<?php
// Minimal PayMongo client (Checkout Sessions API) for paying a settlement online.
// Demo only: PayMongo collects into the app's own merchant account, not the receiver's wallet,
// so this is meant to run with test keys (sk_test_...) where no real money moves.

declare(strict_types=1);

// PayMongo rejects checkouts below ₱20.00.
const PAYMONGO_MIN_CENTS = 2000;

class PayMongoException extends RuntimeException
{
}

function paymongo_available(): bool
{
    return (string) config('paymongo.secret_key') !== '' && function_exists('curl_init');
}

function paymongo_test_mode(): bool
{
    return str_starts_with((string) config('paymongo.secret_key'), 'sk_test_');
}

/**
 * Create a hosted checkout page. Returns ['id' => session id, 'url' => checkout URL].
 * @throws PayMongoException with a message that is safe to show to the user
 */
function paymongo_create_checkout(int $cents, string $name, string $description, string $successUrl, string $cancelUrl): array
{
    $res = paymongo_request('POST', 'checkout_sessions', ['data' => ['attributes' => [
        'line_items'           => [['currency' => 'PHP', 'amount' => $cents, 'name' => $name, 'quantity' => 1]],
        'payment_method_types' => (array) config('paymongo.methods'),
        'description'          => $description,
        'success_url'          => $successUrl,
        'cancel_url'           => $cancelUrl,
        'show_description'     => true,
        'show_line_items'      => true,
        'send_email_receipt'   => false,
    ]]]);
    $id = $res['data']['id'] ?? null;
    $url = $res['data']['attributes']['checkout_url'] ?? null;
    if (!is_string($id) || !is_string($url)) {
        throw new PayMongoException('PayMongo sent an unexpected reply. Try again.');
    }
    return ['id' => $id, 'url' => $url];
}

/**
 * The paid payment on a checkout session, or null if nothing has been paid yet.
 * Returns ['id' => payment id, 'amount' => cents, 'method' => e.g. "gcash"].
 */
function paymongo_paid_payment(string $sessionId): ?array
{
    $res = paymongo_request('GET', 'checkout_sessions/' . rawurlencode($sessionId));
    foreach ($res['data']['attributes']['payments'] ?? [] as $p) {
        if (($p['attributes']['status'] ?? '') === 'paid') {
            return [
                'id'     => (string) $p['id'],
                'amount' => (int) $p['attributes']['amount'],
                'method' => (string) ($p['attributes']['source']['type'] ?? 'online'),
            ];
        }
    }
    return null;
}

/** One API call. Returns the decoded JSON body. */
function paymongo_request(string $method, string $path, ?array $body = null): array
{
    if (!paymongo_available()) {
        throw new PayMongoException('Online payment is not set up on this server (missing PayMongo key).');
    }
    $ch = curl_init('https://api.paymongo.com/v1/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => $body === null ? null : json_encode($body),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_USERPWD        => config('paymongo.secret_key') . ':',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw = curl_exec($ch);
    $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw === false) {
        error_log('[setlo] PayMongo request failed: ' . curl_error($ch));
    }
    curl_close($ch);

    if ($status === 0) {
        throw new PayMongoException("Couldn't reach PayMongo. Check your connection and try again.");
    }
    $res = json_decode((string) $raw, true);
    if ($status < 200 || $status >= 300 || !is_array($res)) {
        error_log("[setlo] PayMongo HTTP $status: " . substr((string) $raw, 0, 500));
        if ($status === 401) {
            throw new PayMongoException('PayMongo rejected the server\'s API key.');
        }
        throw new PayMongoException('PayMongo had a problem with this payment. Try again, or pay another way.');
    }
    return $res;
}
