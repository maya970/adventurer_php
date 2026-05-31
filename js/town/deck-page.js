/* global gameApi, RpgPage */
(function () {
  const { toast, onReady } = RpgPage;
  const pickedSkills = [];
  const pickedPotions = [];

  function updateCounts() {
    const sc = document.getElementById('deck-skill-count');
    const pc = document.getElementById('deck-potion-count');
    if (sc) sc.textContent = pickedSkills.length + '/12';
    if (pc) pc.textContent = pickedPotions.length + '/6';
  }

  function renderPicked() {
    const sk = document.getElementById('deck-skills-picked');
    const po = document.getElementById('deck-potions-picked');
    if (sk) {
      sk.innerHTML = pickedSkills
        .map(
          (k, i) =>
            `<div class="bag-item">${k.label || k.ref} <button type="button" data-i="${i}" class="deck-rm-skill">移除</button></div>`
        )
        .join('');
      sk.querySelectorAll('.deck-rm-skill').forEach((b) => {
        b.onclick = () => {
          pickedSkills.splice(Number(b.getAttribute('data-i')), 1);
          renderAll();
        };
      });
    }
    if (po) {
      po.innerHTML = pickedPotions
        .map(
          (p, i) =>
            `<div class="bag-item">${p.label || p.ref} <button type="button" data-i="${i}" class="deck-rm-pot">移除</button></div>`
        )
        .join('');
      po.querySelectorAll('.deck-rm-pot').forEach((b) => {
        b.onclick = () => {
          pickedPotions.splice(Number(b.getAttribute('data-i')), 1);
          renderAll();
        };
      });
    }
    updateCounts();
  }

  function renderAll() {
    renderPicked();
    document.querySelectorAll('.deck-add-skill').forEach((b) => {
      const k = b.getAttribute('data-key');
      b.disabled = pickedSkills.length >= 12 || pickedSkills.some((x) => x.ref === k);
    });
    document.querySelectorAll('.deck-add-pot').forEach((b) => {
      const id = b.getAttribute('data-id');
      b.disabled = pickedPotions.length >= 6 || pickedPotions.some((x) => String(x.ref) === id);
    });
  }

  function loadPools(skills, inventory, saved) {
    saved.forEach((c) => {
      if (c.card_type === 'skill') pickedSkills.push({ ref: c.card_ref, label: c.label });
      if (c.card_type === 'potion') pickedPotions.push({ ref: c.card_ref, label: c.label });
    });
    const skPool = document.getElementById('deck-skills-pool');
    if (skPool) {
      skPool.innerHTML = (skills || [])
        .map(
          (s) =>
            `<div class="bag-item">${s.label || s.skill_key} Lv.${s.level || 1}${s.profession_match ? ' ★职业适性' : ''} · 战力×${s.power_mult != null ? Number(s.power_mult).toFixed(2) : '—'}
            <button type="button" class="deck-add-skill town-dg-btn" data-key="${s.skill_key}">加入</button></div>`
        )
        .join('');
      skPool.querySelectorAll('.deck-add-skill').forEach((b) => {
        b.onclick = () => {
          if (pickedSkills.length >= 12) return toast('技能已满 12 张');
          const key = b.getAttribute('data-key');
          if (pickedSkills.some((x) => x.ref === key)) return;
          const row = skills.find((x) => x.skill_key === key);
          pickedSkills.push({ ref: key, label: row && row.label ? row.label : key });
          renderAll();
        };
      });
    }
    const potPool = document.getElementById('deck-potions-pool');
    const pots = (inventory || []).filter((it) => {
      if (Number(it.in_warehouse) === 1 || Number(it.equipped) === 1) return false;
      const k = String(it.item_key || '').toLowerCase();
      return k.includes('potion') || k.includes('elixir') || k.includes('healing') || k.includes('life_');
    });
    if (potPool) {
      potPool.innerHTML = pots.length
        ? pots
            .map(
              (it) =>
                `<div class="bag-item">${it.label} #${it.id}
              <button type="button" class="deck-add-pot town-dg-btn" data-id="${it.id}">加入</button></div>`
            )
            .join('')
        : '<div class="bag-item">背包暂无药水，请先在杂货店购买</div>';
      potPool.querySelectorAll('.deck-add-pot').forEach((b) => {
        b.onclick = () => {
          if (pickedPotions.length >= 6) return toast('药水已满 6 张');
          const id = b.getAttribute('data-id');
          if (pickedPotions.some((x) => String(x.ref) === id)) return;
          const row = pots.find((x) => String(x.id) === id);
          pickedPotions.push({ ref: id, label: row ? row.label : '药水' });
          renderAll();
        };
      });
    }
    renderAll();
  }

  onReady(async () => {
    const data = await gameApi('player', {});
      const deck = (data.battle_deck && data.battle_deck.cards) || [];
      const st = document.getElementById('deck-status');
      if (st) {
        st.textContent = deck.length
          ? '当前已保存 ' + deck.length + ' 张' + (data.battle_deck.complete ? '（完整）' : '（未完成）')
          : '尚未保存牌组';
      }
      loadPools(data.skills || [], data.inventory || [], deck);
      document.getElementById('btn-deck-save').onclick = async () => {
        if (pickedSkills.length !== 12 || pickedPotions.length !== 6) {
          toast('须选满 12 技能 + 6 药水');
          return;
        }
        const cards = [];
        pickedSkills.forEach((s) => cards.push({ card_type: 'skill', card_ref: s.ref }));
        pickedPotions.forEach((p) => cards.push({ card_type: 'potion', card_ref: String(p.ref) }));
        try {
          const r = await gameApi('battle_deck_set', { cards });
          toast('牌组已保存');
          if (st && r.deck) st.textContent = '已保存 18 张（完整）';
        } catch (e) {
          toast(String(e.message || e));
        }
      };
  });
})();
