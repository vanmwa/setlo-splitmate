<?php
// Payments towards a settlement. A debt can be paid in parts, each by its own method (online, transfer, cash) and
// possibly by someone else on the debtor's behalf. With installments on (settlements.interest_rate), every partial
// payment adds that % of what's still left. Callers run these inside a transaction.

declare(strict_types=1);

/** Lock a settlement row for the rest of the transaction (so two payments can't both use the same balance) and return it. */
function lock_settlement(int $id): array
{
    $s = q(
        'SELECT s.*, b.name AS bill_name, b.creator_id AS bill_creator_id FROM settlements s JOIN bills b ON b.id = s.bill_id WHERE s.id = ? FOR UPDATE',
        [$id]
    )->fetch();
    if (!$s) {
        fail('Settlement not found.', 404);
    }
    return $s;
}

/** Cents still to pay that no payment is on its way for yet (parts waiting for the receiver count as on their way). */
function open_cents(array $s): int
{
    $awaiting = (int) q(
        "SELECT COALESCE(SUM(ROUND(amount * 100)), 0) FROM settlement_payments WHERE settlement_id = ? AND status = 'awaiting'",
        [$s['id']]
    )->fetchColumn();
    return max(0, cents($s['amount']) - cents($s['paid_amount']) - $awaiting);
}

/** A payment part of a settlement in the given status. Without an id: the latest one (older clients send none). */
function load_payment(int $settlementId, ?int $paymentId, string $status): array
{
    $p = $paymentId
        ? q('SELECT * FROM settlement_payments WHERE id = ? AND settlement_id = ?', [$paymentId, $settlementId])->fetch()
        : q('SELECT * FROM settlement_payments WHERE settlement_id = ? AND status = ? ORDER BY id DESC LIMIT 1', [$settlementId, $status])->fetch();
    if (!$p || $p['status'] !== $status) {
        fail('This payment changed in the meantime. Refresh and try again.', 409);
    }
    return $p;
}

/** Record a payment part. Returns its id. */
function add_payment(array $s, int $paidBy, int $cents, string $method, string $status, array $extra = []): int
{
    q(
        'INSERT INTO settlement_payments (settlement_id, paid_by, amount, method, status, payment_ref, proof_image, paymongo_session, pay_back)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$s['id'], $paidBy, pesos($cents), $method, $status, $extra['ref'] ?? null, $extra['proof'] ?? null, $extra['session'] ?? null, (int) !empty($extra['pay_back'])]
    );
    return (int) db()->lastInsertId();
}

/**
 * Settlement status from its parts: settled once nothing is left, awaiting while a part waits for the receiver,
 * disputed until the sender resends; otherwise pending (whether or not something was paid already).
 * Also keeps paid_at, confirmed_at and settle_method ('mixed' when paid several ways) in step.
 */
function refresh_settlement_status(int $id): string
{
    $s = q('SELECT * FROM settlements WHERE id = ?', [$id])->fetch();
    $parts = q("SELECT status, method, created_at FROM settlement_payments WHERE settlement_id = ? AND status IN ('awaiting','confirmed') ORDER BY id", [$id])->fetchAll();
    $methods = array_values(array_unique(array_column(array_filter($parts, fn ($p) => $p['status'] === 'confirmed'), 'method')));
    $awaiting = array_filter($parts, fn ($p) => $p['status'] === 'awaiting');

    if (cents($s['amount']) - cents($s['paid_amount']) <= 0) {
        $status = 'settled';
    } elseif ($awaiting) {
        $status = 'awaiting';
    } elseif ($s['status'] === 'disputed') {
        $status = 'disputed';
    } else {
        $status = 'pending';
    }
    $method = count($methods) > 1 ? 'mixed' : ($methods[0] ?? null);
    $paidAt = $parts ? end($parts)['created_at'] : null;
    q(
        "UPDATE settlements SET status = ?, settle_method = ?, paid_at = ?, confirmed_at = IF(? = 'settled', COALESCE(confirmed_at, NOW()), NULL) WHERE id = ?",
        [$status, $method, $paidAt, $status, $id]
    );
    return $status;
}

