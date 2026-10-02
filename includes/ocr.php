<?php
// Receipt OCR: sends receipt photos to Gemini and turns its structured reply into receipts with line items + totals.
// OCR is assistive: every result goes through the Review screen before it is used.

declare(strict_types=1);

require_once __DIR__ . '/gemini.php';

// Sample receipt used by the "Load demo receipt" option (matches the README demo scenario).
// Works offline, as a backup for live demos. Shows each review case: a misread (item 2), abbreviations turned into
// plain names (items 1, 4, 5) and a meal set with its contents (item 3). Every renamed row is flagged for checking.
// Loading it twice into one bill shows the duplicate-transaction and repeated-item flags.
const DEMO_RECEIPT = [
    'items' => [
        ['name' => 'Chicken Inasal',                   'printed_name' => 'Chkn Inasal',  'qty' => 2, 'unit_price' => 179.00, 'needs_review' => true,  'suggestion' => null, 'details' => null],
        ['name' => 'Bangus Sisig',                     'printed_name' => 'Banaus 5is1g', 'qty' => 1, 'unit_price' => 149.00, 'needs_review' => true,  'suggestion' => null, 'details' => null],
        ['name' => 'Meal set PM2',                     'printed_name' => 'PM2',          'qty' => 1, 'unit_price' => 135.00, 'needs_review' => true,  'suggestion' => null, 'details' => 'Pork BBQ, Rice, Iced Tea'],
        ['name' => 'Halo-Halo, Regular with Ice Cream', 'printed_name' => 'Halo Reg W I.C', 'qty' => 2, 'unit_price' => 89.00, 'needs_review' => true, 'suggestion' => null, 'details' => null],
        ['name' => 'Iced Tea (Large)',                 'printed_name' => 'IcedTea LRG',  'qty' => 4, 'unit_price' => 65.00,  'needs_review' => true,  'suggestion' => null, 'details' => null],
        ['name' => 'Buko Pandan',                      'printed_name' => 'Buko Pandan',  'qty' => 1, 'unit_price' => 70.00,  'needs_review' => false, 'suggestion' => null, 'details' => null],
    ],
    'store_name'     => 'Mang Inasal',
    'receipt_no'     => '00042871',
    'txn_date'       => '2026-10-02 12:41',
    'subtotal'       => 1150.00,
    'tax'            => 55.00,
    'service_charge' => 35.00,
    'discount'       => 0.0,
    'total'          => 1240.00,
    'raw_text'       => "MANG INASAL\nGuadalupe Branch\nOR# 00042871   2026-10-02 12:41\n--------------------------------\n2x Chkn Inasal 358.00\n1x Banaus 5is1g 149.00\n1x PM2 135.00\n   Pork BBQ\n   Rice\n   Iced Tea\n2x Halo Reg W I.C 178.00\n4x IcedTea LRG 260.00\n1x Buko Pandan 70.00\n--------------------------------\nSUBTOTAL 1,150.00\nTAX 55.00\nSVC CHARGE 35.00\nTOTAL 1,240.00\nCASH 1,500.00\nCHANGE 260.00",
];

const RECEIPT_PROMPT = <<<'TXT'
You are reading photos of restaurant or food receipts from the Philippines. Amounts are in pesos.

