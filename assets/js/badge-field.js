// The user's earned badges, repeated and scattered over a jittered grid, drifting behind the page (see badge-bg.css).
// Added to <body> (not #app) so it never fights Vue's rendering. Decoration only: any failure just shows nothing.
(function () {
  const rnd = (min, max) => min + Math.random() * (max - min);

  function build(badges) {
    const big = window.matchMedia('(min-width: 1024px)').matches;
    const cols = big ? 6 : 4, rows = 4;
    const field = document.createElement('div');
    field.className = 'badge-field';
    field.setAttribute('aria-hidden', 'true');
    for (let n = 0; n < cols * rows; n++) {
      const img = document.createElement('img');
      const size = Math.round(rnd(44, 86));
      img.src = badges[n % badges.length].image;
      img.alt = '';
      img.draggable = false;
      img.style.cssText = `left:${(((n % cols) + 0.5) / cols * 100 + rnd(-6, 6)).toFixed(1)}%;`
        + `top:${(((Math.floor(n / cols)) + 0.5) / rows * 100 + rnd(-8, 8)).toFixed(1)}%;`
        + `width:${size}px;height:${size}px;opacity:${rnd(0.45, 0.8).toFixed(2)};`
        + `--dx:${Math.round(rnd(25, 60) * (Math.random() < 0.5 ? -1 : 1))}px;--dy:${Math.round(rnd(20, 50))}px;`
        + `--dur:${rnd(9, 16).toFixed(1)}s;--delay:-${rnd(0, 12).toFixed(1)}s;`;
      field.appendChild(img);
    }
    document.body.appendChild(field);
  }

  document.addEventListener('DOMContentLoaded', async () => {
    try {
      const r = await api.get('achievements.php');
      const badges = r.badges.filter((a) => !a.hidden);
      if (badges.length) build(badges);
    } catch (e) { /* non-critical */ }
  });
})();