/** First names by user id, for event notes and notifications. */
function first_names(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $in = implode(',', array_fill(0, count($ids), '?'));
    return array_map('first_name', q("SELECT id, full_name FROM users WHERE id IN ($in)", $ids)->fetchAll(PDO::FETCH_KEY_PAIR));
}

/**
 * The receiver got this part: count it, add interest on what's left after a partial payment, and when someone paid
 * on the debtor's behalf with "they'll pay me back", create the debt from the debtor to them.
 * $s is the settlement locked by lock_settlement() in this transaction.
 */
function confirm_payment(array $s, array $p, ?int $actorId): void
{
    $n = q("UPDATE settlement_payments SET status = 'confirmed', confirmed_at = NOW() WHERE id = ? AND status IN ('awaiting','started')", [$p['id']])->rowCount();
    if ($n === 0) {
        fail('This payment changed in the meantime. Refresh and try again.', 409);
    }
    $cents = cents($p['amount']);
    q('UPDATE settlements SET paid_amount = paid_amount + ? WHERE id = ?', [pesos($cents), $s['id']]);
    $s['paid_amount'] = pesos(cents($s['paid_amount']) + $cents);

    // Installments: interest on what's still left, unless the rest is already on its way (waiting for confirmation).
    $rate = (float) ($s['interest_rate'] ?? 0);
    if ($rate > 0 && cents($s['amount']) - cents($s['paid_amount']) > 0) {
        $base = open_cents($s);
        $interest = (int) round($base * $rate / 100);
        if ($interest > 0) {
            q('UPDATE settlements SET amount = amount + ? WHERE id = ?', [pesos($interest), $s['id']]);
            log_event((int) $s['id'], null, 'interest', peso_str($interest) . ' interest added: ' . rtrim(rtrim(number_format($rate, 2), '0'), '.') . '% of the ' . peso_str($base) . ' still to pay.');
        }
    }

    $payer = (int) $p['paid_by'];
    $debtor = (int) $s['from_user_id'];
    if ($p['pay_back'] && $payer !== $debtor) {
        $names = first_names([$payer, $debtor, (int) $s['to_user_id']]);
        q('INSERT INTO settlements (bill_id, from_user_id, to_user_id, principal, amount) VALUES (?, ?, ?, ?, ?)', [$s['bill_id'], $debtor, $payer, pesos($cents), pesos($cents)]);
        $newId = (int) db()->lastInsertId();
        log_event($newId, $actorId, 'created', "{$names[$payer]} paid " . peso_str($cents) . " of {$names[$debtor]}’s debt to {$names[(int) $s['to_user_id']]}; {$names[$debtor]} pays {$names[$payer]} back.");
        notify($debtor, 'settling', "{$names[$payer]} paid " . peso_str($cents) . " of your debt to {$names[(int) $s['to_user_id']]} for {$s['bill_name']}. You now owe {$names[$payer]} that instead.", 'my-settlements');
    }

    refresh_settlement_status((int) $s['id']);
    maybe_close_bill((int) $s['bill_id']);
}

/** The receiver says this part never arrived: the settlement is disputed until the sender resends. */
function reject_payment(array $s, array $p, string $reason): void
{
    $n = q("UPDATE settlement_payments SET status = 'rejected', reject_reason = ? WHERE id = ? AND status = 'awaiting'", [$reason, $p['id']])->rowCount();
    if ($n === 0) {
        fail('This payment changed in the meantime. Refresh and try again.', 409);
    }
    q("UPDATE settlements SET status = 'disputed', disputed_at = NOW(), dispute_reason = ? WHERE id = ?", [$reason, $s['id']]);
    refresh_settlement_status((int) $s['id']);
}

