<?php
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/receipts.php';

$me = api_user();

/** Add a name-only guest (no account) to a bill. The Bill Creator settles on their behalf. */
function add_guest(int $billId, string $name): void
{
    $name = trim($name);
    require_valid(valid_name($name, 'Guest name'), 'name');
    $palette = ['#94a3b8', '#a78bfa', '#fb923c', '#34d399', '#f472b6', '#60a5fa'];
    q(
        "INSERT INTO users (full_name, email, password_hash, role, avatar_color) VALUES (?, NULL, '', 'guest', ?)",
        [$name, $palette[array_rand($palette)]]
    );
    q('INSERT INTO bill_members (bill_id, user_id) VALUES (?, ?)', [$billId, (int) db()->lastInsertId()]);
}

/** Invite codes: 12 chars from an unambiguous alphabet (no 0/O, 1/l/I). */
function new_invite_code(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
    } while (q('SELECT 1 FROM bills WHERE invite_code = ?', [$code])->fetchColumn());
    return $code;
}

function bill_by_invite(string $code): array
{
    if (!preg_match('/^[A-Za-z0-9]{12}$/', $code)) {
        fail('This invite link is invalid.', 404);
    }
    $bill = q('SELECT b.*, u.full_name AS creator_name FROM bills b JOIN users u ON u.id = b.creator_id WHERE b.invite_code = BINARY ?', [$code])->fetch();
    if (!$bill) {
        fail('This invite link is invalid or has been turned off.', 404);
    }
    $bill['id'] = (int) $bill['id'];
    return $bill;
}

// ---------- Reads ----------
if (method() === 'GET') {
    if (isset($_GET['invite'])) {
        // Preview for the join page.
        $bill = bill_by_invite((string) $_GET['invite']);
        json_ok([
            'bill' => [
                'id'        => $bill['id'],
                'name'      => $bill['name'],
                'creator'   => $bill['creator_name'],
                'status'    => $bill['status'],
                'locked'    => in_array($bill['status'], ['settling', 'closed'], true),
                'created_at'=> $bill['created_at'],
            ],
            'members'   => bill_members($bill['id']),
            'is_member' => is_member($bill['id'], $me['id']),
        ]);
    }
    if (isset($_GET['id'])) {
        $bill = bill_for(int_param('id'), $me);
        $members = bill_members($bill['id']);
        $items = bill_items($bill['id']);
        $calc = compute_shares($bill, $members, $items);
        $plan = settlement_plan($bill, $members, $calc);
        $creator = $bill['creator_id'] === $me['id'];
        $receipts = bill_receipts($bill['id']);

        json_ok([
            'bill' => [
                'id'             => $bill['id'],
                'name'           => $bill['name'],
                'status'         => $bill['status'],
                'creator_id'     => $bill['creator_id'],
                'payer_id'       => $bill['payer_id'],
                'tax'            => (float) $bill['tax'],
                'tax_included'   => $calc['tax_included'],
                'service_charge' => (float) $bill['service_charge'],
                'discount'       => (float) $bill['discount'],
                'split_mode'     => $calc['split_mode'],
                'percent_total'  => $calc['percent_total'] === null ? null : $calc['percent_total'] / 100,
                'interest_rate'  => $bill['interest_rate'] === null ? null : (float) $bill['interest_rate'],
                'invite_code'    => $creator ? $bill['invite_code'] : null,
                'receipt_total'  => $bill['receipt_total'] === null ? null : (float) $bill['receipt_total'],
                'has_receipt'    => (bool) $bill['receipt_image'] || $receipts,
                'ocr_status'     => $bill['ocr_status'],
                'created_at'     => $bill['created_at'],
                'settling_at'    => $bill['settling_at'],
                'closed_at'      => $bill['closed_at'],
                'locked'         => in_array($bill['status'], ['settling', 'closed'], true),
            ],
            'me'          => ['id' => $me['id'], 'is_creator' => $creator, 'is_payer' => $bill['payer_id'] === $me['id']],
            'members'     => $members,
            'items'       => $items,
            'receipts'    => $receipts,
            'shares'      => array_map('pesos', $calc['shares']),
            'subtotal'    => pesos($calc['subtotal']),
            'extras'      => pesos($calc['extras']),
            'discount_by' => array_map('pesos', $calc['discount_by']),
            'total'       => pesos($calc['total']),
            'unassigned'  => pesos($calc['unassigned']),
            'unassigned_count' => $calc['unassigned_count'],
            'settlements' => bill_settlements($bill['id']),
            // Who paid the restaurant and the proposed transfers (before settling starts).
            'payments'    => [
                'multi'    => (bool) q('SELECT 1 FROM bill_payments WHERE bill_id = ? LIMIT 1', [$bill['id']])->fetchColumn(),
                'paid'     => array_map('pesos', $plan['paid']),
                'total'    => pesos($plan['paid_total']),
                'mismatch' => pesos($plan['mismatch']),
            ],
            'balances'    => array_map('pesos', $plan['balances']),
            'plan'        => array_map(fn ($t) => ['from' => $t[0], 'to' => $t[1], 'amount' => pesos($t[2])], $plan['transfers']),
        ]);
    }

    $scope = $_GET['scope'] ?? 'all';
    $where = $scope === 'history' ? "AND b.status = 'closed'" : '';
    $rows = q(
        "SELECT b.* FROM bills b JOIN bill_members m ON m.bill_id = b.id
         WHERE m.user_id = ? $where
         ORDER BY FIELD(b.status, 'active', 'draft', 'settling', 'closed'), COALESCE(b.closed_at, b.created_at) DESC",
        [$me['id']]
    )->fetchAll();

    json_ok(['bills' => array_map(fn ($b) => bill_card($b) + ['link' => bill_link($b, $me['id'])], $rows)]);
}

