/* global ChristmasRPG, RpgPage */

RpgPage.onReady(async () => {
  const g = window.ChristmasRPG;
  if (!g) return;
  try {
    await g.bootstrapDungeonScene();
  } catch (e) {
    RpgPage.toast(String(e.message || e));
    return;
  }
  const autoStart = new URLSearchParams(window.location.search).get('autostart') === '1';
  g.openDungeonEntry(autoStart ? { autoStart: true } : undefined);
});
