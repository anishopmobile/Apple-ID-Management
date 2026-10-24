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

    public function updateDateMode(string $mode): void
    {
        if (!in_array($mode,['jalali','gregorian'],true)) {
            throw new DomainException('نوع نمایش تاریخ فقط می‌تواند شمسی یا میلادی باشد.');
        }
        $this->pdo->prepare('INSERT INTO '.table('settings').' (name,value) VALUES (?,?)
            ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute(['date_display_mode',$mode]);
        date_display_mode($mode);
    }

    /**
     * Return all current date fields. Unlike labels, IDs survive field renames.
     * New installs may not have visited the record page yet: migrate additively.
     */
    public function dateFields(): array {
        require_once __DIR__.'/RecordManager.php';
        $manager = new RecordManager($this->pdo,config()['DB_PREFIX']);
        $manager->migrate();
        return array_values(array_filter($manager->fields(), static fn($f)=>
            $f['type']==='date' && empty($f['deleted_at'])
        ));
    }

    public function updateDatePreferences(string $mode,array $exempt): void {
        if (!in_array($mode,['jalali','gregorian'],true)) {
            throw new DomainException('نوع نمایش تاریخ فقط شمسی یا میلادی است.');
        }
        $fields=$this->dateFields();
        $allowed=[];
        foreach ($fields as $field) {
            if ($field['field_key']!=='created_at') $allowed[(int)$field['id']]=true;
        }
        if (count($exempt)>count($allowed) || count($exempt)>500) {
            throw new DomainException('تعداد فیلدهای مستثنی نامعتبر است.');
        }
        $selected=[];
        foreach ($exempt as $id) {
            if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,17}$/D',$id)
                || !isset($allowed[(int)$id])) {
                throw new DomainException('فهرست فیلدهای تاریخ معتبر نیست.');
            }
            $selected[(int)$id]=(int)$id;
        }
        $selected=array_values($selected);
        sort($selected,SORT_NUMERIC);
        $this->pdo->beginTransaction();
        try {
            $stmt=$this->pdo->prepare('INSERT INTO '.table('settings').' (name,value) VALUES (?,?)
                ON DUPLICATE KEY UPDATE value=VALUES(value)');
            $stmt->execute(['date_display_mode',$mode]);
            $stmt->execute(['date_jalali_exempt_ids',json_encode($selected,JSON_THROW_ON_ERROR)]);
            $this->pdo->commit();
        } catch(Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
        date_display_mode($mode);
        date_jalali_exempt_ids($selected);
    }

    public function updateCommitment(string $html, string $mode): void
    {
        require_once __DIR__.'/CommitmentText.php';
        if (!in_array($mode,['simple','advanced'],true)) {
            throw new DomainException('حالت ویرایشگر باید ساده یا پیشرفته باشد.');
        }
        $safe=CommitmentText::clean($html);
        if (mb_strlen(CommitmentText::plain($safe))>10000) {
            throw new DomainException('متن تعهدنامه حداکثر ۱۰ هزار نویسه است.');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt=$this->pdo->prepare('INSERT INTO '.table('settings').'(name,value) VALUES (?,?)
                ON DUPLICATE KEY UPDATE value=VALUES(value)');
            $stmt->execute(['commitment_html',$safe]);
            $stmt->execute(['commitment_editor_mode',$mode]);
            $this->pdo->commit();
        } catch(Throwable $exception) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
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
                case 'save_date_mode':
                    $mode=$_POST['date_display_mode']??null;
                    if (!is_string($mode)) throw new DomainException('نوع نمایش تاریخ نامعتبر است.');
                    // Native HTML checkbox submission is the single source of truth:
                    // only individually checked fields are posted. Missing array
                    // means that the user intentionally unchecked every custom field.
                    // A version marker prevents an old cached JSON-only form from
                    // silently clearing the saved exceptions.
                    if (($_POST['date_exceptions_version']??null)!=='2') {
                        throw new DomainException('فرم تنظیمات تاریخ قدیمی است؛ صفحه را دوباره بارگذاری کنید.');
                    }
                    $exempt=$_POST['jalali_exempt_ids']??[];
                    if (!is_array($exempt) || !array_is_list($exempt)) {
                        throw new DomainException('فهرست استثناهای تاریخ معتبر نیست.');
                    }
                    $this->updateDatePreferences($mode,$exempt);
                    $message='تنظیم نمایش تاریخ و '.count($exempt).' استثنای سفارشی ذخیره شد.';
                    break;
                case 'save_commitment':
                    $html=$_POST['commitment_html']??null;
                    $editorMode=$_POST['commitment_editor_mode']??null;
                    if(!is_string($html) || !is_string($editorMode)) {
                        throw new DomainException('متن تعهدنامه یا حالت ویرایشگر نامعتبر است.');
                    }
                    $this->updateCommitment($html,$editorMode);
                    $message='متن تعهدنامه و حالت ویرایشگر ذخیره شدند.';
                    break;
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
        $dateFields=$this->dateFields();
        $jalaliFields=date_jalali_exempt_ids();
        ?>
        <?php if(is_array($flash)):?><div class="alert alert-<?=e($flash[0])?>"><?=e($flash[1])?></div><?php endif;?>
        <div id="settings-layout" class="container-fluid px-0">
          <div class="row g-3 mb-3" id="settings-general-row">
            <div class="col-12 col-xl-6"><section class="card h-100"><div class="card-header"><h3 class="card-title">عنوان سایت</h3></div>
              <form method="post" action="/index.php?route=settings" class="card-body">
                <input type="hidden" name="_csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save_title">
                <label class="form-label" for="settings-site-name">نام نمایشی سایت</label>
                <input id="settings-site-name" class="form-control" name="site_name" maxlength="100" required value="<?=e(setting('site_name','مدیریت Apple ID'))?>">
                <p class="small text-secondary mt-2">در سربرگ صفحه ورود، عنوان تب مرورگر و بالای سایدبار نمایش داده می‌شود.</p>
                <button class="btn btn-primary" type="submit">ذخیره عنوان</button>
              </form>
            </section></div>
            <div class="col-12 col-xl-6"><section class="card h-100"><div class="card-header"><h3 class="card-title">لوگوی سایت</h3></div><div class="card-body">
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
            </div></section></div>
          </div>
          <div class="row g-3 mb-3" id="settings-preferences-row">
            <div class="col-12 col-xl-7"><section class="card h-100" id="date-display-settings">
              <div class="card-header"><h3 class="card-title">نوع نمایش فیلد تاریخ</h3></div>
              <form method="post" action="/index.php?route=settings" class="card-body" id="date-exception-form" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?=e(csrf())?>">
                <input type="hidden" name="action" value="save_date_mode">
                <input type="hidden" name="date_exceptions_version" value="2">
                <label for="settings-date-mode" class="form-label">تقویم نمایش تاریخ</label>
                <select id="settings-date-mode" name="date_display_mode" class="form-select">
                  <option value="jalali" <?=date_display_mode()==='jalali'?'selected':''?>>شمسی</option>
                  <option value="gregorian" <?=date_display_mode()==='gregorian'?'selected':''?>>میلادی</option>
                </select>
                <p class="small text-secondary mt-2">تقویم عمومی برای فیلدهای تاریخ، جداول، اکسل و PDF. تاریخ ثبت سیستمی همیشه شمسی است؛ فیلدهای دلخواه زیر هم می‌توانند از حالت میلادی مستثنی شوند.</p>
                <fieldset id="date-exception-fields" class="mt-3 border rounded p-3">
                  <legend class="fs-5 fw-semibold mb-2">فیلدهایی که همیشه شمسی نمایش داده شوند</legend>
                  <?php foreach ($dateFields as $dateField):
                    $id=(int)$dateField['id'];
                    $locked=$dateField['field_key']==='created_at';
                    $selected=$locked || in_array($id,$jalaliFields,true); ?>
                    <div class="form-check mb-2">
                      <input type="checkbox" class="form-check-input date-exemption-input" id="date-exception-<?=$id?>"
                        name="<?=$locked?'':'jalali_exempt_ids[]'?>" value="<?=$id?>"
                        <?=$selected?'checked':''?> <?=$locked?'disabled':''?>>
                      <label class="form-check-label" for="date-exception-<?=$id?>">
                        <?=e((string)$dateField['label'])?>
                        <?php if ($locked): ?><span class="badge bg-blue-lt">سیستمی · همیشه شمسی</span><?php endif; ?>
                      </label>
                    </div>
                  <?php endforeach; ?>
                  <p class="small text-secondary mb-0">این استثناها در جدول رکوردها، ویرایش رکورد، خروجی اکسل و کارت PDF اعمال می‌شوند. نام فیلدها قابل تغییر است اما انتخاب با شناسه ثابت فیلد ذخیره می‌شود. تاریخ ثبت مشتریان نیز همیشه شمسی است.</p>
                </fieldset>
                <p id="date-exception-count" class="small text-secondary mt-2" role="status" aria-live="polite"></p>
                <button type="submit" class="btn btn-primary mt-3">ذخیره نوع تاریخ و استثناها</button>
              </form>
            </section></div>
            <div class="col-12 col-xl-5"><section class="card h-100"><div class="card-header"><h3 class="card-title">تغییر رمز عبور مدیر</h3></div>
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
            </section></div>
          </div>
          <div class="row g-3" id="settings-commitment-row">
            <div class="col-12"><section class="card" id="commitment-editor-settings">
              <div class="card-header"><h3 class="card-title">متن تعهدنامه برای گزارش تعهد</h3></div>
              <form method="post" action="/index.php?route=settings" class="card-body" id="commitment-settings-form">
                <input type="hidden" name="_csrf" value="<?=e(csrf())?>">
                <input type="hidden" name="action" value="save_commitment">
                <div class="row g-3 mb-3">
                  <div class="col-md-6">
                    <label for="commitment-editor-mode" class="form-label">نوع ویرایشگر</label>
                    <?php $editorMode=setting('commitment_editor_mode','simple'); ?>
                    <select id="commitment-editor-mode" name="commitment_editor_mode" class="form-select">
                      <option value="simple" <?=$editorMode==='simple'?'selected':''?>>ساده</option>
                      <option value="advanced" <?=$editorMode==='advanced'?'selected':''?>>پیشرفته</option>
                    </select>
                  </div>
                </div>
                <label for="commitment-html" class="form-label">متن تعهدنامه (قابل قالب‌بندی)</label>
                <textarea id="commitment-html" name="commitment_html" class="form-control" dir="rtl"
                  rows="12" maxlength="48000" data-editor-mode="<?=e($editorMode)?>"><?=e(setting('commitment_html',''))?></textarea>
                <p class="small text-secondary mt-2">متن ذخیره‌شده زیر کارت در PDF A4 گزارش تعهد چاپ می‌شود. فقط قالب‌بندی متن و فهرست مجاز است؛ تصاویر، کد و لینک‌های خارجی ذخیره نمی‌شوند. در صورت نبود ویرایشگر، کادر متن قابل استفاده باقی می‌ماند.</p>
                <button type="submit" class="btn btn-primary">ذخیره متن تعهدنامه</button>
              </form>
            </section></div>
          </div>
        </div>
        <?php
    }
}
