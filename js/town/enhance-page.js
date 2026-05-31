/* global gameApi, TownUI, RpgPage */

function qs(id) {
  return document.getElementById(id);
}

const toast = (msg) => RpgPage.toast(msg);
const escapeHtml = (t) => RpgPage.esc(t);

function rarityNorm(r) {
  return String(r || 'common')
    .toLowerCase()
    .replace(/[^a-z0-9_-]/g, '') || 'common';
}

function itemImgUrlList(num) {
  const n = Math.max(0, Number(num) || 0);
  const idx = n < 1 ? 1 : n;
  const b = String(idx).padStart(4, '0');
  const exts = ['webp', 'png', 'jpg', 'jpeg', 'gif'];
  const out = [];
  exts.forEach((e) => {
    out.push(`img/items/${b}.${e}`);
    out.push(`img/item/${b}.${e}`);
  });
  return out;
}

function bindImgSeq(img, urls) {
  let i = 0;
  img.onerror = () => {
    i++;
    if (i < urls.length) img.src = urls[i];
  };
  img.src = urls[0] || '';
}

function playEnhFailFx(target) {
  if (!target) return;
  target.classList.remove('enh-fx-shake');
  void target.offsetWidth;
  target.classList.add('enh-fx-shake');
  setTimeout(() => target.classList.remove('enh-fx-shake'), 500);
}

function playEnhSuccessFx(layer) {
  if (!layer) return;
  const burst = document.createElement('div');
  burst.className = 'enh-success-burst';
  layer.appendChild(burst);
  setTimeout(() => burst.remove(), 900);
}

let detailItemId = null;

function panelsHeaderVisible(on) {
  const grid = document.querySelector('.enhance-panels-grid');
  if (grid) grid.hidden = !on;
}

function showEnhListView() {
  detailItemId = null;
  const dw = qs('enh-detail-wrap');
  const mw = qs('enh-main-wrap');
  if (dw) dw.hidden = true;
  if (mw) mw.hidden = false;
  panelsHeaderVisible(true);
  if (location.pathname.indexOf('enhance') !== -1) {
    history.replaceState(null, '', 'enhance.html');
  }
}

function showEnhDetailView() {
  const dw = qs('enh-detail-wrap');
  const mw = qs('enh-main-wrap');
  if (dw) dw.hidden = false;
  if (mw) mw.hidden = true;
  panelsHeaderVisible(false);
}

function openEnhDetail(game, itemId) {
  const id = Number(itemId);
  if (!id) return;
  detailItemId = id;
  showEnhDetailView();
  history.replaceState(null, '', 'enhance.html?item=' + id);
  renderEnhanceDetail(game, id).catch((e) => toast(String(e.message || e)));
}

