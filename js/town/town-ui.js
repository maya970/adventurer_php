/* global gameApi */

function qs(id) {
  return document.getElementById(id);
}

const SLOT_ZH = {
  weapon: '武器',
  armor: '护甲',
  ring: '戒指',
  boots: '鞋',
  misc: '杂物',
};

const RARITY_ZH = {
  common: '普通',
  uncommon: '优秀',
  rare: '稀有',
  epic: '史诗',
  legendary: '传说',
};

function slotZh(s) {
  return SLOT_ZH[s] || s;
}

function itemCannotSellOrAuction(it) {
  return it && String(it.item_key || '') === 'bonfire_blade';
}

function rarityZh(r) {
  return RARITY_ZH[r] || r;
}

/** 伤害骰 n×m 分档，与 CSS .dice-tier-* 对应 */
function dicePowerTier(dice) {
  const m = String(dice || '').match(/^(\d+)d(\d+)/i);
  const p = m ? Math.max(1, parseInt(m[1], 10) * parseInt(m[2], 10)) : 1;
  if (p <= 4) return 1;
  if (p <= 12) return 2;
  if (p <= 30) return 3;
  return 4;
}

function rarityTagHtml(r) {
  const k = String(r || 'common')
    .toLowerCase()
    .replace(/[^a-z0-9_-]/g, '');
  const key = k || 'common';
  return `<span class="rarity-tag rarity-${key}">${escapeHtml(rarityZh(r))}</span>`;
}

function diceTagHtml(dice) {
  const t = dicePowerTier(dice);
  return `<span class="dice-tag dice-tier-${t}">${escapeHtml(String(dice || '1d4'))}</span>`;
}

/** 与服务器 damage_dice_min_max 一致：单件物品当前字面骰的上下限（不含强化后缀） */
function damageDiceMinMaxPair(dice) {
  const m = String(dice || '')
    .toLowerCase()
    .trim()
    .match(/^(\d+)d(\d+)([+-]\d+)?$/);
  if (!m) return [1, 1];
  const n = Math.min(20, Math.max(1, parseInt(m[1], 10)));
  const d = Math.min(100, Math.max(2, parseInt(m[2], 10)));
  const mod = m[3] ? parseInt(m[3], 10) : 0;
  return [Math.max(1, n + mod), Math.max(1, n * d + mod)];
}

function escapeHtml(t) {
  if (typeof RpgPage !== 'undefined' && RpgPage.esc) return RpgPage.esc(t);
  const d = document.createElement('div');
  d.textContent = t == null ? '' : String(t);
  return d.innerHTML;
}

function weaponAllowLabel(it) {
  const a = it.weapon_allow || 'both';
  if (a === 'main') return '仅主手（右）';
  if (a === 'off') return '仅副手（左）';
  return '主手或副手均可';
}

function weaponAllowMainOk(it) {
  const a = it.weapon_allow || 'both';
  return a === 'main' || a === 'both';
}

function weaponAllowOffOk(it) {
  const a = it.weapon_allow || 'both';
  return a === 'off' || a === 'both';
}

function equippedForSlotKey(inv, key) {
  const list = inv || [];
  if (key === 'weapon_main') {
    return list.find(
      (x) =>
        Number(x.equipped) === 1 &&
        x.slot === 'weapon' &&
        (x.weapon_hand == null || x.weapon_hand === '' || x.weapon_hand === 'main')
    );
  }
  if (key === 'weapon_off') {
    return list.find((x) => Number(x.equipped) === 1 && x.slot === 'weapon' && x.weapon_hand === 'off');
  }
  return list.find((x) => Number(x.equipped) === 1 && x.slot === key);
}

/** 与 items 的 image_num 一致；兼容 sprite_num / sprite_index */
function monsterImageNumFromDef(def) {
  if (!def || typeof def !== 'object') return 0;
  const raw =
    def.image_num != null
      ? def.image_num
      : def.sprite_num != null
        ? def.sprite_num
        : def.sprite_index;
  const n = Math.floor(Number(raw));
  return n > 0 && n <= 9999 ? n : 0;
}

/** 与服务器 damage_dice_difficulty_mult 一致：ceil(√(n·m)) */
function diceDifficultyMult(dice) {
  const m = String(dice || '1d4').match(/^(\d+)d(\d+)/i);
  if (!m) return 1;
  const n = Math.max(1, parseInt(m[1], 10));
  const d = Math.max(1, parseInt(m[2], 10));
  return Math.max(1, Math.ceil(Math.sqrt(n * d)));
}

function nextEnhanceChancePercent(currentPlus, dice) {
  const pl = Math.max(0, Number(currentPlus) || 0);
  const denom = (pl + 1) * diceDifficultyMult(dice);
  return Math.floor(100 / Math.max(1, denom));
}

function nextEnhanceGoldCost(currentPlus) {
  return 50 * Math.pow(2, Math.max(0, Number(currentPlus) || 0));
}

function buildItemStatLines(it) {
  const blocks = [];
  blocks.push(`<div class="detail-line">${escapeHtml(`装备部位：${it.slot === 'misc' ? '杂物（不可装备）' : slotZh(it.slot)}`)}</div>`);
  if (it.slot === 'weapon' && Number(it.equipped) === 1) {
    const h = it.weapon_hand;
    blocks.push(
      `<div class="detail-line">${escapeHtml(h === 'off' ? '佩戴位：副手（左），不参与主手伤害骰' : '佩戴位：主手（右），用于伤害骰与力量加成')}</div>`
    );
  }
  if (it.slot === 'weapon') {
    blocks.push(`<div class="detail-line">${escapeHtml(`佩戴限制：${weaponAllowLabel(it)}`)}</div>`);
  }
  blocks.push(`<div class="detail-line">稀有度：${rarityTagHtml(it.rarity)}</div>`);
  const pl = Number(it.plus_level) || 0;
  const diceStr = it.damage_dice || '1d4';
  if (['weapon', 'armor', 'ring', 'boots'].includes(it.slot)) {
    const ch = nextEnhanceChancePercent(pl, diceStr);
    const gc = nextEnhanceGoldCost(pl);
    if (pl > 0) {
      blocks.push(
        `<div class="detail-line">${escapeHtml(
          `强化：+${pl}（属性加成每层约 ×1.5，伤害骰不变；下一级约 ${ch}% 成功，需 ${gc} 金币）`
        )}</div>`
      );
    } else {
      blocks.push(`<div class="detail-line">${escapeHtml(`强化：+0（下一级约 ${ch}% 成功，需 ${gc} 金币）`)}</div>`);
    }
    blocks.push(
      `<div class="detail-line">强化操作：请前往 <a href="enhance.html" class="detail-inline-link">强化工坊</a></div>`
    );
  }
  if (it.slot === 'weapon' || it.slot === 'boots' || it.slot === 'armor' || (it.damage_dice && it.damage_dice !== '1d4')) {
    blocks.push(`<div class="detail-line">伤害骰：${diceTagHtml(it.damage_dice || '1d4')}</div>`);
  }
  if (it.slot === 'weapon' || it.slot === 'armor' || it.slot === 'boots') {
    const [dmn, dmx] = damageDiceMinMaxPair(it.damage_dice || '1d4');
    blocks.push(
      `<div class="detail-line">${escapeHtml(`该骰字面区间：${dmn}～${dmx}（强化会追加 +2/级；武器命中另加力量调整）`)}</div>`
    );
  }
  if (it.slot === 'boots') {
    blocks.push(
      `<div class="detail-line">${escapeHtml(
        '陷阱规避：排行分 = 伤害骰 n×m × max(1,强化等级)，与「陷阱减免基准」叠加后决定减震区间；穿装后的具体上下限见属性面板。'
      )}</div>`
    );
  }
  if (Number(it.bonus_str)) blocks.push(`<div class="detail-line">${escapeHtml(`力量：+${it.bonus_str}`)}</div>`);
  if (Number(it.bonus_dex)) blocks.push(`<div class="detail-line">${escapeHtml(`敏捷：+${it.bonus_dex}`)}</div>`);
  if (Number(it.bonus_con)) blocks.push(`<div class="detail-line">${escapeHtml(`体质：+${it.bonus_con}`)}</div>`);
  if (Number(it.bonus_ac)) blocks.push(`<div class="detail-line">${escapeHtml(`护甲加值：+${it.bonus_ac}`)}</div>`);
  if (Number(it.bonus_trap))
    blocks.push(`<div class="detail-line">${escapeHtml(`陷阱减免基准：+${it.bonus_trap}（随强化层数缩放）`)}</div>`);
  if (itemCannotSellOrAuction(it)) {
    blocks.push(
      `<div class="detail-line">${escapeHtml('该物品不可出售给商店，亦不可上架拍卖行。')}</div>`
    );
  } else {
    const sell = it.shop_sell_gold;
    if (sell != null && sell !== '') {
      blocks.push(`<div class="detail-line">${escapeHtml(`商店收购价：${sell} 金币`)}</div>`);
    } else {
      blocks.push(`<div class="detail-line">${escapeHtml('商店收购价：—（未定价）')}</div>`);
    }
  }
  return blocks.join('');
}

