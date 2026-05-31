/* global TownUI, RpgPage */
(function () {
  const { toast, onReady } = RpgPage;

  onReady(async () => {
    const g = { toast };
    TownUI.initTownUI(g);
    await TownUI.refreshLeaderboard();
  });
})();
