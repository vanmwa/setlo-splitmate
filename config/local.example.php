<?php
// Local secrets for this machine. Copy this file to config/local.php and fill in the values.
// config/local.php is ignored by git, so the keys never get committed. Leave a value empty to turn that feature off.
// Environment variables (SetEnv in httpd.conf) still take priority over anything here.

return [
    'GEMINI_API_KEY'      => '',   // receipt scanning - free key at https://aistudio.google.com/apikey
    'GOOGLE_CLIENT_ID'    => '',   // "Continue with Google"
    'PAYMONGO_SECRET_KEY' => '',   // "Pay online" - use a TEST key (sk_test_...)

    // "Forgot password?" emails a 6-digit code. Gmail: turn on 2-Step Verification, then create an App Password
    // at https://myaccount.google.com/apppasswords and paste it here (not your normal password).
    'SMTP_HOST'           => 'smtp.gmail.com',
    'SMTP_PORT'           => '587',
    'SMTP_SECURE'         => 'tls',   // tls (port 587) or ssl (port 465)
    'SMTP_USER'           => '',      // e.g. yourname@gmail.com
    'SMTP_PASS'           => '',      // the 16-character App Password
    'MAIL_FROM'           => '',      // leave empty to send from SMTP_USER
    'MAIL_FROM_NAME'      => 'Setlo',
];