Return receipts: one entry per separate receipt you can see. If a photo shows two or more receipts side by side
or overlapping, return each one separately; never combine two receipts into one. For each receipt:
- store_name: the store/restaurant name as printed (without the branch), or "" if not visible.
- receipt_no: the receipt, invoice, OR or transaction number as printed, or "" if none.
- date_time: the date and time printed, as YYYY-MM-DD HH:MM (or just YYYY-MM-DD), or "" if none.
- raw_text: the receipt text transcribed line by line, top to bottom, starting with the store/restaurant name.
- items: every ordered food/drink line that has its own price. name_as_printed is the item text exactly as printed
  (do not expand abbreviations, translate or tidy it). qty is the quantity (1 if none is printed). line_total is the
  amount printed for the whole line.
  plain_name is what a diner would call it: expand abbreviations, fix misspellings and drop store codes, keeping
  size and variant words. Put the size in brackets at the end. Leave it identical to name_as_printed when the printed
  name is already clear. Common Philippine receipt shorthand: SML/SM = Small, MED/MD = Medium, LRG/LG = Large,
  REG = Regular, W/ or W = with, W/O = without, I.C / IC = Ice Cream, CHKN = Chicken, BF = Beef, PRK = Pork,
  BFST = Breakfast, HH = Halo-Halo, ICDTEA = Iced Tea, FF = French Fries, SPAG = Spaghetti, BRGR = Burger.
  Examples: "1 SML Coffee" -> "Coffee (Small)", "Cffe" -> "Coffee", "PRoastCoffe" -> "Roast Coffee",
  "Hte1 Apple Pie" -> "Apple Pie", "Halo Reg W I.C" -> "Halo-Halo, Regular with Ice Cream".
  Meal sets: a code or set name (like C1, PM2, "Value Meal 3") with a price, usually followed by unpriced or ₱0
  lines listing what it includes. Return it as ONE item with is_meal_set=true and those included lines in
  set_contents (plain names, e.g. ["Chicken", "Rice", "Iced Tea"]); do not list the included lines as items.
  Its plain_name is a short name that keeps the code, e.g. "Chicken Meal (C1)", or "Meal set C1" if unknown.
  Lines under a set with their own price (upgrades, add-ons) are separate items. Otherwise is_meal_set=false
  and set_contents=[]. Skip instruction lines like "No onions" or "Less ice".
  Set unclear=true when the printed name is smudged, cut off or ambiguous, or you are guessing plain_name.
- subtotal, total: as printed, or null if missing.
- tax: the VAT / tax amount printed, whether added on top or already included in the prices (0 if none).
- service_charge: service charge amounts (0 if none).
- discount: senior citizen, PWD, promo discounts and "LESS VAT" amounts, as a positive number (0 if none).

Do not list payment and change lines (CASH, CHANGE, TENDER, CARD, GCASH, MAYA), VATABLE / VAT-EXEMPT / ZERO-RATED
breakdown lines, subtotals, taxes, service charges or discounts as items.
If a photo is not a receipt, return no receipt for it.
TXT;

// Added to the prompt when several photos are sections of one long receipt.
const RECEIPT_PARTS_PROMPT = <<<'TXT'
These photos are consecutive sections of ONE long receipt, top to bottom, in order. Neighbouring photos usually
overlap: a line visible at the bottom of one photo and again at the top of the next is ONE line, so list it once.
Return exactly one receipt.
TXT;

const RECEIPT_SCHEMA = [
    'type'       => 'OBJECT',
    'properties' => [
        'store_name' => ['type' => 'STRING'],
        'receipt_no' => ['type' => 'STRING'],
        'date_time'  => ['type' => 'STRING'],
        'raw_text'   => ['type' => 'STRING'],
        'items'      => [
            'type'  => 'ARRAY',
            'items' => [
                'type'       => 'OBJECT',
                'properties' => [
                    'name_as_printed' => ['type' => 'STRING'],
                    'plain_name'      => ['type' => 'STRING'],
                    'qty'             => ['type' => 'INTEGER'],
                    'line_total'      => ['type' => 'NUMBER'],
                    'unclear'         => ['type' => 'BOOLEAN'],
                    'is_meal_set'     => ['type' => 'BOOLEAN'],
                    'set_contents'    => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                ],
                'required'   => ['name_as_printed', 'plain_name', 'qty', 'line_total', 'unclear', 'is_meal_set', 'set_contents'],
            ],
        ],
        'subtotal'       => ['type' => 'NUMBER', 'nullable' => true],
        'tax'            => ['type' => 'NUMBER'],
        'service_charge' => ['type' => 'NUMBER'],
        'discount'       => ['type' => 'NUMBER'],
        'total'          => ['type' => 'NUMBER', 'nullable' => true],
    ],
    'required'   => ['store_name', 'receipt_no', 'date_time', 'raw_text', 'items', 'tax', 'service_charge', 'discount'],
];

const RECEIPTS_SCHEMA = [
    'type'       => 'OBJECT',
    'properties' => ['receipts' => ['type' => 'ARRAY', 'items' => RECEIPT_SCHEMA]],
    'required'   => ['receipts'],
];

/**
 * Read receipt photos into one entry per receipt found. Usually one photo (which may show several receipts); with
 * $partsOfOne, several photos that are sections of one long receipt, which always gives at most one receipt.
 * @return list<array{items: array, subtotal: ?float, tax: float, service_charge: float, discount: float, total: ?float,
 *                    raw_text: string, store_name: string, receipt_no: string, txn_date: string}>
 * @throws GeminiException
 */
