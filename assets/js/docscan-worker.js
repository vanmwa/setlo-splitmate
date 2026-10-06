// Camera-frame analysis off the page's main thread: OpenCV.js (~10 MB) compiles here, and every frame's outline,
// light and sharpness check runs here, so the page (and its Capture button) stays responsive.
importScripts('docscan-core.js' + self.location.search); // same ?v= as this worker, so a deploy never mixes old and new

const OPENCV_URL = 'https://cdn.jsdelivr.net/npm/@techstark/opencv-js@4.12.0-release.1/dist/opencv.js';
const OPENCV_SRI = 'sha384-i8A4fJEsRcMFMyEEDNri/2MR12DhkFLlUF+9oxUrxs6prIcRj7YtWAQ1OJ+iE0C7';
const core = self.SetloDocscanCore;

let cv = null;
let tracker = core.createTracker();

/**
 * Fetch OpenCV, check it against the pinned hash (importScripts has no integrity option), then run it.
 * Resolves { cv } or null. The module is wrapped on purpose: it has its own .then, so returning it bare from an
 * async function makes the promise wait on that .then, which never settles, and OpenCV would never be used.
 */
async function loadOpenCV() {
  try {
    const res = await fetch(OPENCV_URL, { mode: 'cors', credentials: 'omit' });
    if (!res.ok) return null;
    const bytes = await res.arrayBuffer();
    const digest = new Uint8Array(await crypto.subtle.digest('SHA-384', bytes));
    const b64 = btoa(String.fromCharCode(...digest));
    if ('sha384-' + b64 !== OPENCV_SRI) return null;
    const url = URL.createObjectURL(new Blob([bytes], { type: 'text/javascript' }));
    importScripts(url);
    URL.revokeObjectURL(url);
    // self.cv is an Emscripten module whose .then never settles when awaited, so wait for its classes instead.
    const start = Date.now();
    while (!(self.cv && self.cv.Mat)) {
      if (Date.now() - start > 30000) return null;
      await new Promise((r) => setTimeout(r, 100));
    }
    return { cv: self.cv };
  } catch (e) {
    return null;
  }
}

loadOpenCV().then((loaded) => {
  cv = loaded ? loaded.cv : null;
  postMessage({ type: 'cv', ready: !!cv });
});

onmessage = (e) => {
  const msg = e.data;
  if (msg.type === 'reset') {
    tracker = core.createTracker();
  } else if (msg.type === 'frame') {
    postMessage({ type: 'frame', id: msg.id, result: tracker.check(msg.img, msg.scale, cv) });
  } else if (msg.type === 'warp') {
    const out = cv && msg.quad ? core.warpImage(cv, msg.img, msg.quad) : null;
    postMessage({ type: 'warp', id: msg.id, img: out }, out ? [out.data.buffer] : []);
  }
};