function showItemDetail(game, it, tag) {
  const pane = qs('item-detail-pane');
  if (!pane || !it) return;
  const prefix = tag ? escapeHtml(tag) : '';
  const pl = Number(it.plus_level) || 0;
  const plusTitle = pl > 0 ? ` +${pl}` : '';
  const title = prefix + escapeHtml(it.label) + escapeHtml(plusTitle);
  const rawDesc = String(it.item_desc || it.desc || '').trim();
  const descHtml = rawDesc
    ? escapeHtml(rawDesc).replace(/\n/g, '<br>')
    : '暂无背景介绍。';
  const canUnequip =
    game &&
    it &&
    Number(it.equipped) === 1 &&
    ['weapon', 'armor', 'ring', 'boots'].includes(it.slot) &&
    Number(it.id) > 0;
  const canLearnBook =
    game &&
    it &&
    Number(it.id) > 0 &&
    Number(it.equipped) !== 1 &&
    Number(it.in_warehouse) !== 1 &&
    String(it.item_key || '').indexOf('skill_book_') === 0;
  const actionBits = [];
  if (canUnequip) actionBits.push('<button type="button" class="detail-action-btn detail-unequip-btn">卸下</button>');
  if (canLearnBook) actionBits.push('<button type="button" class="detail-action-btn detail-skillbook-btn">学习技能书</button>');
  const canSell =
    game &&
    it &&
    Number(it.id) > 0 &&
    Number(it.equipped) !== 1 &&
    Number(it.in_warehouse) !== 1 &&
    !itemCannotSellOrAuction(it);
  if (canSell) actionBits.push('<button type="button" class="detail-action-btn detail-sell-btn">出售</button>');
  const actionsHtml = actionBits.length ? `<div class="item-detail-actions">${actionBits.join('')}</div>` : '';
  const whNote =
    Number(it.in_warehouse) === 1
      ? '<p class="detail-warehouse-note">此物品在仓库中：取出到背包后方可出售、强化或上架拍卖。</p>'
      : '';
  pane.innerHTML = `
    <h3 class="detail-title">${title}</h3>
    <p class="detail-desc">${descHtml}</p>
    ${whNote}
    <div class="detail-box"><strong>数值与词条</strong>${buildItemStatLines(it)}</div>
    ${actionsHtml}
  `;
  const uBtn = pane.querySelector('.detail-unequip-btn');
  if (uBtn && game) {
    uBtn.onclick = async () => {
      try {
        const data = await gameApi('unequip', { item_id: it.id });
        if (typeof game.applyPlayerPayload === 'function') {
          game.applyPlayerPayload(data);
        }
        refreshAllInventoryUI(game, data.inventory || []);
        if (typeof game.toast === 'function') game.toast('已卸下');
        showPlaceholderDetail();
      } catch (err) {
        if (typeof game.toast === 'function') game.toast(String(err.message || err));
      }
    };
  }
  const sBtn = pane.querySelector('.detail-skillbook-btn');
  if (sBtn && game) {
    sBtn.onclick = async () => {
      try {
        const data = await gameApi('skills_learn_book', { item_id: it.id });
        if (typeof game.applyPlayerPayload === 'function') {
          game.applyPlayerPayload(data);
        }
        refreshAllInventoryUI(game, data.inventory || []);
        if (typeof game.toast === 'function') game.toast(data.skill_message || '技能学习成功');
        showPlaceholderDetail();
      } catch (err) {
        if (typeof game.toast === 'function') game.toast(String(err.message || err));
      }
    };
  }
  const sellBtn = pane.querySelector('.detail-sell-btn');
  if (sellBtn && game) {
    sellBtn.onclick = () => {
      const m = qs('sell-one-modal');
      const sum = qs('sell-one-summary');
      const price = it.shop_sell_gold != null ? it.shop_sell_gold : '—';
      if (m && sum) {
        m.dataset.itemId = String(it.id);
        sum.textContent = `确定出售「${it.label}」？将获得 ${price} 金币（不可撤销）。`;
        openModal(m);
      }
    };
  }
}

function showMonsterDetail(m, spriteIndexLabel) {
  const pane = qs('item-detail-pane');
  if (!pane || !m) return;
  const descHtml = escapeHtml(m.desc || '').replace(/\n/g, '<br>');
  const picHint =
    spriteIndexLabel != null
      ? `<div class="detail-line">地城贴图：img/monsters/${escapeHtml(spriteIndexLabel)}.*（monsters.json 的 image_num，与物品编号规则相同）</div>`
      : '<div class="detail-line">未配置 image_num 时使用怪物 key 文件名或远程 sprite</div>';
  pane.innerHTML = `
    <h3 class="detail-title">【魔物图鉴】${escapeHtml(m.label)}</h3>
    ${picHint}
    <p class="detail-desc">${descHtml}</p>
    <div class="detail-box">
      <strong>战斗参考（未计层数加成）</strong>
      <div class="detail-line">生命：${m.hp}</div>
      <div class="detail-line">护甲 AC：${m.ac}</div>
      <div class="detail-line">命中加值：+${m.to_hit}</div>
      <div class="detail-line">伤害骰：${diceTagHtml(m.damage)}</div>
    </div>
  `;
}

function showPlaceholderDetail() {
  const pane = qs('item-detail-pane');
  if (!pane) return;
  pane.innerHTML =
    '<p class="detail-placeholder">点击左侧<strong>装备槽</strong>、下方<strong>背包</strong>中的物品，或<strong>拍卖行</strong>条目，可在此查看<strong>物品介绍</strong>与<strong>属性</strong>。点击<strong>地城图鉴</strong>中的名称可阅读<strong>怪物设定</strong>。</p>';
}

