/* global THREE, gameApi, TownUI */
(function () {
  const COLS = 12;
  const ROWS = 12;
  const CELL = 1;

  const TEX = {
    wall: 'https://arweave.net/OD1cNP8ruEeADeWPzTGeshzPBSqVLH00QRZQGWHQli8',
    floor: 'https://arweave.net/OD1cNP8ruEeADeWPzTGeshzPBSqVLH00QRZQGWHQli8',
    chest: 'https://arweave.net/TmcSXpfuDJPXmh9F3hMvqbgyfVHANpCex7GNnpsmv2M',
    stairs: 'https://arweave.net/BufnZYf3hyWFDo6QJeV3M1YC2UyBqxE75NBl2pBr68c',
    goal: 'https://arweave.net/ol8b1uQnffHbCWTEfwp6A3cTKzZVH3fgQ3sj9xicQw4',
  };

  const IMG_EXT = ['png', 'jpg', 'jpeg', 'webp'];
  /** 本地素材包：0001.gif / 0002.gif …（怪物、物品、地砖通用） */
  const FRAME_EXTS = ['gif', 'png', 'jpg', 'jpeg', 'webp'];

  function pad4(n) {
    const k = Math.max(0, Math.min(9999, Math.floor(Number(n) || 0)));
    return String(k).padStart(4, '0');
  }

  function pushNumberedFrame(out, folder, num) {
    const b = pad4(num);
    FRAME_EXTS.forEach((ext) => out.push(`${folder}/${b}.${ext}`));
  }

  function pushNumberedRange(out, folder, from, to) {
    for (let n = from; n <= to; n++) pushNumberedFrame(out, folder, n);
  }

  function easeInOutQuad(t) {
    return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
  }

  function shuffleInPlace(arr) {
    for (let i = arr.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr;
  }

  function isPow2(n) {
    return n > 0 && (n & (n - 1)) === 0;
  }

  /** 适配本地 PNG/JPG 与 Arweave：NPOT 贴图关闭 repeat/mipmap，避免整面黑 */
  function configureTextureForMap(tex, opts) {
    const img = tex.image;
    const w = img && img.width;
    const h = img && img.height;
    const pot = w && h && isPow2(w) && isPow2(h);
    tex.flipY = true;
    tex.generateMipmaps = !!pot;
    tex.minFilter = pot ? THREE.LinearMipMapLinearFilter : THREE.LinearFilter;
    tex.magFilter = THREE.LinearFilter;
    if (opts && opts.repeatTiles && pot) {
      tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
      const n = opts.repeatTiles;
      tex.repeat.set(n, n);
    } else {
      tex.wrapS = tex.wrapT = THREE.ClampToEdgeWrapping;
      tex.repeat.set(1, 1);
    }
    tex.needsUpdate = true;
  }

  function configureTextureForSprite(tex) {
    tex.flipY = true;
    tex.wrapS = tex.wrapT = THREE.ClampToEdgeWrapping;
    tex.repeat.set(1, 1);
    tex.generateMipmaps = false;
    tex.minFilter = THREE.LinearFilter;
    tex.magFilter = THREE.LinearFilter;
    tex.needsUpdate = true;
  }

  /** 物品 48×64 雪碧图：有效像素约在 (16,21) 的 20×20，用于手部武器贴图裁切 */
  const HAND_ITEM_ATLAS = { sheetW: 48, sheetH: 64, cropX: 16, cropY: 21, cropW: 20, cropH: 20 };

  function applyHandItemAtlasCrop(tex) {
    const img = tex.image;
    const iw = img && img.width;
    const ih = img && img.height;
    const A = HAND_ITEM_ATLAS;
    if (iw === A.sheetW && ih === A.sheetH) {
      tex.repeat.set(A.cropW / A.sheetW, A.cropH / A.sheetH);
      tex.offset.set(A.cropX / A.sheetW, (A.sheetH - A.cropY - A.cropH) / A.sheetH);
    } else {
      tex.repeat.set(1, 1);
      tex.offset.set(0, 0);
    }
    tex.flipY = true;
    tex.wrapS = tex.wrapT = THREE.ClampToEdgeWrapping;
    tex.generateMipmaps = false;
    tex.minFilter = THREE.LinearFilter;
    tex.magFilter = THREE.LinearFilter;
    tex.needsUpdate = true;
  }

  function findEquippedWeaponByHand(inv, mainHand) {
    return (inv || []).find((x) => {
      if (Number(x.equipped) !== 1 || x.slot !== 'weapon') return false;
      const h = x.weapon_hand;
      if (mainHand) return h == null || h === '' || h === 'main';
      return h === 'off';
    });
  }

  function buildShortestRotationSteps(fromDir, toDir) {
    if (fromDir === toDir) return [];
    const cw = (toDir - fromDir + 4) % 4;
    const ccw = (fromDir - toDir + 4) % 4;
    const steps = [];
    let d = fromDir;
    if (cw <= ccw) {
      for (let i = 0; i < cw; i++) {
        d = (d + 1) % 4;
        steps.push({ nextDir: d, deltaYaw: -Math.PI / 2 });
      }
    } else {
      for (let i = 0; i < ccw; i++) {
        d = (d + 3) % 4;
        steps.push({ nextDir: d, deltaYaw: Math.PI / 2 });
      }
    }
    return steps;
  }

  /**
   * 与 items 的 image_num 一致：1→0001.*，仅读取 monsters.json 显式字段，不按 key 排序自动占号。
   * 兼容旧字段 sprite_num / sprite_index。未配置编号时走 key 文件名与远程 sprite。
   */
  function monsterImageNumFromDef(def) {
    if (!def || typeof def !== 'object') return 0;
    const raw =
      def.image_num != null
        ? def.image_num
        : def.sprite_num != null
          ? def.sprite_num
          : def.sprite_index;
    const n = Math.floor(Number(raw));
    return n > 0 && n <= 9999 ? n : 0;
  }

  /**
   * 魔物贴图：image_num>0 时只尝试 img/monsters|monster/NNNN.*，再才回退远程。
   */
  function urlsMonster(key, remote, catalogEntry, spriteFileIndex) {
    const out = [];
    const n = spriteFileIndex != null ? Math.floor(Number(spriteFileIndex)) : 0;
    if (n > 0) {
      pushNumberedFrame(out, 'img/monsters', n);
      pushNumberedFrame(out, 'img/monster', n);
      if (remote) out.push(remote);
      return out;
    }
    const alias = catalogEntry && catalogEntry.image;
    const pushKeyFiles = (k) => {
      if (!k) return;
      for (let j = 0; j <= 3; j++) {
        const suffix = j === 0 ? '' : '_' + j;
        IMG_EXT.forEach((e) => {
          out.push(`img/monsters/${k}${suffix}.${e}`);
          out.push(`img/monster/${k}${suffix}.${e}`);
        });
      }
    };
    if (alias) {
      if (String(alias).includes('.')) {
        out.push(`img/monsters/${alias}`);
        out.push(`img/monster/${alias}`);
      } else {
        pushKeyFiles(alias);
      }
    }
    pushKeyFiles(key);
    if (remote) out.push(remote);
    return out;
  }

  /** 宝箱仅用 items：0000、0002、0003、0004 四张，按序号轮换 */
  const CHEST_ITEM_FRAMES = [0, 2, 3, 4];

  function urlsChestVariant(chestIndex) {
    const v = CHEST_ITEM_FRAMES[Math.max(0, chestIndex) % CHEST_ITEM_FRAMES.length];
    const out = [];
    pushNumberedFrame(out, 'img/items', v);
    out.push(TEX.chest);
    return out;
  }

  /** 楼梯/传送门固定使用 tiles/0000.*（编号 0 → 0000） */
  function urlsPortalStairs() {
    const out = [];
    pushNumberedFrame(out, 'img/tiles', 0);
    out.push(TEX.stairs);
    return out;
  }

  /**
   * 每 10 层一组三连号（与材质包一致）：
   * 第 1–10 层：地 0001、墙 0002、顶 0003；第 11–20 层：0004/0005/0006…
   * 贴图仅尝试 img/tiles/NNNN + 常见后缀，不加载 NNNN_1 等子编号文件。
   */
  const TILE_TRIPLE_PERIOD = 9996;

  function tileIndicesForFloor(floor) {
    const f = Math.max(1, Math.floor(Number(floor) || 1));
    const tier = Math.floor((f - 1) / 10);
    const base = 1 + ((tier * 3) % TILE_TRIPLE_PERIOD);
    return { floor: base, wall: base + 1, ceiling: base + 2 };
  }

  function urlsTileSingle(num, fallbackUrl) {
    const out = [];
    const n = Math.max(0, Math.min(9999, Math.floor(Number(num) || 0)));
    pushNumberedFrame(out, 'img/tiles', n);
    if (fallbackUrl) out.push(fallbackUrl);
    return out;
  }

  /**
   * 地砖 / 墙体 / 天花板：仅 img/tiles/NNNN.<gif|png|…>，不再请求 NNNN_k 变体（减少 404 与加载量）。
   */
  function urlsTileVariantPool(num, fallbackUrl) {
    const out = [];
    const n = Math.max(0, Math.min(9999, Math.floor(Number(num) || 0)));
    pushNumberedFrame(out, 'img/tiles', n);
    if (fallbackUrl) {
      out.push(fallbackUrl);
    }
    return out;
  }

  function chainLoadTexture(loader, urls, mat, repeatTiles) {
    let i = 0;
    function next() {
      if (i >= urls.length) return;
      const u = urls[i++];
      loader.load(
        u,
        (tex) => {
          configureTextureForMap(tex, { repeatTiles: repeatTiles || 0 });
          mat.map = tex;
          mat.needsUpdate = true;
        },
        undefined,
        next
      );
    }
    next();
  }

  let monsterCatalog = {};
  let monsterSpriteIndexByKey = {};
  /** item_key → image_num（旧背包 image_num=0 时用于地城武器贴图回退） */
  let itemImageNumByKey = {};

  function rebuildMonsterSpriteIndex() {
    monsterSpriteIndexByKey = {};
    Object.keys(monsterCatalog).forEach((k) => {
      const n = monsterImageNumFromDef(monsterCatalog[k]);
      if (n > 0) monsterSpriteIndexByKey[k] = n;
    });
  }

  let dungeonMusicConfig = {
    default: ['https://arweave.net/3QaXlF77IDjwKKIsROMldfaE9XWh5cIkM_E6556BreE'],
    ranges: [],
  };

  function pickMusicSourcesForFloor(floor) {
    const ff = Math.max(1, Math.floor(Number(floor) || 1));
    const cfg = dungeonMusicConfig || {};
    const defList = Array.isArray(cfg.default)
      ? cfg.default
      : cfg.default
        ? [cfg.default]
        : [];
    const floorsExact = Array.isArray(cfg.floors) ? cfg.floors : [];
    const exact = floorsExact.find((r) => Number(r.floor) === ff);
    if (exact) {
      const s = exact.src != null ? exact.src : exact.sources;
      const arr = Array.isArray(s) ? s : s ? [s] : [];
      if (arr.length) return arr;
    }
    const ranges = Array.isArray(cfg.ranges) ? cfg.ranges : [];
    const hit = ranges.find((r) => {
      const a = Number(r.from_floor);
      const b = Number(r.to_floor);
      return ff >= a && ff <= b;
    });
    if (hit) {
      const s = hit.src != null ? hit.src : hit.sources;
      const arr = Array.isArray(s) ? s : s ? [s] : [];
      if (arr.length) return arr;
    }
    if (ranges.length) {
      const tier = Math.floor((ff - 1) / 10);
      const r = ranges[tier % ranges.length];
      const s = r && (r.src != null ? r.src : r.sources);
      const arr = Array.isArray(s) ? s : s ? [s] : [];
      if (arr.length) return arr;
    }
    return defList.length
      ? defList
      : ['https://arweave.net/3QaXlF77IDjwKKIsROMldfaE9XWh5cIkM_E6556BreE'];
  }

  function urlsItemImageNum(num) {
    const out = [];
    const n = Math.max(0, Number(num) || 0);
    if (n > 0) {
      pushNumberedFrame(out, 'img/items', n);
      pushNumberedFrame(out, 'img/item', n);
    }
    return out;
  }

  const MONSTER_POOL_T1 = ['ginger_grunt', 'snow_spirit', 'candy_slime', 'frost_imp'];
  const MONSTER_POOL_T2 = [
    'ginger_grunt',
    'snow_spirit',
    'candy_slime',
    'frost_imp',
    'coal_golem',
    'elf_archer',
    'carol_wraith',
    'stocking_mimic',
  ];
  const MONSTER_POOL_T3 = [
    'nutcracker',
    'coal_golem',
    'elf_archer',
    'carol_wraith',
    'stocking_mimic',
    'yule_treant',
    'reindeer_fury',
    'candy_slime',
  ];

  /** 层数档位倍率：1–10 → 1，11–20 → 2，21–30 → 3 …（陷阱等 UI / 非魔物用） */
  function floorTierMultiplier(floor) {
    const f = Math.max(1, Math.floor(Number(floor) || 1));
    return Math.floor((f - 1) / 10) + 1;
  }

  /**
   * 魔物 HP / 魔物反击伤害：
   * · 第 1–100 层：与陷阱相同的线性档 ⌊(f−1)/10⌋+1（1–10→1 … 91–100→10）。
   * · 第 101 层起：以第 100 层档倍 10 为基准，每多 10 层再 ×2（101–110→20，111–120→40…）。
   * 翻倍次数封顶避免溢出（约第 491 层起档倍率不再升）。
   */
  const MONSTER_POST100_DOUBLE_CAP = 40;

  function monsterFloorTierMultiplier(floor) {
    const f = Math.max(1, Math.floor(Number(floor) || 1));
    if (f <= 100) {
      return Math.floor((f - 1) / 10) + 1;
    }
    const base100 = 10;
    const doubleSteps = Math.floor((f - 101) / 10) + 1;
    const exp = Math.min(MONSTER_POST100_DOUBLE_CAP, doubleSteps);
    return base100 * Math.pow(2, exp);
  }

  /** 未命中/未破防时仍有概率造成擦伤（与战斗共用） */
  const COMBAT_CHIP_CHANCE = 0.15;

  function pickMonsterKeyForFloor(floor) {
    const all = Object.keys(monsterCatalog);
    if (!all.length) return null;
    const f = Math.max(1, floor);
    let pool;
    if (f <= 4) pool = MONSTER_POOL_T1;
    else if (f <= 11) pool = MONSTER_POOL_T2;
    else if (f <= 24) pool = MONSTER_POOL_T3;
    else pool = all;
    const valid = pool.filter((k) => monsterCatalog[k]);
    const use = valid.length ? valid : all;
    return use[Math.floor(Math.random() * use.length)];
  }

  function loadJson(url) {
    return fetch(url).then((r) => r.json());
  }

  function scaleMonster(def, floor) {
    const f = Math.max(1, floor);
    const bracket = Math.floor((f - 1) / 10);
    const tierMult = monsterFloorTierMultiplier(f);
    const mul = 1 + (f - 1) * 0.11;
    let hp = Math.max(1, Math.round(def.hp * mul + (f - 1) * 2));
    hp = Math.max(1, Math.round(hp * tierMult));
    const acCap = Math.min(46, 30 + Math.floor(bracket / 2));
    const toHitCap = Math.min(40, 20 + Math.floor(bracket / 2));
    const ac = Math.min(acCap, Math.round(def.ac + Math.floor((f - 1) / 2)));
    const to_hit = Math.min(toHitCap, Math.round(def.to_hit + Math.floor((f - 1) / 3)));
    return {
      hp,
      maxHp: hp,
      ac,
      to_hit,
      damage: def.damage,
      label: def.label || '怪物',
      sprite: def.sprite || TEX.wall,
      desc: def.desc || '',
      tierMult,
    };
  }

  function rollDice(expr) {
    const m = String(expr)
      .toLowerCase()
      .trim()
      .match(/^(\d+)d(\d+)([+-]\d+)?$/);
    if (!m) return 1;
    const n = Math.min(20, Math.max(1, parseInt(m[1], 10)));
    const d = Math.min(100, Math.max(2, parseInt(m[2], 10)));
    let sum = m[3] ? parseInt(m[3], 10) : 0;
    for (let i = 0; i < n; i++) sum += 1 + Math.floor(Math.random() * d);
    return Math.max(1, sum);
  }

  function diceExprMinMax(expr) {
    const m = String(expr)
      .toLowerCase()
      .trim()
      .match(/^(\d+)d(\d+)([+-]\d+)?$/);
    if (!m) return [1, 1];
    const n = Math.min(20, Math.max(1, parseInt(m[1], 10)));
    const d = Math.min(100, Math.max(2, parseInt(m[2], 10)));
    const mod = m[3] ? parseInt(m[3], 10) : 0;
    const lo = Math.max(1, n + mod);
    const hi = Math.max(1, n * d + mod);
    return [lo, hi];
  }

  function generateMaze(cols, rows) {
    const grid = [];
    for (let y = 0; y < rows; y++) {
      grid[y] = [];
      for (let x = 0; x < cols; x++) {
        grid[y][x] = { walls: [true, true, true, true], visited: false };
      }
    }
    function shuffle(a) {
      for (let i = a.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [a[i], a[j]] = [a[j], a[i]];
      }
      return a;
    }
    function carve(x, y) {
      grid[y][x].visited = true;
      const dirs = shuffle([
        [0, -1],
        [1, 0],
        [0, 1],
        [-1, 0],
      ]);
      for (const [dx, dy] of dirs) {
        const nx = x + dx;
        const ny = y + dy;
        if (nx >= 0 && nx < cols && ny >= 0 && ny < rows && !grid[ny][nx].visited) {
          if (dx === 1) {
            grid[y][x].walls[1] = false;
            grid[ny][nx].walls[3] = false;
          } else if (dx === -1) {
            grid[y][x].walls[3] = false;
            grid[ny][nx].walls[1] = false;
          } else if (dy === 1) {
            grid[y][x].walls[2] = false;
            grid[ny][nx].walls[0] = false;
          } else if (dy === -1) {
            grid[y][x].walls[0] = false;
            grid[ny][nx].walls[2] = false;
          }
          carve(nx, ny);
        }
      }
    }
    carve(0, 0);
    grid[rows - 2][cols - 2].walls = [false, false, false, false];
    return grid;
  }

  function dirToFaceCell(px, py, tx, ty) {
    const dx = tx - px;
    const dy = ty - py;
    if (dx === 1) return 1;
    if (dx === -1) return 3;
    if (dy === -1) return 0;
    if (dy === 1) return 2;
    return null;
  }

  /** 与怪格相邻且中间无墙，才允许近战（禁止隔墙互打） */
  function canMeleeFromCell(game, px, py, m) {
    if (!m || m.hp <= 0 || !game.maze) return false;
    if (Math.abs(m.x - px) + Math.abs(m.y - py) !== 1) return false;
    const d = dirToFaceCell(px, py, m.x, m.y);
    if (d == null) return false;
    return !wallBlocks(game.maze, px, py, d);
  }

  function wallBlocks(maze, x, y, dir) {
    return maze[y][x].walls[dir];
  }

  function stepFrom(x, y, dir) {
    if (dir === 0) return { x, y: y - 1, wallIdx: 0 };
    if (dir === 1) return { x: x + 1, y, wallIdx: 1 };
    if (dir === 2) return { x, y: y + 1, wallIdx: 2 };
    return { x: x - 1, y, wallIdx: 3 };
  }

  function monsterAt(game, x, y) {
    return game.monsters.find((m) => m.x === x && m.y === y && m.hp > 0) || null;
  }

  function chestAt(game, x, y) {
    return game.chests.find((c) => c.x === x && c.y === y && !c.opened) || null;
  }

  function bfs(game, startX, startY, goalTest) {
    const key = (x, y) => x + ',' + y;
    const q = [[startX, startY]];
    const prev = Object.create(null);
    prev[key(startX, startY)] = null;
    const cols = COLS;
    const rows = ROWS;
    while (q.length) {
      const [x, y] = q.shift();
      if (goalTest(x, y)) {
        const path = [];
        let cx = x;
        let cy = y;
        while (cx != null) {
          path.push([cx, cy]);
          const p = prev[key(cx, cy)];
          if (!p) break;
          cx = p[0];
          cy = p[1];
        }
        path.reverse();
        return path;
      }
      for (let d = 0; d < 4; d++) {
        if (wallBlocks(game.maze, x, y, d)) continue;
        const nx = d === 1 ? x + 1 : d === 3 ? x - 1 : x;
        const ny = d === 2 ? y + 1 : d === 0 ? y - 1 : y;
        if (nx < 0 || nx >= cols || ny < 0 || ny >= rows) continue;
        const k = key(nx, ny);
        if (prev[k] !== undefined) continue;
        if (monsterAt(game, nx, ny)) continue;
        prev[k] = [x, y];
        q.push([nx, ny]);
      }
    }
    return null;
  }

  /** 与怪相邻且中间无墙时优先打「眼前」挡路的那只 */
  function pickFightMonster(game) {
    const px = game.player.x;
    const py = game.player.y;
    const adj = game.monsters.filter(
      (m) => m.hp > 0 && canMeleeFromCell(game, px, py, m)
    );
    if (!adj.length) return null;
    const fwd = stepFrom(px, py, game.player.dir);
    const blocking = adj.find((m) => m.x === fwd.x && m.y === fwd.y);
    if (blocking) return blocking;
    for (const m of adj) {
      const d = dirToFaceCell(px, py, m.x, m.y);
      if (d != null && d === game.player.dir) return m;
    }
    return adj[0];
  }

  function nearestMonsterAdjacentCells(game) {
    const goals = [];
    game.monsters.forEach((m) => {
      if (m.hp <= 0) return;
      const adj = [
        [m.x + 1, m.y],
        [m.x - 1, m.y],
        [m.x, m.y + 1],
        [m.x, m.y - 1],
      ];
      adj.forEach(([x, y]) => {
        if (x < 0 || x >= COLS || y < 0 || y >= ROWS) return;
        if (monsterAt(game, x, y)) return;
        const d = dirToFaceCell(x, y, m.x, m.y);
        if (d == null) return;
        if (wallBlocks(game.maze, x, y, d)) return;
        goals.push([x, y, m]);
      });
    });
    return goals;
  }

  function pickTarget(game) {
    const px = game.player.x;
    const py = game.player.y;
    const fightM = pickFightMonster(game);
    if (fightM) return { type: 'fight', monster: fightM };
    let best = null;
    let bestLen = 1e9;
    for (const [gx, gy, m] of nearestMonsterAdjacentCells(game)) {
      const path = bfs(game, px, py, (x, y) => x === gx && y === gy);
      if (path && path.length < bestLen) {
        bestLen = path.length;
        best = { type: 'path', path, monster: m };
      }
    }
    if (best) return best;
    for (const c of game.chests) {
      if (c.opened) continue;
      const path = bfs(game, px, py, (x, y) => x === c.x && y === c.y);
      if (path && path.length < bestLen) {
        bestLen = path.length;
        best = { type: 'path', path };
      }
    }
    if (best) return best;
    const sx = COLS - 2;
    const sy = ROWS - 2;
    const path = bfs(game, px, py, (x, y) => x === sx && y === sy);
    if (path) return { type: 'path', path };
    return { type: 'idle' };
  }

  function makeTextureFallback(hex, label) {
    const c = document.createElement('canvas');
    c.width = c.height = 64;
    const g = c.getContext('2d');
    g.fillStyle = hex;
    g.fillRect(0, 0, 64, 64);
    g.fillStyle = '#fff';
    g.font = '10px sans-serif';
    g.fillText(label, 4, 36);
    const t = new THREE.CanvasTexture(c);
    t.needsUpdate = true;
    return t;
  }

  /** 平面默认中心在原点，贴图常偏上；将几何体整体上移半格，使本地 y=0 为「脚底」贴地 */
  const SPRITE_FOOT_CLEARANCE = 0.02;

  function createSpriteFromUrls(scene, urls, scale, feetYOffset, loader) {
    loader.setCrossOrigin('anonymous');
    const mat = new THREE.MeshBasicMaterial({
      map: makeTextureFallback('#8d6e63', '?'),
      transparent: true,
      side: THREE.DoubleSide,
      depthTest: true,
    });
    let i = 0;
    function next() {
      if (i >= urls.length) return;
      const u = urls[i++];
      loader.load(
        u,
        (tex) => {
          configureTextureForSprite(tex);
          mat.map = tex;
          mat.needsUpdate = true;
        },
        undefined,
        next
      );
    }
    next();
    const geo = new THREE.PlaneGeometry(1, 1);
    geo.translate(0, 0.5, 0);
    const mesh = new THREE.Mesh(geo, mat);
    mesh.scale.set(scale, scale, scale);
    mesh.position.y = feetYOffset != null ? feetYOffset : SPRITE_FOOT_CLEARANCE;
    scene.add(mesh);
    return mesh;
  }

  const DUNGEON_RARITY_ZH = {
    common: '普通',
    uncommon: '优秀',
    rare: '稀有',
    epic: '史诗',
    legendary: '传说',
  };

  /** 侧栏单独展示，不参与「背包 16 件」截断 */
  const RITUAL_DUNGEON_ITEM_KEYS = ['bonfire_blade', 'sanctuary_scepter'];

  function isRitualDungeonItem(it) {
    if (!it || Number(it.in_warehouse) === 1) return false;
    return RITUAL_DUNGEON_ITEM_KEYS.includes(String(it.item_key || ''));
  }

  function dungeonDiceTier(dice) {
    const m = String(dice || '').match(/^(\d+)d(\d+)/i);
    const p = m ? Math.max(1, parseInt(m[1], 10) * parseInt(m[2], 10)) : 1;
    if (p <= 4) return 1;
    if (p <= 12) return 2;
    if (p <= 30) return 3;
    return 4;
  }

  const Game = {
    state: { inventory: [], player: null },
    mode: 'town',
    floor: 1,
    scene: null,
    camera: null,
    renderer: null,
    maze: null,
    monsters: [],
    chests: [],
    traps: [],
    meshes: [],
    player: { x: 1, y: 1, dir: 0, hp: 20, hpMax: 20 },
    animationState: {
      isAnimating: false,
      type: null,
      startTime: 0,
      duration: 380,
      startPos: null,
      endPos: null,
      startYaw: 0,
      deltaYaw: 0,
    },
    leftHand: null,
    rightHand: null,
    handPhase: 0,
    localLogLines: [],
    combatLock: false,
    strMod: 0,
    dexMod: 0,
    ac: 10,
    weaponDice: '1d4',
    radarLight: null,
    stairsMesh: null,
    weaponHandR: null,
    weaponHandL: null,
    _weaponHandSigR: '',
    _weaponHandSigL: '',
    trapMitigMin: 0,
    trapMitigMax: 0,
    _bootstrapped: false,
    _rotationQueue: [],
    _rotationTargetKey: '',
    _dungeonSheetSig: '',
    _dungeonLoading: false,
    /** 已废弃全量预加载；保留字段避免旧逻辑报错 */
    _dungeonAssetsReady: true,
    /** 回城时自增，作废进行中的后台预加载 UI 回调 */
    _dungeonBgPreloadGen: 0,
    /** 进入地城时从 player 快照的战斗数值；击杀同步后由 applyPlayerPayload 刷新 */
    _dungeonCombat: null,
    /** 避免同一套护甲/武器规则提示重复 Toast */
    _combatRuleHintSig: '',
    /** 单机：个人解锁层数（来自 player / dungeon_world） */
    dungeonWorld: { max_unlocked_floor: 10, bosses: [] },
    /** 本趟未存档收益（回城/存档时一次性提交） */
    runLedger: { events: [], bossMilestones: [], peakFloor: 1 },
    battleDeckState: null,
    _defendingTurn: false,
    _localBossByMilestone: {},
    _bossPollId: null,
    _autopilotUserPaused: false,
    _dungeonSheetCollapsed: false,
    autoBattleEnabled: true,
    manualActionQueue: [],
    manualTurnIndex: 0,
    editingSlotIndex: -1,
    autoActionPreset: ['main_attack'],
    turnBuffs: { focus: 0, mind_eye: 0 },
    actionCatalog: null,

    toast(msg) {
      if (window.RpgPage) {
        window.RpgPage.toast(msg);
        return;
      }
      const t = document.getElementById('toast');
      if (!t) return;
      t.textContent = msg;
      t.classList.add('show');
      clearTimeout(this._toastT);
      this._toastT = setTimeout(() => t.classList.remove('show'), 2200);
    },

    flash() {
      const f = document.getElementById('flash');
      if (!f) return;
      f.classList.add('on');
      setTimeout(() => f.classList.remove('on'), 120);
    },

    pushLocalLog(text) {
      const el = document.getElementById('local-combat-log');
      const time = new Date().toLocaleTimeString('zh-CN', { hour12: false });
      const line = `[${time}] ${text}`;
      this.localLogLines.unshift(line);
      this.localLogLines = this.localLogLines.slice(0, 50);
      if (!el) return;
      el.innerHTML = '';
      this.localLogLines.forEach((l) => {
        const d = document.createElement('div');
        d.className = 'local-log-line';
        d.textContent = l;
        el.appendChild(d);
      });
    },

    getYawForDir(d) {
      if (d === 0) return 0;
      if (d === 1) return -Math.PI / 2;
      if (d === 2) return Math.PI;
      return Math.PI / 2;
    },

    snapCameraToGrid() {
      if (!this.camera) return;
      this.camera.position.set(this.player.x * CELL, CELL * 0.5, this.player.y * CELL);
      this.camera.rotation.set(0, this.getYawForDir(this.player.dir), 0);
      this.camera.rotation.order = 'YXZ';
    },

    ensureCombatFacingMonster(m) {
      if (!m) return;
      if (!canMeleeFromCell(this, this.player.x, this.player.y, m)) return;
      const fd = dirToFaceCell(this.player.x, this.player.y, m.x, m.y);
      if (fd == null) return;
      this._rotationQueue = [];
      this._rotationTargetKey = m.x + ',' + m.y;
      if (this.animationState.isAnimating && this.animationState.type === 'rotate') {
        this.animationState.isAnimating = false;
        this.animationState.type = null;
      }
      this.player.dir = fd;
      this.snapCameraToGrid();
    },

    updateCamera() {
      if (!this.camera) return;
      const s = this.animationState;
      if (!s.isAnimating) {
        this.snapCameraToGrid();
        return;
      }
      const elapsed = performance.now() - s.startTime;
      let t = Math.min(elapsed / s.duration, 1);
      t = easeInOutQuad(t);
      if (s.type === 'move') {
        const x = s.startPos.x + (s.endPos.x - s.startPos.x) * t;
        const z = s.startPos.z + (s.endPos.z - s.startPos.z) * t;
        this.camera.position.set(x, CELL * 0.5, z);
      } else if (s.type === 'rotate') {
        const yaw = s.startYaw + s.deltaYaw * t;
        this.camera.rotation.set(0, yaw, 0);
      }
      this.camera.rotation.order = 'YXZ';
      if (t >= 1) {
        if (s.type === 'rotate') {
          s.isAnimating = false;
          s.type = null;
          this.snapCameraToGrid();
          if (this._rotationQueue && this._rotationQueue.length) {
            const step = this._rotationQueue.shift();
            this.player.dir = step.nextDir;
            s.isAnimating = true;
            s.type = 'rotate';
            s.startTime = performance.now();
            s.duration = 380;
            s.startYaw = this.camera.rotation.y;
            s.deltaYaw = step.deltaYaw;
          }
        } else {
          s.isAnimating = false;
          s.type = null;
          this.snapCameraToGrid();
        }
      }
    },

    createHands() {
      const handX = 0.14;
      const handGeo = new THREE.BoxGeometry(0.1, 0.05, 0.2);
      const handMat = new THREE.MeshLambertMaterial({ color: 0xcccccc });
      this.leftHand = new THREE.Mesh(handGeo, handMat);
      this.rightHand = new THREE.Mesh(handGeo.clone(), handMat.clone());
      this.leftHand.position.set(-handX, -0.22, -0.48);
      this.rightHand.position.set(handX, -0.22, -0.48);
      this.leftHand.visible = false;
      this.camera.add(this.leftHand);
      this.camera.add(this.rightHand);
      const wGeo = new THREE.PlaneGeometry(1, 1);
      wGeo.translate(0, 0.5, 0);
      const makeWMat = () =>
        new THREE.MeshBasicMaterial({
          map: makeTextureFallback('#37474f', 'W'),
          transparent: true,
          alphaTest: 0.01,
          side: THREE.DoubleSide,
          depthTest: false,
          depthWrite: false,
        });
      const handScale = 0.39;
      this._handViewX = handX;
      this._weaponViewY = -0.3;
      this.weaponHandR = new THREE.Mesh(wGeo, makeWMat());
      this.weaponHandR.renderOrder = 999;
      this.weaponHandR.frustumCulled = false;
      this.weaponHandR.scale.set(handScale, handScale, handScale);
      this.weaponHandR.position.set(handX, this._weaponViewY, -0.48);
      this.weaponHandR.rotation.set(0, 0, 0);
      this.weaponHandR.visible = false;
      this.camera.add(this.weaponHandR);

      this.weaponHandL = new THREE.Mesh(wGeo.clone(), makeWMat());
      this.weaponHandL.renderOrder = 998;
      this.weaponHandL.frustumCulled = false;
      this.weaponHandL.scale.set(-handScale, handScale, handScale);
      this.weaponHandL.position.set(-handX, this._weaponViewY, -0.48);
      this.weaponHandL.rotation.set(0, 0, 0);
      this.weaponHandL.visible = false;
      this.camera.add(this.weaponHandL);
    },

    _syncOneWeaponHand(mesh, w, sigProp) {
      if (!mesh) return;
      let num = w ? Math.max(0, Number(w.image_num) || 0) : 0;
      if (num < 1 && w && w.item_key) {
        num = Math.max(0, Number(itemImageNumByKey[w.item_key]) || 0);
      }
      if (w && num < 1) {
        num = 1;
      }
      const sig = (w ? w.id : '') + ':' + num + ':' + (this.mode || '');
      if (sig === this[sigProp]) return;
      this[sigProp] = sig;
      const canShow = this.mode === 'dungeon' && w && num >= 1;
      if (!canShow) {
        mesh.visible = false;
        return;
      }
      mesh.visible = true;
      const mat = mesh.material;
      const urls = urlsItemImageNum(num);
      const loader = new THREE.TextureLoader();
      loader.setCrossOrigin('anonymous');
      let i = 0;
      const next = () => {
        if (i >= urls.length) return;
        const u = urls[i++];
        loader.load(
          u,
          (tex) => {
            configureTextureForSprite(tex);
            mat.map = tex;
            mat.needsUpdate = true;
          },
          undefined,
          next
        );
      };
      next();
    },

    syncWeaponHandTexture() {
      if (!this.camera) return;
      const inv = this.state.inventory || [];
      const mainW = findEquippedWeaponByHand(inv, true);
      const offW = findEquippedWeaponByHand(inv, false);
      this._syncOneWeaponHand(this.weaponHandR, mainW, '_weaponHandSigR');
      this._syncOneWeaponHand(this.weaponHandL, offW, '_weaponHandSigL');
      let mainNum = 0;
      if (mainW) {
        mainNum = Math.max(0, Number(mainW.image_num) || 0);
        if (mainNum < 1 && mainW.item_key) {
          mainNum = Math.max(0, Number(itemImageNumByKey[mainW.item_key]) || 0);
        }
        if (mainNum < 1) mainNum = 1;
      }
      if (this.rightHand) {
        const showR = !(this.mode === 'dungeon' && mainW && mainNum >= 1 && this.weaponHandR && this.weaponHandR.visible);
        this.rightHand.visible = showR;
      }
      if (this.leftHand) this.leftHand.visible = false;
    },

    cacheDungeonCombatFromPlayer() {
      const p = this.state.player;
      if (!p) return;
      const wdm = Number(p.weapon_damage_mult);
      this._dungeonCombat = {
        strMod: Number(p.str_mod) || 0,
        ac: Number(p.ac) || 10,
        weaponDice: p.weapon_dice || '1d4',
        weaponDamageMult: Number.isFinite(wdm) && wdm > 0 ? Math.min(3, Math.max(0.25, wdm)) : 1,
      };
    },

    updateHands() {
      if (!this.rightHand) return;
      const wd = String(this.weaponDice || '1d4');
      let c = 0xcccccc;
      if (wd.includes('2d')) c = 0xffd700;
      else if (wd.includes('1d10') || wd.includes('2d6')) c = 0x00c853;
      else if (wd.includes('1d8') || wd.includes('1d6')) c = 0xd42426;
      if (this.rightHand.visible) this.rightHand.material.color.setHex(c);
      const bob = Math.sin(this.handPhase) * 0.02;
      if (this.rightHand.visible) this.rightHand.position.y = -0.22 + bob;
      const hx = this._handViewX != null ? this._handViewX : 0.14;
      const handY = -0.22 + bob;
      const wy = (this._weaponViewY != null ? this._weaponViewY : -0.3) + bob;
      if (this.weaponHandR && this.weaponHandR.visible) {
        this.weaponHandR.position.set(hx, wy, -0.48);
      }
      if (this.weaponHandL && this.weaponHandL.visible) {
        this.weaponHandL.position.set(-hx, wy, -0.48);
      }
      this.handPhase += 0.06;
      this.syncWeaponHandTexture();
    },

    _applyDungeonBgm(floor) {
      const audio = document.getElementById('bgm');
      if (!audio || this.mode !== 'dungeon') return;
      if (typeof localStorage !== 'undefined' && localStorage.getItem('rpg_bgm_muted') === '1') {
        audio.pause();
        audio.volume = 0;
        return;
      }
      audio.volume = 1;
      const urls = pickMusicSourcesForFloor(floor);
      if (!urls || !urls.length) return;
      audio.onerror = null;
      let i = 0;
      const tryOne = () => {
        if (i >= urls.length) {
          audio.onerror = null;
          return;
        }
        const u = urls[i++];
        const onOk = () => {
          audio.removeEventListener('loadeddata', onOk);
          audio.onerror = null;
        };
        audio.addEventListener('loadeddata', onOk);
        audio.onerror = () => {
          audio.removeEventListener('loadeddata', onOk);
          tryOne();
        };
        audio.src = u;
        audio.load();
        const p = audio.play();
        if (p && p.catch) p.catch(() => {});
      };
      tryOne();
    },

    applyPlayerPayload(data) {
      if (!data || !data.player) return;
      if (data.username) this.state.username = data.username;
      this.state.player = data.player;
      this.state.inventory = data.inventory || [];
      this.state.knapsack_inventory = data.knapsack_inventory || this.state.knapsack_inventory || [];
      this.state.skills = data.skills || this.state.skills || [];
      this.state.battleDeck = data.battle_deck || this.state.battleDeck || null;
      this.autoActionPreset = Array.isArray(data.auto_action_preset) && data.auto_action_preset.length
        ? data.auto_action_preset.slice(0, 5)
        : this.autoActionPreset;
      this.renderActionButtons();
      this.renderActionQueueSlots();
      const p = data.player;
      this.player.hpMax = p.hp_max;
      this.player.hp = Math.min(this.player.hp, p.hp_max);
      this.strMod = p.str_mod;
      this.dexMod = p.dex_mod;
      this.ac = p.ac;
      this.weaponDice = p.weapon_dice;
      this.trapMitigMin = Number(p.trap_mitig_min) || 0;
      this.trapMitigMax = Math.max(this.trapMitigMin, Number(p.trap_mitig_max) || 0);
      this._weaponHandSigR = '';
      this._weaponHandSigL = '';
      if (this.mode === 'dungeon') {
        this.cacheDungeonCombatFromPlayer();
        const wh = String(p.weapon_combat_hint || '');
        const ah = String(p.armor_combat_hint || '');
        const sig = wh + '\n' + ah;
        if (sig.trim() && sig !== this._combatRuleHintSig) {
          this._combatRuleHintSig = sig;
          if (wh) this.toast(wh);
          if (ah) this.toast(ah);
        }
      } else {
        this._dungeonCombat = null;
      }
      this.updateDungeonSheet();
      this.syncWeaponHandTexture();
      if (data.dungeon_world) {
        this.mergeDungeonWorld(data.dungeon_world);
      }
      if (this.mode === 'dungeon') {
        this.syncDungeonCombatBar();
      }
    },

    mergeDungeonWorld(dw) {
      if (!dw || typeof dw !== 'object') return;
      const maxF = Number(dw.max_unlocked_floor);
      this.dungeonWorld = {
        max_unlocked_floor: Number.isFinite(maxF) && maxF >= 1 ? maxF : 100000,
        bosses: Array.isArray(dw.bosses) ? dw.bosses : [],
      };
    },

    getMaxUnlockedFloor() {
      const n = Number(this.dungeonWorld && this.dungeonWorld.max_unlocked_floor);
      return Number.isFinite(n) && n >= 10 ? n : 10;
    },

    resetRunLedger() {
      this.runLedger = { events: [], bossMilestones: [], peakFloor: 1 };
      this._localBossByMilestone = {};
    },

    noteRunPeak(floor) {
      const f = Math.max(1, Math.floor(Number(floor) || 1));
      this.runLedger.peakFloor = Math.max(this.runLedger.peakFloor || 1, f);
    },

    pushRunKill(floor, monster) {
      const m = monster || {};
      this.runLedger.events.push({
        type: 'kill',
        floor: Math.max(1, Math.floor(Number(floor) || 1)),
        monster_key: String(m.key || 'unknown'),
      });
    },

    pushRunChest(floor) {
      this.runLedger.events.push({
        type: 'chest',
        floor: Math.max(1, Math.floor(Number(floor) || 1)),
      });
    },

    getOrCreateLocalBossState(floor) {
      const f = Math.floor(Number(floor) || 0);
      const unlock = this.getMaxUnlockedFloor();
      if (f % 10 !== 0 || f < 10 || f !== unlock) return null;
      if (this._localBossByMilestone[f]) return this._localBossByMilestone[f];
      const raw =
        monsterCatalog.world_boss ||
        monsterCatalog.ginger_grunt || {
          hp: 18,
          ac: 12,
          to_hit: 4,
          damage: '1d6',
          label: '世界首领',
        };
      const scaled = scaleMonster(raw, f);
      const hpMax = Math.max(1, Math.round(scaled.hp * 1000));
      const st = {
        milestone: f,
        hp: hpMax,
        max_hp: hpMax,
        ac: Math.min(40, Math.round(scaled.ac * 1.3)),
        to_hit: Math.min(30, Math.round(scaled.to_hit * 1.3)),
        damage: String(scaled.damage || '1d6'),
        label: '世界首领 · 第' + f + '层',
        monster_key: 'world_boss',
        defeated: 0,
      };
      this._localBossByMilestone[f] = st;
      return st;
    },

    findWorldBossStateForFloor(floor) {
      return this.getOrCreateLocalBossState(floor);
    },

    async commitDungeonSave(opts) {
      const silent = !!(opts && opts.silent);
      const ev = (this.runLedger && this.runLedger.events) || [];
      const bosses = (this.runLedger && this.runLedger.bossMilestones) || [];
      if (!ev.length && !bosses.length) {
        if (!silent) this.toast('本趟尚无待存档收益');
        return { ok: true, empty: true };
      }
      try {
        const data = await gameApi('dungeon_save', {
          events: ev.slice(),
          peak_floor: Math.max(this.runLedger.peakFloor || 1, this.floor || 1),
          boss_milestones_defeated: bosses.slice(),
        });
        this.applyPlayerPayload(data);
        const s = data.save || {};
        const lg = Number(s.level_gain) || Math.max(0, (Number(s.level_after) || 0) - (Number(s.level_before) || 0));
        const ig = Number(s.items_gained) || 0;
        const skillBooks = Array.isArray(s.skill_books) ? s.skill_books : [];
        if (!silent) {
          let msg =
            `存档完成：升 ${lg} 级 · 获得 ${ig} 件装备 · +${Number(s.xp_granted) || 0} 经验 · +${Number(s.gold_granted) || 0} 金币`;
          if (skillBooks.length) {
            msg += ' · 技能书：' + skillBooks.join('、');
          }
          this.toast(msg);
          this.pushLocalLog(`存档：+${lg} 级 · ${ig} 件装备 · 经验/金币已写入角色`);
          if (skillBooks.length) this.pushLocalLog('技能书：' + skillBooks.join('、'));
        }
        this.resetRunLedger();
        this.cacheDungeonCombatFromPlayer();
        return data;
      } catch (e) {
        if (!silent) this.toast('存档失败: ' + (e.message || e));
        throw e;
      }
    },

    async refreshDungeonWorld() {
      try {
        const data = await gameApi('player', {});
        if (data.dungeon_world) this.mergeDungeonWorld(data.dungeon_world);
      } catch (_) {
        /* 保持本地缓存 */
      }
    },

    stopBossPoll() {
      if (this._bossPollId != null) {
        clearInterval(this._bossPollId);
        this._bossPollId = null;
      }
    },

    findWorldBossOnFloor() {
      return this.monsters.find((m) => m.isWorldBoss) || null;
    },

    syncWorldBossHpFromServer() {
      const m = this.floor;
      if (m % 10 !== 0 || m < 10) return;
      const st = this.findWorldBossStateForFloor(m);
      const mon = this.findWorldBossOnFloor();
      if (!st || !mon || Number(st.defeated) === 1) return;
      mon.hp = Math.max(0, Number(st.hp) || 0);
      mon.maxHp = Math.max(1, Number(st.max_hp) || mon.maxHp);
    },

    startBossPollIfNeeded() {
      /* 单机模式：首领 HP 仅本地维护 */
    },

    escapeHtml(t) {
      const d = document.createElement('div');
      d.textContent = t == null ? '' : String(t);
      return d.innerHTML;
    },

    rarityTagHtml(r) {
      const k = String(r || 'common')
        .toLowerCase()
        .replace(/[^a-z0-9_-]/g, '');
      const key = k || 'common';
      const z = DUNGEON_RARITY_ZH[key] || String(r || 'common');
      return `<span class="rarity-tag rarity-${key}">${this.escapeHtml(z)}</span>`;
    },

    diceTagHtml(dice) {
      const t = dungeonDiceTier(dice);
      return `<span class="dice-tag dice-tier-${t}">${this.escapeHtml(String(dice || '1d4'))}</span>`;
    },

    updateDungeonSheet() {
      const el = document.getElementById('dungeon-sheet');
      if (!el || this.mode !== 'dungeon') return;
      const p = this.state.player;
      const inv = this.state.inventory || [];
      const peff =
        p &&
        [
          p.level,
          p.xp,
          p.gold,
          p.str_effective ?? p.str,
          p.dex_effective ?? p.dex,
          p.str_mod,
          p.dex_mod,
          this.ac,
          this.weaponDice,
          p.weapon_hit_dmg_min,
          p.weapon_hit_dmg_max,
          p.trap_mitig_min,
          p.trap_mitig_max,
          p.trap_final_dmg_min,
          p.trap_final_dmg_max,
          p.armor_roll_min,
          p.armor_roll_max,
        ].join(':');
      const wbm = this.findWorldBossOnFloor();
      const stBoss = this.floor % 10 === 0 && this.floor >= 10 ? this.findWorldBossStateForFloor(this.floor) : null;
      const bossSig = wbm
        ? 'live:' + wbm.hp + '/' + wbm.maxHp
        : stBoss && Number(stBoss.defeated) === 1
          ? 'dead:' + (stBoss.first_killer_username || '')
          : stBoss
            ? 'wait:' + (stBoss.hp != null ? stBoss.hp : '')
            : '-';
      const sig =
        this.floor +
        ':' +
        Math.floor(this.player.hp) +
        ':' +
        (peff || '') +
        ':' +
        inv.map((x) => x.id + ':' + x.equipped + ':' + (x.weapon_hand || '')).join(',') +
        ':' +
        (p && p.adventurer_title ? String(p.adventurer_title.slug || '') : '') +
        ':' +
        this.getMaxUnlockedFloor() +
        ':' +
        bossSig +
        ':' +
        (this._dungeonSheetCollapsed ? '1' : '0');
      if (sig === this._dungeonSheetSig) return;
      this._dungeonSheetSig = sig;
      const slotDefs = [
        { key: 'weapon_main', label: '主手(右)' },
        { key: 'weapon_off', label: '副手(左)' },
        { key: 'armor', label: '护甲' },
        { key: 'ring', label: '戒指' },
        { key: 'boots', label: '鞋' },
      ];
      let equipHtml = '';
      slotDefs.forEach(({ key, label }) => {
        let eq;
        if (key === 'weapon_main') {
          eq = inv.find(
            (x) =>
              Number(x.equipped) === 1 &&
              x.slot === 'weapon' &&
              (x.weapon_hand == null || x.weapon_hand === '' || x.weapon_hand === 'main')
          );
        } else if (key === 'weapon_off') {
          eq = inv.find((x) => Number(x.equipped) === 1 && x.slot === 'weapon' && x.weapon_hand === 'off');
        } else {
          eq = inv.find((x) => Number(x.equipped) === 1 && x.slot === key);
        }
        const name = eq ? this.escapeHtml(eq.label) : '（空）';
        const meta = eq
          ? `${eq.damage_dice ? this.diceTagHtml(eq.damage_dice) + ' · ' : ''}${this.rarityTagHtml(eq.rarity || 'common')}`
          : '';
        equipHtml += `<div class="ds-slot"><div class="ds-slot-label">${label}</div><div>${name}</div>${
          meta ? `<div class="ds-line">${meta}</div>` : ''
        }</div>`;
      });
      const ritualItems = inv.filter(isRitualDungeonItem).sort((a, b) => {
        const oa = RITUAL_DUNGEON_ITEM_KEYS.indexOf(String(a.item_key || ''));
        const ob = RITUAL_DUNGEON_ITEM_KEYS.indexOf(String(b.item_key || ''));
        if (oa !== ob) return oa - ob;
        return Number(a.id) - Number(b.id);
      });
      const ritualHtml =
        ritualItems.length > 0
          ? ritualItems
              .map((it) => {
                const worn =
                  Number(it.equipped) === 1
                    ? '<span class="ds-ritual-tag">已装备杂物</span> · '
                    : '';
                return `<div class="ds-ritual-row">${worn}${this.escapeHtml(it.label)} · ${this.rarityTagHtml(
                  it.rarity || 'common'
                )}</div>`;
              })
              .join('')
          : '<div class="ds-ritual-muted">（无篝火长剑 / 避难所权杖）</div>';
      const ks = Array.isArray(this.state.knapsack_inventory) ? this.state.knapsack_inventory : [];
      const bagPool = ks.length
        ? ks
        : inv.filter(
            (x) =>
              Number(x.equipped) !== 1 &&
              Number(x.in_warehouse) !== 1 &&
              !RITUAL_DUNGEON_ITEM_KEYS.includes(String(x.item_key || ''))
          ).slice(0, 16);
      const bag = bagPool.slice(0, 16);
      const bagHtml = bag.length
        ? bag
            .map(
              (it) =>
                `<div>${this.escapeHtml(it.label)} · ${this.rarityTagHtml(it.rarity || 'common')}</div>`
            )
            .join('')
        : '<div>（背包空）</div>';
      let attrHtml = '';
      if (p) {
        const fmt = (n) => (Number(n) >= 0 ? '+' : '') + n;
        const N = (x, d) => (x != null && x !== '' && !Number.isNaN(Number(x)) ? Number(x) : d);
        const se = p.str_effective != null ? p.str_effective : p.str;
        const de = p.dex_effective != null ? p.dex_effective : p.dex;
        const ce = p.con_effective != null ? p.con_effective : p.con;
        const smb = p.str_mod_base;
        const dmb = p.dex_mod_base;
        const strNaked = smb != null ? fmt(smb) : fmt(Math.floor((Number(p.str) - 10) / 2));
        const dexNaked = dmb != null ? fmt(dmb) : fmt(Math.floor((Number(p.dex) - 10) / 2));
        const ad = p.armor_dice || '1d4';
        const fmtXpNext = (x) =>
          x == null || x === '' ? '0' : typeof x === 'string' ? x : String(Number(x));
        const xpn = p.xp_to_next_level;
        const after300 = Number(p.level || 0) > 300;
        const xpSeg = ` · <span class="ds-xp-next">${after300 ? '距离下一阶还需' : '升级还需'} ${fmtXpNext(xpn)} XP</span>`;
        const wMultN = Number(p.weapon_damage_mult);
        const wMultSeg =
          Number.isFinite(wMultN) && Math.abs(wMultN - 1) > 0.001 ? ` · 持武倍率×${N(p.weapon_damage_mult, 1)}` : '';
        const armorDieD = Number(p.armor_die_d) || 0;
        const armorEffN = Number(p.armor_ac_effectiveness);
        const armorEffSeg =
          armorDieD > 0 && Number.isFinite(armorEffN) && armorEffN < 0.999
            ? ` · 加值生效${Math.round(armorEffN * 100)}%（实计 AC +${N(p.armor_ac_applied, 0)}，d1→100% d10→50% d20→0%）`
            : '';
        const lvlTxt = p.level_display ? String(p.level_display) : String(p.level);
        attrHtml = `
          <div class="ds-line">等级 ${lvlTxt}${xpSeg} · 金币 ${p.gold}</div>
          <div class="ds-line">生命 ${Math.max(0, Math.floor(this.player.hp))} / ${this.player.hpMax}（上限 ${p.hp_max}）</div>
          <div class="ds-line">STR：基础 ${p.str} → 有效 ${se}，调整值 ${fmt(p.str_mod)}（裸装调整 ${strNaked}）</div>
          <div class="ds-line">DEX：基础 ${p.dex} → 有效 ${de}，调整值 ${fmt(p.dex_mod)}（裸装调整 ${dexNaked}）</div>
          <div class="ds-line">CON：基础 ${p.con} → 有效 ${ce}</div>
          <div class="ds-line">INT ${p.int_stat} · WIS ${p.wis} · CHA ${p.cha}</div>
          <div class="ds-line">有效 AC ${this.ac}</div>
          <div class="ds-line">武器 ${this.diceTagHtml(this.weaponDice)}：骰面 ${N(p.weapon_roll_min, 1)}～${N(p.weapon_roll_max, 1)} · 命中伤害 ${N(p.weapon_hit_dmg_min, 1)}～${N(p.weapon_hit_dmg_max, 1)}（含力量${wMultSeg}）</div>
          <div class="ds-line">护甲 ${this.diceTagHtml(ad)}：骰面 ${N(p.armor_roll_min, 1)}～${N(p.armor_roll_max, 1)} · 护甲件 AC +${N(p.armor_ac_bonus, 0)}${armorEffSeg}</div>
          <div class="ds-line">陷阱：基础 ${N(p.trap_raw_min, 4)}～${N(p.trap_raw_max, 11)}（×${floorTierMultiplier(
            this.floor
          )} 档后约 ${Math.max(1, Math.round(N(p.trap_raw_min, 4) * floorTierMultiplier(this.floor)))}～${Math.max(
            1,
            Math.round(N(p.trap_raw_max, 11) * floorTierMultiplier(this.floor))
          )}） · 鞋减震 ${N(p.trap_mitig_min, 0)}～${N(p.trap_mitig_max, 0)} · 最终约 ${Math.max(
            1,
            Math.round(N(p.trap_final_dmg_min, 1) * floorTierMultiplier(this.floor))
          )}～${Math.max(1, Math.round(N(p.trap_final_dmg_max, 11) * floorTierMultiplier(this.floor)))}</div>`;
      }
      let bossSheet = '';
      if (this.floor % 10 === 0 && this.floor >= 10) {
        const st = this.findWorldBossStateForFloor(this.floor);
        const wb = this.findWorldBossOnFloor();
        if (st && Number(st.defeated) === 1) {
          const who = st.first_killer_username ? this.escapeHtml(String(st.first_killer_username)) : '未知';
          bossSheet = `<div class="ds-world-boss-sheet ds-world-boss-done">第 ${this.floor} 层首领已击败 · 首杀：${who}</div>`;
        } else if (wb) {
          const pct = wb.maxHp > 0 ? Math.min(100, Math.round((100 * wb.hp) / wb.maxHp)) : 0;
          bossSheet =
            '<div class="ds-world-boss-sheet" role="status"><div class="ds-wb-title">单机首领 · ' +
            this.escapeHtml(wb.label) +
            '</div><div class="ds-wb-hp">' +
            wb.hp +
            ' / ' +
            wb.maxHp +
            '（' +
            pct +
            '%）</div><div class="ds-wb-bar"><div class="ds-wb-fill" style="width:' +
            pct +
            '%"></div></div></div>';
        } else if (st) {
          bossSheet =
            '<div class="ds-world-boss-sheet ds-world-boss-wait">首领即将出现…</div>';
        }
      }
      const evP = (this.runLedger && this.runLedger.events) || [];
      const pendingKills = evP.filter((e) => e.type === 'kill').length;
      const pendingChests = evP.filter((e) => e.type === 'chest').length;
      const pendingBoss = ((this.runLedger && this.runLedger.bossMilestones) || []).length;
      const pendingHtml =
        '<div class="ds-pending-save">待存档：击杀 ' +
        pendingKills +
        ' · 宝箱 ' +
        pendingChests +
        (pendingBoss ? ' · 待写入首领 ' + pendingBoss : '') +
        '（回城或点「存档」后结算）</div>';
      const folded = !!this._dungeonSheetCollapsed;
      const mob =
        typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 768px)').matches;
      el.classList.toggle('dungeon-sheet--drawer-collapsed', mob && folded);
      const foldBtnLabel = folded ? (mob ? '▶' : '展开面板') : (mob ? '◀' : '折叠面板');
      el.innerHTML = `
        <button type="button" class="ds-fold-toggle${mob ? ' ds-drawer-tab' : ''}" id="ds-btn-fold" aria-expanded="${
        folded ? 'false' : 'true'
      }" aria-label="${folded ? '展开角色面板' : '收起角色面板'}">${foldBtnLabel}</button>
        <aside class="ds-sheet-panel" ${!mob && folded ? ' hidden' : ''}>
        <div class="ds-sheet-scroll">
        ${pendingHtml}
        ${
          p && p.adventurer_title
            ? `<div class="adv-title adv-title--${this.escapeHtml(String(p.adventurer_title.slug || 'porcelain'))}">${this.escapeHtml(
                String(p.adventurer_title.label || '')
              )}</div><div class="adv-title-sub">历史最深 ${Number(p.adventurer_title.peak) || 1} 层 · 个人解锁 ${
                this.getMaxUnlockedFloor()
              } 层</div>`
            : ''
        }
        <h3>角色属性（含装备）</h3>
        ${attrHtml || '<div class="ds-line">—</div>'}
        ${bossSheet}
        <h3>当前装备</h3>
        <div class="ds-equip">${equipHtml}</div>
        <h3>仪式道具（篝火 / 避难所）</h3>
        <p class="ds-ritual-hint">始终显示；使用底部战斗条中的「篝火」「避难所」。与下方背包 16 件限额无关。</p>
        <div class="ds-ritual">${ritualHtml}</div>
        <h3>行囊（最多 16 件，进入地牢仅携带此列表）</h3>
        <div class="ds-bag">${bagHtml}</div>
        </div>
        </aside>
      `;
      const bf = document.getElementById('ds-btn-fold');
      if (bf) {
        bf.onclick = () => {
          this._dungeonSheetCollapsed = !this._dungeonSheetCollapsed;
          this._dungeonSheetSig = '';
          this.updateDungeonSheet();
        };
      }
    },

    clearWorld() {
      if (!this.scene) return;
      this.meshes.forEach((o) => this.scene.remove(o));
      this.meshes = [];
      this.monsters = [];
      this.chests = [];
      this.traps = [];
      this.stairsMesh = null;
    },

    async buildFloor(levelIndex) {
      this.stopBossPoll();
      this.noteRunPeak(levelIndex);
      const li0 = Math.max(1, Math.floor(Number(levelIndex) || 0));
      if (li0 > 1) {
        const en =
          typeof localStorage === 'undefined' || localStorage.getItem('rpg_dungeon_safe_loop') !== '0';
        const w0 = this.getMaxUnlockedFloor();
        if (en && w0 >= 2) {
          const th0 = Math.ceil(w0 / 2);
          if (li0 >= th0) {
            this.toast(`「安全层折返」：已进入较深楼层，改为从第 1 层重新开始`);
            this.pushLocalLog(`折返：第 ${li0} 层 → 第 1 层`);
            await this.buildFloor(1);
            return;
          }
        }
      }
      this.clearWorld();
      this._rotationQueue = [];
      this._rotationTargetKey = '';
      this.floor = levelIndex;
      this.maze = generateMaze(COLS, ROWS);
      this.player.x = 1;
      this.player.y = 1;
      this.player.dir = 0;
      const loader = new THREE.TextureLoader();
      loader.setCrossOrigin('anonymous');
      const wallTex = makeTextureFallback('#5d4037', 'W');
      const floorTex = makeTextureFallback('#3e2723', 'F');
      floorTex.wrapS = floorTex.wrapT = THREE.RepeatWrapping;
      floorTex.repeat.set(4, 4);
      const wallH = 1.2;
      const wallMat = new THREE.MeshLambertMaterial({ map: wallTex });
      const floorMat = new THREE.MeshLambertMaterial({ map: floorTex, color: 0x898989 });
      const ceilTex = makeTextureFallback('#1a237e', 'C');
      const ceilingMat = new THREE.MeshLambertMaterial({ map: ceilTex, color: 0x555555 });
      const ti = tileIndicesForFloor(levelIndex);
      chainLoadTexture(loader, urlsTileVariantPool(ti.wall, TEX.wall), wallMat, 0);
      chainLoadTexture(loader, urlsTileVariantPool(ti.floor, TEX.floor), floorMat, 4);
      chainLoadTexture(loader, urlsTileVariantPool(ti.ceiling, TEX.wall), ceilingMat, 2);

      for (let y = 0; y < ROWS; y++) {
        for (let x = 0; x < COLS; x++) {
          const floor = new THREE.Mesh(new THREE.PlaneGeometry(CELL, CELL), floorMat);
          floor.rotation.x = -Math.PI / 2;
          floor.position.set(x * CELL, 0, y * CELL);
          this.scene.add(floor);
          this.meshes.push(floor);
          const ceiling = new THREE.Mesh(new THREE.PlaneGeometry(CELL, CELL), ceilingMat);
          ceiling.rotation.x = Math.PI / 2;
          ceiling.position.set(x * CELL, wallH, y * CELL);
          this.scene.add(ceiling);
          this.meshes.push(ceiling);
          const wg = new THREE.BoxGeometry(CELL, wallH, 0.1);
          const addW = (mesh) => {
            this.scene.add(mesh);
            this.meshes.push(mesh);
          };
          if (this.maze[y][x].walls[0]) {
            const w = new THREE.Mesh(wg, wallMat);
            w.position.set(x * CELL, wallH / 2, y * CELL - CELL / 2);
            addW(w);
          }
          if (this.maze[y][x].walls[1]) {
            const w = new THREE.Mesh(wg, wallMat);
            w.rotation.y = Math.PI / 2;
            w.position.set(x * CELL + CELL / 2, wallH / 2, y * CELL);
            addW(w);
          }
          if (this.maze[y][x].walls[2]) {
            const w = new THREE.Mesh(wg, wallMat);
            w.position.set(x * CELL, wallH / 2, y * CELL + CELL / 2);
            addW(w);
          }
          if (this.maze[y][x].walls[3]) {
            const w = new THREE.Mesh(wg, wallMat);
            w.rotation.y = Math.PI / 2;
            w.position.set(x * CELL - CELL / 2, wallH / 2, y * CELL);
            addW(w);
          }
        }
      }

      const bossState =
        levelIndex % 10 === 0 && levelIndex >= 10 ? this.findWorldBossStateForFloor(levelIndex) : null;
      const bossActive = !!(bossState && Number(bossState.defeated) !== 1);
      let mCount = Math.min(11, 4 + Math.floor(levelIndex / 2) + (levelIndex % 4 === 0 ? 1 : 0));
      if (bossActive) mCount = Math.max(0, mCount - 1);
      const sx = COLS - 2;
      const sy = ROWS - 2;
      for (let i = 0; i < mCount; i++) {
        let x;
        let y;
        do {
          x = Math.floor(Math.random() * COLS);
          y = Math.floor(Math.random() * ROWS);
        } while (
          (x <= 1 && y <= 1) ||
          (x >= COLS - 2 && y >= ROWS - 2) ||
          (x === sx && y === sy) ||
          monsterAt(this, x, y)
        );
        const pick = pickMonsterKeyForFloor(levelIndex);
        const raw = (pick && monsterCatalog[pick]) || {
          hp: 12,
          ac: 11,
          to_hit: 3,
          damage: '1d6',
          sprite: TEX.wall,
          label: '未知魔物',
          desc: '',
        };
        const def = scaleMonster(raw, levelIndex);
        const spriteNum = pick ? monsterImageNumFromDef(monsterCatalog[pick]) : 0;
        const mesh = createSpriteFromUrls(
          this.scene,
          urlsMonster(
            pick || 'unknown',
            raw.sprite || TEX.wall,
            pick ? monsterCatalog[pick] : null,
            spriteNum
          ),
          0.75,
          SPRITE_FOOT_CLEARANCE,
          loader
        );
        mesh.position.set(x * CELL, 0, y * CELL);
        this.monsters.push({
          x,
          y,
          key: pick || 'unknown',
          hp: def.hp,
          maxHp: def.maxHp,
          ac: def.ac,
          to_hit: def.to_hit,
          damage: def.damage,
          label: def.label || '怪物',
          desc: def.desc,
          mesh,
          tierMult: def.tierMult != null ? def.tierMult : monsterFloorTierMultiplier(levelIndex),
        });
        this.meshes.push(mesh);
      }

      if (bossActive && bossState) {
        let bx;
        let by;
        do {
          bx = Math.floor(Math.random() * COLS);
          by = Math.floor(Math.random() * ROWS);
        } while (
          (bx <= 1 && by <= 1) ||
          (bx >= COLS - 2 && by >= ROWS - 2) ||
          (bx === sx && by === sy) ||
          monsterAt(this, bx, by)
        );
        const wbKey = bossState.monster_key || 'world_boss';
        const rawBoss = monsterCatalog[wbKey] || {
          hp: 18,
          ac: 12,
          to_hit: 4,
          damage: '1d6',
          sprite: TEX.wall,
          label: '世界首领',
          desc: '',
        };
        const spriteNum = monsterImageNumFromDef(monsterCatalog[wbKey]) || monsterImageNumFromDef(rawBoss);
        const bmesh = createSpriteFromUrls(
          this.scene,
          urlsMonster(wbKey, rawBoss.sprite || TEX.wall, monsterCatalog[wbKey] || null, spriteNum),
          0.88,
          SPRITE_FOOT_CLEARANCE,
          loader
        );
        bmesh.position.set(bx * CELL, 0, by * CELL);
        const bhp = Math.max(0, Number(bossState.hp) || 0);
        const bmhp = Math.max(1, Number(bossState.max_hp) || 1);
        this.monsters.push({
          x: bx,
          y: by,
          key: wbKey,
          hp: bhp,
          maxHp: bmhp,
          ac: Math.min(40, Number(bossState.ac) || 12),
          to_hit: Math.min(30, Number(bossState.to_hit) || 4),
          damage: String(bossState.damage || rawBoss.damage || '1d6'),
          label: String(bossState.label || rawBoss.label || '世界首领'),
          desc: '单机首领，击败后需存档写入进度',
          mesh: bmesh,
          isWorldBoss: true,
          milestone: levelIndex,
          tierMult: monsterFloorTierMultiplier(levelIndex),
        });
        this.meshes.push(bmesh);
      }

      const chestCount = 3;
      for (let i = 0; i < chestCount; i++) {
        let x;
        let y;
        do {
          x = Math.floor(Math.random() * COLS);
          y = Math.floor(Math.random() * ROWS);
        } while (
          (x <= 1 && y <= 1) ||
          (x >= COLS - 2 && y >= ROWS - 2) ||
          (x === sx && y === sy) ||
          monsterAt(this, x, y) ||
          chestAt(this, x, y)
        );
        const mesh = createSpriteFromUrls(this.scene, urlsChestVariant(i), 0.55, SPRITE_FOOT_CLEARANCE, loader);
        mesh.position.set(x * CELL, 0, y * CELL);
        this.chests.push({ x, y, opened: false, mesh });
        this.meshes.push(mesh);
      }

      for (let i = 0; i < 2; i++) {
        let x;
        let y;
        do {
          x = Math.floor(Math.random() * COLS);
          y = Math.floor(Math.random() * ROWS);
        } while (
          (x <= 1 && y <= 1) ||
          (x === sx && y === sy) ||
          monsterAt(this, x, y)
        );
        this.traps.push({ x, y });
      }

      this.stairsMesh = createSpriteFromUrls(this.scene, urlsPortalStairs(), 0.85, SPRITE_FOOT_CLEARANCE, loader);
      this.stairsMesh.position.set(sx * CELL, 0, sy * CELL);
      this.meshes.push(this.stairsMesh);
      this.animationState.isAnimating = false;
      this.animationState.type = null;
      this.snapCameraToGrid();
      this._applyDungeonBgm(levelIndex);
      this.startBossPollIfNeeded();
      void this.reportDungeonPeakFloor(levelIndex);
    },

    faceToward(tx, ty) {
      const want = dirToFaceCell(this.player.x, this.player.y, tx, ty);
      if (want == null) return false;
      const tkey = tx + ',' + ty;
      if (this._rotationTargetKey !== tkey) {
        this._rotationTargetKey = tkey;
        this._rotationQueue = [];
        if (this.animationState.isAnimating && this.animationState.type === 'rotate') {
          this.animationState.isAnimating = false;
          this.animationState.type = null;
          this.snapCameraToGrid();
        }
      }
      if (this.player.dir === want && !this.animationState.isAnimating) {
        this._rotationQueue = [];
        return true;
      }
      if (this.animationState.isAnimating || this.combatLock) return false;
      if (!this._rotationQueue.length) {
        if (this.player.dir === want) return true;
        this._rotationQueue = buildShortestRotationSteps(this.player.dir, want);
      }
      if (!this._rotationQueue.length) return true;
      const step = this._rotationQueue.shift();
      this.player.dir = step.nextDir;
      this.animationState = {
        isAnimating: true,
        type: 'rotate',
        startTime: performance.now(),
        duration: 380,
        startYaw: this.camera.rotation.y,
        deltaYaw: step.deltaYaw,
        startPos: null,
        endPos: null,
      };
      return false;
    },

    tryStepToward(tx, ty) {
      const nx = tx;
      const ny = ty;
      const dx = nx - this.player.x;
      const dy = ny - this.player.y;
      let d = null;
      if (dx === 1) d = 1;
      else if (dx === -1) d = 3;
      else if (dy === 1) d = 2;
      else if (dy === -1) d = 0;
      if (d == null) return;
      if (this.player.dir !== d) {
        this.faceToward(nx, ny);
        return;
      }
      if (this.animationState.isAnimating || this.combatLock) return;
      if (wallBlocks(this.maze, this.player.x, this.player.y, d)) return;
      if (monsterAt(this, nx, ny)) return;
      const sx = this.player.x * CELL;
      const sz = this.player.y * CELL;
      const ex = nx * CELL;
      const ez = ny * CELL;
      this.player.x = nx;
      this.player.y = ny;
      this.animationState = {
        isAnimating: true,
        type: 'move',
        startTime: performance.now(),
        duration: 440,
        startPos: { x: sx, z: sz },
        endPos: { x: ex, z: ez },
        startYaw: 0,
        deltaYaw: 0,
      };
      this.onEnterCell(nx, ny);
    },

    async onEnterCell(x, y) {
      if (this.traps.some((t) => t.x === x && t.y === y)) {
        const tierM = floorTierMultiplier(this.floor);
        const rawBase = 4 + Math.floor(Math.random() * 8);
        const raw = Math.max(1, Math.round(rawBase * tierM));
        const lo = this.trapMitigMin;
        const hi = Math.max(lo, this.trapMitigMax);
        const mitig = lo + Math.floor(Math.random() * (hi - lo + 1));
        const dmg = Math.max(1, raw - mitig);
        this.player.hp -= dmg;
        this.toast('陷阱! 基础 ' + raw + ' · 减免 ' + mitig + ' → -' + dmg + ' HP');
        this.pushLocalLog(
          `第${this.floor}层：触发陷阱，基础 ${raw}，鞋减震 ${mitig}，实际失去 ${dmg} 生命`
        );
        this.flash();
        this.traps = this.traps.filter((t) => !(t.x === x && t.y === y));
        if (this.player.hp <= 0) await this.handleDeath();
      }
      const ch = chestAt(this, x, y);
      if (ch && !ch.opened) {
        this.combatLock = true;
        ch.opened = true;
        this.scene.remove(ch.mesh);
        try {
          this.pushRunChest(this.floor);
          this.toast('宝箱已开启（收益计入待存档）');
          this.pushLocalLog(`第${this.floor}层：开启宝箱 · 待存档`);
          this.updateDungeonSheet();
        } catch (e) {
          this.toast('记录失败: ' + (e.message || e));
        } finally {
          this.combatLock = false;
        }
      }
      if (x === COLS - 2 && y === ROWS - 2) {
        const next = this.floor + 1;
        const cap = this.getMaxUnlockedFloor();
        if (next > cap) {
          const gate = Math.floor(this.floor / 10) * 10 || 10;
          this.toast(`更深区域尚未解锁：需击败第 ${gate} 层首领并存档`);
          this.pushLocalLog(`楼梯封印：当前个人解锁至第 ${cap} 层`);
          return;
        }
        this.toast('进入第 ' + next + ' 层…');
        this.pushLocalLog(`抵达楼梯，进入第 ${next} 层`);
        await this.buildFloor(next);
      }
    },

    async handleDeath() {
      this.combatLock = true;
      this.resetRunLedger();
      let respawn = 1;
      try {
        const d = await gameApi('death', { floor: this.floor });
        respawn = Number(d.respawn_floor) || 1;
        if (d && d.stamina && this.state.player) {
          this.state.player.stamina = d.stamina;
        }
      } catch (_) {
        /* ignore */
      }
      const msg =
        respawn > 1
          ? `倒下… 本趟未存档收益已清空，在第 ${respawn} 层复苏`
          : '倒下… 本趟未存档收益已清空，从第 1 层重新开始';
      this.toast(msg);
      this.player.hp = this.player.hpMax;
      this.floor = respawn;
      this.pushLocalLog(msg);
      await this.buildFloor(respawn);
      this.combatLock = false;
    },

    async fightOnce(m) {
      if (this.combatLock) return;
      this.combatLock = true;
      if (!canMeleeFromCell(this, this.player.x, this.player.y, m)) {
        this.combatLock = false;
        return;
      }
      this.ensureCombatFacingMonster(m);
      const dc = this._dungeonCombat;
      const sm = dc ? dc.strMod : this.strMod;
      const acLocal = dc ? dc.ac : this.ac;
      const wd = dc ? dc.weaponDice : this.weaponDice;
      const wdm = dc && Number.isFinite(dc.weaponDamageMult) ? dc.weaponDamageMult : 1;
      const tierMult = m.tierMult != null ? m.tierMult : monsterFloorTierMultiplier(this.floor);

      const playerActs = this.getPlannedActions();
      const monActs = [];
      const monN = 1 + Math.floor(Math.random() * 3);
      for (let i = 0; i < monN; i++) {
        const r = Math.random();
        if (r < 0.7) monActs.push('main_attack');
        else if (r < 0.86) monActs.push('off_attack');
        else if (r < 0.94) monActs.push('skill_focus');
        else monActs.push('use_potion');
      }
      const tP = playerActs.reduce((s, a) => s + this.actionTimeCost(a), 0);
      const tM = monActs.reduce((s, a) => s + this.actionTimeCost(a), 0);
      const order = tP <= tM ? ['player', 'monster'] : ['monster', 'player'];
      const logs = [];
      const runAttack = async (fromPlayer, aKey) => {
        if (fromPlayer) {
          if (m.hp <= 0) return;
          let hitBase = Math.floor(Math.random() * 20) + 1 + sm + (this.turnBuffs.focus > 0 ? 2 : 0);
          let hit = hitBase >= m.ac || hitBase - sm === 20;
          let chip = false;
          if (!hit && Math.random() < COMBAT_CHIP_CHANCE) {
            hit = true;
            chip = true;
          }
          if (!hit) {
            logs.push('你的' + aKey + '未命中');
            return;
          }
          if (fromPlayer && (aKey.indexOf('skill_') === 0)) {
            this.playSkillEffect(aKey);
          }
          let dmg = 1;
          if (!chip) {
            if (aKey === 'off_attack') {
              dmg = Math.max(1, Math.round((rollDice('1d4') + Math.floor(sm * 0.5)) * 0.7));
            } else if (aKey === 'skill_fireball' || aKey === 'skill_arcane_bolt') {
              const sk = aKey === 'skill_arcane_bolt' ? 'arcane_bolt' : 'fireball';
              dmg = this.skillScale(rollDice('1d8') + sm, sk);
            } else if (aKey === 'skill_ice_cone') {
              dmg = this.skillScale(rollDice('1d7') + sm, 'ice_cone');
            } else {
              dmg = Math.max(1, Math.round((rollDice(wd) + sm) * wdm * (this.turnBuffs.mind_eye > 0 ? 1.15 : 1)));
            }
          }
          if (m.isWorldBoss) {
            m.hp = Math.max(0, m.hp - (chip ? 1 : dmg));
            const st = this.findWorldBossStateForFloor(m.milestone);
            if (st) st.hp = m.hp;
            logs.push((chip ? '擦伤' : '命中') + ` ${m.label} (-${chip ? 1 : dmg})`);
          } else {
            m.hp -= chip ? 1 : dmg;
            logs.push((chip ? '擦伤' : '命中') + ` ${m.label} (-${chip ? 1 : dmg})`);
          }
        } else {
          if (this.player.hp <= 0 || m.hp <= 0) return;
          let mNat = Math.floor(Math.random() * 20) + 1;
          let mTh = Number(m.to_hit) || 0;
          let mTotal = mNat + mTh;
          let mHit = mNat === 20 || (mNat !== 1 && mTotal >= acLocal);
          let mChip = false;
          if (!mHit && mNat !== 1 && Math.random() < COMBAT_CHIP_CHANCE) {
            mHit = true;
            mChip = true;
          }
          if (!mHit) {
            logs.push('怪物动作未命中');
            return;
          }
          let md = 1;
          if (!mChip) {
            if (aKey === 'off_attack') {
              md = Math.max(1, Math.round(rollDice('1d4') * Math.max(1, tierMult * 0.6)));
            } else {
              md = Math.max(1, Math.round(rollDice(m.damage) * tierMult));
            }
          }
          if (this._defendingTurn && !mChip) {
            md = Math.max(1, Math.round(md * 0.55));
          }
          this.player.hp -= mChip ? 1 : md;
          this.flash();
          logs.push(`怪物命中你 -${mChip ? 1 : md}`);
        }
      };

      const runNonAttack = (fromPlayer, aKey) => {
        if (fromPlayer) {
          if (aKey.indexOf('skill_') === 0) this.playSkillEffect(aKey);
          if (aKey === 'use_potion' || (window.BattleDeck && BattleDeck.isPotionAction(aKey))) {
            const r = this.consumePotionAction(aKey);
            logs.push(r.text);
          } else if (aKey === 'skill_focus') {
            this.turnBuffs.focus = Math.max(this.turnBuffs.focus, 2);
            logs.push('你进入凝神状态（后续命中提升）');
          } else if (aKey === 'defend' || aKey === 'skill_mana_shield') {
            this._defendingTurn = true;
            logs.push(aKey === 'skill_mana_shield' ? '你展开法力护盾' : '你进入防御姿态');
          } else if (aKey === 'skill_intellect_surge') {
            this.turnBuffs.mind_eye = Math.max(this.turnBuffs.mind_eye, 3);
            logs.push('智识涌动：法术强化');
          }
        } else {
          if (aKey === 'use_potion') {
            const heal = Math.max(2, Math.round(m.maxHp * 0.12));
            m.hp = Math.min(m.maxHp, m.hp + heal);
            logs.push(`${m.label} 恢复 ${heal}`);
          } else if (aKey === 'skill_focus') {
            logs.push(`${m.label} 在观察你的动作`);
          }
        }
      };

      for (const side of order) {
        const acts = side === 'player' ? playerActs : monActs;
        for (const aKey of acts) {
          if (m.hp <= 0 || this.player.hp <= 0) break;
          if (aKey === 'main_attack' || aKey === 'off_attack' || aKey === 'skill_fireball' || aKey === 'skill_ice_cone' || aKey === 'skill_arcane_bolt') {
            await runAttack(side === 'player', aKey);
          } else {
            runNonAttack(side === 'player', aKey);
          }
        }
      }
      if (this.turnBuffs.focus > 0) this.turnBuffs.focus--;
      if (this.turnBuffs.mind_eye > 0) this.turnBuffs.mind_eye--;
      this._defendingTurn = false;
      const msg = `回合(${tP}/${tM})：` + logs.join(' · ');
      this.toast(msg);
      this.pushLocalLog(`第${this.floor}层：${msg}`);
      if (m.hp <= 0) {
        this.scene.remove(m.mesh);
        try {
          if (m.isWorldBoss && m.milestone) {
            const ms = Number(m.milestone) || this.floor;
            if (!this.runLedger.bossMilestones.includes(ms)) {
              this.runLedger.bossMilestones.push(ms);
            }
            const newUnlock = Math.min(100000, ms + 10);
            this.dungeonWorld.max_unlocked_floor = Math.max(this.getMaxUnlockedFloor(), newUnlock);
            delete this._localBossByMilestone[ms];
            this.pushLocalLog(`第${this.floor}层：击败 ${m.label} · 解锁至第 ${newUnlock} 层（需存档生效）`);
          } else {
            this.pushRunKill(this.floor, m);
            this.pushLocalLog(`第${this.floor}层：击败 ${m.label} · 待存档`);
          }
          this.toast('击败 ' + (m.label || '怪物') + '（待存档）');
          this.updateDungeonSheet();
        } catch (e) {
          this.toast('记录失败: ' + (e.message || e));
        }
        this.monsters = this.monsters.filter((x) => x !== m);
      }
      if (this.player.hp <= 0) await this.handleDeath();
      if (!this.autoBattleEnabled) {
        this.manualActionQueue = [];
        this.manualTurnIndex = 0;
        this.editingSlotIndex = -1;
        this.renderActionQueueSlots();
        if (this.battleDeckState) this.renderActionButtons();
      }
      this.combatLock = false;
    },

    /** 仅 mode==='dungeon' 时执行：回城后挂机停止，全局只有一个地城逻辑在跑 */
    autopilotTick() {
      if (this._dungeonLoading) return;
      if (this._autopilotUserPaused) return;
      if (this.mode !== 'dungeon' || this.combatLock) return;
      if (this.animationState.isAnimating) return;
      const target = pickTarget(this);
      if (target.type === 'fight') {
        if (!this.autoBattleEnabled) return;
        const m = target.monster;
        const ok = this.faceToward(m.x, m.y);
        if (ok) void this.fightOnce(m);
        return;
      }
      if (target.type === 'path' && target.path && target.path.length >= 2) {
        const nx = target.path[1][0];
        const ny = target.path[1][1];
        if (!this.faceToward(nx, ny)) return;
        this.tryStepToward(nx, ny);
        return;
      }
    },

    async manualStep() {
      if (this.autoBattleEnabled) return;
      if (this.mode !== 'dungeon' || this.combatLock || this.animationState.isAnimating) return;
      const target = pickTarget(this);
      if (target.type === 'fight') {
        if (!this.manualActionQueue || this.manualActionQueue.length < 1) {
          this.toast('请先预设本回合动作（1-5个）');
          return;
        }
        const m = target.monster;
        const ok = this.faceToward(m.x, m.y);
        if (ok) await this.fightOnce(m);
        return;
      }
      if (target.type === 'path' && target.path && target.path.length >= 2) {
        const nx = target.path[1][0];
        const ny = target.path[1][1];
        if (!this.faceToward(nx, ny)) return;
        this.tryStepToward(nx, ny);
      }
    },

    /**
     * 地城贴图与精灵改为进入每层时由 TextureLoader 按需加载（见 buildFloor），不再全量预取 URL。
     */
    preloadAllDungeonAssetsOnce(onProgress) {
      if (onProgress) onProgress(1);
      this._dungeonAssetsReady = true;
      return Promise.resolve();
    },

    async preloadDungeonAssetsWithBar() {
      const fill = document.getElementById('dungeon-load-fill');
      const pct = document.getElementById('dungeon-load-pct');
      const label = document.getElementById('dungeon-load-label');
      const barWrap = document.getElementById('dungeon-load-bar-wrap');
      if (fill) fill.style.width = '100%';
      if (pct) pct.textContent = '100%';
      if (barWrap) barWrap.setAttribute('aria-valuenow', '100');
      if (label) label.textContent = '素材随场景按需加载，无需预取。';
      await this.preloadAllDungeonAssetsOnce(() => {});
    },

    async preloadDungeonAssetsInBackgroundWithHint() {
      const hint = document.getElementById('dungeon-bg-preload-hint');
      if (hint) hint.hidden = true;
    },

    reportDungeonPeakFloor(levelIndex) {
      const f = Math.max(1, Math.floor(Number(levelIndex) || 0));
      void gameApi('dungeon_peak_report', { floor: f })
        .then((d) => {
          if (d && d.player) {
            this.state.player = d.player;
            this.updateDungeonSheet();
          }
        })
        .catch(() => {});
    },

    freezeDungeonForTown() {
      this.stopBossPoll();
      this.mode = 'town';
      if (typeof window.setRpgBodyView === 'function') window.setRpgBodyView('rpg-view-town');
      this._dungeonBgPreloadGen++;
      const dbar = document.getElementById('dungeon-combat-bar');
      if (dbar) dbar.hidden = true;
      const hint = document.getElementById('dungeon-bg-preload-hint');
      if (hint) hint.hidden = true;
      this.combatLock = false;
      this.animationState.isAnimating = false;
      this.animationState.type = null;
      this._rotationQueue = [];
      this.snapCameraToGrid();
    },

    async _enterDungeonCore() {
      this.combatLock = false;
      this.animationState.isAnimating = false;
      this.animationState.type = null;
      this._rotationQueue = [];
      this.mode = 'dungeon';
      if (typeof window.setRpgBodyView === 'function') window.setRpgBodyView('rpg-view-dungeon');
      const ds = document.getElementById('dungeon-sheet');
      if (ds) ds.hidden = false;
      if (this.state.player) {
        this.player.hp = this.state.player.hp_max;
        this.player.hpMax = this.state.player.hp_max;
      }
      const maxU = this.getMaxUnlockedFloor();
      let startFloor = Number(this.state.player && this.state.player.dungeon_spawn_floor) || 1;
      if (!Number.isFinite(startFloor) || startFloor < 1) startFloor = 1;
      startFloor = Math.min(maxU, Math.max(1, startFloor));
      this.floor = 1;
      this.resetRunLedger();
      const deckCards = (this.state.battleDeck && this.state.battleDeck.cards) || [];
      if (window.BattleDeck && deckCards.length >= 18) {
        this.battleDeckState = BattleDeck.resetForBattle(deckCards);
        BattleDeck.refillHand(this.battleDeckState, 5);
      } else {
        this.battleDeckState = null;
        if (this.state.battleDeck && !this.state.battleDeck.complete) {
          this.toast('战斗牌组未满 18 张，将使用全部已学技能（建议回城配置牌组）');
        }
      }
      this.localLogLines = [];
      this._dungeonSheetSig = '';
      const el = document.getElementById('local-combat-log');
      if (el) el.innerHTML = '';
      await this.buildFloor(startFloor);
      if (typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 768px)').matches) {
        this._dungeonSheetCollapsed = true;
      }
      if (startFloor > 1) {
        try {
          const ack = await gameApi('jump_spawn_ack', {});
          if (ack && ack.player) {
            this.applyPlayerPayload(ack);
          }
        } catch (_) {
          /* 离线时仍可在当前层游玩；下次进城再同步 */
        }
      }
      this.cacheDungeonCombatFromPlayer();
      this.updateDungeonSheet();
      this.pushLocalLog(
        startFloor > 1 ? `跳跃法阵将你送至第 ${startFloor} 层，开始挂机探索` : '进入地下城，开始挂机探索'
      );
      this.toast('单机地下城 — 收益需「存档」或「回城」后写入角色');
      const dbar = document.getElementById('dungeon-combat-bar');
      if (dbar) dbar.hidden = false;
      const btnTown = document.getElementById('btn-town');
      if (btnTown) btnTown.disabled = false;
      this.syncDungeonCombatBar();
    },

    /** 背包或已穿杂物槽；仓库内不算 */
    inventoryHasMiscKey(key) {
      const k = String(key || '');
      return (this.state.inventory || []).some(
        (it) =>
          String(it.item_key || '') === k &&
          Number(it.in_warehouse) !== 1 &&
          String(it.slot || '') === 'misc'
      );
    },

    syncDungeonCombatBar() {
      const bon = document.getElementById('btn-campfire');
      if (bon) {
        const hasBon = this.inventoryHasMiscKey('bonfire_blade');
        bon.hidden = false;
        bon.disabled = !hasBon;
        bon.classList.toggle('dungeon-bar-btn-dim', !hasBon);
        bon.title = hasBon ? '记录本层篝火（记录后回城）' : '需要篝火长剑：杂货店购买，或从仓库取出到背包/杂物槽';
      }
      const san = document.getElementById('btn-sanctuary');
      if (san) {
        const hasSan = this.inventoryHasMiscKey('sanctuary_scepter');
        san.hidden = false;
        san.disabled = !hasSan;
        san.classList.toggle('dungeon-bar-btn-dim', !hasSan);
        const f = Number(this.floor) || 1;
        const cap = this.getMaxUnlockedFloor();
        if (!hasSan) {
          san.title = '需要避难所权杖（从仓库取出并装备杂物槽或放在背包）';
        } else if (f >= 1 && f <= cap) {
          san.title = `将避难所锚点设为当前第 ${f} 层（可为奇数层）`;
        } else {
          san.title = '当前层数无效';
        }
      }
      const muteBtn = document.getElementById('btn-bgm-mute');
      if (muteBtn) {
        const muted = typeof localStorage !== 'undefined' && localStorage.getItem('rpg_bgm_muted') === '1';
        muteBtn.textContent = muted ? '取消静音' : '静音';
      }
      const p = document.getElementById('btn-ap-pause');
      const r = document.getElementById('btn-ap-resume');
      if (p && r) {
        p.hidden = !!this._autopilotUserPaused;
        r.hidden = !this._autopilotUserPaused;
      }
      const am = document.getElementById('btn-auto-mode');
      if (am) {
        am.textContent = this.autoBattleEnabled ? '自动战斗:开' : '自动战斗:关';
      }
      this.renderActionQueueSlots();
    },

    playerHasSkill(skillKey) {
      const rows = this.state.skills || [];
      return rows.some((x) => String(x.skill_key || '') === skillKey);
    },

    playerSkillKeySet() {
      const set = {};
      const rows = this.state.skills || [];
      rows.forEach((x) => {
        const k = String(x.skill_key || '').trim();
        if (k) set[k] = true;
      });
      return set;
    },

    getActionDefsForPlayer() {
      if (this.battleDeckState && window.BattleDeck) {
        const defs = [];
        const baseLabels = { main_attack: '主手攻击', off_attack: '副手攻击', defend: '防御' };
        BattleDeck.BASE_ACTIONS.forEach((k) => {
          defs.push({ key: k, label: baseLabels[k] || k });
        });
        BattleDeck.handActions(this.battleDeckState).forEach(({ card, action, label }) => {
          if (!action) return;
          const prefix = card.card_type === 'potion' ? '药·' : '';
          defs.push({ key: action, label: prefix + (label || action), deckCard: card });
        });
        return defs;
      }

      const skillSet = this.playerSkillKeySet();
      const defs = [];
      const seen = {};

      // 1) 优先用后端动作目录（可扩展、可配表）
      if (Array.isArray(this.actionCatalog) && this.actionCatalog.length) {
        this.actionCatalog.forEach((row) => {
          const k = String(row.action_key || '').trim();
          if (!k || seen[k]) return;
          const label = String(row.label || k);
          const kind = String(row.kind || '');
          // skill_* 动作：仅当玩家学过对应技能才显示
          if (k.indexOf('skill_') === 0 || kind === 'skill' || kind === 'stance') {
            const sk = k.indexOf('skill_') === 0 ? k.slice(6) : '';
            if (sk && !skillSet[sk]) return;
          }
          seen[k] = true;
          defs.push({ key: k, label });
        });
      }

      // 2) 兜底：目录里没有写到的已学技能，自动补一个动作按钮
      Object.keys(skillSet).forEach((sk) => {
        const actionKey = 'skill_' + sk;
        if (seen[actionKey]) return;
        seen[actionKey] = true;
        defs.push({ key: actionKey, label: sk });
      });

      // 3) 再兜底：至少保证基础动作存在
      ['main_attack', 'off_attack', 'use_potion'].forEach((k) => {
        if (!seen[k]) defs.unshift({ key: k, label: k === 'main_attack' ? '主手攻击' : k === 'off_attack' ? '副手攻击' : '使用药剂' });
      });

      return defs;
    },

    renderActionButtons() {
      const host = document.getElementById('dungeon-action-buttons');
      if (!host) return;
      const defs = this.getActionDefsForPlayer();
      host.innerHTML = '';
      defs.forEach((d) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'dungeon-bar-btn';
        b.textContent = d.label;
        b.onclick = () => {
          let actionKey = d.key;
          if (d.deckCard && this.battleDeckState && window.BattleDeck) {
            const played = BattleDeck.playFromHand(this.battleDeckState, d.deckCard);
            if (!played) {
              this.toast('该卡牌已不可用');
              this.renderActionButtons();
              return;
            }
            actionKey = played;
          }
          if (this.editingSlotIndex >= 0 && this.editingSlotIndex < 5) {
            this.manualActionQueue[this.editingSlotIndex] = actionKey;
            this.toast('已替换第 ' + (this.editingSlotIndex + 1) + ' 槽为：' + d.label);
            this.editingSlotIndex = -1;
            this.renderActionQueueSlots();
            this.renderActionButtons();
            return;
          }
          if (this.manualActionQueue.length >= 5) {
            this.toast('每回合最多 5 个动作；可点击槽位后替换');
            return;
          }
          this.manualActionQueue.push(actionKey);
          this.renderActionQueueSlots();
          this.renderActionButtons();
          this.toast('已加入动作：' + d.label + '（' + this.manualActionQueue.length + '/5）');
        };
        host.appendChild(b);
      });
    },

    renderActionQueueSlots() {
      const host = document.getElementById('dungeon-action-slots');
      if (!host) return;
      const defs = this.getActionDefsForPlayer();
      const map = {};
      defs.forEach((d) => {
        map[d.key] = d.label;
      });
      host.innerHTML = '';
      for (let i = 0; i < 5; i++) {
        const key = this.manualActionQueue[i] || (this.autoBattleEnabled ? this.autoActionPreset[i] || '' : '');
        const label = key ? map[key] || key : '—';
        const d = document.createElement('button');
        d.type = 'button';
        d.className = 'dungeon-action-slot';
        if (this.editingSlotIndex === i) d.classList.add('is-editing');
        const mark = !this.autoBattleEnabled && i === (this.manualTurnIndex % 5) ? ' ▶' : '';
        d.innerHTML = `
          <div class="slot-idx">${String(i + 1)}</div>
          <div class="slot-icon">${this.actionIconHtml(key, true)}</div>
          <div class="slot-lab">${label}${mark}</div>
        `;
        d.onclick = () => {
          this.editingSlotIndex = this.editingSlotIndex === i ? -1 : i;
          this.renderActionQueueSlots();
          if (this.editingSlotIndex === i) {
            this.toast('替换第 ' + (i + 1) + ' 槽：请点下方动作按钮');
          }
        };
        host.appendChild(d);
      }
    },

    actionIconHtml(actionKey, large) {
      const key = String(actionKey || '');
      if (!key) return '<span>□</span>';
      const mainW = findEquippedWeaponByHand(this.state.inventory || [], true);
      const offW = findEquippedWeaponByHand(this.state.inventory || [], false);
      const sz = large ? 38 : 16;
      const imgTag = (num) =>
        `<img src="img/items/${String(num).padStart(4, '0')}.gif" alt="" style="width:${sz}px;height:${sz}px;vertical-align:middle;border-radius:6px">`;
      if (key === 'main_attack') return mainW ? imgTag(Number(mainW.image_num) || 1) : '🗡';
      if (key === 'off_attack') return offW ? imgTag(Number(offW.image_num) || 1) : '🛡';
      if (key === 'use_potion' || (window.BattleDeck && BattleDeck.isPotionAction(key))) return imgTag(1);
      if (key.indexOf('skill_') === 0) {
        const row = window.RpgSkillVfx && window.RpgSkillVfx.skillFromAction ? window.RpgSkillVfx.skillFromAction(key) : null;
        if (row && row.vfx && row.vfx.icon) return String(row.vfx.icon);
        return '✨';
      }
      return '◇';
    },

    actionTimeCost(actionKey) {
      if (Array.isArray(this.actionCatalog) && this.actionCatalog.length) {
        const row = this.actionCatalog.find((x) => String(x.action_key) === actionKey);
        if (row) return Math.max(1, Number(row.time_cost) || 1);
      }
      if (actionKey === 'main_attack') return 4;
      if (actionKey === 'off_attack') return 2;
      if (actionKey === 'use_potion' || (window.BattleDeck && BattleDeck.isPotionAction(actionKey))) return 3;
      if (actionKey === 'skill_focus' || actionKey === 'skill_mind_eye') return 2;
      if (actionKey === 'skill_fireball' || actionKey === 'skill_ice_cone') return 5;
      return 4;
    },

    getPlannedActions() {
      const q = this.manualActionQueue && this.manualActionQueue.length ? this.manualActionQueue.slice(0, 5) : [];
      if (this.autoBattleEnabled) {
        const a = Array.isArray(this.autoActionPreset) && this.autoActionPreset.length ? this.autoActionPreset : ['main_attack'];
        return a.slice(0, 5);
      }
      if (!q.length) return [];
      return q;
    },

    consumePotionAction(actionKey) {
      if (window.BattleDeck && BattleDeck.isPotionAction(actionKey)) {
        const iid = BattleDeck.potionItemIdFromAction(actionKey);
        const heal = Math.max(4, Math.round(this.player.hpMax * 0.2));
        this.player.hp = Math.min(this.player.hpMax, this.player.hp + heal);
        void gameApi('battle_deck_consume_potion', { item_id: iid })
          .then((d) => {
            if (d && d.inventory) this.state.inventory = d.inventory;
            if (d && d.battle_deck) this.state.battleDeck = d.battle_deck;
          })
          .catch(() => {});
        return { ok: true, heal, text: `使用药剂恢复 ${heal}` };
      }
      if (this.battleDeckState) {
        return { ok: false, heal: 0, text: '手牌中无可用药剂' };
      }
      const inv = this.state.knapsack_inventory && this.state.knapsack_inventory.length ? this.state.knapsack_inventory : this.state.inventory;
      const p = (inv || []).find((it) => {
        if (Number(it.in_warehouse) === 1) return false;
        if (Number(it.equipped) === 1) return false;
        const k = String(it.item_key || '');
        return k.includes('potion') || k.includes('life_') || k.includes('revive');
      });
      if (!p) return { ok: false, heal: 0, text: '未找到可用药剂' };
      const heal = Math.max(4, Math.round(this.player.hpMax * 0.2));
      this.player.hp = Math.min(this.player.hpMax, this.player.hp + heal);
      return { ok: true, heal, text: `使用药剂恢复 ${heal}` };
    },

    playSkillEffect(actionKey) {
      if (window.RpgSkillVfx && typeof window.RpgSkillVfx.playSkillVfx === 'function') {
        window.RpgSkillVfx.playSkillVfx(actionKey, { toast: null });
      }
    },

    skillScale(base, key) {
      const skills = this.state.skills || [];
      const row = skills.find((x) => String(x.skill_key) === key);
      if (row && row.power_mult != null) {
        return Math.max(1, Math.round(base * Number(row.power_mult)));
      }
      const lv = row ? Math.max(1, Number(row.level) || 1) : 1;
      const p = this.state.player || {};
      const intEff = Number(p.int_effective != null ? p.int_effective : p.int_stat) || 10;
      const wis = Number(p.wis) || 10;
      const scale = 1 + lv * 0.08 + (intEff - 10) * 0.015 + (wis - 10) * 0.008;
      return Math.max(0.2, Math.round(base * scale));
    },

    /**
     * @param {{ autoStart?: boolean }} [options]
     * autoStart：跳跃法阵等直达；贴图均按需加载，不再展示全量预加载界面。
     */
    openDungeonEntry(options) {
      const btnEnter = document.getElementById('btn-enter');
      if (btnEnter) btnEnter.disabled = true;
      const ov = document.getElementById('dungeon-load-overlay');
      if (ov) ov.hidden = true;
      void this._enterDungeonCore();
      if (btnEnter) btnEnter.disabled = false;
    },

    loop() {
      requestAnimationFrame(() => this.loop());
      if (this.scene && this.camera && this.renderer) {
        this.updateCamera();
        this.updateHands();
        const cy = this.camera.rotation.y;
        this.monsters.forEach((m) => {
          m.mesh.rotation.order = 'YXZ';
          m.mesh.rotation.x = 0;
          m.mesh.rotation.z = 0;
          m.mesh.rotation.y = cy;
        });
        this.chests.forEach((c) => {
          c.mesh.rotation.order = 'YXZ';
          c.mesh.rotation.x = 0;
          c.mesh.rotation.z = 0;
          c.mesh.rotation.y = cy;
        });
        if (this.stairsMesh) {
          this.stairsMesh.rotation.order = 'YXZ';
          this.stairsMesh.rotation.x = 0;
          this.stairsMesh.rotation.z = 0;
          this.stairsMesh.rotation.y = cy;
        }
        if (this.radarLight) {
          const t = performance.now() * 0.001;
          const cx = this.camera.position.x;
          const cz = this.camera.position.z;
          this.radarLight.position.set(cx, 1.15, cz);
          this.radarLight.target.position.set(cx + Math.cos(t) * 3 * CELL, 0, cz + Math.sin(t) * 3 * CELL);
          this.radarLight.target.updateMatrixWorld();
        }
        this.renderer.render(this.scene, this.camera);
      }
      const hud = document.getElementById('hud');
      if (hud) {
        if (this.mode === 'dungeon') {
          const p = this.state.player;
          const N = (x, d) => (x != null && x !== '' && !Number.isNaN(Number(x)) ? Number(x) : d);
          if (p) {
            const hitR =
              p.weapon_hit_dmg_min != null
                ? ` 命中${N(p.weapon_hit_dmg_min, 1)}～${N(p.weapon_hit_dmg_max, 1)}`
                : '';
            const tm = floorTierMultiplier(this.floor);
            const tfMin =
              p.trap_final_dmg_min != null ? Math.max(1, Math.round(N(p.trap_final_dmg_min, 1) * tm)) : null;
            const tfMax =
              p.trap_final_dmg_max != null ? Math.max(1, Math.round(N(p.trap_final_dmg_max, 11) * tm)) : null;
            const tr =
              tfMin != null && tfMax != null ? ` · 陷阱承${tfMin}～${tfMax}（×${tm}档）` : '';
            const cap = this.getMaxUnlockedFloor();
            const fmtXpHud = (x) =>
              x == null || x === '' ? '0' : typeof x === 'string' ? x : String(Number(x));
            const xpn = p.xp_to_next_level;
            const after300 = Number(p.level || 0) > 300;
            const xpHud = after300 ? `还差${fmtXpHud(xpn)}XP到下一阶` : `还差${fmtXpHud(xpn)}XP升级`;
            const lvlTxt = p.level_display ? String(p.level_display) : String(p.level);
            const pend = (this.runLedger && this.runLedger.events && this.runLedger.events.length) || 0;
            const pendTag = pend ? ` · 待存档${pend}项` : '';
            const profLabel = (p.profession && p.profession.label) || '无职业';
            const crystals = Math.max(0, Number(p.sunbeam_crystal_count) || 0);
            const metaTag = ` · ${profLabel} · 晶屑${crystals}`;
            const main = `第 ${this.floor} 层 · 个人解锁至 ${cap} 层${pendTag}${metaTag} · HP ${Math.max(
              0,
              Math.floor(this.player.hp)
            )}/${this.player.hpMax} · AC ${this.ac} · Lv${lvlTxt}（${xpHud}） · 武器 ${this.weaponDice}${hitR}${tr}`;
            hud.innerHTML = main;
          } else {
            hud.innerHTML = `第 ${this.floor} 层 · 生命 ${Math.max(0, Math.floor(this.player.hp))}/${
              this.player.hpMax
            } · 护甲 ${this.ac} · 武器 ${this.weaponDice}`;
          }
        } else {
          hud.innerHTML = '在主城 — 使用下方面板管理角色，然后进入地下城';
        }
      }
      if (this.mode === 'dungeon') this.updateDungeonSheet();
    },

    _dungeonGlSize() {
      const root = document.getElementById('game-root');
      const mob =
        typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 768px)').matches;
      if (mob && root) {
        const w = Math.max(1, root.clientWidth || window.innerWidth);
        const h = Math.max(1, root.clientHeight || window.innerHeight);
        return { w, h, aspect: w / h };
      }
      const s = Math.min(window.innerWidth, window.innerHeight, 820);
      return { w: s, h: s, aspect: 1 };
    },

    initThree() {
      this.scene = new THREE.Scene();
      this.scene.background = new THREE.Color(0x050f14);
      const { w, h, aspect } = this._dungeonGlSize();
      this.camera = new THREE.PerspectiveCamera(72, aspect, 0.1, 200);
      this.renderer = new THREE.WebGLRenderer({ antialias: true });
      this.renderer.setSize(w, h);
      document.getElementById('game-root').appendChild(this.renderer.domElement);

      const ambient = new THREE.AmbientLight(0xffffff, 0.55);
      this.scene.add(ambient);
      const dir = new THREE.DirectionalLight(0xffe082, 0.45);
      dir.position.set(2, 6, 2);
      this.scene.add(dir);
      this.radarLight = new THREE.SpotLight(0xff1744, 0.65, 14, Math.PI / 5);
      this.scene.add(this.radarLight);
      this.scene.add(this.camera);
      this.createHands();
      this.syncWeaponHandTexture();

      window.addEventListener('resize', () => {
        const { w, h, aspect } = this._dungeonGlSize();
        this.renderer.setSize(w, h);
        this.camera.aspect = aspect;
        this.camera.updateProjectionMatrix();
      });
    },

    async _loadDungeonDataJson() {
      await loadJson('data/items.json')
        .then((arr) => {
          itemImageNumByKey = {};
          (Array.isArray(arr) ? arr : []).forEach((t) => {
            if (t && t.id) itemImageNumByKey[t.id] = Math.max(0, Number(t.image_num) || 0);
          });
        })
        .catch(() => {
          itemImageNumByKey = {};
        });
      await loadJson('data/monsters.json').then((j) => {
        monsterCatalog = j || {};
        rebuildMonsterSpriteIndex();
      });
      await loadJson('data/dungeon/dungeon_music.json')
        .then((j) => {
          if (j && typeof j === 'object') dungeonMusicConfig = j;
        })
        .catch(() => {});
    },

    /** 仅地下城页：加载数据、Three、玩家；不打开主城 UI */
    async bootstrapDungeonScene() {
      await this._loadDungeonDataJson();
      if (!this.renderer) {
        this.initThree();
        setInterval(() => this.autopilotTick(), 90);
        this.loop();
      }
      try {
        const data = await gameApi('player', {});
        this.applyPlayerPayload(data);
      } catch (e) {
        this.toast('API: ' + (e.message || e));
      }
      try {
        const ac = await gameApi('combat_action_catalog', {});
        if (ac && ac.ok && Array.isArray(ac.combat_actions)) this.actionCatalog = ac.combat_actions;
      } catch (_) {
        /* use local fallback */
      }
      if (window.RpgSkillVfx && typeof window.RpgSkillVfx.loadSkillsCatalog === 'function') {
        await window.RpgSkillVfx.loadSkillsCatalog('data/skills.json');
      }
    },

    /** 单页整合模式：登录后进主城（保留兼容） */
    async bootstrap() {
      await this._loadDungeonDataJson();
      if (!this.renderer) {
        this.initThree();
        setInterval(() => this.autopilotTick(), 90);
        this.loop();
      }
      try {
        const data = await gameApi('player', {});
        this.applyPlayerPayload(data);
      } catch (e) {
        this.toast('API: ' + (e.message || e));
      }
      if (!this._bootstrapped) {
        TownUI.initTownUI(this);
        this._bootstrapped = true;
      }
      TownUI.openTown(this);
    },
  };

  window.ChristmasRPG = Game;

  window.setRpgBodyView = function (cls) {
    const b = document.body;
    b.classList.remove('rpg-view-auth', 'rpg-view-town', 'rpg-view-dungeon');
    if (cls) b.classList.add(cls);
  };
})();

