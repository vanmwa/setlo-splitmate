<?php
// Fun Mode game screen: Game Options (creator), lobby (live mode), play, and the result. The server holds the
// game (api/games.php); this page draws it and polls for changes every 1–2 s, paused while the tab is hidden.
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Fun Mode';
$nav = 'scan';
$billId = (int) ($_GET['bill'] ?? 0);
if (!$billId) {
    redirect('pages/my-bills');
}
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow" v-cloak>

  <div class="app-hero fun-hero px-5 pb-6 pt-8">
    <div class="flex items-center gap-3">
      <a :href="me.is_creator ? 'review-items?bill=' + billId : (bill ? bill.next : 'my-bills')" class="glass-btn shrink-0" aria-label="Back">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
      </a>
      <div class="min-w-0 flex-1">
        <p class="fun-chip">🎉 Fun Mode</p>
        <h1 class="mt-1 truncate text-[21px] font-extrabold tracking-tight">{{ game ? games[game.game].emoji + ' ' + games[game.game].title : (bill ? bill.name : 'Game time') }}</h1>
      </div>
      <div v-if="secondsLeft !== null" class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-full bg-white/25 ring-2 ring-white/50" :class="{ 'animate-pulse': secondsLeft <= 10 }" aria-live="polite">
        <span class="text-[17px] font-extrabold leading-none tabular-nums">{{ secondsLeft }}</span><span class="text-[9px] font-bold uppercase">sec</span>
      </div>
    </div>
    <p v-if="bill" class="mt-3 text-[12.5px] font-medium text-white/90">{{ bill.name }} · {{ members.length }} players<span v-if="game"> · {{ game.mode === 'live' ? 'everyone’s phone' : 'one phone, passed around' }}</span></p>
  </div>

  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 space-y-4 px-5 pb-8 pt-5">

    <!-- ===== Game Options (creator, before a game) ===== -->
    <template v-if="view === 'options'">
      <p class="section-label">PICK A GAME</p>
      <!-- swipe to pick: the slide in the middle is the chosen game -->
      <div ref="track" @scroll.passive="onTrackScroll" class="game-carousel -mx-5 px-5">
        <button v-for="(gm, key, i) in games" :key="key" :data-game="key" @click="selectGame(key)" class="game-slide" :class="{ on: pick.game === key }" :aria-pressed="pick.game === key">
          <img :src="gameArt + key + '.svg'" alt="" :loading="i ? 'lazy' : 'eager'" />
          <span class="block p-4">
            <span class="block text-[16px] font-extrabold text-ink">{{ gm.emoji }} {{ gm.title }}</span>
            <span class="mt-1 block text-[12.5px] leading-snug text-slate-500">{{ gm.description }}</span>
            <span v-if="key === 'race'" class="mt-1.5 block text-[11px] font-bold text-fuchsia-600">🧮 Winner: Human Calculator</span>
            <span v-if="key === 'closest'" class="mt-1.5 block text-[11px] font-bold text-fuchsia-600">🎯 Winner: Bullseye</span>
            <span v-if="key === 'roulette'" class="mt-1.5 block text-[11px] font-bold text-fuchsia-600">🌟 Picked: Main Character</span>
          </span>
        </button>
      </div>
      <div class="flex items-center justify-center gap-3">
        <button @click="stepGame(-1)" class="carousel-arrow" :disabled="gameIndex <= 0" aria-label="Previous game">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </button>
        <div class="flex items-center gap-1.5">
          <button v-for="(gm, key) in games" :key="key" @click="selectGame(key)" class="carousel-dot" :class="{ on: pick.game === key }" :aria-label="'Show ' + gm.title" :aria-current="pick.game === key"></button>
        </div>
        <button @click="stepGame(1)" class="carousel-arrow" :disabled="gameIndex >= gameKeys.length - 1" aria-label="Next game">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </button>
      </div>

      <!-- slides in when Mystery Card is picked; the card lines are dealt in one by one -->
      <transition name="deck">
        <div v-if="pick.game === 'cards'" class="tile p-3.5">
          <p class="mb-2 text-[11.5px] font-bold text-slate-500">{{ Math.max(8, members.length) }} face-down cards — all 7 below plus {{ Math.max(8, members.length) - 7 }} extra · one card each, starting from an equal split</p>
          <div class="space-y-1">
            <p v-for="(c, key, i) in cards" :key="key" class="deck-line text-[12px] text-slate-600" :style="{ '--i': i }"><span class="font-bold text-ink">{{ c.emoji }} {{ c.title }}</span> — {{ cardText(c.description) }}</p>
          </div>
        </div>
      </transition>

      <p class="section-label !mt-5">HOW DO YOU PLAY?</p>
      <div class="seg seg-light" role="radiogroup" aria-label="How to play">
        <button @click="pick.mode = 'screen'" role="radio" :aria-checked="pick.mode === 'screen'" :class="{ on: pick.mode === 'screen' }">📱 One phone</button>
        <button @click="pick.mode = 'live'" role="radio" :aria-checked="pick.mode === 'live'" :class="{ on: pick.mode === 'live' }">👥 Everyone’s phone</button>
      </div>
      <p class="text-[12px] text-slate-500">{{ pick.mode === 'screen' ? 'Pass this phone around the table. Each person takes their turn here.' : 'Everyone gets a notification and plays on their own phone. You play for guests.' }}</p>

      <p v-if="!bill.has_items" class="rounded-xl bg-amber-50 p-3 text-[12.5px] font-semibold text-amber-700">Add the receipt items first.</p>
      <p v-else-if="members.length < 2" class="rounded-xl bg-amber-50 p-3 text-[12.5px] font-semibold text-amber-700">Add at least one more member to play.</p>
      <button @click="start" class="btn-pill btn-fun" :disabled="busy || !bill.has_items || members.length < 2">Let’s play {{ games[pick.game].emoji }}</button>
      <button @click="skip" class="btn btn-ghost w-full !text-slate-500" :disabled="busy">Skip — split normally</button>
    </template>

    <!-- ===== Waiting (members, before the creator starts) ===== -->
    <div v-else-if="view === 'waiting'" class="tile p-6 text-center">
      <p class="wiggle text-[44px]">🎲</p>
      <p class="mt-2 text-[15px] font-extrabold text-ink">Waiting for a game</p>
      <p class="mt-1 text-[13px] text-slate-500">The bill’s creator is picking a game. This page updates by itself.</p>
      <a :href="bill.next" class="btn btn-ghost mt-3">See the items instead</a>
    </div>

    <!-- ===== One-phone game run by someone else ===== -->
    <div v-else-if="view === 'elsewhere'" class="tile p-6 text-center">
      <p class="text-[44px]">📱</p>
      <p class="mt-2 text-[15px] font-extrabold text-ink">{{ hostName }} is running this game on their phone</p>
      <p class="mt-1 text-[13px] text-slate-500">Take your turn when the phone comes to you. The result shows here when it’s done.</p>
    </div>

    <!-- ===== Lobby (live mode) ===== -->
    <template v-else-if="view === 'lobby'">
      <div class="tile p-5 text-center">
        <p class="wiggle text-[40px]">{{ games[game.game].emoji }}</p>
        <p class="mt-2 text-[15px] font-extrabold text-ink">{{ game.is_host ? 'Waiting for everyone to join' : 'You’re in! Waiting for ' + hostName + ' to start' }}</p>
        <p class="mt-1 text-[12.5px] text-slate-500">{{ games[game.game].description }}</p>
      </div>
      <div class="tile divide-y divide-slate-100">
        <div v-for="p in game.players" :key="p.user.id" class="flex items-center gap-3 p-3">
          <avatar :user="p.user" :size="32"></avatar>
          <span class="flex-1 text-[13.5px] font-semibold text-ink">{{ who(p.user) }}<span v-if="p.user.is_guest" class="text-[11px] text-slate-400"> · guest, {{ game.is_host ? 'you play for them' : 'host plays' }}</span></span>
          <span v-if="p.joined || p.user.is_guest" class="text-[12px] font-bold text-emerald-600">✓ Ready</span>
          <span v-else class="text-[12px] font-semibold text-slate-400">Not here yet</span>
        </div>
      </div>
      <button v-if="game.is_host" @click="post('begin')" class="btn-pill btn-fun" :disabled="busy">Start the game ({{ joinedCount }}/{{ game.players.length }} ready)</button>
    </template>

    <!-- ===== Receipt Race ===== -->
    <template v-else-if="view === 'playing' && game.game === 'race'">
      <div class="tile p-4 text-center">
        <p class="text-[15px] font-extrabold text-ink">🏁 Work out the total — fast!</p>
        <p class="mt-0.5 text-[12px] text-slate-500">Everyone gets the same items. One answer each; the fastest correct total pays nothing.</p>
      </div>

      <div v-if="actor" class="tile p-5">
        <!-- one phone: hand it over, then the clock starts on Go -->
        <div v-if="!actor.started" class="text-center">
          <avatar :user="actor.user" :size="56"></avatar>
          <p class="mt-3 text-[16px] font-extrabold text-ink">Pass the phone to {{ actor.user.first }}</p>
          <p class="mt-1 text-[12.5px] text-slate-500">The clock starts when you tap Go. You have {{ turnLimit }} seconds.</p>
          <button @click="raceGo" class="btn-pill btn-fun mt-4" :disabled="busy">I’m {{ actor.user.first }} — Go! 🏁</button>
        </div>
        <template v-else-if="game.race">
          <div class="flex items-center justify-between">
            <p class="text-[13px] font-bold text-ink">{{ actor.user.id === me.id ? 'Your race' : actor.user.first + '’s race' }}</p>
            <p class="rounded-full bg-fuchsia-50 px-2.5 py-0.5 text-[12px] font-extrabold tabular-nums text-fuchsia-700" aria-live="off">⏱ {{ elapsed(actor) }} s</p>
          </div>
          <div class="mt-3 space-y-1.5 rounded-2xl bg-slate-50 p-3.5">
            <div v-for="(l, i) in game.race.lines" :key="i" class="flex justify-between text-[14px]">
              <span class="font-semibold text-ink">{{ l.qty }} × {{ l.name }}</span><span class="tabular-nums text-slate-600">@ {{ peso(l.unit_cents / 100) }}</span>
            </div>
            <div class="border-t border-dashed border-slate-300 pt-1.5">
              <p v-for="(a, i) in game.race.adjustments" :key="i" class="text-[13.5px] font-bold" :class="a.bp < 0 ? 'text-emerald-700' : 'text-amber-700'">{{ a.label }}</p>
              <p class="mt-0.5 text-[11px] text-slate-400">Percentages are of the items’ subtotal.</p>
            </div>
          </div>
          <div class="mt-3 flex gap-2">
            <div class="relative flex-1">
              <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[16px] font-bold text-slate-400">₱</span>
              <input v-model="answerInput" @keydown.enter="sendRace" inputmode="decimal" class="input-soft !pl-8 text-[18px] font-extrabold" placeholder="0.00" aria-label="Total in pesos" autocomplete="off" />
            </div>
            <button @click="sendRace" class="btn btn-fun !rounded-2xl !px-5" :disabled="busy || !validAnswer">Lock in</button>
          </div>
          <p class="mt-1.5 text-[11.5px] text-slate-400">One answer only. Within ₱1 counts as correct.</p>
        </template>
      </div>
      <div v-else class="tile p-4 text-center text-[13px] font-semibold text-slate-500">
        <template v-if="myPlayer && myPlayer.answer !== null">You locked in {{ peso(myPlayer.answer / 100) }}. </template>Waiting for the others…
      </div>

      <div class="tile divide-y divide-slate-100">
        <div v-for="p in game.players" :key="p.user.id" class="flex items-center gap-3 p-3">
          <avatar :user="p.user" :size="30"></avatar>
          <span class="flex-1 text-[13px] font-semibold text-ink">{{ who(p.user) }}</span>
          <span v-if="p.answered" class="text-[12px] font-bold text-emerald-600">✓ Locked in</span>
          <span v-else-if="p.started" class="text-[12px] font-bold text-fuchsia-600">Racing…</span>
          <span v-else class="text-[12px] font-semibold text-slate-400">Up next</span>
        </div>
      </div>
    </template>

    <!-- ===== Closest Without Going Over ===== -->
    <template v-else-if="view === 'playing' && game.game === 'closest'">
      <div class="pop-in tile !border-fuchsia-200 !bg-fuchsia-50/60 p-5 text-center">
        <p class="text-[12.5px] font-semibold text-slate-500">Target</p>
        <p class="text-[32px] font-extrabold tracking-tight text-ink">{{ peso(game.closest.target / 100) }}</p>
        <p class="text-[12px] text-slate-500">Pick items to get as close as you can <b>without going over</b>. No calculator, no running total!</p>
      </div>

      <div v-if="actor" class="tile p-4">
        <div v-if="game.mode === 'screen' && !revealed" class="py-2 text-center">
          <avatar :user="actor.user" :size="56"></avatar>
          <p class="mt-3 text-[16px] font-extrabold text-ink">Pass the phone to {{ actor.user.first }}</p>
          <p class="mt-1 text-[12.5px] text-slate-500">Everyone else, look away 🙈</p>
          <button @click="revealed = true" class="btn-pill btn-fun mt-4">I’m {{ actor.user.first }} — let me pick</button>
        </div>
        <template v-else>
          <p class="mb-2 text-[13px] font-bold text-ink">{{ actor.user.id === me.id ? 'Your basket' : actor.user.first + '’s basket' }}</p>
          <div class="divide-y divide-slate-100">
            <div v-for="it in game.closest.items" :key="it.id" class="flex items-center gap-3 py-2">
              <div class="min-w-0 flex-1">
                <p class="truncate text-[13.5px] font-semibold text-ink">{{ it.name }}</p>
                <p class="text-[11.5px] text-slate-400">{{ peso(it.unit_cents / 100) }} each · up to {{ it.max }}</p>
              </div>
              <button @click="setQty(it, -1)" class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 text-[18px] font-bold text-slate-500 disabled:opacity-30" :disabled="!basket[it.id]" :aria-label="'One less ' + it.name">−</button>
              <span class="w-5 text-center text-[15px] font-extrabold tabular-nums">{{ basket[it.id] || 0 }}</span>
              <button @click="setQty(it, 1)" class="flex h-8 w-8 items-center justify-center rounded-full border border-fuchsia-300 text-[18px] font-bold text-fuchsia-600 disabled:opacity-30" :disabled="(basket[it.id] || 0) >= it.max" :aria-label="'One more ' + it.name">+</button>
            </div>
          </div>
          <button @click="sendBasket" class="btn-pill btn-fun mt-3" :disabled="busy || !basketCount">Lock in {{ basketCount }} item{{ basketCount === 1 ? '' : 's' }}</button>
          <p class="mt-1.5 text-center text-[11.5px] text-slate-400">One basket only. Go over the target and you’re out.</p>
        </template>
      </div>
      <div v-else class="tile p-4 text-center text-[13px] font-semibold text-slate-500">
        <template v-if="myPlayer && myPlayer.answered">Your basket is locked in. </template>Waiting for the others…
      </div>

      <div class="tile divide-y divide-slate-100">
        <div v-for="p in game.players" :key="p.user.id" class="flex items-center gap-3 p-3">
          <avatar :user="p.user" :size="30"></avatar>
          <span class="flex-1 text-[13px] font-semibold text-ink">{{ who(p.user) }}</span>
          <span v-if="p.answered" class="text-[12px] font-bold text-emerald-600">✓ Locked in</span>
          <span v-else class="text-[12px] font-semibold text-slate-400">Choosing…</span>
        </div>
      </div>
    </template>

    <!-- ===== Roulette ===== -->
    <template v-else-if="(view === 'playing' || view === 'done') && game.game === 'roulette'">
      <div class="tile p-5">
        <p class="text-center text-[13px] font-semibold text-slate-500">{{ view === 'done' ? 'The roulette has spoken' : 'Spinning… one by one, players are safe' }}</p>
        <div class="mt-5 grid grid-cols-3 gap-4 px-1 py-2 sm:grid-cols-4">
          <div v-for="(p, i) in game.players" :key="p.user.id" class="roulette-seat"
            :class="{ out: knocked.includes(p.user.id), hot: view === 'playing' && hot === p.user.id && !knocked.includes(p.user.id), payer: view === 'done' && result && result.payer === p.user.id }">
            <avatar :user="p.user" :size="46"></avatar>
            <span class="max-w-full truncate text-[12px] font-bold text-ink">{{ who(p.user) }}</span>
            <span v-if="knocked.includes(p.user.id)" class="text-[10.5px] font-bold text-emerald-600">Safe ✓</span>
          </div>
        </div>
      </div>
      <div v-if="view === 'done' && result" class="pop-in tile !border-amber-300 !bg-amber-50 p-5 text-center">
        <p class="text-[44px]">💸</p>
        <p class="text-[18px] font-extrabold text-ink">{{ result.payer === me.id ? 'Your wallet has been selected!' : nameOf(result.payer) + '’s wallet has been selected!' }}</p>
        <p class="mt-1 text-[13px] text-slate-600">{{ result.payer === me.id ? 'You pay' : nameOf(result.payer) + ' pays' }} the whole bill. 🌟 Main Character unlocked.</p>
      </div>
    </template>

    <!-- ===== Mystery Card ===== -->
    <template v-else-if="(view === 'playing' || view === 'choosing' || view === 'done') && game.game === 'cards'">
      <div class="tile p-4 text-center">
        <p v-if="view === 'playing' && actor" class="text-[15px] font-extrabold text-ink">🃏 {{ actor.user.id === me.id ? 'Pick a card' : actor.user.first + ', pick a card' }}</p>
        <p v-else-if="view === 'playing'" class="text-[14px] font-bold text-slate-500">{{ myPlayer && myPlayer.card ? 'Waiting for the others to pick…' : 'Waiting…' }}</p>
        <p v-else-if="view === 'choosing'" class="text-[15px] font-extrabold text-ink">Time to use the cards</p>
        <p v-else class="text-[15px] font-extrabold text-ink">The cards have spoken</p>
        <p v-if="view === 'playing'" class="mt-0.5 text-[12px] text-slate-500">Everyone starts at an equal {{ peso(equalShare / 100) }}. Card amount: {{ peso(game.x / 100) }}.</p>
      </div>

      <!-- face-down deck; a picked card greys out and can't be picked again -->
      <div v-if="view === 'playing'" class="grid grid-cols-3 gap-3 sm:grid-cols-4">
        <template v-for="slot in game.slots" :key="slot">
          <div v-if="slotOwner(slot - 1)" class="mcard-taken" :aria-label="'Taken by ' + who(slotOwner(slot - 1).user)">
            <span class="text-[22px]">{{ cards[slotOwner(slot - 1).card].emoji }}</span>
            <span class="max-w-full truncate text-[10.5px] font-bold">{{ who(slotOwner(slot - 1).user) }}</span>
          </div>
          <button v-else @click="pickCard(slot - 1)" class="mcard" :disabled="busy || !actor" :aria-label="'Face-down card ' + slot">
            <span class="mcard-inner"><span class="mcard-face mcard-back">🃏</span></span>
          </button>
        </template>
      </div>

      <!-- everyone's cards, face up -->
      <div v-if="hands.length" class="tile divide-y divide-slate-100">
        <div v-for="p in hands" :key="p.user.id" class="flex items-center gap-3 p-3">
          <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-fuchsia-50 text-[22px]">{{ cards[p.card].emoji }}</span>
          <div class="min-w-0 flex-1">
            <p class="text-[13px] font-bold text-ink">{{ who(p.user) }} · {{ cards[p.card].title }}</p>
            <p class="text-[11.5px] text-slate-500">{{ cardText(cards[p.card].description) }}</p>
          </div>
        </div>
      </div>

      <!-- 🔄 / 🔀 / 💥 holders pick in the pop-up as they draw; this shows who's still picking -->
      <template v-if="game.choosers">
        <p v-for="c in game.choosers.filter((x) => !myChoosers.includes(x))" :key="'w' + c.id" class="tile p-3 text-center text-[12.5px] text-slate-500">
          {{ c.chosen ? '✓ ' + nameOf(c.id) + ' used ' + cards[c.card].emoji + ' ' + cards[c.card].title + ' on someone…' : cards[c.card].emoji + ' Waiting for ' + nameOf(c.id) + ' to pick someone…' }}
        </p>
      </template>

      <!-- result: what each card did, and everyone's share before → after -->
      <div v-if="view === 'done' && result" class="pop-in tile !border-amber-300 !bg-amber-50 p-4">
        <p class="text-[13px] font-extrabold text-ink">What the cards did</p>
        <p class="text-[11.5px] text-slate-500">From an equal {{ peso(equalShare / 100) }} each</p>
        <ul class="mt-2 space-y-1">
          <li v-for="(l, i) in result.log" :key="i" class="text-[12.5px] text-slate-700">{{ cards[l.card].emoji }} {{ logText(l) }}</li>
          <li v-if="!result.log.length" class="text-[12.5px] text-slate-500">Nothing changed hands — an equal split it is.</li>
        </ul>
        <div class="mt-3 space-y-1 border-t border-amber-200 pt-2">
          <div v-for="m in members" :key="m.id" class="flex items-center gap-2 text-[12.5px]">
            <span class="flex-1 font-semibold">{{ who(m) }}</span>
            <span class="tabular-nums text-slate-400 line-through" v-if="result.base[m.id] !== result.shares[m.id]">{{ peso(result.base[m.id] / 100) }}</span>
            <span class="w-20 text-right font-extrabold tabular-nums" :class="result.shares[m.id] < result.base[m.id] ? 'text-emerald-700' : result.shares[m.id] > result.base[m.id] ? 'text-rose-600' : 'text-ink'">{{ peso(result.shares[m.id] / 100) }}</span>
          </div>
        </div>
      </div>

      <!-- the big reveal on the phone that just picked; 🔄 / 🔀 / 💥 pick their player right here -->
      <div v-if="popup" class="sheet-backdrop flex items-center justify-center" @click.self="!popup.chooser && (reveal = null)">
        <div class="pop-in mx-6 w-full max-w-[320px] rounded-[28px] bg-white p-6 text-center shadow-2xl ring-4 ring-fuchsia-300">
          <p class="text-[12px] font-extrabold uppercase tracking-[.1em] text-fuchsia-600">{{ popup.name }} drew</p>
          <p class="mt-2 text-[64px] leading-none">{{ cards[popup.card].emoji }}</p>
          <p class="mt-2 text-[20px] font-extrabold text-ink">{{ cards[popup.card].title }}</p>
          <p class="mt-1 text-[13px] text-slate-500">{{ cardText(cards[popup.card].description) }}</p>
          <template v-if="popup.chooser">
            <p class="mt-4 text-[13.5px] font-bold text-ink">{{ cardText(cards[popup.card].choose) }}</p>
            <div class="mt-2 grid grid-cols-2 gap-2 text-left">
              <button v-for="p in targetsFor(popup.chooser.id)" :key="p.user.id" @click="chooseTarget(popup.chooser, p.user.id)" class="flex items-center gap-2 rounded-2xl border-[1.5px] border-slate-200 p-2.5 hover:border-fuchsia-400" :disabled="busy">
                <avatar :user="p.user" :size="26"></avatar><span class="truncate text-[13px] font-bold">{{ who(p.user) }}</span>
              </button>
            </div>
            <p class="mt-2 text-[11px] text-slate-400">Careful: if they draw 🛡️ or 👑 later, your card is blocked.<span v-if="game.protected.length"> {{ game.protected.map(nameOf).join(', ') }} can’t be picked.</span></p>
          </template>
          <button v-else @click="reveal = null" class="btn-pill btn-fun mt-4">Nice!</button>
        </div>
      </div>
    </template>

    <!-- ===== Receipt Race: reveal ===== -->
    <template v-if="view === 'done' && game.game === 'race' && result && game.race">
      <div class="pop-in tile !border-amber-300 !bg-amber-50 p-5">
        <p class="text-center text-[12.5px] font-semibold text-slate-500">The correct total</p>
        <p class="text-center text-[30px] font-extrabold tracking-tight text-ink">{{ peso(result.answer / 100) }}</p>
        <div class="mt-2 space-y-0.5 text-[12.5px] text-slate-600">
          <div v-for="(l, i) in game.race.lines" :key="i" class="flex justify-between"><span>{{ l.qty }} × {{ l.name }}</span><span class="tabular-nums">{{ peso(l.qty * l.unit_cents / 100) }}</span></div>
          <div class="flex justify-between border-t border-amber-200 pt-0.5 font-semibold"><span>Subtotal</span><span class="tabular-nums">{{ peso(game.race.subtotal / 100) }}</span></div>
          <div v-for="(a, i) in game.race.adjustments" :key="'a' + i" class="flex justify-between"><span>{{ a.label }}</span><span class="tabular-nums">{{ a.bp < 0 ? '−' : '+' }}{{ peso(Math.abs(Math.round(game.race.subtotal * a.bp / 10000)) / 100) }}</span></div>
        </div>
        <p class="mt-3 text-center text-[13px] text-slate-600">{{ winnerLine('fastest', 'Nobody got it right') }}</p>
      </div>
      <div class="tile divide-y divide-slate-100">
        <div v-for="row in raceRanking" :key="row.p.user.id" class="flex items-center gap-3 p-3" :class="{ 'bg-emerald-50/70': result.winners.includes(row.p.user.id) }">
          <span class="w-5 text-center text-[13px] font-extrabold text-slate-400">{{ row.rank }}</span>
          <avatar :user="row.p.user" :size="30"></avatar>
          <span class="flex-1 text-[13px] font-semibold text-ink">{{ who(row.p.user) }}<span v-if="result.winners.includes(row.p.user.id)"> 🧮</span></span>
          <span class="text-right">
            <span class="block text-[13.5px] font-extrabold tabular-nums" :class="row.correct ? 'text-emerald-700' : 'text-slate-400'">{{ row.p.answer === null ? 'No answer' : peso(row.p.answer / 100) }} {{ row.correct ? '✓' : row.p.answer === null ? '' : '✗' }}</span>
            <span v-if="row.p.time_ms !== null && row.p.answer !== null" class="block text-[11px] text-slate-400">{{ (row.p.time_ms / 1000).toFixed(1) }} s</span>
          </span>
        </div>
      </div>
    </template>

    <!-- ===== Closest Without Going Over: reveal ===== -->
    <template v-if="view === 'done' && game.game === 'closest' && result">
      <div class="pop-in tile !border-amber-300 !bg-amber-50 p-5 text-center">
        <p class="text-[12.5px] font-semibold text-slate-500">The target was</p>
        <p class="text-[30px] font-extrabold tracking-tight text-ink">{{ peso(result.target / 100) }}</p>
        <p class="mt-1 text-[13px] text-slate-600">{{ winnerLine('closest', 'Everyone went over (or picked nothing)') }}</p>
      </div>
      <div class="tile divide-y divide-slate-100">
        <div v-for="row in closestRanking" :key="row.p.user.id" class="flex items-start gap-3 p-3" :class="{ 'bg-emerald-50/70': result.winners.includes(row.p.user.id) }">
          <span class="mt-1 w-5 text-center text-[13px] font-extrabold text-slate-400">{{ row.rank }}</span>
          <avatar :user="row.p.user" :size="30"></avatar>
          <div class="min-w-0 flex-1">
            <p class="text-[13px] font-semibold text-ink">{{ who(row.p.user) }}<span v-if="result.winners.includes(row.p.user.id)"> 🎯</span></p>
            <p v-if="row.p.basket" class="text-[11.5px] text-slate-400">{{ basketText(row.p.basket) }}</p>
          </div>
          <span class="text-right">
            <span class="block text-[13.5px] font-extrabold tabular-nums" :class="row.over ? 'text-rose-500' : 'text-ink'">{{ row.p.answer === null ? 'No basket' : peso(row.p.answer / 100) }}</span>
            <span v-if="row.p.answer !== null" class="block text-[11px]" :class="row.over ? 'font-bold text-rose-500' : 'text-slate-400'">{{ row.over ? 'over by ' + peso((row.p.answer - result.target) / 100) : peso((result.target - row.p.answer) / 100) + ' under' }}</span>
          </span>
        </div>
      </div>
    </template>

    <!-- ===== Result: the new shares ===== -->
    <template v-if="view === 'done'">
      <div v-if="shares && bill.split_mode === 'game'" class="tile p-4">
        <p class="section-label">WHAT EVERYONE PAYS</p>
        <div v-for="m in members" :key="m.id" class="flex items-center gap-3 py-1.5">
          <avatar :user="m" :size="26"></avatar>
          <span class="flex-1 text-[13px] font-semibold">{{ who(m) }}</span>
          <span class="text-[13.5px] font-extrabold tabular-nums" :class="shares[m.id] < 0 ? 'text-emerald-600' : shares[m.id] === 0 ? 'text-slate-400' : 'text-ink'">{{ shares[m.id] < 0 ? '+' + peso(-shares[m.id]) : peso(shares[m.id] || 0) }}</span>
        </div>
        <p v-if="result && game.game === 'roulette' && result.payer === bill.payer_id" class="mt-2 rounded-xl bg-emerald-50 p-2.5 text-[12px] font-semibold text-emerald-700">{{ nameOf(bill.payer_id) }} already paid the restaurant, so nobody owes anything!</p>
      </div>
      <a :href="bill.next" class="btn-pill btn-fun">{{ me.is_creator ? 'Continue to split' : 'See the bill' }}</a>
      <button v-if="me.is_creator && bill.status !== 'settling' && bill.status !== 'closed'" @click="replay" class="btn btn-ghost w-full !text-slate-500" :disabled="busy">Play another game</button>
    </template>

    <button v-if="game && me.is_creator && ['lobby', 'playing', 'choosing'].includes(view)" @click="cancel" class="btn btn-ghost w-full !text-slate-400" :disabled="busy">Stop this game</button>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
