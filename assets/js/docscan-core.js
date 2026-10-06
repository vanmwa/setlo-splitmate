// Receipt-frame checks shared by the page (picked photos) and the camera worker (live frames): light, sharpness,
// and, when OpenCV is loaded, the paper's outline and a flattened crop. No DOM here, so it runs in a Worker too.
(function (global) {
  const EDGE = 480;          // frames are analysed at this long edge: enough for edges and text sharpness, cheap on phones
  // Each receipt must cover at least this share of the frame. Low enough for a receipt held up to a laptop webcam,
  // or two receipts side by side; stray shapes are weeded out by shape and brightness (paperLike()).
  const MIN_AREA = 0.04;
  const MAX_AREA = 0.97;     // an outline this big is the frame's own border, not paper
  const MAX_ASPECT = 9;      // long side ÷ short side: receipts are long, but not thin strips
  const MAX_RECEIPTS = 4;    // outlines reported per frame
  const DARK_MEAN = 70;      // average brightness (0–255) below this is too dark to read
  const GLARE_SHARE = 0.08;  // more than this share of blown-out pixels is glare
  const SHARP_MIN = 0.07;    // Laplacian variance ÷ brightness variance below this is blurry (≈0.2 sharp, ≈0.05 unreadable)
  const FLAT_VAR = 25;       // brightness variance below this means nothing to judge (blank wall, plain paper)
  const STABLE_FRAMES = 4;   // the outline must hold still over this many frames
  const STABLE_DRIFT = 0.03; // ... moving its corners less than this share of the frame diagonal
  // A long receipt often runs past the top or bottom of the frame, so its outline never closes. Then its left and
  // right edges are looked for instead: two long, near-vertical, near-parallel lines.
  const SIDE_MIN_LEN = 0.3;  // each side line covers at least this share of the frame height
  const SIDE_SLANT = 0.27;   // at most this much sideways per unit down (≈15° off vertical)
  const SIDE_PARALLEL = 0.1; // the two sides' slants differ by at most this
  const SIDE_MIN_GAP = 0.15; // the sides are at least this share of the frame width apart
  const SIDE_TO_EDGE = 0.08; // a side ending this close to the frame's top or bottom is taken to run past it

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

  function cropGray(gray, w, box) {
    const out = [];
    for (let y = box.y0; y < box.y1; y += 2) for (let x = box.x0; x < box.x1; x += 2) out.push(gray[y * w + x]);
    return out.length ? out : gray;
  }

  /** Corners in order: top-left, top-right, bottom-right, bottom-left. */
  function order(pts) {
    const bySum = [...pts].sort((a, b) => a.x + a.y - (b.x + b.y));
    const byDiff = [...pts].sort((a, b) => a.y - a.x - (b.y - b.x));
    return [bySum[0], byDiff[0], bySum[3], byDiff[3]];
  }

  /**
   * The receipt's left and right edges in a Canny edge image, as a quad from where the sides start to where they end
   * (stretched to the frame's top or bottom when they run past it); null if no such pair of lines.
   */
  function findSides(cv, edges, w, h) {
    const lines = new cv.Mat();
    try {
      cv.HoughLinesP(edges, lines, 1, Math.PI / 180, 40, Math.round(h * SIDE_MIN_LEN), Math.round(h * 0.04));
      const cand = [];
      for (let i = 0; i < lines.rows; i++) {
        let [x1, y1, x2, y2] = lines.data32S.slice(i * 4, i * 4 + 4);
        if (y1 > y2) [x1, y1, x2, y2] = [x2, y2, x1, y1];
        const dy = y2 - y1, dx = x2 - x1;
        if (dy <= 0 || Math.abs(dx) / dy > SIDE_SLANT) continue;
        const slope = dx / dy;
        cand.push({ x1, y1, y2, slope, len: dy, mid: x1 + slope * (h / 2 - y1) });
      }
      // The longest pair of near-parallel lines far enough apart; text strokes are too short to count.
      let best = null, bestLen = 0;
      for (const l of cand) {
        for (const r of cand) {
          if (r.mid - l.mid < SIDE_MIN_GAP * w || Math.abs(l.slope - r.slope) > SIDE_PARALLEL) continue;
          if (l.len + r.len > bestLen) { best = [l, r]; bestLen = l.len + r.len; }
        }
      }
      if (!best) return null;
      const [l, r] = best;
      let top = Math.min(l.y1, r.y1), bottom = Math.max(l.y2, r.y2);
      if (top < SIDE_TO_EDGE * h) top = 0;
      if (bottom > (1 - SIDE_TO_EDGE) * h) bottom = h - 1;
      const at = (s, y) => ({ x: Math.min(w - 1, Math.max(0, s.x1 + s.slope * (y - s.y1))), y });
      return [at(l, top), at(r, top), at(r, bottom), at(l, bottom)];
    } catch (e) {
      return null;
    } finally {
      lines.delete();
    }
  }

  /** Axis-aligned bounding box of one or more quads, in whole pixels within w × h. */
  function boxOf(quads, w, h) {
    const pts = quads.flat();
    return {
      x0: Math.max(0, Math.floor(Math.min(...pts.map((p) => p.x)))), y0: Math.max(0, Math.floor(Math.min(...pts.map((p) => p.y)))),
      x1: Math.min(w, Math.ceil(Math.max(...pts.map((p) => p.x)))), y1: Math.min(h, Math.ceil(Math.max(...pts.map((p) => p.y)))),
    };
  }

  function meanIn(gray, w, box) {
    let sum = 0, n = 0;
    for (let y = box.y0; y < box.y1; y += 2) for (let x = box.x0; x < box.x1; x += 2) { sum += gray[y * w + x]; n++; }
    return n ? sum / n : 0;
  }

  /**
   * Whether an outline looks like a receipt: not a hair-thin strip, and paper-bright — at least as bright as the
   * frame overall (receipts are the lightest thing in view; a dark screen or doorway in the background isn't).
   */
  function paperLike(quad, gray, w, h, frameMean) {
    const d = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
    const s1 = Math.max(d(quad[0], quad[1]), d(quad[3], quad[2])), s2 = Math.max(d(quad[0], quad[3]), d(quad[1], quad[2]));
    if (Math.max(s1, s2) / Math.max(1, Math.min(s1, s2)) > MAX_ASPECT) return false;
    return meanIn(gray, w, boxOf([quad], w, h)) >= Math.max(90, frameMean);
  }

  /**
   * The receipts in an RGBA ImageData, in its pixel coordinates: { quads, sides }. quads: every receipt-shaped
   * outline, biggest first (two receipts side by side give two). sides: none closed, so the quad is just one
   * receipt's left and right edges (its top or bottom is out of frame or blends in). Null if nothing was found.
   */
  function findQuads(cv, img, gray) {
    const mats = [];
    const keep = (m) => { mats.push(m); return m; };
    try {
      const src = keep(cv.matFromImageData(img));
      const blurred = keep(new cv.Mat());
      cv.cvtColor(src, blurred, cv.COLOR_RGBA2GRAY);
      cv.GaussianBlur(blurred, blurred, new cv.Size(5, 5), 0);
      const edges = keep(new cv.Mat());
      cv.Canny(blurred, edges, 50, 150);
      // Softer edges for the side search: white paper on a light table is low contrast.
      const soft = keep(new cv.Mat());
      cv.Canny(blurred, soft, 25, 75);
      const kernel = keep(cv.Mat.ones(3, 3, cv.CV_8U));
      cv.dilate(edges, edges, kernel);
      const contours = keep(new cv.MatVector());
      const hierarchy = keep(new cv.Mat());
      cv.findContours(edges, contours, hierarchy, cv.RETR_EXTERNAL, cv.CHAIN_APPROX_SIMPLE);

      const frameArea = img.width * img.height;
      const frameMean = meanIn(gray, img.width, { x0: 0, y0: 0, x1: img.width, y1: img.height });
      const found = [];
      for (let i = 0; i < contours.size(); i++) {
        const c = contours.get(i);
        const area = cv.contourArea(c);
        if (area < MIN_AREA * frameArea || area > MAX_AREA * frameArea) { c.delete(); continue; }
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
        if (!pts) continue;
        const quad = order(pts.map((p) => ({ x: p.x, y: p.y })));
        if (paperLike(quad, gray, img.width, img.height, frameMean)) found.push({ quad, area });
      }
      if (found.length) {
        found.sort((a, b) => b.area - a.area);
        return { quads: found.slice(0, MAX_RECEIPTS).map((f) => f.quad), sides: false };
      }
      // Between the two side lines there must be paper too (not the edges of a dark screen or doorway).
      const sides = findSides(cv, soft, img.width, img.height);
      return sides && meanIn(gray, img.width, boxOf([sides], img.width, img.height)) >= Math.max(90, frameMean)
        ? { quads: [sides], sides: true } : null;
    } catch (e) {
      return null;
    } finally {
      mats.forEach((m) => m.delete());
    }
  }

  /**
   * One tracker per camera session; keeps the recent outlines to tell when the receipts are held still.
   * check(img, scale, cv) takes a downscaled RGBA frame (scale = its size ÷ the video's) and returns
   * { quads: every receipt outline (video pixels), left to right | [], quad: the biggest one | null,
   *   sides: only one receipt's left and right edges were found, light: 'ok'|'dark'|'glare', sharp, stable,
   *   outline: whether OpenCV ran }.
   */
  function createTracker() {
    let recent = [];
    return {
      check(img, scale, cv) {
        const w = img.width, h = img.height;
        const gray = toGray(img);
        const found = cv ? findQuads(cv, img, gray) : null;
        const quads = found ? found.quads : [];
        const biggest = quads[0] || null;
        // Light and sharpness over all the receipts in view (or the middle of the frame when none was found).
        const box = quads.length ? boxOf(quads, w, h)
          : { x0: Math.round(w * 0.15), y0: Math.round(h * 0.15), x1: Math.round(w * 0.85), y1: Math.round(h * 0.85) };
        const sharp = sharpnessOf(gray, w, h, box) >= SHARP_MIN;
        // Light is judged on the receipts themselves when found: a dark table around white paper is fine.
        const light = lightOf(quads.length ? cropGray(gray, w, box) : gray);

        // Left to right, so the same receipt keeps the same place from frame to frame.
        const centre = (q) => q.reduce((a, p) => a + p.x, 0) / 4;
        const sorted = [...quads].sort((a, b) => centre(a) - centre(b));
        recent = sorted.length ? [...recent, sorted].slice(-STABLE_FRAMES) : [];
        const diag = Math.hypot(w, h);
        const stable = recent.length === STABLE_FRAMES
          && recent.every((frame) => frame.length === sorted.length
            && frame.every((q, i) => q.every((p, k) => Math.hypot(p.x - sorted[i][k].x, p.y - sorted[i][k].y) < STABLE_DRIFT * diag)));

        const toVideo = (q) => q.map((p) => ({ x: p.x / scale, y: p.y / scale }));
        return {
          quads: sorted.map(toVideo), quad: biggest && toVideo(biggest), sides: !!(found && found.sides),
          light, sharp, stable, outline: !!cv,
        };
      },
    };
  }

  // Allowance around a found receipt when cropping, so none of the paper is cut off: the outline comes from a
  // downscaled frame and can sit a few pixels inside a curled or shadowed edge. Each side moves out by this share of
  // the receipt's own size, and at least CROP_PAD_MIN pixels of the full-size photo.
  const CROP_PAD = 0.06;
  const CROP_PAD_MIN = 28;

  /** quad grown outward on every side by the crop allowance, kept inside a w × h frame. */
  function padQuad(quad, w, h) {
    const d = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
    const qw = Math.max(d(quad[0], quad[1]), d(quad[3], quad[2])), qh = Math.max(d(quad[0], quad[3]), d(quad[1], quad[2]));
    const padX = Math.max(CROP_PAD * qw, CROP_PAD_MIN), padY = Math.max(CROP_PAD * qh, CROP_PAD_MIN);
    const cx = quad.reduce((a, p) => a + p.x, 0) / 4, cy = quad.reduce((a, p) => a + p.y, 0) / 4;
    return quad.map((p) => ({
      x: Math.min(w, Math.max(0, p.x + Math.sign(p.x - cx) * padX)),
      y: Math.min(h, Math.max(0, p.y + Math.sign(p.y - cy) * padY)),
    }));
  }

  /** Flattened crop of the receipt inside quad (pixels of the full-size RGBA frame img), as ImageData; null if it can't. */
  function warpImage(cv, img, quad) {
    const q = padQuad(quad, img.width, img.height);
    const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
    const w = Math.round(Math.max(dist(q[0], q[1]), dist(q[3], q[2])));
    const h = Math.round(Math.max(dist(q[0], q[3]), dist(q[1], q[2])));
    if (w < 100 || h < 100) return null;

    const mats = [];
    const keep = (m) => { mats.push(m); return m; };
    try {
      const src = keep(cv.matFromImageData(img));
      const from = keep(cv.matFromArray(4, 1, cv.CV_32FC2, q.flatMap((p) => [p.x, p.y])));
      const to = keep(cv.matFromArray(4, 1, cv.CV_32FC2, [0, 0, w, 0, w, h, 0, h]));
      const m = keep(cv.getPerspectiveTransform(from, to));
      const dst = keep(new cv.Mat());
      cv.warpPerspective(src, dst, m, new cv.Size(w, h), cv.INTER_LINEAR, cv.BORDER_REPLICATE, new cv.Scalar());
      return new ImageData(new Uint8ClampedArray(dst.data), w, h);
    } catch (e) {
      return null;
    } finally {
      mats.forEach((mat) => mat.delete());
    }
  }

  global.SetloDocscanCore = { EDGE, SHARP_MIN, toGray, lightOf, sharpnessOf, boxOf, padQuad, createTracker, warpImage };
})(self);
