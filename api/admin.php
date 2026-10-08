<?php
// Admin / App Manager panel: platform oversight, user management, dispute queue.
require __DIR__ . '/../includes/api.php';

$admin = api_admin();

if (method() === 'GET') {
    switch ($_GET['view'] ?? 'overview') {
        case 'overview':
            $bills = q('SELECT status, COUNT(*) AS n FROM bills GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
            $settle = q('SELECT status, COUNT(*) AS n FROM settlements GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
            $ocr = q("SELECT COUNT(*) AS n, COALESCE(SUM(was_corrected), 0) AS fixed FROM receipt_items WHERE source = 'ocr'")->fetch();
            $recent = q('SELECT b.*, u.full_name AS creator_name FROM bills b JOIN users u ON u.id = b.creator_id ORDER BY b.created_at DESC LIMIT 5')->fetchAll();
            json_ok([
                'users'          => (int) q("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn(),
                'users_week'     => (int) q("SELECT COUNT(*) FROM users WHERE role = 'user' AND created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn(),
                'bills'          => array_map('intval', $bills),
                'settlements'    => array_map('intval', $settle),
                'settled_volume' => (float) q("SELECT COALESCE(SUM(amount), 0) FROM settlements WHERE status = 'settled' AND confirmed_at >= NOW() - INTERVAL 30 DAY")->fetchColumn(),
                'ocr_items'      => (int) $ocr['n'],
                'ocr_corrected'  => (int) $ocr['fixed'],
                'recent'         => array_map('bill_card', $recent),
            ]);

        case 'users':
            $term = trim((string) ($_GET['q'] ?? ''));
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $rows = q(
                "SELECT u.id, u.full_name, u.email, u.avatar_color, u.status, u.created_at,
                   (SELECT COUNT(*) FROM bill_members m WHERE m.user_id = u.id) AS bills,
                   (SELECT COUNT(*) FROM settlements s WHERE s.status = 'disputed' AND (s.from_user_id = u.id OR s.to_user_id = u.id)) AS disputes
                 FROM users u WHERE u.role = 'user' AND (u.full_name LIKE ? OR u.email LIKE ?)
                 ORDER BY u.created_at DESC LIMIT 200",
                [$like, $like]
            )->fetchAll();
            json_ok(['users' => array_map(fn ($u) => public_user($u) + [
                'email'      => $u['email'],
                'status'     => $u['status'],
                'created_at' => $u['created_at'],
                'bills'      => (int) $u['bills'],
                'disputes'   => (int) $u['disputes'],
            ], $rows)]);

        case 'bills':
            $status = $_GET['status'] ?? '';
            $where = in_array($status, ['draft', 'active', 'settling', 'closed'], true) ? 'WHERE b.status = ?' : '';
            $rows = q(
                "SELECT b.*, u.full_name AS creator_name,
                   (SELECT COUNT(*) FROM settlements s WHERE s.bill_id = b.id AND s.status = 'disputed') AS disputed,
                   (SELECT COUNT(*) FROM settlements s WHERE s.bill_id = b.id AND s.status = 'awaiting') AS awaiting
                 FROM bills b JOIN users u ON u.id = b.creator_id $where ORDER BY b.created_at DESC LIMIT 200",
                $where ? [$status] : []
            )->fetchAll();
            json_ok(['bills' => array_map(fn ($b) => bill_card($b) + [
                'disputed' => (int) $b['disputed'],
                'awaiting' => (int) $b['awaiting'],
            ], $rows)]);

        case 'bill':
            $bill = bill_for(int_param('id'), $admin);
            require_once __DIR__ . '/../includes/payments.php';
            $members = bill_members($bill['id']);
            $items = bill_items($bill['id']);
            $calc = compute_shares($bill, $members, $items);
            $settlementRows = q(
                'SELECT s.*, ' . SETTLEMENT_ONLINE_STARTED . ', b.kind AS bill_kind, fu.full_name AS from_name, fu.avatar_color AS from_color, fu.role AS from_role,
                   tu.full_name AS to_name, tu.avatar_color AS to_color, tu.payment_method AS to_method, tu.payment_account AS to_account, tu.pay_code AS to_pay_code
                 FROM settlements s JOIN bills b ON b.id = s.bill_id JOIN users fu ON fu.id = s.from_user_id JOIN users tu ON tu.id = s.to_user_id
                 WHERE s.bill_id = ? ORDER BY s.id',
                [$bill['id']]
            )->fetchAll();
            $events = q(
                'SELECT e.id, e.settlement_id, e.event, e.note, e.created_at, u.full_name AS actor
                 FROM settlement_events e JOIN settlements s ON s.id = e.settlement_id LEFT JOIN users u ON u.id = e.actor_id
                 WHERE s.bill_id = ? ORDER BY e.created_at DESC, e.id DESC LIMIT 100',
                [$bill['id']]
            )->fetchAll();
            $receipts = q('SELECT id, title, store_name, receipt_no, total FROM receipts WHERE bill_id = ? ORDER BY position, id', [$bill['id']])->fetchAll();
            json_ok([
                'bill'        => bill_card($bill + ['creator_name' => q('SELECT full_name FROM users WHERE id = ?', [$bill['creator_id']])->fetchColumn()])
                    + ['kind' => $bill['kind'] ?? 'bill', 'split_mode' => $bill['split_mode'] ?? 'items', 'payer_id' => $bill['payer_id']],
                'members'     => $members,
                'items'       => $items,
                'settlements' => settlement_rows_with_parts($settlementRows),
                'events'      => array_map(fn ($e) => [
                    'id' => (int) $e['id'], 'settlement_id' => (int) $e['settlement_id'], 'event' => $e['event'],
                    'note' => $e['note'], 'actor' => $e['actor'], 'created_at' => $e['created_at'],
                ], $events),
                'receipts'    => array_map(fn ($r) => [
                    'id' => (int) $r['id'], 'title' => $r['title'], 'store' => $r['store_name'], 'no' => $r['receipt_no'],
                    'total' => $r['total'] === null ? null : (float) $r['total'],
                ], $receipts),
                'totals'      => [
                    'subtotal'     => pesos($calc['subtotal']),
                    'extras'       => pesos($calc['extras']),
                    'tax'          => $calc['tax_included'] ? 0.0 : (float) $bill['tax'],
                    'tax_included' => $calc['tax_included'],
                    'service'      => (float) $bill['service_charge'],
                    'discount'     => pesos($calc['discount']),
                    'total'        => pesos($calc['total']),
                    'unassigned'   => $calc['unassigned_count'],
                ],
                'shares'      => array_map(fn ($c) => pesos((int) $c), $calc['shares']),
            ]);

        case 'disputes':
            $rows = q(
                "SELECT s.*, b.name AS bill_name, fu.full_name AS from_name, fu.avatar_color AS from_color, fu.role AS from_role,
                   tu.full_name AS to_name, tu.avatar_color AS to_color, tu.payment_method AS to_method, tu.payment_account AS to_account, tu.pay_code AS to_pay_code
                 FROM settlements s JOIN bills b ON b.id = s.bill_id
                 JOIN users fu ON fu.id = s.from_user_id JOIN users tu ON tu.id = s.to_user_id
                 WHERE s.status = 'disputed' ORDER BY s.disputed_at DESC"
            )->fetchAll();
            json_ok(['disputes' => array_map('settlement_row', $rows)]);
    }
    fail('Unknown view.', 404);
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

switch (input('action', '')) {
    case 'set_user_status':
        $uid = int_param('user_id');
        $status = input('status') === 'suspended' ? 'suspended' : 'active';
        $n = q("UPDATE users SET status = ? WHERE id = ? AND role = 'user'", [$status, $uid])->rowCount();
        json_ok(['changed' => $n > 0]);

    case 'reset_password':
        // Fallback for "Forgot password?" (users normally get an emailed code): the app manager hands out a temporary password.
        $uid = int_param('user_id');
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $temp = '';
        for ($i = 0; $i < 10; $i++) {
            $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $n = q("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'user'", [password_hash($temp, PASSWORD_DEFAULT), $uid])->rowCount();
        if (!$n) {
            fail('User not found.', 404);
        }
        notify($uid, 'admin', 'The app manager reset your password. Sign in with the temporary password they gave you, then change it on your profile.', 'profile');
        json_ok(['temporary_password' => $temp]);

    case 'message_both':
        $s = q('SELECT s.*, b.name AS bill_name FROM settlements s JOIN bills b ON b.id = s.bill_id WHERE s.id = ?', [int_param('id')])->fetch();
        if (!$s) {
            fail('Settlement not found.', 404);
        }
        $msg = trim((string) input('message', '')) ?: "The app manager is reviewing a disputed payment on {$s['bill_name']}. Please talk it through and resolve it.";
        if (strlen($msg) > 280) {
            fail('Message is too long.', 422);
        }
        foreach ([(int) $s['from_user_id'], (int) $s['to_user_id']] as $uid) {
            notify($uid, 'admin', $msg, 'my-settlements');
        }
        log_event((int) $s['id'], $admin['id'], 'nudged', 'App manager messaged both parties.');
        json_ok();
}

fail('Unknown action.', 404);
