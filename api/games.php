<?php
// Fun Mode games (pages/game.php). GET polls a bill's current game (every 1–2 s while one is on);
// POST starts, plays or skips it. The rules live in includes/games.php.
require __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/games.php';

$me = api_user();

/** Everything the game screen draws: the bill, its members and the game (null before one starts). */
function game_reply(int $billId, ?array $g, array $me): array
{
    $bill = bill_for($billId, $me);
    $members = bill_members($bill['id']);
    $items = bill_items($bill['id']);
    $calc = compute_shares($bill, $members, $items);
    $creator = $bill['creator_id'] === $me['id'];
    if ($g && $g['status'] === 'done') {
        // A badge this game gave me in someone else's request (live mode): pop it up here, not just on the dashboard.
        foreach (q("SELECT code FROM user_achievements WHERE user_id = ? AND bill_id = ? AND seen_at IS NULL AND code IN ('human_calculator','bullseye','main_character')", [$me['id'], $bill['id']]) as $row) {
            achievements_earned($me['id'], $row['code']);
        }
    }
    return [
        'bill' => [
            'id'           => $bill['id'],
            'name'         => $bill['name'],
            'status'       => $bill['status'],
            'fun_mode'     => bill_fun_mode($bill),
            'has_items'    => (bool) $items,
            'total_cents'  => $calc['total'],
            'split_mode'   => $calc['split_mode'],
            'payer_id'     => $bill['payer_id'],
            'next'         => ($creator ? 'assign-items?bill=' : 'bill-items?bill=') . $bill['id'],
        ],
        'me'      => ['id' => $me['id'], 'is_creator' => $creator],
        'members' => $members,
        'shares'  => $g && $g['status'] === 'done' ? array_map('pesos', $calc['shares']) : null,
        'games'   => GAMES,
        'cards'   => CARDS,
        'game'    => $g && $g['game'] !== 'skip' ? game_payload($g, $me) : null,
    ];
}

if (method() === 'GET') {
    $bill = bill_for(int_param('bill'), $me);
    $g = current_game($bill['id']);
    if ($g) {
        $g = advance_game($g);
    }
    // Nothing new since the screen's last poll: a tiny reply. Roulette counts its knock-outs off the clock, so it
    // always gets the full one while it plays.
    if ($g && (int) ($_GET['v'] ?? 0) === $g['version'] && !($g['game'] === 'roulette' && $g['status'] === 'playing')) {
        json_ok(['unchanged' => true]);
    }
    json_ok(game_reply($bill['id'], $g, $me));
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

$bill = bill_for(int_param('bill_id'), $me);
$action = (string) input('action', '');
$for = input('for_user_id') ? (int) input('for_user_id') : null;

if ($action === 'start') {
    $mode = input('mode') === 'live' ? 'live' : 'screen';
    start_game($bill, $me, (string) input('game', ''), $mode);
    if (input('game') === 'skip') {
        json_ok(['redirect' => 'assign-items?bill=' . $bill['id']]);
    }
} else {
    $g = current_game($bill['id']);
    if (!$g || $g['game'] === 'skip') {
        fail('No game is on for this bill.', 404);
    }
    $g = advance_game($g);
    switch ($action) {
        case 'join':
            join_game($g, $me);
            break;
        case 'begin':
            begin_game($g, $me);
            break;
        case 'race_go':
            game_race_go($g, $me, $for);
            break;
        case 'answer':
            game_answer($g, $me, $for, ['amount' => input('amount'), 'basket' => input('basket', [])]);
            break;
        case 'pick_card':
            game_pick_card($g, $me, $for, (int) input('slot', -1));
            break;
        case 'choose_target':
            game_choose_target($g, $me, $for, (int) input('user_id', 0));
            break;
        case 'cancel':
            require_creator($bill, $me);
            if ($g['status'] !== 'done') {
                q("UPDATE game_sessions SET status = 'cancelled', version = version + 1 WHERE id = ?", [$g['id']]);
            }
            break;
        default:
            fail('Unknown action.', 404);
    }
}

$g = current_game($bill['id']);
json_ok(game_reply($bill['id'], $g ? advance_game($g) : null, $me));
