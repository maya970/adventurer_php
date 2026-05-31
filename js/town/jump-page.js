/* global gameApi, TownUI, RpgPage */

(function () {
  const { toast, esc, onReady } = RpgPage;

  function qs(id) {
    return document.getElementById(id);
  }

  const JumpGame = RpgPage.createTownShell();
  JumpGame.catalogRows = [];
  JumpGame.equivByKey = Object.create(null);
  JumpGame.maxFloor = 10;

  function rarityZh(r) {
    const m = {
      common: '普通',
      uncommon: '优秀',
      rare: '稀有',
      epic: '史诗',
      legendary: '传说',
    };
    return m[String(r || '').toLowerCase()] || String(r || '');
  }

  function rebuildTargetSelect(game) {
    const sel = qs('jump-target-floor');
    if (!sel) return;
    const cap = Math.max(0, Number(game.maxFloor) || 0);
    const shRaw = game.state.player && game.state.player.stamina && game.state.player.stamina.shelter_floor;
    const shNum = Number(shRaw);
    sel.innerHTML = '';
    if (cap < 10) {
      const o = document.createElement('option');
      o.value = '';
      o.textContent = '全服尚未解锁至 10 层，无法使用跳跃法阵';
      sel.appendChild(o);
      sel.disabled = true;
      return;
    }
    const added = new Set();
    for (let f = 10; f <= cap; f += 10) {
      const o = document.createElement('option');
      o.value = String(f);
      o.textContent = `第 ${f} 层（世界首领层，需 ${f / 10} 个等价物）`;
      sel.appendChild(o);
      added.add(f);
    }
    if (Number.isFinite(shNum) && shNum >= 1 && shNum <= cap && !added.has(shNum)) {
      const o = document.createElement('option');
      o.value = String(shNum);
      o.textContent = `第 ${shNum} 层（避难所锚点，免费传送）`;
      sel.appendChild(o);
    }
    sel.disabled = false;
  }

  function selectedTargetFloor(game) {
    const sel = qs('jump-target-floor');
    if (!sel || sel.disabled) return 0;
    const n = Number(sel.value);
    return Number.isFinite(n) ? n : 0;
  }

  function equivNeededForFloor(f) {
    return f >= 10 && f % 10 === 0 ? f / 10 : 0;
  }

  function sumCheckedEquiv(game) {
    const list = qs('jump-equiv-list');
    if (!list) return 0;
    let sum = 0;
    list.querySelectorAll('input.jump-equiv-cb:checked').forEach((cb) => {
      const u = Number(cb.dataset.equivUnits);
      if (Number.isFinite(u) && u > 0) sum += u;
    });
    return sum;
  }

  function refreshJumpHints(game) {
    const maxHint = qs('jump-max-hint');
    if (maxHint) {
      const cap = Math.max(0, Number(game.maxFloor) || 0);
      const sh = game.state.player && game.state.player.stamina && game.state.player.stamina.shelter_floor;
      const shTxt = sh
        ? ` 已在地下城设锚点第 ${sh} 层（可为奇数）：下拉里选该层可免费传送。`
        : ' 避难所锚点须在地下城内用「避难所权杖」设立。';
      maxHint.textContent =
        cap >= 10
          ? `当前全服解锁层上限为第 ${cap} 层（含首领层）；常规目标为不超过该层的 10 的倍数（10、20、30…）。${shTxt}`
          : '全服尚未解锁至 10 层，无法跳跃。';
    }
    const costHint = qs('jump-cost-hint');
    const tf = selectedTargetFloor(game);
    const shelterF = Number(
      game.state.player && game.state.player.stamina && game.state.player.stamina.shelter_floor
    );
    const isShelter = Number.isFinite(shelterF) && shelterF > 0 && tf === shelterF;
    const need = isShelter ? 0 : equivNeededForFloor(tf);
    if (costHint) {
      costHint.textContent = isShelter
        ? '当前为避难所锚点层：献祭跳跃将免费传送（不消耗等价物）。'
        : need
          ? `当前选择需要献祭等价物合计 ≥ ${need}。`
          : '请选择有效的目标层。';
    }
    const sumHint = qs('jump-sum-hint');
    if (sumHint) {
      if (isShelter) {
        sumHint.innerHTML = '避难所锚点：<strong>无需等价物</strong>';
      } else {
        const s = sumCheckedEquiv(game);
        sumHint.innerHTML = `已选等价合计：<strong>${s}</strong>${need ? ` / 需要 ${need}` : ''}`;
      }
    }
    const btn = qs('jump-submit-btn');
    if (btn) {
      const sum = sumCheckedEquiv(game);
      btn.disabled = !tf || (!isShelter && (need < 1 || sum < need));
    }
  }

  function renderCatalogPanel(game) {
    const el = qs('jump-catalog-list');
    if (!el) return;
    el.innerHTML = '';
    if (!game.catalogRows.length) {
      el.innerHTML = '<div class="empty-hint">目录为空或未创建数据表。</div>';
      return;
    }
    game.catalogRows.forEach((row) => {
      const div = document.createElement('div');
      div.className = 'jump-catalog-row';
      const k = row.item_key || '';
      const lab = row.label || k;
      const eu = Number(row.equiv_units) || 0;
      div.innerHTML = `${esc(lab)} <code>${esc(k)}</code> · 等价 <strong>${eu}</strong>`;
      el.appendChild(div);
    });
  }

  function renderEquivList(game) {
    const list = qs('jump-equiv-list');
    if (!list) return;
    list.innerHTML = '';
    const inv = game.state.inventory || [];
    const rows = inv.filter((it) => {
      if (Number(it.equipped) === 1) return false;
      if (Number(it.in_warehouse) === 1) return false;
      if (String(it.slot || '') !== 'misc') return false;
      const key = String(it.item_key || '');
      const eu = game.equivByKey[key];
      return Number.isFinite(eu) && eu > 0;
    });
    if (!rows.length) {
      list.innerHTML = '<div class="empty-hint">背包中没有可作为等价物的允许杂物。</div>';
      refreshJumpHints(game);
      return;
    }
    rows.forEach((it) => {
      const key = String(it.item_key || '');
      const eu = game.equivByKey[key] || 0;
      const wrap = document.createElement('label');
      wrap.className = 'jump-equiv-row';
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.className = 'jump-equiv-cb';
      cb.dataset.itemId = String(it.id);
      cb.dataset.equivUnits = String(eu);
      cb.addEventListener('change', () => refreshJumpHints(game));
      const span = document.createElement('span');
      span.innerHTML = ` ${esc(it.label || '?')}（${rarityZh(it.rarity)}）· 等价 <strong>${eu}</strong> · #${it.id}`;
      wrap.appendChild(cb);
      wrap.appendChild(span);
      list.appendChild(wrap);
    });
    refreshJumpHints(game);
  }

  onReady(async () => {
    const g = JumpGame;
    const [pl, cat] = await Promise.all([gameApi('player', {}), gameApi('jump_catalog', {})]);
      g.applyPlayerPayload(pl);
      g.catalogRows = Array.isArray(cat.catalog) ? cat.catalog : [];
      g.maxFloor = Number(cat.max_floor) || 10;
      g.equivByKey = Object.create(null);
      g.catalogRows.forEach((r) => {
        const k = String(r.item_key || '');
        if (k) g.equivByKey[k] = Number(r.equiv_units) || 0;
      });
      if (pl.dungeon_world && Number(pl.dungeon_world.max_unlocked_floor)) {
        g.maxFloor = Number(pl.dungeon_world.max_unlocked_floor);
      }
    TownUI.renderAttrPanel(g.state.player);
    TownUI.refreshAllInventoryUI(g, g.state.inventory || []);
    rebuildTargetSelect(g);
    renderCatalogPanel(g);
    renderEquivList(g);
    const sel = qs('jump-target-floor');
    if (sel) {
      sel.addEventListener('change', () => refreshJumpHints(g));
    }
    const btn = qs('jump-submit-btn');
    if (btn) {
      btn.onclick = async () => {
        const tf = selectedTargetFloor(g);
        const need = equivNeededForFloor(tf);
        const list = qs('jump-equiv-list');
        const ids = [];
        if (list) {
          list.querySelectorAll('input.jump-equiv-cb:checked').forEach((cb) => {
            const id = Number(cb.dataset.itemId);
            if (id > 0) ids.push(id);
          });
        }
        const shelterF = Number(
          g.state.player && g.state.player.stamina && g.state.player.stamina.shelter_floor
        );
        const isShelterJump = shelterF > 0 && tf === shelterF;
        if (!tf) {
          g.toast('请选择有效目标层');
          return;
        }
        if (!isShelterJump) {
          if (need < 1) {
            g.toast('请选择有效目标层');
            return;
          }
          if (!ids.length) {
            g.toast('请选择等价物（避难所锚点层可免费传送）');
            return;
          }
          if (sumCheckedEquiv(g) < need) {
            g.toast('等价物不足');
            return;
          }
        }
        btn.disabled = true;
        try {
          const data = await gameApi('jump_submit', { target_floor: tf, item_ids: isShelterJump ? [] : ids });
          g.applyPlayerPayload(data);
          TownUI.renderAttrPanel(g.state.player);
          TownUI.refreshAllInventoryUI(g, g.state.inventory || []);
          renderEquivList(g);
          window.location.href = 'dungeon.html?autostart=1';
        } catch (err) {
          g.toast(String(err.message || err));
        } finally {
          btn.disabled = false;
          refreshJumpHints(g);
        }
      };
    }
  });
})();
