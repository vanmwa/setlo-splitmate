<?php
// Admin panel shell (opens layout). Set $adminNav, $title, $subtitle before including; close with admin-bottom.php.
$openDisputes = (int) q("SELECT COUNT(*) FROM settlements WHERE status = 'disputed'")->fetchColumn();
$links = [
    'overview' => ['admin-dashboard', 'Overview', 'M3 12l9-9 9 9M5 10v10h14V10'],
    'users'    => ['admin-users', 'Users', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m5-6.13a4 4 0 110 8 4 4 0 010-8z'],
    'bills'    => ['admin-bills', 'Bills & Settlements', 'M6 3h12v18l-3-2-3 2-3-2-3 2V3zM9 8h6M9 12h6'],
    'disputes' => ['admin-disputes', 'Disputes', 'M12 9v3.75m0 3.75h.008M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
];
?>
<div class="flex min-h-screen bg-slate-50">
  <div class="nav-backdrop admin-backdrop" data-nav-close></div>
  <aside class="admin-aside hidden w-60 shrink-0 flex-col bg-ink text-slate-300 md:flex" aria-label="Admin menu">
    <div class="admin-brand flex items-center gap-2.5 border-b border-white/10 px-5 py-5">
      <img src="<?= h(url('assets/setlo_logo.png')) ?>" alt="" class="h-9 w-9 shrink-0 rounded-lg object-cover" />
      <div class="nav-label">
        <p class="text-sm font-bold leading-tight text-white">Setlo</p>
        <p class="text-[10px] text-slate-400">Admin Panel</p>
      </div>
    </div>
    <nav class="admin-nav flex-1 space-y-1 px-3 py-4 text-sm">
      <?php foreach ($links as $key => [$href, $label, $d]): ?>
        <a href="<?= $href ?>" title="<?= $label ?>"<?= $adminNav === $key ? ' class="active"' : '' ?>>
          <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $d ?>"/></svg>
          <span class="nav-label"><?= $label ?></span>
          <?php if ($key === 'disputes' && $openDisputes): ?>
            <span class="admin-badge ml-auto rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white"><?= $openDisputes ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="border-t border-white/10 px-3 py-4">
      <form method="post" action="logout" class="admin-nav">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>" />
        <input type="hidden" name="area" value="admin" />
        <button class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm hover:bg-white/5" title="Log Out">
          <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
          <span class="nav-label">Log Out</span>
        </button>
      </form>
    </div>
  </aside>

  <main class="min-w-0 flex-1">
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-5 py-4 md:px-8">
      <div class="flex min-w-0 items-center gap-3">
        <button type="button" class="nav-burger h-10 w-10 shrink-0" data-nav-toggle="md" aria-label="Show or hide the menu" title="Show or hide the menu">
          <svg class="h-[22px] w-[22px]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>
        <div class="min-w-0">
          <h1 class="text-lg font-bold text-ink"><?= h($title) ?></h1>
          <p class="text-xs text-slate-400"><?= h($subtitle ?? '') ?></p>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <span class="av h-9 w-9 text-xs" style="background:<?= h($admin['avatar_color']) ?>;box-shadow:none"><?= h(initials($admin['full_name'])) ?></span>
        <div class="hidden text-sm sm:block">
          <p class="font-semibold leading-tight"><?= h($admin['full_name']) ?></p>
          <p class="text-xs text-slate-400"><?= h($admin['email']) ?></p>
        </div>
      </div>
    </header>