function fmtMod(n) {
  return (n >= 0 ? '+' : '') + n;
}

function renderAttrPanel(p) {
  const el = qs('town-stats');
  if (!el || !p) return;
  const adv = p.adventurer_title;
  const guildHtml =
    p.guild && p.guild.name
      ? `公会：${escapeHtml(p.guild.name)}（${p.guild.role === 'leader' ? '会长' : '成员'}）`
      : '公会：未加入 · <a href="guild.html" class="detail-inline-link">公会大厅</a>';
  const advBlock =
    adv && adv.label
      ? `<div class="town-adv-banner"><div class="adv-title adv-title--${escapeHtml(String(adv.slug || 'porcelain'))}">${escapeHtml(
          String(adv.label)
        )}</div><div class="adv-title-sub">历史最深 ${Number(adv.peak) || 1} 层 · 全服解锁 ${Number(adv.world_max) || 1} 层</div><div class="town-guild-line">${guildHtml}</div></div>`
      : `<div class="town-adv-banner"><div class="town-guild-line">${guildHtml}</div></div>`;
  const et = p.equipped_title;
  const titleBand =
    et && et.label
      ? `<div class="town-equipped-title-line">展示称号：<strong>${escapeHtml(String(et.label))}</strong>${
          et.item_desc
            ? ` · <span class="town-title-desc">${escapeHtml(String(et.item_desc))}</span>`
            : ''
        }</div>`
      : '';
  const N = (x, d) => (x != null && x !== '' && !Number.isNaN(Number(x)) ? Number(x) : d);
  const se = p.str_effective != null ? p.str_effective : p.str;
  const de = p.dex_effective != null ? p.dex_effective : p.dex;
  const ce = p.con_effective != null ? p.con_effective : p.con;
  const smb = p.str_mod_base != null ? p.str_mod_base : null;
  const dmb = p.dex_mod_base != null ? p.dex_mod_base : null;
  const strLine =
    smb != null
      ? `力量 STR：基础 ${p.str} → 装备后有效 ${se}，调整值 ${fmtMod(p.str_mod)}（裸装 ${fmtMod(smb)}）— 近战命中与伤害`
      : `力量 STR：${p.str}，调整值 ${fmtMod(p.str_mod)} — 近战命中与伤害`;
  const dexLine =
    dmb != null
      ? `敏捷 DEX：基础 ${p.dex} → 有效 ${de}，调整值 ${fmtMod(p.dex_mod)}（裸装 ${fmtMod(dmb)}）— 影响 AC 等`
      : `敏捷 DEX：${p.dex}，调整值 ${fmtMod(p.dex_mod)} — 影响 AC 等`;
  const conLine =
    p.con_effective != null
      ? `体质 CON：基础 ${p.con} → 有效 ${ce}（生命上限已按有效体质计算）`
      : `体质 CON：${p.con} — 影响生命成长`;
  const fmtSlot = (v) => (typeof v === 'string' ? v : String(Number(v ?? 0)));
  const xpMeter = p.xp_meter;
  const s1 = xpMeter && Array.isArray(xpMeter.slots) ? fmtSlot(xpMeter.slots[1]) : '0';
  const s2 = xpMeter && Array.isArray(xpMeter.slots) ? fmtSlot(xpMeter.slots[2]) : '0';
  const xpMeterBlock =
    xpMeter && Array.isArray(xpMeter.slots) && xpMeter.slots.length >= 3 && (s1 !== '0' || s2 !== '0')
      ? `<div class="stat-line stat-xp-meter">经验分栏（每栏满 ${fmtSlot(xpMeter.max_per_slot)} 向下一栏进 1）：` +
        `<span class="xp-slot">栏一 ${fmtSlot(xpMeter.slots[0])}</span> · ` +
        `<span class="xp-slot">栏二 ${s1}</span> · ` +
        `<span class="xp-slot">栏三 ${s2}</span></div>`
      : '';
  const metaLine =
    window.PlayerMetaBar && typeof PlayerMetaBar.statLineHtml === 'function'
      ? PlayerMetaBar.statLineHtml(p)
      : (() => {
          const pf = p.profession || {};
          const prof = escapeHtml(String(pf.label || '无职业'));
          const crystals = Math.max(0, Number(p.sunbeam_crystal_count) || 0);
          return `<div class="stat-line stat-prof-crystal">职业：<strong>${prof}</strong> · 曦光晶屑：<strong>${crystals}</strong></div>`;
        })();
  el.innerHTML = `${advBlock}${titleBand}
    <div class="attr-block">
      <h3 class="attr-sub">冒险概要</h3>
      ${metaLine}
      <div class="stat-line">等级：${escapeHtml(String(p.level_display || p.level))}</div>
      ${xpMeterBlock}
      <div class="stat-line">经验值：${
        typeof p.xp === 'string' ? p.xp : String(Number(p.xp ?? 0))
      } · <span class="stat-xp-next">${Number(p.level || 0) > 300 ? '距离下一阶还需' : '距离下一级还需'} <strong>${
        p.xp_to_next_level == null || p.xp_to_next_level === ''
          ? '0'
          : typeof p.xp_to_next_level === 'string'
          ? p.xp_to_next_level
          : String(Number(p.xp_to_next_level))
      }</strong> 点经验</span></div>
      <div class="stat-line">金币：${p.gold}</div>
      <div class="stat-line">生命上限：${p.hp_max}</div>
      <div class="stat-line">护甲（AC）：${p.ac}</div>
      <div class="stat-line">武器伤害骰：${diceTagHtml(p.weapon_dice)}（骰面 ${N(p.weapon_roll_min, 1)}～${N(p.weapon_roll_max, 1)}）</div>
      <div class="stat-line">武器命中伤害：${N(p.weapon_hit_dmg_min, 1)}～${N(p.weapon_hit_dmg_max, 1)}（已含力量调整 ${fmtMod(Number(p.str_mod) || 0)}）</div>
      <div class="stat-line">护甲骰 ${diceTagHtml(p.armor_dice || '1d4')}：${N(p.armor_roll_min, 1)}～${N(p.armor_roll_max, 1)} · 护甲件 AC +${N(p.armor_ac_bonus, 0)}</div>
      <div class="stat-line">陷阱：基础 ${N(p.trap_raw_min, 4)}～${N(p.trap_raw_max, 11)} HP · 鞋减震 ${N(p.trap_mitig_min, 0)}～${N(p.trap_mitig_max, 0)} · 最终约 ${N(p.trap_final_dmg_min, 1)}～${N(p.trap_final_dmg_max, 11)} HP</div>
    </div>
    <div class="attr-block">
      <h3 class="attr-sub">属性面板（含装备加成）</h3>
      <div class="stat-line">${strLine}</div>
      <div class="stat-line">${dexLine}</div>
      <div class="stat-line">${conLine}</div>
      <div class="stat-line">智力 INT：${p.int_stat}</div>
      <div class="stat-line">感知 WIS：${p.wis}</div>
      <div class="stat-line">魅力 CHA：${p.cha}</div>
    </div>
    <p class="attr-footnote">地城内战斗数值在进入地下城时按当前装备载入；击杀同步后会刷新。</p>
  `;
}

