/* global gameApi, TownUI, RpgPage */
(function () {
  const { toast, onReady } = RpgPage;

  const G = RpgPage.createTownShell();

  onReady(async () => {
    G.applyPlayerPayload(await gameApi('player', {}));
    TownUI.renderAttrPanel(G.state.player);
    const bc = document.getElementById('btn-guild-create');
    const bj = document.getElementById('btn-guild-join');
    const bl = document.getElementById('btn-guild-leave');
    if (bc) {
      bc.onclick = async () => {
        const n = (document.getElementById('guild-create-name') || {}).value || '';
        try {
          const r = await gameApi('guild_create', { name: n });
          G.applyPlayerPayload(r);
          TownUI.renderAttrPanel(G.state.player);
          toast(r.message || '公会已创建');
        } catch (e) {
          toast(String(e.message || e));
        }
      };
    }
    if (bj) {
      bj.onclick = async () => {
        const name = (document.getElementById('guild-join-name') || {}).value || '';
        try {
          const r = await gameApi('guild_join', { name: name });
          G.applyPlayerPayload(r);
          TownUI.renderAttrPanel(G.state.player);
          toast('已加入公会');
        } catch (e) {
          toast(String(e.message || e));
        }
      };
    }
    if (bl) {
      bl.onclick = async () => {
        try {
          const r = await gameApi('guild_leave', {});
          G.applyPlayerPayload(r);
          TownUI.renderAttrPanel(G.state.player);
          toast(r.message || '已离开公会');
        } catch (e) {
          toast(String(e.message || e));
        }
      };
    }
  });
})();
