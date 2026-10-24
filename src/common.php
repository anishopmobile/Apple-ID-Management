<?php
declare(strict_types=1);

const ROOT_PATH = __DIR__ . '/..';
const STORAGE_PATH = ROOT_PATH . '/storage';
const CONFIG_FILE = STORAGE_PATH . '/config.php';
const INSTALLED_LOCK = STORAGE_PATH . '/installed.lock';

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
$clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (PHP_SAPI !== 'cli' && !$https && !in_array($clientIp, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('برای امنیت اطلاعات، دسترسی به پنل تنها از طریق HTTPS مجاز است.');
}
if ($https) { header('Strict-Transport-Security: max-age=31536000'); }
if (!is_dir(STORAGE_PATH)) {
    @mkdir(STORAGE_PATH, 0700, true);
}
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
session_name('aid_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'");

function installed(): bool { return is_file(CONFIG_FILE) && is_file(INSTALLED_LOCK); }
function installation_blocked(): bool { return is_file(CONFIG_FILE) || is_file(INSTALLED_LOCK); }
function go(string $url): never { header('Location: ' . $url, true, 303); exit; }
function e(?string $text): string { return htmlspecialchars($text ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf(): string {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['csrf'];
}
function valid_csrf(): bool {
    return is_string($_POST['_csrf'] ?? null) && hash_equals(csrf(), $_POST['_csrf']);
}
function require_csrf(): void {
    if (!valid_csrf()) { http_response_code(419); exit('نشست منقضی شده است. صفحه را تازه‌سازی کنید.'); }
}
function config(): array {
    static $config = null;
    if ($config === null) {
        if (!installed()) { throw new RuntimeException('سامانه پیکربندی نشده است.'); }
        $config = require CONFIG_FILE;
        if (!is_array($config)) { throw new RuntimeException('پیکربندی نامعتبر است.'); }
        date_default_timezone_set($config['APP_TZ'] ?? 'Asia/Tehran');
    }
    return $config;
}
function table(string $name): string {
    static $allowed = ['admins', 'settings', 'customers', 'apple_records', 'login_attempts', 'remember_tokens', 'record_deletions', 'record_values', 'record_fields', 'record_meta', 'customer_mobile_keys'];
    if (!in_array($name, $allowed, true)) { throw new InvalidArgumentException('جدول نامعتبر است'); }
    return chr(96) . config()['DB_PREFIX'] . $name . chr(96);
}
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) { $pdo = connect_db(config()); }
    return $pdo;
}
function connect_db(array $settings): PDO {
    $host = $settings['DB_HOST'];
    $port = (int)$settings['DB_PORT'];
    $name = $settings['DB_NAME'];
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $settings['DB_USER'], $settings['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
    ]);
    return $pdo;
}
function jdatetime(?string $utc, string $format = 'Y/m/d H:i'): string {
    if (!$utc) { return '—'; }
    require_once ROOT_PATH . '/src/vendor/jdf.php';
    try {
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return jdate($format, $dt->getTimestamp(), '', config()['APP_TZ'], 'fa');
    } catch (Throwable $exception) { return '—'; }
}
function local_today(): string {
    require_once ROOT_PATH . '/src/vendor/jdf.php';
    return jdate('Y/m/d', time(), '', config()['APP_TZ'], 'fa');
}
function cookie_options(int $expires): array {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    return ['expires' => $expires, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'];
}
function set_remember(int $adminId): void {
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires = new DateTimeImmutable('+7 days', new DateTimeZone('UTC'));
    $stmt = db()->prepare('INSERT INTO ' . table('remember_tokens') . ' (admin_id,selector,validator_hash,expires_at) VALUES (?,?,?,?)');
    $stmt->execute([$adminId, $selector, hash('sha256', $validator), $expires->format('Y-m-d H:i:s')]);
    setcookie('aid_remember', $selector . ':' . $validator, cookie_options($expires->getTimestamp()));
}
function clear_remember(): void {
    $value = $_COOKIE['aid_remember'] ?? '';
    if (is_string($value) && preg_match('/^([a-f0-9]{24}):[a-f0-9]{64}$/D', $value, $m) && installed()) {
        db()->prepare('DELETE FROM ' . table('remember_tokens') . ' WHERE selector=?')->execute([$m[1]]);
    }
    setcookie('aid_remember', '', cookie_options(time() - 3600));
    unset($_COOKIE['aid_remember']);
}
function login_from_cookie(): void {
    if (!empty($_SESSION['admin_id'])) { return; }
    $raw = $_COOKIE['aid_remember'] ?? '';
    if (!is_string($raw) || !preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/D', $raw, $m)) { return; }
    $query = db()->prepare('SELECT rt.admin_id,rt.validator_hash,rt.expires_at FROM ' . table('remember_tokens') . ' rt JOIN ' . table('admins') . ' a ON a.id=rt.admin_id WHERE rt.selector=? LIMIT 1');
    $query->execute([$m[1]]);
    $row = $query->fetch();
    if (!$row || strtotime($row['expires_at'] . ' UTC') < time() || !hash_equals($row['validator_hash'], hash('sha256', $m[2]))) {
        clear_remember();
        return;
    }
    db()->prepare('DELETE FROM ' . table('remember_tokens') . ' WHERE selector=?')->execute([$m[1]]);
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$row['admin_id'];
    $_SESSION['login_at'] = time();
    set_remember((int)$row['admin_id']);
}
function current_admin(): ?array {
    login_from_cookie();
    if (empty($_SESSION['admin_id'])) { return null; }
    $stmt = db()->prepare('SELECT id,full_name,username FROM ' . table('admins') . ' WHERE id=? LIMIT 1');
    $stmt->execute([(int)$_SESSION['admin_id']]);
    $row = $stmt->fetch();
    if (!$row) { unset($_SESSION['admin_id']); return null; }
    return $row;
}
function require_admin(): array {
    $admin = current_admin();
    if (!$admin) { go('/index.php?route=login'); }
    return $admin;
}
function setting(string $key, string $default = ''): string {
    $stmt = db()->prepare('SELECT value FROM ' . table('settings') . ' WHERE name=? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}
function app_error(Throwable $ex): void {
    error_log('[Apple ID Management] ' . get_class($ex) . ': ' . $ex->getMessage());
    http_response_code(500);
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>خطا</title><body style="font-family:sans-serif;padding:3rem"><h1>خطای داخلی سامانه</h1><p>لطفاً دوباره تلاش کنید یا با مدیر سرور تماس بگیرید.</p></body></html>';
}
