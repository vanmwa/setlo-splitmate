<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/gemini.php';
$user = require_login();
$title = 'Scan Receipt';
$nav = 'scan';
$step = 1;
$billId = (int) ($_GET['bill'] ?? 0);
$adding = $billId && !empty($_GET['add']); // "+ Add another receipt" from the Review screen
$back = $adding ? 'review-items.php?bill=' . $billId : 'my-bills.php';
$headExtra = ['assets/js/docscan-core.js', 'assets/js/docscan.js'];
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0">
        <h1 class="text-[19px] font-extrabold leading-tight tracking-tight">{{ receiptCount && addMode ? 'Add a Receipt' : 'Scan Receipt' }}</h1>
        <p class="truncate text-[12px] font-medium text-brand-50/90">{{ bill ? bill.name : 'Choose a bill' }}</p>
      </div>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/steps.php'; ?>

  <!-- No bill chosen: pick one of my open bills -->
  <div v-if="!billId" class="flex-1 space-y-3 px-5 pb-6 pt-4">
    <p class="section-label">WHICH BILL IS THIS RECEIPT FOR?</p>
    <spinner v-if="loading"></spinner>
    <a v-for="b in pickable" :key="b.id" :href="'scan-receipt.php?bill=' + b.id" class="tile flex items-center gap-3.5 p-4">
      <div class="initials">{{ b.initials }}</div>
      <div class="min-w-0 flex-1">
        <p class="truncate text-[14px] font-bold text-ink">{{ b.name }}</p>
        <p class="mt-0.5 text-[12px] text-slate-400">{{ b.members }} members · {{ fmtDate(b.created_at) }}</p>
      </div>
      <status-pill :status="b.status"></status-pill>
    </a>
    <a href="my-bills?new=1" class="btn-pill btn-pill-primary">+ Create a new bill</a>
  </div>

  <div v-else class="flex flex-1 flex-col px-5 pb-5 pt-4">
    <div class="viewfinder h-[300px] shrink-0">
      <template v-if="state !== 'camera' || !guide.found">
        <span class="vf-corner tl"></span><span class="vf-corner tr"></span>
        <span class="vf-corner bl"></span><span class="vf-corner br"></span>
      </template>

      <video ref="video" v-show="state === 'camera'" autoplay playsinline muted class="absolute inset-0 h-full w-full object-cover"></video>
      <canvas ref="overlay" v-show="state === 'camera'" class="pointer-events-none absolute inset-0 h-full w-full" aria-hidden="true"></canvas>
      <div v-if="state === 'camera'" class="absolute inset-x-0 bottom-4 flex flex-wrap justify-center gap-1.5 px-3" role="status" aria-live="polite">
        <span v-if="guide.outline !== 'off'" class="vf-chip" :class="guide.outline === 'loading' ? '' : guide.found ? '!bg-emerald-500/80' : '!bg-amber-500/85'">
          {{ guide.outline === 'loading' ? '… Finding edges' : guide.found ? (guide.sides ? '▯ Receipt sides found' : guide.count > 1 ? '▭ ' + guide.count + ' receipts found' : '▭ Receipt found') : '▭ No receipt in frame' }}
        </span>
        <span v-if="guide.light" class="vf-chip" :class="guide.light === 'ok' ? '!bg-emerald-500/80' : '!bg-amber-500/85'">{{ lightLabel[guide.light] }}</span>
        <span v-if="guide.light" class="vf-chip" :class="guide.sharp ? '!bg-emerald-500/80' : '!bg-amber-500/85'">{{ guide.sharp ? '✓ Sharp' : '≋ Blurry' }}</span>
      </div>
      <template v-if="state !== 'camera'">
      <img v-if="preview" :src="preview" alt="Receipt preview" class="absolute left-1/2 top-9 h-[200px] w-auto max-w-[70%] -translate-x-1/2 rounded-md object-contain shadow-2xl" />
      <div v-else class="paper absolute left-1/2 top-9 h-[200px] w-[140px] px-4 pt-5" style="transform: translateX(-50%) rotate(-5deg)">
        <div class="paper-line mx-auto mb-4 w-12 !h-[7px] !bg-[#c9d2da]"></div>
        <div class="space-y-2.5">
          <div class="flex gap-2"><div class="paper-line flex-1"></div><div class="paper-line w-6"></div></div>
          <div class="flex gap-2"><div class="paper-line flex-1"></div><div class="paper-line w-6"></div></div>
          <div class="flex gap-2"><div class="paper-line w-16"></div><div class="flex-1"></div><div class="paper-line w-6"></div></div>
          <div class="flex gap-2"><div class="paper-line flex-1"></div><div class="paper-line w-6"></div></div>
          <div class="flex gap-2"><div class="paper-line w-14"></div><div class="flex-1"></div><div class="paper-line w-6"></div></div>
          <div class="flex gap-2"><div class="paper-line flex-1"></div><div class="paper-line w-6"></div></div>
          <div class="flex gap-2"><div class="paper-line w-20"></div><div class="flex-1"></div><div class="paper-line w-6"></div></div>
        </div>
      </div>

      <div class="vf-line" :class="{ run: state === 'scanning' }"></div>
      <div class="absolute inset-x-0 bottom-4 flex justify-center gap-1.5">
        <span class="vf-chip">☀ Good light</span><span class="vf-chip">▭ Flat surface</span><span class="vf-chip">✓ Full receipt</span>
      </div>
      </template>
    </div>

    <input ref="camera" type="file" accept="image/*" capture="environment" class="hidden" @change="picked" />
    <input ref="gallery" type="file" accept="image/jpeg,image/png,image/webp" multiple class="hidden" @change="picked" />

    <div v-if="state === 'capture'" class="text-center">
      <div v-if="receiptCount" class="mt-4 grid grid-cols-2 gap-1 rounded-2xl border border-slate-200 bg-white p-1 text-[12.5px] font-bold" role="radiogroup" aria-label="What to do with new photos">
        <button @click="addMode = true" role="radio" :aria-checked="addMode" class="rounded-xl py-2" :class="addMode ? 'bg-brand-600 text-white' : 'text-slate-500'">Add to the {{ receiptCount }} receipt{{ receiptCount > 1 ? 's' : '' }}</button>
        <button @click="addMode = false" role="radio" :aria-checked="!addMode" class="rounded-xl py-2" :class="!addMode ? 'bg-rose-600 text-white' : 'text-slate-500'">Replace {{ receiptCount > 1 ? 'them' : 'it' }}</button>
      </div>
      <p class="mt-5 text-[16px] font-extrabold text-ink">{{ receiptCount && addMode ? 'Add another receipt' : 'Position the receipt in frame' }}</p>
      <p class="mt-1 text-[13px] leading-snug text-slate-500">Item names, prices, tax and total should be clearly visible. Several receipts in one photo are read separately; a long receipt can be taken in parts.</p>
      <div class="mt-5 grid grid-cols-2 gap-3">
        <button @click="$refs.gallery.click()" class="btn-pill btn-pill-outline">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="8.5" cy="9.5" r="1.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 15l-5-5-8 9"/></svg>
          Upload
        </button>
        <button @click="openCamera" class="btn-pill btn-pill-primary">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.5l1-2h11l1 2H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><circle cx="12" cy="13" r="3.2"/></svg>
          Take Photo
        </button>
      </div>
      <p v-if="!ocrReady" class="mt-4 rounded-2xl bg-amber-50 px-4 py-2.5 text-[12px] text-amber-800">
        Receipt scanning isn't set up on this server yet: {{ ocrProblem }}. Photos are still saved, and you'll type the items on the next screen.
      </p>
      <p class="mt-4 text-[12px] text-slate-500">Faded or handwritten? <button @click="manual" class="font-bold text-brand-700">Skip to manual entry</button></p>    </div>

    <div v-else-if="state === 'camera'" class="text-center">
      <p class="mt-5 text-[16px] font-extrabold" :class="hint.ready ? 'text-emerald-600' : 'text-ink'">{{ hint.title }}</p>
      <p class="mt-1 text-[13px] leading-snug text-slate-500">{{ hint.text }}</p>
      <div class="mt-5 grid grid-cols-2 gap-3">
        <button @click="cancelCamera" class="btn-pill btn-pill-outline">Cancel</button>
        <button @click="capture" :disabled="busy" class="btn-pill btn-pill-primary">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.5l1-2h11l1 2H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><circle cx="12" cy="13" r="3.2"/></svg>
          Capture
        </button>
      </div>
    </div>

    <div v-else-if="state === 'queue'" class="text-center">
      <p class="mt-5 text-[16px] font-extrabold text-ink">{{ queue.length === 1 ? '1 photo ready' : queue.length + ' photos ready' }}</p>
      <p class="mt-1 text-[13px] leading-snug text-slate-500">Add more receipts, or the next part of a long one — or scan now.</p>
      <div class="mt-4 flex flex-wrap justify-center gap-2.5">
        <div v-for="(q, i) in queue" :key="q.key" class="relative">
          <img :src="q.url" :alt="'Photo ' + (i + 1)" class="h-20 w-16 rounded-lg object-cover shadow" />
          <span class="absolute bottom-1 left-1 rounded bg-ink/70 px-1 text-[10px] font-bold text-white">{{ i + 1 }}</span>
          <button @click="unqueue(i)" class="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-white text-[10px] font-bold text-slate-500 shadow" :aria-label="'Remove photo ' + (i + 1)">✕</button>
        </div>
      </div>
      <label v-if="queue.length > 1" class="mt-4 flex items-start gap-2.5 rounded-2xl bg-slate-50 px-3.5 py-3 text-left text-[12.5px] leading-snug text-slate-600">
        <input type="checkbox" v-model="partsOfOne" class="mt-0.5 h-4 w-4 shrink-0 accent-brand-600" />
        <span><b class="text-ink">These are parts of one long receipt</b><br />{{ partsOfOne ? 'Read together, top to bottom, as one receipt — keep them in order.' : 'Off: each photo is read as its own receipt.' }}</span>
      </label>
      <div class="mt-4 grid grid-cols-2 gap-3">
        <button @click="$refs.gallery.click()" :disabled="queueFull" class="btn-pill btn-pill-outline">+ Upload</button>
        <button @click="openCamera" :disabled="queueFull" class="btn-pill btn-pill-outline">+ Take photo</button>
      </div>
      <p v-if="queueFull" class="mt-2 text-[12px] text-slate-400">Up to {{ maxPhotos }} photos at a time.</p>
      <button @click="scanAll" class="btn-pill btn-pill-primary mt-3 w-full">{{ queue.length === 1 || partsOfOne ? 'Scan receipt' : 'Scan ' + queue.length + ' photos' }}</button>
      <button @click="clearQueue" class="mt-3 text-[12px] font-bold text-slate-400">Start over</button>
    </div>

    <div v-else-if="state === 'scanning'" class="text-center">
      <p class="mt-5 text-[16px] font-extrabold text-ink">{{ step.n > 1 ? 'Reading photo ' + step.i + ' of ' + step.n + '…' : 'Reading your receipt…' }}</p>
      <p class="mt-1 text-[13px] text-slate-500">Extracting item names and prices</p>
      <div class="progress mx-auto mt-5 w-44 !h-1.5"><span :style="{ width: progress + '%', transitionDuration: '6s', transitionTimingFunction: 'ease-out' }"></span></div>
      <div class="mt-6 grid grid-cols-2 gap-3">
        <button @click="cancelScan(false)" class="btn-pill btn-pill-outline">Cancel</button>
        <button @click="cancelScan(true)" class="btn-pill btn-pill-primary">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.5l1-2h11l1 2H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><circle cx="12" cy="13" r="3.2"/></svg>
          Take again
        </button>
      </div>
      <p class="mt-2 text-[11.5px] text-slate-400">Wrong receipt or a bad shot? Nothing from this scan is kept.</p>
    </div>

    <div v-else-if="state === 'failed'" class="text-center">
      <p class="mt-5 text-[16px] font-extrabold text-ink">Couldn't read this receipt</p>
      <p class="mt-1 text-[13px] text-slate-500">{{ failMessage }}</p>
      <div class="mt-5 grid grid-cols-2 gap-3">
        <button @click="clearQueue" class="btn-pill btn-pill-outline">Try another photo</button>
        <a :href="'review-items.php?bill=' + billId" class="btn-pill btn-pill-primary">Enter manually</a>
      </div>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