/** Payment parts per settlement id, for the UI (online checkouts not paid yet are left out). */
function settlement_parts(array $settlementIds): array
{
    if (!$settlementIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($settlementIds), '?'));
    $rows = q(
        "SELECT p.*, u.full_name AS payer_name, u.avatar_color AS payer_color FROM settlement_payments p JOIN users u ON u.id = p.paid_by
         WHERE p.settlement_id IN ($in) AND p.status <> 'started' ORDER BY p.id",
        array_values($settlementIds)
    )->fetchAll();
    $out = array_fill_keys($settlementIds, []);
    foreach ($rows as $p) {
        $out[(int) $p['settlement_id']][] = [
            'id'            => (int) $p['id'],
            'amount'        => (float) $p['amount'],
            'method'        => $p['method'],
            'status'        => $p['status'],
            'paid_by'       => public_user(['id' => $p['paid_by'], 'full_name' => $p['payer_name'], 'avatar_color' => $p['payer_color']]),
            'pay_back'      => (bool) $p['pay_back'],
            'payment_ref'   => $p['payment_ref'],
            'has_proof'     => (bool) $p['proof_image'],
            'reject_reason' => $p['reject_reason'],
            'created_at'    => $p['created_at'],
            'confirmed_at'  => $p['confirmed_at'],
        ];
    }
    return $out;
}

/** Receipt number shown on a payment's receipt, e.g. SL-000042. */
function receipt_no(int $paymentId): string
{
    return 'SL-' . str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT);
}

/**
 * Everything printed on a received payment's receipt. $s is a settlement row with the names joined in
 * (SETTLEMENT_SELECT in api/settlements.php). "Paid so far" counts received parts up to and including this one.
 */
function payment_receipt(array $p, array $s): array
{
    $paidSoFar = (int) q(
        "SELECT COALESCE(SUM(ROUND(amount * 100)), 0) FROM settlement_payments WHERE settlement_id = ? AND status = 'confirmed' AND id <= ?",
        [$s['id'], $p['id']]
    )->fetchColumn();
    $payer = q('SELECT id, full_name, avatar_color, role FROM users WHERE id = ?', [$p['paid_by']])->fetch();
    return [
        'no'          => receipt_no((int) $p['id']),
        'payment_id'  => (int) $p['id'],
        'received_at' => $p['confirmed_at'] ?? $p['created_at'],
        'amount'      => (float) $p['amount'],
        'method'      => $p['method'],
        'payment_ref' => $p['payment_ref'],
        'tendered'    => isset($p['tendered']) && $p['tendered'] !== null ? (float) $p['tendered'] : null,
        'change'      => isset($p['change_given']) && $p['change_given'] !== null ? (float) $p['change_given'] : null,
        'credit'      => isset($p['credit_kept']) && $p['credit_kept'] !== null ? (float) $p['credit_kept'] : null,
        'pay_back'    => (bool) $p['pay_back'],
        'for'         => $s['bill_name'],
        'kind'        => $s['bill_kind'] ?? 'bill',
        'from'        => public_user(['id' => $s['from_user_id'], 'full_name' => $s['from_name'], 'avatar_color' => $s['from_color'], 'role' => $s['from_role']]),
        'to'          => public_user(['id' => $s['to_user_id'], 'full_name' => $s['to_name'], 'avatar_color' => $s['to_color']]),
        'paid_by'     => public_user($payer),
        'total_owed'  => (float) $s['amount'],
        'paid_so_far' => pesos($paidSoFar),
        'left_now'    => pesos(max(0, cents($s['amount']) - cents($s['paid_amount']))),
        'settlement_id' => (int) $s['id'],
    ];
}

/** settlement_row() plus its parts and what's open to pay now, for a list of settlement rows. */
function settlement_rows_with_parts(array $rows): array
{
    $parts = settlement_parts(array_map(fn ($r) => (int) $r['id'], $rows));
    return array_map(function ($r) use ($parts) {
        $row = settlement_row($r);
        $row['payments'] = $parts[$row['id']] ?? [];
        $awaiting = array_sum(array_map(fn ($p) => cents($p['amount']), array_filter($row['payments'], fn ($p) => $p['status'] === 'awaiting')));
        $row['open'] = pesos(max(0, cents($row['remaining']) - $awaiting));
        return $row;
    }, $rows);
}
