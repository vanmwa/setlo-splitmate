// Live receipt guidance for the camera: finds the paper's outline (OpenCV.js, loaded only when the camera opens)
// and checks light and sharpness on every frame. Light and blur are plain JS, so they still work if OpenCV can't load.
(function (global) {
  const OPENCV_URL = 'https://cdn.jsdelivr.net/npm/@techstark/opencv-js@4.12.0-release.1/dist/opencv.js';
  const OPENCV_SRI = 'sha384-i8A4fJEsRcMFMyEEDNri/2MR12DhkFLlUF+9oxUrxs6prIcRj7YtWAQ1OJ+iE0C7';
  const EDGE = 480;          // frames are analysed at this long edge: enough for edges and text sharpness, cheap on phones
  const MIN_AREA = 0.2;      // the receipt must cover at least this share of the frame
  const DARK_MEAN = 70;      // average brightness (0–255) below this is too dark to read
  const GLARE_SHARE = 0.08;  // more than this share of blown-out pixels is glare
  const SHARP_MIN = 0.07;    // Laplacian variance ÷ brightness variance below this is blurry (≈0.2 sharp, ≈0.05 unreadable)
  const FLAT_VAR = 25;       // brightness variance below this means nothing to judge (blank wall, plain paper)
  const STABLE_FRAMES = 4;   // the outline must hold still over this many frames
  const STABLE_DRIFT = 0.03; // ... moving its corners less than this share of the frame diagonal

  let loading = null;

  /** Load OpenCV.js once. Resolves with cv, or null when it can't load (offline, blocked). */
  function load() {
    if (loading) return loading;
    loading = new Promise((resolve) => {
      if (global.cv && global.cv.Mat) { resolve(global.cv); return; }
      const s = document.createElement('script');
      s.src = OPENCV_URL;
      s.integrity = OPENCV_SRI;
      s.crossOrigin = 'anonymous';
      s.async = true;
      s.onerror = () => resolve(null);
      // window.cv is an Emscripten module whose .then never settles when awaited, so wait for its classes instead.
      s.onload = () => {
        const start = Date.now();
        (function wait() {
          if (global.cv && global.cv.Mat) resolve(global.cv);
          else if (Date.now() - start > 30000) resolve(null);
          else setTimeout(wait, 100);
        })();
      };
      document.head.appendChild(s);
    });
    return loading;
  }

  const cvReady = () => !!(global.cv && global.cv.Mat);

  /** Grayscale bytes of an RGBA ImageData. */
  function toGray(img) {
    const d = img.data;
    const g = new Uint8ClampedArray(img.width * img.height);
    for (let i = 0, j = 0; j < g.length; i += 4, j++) g[j] = (d[i] * 77 + d[i + 1] * 150 + d[i + 2] * 29) >> 8;
    return g;
  }

  function lightOf(gray) {
    let sum = 0, blown = 0;
    for (let i = 0; i < gray.length; i++) { sum += gray[i]; if (gray[i] >= 250) blown++; }
    if (sum / gray.length < DARK_MEAN) return 'dark';
    return blown / gray.length > GLARE_SHARE ? 'glare' : 'ok';
  }

  /**
   * How crisp the edges inside a box are: variance of the 4-neighbour Laplacian divided by the brightness variance,
   * so faint thermal print and bold ink score alike. The box is first smoothed with a 3×3 mean so camera noise
   * (also high-frequency) doesn't pass for sharpness. Featureless boxes return Infinity (nothing to judge).
   */
  function sharpnessOf(gray, w, h, box) {
    const x0 = Math.max(1, box.x0), y0 = Math.max(1, box.y0);
    const x1 = Math.min(w - 1, box.x1), y1 = Math.min(h - 1, box.y1);
    const bw = x1 - x0, bh = y1 - y0;
    if (bw < 3 || bh < 3) return 0;
    const s = new Float32Array(bw * bh);
    for (let y = 0; y < bh; y++) {
      for (let x = 0; x < bw; x++) {
        const i = (y + y0) * w + x + x0;
        s[y * bw + x] = (gray[i - w - 1] + gray[i - w] + gray[i - w + 1] + gray[i - 1] + gray[i] + gray[i + 1]
          + gray[i + w - 1] + gray[i + w] + gray[i + w + 1]) / 9;
      }
    }
    let n = 0, sum = 0, sq = 0, gsum = 0, gsq = 0;
    for (let y = 1; y < bh - 1; y++) {
      for (let x = 1, i = y * bw + 1; x < bw - 1; x++, i++) {
        const v = 4 * s[i] - s[i - 1] - s[i + 1] - s[i - bw] - s[i + bw];
        sum += v; sq += v * v; gsum += s[i]; gsq += s[i] * s[i]; n++;
      }
    }
    if (!n) return 0;
    const gvar = gsq / n - (gsum / n) ** 2;
    if (gvar < FLAT_VAR) return Infinity;
    return (sq / n - (sum / n) ** 2) / gvar;
  }

  /** Corners in order: top-left, top-right, bottom-right, bottom-left. */
  function order(pts) {
    const bySum = [...pts].sort((a, b) => a.x + a.y - (b.x + b.y));
    const byDiff = [...pts].sort((a, b) => a.y - a.x - (b.y - b.x));
    return [bySum[0], byDiff[0], bySum[3], byDiff[3]];
  }

  /** The largest paper-shaped outline in an RGBA ImageData, in its pixel coordinates; null if none. */
  function findQuad(img) {
    const cv = global.cv;
    const mats = [];
    const keep = (m) => { mats.push(m); return m; };
    try {
      const src = keep(cv.matFromImageData(img));
      const gray = keep(new cv.Mat());
      cv.cvtColor(src, gray, cv.COLOR_RGBA2GRAY);
      cv.GaussianBlur(gray, gray, new cv.Size(5, 5), 0);
      const edges = keep(new cv.Mat());
      cv.Canny(gray, edges, 50, 150);
      const kernel = keep(cv.Mat.ones(3, 3, cv.CV_8U));
      cv.dilate(edges, edges, kernel);
      const contours = keep(new cv.MatVector());
      const hierarchy = keep(new cv.Mat());
      cv.findContours(edges, contours, hierarchy, cv.RETR_EXTERNAL, cv.CHAIN_APPROX_SIMPLE);

      const minArea = MIN_AREA * img.width * img.height;
      let best = null, bestArea = 0;
      for (let i = 0; i < contours.size(); i++) {
        const c = contours.get(i);
        const area = cv.contourArea(c);
        if (area < minArea || area <= bestArea) { c.delete(); continue; }
        const approx = new cv.Mat();
        cv.approxPolyDP(c, approx, 0.02 * cv.arcLength(c, true), true);
        let pts = null;
        if (approx.rows === 4 && cv.isContourConvex(approx)) {
          pts = [0, 1, 2, 3].map((k) => ({ x: approx.data32S[k * 2], y: approx.data32S[k * 2 + 1] }));
        } else {
          // Curled corners or a torn edge: fall back to the tightest rotated box, if the shape mostly fills it.
          const rect = cv.minAreaRect(c);
          if (area / (rect.size.width * rect.size.height) > 0.8) pts = cv.RotatedRect.points(rect);
        }
        approx.delete();
        c.delete();
        if (pts) { best = pts; bestArea = area; }
      }
      return best ? order(best.map((p) => ({ x: p.x, y: p.y }))) : null;
    } catch (e) {
      return null;
    } finally {
      mats.forEach((m) => m.delete());
    }
  }

  /** One analyser per camera session; keeps the recent outlines to tell when the receipt is held still. */
  function createAnalyzer() {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    let recent = [];

    return {
      /** Returns { quad (video pixels) | null, light: 'ok'|'dark'|'glare', sharp, stable, outline: whether OpenCV ran }. */
      analyze(video) {
        const vw = video.videoWidth, vh = video.videoHeight;
        if (!vw || !vh) return null;
        const scale = Math.min(1, EDGE / Math.max(vw, vh));
        const w = Math.round(vw * scale), h = Math.round(vh * scale);
        if (canvas.width !== w || canvas.height !== h) { canvas.width = w; canvas.height = h; }
        ctx.drawImage(video, 0, 0, w, h);
        const img = ctx.getImageData(0, 0, w, h);
        const gray = toGray(img);

        const outline = cvReady();
        const q = outline ? findQuad(img) : null;
        const box = q
          ? { x0: Math.min(...q.map((p) => p.x)), y0: Math.min(...q.map((p) => p.y)), x1: Math.max(...q.map((p) => p.x)), y1: Math.max(...q.map((p) => p.y)) }
          : { x0: Math.round(w * 0.15), y0: Math.round(h * 0.15), x1: Math.round(w * 0.85), y1: Math.round(h * 0.85) };
        const sharp = sharpnessOf(gray, w, h, box) >= SHARP_MIN;
        // Light is judged on the receipt itself when found: a dark table around white paper is fine.
        const light = lightOf(q ? cropGray(gray, w, box) : gray);

        recent = q ? [...recent, q].slice(-STABLE_FRAMES) : [];
        const diag = Math.hypot(w, h);
        const stable = recent.length === STABLE_FRAMES
          && recent.every((r) => r.every((p, k) => Math.hypot(p.x - q[k].x, p.y - q[k].y) < STABLE_DRIFT * diag));

        return { quad: q && q.map((p) => ({ x: p.x / scale, y: p.y / scale })), light, sharp, stable, outline };
      },
    };
  }

  function cropGray(gray, w, box) {
    const out = [];
    for (let y = box.y0; y < box.y1; y += 2) for (let x = box.x0; x < box.x1; x += 2) out.push(gray[y * w + x]);
    return out.length ? out : gray;
  }

  /** Draw the outline over a <video> shown with object-fit: cover. */
  function drawOverlay(canvas, video, quad, good) {
    const dpr = global.devicePixelRatio || 1;
    const cw = canvas.clientWidth, ch = canvas.clientHeight;
    if (canvas.width !== Math.round(cw * dpr) || canvas.height !== Math.round(ch * dpr)) {
      canvas.width = Math.round(cw * dpr);
      canvas.height = Math.round(ch * dpr);
    }
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, cw, ch);
    if (!quad || !video.videoWidth) return;
    const s = Math.max(cw / video.videoWidth, ch / video.videoHeight);
    const ox = (cw - video.videoWidth * s) / 2, oy = (ch - video.videoHeight * s) / 2;
    ctx.beginPath();
    quad.forEach((p, i) => ctx[i ? 'lineTo' : 'moveTo'](ox + p.x * s, oy + p.y * s));
    ctx.closePath();
    ctx.fillStyle = good ? 'rgba(16, 185, 129, 0.16)' : 'rgba(245, 158, 11, 0.14)';
    ctx.fill();
    ctx.lineWidth = 3;
    ctx.lineJoin = 'round';
    ctx.strokeStyle = good ? '#10b981' : '#f59e0b';
    ctx.stroke();
  }

  /**
   * Full-resolution, flattened crop of the receipt inside quad (video pixels). Returns a canvas, or null when it
   * can't (no OpenCV), so the caller falls back to the whole frame.
   */
  function warp(video, quad) {
    if (!cvReady() || !quad) return null;
    const cv = global.cv;
    // Push the corners out 2% from the centre, so the paper's edge isn't clipped.
    const cx = quad.reduce((a, p) => a + p.x, 0) / 4, cy = quad.reduce((a, p) => a + p.y, 0) / 4;
    const vw = video.videoWidth, vh = video.videoHeight;
    const q = quad.map((p) => ({
      x: Math.min(vw, Math.max(0, cx + (p.x - cx) * 1.02)),
      y: Math.min(vh, Math.max(0, cy + (p.y - cy) * 1.02)),
    }));
    const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
    const w = Math.round(Math.max(dist(q[0], q[1]), dist(q[3], q[2])));
    const h = Math.round(Math.max(dist(q[0], q[3]), dist(q[1], q[2])));
    if (w < 100 || h < 100) return null;

    const frame = document.createElement('canvas');
    frame.width = vw;
    frame.height = vh;
    frame.getContext('2d').drawImage(video, 0, 0);
    const mats = [];
    const keep = (m) => { mats.push(m); return m; };
    try {
      const src = keep(cv.imread(frame));
      const from = keep(cv.matFromArray(4, 1, cv.CV_32FC2, q.flatMap((p) => [p.x, p.y])));
      const to = keep(cv.matFromArray(4, 1, cv.CV_32FC2, [0, 0, w, 0, w, h, 0, h]));
      const m = keep(cv.getPerspectiveTransform(from, to));
      const dst = keep(new cv.Mat());
      cv.warpPerspective(src, dst, m, new cv.Size(w, h), cv.INTER_LINEAR, cv.BORDER_REPLICATE, new cv.Scalar());
      const out = document.createElement('canvas');
      cv.imshow(out, dst);
      return out;
    } catch (e) {
      return null;
    } finally {
      mats.forEach((mat) => mat.delete());
    }
  }

  /** One-off light and sharpness check of a picked photo. Resolves { light, sharp }, or null if it can't be read. */
  async function checkFile(file) {
    try {
      const bmp = await createImageBitmap(file);
      const scale = Math.min(1, EDGE / Math.max(bmp.width, bmp.height));
      const w = Math.round(bmp.width * scale), h = Math.round(bmp.height * scale);
      const canvas = document.createElement('canvas');
      canvas.width = w;
      canvas.height = h;
      const ctx = canvas.getContext('2d', { willReadFrequently: true });
      ctx.drawImage(bmp, 0, 0, w, h);
      bmp.close && bmp.close();
      const gray = toGray(ctx.getImageData(0, 0, w, h));
      const box = { x0: Math.round(w * 0.1), y0: Math.round(h * 0.1), x1: Math.round(w * 0.9), y1: Math.round(h * 0.9) };
      return { light: lightOf(gray), sharp: sharpnessOf(gray, w, h, box) >= SHARP_MIN };
    } catch (e) {
      return null;
    }
  }

  global.Setlo = global.Setlo || {};
  global.Setlo.docscan = { load, createAnalyzer, drawOverlay, warp, checkFile };
})(window);