// Kept outside Vue's reactivity: the stream, the frame analyser and its latest result (outline in video pixels).
let cameraStream = null, analyzer = null, scanTimer = null, lastScan = null, scanRound = 0;
addEventListener('pagehide', () => cameraStream?.getTracks().forEach((t) => t.stop()));
Setlo.mount({
  data: () => ({
    billId: <?= $billId ?>, ocrReady: <?= gemini_available() ? 'true' : 'false' ?>, ocrProblem: <?= json_encode((string) gemini_setup_problem(), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>, me: <?= (int) $user['id'] ?>,
    bill: null, bills: [], loading: true, busy: false,
    state: 'capture', preview: null, progress: 0, failMessage: '',
    // Several receipts per bill: add to the bill's receipts, or replace them (the first scan).
    receiptCount: 0, addMode: <?= $adding ? 'true' : 'false' ?>,
    // Photos waiting to be scanned ({ key, file, url }), and whether they are sections of one long receipt.
    queue: [], partsOfOne: false, maxPhotos: 6, step: { i: 1, n: 1 },
    // Bumped by Cancel / Take again: a scan still waiting on the server sees it changed and throws its result away.
    scanRun: 0,
    // Live camera guidance. outline: 'loading' | 'on' | 'off' (OpenCV couldn't load: light and blur checks only).
    // count: receipts outlined in view (two side by side are each saved as their own receipt).
    guide: { outline: 'loading', found: false, count: 0, sides: false, light: null, sharp: true, stable: false },
    lightLabel: { ok: '☀ Good light', dark: '☾ Too dark', glare: '✺ Glare' },
  }),
  computed: {
    hint() {
      const g = this.guide;
      const many = g.count > 1;
      if (!g.light) return { title: 'Fit the whole receipt inside the frame', text: 'Starting the camera…' };
      if (g.outline === 'on' && !g.found) return { title: 'Fit the whole receipt inside the frame', text: 'Lay it flat on a darker surface, or hold it up so its edges stand out. Two receipts? Put them side by side.' };
      if (g.light === 'dark') return { title: 'Too dark to read', text: 'Move to better light or turn on a lamp.' };
      if (g.light === 'glare') return { title: 'Glare on the receipt', text: 'Tilt the receipt or phone away from the light.' };
      if (!g.sharp) return { title: 'Blurry — hold still', text: 'Keep the camera steady and let it focus.' };
      if (g.outline === 'on' && !g.stable) return { title: many ? g.count + ' receipts — hold steady…' : 'Hold steady…', text: 'Almost there.' };
      if (many) return { title: g.count + ' receipts in view — tap Capture', text: 'Each one is saved as its own receipt on this bill.', ready: true };
      const text = g.outline === 'on'
        ? (g.sides ? 'The photo will be cropped to the receipt’s left and right edges.' : 'The photo will be cropped to the green outline.')
        : g.outline === 'loading' ? 'Loading receipt detection… you can capture already.' : 'Item names, prices, tax and total should be clearly visible.';
      return { title: 'Ready — tap Capture', text, ready: true };
    },
    pickable() { return this.bills.filter((b) => b.status === 'draft' || b.status === 'active'); },
    queueFull() { return this.queue.length >= this.maxPhotos; },
  },
  async mounted() {
    if (!this.billId) {
      await Setlo.load(this, 'bills.php', null, (r) => {
        this.bills = r.bills.filter((b) => b.link.startsWith('scan-receipt') || b.link.startsWith('assign-items'));
      });
      return;
    }
    await Setlo.load(this, 'bills.php', { id: this.billId }, (r) => {
      if (!r.me.is_creator || r.bill.locked) {
        location.replace('bill-detail?bill=' + this.billId);
        return;
      }
      this.bill = r.bill;
      this.receiptCount = (r.receipts || []).length;
      // Adding is the safe default once the bill has receipts: replacing drops them and their items.
      if (this.receiptCount) this.addMode = true;
    });
  },
  methods: {
    async picked(e) {
      const files = [...e.target.files];
      e.target.value = '';
      const room = this.maxPhotos - this.queue.length;
      if (files.length > room) Setlo.toast(`Up to ${this.maxPhotos} photos at a time — added the first ${room}.`, 'warn');
      let warned = false;
      for (const file of files.slice(0, room)) {
        if (file.size > 8 * 1024 * 1024) { Setlo.toast('A photo is too large (max 8 MB) — skipped it.'); continue; }
        const q = warned ? null : await Setlo.docscan.checkFile(file);
        const n = files.length > 1 ? 'One photo' : 'This photo';
        if (q && q.light === 'dark') Setlo.toast(n + ' looks dark — if items come out wrong, retake it in better light.', 'warn');
        else if (q && q.light === 'glare') Setlo.toast(n + ' has glare — if items come out wrong, retake it at an angle.', 'warn');
        else if (q && !q.sharp) Setlo.toast(n + ' looks blurry — if items come out wrong, retake it.', 'warn');
        warned = warned || (q && (q.light !== 'ok' || !q.sharp));
        this.enqueue(file);
      }
    },
    enqueue(file) {
      this.queue.push({ key: Math.random().toString(36).slice(2), file, url: URL.createObjectURL(file) });
      this.preview = this.queue[this.queue.length - 1].url;
      this.state = 'queue';
    },
    unqueue(i) {
      URL.revokeObjectURL(this.queue[i].url);
      this.queue.splice(i, 1);
      if (!this.queue.length) this.reset();
      else this.preview = this.queue[this.queue.length - 1].url;
    },
    clearQueue() {
      this.queue.forEach((q) => URL.revokeObjectURL(q.url));
      this.queue = [];
      this.partsOfOne = false;
      this.reset();
    },
    // Live camera in the viewfinder; the capture="environment" input is the fallback (it only opens a camera on phones).
    async openCamera() {
      if (!navigator.mediaDevices?.getUserMedia) { this.$refs.camera.click(); return; }
      try {
        cameraStream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } },
          audio: false,
        });
      } catch (err) {
        if (err.name === 'NotAllowedError') Setlo.toast('Camera access was blocked — allow it in the browser, or use Upload.');
        else this.$refs.camera.click();
        return;
      }
      this.state = 'camera';
      this.guide = { outline: 'loading', found: false, count: 0, sides: false, light: null, sharp: true, stable: false };
      await this.$nextTick();
      this.$refs.video.srcObject = cameraStream;
      analyzer = Setlo.docscan.createAnalyzer((outline) => { if (this.state === 'camera') this.guide.outline = outline; });
      this.watchFrames();
    },
    // Re-check the frame a few times a second: outline, light, sharpness. The checks run in a worker; the next
    // round is only scheduled once this one is back, so slow phones skip frames instead of piling them up.
    async watchFrames() {
      clearTimeout(scanTimer);
      const round = ++scanRound;
      if (!cameraStream || !analyzer) return;
      const v = this.$refs.video;
      const r = v && await analyzer.analyze(v);
      if (round !== scanRound) return; // camera closed, Capture pressed or the loop restarted meanwhile
      if (r) {
        lastScan = r;
        const g = this.guide;
        const outline = g.outline === 'loading' && r.outline ? 'on' : g.outline;
        const count = (r.quads || []).length;
        // Only touch reactive state when something changed, so the page isn't re-rendered on every frame.
        if (g.outline !== outline || g.found !== !!r.quad || g.count !== count || g.sides !== r.sides || g.light !== r.light || g.sharp !== r.sharp || g.stable !== r.stable) {
          this.guide = { outline, found: !!r.quad, count, sides: r.sides, light: r.light, sharp: r.sharp, stable: r.stable };
        }
        Setlo.docscan.drawOverlay(this.$refs.overlay, v, r.quads, r.light === 'ok' && r.sharp);
      }
      scanTimer = setTimeout(this.watchFrames, 200);
    },
    stopCamera() {
      clearTimeout(scanTimer);
      scanRound++;
      analyzer?.close();
      analyzer = null;
      lastScan = null;
      cameraStream?.getTracks().forEach((t) => t.stop());
      cameraStream = null;
      if (this.$refs.video) this.$refs.video.srcObject = null;
    },
    cancelCamera() {
      this.stopCamera();
      if (this.queue.length) this.state = 'queue';
      else this.reset();
    },
    async capture() {
      const v = this.$refs.video;
      if (!v.videoWidth) return; // camera not showing a picture yet
      if (this.busy) return;
      const scan = lastScan, a = analyzer;
      clearTimeout(scanTimer); // freeze the guidance while asking and cropping
      scanRound++;
      if (scan && (scan.light === 'dark' || !scan.sharp)) {
        const ok = await Setlo.confirm({
          title: scan.light === 'dark' ? 'Photo may be too dark' : 'Photo may be blurry',
          text: 'Item names may come out wrong. Capture anyway?', confirmText: 'Capture anyway', cancelText: 'Retake',
        });
        if (!ok) { this.watchFrames(); return; }
        if (!cameraStream) return;
      }
      // One receipt: cropped and flattened to its outline. Several: cropped to the area around all of them (not
      // flattened, so none is cut off; the scanner saves each separately). None found: the whole frame.
      this.busy = true;
      let canvas = null;
      try {
        if (scan && a && scan.quads && scan.quads.length > 1) canvas = a.cropAround(v, scan.quads);
        else canvas = scan && scan.quad && a ? await a.warp(v, scan.quad) : null;
      } finally {
        this.busy = false;
      }
      if (!cameraStream) return;
      if (!canvas) {
        canvas = document.createElement('canvas');
        canvas.width = v.videoWidth;
        canvas.height = v.videoHeight;
        canvas.getContext('2d').drawImage(v, 0, 0);
      }
      this.stopCamera();
      canvas.toBlob((blob) => this.enqueue(new File([blob], 'receipt-' + (this.queue.length + 1) + '.jpg', { type: 'image/jpeg' })), 'image/jpeg', 0.9);
    },
    /** One upload request: a single photo, or all sections of one long receipt. */
    sendPhotos(files, mode, force) {
      const fd = new FormData();
      fd.append('action', 'upload');
      fd.append('bill_id', this.billId);
      fd.append('mode', mode);
      if (force) fd.append('force', '1');
      files.forEach((f) => fd.append('images[]', f));
      return api.post('receipts.php', fd);
    },
    // Separate photos are sent one at a time (each scan can take a while); a long receipt's parts go together.
    async scanAll() {
      if (!this.addMode && this.receiptCount) {
        const ok = await Setlo.confirm({
          title: `Replace the ${this.receiptCount > 1 ? this.receiptCount + ' receipts' : 'receipt'}?`, danger: true, confirmText: 'Replace',
          text: 'The bill’s scanned receipts and their items are removed and replaced by these photos.',
        });
        if (!ok) return;
      }
      const batches = this.partsOfOne ? [this.queue] : this.queue.map((q) => [q]);
      let read = 0, stored = 0;
      const problems = [];
      // Receipts this run saved: removed again if it's cancelled (the server finishes a scan even then).
      const run = ++this.scanRun, saved = [];
      const cancelled = (r) => {
        if (run === this.scanRun) return false;
        if (r && r.receipt_ids) saved.push(...r.receipt_ids);
        this.discard(saved);
        return true;
      };
      this.state = 'scanning';
      for (let i = 0; i < batches.length; i++) {
        this.step = { i: i + 1, n: batches.length };
        this.preview = batches[i][0].url;
        this.progress = 0;
        requestAnimationFrame(() => requestAnimationFrame(() => { this.progress = 90; }));
        // Only the very first request may replace; everything after it adds to what it stored.
        const mode = !this.addMode && i === 0 ? 'replace' : 'add';
        const files = batches[i].map((q) => q.file);
        let r;
        try {
          r = await this.sendPhotos(files, mode, false);
          if (cancelled(r)) return;
          if (r.duplicate_photo) {
            const d = r.duplicate_photo;
            const yes = await Setlo.confirm({
              title: 'Same photo again?', confirmText: 'Add anyway', cancelText: 'Skip it',
              text: d.receipt
                ? `This looks like the photo of Receipt ${d.receipt}${d.store ? ' (' + d.store + ')' : ''}, already on this bill.`
                : 'Two of these photos look the same.',
            });
            if (cancelled()) return;
            if (!yes) continue;
            r = await this.sendPhotos(files, mode, true);
            if (cancelled(r)) return;
          }
        } catch (err) {
          if (cancelled()) return;
          Setlo.toast(err.message);
          if (!stored) { this.state = 'queue'; return; }
          break; // keep what was already stored and show it on Review
        }
        stored++;
        saved.push(...(r.receipt_ids || []));
        if (r.ocr === 'ok' && r.found > 0) read++;
        else problems.push(!r.available ? 'Receipt scanning is not set up on this server. Your photo is saved — enter the items manually.'
          : r.error || 'No line items were detected. Try better lighting, or enter the items manually.');
      }
      this.progress = 100;
      if (read || (stored && this.receiptCount)) {
        this.queue.forEach((q) => URL.revokeObjectURL(q.url));
        setTimeout(() => { if (!cancelled()) location.href = 'review-items?bill=' + this.billId; }, 300);
      } else if (stored) {
        this.state = 'failed';
        this.failMessage = problems[0];
      } else {
        this.state = 'queue'; // every photo skipped as a duplicate
      }
    },
    /** Cancel / Take again while reading: drop this scan and start over (retake: straight into the camera). */
    cancelScan(retake) {
      this.scanRun++;
      this.progress = 0;
      this.clearQueue();
      Setlo.toast('Scan cancelled.');
      // Called right from the tap, so the camera's file-input fallback still counts as a user action.
      if (retake) this.openCamera();
    },
    /** Remove receipts a cancelled scan saved anyway. Quiet: if one fails, it simply shows up on Review. */
    discard(ids) {
      ids.splice(0).forEach((id) => api.post('receipts.php', { action: 'remove_receipt', bill_id: this.billId, receipt_id: id }).catch(() => {}));
    },
    reset() { this.state = 'capture'; this.preview = null; },
    async manual() {
      const r = await Setlo.run(this, () => api.post('receipts.php', { action: 'manual', bill_id: this.billId }));
      if (r) location.href = r.redirect;
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
