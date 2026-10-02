<?php
// Shared bootstrap for pages and API endpoints: config, DB, session, auth and CSRF helpers.

declare(strict_types=1);

$GLOBALS['setlo_config'] = require __DIR__ . '/../config/config.php';
date_default_timezone_set(config('timezone'));

const REMEMBER_ME_SECONDS = 30 * 24 * 3600;

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

// ---------- Security headers (every page and API response) ----------
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=()');
    // Lets the Google sign-in popup report back to this page and close itself.
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
    // Scripts only from this site, jsDelivr (Vue, SweetAlert, QR) and Google sign-in. 'unsafe-eval' is required by
    // Vue's in-page templates; inline scripts are used for per-page setup.
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://accounts.google.com/gsi/client; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://accounts.google.com/gsi/style; "
        . "font-src 'self' data: https://fonts.gstatic.com; "
        . "img-src 'self' data: blob: https://*.googleusercontent.com; "
        . "connect-src 'self' https://accounts.google.com/gsi/ https://cdn.jsdelivr.net https://fonts.googleapis.com https://fonts.gstatic.com; "
        . "frame-src https://accounts.google.com/gsi/; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; "
        . "form-action 'self'; worker-src 'self'; manifest-src 'self'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=15552000');
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Keep session data around long enough for "Remember me" (PHP's default cleans it up after 24 minutes).
    ini_set('session.gc_maxlifetime', (string) REMEMBER_ME_SECONDS);
    ini_set('session.use_strict_mode', '1');   // never adopt a session id the server didn't create
    ini_set('session.use_only_cookies', '1');
    session_name('setlo_sid');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/', 'secure' => is_https()]);
    session_start();
}

function config(string $key)
{
    $value = $GLOBALS['setlo_config'];
    foreach (explode('.', $key) as $part) {
        $value = $value[$part] ?? null;
    }
    return $value;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset={$c['charset']}";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $offset = (new DateTime())->format('P');
        $pdo->exec("SET time_zone = '$offset'");
    }
    return $pdo;
}

/**
 * Run a prepared query and return the statement.
 * Anti-SQL-injection rule for this project: every value that comes from a request (GET/POST/JSON body)
 * must travel through $params as a `?` placeholder — never be concatenated or interpolated into $sql.
 * PDO::ATTR_EMULATE_PREPARES is off (see db()), so placeholders are sent to MySQL as real bind
 * parameters, not just escaped and spliced client-side. The few places that interpolate a column name
 * or SQL fragment (api/dashboard.php, includes/receipts.php) only ever do so from a hardcoded literal,
 * guarded by an explicit whitelist check — follow that pattern if you add another one.
 */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function url(string $path): string
{
    return config('base_url') . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

// ---------- Auth ----------

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $row = q('SELECT id, full_name, email, payment_method, avatar_color, role, status FROM users WHERE id = ?', [$_SESSION['user_id']])->fetch();
            if ($row && $row['status'] === 'active') {
                $row['id'] = (int) $row['id'];
                $user = $row;
            } else {
                unset($_SESSION['user_id']);
            }
        }
    }
    return $user;
}

/** Sign the user in. With $remember the cookie lasts 30 days; otherwise it ends when the browser closes. */
function login_user(int $userId, bool $remember = false): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    unset($_SESSION['csrf']);
    if ($remember) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + REMEMBER_ME_SECONDS,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => is_https(),
        ]);
    }
}

function logout_user(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Page guard: redirect guests to the login screen. */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('pages/login');
    }
    if ($user['role'] === 'admin') {
        redirect('pages/admin-dashboard');
    }
    return $user;
}

function require_admin(): array
{
    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        redirect('pages/admin-login');
    }
    return $user;
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= strtoupper(substr($p, 0, 1));
    }
    return $out ?: '?';
}

/** Only allow in-app "continue to" targets (invite and Pay-me links), never external URLs. */
function safe_next(string $next): string
{
    return preg_match('/^(join\?code|pay\?u)=[A-Za-z0-9]{12}$/', $next) ? $next : '';
}

function first_name(string $name): string
{
    return explode(' ', trim($name))[0];
}
