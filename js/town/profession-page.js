/* global gameApi, RpgPage */
(function () {
  const { toast, onReady, renderMetaBar } = RpgPage;
  let playerGold = 0;
  let enhancingKey = '';

  function affinityLabel(aff) {
    if (aff === 'physical') return '体修向';
    if (aff === 'arcane') return '灵修向';
    return '通用';
  }

  function skillRow(key) {
    return (window.__pfSkills || []).find((x) => String(x.skill_key) === key);
  }

  function render(p, skills) {
    playerGold = Number((p && p.gold) || 0);
    const cur = document.getElementById('pf-current');
    if (cur) {
      const pf = (p && p.profession) || {};
      cur.textContent =
        '当前职业：' +
        (pf.label || '无') +
        '（力量×' +
        Number(pf.str_mult || 1).toFixed(2) +
        '，智力×' +
        Number(pf.int_mult || 1).toFixed(2) +
        '）· 金币 ' +
        playerGold;
    }
    const box = document.getElementById('pf-skills');
    if (!box) return;
    const rows = Array.isArray(skills) ? skills : [];
    if (!rows.length) {
      box.innerHTML = '<div class="bag-item">暂无已学技能，请在背包使用技能书学习</div>';
      return;
    }
    box.innerHTML = rows
      .map((x) => {
        const key = String(x.skill_key || '');
        const lv = Number(x.level) || 1;
        const max = Number(x.max_level) || 10;
        const can = !!x.can_enhance;
        const gold = Number(x.enhance_gold) || 0;
        const pct = Number(x.enhance_chance_pct) || 0;
        const match = x.profession_match ? ' · 职业适性★' : '';
        const mult = x.power_mult != null ? Number(x.power_mult).toFixed(2) : '—';
        const busy = enhancingKey === key;
        const actions = can
          ? '<button type="button" class="town-dg-btn pf-enh-once" data-key="' +
            key +
            '"' +
            (busy ? ' disabled' : '') +
            '>强化一次</button>' +
            '<button type="button" class="town-dg-btn town-dg-btn-secondary pf-enh-auto" data-key="' +
            key +
            '"' +
            (busy ? ' disabled' : '') +
            '>连续强化</button>' +
            '<span class="pf-enh-meta">' +
            gold +
            '金 · ' +
            pct +
            '%</span>'
          : '<span class="panel-intro">已满级</span>';
        return (
          '<div class="bag-item pf-skill-row">' +
          '<div class="pf-skill-head"><strong>' +
          (x.label || key) +
          '</strong> Lv.' +
          lv +
          '/' +
          max +
          ' · ' +
          affinityLabel(x.affinity) +
          match +
          ' · 战力×' +
          mult +
          '</div>' +
          '<div class="pf-skill-actions">' +
          actions +
          '</div></div>'
        );
      })
      .join('');
    box.querySelectorAll('.pf-enh-once').forEach((b) => {
      b.onclick = () => enhanceSkillOnce(b.getAttribute('data-key'), false);
    });
    box.querySelectorAll('.pf-enh-auto').forEach((b) => {
      b.onclick = () => runAutoSkillEnhance(b.getAttribute('data-key'));
    });
  }

  function applyPayload(d) {
    if (d.player) window.__pfPlayer = d.player;
    if (d.skills) window.__pfSkills = d.skills;
    render(window.__pfPlayer || {}, window.__pfSkills || []);
  }

  async function enhanceSkillOnce(skillKey, skipConfirm) {
    const key = String(skillKey || '').trim();
    if (!key || enhancingKey) return;
    const row = skillRow(key);
    if (!row || !row.can_enhance) {
      toast('该技能无法继续强化');
      return;
    }
    try {
      const prev = await gameApi('skill_enhance_preview', { skill_key: key });
      if (!prev.ok) {
        toast(prev.error || '无法强化');
        return;
      }
      if (!skipConfirm) {
        const match = prev.profession_match ? '（职业适性加成）' : '';
        const msg =
          '强化「' +
          (prev.label || key) +
          '」至 Lv.' +
          prev.next_level +
          '？\n消耗 ' +
          prev.gold_cost +
          ' 金币，成功率约 ' +
          prev.chance_percent +
          '%' +
          match +
          '\n失败仍扣金币。';
        if (!window.confirm(msg)) return;
      }
      enhancingKey = key;
      render(window.__pfPlayer || {}, window.__pfSkills || []);
      const d = await gameApi('skill_enhance', { skill_key: key });
      applyPayload(d);
      toast(d.message || (d.skill_enhance && d.skill_enhance.message) || '完成');
    } catch (e) {
      toast(String(e.message || e));
    } finally {
      enhancingKey = '';
      render(window.__pfPlayer || {}, window.__pfSkills || []);
    }
  }

  async function runAutoSkillEnhance(skillKey) {
    const key = String(skillKey || '').trim();
    if (!key || enhancingKey) return;
    const row0 = skillRow(key);
    if (!row0 || !row0.can_enhance) {
      toast('该技能已满级');
      return;
    }
    if (
      !window.confirm(
        '连续强化「' +
          (row0.label || key) +
          '」？\n每次失败仍扣金币，直到成功、满级或金币不足时停止。'
      )
    ) {
      return;
    }
    enhancingKey = key;
    render(window.__pfPlayer || {}, window.__pfSkills || []);
    try {
      for (;;) {
        const row = skillRow(key);
        if (!row || !row.can_enhance) {
          toast('已达最高等级');
          break;
        }
        const gold = Number((window.__pfPlayer && window.__pfPlayer.gold) || 0);
        let prev;
        try {
          prev = await gameApi('skill_enhance_preview', { skill_key: key });
        } catch (e) {
          toast(String(e.message || e));
          break;
        }
        if (!prev.ok) {
          toast(prev.error || '无法强化');
          break;
        }
        if (gold < Number(prev.gold_cost) || 0) {
          toast('金币不足，停止连续强化');
          break;
        }
        const d = await gameApi('skill_enhance', { skill_key: key });
        applyPayload(d);
        if (d.skill_enhance && d.skill_enhance.enhance_success) {
          toast(d.message || '连续强化成功');
          break;
        }
        toast(d.message || '失败，继续…');
      }
    } finally {
      enhancingKey = '';
      render(window.__pfPlayer || {}, window.__pfSkills || []);
    }
  }

  onReady(async () => {
    const pData = await gameApi('player', {});
    window.__pfPlayer = pData.player || {};
    window.__pfSkills = pData.skills || [];
    renderMetaBar(window.__pfPlayer);
    render(window.__pfPlayer, window.__pfSkills);
    const curK = String((pData.player && pData.player.profession && pData.player.profession.key) || '');
    const note = document.getElementById('pf-prof-note');
    if (note) note.style.display = curK ? 'none' : '';
  });
})();
