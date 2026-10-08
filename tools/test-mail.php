<?php
// Sends a test email and prints exactly why it failed, if it did. Run from the project root:
//   C:\xampp\php\php.exe tools\test-mail.php you@example.com

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/mail.php';

$to = $argv[1] ?? '';
if ($to === '') {
    exit("Usage: php tools\test-mail.php you@example.com\n");
}
$m = config('mail');
echo "Sending via {$m['host']}:{$m['port']} ({$m['secure']}) as {$m['user']} ...\n";
if (send_mail($to, 'Setlo test email', '<p>It works - Setlo can send email.</p>', "It works - Setlo can send email.\n")) {
    echo "Sent to $to. Check the inbox (and spam).\n";
    exit(0);
}
echo 'FAILED: ' . mail_last_error() . "\n";
exit(1);
