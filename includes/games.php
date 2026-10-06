<?php
// Fun Mode games (pages/game.php, api/games.php). A saved group with Fun Mode on offers a game after the receipt
// review; its result becomes the bill's split (bills.split_mode = 'game', see compute_game_shares()).
//
// The server holds the true state and makes every random choice (random_int); screens only draw it and poll.
// Nothing runs on a timer: whichever request comes in after a deadline (or once everyone has played) moves the game
// on, guarded by `version`, so each step happens exactly once. Times are compared in the database (NOW(3)).

declare(strict_types=1);

const GAMES = [
    'race'     => ['emoji' => '🏁', 'title' => 'Receipt Race',               'description' => 'Same items for everyone. The fastest correct total pays nothing.'],
    'closest'  => ['emoji' => '🎯', 'title' => 'Closest Without Going Over', 'description' => 'Build a basket closest to the target without going over. The closest pays nothing.'],
    'roulette' => ['emoji' => '🎡', 'title' => 'Roulette',                   'description' => 'Players drop out one by one. The last one standing pays it all.'],
    'cards'    => ['emoji' => '🃏', 'title' => 'Mystery Card',               'description' => 'Everyone picks a face-down card. One of them says “You Pay”.'],
];

// One card per player, flipped as it's picked. {x} is the game's card amount (about a quarter of an equal share).
// choose: its holder picks another player once every card is drawn.
const CARDS = [
    'lucky'  => ['emoji' => '💰', 'title' => 'Lucky You',      'description' => '{x} comes off your share.'],
    'payer'  => ['emoji' => '🔄', 'title' => 'Pick a Payer',   'description' => 'Choose someone to pay your share.', 'choose' => 'Who pays your share?'],
    'grab'   => ['emoji' => '🎁', 'title' => 'Cash Grab',      'description' => 'Take ₱10 from every other player.'],
    'shield' => ['emoji' => '🛡️', 'title' => 'Shield',         'description' => 'No other card can raise your share.'],
    'swap'   => ['emoji' => '🔀', 'title' => 'Swap',           'description' => 'Swap your final share with another player.', 'choose' => 'Swap shares with…'],
    'double' => ['emoji' => '💥', 'title' => 'Double Trouble', 'description' => 'Add {x} to someone’s share — it comes off yours.', 'choose' => 'Who gets the extra {x}?'],
    'free'   => ['emoji' => '👑', 'title' => 'Free Pass',      'description' => 'You pay ₱0.'],
];
const CARD_ORDER = ['free', 'lucky', 'grab', 'double', 'payer', 'swap']; // how the cards resolve; 🛡️ just protects
const CARD_REPEATS = ['lucky', 'shield', 'grab'];                       // the extra cards past the seven
const CARD_DECK_MIN = 8;                                                // at least 8 cards on the table, one more per player past 8
const CARD_GRAB_CENTS = 1000;
const GAME_CARD_SECONDS = 45;
const GAME_CHOOSE_SECONDS = 30;
const ROULETTE_STEP_MS = 2500;   // one player drops out every 2.5 s

const GAME_ANSWER_SECONDS = 90;  // Receipt Race / Closest: the live clock; in a one-phone race, each turn's limit
const RACE_TOLERANCE_CENTS = 100; // a race answer within ₱1 counts as correct (rounding)
// Receipt Race adjustments, each a % of the race's items subtotal: always one charge, sometimes a discount too.
const RACE_CHARGES = [['label' => 'Add 12% VAT', 'bp' => 1200], ['label' => 'Add 10% service charge', 'bp' => 1000], ['label' => 'Add 5% service charge', 'bp' => 500]];
const RACE_DISCOUNTS = [['label' => 'Less 20% Senior/PWD discount', 'bp' => -2000], ['label' => 'Less 10% promo discount', 'bp' => -1000], ['label' => 'Less 5% promo discount', 'bp' => -500]];

const GAME_COLS = 'g.*, ROUND(UNIX_TIMESTAMP(g.started_at) * 1000) AS started_ms, ROUND(UNIX_TIMESTAMP(g.deadline_at) * 1000) AS deadline_ms,
    ROUND(UNIX_TIMESTAMP(NOW(3)) * 1000) AS now_ms, (g.deadline_at IS NOT NULL AND g.deadline_at <= NOW(3)) AS expired';

/** Whether the bill was started from a saved group that has Fun Mode on. */
function bill_fun_mode(array $bill): bool
{
    if (empty($bill['group_id']) || ($bill['kind'] ?? 'bill') !== 'bill') {
        return false;
    }
    return (bool) q('SELECT fun_mode FROM user_groups WHERE id = ?', [$bill['group_id']])->fetchColumn();
}

