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
const RESET_MAX_SENDS = 3;        // code emails per address per window
const RESET_RESEND_SECONDS = 60;
const RESET_CODE_MINUTES = 10;
const RESET_MAX_TRIES = 5;        // wrong guesses before a code is thrown away

/** Hash of a 6-digit code. Salted with the user and a server-side secret so a leaked table can't be reversed cheaply. */
function reset_code_hash(string $code, int $userId): string
{
    $db = config('db');
    return hash_hmac('sha256', $code . '|' . $userId, 'setlo-reset|' . $db['name'] . '|' . $db['pass']);
}

/** [html, text] bodies of the code email. */
function reset_email(string $name, string $code): array
{
    $n = h($name);
    $minutes = RESET_CODE_MINUTES;
    $html = "<div style=\"font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#1e293b\">"
        . "<h2 style=\"color:#0d9488;margin:0 0 12px\">setlo</h2>"
        . "<p>Hi $n,</p><p>Use this code to reset your Setlo password:</p>"
        . "<p style=\"font-size:34px;font-weight:bold;letter-spacing:8px;margin:16px 0\">$code</p>"
        . "<p style=\"font-size:13px;color:#64748b\">It works for $minutes minutes and only once. "
        . "Didn’t ask for this? Ignore this email — your password stays the same, and never share this code.</p></div>";
    $text = "Hi $name,\n\nYour Setlo password reset code is: $code\n\nIt works for $minutes minutes and only once. "
        . "Didn't ask for this? Ignore this email - your password stays the same. Never share this code.\n";
    return [$html, $text];
}

