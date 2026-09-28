<?php
// Brings an existing Setlo database up to date without losing data. Safe to run repeatedly.
// Run from the project root:  C:\xampp\php\php.exe database\migrate.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require __DIR__ . '/../includes/bootstrap.php';

$steps = [
    // Payment QR + account on profile, proof of payment on settlements
    'ALTER TABLE users ADD COLUMN IF NOT EXISTS payment_account VARCHAR(60) NULL AFTER payment_method',
    'ALTER TABLE users ADD COLUMN IF NOT EXISTS payment_qr VARCHAR(255) NULL AFTER payment_account',
    'ALTER TABLE settlements ADD COLUMN IF NOT EXISTS payment_ref VARCHAR(60) NULL AFTER dispute_reason',
    'ALTER TABLE settlements ADD COLUMN IF NOT EXISTS proof_image VARCHAR(255) NULL AFTER payment_ref',
    // Senior/PWD discount + invite links
    'ALTER TABLE bills ADD COLUMN IF NOT EXISTS discount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER service_charge',
    'ALTER TABLE bills ADD COLUMN IF NOT EXISTS invite_code CHAR(12) NULL UNIQUE AFTER ocr_status',
    "ALTER TABLE bill_members ADD COLUMN IF NOT EXISTS discount_type ENUM('none','senior','pwd') NOT NULL DEFAULT 'none' AFTER user_id",
    // Guest members (no account)
    'ALTER TABLE users MODIFY email VARCHAR(190) NULL',
    "ALTER TABLE users MODIFY role ENUM('user','admin','guest') NOT NULL DEFAULT 'user'",
    // Multiple payers
    'CREATE TABLE IF NOT EXISTS bill_payments (
       bill_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, amount DECIMAL(10,2) NOT NULL,
       PRIMARY KEY (bill_id, user_id),
       FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
       FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
     ) ENGINE=InnoDB',
    // Continue with Google
    'ALTER TABLE users ADD COLUMN IF NOT EXISTS google_sub VARCHAR(64) NULL UNIQUE AFTER password_hash',
    // Security: sign-in throttling
    'CREATE TABLE IF NOT EXISTS login_attempts (
       id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL, ip VARCHAR(45) NOT NULL,
       attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (email, ip, attempted_at)
     ) ENGINE=InnoDB',
    // Personal "Pay me" QR code per account
    'ALTER TABLE users ADD COLUMN IF NOT EXISTS pay_code CHAR(12) NULL UNIQUE AFTER google_sub',
    // The Pay-me QR is generated client-side now (assets/js/ui.js qrSvg/qrPng); the old uploaded-QR
    // column is unused dead weight — drop it if an earlier run of this script added it.
    'ALTER TABLE users DROP COLUMN IF EXISTS payment_qr',
    // How a settlement was paid (PayMongo online, transfer with proof, or cash in person)
    "ALTER TABLE settlements ADD COLUMN IF NOT EXISTS settle_method ENUM('online','transfer','cash') NULL AFTER status",
    'ALTER TABLE settlements ADD COLUMN IF NOT EXISTS paymongo_session VARCHAR(80) NULL AFTER proof_image',
    // Plain-English item names from receipt codes, and meal-set contents
    'ALTER TABLE receipt_items ADD COLUMN IF NOT EXISTS printed_name VARCHAR(120) NULL AFTER ocr_name',
    'ALTER TABLE receipt_items ADD COLUMN IF NOT EXISTS details VARCHAR(255) NULL AFTER printed_name',
];
foreach ($steps as $sql) {
    db()->exec($sql);
}

// Give every real account (not guests) its own Pay-me code.
require_once __DIR__ . '/../includes/domain.php';
$missing = db()->query("SELECT id FROM users WHERE pay_code IS NULL AND role = 'user'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($missing as $uid) {
    q('UPDATE users SET pay_code = ? WHERE id = ?', [new_pay_code(), $uid]);
}
if ($missing) {
    echo 'Created Pay-me QR codes for ' . count($missing) . " account(s).\n";
}
echo "Database is up to date.\n";