/** A Fun Mode bill whose game hasn't been played (or skipped) yet: the review sends it to the game screen. */
function game_pending(array $bill): bool
{
    return in_array($bill['status'], ['draft', 'active'], true) && bill_fun_mode($bill)
        && !q("SELECT 1 FROM game_sessions WHERE bill_id = ? AND status = 'done'", [$bill['id']])->fetchColumn();
}

function game_row(array|false $g): ?array
{
    if (!$g) {
        return null;
    }
    foreach (['id', 'bill_id', 'host_id', 'version'] as $k) {
        $g[$k] = (int) $g[$k];
    }
    foreach (['started_ms', 'deadline_ms', 'now_ms'] as $k) {
        $g[$k] = $g[$k] === null ? null : (int) $g[$k];
    }
    $g['expired'] = (bool) $g['expired'];
    $g['state'] = $g['state'] ? json_decode($g['state'], true) : [];
    return $g;
}

/** The bill's latest game that wasn't cancelled (a replay cancels the earlier one), or null. */
function current_game(int $billId): ?array
{
    return game_row(q('SELECT ' . GAME_COLS . " FROM game_sessions g WHERE g.bill_id = ? AND g.status <> 'cancelled' ORDER BY g.id DESC LIMIT 1", [$billId])->fetch());
}

function game_by_id(int $id): ?array
{
    return game_row(q('SELECT ' . GAME_COLS . ' FROM game_sessions g WHERE g.id = ?', [$id])->fetch());
}

/** Players in bill-member order (the order the phone is passed around on one screen). */
function game_players(int $sessionId): array
{
    $rows = q(
        'SELECT p.*, u.id, u.full_name, u.avatar_color, u.role, u.featured_achievement,
                ROUND(UNIX_TIMESTAMP(p.turn_started_at) * 1000) AS turn_started_ms,
                ROUND((UNIX_TIMESTAMP(p.answered_at) - UNIX_TIMESTAMP(p.turn_started_at)) * 1000) AS time_ms
         FROM game_players p
         JOIN users u ON u.id = p.user_id JOIN game_sessions g ON g.id = p.session_id
         LEFT JOIN bill_members m ON m.bill_id = g.bill_id AND m.user_id = p.user_id
         WHERE p.session_id = ? ORDER BY m.joined_at, u.id',
        [$sessionId]
    )->fetchAll();
    return array_map(fn ($r) => [
        'user'        => public_user($r),
        'id'          => (int) $r['user_id'],
        'joined'      => $r['joined_at'] !== null,
        'answer_cents'    => $r['answer_cents'] === null ? null : (int) $r['answer_cents'],
        'basket'          => $r['basket'] ? json_decode($r['basket'], true) : null,
        'answered'        => $r['answered_at'] !== null,
        'turn_started_ms' => $r['turn_started_ms'] === null ? null : (int) $r['turn_started_ms'],
        'time_ms'         => $r['time_ms'] === null || $r['answered_at'] === null ? null : (int) $r['time_ms'],
        'card_slot'       => $r['card_slot'] === null ? null : (int) $r['card_slot'],
        'target_id'       => $r['target_id'] === null ? null : (int) $r['target_id'],
    ], $rows);
}

function game_shuffle(array $a): array
{
    $a = array_values($a);
    for ($i = count($a) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
    }
    return $a;
}

/** The deck: all seven cards plus repeats of the milder ones — 8 cards, or one per player when more than 8 play. */
function build_deck(int $n): array
{
    $deck = array_keys(CARDS);
    while (count($deck) < max(CARD_DECK_MIN, $n)) {
        $deck[] = CARD_REPEATS[random_int(0, count(CARD_REPEATS) - 1)];
    }
    return game_shuffle($deck);
}

/** The card amount X: about a quarter of an equal share, in whole ₱5s, at least ₱5. */
function card_amount(int $totalCents, int $players): int
{
    return max(500, (int) round($totalCents / max(1, $players) / 4 / 500) * 500);
}

/**
 * Receipt Race challenge: 2–3 random receipt items, each with a random quantity (1–3), plus a charge and maybe a
 * discount, both as a % of those items' subtotal. answer = what everyone must work out (centavos).
 */
