<?php
// Server-side input rules. They mirror assets/js/validate.js so the browser and API agree.
// Each function returns an error message, or '' when the value is fine.

declare(strict_types=1);

function valid_name(string $v, string $label = 'Name'): string
{
    $t = trim($v);
    if ($t === '') {
        return "$label is required.";
    }
    if (strlen($t) < 2 || strlen($t) > 100) {
        return "$label must be 2–100 characters.";
    }
    return preg_match("/^\\p{L}[\\p{L} .'\\-]*$/u", $t) ? '' : "$label can only have letters, spaces, periods, apostrophes and hyphens.";
}

function valid_bill_name(string $v): string
{
    $t = trim($v);
    if ($t === '') {
        return 'Give the bill a name.';
    }
    if (strlen($t) > 120) {
        return 'Bill name must be 120 characters or fewer.';
    }
    return preg_match('/[<>]/', $t) ? 'Bill name can’t contain < or >.' : '';
}

function valid_password(string $p): string
{
    if (strlen($p) < 8) {
        return 'Use at least 8 characters.';
    }
    if (strlen($p) > 72) {
        return 'Use 72 characters or fewer.';
    }
    return preg_match('/[A-Za-z]/', $p) && preg_match('/\d/', $p) ? '' : 'Include at least one letter and one number.';
}

function valid_account(string $v): string
{
    $t = trim($v);
    if ($t === '') {
        return '';
    }
    if (strlen($t) > 60) {
        return 'Keep the account details to 60 characters or fewer.';
    }
    return preg_match('/^[\p{L}\d .·+\-()]+$/u', $t) ? '' : 'Use letters, numbers, spaces and · . - + ( ) only.';
}

// Philippine mobile number: 09 followed by 9 more digits (11 in total).
function valid_gcash(string $v): string
{
    $t = trim($v);
    if ($t === '') {
        return '';
    }
    return preg_match('/^09\d{9}$/', $t) ? '' : 'GCash number must start with 09 and be exactly 11 digits.';
}

function valid_ref(string $v): string
{
    $t = trim($v);
    if ($t === '') {
        return '';
    }
    if (strlen($t) > 60) {
        return 'Reference number must be 60 characters or fewer.';
    }
    return preg_match('/^[A-Za-z0-9 \-]+$/', $t) ? '' : 'Reference number can only have letters, numbers, spaces and hyphens.';
}

/** Fail the request with a 422 when a rule returned an error. */
function require_valid(string $error, ?string $field = null): void
{
    if ($error !== '') {
        fail($error, 422, $field ? ['fields' => [$field => $error]] : []);
    }
}
