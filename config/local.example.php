<?php
// Local secrets for this machine. Copy this file to config/local.php and fill in the values.
// config/local.php is ignored by git, so the keys never get committed. Leave a value empty to turn that feature off.
// Environment variables (SetEnv in httpd.conf) still take priority over anything here.

return [
    'GEMINI_API_KEY'      => '',   // receipt scanning - free key at https://aistudio.google.com/apikey
    'GOOGLE_CLIENT_ID'    => '',   // "Continue with Google"
    'PAYMONGO_SECRET_KEY' => '',   // "Pay online" - use a TEST key (sk_test_...)
];
