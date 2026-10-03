<?php
// Utang: money lent or borrowed outside any bill. Either side records it; it counts once the other person
// confirms. It's kept as a two-person bill of kind 'loan' (draft = waiting to be confirmed, settling = being
// paid back, closed = paid back), so paying it back is a normal settlement: in parts, any method, installment
// interest, proof and receipts.
require __DIR__ . '/../includes/api.php';

$me = api_user();

/** One of my utang, as the Utang page shows it. */
function loan_row(array $b, array $me): array
{
    $lender = (int) $b['payer_id'];
    $other = (int) q('SELECT user_id FROM bill_members WHERE bill_id = ? AND user_id <> ? LIMIT 1', [$b['id'], $me['id']])->fetchColumn();
    $people = [];
    foreach (q('SELECT id, full_name, avatar_color, role, payment_method, payment_account, pay_code FROM users WHERE id IN (?, ?)', [$me['id'], $other])->fetchAll() as $u) {
        $people[(int) $u['id']] = public_user($u);
    }
    $s = q('SELECT * FROM settlements WHERE bill_id = ? ORDER BY id LIMIT 1', [$b['id']])->fetch();
    return [
        'id'            => (int) $b['id'],
        'purpose'       => $b['name'],
        'amount'        => (float) $b['loan_amount'],
        'interest_rate' => $b['interest_rate'] === null ? null : (float) $b['interest_rate'],
        'status'        => $b['status'],
        'i_lent'        => $lender === $me['id'],
        'other'         => $people[$other] ?? null,
        'recorded_by_me'=> (int) $b['creator_id'] === $me['id'],
        'created_at'    => $b['created_at'],
        'settlement'    => $s ? [
            'id'          => (int) $s['id'],
            'status'      => $s['status'],
            'amount'      => (float) $s['amount'],
            'paid_amount' => (float) $s['paid_amount'],
            'remaining'   => pesos(max(0, cents($s['amount']) - cents($s['paid_amount']))),
        ] : null,
    ];
}

/** A loan I'm part of; fails for anything else. */
function my_loan(array $me): array
{
    $b = q("SELECT * FROM bills WHERE id = ? AND kind = 'loan'", [int_param('bill_id')])->fetch();
    if (!$b || !is_member((int) $b['id'], $me['id'])) {
        fail('Utang not found.', 404);
    }
    foreach (['id', 'creator_id', 'payer_id'] as $k) {
        $b[$k] = (int) $b[$k];
    }
    return $b;
}

