<?php
// Receipt OCR: sends receipt photos to Gemini and turns its structured reply into receipts with line items + totals.
// OCR is assistive: every result goes through the Review screen before it is used.

declare(strict_types=1);

require_once __DIR__ . '/gemini.php';

const RECEIPT_PROMPT = <<<'TXT'
You are reading photos of restaurant or food receipts from the Philippines. Amounts are in pesos.

Return receipts: one entry per separate receipt you can see. If a photo shows two or more receipts side by side
or overlapping, return each one separately; never combine two receipts into one. For each receipt:
- store_name: the store/restaurant name as printed (without the branch), or "" if not visible.
- title: a short name (1-4 words) for this purchase, to tell it apart from the group's other receipts. Base it on
  the meal and the time printed (Breakfast, Lunch, Merienda, Dinner, Coffee, Snacks, Drinks, Dessert, Groceries)
  and the store in plain words, e.g. "Lunch - Mang Inasal", "Coffee - Starbucks", "Merienda". Never include item
  or meal codes (letter and number combos like C1, PM2, B12, 2PC), receipt numbers, prices or dates.
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
  Item promos: a negative amount printed directly under an item line (e.g. "-57.50", "57.50-", "(57.50)", often
  labelled PROMO, DISC, LESS, B1T1, xx% OFF, VOUCHER) belongs to that item. Put it in that item's promo as a
  positive number and keep the item's line_total as printed BEFORE the promo; never list the promo line as an item.
  If several promo lines sit under one item, add them up. promo is 0 when the item has none.
- subtotal, total: as printed, or null if missing.
- tax: the VAT / tax amount printed, whether added on top or already included in the prices (0 if none).
- service_charge: service charge amounts (0 if none).
- discount: senior citizen, PWD, whole-receipt promo discounts and "LESS VAT" amounts printed with the totals, as a
  positive number (0 if none). Do not include item promos already given under an item.

Do not list payment and change lines (CASH, CHANGE, TENDER, CARD, GCASH, MAYA), VATABLE / VAT-EXEMPT / ZERO-RATED
breakdown lines, subtotals, taxes, service charges or discounts as items.
If a photo is not a receipt, return no receipt for it.
TXT;

// Names of lines that are a promo on the item above, not an item ("PROMO DISC", "LESS 10%", "20% OFF"). Kept narrow so
// real items aren't caught ("B1T1 Burger", "Less Sugar Tea"); a promo printed as a negative amount is caught by its sign.
const PROMO_LINE = '/^\s*(?:(?:promo|item)\s*disc\w*|disc(?:ount)?|void|voucher|coupon|markdown)\b|^\s*less\s*(?:\d|disc|promo)|\b\d{1,3}\s*%\s*off\b/i';

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
        'title'      => ['type' => 'STRING'],
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
                    'promo'           => ['type' => 'NUMBER'],
                    'unclear'         => ['type' => 'BOOLEAN'],
                    'is_meal_set'     => ['type' => 'BOOLEAN'],
                    'set_contents'    => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                ],
                'required'   => ['name_as_printed', 'plain_name', 'qty', 'line_total', 'promo', 'unclear', 'is_meal_set', 'set_contents'],
            ],
        ],
        'subtotal'       => ['type' => 'NUMBER', 'nullable' => true],
        'tax'            => ['type' => 'NUMBER'],
        'service_charge' => ['type' => 'NUMBER'],
        'discount'       => ['type' => 'NUMBER'],
        'total'          => ['type' => 'NUMBER', 'nullable' => true],
    ],
    'required'   => ['store_name', 'title', 'receipt_no', 'date_time', 'raw_text', 'items', 'tax', 'service_charge', 'discount'],
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
 *                    raw_text: string, store_name: string, title: string, receipt_no: string, txn_date: string}>
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

const RECEIPT_CHECK_PROMPT = <<<'TXT'
Is this a photo of a printed receipt, bill or invoice (even if blurry, tilted, dim or partly cut off)?
is_receipt: true if a receipt, bill or invoice is the main thing in the photo. false for people, faces, rooms, screens,
food, objects, walls, or any other kind of document or photo.
what: 2 to 5 words naming the main subject of the photo, e.g. "a person", "a wall", "a laptop screen", "a receipt".
TXT;

const RECEIPT_CHECK_SCHEMA = [
    'type'       => 'OBJECT',
    'properties' => ['is_receipt' => ['type' => 'BOOLEAN'], 'what' => ['type' => 'STRING']],
    'required'   => ['is_receipt', 'what'],
];

