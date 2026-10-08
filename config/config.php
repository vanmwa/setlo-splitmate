<?php
// Setlo configuration. Defaults match a stock XAMPP install; on a server, set the environment
// variables instead (e.g. `SetEnv GEMINI_API_KEY ...` in the Apache vhost) so secrets stay out of the code.
// On a local XAMPP copy, the same values can go in config/local.php instead (see local.example.php; git ignores it).

$local = is_file(__DIR__ . '/local.php') ? (array) require __DIR__ . '/local.php' : [];
$env = fn (string $name, string $default) => (string) getenv($name) !== '' ? getenv($name) : (string) ($local[$name] ?? $default);

// URL path of this project folder under Apache's document root (e.g. "/SplitMate", or "" at the domain root),
// so renaming or moving the folder doesn't break CSS/JS paths and redirects.
$detectBase = function (): string {
    $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $app = realpath(__DIR__ . '/..') ?: '';
    if ($root === '' || stripos($app, $root) !== 0) {
        return '/SplitMate'; // command line (setup/migrate scripts) or an unusual server layout
    }
    return rtrim(str_replace('\\', '/', substr($app, strlen($root))), '/');
};

return [
    'db' => [
        'host'    => $env('DB_HOST', '127.0.0.1'),
        'port'    => (int) $env('DB_PORT', '3306'),
        'name'    => $env('DB_NAME', 'setlo'),
        'user'    => $env('DB_USER', 'root'),
        'pass'    => $env('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],

    // Base URL path of the app (no trailing slash; empty when served from the domain root).
    // Detected automatically; set APP_BASE_URL on a server to override.
    'base_url' => $env('APP_BASE_URL', $detectBase()),

    'uploads' => [
        'dir'          => __DIR__ . '/../uploads',   // receipts/, qr/ and proofs/ are created inside
        'max_bytes'    => 8 * 1024 * 1024,
    ],

    // Receipt OCR via Google Gemini. Get a free API key at https://aistudio.google.com/apikey.
    // Leave the key empty to skip OCR and go straight to manual entry.
    'gemini' => [
        'api_key' => $env('GEMINI_API_KEY', ''),
        'model'   => $env('GEMINI_MODEL', 'gemini-flash-latest'),
        // Tried when the main model is overloaded (HTTP 503) or rate-limited; lighter and usually less busy.
        // Busy (503) replies come back in about a second, so a longer list costs little and finds a free model.
        'fallback_models' => ['gemini-3.6-flash', 'gemini-3.1-flash-lite-preview', 'gemini-3.1-flash-lite', 'gemini-flash-lite-latest', 'gemini-3.5-flash-lite'],
        'timeout' => 40,   // seconds per attempt; a busy model can otherwise hang for over a minute. Scans with plain names + meal sets take longer to answer
    ],

    // "Continue with Google": an OAuth 2.0 Web client ID from https://console.cloud.google.com/apis/credentials
    // (add http://localhost and your site's address under "Authorized JavaScript origins"). Empty = button explains it's not set up.
    'google' => [
        'client_id' => $env('GOOGLE_CLIENT_ID', ''),
    ],

    // "Pay online" on settlements via PayMongo Checkout (demo). Use a TEST secret key (sk_test_...) from
    // https://dashboard.paymongo.com/developers — live keys would collect real money into your own account.
    // Empty = only screenshot and cash settling are offered.
    'paymongo' => [
        'secret_key' => $env('PAYMONGO_SECRET_KEY', ''),
        'methods'    => ['gcash', 'paymaya', 'card'],
    ],

    // Outgoing email (the "Forgot password?" code) over SMTP. Gmail: smtp.gmail.com, port 587, "tls", and an App Password
    // (https://myaccount.google.com/apppasswords). Empty user/password = "Forgot password?" says email isn't set up.
    'mail' => [
        'host'      => $env('SMTP_HOST', 'smtp.gmail.com'),
        'port'      => (int) $env('SMTP_PORT', '587'),
        'secure'    => $env('SMTP_SECURE', 'tls'),   // tls = STARTTLS (587), ssl = implicit TLS (465)
        'user'      => $env('SMTP_USER', ''),
        'pass'      => $env('SMTP_PASS', ''),
        'from'      => $env('MAIL_FROM', ''),        // defaults to SMTP_USER
        'from_name' => $env('MAIL_FROM_NAME', 'Setlo'),
    ],

    'timezone' => 'Asia/Manila',
];
