<?php
// One-time achievements. Each is earned once (user_achievements) and kept for good. Whoever's request earns one
// sees its pop-up in that reply (json_ok adds them); anyone else sees theirs on the dashboard, after a notification.
// A user can hide badges and feature one shown badge as the title next to their name (users.featured_achievement).

declare(strict_types=1);

// image: artwork in assets/achievement (transparent PNG, shown on the badge card's colour)
const ACHIEVEMENTS = [
    'itemalizer'        => ['emoji' => '🧾', 'title' => 'Itemalizer',        'image' => 'itemalizer.png',        'description' => 'Split three bills item by item.'],
    'kuripot'           => ['emoji' => '🪙', 'title' => 'Kuripot',           'image' => 'kuripot.png',           'description' => 'Had the smallest share of the whole group.'],
    'glutton'           => ['emoji' => '🍔', 'title' => 'Glutton',           'image' => 'glutton.png',           'description' => 'Your share of a single bill reached ₱1,000.'],
    'one_two_three'     => ['emoji' => '🤝', 'title' => '1, 2, 3',           'image' => '1-2-3.png',             'description' => 'Someone covered your share.'],
    'samaritan'         => ['emoji' => '💚', 'title' => 'Samaritan',         'image' => 'samaritan.png',         'description' => 'Paid someone’s whole share as a treat.'],
    'split_personality' => ['emoji' => '🎭', 'title' => 'Split Personality', 'image' => 'split-personality.png', 'description' => 'Paid one debt in parts using two or more payment methods.'],
    'debt_collector'    => ['emoji' => '💰', 'title' => 'Debt Collector',    'image' => 'debt-collector.png',    'description' => 'Received money you were owed.'],
    'clean_slate'       => ['emoji' => '🧼', 'title' => 'Clean Slate',       'image' => 'clean-slate.png',       'description' => 'Paid everything off — you owe ₱0.'],
    'human_calculator'  => ['emoji' => '🧮', 'title' => 'Human Calculator',  'image' => 'human-calculator.png',  'description' => 'Fastest correct total in Receipt Race.'],
    'bullseye'          => ['emoji' => '🎯', 'title' => 'Bullseye',          'image' => 'bullseye.png',          'description' => 'Got closest to the target without going over.'],
    'main_character'    => ['emoji' => '🌟', 'title' => 'Main Character',    'image' => 'main-character.png',    'description' => 'The roulette picked your wallet to pay the whole bill.'],
    'receipt_from_hell' => ['emoji' => '😈', 'title' => 'Receipt from Hell', 'image' => 'receipt-from-hell.png', 'description' => 'Were on a single bill with 30 or more items.'],
];

const GLUTTON_CENTS = 100000;
const ITEMALIZER_BILLS = 3;
const RECEIPT_FROM_HELL_ITEMS = 30;

/** Badges earned during this request, by user id. With $userId and $code, records one. */
function achievements_earned(?int $userId = null, ?string $code = null): array
{
    static $earned = [];
    if ($userId !== null) {
        $earned[$userId][] = $code;
    }
    return $earned;
}

/** Give $userId a badge, once. Guests never get one. */
function award(int $userId, string $code, ?int $billId = null): void
{
    $n = q(
        "INSERT IGNORE INTO user_achievements (user_id, code, bill_id) SELECT id, ?, ? FROM users WHERE id = ? AND role <> 'guest'",
        [$code, $billId, $userId]
    )->rowCount();
    if ($n === 0) {
        return;
    }
    achievements_earned($userId, $code);
    $a = ACHIEVEMENTS[$code];
    notify($userId, 'achievement', "Achievement unlocked: {$a['emoji']} {$a['title']} — {$a['description']}", 'dashboard?achievements=1');
}

/** What the pop-up and the profile need for one badge. $row: its user_achievements row, if loaded. */
function achievement_payload(string $code, ?array $row = null): array
{
    return ['code' => $code, 'image' => url('assets/achievement/' . ACHIEVEMENTS[$code]['image'])] + ACHIEVEMENTS[$code] + ($row ? [
        'earned_at' => $row['earned_at'],
        'hidden'    => (bool) $row['hidden'],
    ] : []);
}

/** A user's badges in the order earned; without $withHidden, only the ones they show. */
function user_badges(int $userId, bool $withHidden): array
{
    $rows = q('SELECT * FROM user_achievements WHERE user_id = ?' . ($withHidden ? '' : ' AND hidden = 0') . ' ORDER BY earned_at, code', [$userId])->fetchAll();
    $rows = array_filter($rows, fn ($r) => isset(ACHIEVEMENTS[$r['code']])); // badges since retired stay in the table, unshown
    return array_values(array_map(fn ($r) => achievement_payload($r['code'], $r), $rows));
}