/**
 * A quick look at one photo before it is queued: is it a receipt at all? Two model attempts at most, so it stays fast.
 * @return array{is_receipt: bool, what: string}
 * @throws GeminiException
 */
function check_receipt_photo(string $imagePath): array
{
    $data = gemini_json([gemini_image_part($imagePath), ['text' => RECEIPT_CHECK_PROMPT]], RECEIPT_CHECK_SCHEMA, 2);
    $what = mb_substr(trim(preg_replace('/\s+/', ' ', str_replace(['<', '>'], '', (string) ($data['what'] ?? '')))), 0, 40);
    return ['is_receipt' => (bool) ($data['is_receipt'] ?? true), 'what' => $what];
}

/** Sections that came back as separate receipts despite the prompt: items in order, totals from the last section that has them. */
function merge_receipt_parts(array $parts): array
{
    $out = $parts[0];
    foreach (array_slice($parts, 1) as $p) {
        $out['items'] = array_merge($out['items'], $p['items']);
        $out['raw_text'] = trim($out['raw_text'] . "\n" . $p['raw_text']);
        foreach (['store_name', 'title', 'receipt_no', 'txn_date'] as $k) {
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
        // A promo line read as an item of its own (negative, or named like a discount): it comes off the item above.
        $negative = is_numeric($it['line_total']) && (float) $it['line_total'] < 0;
        if ($negative || preg_match(PROMO_LINE, $printed)) {
            if ($items) {
                $last = &$items[count($items) - 1];
                // Unless the scanner already put this same amount in that item's promo.
                if (cents($last['promo']) !== cents($lineTotal)) {
                    $last['promo'] = round($last['promo'] + $lineTotal, 2);
                }
                unset($last);
            }
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
            'line_total'   => $lineTotal,
            'promo'        => $amount($it['promo'] ?? null) ?? 0.0,
            // Renamed, unclear, or a meal set whose contents aren't listed: the user checks it on Review.
            'needs_review' => !empty($it['unclear']) || $key($name) !== $key($printed) || ($isSet && !$contents),
            'suggestion'   => null,
            'details'      => $contents ? mb_substr(implode(', ', $contents), 0, 255) : null,
        ];
    }

    // The price is what was actually paid for the line: printed amount less its promo (a promo can't go below free).
    // Promos are always flagged, so the user confirms the item they came off.
    $promos = 0;
    foreach ($items as &$item) {
        $item['promo'] = min($item['promo'], $item['line_total']);
        $item['unit_price'] = round(($item['line_total'] - $item['promo']) / $item['qty'], 2);
        $item['needs_review'] = $item['needs_review'] || $item['promo'] > 0;
        $promos += cents($item['promo']);
        unset($item['line_total']);
    }
    unset($item);

    // Item promos counted again in the receipt's discount would be taken off twice.
    $discount = $amount($data['discount'] ?? null) ?? 0.0;
    if ($promos && abs(cents($discount) - $promos) <= 1) {
        $discount = 0.0;
    }

    return [
        'items'          => $items,
        'subtotal'       => $amount($data['subtotal'] ?? null),
        'tax'            => $amount($data['tax'] ?? null) ?? 0.0,
        'service_charge' => $amount($data['service_charge'] ?? null) ?? 0.0,
        'discount'       => $discount,
        'total'          => $amount($data['total'] ?? null),
        'raw_text'       => trim((string) ($data['raw_text'] ?? '')),
        'store_name'     => $clean($data['store_name'] ?? '', 120),
        'title'          => mb_substr(strip_codes($clean($data['title'] ?? '', 120)), 0, 60),
        'receipt_no'     => $clean($data['receipt_no'] ?? '', 60),
        'txn_date'       => $clean($data['date_time'] ?? '', 40),
    ];
}

/**
 * Drop menu / meal codes ("C1", "PM2", "#B12", "(2PC)") and long numbers from a suggested title, in case the scanner
 * kept them: "Lunch - C1 Mang Inasal" -> "Lunch - Mang Inasal". Words with no digit, like "7-Eleven", stay.
 */
function strip_codes(string $s): string
{
    $s = preg_replace('/[#(\[]*\b(?:(?=[a-z0-9]*\d)(?=[a-z0-9]*[a-z])[a-z0-9]{2,6}|\d{3,})\b[)\]]*/i', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    // Separators left with nothing on one side ("Lunch - ", "Lunch - - Cafe").
    $s = preg_replace('/(\s*[-–·,|\/:])+(?=\s*[-–·,|\/:])/u', '', $s);
    return trim(preg_replace('/^[\s\-–·,|\/:]+|[\s\-–·,|\/:]+$/u', '', $s));
}
