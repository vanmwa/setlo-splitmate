<?php
// Receipts on a bill: storing scans, duplicate checks, and removal.
// A receipt's tax / service charge / discount / total are added into the bill's when it is scanned and taken out
// again when it is removed, so corrections typed on the Review screen survive adding another receipt.

declare(strict_types=1);

require_once __DIR__ . '/uploads.php';

// Perceptual hashes this close (of 256 bits) are the same photo, re-saved or re-sent. A resized, recompressed copy
// lands ~2 bits away; different receipts ~50+ (a reframed new photo of the same receipt is not a copy and lands far too).
const SAME_PHOTO_BITS = 12;
const OTHER_BILLS_DAYS = 60;      // how far back the same-transaction check looks at the creator's other bills

/** Lower-case letters and digits only, for comparing names and numbers as printed. */
function norm_key(?string $s): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower((string) $s));
}

/** Receipts on a bill, in order, for the Review screen. */
function bill_receipts(int $billId): array
{
    $rows = q('SELECT * FROM receipts WHERE bill_id = ? ORDER BY position, id', [$billId])->fetchAll();
    $photos = [];
    foreach (q('SELECT p.receipt_id, p.id FROM receipt_photos p JOIN receipts r ON r.id = p.receipt_id WHERE r.bill_id = ? ORDER BY p.position, p.id', [$billId]) as $p) {
        $photos[(int) $p['receipt_id']][] = (int) $p['id'];
    }
    return array_map(fn ($r, $i) => [
        'id'         => (int) $r['id'],
        'number'     => $i + 1,
        'store'      => $r['store_name'],
        'receipt_no' => $r['receipt_no'],
        'date'       => $r['txn_date'],
        'total'      => $r['total'] === null ? null : (float) $r['total'],
        'ocr_status' => $r['ocr_status'],
        'dup_note'   => $r['dup_note'],
        'photos'     => $photos[(int) $r['id']] ?? [],
    ], $rows, array_keys($rows));
}

/**
 * Hash freshly saved photos: [[image, sha1, dhash], ...].
 */
function hash_photos(array $names): array
{
    $dir = upload_dir('receipts');
    return array_map(fn ($n) => [$n, sha1_file("$dir/$n"), image_dhash("$dir/$n")], $names);
}

/**
 * The first photo that is already on this bill (same file, or near-identical), as
 * ['receipt' => number on the bill, 'store' => name, 'exact' => bool]; null when all are new.
 * Also catches the same photo sent twice in one batch.
 */
function find_duplicate_photo(int $billId, array $hashed): ?array
{
    $known = q(
        'SELECT p.sha1, p.dhash, r.id AS receipt_id, r.store_name FROM receipt_photos p JOIN receipts r ON r.id = p.receipt_id WHERE r.bill_id = ?',
        [$billId]
    )->fetchAll();
    $numbers = array_flip(array_column(q('SELECT id FROM receipts WHERE bill_id = ? ORDER BY position, id', [$billId])->fetchAll(), 'id'));
    foreach ($hashed as $i => [, $sha1, $dhash]) {
        foreach ($known as $k) {
            $exact = $k['sha1'] === $sha1;
            if ($exact || ($dhash && $k['dhash'] && dhash_distance($dhash, $k['dhash']) <= SAME_PHOTO_BITS)) {
                return ['receipt' => ($numbers[$k['receipt_id']] ?? 0) + 1, 'store' => $k['store_name'], 'exact' => $exact];
            }
        }
        foreach (array_slice($hashed, 0, $i) as [, $sha1b, $dhashb]) {
            if ($sha1 === $sha1b || ($dhash && $dhashb && dhash_distance($dhash, $dhashb) <= SAME_PHOTO_BITS)) {
                return ['receipt' => null, 'store' => null, 'exact' => $sha1 === $sha1b];
            }
        }
    }
    return null;
}

/** Whether two receipts look like the same purchase: same receipt no. at the same store, or same store, total and date. */
function same_transaction(array $a, array $b): bool
{
    $storeA = norm_key($a['store_name']);
    $storeB = norm_key($b['store_name']);
    $sameStore = $storeA !== '' && $storeA === $storeB;
    $noA = norm_key($a['receipt_no']);
    if ($noA !== '' && $noA === norm_key($b['receipt_no'])) {
        return $sameStore || $storeA === '' || $storeB === '';
    }
    if (!$sameStore || $a['total'] === null || $b['total'] === null || cents($a['total']) !== cents($b['total'])) {
        return false;
    }
    // Same store and total: the same purchase unless both dates are printed and differ.
    $dateA = substr(norm_key($a['txn_date']), 0, 12);
    $dateB = substr(norm_key($b['txn_date']), 0, 12);
    return $dateA === '' || $dateB === '' || $dateA === $dateB;
}

