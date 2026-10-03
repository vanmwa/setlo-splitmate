<?php
// Saved groups: people who split often. New bills start from a group, and the group page keeps one trail of
// its bills and every payment on them (each received one with its receipt). Members see the group; the owner
// (who made it) renames it, adds and removes people, or deletes it (its bills stay).
require __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/payments.php';

$me = api_user();

function group_members(int $groupId): array
{
    $rows = q(
        'SELECT u.id, u.full_name, u.avatar_color, u.role FROM user_group_members m JOIN users u ON u.id = m.user_id
         WHERE m.group_id = ? ORDER BY m.joined_at, u.id',
        [$groupId]
    )->fetchAll();
    return array_map('public_user', $rows);
}

/** A group I'm in; fails otherwise. With $owner, only its owner may act. */
function my_group(array $me, bool $owner = false): array
{
    $g = q('SELECT * FROM user_groups WHERE id = ?', [int_param('id')])->fetch();
    if (!$g || !q('SELECT 1 FROM user_group_members WHERE group_id = ? AND user_id = ?', [$g['id'], $me['id']])->fetchColumn()) {
        fail('Group not found.', 404);
    }
    if ($owner && (int) $g['owner_id'] !== $me['id']) {
        fail('Only the person who made this group can change it.', 403);
    }
    $g['id'] = (int) $g['id'];
    $g['owner_id'] = (int) $g['owner_id'];
    return $g;
}

function group_name(): string
{
    $name = trim(preg_replace('/\s+/', ' ', str_input('name', 60)));
    if (mb_strlen($name) < 2 || preg_match('/[<>]/', $name)) {
        fail('Give the group a name (2–60 characters, no < or >).', 422, ['fields' => ['name' => 'Name it in 2–60 characters, no < or >.']]);
    }
    return $name;
}

/** Real, active accounts among $ids (guests can't be in a group). */
function real_users(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return array_map('intval', q("SELECT id FROM users WHERE role = 'user' AND status = 'active' AND id IN ($in)", $ids)->fetchAll(PDO::FETCH_COLUMN));
}

