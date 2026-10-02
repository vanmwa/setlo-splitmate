<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/gemini.php';
$user = require_login();
$title = 'Scan Receipt';
$nav = 'scan';
$back = 'my-bills.php';
$step = 1;
$billId = (int) ($_GET['bill'] ?? 0);
$headExtra = ['assets/js/docscan.js'];
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0">
        <h1 class="text-[19px] font-extrabold leading-tight tracking-tight">Scan Receipt</h1>
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
          {{ guide.outline === 'loading' ? '… Finding edges' : guide.found ? '▭ Receipt found' : '▭ No receipt in frame' }}
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
    <input ref="gallery" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="picked" />

    <div v-if="state === 'capture'" class="text-center">
      <p class="mt-5 text-[16px] font-extrabold text-ink">Position the receipt in frame</p>
      <p class="mt-1 text-[13px] leading-snug text-slate-500">Item names, prices, tax and total should be clearly visible.</p>
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
      <p class="mt-4 text-[12px] text-slate-500">Faded or handwritten? <button @click="manual" class="font-bold text-brand-700">Skip to manual entry</button></p>
      <p class="mt-1.5 text-[12px] text-slate-500">No receipt handy? <button @click="demo" class="font-bold text-brand-700">Load demo receipt</button></p>
    </div>

    <div v-else-if="state === 'camera'" class="text-center">
      <p class="mt-5 text-[16px] font-extrabold" :class="hint.ready ? 'text-emerald-600' : 'text-ink'">{{ hint.title }}</p>
      <p class="mt-1 text-[13px] leading-snug text-slate-500">{{ hint.text }}</p>
      <div class="mt-5 grid grid-cols-2 gap-3">
        <button @click="cancelCamera" class="btn-pill btn-pill-outline">Cancel</button>
        <button @click="capture" class="btn-pill btn-pill-primary">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.5l1-2h11l1 2H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><circle cx="12" cy="13" r="3.2"/></svg>
          Capture
        </button>
      </div>
    </div>

    <div v-else-if="state === 'scanning'" class="text-center">
      <p class="mt-5 text-[16px] font-extrabold text-ink">Reading your receipt…</p>
      <p class="mt-1 text-[13px] text-slate-500">Extracting item names and prices</p>
      <div class="progress mx-auto mt-5 w-44 !h-1.5"><span :style="{ width: progress + '%', transitionDuration: '6s', transitionTimingFunction: 'ease-out' }"></span></div>
    </div>

    <div v-else-if="state === 'failed'" class="text-center">
      <p class="mt-5 text-[16px] font-extrabold text-ink">Couldn't read this receipt</p>
      <p class="mt-1 text-[13px] text-slate-500">{{ failMessage }}</p>
      <div class="mt-5 grid grid-cols-2 gap-3">
        <button @click="reset" class="btn-pill btn-pill-outline">Try another photo</button>
        <a :href="'review-items.php?bill=' + billId" class="btn-pill btn-pill-primary">Enter manually</a>
      </div>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
