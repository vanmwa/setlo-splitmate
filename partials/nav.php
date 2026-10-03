<?php
// Tab bar: bottom bar on phones, left sidebar on laptops (see .nav2 in src/input.css).
// Set $nav to 'dashboard' | 'bills' | 'scan' | 'settle' (or 'groups' / 'utang' / 'stats' / 'history' / 'profile') before including.
$tabs = [
    'dashboard' => ['dashboard', 'Dashboard', '<path stroke-linecap="round" stroke-linejoin="round" d="M3 11l9-7 9 7M5 9.5V20h5v-5h4v5h5V9.5"/>'],
    'bills'     => ['my-bills', 'Bills', '<path stroke-linecap="round" stroke-linejoin="round" d="M6 3h12v18l-3-2-3 2-3-2-3 2V3zM9 8h6M9 12h6"/>'],
    'scan'      => ['scan-receipt', 'Scan', '<path stroke-linecap="round" stroke-linejoin="round" d="M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2M8 12h8"/>'],
    'settle'    => ['my-settlements', 'Settle', '<rect x="3" y="6" width="18" height="14" rx="2.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 13h2M3 10h14a2 2 0 012 2"/>'],
];
// More links, listed right under the tabs in the desktop sidebar (on phones they live in the dashboard menu).
$more = [
    'groups'  => ['groups', 'Groups', '<circle cx="9" cy="8" r="3.2"/><circle cx="17" cy="9" r="2.6"/><path stroke-linecap="round" d="M3 19c.8-3 3.2-4.6 6-4.6s5.2 1.6 6 4.6M15.5 14.6c2.6-.3 4.6 1.2 5.5 4.4"/>'],
    'utang'   => ['utang', 'Utang', '<path stroke-linecap="round" stroke-linejoin="round" d="M7 10h10M7 14h6M17 3l4 4-4 4M21 7H9a6 6 0 00-6 6v0a6 6 0 006 6h3"/>'],
    'stats'   => ['stats', 'Spending', '<path stroke-linecap="round" stroke-linejoin="round" d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>'],
    'history' => ['bill-history', 'History', '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2"/>'],
    'profile' => ['profile', 'Profile & payments', '<circle cx="12" cy="8" r="4"/><path stroke-linecap="round" d="M4 20c1.5-3.5 4.5-5 8-5s6.5 1.5 8 5"/>'],
];
$icon = fn (string $paths) => '<svg class="h-[22px] w-[22px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">' . $paths . '</svg>';
$burger = '<svg class="h-[22px] w-[22px] shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>';
$signOut = $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>');
$bell = $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>');
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
<?php foreach ($tabs as $key => [$href, $label, $paths]): ?>
  <a href="<?= $href ?>" title="<?= $label ?>"<?= $isActive($key) ? ' class="active" aria-current="page"' : '' ?>><?= $icon($paths) ?><span class="nav-label"><?= $label ?></span></a>
<?php endforeach; ?>
<?php foreach ($more as $key => [$href, $label, $paths]): ?>
  <a href="<?= $href ?>" title="<?= $label ?>" class="!hidden lg:!flex<?= $isActive($key) ? ' active' : '' ?>"><?= $icon($paths) ?><span class="nav-label"><?= $label ?></span></a>
<?php endforeach; ?>
  <!-- Phones: 5th tab opens the slide-in menu -->
  <button type="button" class="lg:!hidden" data-nav-toggle="lg" aria-label="Open menu"><?= $burger ?>Menu</button>
  <form method="post" action="logout" class="hidden lg:mt-3 lg:block lg:border-t lg:border-[#e8eeee] lg:pt-3">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>" />
    <button class="!text-rose-600" title="Sign out"><?= $signOut ?><span class="nav-label">Sign out</span></button>
  </form>
</nav>

<!-- Phones: slide-in menu with every link -->
<div class="nav-backdrop" data-nav-close></div>
<aside class="nav-drawer" aria-label="Menu">
  <div class="mb-3 flex items-center justify-between px-1">
    <a href="dashboard" class="flex items-center gap-2 px-1 text-lg font-extrabold text-ink">
      <img src="<?= h(url('assets/icons/icon-192.png')) ?>" alt="" class="h-8 w-8 rounded-lg" /> setlo
    </a>
    <button type="button" class="nav-burger" data-nav-close aria-label="Close menu">
      <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
<?php foreach ($tabs + $more as $key => [$href, $label, $paths]): ?>
  <a href="<?= $href ?>"<?= $isActive($key) ? ' class="active" aria-current="page"' : '' ?>><?= $icon($paths) ?><?= $label ?></a>
<?php endforeach; ?>
  <a href="notifications"><?= $bell ?>Notifications</a>
  <form method="post" action="logout" class="mt-auto border-t border-[#e8eeee] pt-3">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>" />
    <button class="nav-drawer-out"><?= $signOut ?>Sign out</button>
  </form>
</aside>