function renderTitlesPanel(game, p) {
  const host = qs('town-titles');
  if (!host) return;
  if (!p) {
    host.innerHTML = '';
    return;
  }
  const list = Array.isArray(p.owned_titles) ? p.owned_titles : [];
  if (!list.length) {
    host.innerHTML =
      '<div class="empty-hint">暂无称号。获得称号后会显示在这里，可选择是否对外展示。</div>';
    return;
  }
  host.innerHTML = '';
  list.forEach((t) => {
    const row = document.createElement('div');
    row.className = 'town-title-row' + (t.equipped ? ' equipped' : '');
    const lab = document.createElement('div');
    lab.style.flex = '1';
    lab.style.minWidth = '0';
    lab.innerHTML = `${rarityTagHtml(t.rarity)} <strong>${escapeHtml(t.label)}</strong> <span class="town-title-key">${escapeHtml(
      t.title_key || ''
    )}</span>${t.item_desc ? `<div class="town-title-desc">${escapeHtml(t.item_desc)}</div>` : ''}`;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = t.equipped ? 'town-dg-btn town-dg-btn-secondary' : 'town-dg-btn town-dg-btn-primary';
    btn.textContent = t.equipped ? '卸下' : '装备展示';
    btn.onclick = async () => {
      try {
        const res = t.equipped
          ? await gameApi('title_unequip', {})
          : await gameApi('title_equip', { player_title_id: t.player_title_id });
        game.applyPlayerPayload(res);
        if (res.player) renderAttrPanel(res.player);
        refreshAllInventoryUI(game, res.inventory || []);
        game.toast(t.equipped ? '已卸下称号' : '已装备称号');
      } catch (e) {
        game.toast(String(e.message || e));
      }
    };
    row.appendChild(lab);
    row.appendChild(btn);
    host.appendChild(row);
  });
}

function renderEquipSlots(game, inv) {
  const host = qs('equip-slots');
  if (!host) return;
  host.innerHTML = '';
  const slots = [
    { key: 'weapon_main', label: '主手（右）' },
    { key: 'weapon_off', label: '副手（左）' },
    { key: 'armor', label: '护甲' },
    { key: 'ring', label: '戒指' },
    { key: 'boots', label: '鞋' },
  ];
  slots.forEach(({ key, label }) => {
    const equipped = equippedForSlotKey(inv, key);
    const box = document.createElement('div');
    box.className = 'equip-slot' + (equipped ? ' filled' : ' empty');
    if (equipped) {
      const epl = Number(equipped.plus_level) || 0;
      const plusBit = epl > 0 ? ` +${epl}` : '';
      box.innerHTML = `<div class="equip-slot-label">${escapeHtml(label)}</div><div class="equip-slot-name">${escapeHtml(equipped.label)}${escapeHtml(plusBit)}</div><div class="equip-slot-meta">${rarityTagHtml(equipped.rarity)} · ${diceTagHtml(
        equipped.damage_dice || '1d4'
      )}</div>`;
    } else {
      box.innerHTML = `<div class="equip-slot-label">${escapeHtml(label)}</div><div class="equip-slot-empty">（空）</div>`;
    }
    box.onclick = () => {
      if (equipped) showItemDetail(game, equipped, '【已装备】');
      else {
        showPlaceholderDetail();
        game.toast('该槽位暂无装备，请从背包选择物品装备到对应位置');
      }
    };
    host.appendChild(box);
  });
}

function renderWarehouse(game, inv) {
  const el = qs('warehouse-list');
  if (!el) return;
  el.innerHTML = '';
  const wh = inv.filter((x) => Number(x.equipped) !== 1 && Number(x.in_warehouse) === 1);
  if (!wh.length) {
    el.innerHTML = '<div class="empty-hint">仓库为空。在背包中点击「入仓」可放入（不入背包出售范围）。</div>';
    return;
  }
  const tb = document.createElement('div');
  tb.className = 'bag-toolbar bag-misc-wh-toolbar';
  const btnAll = document.createElement('button');
  btnAll.type = 'button';
  btnAll.className = 'bag-misc-wh-btn';
  btnAll.textContent = `全部取出到背包（${wh.length}）`;
  btnAll.onclick = async () => {
    const n = wh.length;
    if (!window.confirm(`将仓库中 ${n} 件物品全部取出到背包？`)) return;
    try {
      const data = await gameApi('warehouse_recall_all', {});
      game.applyPlayerPayload(data);
      refreshAllInventoryUI(game, data.inventory || []);
      const moved = Number(data.warehouse_recall_moved);
      game.toast(Number.isFinite(moved) && moved >= 0 ? `已取出 ${moved} 件到背包` : '操作完成');
    } catch (err) {
      game.toast(String(err.message || err));
    }
  };
  tb.appendChild(btnAll);
  el.appendChild(tb);
  wh.forEach((it) => {
    const row = document.createElement('div');
    row.className = 'bag-row warehouse-row';
    const info = document.createElement('div');
    info.className = 'bag-info';
    const bpl = Number(it.plus_level) || 0;
    const bagPlus = bpl > 0 ? ` +${bpl}` : '';
    info.innerHTML = `<span class="bag-name">${escapeHtml(it.label)}${escapeHtml(bagPlus)}</span><span class="bag-meta">${escapeHtml(slotZh(it.slot))} · ${rarityTagHtml(it.rarity)} · 仓库中 · 点击查看介绍</span>`;
    info.onclick = () => showItemDetail(game, it, '【仓库】');
    const actions = document.createElement('div');
    actions.className = 'bag-actions';
    const out = document.createElement('button');
    out.type = 'button';
    out.textContent = '取出到背包';
    out.onclick = async (e) => {
      e.stopPropagation();
      try {
        const data = await gameApi('warehouse_set', { item_id: it.id, warehouse: 0 });
        game.applyPlayerPayload(data);
        refreshAllInventoryUI(game, data.inventory || []);
        game.toast('已取出到背包');
      } catch (err) {
        game.toast(String(err.message || err));
      }
    };
    actions.appendChild(out);
    row.appendChild(info);
    row.appendChild(actions);
    el.appendChild(row);
  });
}

