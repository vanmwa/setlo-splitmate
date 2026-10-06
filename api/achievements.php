<?php
// My achievements: the collection on the profile, pop-ups not shown yet (dashboard), show/hide, featured title.
require __DIR__ . '/../includes/api.php';

$me = api_user();

/** My badges, with which one is featured and which pop-ups haven't been shown yet. */
function my_achievements(int $userId): array
{
    $badges = user_badges($userId, true);
    $unseen = q('SELECT code FROM user_achievements WHERE user_id = ? AND seen_at IS NULL ORDER BY earned_at, code', [$userId])->fetchAll(PDO::FETCH_COLUMN);
    return [
        'badges'   => $badges,
        'unseen'   => array_values(array_filter($badges, fn ($b) => in_array($b['code'], $unseen, true))),
        'featured' => q('SELECT featured_achievement FROM users WHERE id = ?', [$userId])->fetchColumn() ?: null,
        'total'    => count(ACHIEVEMENTS),
    ];
}

/** The code sent, which must be a badge I have. */
function my_code(int $userId): string
{
    $code = str_input('code', 30);
    if (!isset(ACHIEVEMENTS[$code]) || !q('SELECT 1 FROM user_achievements WHERE user_id = ? AND code = ?', [$userId, $code])->fetchColumn()) {
        fail('You haven’t unlocked that badge yet.', 404);
    }
    return $code;
}

if (method() === 'GET') {
    json_ok(my_achievements($me['id']));
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

switch (input('action', '')) {
    case 'seen':
        // Their pop-ups were shown.
        $codes = array_values(array_filter((array) input('codes', []), fn ($c) => is_string($c) && isset(ACHIEVEMENTS[$c])));
        if ($codes) {
            $in = implode(',', array_fill(0, count($codes), '?'));
            q("UPDATE user_achievements SET seen_at = COALESCE(seen_at, NOW()) WHERE user_id = ? AND code IN ($in)", array_merge([$me['id']], $codes));
        }
        json_ok();

    case 'hide':
        // Hidden badges aren't shown to other people, and can't be the featured title.
        $code = my_code($me['id']);
        $hidden = in_array(input('hidden'), [true, 1, '1'], true);
        q('UPDATE user_achievements SET hidden = ? WHERE user_id = ? AND code = ?', [(int) $hidden, $me['id'], $code]);
        if ($hidden) {
            q('UPDATE users SET featured_achievement = NULL WHERE id = ? AND featured_achievement = ?', [$me['id'], $code]);
        }
        json_ok(my_achievements($me['id']));

    case 'feature':
        // The title shown next to my name; no code clears it.
        $code = null;
        if ((string) input('code', '') !== '') {
            $code = my_code($me['id']);
            if (q('SELECT hidden FROM user_achievements WHERE user_id = ? AND code = ?', [$me['id'], $code])->fetchColumn()) {
                fail('Show this badge first to use it as your title.', 409);
            }
        }
        q('UPDATE users SET featured_achievement = ? WHERE id = ?', [$code, $me['id']]);
        json_ok(my_achievements($me['id']));
}

fail('Unknown action.', 404);
