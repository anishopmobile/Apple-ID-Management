<?php
declare(strict_types=1);
require_once __DIR__ . '/RecordManager.php';
require_once __DIR__ . '/BackupService.php';

final class BackupController
{
    private BackupService $service;

    public function __construct()
    {
        // Create additive record tables if this install predates dynamic records.
        (new RecordManager(db(),config()['DB_PREFIX']))->migrate();
        $this->service=new BackupService();
    }

    public function dispatch(): void
    {
        if ($_SERVER['REQUEST_METHOD']!=='POST') return;
        require_csrf();
        $action = $_POST['action'] ?? '';
        try {
            if ($action==='save_path') {
                $dir=$this->service->saveDirectory((string)($_POST['directory']??''));
                $_SESSION['backup_flash']=['success','مسیر نسخه پشتیبان ذخیره شد: '.$dir];
            } elseif ($action==='create_backup') {
                $result=$this->service->create($this->service->directory());
                $_SESSION['backup_flash']=['success',
                    'نسخه پشتیبان SQL با موفقیت در مسیر زیر ذخیره شد: '.$result['file'] .
                    ' ('.number_format($result['size']).' بایت)'];
            } elseif ($action==='restore_backup') {
                $this->checkRestoreCredentials();
                $upload=$_FILES['restore_sql']??null;
                if (!is_array($upload) || ($upload['error']??-1)!==UPLOAD_ERR_OK ||
                    !is_uploaded_file((string)($upload['tmp_name']??'')) ||
                    !preg_match('/\.sql$/i',(string)($upload['name']??''))) {
                    throw new DomainException('برای بازیابی باید یک فایل معتبر SQL انتخاب کنید.');
                }
                if ((int)($upload['size']??0)<100 || (int)$upload['size']>BackupService::MAX_BYTES) {
                    throw new DomainException('حجم SQL حداکثر ۶۴ مگابایت مجاز است.');
                }
                $result=$this->service->restore($upload['tmp_name']);
                // Invalidate the browser session after replacing admins and data.
                clear_remember();
                $_SESSION=[];
                session_regenerate_id(true);
                go('/index.php?route=login&restored=1');
            } else {
                throw new DomainException('عملیات نامعتبر است.');
            }
        } catch (DomainException $ex) {
            $_SESSION['backup_flash']=['danger',$ex->getMessage()];
        } catch (Throwable $ex) {
            error_log('[Secure backup/restore] '.get_class($ex).': '.$ex->getMessage());
            $_SESSION['backup_flash']=['danger','عملیات انجام نشد؛ تنظیمات مسیر، مجوزهای PHP و ساختار فایل را بررسی کنید. داده‌های قبلی حفظ شده‌اند.'];
        }
        go('/index.php?route=backup');
    }

    private function checkRestoreCredentials(): void
    {
        if (($_POST['confirm_restore']??'')!=='REPLACE' || empty($_POST['restore_ack'])) {
            throw new DomainException('تأیید جایگزینی کامل داده‌ها انجام نشده است.');
        }
        $password=$_POST['current_password']??null;
        if (!is_string($password) || strlen($password)>512 || $password==='') {
            throw new DomainException('رمز عبور مدیر الزامی است.');
        }
        $admin=current_admin();
        if (!$admin) throw new DomainException('نشست مدیریت منقضی شده است.');
        $stmt=db()->prepare('SELECT password_hash FROM '.table('admins').' WHERE id=? LIMIT 1');
        $stmt->execute([$admin['id']]);
        $hash=$stmt->fetchColumn();
        if (!is_string($hash) || !password_verify($password,$hash)) {
            usleep(150000);
            throw new DomainException('رمز عبور مدیر صحیح نیست.');
        }
    }

