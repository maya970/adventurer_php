/**
 * img/man/{1001,1002,...}.png — 4 行 × 3 列行走图
 * 行0 向下(正面) 行1 向左 行2 向右 行3 向上(背面)
 */
(function (global) {
  const COLS = 3;
  const ROWS = 4;
  const FRAME_FRONT = { col: 0, row: 0 };

  function sheetUrl(manPng) {
    const n = Math.max(1, parseInt(String(manPng || 1001), 10) || 1001);
    return 'img/man/' + n + '.png';
  }

  function frameBgStyle(manPng, col, row) {
    const c = Math.max(0, Math.min(COLS - 1, col | 0));
    const r = Math.max(0, Math.min(ROWS - 1, row | 0));
    const x = COLS <= 1 ? '0%' : (c / (COLS - 1)) * 100 + '%';
    const y = ROWS <= 1 ? '0%' : (r / (ROWS - 1)) * 100 + '%';
    return (
      'background-image:url(' +
      sheetUrl(manPng) +
      ');background-size:' +
      COLS * 100 +
      '% ' +
      ROWS * 100 +
      '%;background-position:' +
      x +
      ' ' +
      y +
      ';background-repeat:no-repeat;'
    );
  }

  function previewHtml(manPng, opts) {
    const o = opts || {};
    const col = o.col != null ? o.col : FRAME_FRONT.col;
    const row = o.row != null ? o.row : FRAME_FRONT.row;
    const cls = 'man-sprite-preview' + (o.className ? ' ' + o.className : '');
    const title = o.title ? ' title="' + String(o.title).replace(/"/g, '&quot;') + '"' : '';
    return (
      '<span class="' +
      cls +
      '"' +
      title +
      ' style="' +
      frameBgStyle(manPng, col, row) +
      '" role="img" aria-hidden="true"></span>'
    );
  }

  function resolveManPng(skinOrPlayer) {
    if (skinOrPlayer == null) return 1001;
    if (typeof skinOrPlayer === 'number') return skinOrPlayer;
    if (typeof skinOrPlayer === 'string' && /^\d+$/.test(skinOrPlayer)) {
      return parseInt(skinOrPlayer, 10);
    }
    if (typeof skinOrPlayer === 'object') {
      if (skinOrPlayer.man_png != null) return parseInt(skinOrPlayer.man_png, 10) || 1001;
      if (skinOrPlayer.reward_skin_png != null) return parseInt(skinOrPlayer.reward_skin_png, 10) || 1001;
      if (skinOrPlayer.avatar_sheet != null) {
        const a = skinOrPlayer.avatar_sheet;
        if (typeof a === 'number') return a;
        const m = String(a).match(/(\d+)/);
        if (m) return parseInt(m[1], 10);
      }
    }
    return 1001;
  }

  /** @param {THREE.Texture} tex */
  function applyThreeFrame(tex, col, row) {
    if (!tex || !global.THREE) return tex;
    const c = Math.max(0, Math.min(COLS - 1, col | 0));
    const r = Math.max(0, Math.min(ROWS - 1, row | 0));
    tex.flipY = true;
    tex.wrapS = global.THREE.ClampToEdgeWrapping;
    tex.wrapT = global.THREE.ClampToEdgeWrapping;
    tex.repeat.set(1 / COLS, 1 / ROWS);
    tex.offset.set(c / COLS, 1 - (r + 1) / ROWS);
    tex.generateMipmaps = false;
    if (tex.minFilter !== undefined) tex.minFilter = global.THREE.LinearFilter;
    if (tex.magFilter !== undefined) tex.magFilter = global.THREE.LinearFilter;
    tex.needsUpdate = true;
    return tex;
  }

  function loadThreeFrame(loader, manPng, col, row, onLoad) {
    const url = sheetUrl(manPng);
    return loader.load(
      url,
      function (tex) {
        applyThreeFrame(tex, col, row);
        if (onLoad) onLoad(tex);
      },
      undefined,
      function () {
        if (onLoad) onLoad(null);
      }
    );
  }

  global.ManSprite = {
    COLS: COLS,
    ROWS: ROWS,
    FRAME_FRONT: FRAME_FRONT,
    sheetUrl: sheetUrl,
    frameBgStyle: frameBgStyle,
    previewHtml: previewHtml,
    resolveManPng: resolveManPng,
    applyThreeFrame: applyThreeFrame,
    loadThreeFrame: loadThreeFrame,
  };
})(typeof window !== 'undefined' ? window : globalThis);
