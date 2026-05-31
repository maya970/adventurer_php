/* global gameApi, PlayerMetaBar, RpgPage */
(function () {
  const { toast, esc, onReady, renderMetaBar } = RpgPage;

  function renderProgress(p) {
    const el = document.getElementById('tasks-progress');
    if (!el || !p) return;
    el.innerHTML =
      '今日进度：地下城' +
      (p.dungeon_visited ? '已进入' : '未进入') +
      ' · 地下城经验 ' +
      (p.dungeon_xp_gained || 0) +
      ' / ' +
      (p.dungeon_xp_target || '?') +
      ' · 分支凯旋 ' +
      (p.branch_success ? '是' : '否') +
      ' · PK 胜 ' +
      (p.pk_won ? '是' : '否') +
      ' · 等级榜名次 ' +
      (p.level_rank > 0 ? p.level_rank : '—');
  }

  function renderTasks(tasks) {
    const host = document.getElementById('tasks-list');
    if (!host) return;
    if (!tasks || !tasks.length) {
      host.innerHTML = '<p class="panel-intro">暂无任务配置。</p>';
      return;
    }
    host.innerHTML = tasks
      .map((t) => {
        const claimed = !!t.claimed;
        const can = !!t.can_claim;
        const btn = claimed
          ? '<span class="tasks-done-tag">已领取</span>'
          : '<button type="button" class="town-dg-btn town-dg-btn-primary tasks-claim-btn" data-key="' +
            esc(t.key) +
            '"' +
            (can ? '' : ' disabled') +
            '>领取 ×' +
            (t.crystal || 1) +
            '</button>';
        return (
          '<div class="panel tasks-card' +
          (claimed ? ' tasks-card-done' : can ? ' tasks-card-ready' : '') +
          '">' +
          '<h2>' +
          esc(t.label) +
          '</h2>' +
          '<p class="panel-intro">' +
          esc(t.desc) +
          '</p>' +
          '<p class="tasks-status">' +
          esc(t.status_hint) +
          '</p>' +
          '<div class="tasks-card-actions">' +
          btn +
          '</div></div>'
        );
      })
      .join('');
    host.querySelectorAll('.tasks-claim-btn').forEach((btn) => {
      btn.onclick = async () => {
        const key = btn.getAttribute('data-key');
        if (!key) return;
        btn.disabled = true;
        try {
          const d = await gameApi('daily_tasks_claim', { task_key: key });
          renderMetaBar(d.player);
          const hint = document.getElementById('tasks-crystal-hint');
          if (hint && d.player) {
            hint.textContent = '背包曦光晶屑：' + (Number(d.player.sunbeam_crystal_count) || 0);
          }
          toast(d.message || '已领取');
          const st = await gameApi('daily_tasks_status', {});
          if (st.ok) {
            renderProgress(st.progress);
            renderTasks(st.tasks);
          }
        } catch (e) {
          toast(String(e.message || e));
          btn.disabled = false;
        }
      };
    });
  }

  onReady(async () => {
    const pl = await RpgPage.loadPlayer();
    renderMetaBar(pl.player);
    const hint = document.getElementById('tasks-crystal-hint');
    if (hint && pl.player) {
      hint.textContent = '背包曦光晶屑：' + (Number(pl.player.sunbeam_crystal_count) || 0);
    }
    const st = await gameApi('daily_tasks_status', {});
    if (!st.ok) throw new Error(st.error || '加载失败');
    renderProgress(st.progress);
    renderTasks(st.tasks);
  });
})();
