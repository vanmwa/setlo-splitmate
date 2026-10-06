<?php
// Save the reviewed/corrected item list (Review Extracted Items screen).
require __DIR__ . '/../includes/api.php';

$me = api_user();
if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

$bill = bill_for(int_param('bill_id'), $me);
require_creator($bill, $me);
require_editable($bill);

$rows = input('items', []);
if (!is_array($rows) || !$rows) {
    fail('Add at least one item.', 422);
}
if (count($rows) > 100) {
    fail('Too many items.', 422);
}

$clean = [];
foreach ($rows as $i => $r) {
    $name = trim((string) ($r['name'] ?? ''));
    $qty = (int) ($r['qty'] ?? 0);
    $price = round((float) ($r['unit_price'] ?? -1), 2);
    if ($name === '' || strlen($name) > 120) {
        fail('Item ' . ($i + 1) . ' needs a name.', 422);
    }
    if ($qty < 1 || $qty > 999) {
        fail("Check the quantity of $name.", 422);
    }
    if ($price < 0 || $price > 1000000) {
        fail("Check the price of $name.", 422);
    }
    // The item promo is only a note of what came off: unit_price is already the price after it.
    $promo = min(1000000, max(0, round((float) ($r['promo'] ?? 0), 2)));
    // needs_review: a flag left open (saved before adding another receipt); Continue only sends confirmed rows.
    $clean[] = ['id' => isset($r['id']) ? (int) $r['id'] : null, 'name' => $name, 'qty' => $qty, 'unit_price' => $price, 'promo' => $promo, 'needs_review' => !empty($r['needs_review'])];
}

$tax = max(0, round((float) input('tax', 0), 2));
$svc = max(0, round((float) input('service_charge', 0), 2));
$discount = max(0, round((float) input('discount', 0), 2));
$subtotalCents = array_sum(array_map(fn ($it) => $it['qty'] * cents($it['unit_price']), $clean));
if (cents($discount) > $subtotalCents) {
    fail('The discount can’t be more than the items subtotal.', 422);
}
$receiptTotal = input('receipt_total');
$receiptTotal = $receiptTotal === null || $receiptTotal === '' ? null : max(0, round((float) $receiptTotal, 2));

// What the split is worked out from, before and after this save: if it changes, a Fun Mode game's result is void.
$receiptKey = fn () => json_encode([
    q('SELECT name, qty, unit_price FROM receipt_items WHERE bill_id = ? ORDER BY id', [$bill['id']])->fetchAll(PDO::FETCH_NUM),
    q('SELECT tax, service_charge, discount, receipt_total FROM bills WHERE id = ?', [$bill['id']])->fetch(PDO::FETCH_NUM),
]);
$before = $receiptKey();

$existing = [];
foreach (q('SELECT id, name, qty, unit_price, ocr_name FROM receipt_items WHERE bill_id = ?', [$bill['id']]) as $row) {
    $existing[(int) $row['id']] = $row;
}

$pdo = db();
$pdo->beginTransaction();
$keep = [];
foreach ($clean as $pos => $it) {
    $old = $it['id'] !== null ? ($existing[$it['id']] ?? null) : null;
    if ($old) {
        $corrected = $old['ocr_name'] !== null && (
            $it['name'] !== $old['ocr_name'] || $it['qty'] !== (int) $old['qty'] || cents($it['unit_price']) !== cents($old['unit_price'])
        );
        q(
            'UPDATE receipt_items SET position = ?, name = ?, qty = ?, unit_price = ?, promo = ?, needs_review = ?, suggestion = IF(?, suggestion, NULL),
             was_corrected = GREATEST(was_corrected, ?) WHERE id = ?',
            [$pos, $it['name'], $it['qty'], $it['unit_price'], $it['promo'], (int) $it['needs_review'], (int) $it['needs_review'], (int) $corrected, $it['id']]
        );
        $keep[] = $it['id'];
    } else {
        q(
            "INSERT INTO receipt_items (bill_id, position, name, qty, unit_price, promo, source) VALUES (?, ?, ?, ?, ?, ?, 'manual')",
            [$bill['id'], $pos, $it['name'], $it['qty'], $it['unit_price'], $it['promo']]
        );
        $keep[] = (int) $pdo->lastInsertId();
    }
}
$remove = array_diff(array_keys($existing), $keep);
if ($remove) {
    $in = implode(',', array_fill(0, count($remove), '?'));
    q("DELETE FROM receipt_items WHERE id IN ($in)", array_values($remove));
}
q(
    "UPDATE bills SET status = 'active', tax = ?, service_charge = ?, discount = ?, receipt_total = ? WHERE id = ?",
    [$tax, $svc, $discount, $receiptTotal, $bill['id']]
);
if ($bill['split_mode'] === 'game' && $receiptKey() !== $before) {
    // The receipt changed after a game decided the split: replay it on the corrected bill.
    require_once __DIR__ . '/../includes/games.php';
    clear_game_result($bill);
    q("UPDATE game_sessions SET status = 'cancelled', version = version + 1 WHERE bill_id = ? AND status <> 'cancelled'", [$bill['id']]);
}
$pdo->commit();

// Fun Mode bills play their game before the split.
require_once __DIR__ . '/../includes/games.php';
$bill['status'] = 'active';
json_ok(['redirect' => (game_pending($bill) ? 'game?bill=' : 'assign-items?bill=') . $bill['id']]);
