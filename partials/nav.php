<?php
// Navigation: bottom tab bar on phones, sectioned left sidebar on laptops (see .nav2 in src/input.css), and a
// slide-in menu on phones with the same sections. Set $nav to one of the keys below before including.
//
// Each link: key => [href, sidebar label, bottom-bar label or null, icon paths]. Links with a bottom-bar label are
// the phone tabs (in this order: Home, Bills, Scan, Settle, then Menu); the rest are sidebar/menu only.
$sections = [
    '' => [
        'dashboard' => ['dashboard', 'Dashboard', 'Home', '<path stroke-linecap="round" stroke-linejoin="round" d="M3 11l9-7 9 7M5 9.5V20h5v-5h4v5h5V9.5"/>'],
    ],
    'Split a bill' => [
        'bills'  => ['my-bills', 'My bills', 'Bills', '<path stroke-linecap="round" stroke-linejoin="round" d="M6 3h12v18l-3-2-3 2-3-2-3 2V3zM9 8h6M9 12h6"/>'],
        'scan'   => ['scan-receipt', 'Scan a receipt', 'Scan', '<path stroke-linecap="round" stroke-linejoin="round" d="M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2M8 12h8"/>'],
        'groups' => ['groups', 'Groups', null, '<circle cx="9" cy="8" r="3.2"/><circle cx="17" cy="9" r="2.6"/><path stroke-linecap="round" d="M3 19c.8-3 3.2-4.6 6-4.6s5.2 1.6 6 4.6M15.5 14.6c2.6-.3 4.6 1.2 5.5 4.4"/>'],
    ],
    'Money' => [
        'settle' => ['my-settlements', 'Settle up', 'Settle', '<rect x="3" y="6" width="18" height="14" rx="2.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 13h2M3 10h14a2 2 0 012 2"/>'],
        'utang'  => ['utang', 'Utang', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M7 10h10M7 14h6M17 3l4 4-4 4M21 7H9a6 6 0 00-6 6v0a6 6 0 006 6h3"/>'],
        // Overview and past bills (the old Bill History) on one page
        'stats'  => ['stats', 'Spending & history', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>'],
    ],
    'Account' => [
        'profile'       => ['profile', 'Profile & payments', null, '<circle cx="12" cy="8" r="4"/><path stroke-linecap="round" d="M4 20c1.5-3.5 4.5-5 8-5s6.5 1.5 8 5"/>'],
        'notifications' => ['notifications', 'Notifications', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>'],
    ],
];
$icon = fn (string $paths) => '<svg class="h-[22px] w-[22px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">' . $paths . '</svg>';
$burger = '<svg class="h-[22px] w-[22px] shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>';
$signOut = $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>');
$isActive = fn (string $key) => ($nav ?? '') === $key;
?>
<nav class="nav2">
  <!-- Laptop: logo + burger (shrinks the sidebar to icons) -->
  <div class="nav2-head">
    <a href="dashboard" class="nav2-logo" aria-label="Setlo home">
      <img src="<?= h(url('assets/icons/icon-192.png')) ?>" alt="" class="h-8 w-8 rounded-lg" /> setlo
    </a>
    <button type="button" class="nav-burger" data-nav-toggle="lg" aria-label="Show or hide the sidebar" title="Show or hide the sidebar"><?= $burger ?></button>
  </div>
<?php foreach ($sections as $heading => $links): ?>
<?php if ($heading !== ''): ?>
  <p class="nav-section"><span class="nav-label"><?= h($heading) ?></span></p>
<?php endif; ?>
<?php foreach ($links as $key => [$href, $label, $tab, $paths]): ?>
  <a href="<?= $href ?>" title="<?= h($label) ?>" class="<?= $tab ? '' : '!hidden lg:!flex' ?><?= $isActive($key) ? ' active' : '' ?>"<?= $isActive($key) ? ' aria-current="page"' : '' ?>><?= $icon($paths) ?><span class="nav-label"><span class="lg:hidden"><?= h($tab ?? $label) ?></span><span class="hidden lg:inline"><?= h($label) ?></span></span></a>
<?php endforeach; ?>
<?php endforeach; ?>
  <!-- Phones: 5th tab opens the slide-in menu -->
  <button type="button" class="lg:!hidden" data-nav-toggle="lg" aria-label="Open menu"><?= $burger ?>Menu</button>
  <form method="post" action="logout" class="hidden lg:mt-auto lg:block lg:border-t lg:border-[#e8eeee] lg:pt-3">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>" />
    <button class="!text-rose-600" title="Sign out"><?= $signOut ?><span class="nav-label">Sign out</span></button>
  </form>
</nav>

<!-- Phones: slide-in menu, same sections as the laptop sidebar -->
<div class="nav-backdrop" data-nav-close></div>
<aside class="nav-drawer" aria-label="Menu">
  <div class="mb-2 flex items-center justify-between px-1">
    <a href="dashboard" class="flex items-center gap-2 px-1 text-lg font-extrabold text-ink">
      <img src="<?= h(url('assets/icons/icon-192.png')) ?>" alt="" class="h-8 w-8 rounded-lg" /> setlo
    </a>
    <button type="button" class="nav-burger" data-nav-close aria-label="Close menu">
      <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
<?php foreach ($sections as $heading => $links): ?>
<?php if ($heading !== ''): ?>
  <p class="nav-drawer-section"><?= h($heading) ?></p>
<?php endif; ?>
<?php foreach ($links as $key => [$href, $label, $tab, $paths]): ?>
  <a href="<?= $href ?>"<?= $isActive($key) ? ' class="active" aria-current="page"' : '' ?>><?= $icon($paths) ?><?= h($label) ?></a>
<?php endforeach; ?>
<?php endforeach; ?>
  <form method="post" action="logout" class="mt-auto border-t border-[#e8eeee] pt-3">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>" />
    <button class="nav-drawer-out"><?= $signOut ?>Sign out</button>
  </form>
</aside>