function renderBag(game, inv) {
  const el = qs('bag-list');
  if (!el) return;
  el.innerHTML = '';
  const bag = inv.filter((x) => Number(x.equipped) !== 1 && Number(x.in_warehouse) !== 1);
  if (!bag.length) {
    el.innerHTML = '<div class="empty-hint">背包为空。已穿戴的物品显示在左侧装备栏。</div>';
    return;
  }
  const miscInBag = bag.filter((it) => it.slot === 'misc');
  if (miscInBag.length) {
    const tb = document.createElement('div');
    tb.className = 'bag-toolbar bag-misc-wh-toolbar';
    const btnAll = document.createElement('button');
    btnAll.type = 'button';
    btnAll.className = 'bag-misc-wh-btn';
    btnAll.textContent = `杂物全部入仓（${miscInBag.length}）`;
    btnAll.onclick = async () => {
      const n = miscInBag.length;
      if (!window.confirm(`将背包中 ${n} 件杂物全部放入仓库？`)) return;
      try {
        const data = await gameApi('warehouse_misc_all', {});
        game.applyPlayerPayload(data);
        refreshAllInventoryUI(game, data.inventory || []);
        const moved = Number(data.warehouse_misc_moved);
        game.toast(Number.isFinite(moved) && moved >= 0 ? `已将 ${moved} 件杂物放入仓库` : '操作完成');
      } catch (err) {
        game.toast(String(err.message || err));
      }
    };
    tb.appendChild(btnAll);
    el.appendChild(tb);
  }
  bag.forEach((it) => {
    const row = document.createElement('div');
    row.className = 'bag-row';
    const info = document.createElement('div');
    info.className = 'bag-info';
    const bpl = Number(it.plus_level) || 0;
    const bagPlus = bpl > 0 ? ` +${bpl}` : '';
    info.innerHTML = `<span class="bag-name">${escapeHtml(it.label)}${escapeHtml(bagPlus)}</span><span class="bag-meta">${escapeHtml(slotZh(it.slot))} · ${rarityTagHtml(it.rarity)} · ${diceTagHtml(
      it.damage_dice || '1d4'
    )} · 点击查看介绍</span>`;
    info.onclick = () => showItemDetail(game, it, '【背包】');
    const actions = document.createElement('div');
    actions.className = 'bag-actions';
    if (it.slot === 'weapon') {
      if (weaponAllowMainOk(it)) {
        const bm = document.createElement('button');
        bm.type = 'button';
        bm.textContent = '主手(右)';
        bm.onclick = async (e) => {
          e.stopPropagation();
          try {
            const data = await gameApi('equip', { item_id: it.id, hand: 'main' });
            game.applyPlayerPayload(data);
            refreshAllInventoryUI(game, data.inventory || []);
            game.toast('已装备到主手（右）');
          } catch (err) {
            game.toast(String(err.message || err));
          }
        };
        actions.appendChild(bm);
      }
      if (weaponAllowOffOk(it)) {
        const bo = document.createElement('button');
        bo.type = 'button';
        bo.textContent = '副手(左)';
        bo.onclick = async (e) => {
          e.stopPropagation();
          try {
            const data = await gameApi('equip', { item_id: it.id, hand: 'off' });
            game.applyPlayerPayload(data);
            refreshAllInventoryUI(game, data.inventory || []);
            game.toast('已装备到副手（左）');
          } catch (err) {
            game.toast(String(err.message || err));
          }
        };
        actions.appendChild(bo);
      }
    } else if (['armor', 'ring', 'boots'].includes(it.slot)) {
      const b1 = document.createElement('button');
      b1.type = 'button';
      b1.textContent = '装备';
      b1.onclick = async (e) => {
        e.stopPropagation();
        try {
          const data = await gameApi('equip', { item_id: it.id });
          game.applyPlayerPayload(data);
          refreshAllInventoryUI(game, data.inventory || []);
          game.toast('装备已更换');
        } catch (err) {
          game.toast(String(err.message || err));
        }
      };
      actions.appendChild(b1);
    }
    const bWh = document.createElement('button');
    bWh.type = 'button';
    bWh.textContent = '入仓';
    bWh.onclick = async (e) => {
      e.stopPropagation();
      try {
        const data = await gameApi('warehouse_set', { item_id: it.id, warehouse: 1 });
        game.applyPlayerPayload(data);
        refreshAllInventoryUI(game, data.inventory || []);
        game.toast('已放入仓库');
      } catch (err) {
        game.toast(String(err.message || err));
      }
    };
    actions.appendChild(bWh);
    if (!itemCannotSellOrAuction(it)) {
      const b2 = document.createElement('button');
      b2.type = 'button';
      b2.textContent = '出售';
      b2.onclick = (e) => {
        e.stopPropagation();
        const m = qs('sell-one-modal');
        const sum = qs('sell-one-summary');
        const price = it.shop_sell_gold != null ? it.shop_sell_gold : '—';
        if (m && sum) {
          m.dataset.itemId = String(it.id);
          sum.textContent = `确定出售「${it.label}」？将获得 ${price} 金币（不可撤销）。`;
          openModal(m);
          return;
        }
        if (!window.confirm(`确定出售「${it.label}」？将获得 ${price} 金币。`)) return;
        gameApi('sell', { item_id: it.id })
          .then((data) => {
            game.applyPlayerPayload(data);
            refreshAllInventoryUI(game, data.inventory || []);
            if (qs('town-stats') && data.player) renderAttrPanel(data.player);
            game.toast('卖出获得 ' + (data.sold_for || 0) + ' 金币');
          })
          .catch((err) => game.toast(String(err.message || err)));
      };
      actions.appendChild(b2);
    }
    row.appendChild(info);
    row.appendChild(actions);
    el.appendChild(row);
  });
}

function refreshAllInventoryUI(game, inv, opts) {
  if (game && game.state && Array.isArray(inv)) game.state.inventory = inv;
  renderEquipSlots(game, inv);
  renderBag(game, inv);
  renderWarehouse(game, inv);
  if (qs('town-stats') && game.state && game.state.player) renderAttrPanel(game.state.player);
  if (qs('town-titles') && game.state && game.state.player) renderTitlesPanel(game, game.state.player);
  if (qs('auc-post-item')) populateAuctionSelect(game);
  if (!opts || !opts.skipInventoryHook) {
    if (typeof game.onInventoryChanged === 'function') {
      try {
        game.onInventoryChanged();
      } catch (_) {
        /* ignore */
      }
    }
  }
}

let monsterCodexCache = null;

async function loadMonsterCodex(hostId) {
  const el = qs(hostId || 'monster-codex');
  if (!el) return;
  try {
    if (!monsterCodexCache) {
      const r = await fetch('data/monsters.json');
      monsterCodexCache = await r.json();
    }
    el.innerHTML = '';
    const keys = Object.keys(monsterCodexCache).sort();
    keys.forEach((key) => {
      const m = monsterCodexCache[key];
      const div = document.createElement('div');
      div.className = 'codex-row';
      const n = monsterImageNumFromDef(m);
      const numLabel = n > 0 ? String(n).padStart(4, '0') : '—';
      div.innerHTML = `<span class="codex-num">${escapeHtml(numLabel)}</span><span class="codex-name">${escapeHtml(m.label)}</span><span class="codex-damage">伤害 ${escapeHtml(m.damage)}</span>`;
      div.onclick = () => showMonsterDetail(m, n > 0 ? numLabel : null);
      el.appendChild(div);
    });
  } catch (_) {
    el.innerHTML = '<div class="empty-hint">图鉴数据加载失败。</div>';
  }
}

/** 本城在售（不含本人上架）；分页与筛选由服务端完成 */
let auctionBrowseMeta = { loaded: false, page: 1, total: 0, totalPages: 0, pageSize: 20 };

