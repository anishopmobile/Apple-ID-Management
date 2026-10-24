<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/common.php';

if (!installed()) {
    if (installation_blocked()) {
        http_response_code(503);
        exit('تنظیمات نصب ناقص است؛ مدیر سرور باید آن را بررسی کند.');
    }
    go('/install.php');
}

try {
    $route = is_string($_GET['route'] ?? null) ? $_GET['route'] : 'dashboard';
    $routes = [
        'dashboard' => ['داشبورد', 'home'],
        'records' => ['ایجاد رکورد', 'clipboard'],
        'records_fields' => ['ایجاد فیلد', 'settings'],
        'customers' => ['مدیریت مشتریان', 'users'],
        'customers_export' => ['خروجی اکسل مشتریان', 'database'],
        'reports' => ['گزارشات', 'chart'],
        'reports_apple_id_card' => ['Apple ID Card', 'id'],
        'settings' => ['تنظیمات سایت', 'settings'],
        'backup' => ['پشتیبان‌گیری و بازیابی', 'database'],
    ];
    $error = '';
    if ($route === 'site_logo') {
        require_once dirname(__DIR__) . '/src/SiteSettings.php';
        SiteSettings::sendLogo();
    }
    if ($route === 'logout') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('روش درخواست مجاز نیست.'); }
        require_csrf();
        clear_remember();
        $_SESSION = [];
        session_regenerate_id(true);
        go('/index.php?route=login');
    }
    if ($route === 'login') {
        if (current_admin()) { go('/index.php'); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            require_csrf();
            $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            if (strlen($username) > 100 || strlen($password) > 512) {
                $error = 'نام کاربری یا رمز عبور نادرست است.';
            } else {
                $ip = hash_hmac('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''), config()['APP_KEY']);
                $userhash = hash_hmac('sha256', strtolower($username), config()['APP_KEY']);
                $attempts = db()->prepare('SELECT COUNT(*) FROM ' . table('login_attempts') . ' WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE) AND (ip_hash=? OR username_hash=?)');
                $attempts->execute([$ip, $userhash]);
                if ((int)$attempts->fetchColumn() >= 8) {
                    http_response_code(429);
                    $error = 'تلاش‌های ورود بیش از حد مجاز است. ۱۵ دقیقه دیگر تلاش کنید.';
                } else {
                    $check = db()->prepare('SELECT id,password_hash FROM ' . table('admins') . ' WHERE username=? LIMIT 1');
                    $check->execute([$username]);
                    $admin = $check->fetch();
                    $fakeHash = '$2y$12$Aa0hUWgdZjEZ7HCxyREZVOB01Yv6aJCvP0E0GmlSDHzo0iYLmCkNW';
                    $verified = password_verify($password, $admin['password_hash'] ?? $fakeHash);
                    if (!$admin || !$verified) {
                        db()->prepare('INSERT INTO ' . table('login_attempts') . ' (ip_hash,username_hash) VALUES (?,?)')->execute([$ip, $userhash]);
                        usleep(random_int(80000, 180000));
                        $error = 'نام کاربری یا رمز عبور نادرست است.';
                    } else {
                        if (password_needs_rehash($admin['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
                            db()->prepare('UPDATE ' . table('admins') . ' SET password_hash=? WHERE id=?')->execute([
                                password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT), $admin['id']
                            ]);
                        }
                        db()->prepare('DELETE FROM ' . table('login_attempts') . ' WHERE ip_hash=? OR username_hash=?')->execute([$ip, $userhash]);
                        clear_remember();
                        session_regenerate_id(true);
                        $_SESSION['admin_id'] = (int)$admin['id'];
                        $_SESSION['login_at'] = time();
                        $_SESSION['csrf'] = bin2hex(random_bytes(32));
                        if (!empty($_POST['remember'])) { set_remember((int)$admin['id']); }
                        go('/index.php');
                    }
                }
            }
        }
        $site = setting('site_name', 'مدیریت Apple ID');
        require_once dirname(__DIR__) . '/src/SiteSettings.php';
        $loginLogo = SiteSettings::logoUrl();
        ?>
        <!doctype html><html lang="fa" dir="rtl"><head>
        <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title>ورود مدیر | <?= e($site) ?></title>
        <link rel="stylesheet" href="/assets/vendor/tabler/tabler.rtl.min.css">
        <link rel="stylesheet" href="/assets/css/app.css">
        </head><body class="theme-light login-body">
        <main class="container container-tight py-5"><div class="text-center mb-4">
        <?php if ($loginLogo): ?><img src="<?=e($loginLogo)?>" alt="لوگوی سایت" class="site-logo-login mx-auto"><?php else: ?><div class="brand-mark mx-auto">A</div><?php endif; ?><h1 class="h2 mt-3"><?= e($site) ?></h1>
        <p class="text-secondary">ورود امن به پنل مدیریت</p></div>
        <section class="card card-lg shadow-sm"><div class="card-body">
        <?php if (isset($_GET['installed'])): ?><div class="alert alert-success">راه‌اندازی با موفقیت انجام شد. با حساب مدیر وارد شوید.</div><?php endif; ?>
        <?php if (isset($_GET['restored'])): ?><div class="alert alert-success">بازیابی اطلاعات با موفقیت انجام شد. برای امنیت، دوباره وارد شوید.</div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
        <h2 class="h3 text-center mb-4">ورود مدیر سامانه</h2>
        <form action="/index.php?route=login" method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <div class="mb-3"><label for="username" class="form-label">نام کاربری</label><input id="username" name="username" class="form-control ltr-input" autocomplete="username" maxlength="50" required autofocus></div>
            <div class="mb-3"><label for="password" class="form-label">رمز عبور</label><input id="password" name="password" type="password" class="form-control ltr-input" autocomplete="current-password" required></div>
            <label class="form-check mb-4"><input class="form-check-input" type="checkbox" name="remember" value="1"><span class="form-check-label">مرا برای ۷ روز به خاطر بسپار</span></label>
            <button type="submit" class="btn btn-primary w-100">ورود به پنل مدیریت</button>
        </form></div></section><p class="text-center text-secondary mt-4 small">دسترسی محدود به مدیران مجاز</p></main>
        </body></html>
        <?php
        exit;
    }

    if ($route === 'customers_export') {
        require_admin();
        require_once dirname(__DIR__) . '/src/CustomerExport.php';
        $export = new CustomerExport();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            require_csrf();
            try {
                $ids = $_POST['fields'] ?? [];
                if (!is_array($ids)) throw new DomainException('فیلدهای خروجی نامعتبر هستند.');
                $result = $export->create($ids);
                $file=$result['file'];
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="customers-records-'.gmdate('Ymd-His').'.xlsx"');
                header('Content-Length: '.filesize($file));
                header('X-Content-Type-Options: nosniff');
                readfile($file);
                @unlink($file);
                exit;
            } catch (DomainException $ex) {
                $_SESSION['export_error']=$ex->getMessage();
                go('/index.php?route=customers_export');
            } catch (Throwable $ex) {
                error_log('[customer export] '.$ex->getMessage());
                $_SESSION['export_error']='تولید اکسل ناموفق بود؛ فضای دیسک و حافظه سرور را بررسی کنید.';
                go('/index.php?route=customers_export');
            }
        }
    }
    if ($route === 'reports_apple_id_card_pdf') {
        // Sensitive output: authenticated administrator + POST/CSRF only.
        require_admin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            exit('روش درخواست مجاز نیست.');
        }
        require_csrf();
        $requested = $_POST['record_id'] ?? null;
        if (!is_string($requested) || !preg_match('/^[1-9][0-9]{0,17}$/D', $requested)) {
            http_response_code(400);
            exit('شناسه رکورد معتبر نیست.');
        }
        $include = ($_POST['include_sensitive'] ?? '0') === '1';
        require_once dirname(__DIR__) . '/src/AppleIdCardPdf.php';
        $id = (int)$requested;
        try {
            AppleIdCardPdf::send($id,$include);
        } catch (DomainException $ex) {
            // Never include private record values in error pages.
            http_response_code(404);
            exit('رکورد قابل صدور یافت نشد.');
        }
    }
    if ($route === 'reports_apple_id_card_api') {
        require_admin();
        require_once dirname(__DIR__) . '/src/AppleIdCardReport.php';
        (new AppleIdCardReport())->api();
    }
    if ($route === 'customers_api') {
        require_admin();
        require_once dirname(__DIR__) . '/src/RecordManager.php';
        require_once dirname(__DIR__) . '/src/CustomerDirectory.php';
        (new CustomerDirectory())->api();
    }
    if (in_array($route, ['records_api', 'records_export', 'records_listing_api'], true)) {
        require_admin();
        require_once dirname(__DIR__) . '/src/RecordController.php';
        (new RecordController())->dispatch($route);
        exit;
    }

    if (!array_key_exists($route, $routes)) { http_response_code(404); exit('صفحه پیدا نشد.'); }
    $admin = require_admin();
    $site = setting('site_name', 'مدیریت Apple ID');
    $heading = $routes[$route][0];
    $recordController = null;
    if (in_array($route, ['records', 'records_fields'], true)) {
        require_once dirname(__DIR__) . '/src/RecordController.php';
        $recordController = new RecordController();
        $recordController->dispatch($route);
    }
    $customersController = null;
    if ($route === 'customers') {
        require_once dirname(__DIR__) . '/src/RecordManager.php';
        require_once dirname(__DIR__) . '/src/CustomerDirectory.php';
        $customersController = new CustomerDirectory();
    }
    $appleCardController = null;
    if ($route === 'reports_apple_id_card') {
        require_once dirname(__DIR__) . '/src/AppleIdCardReport.php';
        $appleCardController = new AppleIdCardReport();
    }
    $settingsController = null;
    if ($route === 'settings') {
        require_once dirname(__DIR__) . '/src/SiteSettings.php';
        $settingsController = new SiteSettings();
        $settingsController->dispatch((int)$admin['id']);
    }
    $backupController = null;
    if ($route === 'backup') {
        require_once dirname(__DIR__) . '/src/BackupController.php';
        $backupController = new BackupController();
        $backupController->dispatch();
    }

    function layout_start(string $site, string $heading, string $route, array $routes, array $admin): void {
        ?>
        <!doctype html><html lang="fa" dir="rtl"><head>
        <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($heading) ?> | <?= e($site) ?></title>
        <link rel="stylesheet" href="/assets/vendor/tabler/tabler.rtl.min.css">
        <link rel="stylesheet" href="/assets/css/app.css">
        <script src="/assets/vendor/tabler/tabler.min.js" defer></script>
        <script src="/assets/vendor/apexcharts/apexcharts.min.js" defer></script>
        <script src="/assets/js/app.js" defer></script>
        <?php if ($route === 'reports_apple_id_card'): ?>
        <script src="/assets/js/apple-id-card.js?v=<?=e((string)filemtime(__DIR__ . '/assets/js/apple-id-card.js'))?>" defer></script>
        <?php endif; ?>
        <?php if ($route === 'customers_export'): ?>
        <script src="/assets/js/customers-export.js?v=<?=e((string)filemtime(__DIR__ . '/assets/js/customers-export.js'))?>" defer></script>
        <?php endif; ?>
        <?php if ($route === 'customers'): ?>
        <script src="/assets/js/customers.js" defer></script>
        <?php elseif ($route === 'backup'): ?>
        <script src="/assets/js/backup.js" defer></script>
        <?php endif; ?>
        <?php if (in_array($route, ['records','records_fields'], true)): ?>
        <link rel="stylesheet" href="/assets/vendor/hcg/hcg-select.css">
        <link rel="stylesheet" href="/assets/vendor/persian-datepicker/persian-datepicker.min.css">
        <script src="/assets/vendor/jquery/jquery.min.js" defer></script>
        <script src="/assets/vendor/persian-date/persian-date.min.js" defer></script>
        <script src="/assets/vendor/persian-datepicker/persian-datepicker.min.js" defer></script>
        <script src="/assets/vendor/hcg/hcg-select.js" defer></script>
        <script src="/assets/js/records.js" defer></script>
        <?php if ($route === 'records'): ?><script src="/assets/js/records-grid.js" defer></script><?php endif; ?>
        <?php endif; ?>
        <meta name="csrf-token" content="<?=e(csrf())?>">
        </head><body class="theme-light">
        <div class="page app-shell">
        <aside class="navbar navbar-vertical navbar-expand-lg app-sidebar" data-bs-theme="dark">
          <div class="container-fluid">
            <?php require_once dirname(__DIR__) . '/src/SiteSettings.php'; $sidebarLogo=SiteSettings::logoUrl(); ?>
            <h1 class="navbar-brand navbar-brand-autodark"><a href="/index.php" class="brand-text">
              <?php if ($sidebarLogo): ?><img src="<?=e($sidebarLogo)?>" alt="لوگوی سایت" class="site-logo-sidebar"><?php else: ?><span class="brand-mini">A</span><?php endif; ?><?= e($site) ?></a></h1>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-controls="sidebar-menu" aria-expanded="false" aria-label="بازکردن منو"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="sidebar-menu"><ul class="navbar-nav pt-lg-4">
              <li class="nav-item"><a class="nav-link <?= $route === 'dashboard' ? 'active' : '' ?>" href="/index.php"><span class="nav-link-icon">▦</span><span class="nav-link-title">داشبورد</span></a></li>
              <li class="nav-item">
                <div class="nav-link <?= in_array($route,['records','records_fields'],true)?'active':'' ?>"><span class="nav-link-icon">✚</span><span class="nav-link-title">ثبت اطلاعات</span></div>
                <div class="nav-submenu">
                  <a class="nav-link nav-subitem <?= $route === 'records_fields'?'active':'' ?>" href="/index.php?route=records_fields">ایجاد فیلد</a>
                  <a class="nav-link nav-subitem <?= $route === 'records'?'active':'' ?>" href="/index.php?route=records">ایجاد رکورد</a>
                </div>
              </li>
              <li class="nav-item">
                <a class="nav-link <?=in_array($route,['reports','reports_apple_id_card'],true)?'active':''?>" href="/index.php?route=reports">
                  <span class="nav-link-icon">▤</span><span class="nav-link-title">گزارشات</span>
                </a>
                <div class="nav-submenu">
                  <a class="nav-link nav-subitem <?= $route === 'reports_apple_id_card'?'active':'' ?>"
                    href="/index.php?route=reports_apple_id_card">Apple ID Card</a>
                </div>
              </li>
              <?php foreach ($routes as $slug => $meta): if (in_array($slug,['dashboard','records','records_fields','customers_export','reports','reports_apple_id_card'],true)) continue; ?>
              <li class="nav-item"><a class="nav-link <?= $route === $slug ? 'active' : '' ?>" href="/index.php?route=<?= e($slug) ?>"><span class="nav-link-icon"><?= e(['customers' => '♙', 'reports' => '▤', 'settings' => '⚙', 'backup' => '◫'][$slug]) ?></span><span class="nav-link-title"><?= e($meta[0]) ?></span></a></li>
              <?php endforeach; ?>
            </ul></div>
          </div>
        </aside>
        <div class="page-wrapper">
          <header class="navbar navbar-expand-md d-print-none topbar"><div class="container-xl">
            <div class="d-flex align-items-center justify-content-between w-100 gap-3">
              <div><div class="text-secondary small">پنل مدیریت / <?= e($heading) ?></div><div class="fw-semibold">سلام، <?= e($admin['full_name']) ?></div></div>
              <div class="d-flex align-items-center gap-3">
                <span class="badge bg-green-lt text-green hide-small">● سامانه فعال</span>
                <form action="/index.php?route=logout" method="post"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" class="btn btn-outline-secondary btn-sm">خروج امن</button></form>
              </div>
            </div>
          </div></header>
          <main class="page-body"><div class="container-xl">
            <div class="page-header d-print-none mb-4"><div class="row align-items-center">
              <div class="col"><div class="page-pretitle">آنالیز و مدیریت داده‌ها</div><h2 class="page-title"><?= e($heading) ?></h2></div>
              <div class="col-auto text-secondary"><?= e(local_today()) ?></div>
            </div></div>
        <?php
    }
    function layout_end(): void { echo '</div></main><footer class="footer footer-transparent d-print-none"><div class="container-xl text-secondary small py-3">Apple ID Management · پنل مدیریت</div></footer></div></div></body></html>'; }

    layout_start($site, $heading, $route, $routes, $admin);
    if ($route === 'dashboard') {
        $customers = (int)db()->query('SELECT COUNT(*) FROM ' . table('customers'))->fetchColumn();
        $checkTombstones = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $checkTombstones->execute([config()['DB_PREFIX'] . 'record_deletions']);
        $hasTombstones = (int)$checkTombstones->fetchColumn() > 0;
        $records = $hasTombstones
            ? (int)db()->query('SELECT COUNT(*) FROM ' . table('apple_records') . ' r LEFT JOIN ' . table('record_deletions') . ' d ON d.record_id=r.id WHERE d.record_id IS NULL')->fetchColumn()
            : (int)db()->query('SELECT COUNT(*) FROM ' . table('apple_records'))->fetchColumn();
        $recentSql = 'SELECT r.id,r.apple_email,r.created_at,c.full_name FROM ' . table('apple_records')
            . ' r JOIN ' . table('customers') . ' c ON c.id=r.customer_id';
        if ($hasTombstones) $recentSql .= ' LEFT JOIN ' . table('record_deletions') . ' d ON d.record_id=r.id WHERE d.record_id IS NULL';
        $recent = db()->query($recentSql . ' ORDER BY r.id DESC LIMIT 6')->fetchAll();
        $series = [];
        foreach (['customers', 'apple_records'] as $key) {
            if ($key === 'apple_records' && $hasTombstones) {
                $res = db()->query('SELECT DATE(r.created_at) AS d,COUNT(*) AS total FROM ' . table('apple_records')
                    . ' r LEFT JOIN ' . table('record_deletions') . ' tomb ON tomb.record_id=r.id'
                    . ' WHERE r.created_at >= DATE_SUB(UTC_DATE(), INTERVAL 13 DAY) AND tomb.record_id IS NULL'
                    . ' GROUP BY DATE(r.created_at)')->fetchAll();
            } else {
                $res = db()->query('SELECT DATE(created_at) AS d,COUNT(*) AS total FROM ' . table($key) . ' WHERE created_at >= DATE_SUB(UTC_DATE(), INTERVAL 13 DAY) GROUP BY DATE(created_at)')->fetchAll();
            }
            $series[$key] = array_column($res, 'total', 'd');
        }
        $labels = []; $customerPoints = []; $recordPoints = [];
        for ($day = 13; $day >= 0; $day--) {
            $date = gmdate('Y-m-d', time() - ($day * 86400));
            $labels[] = jdatetime($date . ' 12:00:00', 'm/d');
            $customerPoints[] = (int)($series['customers'][$date] ?? 0);
            $recordPoints[] = (int)($series['apple_records'][$date] ?? 0);
        }
        ?>
        <div class="row row-deck row-cards">
          <div class="col-12 col-sm-6 col-lg-4"><div class="card metric-card"><div class="card-body"><div class="d-flex justify-content-between"><div class="subheader">کل اطلاعات ثبت‌شده</div><span class="metric-icon text-blue">▤</span></div><div class="h1 mt-3 mb-1"><?= e(number_format($records)) ?></div><span class="badge bg-blue-lt text-blue">رکوردهای Apple ID</span></div></div></div>
          <div class="col-12 col-sm-6 col-lg-4"><div class="card metric-card"><div class="card-body"><div class="d-flex justify-content-between"><div class="subheader">کل مشتریان</div><span class="metric-icon text-teal">♙</span></div><div class="h1 mt-3 mb-1"><?= e(number_format($customers)) ?></div><span class="badge bg-teal-lt text-teal">پرونده‌های مشتریان</span></div></div></div>
          <div class="col-12 col-sm-6 col-lg-4"><div class="card metric-card"><div class="card-body"><div class="d-flex justify-content-between"><div class="subheader">وضعیت سامانه</div><span class="metric-icon text-green">✓</span></div><div class="h2 mt-3 mb-1">فعال و ایمن</div><span class="badge bg-green-lt text-green">پنل فقط مدیر</span></div></div></div>
          <div class="col-12"><section class="card"><div class="card-header"><div><h3 class="card-title">روند ثبت اطلاعات و مشتریان</h3><div class="text-secondary small mt-1">آمار روزانه، ۱۴ روز اخیر</div></div></div>
            <div class="card-body"><div id="dashboard-chart" class="dashboard-chart" aria-label="نمودار خطی اطلاعات و مشتریان" data-labels="<?= e(json_encode($labels, JSON_UNESCAPED_UNICODE)) ?>" data-records="<?= e(json_encode($recordPoints)) ?>" data-customers="<?= e(json_encode($customerPoints)) ?>"></div></div></section></div>
          <div class="col-12"><section class="card"><div class="card-header"><h3 class="card-title">آخرین اطلاعات ثبت‌شده (۶ رکورد آخر)</h3><span class="badge bg-blue-lt text-blue me-auto">جدیدترین‌ها</span></div>
            <div class="table-responsive"><table class="table table-vcenter card-table"><thead><tr><th>شناسه</th><th>مشتری</th><th>Apple ID</th><th>تاریخ ثبت (شمسی)</th><th>وضعیت</th></tr></thead><tbody>
            <?php foreach ($recent as $item): ?><tr data-dashboard-recent-record="<?= e((string)$item['id']) ?>"><td class="text-secondary">#<?= e((string)$item['id']) ?></td><td><?= e($item['full_name']) ?></td><td dir="ltr" class="text-start"><?= e($item['apple_email'] ?: '—') ?></td><td><?= e(jdatetime($item['created_at'])) ?></td><td><span class="badge bg-green-lt text-green">ثبت‌شده</span></td></tr><?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="5" class="text-center text-secondary empty-row">هنوز اطلاعاتی ثبت نشده است؛ پس از فعال‌سازی فرم ثبت، داده‌ها اینجا نمایش داده می‌شوند.</td></tr><?php endif; ?>
            </tbody></table></div>
          </section></div>
        </div>
        <?php
    } elseif ($recordController !== null) {
        $recordController->render($route);
    } elseif ($customersController !== null) {
        $customersController->render();
    } elseif ($route === 'customers_export') {
        if (!empty($_SESSION['export_error'])) {
            echo '<div class="alert alert-danger">'.e($_SESSION['export_error']).'</div>';
            unset($_SESSION['export_error']);
        }
        $export->render();
    } elseif ($appleCardController !== null) {
        $appleCardController->render();
    } elseif ($settingsController !== null) {
        $settingsController->render();
    } elseif ($backupController !== null) {
        $backupController->render();
    } else {
        ?>
        <div class="card"><div class="card-body empty-state"><div class="empty-state-icon">◈</div><h3><?= e($heading) ?></h3><p class="text-secondary">ساختار این بخش آماده است. امکانات اختصاصی آن پس از دریافت جزئیات مرحله بعد پیاده‌سازی می‌شود.</p><span class="badge bg-yellow-lt text-yellow">در انتظار تعریف امکانات</span></div></div>
        <?php
    }
    layout_end();
} catch (Throwable $ex) {
    app_error($ex);
}
