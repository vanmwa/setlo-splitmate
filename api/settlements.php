<?php
// Settling a debt, in one go or in parts, each part one of three ways:
//  - online:   payer pays through PayMongo Checkout; the part counts once PayMongo reports it paid.
//  - transfer: payer marks a part Paid (ref no. / screenshot), receiver Confirms or Rejects it.
//  - cash:     receiver taps "Cash received" for the amount handed over; only the receiver can, so nobody settles their own debt.
// Any member of the bill may also pay a part of someone else's debt, optionally to be paid back (a new debt to them).
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/uploads.php';
require __DIR__ . '/../includes/paymongo.php';
require __DIR__ . '/../includes/payments.php';

const NUDGE_COOLDOWN_HOURS = 12;

$me = api_user();

const SETTLEMENT_SELECT = 'SELECT s.*, ' . SETTLEMENT_ONLINE_STARTED . ', b.name AS bill_name, b.creator_id AS bill_creator_id, fu.full_name AS from_name, fu.avatar_color AS from_color, fu.role AS from_role,
    tu.full_name AS to_name, tu.avatar_color AS to_color, tu.payment_method AS to_method, tu.payment_account AS to_account, tu.pay_code AS to_pay_code
    FROM settlements s JOIN bills b ON b.id = s.bill_id
    JOIN users fu ON fu.id = s.from_user_id JOIN users tu ON tu.id = s.to_user_id';

function load_settlement(int $id, array $me): array
{
    $s = q(SETTLEMENT_SELECT . ' WHERE s.id = ?', [$id])->fetch();
    if (!$s) {
        fail('Settlement not found.', 404);
    }
    if ($me['role'] !== 'admin' && !is_member((int) $s['bill_id'], $me['id'])) {
        fail('You are not part of this settlement.', 403);
    }
    return $s;
}

/** The settlement as the UI shows it: row, payment parts, and what's open to pay now. */
function settlement_payload(int $id, array $me): array
{
    return settlement_rows_with_parts([load_settlement($id, $me)])[0];
}

