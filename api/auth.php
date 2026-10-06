<?php
require __DIR__ . '/../includes/api.php';

$action = $_GET['action'] ?? input('action', '');

if (method() === 'GET' && $action === 'me') {
    $u = current_user();
    json_ok(['user' => $u ? public_user($u) + ['email' => $u['email'], 'role' => $u['role']] : null]);
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}
verify_csrf();

const LOGIN_MAX_FAILS = 5;
const LOGIN_WINDOW_MINUTES = 15;

/** Block password guessing: too many failures for this email from this IP → 429 until the window passes. */
function login_throttle(string $email, string $ip): void
{
    q('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
    $row = q(
        'SELECT COUNT(*) AS n, MIN(attempted_at) AS first FROM login_attempts
         WHERE email = ? AND ip = ? AND attempted_at > NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE',
        [$email, $ip]
    )->fetch();
    if ((int) $row['n'] >= LOGIN_MAX_FAILS) {
        $wait = max(1, (int) ceil((strtotime($row['first']) + LOGIN_WINDOW_MINUTES * 60 - time()) / 60));
        fail("Too many failed attempts. Try again in $wait minute" . ($wait === 1 ? '' : 's') . ', or ask the app manager to reset your password.', 429);
    }
}

switch ($action) {
    case 'login':
        $email = strtolower(str_input('email', 190));
        $password = (string) input('password', '');
        $asAdmin = (bool) input('admin', false);
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        login_throttle($email, $ip);

        $u = q('SELECT id, password_hash, role, status FROM users WHERE email = ?', [$email])->fetch();
        if (!$u || $password === '' || !password_verify($password, $u['password_hash'])) {
            q('INSERT INTO login_attempts (email, ip) VALUES (?, ?)', [$email, $ip]);
            $left = LOGIN_MAX_FAILS - (int) q(
                'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip = ? AND attempted_at > NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE',
                [$email, $ip]
            )->fetchColumn();
            if ($left <= 0) {
                fail('Incorrect email or password. Too many failed attempts — sign-in is locked for ' . LOGIN_WINDOW_MINUTES . ' minutes.', 429);
            }
            $warn = $left <= 2 ? " $left attempt" . ($left === 1 ? '' : 's') . ' left before a ' . LOGIN_WINDOW_MINUTES . '-minute lock.' : '';
            fail('Incorrect email or password.' . $warn, 422);
        }
        q('DELETE FROM login_attempts WHERE email = ? AND ip = ?', [$email, $ip]);
        if ($u['status'] !== 'active') {
            fail('This account is suspended. Contact the app manager.', 403);
        }
        if ($asAdmin && $u['role'] !== 'admin') {
            fail('This account does not have admin access.', 403);
        }
        login_user((int) $u['id'], (bool) input('remember', false));
        json_ok([
            'role'     => $u['role'],
            'redirect' => $u['role'] === 'admin' ? 'admin-dashboard' : 'dashboard',
            'csrf'     => csrf_token(),
        ]);

    case 'register':
        $name = str_input('full_name', 100);
        $email = strtolower(str_input('email', 190));
        $password = (string) input('password', '');
        $confirm = (string) input('password_confirm', '');

        $errors = array_filter(['full_name' => valid_name($name, 'Full name')]);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetchColumn()) {
            $errors['email'] = 'An account with this email already exists.';
        }
        if ($e = valid_password($password)) {
            $errors['password'] = $e;
        } elseif ($password !== $confirm) {
            $errors['password_confirm'] = "Passwords don't match.";
        }
        if (!input('agree')) {
            $errors['agree'] = 'Please accept the terms to continue.';
        }
        if ($errors) {
            fail('Please fix the highlighted fields.', 422, ['fields' => $errors]);
        }

        $palette = ['#0d9488', '#f43f5e', '#f59e0b', '#818cf8', '#0ea5e9', '#a855f7', '#10b981', '#ec4899'];
        q(
            "INSERT INTO users (full_name, email, password_hash, payment_method, avatar_color, pay_code) VALUES (?, ?, ?, 'GCash', ?, ?)",
            [trim($name), $email, password_hash($password, PASSWORD_DEFAULT), $palette[array_rand($palette)], new_pay_code()]
        );
        login_user((int) db()->lastInsertId(), true);
        // First stop for a new account: pick a profile picture (pages/choose-photo.php)
        json_ok(['redirect' => 'choose-photo', 'new' => true, 'csrf' => csrf_token()]);

    case 'google':
        // Sign in or sign up with a Google ID token from Google Identity Services.
        require_once __DIR__ . '/../includes/google.php';
        $claims = verify_google_id_token((string) input('credential', ''));
        $sub = (string) $claims['sub'];
        $email = strtolower((string) $claims['email']);

        $u = q('SELECT id, role, status, google_sub FROM users WHERE google_sub = ?', [$sub])->fetch()
            ?: q('SELECT id, role, status, google_sub FROM users WHERE email = ?', [$email])->fetch();
        if ($u) {
            if ($u['role'] !== 'user') {
                fail('This account can’t sign in with Google. Use the email and password instead.', 403);
            }
            if ($u['status'] !== 'active') {
                fail('This account is suspended. Contact the app manager.', 403);
            }
            if ($u['google_sub'] === null) {
                q('UPDATE users SET google_sub = ? WHERE id = ?', [$sub, $u['id']]); // link an existing email account
            }
            $userId = (int) $u['id'];
            $isNew = false;
        } else {
            $name = trim((string) ($claims['name'] ?? '')) ?: explode('@', $email)[0];
            $palette = ['#0d9488', '#f43f5e', '#f59e0b', '#818cf8', '#0ea5e9', '#a855f7', '#10b981', '#ec4899'];
            q(
                "INSERT INTO users (full_name, email, password_hash, payment_method, avatar_color, google_sub, pay_code) VALUES (?, ?, ?, 'GCash', ?, ?, ?)",
                // Random password nobody knows: the account signs in with Google (or after an admin reset).
                [function_exists('mb_substr') ? mb_substr($name, 0, 100) : substr($name, 0, 100), $email,
                 password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $palette[array_rand($palette)], $sub, new_pay_code()]
            );
            $userId = (int) db()->lastInsertId();
            $isNew = true;
        }
        login_user($userId, (bool) input('remember', true));
        json_ok(['redirect' => $isNew ? 'choose-photo' : 'dashboard', 'new' => $isNew, 'csrf' => csrf_token()]);

    case 'logout':
        logout_user();
        json_ok(['redirect' => 'login']);
}

fail('Unknown action.', 404);