function build_race(array $items): array
{
    $pool = game_shuffle(array_filter($items, fn ($it) => cents($it['unit_price']) > 0));
    $lines = [];
    foreach (array_slice($pool, 0, min(count($pool), random_int(2, 3))) as $it) {
        $lines[] = ['name' => $it['name'], 'qty' => random_int(1, 3), 'unit_cents' => cents($it['unit_price'])];
    }
    $adjust = [RACE_CHARGES[random_int(0, count(RACE_CHARGES) - 1)]];
    if (random_int(0, 1)) {
        $adjust[] = RACE_DISCOUNTS[random_int(0, count(RACE_DISCOUNTS) - 1)];
    }
    $subtotal = array_sum(array_map(fn ($l) => $l['qty'] * $l['unit_cents'], $lines));
    $answer = $subtotal;
    foreach ($adjust as $a) {
        $answer += (int) round($subtotal * $a['bp'] / 10000);
    }
    return ['lines' => $lines, 'adjustments' => $adjust, 'subtotal' => $subtotal, 'answer' => $answer];
}

/**
 * Closest Without Going Over: the receipt's items (up to the quantity bought) and a random target between 30% and
 * 80% of their subtotal, in whole pesos — never below the cheapest item, so it can always be met.
 */
function build_closest(array $items): array
{
    $list = [];
    foreach ($items as $it) {
        if (cents($it['unit_price']) > 0) {
            $list[] = ['id' => (int) $it['id'], 'name' => $it['name'], 'unit_cents' => cents($it['unit_price']), 'max' => (int) $it['qty']];
        }
    }
    $subtotal = array_sum(array_map(fn ($i) => $i['unit_cents'] * $i['max'], $list));
    $cheapest = min(array_column($list, 'unit_cents'));
    $target = random_int(intdiv($subtotal * 3, 1000), max(intdiv($subtotal * 3, 1000), intdiv($subtotal * 8, 1000))) * 100;
    return ['items' => $list, 'target' => max($target, (int) ceil($cheapest / 100) * 100)];
}

/** Milliseconds until the playing phase's deadline, or null for none (one screen, no clock). */
function game_play_ms(string $game, bool $live, int $players): ?int
{
    return match ($game) {
        'race', 'closest' => $live ? GAME_ANSWER_SECONDS * 1000 : null,
        'cards'    => $live ? GAME_CARD_SECONDS * 1000 : null,
        'roulette' => ($players - 1) * ROULETTE_STEP_MS + 1500,
    };
}

/** Change the session only if nobody else moved it first. */
function game_update(array $g, string $setSql, array $params = []): bool
{
    return q("UPDATE game_sessions SET $setSql, version = version + 1 WHERE id = ? AND version = ?", array_merge($params, [$g['id'], $g['version']]))->rowCount() === 1;
}

/** Tell the other screens something changed (an answer, a picked card, someone joining). */
function game_touch(array $g): void
{
    q('UPDATE game_sessions SET version = version + 1 WHERE id = ?', [$g['id']]);
}

