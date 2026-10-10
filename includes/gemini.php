<?php
// Minimal Google Gemini client (REST generateContent with structured JSON output).
// Used for receipt OCR now; reusable for other prompts such as humanizing item names.

declare(strict_types=1);

// Phone photos are resized to this long edge before upload: plenty for receipt text, far fewer bytes.
const GEMINI_IMAGE_MAX_EDGE = 2000;
// Stop starting new attempts after this many seconds, so a scan can't outlive the request's time limit.
const GEMINI_TIME_BUDGET = 150;

class GeminiException extends RuntimeException
{
}

function gemini_available(): bool
{
    return gemini_setup_problem() === null;
}

/** Why scanning is off on this server, in words the person setting it up can act on; null when it's ready. */
function gemini_setup_problem(): ?string
{
    if (!function_exists('curl_init')) {
        return "PHP's curl extension is off — remove the ; before extension=curl in xampp\\php\\php.ini, then restart Apache";
    }
    if ((string) config('gemini.api_key') !== '') {
        return null;
    }
    $dir = realpath(__DIR__ . '/../config');
    if (!is_file("$dir/local.php")) {
        $near = array_diff(glob("$dir/local*") ?: [], ["$dir/local.example.php"]);
        return $near
            ? 'found ' . basename(reset($near)) . ' — rename it to exactly local.php (check for a hidden .txt ending)'
            : "no Gemini key — copy config/local.example.php to config/local.php and paste the key in";
    }
    return "config/local.php has no GEMINI_API_KEY value — paste the key between the quotes and save";
}

/**
 * Send prompt parts to Gemini and return the decoded JSON reply, shaped by $schema.
 * $maxAttempts limits how many models are tried (0 = the whole list, twice); quick checks use 2 so they never hang.
 * @throws GeminiException with a message that is safe to show to the user
 */
function gemini_json(array $parts, array $schema, int $maxAttempts = 0): array
{
    if (!gemini_available()) {
        throw new GeminiException('Receipt scanning is not set up on this server (missing Gemini API key).');
    }
    $body = json_encode([
        'contents'         => [['role' => 'user', 'parts' => $parts]],
        'generationConfig' => [
            'temperature'      => 0,
            'responseMimeType' => 'application/json',
            'responseSchema'   => $schema,
        ],
    ]);

    // Overloaded (503), rate-limited (429) or timed-out calls are retried down the model list, then the whole list
    // once more after a short pause (busy spells often clear within seconds), within an overall time budget.
    $models = array_values(array_unique(array_merge([(string) config('gemini.model')], (array) config('gemini.fallback_models'))));
    $attempts = array_merge($models, $models);
    if ($maxAttempts > 0) {
        $attempts = array_slice($attempts, 0, $maxAttempts);
    }
    $deadline = time() + GEMINI_TIME_BUDGET;
    $answered = false; // any attempt got an HTTP reply, so the network itself is fine
    foreach ($attempts as $i => $m) {
        if ($i > 0) {
            sleep($i === count($models) ? 4 : 1);
        }
        [$status, $raw] = gemini_request($m, $body);
        $answered = $answered || $status !== 0;
        if ($status === 200 || !in_array($status, [0, 429, 500, 503, 504], true)) {
            break;
        }
        $last = $i + 1 >= count($attempts) || time() + 5 >= $deadline;
        error_log("[setlo] Gemini $m busy (HTTP $status), " . ($last ? 'giving up' : 'retrying'));
        if ($last) {
            break;
        }
    }

    if ($status === 0) {
        // A timeout after busy replies means an overloaded service, not a dead connection.
        throw new GeminiException($answered
            ? 'The scanning service is busy right now. Try again in a minute, or enter the items manually.'
            : "Couldn't reach the scanning service. Check your connection and try again.");
    }
    $res = json_decode((string) $raw, true);
    if ($status !== 200) {
        error_log("[setlo] Gemini HTTP $status: " . substr((string) $raw, 0, 500));
        if (in_array($status, [429, 500, 503, 504], true)) {
            throw new GeminiException('The scanning service is busy right now. Try again in a minute, or enter the items manually.');
        }
        if (in_array($status, [400, 401, 403], true) && stripos((string) ($res['error']['message'] ?? ''), 'API key') !== false) {
            throw new GeminiException('The scanning service rejected the server\'s API key.');
        }
        throw new GeminiException('The scanning service had a problem. Try again, or enter the items manually.');
    }

    $text = $res['candidates'][0]['content']['parts'][0]['text'] ?? null;
    $data = is_string($text) ? json_decode($text, true) : null;
    if (!is_array($data)) {
        error_log('[setlo] Gemini returned no usable JSON: ' . substr((string) $raw, 0, 500));
        throw new GeminiException("The receipt couldn't be read. Try a clearer photo, or enter the items manually.");
    }
    return $data;
}

/**
 * One generateContent call. Returns [HTTP status, body]; status 0 means a network error or timeout.
 */
function gemini_request(string $model, string $body): array
{
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . config('gemini.api_key')],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => (int) (config('gemini.timeout') ?: 25),
    ]);
    $raw = curl_exec($ch);
    $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw === false) {
        error_log("[setlo] Gemini $model request failed: " . curl_error($ch));
    }
    curl_close($ch);
    return [$status, (string) $raw];
}

/** An inline image part, downscaled (and turned upright) when GD is available. */
function gemini_image_part(string $path): array
{
    $bytes = (string) file_get_contents($path);
    $info = @getimagesizefromstring($bytes);
    $mime = $info['mime'] ?? 'image/jpeg';

    if ($info && extension_loaded('gd') && max($info[0], $info[1]) > GEMINI_IMAGE_MAX_EDGE) {
        $img = @imagecreatefromstring($bytes);
        if ($img) {
            // Re-encoding drops EXIF, so apply the camera's orientation first.
            $orientation = $mime === 'image/jpeg' && function_exists('exif_read_data') ? (int) (@exif_read_data($path)['Orientation'] ?? 1) : 1;
            $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
            if ($angle) {
                $img = imagerotate($img, $angle, 0);
            }
            $scale = GEMINI_IMAGE_MAX_EDGE / max(imagesx($img), imagesy($img));
            $img = imagescale($img, (int) round(imagesx($img) * $scale), (int) round(imagesy($img) * $scale));
            ob_start();
            imagejpeg($img, null, 85);
            $bytes = (string) ob_get_clean();
            $mime = 'image/jpeg';
        }
    }
    return ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]];
}