Setlo.mount({
  data: () => ({
    billId: <?= $billId ?>, loading: true, busy: false,
    bill: null, me: { id: <?= (int) $user['id'] ?>, is_creator: false }, members: [], games: {}, cards: {}, game: null, shares: null,
    pick: { game: 'roulette', mode: 'screen' }, gameArt: <?= json_encode(url('assets/games/')) ?>, scrollFrame: null,
    offset: 0, now: Date.now(), poller: null, ticker: null, hot: null,
    answerInput: '', basket: {}, revealed: false, replaying: false, lastNudge: 0, reveal: null,
  }),
  computed: {
    view() {
      if (!this.game || this.replaying) return this.me.is_creator ? 'options' : 'waiting';
      if (this.game.mode === 'screen' && !this.game.is_host && this.game.status !== 'done') return 'elsewhere';
      return this.game.status;
    },
    result() { return this.game && this.game.result; },
    myPlayer() { return this.game && this.game.players.find((p) => p.user.id === this.me.id); },
    hostName() { return this.game ? this.nameOf(this.game.host_id) : ''; },
    joinedCount() { return this.game.players.filter((p) => p.joined || p.user.is_guest).length; },
    secondsLeft() {
      if (!this.game || !this.game.deadline_ms || !['playing', 'choosing'].includes(this.game.status) || this.game.game === 'roulette') return null;
      return Math.max(0, Math.ceil((this.game.deadline_ms - (this.now + this.offset)) / 1000));
    },
    /** Whose turn it is on this phone: on one phone, the next player in order; live, me first, then guests I play for. */
    actor() {
      if (!this.game || this.game.status !== 'playing') return null;
      const todo = (p) => (['race', 'closest'].includes(this.game.game) ? !p.answered : this.game.game === 'cards' ? p.card_slot === null : false);
      if (this.game.mode === 'screen') return this.game.is_host ? this.game.players.find(todo) || null : null;
      if (this.myPlayer && todo(this.myPlayer)) return this.myPlayer;
      return this.game.is_host ? this.game.players.find((p) => p.user.is_guest && todo(p)) || null : null;
    },
    gameKeys() { return Object.keys(this.games); },
    gameIndex() { return this.gameKeys.indexOf(this.pick.game); },
    turnLimit() { return Math.round((this.game.turn_limit_ms || 90000) / 1000); },
    validAnswer() { const v = parseFloat(this.answerInput); return v >= 0 && this.answerInput.trim() !== '' && v <= 1000000; },
    basketCount() { return Object.values(this.basket).reduce((a, b) => a + b, 0); },
    knocked() { return (this.game && this.game.knocked) || []; },
    /** Correct answers by time, then wrong ones, then no answer. */
    raceRanking() {
      const ok = (p) => this.result.correct.includes(p.user.id);
      const rows = [...this.game.players].sort((a, b) => ok(b) - ok(a) || (ok(a) ? a.time_ms - b.time_ms : (a.answer === null) - (b.answer === null)));
      return rows.map((p, i) => ({ p, correct: ok(p), rank: ok(p) ? i + 1 : '–' }));
    },
    /** Highest basket that didn't go over first, then the ones over the target, then nobody. */
    closestRanking() {
      const t = this.result.target;
      const valid = (p) => p.answer !== null && p.answer <= t;
      const rows = [...this.game.players].sort((a, b) => valid(b) - valid(a) || (valid(a) ? b.answer - a.answer : (a.answer === null) - (b.answer === null)));
      let rank = 0, last = null;
      return rows.map((p, i) => {
        if (valid(p) && p.answer !== last) { rank = i + 1; last = p.answer; }
        return { p, over: p.answer !== null && p.answer > t, rank: valid(p) ? rank : '–' };
      });
    },
    /** Players who have drawn, in draw order on screen (bill-member order). */
    hands() { return this.game && this.game.game === 'cards' ? this.game.players.filter((p) => p.card) : []; },
    /** Choosers this phone answers for: me, or (as host) a guest — or anyone on one phone. */
    myChoosers() { return ((this.game && this.game.choosers) || []).filter((c) => !c.chosen && this.canPlayFor(c.id)); },
    /** The pop-up: a 🔄 / 🔀 / 💥 holder this phone plays for who hasn't picked yet (stays open until they do), else the last card drawn here. */
    popup() {
      const c = this.myChoosers[0];
      if (c) return { card: c.card, name: c.id === this.me.id ? 'You' : this.nameOf(c.id), chooser: c };
      return this.reveal;
    },
    equalShare() {
      if (this.result && this.result.base) return Object.values(this.result.base)[0] || 0;
      const n = this.members.length;
      return n ? Math.floor(Math.round(this.billTotalCents) / n) : 0;
    },
    billTotalCents() { return this.shares ? Math.round(Object.values(this.shares).reduce((a, b) => a + b, 0) * 100) : (this.bill && this.bill.total_cents) || 0; },
  },
  watch: {
    'actor.user.id'() { this.revealed = false; this.answerInput = ''; this.basket = {}; },
    // The carousel is (re)drawn: start it on the current pick, e.g. after "Play another game".
    view(v) { if (v === 'options') this.$nextTick(() => this.scrollToGame(this.pick.game, false)); },
  },
  async mounted() {
    await this.load();
    this.loading = false;
    if (this.view === 'options') this.$nextTick(() => this.scrollToGame(this.pick.game, false));
    this.ticker = setInterval(this.tick, 250);
    this.schedule();
    document.addEventListener('visibilitychange', this.schedule);
  },
  unmounted() {
    clearInterval(this.ticker);
    clearTimeout(this.poller);
    document.removeEventListener('visibilitychange', this.schedule);
  },
  methods: {
    apply(r) {
      if (r.unchanged) return;
      this.bill = r.bill;
      this.me = r.me;
      this.members = r.members;
      this.games = r.games;
      this.cards = r.cards;
      this.shares = r.shares;
      this.game = r.game;
      if (r.game) {
        this.offset = r.game.now_ms - Date.now();
        this.replaying = false;
        // Live: opening the page counts as joining.
        if (r.game.mode === 'live' && ['lobby', 'playing'].includes(r.game.status) && this.myPlayer && !this.myPlayer.joined && !this.busy) this.post('join');
      }
    },
    async load() {
      try {
        this.apply(await api.poll('games.php', { bill: this.billId, v: this.game ? this.game.version : 0 }));
      } catch (e) {
        if (e.status === 403 || e.status === 404) { clearTimeout(this.poller); Setlo.toast(e.message); return; }
        /* a dropped poll: the next one catches up */
      }
    },
    /** Poll fast while a game is on (faster during roulette), slowly otherwise; never while the tab is hidden. */
    schedule() {
      clearTimeout(this.poller);
      if (document.hidden) return;
      const on = this.game && ['lobby', 'playing', 'choosing'].includes(this.game.status);
      const ms = on ? (this.game.game === 'roulette' ? 800 : 1500) : 4000;
      this.poller = setTimeout(async () => { await this.load(); this.schedule(); }, ms);
    },
    tick() {
      this.now = Date.now();
      // Roulette: a highlight hops around the players still in (just for show; the server decides who's out).
      if (this.game && this.game.game === 'roulette' && this.game.status === 'playing') {
        const left = this.game.players.filter((p) => !this.knocked.includes(p.user.id));
        if (left.length) this.hot = left[Math.floor(Math.random() * left.length)].user.id;
      }
      // A deadline just passed: ask the server to move on rather than wait for the next poll.
      if (this.secondsLeft === 0 && !this.busy && this.now - this.lastNudge > 1500) { this.lastNudge = this.now; this.load(); }
    },
    async post(action, extra = {}) {
      const r = await Setlo.run(this, () => api.post('games.php', { action, bill_id: this.billId, ...extra }));
      if (r) { this.apply(r); this.schedule(); }
      return r;
    },
    who(u) { return u.id === this.me.id ? 'You' : u.first; },
    nameOf(id) { const m = this.members.find((x) => x.id === id); return m ? this.who(m) : 'Someone'; },
    canPlayFor(uid) {
      if (!this.game) return false;
      if (uid === this.me.id) return true;
      const p = this.game.players.find((x) => x.user.id === uid);
      return this.game.is_host && (this.game.mode === 'screen' || (p && p.user.is_guest));
    },
    slotOwner(slot) { return this.game.players.find((p) => p.card_slot === slot) || null; },
    cardText(t) { return String(t || '').replace('{x}', this.peso(((this.game && this.game.x) || 0) / 100)); },
    targetsFor(holder) { return this.game.players.filter((p) => p.user.id !== holder && !this.game.protected.includes(p.user.id)); },
    /** One line per card that moved money (see resolve_cards() in includes/games.php). */
    logText(l) {
      const h = this.nameOf(l.holder), t = l.target ? this.nameOf(l.target) : '', amt = this.peso(Math.abs(l.amount) / 100);
      if (l.blocked) return `${t}'s ${this.cards[l.blocked].emoji} blocked ${h}'s ${this.cards[l.card].title}!`;
      switch (l.card) {
        case 'free': return `${h} pays ₱0 — their ${amt} is shared by the others.`;
        case 'lucky': return `${amt} comes off ${h}'s share, shared by the others.`;
        case 'grab': return `${h} grabs ${amt} in total from the others.`;
        case 'double': return `${h} adds ${amt} to ${t}'s share.`;
        case 'payer': return `${t} pays ${h}'s share of ${amt}.`;
        case 'swap': return `${h} swaps shares with ${t}.`;
      }
      return '';
    },
    selectGame(key) {
      this.pick.game = key;
      this.scrollToGame(key, true);
    },
    stepGame(d) {
      const key = this.gameKeys[this.gameIndex + d];
      if (key) this.selectGame(key);
    },
    scrollToGame(key, smooth) {
      const track = this.$refs.track, slide = track && track.querySelector(`[data-game="${key}"]`);
      if (!slide) return;
      const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      track.scrollTo({ left: slide.offsetLeft - (track.clientWidth - slide.offsetWidth) / 2, behavior: smooth && !calm ? 'smooth' : 'auto' });
    },
    /** Swiping picks the game: whichever slide sits closest to the middle of the track. */
    onTrackScroll() {
      if (this.scrollFrame) return;
      this.scrollFrame = requestAnimationFrame(() => {
        this.scrollFrame = null;
        const track = this.$refs.track;
        if (!track) return;
        const mid = track.scrollLeft + track.clientWidth / 2;
        let best = null, dist = Infinity;
        for (const s of track.children) {
          const d = Math.abs(s.offsetLeft + s.offsetWidth / 2 - mid);
          if (d < dist) { dist = d; best = s.dataset.game; }
        }
        if (best) this.pick.game = best;
      });
    },
    async start() {
      const r = await this.post('start', { game: this.pick.game, mode: this.pick.mode });
      if (r && this.pick.mode === 'live') Setlo.toast('Everyone was notified — waiting for them to join.');
    },
    async skip() {
      const r = await Setlo.run(this, () => api.post('games.php', { action: 'start', bill_id: this.billId, game: 'skip' }));
      if (r) location.href = r.redirect;
    },
    forActor() { return this.actor.user.id === this.me.id ? null : this.actor.user.id; },
    /** Seconds on a player's race clock, from the server's start time. */
    elapsed(p) { return p.turn_started_ms ? Math.max(0, (this.now + this.offset - p.turn_started_ms) / 1000).toFixed(1) : '0.0'; },
    async raceGo() { await this.post('race_go', { for_user_id: this.forActor() }); },
    async sendRace() {
      if (!this.validAnswer || !this.actor) return;
      const r = await this.post('answer', { amount: parseFloat(this.answerInput), for_user_id: this.forActor() });
      if (r) this.answerInput = '';
    },
    setQty(it, d) {
      const q = Math.min(it.max, Math.max(0, (this.basket[it.id] || 0) + d));
      this.basket = { ...this.basket, [it.id]: q };
    },
    async sendBasket() {
      if (!this.actor || !this.basketCount) return;
      const r = await this.post('answer', { basket: this.basket, for_user_id: this.forActor() });
      if (r) { this.basket = {}; this.revealed = false; }
    },
    basketText(b) {
      const byId = Object.fromEntries(this.game.closest.items.map((it) => [it.id, it]));
      return Object.entries(b).map(([id, q]) => q + '× ' + ((byId[id] || {}).name || 'item')).join(', ');
    },
    /** "🧮 Ana was fastest and pays nothing!" / normal-split wording. */
    winnerLine(how, none) {
      const w = this.result.winners;
      if (!w.length) return none + ' — split it normally.';
      if (this.result.normal) return 'Everyone tied — split it normally!';
      const names = w.map(this.nameOf).join(' & ');
      return (this.game.game === 'race' ? '🧮 ' : '🎯 ') + names + (w.length > 1 ? ' were ' : ' was ') + how + ' and ' + (w.length > 1 ? 'pay' : 'pays') + ' nothing!';
    },
    async pickCard(slot) {
      if (!this.actor) return;
      const actor = this.actor;
      const r = await this.post('pick_card', { slot, for_user_id: actor.user.id === this.me.id ? null : actor.user.id });
      const mine = r && r.game && r.game.players.find((p) => p.user.id === actor.user.id);
      if (mine && mine.card && !this.cards[mine.card].choose) this.reveal = { card: mine.card, name: actor.user.id === this.me.id ? 'You' : actor.user.first };
    },
    async chooseTarget(c, uid) {
      const verb = { payer: 'pays ' + (c.id === this.me.id ? 'your' : this.nameOf(c.id) + '’s') + ' share', swap: 'swaps shares with ' + (c.id === this.me.id ? 'you' : this.nameOf(c.id)), double: 'gets +' + this.peso(this.game.x / 100) }[c.card];
      if (!(await Setlo.confirm({ title: this.nameOf(uid) + '?', text: this.nameOf(uid) + ' ' + verb + '.', confirmText: 'Yes' }))) return;
      await this.post('choose_target', { user_id: uid, for_user_id: c.id === this.me.id ? null : c.id });
      this.reveal = null;
    },
    async replay() {
      if (!(await Setlo.confirm({ title: 'Play another game?', text: 'This result is thrown away and the bill goes back to a normal split until the new game ends.', confirmText: 'Play again' }))) return;
      this.replaying = true;
    },
    async cancel() {
      if (!(await Setlo.confirm({ title: 'Stop this game?', text: 'Nobody’s result counts. You can start another one.', confirmText: 'Stop', danger: true }))) return;
      await this.post('cancel');
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