async function renderEnhanceDetail(game, itemId) {
  const root = qs('enh-detail-root');
  if (!root) return;
  const it = (game.state.inventory || []).find((x) => Number(x.id) === Number(itemId));
  if (!it || !['weapon', 'armor', 'ring', 'boots'].includes(it.slot)) {
    root.innerHTML = '<p class="empty-hint">未找到可强化的装备。</p>';
    return;
  }
  if (Number(it.in_warehouse) === 1) {
    root.innerHTML = '<p class="empty-hint">该物品在仓库中，请先取出。</p>';
    return;
  }
  const pl = Number(it.plus_level) || 0;
  if (pl >= 20) {
    root.innerHTML = `<p class="empty-hint">${escapeHtml(it.label)} 已达 +20。</p>`;
    return;
  }
  let prev = {};
  try {
    prev = await gameApi('enhance_preview', { item_id: itemId });
  } catch (e) {
    prev = { ok: false, _err: e.message || String(e) };
  }
  if (!prev.ok) {
    root.innerHTML = `<p class="empty-hint">${escapeHtml(prev._err || '无法预览')}</p>`;
    return;
  }
  const rr = rarityNorm(it.rarity);
  const dTag =
    typeof TownUI !== 'undefined' && TownUI.diceTagHtml
      ? TownUI.diceTagHtml(it.damage_dice || '1d4')
      : escapeHtml(String(it.damage_dice || '1d4'));
  const rTag =
    typeof TownUI !== 'undefined' && TownUI.rarityTagHtml ? TownUI.rarityTagHtml(it.rarity) : '';
  const rawDesc = String(it.item_desc || '').trim();
  const descHtml = rawDesc ? escapeHtml(rawDesc).replace(/\n/g, '<br>') : '暂无介绍。';
  root.innerHTML = `
    <div class="enh-detail-card" id="enh-detail-card">
      <div class="enh-detail-visual">
        <div class="enh-fx-layer" id="enh-fx-layer"></div>
        <div class="enh-weapon-frame rarity-${escapeHtml(rr)}">
          <img id="enh-detail-img" class="enh-detail-img" alt="" width="96" height="96" />
        </div>
      </div>
      <div class="enh-detail-text">
        <h2 class="enh-detail-title">${escapeHtml(it.label)} ${rTag} <span class="enh-plus">+${pl}</span></h2>
        <p class="enh-detail-desc">${descHtml}</p>
        <div class="enh-detail-stats">
          <div>下一目标：<strong>+${prev.next_plus}</strong></div>
          <div>消耗金币：<strong>${prev.gold_cost}</strong> · 成功率约 <strong>${prev.chance_percent}%</strong></div>
          <div>伤害骰：${dTag} · 难度系数 ${prev.dice_difficulty}</div>
        </div>
        <div class="enh-detail-actions">
          <button type="button" class="auth-btn primary" id="enh-btn-once">强化一次</button>
          <button type="button" class="auth-btn" id="enh-btn-auto">连续强化（直到成功或金币不足）</button>
        </div>
      </div>
    </div>
  `;
  const img = qs('enh-detail-img');
  if (img) bindImgSeq(img, itemImgUrlList(Number(it.image_num) || 0));

  const card = qs('enh-detail-card');
  const fxLayer = qs('enh-fx-layer');

  qs('enh-btn-once').onclick = async () => {
    try {
      const data = await gameApi('enhance', { item_id: itemId });
      if (data.enhance_failed) {
        game.applyPlayerPayload({ player: data.player, inventory: data.inventory });
        if (qs('enh-gold') && game.state.player) qs('enh-gold').textContent = String(game.state.player.gold);
        TownUI.renderAttrPanel(game.state.player);
        TownUI.refreshAllInventoryUI(game, game.state.inventory || [], { skipInventoryHook: true });
        playEnhFailFx(card);
        toast(data.message || '强化失败');
        await renderEnhanceDetail(game, itemId);
        return;
      }
      game.applyPlayerPayload(data);
      if (qs('enh-gold') && game.state.player) qs('enh-gold').textContent = String(game.state.player.gold);
      TownUI.renderAttrPanel(game.state.player);
      TownUI.refreshAllInventoryUI(game, data.inventory || game.state.inventory || [], {
        skipInventoryHook: true,
      });
      playEnhSuccessFx(fxLayer);
      toast('强化成功 +' + (data.enhance && data.enhance.plus_level != null ? data.enhance.plus_level : pl + 1));
      await renderEnhanceDetail(game, itemId);
      await renderEnhanceList(game);
    } catch (e) {
      toast(String(e.message || e));
    }
  };

  qs('enh-btn-auto').onclick = async () => {
    await runAutoEnhance(game, card, fxLayer);
  };
}

async function runAutoEnhance(game, card, fxLayer) {
  for (;;) {
    const it = (game.state.inventory || []).find((x) => Number(x.id) === Number(detailItemId));
    if (!it) break;
    const pl = Number(it.plus_level) || 0;
    if (pl >= 20) {
      toast('已达 +20');
      break;
    }
    let prev;
    try {
      prev = await gameApi('enhance_preview', { item_id: detailItemId });
    } catch {
      break;
    }
    if (!prev.ok) break;
    const gold = Number(game.state.player && game.state.player.gold) || 0;
    if (gold < prev.gold_cost) {
      toast('金币不足，停止连续强化');
      playEnhFailFx(card);
      break;
    }
    try {
      const data = await gameApi('enhance', { item_id: detailItemId });
      if (data.enhance_failed) {
        game.applyPlayerPayload({ player: data.player, inventory: data.inventory });
        if (qs('enh-gold') && game.state.player) qs('enh-gold').textContent = String(game.state.player.gold);
        playEnhFailFx(card);
        continue;
      }
      game.applyPlayerPayload(data);
      if (qs('enh-gold') && game.state.player) qs('enh-gold').textContent = String(game.state.player.gold);
      TownUI.renderAttrPanel(game.state.player);
      playEnhSuccessFx(fxLayer);
      toast('强化成功 +' + (data.enhance && data.enhance.plus_level));
      await renderEnhanceDetail(game, detailItemId);
      await renderEnhanceList(game);
      TownUI.refreshAllInventoryUI(game, game.state.inventory || [], { skipInventoryHook: true });
      break;
    } catch (e) {
      toast(String(e.message || e));
      break;
    }
  }
}