function appendAuctionRow(el, game, listId, myPid, L, snap) {
  const row = document.createElement('div');
  row.className = 'row auc-row';
  const left = document.createElement('div');
  left.className = 'auc-item';
  const seller = L.seller_username || L.seller_name || '?';
  const recv =
    L.seller_receives_gold != null && L.seller_receives_gold !== ''
      ? Number(L.seller_receives_gold)
      : Number(L.price_gold);
  const recvTxt = Number.isFinite(recv) ? recv : Number(L.price_gold);
  const slotLab = slotZh(snap.slot || '');
  left.innerHTML = `<span class="auc-title">${escapeHtml(snap.label || '?')}</span><span class="auc-sub">${rarityTagHtml(
    snap.rarity || 'common'
  )} · ${escapeHtml(slotLab)} · 挂单价 ${L.price_gold}（买家支付） · 卖家实收约 ${recvTxt} · ${escapeHtml(seller)} · 点击查看介绍</span>`;
  left.onclick = () => showItemDetail(game, snap, '【拍卖行】');
  const actions = document.createElement('div');
  actions.className = 'auc-row-actions';
  if (myPid > 0 && Number(L.seller_id) === myPid) {
    const bc = document.createElement('button');
    bc.type = 'button';
    bc.className = 'auth-btn auc-delist-btn';
    bc.textContent = '下架';
    bc.onclick = async (e) => {
      e.stopPropagation();
      try {
        const res = await gameApi('auction_cancel', { auction_id: L.id });
        game.applyPlayerPayload(res);
        game.toast('已下架，物品已回到背包');
        await refreshAuctionListsAfterMutation(game);
        refreshAllInventoryUI(game, res.inventory || []);
        populateAuctionSelect(game);
      } catch (err) {
        game.toast(String(err.message || err));
      }
    };
    actions.appendChild(bc);
  } else {
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = '购买';
    b.onclick = async (e) => {
      e.stopPropagation();
      try {
        const res = await gameApi('auction_buy', { auction_id: L.id });
        game.applyPlayerPayload(res);
        game.toast('购买成功');
        await refreshAuctionListsAfterMutation(game);
        refreshAllInventoryUI(game, res.inventory || []);
      } catch (err) {
        game.toast(String(err.message || err));
      }
    };
    actions.appendChild(b);
  }
  row.appendChild(left);
  row.appendChild(actions);
  el.appendChild(row);
}

function renderAuctionRowsInto(el, game, listId, listings, emptyHint) {
  if (!el) return;
  el.innerHTML = '';
  const myPid = game.state && game.state.player ? Number(game.state.player.id) : 0;
  const rows = Array.isArray(listings) ? listings : [];
  rows.forEach((L) => {
    let snap = {};
    try {
      snap = JSON.parse(L.item_snapshot || '{}');
    } catch (_) {
      snap = {};
    }
    appendAuctionRow(el, game, listId, myPid, L, snap);
  });
  if (rows.length === 0) {
    el.innerHTML =
      '<p class="empty-hint">' +
      escapeHtml(emptyHint || '没有符合条件的拍卖品，可调整分类或关键词后重新搜索。') +
      '</p>';
  }
}

function updateAuctionBrowsePaginationUi() {
  const info = qs('auc-page-info');
  const prev = qs('auc-page-prev');
  const next = qs('auc-page-next');
  if (info) {
    if (!auctionBrowseMeta.loaded) {
      info.textContent = '';
    } else if (auctionBrowseMeta.total < 1) {
      info.textContent = '共 0 条';
    } else {
      info.textContent = `第 ${auctionBrowseMeta.page} / ${Math.max(1, auctionBrowseMeta.totalPages)} 页 · 共 ${auctionBrowseMeta.total} 条`;
    }
  }
  if (prev) {
    prev.disabled = !auctionBrowseMeta.loaded || auctionBrowseMeta.page <= 1;
  }
  if (next) {
    next.disabled = !auctionBrowseMeta.loaded || auctionBrowseMeta.page >= auctionBrowseMeta.totalPages;
  }
}

async function fetchAuctionBrowsePage(game, page) {
  const listEl = qs('auc-list');
  if (!listEl) return;
  const slot = qs('auc-filter-slot') ? String(qs('auc-filter-slot').value || '').trim() : '';
  const q = qs('auc-filter-q') ? String(qs('auc-filter-q').value || '').trim() : '';
  const p = Math.max(1, Number(page) || 1);
  try {
    const data = await gameApi('auction_list', {
      scope: 'browse',
      page: p,
      page_size: auctionBrowseMeta.pageSize,
      slot,
      q,
    });
    auctionBrowseMeta.loaded = true;
    auctionBrowseMeta.page = data.page != null ? Number(data.page) : p;
    auctionBrowseMeta.total = data.total != null ? Number(data.total) : 0;
    auctionBrowseMeta.totalPages = data.total_pages != null ? Number(data.total_pages) : 0;
    auctionBrowseMeta.pageSize = data.page_size != null ? Number(data.page_size) : auctionBrowseMeta.pageSize;
    renderAuctionRowsInto(listEl, game, 'auc-list', data.listings || [], null);
    updateAuctionBrowsePaginationUi();
  } catch (e) {
    game.toast(String(e.message || e));
  }
}

async function runAuctionBrowseSearch(game) {
  auctionBrowseMeta.loaded = true;
  auctionBrowseMeta.page = 1;
  await fetchAuctionBrowsePage(game, 1);
}

async function refreshAuctionMine(game) {
  const el = qs('auc-my-list');
  if (!el) return;
  try {
    const data = await gameApi('auction_list', { scope: 'mine' });
    renderAuctionRowsInto(el, game, 'auc-my-list', data.listings || [], '您在当前主城拍卖行暂无上架物品。');
    const hint = qs('auc-my-count');
    if (hint) {
      const n = Array.isArray(data.listings) ? data.listings.length : 0;
      hint.textContent = n > 0 ? `（${n} 件）` : '（暂无）';
    }
    const btnAll = qs('auc-cancel-all-btn');
    if (btnAll) {
      const n = Array.isArray(data.listings) ? data.listings.length : 0;
      btnAll.disabled = n < 1;
    }
  } catch (e) {
    game.toast(String(e.message || e));
  }
}

async function refreshAuctionListsAfterMutation(game) {
  await refreshAuctionMine(game);
  if (auctionBrowseMeta.loaded) {
    await fetchAuctionBrowsePage(game, auctionBrowseMeta.page);
  }
}

async function refreshAuction(game, listId) {
  await refreshAuctionMine(game);
  const el = qs(listId || 'auc-list');
  if (el && !auctionBrowseMeta.loaded) {
    el.innerHTML =
      '<p class="empty-hint">可选：分类、名称或物品 id；设置后点击「搜索」浏览本城他人上架（当前主城拍卖行）。</p>';
    updateAuctionBrowsePaginationUi();
  } else if (auctionBrowseMeta.loaded) {
    await fetchAuctionBrowsePage(game, auctionBrowseMeta.page);
  }
}

function renderLbPersonRow(r, i, extraLineHtml) {
  const who = r.username || r.display_name || '?';
  const ex = extraLineHtml || '';
  return `<div class="row lb-row"><div class="lb-rank">${i + 1}</div><div class="lb-main"><div class="lb-name">${escapeHtml(who)}</div><div class="lb-stats">等级 ${r.level} · 经验 ${r.xp} · 金币 ${r.gold}</div><div class="lb-abilities">STR 有效 ${r.str_effective}（调整 ${fmtMod(Number(r.str_mod) || 0)}） · DEX 有效 ${r.dex_effective}（调整 ${fmtMod(Number(r.dex_mod) || 0)}） · CON 有效 ${r.con_effective} · AC ${r.ac}</div><div class="lb-abilities">武器骰 ${diceTagHtml(r.weapon_dice || '1d4')}</div>${ex}</div></div>`;
}

function renderLbGearRow(r, i) {
  const who = r.username || r.display_name || '?';
  const dice = r.damage_dice || '1d4';
  return `<div class="row lb-row"><div class="lb-rank">${i + 1}</div><div class="lb-main"><div class="lb-name">${escapeHtml(who)}</div><div class="lb-stats">${escapeHtml(r.item_label || '—')} · +${Number(r.plus_level) || 0}</div><div class="lb-abilities">排行分 ${Number(r.score) || 0} · 伤害骰 ${diceTagHtml(dice)}</div></div></div>`;
}

