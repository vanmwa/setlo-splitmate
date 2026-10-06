<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/paymongo.php';
$user = require_login();
$title = 'My Settlements';
$nav = 'settle';
$back = 'dashboard.php';
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <h1 class="text-[21px] font-extrabold tracking-tight">My Settlements</h1>
    </div>
    <div class="seg mt-5">
      <button :class="{ on: tab === 'owe' }" @click="tab = 'owe'">You Owe <span v-if="openCount('owe')" class="ml-1 opacity-70">{{ openCount('owe') }}</span></button>
      <button :class="{ on: tab === 'owed' }" @click="tab = 'owed'">You're Owed <span v-if="openCount('owed')" class="ml-1 opacity-70">{{ openCount('owed') }}</span></button>
    </div>
  </div>

  <!-- The grids sit inside this flex-1 block (not on it), so desktop rows never stretch to fill the page height. -->
  <div class="flex-1 space-y-5 px-5 pb-6 pt-4">
    <spinner v-if="loading"></spinner>
    <div v-else-if="!list.length && !(tab === 'owe' && cover.length)" class="tile p-6 text-center">
      <p class="text-sm font-bold text-ink">{{ tab === 'owe' ? "You don't owe anyone" : 'Nobody owes you' }}</p>
      <p class="mt-1 text-[12px] text-slate-400">Settlements appear once a bill's split is confirmed.</p>
    </div>

    <section v-for="sec in sections" :key="sec.key">
      <div v-if="sec.title" class="mb-2 flex items-baseline justify-between gap-2 px-1">
        <p class="text-[13px] font-extrabold text-ink">{{ sec.title }} <span class="font-semibold text-slate-400">· {{ sec.items.length }}</span></p>
        <p class="text-[11px] text-slate-400">{{ sec.hint }}</p>
      </div>
      <div class="space-y-3 lg:grid lg:grid-cols-2 lg:items-start lg:gap-3 lg:space-y-0">
    <div v-for="s in sec.items" :key="s.id" class="tile p-3.5" :class="{ '!border-red-200 ring-1 ring-red-100': s.status === 'disputed', 'tile-muted': s.status === 'settled' }">
      <div class="flex items-start gap-3">
        <button @click="showPerson(other(s).id)" class="shrink-0 rounded-full" :aria-label="'About ' + other(s).name"><avatar :user="other(s)" :size="36"></avatar></button>
        <div class="min-w-0 flex-1">
          <button @click="showPerson(other(s).id)" class="block max-w-full truncate text-left text-sm font-semibold hover:underline">{{ tab === 'owe' ? (s.from.is_guest ? s.from.first + ' → ' + s.to.name : 'To ' + s.to.name) : 'From ' + s.from.name }}</button>
          <a :href="billHref(s)" class="block truncate text-xs text-slate-400"><span v-if="s.bill_kind === 'loan'" class="font-bold text-violet-600">Utang · </span>{{ s.bill_name }}</a>
          <span v-if="s.from.is_guest" class="pill pill-draft mt-1">{{ tab === 'owe' ? 'Guest · you pay for them' : 'Guest' }}</span>
        </div>
        <div class="shrink-0 text-right">
          <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{{ amountLabel(s) }}</p>
          <p class="text-sm font-bold">{{ peso(s.status === 'settled' ? s.paid_amount : s.remaining) }}</p>
          <p v-if="s.status !== 'settled' && s.paid_amount > 0" class="text-[10.5px] text-slate-400">of {{ peso(s.amount) }}</p>
        </div>
      </div>

      <!-- Paid in parts so far -->
      <div v-if="s.status !== 'settled' && s.paid_amount > 0" class="mt-2.5 px-0.5">
        <div class="progress !h-1.5"><span :style="{ width: Math.min(100, (100 * s.paid_amount) / s.amount) + '%' }"></span></div>
        <p class="mt-1 text-[11px] text-slate-500">{{ peso(s.paid_amount) }} paid · {{ peso(s.remaining) }} left</p>
      </div>
      <div v-if="s.interest_rate" class="mt-2 rounded-lg bg-amber-50 px-3 py-1.5 text-[11px] leading-snug text-amber-800">
        <p><b>Installments:</b> each partial payment adds {{ s.interest_rate }}% of what's left<span v-if="s.interest_added > 0"> · {{ peso(s.interest_added) }} interest so far</span>.</p>
      </div>

      <div v-if="s.status === 'disputed'" class="mt-2.5 rounded-lg bg-red-50 px-3 py-2">
        <p class="text-xs text-red-700"><b>{{ tab === 'owe' ? 'Disputed by ' + s.to.first : 'You disputed this' }}:</b> “{{ s.dispute_reason }}”</p>
      </div>
      <div v-if="tab === 'owe' && s.open > 0 && s.status !== 'settled'" class="mt-2.5 flex items-center gap-3 rounded-lg bg-slate-50 px-3 py-2">
        <div class="min-w-0 flex-1 text-xs text-slate-600">
          <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Send to</p>
          <p class="break-all"><b class="text-slate-800">{{ s.to.payment_method }}</b>
            <span v-if="s.to.payment_account"> · {{ s.to.payment_account }}</span>
            <span v-else-if="s.to.payment_method !== 'Cash'" class="text-slate-400"> · no account details yet</span>
          </p>
        </div>
        <button v-if="s.to.pay_code" @click="showQr(s.to)" class="shrink-0 text-xs font-bold text-brand-700">Show QR</button>
      </div>
      <div v-if="tab === 'owe' && s.online_started && s.status !== 'settled'" class="mt-2 flex items-center justify-between gap-2 px-1 text-xs">
        <span class="text-slate-500">Started paying online?</span>
        <button @click="checkOnline(s)" class="font-bold text-brand-700" :disabled="busy">Check payment status</button>
      </div>

      <!-- Payment parts -->
      <div v-if="s.payments.length && s.status !== 'settled'" class="mt-2.5 space-y-1.5">
        <div v-for="p in s.payments" :key="p.id" class="rounded-lg px-3 py-2 text-xs" :class="partStyle[p.status]">
          <div class="flex items-center justify-between gap-2">
            <span class="min-w-0"><b>{{ peso(p.amount) }}</b> · {{ methodWithVia(p, methodName) }}<span v-if="p.paid_by.id !== s.from.id"> · paid by {{ p.paid_by.id === me ? 'you' : p.paid_by.first }}</span></span>
            <span class="flex shrink-0 items-center gap-2 font-semibold">{{ partLabel(s, p) }}
              <button v-if="p.status === 'confirmed'" @click="showReceipt(p.id)" class="font-bold underline">Receipt</button>
            </span>
          </div>
          <div v-if="p.payment_ref || p.has_proof || p.reject_reason" class="mt-1 flex items-center justify-between gap-2">
            <span class="min-w-0 break-all opacity-80">{{ p.reject_reason ? '“' + p.reject_reason + '”' : p.payment_ref ? 'Ref no. ' + p.payment_ref : '' }}</span>
            <button v-if="p.has_proof" @click="view('Proof of payment', proofSrc(p.id))" class="shrink-0 font-bold underline">View proof</button>
          </div>
          <div v-if="tab === 'owed' && p.status === 'awaiting'" class="mt-2 flex justify-end gap-2">
            <button @click="openReject(s, p)" class="btn btn-danger btn-sm" :disabled="busy">Reject</button>
            <button @click="confirmPart(s, p)" class="btn btn-accent btn-sm" :disabled="busy">Confirm {{ peso(p.amount) }}</button>
          </div>
        </div>
      </div>

      <p v-if="s.status === 'settled'" class="mt-2.5 rounded-lg bg-white px-3 py-2 text-[11px] text-slate-500">
        <b class="text-slate-700">Old record</b> · settled {{ fmtDate(s.confirmed_at || s.paid_at, true) }}<span v-if="s.settle_method"> · {{ methodLabel[s.settle_method] }}</span>
        <span v-if="received(s).length" class="mt-1 flex flex-wrap gap-x-3 gap-y-1">
          <button v-for="p in received(s)" :key="p.id" @click="showReceipt(p.id)" class="font-bold text-brand-700">Receipt {{ peso(p.amount) }}</button>
        </span>
      </p>

      <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3">
        <status-pill :status="s.status" :label="tab === 'owed' && s.status === 'awaiting' ? 'Awaiting Your Confirmation' : null"></status-pill>

        <!-- Sender actions -->
        <template v-if="tab === 'owe'">
          <button v-if="s.status === 'disputed'" @click="act(s, 'resend')" class="btn btn-outline btn-sm" :disabled="busy">Review &amp; Resend</button>
          <div v-else-if="s.status !== 'settled'" class="ml-auto flex flex-wrap items-center justify-end gap-2">
            <template v-if="s.status === 'awaiting'">
              <button v-if="canNudge(s)" @click="nudge(s)" class="btn btn-ghost btn-sm !px-2.5 !text-brand-700" :disabled="busy">Remind to confirm</button>
              <span v-else-if="s.my_last_nudge" class="text-[11px] font-semibold text-slate-400">Reminded {{ timeAgo(s.my_last_nudge) }}</span>
            </template>
            <button v-if="s.open > 0" @click="openPay(s, false)" class="btn btn-primary btn-sm" :disabled="busy">{{ s.paid_amount > 0 || s.status === 'awaiting' ? 'Pay more' : 'Pay' }}</button>
          </div>
          <span v-else class="ml-auto flex items-center gap-3 text-[11px] font-semibold">
            <button v-if="s.bill_closed && s.bill_kind === 'bill'" @click="archiveBill(s)" class="text-slate-500 hover:text-brand-700">Archive bill</button>
            <a :href="'settlement-audit.php?id=' + s.id" class="text-brand-600">View timestamps →</a>
          </span>
        </template>

        <!-- Receiver actions -->
        <template v-else>
          <div v-if="s.status !== 'settled'" class="ml-auto flex flex-wrap items-center justify-end gap-2">
            <template v-if="s.status === 'pending'">
              <button v-if="canNudge(s)" @click="nudge(s)" class="btn btn-ghost btn-sm !px-2.5 !text-brand-700" :disabled="busy">Remind</button>
              <span v-else-if="s.my_last_nudge" class="text-[11px] font-semibold text-slate-400">Reminded {{ timeAgo(s.my_last_nudge) }}</span>
            </template>
            <button v-if="s.open > 0" @click="openCash(s)" class="btn btn-accent btn-sm" :disabled="busy">Cash Received</button>
          </div>
          <span v-else class="ml-auto flex items-center gap-3 text-[11px] font-semibold">
            <button v-if="s.bill_closed && s.bill_kind === 'bill'" @click="archiveBill(s)" class="text-slate-500 hover:text-brand-700">Archive bill</button>
            <a :href="'settlement-audit.php?id=' + s.id" class="text-brand-600">View timestamps →</a>
          </span>
        </template>
      </div>
    </div>
      </div>
    </section>

    <!-- Other members' debts on my bills, which I can pay for them -->
    <section v-if="tab === 'owe' && cover.length">
      <div class="mb-2 flex items-baseline justify-between gap-2 px-1">
        <p class="text-[13px] font-extrabold text-ink">Pay for someone else <span class="font-semibold text-slate-400">· {{ cover.length }}</span></p>
        <p class="text-[11px] text-slate-400">Open debts on your bills</p>
      </div>
      <div class="space-y-3 lg:grid lg:grid-cols-2 lg:items-start lg:gap-3 lg:space-y-0">
        <div v-for="s in cover" :key="'c' + s.id" class="tile p-3.5">
          <div class="flex items-center gap-3">
            <button @click="showPerson(s.from.id)" class="shrink-0 rounded-full" :aria-label="'About ' + s.from.name"><avatar :user="s.from" :size="32"></avatar></button>
            <div class="min-w-0 flex-1">
              <button @click="showPerson(s.from.id)" class="block max-w-full truncate text-left text-sm font-semibold hover:underline">{{ s.from.first }} → {{ s.to.id === me ? 'you' : s.to.first }}</button>
              <a :href="billHref(s)" class="block truncate text-xs text-slate-400"><span v-if="s.bill_kind === 'loan'" class="font-bold text-violet-600">Utang · </span>{{ s.bill_name }}</a>
            </div>
            <div class="shrink-0 text-right">
              <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Left to pay</p>
              <p class="text-sm font-bold">{{ peso(s.remaining) }}</p>
            </div>
          </div>
          <div class="mt-3 flex items-center justify-end gap-3 border-t border-slate-100 pt-3">
            <button v-if="s.online_started" @click="checkOnline(s)" class="text-xs font-bold text-brand-700" :disabled="busy">Check payment status</button>
            <button v-if="s.open > 0" @click="openPay(s, true)" class="btn btn-outline btn-sm" :disabled="busy">Pay for {{ s.from.first }}</button>
          </div>
        </div>
      </div>
    </section>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>

  <div v-if="paying" class="sheet-backdrop" @click.self="paying = null">
    <form class="sheet" @submit.prevent="submitPay">
      <div class="sheet-grip"></div>
      <h2 class="text-[17px] font-extrabold text-ink">{{ covering ? 'Pay for ' + paying.from.first : 'Pay ' + paying.to.first }}</h2>
      <p class="mt-0.5 text-[12px] text-slate-500">
        {{ peso(paying.open) }} still to pay<template v-if="covering"> to {{ paying.to.id === me ? 'you' : paying.to.first }}</template><template v-if="paying.paid_amount > 0"> · {{ peso(paying.paid_amount) }} paid so far</template>
      </p>

      <!-- How much: all of it, or a part -->
      <label class="field-label mt-4" for="amt">Amount</label>
      <div class="flex items-center gap-2">
        <span class="text-slate-400">₱</span>
        <input id="amt" data-field="amount" :value="payAmount" @input="payAmount = filterMoney($event.target.value); $event.target.value = payAmount; touch('amount')" :class="{ 'is-invalid': err('amount') }" class="input-soft flex-1" inputmode="decimal" aria-label="Amount to pay" />
      </div>
      <div class="mt-2 flex gap-2">
        <button type="button" @click="setAmount(paying.open)" class="pill border border-slate-200 bg-white !px-2.5 !py-1 !text-[12px] text-slate-600">All {{ peso(paying.open) }}</button>
        <button v-if="paying.open >= 2" type="button" @click="setAmount(Math.round(paying.open * 50) / 100)" class="pill border border-slate-200 bg-white !px-2.5 !py-1 !text-[12px] text-slate-600">Half {{ peso(Math.round(paying.open * 50) / 100) }}</button>
      </div>
      <p v-if="err('amount')" class="field-error">{{ err('amount') }}</p>

      <!-- Billing preview for this amount (mirrors confirm_payment() in includes/payments.php) -->
      <div v-if="preview" class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 px-3.5 py-3 text-[13px] tabular-nums" aria-live="polite">
        <div class="flex justify-between"><span class="text-slate-500">Current balance</span><span>{{ peso(preview.balance) }}</span></div>
        <div v-if="preview.waiting > 0" class="flex justify-between"><span class="text-slate-500">Waiting for confirmation</span><span>−{{ peso(preview.waiting) }}</span></div>
        <div class="flex justify-between"><span class="text-slate-500">This payment</span><span>−{{ peso(preview.pay) }}</span></div>
        <div class="flex justify-between"><span class="text-slate-500">After payment</span><span>{{ peso(preview.after) }}</span></div>
        <div v-if="paying.interest_rate" class="flex justify-between"><span class="text-slate-500">Installment fee ({{ paying.interest_rate }}%)</span><span :class="preview.fee > 0 ? 'text-amber-700' : ''">{{ peso(preview.fee) }}</span></div>
        <div class="mt-2 flex justify-between border-t border-slate-300 pt-2 font-extrabold text-ink"><span>Next amount due</span><span>{{ peso(preview.next) }}</span></div>
      </div>

      <label v-if="covering" class="mt-4 flex items-start gap-2.5 rounded-2xl bg-slate-50 px-3.5 py-3 text-[12.5px] leading-snug text-slate-600">
        <input type="checkbox" v-model="payBack" class="mt-0.5 h-4 w-4 shrink-0 accent-brand-600" />
        <span><b class="text-ink">{{ paying.from.first }} will pay me back</b><br />{{ payBack ? 'Once ' + (paying.to.id === me ? 'you confirm' : paying.to.first + ' confirms') + ' it, ' + paying.from.first + ' owes you this amount instead.' : 'Off: it’s a treat — ' + paying.from.first + ' owes nothing back.' }}</span>
      </label>

      <!-- Digital, verified: PayMongo -->
      <div v-if="online.enabled" class="mt-4 rounded-2xl border border-brand-100 bg-brand-50/60 p-3.5">
        <div class="flex items-center gap-2">
          <p class="flex-1 text-[13px] font-extrabold text-ink">Pay online</p>
          <span v-if="online.test" class="pill pill-draft">Demo · test mode</span>
        </div>
        <p class="mt-0.5 text-[12px] text-slate-500">GCash, Maya or card through PayMongo. Counts automatically once PayMongo confirms it. No screenshot needed.</p>
        <button type="button" @click="payOnline" class="btn btn-primary mt-3 w-full" :disabled="busy || tooSmallOnline">
          {{ tooSmallOnline ? 'Online needs at least ' + peso(online.min / 100) : 'Pay ' + peso(money(payAmount)) + ' with PayMongo' }}
        </button>
      </div>

      <!-- Digital, manual: ref no. / screenshot, receiver confirms -->
      <p class="mt-5 text-[13px] font-extrabold text-ink">{{ online.enabled ? 'Already sent it another way?' : 'Mark as paid' }}</p>
      <p class="mb-3 mt-0.5 text-xs text-slate-500">{{ paying.to.id === me ? 'You' : paying.to.first }} will be asked to confirm receiving it. A reference number or screenshot helps confirm faster.</p>
      <label class="field-label" for="ref">Reference no. <span class="font-medium text-slate-400">(optional)</span></label>
      <input id="ref" data-field="ref" v-model="payRef" @input="touch('ref')" :class="{ 'is-invalid': err('ref') }" class="input-soft" maxlength="60" placeholder="e.g. 1009 234 567 890" />
      <p v-if="err('ref')" class="field-error">{{ err('ref') }}</p>
      <p class="field-label mt-4">Screenshot <span class="font-medium text-slate-400">(optional)</span></p>
      <input ref="proof" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="pickProof" aria-label="Proof screenshot" />
      <div class="flex items-center gap-3">
        <img v-if="proofPreview" :src="proofPreview" alt="Selected screenshot" class="h-16 w-16 rounded-xl object-cover" />
        <button type="button" @click="$refs.proof.click()" class="btn btn-outline btn-sm">{{ proofFile ? 'Change screenshot' : 'Attach screenshot' }}</button>
        <button v-if="proofFile" type="button" @click="clearProof" class="text-xs font-semibold text-slate-400">Remove</button>
      </div>
      <div class="mt-5 grid grid-cols-2 gap-2.5">
        <button type="button" @click="paying = null" class="btn-pill btn-pill-soft">Cancel</button>
        <button class="btn-pill btn-pill-primary" :disabled="busy">{{ busy ? 'Sending…' : 'Mark ' + peso(money(payAmount)) + ' paid' }}</button>
      </div>

      <!-- In person -->
      <p v-if="!covering" class="mt-4 rounded-xl bg-slate-50 px-3 py-2.5 text-[12px] text-slate-500"><b class="text-slate-700">Paying cash?</b> Hand it over in person — all or part. {{ paying.to.first }} taps “Cash Received” and enters the amount.</p>
    </form>
  </div>

  <div v-if="cashing" class="sheet-backdrop" @click.self="cashing = null">
    <form class="sheet" @submit.prevent="submitCash">
      <div class="sheet-grip"></div>
      <h2 class="text-[17px] font-extrabold text-ink">Cash from {{ cashing.from.first }}</h2>
      <p class="mt-0.5 text-[12px] text-slate-500">{{ peso(cashing.open) }} still to pay. Only confirm cash that's in your hands.</p>
      <label class="field-label mt-4" for="cash">Cash handed over</label>
      <div class="flex items-center gap-2">
        <span class="text-slate-400">₱</span>
        <input id="cash" data-field="amount" :value="payAmount" @input="payAmount = filterMoney($event.target.value); $event.target.value = payAmount; touch('amount')" :class="{ 'is-invalid': err('amount') }" class="input-soft flex-1" inputmode="decimal" aria-label="Cash amount received" />
      </div>
      <p v-if="err('amount')" class="field-error">{{ err('amount') }}</p>
      <p v-else-if="isPart" class="mt-2 text-[12px] text-slate-500">{{ peso(leftAfter) }} stays to pay.</p>

      <!-- More cash than owed: change given back, or kept and owed back as utang (pays their next debts to me first) -->
      <template v-if="!err('amount') && cashExtra > 0">
        <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 px-3.5 py-3 text-[13px] tabular-nums">
          <div class="flex justify-between"><span class="text-slate-500">Cash handed over</span><span>{{ peso(money(payAmount)) }}</span></div>
          <div class="flex justify-between"><span class="text-slate-500">Pays the debt</span><span>−{{ peso(cashing.open) }}</span></div>
          <div class="mt-2 flex justify-between border-t border-slate-300 pt-2 font-extrabold text-ink"><span>Extra</span><span>{{ peso(cashExtra) }}</span></div>
        </div>
        <div class="mt-3 space-y-2" role="radiogroup" aria-label="What happened to the extra cash">
          <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border px-3 py-2.5 text-[12.5px]" :class="extraMode === 'change' ? 'border-brand-400 bg-brand-50' : 'border-slate-200 bg-white'">
            <input type="radio" value="change" v-model="extraMode" class="mt-0.5 accent-brand-600" />
            <span><b class="text-ink">I gave {{ peso(cashExtra) }} change back</b><br /><span class="text-slate-500">Shown on the receipt.</span></span>
          </label>
          <label v-if="!cashing.from.is_guest" class="flex cursor-pointer items-start gap-2.5 rounded-xl border px-3 py-2.5 text-[12.5px]" :class="extraMode === 'keep' ? 'border-brand-400 bg-brand-50' : 'border-slate-200 bg-white'">
            <input type="radio" value="keep" v-model="extraMode" class="mt-0.5 accent-brand-600" />
            <span><b class="text-ink">Keep {{ peso(cashExtra) }} — no change on hand / for next time</b><br />
              <span class="text-slate-500">
                <template v-if="keepPreview.uses > 0">{{ peso(keepPreview.uses) }} pays {{ cashing.from.first }}'s other debts to you now.</template>
                <template v-if="keepPreview.stays > 0"> {{ keepPreview.uses > 0 ? 'The other' : 'You owe' }} {{ peso(keepPreview.stays) }} {{ keepPreview.uses > 0 ? 'is saved' : 'back to ' + cashing.from.first + ', saved' }} in Utang — it pays {{ cashing.from.first }}'s next debts to you, or you pay it back.</template>
              </span>
            </span>
          </label>
          <p v-else class="px-1 text-[11.5px] text-slate-400">Guests have no account to keep change for, so give it back.</p>
        </div>
      </template>
      <div class="mt-5 grid grid-cols-2 gap-2.5">
        <button type="button" @click="cashing = null" class="btn-pill btn-pill-soft">Cancel</button>
        <button class="btn-pill btn-pill-primary" :disabled="busy">Got {{ peso(money(payAmount)) }}</button>
      </div>
    </form>
  </div>

  <div v-if="viewer" class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-3 bg-ink/80 p-6" @click="viewer = null">
    <p class="text-sm font-bold text-white">{{ viewer.title }}</p>
    <img :src="viewer.src" :alt="viewer.title" class="max-h-[75vh] max-w-full rounded-2xl bg-white shadow-2xl" />
    <p class="text-xs text-white/60">Tap anywhere to close</p>
  </div>

  <div v-if="rejecting" class="sheet-backdrop" @click.self="rejecting = null">
    <form class="sheet" @submit.prevent="submitReject">
      <div class="sheet-grip"></div>
      <h2 class="mb-1 text-[17px] font-extrabold text-ink">Reject this {{ peso(rejecting.p.amount) }} payment?</h2>
      <p class="mb-4 text-xs text-slate-500">This flags the settlement as Disputed and notifies {{ rejecting.p.paid_by.first }}. Explain why.</p>
      <textarea data-field="reason" v-model="reason" @input="touch('reason')" :class="{ 'is-invalid': err('reason') }" class="input-soft h-24" maxlength="500" placeholder="e.g. I haven't received it — no GCash reference number yet." aria-label="Reason"></textarea>
      <div class="mt-1 flex justify-between text-[11px]"><span class="field-error !mt-0">{{ err('reason') }}</span><span class="text-slate-400">{{ reason.length }}/500</span></div>
      <div class="mt-4 grid grid-cols-2 gap-2.5">
        <button type="button" @click="rejecting = null" class="btn-pill btn-pill-soft">Cancel</button>
        <button class="btn-pill btn-pill-danger" :disabled="busy">Submit Dispute</button>
      </div>
    </form>
  </div>