/** "oconnorv2wo@gmail.com" → "o•••••••••@gmail.com" */
function mask_email(string $email): string
{
    [$local, $domain] = explode('@', $email, 2) + ['', ''];
    return substr($local, 0, 1) . str_repeat('•', max(2, min(8, strlen($local) - 1))) . '@' . $domain;
}

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
        fail("Too many failed attempts. Try again in $wait minute" . ($wait === 1 ? '' : 's') . ', or use “Forgot password?” to get a code by email.', 429);
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

    case 'forgot':
        // Step 1: email a 6-digit code. The reply is the same whether or not the email has an account,
        // so this can't be used to find out who is registered.
        require_once __DIR__ . '/../includes/mail.php';
        $email = strtolower(str_input('email', 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fail('Enter a valid email address.', 422, ['fields' => ['email' => 'Enter a valid email address.']]);
        }
        if (!mail_configured()) {
            fail('Email isn’t set up on this server yet. Ask the app manager to reset your password.', 503);
        }
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $key = 'reset:' . $email;
        $row = q(
            'SELECT COUNT(*) AS n, MAX(attempted_at) AS last FROM login_attempts
             WHERE email = ? AND ip = ? AND attempted_at > NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE',
            [$key, $ip]
        )->fetch();
        if ($row['last'] && time() - strtotime($row['last']) < RESET_RESEND_SECONDS) {
            $wait = RESET_RESEND_SECONDS - (time() - strtotime($row['last']));
            fail("Please wait $wait second" . ($wait === 1 ? '' : 's') . ' before asking for another code.', 429, ['retry_after' => $wait]);
        }
        if ((int) $row['n'] >= RESET_MAX_SENDS) {
            fail('Too many code requests. Check your inbox (and spam folder), or try again in ' . LOGIN_WINDOW_MINUTES . ' minutes.', 429);
        }
        q('INSERT INTO login_attempts (email, ip) VALUES (?, ?)', [$key, $ip]);

        $u = q("SELECT id, full_name FROM users WHERE email = ? AND role = 'user' AND status = 'active'", [$email])->fetch();
        if ($u) {
            q('DELETE FROM password_resets WHERE user_id = ? OR expires_at < NOW() - INTERVAL 1 DAY', [$u['id']]);
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            q(
                'INSERT INTO password_resets (user_id, code_hash, expires_at, ip) VALUES (?, ?, NOW() + INTERVAL ' . RESET_CODE_MINUTES . ' MINUTE, ?)',
                [$u['id'], reset_code_hash($code, (int) $u['id']), $ip]
            );
            if (!send_mail($email, "$code is your Setlo password reset code", ...reset_email(first_name($u['full_name']), $code))) {
                q('DELETE FROM password_resets WHERE user_id = ?', [$u['id']]);
                fail('We couldn’t send the email right now. Try again in a bit, or ask the app manager to reset your password.', 503);
            }
        }
        json_ok(['masked' => mask_email($email), 'expires_minutes' => RESET_CODE_MINUTES, 'resend_after' => RESET_RESEND_SECONDS]);

    case 'verify_code':
        // Step 2: check the code. 5 wrong tries throw it away; a right one is single-use and unlocks step 3 for this browser session.
        $email = strtolower(str_input('email', 190));
        $code = preg_replace('/\D/', '', (string) input('code', ''));
        if (strlen($code) !== 6) {
            fail('Enter the 6-digit code from the email.', 422, ['fields' => ['code' => 'Enter the 6-digit code from the email.']]);
        }
        $r = q(
            "SELECT p.id, p.user_id, p.code_hash, p.attempts FROM password_resets p JOIN users u ON u.id = p.user_id
             WHERE u.email = ? AND u.role = 'user' AND u.status = 'active' AND p.expires_at > NOW()
             ORDER BY p.id DESC LIMIT 1",
            [$email]
        )->fetch();
        if (!$r || (int) $r['attempts'] >= RESET_MAX_TRIES) {
            fail('That code has expired. Go back and request a new one.', 410);
        }
        if (!hash_equals($r['code_hash'], reset_code_hash($code, (int) $r['user_id']))) {
            q('UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?', [$r['id']]);
            $left = RESET_MAX_TRIES - (int) $r['attempts'] - 1;
            fail($left > 0
                ? "That code isn’t right. $left " . ($left === 1 ? 'try' : 'tries') . ' left.'
                : 'Too many wrong codes. Go back and request a new one.', $left > 0 ? 422 : 410);
        }
        q('DELETE FROM password_resets WHERE user_id = ?', [$r['user_id']]);
        $_SESSION['pw_reset'] = ['user_id' => (int) $r['user_id'], 'until' => time() + RESET_CODE_MINUTES * 60];
        json_ok(['verified' => true]);

    case 'reset':
        // Step 3: choose the new password (only after a correct code in this same browser session).
        $grant = $_SESSION['pw_reset'] ?? null;
        if (!$grant || $grant['until'] < time()) {
            unset($_SESSION['pw_reset']);
            fail('Your reset session expired. Start again from “Forgot password?”.', 410);
        }
        $password = (string) input('password', '');
        $confirm = (string) input('password_confirm', '');
        if ($e = valid_password($password)) {
            fail($e, 422, ['fields' => ['password' => $e]]);
        }
        if ($password !== $confirm) {
            fail('Passwords don’t match.', 422, ['fields' => ['password_confirm' => 'Passwords don’t match.']]);
        }
        $u = q("SELECT id, email FROM users WHERE id = ? AND role = 'user' AND status = 'active'", [$grant['user_id']])->fetch();
        if (!$u) {
            unset($_SESSION['pw_reset']);
            fail('This account can’t be reset. Contact the app manager.', 403);
        }
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        q('DELETE FROM password_resets WHERE user_id = ?', [$u['id']]);
        q('DELETE FROM login_attempts WHERE email IN (?, ?)', [$u['email'], 'reset:' . $u['email']]);
        unset($_SESSION['pw_reset']);
        login_user((int) $u['id'], false);
        json_ok(['redirect' => 'dashboard', 'csrf' => csrf_token()]);

    case 'logout':
        logout_user();
        json_ok(['redirect' => 'login']);
}

fail('Unknown action.', 404);