    public function render(): void
    {
        $flash=$_SESSION['backup_flash']??null;
        unset($_SESSION['backup_flash']);
        $path=$this->service->directory();
        ?>
        <?php if (is_array($flash) && count($flash)===2): ?>
        <div class="alert alert-<?=e($flash[0])?>" role="alert"><?=e($flash[1])?></div>
        <?php endif; ?>
        <div class="row row-cards">
          <div class="col-12 col-xl-6"><section class="card h-100">
            <div class="card-header"><h3 class="card-title">تهیه نسخه پشتیبان SQL</h3></div>
            <div class="card-body">
              <p class="text-secondary">مسیر ذخیره فقط یک بار تنظیم می‌شود. نسخه SQL اطلاعات تمام جدول‌های همین سامانه را به‌صورت فایل امضاشده ذخیره می‌کند.</p>
              <form action="/index.php?route=backup" method="post">
                <input type="hidden" name="_csrf" value="<?=e(csrf())?>">
                <input type="hidden" name="action" value="save_path">
                <label class="form-label" for="backup-directory">آدرس پوشه ذخیره نسخه پشتیبان</label>
                <input id="backup-directory" class="form-control ltr-input" name="directory" required
                  autocomplete="off" value="<?=e($path)?>" maxlength="1024" dir="ltr">
                <div class="form-hint mt-2">پوشه باید قبلاً روی سرور ایجاد شده باشد، PHP اجازه نوشتن داشته باشد و از public_html / پوشه عمومی سایت خارج باشد.</div>
                <button type="submit" class="btn btn-outline-primary mt-3">ذخیره مسیر</button>
              </form>
              <hr class="my-4">
              <form action="/index.php?route=backup" method="post">
                <input type="hidden" name="_csrf" value="<?=e(csrf())?>">
                <input type="hidden" name="action" value="create_backup">
                <p class="small text-secondary mb-3">مسیر فعلی: <span dir="ltr" class="d-inline-block"><?=e($path)?></span></p>
                <button type="submit" class="btn btn-primary">تهیه نسخه پشتیبان</button>
              </form>
              <div class="alert alert-warning mt-4 mb-0">
                فایل‌های SQL رمزگذاری نشده‌اند و شامل اطلاعات محرمانه مشتریان و حساب مدیر هستند. فقط در پوشه امن نگهداری شوند و دسترسی عمومی به آن‌ها داده نشود.
              </div>
            </div>
          </section></div>
          <div class="col-12 col-xl-6"><section class="card h-100">
            <div class="card-header"><h3 class="card-title">بازیابی نسخه پشتیبان</h3></div>
            <div class="card-body">
              <p class="text-secondary">فایل SQL ساخته‌شده توسط همین نصب را انتخاب کنید. برای امنیت، SQL ناشناس، دستکاری‌شده یا دارای ساختار متفاوت اجرا نخواهد شد.</p>
              <form action="/index.php?route=backup" method="post" enctype="multipart/form-data" id="restore-form">
                <input type="hidden" name="_csrf" value="<?=e(csrf())?>">
                <input type="hidden" name="action" value="restore_backup">
                <input type="hidden" name="confirm_restore" value="REPLACE">
                <div class="mb-3">
                  <label class="form-label" for="restore-sql">انتخاب فایل SQL</label>
                  <input id="restore-sql" class="form-control" type="file" name="restore_sql" accept=".sql" required>
                </div>
                <div class="mb-3">
                  <label class="form-label" for="restore-password">رمز عبور فعلی مدیر</label>
                  <input id="restore-password" class="form-control ltr-input" type="password" name="current_password" autocomplete="current-password" required>
                </div>
                <label class="form-check mb-3">
                  <input class="form-check-input" type="checkbox" name="restore_ack" value="1" required>
                  <span class="form-check-label text-danger">می‌دانم همه اطلاعات فعلی (مشتریان، رکوردها، فیلدها، مدیران و تنظیمات) با داده‌های فایل SQL جایگزین خواهند شد.</span>
                </label>
                <div class="alert alert-danger">
                  هشدار: این عملیات داده‌های فعلی را حذف و داده‌های فایل پشتیبان را جایگزین می‌کند. پیش از بازیابی، یک نسخه پشتیبان تازه از وضعیت فعلی بگیرید. پس از بازیابی باید دوباره وارد شوید.
                </div>
                <button type="submit" class="btn btn-danger" id="restore-submit">بازیابی و جایگزینی داده‌ها</button>
              </form>
              <p class="small text-secondary mt-3">حداکثر حجم ۶۴ مگابایت؛ محدودیت upload_max_filesize و post_max_size سرور هم اعمال می‌شوند.</p>
            </div>
          </section></div>
        </div>
        <?php
    }
}