if (method() === 'GET') {
    $rows = q(
        "SELECT b.* FROM bills b JOIN bill_members m ON m.bill_id = b.id WHERE m.user_id = ? AND b.kind = 'loan'
         ORDER BY FIELD(b.status, 'draft', 'settling', 'closed'), b.created_at DESC",
        [$me['id']]
    )->fetchAll();
    json_ok(['loans' => array_map(fn ($b) => loan_row($b, $me), $rows)]);
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

$myName = first_name($me['full_name']);
switch (input('action', '')) {
    case 'create':
        // direction: lent (I gave them money) or borrowed (they gave me money).
        $otherId = int_param('person_id');
        $direction = input('direction', 'lent');
        if (!in_array($direction, ['lent', 'borrowed'], true)) {
            fail('Choose whether you lent or borrowed.', 422);
        }
        $other = q("SELECT id, full_name FROM users WHERE id = ? AND role = 'user' AND status = 'active'", [$otherId])->fetch();
        if (!$other || $otherId === $me['id']) {
            fail('Choose someone with a Setlo account.', 422, ['fields' => ['person' => 'Choose someone with a Setlo account.']]);
        }
        $purpose = trim(str_input('purpose', 120));
        require_valid(valid_bill_name($purpose), 'purpose');
        $cents = cents(input('amount', 0));
        if ($cents < 100 || $cents > 100000000) {
            fail('Enter an amount from ₱1.00.', 422, ['fields' => ['amount' => 'Enter an amount from ₱1.00.']]);
        }
        // Installment interest is the lender's call.
        $rate = input('interest_rate');
        $rate = $direction === 'lent' && $rate !== null && $rate !== '' ? round((float) $rate, 2) : null;
        if ($rate !== null && ($rate <= 0 || $rate > 20)) {
            fail('The interest rate must be above 0% and up to 20%.', 422, ['fields' => ['interest' => 'Above 0% and up to 20%.']]);
        }
        $lender = $direction === 'lent' ? $me['id'] : $otherId;
        $pdo = db();
        $pdo->beginTransaction();
        q("INSERT INTO bills (name, kind, loan_amount, creator_id, payer_id, status, interest_rate) VALUES (?, 'loan', ?, ?, ?, 'draft', ?)",
            [$purpose, pesos($cents), $me['id'], $lender, $rate]);
        $billId = (int) $pdo->lastInsertId();
        q('INSERT INTO bill_members (bill_id, user_id) VALUES (?, ?), (?, ?)', [$billId, $me['id'], $billId, $otherId]);
        notify($otherId, 'added', $direction === 'lent'
            ? "$myName says they lent you " . peso_str($cents) . " ($purpose). Confirm it on the Utang page."
            : "$myName says they borrowed " . peso_str($cents) . " from you ($purpose). Confirm it on the Utang page.", 'utang');
        $pdo->commit();
        json_ok(['loan' => loan_row(q('SELECT * FROM bills WHERE id = ?', [$billId])->fetch(), $me)]);

    case 'confirm':
        // The other side agrees it happened: the borrower now owes the lender.
        $b = my_loan($me);
        if ($b['creator_id'] === $me['id']) {
            fail('The other person confirms this one.', 403);
        }
        $pdo = db();
        $pdo->beginTransaction();
        if (q("UPDATE bills SET status = 'settling', settling_at = NOW() WHERE id = ? AND status = 'draft'", [$b['id']])->rowCount() === 0) {
            fail('This utang changed in the meantime. Refresh and try again.', 409);
        }
        $lender = $b['payer_id'];
        $borrower = (int) q('SELECT user_id FROM bill_members WHERE bill_id = ? AND user_id <> ?', [$b['id'], $lender])->fetchColumn();
        $names = first_names([$lender, $borrower]);
        q('INSERT INTO settlements (bill_id, from_user_id, to_user_id, principal, amount, interest_rate) VALUES (?, ?, ?, ?, ?, ?)',
            [$b['id'], $borrower, $lender, $b['loan_amount'], $b['loan_amount'], $b['interest_rate']]);
        $sid = (int) $pdo->lastInsertId();
        log_event($sid, $me['id'], 'created', "{$names[$lender]} lent {$names[$borrower]} " . peso_str(cents($b['loan_amount'])) . " ({$b['name']}). Confirmed by $myName.");
        apply_credits($borrower, $lender, $me['id']);
        maybe_close_bill($b['id']);
        notify($b['creator_id'], 'confirmed', "$myName confirmed the " . peso_str(cents($b['loan_amount'])) . " utang ({$b['name']}).", 'utang');
        $pdo->commit();
        break;

    case 'decline':
        // The other side says it didn't happen (or not like that): it's removed, and whoever recorded it is told why.
        $b = my_loan($me);
        if ($b['creator_id'] === $me['id'] || $b['status'] !== 'draft') {
            fail('Only an unconfirmed utang recorded by the other person can be declined.', 409);
        }
        $reason = trim(str_input('reason', 200, false));
        q("DELETE FROM bills WHERE id = ? AND status = 'draft'", [$b['id']]);
        notify($b['creator_id'], 'disputed', "$myName declined the " . peso_str(cents($b['loan_amount'])) . " utang ({$b['name']})" . ($reason !== '' ? ": “{$reason}”" : '.'), 'utang');
        break;

    case 'cancel':
        // Recorded by mistake, before the other person confirmed.
        $b = my_loan($me);
        if ($b['creator_id'] !== $me['id'] || $b['status'] !== 'draft') {
            fail('Only an unconfirmed utang you recorded can be cancelled.', 409);
        }
        q("DELETE FROM bills WHERE id = ? AND status = 'draft'", [$b['id']]);
        break;

    default:
        fail('Unknown action.', 404);
}

json_ok();