function renderLbBossFirstRow(r, i) {
  const who = r.first_killer_username || '?';
  const ms = Number(r.milestone) || 0;
  const t = r.first_killed_at ? escapeHtml(String(r.first_killed_at)) : '—';
  return `<div class="row lb-row"><div class="lb-rank">${i + 1}</div><div class="lb-main"><div class="lb-name">${escapeHtml(who)}</div><div class="lb-stats">首杀第 ${ms} 层世界首领</div><div class="lb-abilities">时间 ${t}</div></div></div>`;
}

async function refreshLeaderboard() {
  const data = await gameApi('leaderboard', {});
  const host = qs('lb-boards');
  const legacy = qs('lb-list');
  const boards = data.boards || {};
  if (host) {
    const sections = [
      { key: 'xp', title: '经验榜（前 10）', type: 'person' },
      { key: 'gold', title: '金币榜（前 10）', type: 'person' },
      { key: 'boss_first', title: '世界首领首杀（层数从高到低，最多 10 条）', type: 'boss_first' },
      { key: 'weapon', title: '武器榜（已装备，按排行分）', type: 'gear' },
      { key: 'armor', title: '护甲榜（已装备，按排行分）', type: 'gear' },
      { key: 'ring', title: '戒指榜（已装备，按排行分）', type: 'gear' },
      { key: 'boots', title: '鞋子榜（已装备，按排行分）', type: 'gear' },
    ];
    host.innerHTML = sections
      .map((sec) => {
        const rows = boards[sec.key] || [];
        let inner = '';
        if (sec.type === 'person') inner = rows.map((r, i) => renderLbPersonRow(r, i, '')).join('');
        else if (sec.type === 'boss_first') inner = rows.map((r, i) => renderLbBossFirstRow(r, i)).join('');
        else inner = rows.map((r, i) => renderLbGearRow(r, i)).join('');
        const empty = rows.length ? '' : '<p class="empty-hint">暂无数据</p>';
        return `<section class="lb-section"><h2 class="lb-section-title">${escapeHtml(sec.title)}</h2><div class="lb-section-body">${inner}${empty}</div></section>`;
      })
      .join('');
    return;
  }
  if (legacy) {
    const rows = boards.xp || [];
    legacy.innerHTML = rows.map((r, i) => renderLbPersonRow(r, i, '')).join('');
  }
}

function openModal(el) {
  if (!el) return;
  el.hidden = false;
  el.removeAttribute('hidden');
  el.classList.add('is-open');
}

function closeModal(el) {
  if (!el) return;
  el.hidden = true;
  el.setAttribute('hidden', '');
  el.classList.remove('is-open');
}

function wireSellButton(game, btn, openFn) {
  if (!btn || btn.dataset.sellWired === '1') return;
  btn.dataset.sellWired = '1';
  btn.addEventListener('click', (ev) => {
    ev.preventDefault();
    openFn();
  });
}

function initTownUI(game) {
  if (!game) return;
  const sellOneModal = qs('sell-one-modal');
  const sellOneSummary = qs('sell-one-summary');
  const sellOneCancel = qs('sell-one-cancel');
  const sellOneConfirm = qs('sell-one-confirm');
  if (sellOneCancel && sellOneModal) {
    sellOneCancel.onclick = () => {
      closeModal(sellOneModal);
      delete sellOneModal.dataset.itemId;
    };
  }
  if (sellOneConfirm && sellOneModal) {
    sellOneConfirm.onclick = async () => {
      const id = Number(sellOneModal.dataset.itemId || 0);
      closeModal(sellOneModal);
      delete sellOneModal.dataset.itemId;
      if (id < 1) return;
      try {
        const data = await gameApi('sell', { item_id: id });
        game.applyPlayerPayload(data);
        refreshAllInventoryUI(game, data.inventory || []);
        if (qs('town-stats') && data.player) renderAttrPanel(data.player);
        game.toast('卖出获得 ' + (data.sold_for || 0) + ' 金币');
      } catch (e) {
        game.toast(String(e.message || e));
      }
    };
  }

  const sellModal = qs('sell-all-modal');
  const btnSellAll = qs('btn-sell-all');
  const sellCancel = qs('sell-all-cancel');
  const sellConfirm = qs('sell-all-confirm');
  wireSellButton(game, btnSellAll, () => {
    openModal(sellModal);
    const sum = qs('sell-all-summary');
    if (sum) sum.textContent = '正在计算…';
    gameApi('sell_all_preview', {})
      .then((p) => {
        if (!sum) return;
        sum.textContent =
          (p.count || 0) > 0
            ? `将卖出 ${p.count} 件未装备物品，预计共获得 ${p.total_gold} 金币。确认后不可撤销。`
            : '当前没有可卖出的未装备物品（已装备需先卸下）。';
      })
      .catch((e) => {
        if (sum) sum.textContent = '无法预览：' + (e.message || e);
      });
  });
  if (sellCancel && sellModal) {
    sellCancel.onclick = () => closeModal(sellModal);
  }
  if (sellConfirm && sellModal) {
    sellConfirm.onclick = async () => {
      closeModal(sellModal);
      try {
        const data = await gameApi('sell_all', {});
        game.applyPlayerPayload(data);
        refreshAllInventoryUI(game, data.inventory || []);
        if (qs('town-stats') && data.player) renderAttrPanel(data.player);
        game.toast(`已卖出 ${data.sell_all_count || 0} 件，共 ${data.sell_all_gold || 0} 金币`);
      } catch (e) {
        game.toast(String(e.message || e));
      }
    };
  }

  const sellEquivModal = qs('sell-all-except-equiv-modal');
  const btnSellAllExceptEquiv = qs('btn-sell-all-except-equiv');
  const sellEquivCancel = qs('sell-all-except-equiv-cancel');
  const sellEquivConfirm = qs('sell-all-except-equiv-confirm');
  wireSellButton(game, btnSellAllExceptEquiv, () => {
    openModal(sellEquivModal);
    const sumEq = qs('sell-all-except-equiv-summary');
    if (sumEq) sumEq.textContent = '正在计算…';
    gameApi('sell_all_except_equiv_preview', {})
      .then((p) => {
        if (!sumEq) return;
        sumEq.textContent =
          (p.count || 0) > 0
            ? `将卖出 ${p.count} 件（已排除等价表物品），预计共获得 ${p.total_gold} 金币。确认后不可撤销。`
            : '当前没有符合条件的物品（可能均在等价表内、已装备或在仓库）。';
      })
      .catch((e) => {
        if (sumEq) sumEq.textContent = '无法预览：' + (e.message || e);
      });
  });
  if (sellEquivCancel && sellEquivModal) {
    sellEquivCancel.onclick = () => closeModal(sellEquivModal);
  }
  if (sellEquivConfirm && sellEquivModal) {
    sellEquivConfirm.onclick = async () => {
      closeModal(sellEquivModal);
      try {
        const data = await gameApi('sell_all_except_equiv', {});
        game.applyPlayerPayload(data);
        refreshAllInventoryUI(game, data.inventory || []);
        if (qs('town-stats') && data.player) renderAttrPanel(data.player);
        game.toast(
          `已卖出 ${data.sell_except_equiv_count || 0} 件（已保留等价表物品），共 ${data.sell_except_equiv_gold || 0} 金币`
        );
      } catch (e) {
        game.toast(String(e.message || e));
      }
    };
  }

  const btnAuc = qs('btn-auction-refresh');
  if (btnAuc) {
    btnAuc.onclick = () => {
      refreshAuctionMine(game)
        .then(() => {
          if (auctionBrowseMeta.loaded) {
            return fetchAuctionBrowsePage(game, auctionBrowseMeta.page);
          }
        })
        .catch((e) => game.toast(String(e.message || e)));
    };
  }
  const btnAucSearch = qs('btn-auction-search');
  if (btnAucSearch && !btnAucSearch.dataset.aucSearchWired) {
    btnAucSearch.dataset.aucSearchWired = '1';
    btnAucSearch.onclick = () => {
      runAuctionBrowseSearch(game).catch((e) => game.toast(String(e.message || e)));
    };
  }
  const aucPrev = qs('auc-page-prev');
  if (aucPrev && !aucPrev.dataset.aucPagWired) {
    aucPrev.dataset.aucPagWired = '1';
    aucPrev.onclick = () => {
      if (auctionBrowseMeta.page <= 1) return;
      fetchAuctionBrowsePage(game, auctionBrowseMeta.page - 1).catch((e) => game.toast(String(e.message || e)));
    };
  }
  const aucNext = qs('auc-page-next');
  if (aucNext && !aucNext.dataset.aucPagWired) {
    aucNext.dataset.aucPagWired = '1';
    aucNext.onclick = () => {
      if (auctionBrowseMeta.page >= auctionBrowseMeta.totalPages) return;
      fetchAuctionBrowsePage(game, auctionBrowseMeta.page + 1).catch((e) => game.toast(String(e.message || e)));
    };
  }
  const btnCancelAll = qs('auc-cancel-all-btn');
  if (btnCancelAll && !btnCancelAll.dataset.aucCancelAllWired) {
    btnCancelAll.dataset.aucCancelAllWired = '1';
    btnCancelAll.onclick = async () => {
      const n = (qs('auc-my-list') && qs('auc-my-list').querySelectorAll('.auc-row').length) || 0;
      if (n < 1) return;
      if (!window.confirm(`确定将当前主城拍卖行中自己上架的 ${n} 件物品全部下架并取回背包？`)) return;
      try {
        const res = await gameApi('auction_cancel_all', {});
        game.applyPlayerPayload(res);
        const c = res.auction_cancel_all_count != null ? Number(res.auction_cancel_all_count) : n;
        game.toast(`已全部下架（${c} 件）`);
        await refreshAuctionListsAfterMutation(game);
        refreshAllInventoryUI(game, res.inventory || []);
        populateAuctionSelect(game);
      } catch (e) {
        game.toast(String(e.message || e));
      }
    };
  }
  const btnLb = qs('btn-lb-refresh');
  if (btnLb) {
    btnLb.onclick = () => {
      refreshLeaderboard().catch((e) => game.toast(String(e.message || e)));
    };
  }

  const aucSel = qs('auc-post-item');
  const aucPostBtn = qs('auc-post-btn');
  if (aucSel && aucPostBtn) {
    aucSel.addEventListener('change', () => {
      aucPostBtn.disabled = !aucSel.value;
      syncAuctionPostMinPriceUi();
    });
    aucPostBtn.onclick = async () => {
      const id = Number(aucSel.value || 0);
      const price = Number((qs('auc-post-price') && qs('auc-post-price').value) || 0);
      if (!id || price < 1) return;
      const opt = aucSel.options[aucSel.selectedIndex];
      const minShop = Number(opt && opt.dataset && opt.dataset.minShopGold);
      const minP = Number.isFinite(minShop) && minShop >= 1 ? minShop : 1;
      if (price < minP) {
        game.toast('上架价不能低于商店收购价（' + minP + ' 金币）');
        return;
      }
      try {
        await gameApi('auction_post', { item_id: id, price_gold: price });
        game.toast('已上架拍卖行');
        const data = await gameApi('player', {});
        game.applyPlayerPayload(data);
        refreshAllInventoryUI(game, data.inventory || []);
        populateAuctionSelect(game);
        await refreshAuction(game);
      } catch (e) {
        game.toast(String(e.message || e));
      }
    };
  }
}

