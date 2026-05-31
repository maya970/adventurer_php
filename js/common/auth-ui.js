/* global gameApi, ChristmasRPG, TownUI */

(function () {
  function authEl() {
    return document.getElementById('auth-overlay');
  }
  function appEl() {
    return document.getElementById('app-wrap');
  }

  function showAuth(msg) {
    if (typeof window.setRpgBodyView === 'function') window.setRpgBodyView('rpg-view-auth');
    const a = authEl();
    const w = appEl();
    if (a) a.classList.add('open');
    if (w) w.classList.add('hidden');
    const err = document.getElementById('auth-msg');
    if (err) {
      err.textContent = msg || '';
      err.style.display = msg ? 'block' : 'none';
    }
  }

  function hideAuth() {
    document.body.classList.remove('rpg-view-auth');
    const a = authEl();
    const w = appEl();
    if (a) a.classList.remove('open');
    if (w) w.classList.remove('hidden');
  }

  async function trySession() {
    try {
      const d = await gameApi('session', {});
      if (d.logged_in) {
        const redir = typeof window.RPG_AUTH_REDIRECT === 'string' && window.RPG_AUTH_REDIRECT;
        if (redir) {
          location.href = redir;
          return true;
        }
        hideAuth();
        if (window.ChristmasRPG && typeof window.ChristmasRPG.applyPlayerPayload === 'function') {
          window.ChristmasRPG.applyPlayerPayload(d);
          await window.ChristmasRPG.bootstrap();
        }
        return true;
      }
    } catch (_) {
      /* offline */
    }
    showAuth('');
    return false;
  }

  async function doLogin() {
    const u = document.getElementById('auth-user').value.trim();
    const p = document.getElementById('auth-pass').value;
    const err = document.getElementById('auth-msg');
    err.style.display = 'none';
    try {
      const d = await gameApi('login', { username: u, password: p });
      const redir = typeof window.RPG_AUTH_REDIRECT === 'string' && window.RPG_AUTH_REDIRECT;
      if (redir) {
        location.href = redir;
        return;
      }
      hideAuth();
      window.ChristmasRPG.applyPlayerPayload(d);
      await window.ChristmasRPG.bootstrap();
    } catch (e) {
      err.classList.add('auth-error');
      err.textContent = e.message || String(e);
      err.style.display = 'block';
    }
  }

  async function doRegister() {
    const u = document.getElementById('auth-user').value.trim();
    const p = document.getElementById('auth-pass').value;
    const err = document.getElementById('auth-msg');
    err.style.display = 'none';
    try {
      const d = await gameApi('register', { username: u, password: p });
      if (d && d.need_email_verify) {
        err.classList.remove('auth-error');
        err.textContent = d.message || '请查收邮箱完成验证；也可直接登录游戏。';
        err.style.display = 'block';
        return;
      }
      const redir = typeof window.RPG_AUTH_REDIRECT === 'string' && window.RPG_AUTH_REDIRECT;
      if (redir) {
        location.href = redir;
        return;
      }
      hideAuth();
      if (window.ChristmasRPG && typeof window.ChristmasRPG.applyPlayerPayload === 'function') {
        window.ChristmasRPG.applyPlayerPayload(d);
        await window.ChristmasRPG.bootstrap();
      }
    } catch (e) {
      err.classList.add('auth-error');
      err.textContent = e.message || String(e);
      err.style.display = 'block';
    }
  }

  async function doResendVerify() {
    const u = document.getElementById('auth-user').value.trim();
    const p = document.getElementById('auth-pass').value;
    const err = document.getElementById('auth-msg');
    err.style.display = 'none';
    try {
      const d = await gameApi('resend_verify_email', { username: u, password: p });
      err.textContent = (d && d.message) || '已发送，请查收邮箱。';
      err.style.display = 'block';
      err.classList.remove('auth-error');
    } catch (e) {
      err.classList.add('auth-error');
      err.textContent = e.message || String(e);
      err.style.display = 'block';
    }
  }

  document.addEventListener('DOMContentLoaded', async () => {
    const urlMsg = new URLSearchParams(window.location.search).get('msg');
    const loginHint = urlMsg ? decodeURIComponent(urlMsg.replace(/\+/g, ' ')) : '';
    if (window.RpgBuildCheck && typeof window.RpgBuildCheck.run === 'function') {
      await window.RpgBuildCheck.run();
    }
    const btnLogin = document.getElementById('btn-auth-login');
    const btnReg = document.getElementById('btn-auth-register');
    const btnResend = document.getElementById('btn-resend-verify');
    if (btnLogin) btnLogin.addEventListener('click', () => doLogin());
    if (btnReg) btnReg.addEventListener('click', () => doRegister());
    if (btnResend) btnResend.addEventListener('click', () => doResendVerify());
    const lo = document.getElementById('btn-logout');
    if (lo) {
      lo.addEventListener('click', async () => {
        try {
          await gameApi('logout', {});
        } catch (_) {
          /* ignore */
        }
        location.reload();
      });
    }
    window.addEventListener('rpg:unauthorized', () => {
      showAuth('登录已过期，请重新登录');
      setTimeout(() => location.reload(), 400);
    });
    const sessionOk = await trySession();
    if (!sessionOk && loginHint) {
      showAuth(loginHint);
    }
  });

  window.AuthUI = { trySession, showAuth, hideAuth };
})();
