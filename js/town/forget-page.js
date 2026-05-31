/* global gameApi, PlayerMetaBar, RpgPage */
(function () {
  const { toast, onReady, renderMetaBar } = RpgPage;

  let player = null;
  let skills = [];

  function renderSkills() {
    const box = document.getElementById('fg-skills');
    if (!box) return;
    if (!skills.length) {
      box.innerHTML = '<div class="bag-item">暂无已学技能</div>';
      return;
    }
    box.innerHTML = skills
      .map((s) => {
        const key = String(s.skill_key || '');
        const lv = Number(s.level) || 1;
        return (
          '<div class="bag-item fg-skill-row">' +
          '<span><strong>' +
          (s.label || key) +
          '</strong> Lv.' +
          lv +
          '</span> ' +
          '<button type="button" class="town-dg-btn town-dg-btn-secondary fg-forget-skill" data-key="' +
          key +
          '">预览消耗</button></div>'
        );
      })
      .join('');
    box.querySelectorAll('.fg-forget-skill').forEach((btn) => {
      btn.onclick = () => forgetSkill(btn.getAttribute('data-key'));
    });
  }

  async function refreshProf() {
    const el = document.getElementById('fg-prof');
    const btn = document.getElementById('fg-forget-prof');
    const prev = await gameApi('forget_preview', { mode: 'profession' });
    if (!prev.ok) {
      if (el) el.textContent = prev.error || '当前无职业';
      if (btn) btn.disabled = true;
      return;
    }
    if (el) {
      el.textContent =
        '当前职业：' +
        (prev.label || '') +
        ' · 遗忘需曦光晶屑 ×' +
        prev.crystal_cost +
        '（拥有 ' +
        prev.crystal_have +
        '）';
    }
    if (btn) {
      btn.disabled = !prev.can_forget;
      btn.onclick = async () => {
        if (!window.confirm('遗忘职业「' + (prev.label || '') + '」？消耗曦光晶屑×' + prev.crystal_cost)) return;
        const d = await gameApi('forget_commit', { mode: 'profession' });
        applyPayload(d);
        toast(d.message || '已遗忘职业');
      };
    }
  }

  async function forgetSkill(key) {
    if (!key) return;
    const prev = await gameApi('forget_preview', { mode: 'skill', skill_key: key });
    if (!prev.ok) {
      toast(prev.error || '无法预览');
      return;
    }
    if (
      !window.confirm(
        '遗忘「' +
          (prev.label || key) +
          '」Lv.' +
          prev.level +
          '？\n消耗曦光晶屑 ×' +
          prev.crystal_cost +
          '（拥有 ' +
          prev.crystal_have +
          '）'
      )
    ) {
      return;
    }
    const d = await gameApi('forget_commit', { mode: 'skill', skill_key: key });
    applyPayload(d);
    toast(d.message || '已遗忘技能');
  }

  function applyPayload(d) {
    if (d.player) player = d.player;
    if (d.skills) skills = d.skills;
    renderMetaBar(player || {});
    const c = document.getElementById('fg-crystal');
    if (c && player) c.textContent = '背包曦光晶屑：' + (Number(player.sunbeam_crystal_count) || 0);
    renderSkills();
    refreshProf().catch(() => {});
  }

  onReady(async () => {
    applyPayload(await gameApi('player', {}));
  });
})();