async function renderEnhanceList(game) {
  const host = qs('enhance-list');
  if (!host) return;
  host.innerHTML = '';
  const inv = game.state.inventory || [];
  const items = inv.filter(
    (it) =>
      ['weapon', 'armor', 'ring', 'boots'].includes(it.slot) &&
      (Number(it.plus_level) || 0) < 20 &&
      Number(it.in_warehouse) !== 1
  );
  if (!items.length) {
    host.innerHTML = '<p class="empty-hint">没有可强化的装备（仓库内需先取出，或已全部 +20）。</p>';
    return;
  }
  for (const it of items) {
    let prev = {};
    try {
      prev = await gameApi('enhance_preview', { item_id: it.id });
    } catch (e) {
      prev = { ok: false, _err: e.message || String(e) };
    }
    const row = document.createElement('div');
    row.className = 'enhance-row enhance-row-click';
    if (!prev.ok) {
      row.innerHTML = `<div class="enhance-row-main">${escapeHtml(it.label)} — ${escapeHtml(prev._err || '无法预览')}</div>`;
      host.appendChild(row);
      continue;
    }
    const pl = Number(it.plus_level) || 0;
    const rTag = typeof TownUI !== 'undefined' && TownUI.rarityTagHtml ? TownUI.rarityTagHtml(it.rarity) : '';
    const dTag =
      typeof TownUI !== 'undefined' && TownUI.diceTagHtml
        ? TownUI.diceTagHtml(it.damage_dice || '1d4')
        : escapeHtml(String(it.damage_dice || '1d4'));
    row.innerHTML = `
      <div class="enhance-row-main">
        <strong>${escapeHtml(it.label)}</strong> ${rTag} +${pl} → +${prev.next_plus}<br>
        消耗 <strong>${prev.gold_cost}</strong> 金币 · 成功率约 <strong>${prev.chance_percent}%</strong>
        · 骰子难度 <strong>${prev.dice_difficulty}</strong>（${dTag}）
        <span class="enh-row-hint">点击进入独立强化页</span>
      </div>
      <div class="enhance-row-actions"></div>
    `;
    row.onclick = () => openEnhDetail(game, it.id);
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'auth-btn primary enhance-action-btn';
    btn.textContent = '强化间';
    btn.onclick = (e) => {
      e.stopPropagation();
      openEnhDetail(game, it.id);
    };
    row.querySelector('.enhance-row-actions').appendChild(btn);
    host.appendChild(row);
  }
}

RpgPage.onReady(async () => {
  const g = RpgPage.createTownShell();
  g.onInventoryChanged = function () {
    renderEnhanceList(g).catch((e) => toast(String(e.message || e)));
    if (detailItemId) {
      renderEnhanceDetail(g, detailItemId).catch((e) => toast(String(e.message || e)));
    }
    if (qs('enh-gold') && g.state.player) qs('enh-gold').textContent = String(g.state.player.gold);
  };
  TownUI.initTownUI(g);
  TownUI.showPlaceholderDetail();

  const back = qs('enh-detail-back');
  if (back) {
    back.onclick = () => {
      showEnhListView();
      renderEnhanceList(g).catch((e) => toast(String(e.message || e)));
    };
  }

  try {
    const d = await gameApi('player', {});
    g.applyPlayerPayload(d);
    if (qs('enh-gold') && g.state.player) qs('enh-gold').textContent = String(g.state.player.gold);
    TownUI.renderAttrPanel(d.player);
    TownUI.refreshAllInventoryUI(g, d.inventory || [], { skipInventoryHook: true });
  } catch (e) {
    toast(String(e.message || e));
    return;
  }

  await renderEnhanceList(g);

  const qp = new URLSearchParams(window.location.search);
  const itemQ = qp.get('item');
  if (itemQ && Number(itemQ) > 0) {
    openEnhDetail(g, itemQ);
  }

  const ref = qs('btn-enh-refresh');
  if (ref) {
    ref.onclick = async () => {
      try {
        const d = await gameApi('player', {});
        g.applyPlayerPayload(d);
        if (qs('enh-gold') && g.state.player) qs('enh-gold').textContent = String(g.state.player.gold);
        TownUI.renderAttrPanel(d.player);
        TownUI.refreshAllInventoryUI(g, d.inventory || [], { skipInventoryHook: true });
        await renderEnhanceList(g);
        if (detailItemId) await renderEnhanceDetail(g, detailItemId);
        toast('已刷新');
      } catch (e) {
        toast(String(e.message || e));
      }
    };
  }
});
