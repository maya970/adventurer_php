/* global window */
(function (global) {
  let catalog = { skills: [], byKey: {}, byAction: {} };

  function indexSkills(list) {
    catalog.skills = Array.isArray(list) ? list : [];
    catalog.byKey = {};
    catalog.byAction = {};
    catalog.skills.forEach((row) => {
      if (!row || !row.skill_key) return;
      catalog.byKey[row.skill_key] = row;
      if (row.action_key) catalog.byAction[row.action_key] = row;
    });
  }

  async function loadSkillsCatalog(url) {
    try {
      const res = await fetch(url || 'data/skills.json', { cache: 'no-cache' });
      if (!res.ok) return catalog;
      const j = await res.json();
      indexSkills(j.skills || []);
      catalog.dungeon_drop = j.dungeon_drop || {};
    } catch (_) {
      /* 离线或本地 file:// 时静默失败 */
    }
    return catalog;
  }

  function skillFromAction(actionKey) {
    const k = String(actionKey || '');
    if (catalog.byAction[k]) return catalog.byAction[k];
    if (k.indexOf('skill_') === 0) {
      const sk = k.slice(6);
      return catalog.byKey[sk] || null;
    }
    return null;
  }

  function playSkillVfx(actionKey, opts) {
    const row = skillFromAction(actionKey);
    if (!row || !row.vfx) return;
    const vfx = row.vfx;
    const flash = document.getElementById('flash');
    if (flash) {
      flash.className = 'flash ' + String(vfx.flash_class || 'skill-vfx-arcane');
      flash.style.setProperty('--skill-vfx-color', String(vfx.color || '#7c4dff'));
      flash.classList.add('on');
      const shake = Number(vfx.shake) || 0;
      if (shake > 0 && document.body) {
        document.body.style.animation = 'skill-shake ' + Math.max(0.08, shake) + 's ease';
        setTimeout(() => {
          document.body.style.animation = '';
        }, 280);
      }
      setTimeout(() => flash.classList.remove('on'), 220);
    }
    const logEl = document.getElementById('local-combat-log');
    if (logEl && vfx.log) {
      const line = document.createElement('div');
      line.className = 'skill-vfx-log';
      line.textContent = (vfx.icon ? vfx.icon + ' ' : '') + String(vfx.log);
      logEl.appendChild(line);
      while (logEl.children.length > 40) logEl.removeChild(logEl.firstChild);
    }
    if (opts && typeof opts.toast === 'function' && vfx.log) {
      opts.toast(String(vfx.icon || '✨') + ' ' + String(vfx.log));
    }
  }

  global.RpgSkillVfx = {
    loadSkillsCatalog,
    skillFromAction,
    playSkillVfx,
    get catalog() {
      return catalog;
    },
  };
})(typeof window !== 'undefined' ? window : globalThis);
