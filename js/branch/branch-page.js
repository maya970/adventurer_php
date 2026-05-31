/* global gameApi, PlayerMetaBar, RpgPage */
(function () {
  const { toast, onReady } = RpgPage;
  const POTION_KEYS = ['healing_potion', 'greater_healing_potion'];
  const RECENT_PATH = 5;
  const TAG_DIV = '</' + 'div>';

  let run = null;
  let runId = 0;
  let branchHistory = [];
  let currentResolved = false;
  let loadoutItems = [];
  let resolving = false;
  let eventOutcomeHtml = '';
  let pendingReturnTown = false;

  function bagRowCount(row) {
    if (!row) return 0;
    if (Array.isArray(row.stacks)) return row.stacks.length;
    return Number(row.count) || 0;
  }

  function showBranchPanel(panelId) {
    document.querySelectorAll('.sb-branch-panel').forEach((el) => {
      const on = el.id === panelId;
      el.classList.toggle('sb-panel-hidden', !on);
      el.classList.toggle('sb-panel-active', on);
    });
  }

  function showLoadout() {
    showBranchPanel('sb-loadout-panel');
  }

  function showRun() {
    showBranchPanel('sb-run-panel');
  }

  function showLoading(msg) {
    const el = document.getElementById('sb-loading-panel');
    if (el) {
      const p = el.querySelector('.panel-intro');
      if (p) p.textContent = msg || '正在加载…';
    }
    showBranchPanel('sb-loading-panel');
  }

  function showFatalError(msg) {
    showLoading(msg || '加载失败，请返回主城重试');
    const el = document.getElementById('sb-loading-panel');
    if (el && !el.querySelector('.sb-retry-home')) {
      const a = document.createElement('a');
      a.href = 'town.html';
      a.className = 'town-dg-btn town-dg-btn-secondary sb-retry-home';
      a.textContent = '回主城';
      a.style.marginTop = '12px';
      a.style.display = 'inline-block';
      el.appendChild(a);
    }
  }

  function statCheckHint(sc) {
    if (!sc || !sc.stat) return '';
    const label = sc.label || sc.stat;
    return ' · 检定 ' + label + ' DC' + (sc.dc || '?');
  }

  function doorDisplayLabel(door) {
    if (!door) return '门';
    const j = Number(door.floor_jump);
    if (door.door_type === 'surge') {
      const n = Number.isFinite(j) ? j : 0;
      return '捷径 +' + n;
    }
    const n = Number.isFinite(j) ? j : 0;
    if (n > 0) return '普通门 +' + n;
    if (n < 0) return '普通门 ' + n;
    return '普通门 ±0';
  }

  function doorOptions(r) {
    if (!r) return [];
    if (Array.isArray(r.doors) && r.doors.length) return r.doors;
    const opts = r.options;
    if (Array.isArray(opts)) return opts.filter((x) => x && x.option_index != null);
    if (opts && typeof opts === 'object') {
      return Object.keys(opts)
        .filter((k) => k !== '_meta' && k !== 'doors')
        .map((k) => opts[k])
        .filter((x) => x && x.option_index != null)
        .sort((a, b) => (a.option_index || 0) - (b.option_index || 0));
    }
    return [];
  }

  function photoImgCandidates(num) {
    const n = String(Math.max(1, Number(num) || 1)).padStart(4, '0');
    const exts = ['webp', 'png', 'jpg', 'gif'];
    const out = [];
    exts.forEach((e) => out.push('img/photo/' + n + '.' + e));
    exts.forEach((e) => out.push('img/items/' + n + '.' + e));
    return out;
  }

  function bindPhotoImg(imgEl, num) {
    if (!imgEl) return;
    const list = photoImgCandidates(num);
    let i = 0;
    const tryNext = () => {
      if (i >= list.length) {
        imgEl.removeAttribute('src');
        imgEl.alt = '遭遇插图';
        return;
      }
      const src = list[i++];
      imgEl.onload = () => {
        imgEl.onerror = null;
      };
      imgEl.onerror = tryNext;
      imgEl.src = src;
    };
    tryNext();
  }

  function renderSkills() {
    const el = document.getElementById('sb-skills');
    if (!el || !run) return;
    const learned = Array.isArray(run.skills_learned) ? run.skills_learned : [];
    const opts = Array.isArray(run.resolve_options) ? run.resolve_options : [];
    const skillStrategies = new Set(
      opts.filter((o) => String(o.strategy || '').indexOf('skill:') === 0).map((o) => String(o.strategy))
    );
    if (!learned.length) {
      el.innerHTML = '<p class="sb-skills-empty">暂无已学技能（可在主城技能页学习）。</p>';
      return;
    }
    el.innerHTML =
      '<p class="sb-skills-title">已学技能</p><div class="sb-skills-btns">' +
      learned
        .map((sk) => {
          const key = String(sk.skill_key || '');
          const strat = 'skill:' + key;
          const canUse = !currentResolved && skillStrategies.has(strat);
          const hint = canUse
            ? ''
            : currentResolved
            ? ' title="本层已结算，选门后继续"'
            : ' title="当前遭遇下强度不足或类型不符"';
          return (
            '<button type="button" class="town-dg-btn sb-skill-btn' +
            (canUse ? '' : ' sb-skill-btn-muted') +
            '" data-strategy="' +
            strat +
            '"' +
            hint +
            (canUse ? '' : ' disabled') +
            '>' +
            String(sk.label || key) +
            ' Lv' +
            (sk.level || 1) +
            '</button>'
          );
        })
        .join('') +
      '</div>';
    el.querySelectorAll('.sb-skill-btn:not([disabled])').forEach((btn) => {
      btn.onclick = () => {
        const st = btn.getAttribute('data-strategy') || '';
        resolveRoom(st).catch((e) => toast(String(e.message || e)));
      };
    });
  }

  function applyRunState(rs) {
    if (!rs || !run) return;
    run.run_state = rs;
    if (typeof rs.resolved === 'boolean') currentResolved = rs.resolved;
    renderStatus();
    renderBag();
    renderSkills();
  }

  function renderStatus() {
    const el = document.getElementById('sb-status');
    if (!el || !run) return;
    const st = run.run_state || {};
    const stats = st.stats || {};
    const hp = Number(st.hp) || 0;
    const hpMax = Number(st.hp_max) || 1;
    const eff = Number(run.effective_danger) || Number(run.danger) || 10;
    const mult = Number(run.reward_mult) || 1;
    el.innerHTML =
      '<div class="sb-stat-row"><strong>生命</strong> ' +
      hp +
      ' / ' +
      hpMax +
      TAG_DIV +
      '<div class="sb-stat-row"><strong>属性</strong> 力' +
      (stats.str ?? '?') +
      ' 敏' +
      (stats.dex ?? '?') +
      ' 体' +
      (stats.con ?? '?') +
      ' 智' +
      (stats.int ?? '?') +
      ' 感' +
      (stats.wis ?? '?') +
      ' AC' +
      (stats.ac ?? '?') +
      TAG_DIV +
      '<div class="sb-stat-row"><strong>当前强度</strong> ' +
      eff +
      ' · 奖励倍率 ×' +
      mult.toFixed(1) +
      statCheckHint(run.stat_check) +
      TAG_DIV;
  }

  function renderBag() {
    const el = document.getElementById('sb-bag');
    if (!el) return;
    const bag = (run && run.run_state && Array.isArray(run.run_state.bag) ? run.run_state.bag : []) || [];
    if (!bag.length) {
      el.innerHTML =
        '<div class="sb-bag-empty">行囊为空。可在杂货铺购买消耗品后，<strong>放弃并重选行囊</strong>重新带入。' + TAG_DIV;
      return;
    }
    el.innerHTML = bag
      .map((x) => {
        const key = String(x.item_key || '');
        const label = String(x.label || key);
        const count = bagRowCount(x);
        const isPotion = POTION_KEYS.includes(key);
        let action = '';
        if (isPotion) {
          action = '<button type="button" class="town-dg-btn sb-bag-use" data-key="' + key + '">使用</button>';
        } else {
          action = '<span class="sb-bag-item-hint">遭遇区可选</span>';
        }
        return (
          '<div class="sb-bag-item">' +
          '<span class="sb-bag-item-label">' +
          label +
          '</span>' +
          '<span class="sb-bag-item-count">×' +
          count +
          '</span>' +
          action +
          TAG_DIV
        );
      })
      .join('');
    el.querySelectorAll('.sb-bag-use').forEach((btn) => {
      btn.onclick = () => {
        const key = btn.getAttribute('data-key');
        if (!key) return;
        useBagItem(key).catch((e) => toast(String(e.message || e)));
      };
    });
  }

  async function useBagItem(itemKey) {
    const rs = await gameApi('branch_dungeon_use_item', { run_id: runId, item_key: itemKey });
    if (!rs.ok) throw new Error(rs.error || '使用失败');
    if (rs.run_state) applyRunState(rs.run_state);
    toast(String(rs.message || '已使用'));
  }

  function renderLoadoutList() {
    const el = document.getElementById('sb-loadout-list');
    if (!el) return;
    if (!loadoutItems.length) {
      el.innerHTML = '<p class="panel-intro">背包中没有可带入的消耗品。可先空行囊出发，或去杂货铺购买。</p>';
      return;
    }
    el.innerHTML = loadoutItems
      .map((it) => {
        const key = String(it.item_key || '');
        const avail = Math.max(0, Number(it.available) || 0);
        return (
          '<div class="sb-loadout-row">' +
          '<span class="sb-loadout-name">' +
          String(it.label || key) +
          '</span>' +
          '<span class="sb-loadout-meta">背包 ' +
          avail +
          ' 个</span>' +
          '<label class="sb-loadout-qty-label">带入 <input type="number" class="sb-loadout-qty" data-key="' +
          key +
          '" min="0" max="' +
          avail +
          '" value="0" /></label>' +
          TAG_DIV
        );
      })
      .join('');
  }

  async function fetchLoadout() {
    const rs = await gameApi('branch_dungeon_loadout', {});
    if (!rs.ok) throw new Error(rs.error || '无法读取背包');
    loadoutItems = Array.isArray(rs.items) ? rs.items : [];
    renderLoadoutList();
  }

  function selectedLoadoutCounts() {
    const counts = {};
    document.querySelectorAll('.sb-loadout-qty').forEach((inp) => {
      const key = String(inp.getAttribute('data-key') || '');
      const n = Math.max(0, parseInt(inp.value, 10) || 0);
      if (key && n > 0) counts[key] = n;
    });
    return counts;
  }

  async function startWithLoadout(carryCounts) {
    const counts = carryCounts || {};
    const total = Object.values(counts).reduce((s, n) => s + (Number(n) || 0), 0);
    const rs = await gameApi('branch_dungeon_start', { carry_counts: counts });
    if (!rs.ok) throw new Error(rs.error || '无法开始冒险');
    run = rs.run || null;
    runId = Number(run && run.id) || 0;
    branchHistory = [];
    currentResolved = !!(run && (run.resolved || (run.run_state && run.run_state.resolved)));
    showRun();
    toast(total > 0 ? '已带入 ' + total + ' 件物品，冒险开始' : '空行囊出发');
    renderRun();
  }

  function nodeHtml(label, kind, active) {
    const cls = active ? 'sb-path-node sb-path-node-active' : 'sb-path-node';
    return (
      '<div class="' +
      cls +
      '"><span class="sb-path-node-label">' +
      label +
      '</span><span class="sb-path-node-kind">' +
      (kind || '未知') +
      '</span></div>'
    );
  }

  function renderRecentPath() {
    const grid = document.getElementById('sb-grid');
    if (!grid || !run) return;
    const recent = branchHistory.slice(-RECENT_PATH);
    const parts = [];
    if (branchHistory.length > RECENT_PATH) {
      parts.push('<div class="sb-path-ellipsis">…</div>');
    }
    recent.forEach((x, i) => {
      parts.push(nodeHtml(String(branchHistory.length - recent.length + i + 1), x.kind, false));
      if (i < recent.length - 1) parts.push('<div class="sb-path-arrow">→</div>');
    });
    if (recent.length) parts.push('<div class="sb-path-arrow">→</div>');
    parts.push(nodeHtml('当前', String(run.room_kind || '未知'), true));
    grid.innerHTML = parts.join('');
    const scroll = document.getElementById('sb-path-scroll');
    if (scroll) scroll.scrollLeft = scroll.scrollWidth;
  }

  function renderRecentLog() {
    const el = document.getElementById('sb-log');
    if (!el) return;
    const logs = run && Array.isArray(run.recent_log) ? run.recent_log : [];
    if (!logs.length) {
      el.innerHTML = '<p class="panel-intro sb-log-empty">暂无日志，处理遭遇后将显示最近记录。</p>';
      return;
    }
    el.innerHTML = logs
      .map((row) => {
        const fx = Array.isArray(row.effects) ? row.effects.join('；') : '';
        return (
          '<div class="sb-log-item">' +
          '<div class="sb-log-head">第 ' +
          (row.depth ?? '?') +
          ' 层 · ' +
          String(row.title || row.kind || '遭遇') +
          TAG_DIV +
          '<div class="sb-log-body">' +
          fx +
          TAG_DIV +
          TAG_DIV
        );
      })
      .join('');
    const scroll = document.getElementById('sb-log-scroll');
    if (scroll) scroll.scrollTop = 0;
  }

  function renderEventPanel() {
    const textEl = document.getElementById('sb-event-text');
    const actionsEl = document.getElementById('sb-event-actions');
    const imgEl = document.getElementById('sb-event-img');
    if (!run || !textEl || !actionsEl) return;

    const enc = run.encounter || {};
    const kindLabel = enc.kind_label || run.room_kind || '遭遇';
    bindPhotoImg(imgEl, enc.image_num || 1);
    if (imgEl) {
      const path = enc.image_path || 'img/photo/' + String(enc.image_num || 1).padStart(4, '0');
      imgEl.alt = String(enc.title || kindLabel) + '（' + path + '）';
    }
    const outcomeBlock = eventOutcomeHtml
      ? '<div class="sb-event-outcome">' + eventOutcomeHtml + '</div>'
      : '';
    textEl.innerHTML =
      '<h3 class="sb-event-title">' +
      String(enc.title || kindLabel) +
      '</h3>' +
      '<p class="sb-event-kind">' +
      kindLabel +
      statCheckHint(run.stat_check) +
      '</p>' +
      '<p class="sb-event-story">' +
      String(enc.story || '前方出现了未知状况，请做出选择。') +
      '</p>' +
      outcomeBlock;

    actionsEl.innerHTML = '';
    if (pendingReturnTown) {
      actionsEl.innerHTML =
        '<button type="button" class="town-dg-btn town-dg-btn-primary sb-return-town">返回主城</button>';
      const rb = actionsEl.querySelector('.sb-return-town');
      if (rb) rb.onclick = () => (location.href = 'town.html');
      return;
    }
    if (currentResolved) {
      actionsEl.innerHTML =
        '<p class="sb-event-resolved">本层已处理完毕，请选择一扇门继续（按钮上会显示层数变化）。</p>';
      return;
    }

    const opts = Array.isArray(run.resolve_options) ? run.resolve_options : [];
    if (!opts.length) {
      actionsEl.innerHTML =
        '<button type="button" class="town-dg-btn" data-strategy="force">处理事件</button>';
    } else {
      opts.forEach((opt) => {
        const strat = String(opt.strategy || 'force');
        if (strat.indexOf('skill:') === 0) return;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'town-dg-btn sb-event-opt';
        btn.textContent = String(opt.label || '选择');
        btn.setAttribute('data-strategy', strat);
        if (opt.hint) btn.title = String(opt.hint);
        actionsEl.appendChild(btn);
      });
    }

    actionsEl.querySelectorAll('[data-strategy]').forEach((btn) => {
      btn.disabled = resolving;
      btn.onclick = () => {
        const st = btn.getAttribute('data-strategy') || 'force';
        resolveRoom(st).catch((e) => toast(String(e.message || e)));
      };
    });
    renderSkills();
  }

  function showEventResult(effects, loot, mult, isDead) {
    const lootText =
      loot && loot.length
        ? '<p class="sb-event-loot">奖励：' + loot.map((x) => String(x.label || '物品')).join('、') + '</p>'
        : '';
    const deadNote = isDead
      ? '<p class="sb-event-dead">你已无法继续本次冒险，行囊物品已全部遗失。确认结果后点击下方返回主城。</p>'
      : '<p class="sb-event-result-hint">未阵亡可继续选门。安全层可结束冒险取回行囊。</p>';
    eventOutcomeHtml =
      '<h4 class="sb-event-result-title">事件结果' +
      (mult ? '（奖励 ×' + mult + '）' : '') +
      '</h4>' +
      '<p class="sb-event-result-body">' +
      (effects && effects.length ? effects.join('；') : '本层已结算。') +
      '</p>' +
      lootText +
      deadNote;
    if (isDead) {
      pendingReturnTown = true;
    }
    renderEventPanel();
  }

  function renderRun() {
    const info = document.getElementById('sb-info');
    const ba = document.getElementById('sb-choose-a');
    const bb = document.getElementById('sb-choose-b');
    const bc = document.getElementById('sb-choose-c');
    const bs = document.getElementById('sb-save');
    const bf = document.getElementById('sb-finish');
    const bRetreat = document.getElementById('sb-retreat');
    const hintEl = document.getElementById('sb-safe-hint');
    if (!run) return;
    const opts = doorOptions(run);
    const depth = Number(run.depth) || 0;
    const canLoot = !!run.can_finish_with_loot;
    if (info) {
      info.textContent =
        '第 ' +
        depth +
        ' 层（无限） · 危险度 ' +
        (Number(run.effective_danger) || Number(run.danger) || '?') +
        ' · 当前 ' +
        String((run.encounter && run.encounter.title) || run.room_kind || '未知') +
        (run.resumed ? ' · 已载入存档' : '');
    }
    if (hintEl) hintEl.textContent = String(run.safe_exit_hint || '');
    renderRecentPath();
    renderRecentLog();
    renderEventPanel();
    const alive = (Number(run.run_state && run.run_state.hp) || 0) > 0;
    if (bs) bs.disabled = !alive;
    if (bf) {
      bf.disabled = !alive || !canLoot;
      bf.title = canLoot ? '将行囊物品归还背包' : '仅安全撤离点可用';
    }
    if (bRetreat) {
      bRetreat.disabled = !alive;
      bRetreat.title = '放弃本次战利品，直接回城';
    }
    [ba, bb, bc].forEach((btn, idx) => {
      if (!btn) return;
      const door = opts[idx];
      btn.disabled = !currentResolved || !door;
      btn.textContent = door
        ? doorDisplayLabel(door) + ' · 门 ' + String.fromCharCode(65 + idx)
        : '门 ' + String.fromCharCode(65 + idx);
      btn.style.display = door ? '' : 'none';
    });
    renderStatus();
    renderBag();
    renderSkills();
  }

  async function resolveRoom(strategy) {
    if (resolving || currentResolved) return false;
    resolving = true;
    renderEventPanel();
    try {
      const rs = await gameApi('surface_branch_resolve_room', { run_id: runId, strategy: strategy || 'force' });
      if (!rs.ok) throw new Error(rs.error || '房间结算失败');
      if (rs.run_state) applyRunState(rs.run_state);
      if (Array.isArray(rs.doors) && rs.doors.length) {
        run.doors = rs.doors;
        run.options = rs.doors;
      }
      const effects = rs.resolution && Array.isArray(rs.resolution.effects) ? rs.resolution.effects : [];
      const loot = rs.resolution && Array.isArray(rs.resolution.loot) ? rs.resolution.loot : [];
      const mult = rs.resolution && rs.resolution.reward_mult ? rs.resolution.reward_mult : null;
      const isDead = !!(rs.dead || rs.dead_returned_town);
      currentResolved = true;
      if (rs.resolution && rs.resolution.reward_mult) run.reward_mult = rs.resolution.reward_mult;
      if (Array.isArray(rs.recent_log)) run.recent_log = rs.recent_log;
      showEventResult(effects, loot, mult, isDead);
      renderRun();
      return true;
    } finally {
      resolving = false;
    }
  }

  async function choose(idx) {
    if (!run) return;
    if (!currentResolved) {
      toast('请先处理当前遭遇');
      return;
    }
    branchHistory.push({
      kind: String((run.encounter && run.encounter.kind_label) || run.room_kind || '未知'),
    });
    eventOutcomeHtml = '';
    pendingReturnTown = false;
    const nx = await gameApi('surface_branch_choose', { run_id: runId, option_index: idx });
    if (!nx.ok) throw new Error(nx.error || '推进失败');
    run = nx.room || null;
    if (run) {
      run.id = run.id || runId;
      runId = run.id;
    }
    if (run && Array.isArray(run.doors)) {
      run.options = run.doors;
    }
    currentResolved = !!(run && run.resolved);
    if (run && run.run_state) applyRunState(run.run_state);
    eventOutcomeHtml = '';
    pendingReturnTown = false;
    renderRun();
  }

  async function saveAndQuit() {
    const rs = await gameApi('branch_dungeon_save', { run_id: runId });
    if (!rs.ok) throw new Error(rs.error || '存档失败');
    toast(String(rs.message || '已存档'));
    setTimeout(() => {
      location.href = 'town.html';
    }, 600);
  }

  async function finishRun() {
    if (!run || !run.can_finish_with_loot) {
      toast('当前层不是安全撤离点，请使用「无功而返」或继续深入');
      return;
    }
    if (!confirm('结束本次冒险？剩余行囊物品将归还背包，进度不会保留。')) return;
    const rs = await gameApi('branch_dungeon_finish', { run_id: runId, mode: 'claim' });
    if (!rs.ok) throw new Error(rs.error || '结束失败');
    toast(String(rs.message || '冒险已结束'));
    setTimeout(() => {
      location.href = 'town.html';
    }, 800);
  }

  async function retreatEmpty() {
    if (!confirm('无功而返？行囊中的战利品将全部遗失，不会归还背包。')) return;
    const rs = await gameApi('branch_dungeon_finish', { run_id: runId, mode: 'retreat' });
    if (!rs.ok) throw new Error(rs.error || '撤离失败');
    toast(String(rs.message || '已回城'));
    setTimeout(() => {
      location.href = 'town.html';
    }, 800);
  }

  async function abandonForLoadout() {
    if (!confirm('将放弃当前冒险（行囊物品全部遗失），并重新选择行囊？')) return;
    const en = await gameApi('branch_dungeon_enter', { fresh_start: 1 });
    if (!en.ok) throw new Error(en.error || '操作失败');
    run = null;
    runId = 0;
    showLoadout();
    await fetchLoadout();
  }

  async function loadRun() {
    showLoading('正在连接分支地牢…');
    const en = await gameApi('branch_dungeon_enter', {});
    if (!en.ok) throw new Error(en.error || '无法进入分支地牢');
    if (en.need_loadout) {
      showLoadout();
      await fetchLoadout();
      return;
    }
    if (!en.run || !en.run.id) {
      showLoadout();
      await fetchLoadout();
      toast('未找到进行中的冒险，请准备行囊');
      return;
    }
    run = en.run;
    runId = Number(run.id) || 0;
    currentResolved = !!(run.resolved || (run.run_state && run.run_state.resolved));
    showRun();
    if (en.run.resumed) toast('已载入存档，行囊物品仍在冒险中');
    renderRun();
  }

  async function refreshMetaBar() {
    if (window.PlayerMetaBar) await PlayerMetaBar.refresh('player-meta-bar');
  }

  onReady(async () => {
    showLoading('正在验证登录…');
    try {
      await refreshMetaBar();
      await loadRun();
      document.getElementById('sb-choose-a').onclick = () => choose(0).catch((e) => toast(String(e.message || e)));
      document.getElementById('sb-choose-b').onclick = () => choose(1).catch((e) => toast(String(e.message || e)));
      document.getElementById('sb-choose-c').onclick = () => choose(2).catch((e) => toast(String(e.message || e)));
      document.getElementById('sb-save').onclick = () => saveAndQuit().catch((e) => toast(String(e.message || e)));
      document.getElementById('sb-finish').onclick = () => finishRun().catch((e) => toast(String(e.message || e)));
      const retreatBtn = document.getElementById('sb-retreat');
      if (retreatBtn) retreatBtn.onclick = () => retreatEmpty().catch((e) => toast(String(e.message || e)));
      document.getElementById('sb-new-run').onclick = () => abandonForLoadout().catch((e) => toast(String(e.message || e)));
      document.getElementById('sb-loadout-start').onclick = () =>
        startWithLoadout(selectedLoadoutCounts()).catch((e) => toast(String(e.message || e)));
      document.getElementById('sb-loadout-empty').onclick = () => startWithLoadout({}).catch((e) => toast(String(e.message || e)));
    } catch (e) {
      const msg = String(e.message || e);
      toast(msg);
      showFatalError(msg);
    }
  });
})();