function syncAuctionPostMinPriceUi() {
  const sel = qs('auc-post-item');
  const inp = qs('auc-post-price');
  if (!sel || !inp) return;
  const opt = sel.options[sel.selectedIndex];
  const raw = opt && opt.dataset ? opt.dataset.minShopGold : '';
  const minP = raw !== '' && raw != null ? Number(raw) : 1;
  const m = Number.isFinite(minP) && minP >= 1 ? Math.floor(minP) : 1;
  inp.setAttribute('min', String(m));
  inp.placeholder = '价格（金币，最低 ' + m + '）';
}

function populateAuctionSelect(game) {
  const sel = qs('auc-post-item');
  if (!sel) return;
  sel.innerHTML = '<option value="">选择要上架的背包物品</option>';
  (game.state.inventory || []).forEach((it) => {
    if (Number(it.equipped) === 1) return;
    if (Number(it.in_warehouse) === 1) return;
    if (itemCannotSellOrAuction(it)) return;
    const o = document.createElement('option');
    o.value = String(it.id);
    o.textContent = `${it.label}（${rarityZh(it.rarity)}）`;
    const sg = it.shop_sell_gold != null ? Number(it.shop_sell_gold) : NaN;
    if (Number.isFinite(sg) && sg >= 1) {
      o.dataset.minShopGold = String(Math.floor(sg));
    }
    sel.appendChild(o);
  });
  const postBtn = qs('auc-post-btn');
  if (postBtn) postBtn.disabled = true;
  syncAuctionPostMinPriceUi();
}

async function openTown(game) {
  qs('town-overlay').classList.add('open');
  if (typeof window.setRpgBodyView === 'function') window.setRpgBodyView('rpg-view-town');
  showPlaceholderDetail();
  try {
    const data = await gameApi('player', {});
    game.applyPlayerPayload(data);
    const nameText = qs('town-name-text');
    if (nameText) {
      nameText.textContent = (data.username ? data.username + ' · ' : '') + data.player.display_name;
    } else {
      const legacy = qs('town-name');
      if (legacy) legacy.textContent = (data.username ? data.username + ' · ' : '') + data.player.display_name;
    }
    renderAttrPanel(data.player);
    refreshAllInventoryUI(game, data.inventory || []);
    if (qs('monster-codex')) await loadMonsterCodex();
    if (qs('auc-list')) await refreshAuction(game);
    if (qs('lb-list')) await refreshLeaderboard();
  } catch (e) {
    game.toast('加载角色失败：' + (e.message || e));
  }
}

function closeTownEnterDungeon(game) {
  qs('town-overlay').classList.remove('open');
  game.openDungeonEntry();
}

window.TownUI = {
  initTownUI,
  openTown,
  closeTownEnterDungeon,
  renderInventory: refreshAllInventoryUI,
  populateAuctionSelect,
  refreshAllInventoryUI,
  refreshAuction,
  refreshLeaderboard,
  renderAttrPanel,
  renderTitlesPanel,
  loadMonsterCodex,
  showPlaceholderDetail,
  showItemDetail,
  rarityTagHtml,
  diceTagHtml,
  dicePowerTier,
  rarityZh,
};
