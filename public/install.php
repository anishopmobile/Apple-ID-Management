<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/common.php';

if (installation_blocked()) {
    if (installed()) { go('/index.php?route=login'); }
    http_response_code(503);
    exit('نصب قبلی ناقص است. برای جلوگیری از دسترسی غیرمجاز، بازیابی باید توسط مدیر سرور انجام شود.');
}

function field(string $key, string $default = ''): string {
    return is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : $default;
}
function db_values(): array {
    $data = [
        'APP_TZ' => field('APP_TZ', 'Asia/Tehran'),
        'DB_HOST' => field('DB_HOST'),
        'DB_PORT' => field('DB_PORT', '3306'),
        'DB_NAME' => field('DB_NAME'),
        'DB_USER' => field('DB_USER'),
        'DB_PASSWORD' => is_string($_POST['DB_PASSWORD'] ?? null) ? $_POST['DB_PASSWORD'] : '',
        'DB_PREFIX' => field('DB_PREFIX', 'anishop_'),
    ];
    if (!in_array($data['APP_TZ'], timezone_identifiers_list(), true)) {
        throw new InvalidArgumentException('منطقه زمانی معتبر نیست.');
    }
    if (!preg_match('/^[a-zA-Z0-9._:-]{1,253}$/D', $data['DB_HOST'])) {
        throw new InvalidArgumentException('میزبان دیتابیس معتبر نیست.');
    }
    if (!ctype_digit($data['DB_PORT']) || (int)$data['DB_PORT'] < 1 || (int)$data['DB_PORT'] > 65535) {
        throw new InvalidArgumentException('پورت باید بین ۱ تا ۶۵۵۳۵ باشد.');
    }
    foreach (['DB_NAME', 'DB_USER'] as $key) {
        if (!preg_match('/^[a-zA-Z0-9_$-]{1,64}$/D', $data[$key])) {
            throw new InvalidArgumentException($key . ' معتبر نیست.');
        }
    }
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,29}_$/D', $data['DB_PREFIX'])) {
        throw new InvalidArgumentException('پیشوند باید با حرف شروع، فقط شامل حروف لاتین/عدد/زیرخط و به زیرخط ختم شود (حداکثر ۳۱ کاراکتر).');
    }
    if (strlen($data['DB_PASSWORD']) > 512) { throw new InvalidArgumentException('رمز دیتابیس بیش از حد طولانی است.'); }
    return $data;
}
function connection_error(PDOException $exception): string {
    $text = $exception->getMessage();
    if (str_contains($text, '1045')) { return 'نام کاربری یا رمز عبور MySQL نادرست است.'; }
    if (str_contains($text, '1049')) { return 'دیتابیس موردنظر وجود ندارد.'; }
    if (str_contains($text, '1044')) { return 'حساب MySQL مجوز دسترسی به دیتابیس را ندارد.'; }
    if (str_contains($text, '2002') || str_contains($text, '2003') || str_contains($text, '2005')) { return 'اتصال به میزبان یا پورت MySQL برقرار نشد.'; }
    return 'اتصال به MySQL ناموفق بود؛ تنظیمات و دسترسی‌های دیتابیس را بررسی کنید.';
}
function schema(PDO $pdo, string $prefix): void {
    $q = static fn(string $suffix): string => chr(96) . $prefix . $suffix . chr(96);
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $q('admins') . ' (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(150) NOT NULL, username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $q('settings') . ' (
        name VARCHAR(80) PRIMARY KEY, value TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $q('customers') . ' (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(150) NOT NULL, mobile VARCHAR(30) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $q('apple_records') . ' (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id BIGINT UNSIGNED NOT NULL, apple_email VARCHAR(254) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created_at (created_at), INDEX idx_customer_id (customer_id),
        CONSTRAINT ' . chr(96) . $prefix . 'fk_record_customer' . chr(96) . '
        FOREIGN KEY (customer_id) REFERENCES ' . $q('customers') . ' (id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $q('login_attempts') . ' (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip_hash CHAR(64) NOT NULL, username_hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_created (ip_hash, created_at),
        INDEX idx_user_created (username_hash, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $q('remember_tokens') . ' (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        admin_id BIGINT UNSIGNED NOT NULL, selector CHAR(24) NOT NULL UNIQUE,
        validator_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL,
        INDEX idx_expires (expires_at),
        FOREIGN KEY (admin_id) REFERENCES ' . $q('admins') . ' (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}
function save_config(array $values): void {
    if (!is_dir(STORAGE_PATH) && !mkdir(STORAGE_PATH, 0700, true)) {
        throw new RuntimeException('پوشه storage قابل ایجاد نیست.');
    }
    $tmp = tempnam(STORAGE_PATH, 'setup_');
    if ($tmp === false) { throw new RuntimeException('مجوز نوشتن در storage وجود ندارد.'); }
    try {
        chmod($tmp, 0600);
        $payload = "<?php\nreturn " . var_export($values, true) . ";\n";
        if (file_put_contents($tmp, $payload, LOCK_EX) !== strlen($payload)) {
            throw new RuntimeException('ذخیره امن تنظیمات امکان‌پذیر نیست.');
        }
        if (!rename($tmp, CONFIG_FILE)) { throw new RuntimeException('انتقال تنظیمات ناموفق بود.'); }
        chmod(CONFIG_FILE, 0600);
    } finally { if (is_file($tmp)) { @unlink($tmp); } }
}
$tested = !empty($_SESSION['install_db']) && (time() - (int)($_SESSION['install_test_time'] ?? 0)) < 900;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!valid_csrf()) {
        http_response_code(419);
        $error = 'نشست منقضی شده است. صفحه را دوباره بارگذاری کنید.';
    } elseif (field('action') === 'test') {
        header('Content-Type: application/json; charset=utf-8');
        unset($_SESSION['install_db'], $_SESSION['install_test_time']);
        try {
            $credentials = db_values();
            connect_db($credentials)->query('SELECT 1')->fetchColumn();
            $_SESSION['install_db'] = $credentials;
            $_SESSION['install_test_time'] = time();
            echo json_encode(['ok' => true, 'message' => 'اتصال با موفقیت برقرار شد. اکنون می‌توانید به مرحله بعد بروید.'], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $ex) {
            echo json_encode(['ok' => false, 'message' => connection_error($ex)], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $ex) {
            echo json_encode(['ok' => false, 'message' => $ex instanceof InvalidArgumentException ? $ex->getMessage() : 'خطا در بررسی تنظیمات اتصال.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    } elseif (field('action') === 'finish') {
        if (!$tested) { $error = 'ابتدا اتصال دیتابیس را دوباره آزمایش کنید.'; }
        else {
            $site = field('site_name');
            $name = field('full_name');
            $username = field('username');
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $repeat = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';
            if (mb_strlen($site) < 2 || mb_strlen($site) > 100 || mb_strlen($name) < 3 || mb_strlen($name) > 150) {
                $error = 'نام سایت و نام مدیر را به‌درستی وارد کنید.';
            } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/D', $username)) {
                $error = 'نام کاربری باید ۳ تا ۵۰ کاراکتر لاتین، عدد، نقطه، خط تیره یا زیرخط باشد.';
            } elseif (strlen($password) < 12 || strlen($password) > 128) {
                $error = 'رمز عبور باید حداقل ۱۲ و حداکثر ۱۲۸ کاراکتر داشته باشد.';
            } elseif (!hash_equals($password, $repeat)) {
                $error = 'تکرار رمز عبور مطابقت ندارد.';
            } else {
                $lock = fopen(STORAGE_PATH . '/install.mutex', 'c');
                if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                    $error = 'نصب دیگری در حال انجام است.';
                } else {
                    try {
                        if (installation_blocked()) { throw new RuntimeException('سامانه قبلاً نصب شده است.'); }
                        $credentials = $_SESSION['install_db'];
                        $pdo = connect_db($credentials);
                        schema($pdo, $credentials['DB_PREFIX']);
                        $q = static fn(string $suffix): string => chr(96) . $credentials['DB_PREFIX'] . $suffix . chr(96);
                        if ((int)$pdo->query('SELECT COUNT(*) FROM ' . $q('admins'))->fetchColumn() !== 0) {
                            throw new RuntimeException('این پیشوند شامل مدیر قبلی است؛ برای امنیت، از دیتابیس/پیشوند خالی استفاده کنید.');
                        }
                        $values = $credentials;
                        $values['APP_KEY'] = bin2hex(random_bytes(32));
                        $pdo->beginTransaction();
                        try {
                            $hash = password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
                            $pdo->prepare('INSERT INTO ' . $q('admins') . ' (full_name,username,password_hash) VALUES (?,?,?)')
                                ->execute([$name, $username, $hash]);
                            $pdo->prepare('INSERT INTO ' . $q('settings') . ' (name,value) VALUES (?,?)')->execute(['site_name', $site]);
                            save_config($values);
                            $pdo->commit();
                        } catch (Throwable $ex) {
                            if ($pdo->inTransaction()) { $pdo->rollBack(); }
                            if (is_file(CONFIG_FILE)) { @unlink(CONFIG_FILE); }
                            throw $ex;
                        }
                        $handle = @fopen(INSTALLED_LOCK, 'x');
                        if (!$handle) { throw new RuntimeException('ایجاد قفل نصب ناموفق بود؛ با پشتیبانی سرور تماس بگیرید.'); }
                        fwrite($handle, 'Installed on ' . gmdate('c') . "\n");
                        fclose($handle);
                        chmod(INSTALLED_LOCK, 0600);
                        unset($_SESSION['install_db'], $_SESSION['install_test_time']);
                        @unlink(__FILE__);
                        header('Refresh: 3;url=/index.php?route=login&installed=1');
                        echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>نصب موفق</title><body style="font-family:sans-serif;padding:3rem"><h2>نصب با موفقیت انجام شد.</h2><p>تا چند لحظه دیگر به صفحه ورود مدیر منتقل می‌شوید.</p><a href="/index.php?route=login&installed=1">ورود به مدیریت</a></body></html>';
                        exit;
                    } catch (Throwable $ex) {
                        error_log('[Installer] ' . $ex->getMessage());
                        $error = $ex instanceof RuntimeException ? $ex->getMessage() : 'ایجاد جداول یا ذخیره تنظیمات ناموفق بود. دسترسی CREATE و INSERT و مجوز نوشتن storage را بررسی کنید.';
                    } finally {
                        flock($lock, LOCK_UN);
                        fclose($lock);
                    }
                }
            }
        }
    }
}
$tested = !empty($_SESSION['install_db']) && (time() - (int)($_SESSION['install_test_time'] ?? 0)) < 900;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>راه‌اندازی | مدیریت Apple ID</title>
<link rel="stylesheet" href="/assets/vendor/tabler/tabler.rtl.min.css">
<link rel="stylesheet" href="/assets/css/app.css">
<script src="/assets/js/setup.js" defer></script>
</head>
<body class="theme-light setup-body" data-tested="<?= $tested ? '1' : '0' ?>">
<main class="container container-tight py-5">
  <header class="text-center mb-4"><div class="brand-mark mx-auto">A</div><h1 class="h2 mt-3">نصب سامانه مدیریت Apple ID</h1>
  <p class="text-secondary">راه‌اندازی اولیه امن در دو مرحله</p></header>
  <section class="card card-lg shadow-sm"><div class="card-body">
    <div class="steps"><div class="step is-active" id="step-one">۱ <span>اتصال دیتابیس</span></div><div class="step" id="step-two">۲ <span>مدیر سامانه</span></div></div>
    <?php if ($error): ?><div class="alert alert-danger mt-3" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form id="database-form" class="mt-4" method="post" action="/install.php" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="test">
      <h3 class="card-title">تنظیمات پایگاه داده</h3>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="APP_TZ">APP_TZ</label><input class="form-control" id="APP_TZ" name="APP_TZ" value="Asia/Tehran" required></div>
        <div class="col-md-6"><label class="form-label" for="DB_HOST">DB_HOST</label><input class="form-control ltr-input" id="DB_HOST" name="DB_HOST" value="localhost" required></div>
        <div class="col-md-6"><label class="form-label" for="DB_PORT">DB_PORT</label><input class="form-control ltr-input" id="DB_PORT" type="number" min="1" max="65535" name="DB_PORT" value="3306" required></div>
        <div class="col-md-6"><label class="form-label" for="DB_NAME">DB_NAME</label><input class="form-control ltr-input" id="DB_NAME" name="DB_NAME" placeholder="appledb" required></div>
        <div class="col-md-6"><label class="form-label" for="DB_USER">DB_USER</label><input class="form-control ltr-input" id="DB_USER" name="DB_USER" placeholder="appleuser" required></div>
        <div class="col-md-6"><label class="form-label" for="DB_PASSWORD">DB_PASSWORD</label><input class="form-control ltr-input" id="DB_PASSWORD" name="DB_PASSWORD" type="password" autocomplete="new-password"></div>
        <div class="col-12"><label class="form-label" for="DB_PREFIX">DB_PREFIX</label><input class="form-control ltr-input" id="DB_PREFIX" name="DB_PREFIX" value="anishop_" required></div>
      </div>
      <div id="test-message" class="mt-3" aria-live="polite"></div>
      <div class="d-flex gap-2 justify-content-end mt-4"><button type="submit" class="btn btn-outline-primary" id="test-button">آزمایش اتصال</button><button class="btn btn-primary" type="button" id="next-button" <?= $tested ? '' : 'disabled' ?>>مرحله بعد ←</button></div>
    </form>
    <form id="admin-form" class="mt-4 d-none" method="post" action="/install.php" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="finish">
      <h3 class="card-title">مشخصات سایت و مدیر</h3>
      <div class="mb-3"><label class="form-label" for="site_name">نام سایت</label><input class="form-control" id="site_name" name="site_name" maxlength="100" required value="<?= e(field('site_name')) ?>"></div>
      <div class="mb-3"><label class="form-label" for="full_name">نام و نام خانوادگی مدیر</label><input class="form-control" id="full_name" name="full_name" maxlength="150" required value="<?= e(field('full_name')) ?>"></div>
      <div class="mb-3"><label class="form-label" for="username">نام کاربری</label><input class="form-control ltr-input" id="username" name="username" maxlength="50" minlength="3" required value="<?= e(field('username')) ?>"></div>
      <div class="mb-3"><label class="form-label" for="password">رمز عبور <span class="text-secondary">(حداقل ۱۲ کاراکتر)</span></label><input class="form-control ltr-input" id="password" type="password" name="password" minlength="12" maxlength="128" autocomplete="new-password" required></div>
      <div class="mb-3"><label class="form-label" for="password_confirm">تکرار رمز عبور</label><input class="form-control ltr-input" id="password_confirm" type="password" name="password_confirm" minlength="12" maxlength="128" autocomplete="new-password" required></div>
      <div class="d-flex gap-2 justify-content-end"><button class="btn btn-outline-secondary" type="button" id="back-button">بازگشت</button><button class="btn btn-primary" type="submit">تکمیل نصب و ساخت مدیر</button></div>
    </form>
  </div></section>
  <p class="text-center text-secondary mt-4 small">اطلاعات اتصال خارج از پوشه عمومی و با سطح دسترسی محدود ذخیره می‌شود.</p>
</main>
</body></html>
