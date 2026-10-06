<?php
// My profile: picture, name, payment details, personal "Pay me" QR code, password.
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/uploads.php';

$me = api_user();

const PAYMENT_METHODS = ['GCash', 'Maya', 'Bank Transfer', 'Cash'];

function profile_of(int $userId): array
{
    $u = q('SELECT id, full_name, email, payment_method, payment_account, pay_code, avatar_color, avatar, featured_achievement, google_sub FROM users WHERE id = ?', [$userId])->fetch();
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
        'avatar'          => $u['avatar'], // 'pfp/<file>', 'up/<file>' or null: which picker choice is selected
    ];
}

/** Replace my picture; a gallery photo I'm moving away from is deleted. */
function set_avatar(int $userId, ?string $avatar): void
{
    $old = (string) q('SELECT avatar FROM users WHERE id = ?', [$userId])->fetchColumn();
    q('UPDATE users SET avatar = ? WHERE id = ?', [$avatar, $userId]);
    if (str_starts_with($old, 'up/') && $old !== $avatar) {
        delete_upload('avatars', substr($old, 3));
    }
}

/**
 * Money owed to me (by person, unfinished installments flagged) and what I owe. An installment is a debt being paid
 * in parts or on an installment plan with interest, not finished yet.
 */
function money_stats(int $userId): array
{
    $people = q(
        "SELECT u.id, u.full_name, u.avatar_color, u.role,
                SUM(s.amount - s.paid_amount) AS owed,
                SUM(s.paid_amount > 0 OR s.interest_rate IS NOT NULL) AS installments,
                SUM(CASE WHEN s.paid_amount > 0 OR s.interest_rate IS NOT NULL THEN s.amount - s.paid_amount ELSE 0 END) AS installment_owed
         FROM settlements s JOIN users u ON u.id = s.from_user_id
         WHERE s.to_user_id = ? AND s.status <> 'settled' GROUP BY u.id ORDER BY owed DESC",
        [$userId]
    )->fetchAll();
    $iOwe = (float) q("SELECT COALESCE(SUM(amount - paid_amount), 0) FROM settlements WHERE from_user_id = ? AND status <> 'settled'", [$userId])->fetchColumn();
    return [
        'owed_to_me'       => pesos(array_sum(array_map(fn ($p) => cents($p['owed']), $people))),
        'installment_owed' => pesos(array_sum(array_map(fn ($p) => cents($p['installment_owed']), $people))),
        'installments'     => (int) array_sum(array_column($people, 'installments')),
        'i_owe'            => round($iOwe, 2),
        'people'           => array_map(fn ($p) => public_user($p) + [
            'owed'             => (float) $p['owed'],
            'installments'     => (int) $p['installments'],
            'installment_owed' => (float) $p['installment_owed'],
        ], $people),
    ];
}

if (method() === 'GET') {
    if (isset($_GET['avatars'])) {
        // Just what the picture picker needs (first-run page)
        json_ok(['profile' => profile_of($me['id']), 'presets' => avatar_preset_list()]);
    }
    json_ok(['profile' => profile_of($me['id']), 'money' => money_stats($me['id']), 'presets' => avatar_preset_list()]);
}

/** The preset pictures for the picker: [{ key: 'pfp/<file>', url }]. */
function avatar_preset_list(): array
{
    return array_map(fn ($f) => ['key' => 'pfp/' . $f, 'url' => url('assets/pfp/' . rawurlencode($f))], avatar_presets());
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

    case 'set_avatar':
        // key: a preset ('pfp/<file>'), or '' to go back to initials
        $key = (string) input('key', '');
        if ($key !== '' && !(str_starts_with($key, 'pfp/') && in_array(substr($key, 4), avatar_presets(), true))) {
            fail('Pick one of the pictures shown.', 422);
        }
        set_avatar($me['id'], $key ?: null);
        json_ok(['profile' => profile_of($me['id'])]);

    case 'upload_avatar':
        // A photo from the phone's gallery, already cropped square and shrunk by the page (assets/js/ui.js)
        $file = save_uploaded_image('photo', 'avatars', 'u' . $me['id']);
        set_avatar($me['id'], 'up/' . $file);
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
