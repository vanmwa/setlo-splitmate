// Live receipt guidance for the camera: finds the paper's outline (OpenCV.js) and checks light and sharpness on
// each frame. The work runs in a Web Worker (docscan-worker.js) so the page never freezes; the page only grabs a
// small frame and draws the outline. Light and blur are plain JS, so they still work if OpenCV or the worker can't load.
(function (global) {
  const core = global.SetloDocscanCore;
  // The worker (and the core it loads) share this script's ?v=, which changes whenever docscan.js is deployed.
  const WORKER_URL = document.currentScript.src.replace(/docscan\.js(\?.*)?$/, 'docscan-worker.js$1');

  // One worker for the whole page, started when the camera first opens; OpenCV then loads once and is reused.
  let worker = null, workerFailed = false, cvState = 'loading', nextId = 1;
  const pending = new Map();
  const cvListeners = new Set();

  function getWorker() {
    if (worker || workerFailed) return worker;
    try {
      worker = new Worker(WORKER_URL);
    } catch (e) {
      workerFailed = true;
      cvState = 'off';
      return null;
    }
    worker.onmessage = (e) => {
      const msg = e.data;
      if (msg.type === 'cv') {
        cvState = msg.ready ? 'on' : 'off';
        cvListeners.forEach((fn) => fn(cvState));
      } else if (pending.has(msg.id)) {
        pending.get(msg.id)(msg);
        pending.delete(msg.id);
      }
    };
    worker.onerror = () => {
      // The worker couldn't start (blocked, old browser): fall back to light and blur checks on the page.
      worker.terminate();
      worker = null;
      workerFailed = true;
      cvState = 'off';
      cvListeners.forEach((fn) => fn(cvState));
      pending.forEach((resolve) => resolve({ result: null, img: null }));
      pending.clear();
    };
    return worker;
  }

  function ask(msg, transfer) {
    const w = getWorker();
    if (!w) return null;
    const id = nextId++;
    return new Promise((resolve) => {
      pending.set(id, resolve);
      w.postMessage({ ...msg, id }, transfer);
    });
  }

  /**
   * One analyser per camera session. onOutline(state) is told when OpenCV is 'on' or 'off'.
   * analyze(video) resolves { quads (video pixels), quad: the biggest | null, sides, light, sharp, stable, outline }, or null when there's no
   * picture yet or the previous frame is still being checked (frames are skipped, never queued up).
   */
  function createAnalyzer(onOutline) {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    const local = core.createTracker(); // used only when the worker can't run
    let busy = false;

    if (onOutline) {
      cvListeners.add(onOutline);
      if (cvState !== 'loading') onOutline(cvState);
    }
    if (getWorker()) worker.postMessage({ type: 'reset' });

    return {
      async analyze(video) {
        const vw = video.videoWidth, vh = video.videoHeight;
        if (!vw || !vh || busy) return null;
        const scale = Math.min(1, core.EDGE / Math.max(vw, vh));
        const w = Math.round(vw * scale), h = Math.round(vh * scale);
        if (canvas.width !== w || canvas.height !== h) { canvas.width = w; canvas.height = h; }
        ctx.drawImage(video, 0, 0, w, h);
        const img = ctx.getImageData(0, 0, w, h);
        const reply = ask({ type: 'frame', img, scale }, [img.data.buffer]);
        if (!reply) return local.check(img, scale, null);
        busy = true;
        try {
          return (await reply).result;
        } finally {
          busy = false;
        }
      },
      /**
       * Several receipts in view: full-resolution crop of the area around all of them (plus a margin), unflattened,
       * so none is cut off and the scanner reads each one separately. Returns a canvas, or null.
       */
      cropAround(video, quads) {
        const vw = video.videoWidth, vh = video.videoHeight;
        if (!vw || !quads || !quads.length) return null;
        // Each receipt grown by the same allowance as a single crop (core.padQuad), then the box around them all.
        const b = core.boxOf(quads.map((q) => core.padQuad(q, vw, vh)), vw, vh);
        const { x0, y0, x1, y1 } = b;
        if (x1 - x0 < 100 || y1 - y0 < 100) return null;
        const c = document.createElement('canvas');
        c.width = x1 - x0;
        c.height = y1 - y0;
        c.getContext('2d').drawImage(video, x0, y0, c.width, c.height, 0, 0, c.width, c.height);
        return c;
      },
      /** Full-resolution, flattened crop of the receipt inside quad (video pixels); resolves a canvas, or null. */
      async warp(video, quad) {
        if (!quad || cvState !== 'on') return null;
        const vw = video.videoWidth, vh = video.videoHeight;
        const frame = document.createElement('canvas');
        frame.width = vw;
        frame.height = vh;
        const fctx = frame.getContext('2d', { willReadFrequently: true });
        fctx.drawImage(video, 0, 0);
        const img = fctx.getImageData(0, 0, vw, vh);
        const reply = ask({ type: 'warp', img, quad }, [img.data.buffer]);
        const out = reply && (await reply).img;
        if (!out) return null;
        const c = document.createElement('canvas');
        c.width = out.width;
        c.height = out.height;
        c.getContext('2d').putImageData(out, 0, 0);
        return c;
      },
      close() {
        if (onOutline) cvListeners.delete(onOutline);
      },
    };
  }

  /** Draw the receipt outline(s) over a <video> shown with object-fit: cover. quads: one quad or a list of them. */
  function drawOverlay(canvas, video, quads, good) {
    const dpr = global.devicePixelRatio || 1;
    const cw = canvas.clientWidth, ch = canvas.clientHeight;
    if (canvas.width !== Math.round(cw * dpr) || canvas.height !== Math.round(ch * dpr)) {
      canvas.width = Math.round(cw * dpr);
      canvas.height = Math.round(ch * dpr);
    }
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, cw, ch);
    const list = !quads || !quads.length ? [] : Array.isArray(quads[0]) ? quads : [quads];
    if (!list.length || !video.videoWidth) return;
    const s = Math.max(cw / video.videoWidth, ch / video.videoHeight);
    const ox = (cw - video.videoWidth * s) / 2, oy = (ch - video.videoHeight * s) / 2;
    list.forEach((quad, n) => {
      ctx.beginPath();
      quad.forEach((p, i) => ctx[i ? 'lineTo' : 'moveTo'](ox + p.x * s, oy + p.y * s));
      ctx.closePath();
      ctx.fillStyle = good ? 'rgba(16, 185, 129, 0.16)' : 'rgba(245, 158, 11, 0.14)';
      ctx.fill();
      ctx.lineWidth = 3;
      ctx.lineJoin = 'round';
      ctx.strokeStyle = good ? '#10b981' : '#f59e0b';
      ctx.stroke();
      if (list.length > 1) {
        // Number each receipt, so "2 receipts in view" matches what's outlined
        const tl = quad.reduce((a, p) => (p.x + p.y < a.x + a.y ? p : a));
        const x = ox + tl.x * s + 16, y = oy + tl.y * s + 16;
        ctx.beginPath();
        ctx.arc(x, y, 12, 0, Math.PI * 2);
        ctx.fillStyle = good ? '#10b981' : '#f59e0b';
        ctx.fill();
        ctx.fillStyle = '#fff';
        ctx.font = '800 13px "Plus Jakarta Sans", system-ui, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(String(n + 1), x, y + 0.5);
      }
    });
  }

  /** One-off light and sharpness check of a picked photo. Resolves { light, sharp }, or null if it can't be read. */
  async function checkFile(file) {
    try {
      const bmp = await createImageBitmap(file);
      const scale = Math.min(1, core.EDGE / Math.max(bmp.width, bmp.height));
      const w = Math.round(bmp.width * scale), h = Math.round(bmp.height * scale);
      const canvas = document.createElement('canvas');
      canvas.width = w;
      canvas.height = h;
      const ctx = canvas.getContext('2d', { willReadFrequently: true });
      ctx.drawImage(bmp, 0, 0, w, h);
      bmp.close && bmp.close();
      const gray = core.toGray(ctx.getImageData(0, 0, w, h));
      const box = { x0: Math.round(w * 0.1), y0: Math.round(h * 0.1), x1: Math.round(w * 0.9), y1: Math.round(h * 0.9) };
      return { light: core.lightOf(gray), sharp: core.sharpnessOf(gray, w, h, box) >= core.SHARP_MIN };
    } catch (e) {
      return null;
    }
  }

  global.Setlo = global.Setlo || {};
  global.Setlo.docscan = { createAnalyzer, drawOverlay, checkFile };
})(window);