document.addEventListener('DOMContentLoaded', () => {
  const g = window.ChristmasRPG;
  const btnTown = document.getElementById('btn-town');
  const btnSave = document.getElementById('btn-dungeon-save');
  if (btnSave) {
    btnSave.onclick = async () => {
      btnSave.disabled = true;
      try {
        await g.commitDungeonSave();
      } catch (_) {
        /* toast 已提示 */
      } finally {
        btnSave.disabled = false;
      }
    };
  }
  if (btnTown) {
    btnTown.onclick = async () => {
      const ext = typeof window.RPG_RETURN_TOWN === 'string' && window.RPG_RETURN_TOWN;
      btnTown.disabled = true;
      try {
        await g.commitDungeonSave({ silent: !ext });
      } catch (_) {
        btnTown.disabled = false;
        return;
      }
      if (ext) {
        location.href = ext;
        return;
      }
      g.freezeDungeonForTown();
      const ds = document.getElementById('dungeon-sheet');
      if (ds) ds.hidden = true;
      TownUI.openTown(g);
      btnTown.disabled = false;
    };
  }
  const btnEnter = document.getElementById('btn-enter');
  if (btnEnter) {
    btnEnter.onclick = () => {
      const extDungeon = typeof window.RPG_ENTER_DUNGEON === 'string' && window.RPG_ENTER_DUNGEON;
      if (extDungeon) {
        location.href = extDungeon;
        return;
      }
      TownUI.closeTownEnterDungeon(g);
    };
  }

  const bpause = document.getElementById('btn-ap-pause');
  const bresume = document.getElementById('btn-ap-resume');
  if (bpause) {
    bpause.onclick = () => {
      g._autopilotUserPaused = true;
      g.syncDungeonCombatBar();
      g.toast('已停止自动移动');
    };
  }
  if (bresume) {
    bresume.onclick = () => {
      g._autopilotUserPaused = false;
      g.syncDungeonCombatBar();
      g.toast('已开启自动移动');
    };
  }
  const bcf = document.getElementById('btn-campfire');
  if (bcf) {
    bcf.onclick = async () => {
      if (!g.inventoryHasMiscKey('bonfire_blade')) {
        g.toast('需要篝火长剑：杂货店可购，或从仓库取出。');
        return;
      }
      try {
        await g.commitDungeonSave({ silent: true });
        await gameApi('campfire_set', { floor: g.floor });
        const d = await gameApi('player', {});
        g.applyPlayerPayload(d);
        g.toast('已存档并记录篝火，返回主城');
        window.location.href = 'town.html?from_campfire=1';
      } catch (e) {
        g.toast(String(e.message || e));
      }
    };
  }
  const bSan = document.getElementById('btn-sanctuary');
  if (bSan) {
    bSan.onclick = async () => {
      if (!g.inventoryHasMiscKey('sanctuary_scepter')) {
        g.toast('需要避难所权杖：从仓库取出并装备杂物槽或放在背包。');
        return;
      }
      const f = Number(g.floor) || 1;
      const cap = g.getMaxUnlockedFloor();
      if (f < 1 || f > cap) {
        g.toast('当前层数无效，无法设立锚点');
        return;
      }
      try {
        const data = await gameApi('sanctuary_set', { floor: f, origin: 'dungeon' });
        g.applyPlayerPayload(data);
        g.toast(`避难所锚点已设为第 ${f} 层`);
      } catch (e) {
        g.toast(String(e.message || e));
      }
    };
  }
  const dbar = document.getElementById('dungeon-combat-bar');
  const dbarToggle = document.getElementById('btn-dbar-toggle');
  if (dbarToggle && dbar) {
    dbarToggle.onclick = () => {
      dbar.classList.toggle('dungeon-combat-bar--collapsed');
      const collapsed = dbar.classList.contains('dungeon-combat-bar--collapsed');
      dbarToggle.textContent = collapsed ? '展开战斗条' : '收起战斗条';
      dbarToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    };
  }
  const bmute = document.getElementById('btn-bgm-mute');
  if (bmute) {
    bmute.onclick = () => {
      const a = document.getElementById('bgm');
      const cur = typeof localStorage !== 'undefined' && localStorage.getItem('rpg_bgm_muted') === '1';
      if (cur) {
        localStorage.removeItem('rpg_bgm_muted');
        if (a) {
          a.volume = 1;
          g._applyDungeonBgm(g.floor);
        }
      } else {
        if (typeof localStorage !== 'undefined') localStorage.setItem('rpg_bgm_muted', '1');
        if (a) {
          a.pause();
          a.volume = 0;
        }
      }
      g.syncDungeonCombatBar();
    };
  }
  const bAutoMode = document.getElementById('btn-auto-mode');
  if (bAutoMode) {
    bAutoMode.onclick = () => {
      g.autoBattleEnabled = !g.autoBattleEnabled;
      g.manualTurnIndex = 0;
      g.editingSlotIndex = -1;
      g.syncDungeonCombatBar();
      g.toast(g.autoBattleEnabled ? '已开启自动战斗（按预设动作）' : '已切换手动动作队列');
    };
  }
  const bActClear = document.getElementById('btn-action-clear');
  if (bActClear) {
    bActClear.onclick = () => {
      g.manualActionQueue = [];
      g.editingSlotIndex = -1;
      g.renderActionQueueSlots();
      g.toast('已清空手动动作队列');
    };
  }
  const bActSave = document.getElementById('btn-action-save');
  if (bActSave) {
    bActSave.onclick = async () => {
      const use = g.manualActionQueue.length ? g.manualActionQueue.slice(0, 5) : ['main_attack'];
      try {
        const d = await gameApi('auto_actions_set', { actions: use });
        g.applyPlayerPayload(d);
        g.autoActionPreset = use;
        g.renderActionQueueSlots();
        g.toast('已保存自动战斗预设');
      } catch (e) {
        g.toast(String(e.message || e));
      }
    };
  }
  const bTurn = document.getElementById('btn-turn-execute');
  if (bTurn) {
    bTurn.onclick = async () => {
      if (g.autoBattleEnabled) {
        g.toast('请先关闭自动战斗，再手动执行回合');
        return;
      }
      await g.manualStep();
    };
  }
});
