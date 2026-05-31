/* global gameApi, TownUI, RpgPage */
(function () {
  const { toast, onReady } = RpgPage;

  const AuctionGame = RpgPage.createTownShell();

  onReady(async () => {
    const g = AuctionGame;
    const d = await gameApi('player', {});
    g.applyPlayerPayload(d);
    TownUI.initTownUI(g);
    TownUI.showPlaceholderDetail();
    TownUI.renderAttrPanel(g.state.player);
    TownUI.refreshAllInventoryUI(g, g.state.inventory || []);
    await TownUI.refreshAuction(g);
    TownUI.populateAuctionSelect(g);
  });
})();
