<?php
// JSON API helpers. Every file in api/ starts with: require __DIR__ . '/../includes/api.php';

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/domain.php';
require_once __DIR__ . '/validate.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

class ApiError extends Exception
{
    public int $status;
    public array $extra;

    public function __construct(string $message, int $status = 400, array $extra = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->extra = $extra;
    }
}

set_exception_handler(function (Throwable $e) {
    if ($e instanceof ApiError) {
        http_response_code($e->status);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()] + $e->extra);
        return;
    }
    error_log('[setlo] ' . $e);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong on the server.']);
});

function json_ok(array $data = []): void
{
    // Badges this request earned the signed-in user: the page shows their pop-up (assets/js/api.js).
    $earned = achievements_for_reply();
    echo json_encode(['ok' => true] + $data + ($earned ? ['achievements' => $earned] : []));
    exit;
}

function fail(string $message, int $status = 400, array $extra = []): void
{
    throw new ApiError($message, $status, $extra);
}

function method(): string
{
    return $_SERVER['REQUEST_METHOD'];
}

/** Parsed JSON body (or form fields for multipart uploads). */
function body(): array
{
    static $body = null;
    if ($body === null) {
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($type, 'application/json') !== false) {
            $body = json_decode(file_get_contents('php://input') ?: '[]', true);
            if (!is_array($body)) {
                fail('Invalid JSON body.');
            }
        } else {
            $body = $_POST;
        }
    }
    return $body;
}

function input(string $key, $default = null)
{
    return body()[$key] ?? $default;
}

function str_input(string $key, int $max = 255, bool $required = true): string
{
    $v = trim((string) input($key, ''));
    if ($required && $v === '') {
        fail("Missing field: $key", 422);
    }
    if (strlen($v) > $max) {
        fail("Field too long: $key", 422);
    }
    return $v;
}

function int_param(string $key): int
{
    $v = $_GET[$key] ?? input($key);
    if (!is_numeric($v) || (int) $v <= 0) {
        fail("Missing or invalid $key.", 422);
    }
    return (int) $v;
}

/** Requires a logged-in, active user; also enforces CSRF on state-changing requests. */
function api_user(): array
{
    $user = current_user();
    if (!$user) {
        fail('Please sign in again.', 401);
    }
    verify_csrf();
    return $user;
}

function api_admin(): array
{
    $user = api_user();
    if ($user['role'] !== 'admin') {
        fail('Admins only.', 403);
    }
    return $user;
}

function verify_csrf(): void
{
    if (method() === 'GET') {
        return;
    }
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        fail('Your session expired. Refresh the page and try again.', 403, ['code' => 'csrf']);
    }
}
