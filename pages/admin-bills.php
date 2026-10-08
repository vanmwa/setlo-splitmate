<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();
$title = 'Bills & Settlements';
$subtitle = 'Read-only monitoring across all bills';
$adminNav = 'bills';
$loginPage = 'admin-login';
require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/admin-top.php';
?>
<style>
  /* Bill detail drawer. Own classes (bd-*) so it doesn't depend on which Tailwind utilities the built CSS contains. */
  .bd-backdrop { position: fixed; inset: 0; z-index: 50; display: flex; justify-content: flex-end; background: rgb(15 23 42 / .45); animation: bd-fade .18s ease-out; }
  .bd-panel { display: flex; flex-direction: column; width: 100%; max-width: 44rem; height: 100%; background: #fff; box-shadow: -20px 0 50px -20px rgb(15 23 42 / .45); animation: bd-slide .22s cubic-bezier(.2, .8, .2, 1); }
  @keyframes bd-fade { from { opacity: 0 } to { opacity: 1 } }
  @keyframes bd-slide { from { transform: translateX(32px); opacity: .4 } to { transform: none; opacity: 1 } }
  .bd-head { padding: 1.1rem 1.5rem .9rem; border-bottom: 1px solid #e8eeee; }
  .bd-body { flex: 1; overflow-y: auto; padding: 1.1rem 1.5rem 2rem; }
  .bd-row { display: flex; align-items: center; gap: .6rem; }
  .bd-between { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
  .bd-title { font-size: 1.15rem; font-weight: 800; color: #0f172a; line-height: 1.25; }
  .bd-sub { font-size: .75rem; color: #94a3b8; margin-top: .15rem; }
  .bd-total { font-size: 1.5rem; font-weight: 800; color: #0f172a; line-height: 1; }
  .bd-iconbtn { width: 2rem; height: 2rem; border-radius: .6rem; display: inline-flex; align-items: center; justify-content: center; color: #64748b; font-size: .95rem; transition: background .15s, color .15s; }
  .bd-iconbtn:hover:not(:disabled) { background: #f1f5f5; color: #0f172a; }
  .bd-iconbtn:disabled { opacity: .35; cursor: default; }
  .bd-tabs { display: flex; gap: .25rem; padding: .25rem; border-radius: 14px; background: #f1f5f9; margin-top: .9rem; overflow-x: auto; }
  .bd-tabs button { flex: 1; white-space: nowrap; height: 2.1rem; padding: 0 .9rem; border-radius: 10px; font-size: .8rem; font-weight: 700; color: #64748b; transition: background .15s, color .15s; }
  .bd-tabs button.on { background: #fff; color: #0f766e; box-shadow: 0 1px 3px rgb(0 0 0 / .08); }
  .bd-tabs .bd-count { margin-left: .3rem; font-size: .7rem; color: #94a3b8; }
  .bd-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: .6rem; margin-bottom: 1.25rem; }
  @media (max-width: 560px) { .bd-stats { grid-template-columns: repeat(2, 1fr); } .bd-head, .bd-body { padding-left: 1rem; padding-right: 1rem; } }
  .bd-stat { border: 1px solid #e2e8f0; border-radius: .85rem; padding: .7rem .8rem; background: #fff; }
  .bd-stat b { display: block; font-size: 1.1rem; color: #0f172a; }
  .bd-stat span { font-size: .7rem; font-weight: 700; color: #94a3b8; letter-spacing: .03em; text-transform: uppercase; }
  .bd-stat.warn b { color: #b45309; }
  .bd-stat.ok b { color: #0f766e; }
  .bd-math { font-size: .85rem; }
  .bd-math > div { display: flex; justify-content: space-between; padding: .45rem 1rem; }
  .bd-math > div.bd-grand { font-weight: 800; color: #0f172a; background: #f8fafc; }
  .bd-member { display: flex; align-items: center; gap: .7rem; width: 100%; text-align: left; padding: .65rem 1rem; transition: background .15s; }
  .bd-member:hover { background: #f8fafc; }
  .bd-member.on { background: #e6f7f4; }
  .bd-bar { height: 5px; border-radius: 999px; background: #e2e8f0; overflow: hidden; margin-top: .35rem; }
  .bd-bar > span { display: block; height: 100%; border-radius: 999px; background: #14b8a6; transition: width .4s; }
  .bd-bar.warn > span { background: #f59e0b; }
  .bd-bar.bad > span { background: #f43f5e; }
  .bd-filters { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; margin-bottom: .8rem; }
  .bd-chip { display: inline-flex; align-items: center; gap: .3rem; border: 1.5px solid #e2e8f0; border-radius: 999px; padding: .25rem .7rem; font-size: .75rem; font-weight: 700; color: #64748b; background: #fff; transition: all .15s; }
  .bd-chip:hover { border-color: #5eead4; color: #0f766e; }
  .bd-chip.on { background: #0d9488; border-color: #0d9488; color: #fff; }
  .bd-item { width: 100%; text-align: left; padding: .65rem 1rem; display: block; transition: background .15s; }
  .bd-item:hover { background: #f8fafc; }
  .bd-item-more { margin-top: .5rem; padding: .55rem .7rem; border-radius: .6rem; background: #f8fafc; font-size: .75rem; color: #64748b; line-height: 1.6; }
  .bd-faces { display: inline-flex; align-items: center; }
  .bd-faces > * { margin-left: -6px; box-shadow: 0 0 0 2px #fff; border-radius: 999px; }
  .bd-faces > *:first-child { margin-left: 0; }
  .bd-group { font-size: .7rem; font-weight: 800; letter-spacing: .06em; color: #64748b; padding: .5rem 1rem; background: #f1f5f9; }
  .bd-settle { width: 100%; text-align: left; padding: .8rem 1rem; display: block; }
  .bd-settle:hover { background: #f8fafc; }
  .bd-part { display: flex; justify-content: space-between; gap: .75rem; padding: .5rem 0; border-top: 1px dashed #e2e8f0; font-size: .8rem; }
  .bd-note { margin: .6rem 0 0; padding: .55rem .75rem; border-radius: .6rem; background: #fef2f2; color: #b91c1c; font-size: .8rem; }
  .bd-btn { display: inline-flex; align-items: center; justify-content: center; height: 2.1rem; padding: 0 .9rem; border-radius: 999px; border: 1.5px solid #99e6da; background: #fff; color: #0f766e; font-size: .78rem; font-weight: 700; transition: background .15s; }
  .bd-btn:hover { background: #f0fdfa; }
  .bd-timeline { position: relative; margin-left: .45rem; padding-left: 1.2rem; border-left: 2px solid #e2e8f0; }
  .bd-event { position: relative; padding-bottom: 1rem; font-size: .85rem; }
  .bd-event::before { content: ''; position: absolute; left: calc(-1.2rem - 6px); top: .3rem; width: 10px; height: 10px; border-radius: 999px; background: #14b8a6; box-shadow: 0 0 0 3px #fff; }
  .bd-event.disputed::before { background: #f43f5e; }
  .bd-event.confirmed::before { background: #16a34a; }
  .bd-skel { border-radius: .6rem; background: linear-gradient(90deg, #f1f5f9 25%, #e8eef3 37%, #f1f5f9 63%); background-size: 400% 100%; animation: bd-shimmer 1.2s ease infinite; }
  @keyframes bd-shimmer { 0% { background-position: 100% 0 } 100% { background-position: 0 0 } }
  .bd-muted { color: #94a3b8; font-size: .8rem; }
  tr.bd-active { background: #f0fdfa; box-shadow: inset 3px 0 0 #14b8a6; }
  th.bd-sort { cursor: pointer; user-select: none; }
  th.bd-sort:hover { color: #0f766e; }
  .bd-kbd { font-size: .65rem; border: 1px solid #cbd5e1; border-bottom-width: 2px; border-radius: .3rem; padding: 0 .3rem; color: #64748b; }
  @media (prefers-reduced-motion: reduce) { .bd-backdrop, .bd-panel, .bd-skel { animation: none; } }
</style>

<div id="app" class="space-y-4 p-5 md:p-8" v-cloak>
  <div class="flex flex-wrap items-center gap-3">
    <div class="seg seg-light max-w-md flex-1">
      <button v-for="f in filters" :key="f.key" :class="{ on: status === f.key }" @click="status = f.key; load()">{{ f.label }}</button>
    </div>
    <input v-model.trim="q" class="input !w-64" placeholder="Search bill or creator" aria-label="Search bills" />
    <span class="bd-muted">{{ shown.length }} bill{{ shown.length === 1 ? '' : 's' }}</span>
  </div>

  <div class="card overflow-x-auto">
    <spinner v-if="loading"></spinner>
    <table v-else class="w-full min-w-[760px] text-sm">
      <thead class="bg-slate-50"><tr>
        <th class="th bd-sort" @click="sortBy('name')">Bill{{ arrow('name') }}</th>
        <th class="th bd-sort" @click="sortBy('creator')">Creator{{ arrow('creator') }}</th>
        <th class="th">Members</th>
        <th class="th bd-sort" @click="sortBy('total')">Total{{ arrow('total') }}</th>
        <th class="th bd-sort" @click="sortBy('created_at')">Created{{ arrow('created_at') }}</th>
        <th class="th">Progress</th><th class="th">Status</th><th class="th"></th>
      </tr></thead>
      <tbody class="divide-y divide-slate-100">
        <tr v-for="b in shown" :key="b.id" class="cursor-pointer hover:bg-slate-50" :class="{ 'bd-active': curId === b.id }" @click="open(b.id)">
          <td class="td font-medium">{{ b.name }}</td>
          <td class="td text-slate-500">{{ b.creator }}</td>
          <td class="td text-slate-500">{{ b.members }}</td>
          <td class="td font-medium">{{ peso(b.total) }}</td>
          <td class="td text-slate-500">{{ fmtDate(b.created_at, true) }}</td>
          <td class="td"><div class="progress w-24"><span :style="{ width: b.progress + '%' }"></span></div></td>
          <td class="td">
            <div class="flex flex-wrap gap-1">
              <status-pill :status="b.status"></status-pill>
              <status-pill v-if="b.disputed" status="disputed" :label="b.disputed + ' disputed'"></status-pill>
              <status-pill v-if="b.awaiting" status="awaiting" :label="b.awaiting + ' awaiting'"></status-pill>
            </div>
          </td>
          <td class="td text-right text-xs font-semibold text-brand-600">View →</td>
        </tr>
        <tr v-if="!shown.length"><td colspan="8" class="td py-8 text-center text-slate-400">{{ q ? 'No bills match “' + q + '”.' : 'No bills.' }}</td></tr>
      </tbody>
    </table>
  </div>

  <!-- Bill detail drawer -->
  <div v-if="curId" class="bd-backdrop" @click.self="close">
    <div class="bd-panel" role="dialog" aria-modal="true" :aria-label="head ? head.name : 'Bill details'">
      <div class="bd-head">
        <div class="bd-between">
          <div style="min-width:0">
            <h2 class="bd-title">{{ head ? head.name : '…' }}</h2>
            <p v-if="head" class="bd-sub">Created by {{ head.creator }} · {{ fmtDate(head.created_at, true) }}<span v-if="detail && detail.bill.kind === 'loan'"> · Utang</span></p>
          </div>
          <div class="bd-row" style="flex-shrink:0">
            <button class="bd-iconbtn" :disabled="idx <= 0" @click="step(-1)" title="Previous bill (←)" aria-label="Previous bill">‹</button>
            <button class="bd-iconbtn" :disabled="idx < 0 || idx >= shown.length - 1" @click="step(1)" title="Next bill (→)" aria-label="Next bill">›</button>
            <button class="bd-iconbtn" @click="copyLink" title="Copy link to this bill" aria-label="Copy link">🔗</button>
            <button class="bd-iconbtn" @click="close" title="Close (Esc)" aria-label="Close">✕</button>
          </div>
        </div>
        <div v-if="head" class="bd-row" style="margin-top:.8rem; align-items:flex-end; justify-content:space-between">
          <div>
            <div class="bd-total">{{ peso(head.total) }}</div>
            <div class="flex flex-wrap gap-1" style="margin-top:.45rem">
              <status-pill :status="head.status"></status-pill>
              <status-pill v-if="listRow && listRow.disputed" status="disputed" :label="listRow.disputed + ' disputed'"></status-pill>
              <status-pill v-if="listRow && listRow.awaiting" status="awaiting" :label="listRow.awaiting + ' awaiting'"></status-pill>
            </div>
          </div>
          <div style="width:9rem; text-align:right">
            <div class="bd-muted" style="margin-bottom:.3rem">{{ progressLabel }}</div>
            <div class="progress"><span :style="{ width: head.progress + '%' }"></span></div>
          </div>
        </div>
        <div class="bd-tabs" role="tablist">
          <button v-for="t in tabs" :key="t.key" role="tab" :class="{ on: tab === t.key }" @click="tab = t.key">{{ t.label }}<span v-if="detail && t.count !== undefined" class="bd-count">{{ t.count }}</span></button>
        </div>
      </div>

      <div class="bd-body">
        <!-- loading skeleton -->
        <div v-if="!detail" aria-busy="true">
          <div class="bd-stats"><div v-for="n in 4" :key="n" class="bd-skel" style="height:3.6rem"></div></div>
          <div v-for="n in 5" :key="n" class="bd-skel" style="height:2.6rem; margin-bottom:.6rem"></div>
        </div>

        <!-- OVERVIEW -->
        <template v-else-if="tab === 'overview'">
          <div class="bd-stats">
            <div class="bd-stat"><b>{{ detail.members.length }}</b><span>Members</span></div>
            <div class="bd-stat"><b>{{ assignedCount }}/{{ detail.items.length }}</b><span>Items assigned</span></div>
            <div class="bd-stat ok"><b>{{ peso(paidTotal) }}</b><span>Paid so far</span></div>
            <div class="bd-stat" :class="{ warn: owedTotal > 0 }"><b>{{ peso(owedTotal) }}</b><span>Still owed</span></div>
          </div>

          <p class="section-label">BILL MATH</p>
          <div class="card bd-math" style="margin-bottom:1.25rem">
            <div><span>Items subtotal</span><span>{{ peso(detail.totals.subtotal) }}</span></div>
            <div v-if="detail.totals.tax"><span>Tax</span><span>{{ peso(detail.totals.tax) }}</span></div>
            <div v-else-if="detail.totals.tax_included"><span>Tax</span><span class="bd-muted">included in prices</span></div>
            <div v-if="detail.totals.service"><span>Service charge</span><span>{{ peso(detail.totals.service) }}</span></div>
            <div v-if="detail.totals.discount"><span>Discount</span><span>−{{ peso(detail.totals.discount) }}</span></div>
            <div class="bd-grand"><span>Total</span><span>{{ peso(detail.totals.total) }}</span></div>
          </div>
          <p v-if="detail.totals.unassigned" class="bd-note" style="background:#fffbeb; color:#92400e; margin-bottom:1.25rem">
            {{ detail.totals.unassigned }} item{{ detail.totals.unassigned === 1 ? ' isn’t' : 's aren’t' }} assigned to anyone yet, so those amounts aren’t in anyone’s share.
          </p>

          <p class="section-label">WHO OWES WHAT <span class="bd-muted" style="font-weight:500; letter-spacing:0">· tap a person to see what they ordered</span></p>
          <div class="card divide-y divide-slate-100">
            <button v-for="m in detail.members" :key="m.id" class="bd-member" :class="{ on: memberFilter === m.id }" @click="showMemberItems(m.id)">
              <avatar :user="m" :size="30"></avatar>
              <span style="flex:1; min-width:0">
                <span class="bd-between" style="align-items:center">
                  <span style="font-size:.88rem; font-weight:600; color:#0f172a">{{ m.name }}
                    <span v-if="m.discount_type && m.discount_type !== 'none'" class="pill pill-settling" style="margin-left:.3rem">{{ m.discount_type.toUpperCase() }}</span>
                    <span v-if="m.id === detail.bill.payer_id" class="pill pill-active" style="margin-left:.3rem">Paid the bill</span>
                  </span>
                  <b style="font-size:.88rem">{{ peso(detail.shares[m.id] || 0) }}</b>
                </span>
                <span class="bd-bar"><span :style="{ width: sharePct(m.id) + '%' }"></span></span>
              </span>
            </button>
          </div>
        </template>

        <!-- ITEMS -->
        <template v-else-if="tab === 'items'">
          <input v-model.trim="itemQ" class="input" style="margin-bottom:.7rem" placeholder="Search items" aria-label="Search items" />
          <div class="bd-filters">
            <button v-for="f in itemFilters" :key="f.key" class="bd-chip" :class="{ on: itemFilter === f.key }" @click="itemFilter = f.key">{{ f.label }} <span style="opacity:.7">{{ f.n }}</span></button>
            <button v-if="memberFilter" class="bd-chip on" @click="memberFilter = null">
              Ordered by {{ memberName(memberFilter) }} ✕
            </button>
          </div>
          <div class="card divide-y divide-slate-100">
            <template v-for="g in itemGroups" :key="g.key">
              <div v-if="itemGroups.length > 1" class="bd-group">{{ g.label }}</div>
              <button v-for="it in g.items" :key="it.id" class="bd-item" @click="openItems[it.id] = !openItems[it.id]">
                <span class="bd-between" style="align-items:center">
                  <span style="min-width:0; font-size:.88rem">
                    {{ it.name }} <span class="bd-muted">×{{ it.qty }}</span>
                    <span v-if="it.was_corrected" class="pill pill-settling" style="margin-left:.3rem">OCR corrected</span>
                    <span v-if="it.needs_review" class="pill pill-unassigned" style="margin-left:.3rem">Needs review</span>
                    <span v-if="it.dup_note" class="pill pill-awaiting" style="margin-left:.3rem">Possible duplicate</span>
                    <span v-if="!it.who.length" class="pill pill-unassigned" style="margin-left:.3rem">Unassigned</span>
                  </span>
                  <span class="bd-row" style="flex-shrink:0">
                    <span class="bd-faces"><avatar v-for="m in faces(it)" :key="m.id" :user="m" :size="20"></avatar></span>
                    <span v-if="it.who.length > 4" class="bd-muted">+{{ it.who.length - 4 }}</span>
                    <b style="font-size:.88rem">{{ peso(it.line_total) }}</b>
                  </span>
                </span>
                <span v-if="openItems[it.id]" class="bd-item-more" style="display:block">
                  {{ it.qty }} × {{ peso(it.unit_price) }}<span v-if="it.promo"> · promo −{{ peso(Math.abs(it.promo)) }}</span><br />
                  <span v-if="it.printed_name && it.printed_name !== it.name">Printed on receipt: <b>{{ it.printed_name }}</b><br /></span>
                  <span v-if="it.ocr_name && it.ocr_name !== it.name">Scanned as: <b>{{ it.ocr_name }}</b><br /></span>
                  <span v-if="it.details">{{ it.details }}<br /></span>
                  <span v-if="it.dup_note">{{ it.dup_note }}<br /></span>
                  Ordered by: <b>{{ it.who.length ? it.who.map(memberName).join(', ') : 'nobody yet' }}</b>
                  <span v-if="it.who.length > 1"> · {{ peso(it.line_total / it.who.length) }} each</span>
                </span>
              </button>
            </template>
            <p v-if="!itemGroups.length" class="px-4 py-6 text-center bd-muted">No items match.</p>
          </div>
        </template>

        <!-- PAYMENTS -->
        <template v-else-if="tab === 'payments'">
          <div class="bd-filters">
            <button v-for="f in payFilters" :key="f.key" class="bd-chip" :class="{ on: payFilter === f.key }" @click="payFilter = f.key">{{ f.label }} <span style="opacity:.7">{{ f.n }}</span></button>
          </div>
          <div class="card divide-y divide-slate-100">
            <div v-for="s in paymentList" :key="s.id">
              <button class="bd-settle" @click="openSettle[s.id] = !openSettle[s.id]">
                <span class="bd-between" style="align-items:center">
                  <span class="bd-row" style="min-width:0">
                    <avatar :user="s.from" :size="26"></avatar>
                    <span style="font-size:.88rem; min-width:0">{{ s.from.name }} <span class="bd-muted">→</span> {{ s.to.name }}</span>
                  </span>
                  <span class="bd-row" style="flex-shrink:0"><b style="font-size:.9rem">{{ peso(s.amount) }}</b><status-pill :status="s.status"></status-pill></span>
                </span>
                <span class="bd-bar" :class="{ bad: s.status === 'disputed' }"><span :style="{ width: paidPct(s) + '%' }"></span></span>
                <span class="bd-muted" style="display:block; margin-top:.3rem">{{ peso(s.paid_amount) }} paid · {{ peso(s.remaining) }} left<span v-if="s.interest_added"> · incl. {{ peso(s.interest_added) }} interest</span></span>
              </button>
              <div v-if="openSettle[s.id]" style="padding:0 1rem 1rem">
                <p v-if="s.status === 'disputed'" class="bd-note"><b>Disputed:</b> {{ s.dispute_reason || 'No reason given.' }}</p>
                <div v-for="p in s.payments" :key="p.id" class="bd-part" style="margin-top:.4rem">
                  <span>
                    <b>{{ peso(p.amount) }}</b> by {{ p.paid_by.name }}
                    <span class="bd-muted">· {{ methodLabel(p) }}<span v-if="p.payment_ref"> · ref {{ p.payment_ref }}</span><span v-if="p.has_proof"> · proof attached</span></span>
                    <span v-if="p.status === 'rejected' && p.reject_reason" class="bd-muted" style="display:block">Rejected: {{ p.reject_reason }}</span>
                  </span>
                  <span style="text-align:right; flex-shrink:0">
                    <span class="pill" :class="p.status === 'confirmed' ? 'pill-settled' : p.status === 'rejected' ? 'pill-disputed' : 'pill-awaiting'">{{ payLabels[p.status] || p.status }}</span>
                    <span class="bd-muted" style="display:block">{{ fmtDateTime(p.confirmed_at || p.created_at) }}</span>
                  </span>
                </div>
                <p v-if="!s.payments.length" class="bd-muted" style="margin-top:.5rem">No payments recorded yet.</p>
                <div v-if="s.status === 'disputed'" style="margin-top:.7rem">
                  <button class="bd-btn" @click="message(s)">Message both parties</button>
                </div>
              </div>
            </div>
            <p v-if="!paymentList.length" class="px-4 py-6 text-center bd-muted">{{ detail.settlements.length ? 'No payments with this status.' : 'Settlements aren’t generated yet.' }}</p>
          </div>
        </template>

        <!-- ACTIVITY -->
        <template v-else>
          <div v-if="detail.events.length" class="bd-timeline">
            <div v-for="e in detail.events" :key="e.id" class="bd-event" :class="e.event">
              <div style="font-weight:600; color:#0f172a">{{ eventLabels[e.event] || e.event }}<span v-if="e.actor" class="bd-muted"> · {{ e.actor }}</span></div>
              <div v-if="e.note" style="color:#475569">{{ e.note }}</div>
              <div class="bd-muted">{{ settleName(e.settlement_id) }} · {{ fmtDateTime(e.created_at) }}</div>
            </div>
          </div>
          <p v-else class="px-1 py-6 text-center bd-muted">No payment activity yet.</p>
        </template>
      </div>
    </div>
  </div>
</div>

<script>
Setlo.mount({
  data: () => ({
    loading: true, bills: [], status: '', q: '', sort: { key: 'created_at', dir: -1 },
    curId: null, detail: null, tab: 'overview', token: 0,
    itemQ: '', itemFilter: 'all', memberFilter: null, openItems: {}, openSettle: {}, payFilter: 'all',
    filters: [{ key: '', label: 'All' }, { key: 'active', label: 'Active' }, { key: 'settling', label: 'Settling' }, { key: 'closed', label: 'Closed' }],
    payLabels: { started: 'Started', awaiting: 'Awaiting', confirmed: 'Confirmed', rejected: 'Rejected' },
    eventLabels: { created: 'Settlement created', marked_paid: 'Marked as paid', confirmed: 'Payment confirmed', disputed: 'Payment disputed', resent: 'Sent back for payment', nudged: 'Reminder sent', interest: 'Interest added', covered: 'Paid by someone else' },
  }),
  computed: {
    shown() {
      const k = this.sort.key, d = this.sort.dir, needle = this.q.toLowerCase();
      const rows = this.bills.filter((b) => !needle || b.name.toLowerCase().includes(needle) || (b.creator || '').toLowerCase().includes(needle));
      return rows.slice().sort((a, b) => {
        const x = a[k], y = b[k];
        return (typeof x === 'string' ? x.localeCompare(y) : (x > y) - (x < y)) * d;
      });
    },
    idx() { return this.shown.findIndex((b) => b.id === this.curId); },
    listRow() { return this.bills.find((b) => b.id === this.curId) || null; },
    head() { return (this.detail && this.detail.bill) || this.listRow; },
    tabs() {
      const d = this.detail;
      return [
        { key: 'overview', label: 'Overview' },
        { key: 'items', label: 'Items', count: d ? d.items.length : undefined },
        { key: 'payments', label: 'Payments', count: d ? d.settlements.length : undefined },
        { key: 'activity', label: 'Activity', count: d ? d.events.length : undefined },
      ];
    },
    memberById() { return Object.fromEntries(((this.detail && this.detail.members) || []).map((m) => [m.id, m])); },
    assignedCount() { return this.detail.items.filter((i) => i.who.length).length; },
    paidTotal() { return this.detail.settlements.reduce((n, s) => n + (s.paid_amount || 0), 0); },
    owedTotal() { return this.detail.settlements.reduce((n, s) => n + (s.remaining || 0), 0); },
    progressLabel() {
      const d = this.detail;
      if (!d) return '';
      if (d.bill.status === 'active' || d.bill.status === 'draft') return this.assignedCount + ' of ' + d.items.length + ' items assigned';
      const done = d.settlements.filter((s) => s.status === 'settled').length;
      return done + ' of ' + d.settlements.length + ' settled';
    },
    itemFilters() {
      const it = this.detail.items;
      return [
        { key: 'all', label: 'All', n: it.length },
        { key: 'unassigned', label: 'Unassigned', n: it.filter((i) => !i.who.length).length },
        { key: 'review', label: 'Needs review', n: it.filter((i) => i.needs_review || i.dup_note).length },
        { key: 'corrected', label: 'OCR corrected', n: it.filter((i) => i.was_corrected).length },
      ];
    },
    itemGroups() {
      const needle = this.itemQ.toLowerCase();
      const f = this.itemFilter;
      const rows = this.detail.items.filter((i) =>
        (!needle || (i.name + ' ' + (i.printed_name || '')).toLowerCase().includes(needle))
        && (f === 'all' || (f === 'unassigned' && !i.who.length) || (f === 'review' && (i.needs_review || i.dup_note)) || (f === 'corrected' && i.was_corrected))
        && (!this.memberFilter || i.who.includes(this.memberFilter)));
      if (!rows.length) return [];
      const rc = this.detail.receipts;
      if (rc.length < 2) return [{ key: 'all', label: '', items: rows }];
      const groups = rc.map((r, n) => ({ key: r.id, label: r.title || r.store || 'Receipt ' + (n + 1), items: rows.filter((i) => i.receipt_id === r.id) }));
      const loose = rows.filter((i) => !rc.some((r) => r.id === i.receipt_id));
      if (loose.length) groups.push({ key: 'other', label: 'Added by hand', items: loose });
      return groups.filter((g) => g.items.length);
    },
    payFilters() {
      const s = this.detail.settlements;
      const n = (st) => s.filter((x) => x.status === st).length;
      return [{ key: 'all', label: 'All', n: s.length }, { key: 'pending', label: 'Pending', n: n('pending') }, { key: 'awaiting', label: 'Awaiting', n: n('awaiting') }, { key: 'disputed', label: 'Disputed', n: n('disputed') }, { key: 'settled', label: 'Settled', n: n('settled') }];
    },
    paymentList() { return this.detail.settlements.filter((s) => this.payFilter === 'all' || s.status === this.payFilter); },
  },
  async mounted() {
    await this.load();
    const id = Number(new URLSearchParams(location.search).get('bill'));
    if (id) this.open(id);
    this._key = (e) => this.onKey(e);
    document.addEventListener('keydown', this._key);
  },
  beforeUnmount() { document.removeEventListener('keydown', this._key); },
  methods: {
    async load() { await Setlo.load(this, 'admin.php', { view: 'bills', status: this.status }, (r) => { this.bills = r.bills; }); },
    sortBy(key) { this.sort = { key, dir: this.sort.key === key ? -this.sort.dir : (key === 'name' || key === 'creator' ? 1 : -1) }; },
    arrow(key) { return this.sort.key === key ? (this.sort.dir === 1 ? ' ↑' : ' ↓') : ''; },
    async open(id) {
      const t = ++this.token;
      this.curId = id;
      this.detail = null;
      this.tab = 'overview';
      this.itemQ = ''; this.itemFilter = 'all'; this.memberFilter = null; this.payFilter = 'all'; this.openItems = {}; this.openSettle = {};
      this.syncUrl(id);
      try {
        const r = await api.get('admin.php', { view: 'bill', id });
        if (t === this.token) {
          this.detail = r;
          // Open the first disputed settlement's card straight away: that's what the manager came to look at.
          const d = r.settlements.find((s) => s.status === 'disputed');
          if (d) this.openSettle[d.id] = true;
        }
      } catch (e) {
        if (t === this.token) { this.close(); Setlo.toast(e.message); }
      }
    },
    close() { this.token++; this.curId = null; this.detail = null; this.syncUrl(null); },
    step(dir) {
      const next = this.shown[this.idx + dir];
      if (next) this.open(next.id);
    },
    syncUrl(id) {
      const u = new URL(location.href);
      id ? u.searchParams.set('bill', id) : u.searchParams.delete('bill');
      history.replaceState(null, '', u);
    },
    onKey(e) {
      if (!this.curId || (e.target.closest && e.target.closest('input, textarea')) || e.metaKey || e.ctrlKey || e.altKey) return;
      if (e.key === 'Escape') this.close();
      else if (e.key === 'ArrowLeft' || e.key === 'k') this.step(-1);
      else if (e.key === 'ArrowRight' || e.key === 'j') this.step(1);
      else if (/^[1-4]$/.test(e.key)) this.tab = this.tabs[Number(e.key) - 1].key;
    },
    async copyLink() {
      try { await navigator.clipboard.writeText(location.href); Setlo.toast('Link copied', 'ok'); }
      catch (e) { Setlo.toast('Copy the link from the address bar.'); }
    },
    faces(it) { return it.who.slice(0, 4).map((id) => this.memberById[id]).filter(Boolean); },
    memberName(id) { const m = this.memberById[id]; return m ? m.name : '?'; },
    sharePct(id) {
      const total = this.detail.totals.total;
      return total > 0 ? Math.max(0, Math.min(100, Math.round(100 * (this.detail.shares[id] || 0) / total))) : 0;
    },
    showMemberItems(id) { this.memberFilter = this.memberFilter === id ? null : id; if (this.memberFilter) { this.itemFilter = 'all'; this.tab = 'items'; } },
    paidPct(s) { return s.amount > 0 ? Math.min(100, Math.round(100 * s.paid_amount / s.amount)) : 0; },
    methodLabel(p) {
      if (p.method === 'online') return 'Online' + (p.online_via ? ' (' + p.online_via + ')' : '');
      return { transfer: 'Bank/e-wallet transfer', cash: 'Cash', credit: 'Credit' }[p.method] || p.method;
    },
    settleName(id) {
      const s = this.detail.settlements.find((x) => x.id === id);
      return s ? s.from.name + ' → ' + s.to.name : 'Settlement';
    },
    async message(s) {
      const text = await Setlo.promptText({
        title: 'Message both parties',
        text: 'Sent to ' + s.from.first + ' and ' + s.to.first + '. Leave blank to send the standard follow-up.',
        placeholder: 'e.g. Please share the GCash reference number so this can be confirmed.',
        validate: (v) => (v.length > 280 ? 'Keep it to 280 characters or fewer.' : (/[<>]/.test(v) ? 'Please don’t use < or >.' : '')),
      });
      if (text === null) return;
      const r = await Setlo.run(this, () => api.post('admin.php', { action: 'message_both', id: s.id, message: text }));
      if (r) Setlo.toast('Both parties notified', 'ok');
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/admin-bottom.php'; ?>
<?php require __DIR__ . '/../partials/foot.php'; ?>
