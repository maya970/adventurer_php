/**
 * 各独立页共用：登录检查、toast、HTML 转义、顶栏 meta。
 * 页面脚本加载顺序：api-client.js → rpg-page.js →（可选 town-nav / town-ui）→ *-page.js
 */
(function (global) {
  function loginUrl() {
    return typeof global.RPG_LOGIN_URL === 'string' && global.RPG_LOGIN_URL
      ? global.RPG_LOGIN_URL
      : 'login.html';
  }

  function toast(msg, ms) {
    const t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg == null ? '' : String(msg);
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), ms == null ? 2200 : ms);
  }

  function esc(t) {
    const d = document.createElement('div');
    d.textContent = t == null ? '' : String(t);
    return d.innerHTML;
  }

  async function requireLogin() {
    if (typeof global.gameApi !== 'function') {
      throw new Error('缺少 gameApi');
    }
    const s = await global.gameApi('session', {});
    if (!s.logged_in) {
      global.location.href = loginUrl();
      return null;
    }
    return s;
  }

  /** 已登录则执行 fn；未登录已跳转 */
  function onReady(fn) {
    document.addEventListener('DOMContentLoaded', () => {
      requireLogin()
        .then((session) => {
          if (session) return fn(session);
        })
        .catch((e) => toast(String((e && e.message) || e)));
    });
  }

  function renderMetaBar(player) {
    if (!player || !global.PlayerMetaBar) return;
    const el = document.getElementById('player-meta-bar');
    if (el) global.PlayerMetaBar.render(el, player);
  }

  async function loadPlayer() {
    const d = await global.gameApi('player', {});
    return d && d.player ? d : { player: null };
  }

  /** 城镇独立页共用的 state + applyPlayerPayload */
  function createTownShell(extraState) {
    const state = Object.assign({ player: null, inventory: [], username: '' }, extraState || {});
    return {
      state,
      toast,
      applyPlayerPayload(data) {
        if (!data || !data.player) return;
        if (data.username) state.username = data.username;
        state.player = data.player;
        state.inventory = data.inventory || [];
        if ('account' in state) state.account = data.account || null;
      },
    };
  }

  const api = {
    loginUrl,
    toast,
    esc,
    escapeHtml: esc,
    requireLogin,
    onReady,
    renderMetaBar,
    loadPlayer,
    createTownShell,
  };

  global.RpgPage = api;
})(typeof window !== 'undefined' ? window : globalThis);
