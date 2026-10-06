<?php
// Creates the `setlo` database, all tables, and the App Manager (admin) account. No sample users or bills:
// people sign up on the app.
// Run from the project root:  C:\xampp\php\php.exe database\setup.php
// WARNING: drops and recreates every Setlo table.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require __DIR__ . '/../includes/bootstrap.php';

// ---------- Schema ----------
$c = config('db');
$root = new PDO("mysql:host={$c['host']};port={$c['port']};charset={$c['charset']}", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sql = preg_replace('/(^|\s)-- .*$/m', '$1', file_get_contents(__DIR__ . '/schema.sql'));
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    $root->exec($stmt);
}
echo "Schema created.\n";

// ---------- App Manager ----------
q("INSERT INTO users (full_name, email, password_hash, role, avatar_color) VALUES ('App Manager', 'admin@setlo.app', ?, 'admin', '#0d9488')",
    [password_hash('admin12345', PASSWORD_DEFAULT)]);

echo "\nSign up at http://localhost" . config('base_url') . "/pages/register\n";
echo "  App Manager:  admin@setlo.app  (password: admin12345 — change it after the first sign-in)\n";
