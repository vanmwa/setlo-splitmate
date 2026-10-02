<?php
// Receipts on a bill: upload + OCR (one or more photos, one or more receipts each), duplicate checks, removal,
// and access-controlled photo viewing.
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/ocr.php';
require __DIR__ . '/../includes/receipts.php';

const MAX_PHOTOS = 6; // sections of one long receipt, sent to the scanner together

$me = api_user();

if (method() === 'GET') {
    // ?photo_id= one photo of a receipt; without it, the bill's first photo.
    $bill = bill_for(int_param('bill_id'), $me);
    $photoId = (int) ($_GET['photo_id'] ?? 0);
    $image = q(
        'SELECT p.image FROM receipt_photos p JOIN receipts r ON r.id = p.receipt_id WHERE r.bill_id = ?' . ($photoId ? ' AND p.id = ?' : '')
        . ' ORDER BY r.position, r.id, p.position LIMIT 1',
        $photoId ? [$bill['id'], $photoId] : [$bill['id']]
    )->fetchColumn();
    serve_upload('receipts', $image ?: $bill['receipt_image']);
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

$bill = bill_for(int_param('bill_id'), $me);
require_creator($bill, $me);
require_editable($bill);
$action = input('action', 'upload');
// add: put the new receipt next to the bill's others · replace: start the bill's receipts over (the first scan)
$replace = input('mode', 'replace') !== 'add';

switch ($action) {
    case 'manual':
        q("UPDATE bills SET status = 'active', ocr_status = 'skipped' WHERE id = ? AND status = 'draft'", [$bill['id']]);
        json_ok(['redirect' => 'review-items?bill=' . $bill['id']]);

    case 'demo':
        $stored = store_receipts($bill, [DEMO_RECEIPT], 'ok', [], $replace);
        json_ok(['ocr' => 'ok', 'duplicates' => $stored['duplicates'], 'redirect' => 'review-items?bill=' . $bill['id']]);

    case 'upload':
        // images[]: one photo, or several sections of one long receipt. "image" is the older single-photo field.
        $names = save_uploaded_images('images', 'receipts', 'bill' . $bill['id'], MAX_PHOTOS)
            ?: [save_uploaded_image('image', 'receipts', 'bill' . $bill['id'])];
        $photos = hash_photos($names);

        // Checked before scanning, so a photo sent twice doesn't use up a scan. force=1: the user said add it anyway.
        if (!in_array(input('force'), ['1', 1, true], true)) {
            $dup = find_duplicate_photo($replace ? 0 : $bill['id'], $photos);
            if ($dup) {
                foreach ($names as $n) {
                    delete_upload('receipts', $n);
                }
                json_ok(['duplicate_photo' => $dup]);
            }
        }

        $error = null;
        set_time_limit(200); // up to four Gemini attempts (40 s each) when the service is busy
        try {
            $receipts = scan_receipt(array_map(fn ($n) => upload_dir('receipts') . "/$n", $names), count($names) > 1);
            $status = 'ok';
        } catch (GeminiException $e) {
            [$receipts, $status, $error] = [[], 'failed', $e->getMessage()];
        }
        $stored = store_receipts($bill, $receipts, $status, $photos, $replace);

        json_ok([
            'ocr'            => $status,
            'available'      => gemini_available(),
            'found'          => array_sum(array_map(fn ($r) => count($r['items']), $receipts)),
            'receipts_found' => count($receipts),
            'duplicates'     => $stored['duplicates'],
            'error'          => $error,
            'redirect'       => 'review-items?bill=' . $bill['id'],
        ]);

    case 'remove_receipt':
        remove_receipt($bill, int_param('receipt_id'));
        json_ok(['receipts' => bill_receipts($bill['id'])]);

    case 'keep_receipt':
        // "Keep both": the user checked a same-purchase warning and it's a real second purchase.
        q('UPDATE receipts SET dup_note = NULL WHERE id = ? AND bill_id = ?', [int_param('receipt_id'), $bill['id']]);
        json_ok(['receipts' => bill_receipts($bill['id'])]);
}

fail('Unknown action.', 404);