</div>

<script>
const money = (v) => Math.round((parseFloat(String(v).replace(/,/g, '')) || 0) * 100) / 100;
Setlo.mount({
  mixins: [Setlo.validation],
  data: () => ({
    me: <?= (int) $user['id'] ?>, loading: true, busy: false, tab: new URLSearchParams(location.search).get('tab') === 'owed' ? 'owed' : 'owe',
    owe: [], owed: [], cover: [], rejecting: null, reason: '',
    paying: null, covering: false, payBack: true, payAmount: '', cashing: null, extraMode: 'change',
    payRef: '', proofFile: null, proofPreview: null, viewer: null,
    online: { enabled: <?= paymongo_available() ? 'true' : 'false' ?>, test: <?= paymongo_test_mode() ? 'true' : 'false' ?>, min: <?= PAYMONGO_MIN_CENTS ?> },
    methodLabel: { online: 'Paid online · verified by PayMongo', transfer: 'Paid by transfer · confirmed by receiver', cash: 'Paid in cash · settled by receiver', credit: 'Paid from kept change (extra cash)', mixed: 'Paid in parts, several ways' },
    methodName: { online: 'Online', transfer: 'Transfer', cash: 'Cash', credit: 'Kept change' },
    partStyle: { awaiting: 'bg-blue-50 text-blue-800', confirmed: 'bg-emerald-50 text-emerald-800', rejected: 'bg-red-50 text-red-700' },
  }),
  computed: {
    list() { return this[this.tab]; },
    // Open ones first; settled ones below under their own heading, so old records don't mix in.
    sections() {
      const open = this.list.filter((s) => s.status !== 'settled');
      const done = this.list.filter((s) => s.status === 'settled');
      return [
        { key: 'open', title: done.length ? (this.tab === 'owe' ? 'To pay' : 'To collect') : '', hint: '', items: open },
        { key: 'done', title: 'Past settlements', hint: 'Archive a closed bill to clear it from here', items: done },
      ].filter((sec) => sec.items.length);
    },
    /** The settlement in the open sheet (pay or cash). */
    current() { return this.paying || this.cashing; },
    isPart() { return !!this.current && money(this.payAmount) > 0 && money(this.payAmount) < this.current.open; },
    /**
     * The Pay sheet's billing preview, in pesos: balance, minus parts already on their way and this payment, plus the
     * installment fee on what's left (rate % of it, to the centavo, as confirm_payment() adds it once this part is confirmed).
     */
    preview() {
      const s = this.paying;
      const pay = Math.round(money(this.payAmount) * 100);
      if (!s || !(pay > 0) || pay > Math.round(s.open * 100)) return null;
      const balance = Math.round(s.remaining * 100);
      const waiting = balance - Math.round(s.open * 100);
      const after = balance - waiting - pay;
      const fee = s.interest_rate && after > 0 ? Math.round((after * s.interest_rate) / 100) : 0;
      return { balance: balance / 100, waiting: waiting / 100, pay: pay / 100, after: after / 100, fee: fee / 100, next: (after + fee) / 100 };
    },
    /**
     * Keeping the extra cash: how much of it pays this person's other open debts to me right away (offset_change() in
     * includes/payments.php), and how much stays as change I owe them in Utang.
     */
    keepPreview() {
      if (!this.cashing) return { uses: 0, stays: 0 };
      const others = this.owed.filter((s) => s.id !== this.cashing.id && s.from.id === this.cashing.from.id && s.status !== 'settled')
        .reduce((sum, s) => sum + Math.round(s.open * 100), 0);
      const extra = Math.round(this.cashExtra * 100);
      const uses = Math.min(extra, others);
      return { uses: uses / 100, stays: (extra - uses) / 100 };
    },
    /** Cash handed over beyond what's open (Cash sheet only). */
    cashExtra() { return this.cashing ? Math.max(0, Math.round((money(this.payAmount) - this.cashing.open) * 100) / 100) : 0; },
    leftAfter() { return this.current ? Math.round((this.current.open - money(this.payAmount)) * 100) / 100 : 0; },
    // PayMongo takes nothing below its minimum, even when that's all that's left.
    tooSmallOnline() { return !!this.paying && Math.round(money(this.payAmount) * 100) < this.online.min; },
  },
  async mounted() {
    await Setlo.load(this, 'settlements.php', null, (r) => { this.fill(r); });
    const params = new URLSearchParams(location.search);
    // From Bill Detail's "Pay for them".
    const coverId = Number(params.get('cover'));
    if (coverId) {
      const s = this.cover.find((x) => x.id === coverId);
      if (s) this.openPay(s, true);
      else Setlo.toast('That debt is already paid or waiting for confirmation.', 'warn');
    }
    // Back from PayMongo's checkout page.
    const back = params.get('online');
    if (!back) return;
    history.replaceState(null, '', location.pathname);
    if (back === 'cancelled') { Setlo.toast('Online payment cancelled. Nothing was charged.'); return; }
    // The URL doesn't say which settlement was paid, so check each one with an online payment started.
    for (const s of [...this.owe, ...this.cover].filter((x) => x.status !== 'settled' && x.online_started)) {
      await this.checkOnline(s, true);
    }
  },
  methods: {
    money,
    fill(r) {
      this.owe = r.owe;
      this.owed = r.owed;
      this.cover = r.others_open || []; // a cached reply from before paying for others existed has none
    },
    async load() { this.fill(await api.get('settlements.php')); },
    amountLabel(s) {
      if (s.status === 'settled') return this.tab === 'owe' ? 'You paid' : 'You received';
      return this.tab === 'owe' ? 'You owe' : 'Owes you';
    },
    partLabel(s, p) {
      if (p.status === 'confirmed') return 'Received ✓';
      if (p.status === 'rejected') return 'Rejected';
      return this.tab === 'owed' ? 'Did you get it?' : 'Waiting for ' + s.to.first;
    },
    openCount(tab) {
      return this[tab].filter((s) => (tab === 'owe' ? ['pending', 'disputed'] : ['awaiting']).includes(s.status)).length;
    },
    /** After an action (done or refused): reload, since one action can change several cards (e.g. a new pay-back debt). */
    async after(r, msg, tone = 'ok') {
      await this.load();
      if (r) Setlo.toast(msg, tone);
      return r;
    },
    async act(s, action, extra) {
      const r = await Setlo.run(this, () => api.post('settlements.php', { action, id: s.id, ...(extra || {}) }));
      const msg = { confirm: 'Payment confirmed ✓', reject: 'Dispute submitted', resend: 'Payment reopened' }[action];
      return this.after(r, msg, action === 'reject' ? 'warn' : 'ok');
    },
    showPerson(id) { Setlo.showPerson(id); },
    /** The other person on a card: who I pay, or who pays me. */
    other(s) { return this.tab === 'owe' ? s.to : s.from; },
    billHref(s) { return s.bill_kind === 'loan' ? 'utang' : 'bill-detail.php?bill=' + s.bill_id; },
    received(s) { return s.payments.filter((p) => p.status === 'confirmed'); },
    showReceipt(id) { Setlo.showReceipt(id); },
    /** Clear a closed bill's records from my lists (they stay under My Bills → Archived). */
    async archiveBill(s) {
      if (await Setlo.toggleArchive({ id: s.bill_id, archived: false })) await this.load();
    },
    confirmPart(s, p) { return this.act(s, 'confirm', { payment_id: p.id }); },
    proofSrc(paymentId) { return '<?= h(url('api/settlements.php')) ?>?payment_proof=' + paymentId; },
    view(title, src) { this.viewer = { title, src }; },
    canNudge(s) {
      if (s.from.is_guest) return false; // guests have no account to remind
      return !s.my_last_nudge || Date.now() - new Date(s.my_last_nudge.replace(' ', 'T')).getTime() > 12 * 3600 * 1000;
    },
    async nudge(s) {
      const r = await Setlo.run(this, () => api.post('settlements.php', { action: 'nudge', id: s.id }));
      if (r) {
        const now = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        s.my_last_nudge = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
        Setlo.toast('Reminder sent to ' + (this.tab === 'owe' ? s.to.first : s.from.first), 'ok');
      }
    },
    showQr(person) { Setlo.showPayQr(person); },
    setAmount(v) { this.payAmount = v.toFixed(2); this.touch('amount'); },
    openPay(s, covering) {
      this.paying = s;
      this.covering = covering;
      this.payBack = true;
      this.payAmount = s.open.toFixed(2);
      this.payRef = '';
      this.touched.ref = false;
      this.touched.amount = false;
      this.clearProof();
    },
    openCash(s) {
      this.cashing = s;
      this.extraMode = 'change';
      this.payAmount = s.open.toFixed(2);
      this.touched.amount = false;
    },
    pickProof(e) {
      const file = e.target.files[0];
      e.target.value = '';
      if (!file) return;
      if (file.size > 8 * 1024 * 1024) { Setlo.toast('Screenshot is too large (max 8 MB).'); return; }
      this.proofFile = file;
      this.proofPreview = URL.createObjectURL(file);
    },
    clearProof() { this.proofFile = null; this.proofPreview = null; },
    validators() {
      const s = this.current;
      const amt = money(this.payAmount);
      let amount = '';
      if (s) {
        // Cash may be more than what's left (change or credit); other payments can't.
        amount = V.money(this.payAmount, { required: true, label: 'Amount' })
          || (amt < Math.min(1, s.open) ? 'Enter at least ₱1.00.' : '')
          || (!this.cashing && amt > s.open ? 'At most ' + this.peso(s.open) + ' — that’s all that’s left.' : '');
      }
      return { ref: V.refNo(this.payRef), amount, reason: this.rejecting ? V.reason(this.reason, 5, 500) : '' };
    },
    /** What every pay request sends: the settlement, the amount, and who pays (when paying for someone else). */
    payFields() {
      return { id: this.paying.id, amount: money(this.payAmount), ...(this.covering ? { for_other: 1, pay_back: this.payBack ? 1 : 0 } : {}) };
    },
    async submitPay() {
      if (!this.validateAll(['amount', 'ref'])) return;
      const fd = new FormData();
      fd.append('action', 'mark_paid');
      for (const [k, v] of Object.entries(this.payFields())) fd.append(k, v);
      fd.append('payment_ref', this.payRef.trim());
      if (this.proofFile) fd.append('proof', this.proofFile);
      const r = await Setlo.run(this, () => api.post('settlements.php', fd));
      if (r) this.paying = null;
      await this.after(r, 'Marked as paid — waiting for confirmation');
    },
    async payOnline() {
      if (!this.validateAll(['amount'])) return;
      const r = await Setlo.run(this, () => api.post('settlements.php', { action: 'pay_online', ...this.payFields() }));
      if (r) location.href = r.checkout_url;
    },
    async submitCash() {
      if (!this.validateAll(['amount'])) return;
      const s = this.cashing;
      const extra = this.cashExtra;
      const r = await Setlo.run(this, () => api.post('settlements.php', { action: 'settle_cash', id: s.id, amount: money(this.payAmount), extra: this.extraMode }));
      if (r) this.cashing = null;
      const msg = !r ? '' : extra > 0
        ? 'Cash received · ' + this.peso(extra) + (this.extraMode === 'keep' ? ' kept — saved in Utang' : ' change given')
        : r.settlement.status === 'settled' ? 'Cash received · settled ✓' : 'Cash received · ' + this.peso(r.settlement.remaining) + ' left';
      await this.after(r, msg);
    },
    // quiet: skip the "not paid yet" toast, for the automatic check after returning from PayMongo.
    async checkOnline(s, quiet = false) {
      let r;
      if (quiet) {
        try { r = await api.post('settlements.php', { action: 'check_online', id: s.id }); } catch (e) { return; }
      } else {
        r = await Setlo.run(this, () => api.post('settlements.php', { action: 'check_online', id: s.id }));
      }
      if (r) {
        await this.load();
        const left = r.settlement.remaining;
        Setlo.alert('Payment received', `PayMongo confirmed the payment to ${s.to.first}. ` + (left > 0 ? `${this.peso(left)} is still to pay.` : 'This settlement is closed.'), 'success');
      }
    },
    openReject(s, p) { this.rejecting = { s, p }; this.reason = ''; this.touched.reason = false; },
    async submitReject() {
      if (!this.validateAll(['reason'])) return;
      if (await this.act(this.rejecting.s, 'reject', { payment_id: this.rejecting.p.id, reason: this.reason.trim() })) this.rejecting = null;
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