function scan_receipt(array $imagePaths, bool $partsOfOne = false): array
{
    $parts = array_map('gemini_image_part', $imagePaths);
    $parts[] = ['text' => RECEIPT_PROMPT . ($partsOfOne ? "\n\n" . RECEIPT_PARTS_PROMPT : '')];
    $data = gemini_json($parts, RECEIPTS_SCHEMA);

    $receipts = array_values(array_filter(
        array_map('parse_receipt', is_array($data['receipts'] ?? null) ? $data['receipts'] : []),
        fn ($r) => $r['items'] || $r['total'] !== null
    ));
    if ($partsOfOne && count($receipts) > 1) {
        $receipts = [merge_receipt_parts($receipts)];
    }
    return $receipts;
}

/** Sections that came back as separate receipts despite the prompt: items in order, totals from the last section that has them. */
function merge_receipt_parts(array $parts): array
{
    $out = $parts[0];
    foreach (array_slice($parts, 1) as $p) {
        $out['items'] = array_merge($out['items'], $p['items']);
        $out['raw_text'] = trim($out['raw_text'] . "\n" . $p['raw_text']);
        foreach (['store_name', 'receipt_no', 'txn_date'] as $k) {
            $out[$k] = $out[$k] ?: $p[$k];
        }
        foreach (['subtotal', 'total'] as $k) {
            $out[$k] = $p[$k] ?? $out[$k];
        }
        foreach (['tax', 'service_charge', 'discount'] as $k) {
            $out[$k] = $p[$k] ?: $out[$k];
        }
    }
    return $out;
}

/** One receipt from Gemini's reply, cleaned up. */
function parse_receipt($data): array
{
    $data = is_array($data) ? $data : [];
    $amount = fn ($v) => is_numeric($v) ? round(abs((float) $v), 2) : null;
    // Item names go into the page as text; keep them to one tidy line without markup characters.
    $clean = fn ($v, $max) => mb_substr(trim(preg_replace('/\s+/', ' ', str_replace(['<', '>'], '', (string) $v))), 0, $max);
    // Only a real rename needs checking, not a change of case or punctuation ("RICE" -> "Rice").
    $key = fn ($s) => preg_replace('/[^a-z0-9]/', '', strtolower($s));
    $items = [];
    foreach ($data['items'] ?? [] as $it) {
        $printed = $clean($it['name_as_printed'] ?? '', 120);
        $lineTotal = $amount($it['line_total'] ?? null);
        if ($printed === '' || !$lineTotal) {
            continue;
        }
        $qty = max(1, min(99, (int) ($it['qty'] ?? 1)));
        $name = $clean($it['plain_name'] ?? '', 120) ?: $printed;
        $contents = array_values(array_filter(array_map(fn ($c) => $clean($c, 60), (array) ($it['set_contents'] ?? []))));
        $isSet = !empty($it['is_meal_set']);
        $items[] = [
            'name'         => $name,
            'printed_name' => $printed,
            'qty'          => $qty,
            'unit_price'   => round($lineTotal / $qty, 2),
            // Renamed, unclear, or a meal set whose contents aren't listed: the user checks it on Review.
            'needs_review' => !empty($it['unclear']) || $key($name) !== $key($printed) || ($isSet && !$contents),
            'suggestion'   => null,
            'details'      => $contents ? mb_substr(implode(', ', $contents), 0, 255) : null,
        ];
    }

    return [
        'items'          => $items,
        'subtotal'       => $amount($data['subtotal'] ?? null),
        'tax'            => $amount($data['tax'] ?? null) ?? 0.0,
        'service_charge' => $amount($data['service_charge'] ?? null) ?? 0.0,
        'discount'       => $amount($data['discount'] ?? null) ?? 0.0,
        'total'          => $amount($data['total'] ?? null),
        'raw_text'       => trim((string) ($data['raw_text'] ?? '')),
        'store_name'     => $clean($data['store_name'] ?? '', 120),
        'receipt_no'     => $clean($data['receipt_no'] ?? '', 60),
        'txn_date'       => $clean($data['date_time'] ?? '', 40),
    ];
}
