/* global gameApi */

(function () {
  const STORAGE_KEY = 'rpg_asset_build';

  function ensureOverlay() {
    let el = document.getElementById('rpg-update-overlay');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'rpg-update-overlay';
    el.className = 'rpg-update-overlay';
    el.setAttribute('aria-live', 'polite');
    el.innerHTML =
      '<div class="rpg-update-card">' +
      '<p class="rpg-update-title">正在更新资源</p>' +
      '<p class="rpg-update-hint">校验码已变更，正在清理缓存并刷新页面…</p>' +
      '<div class="rpg-update-bar-wrap" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">' +
      '<div id="rpg-update-fill" class="rpg-update-fill"></div></div>' +
      '<p id="rpg-update-pct" class="rpg-update-pct">0%</p></div>';
    document.body.appendChild(el);
    return el;
  }

  function setProgress(pct) {
    const fill = document.getElementById('rpg-update-fill');
    const lab = document.getElementById('rpg-update-pct');
    const w = document.querySelector('.rpg-update-bar-wrap');
    const n = Math.max(0, Math.min(100, pct | 0));
    if (fill) fill.style.width = n + '%';
    if (lab) lab.textContent = n + '%';
    if (w) w.setAttribute('aria-valuenow', String(n));
  }

  function animateProgress() {
    ensureOverlay().classList.add('open');
    let t = 0;
    return new Promise((resolve) => {
      const id = setInterval(() => {
        t += 8;
        if (t >= 100) {
          clearInterval(id);
          setProgress(100);
          resolve();
          return;
        }
        setProgress(t);
      }, 45);
    });
  }

  async function run() {
    try {
      const d = await gameApi('build_info', {});
      const server = d && d.build != null ? String(d.build) : '';
      if (!server) return;
      let local = '';
      try {
        local = localStorage.getItem(STORAGE_KEY) || '';
      } catch (_) {
        local = '';
      }
      if (local === '') {
        try {
          localStorage.setItem(STORAGE_KEY, server);
        } catch (_) {
          /* ignore */
        }
        return;
      }
      if (local === server) return;

      await animateProgress();

      try {
        if ('caches' in window) {
          const keys = await caches.keys();
          await Promise.all(keys.map((k) => caches.delete(k)));
        }
      } catch (_) {
        /* ignore */
      }

      try {
        localStorage.clear();
      } catch (_) {
        /* ignore */
      }
      try {
        localStorage.setItem(STORAGE_KEY, server);
      } catch (_) {
        /* ignore */
      }

      await new Promise((r) => setTimeout(r, 200));
      location.reload();
    } catch (_) {
      /* 离线或接口失败时不阻断页面 */
    }
  }

  window.RpgBuildCheck = { run };
})();
