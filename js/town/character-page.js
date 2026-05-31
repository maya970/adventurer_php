/* global gameApi, ManSprite, RpgPage */
(function () {
  const { toast, esc, onReady, renderMetaBar, loadPlayer } = RpgPage;
  const RING = 16;
  const GRID_POS = [
    [0, 0], [0, 1], [0, 2], [0, 3], [0, 4],
    [1, 4], [2, 4], [3, 4], [4, 4],
    [4, 3], [4, 2], [4, 1], [4, 0],
    [3, 0], [2, 0], [1, 0],
  ];

  let cfg = null;
  let myId = 0;
  let animating = false;

  function cellIdx(c) {
    const i = c.index != null ? c.index : c.cell_index;
    return Number(i);
  }

  function cellsMap(board) {
    const m = {};
    (board.cells || []).forEach((c) => {
      const i = cellIdx(c);
      if (i >= 0 && i < RING) m[i] = c;
    });
    return m;
  }

  function pos() {
    return cfg ? Number(cfg.position) || 0 : 0;
  }

  function hereCell() {
    return cellsMap(cfg)[pos()];
  }

  function cellLabel(cell) {
    if (!cell) return '—';
    const toll = cell.toll;
    const empty = cell.is_empty || cell.reward_type === 'none';
    if (empty && toll && toll.owner_player_id) {
      const g = Number(toll.toll_gold) || 25;
      const name = toll.owner_name || '玩家';
      if (Number(toll.owner_player_id) === myId) return '我的收费站 ' + g + '金';
      return '收费站 ' + g + '金 · ' + name;
    }
    if (empty) return '空格 · 可建收费站';
    return cell.prize_desc || cell.cell_name || '奖品';
  }

  function place(el, row, col) {
    el.style.gridRowStart = String(row + 1);
    el.style.gridRowEnd = String(row + 2);
    el.style.gridColumnStart = String(col + 1);
    el.style.gridColumnEnd = String(col + 2);
  }

  function renderBoard(highlight) {
    const grid = document.getElementById('skin-board-grid');
    if (!grid || !cfg) return;
    const p = highlight != null ? highlight : pos();
    const map = cellsMap(cfg);
    grid.innerHTML = '';

    const center = document.createElement('div');
    center.className = 'skin-mono-center';
    center.textContent = '掷骰区';
    grid.appendChild(center);

    for (let i = 0; i < RING; i++) {
      const cell = map[i];
      const gp = GRID_POS[i];
      if (!cell || !gp) continue;
      const on = i === p;
      const empty = cell.is_empty || cell.reward_type === 'none';
      const el = document.createElement('div');
      el.className =
        'skin-mono-cell' +
        (on ? (animating ? ' skin-mono-tile-step' : ' skin-mono-tile-active') : '') +
        (empty ? ' skin-mono-tile-empty' : ' skin-mono-tile-prize');
      el.dataset.index = String(i);
      place(el, gp[0], gp[1]);
      let face = '';
      if (cell.reward_type === 'skin' && cell.reward_skin_png && window.ManSprite) {
        face = ManSprite.previewHtml(cell.reward_skin_png, { className: 'skin-mono-face' });
      }
      el.innerHTML =
        (on ? '<span class="skin-mono-you">你在此</span>' : '') +
        face +
        '<span class="skin-mono-prize">' +
        esc(cellLabel(cell)) +
        '</span>';
      grid.appendChild(el);
    }
    updateActionButtons();
  }

  function updateActionButtons() {
    const buildBtn = document.getElementById('skin-board-build');
    const buyBtn = document.getElementById('skin-board-buyout');
    const cell = hereCell();
    const idx = pos();
    let canBuild = false;
    let canBuy = false;
    if (cell && (cell.is_empty || cell.reward_type === 'none')) {
      const toll = cell.toll;
      if (toll && toll.owner_player_id && Number(toll.owner_player_id) !== myId) {
        canBuy = true;
      } else if (!toll || !toll.owner_player_id) {
        canBuild = true;
      }
    }
    if (buildBtn) {
      buildBtn.hidden = !canBuild;
      buildBtn.disabled = animating;
    }
    if (buyBtn) {
      buyBtn.hidden = !canBuy;
      buyBtn.disabled = animating;
      if (canBuy && cell.toll) {
        buyBtn.textContent = '收购收费站（' + (Number(cell.toll.toll_gold) || 25) * 2 + ' 金）';
      }
    }
    const hint = document.getElementById('skin-board-toll-hint');
    if (hint) {
      hint.textContent = canBuild
        ? '你站在 #' + idx + '：可花 50 金建造收费站（全服可见，他人经过须缴费）。'
        : canBuy
          ? '你站在 #' + idx + '：可花 2 倍过路费收购此收费站。'
          : '须先掷骰走到目标空格，才能建造或收购收费站；经过他人收费站会自动扣费。';
    }
  }

  function animateMove(from, path) {
    return new Promise((resolve) => {
      if (!path.length) {
        resolve();
        return;
      }
      animating = true;
      updateActionButtons();
      let s = 0;
      const step = () => {
        renderBoard(path[s]);
        s++;
        if (s < path.length) setTimeout(step, 320);
        else {
          animating = false;
          if (cfg) cfg.position = path[path.length - 1];
          renderBoard();
          resolve();
        }
      };
      renderBoard(from);
      setTimeout(step, 280);
    });
  }

  async function buildToll() {
    const idx = pos();
    if (!confirm('在 #' + idx + ' 建造收费站？消耗 50 金，过路费 25 金。')) return;
    const d = await gameApi('skin_board_build_toll', { cell_index: idx });
    toast(d.message || '建造完成');
    await loadAll();
  }

  async function buyoutToll() {
    const cell = hereCell();
    const fee = (Number(cell && cell.toll && cell.toll.toll_gold) || 25) * 2;
    if (!confirm('收购 #' + pos() + ' 收费站？需 ' + fee + ' 金。')) return;
    const d = await gameApi('skin_board_buyout_toll', { cell_index: pos() });
    toast(d.message || '收购完成');
    await loadAll();
  }

  function renderSkins(p) {
    const cur = document.getElementById('char-current-sheet');
    const list = document.getElementById('skin-unlocked-list');
    const skins = p.unlocked_skins || [];
    const active = String(p.active_skin_id || 'm1001');
    const row = skins.find((x) => x.id === active);
    const png = row && row.man_png != null ? row.man_png : ManSprite ? ManSprite.resolveManPng(p) : 1001;
    if (cur) {
      cur.innerHTML =
        (ManSprite ? ManSprite.previewHtml(png, { className: 'char-skin-face' }) : '') +
        '<span class="char-skin-caption">当前：' +
        esc(row ? row.label : active) +
        '</span>';
    }
    if (!list) return;
    list.innerHTML = skins.length
      ? skins
          .map((x) => {
            const on = x.id === active;
            const png2 = x.man_png != null ? x.man_png : 1001;
            return (
              '<div class="char-license-row char-skin-row' +
              (on ? ' char-skin-row-active' : '') +
              '">' +
              (ManSprite ? ManSprite.previewHtml(png2, { className: 'char-skin-face' }) : '') +
              '<span class="char-skin-caption">' +
              esc(x.label || x.id) +
              (on ? '（使用中）' : '') +
              '</span>' +
              (on ? '' : '<button type="button" class="town-dg-btn btn-skin-equip" data-id="' + esc(x.id) + '">装备</button>') +
              '</div>'
            );
          })
          .join('')
      : '<div class="surface-muted">暂无已解锁皮肤</div>';
    list.querySelectorAll('.btn-skin-equip').forEach((btn) => {
      btn.onclick = async () => {
        await gameApi('skin_set_active', { skin_id: btn.getAttribute('data-id') });
        toast('已切换皮肤');
        await loadAll();
      };
    });
  }

  async function loadAll() {
    const pl = await loadPlayer();
    const p = pl.player || {};
    myId = Number(p.id) || 0;
    cfg = await gameApi('skin_board_config', {});
    const meta = document.getElementById('skin-board-meta');
    if (meta) {
      const h = hereCell();
      meta.textContent =
        '你站在 #' + pos() + ' · ' + cellLabel(h) + ' · 已完成 ' + (Number(cfg.laps) || 0) + ' 圈';
    }
    renderSkins(p);
    renderBoard();
  }

  onReady(async () => {
    await loadAll();
    document.getElementById('skin-board-roll').onclick = async () => {
        if (animating) return;
        const btn = document.getElementById('skin-board-roll');
        btn.disabled = true;
        try {
          const d = await gameApi('skin_board_roll', {});
          const from = Number(d.old_position != null ? d.old_position : pos());
          let path = Array.isArray(d.move_path) ? d.move_path.map(Number) : [];
          if (!path.length && d.roll) {
            for (let i = 1; i <= Number(d.roll); i++) path.push((from + i) % RING);
          }
          await animateMove(from, path);
          toast('骰子 ' + (d.roll || 0) + ' · ' + (d.message || ''));
          await loadAll();
        } catch (e) {
          toast(String(e.message || e));
        } finally {
          btn.disabled = false;
        }
    };
    document.getElementById('skin-board-build').onclick = () => buildToll().catch((e) => toast(String(e.message || e)));
    document.getElementById('skin-board-buyout').onclick = () => buyoutToll().catch((e) => toast(String(e.message || e)));
  });
})();
