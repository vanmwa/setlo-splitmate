<?php
// Image uploads (receipts, proof-of-payment screenshots, profile pictures).
// Files live under uploads/<kind>/, which is not web-accessible; they are served through the API after an access check.
// (The Pay-me QR used to be an upload too; it's now generated client-side, see assets/js/ui.js qrSvg/qrPng.)

declare(strict_types=1);

const UPLOAD_KINDS = ['receipts', 'proofs', 'avatars'];

function upload_dir(string $kind): string
{
    if (!in_array($kind, UPLOAD_KINDS, true)) {
        throw new InvalidArgumentException("Unknown upload kind: $kind");
    }
    $dir = config('uploads.dir') . '/' . $kind;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * Validate and store an uploaded image from $_FILES[$field].
 * Returns the stored filename, or null when the field was left empty and $required is false.
 */
function save_uploaded_image(string $field, string $kind, string $prefix, bool $required = true): ?string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            fail('Choose a photo to upload.', 422);
        }
        return null;
    }
    return store_upload($f, $kind, $prefix);
}

/**
 * Validate and store every image sent as $field[] (several files from one form field). Returns the stored
 * filenames in the order sent; fails (after removing any already stored) if one is not a valid image.
 */
function save_uploaded_images(string $field, string $kind, string $prefix, int $max): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || !is_array($f['name'])) {
        return [];
    }
    $files = [];
    foreach (array_keys($f['name']) as $i) {
        if ($f['error'][$i] !== UPLOAD_ERR_NO_FILE) {
            $files[] = ['error' => $f['error'][$i], 'size' => $f['size'][$i], 'tmp_name' => $f['tmp_name'][$i]];
        }
    }
    if (count($files) > $max) {
        fail("Send at most $max photos at a time.", 422);
    }
    $saved = [];
    try {
        foreach ($files as $file) {
            $saved[] = store_upload($file, $kind, $prefix);
        }
    } catch (Throwable $e) {
        foreach ($saved as $name) {
            delete_upload($kind, $name);
        }
        throw $e;
    }
    return $saved;
}

/** Check one $_FILES entry and move it into uploads/<kind>/. Returns the stored filename. */
function store_upload(array $f, string $kind, string $prefix): string
{
    if ($f['error'] !== UPLOAD_ERR_OK) {
        fail('Upload failed. Try a smaller photo.', 422);
    }
    if ($f['size'] > config('uploads.max_bytes')) {
        fail('Photo is too large (max 8 MB).', 422);
    }
    $info = @getimagesize($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext) {
        fail('Please upload a JPG, PNG or WebP image.', 422);
    }
    $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], upload_dir($kind) . '/' . $name)) {
        fail('Could not save the photo.', 500);
    }
    return $name;
}

/**
 * Perceptual "difference hash" of an image: 256 bits (64 hex chars) from a 17×16 grayscale thumbnail, one bit per
 * pair of neighbouring pixels (is the left one brighter?). Re-saved, resized or recompressed copies of a photo land
 * a few bits apart; different receipts land far apart. Null without the GD extension.
 */
function image_dhash(string $path): ?string
{
    if (!extension_loaded('gd')) {
        return null;
    }
    $img = @imagecreatefromstring((string) file_get_contents($path));
    if (!$img) {
        return null;
    }
    $thumb = imagecreatetruecolor(17, 16);
    imagecopyresampled($thumb, $img, 0, 0, 0, 0, 17, 16, imagesx($img), imagesy($img));
    $bits = '';
    for ($y = 0; $y < 16; $y++) {
        $prev = null;
        for ($x = 0; $x < 17; $x++) {
            $c = imagecolorat($thumb, $x, $y);
            $lum = (($c >> 16) & 0xFF) * 299 + (($c >> 8) & 0xFF) * 587 + ($c & 0xFF) * 114;
            if ($prev !== null) {
                $bits .= $prev > $lum ? '1' : '0';
            }
            $prev = $lum;
        }
    }
    $hex = '';
    foreach (str_split($bits, 4) as $nibble) {
        $hex .= dechex(bindec($nibble));
    }
    return $hex;
}

/** Number of differing bits between two image_dhash() values. */
function dhash_distance(string $a, string $b): int
{
    $d = 0;
    for ($i = 0, $n = min(strlen($a), strlen($b)); $i < $n; $i++) {
        $x = hexdec($a[$i]) ^ hexdec($b[$i]);
        $d += ($x & 1) + (($x >> 1) & 1) + (($x >> 2) & 1) + (($x >> 3) & 1);
    }
    return $d;
}

function delete_upload(string $kind, ?string $name): void
{
    if ($name) {
        @unlink(upload_dir($kind) . '/' . basename($name));
    }
}

/** Stream a stored image to the browser and stop. */
function serve_upload(string $kind, ?string $name): void
{
    $file = $name ? upload_dir($kind) . '/' . basename($name) : null;
    if (!$file || !is_file($file)) {
        fail('Image not found.', 404);
    }
    $info = getimagesize($file);
    header('Content-Type: ' . ($info['mime'] ?? 'application/octet-stream'));
    header_remove('Cache-Control');
    header('Cache-Control: private, max-age=3600');
    readfile($file);
    exit;
}
