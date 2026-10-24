<?php
declare(strict_types=1);

/** Administrative site metadata, private logo uploads and password rotation. */
final class SiteSettings
{
    private PDO $pdo;

    public function __construct() { $this->pdo=db(); }

    public static function logoFile(): ?array
    {
        $name=setting('site_logo','');
        if (!preg_match('/^logo-[a-f0-9]{32}\.(png|jpg|webp)$/D',$name,$m)) return null;
        $path=STORAGE_PATH.'/branding/'.$name;
        if (!is_file($path) || is_link($path)) return null;
        return ['path'=>$path,'name'=>$name,'mime'=>[
            'png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp'
        ][$m[1]]];
    }

    public static function logoUrl(): ?string
    {
        $file=self::logoFile();
        return $file ? '/index.php?route=site_logo&v='.rawurlencode($file['name']) : null;
    }

    public static function sendLogo(): never
    {
        $file=self::logoFile();
        if (!$file) { http_response_code(404);exit; }
        header('Content-Type: '.$file['mime']);
        header('Content-Length: '.filesize($file['path']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=3600');
        readfile($file['path']);
        exit;
    }

    public function updateTitle(string $name): void
    {
        $name=trim(preg_replace('/\s+/u',' ',$name) ?? $name);
        if (mb_strlen($name)<2 || mb_strlen($name)>100 || preg_match('/[\x00-\x1F\x7F]/u',$name)) {
            throw new DomainException('عنوان سایت باید بین ۲ تا ۱۰۰ کاراکتر و بدون نویسه کنترلی باشد.');
        }
        $stmt=$this->pdo->prepare('INSERT INTO '.table('settings').' (name,value) VALUES (?,?)
            ON DUPLICATE KEY UPDATE value=VALUES(value)');
        $stmt->execute(['site_name',$name]);
    }

    public function updatePassword(array $request, int $adminId): void
    {
        $current=$request['current_password']??null;
        $next=$request['new_password']??null;
        $repeat=$request['new_password_confirm']??null;
        if (!is_string($current) || !is_string($next) || !is_string($repeat) ||
            strlen($current)>512 || strlen($next)>512 || strlen($repeat)>512 ||
            strlen($next)<12 || $next!==$repeat || $next===$current) {
            throw new DomainException('رمز جدید باید حداقل ۱۲ کاراکتر، متفاوت از رمز فعلی و مطابق تکرار آن باشد.');
        }
        $fail=$_SESSION['password_failures']??['start'=>time(),'count'=>0];
        if (time()-(int)($fail['start']??0)>900) $fail=['start'=>time(),'count'=>0];
        if ((int)($fail['count']??0)>=5) throw new DomainException('تلاش‌های تغییر رمز بیش از حد مجاز است؛ ۱۵ دقیقه دیگر اقدام کنید.');
        $this->pdo->beginTransaction();
        try {
            $stmt=$this->pdo->prepare('SELECT password_hash FROM '.table('admins').' WHERE id=? FOR UPDATE');
            $stmt->execute([$adminId]);
            $existing=$stmt->fetchColumn();
            if (!is_string($existing) || !password_verify($current,$existing)) {
                $this->pdo->rollBack();
                $fail['count']++;
                $_SESSION['password_failures']=$fail;
                usleep(160000);
                throw new DomainException('رمز عبور فعلی صحیح نیست.');
            }
            $hash=password_hash($next,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_BCRYPT);
            $this->pdo->prepare('UPDATE '.table('admins').' SET password_hash=? WHERE id=?')->execute([$hash,$adminId]);
            $this->pdo->prepare('DELETE FROM '.table('remember_tokens').' WHERE admin_id=?')->execute([$adminId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        unset($_SESSION['password_failures']);
        clear_remember();
        session_regenerate_id(true);
        $_SESSION['csrf']=bin2hex(random_bytes(32));
    }

    public function uploadLogo(array $upload): void
    {
        if (($upload['error']??-1)!==UPLOAD_ERR_OK ||
            !is_uploaded_file((string)($upload['tmp_name']??''))) {
            throw new DomainException('آپلود لوگو انجام نشد؛ فایل معتبر انتخاب کنید.');
        }
        $path=(string)$upload['tmp_name'];
        $bytes=(int)($upload['size']??0);
        if ($bytes<50 || $bytes>1024*1024) throw new DomainException('اندازه لوگو باید کمتر از یک مگابایت باشد.');
        $info=@getimagesize($path);
        if (!is_array($info) || ($info[0]??0)<32 || ($info[1]??0)<32 ||
            ($info[0]??0)>2000 || ($info[1]??0)>2000 ||
            ($info[0]*$info[1])>4000000) {
            throw new DomainException('ابعاد لوگو باید بین ۳۲ تا ۲۰۰۰ پیکسل و حداکثر ۴ میلیون پیکسل باشد.');
        }
        $actual=(new finfo(FILEINFO_MIME_TYPE))->file($path);
        $allowed=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
        if (!isset($allowed[$actual]) || !in_array($info[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG,IMAGETYPE_WEBP],true) ||
            $allowed[$actual] !== ($allowed[image_type_to_mime_type($info[2])] ?? null)) {
            throw new DomainException('فقط تصویر واقعی PNG، JPEG یا WebP برای لوگو مجاز است.');
        }
        $folder=STORAGE_PATH.'/branding';
        if (!is_dir($folder) && !mkdir($folder,0700,true)) throw new DomainException('پوشه خصوصی لوگو قابل ایجاد نیست.');
        $name='logo-'.bin2hex(random_bytes(16)).'.'.$allowed[$actual];
        $target=$folder.'/'.$name;
        if (!move_uploaded_file($path,$target)) throw new DomainException('ذخیره تصویر موفق نبود.');
        @chmod($target,0600);
        $previous=self::logoFile();
        try {
            $this->pdo->prepare('INSERT INTO '.table('settings').'(name,value) VALUES (?,?)
                ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute(['site_logo',$name]);
        } catch(Throwable $ex) { @unlink($target);throw $ex; }
        if ($previous) @unlink($previous['path']);
    }

    public function removeLogo(): void
    {
        $old=self::logoFile();
        $this->pdo->prepare('DELETE FROM '.table('settings').' WHERE name=?')->execute(['site_logo']);
        if ($old) @unlink($old['path']);
    }

    public function dispatch(int $adminId): void
    {
        if ($_SERVER['REQUEST_METHOD']!=='POST') return;
        require_csrf();
        try {
            switch ($_POST['action']??'') {
                case 'save_title':
                    $this->updateTitle((string)($_POST['site_name']??''));$message='عنوان سایت ذخیره شد.';break;
                case 'change_password':
                    $this->updatePassword($_POST,$adminId);$message='رمز عبور تغییر کرد؛ نشست‌های ماندگار قبلی لغو شدند.';break;
                case 'upload_logo':
                    $this->uploadLogo($_FILES['logo']??[]);$message='لوگوی سایت ذخیره شد.';break;
                case 'remove_logo':
                    $this->removeLogo();$message='لوگوی سایت حذف شد.';break;
                default: throw new DomainException('عملیات نامعتبر است.');
            }
            $_SESSION['settings_flash']=['success',$message];
        } catch(DomainException $ex) {
            $_SESSION['settings_flash']=['danger',$ex->getMessage()];
        } catch(Throwable $ex) {
            error_log('[site settings] '.$ex->getMessage());
            $_SESSION['settings_flash']=['danger','عملیات انجام نشد؛ لطفاً دوباره تلاش کنید.'];
        }
        go('/index.php?route=settings');
    }

    public function render(): void
    {
        $flash=$_SESSION['settings_flash']??null;
        unset($_SESSION['settings_flash']);
        $logo=self::logoUrl();
        ?>
        <?php if(is_array($flash)):?><div class="alert alert-<?=e($flash[0])?>"><?=e($flash[1])?></div><?php endif;?>
        <div class="row row-cards">
          <div class="col-12 col-xl-6">
            <section class="card mb-3"><div class="card-header"><h3 class="card-title">عنوان سایت</h3></div>
              <form method="post" action="/index.php?route=settings" class="card-body">
                <input type="hidden" name="_csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save_title">
                <label class="form-label" for="settings-site-name">نام نمایشی سایت</label>
                <input id="settings-site-name" class="form-control" name="site_name" maxlength="100" required value="<?=e(setting('site_name','مدیریت Apple ID'))?>">
                <p class="small text-secondary mt-2">در سربرگ صفحه ورود، عنوان تب مرورگر و بالای سایدبار نمایش داده می‌شود.</p>
                <button class="btn btn-primary" type="submit">ذخیره عنوان</button>
              </form>
            </section>
            <section class="card"><div class="card-header"><h3 class="card-title">لوگوی سایت</h3></div><div class="card-body">
              <?php if($logo):?><img src="<?=e($logo)?>" class="site-logo-preview mb-3" alt="لوگوی فعلی سایت"><?php else:?><p class="text-secondary">هنوز لوگویی ثبت نشده است؛ حرف A نمایش داده می‌شود.</p><?php endif;?>
              <form action="/index.php?route=settings" method="post" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="upload_logo">
                <label class="form-label" for="settings-logo">انتخاب تصویر لوگو</label>
                <input class="form-control" type="file" id="settings-logo" name="logo" accept="image/png,image/jpeg,image/webp" required>
                <p class="small text-secondary mt-2">PNG، JPG یا WebP، حداکثر ۱ مگابایت؛ فایل در پوشه خصوصی ذخیره می‌شود.</p>
                <button class="btn btn-primary" type="submit">بارگذاری لوگو</button>
              </form>
              <?php if($logo):?><form method="post" action="/index.php?route=settings" class="mt-3">
                <input type="hidden" name="_csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="remove_logo">
                <button class="btn btn-outline-danger" type="submit">حذف لوگو</button>
              </form><?php endif;?>
            </div></section>
          </div>
          <div class="col-12 col-xl-6">
            <section class="card"><div class="card-header"><h3 class="card-title">تغییر رمز عبور مدیر</h3></div>
              <form method="post" action="/index.php?route=settings" class="card-body" id="settings-password-form">
                <input type="hidden" name="_csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="change_password">
                <div class="mb-3"><label class="form-label" for="settings-current-password">رمز عبور قبلی</label>
                  <input id="settings-current-password" class="form-control ltr-input" type="password" name="current_password" autocomplete="current-password" required></div>
                <div class="mb-3"><label class="form-label" for="settings-new-password">رمز عبور جدید</label>
                  <input id="settings-new-password" class="form-control ltr-input" type="password" name="new_password" autocomplete="new-password" minlength="12" maxlength="512" required></div>
                <div class="mb-3"><label class="form-label" for="settings-confirm-password">تکرار رمز عبور جدید</label>
                  <input id="settings-confirm-password" class="form-control ltr-input" type="password" name="new_password_confirm" autocomplete="new-password" minlength="12" maxlength="512" required></div>
                <p class="small text-secondary">حداقل ۱۲ کاراکتر. پس از تغییر، توکن‌های ورود طولانی‌مدت قبلی باطل می‌شوند.</p>
                <button type="submit" class="btn btn-primary">تغییر رمز عبور</button>
              </form>
            </section>
          </div>
        </div>
        <?php
    }
}