/** Start a game ($game 'skip' records that the creator chose a normal split). Replaces any earlier game. */
function start_game(array $bill, array $me, string $game, string $mode): void
{
    require_creator($bill, $me);
    require_editable($bill);
    if ($game !== 'skip' && !isset(GAMES[$game])) {
        fail('Pick a game.', 422);
    }
    $live = $mode === 'live';
    $members = bill_members($bill['id']);
    if ($game !== 'skip') {
        if (count($members) < 2) {
            fail('Add at least one more member to play.', 422);
        }
        if (!q('SELECT 1 FROM receipt_items WHERE bill_id = ? AND unit_price > 0', [$bill['id']])->fetchColumn()) {
            fail('Add the receipt items first.', 422);
        }
    }
    $ids = array_column($members, 'id');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q("UPDATE game_sessions SET status = 'cancelled', version = version + 1 WHERE bill_id = ? AND status <> 'cancelled'", [$bill['id']]);
        if (($bill['split_mode'] ?? 'items') === 'game') {
            clear_game_result($bill);
        }
        if ($game === 'skip') {
            q("INSERT INTO game_sessions (bill_id, host_id, game, mode, status) VALUES (?, ?, 'skip', 'screen', 'done')", [$bill['id'], $me['id']]);
            $pdo->commit();
            return;
        }
        $items = bill_items($bill['id']);
        $state = match ($game) {
            'race'     => ['race' => build_race($items)],
            'closest'  => ['closest' => build_closest($items)],
            'roulette' => ['order' => game_shuffle($ids)],   // knock-out order; the last one pays
            'cards'    => ['deck' => build_deck(count($ids)), 'x' => card_amount(compute_percent_shares($bill, $members, $items)['total'], count($ids))],
        };
        $ms = $live ? null : game_play_ms($game, false, count($ids));
        q(
            'INSERT INTO game_sessions (bill_id, host_id, game, mode, status, state, started_at, deadline_at)
             VALUES (?, ?, ?, ?, ?, ?, IF(?, NULL, NOW(3)), DATE_ADD(NOW(3), INTERVAL ? MICROSECOND))',
            [$bill['id'], $me['id'], $game, $live ? 'live' : 'screen', $live ? 'lobby' : 'playing', json_encode($state), (int) $live, $ms === null ? null : $ms * 1000]
        );
        $sid = (int) $pdo->lastInsertId();
        foreach ($members as $m) {
            $joined = !$live || $m['id'] === $me['id'];
            q('INSERT INTO game_players (session_id, user_id, joined_at) VALUES (?, ?, IF(?, NOW(), NULL))', [$sid, $m['id'], (int) $joined]);
            if ($live && !$joined && !$m['is_guest']) {
                notify($m['id'], 'game', first_name($me['full_name']) . ' started ' . GAMES[$game]['emoji'] . ' ' . GAMES[$game]['title']
                    . " for {$bill['name']} — join on your phone!", 'game?bill=' . $bill['id']);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Live mode: the host closes the lobby and the clock starts. */
function begin_game(array $g, array $me): void
{
    if ($g['host_id'] !== $me['id']) {
        fail('Only the person who started the game can begin it.', 403);
    }
    if ($g['status'] !== 'lobby') {
        return;
    }
    $ms = game_play_ms($g['game'], true, count(game_players($g['id'])));
    if (game_update($g, "status = 'playing', started_at = NOW(3), deadline_at = DATE_ADD(NOW(3), INTERVAL ? MICROSECOND)", [$ms === null ? null : $ms * 1000])
        && $g['game'] === 'race') {
        q('UPDATE game_players SET turn_started_at = NOW(3) WHERE session_id = ?', [$g['id']]); // live: everyone starts at once
    }
}

function join_game(array $g, array $me): void
{
    if (q('UPDATE game_players SET joined_at = NOW() WHERE session_id = ? AND user_id = ? AND joined_at IS NULL', [$g['id'], $me['id']])->rowCount()) {
        game_touch($g);
    }
}

/**
 * Whose turn an action is for. On one screen the host plays for everyone; in live mode each person plays on
 * their own phone and the host plays for guests (who have no account to sign in with).
 */
function game_actor(array $g, array $me, ?int $forUserId): array
{
    if ($g['mode'] === 'screen' && $g['host_id'] !== $me['id']) {
        fail('This game is played on one phone — take your turn when it comes to you.', 403);
    }
    $target = $forUserId ?: $me['id'];
    $player = null;
    foreach (game_players($g['id']) as $p) {
        if ($p['id'] === $target) {
            $player = $p;
        }
    }
    if (!$player) {
        fail('That person isn’t playing this game.', 403);
    }
    if ($target !== $me['id'] && !($g['host_id'] === $me['id'] && ($g['mode'] === 'screen' || $player['user']['is_guest']))) {
        fail('Play your own turn on your phone.', 403);
    }
    return $player;
}

/** One-phone Receipt Race: the player has the phone and taps Go — their clock starts now, on the server. */
function game_race_go(array $g, array $me, ?int $forUserId): void
{
    if ($g['game'] !== 'race' || $g['status'] !== 'playing' || $g['mode'] !== 'screen') {
        fail('The race isn’t waiting for a Go.', 409);
    }
    $p = game_actor($g, $me, $forUserId);
    if (q('UPDATE game_players SET turn_started_at = NOW(3) WHERE session_id = ? AND user_id = ? AND turn_started_at IS NULL', [$g['id'], $p['id']])->rowCount()) {
        game_touch($g);
    }
}

/**
 * Lock in an answer — one each. Race: $data['amount'] (pesos), timed by the server from the player's start.
 * Closest: $data['basket'] {receipt item id: qty}; its total is worked out here, never taken from the phone.
 */
function game_answer(array $g, array $me, ?int $forUserId, array $data): void
{
    if (!in_array($g['game'], ['race', 'closest'], true) || $g['status'] !== 'playing' || $g['expired']) {
        fail('Answers are closed.', 409);
    }
    $p = game_actor($g, $me, $forUserId);
    if ($p['answered']) {
        fail('That answer is already locked in.', 409);
    }
    $basket = null;
    if ($g['game'] === 'race') {
        if ($p['turn_started_ms'] === null) {
            fail('Tap Go first.', 409);
        }
        $cents = cents($data['amount'] ?? -1);
        if ($cents < 0 || $cents > 100000000) {
            fail('Enter the total in pesos.', 422);
        }
    } else {
        $byId = array_column($g['state']['closest']['items'], null, 'id');
        $basket = [];
        $cents = 0;
        foreach ((array) ($data['basket'] ?? []) as $id => $qty) {
            $id = (int) $id;
            $qty = (int) $qty;
            if ($qty === 0) {
                continue;
            }
            if (!isset($byId[$id]) || $qty < 0 || $qty > $byId[$id]['max']) {
                fail('Pick items from the receipt, up to the quantity on it.', 422);
            }
            $basket[$id] = $qty;
            $cents += $qty * $byId[$id]['unit_cents'];
        }
        if (!$basket) {
            fail('Pick at least one item.', 422);
        }
    }
    q(
        'UPDATE game_players SET answer_cents = ?, basket = ?, answered_at = NOW(3) WHERE session_id = ? AND user_id = ? AND answered_at IS NULL',
        [$cents, $basket === null ? null : json_encode($basket), $g['id'], $p['id']]
    );
    game_touch($g);
}

function game_pick_card(array $g, array $me, ?int $forUserId, int $slot): void
{
    if ($g['game'] !== 'cards' || $g['status'] !== 'playing' || $g['expired']) {
        fail('Card picking is closed.', 409);
    }
    if ($slot < 0 || $slot >= count($g['state']['deck'] ?? [])) {
        fail('Pick one of the cards.', 422);
    }
    $p = game_actor($g, $me, $forUserId);
    if ($p['card_slot'] !== null) {
        fail('You already have a card.', 409);
    }
    try {
        q('UPDATE game_players SET card_slot = ? WHERE session_id = ? AND user_id = ? AND card_slot IS NULL', [$slot, $g['id'], $p['id']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            fail('Someone just took that card — pick another.', 409);
        }
        throw $e;
    }
    game_touch($g);
}

/** Each player's card (uid => card), once drawn. */
function player_cards(array $g, array $players): array
{
    $out = [];
    foreach ($players as $p) {
        if ($p['card_slot'] !== null) {
            $out[$p['id']] = $g['state']['deck'][$p['card_slot']];
        }
    }
    return $out;
}

/** 🛡️ and 👑 holders: no card can raise their share, so nobody can pick them. */
function card_protected(array $cards): array
{
    return array_keys(array_filter($cards, fn ($c) => $c === 'shield' || $c === 'free'));
}

/**
 * Who a choosing card's holder may pick: anyone else playing who isn't known to be protected yet. Picks are made
 * the moment the card is drawn, so a player may still draw 🛡️ / 👑 later — the card is then blocked.
 */
function card_targets(array $playerIds, array $cards, int $holder): array
{
    return array_values(array_diff($playerIds, card_protected($cards), [$holder]));
}

/** Holders of 🔄 / 🔀 / 💥 (with someone to pick), and whether they've picked yet. */
function card_choosers(array $g, array $players): array
{
    $cards = player_cards($g, $players);
    $ids = array_column($players, 'id');
    $out = [];
    foreach ($players as $p) {
        $card = $cards[$p['id']] ?? null;
        if ($card && isset(CARDS[$card]['choose']) && ($p['target_id'] !== null || card_targets($ids, $cards, $p['id']))) {
            $out[] = ['id' => $p['id'], 'card' => $card, 'chosen' => $p['target_id'] !== null, 'target_id' => $p['target_id']];
        }
    }
    return $out;
}

/** A 🔄 / 🔀 / 💥 holder picks their player. */
function game_choose_target(array $g, array $me, ?int $forUserId, int $targetId): void
{
    if (!in_array($g['status'], ['playing', 'choosing'], true) || $g['game'] !== 'cards') {
        fail('It’s not time to choose.', 409);
    }
    $players = game_players($g['id']);
    $p = game_actor($g, $me, $forUserId);
    $mine = array_values(array_filter(card_choosers($g, $players), fn ($c) => $c['id'] === $p['id']));
    if (!$mine) {
        fail('Your card doesn’t pick anyone.', 403);
    }
    if ($mine[0]['chosen']) {
        fail('Already chosen.', 409);
    }
    if (!in_array($targetId, card_targets(array_column($players, 'id'), player_cards($g, $players), $p['id']), true)) {
        fail('Pick someone else who isn’t protected by 🛡️ or 👑.', 422);
    }
    q('UPDATE game_players SET target_id = ? WHERE session_id = ? AND user_id = ? AND target_id IS NULL', [$targetId, $g['id'], $p['id']]);
    game_touch($g);
}

/**
 * Mystery Card outcome. Start from an equal split, then resolve the cards in CARD_ORDER; money only moves, so the
 * shares always add up to the bill. 🛡️ and 👑 holders are never charged more, and a guest never ends up owed money
 * (they have no account to receive it). Returns [base, shares, log].
 */
function resolve_cards(int $total, array $ids, array $cards, array $targets, int $x, array $guests): array
{
    $shares = split_evenly($total, $ids);
    $base = $shares;
    $protected = card_protected($cards);
    $open = fn (int $except) => array_values(array_diff($ids, $protected, [$except])); // who can be charged more
    $spread = function (int $amount, array $to) use (&$shares) {
        foreach (split_evenly($amount, $to) as $uid => $c) {
            $shares[$uid] += $c;
        }
    };
    $log = [];
    foreach (CARD_ORDER as $card) {
        foreach (array_keys($cards, $card, true) as $h) {
            if (!in_array($h, $ids, true)) {
                continue;
            }
            $t = $targets[$h] ?? null;
            if ($t !== null && in_array($t, $protected, true)) {
                // Picked before they drew 🛡️ / 👑: blocked.
                $log[] = ['card' => $card, 'holder' => $h, 'target' => $t, 'amount' => 0, 'blocked' => $cards[$t]];
                continue;
            }
            switch ($card) {
                case 'free':
                    $to = $open($h);
                    if ($to && $shares[$h] > 0) {
                        $log[] = ['card' => $card, 'holder' => $h, 'target' => null, 'amount' => $shares[$h]];
                        $spread($shares[$h], $to);
                        $shares[$h] = 0;
                    }
                    break;
                case 'lucky':
                    $to = $open($h);
                    if ($to) {
                        $shares[$h] -= $x;
                        $spread($x, $to);
                        $log[] = ['card' => $card, 'holder' => $h, 'target' => null, 'amount' => $x];
                    }
                    break;
                case 'grab':
                    $from = $open($h);
                    foreach ($from as $uid) {
                        $shares[$uid] += CARD_GRAB_CENTS;
                    }
                    $shares[$h] -= CARD_GRAB_CENTS * count($from);
                    if ($from) {
                        $log[] = ['card' => $card, 'holder' => $h, 'target' => null, 'amount' => CARD_GRAB_CENTS * count($from)];
                    }
                    break;
                case 'double':
                    if ($t !== null) {
                        $shares[$t] += $x;
                        $shares[$h] -= $x;
                        $log[] = ['card' => $card, 'holder' => $h, 'target' => $t, 'amount' => $x];
                    }
                    break;
                case 'payer':
                    if ($t !== null) {
                        $log[] = ['card' => $card, 'holder' => $h, 'target' => $t, 'amount' => $shares[$h]];
                        $shares[$t] += $shares[$h];
                        $shares[$h] = 0;
                    }
                    break;
                case 'swap':
                    if ($t !== null) {
                        $log[] = ['card' => $card, 'holder' => $h, 'target' => $t, 'amount' => $shares[$t] - $shares[$h]];
                        [$shares[$h], $shares[$t]] = [$shares[$t], $shares[$h]];
                    }
                    break;
            }
        }
    }
    // A guest below ₱0: they pay nothing instead, and the others who still owe something get the difference back.
    foreach ($guests as $gid) {
        if (($shares[$gid] ?? 0) < 0) {
            $back = array_values(array_filter($ids, fn ($uid) => $uid !== $gid && !in_array($uid, $guests, true) && $shares[$uid] > 0));
            if ($back) {
                foreach (split_evenly(-$shares[$gid], $back) as $uid => $c) {
                    $shares[$uid] -= $c;
                }
                $shares[$gid] = 0;
            }
        }
    }
    return [$base, $shares, $log];
}

/** Move the game on as far as it can go right now (several steps at most). Returns the fresh session. */
function advance_game(array $g): array
{
    for ($i = 0; $i < 3 && game_next_step($g); $i++) {
        $g = game_by_id($g['id']);
    }
    return $g;
}

/** One step, if one is due. True when the session changed (or another request changed it first). */
function game_next_step(array $g): bool
{
    if ($g['status'] === 'choosing') {
        $waiting = array_filter(card_choosers($g, game_players($g['id'])), fn ($c) => !$c['chosen']);
        return !$waiting || $g['expired'] ? finish_game($g) : false;
    }
    if ($g['status'] !== 'playing') {
        return false;
    }
    $players = game_players($g['id']);
    if ($g['game'] === 'roulette') {
        return $g['expired'] ? finish_game($g) : false;
    }
    if ($g['game'] === 'race' || $g['game'] === 'closest') {
        // One-phone race: a turn left running past its limit counts as no answer.
        if ($g['game'] === 'race' && $g['mode'] === 'screen' && q(
            'UPDATE game_players SET answered_at = NOW(3) WHERE session_id = ? AND answered_at IS NULL AND turn_started_at <= NOW(3) - INTERVAL ? SECOND',
            [$g['id'], GAME_ANSWER_SECONDS]
        )->rowCount()) {
            game_touch($g);
            return true;
        }
        $all = !in_array(false, array_column($players, 'answered'), true);
        return $all || $g['expired'] ? finish_game($g) : false;
    }
    // cards
    $all = !in_array(null, array_column($players, 'card_slot'), true);
    if (!$all && !$g['expired']) {
        return false;
    }
    $pdo = db();
    $pdo->beginTransaction();
    $ms = $g['mode'] === 'live' ? GAME_CHOOSE_SECONDS * 1000 : null;
    if (!game_update($g, "status = 'choosing', deadline_at = DATE_ADD(NOW(3), INTERVAL ? MICROSECOND)", [$ms === null ? null : $ms * 1000])) {
        $pdo->rollBack();
        return true;
    }
    deal_leftover_cards($g, $players);
    $pdo->commit();
    return true;
}

/** Players who didn't pick before the deadline get a random free card. */
function deal_leftover_cards(array $g, array $players): void
{
    $free = array_values(array_diff(array_keys($g['state']['deck']), array_filter(array_column($players, 'card_slot'), fn ($s) => $s !== null)));
    $free = game_shuffle($free);
    foreach ($players as $p) {
        if ($p['card_slot'] === null && $free) {
            q('UPDATE game_players SET card_slot = ? WHERE session_id = ? AND user_id = ?', [array_pop($free), $g['id'], $p['id']]);
        }
    }
}

/**
 * Race / Closest outcome: the winners (ties all win) pay nothing, everyone else splits the bill equally. With no
 * winner — or everyone winning — it's a normal split. Winners get $badge.
 */
function game_winners_pay_nothing(array $bill, array $ids, array $winners, array &$result, string $badge): void
{
    if (!$winners || count($winners) === count($ids)) {
        $result['normal'] = true;
        clear_game_result($bill);
    } else {
        apply_game_result($bill, array_map(fn ($uid) => in_array($uid, $winners, true) ? 0 : 1, array_combine($ids, $ids)));
    }
    foreach ($winners as $uid) {
        award($uid, $badge, $bill['id']);
    }
}

/** Work out the result, save it as the bill's split and hand out the badges. */
function finish_game(array $g): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (!game_update($g, "status = 'done', deadline_at = NULL")) {
            $pdo->rollBack();
            return true;
        }
        $players = game_players($g['id']);
        $bill = q('SELECT * FROM bills WHERE id = ?', [$g['bill_id']])->fetch();
        $bill['id'] = (int) $bill['id'];
        $members = bill_members($bill['id']);
        $ids = array_column($members, 'id');
        $playing = array_values(array_intersect(array_column($players, 'id'), $ids));
        $result = [];

        if ($g['game'] === 'race') {
            // Fastest correct answer, within the time limit.
            $race = $g['state']['race'];
            $times = [];
            foreach ($players as $p) {
                if (in_array($p['id'], $ids, true) && $p['answer_cents'] !== null && $p['time_ms'] !== null
                    && $p['time_ms'] <= GAME_ANSWER_SECONDS * 1000 && abs($p['answer_cents'] - $race['answer']) <= RACE_TOLERANCE_CENTS) {
                    $times[$p['id']] = $p['time_ms'];
                }
            }
            $winners = $times ? array_keys($times, min($times), true) : [];
            $result = ['answer' => $race['answer'], 'winners' => $winners, 'correct' => array_keys($times)];
            game_winners_pay_nothing($bill, $ids, $winners, $result, 'human_calculator');
        } elseif ($g['game'] === 'closest') {
            // Highest basket total that doesn't go over the target.
            $target = $g['state']['closest']['target'];
            $sums = [];
            foreach ($players as $p) {
                if (in_array($p['id'], $ids, true) && $p['answer_cents'] !== null && $p['answer_cents'] > 0 && $p['answer_cents'] <= $target) {
                    $sums[$p['id']] = $p['answer_cents'];
                }
            }
            $winners = $sums ? array_keys($sums, max($sums), true) : [];
            $result = ['target' => $target, 'winners' => $winners];
            game_winners_pay_nothing($bill, $ids, $winners, $result, 'bullseye');
        } elseif ($g['game'] === 'roulette') {
            $order = array_values(array_intersect($g['state']['order'], $ids));
            $payer = end($order);
            $result = ['payer' => $payer, 'order' => $order];
            apply_game_result($bill, array_map(fn ($uid) => $uid === $payer ? 1 : 0, array_combine($ids, $ids)));
            award($payer, 'main_character', $bill['id']);
        } else {
            $cards = player_cards($g, $players);
            $targets = [];
            foreach (card_choosers($g, $players) as $c) {
                // Ran out of time to choose: the deck picks for them.
                $options = card_targets($ids, $cards, $c['id']);
                $targets[$c['id']] = $c['chosen'] ? $c['target_id'] : $options[random_int(0, count($options) - 1)];
            }
            $guests = array_column(array_filter($members, fn ($m) => $m['is_guest']), 'id');
            $total = compute_percent_shares($bill, $members, bill_items($bill['id']))['total'];
            [$base, $shares, $log] = resolve_cards($total, $ids, $cards, $targets, $g['state']['x'], $guests);
            $result = ['base' => $base, 'shares' => $shares, 'log' => $log, 'x' => $g['state']['x']];
            apply_game_result($bill, [], $shares);
        }
        q('UPDATE game_sessions SET state = ? WHERE id = ?', [json_encode($g['state'] + ['result' => $result]), $g['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return true;
}

/**
 * What a screen needs to draw the game for $me. Answers stay hidden until the reveal (except your own, live).
 * The race items only show once a clock is running (one phone: during that player's turn), so nobody gets a head start.
 */
function game_payload(array $g, array $me): array
{
    $players = game_players($g['id']);
    $done = $g['status'] === 'done';
    $deck = $g['state']['deck'] ?? [];
    $out = [
        'id'          => $g['id'],
        'game'        => $g['game'],
        'mode'        => $g['mode'],
        'status'      => $g['status'],
        'version'     => $g['version'],
        'host_id'     => $g['host_id'],
        'is_host'     => $g['host_id'] === $me['id'],
        'started_ms'  => $g['started_ms'],
        'deadline_ms' => $g['deadline_ms'],
        'now_ms'      => $g['now_ms'],
        'slots'       => count($deck),
        'players'     => array_map(fn ($p) => [
            'user'      => $p['user'],
            'joined'    => $p['joined'],
            'answered'  => $p['answered'],
            'started'   => $p['turn_started_ms'] !== null,
            'turn_started_ms' => $p['turn_started_ms'],
            'answer'    => $done || ($g['mode'] === 'live' && $p['id'] === $me['id']) ? $p['answer_cents'] : null,
            'basket'    => $done || ($g['mode'] === 'live' && $p['id'] === $me['id']) ? $p['basket'] : null,
            'time_ms'   => $done ? $p['time_ms'] : null,
            'card_slot' => $p['card_slot'],
            'card'      => $p['card_slot'] !== null ? $deck[$p['card_slot']] : null, // a card flips over as it's picked
        ], $players),
        'result'      => $done ? ($g['state']['result'] ?? null) : null,
    ];
    if ($g['game'] === 'roulette' && in_array($g['status'], ['playing', 'done'], true)) {
        $order = $g['state']['order'];
        $outCount = count($order) - 1;
        $k = $done ? $outCount : max(0, min($outCount, intdiv(($g['now_ms'] - $g['started_ms']), ROULETTE_STEP_MS)));
        $out['knocked'] = array_slice($order, 0, $k);
        $out['step_ms'] = ROULETTE_STEP_MS;
    }
    if ($g['game'] === 'race') {
        $racing = $done || ($g['status'] === 'playing' && ($g['mode'] === 'live'
            || array_filter($players, fn ($p) => $p['turn_started_ms'] !== null && !$p['answered'])));
        $race = $g['state']['race'];
        $out['race'] = $racing ? ['lines' => $race['lines'], 'adjustments' => $race['adjustments']] + ($done ? ['subtotal' => $race['subtotal'], 'answer' => $race['answer']] : []) : null;
        $out['turn_limit_ms'] = GAME_ANSWER_SECONDS * 1000;
    }
    if ($g['game'] === 'closest') {
        $out['closest'] = $g['state']['closest'];
    }
    if ($g['game'] === 'cards') {
        $out['x'] = $g['state']['x'];
        $out['protected'] = card_protected(player_cards($g, $players));
        if (in_array($g['status'], ['playing', 'choosing'], true)) {
            // 🔄 / 🔀 / 💥 holders pick as soon as they draw; who they picked stays secret until the reveal.
            $out['choosers'] = array_map(fn ($c) => ['id' => $c['id'], 'card' => $c['card'], 'chosen' => $c['chosen']], card_choosers($g, $players));
        }
    }
    return $out;
}