if (method() === 'GET') {
    if (isset($_GET['proof']) || isset($_GET['payment_proof'])) {
        // Proof screenshots are private to the payer, the two parties (and the app manager).
        $p = isset($_GET['payment_proof'])
            ? q('SELECT * FROM settlement_payments WHERE id = ?', [int_param('payment_proof')])->fetch()
            : null;
        $s = load_settlement($p ? (int) $p['settlement_id'] : int_param('proof'), $me);
        $actsForGuest = $s['from_role'] === 'guest' && (int) $s['bill_creator_id'] === $me['id'];
        $allowed = [(int) $s['from_user_id'], (int) $s['to_user_id'], $p ? (int) $p['paid_by'] : 0];
        if ($me['role'] !== 'admin' && !$actsForGuest && !in_array($me['id'], $allowed, true)) {
            fail('Only the sender and receiver can view this proof.', 403);
        }
        serve_upload('proofs', $p ? $p['proof_image'] : $s['proof_image']);
    }

    if (isset($_GET['id'])) {
        $s = load_settlement(int_param('id'), $me);
        $events = q(
            'SELECT e.event, e.note, e.created_at, u.full_name AS actor FROM settlement_events e
             LEFT JOIN users u ON u.id = e.actor_id WHERE e.settlement_id = ? ORDER BY e.created_at, e.id',
            [$s['id']]
        )->fetchAll();
        $others = array_values(array_filter(bill_settlements((int) $s['bill_id']), fn ($o) => $o['id'] !== (int) $s['id']));
        json_ok(['settlement' => settlement_rows_with_parts([$s])[0], 'events' => $events, 'others' => $others]);
    }

    // Includes settlements of guests on bills I created — I settle on their behalf.
    $rows = q(SETTLEMENT_SELECT . " WHERE s.from_user_id = ? OR s.to_user_id = ? OR (fu.role = 'guest' AND b.creator_id = ?)
        ORDER BY FIELD(s.status, 'awaiting', 'disputed', 'pending', 'settled'), s.created_at DESC", [$me['id'], $me['id'], $me['id']])->fetchAll();
    // Other members' open debts on my bills, which I could pay for them.
    $others = q(SETTLEMENT_SELECT . " WHERE s.status IN ('pending','awaiting') AND s.from_user_id <> ? AND s.to_user_id <> ?
        AND NOT (fu.role = 'guest' AND b.creator_id = ?)
        AND EXISTS (SELECT 1 FROM bill_members m WHERE m.bill_id = s.bill_id AND m.user_id = ?)
        ORDER BY s.created_at DESC", [$me['id'], $me['id'], $me['id'], $me['id']])->fetchAll();
    // When I last sent a reminder on each settlement, so the UI can show the cooldown.
    $nudged = q(
        "SELECT settlement_id, MAX(created_at) FROM settlement_events WHERE actor_id = ? AND event = 'nudged' GROUP BY settlement_id",
        [$me['id']]
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $owe = $owed = [];
    foreach (settlement_rows_with_parts($rows) as $row) {
        $row['my_last_nudge'] = $nudged[$row['id']] ?? null;
        if ($row['to']['id'] === $me['id']) {
            $owed[] = $row;
        } else {
            $owe[] = $row; // mine, or a guest's I pay on behalf of
        }
    }
    $cover = array_values(array_filter(settlement_rows_with_parts($others), fn ($r) => $r['open'] > 0 || $r['online_started']));
    json_ok(['owe' => $owe, 'owed' => $owed, 'others_open' => $cover]);
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

$s = load_settlement(int_param('id'), $me);
$id = (int) $s['id'];
$fromGuest = $s['from_role'] === 'guest';
// The Bill Creator acts for guests, who have no account.
$isSender = (int) $s['from_user_id'] === $me['id'] || ($fromGuest && (int) $s['bill_creator_id'] === $me['id']);
$onBehalf = $fromGuest ? ' on behalf of ' . first_name($s['from_name']) : '';
$isReceiver = (int) $s['to_user_id'] === $me['id'];
$myName = first_name($me['full_name']);
$debtorName = first_name($s['from_name']);
$receiverName = first_name($s['to_name']);
// Paying for someone else: any member of the bill who is neither side of this debt.
$covering = !$isSender && !$isReceiver && in_array(input('for_other'), ['1', 1, true], true) && is_member((int) $s['bill_id'], $me['id']);

/** Absolute URL of an app page, for PayMongo to send the payer back to. */
function absolute_url(string $path): string
{
    return (is_https() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . url($path);
}

/** The amount for this part, in cents: what was sent, or everything still open; between ₱1 (or $min) and what's open. */
function part_cents(array $s, int $min = 100): int
{
    $open = open_cents($s);
    if ($open <= 0) {
        fail('Nothing is left to pay right now — a payment is already waiting for confirmation.', 409);
    }
    $raw = input('amount');
    $cents = $raw === null || $raw === '' ? $open : cents($raw);
    if ($cents > $open) {
        fail('That’s more than the ' . peso_str($open) . ' still to pay.', 422, ['fields' => ['amount' => 'At most ' . peso_str($open) . '.']]);
    }
    if ($cents < min($min, $open)) {
        fail('Pay at least ' . peso_str(min($min, $open)) . '.', 422, ['fields' => ['amount' => 'At least ' . peso_str(min($min, $open)) . '.']]);
    }
    return $cents;
}

/** "₱500 (part, ₱700 left)" style wording for event notes and notifications. */
function part_words(int $cents, array $s): string
{
    $left = cents($s['amount']) - cents($s['paid_amount']) - $cents;
    return peso_str($cents) . ($left > 0 ? ' (part — ' . peso_str($left) . ' left after it)' : '');
}

$pdo = db();
switch (input('action', '')) {
    case 'mark_paid':
        if (!$isSender && !$covering) {
            fail('Only the person who owes can mark this as paid.', 403);
        }
        $ref = trim(str_input('payment_ref', 60, false));
        require_valid(valid_ref($ref), 'payment_ref');
        $proof = save_uploaded_image('proof', 'proofs', 'settlement' . $id, false);
        $pdo->beginTransaction();
        try {
            $locked = lock_settlement($id);
            if (!in_array($locked['status'], ['pending', 'awaiting'], true)) {
                fail($locked['status'] === 'disputed' ? 'Review the dispute and resend first.' : 'This settlement isn’t waiting for payment.', 409);
            }
            $cents = part_cents($locked);
            $paidBy = $covering ? $me['id'] : (int) $s['from_user_id'];
            add_payment($locked, $paidBy, $cents, 'transfer', 'awaiting', ['ref' => $ref ?: null, 'proof' => $proof, 'pay_back' => $covering && input('pay_back')]);
        } catch (ApiError $e) {
            $pdo->rollBack();
            delete_upload('proofs', $proof);
            throw $e;
        }
        $details = array_filter([$ref ? "ref no. $ref" : null, $proof ? 'proof attached' : null]);
        $who = $covering ? "$myName (for $debtorName)" : "$myName$onBehalf";
        log_event($id, $me['id'], 'marked_paid', "$who marked " . part_words($cents, $locked) . " as sent to $receiverName" . ($details ? ' (' . implode(', ', $details) . ')' : '') . '.');
        if ($covering) {
            log_event($id, $me['id'], 'covered', "$myName is paying " . peso_str($cents) . " of {$debtorName}’s debt" . (input('pay_back') ? "; $debtorName will pay $myName back." : ' as a treat.'));
        }
        refresh_settlement_status($id);
        notify((int) $s['to_user_id'], 'paid', "$who marked " . peso_str($cents) . " as Paid for {$s['bill_name']}. Confirm you received it.", 'my-settlements?tab=owed');
        $pdo->commit();
        break;

    case 'confirm':
        if (!$isReceiver) {
            fail('Only the receiver can confirm this payment.', 403);
        }
        $pdo->beginTransaction();
        $locked = lock_settlement($id);
        $p = load_payment($id, input('payment_id') ? (int) input('payment_id') : null, 'awaiting');
        confirm_payment($locked, $p, $me['id']);
        $left = settlement_payload($id, $me)['remaining'];
        log_event($id, $me['id'], 'confirmed', "$myName confirmed receiving " . peso_str(cents($p['amount'])) . '.' . ($left > 0 ? ' ' . peso_str(cents($left)) . ' still to pay.' : ' Settlement closed.'));
        foreach (array_unique([(int) $s['from_user_id'], (int) $p['paid_by']]) as $uid) {
            notify($uid, 'confirmed', "$myName confirmed your " . peso_str(cents($p['amount'])) . " payment for {$s['bill_name']}.", 'settlement-audit?id=' . $id);
        }
        $pdo->commit();
        break;

    case 'reject':
        if (!$isReceiver) {
            fail('Only the receiver can reject this payment.', 403);
        }
        $reason = str_input('reason', 500);
        if (strlen($reason) < 5) {
            fail('Please explain in at least 5 characters.', 422, ['fields' => ['reason' => 'Please explain in at least 5 characters.']]);
        }
        $pdo->beginTransaction();
        $locked = lock_settlement($id);
        $p = load_payment($id, input('payment_id') ? (int) input('payment_id') : null, 'awaiting');
        reject_payment($locked, $p, $reason);
        log_event($id, $me['id'], 'disputed', $reason);
        foreach (array_unique([(int) $s['from_user_id'], (int) $p['paid_by']]) as $uid) {
            notify($uid, 'disputed', "$myName disputed the " . peso_str(cents($p['amount'])) . " payment for {$s['bill_name']}.", 'my-settlements');
        }
        $pdo->commit();
        break;

    case 'resend':
        if (!$isSender) {
            fail('Only the sender can resend this payment.', 403);
        }
        $pdo->beginTransaction();
        if (q("UPDATE settlements SET status = 'pending' WHERE id = ? AND status = 'disputed'", [$id])->rowCount() === 0) {
            fail('This settlement changed in the meantime. Refresh and try again.', 409);
        }
        refresh_settlement_status($id);
        log_event($id, $me['id'], 'resent', "$myName reviewed the dispute and reopened the payment.");
        notify((int) $s['to_user_id'], 'resent', "$myName reopened the disputed payment for {$s['bill_name']}.", 'my-settlements?tab=owed');
        $pdo->commit();
        break;

    case 'settle_cash':
    case 'record': // older name, used when only guests' cash could be recorded
        // Paid in person, in full or in part. No proof is possible, so only the receiver can record it.
        if (!$isReceiver) {
            fail('Only the person who received the cash can settle this.', 403);
        }
        $pdo->beginTransaction();
        $locked = lock_settlement($id);
        if ($locked['status'] === 'settled') {
            fail('This settlement is already settled.', 409);
        }
        $cents = part_cents($locked);
        $pid = add_payment($locked, (int) $s['from_user_id'], $cents, 'cash', 'awaiting');
        $payer = $debtorName . ($fromGuest ? ' (guest)' : '');
        log_event($id, $me['id'], 'marked_paid', "$myName received " . part_words($cents, $locked) . " in cash from $payer.");
        confirm_payment($locked, ['id' => $pid, 'amount' => pesos($cents), 'paid_by' => $s['from_user_id'], 'pay_back' => 0], $me['id']);
        // Receiving cash ends a dispute about an earlier part.
        if ($locked['status'] === 'disputed') {
            q("UPDATE settlements SET status = 'pending' WHERE id = ? AND status = 'disputed'", [$id]);
            refresh_settlement_status($id);
        }
        $left = settlement_payload($id, $me)['remaining'];
        log_event($id, $me['id'], 'confirmed', "$myName confirmed receiving the cash." . ($left > 0 ? ' ' . peso_str(cents($left)) . ' still to pay.' : ' Settlement closed.'));
        notify((int) $s['from_user_id'], 'confirmed', "$myName confirmed receiving your " . peso_str($cents) . " cash for {$s['bill_name']}.", 'settlement-audit?id=' . $id);
        $pdo->commit();
        break;

    case 'pay_online':
        // Opens a PayMongo checkout page for this part; it counts only after PayMongo confirms payment (check_online).
        if (!$isSender && !$covering) {
            fail('Only the person who owes can pay this.', 403);
        }
        $paidBy = $covering ? $me['id'] : (int) $s['from_user_id'];
        $pdo->beginTransaction();
        $locked = lock_settlement($id);
        if (!in_array($locked['status'], ['pending', 'awaiting'], true)) {
            fail('This settlement isn’t waiting for payment.', 409);
        }
        // An earlier checkout of mine that was never paid is replaced (one that was paid is checked first, below).
        $stale = q("SELECT * FROM settlement_payments WHERE settlement_id = ? AND paid_by = ? AND status = 'started'", [$id, $paidBy])->fetchAll();
        $pdo->commit();
        foreach ($stale as $old) {
            try {
                $paidOld = paymongo_paid_payment($old['paymongo_session']);
            } catch (PayMongoException $e) {
                fail($e->getMessage(), 502);
            }
            if ($paidOld) {
                fail('An earlier online payment for this went through — tap “Check payment status” to count it first.', 409);
            }
            q("DELETE FROM settlement_payments WHERE id = ? AND status = 'started'", [$old['id']]);
        }
        $cents = part_cents($locked, PAYMONGO_MIN_CENTS);
        if ($cents < PAYMONGO_MIN_CENTS) {
            fail('Online payment needs at least ' . peso_str(PAYMONGO_MIN_CENTS) . '. Pay this one another way.', 422);
        }
        try {
            $checkout = paymongo_create_checkout(
                $cents,
                "Setlo: {$s['bill_name']}",
                ($covering ? "$myName pays for $debtorName" : $debtorName . ' pays') . ' ' . $s['to_name'] . " for {$s['bill_name']}",
                // No settlement ID in the return URL: the page re-checks every online payment in progress.
                absolute_url('pages/my-settlements?online=done'),
                absolute_url('pages/my-settlements?online=cancelled')
            );
        } catch (PayMongoException $e) {
            fail($e->getMessage(), 502);
        }
        add_payment($locked, $paidBy, $cents, 'online', 'started', ['session' => $checkout['id'], 'pay_back' => $covering && input('pay_back')]);
        json_ok(['checkout_url' => $checkout['url']]);

    case 'check_online':
        // Asks PayMongo whether the checkouts were paid; never trusts the browser's return to the success page.
        $started = q("SELECT * FROM settlement_payments WHERE settlement_id = ? AND status = 'started' ORDER BY id", [$id])->fetchAll();
        if (!$isSender && !$isReceiver && !in_array($me['id'], array_map(fn ($p) => (int) $p['paid_by'], $started), true)) {
            fail('You are not part of this settlement.', 403);
        }
        if (!$started) {
            if ($s['status'] === 'settled') {
                break; // already done (e.g. both sides checked at once)
            }
            fail('There’s no online payment to check for this settlement.', 409);
        }
        $counted = 0;
        foreach ($started as $p) {
            try {
                $paid = paymongo_paid_payment($p['paymongo_session']);
            } catch (PayMongoException $e) {
                fail($e->getMessage(), 502);
            }
            if (!$paid) {
                continue;
            }
            if ($paid['amount'] !== cents($p['amount'])) {
                error_log("[setlo] PayMongo amount mismatch on settlement payment {$p['id']}: paid {$paid['amount']}, expected " . cents($p['amount']));
                fail('The amount paid online doesn’t match this payment. Contact the app manager.', 409);
            }
            $pdo->beginTransaction();
            $locked = lock_settlement($id);
            q('UPDATE settlement_payments SET payment_ref = ? WHERE id = ?', [$paid['id'], $p['id']]);
            $payer = first_names([(int) $p['paid_by']])[(int) $p['paid_by']];
            $via = strtoupper($paid['method']) === 'PAYMAYA' ? 'Maya' : ucfirst($paid['method']);
            log_event($id, (int) $p['paid_by'], 'marked_paid', "$payer paid " . part_words($paid['amount'], $locked) . " online via PayMongo ($via, ref {$paid['id']})" . ((int) $p['paid_by'] !== (int) $s['from_user_id'] ? " for $debtorName" : '') . '.');
            confirm_payment($locked, $p, null);
            log_event($id, null, 'confirmed', 'PayMongo confirmed the payment.' . (settlement_payload($id, $me)['remaining'] > 0 ? '' : ' Settlement closed.'));
            notify((int) $s['to_user_id'], 'confirmed', "$payer paid you " . peso_str($paid['amount']) . " online for {$s['bill_name']}. Counted automatically.", 'settlement-audit?id=' . $id);
            $pdo->commit();
            $counted++;
        }
        if (!$counted) {
            fail('PayMongo hasn’t received this payment yet. If you just paid, wait a moment and check again.', 409);
        }
        break;

    case 'nudge':
        // Receiver reminds the sender to pay; sender reminds the receiver to confirm.
        if ($fromGuest) {
            fail('Guests don’t have an account, so they can’t get reminders.', 409);
        }
        $left = peso_str(cents($s['amount']) - cents($s['paid_amount']));
        if ($isReceiver && $s['status'] === 'pending') {
            [$target, $msg] = [(int) $s['from_user_id'], "$myName sent a reminder: you owe $left for {$s['bill_name']}."];
            $link = 'my-settlements';
        } elseif ($isSender && $s['status'] === 'awaiting') {
            [$target, $msg] = [(int) $s['to_user_id'], "$myName is waiting for you to confirm their payment for {$s['bill_name']}."];
            $link = 'my-settlements?tab=owed';
        } else {
            fail('Nothing to remind about right now.', 409);
        }
        $recent = q(
            "SELECT 1 FROM settlement_events WHERE settlement_id = ? AND actor_id = ? AND event = 'nudged' AND created_at > NOW() - INTERVAL " . NUDGE_COOLDOWN_HOURS . ' HOUR',
            [$id, $me['id']]
        )->fetchColumn();
        if ($recent) {
            fail('You already sent a reminder recently. Try again later.', 429);
        }
        log_event($id, $me['id'], 'nudged', "$myName sent a reminder.");
        notify($target, 'nudge', $msg, $link);
        break;

    default:
        fail('Unknown action.', 404);
}

json_ok(['settlement' => settlement_payload($id, $me)]);
