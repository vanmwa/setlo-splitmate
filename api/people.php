<?php
// A person's card, as seen by me: how to pay them, what we owe each other (unsettled installments first),
// and the payments between us. Only for people I share a bill with (or a group, once groups exist).
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/payments.php';

$me = api_user();
if (method() !== 'GET') {
    fail('Method not allowed.', 405);
}

$id = int_param('id');
$u = q('SELECT id, full_name, avatar_color, role, payment_method, payment_account, pay_code, status FROM users WHERE id = ?', [$id])->fetch();
if (!$u) {
    fail('Person not found.', 404);
}
$shared = $id === $me['id'] || $me['role'] === 'admin' || q(
    'SELECT 1 FROM bill_members a JOIN bill_members b ON b.bill_id = a.bill_id WHERE a.user_id = ? AND b.user_id = ? LIMIT 1',
    [$me['id'], $id]
)->fetchColumn();
if (!$shared) {
    fail('You can only see people you share a bill with.', 403);
}

$select = 'SELECT s.*, ' . SETTLEMENT_ONLINE_STARTED . ', b.name AS bill_name, b.creator_id AS bill_creator_id, fu.full_name AS from_name, fu.avatar_color AS from_color, fu.role AS from_role,
    tu.full_name AS to_name, tu.avatar_color AS to_color, tu.payment_method AS to_method, tu.payment_account AS to_account, tu.pay_code AS to_pay_code
    FROM settlements s JOIN bills b ON b.id = s.bill_id JOIN users fu ON fu.id = s.from_user_id JOIN users tu ON tu.id = s.to_user_id';
// Everything between the two of us, either way round.
$between = q(
    "$select WHERE (s.from_user_id = ? AND s.to_user_id = ?) OR (s.from_user_id = ? AND s.to_user_id = ?) ORDER BY s.created_at DESC",
    [$id, $me['id'], $me['id'], $id]
)->fetchAll();
$rows = settlement_rows_with_parts($between);

$open = array_values(array_filter($rows, fn ($s) => $s['status'] !== 'settled'));
$sum = fn (array $list) => pesos(array_sum(array_map(fn ($s) => cents($s['remaining']), $list)));
$theyOwe = array_values(array_filter($open, fn ($s) => $s['from']['id'] === $id));
$iOwe = array_values(array_filter($open, fn ($s) => $s['from']['id'] === $me['id']));
// An installment: being paid in parts, or on an installment plan with interest, and not finished.
$isInstallment = fn ($s) => $s['paid_amount'] > 0 || $s['interest_rate'] !== null;

$history = [];
foreach ($rows as $s) {
    foreach ($s['payments'] as $p) {
        $history[] = $p + [
            'settlement_id' => $s['id'],
            'bill_name'     => $s['bill_name'],
            'from'          => $s['from'],
            'to'            => ['id' => $s['to']['id'], 'first' => $s['to']['first'], 'name' => $s['to']['name']],
        ];
    }
}
usort($history, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']) ?: $b['id'] <=> $a['id']);

$brief = fn (array $s) => [
    'id'            => $s['id'],
    'bill_name'     => $s['bill_name'],
    'from_id'       => $s['from']['id'],
    'amount'        => $s['amount'],
    'paid_amount'   => $s['paid_amount'],
    'remaining'     => $s['remaining'],
    'interest_rate' => $s['interest_rate'],
    'status'        => $s['status'],
    'installment'   => $isInstallment($s),
];

json_ok([
    'person' => public_user($u) + [
        'payment_method'  => $u['payment_method'],
        'payment_account' => $u['payment_account'],
        'pay_code'        => $u['role'] === 'user' && $u['status'] === 'active' ? $u['pay_code'] : null,
        'is_me'           => $id === $me['id'],
    ],
    'they_owe'     => $sum($theyOwe),
    'i_owe'        => $sum($iOwe),
    'installments' => array_map($brief, array_values(array_filter($open, $isInstallment))),
    'open'         => array_map($brief, array_values(array_filter($open, fn ($s) => !$isInstallment($s)))),
    'history'      => array_slice($history, 0, 30),
]);
