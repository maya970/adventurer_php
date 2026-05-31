/* global gameApi, RpgPage */
(function () {
  const { toast, onReady, renderMetaBar } = RpgPage;

  onReady(async () => {
    const pData = await gameApi('player', {});
    const p = pData.player || {};
    renderMetaBar(p);
    const cur = document.getElementById('pc-current');
    const sel = document.getElementById('pc-select');
    const btn = document.getElementById('pc-confirm');
    const note = document.getElementById('pc-lock-note');
    const pf = p.profession || {};
    const curK = String(pf.key || '');
    if (cur) {
      cur.textContent =
        curK !== ''
          ? '当前职业：' + (pf.label || curK) + '（已锁定）'
          : '当前尚未转职，请选择体修或灵修。';
    }
    if (curK) {
      if (sel) {
        sel.value = curK;
        sel.disabled = true;
      }
      if (btn) btn.disabled = true;
      if (note) note.textContent = '职业已锁定。如需重选，请前往「遗忘仪式」消耗曦光晶屑。';
    } else if (btn) {
      btn.onclick = async () => {
        const k = sel ? String(sel.value || '') : '';
        if (!k) {
          toast('请先选择职业');
          return;
        }
        if (!window.confirm('确认转职为「' + (k === 'body' ? '体修' : '灵修') + '」？确认后不可更改。')) return;
        const d = await gameApi('profession_set', { profession_key: k });
        renderMetaBar(d.player);
        if (sel) sel.disabled = true;
        btn.disabled = true;
        if (cur) cur.textContent = '当前职业：' + (k === 'body' ? '体修' : '灵修') + '（已锁定）';
        toast('转职完成');
      };
    }
  });
})();
