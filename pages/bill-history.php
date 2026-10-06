<?php
// Bill History is now the "Past bills" view of the Spending page; old links and bookmarks land there.
require __DIR__ . '/../includes/bootstrap.php';
require_login();
redirect('pages/stats?tab=history');
