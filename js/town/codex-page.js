/* global TownUI, RpgPage */
RpgPage.onReady(async () => {
  TownUI.showPlaceholderDetail();
  await TownUI.loadMonsterCodex('monster-codex');
});
