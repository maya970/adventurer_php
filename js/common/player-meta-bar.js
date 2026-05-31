/* global gameApi */
(function () {
  function esc(t) {
    const d = document.createElement('span');
    d.textContent = t == null ? '' : String(t);
    return d.innerHTML;
  }

  function renderPlayerMetaBar(el, player) {
    if (!el) return;
    const pf = (player && player.profession) || {};
    const prof = pf.label || '无职业';
    const crystals = Math.max(0, Number(player && player.sunbeam_crystal_count) || 0);
    el.innerHTML =
      '<span class="pmeta-chip pmeta-prof">职业 ' +
      esc(prof) +
      '</span><span class="pmeta-chip pmeta-crystal">曦光晶屑 ' +
      crystals +
      '</span>';
  }

  function mount(id) {
    let el = document.getElementById(id);
    if (!el) {
      el = document.createElement('div');
      el.id = id;
      el.className = 'player-meta-bar';
    }
    return el;
  }

  async function refresh(id) {
    const el = mount(id);
    try {
      const d = await gameApi('player', {});
      if (d && d.player) renderPlayerMetaBar(el, d.player);
    } catch (_) {
      /* ignore */
    }
    return el;
  }

  window.PlayerMetaBar = {
    render: renderPlayerMetaBar,
    mount,
    refresh,
    chipsHtml: function (player) {
      const pf = (player && player.profession) || {};
      const prof = esc(pf.label || '无职业');
      const crystals = Math.max(0, Number(player && player.sunbeam_crystal_count) || 0);
      return (
        '<span class="pmeta-chip pmeta-prof">职业 ' +
        prof +
        '</span><span class="pmeta-chip pmeta-crystal">曦光晶屑 ' +
        crystals +
        '</span>'
      );
    },
    statLineHtml: function (player) {
      const pf = (player && player.profession) || {};
      const prof = esc(pf.label || '无职业');
      const crystals = Math.max(0, Number(player && player.sunbeam_crystal_count) || 0);
      return (
        '<div class="stat-line stat-prof-crystal">职业：<strong>' +
        prof +
        '</strong> · 曦光晶屑：<strong>' +
        crystals +
        '</strong></div>'
      );
    },
  };
})();
