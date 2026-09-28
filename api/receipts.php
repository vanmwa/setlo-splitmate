<?php
// Receipt upload + OCR extraction, and access-controlled image viewing.
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/ocr.php';
require __DIR__ . '/../includes/uploads.php';

$me = api_user();

if (method() === 'GET') {
    $bill = bill_for(int_param('bill_id'), $me);
    serve_upload('receipts', $bill['receipt_image']);
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

$bill = bill_for(int_param('bill_id'), $me);
require_creator($bill, $me);
require_editable($bill);
$action = input('action', 'upload');

/** Replace the bill's items with freshly extracted ones. */
function store_extraction(array $bill, array $parsed, string $status, ?string $raw, ?string $image): void
{
    $pdo = db();
    $pdo->beginTransaction();
    q('DELETE FROM receipt_items WHERE bill_id = ?', [$bill['id']]);
    foreach ($parsed['items'] as $pos => $it) {
        q(
            "INSERT INTO receipt_items (bill_id, position, name, qty, unit_price, source, ocr_name, printed_name, details, needs_review, suggestion)
             VALUES (?, ?, ?, ?, ?, 'ocr', ?, ?, ?, ?, ?)",
            [$bill['id'], $pos, $it['name'], $it['qty'], $it['unit_price'], $it['name'], $it['printed_name'], $it['details'], (int) $it['needs_review'], $it['suggestion']]
        );
    }
    $total = $parsed['total'];
    if ($total === null && $parsed['subtotal'] !== null) {
        $total = $parsed['subtotal'] + $parsed['tax'] + $parsed['service_charge'] - $parsed['discount'];
    }
    q(
        "UPDATE bills SET status = 'active', tax = ?, service_charge = ?, discount = ?, receipt_total = ?, ocr_raw = ?, ocr_status = ?,
         receipt_image = COALESCE(?, receipt_image) WHERE id = ?",
        [$parsed['tax'], $parsed['service_charge'], $parsed['discount'], $total, $raw, $status, $image, $bill['id']]
    );
    $pdo->commit();
}

$empty = ['items' => [], 'subtotal' => null, 'tax' => 0.0, 'service_charge' => 0.0, 'discount' => 0.0, 'total' => null];

switch ($action) {
    case 'manual':
        q("UPDATE bills SET status = 'active', ocr_status = 'skipped' WHERE id = ? AND status = 'draft'", [$bill['id']]);
        json_ok(['redirect' => 'review-items?bill=' . $bill['id']]);

    case 'demo':
        store_extraction($bill, DEMO_RECEIPT, 'ok', DEMO_RECEIPT['raw_text'], null);
        json_ok(['ocr' => 'ok', 'redirect' => 'review-items?bill=' . $bill['id']]);

    case 'upload':
        $name = save_uploaded_image('image', 'receipts', 'bill' . $bill['id']);
        delete_upload('receipts', $bill['receipt_image']);
        $dir = upload_dir('receipts');

        $error = null;
        set_time_limit(200); // up to four Gemini attempts (40 s each) when the service is busy
        try {
            $parsed = scan_receipt("$dir/$name");
            $status = 'ok';
        } catch (GeminiException $e) {
            [$parsed, $status, $error] = [$empty, 'failed', $e->getMessage()];
        }
        store_extraction($bill, $parsed, $status, ($parsed['raw_text'] ?? '') ?: null, $name);

        json_ok([
            'ocr'       => $status,
            'available' => gemini_available(),
            'found'     => count($parsed['items']),
            'error'     => $error,
            'redirect'  => 'review-items?bill=' . $bill['id'],
        ]);
}

fail('Unknown action.', 404);
