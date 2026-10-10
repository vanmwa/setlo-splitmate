<?php // Closes the landing shell opened by auth-landing-top.php and adds the phone mockup (xl screens). ?>
      <p class="mt-6 text-center text-[12px] text-slate-400">
        App manager? <a href="admin-login" class="font-semibold hover:text-brand-700">Admin Panel</a> · © 2026 Setlo
      </p>
    </main>

    <!-- Right: callout + illustrative phone -->
    <aside class="relative hidden h-full min-h-[640px] items-center justify-center xl:flex" aria-hidden="true">
      <div class="absolute right-2 top-16 text-center">
        <p class="hand -rotate-[10deg] text-[34px] font-bold leading-[1.05] text-brand-600">Shared expenses,<br />made simple!</p>
        <svg class="mx-auto mt-1 h-8 w-24 -rotate-[8deg] text-brand-600" viewBox="0 0 100 30" fill="none"><path d="M5 10c25 10 60 10 88-2M70 22l10 6 6-10" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </div>

      <div class="relative mt-24">
        <span class="phone-bubble -left-16 top-20">
          <svg class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="14" rx="3"/><path stroke-linecap="round" d="M16 13h2M3 10h14a2 2 0 012 2"/></svg>
        </span>
        <span class="phone-bubble -left-20 top-[17rem]">
          <svg class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path stroke-linecap="round" d="M3 19c.8-3 3.2-5 6-5s5.2 2 6 5M15 14.5c2.4 0 4.6 1.6 5.5 4.5"/></svg>
        </span>
        <span class="phone-bubble -right-12 top-72 !h-12 !w-12 opacity-80">
          <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linejoin="round" d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path stroke-linecap="round" d="M9 8h6M9 12h6"/></svg>
        </span>

        <div class="phone-mock">
          <div class="phone-mock-screen">
            <div class="flex items-center justify-between px-6 pt-3 text-[11px] font-bold text-night"><span>9:41</span><span class="h-5 w-20 rounded-full bg-night"></span><span>▮▮</span></div>
            <div class="flex items-center justify-between px-5 pt-3">
              <span class="flex items-center gap-1.5 text-[17px] font-extrabold text-brand-700"><span class="logo-tile h-6 w-6 rounded-md text-[13px]">₱</span>setlo</span>
              <svg class="h-5 w-5 text-slate-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
            </div>
            <div class="mx-4 mt-3 rounded-2xl bg-gradient-to-br from-brand-500 to-brand-700 p-4 text-white">
              <p class="text-[11px] font-medium text-brand-50/90">You're owed</p>
              <div class="flex items-center justify-between"><p class="text-[22px] font-extrabold">₱2,350.00</p><span class="text-lg">›</span></div>
            </div>
            <p class="px-5 pt-4 text-[11px] font-bold text-slate-500">Recent Activity</p>
            <div class="mt-1 divide-y divide-slate-100 px-4">
              <div class="flex items-center gap-3 py-2.5">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-[15px]">🍗</span>
                <div class="min-w-0 flex-1"><p class="truncate text-[12px] font-bold text-ink">Mang Inasal Friday</p><p class="text-[10px] text-slate-400">Split by 4</p></div>
                <div class="text-right"><p class="text-[12px] font-bold text-ink">₱1,240.00</p><p class="text-[10px] text-slate-400">Sep 24</p></div>
              </div>
              <div class="flex items-center gap-3 py-2.5">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-[15px]">🥩</span>
                <div class="min-w-0 flex-1"><p class="truncate text-[12px] font-bold text-ink">Samgyup Payday</p><p class="text-[10px] text-slate-400">Split by 3</p></div>
                <div class="text-right"><p class="text-[12px] font-bold text-ink">₱1,637.00</p><p class="text-[10px] text-slate-400">Aug 17</p></div>
              </div>
              <div class="flex items-center gap-3 py-2.5">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-[15px]">🍝</span>
                <div class="min-w-0 flex-1"><p class="truncate text-[12px] font-bold text-ink">Jollibee Merienda</p><p class="text-[10px] text-slate-400">Split by 3</p></div>
                <div class="text-right"><p class="text-[12px] font-bold text-ink">₱555.00</p><p class="text-[10px] text-slate-400">Sep 3</p></div>
              </div>
            </div>
            <div class="mx-3 mt-2 grid grid-cols-4 rounded-2xl bg-slate-50 py-2 text-center text-[9px] font-semibold text-slate-400">
              <span class="text-brand-600">⌂<br />Home</span><span>▤<br />Bills</span><span>⌗<br />Scan</span><span>◔<br />Settle</span>
            </div>
          </div>
        </div>
      </div>
    </aside>
  </div>
</div>
