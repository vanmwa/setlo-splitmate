<?php
// My profile: name, payment details, personal "Pay me" QR code, password.
require __DIR__ . '/../includes/api.php';

$me = api_user();

const PAYMENT_METHODS = ['GCash', 'Maya', 'Bank Transfer', 'Cash'];

function profile_of(int $userId): array
{
    $u = q('SELECT id, full_name, email, payment_method, payment_account, pay_code, avatar_color, google_sub FROM users WHERE id = ?', [$userId])->fetch();
    if ($u['pay_code'] === null) {
        // Safety net: every account has its own Pay-me code.
        $u['pay_code'] = new_pay_code();
        q('UPDATE users SET pay_code = ? WHERE id = ?', [$u['pay_code'], $userId]);
    }
    return public_user($u) + [
        'email'           => $u['email'],
        'payment_method'  => $u['payment_method'],
        'payment_account' => $u['payment_account'],
        'pay_code'        => $u['pay_code'],
        'google_linked'   => $u['google_sub'] !== null,
    ];
}

if (method() === 'GET') {
    json_ok(['profile' => profile_of($me['id'])]);
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

switch (input('action', '')) {
    case 'update':
        $name = str_input('full_name', 100);
        $method = str_input('payment_method', 30);
        $account = str_input('payment_account', 60, false);
        $errors = array_filter([
            'full_name'       => valid_name($name, 'Full name'),
            'payment_method'  => in_array($method, PAYMENT_METHODS, true) ? '' : 'Choose a payment method.',
            'payment_account' => match ($method) {
                'Cash'  => '',
                'GCash' => valid_gcash($account),
                default => valid_account($account),
            },
        ]);
        if ($errors) {
            fail(reset($errors), 422, ['fields' => $errors]);
        }
        q('UPDATE users SET full_name = ?, payment_method = ?, payment_account = ? WHERE id = ?',
            [trim($name), $method, $method === 'Cash' ? null : (trim($account) ?: null), $me['id']]);
        json_ok(['profile' => profile_of($me['id'])]);

    case 'rotate_pay_code':
        // New QR; anyone holding the old one can no longer open your pay page.
        q('UPDATE users SET pay_code = ? WHERE id = ?', [new_pay_code(), $me['id']]);
        json_ok(['profile' => profile_of($me['id'])]);

    case 'password':
        $hash = (string) q('SELECT password_hash FROM users WHERE id = ?', [$me['id']])->fetchColumn();
        if (!password_verify((string) input('current', ''), $hash)) {
            fail('Your current password is incorrect.', 422, ['fields' => ['current' => 'Incorrect password.']]);
        }
        $new = (string) input('new', '');
        if ($e = valid_password($new)) {
            fail($e, 422, ['fields' => ['new' => $e]]);
        }
        if ($new !== (string) input('confirm', '')) {
            fail("Passwords don't match.", 422, ['fields' => ['confirm' => "Passwords don't match."]]);
        }
        if (password_verify($new, $hash)) {
            fail('Choose a password different from your current one.', 422, ['fields' => ['new' => 'Choose a different password.']]);
        }
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
        json_ok();
}

fail('Unknown action.', 404);
