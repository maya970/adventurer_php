/* global gameApi, TownUI, RpgPage */

(function () {
  const { toast } = RpgPage;

  const TownGame = RpgPage.createTownShell({ account: null });

  function bindTownDungeonEntry(game) {
    const enterBtn = document.getElementById('btn-dungeon-enter');
    const hint = document.getElementById('town-dungeon-hint');
    const btnRefresh = document.getElementById('btn-dungeon-refresh');
    const recordBtn = document.getElementById('btn-dungeon-record');
    if (!enterBtn) return;

    if (btnRefresh) {
      btnRefresh.onclick = async () => {
        try {
          const d = await gameApi('player', {});
          game.applyPlayerPayload(d);
          TownUI.renderAttrPanel(d.player);
          TownUI.refreshAllInventoryUI(game, d.inventory || []);
          bindTownDungeonEntry(game);
          game.toast('已刷新');
        } catch (e) {
          game.toast(String(e.message || e));
        }
      };
    }

    enterBtn.classList.remove('town-dg-btn-locked');
    enterBtn.onclick = async () => {
      try {
        await gameApi('dungeon_prepare', { route: 'floor1' });
        window.location.href = 'dungeon.html?autostart=1';
      } catch (e) {
        game.toast(String(e.message || e));
      }
    };
    if (hint) {
      hint.textContent = '地下城可随时进入；经验与战利品在回城存档时结算。曦光晶屑请至「每日任务」领取。';
    }

    const st = game.state.player && game.state.player.stamina;
    if (recordBtn && st && st.campfire_floor_today) {
      recordBtn.hidden = false;
      recordBtn.onclick = async () => {
        try {
          await gameApi('dungeon_prepare', { route: 'campfire' });
          window.location.href = 'dungeon.html?autostart=1';
        } catch (e) {
          game.toast(String(e.message || e));
        }
      };
    } else if (recordBtn) {
      recordBtn.hidden = true;
      recordBtn.onclick = null;
    }
  }

  function wireTownOptions(game) {
    const chk = document.getElementById('chk-safe-loop');
    if (chk) {
      try {
        chk.checked = typeof localStorage === 'undefined' || localStorage.getItem('rpg_dungeon_safe_loop') !== '0';
      } catch (_) {
        chk.checked = true;
      }
      chk.onchange = () => {
        try {
          localStorage.setItem('rpg_dungeon_safe_loop', chk.checked ? '1' : '0');
        } catch (_) {
          /* ignore */
        }
      };
    }
    const logoutBtn = document.getElementById('btn-logout');
    if (logoutBtn) {
      logoutBtn.onclick = async () => {
        try {
          await gameApi('logout', {});
        } catch (_) {
          /* ignore */
        }
        window.location.href = RpgPage.loginUrl();
      };
    }
    bindTownDungeonEntry(game);
  }

  RpgPage.onReady(async (session) => {
    if (typeof window.applyTownLabels === 'function') {
      window.applyTownLabels();
    }
    TownGame.applyPlayerPayload(session);
    TownUI.initTownUI(TownGame);
    await TownUI.openTown(TownGame);
    wireTownOptions(TownGame);
  });

  window.TownGame = TownGame;
})();