/**
 * Save scanned receipts on a bill, with their photos, and flag duplicates. $replace clears the bill's receipts first.
 * Returns ['ids' => new receipt ids, 'duplicates' => [notes]].
 */
function store_receipts(array $bill, array $receipts, string $status, array $hashedPhotos, bool $replace): array
{
    $billId = $bill['id'];
    $oldFiles = [];
    $pdo = db();
    $pdo->beginTransaction();
    if ($replace) {
        $oldFiles = q('SELECT p.image FROM receipt_photos p JOIN receipts r ON r.id = p.receipt_id WHERE r.bill_id = ?', [$billId])->fetchAll(PDO::FETCH_COLUMN);
        q('DELETE FROM receipt_items WHERE bill_id = ?', [$billId]);
        q('DELETE FROM receipts WHERE bill_id = ?', [$billId]);
        q('UPDATE bills SET tax = 0, service_charge = 0, discount = 0, receipt_total = NULL, ocr_raw = NULL, receipt_image = NULL WHERE id = ?', [$billId]);
    }
    $existing = q('SELECT * FROM receipts WHERE bill_id = ? ORDER BY position, id', [$billId])->fetchAll();
    $number = count($existing);
    $position = (int) q('SELECT COALESCE(MAX(position) + 1, 0) FROM receipts WHERE bill_id = ?', [$billId])->fetchColumn();
    $itemPos = (int) q('SELECT COALESCE(MAX(position) + 1, 0) FROM receipt_items WHERE bill_id = ?', [$billId])->fetchColumn();

    // Nothing read (scan failed or found no receipt): keep the photo as an empty receipt, so it can still be viewed.
    if (!$receipts && $hashedPhotos) {
        $receipts = [['items' => [], 'subtotal' => null, 'tax' => 0.0, 'service_charge' => 0.0, 'discount' => 0.0, 'total' => null,
                      'raw_text' => '', 'store_name' => '', 'receipt_no' => '', 'txn_date' => '']];
    }

    $ids = [];
    $duplicates = [];
    foreach ($receipts as $r) {
        $number++;
        $total = $r['total'];
        if ($total === null && $r['subtotal'] !== null) {
            $total = $r['subtotal'] + $r['tax'] + $r['service_charge'] - $r['discount'];
        }
        $r['total'] = $total;

        // Same purchase scanned twice: on this bill (a real problem), or on another recent bill of mine (maybe).
        $dup = null;
        foreach ($existing as $i => $e) {
            if (same_transaction($r, $e)) {
                $dup = 'Looks like the same purchase as Receipt ' . ($i + 1) . ' — remove one unless you really paid twice.';
                break;
            }
        }
        if (!$dup && (norm_key($r['receipt_no']) !== '' || norm_key($r['store_name']) !== '')) {
            $others = q(
                'SELECT r.*, b.name AS bill_name FROM receipts r JOIN bills b ON b.id = r.bill_id
                 WHERE b.creator_id = ? AND b.id <> ? AND r.created_at > NOW() - INTERVAL ' . OTHER_BILLS_DAYS . ' DAY ORDER BY r.id DESC',
                [$bill['creator_id'], $billId]
            )->fetchAll();
            foreach ($others as $o) {
                if (same_transaction($r, $o)) {
                    $dup = 'This receipt is also in your bill “' . mb_substr($o['bill_name'], 0, 60) . '” — check it isn’t being split twice.';
                    break;
                }
            }
        }

        q(
            'INSERT INTO receipts (bill_id, position, store_name, receipt_no, txn_date, subtotal, tax, service_charge, discount, total, ocr_raw, ocr_status, dup_note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$billId, $position++, $r['store_name'] ?: null, $r['receipt_no'] ?: null, $r['txn_date'] ?: null, $r['subtotal'], $r['tax'],
             $r['service_charge'], $r['discount'], $total, $r['raw_text'] ?: null, $status === 'ok' ? 'ok' : 'failed', $dup]
        );
        $rid = (int) $pdo->lastInsertId();
        $ids[] = $rid;
        if ($dup) {
            $duplicates[] = ['receipt' => $number, 'note' => $dup];
        }
        foreach ($hashedPhotos as $p => [$image, $sha1, $dhash]) {
            q('INSERT INTO receipt_photos (receipt_id, position, image, sha1, dhash) VALUES (?, ?, ?, ?, ?)', [$rid, $p, $image, $sha1, $dhash]);
        }

        foreach ($r['items'] as $it) {
            q(
                "INSERT INTO receipt_items (bill_id, receipt_id, position, name, qty, unit_price, source, ocr_name, printed_name, details, needs_review, suggestion)
                 VALUES (?, ?, ?, ?, ?, ?, 'ocr', ?, ?, ?, ?, ?)",
                [$billId, $rid, $itemPos++, $it['name'], $it['qty'], $it['unit_price'], $it['name'], $it['printed_name'], $it['details'], (int) $it['needs_review'], $it['suggestion']]
            );
        }

        q(
            'UPDATE bills SET tax = tax + ?, service_charge = service_charge + ?, discount = discount + ?,
             receipt_total = ' . ($total === null ? 'receipt_total' : 'COALESCE(receipt_total, 0) + ?') . ',
             ocr_raw = COALESCE(ocr_raw, ?) WHERE id = ?',
            array_merge([$r['tax'], $r['service_charge'], $r['discount']], $total === null ? [] : [$total], [$r['raw_text'] ?: null, $billId])
        );
        $existing[] = ['store_name' => $r['store_name'], 'receipt_no' => $r['receipt_no'], 'txn_date' => $r['txn_date'], 'total' => $total];
    }

    flag_repeated_items($billId);
    // A failed scan doesn't undo an earlier successful one.
    q("UPDATE bills SET status = 'active', ocr_status = IF(? = 'ok' OR ocr_status <> 'ok', ?, ocr_status) WHERE id = ?", [$status, $status, $billId]);
    $pdo->commit();

    foreach ($oldFiles as $f) {
        delete_upload('receipts', $f);
    }
    return ['ids' => $ids, 'duplicates' => $duplicates];
}