/** The featured title shown next to a name, or null. */
function featured_badge(?string $code): ?array
{
    return $code && isset(ACHIEVEMENTS[$code]) ? ['emoji' => ACHIEVEMENTS[$code]['emoji'], 'title' => ACHIEVEMENTS[$code]['title']] : null;
}

/**
 * The badges the signed-in user earned in this request, for the reply (json_ok). They are on their way to that
 * user's screen, so they count as seen.
 */
function achievements_for_reply(): array
{
    $me = current_user();
    $codes = $me ? array_values(array_unique(achievements_earned()[$me['id']] ?? [])) : [];
    if (!$codes) {
        return [];
    }
    $in = implode(',', array_fill(0, count($codes), '?'));
    q("UPDATE user_achievements SET seen_at = NOW() WHERE user_id = ? AND code IN ($in)", array_merge([$me['id']], $codes));
    return array_map('achievement_payload', $codes);
}

/** Badges for the shares of a bill that just started settling ($calc from compute_shares). */
function achievements_after_settling(array $bill, array $calc): void
{
    if (($bill['kind'] ?? 'bill') !== 'bill') {
        return;
    }
    $billId = (int) $bill['id'];

    if ($calc['split_mode'] === 'items') {
        $itemized = (int) q(
            "SELECT COUNT(*) FROM bills WHERE creator_id = ? AND kind = 'bill' AND split_mode = 'items' AND status IN ('settling','closed')",
            [$bill['creator_id']]
        )->fetchColumn();
        if ($itemized >= ITEMALIZER_BILLS) {
            award((int) $bill['creator_id'], 'itemalizer', $billId);
        }
    }

    // Receipt from Hell: everyone on a bill of 30+ item lines, whatever their share (award() skips guests).
    $items = (int) q('SELECT COUNT(*) FROM receipt_items WHERE bill_id = ?', [$billId])->fetchColumn();
    if ($items >= RECEIPT_FROM_HELL_ITEMS) {
        foreach (q('SELECT user_id FROM bill_members WHERE bill_id = ?', [$billId])->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            award((int) $uid, 'receipt_from_hell', $billId);
        }
    }

    $shares = array_filter($calc['shares'], fn ($c) => $c > 0);
    foreach ($shares as $uid => $c) {
        if ($c >= GLUTTON_CENTS) {
            award((int) $uid, 'glutton', $billId);
        }
    }
    // Kuripot: only when exactly one person has the smallest share.
    if (count($shares) >= 2) {
        $min = min($shares);
        $smallest = array_keys($shares, $min, true);
        if (count($smallest) === 1) {
            award((int) $smallest[0], 'kuripot', $billId);
        }
    }
}

/** Badges for a payment part the receiver just got. $s: the settlement (before this part), $p: the part. */
function achievements_after_payment(array $s, array $p): void
{
    $sid = (int) $s['id'];
    $billId = (int) $s['bill_id'];
    $debtor = (int) $s['from_user_id'];
    $payer = (int) $p['paid_by'];
    $status = (string) q('SELECT status FROM settlements WHERE id = ?', [$sid])->fetchColumn();

    award((int) $s['to_user_id'], 'debt_collector', $billId);

    if ($payer !== $debtor) {
        award($debtor, 'one_two_three', $billId);
        // Samaritan: the whole debt paid by this one person, as a treat.
        $others = (int) q(
            "SELECT COUNT(*) FROM settlement_payments WHERE settlement_id = ? AND status = 'confirmed' AND (paid_by <> ? OR pay_back = 1)",
            [$sid, $payer]
        )->fetchColumn();
        if ($status === 'settled' && $others === 0) {
            award($payer, 'samaritan', $billId);
        }
    }

    $methods = (int) q(
        "SELECT COUNT(DISTINCT method) FROM settlement_payments WHERE settlement_id = ? AND status = 'confirmed' AND paid_by = ? AND method <> 'credit'",
        [$sid, $debtor]
    )->fetchColumn();
    if ($methods >= 2) {
        award($debtor, 'split_personality', $billId);
    }

    if ($status === 'settled') {
        $open = (int) q("SELECT COUNT(*) FROM settlements WHERE from_user_id = ? AND status <> 'settled'", [$debtor])->fetchColumn();
        if ($open === 0) {
            award($debtor, 'clean_slate', $billId);
        }
    }
}
