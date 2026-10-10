<?php
/**
 * Page head. Set before including:
 *   $title      page title (required)
 *   $bodyClass  optional extra classes for <body>
 *   $loginPage  where the API client sends expired sessions (default login)
 *   $headExtra  optional list of extra stylesheets (https:// URLs), fonts to preload (.woff2) and scripts (app paths, e.g. assets/js/x.js),
 *               loaded here so they are ready before the first paint (no flash)
 */
// Pages carry the session's CSRF token and personal data: never serve them from a cache.
header('Cache-Control: no-store');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="<?= h(csrf_token()) ?>" />
<meta name="api-base" content="<?= h(url('api/')) ?>" />
<?php if (session_area() === 'admin') : ?><meta name="area" content="admin" /><?php endif; ?>
<meta name="login-page" content="<?= h($loginPage ?? 'login') ?>" />
<meta name="user-id" content="<?= (int) (current_user()['id'] ?? 0) ?>" />
<title><?= h($title) ?> · Setlo</title>
<link rel="icon" type="image/png" href="<?= h(url('assets/icons/icon-192.png')) ?>" />
<link rel="manifest" href="<?= h(url('manifest.json')) ?>" />
<meta name="theme-color" content="#0d9488" />
<link rel="apple-touch-icon" href="<?= h(url('assets/icons/icon-180.png')) ?>" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-title" content="Setlo" />
<link rel="preload" href="<?= h(url('assets/fonts/jakarta-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin />
<link rel="preload" href="<?= h(url('assets/fonts/jakarta-latin-ext.woff2')) ?>" as="font" type="font/woff2" crossorigin />
<link rel="stylesheet" href="<?= h(url('assets/css/app.css')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/css/app.css') ?>" />
<script src="<?= h(url('assets/js/theme.js')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/theme.js') ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/vue@3.5.13/dist/vue.global.prod.js" integrity="sha384-W/1Fp/LgAYO/oTn9Gs+PbeWuMuq1eQCnUMPCeg8POmMYchhzxctjEqtbiCIxDOON" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js" integrity="sha384-YB/DdIkloKoRpclWB8bNcYXWakt57USgtQPDzvnIDHYU0lasD5eWlXVo1S4ODukY" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js" integrity="sha384-8FWZA6BGMXhsfO+BLtrJK0We6gg5o1JyO8xQm6peWDEUs17ACA5ziE/NIAkl9z2k" crossorigin="anonymous"></script>
<script src="<?= h(url('assets/js/api.js')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/api.js') ?>"></script>
<script src="<?= h(url('assets/js/ui.js')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/ui.js') ?>"></script>
<script src="<?= h(url('assets/js/validate.js')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/validate.js') ?>"></script>
<script src="<?= h(url('assets/js/receipt.js')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/receipt.js') ?>"></script>
<script src="<?= h(url('assets/js/people.js')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/people.js') ?>"></script>
<?php if (!empty($nav) && session_area() !== 'admin'): // sidebar pages: floating badge backdrop ?>
<link rel="stylesheet" href="<?= h(url('assets/css/badge-bg.css')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/css/badge-bg.css') ?>" />
<script src="<?= h(url('assets/js/badge-field.js')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/badge-field.js') ?>"></script>
<?php endif; ?>
<?php foreach ($headExtra ?? [] as $extra): ?>
<?php if (str_ends_with($extra, '.woff2')): ?>
<link rel="preload" href="<?= h(url($extra)) ?>" as="font" type="font/woff2" crossorigin />
<?php elseif (str_starts_with($extra, 'https://')): ?>
<link href="<?= h($extra) ?>" rel="stylesheet" />
<?php else: ?>
<script src="<?= h(url($extra)) ?>?v=<?= @filemtime(__DIR__ . '/../' . $extra) ?>"></script>
<?php endif; ?>
<?php endforeach; ?>
<script>
  // Collapsed sidebar is applied before the first paint (no jump); transitions only after load (no animation on refresh).
  try { if (localStorage.getItem('setlo-sidebar') === 'collapsed') document.documentElement.classList.add('sb-collapsed'); } catch (e) { /* storage blocked */ }
  window.addEventListener('load', function () { requestAnimationFrame(function () { document.documentElement.classList.add('sb-anim'); }); });
  // Installable app + offline shell. Browsers only allow this on https:// or localhost.
  // updateViaCache 'none': always check the server for a new sw.js, so fixes reach every browser.
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () { navigator.serviceWorker.register(<?= json_encode(url('sw.js')) ?>, { updateViaCache: 'none' }).catch(function () {}); });
  }
</script>
</head>
<body class="<?= h(trim(($bodyClass ?? '') . (!empty($nav) && session_area() !== 'admin' ? ' badge-bg' : ''))) ?>">
