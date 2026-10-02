<?php
// Dashboard summary: you owe / you're owed, active bills and recent activity.
require __DIR__ . '/../includes/api.php';

$me = api_user();

// $col is interpolated into the SQL below, so it must never come from request input — only the two
// hardcoded literals this file calls it with. The whitelist check keeps that true even if this
// function is reused elsewhere later.
$sum = function (string $col) use ($me): array {
    if (!in_array($col, ['from_user_id', 'to_user_id'], true)) {
        throw new InvalidArgumentException('Invalid column for $sum().');
    }
    $r = q("SELECT COALESCE(SUM(amount - paid_amount), 0) AS total, COUNT(*) AS n FROM settlements WHERE $col = ? AND status <> 'settled'", [$me['id']])->fetch();
    return ['total' => (float) $r['total'], 'count' => (int) $r['n']];
};

$bills = q(
    "SELECT b.* FROM bills b JOIN bill_members m ON m.bill_id = b.id
     WHERE m.user_id = ? AND b.status <> 'closed' ORDER BY b.created_at DESC LIMIT 3",
    [$me['id']]
)->fetchAll();

$activity = q(
    "SELECT e.event, e.created_at, s.amount, u.full_name AS actor, u.id AS actor_id, u.role AS actor_role, tu.full_name AS to_name, b.name AS bill_name
     FROM settlement_events e
     JOIN settlements s ON s.id = e.settlement_id
     JOIN bills b ON b.id = s.bill_id
     JOIN bill_members m ON m.bill_id = b.id AND m.user_id = ?
     JOIN users tu ON tu.id = s.to_user_id
     LEFT JOIN users u ON u.id = e.actor_id
     WHERE e.event <> 'created'
     ORDER BY e.created_at DESC, e.id DESC LIMIT 6",
    [$me['id']]
)->fetchAll();

json_ok([
    'user'     => public_user($me),
    'owe'      => $sum('from_user_id'),
    'owed'     => $sum('to_user_id'),
    'bills'    => array_map(fn ($b) => bill_card($b) + ['link' => bill_link($b, $me['id'])], $bills),
    'activity' => array_map(fn ($a) => [
        'event'      => $a['event'],
        'by_admin'   => $a['actor_role'] === 'admin',
        'actor'      => $a['actor_id'] === null ? 'PayMongo' : ($a['actor_role'] === 'admin' ? 'The app manager' : ((int) $a['actor_id'] === $me['id'] ? 'You' : first_name((string) $a['actor']))),
        'to'         => first_name($a['to_name']),
        'amount'     => (float) $a['amount'],
        'bill'       => $a['bill_name'],
        'created_at' => $a['created_at'],
    ], $activity),
]);