// ---------- Writes ----------
if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

$action = input('action', '');

if ($action === 'create') {
    $name = trim(str_input('name', 120));
    require_valid(valid_bill_name($name), 'name');
    $memberIds = array_unique(array_map('intval', (array) input('member_ids', [])));
    $memberIds = array_values(array_diff($memberIds, [$me['id']]));
    $payerId = (int) input('payer_id', $me['id']);

    if ($memberIds) {
        $in = implode(',', array_fill(0, count($memberIds), '?'));
        $valid = q("SELECT id FROM users WHERE role = 'user' AND status = 'active' AND id IN ($in)", $memberIds)->fetchAll(PDO::FETCH_COLUMN);
        $memberIds = array_map('intval', $valid);
    }
    $all = array_merge([$me['id']], $memberIds);
    if (!in_array($payerId, $all, true)) {
        $payerId = $me['id'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    q('INSERT INTO bills (name, creator_id, payer_id) VALUES (?, ?, ?)', [$name, $me['id'], $payerId]);
    $billId = (int) $pdo->lastInsertId();
    foreach ($all as $uid) {
        q('INSERT INTO bill_members (bill_id, user_id) VALUES (?, ?)', [$billId, $uid]);
        if ($uid !== $me['id']) {
            notify($uid, 'added', first_name($me['full_name']) . " added you to $name.", 'bill-items?bill=' . $billId);
        }
    }
    foreach (array_slice((array) input('guest_names', []), 0, 20) as $guest) {
        add_guest($billId, (string) $guest);
    }
    $pdo->commit();
    json_ok(['id' => $billId, 'redirect' => 'scan-receipt?bill=' . $billId]);
}

if ($action === 'join') {
    $bill = bill_by_invite(str_input('code', 20));
    if (is_member($bill['id'], $me['id'])) {
        json_ok(['redirect' => 'bill-items?bill=' . $bill['id']]);
    }
    if (in_array($bill['status'], ['settling', 'closed'], true)) {
        fail('This bill is already settling, so new members can’t join.', 409);
    }
    q('INSERT INTO bill_members (bill_id, user_id) VALUES (?, ?)', [$bill['id'], $me['id']]);
    notify((int) $bill['creator_id'], 'added', first_name($me['full_name']) . " joined {$bill['name']} using your invite link.", 'bill-detail?bill=' . $bill['id']);
    json_ok(['redirect' => 'bill-items?bill=' . $bill['id']]);
}

$bill = bill_for(int_param('bill_id'), $me);

switch ($action) {
    case 'invite':
        // enable: create a link if none; rotate: replace it (old link stops working); disable: turn it off.
        require_creator($bill, $me);
        require_editable($bill);
        $mode = input('mode', 'enable');
        $code = match ($mode) {
            'enable'  => $bill['invite_code'] ?: new_invite_code(),
            'rotate'  => new_invite_code(),
            'disable' => null,
            default   => fail('Unknown invite mode.', 422),
        };
        q('UPDATE bills SET invite_code = ? WHERE id = ?', [$code, $bill['id']]);
        json_ok(['invite_code' => $code]);

    case 'set_discount_type':
        require_creator($bill, $me);
        require_editable($bill);
        $uid = int_param('user_id');
        $type = input('type', 'none');
        if (!in_array($type, ['none', 'senior', 'pwd'], true)) {
            fail('Unknown discount type.', 422);
        }
        if (!is_member($bill['id'], $uid)) {
            fail('Not a member of this bill.', 422);
        }
        q('UPDATE bill_members SET discount_type = ? WHERE bill_id = ? AND user_id = ?', [$type, $bill['id'], $uid]);
        json_ok(['members' => bill_members($bill['id'])]);
    case 'add_member':
        require_creator($bill, $me);
        require_editable($bill);
        $uid = int_param('user_id');
        $ok = q("SELECT 1 FROM users WHERE id = ? AND role = 'user' AND status = 'active'", [$uid])->fetchColumn();
        if (!$ok) {
            fail('User not found.', 404);
        }
        q('INSERT IGNORE INTO bill_members (bill_id, user_id) VALUES (?, ?)', [$bill['id'], $uid]);
        notify($uid, 'added', first_name($me['full_name']) . " added you to {$bill['name']}.", 'bill-items?bill=' . $bill['id']);
        json_ok(['members' => bill_members($bill['id'])]);

    case 'add_guest':
        require_creator($bill, $me);
        require_editable($bill);
        add_guest($bill['id'], (string) input('name', ''));
        json_ok(['members' => bill_members($bill['id'])]);

    case 'remove_member':
        require_creator($bill, $me);
        require_editable($bill);
        $uid = int_param('user_id');
        if ($uid === $bill['creator_id'] || $uid === $bill['payer_id']
            || q('SELECT 1 FROM bill_payments WHERE bill_id = ? AND user_id = ?', [$bill['id'], $uid])->fetchColumn()) {
            fail("The creator and anyone who paid the restaurant can't be removed.", 422);
        }
        q('DELETE a FROM item_assignments a JOIN receipt_items i ON i.id = a.item_id WHERE i.bill_id = ? AND a.user_id = ?', [$bill['id'], $uid]);
        q('DELETE FROM bill_members WHERE bill_id = ? AND user_id = ?', [$bill['id'], $uid]);
        // A guest only exists for this bill, so drop the placeholder account too.
        q("DELETE FROM users WHERE id = ? AND role = 'guest' AND NOT EXISTS (SELECT 1 FROM bill_members WHERE user_id = ?)", [$uid, $uid]);
        json_ok(['members' => bill_members($bill['id'])]);

    case 'set_payments':
        // payments: { user_id: amount }. One entry (or none) = a single payer who covered everything.
        require_creator($bill, $me);
        require_editable($bill);
        $raw = input('payments', []);
        if (!is_array($raw)) {
            fail('Invalid payments.', 422);
        }
        $payments = [];
        foreach ($raw as $uid => $amount) {
            $uid = (int) $uid;
            $amount = round((float) $amount, 2);
            if ($amount <= 0) {
                continue;
            }
            if (!is_member($bill['id'], $uid)) {
                fail('Payers must be members of the bill.', 422);
            }
            if (q('SELECT role FROM users WHERE id = ?', [$uid])->fetchColumn() === 'guest') {
                fail('A guest can’t be a payer — they have no account to confirm payments.', 422);
            }
            $payments[$uid] = $amount;
        }
        if (!$payments) {
            fail('Enter how much at least one person paid.', 422);
        }
        arsort($payments);
        $pdo = db();
        $pdo->beginTransaction();
        q('DELETE FROM bill_payments WHERE bill_id = ?', [$bill['id']]);
        q('UPDATE bills SET payer_id = ? WHERE id = ?', [array_key_first($payments), $bill['id']]);
        if (count($payments) > 1) {
            foreach ($payments as $uid => $amount) {
                q('INSERT INTO bill_payments (bill_id, user_id, amount) VALUES (?, ?, ?)', [$bill['id'], $uid, $amount]);
            }
        }
        $pdo->commit();
        json_ok();

    case 'set_payer':
        require_creator($bill, $me);
        require_editable($bill);
        $uid = int_param('user_id');
        if (!is_member($bill['id'], $uid)) {
            fail('The payer must be a member of the bill.', 422);
        }
        if (q("SELECT role FROM users WHERE id = ?", [$uid])->fetchColumn() === 'guest') {
            fail('A guest can’t be the payer — they have no account to confirm payments.', 422);
        }
        q('DELETE FROM bill_payments WHERE bill_id = ?', [$bill['id']]);
        q('UPDATE bills SET payer_id = ? WHERE id = ?', [$uid, $bill['id']]);
        json_ok();

    case 'set_split_mode':
        // items: each item split among who shared it · percent: each member pays a set % of the whole bill
        require_creator($bill, $me);
        require_editable($bill);
        $mode = input('mode', 'items');
        if (!in_array($mode, ['items', 'percent'], true)) {
            fail('Unknown split mode.', 422);
        }
        q('UPDATE bills SET split_mode = ? WHERE id = ?', [$mode, $bill['id']]);
        if ($mode === 'percent' && !q('SELECT 1 FROM bill_members WHERE bill_id = ? AND percent IS NOT NULL LIMIT 1', [$bill['id']])->fetchColumn()) {
            // First switch: start from an even split, leftover hundredths to the first members.
            $ids = array_column(bill_members($bill['id']), 'id');
            foreach (split_evenly(10000, $ids) as $uid => $bp) {
                q('UPDATE bill_members SET percent = ? WHERE bill_id = ? AND user_id = ?', [$bp / 100, $bill['id'], $uid]);
            }
        }
        json_ok(['members' => bill_members($bill['id'])]);

    case 'set_percents':
        // percents: { user_id: percent }. Saved as typed (they may not add up to 100 yet); settling checks the total.
        require_creator($bill, $me);
        require_editable($bill);
        $raw = input('percents', []);
        if (!is_array($raw)) {
            fail('Invalid percentages.', 422);
        }
        $memberIds = array_column(bill_members($bill['id']), 'id');
        $pdo = db();
        $pdo->beginTransaction();
        foreach ($raw as $uid => $pct) {
            $uid = (int) $uid;
            if (!in_array($uid, $memberIds, true)) {
                fail('Not a member of this bill.', 422);
            }
            $pct = $pct === null || $pct === '' ? null : round((float) $pct, 2);
            if ($pct !== null && ($pct < 0 || $pct > 100)) {
                fail('Each percentage must be between 0 and 100.', 422);
            }
            q('UPDATE bill_members SET percent = ? WHERE bill_id = ? AND user_id = ?', [$pct, $bill['id'], $uid]);
        }
        $pdo->commit();
        json_ok(['members' => bill_members($bill['id'])]);

    case 'start_settling':
        require_creator($bill, $me);
        require_editable($bill);
        // Installments with interest (optional): % of what's left, added after each partial payment.
        $rate = input('interest_rate');
        $rate = $rate === null || $rate === '' ? null : round((float) $rate, 2);
        if ($rate !== null && ($rate < 0 || $rate > 20)) {
            fail('The interest rate must be between 0% and 20%.', 422, ['fields' => ['interest' => 'Between 0% and 20%.']]);
        }
        start_settling($bill, $me, $rate);
        json_ok(['redirect' => 'bill-detail?bill=' . $bill['id']]);

    case 'delete':
        require_creator($bill, $me);
        require_editable($bill);
        delete_upload('receipts', $bill['receipt_image']);
        $photos = q('SELECT p.image FROM receipt_photos p JOIN receipts r ON r.id = p.receipt_id WHERE r.bill_id = ?', [$bill['id']])->fetchAll(PDO::FETCH_COLUMN);
        foreach (q('SELECT proof_image FROM settlements WHERE bill_id = ?', [$bill['id']])->fetchAll(PDO::FETCH_COLUMN) as $proof) {
            delete_upload('proofs', $proof);
        }
        $guests = q("SELECT u.id FROM bill_members m JOIN users u ON u.id = m.user_id WHERE m.bill_id = ? AND u.role = 'guest'", [$bill['id']])->fetchAll(PDO::FETCH_COLUMN);
        q('DELETE FROM bills WHERE id = ?', [$bill['id']]);
        delete_unused_receipt_files($photos);
        foreach ($guests as $gid) {
            q("DELETE FROM users WHERE id = ? AND role = 'guest'", [$gid]);
        }
        json_ok(['redirect' => 'my-bills']);
}

fail('Unknown action.', 404);