// Kept outside Vue's reactivity: the stream, the frame analyser and its latest result (outline in video pixels).
let cameraStream = null, analyzer = null, scanTimer = null, lastScan = null;
addEventListener('pagehide', () => cameraStream?.getTracks().forEach((t) => t.stop()));
Setlo.mount({
  data: () => ({
    billId: <?= $billId ?>, ocrReady: <?= gemini_available() ? 'true' : 'false' ?>, ocrProblem: <?= json_encode((string) gemini_setup_problem(), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>, me: <?= (int) $user['id'] ?>,
    bill: null, bills: [], loading: true, busy: false,
    state: 'capture', preview: null, progress: 0, failMessage: '',
    // Live camera guidance. outline: 'loading' | 'on' | 'off' (OpenCV couldn't load: light and blur checks only).
    guide: { outline: 'loading', found: false, light: null, sharp: true, stable: false },
    lightLabel: { ok: '☀ Good light', dark: '☾ Too dark', glare: '✺ Glare' },
  }),
  computed: {
    hint() {
      const g = this.guide;
      if (!g.light) return { title: 'Fit the whole receipt inside the frame', text: 'Starting the camera…' };
      if (g.outline === 'on' && !g.found) return { title: 'Fit the whole receipt inside the frame', text: 'Lay it flat on a darker surface so its edges stand out.' };
      if (g.light === 'dark') return { title: 'Too dark to read', text: 'Move to better light or turn on a lamp.' };
      if (g.light === 'glare') return { title: 'Glare on the receipt', text: 'Tilt the receipt or phone away from the light.' };
      if (!g.sharp) return { title: 'Blurry — hold still', text: 'Keep the phone steady and let it focus.' };
      if (g.outline === 'on' && !g.stable) return { title: 'Hold steady…', text: 'Almost there.' };
      return { title: 'Ready — tap Capture', text: g.outline === 'on' ? 'The photo will be cropped to the green outline.' : 'Item names, prices, tax and total should be clearly visible.', ready: true };
    },
    pickable() { return this.bills.filter((b) => b.status === 'draft' || b.status === 'active'); },
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
    });
  },
  methods: {
    async picked(e) {
      const file = e.target.files[0];
      e.target.value = '';
      if (!file) return;
      const q = await Setlo.docscan.checkFile(file);
      if (q && q.light === 'dark') Setlo.toast('This photo looks dark — if items come out wrong, retake it in better light.', 'warn');
      else if (q && q.light === 'glare') Setlo.toast('This photo has glare — if items come out wrong, retake it at an angle.', 'warn');
      else if (q && !q.sharp) Setlo.toast('This photo looks blurry — if items come out wrong, retake it.', 'warn');
      this.upload(file);
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
      this.guide = { outline: 'loading', found: false, light: null, sharp: true, stable: false };
      await this.$nextTick();
      this.$refs.video.srcObject = cameraStream;
      analyzer = Setlo.docscan.createAnalyzer();
      Setlo.docscan.load().then((cv) => { if (this.state === 'camera') this.guide.outline = cv ? 'on' : 'off'; });
      this.watchFrames();
    },
    // Re-check the frame a few times a second: outline, light, sharpness.
    watchFrames() {
      clearTimeout(scanTimer);
      if (!cameraStream || !analyzer) return;
      const v = this.$refs.video;
      const r = v && analyzer.analyze(v);
      if (r) {
        lastScan = r;
        const outline = this.guide.outline === 'loading' && r.outline ? 'on' : this.guide.outline;
        this.guide = { outline, found: !!r.quad, light: r.light, sharp: r.sharp, stable: r.stable };
        Setlo.docscan.drawOverlay(this.$refs.overlay, v, r.quad, r.light === 'ok' && r.sharp);
      }
      scanTimer = setTimeout(this.watchFrames, 150);
    },
    stopCamera() {
      clearTimeout(scanTimer);
      analyzer = null;
      lastScan = null;
      cameraStream?.getTracks().forEach((t) => t.stop());
      cameraStream = null;
      if (this.$refs.video) this.$refs.video.srcObject = null;
    },
    cancelCamera() { this.stopCamera(); this.reset(); },
    async capture() {
      const v = this.$refs.video;
      if (!v.videoWidth) return; // camera not showing a picture yet
      const scan = lastScan;
      if (scan && (scan.light === 'dark' || !scan.sharp)) {
        clearTimeout(scanTimer); // freeze the guidance while asking
        const ok = await Setlo.confirm({
          title: scan.light === 'dark' ? 'Photo may be too dark' : 'Photo may be blurry',
          text: 'Item names may come out wrong. Capture anyway?', confirmText: 'Capture anyway', cancelText: 'Retake',
        });
        if (!ok) { this.watchFrames(); return; }
        if (!cameraStream) return;
      }
      // Cropped and flattened to the outline when one was found; otherwise the whole frame.
      let canvas = scan && scan.quad ? Setlo.docscan.warp(v, scan.quad) : null;
      if (!canvas) {
        canvas = document.createElement('canvas');
        canvas.width = v.videoWidth;
        canvas.height = v.videoHeight;
        canvas.getContext('2d').drawImage(v, 0, 0);
      }
      this.stopCamera();
      canvas.toBlob((blob) => this.upload(new File([blob], 'receipt.jpg', { type: 'image/jpeg' })), 'image/jpeg', 0.9);
    },
    async upload(file) {
      if (file.size > 8 * 1024 * 1024) { Setlo.toast('Photo is too large (max 8 MB).'); return; }
      this.preview = URL.createObjectURL(file);
      this.state = 'scanning';
      this.progress = 0;
      requestAnimationFrame(() => { this.progress = 90; });

      const fd = new FormData();
      fd.append('action', 'upload');
      fd.append('bill_id', this.billId);
      fd.append('image', file);
      try {
        const r = await api.post('receipts.php', fd);
        this.progress = 100;
        if (r.ocr === 'ok' && r.found > 0) {
          setTimeout(() => { location.href = r.redirect; }, 300);
        } else {
          this.state = 'failed';
          this.failMessage = !r.available
            ? 'Receipt scanning is not set up on this server. Your photo is saved — enter the items manually.'
            : r.error || "No line items were detected. Try better lighting, or enter the items manually.";
        }
      } catch (err) {
        Setlo.toast(err.message);
        this.reset();
      }
    },
    reset() { this.state = 'capture'; this.preview = null; },
    async manual() {
      const r = await Setlo.run(this, () => api.post('receipts.php', { action: 'manual', bill_id: this.billId }));
      if (r) location.href = r.redirect;
    },
    async demo() {
      this.state = 'scanning';
      requestAnimationFrame(() => { this.progress = 100; });
      const r = await Setlo.run(this, () => api.post('receipts.php', { action: 'demo', bill_id: this.billId }));
      if (r) setTimeout(() => { location.href = r.redirect; }, 1200);
      else this.reset();
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