/**
 * Flag scanned items that may be counted twice: the same item at the same price on another receipt of the bill
 * (ordered again, or the same receipt scanned twice), or listed twice on one receipt (a double read).
 * Items that already have a note are skipped: confirming one on Review keeps the note (only needs_review is
 * cleared), so a flag the user already dismissed doesn't come back when another receipt is added.
 */
function flag_repeated_items(int $billId): void
{
    $items = q("SELECT id, receipt_id, name, unit_price, needs_review, dup_note FROM receipt_items WHERE bill_id = ? AND source = 'ocr' AND receipt_id IS NOT NULL ORDER BY position, id", [$billId])->fetchAll();
    $numbers = [];
    foreach (q('SELECT id FROM receipts WHERE bill_id = ? ORDER BY position, id', [$billId])->fetchAll(PDO::FETCH_COLUMN) as $i => $rid) {
        $numbers[(int) $rid] = $i + 1;
    }
    $seen = [];
    foreach ($items as $it) {
        $key = norm_key($it['name']) . '|' . cents($it['unit_price']);
        $rid = (int) $it['receipt_id'];
        $first = $seen[$key] ?? null;
        $seen[$key] ??= $rid;
        if ($first === null || $it['dup_note'] !== null) {
            continue;
        }
        $note = $first === $rid
            ? 'Listed twice on this receipt — check it isn’t read twice.'
            : 'Also on Receipt ' . ($numbers[$first] ?? '?') . ' at the same price — ordered again, or the same receipt twice?';
        q('UPDATE receipt_items SET needs_review = 1, dup_note = ? WHERE id = ?', [$note, $it['id']]);
    }
}

/** Remove one receipt with its items and photos, and take its amounts back out of the bill. */
function remove_receipt(array $bill, int $receiptId): void
{
    $r = q('SELECT * FROM receipts WHERE id = ? AND bill_id = ?', [$receiptId, $bill['id']])->fetch();
    if (!$r) {
        fail('Receipt not found on this bill.', 404);
    }
    $files = q('SELECT image FROM receipt_photos WHERE receipt_id = ?', [$receiptId])->fetchAll(PDO::FETCH_COLUMN);
    $pdo = db();
    $pdo->beginTransaction();
    q('DELETE FROM receipts WHERE id = ?', [$receiptId]); // items and photos go with it (ON DELETE CASCADE)
    $total = $r['total'] === null ? null : (float) $r['total'];
    q(
        'UPDATE bills SET tax = GREATEST(0, tax - ?), service_charge = GREATEST(0, service_charge - ?), discount = GREATEST(0, discount - ?),
         receipt_total = ' . ($total === null ? 'receipt_total' : 'IF(receipt_total IS NULL, NULL, GREATEST(0, receipt_total - ?))') . ' WHERE id = ?',
        array_merge([$r['tax'], $r['service_charge'], $r['discount']], $total === null ? [] : [$total], [$bill['id']])
    );
    // Open flags may point at the removed receipt, or at receipt numbers that have now shifted: work them out again.
    q('UPDATE receipt_items SET dup_note = NULL WHERE bill_id = ? AND dup_note IS NOT NULL AND needs_review = 1', [$bill['id']]);
    flag_repeated_items($bill['id']);
    $pdo->commit();
    delete_unused_receipt_files($files);
}

/** Delete photo files that no receipt uses any more (receipts read from one photo share its file). */
function delete_unused_receipt_files(array $files): void
{
    foreach (array_unique($files) as $f) {
        if (!q('SELECT 1 FROM receipt_photos WHERE image = ? LIMIT 1', [$f])->fetchColumn()) {
            delete_upload('receipts', $f);
        }
    }
}