if (method() === 'GET') {
    if (isset($_GET['id'])) {
        $g = my_group($me);
        // Only the group's bills I'm on: a bill can include just some of the group.
        $mine = 'EXISTS (SELECT 1 FROM bill_members bm WHERE bm.bill_id = b.id AND bm.user_id = ?)';
        $bills = q("SELECT b.* FROM bills b WHERE b.group_id = ? AND $mine ORDER BY b.created_at DESC", [$g['id'], $me['id']])->fetchAll();
        // The trail: every payment on those bills, newest first.
        $trail = q(
            "SELECT p.*, s.from_user_id, s.to_user_id, b.name AS bill_name, b.id AS bill_id,
                    fu.full_name AS from_name, fu.avatar_color AS from_color, fu.role AS from_role,
                    tu.full_name AS to_name, tu.avatar_color AS to_color,
                    pu.full_name AS payer_name, pu.avatar_color AS payer_color
             FROM settlement_payments p JOIN settlements s ON s.id = p.settlement_id JOIN bills b ON b.id = s.bill_id
             JOIN users fu ON fu.id = s.from_user_id JOIN users tu ON tu.id = s.to_user_id JOIN users pu ON pu.id = p.paid_by
             WHERE b.group_id = ? AND $mine AND p.status <> 'started' ORDER BY p.created_at DESC, p.id DESC LIMIT 200",
            [$g['id'], $me['id']]
        )->fetchAll();
        $open = q(
            "SELECT COALESCE(SUM(s.amount - s.paid_amount), 0) FROM settlements s JOIN bills b ON b.id = s.bill_id WHERE b.group_id = ? AND $mine AND s.status <> 'settled'",
            [$g['id'], $me['id']]
        )->fetchColumn();
        json_ok([
            'group'   => ['id' => $g['id'], 'name' => $g['name'], 'owner_id' => $g['owner_id'], 'is_owner' => $g['owner_id'] === $me['id'], 'created_at' => $g['created_at']],
            'members' => group_members($g['id']),
            'bills'   => array_map(fn ($b) => bill_card($b) + ['link' => bill_link($b, $me['id'])], $bills),
            'open'    => round((float) $open, 2),
            'trail'   => array_map(fn ($p) => [
                'id'          => (int) $p['id'],
                'receipt_no'  => $p['status'] === 'confirmed' ? receipt_no((int) $p['id']) : null,
                'amount'      => (float) $p['amount'],
                'method'      => $p['method'],
                'status'      => $p['status'],
                'bill_id'     => (int) $p['bill_id'],
                'bill_name'   => $p['bill_name'],
                'settlement_id' => (int) $p['settlement_id'],
                'from'        => public_user(['id' => $p['from_user_id'], 'full_name' => $p['from_name'], 'avatar_color' => $p['from_color'], 'role' => $p['from_role']]),
                'to'          => public_user(['id' => $p['to_user_id'], 'full_name' => $p['to_name'], 'avatar_color' => $p['to_color']]),
                'paid_by'     => public_user(['id' => $p['paid_by'], 'full_name' => $p['payer_name'], 'avatar_color' => $p['payer_color']]),
                'has_proof'   => (bool) $p['proof_image'],
                'created_at'  => $p['created_at'],
            ], $trail),
        ]);
    }

    $groups = q(
        'SELECT g.* FROM user_groups g JOIN user_group_members m ON m.group_id = g.id WHERE m.user_id = ? ORDER BY g.name',
        [$me['id']]
    )->fetchAll();
    json_ok(['groups' => array_map(fn ($g) => [
        'id'       => (int) $g['id'],
        'name'     => $g['name'],
        'is_owner' => (int) $g['owner_id'] === $me['id'],
        'members'  => group_members((int) $g['id']),
        'bills'    => (int) q('SELECT COUNT(*) FROM bills b WHERE b.group_id = ? AND EXISTS (SELECT 1 FROM bill_members bm WHERE bm.bill_id = b.id AND bm.user_id = ?)', [$g['id'], $me['id']])->fetchColumn(),
    ], $groups)]);
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

$myName = first_name($me['full_name']);
switch (input('action', '')) {
    case 'create':
        $name = group_name();
        $others = array_values(array_diff(real_users((array) input('member_ids', [])), [$me['id']]));
        if (!$others) {
            fail('Add at least one other person.', 422, ['fields' => ['members' => 'Add at least one other person.']]);
        }
        $pdo = db();
        $pdo->beginTransaction();
        q('INSERT INTO user_groups (name, owner_id) VALUES (?, ?)', [$name, $me['id']]);
        $id = (int) $pdo->lastInsertId();
        foreach (array_merge([$me['id']], $others) as $uid) {
            q('INSERT INTO user_group_members (group_id, user_id) VALUES (?, ?)', [$id, $uid]);
        }
        foreach ($others as $uid) {
            notify($uid, 'added', "$myName added you to the group “{$name}”.", 'group?id=' . $id);
        }
        $pdo->commit();
        json_ok(['id' => $id, 'redirect' => 'group?id=' . $id]);

    case 'rename':
        $g = my_group($me, true);
        q('UPDATE user_groups SET name = ? WHERE id = ?', [group_name(), $g['id']]);
        break;

    case 'add_member':
        $g = my_group($me, true);
        $uid = real_users([int_param('user_id')])[0] ?? 0;
        if (!$uid) {
            fail('Choose someone with a Setlo account.', 422);
        }
        q('INSERT IGNORE INTO user_group_members (group_id, user_id) VALUES (?, ?)', [$g['id'], $uid]);
        notify($uid, 'added', "$myName added you to the group “{$g['name']}”.", 'group?id=' . $g['id']);
        break;

    case 'remove_member':
        $g = my_group($me, true);
        $uid = int_param('user_id');
        if ($uid === $g['owner_id']) {
            fail('You made this group — delete it instead of leaving it.', 422);
        }
        q('DELETE FROM user_group_members WHERE group_id = ? AND user_id = ?', [$g['id'], $uid]);
        break;

    case 'leave':
        $g = my_group($me);
        if ($g['owner_id'] === $me['id']) {
            fail('You made this group — delete it instead of leaving it.', 422);
        }
        q('DELETE FROM user_group_members WHERE group_id = ? AND user_id = ?', [$g['id'], $me['id']]);
        json_ok(['redirect' => 'groups']);

    case 'delete':
        // The group goes; its bills, settlements and receipts stay (they just aren't grouped any more).
        $g = my_group($me, true);
        q('DELETE FROM user_groups WHERE id = ?', [$g['id']]);
        json_ok(['redirect' => 'groups']);

    default:
        fail('Unknown action.', 404);
}

json_ok(['members' => group_members(int_param('id'))]);
