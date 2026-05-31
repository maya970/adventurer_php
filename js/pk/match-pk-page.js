/* global gameApi, BattleDeck, THREE, PlayerMetaBar, RpgPage, ManSprite */
(function () {
  let player = null;
  let opponent = null;
  let deckState = null;
  let turnQueue = [];
  let myHp = 0;
  let myHpMax = 0;
  let oppHp = 0;
  let oppHpMax = 0;
  let defending = false;
  let intellectSurge = 0;
  let scene = null;
  let renderer = null;
  let oppMesh = null;

  const toast = (msg) => RpgPage.toast(msg);

  function log(msg) {
    const el = document.getElementById('pk-log');
    if (!el) return;
    const d = document.createElement('div');
    d.textContent = msg;
    el.prepend(d);
  }

  function rollDice(expr) {
    const m = String(expr || '1d6')
      .toLowerCase()
      .match(/^(\d+)d(\d+)([+-]\d+)?$/);
    if (!m) return 1;
    let s = m[3] ? parseInt(m[3], 10) : 0;
    const n = Math.min(20, parseInt(m[1], 10));
    const faces = Math.min(100, parseInt(m[2], 10));
    for (let i = 0; i < n; i++) s += 1 + Math.floor(Math.random() * faces);
    return Math.max(1, s);
  }

  function renderHp() {
    const el = document.getElementById('pk-hp-bar');
    if (!el) return;
    el.innerHTML =
      '你 ' +
      Math.max(0, myHp) +
      '/' +
      myHpMax +
      ' · 对手 ' +
      Math.max(0, oppHp) +
      '/' +
      oppHpMax;
  }

  function renderHand() {
    const host = document.getElementById('pk-hand');
    if (!host || !deckState) return;
    host.innerHTML = '';
    BattleDeck.handActions(deckState).forEach(({ card, label }) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'town-dg-btn';
      b.textContent = (card.card_type === 'potion' ? '药·' : '') + (label || card.card_ref);
      b.onclick = () => queueCard(card);
      host.appendChild(b);
    });
  }

  function renderQueue() {
    const el = document.getElementById('pk-queue');
    if (!el) return;
    el.textContent = turnQueue.length
      ? turnQueue.map((a) => a.label || a.key).join(' → ')
      : '（空）';
  }

  function queueAction(key, label) {
    if (turnQueue.length >= 5) {
      toast('本回合最多 5 个动作');
      return;
    }
    turnQueue.push({ key, label: label || key, card: null });
    renderQueue();
  }

  function queueCard(card) {
    if (turnQueue.length >= 5) {
      toast('本回合最多 5 个动作');
      return;
    }
    const key = BattleDeck.playFromHand(deckState, card);
    if (!key) return;
    turnQueue.push({ key, label: card.label || card.card_ref, card });
    renderHand();
    renderQueue();
  }

  function skillScale(base, intEff) {
    const ie = Number(intEff) || 10;
    return Math.max(1, Math.round(base * (1 + (ie - 10) * 0.04)));
  }

  async function runPlayerAction(act) {
    const key = act.key;
    if (key === 'defend') {
      defending = true;
      log('你进入防御姿态');
      return;
    }
    if (BattleDeck.isPotionAction(key)) {
      const iid = BattleDeck.potionItemIdFromAction(key);
      const heal = Math.max(6, Math.round(myHpMax * 0.22));
      myHp = Math.min(myHpMax, myHp + heal);
      log('使用药水，恢复 ' + heal);
      try {
        await gameApi('battle_deck_consume_potion', { item_id: iid });
      } catch (_) {
        /* 已本地移除 */
      }
      renderHp();
      return;
    }
    const sm = Number(player.str_mod) || 0;
    const wd = String(player.weapon_dice || '1d6');
    const wdm = Number(player.weapon_damage_mult) || 1;
    const intE = Number(player.int_effective) || 10;
    let dmg = 1;
    if (key === 'off_attack') {
      dmg = Math.max(1, Math.round((rollDice('1d4') + Math.floor(sm * 0.5)) * 0.7 * wdm));
    } else if (key === 'skill_fireball' || key === 'skill_arcane_bolt') {
      let b = rollDice('1d8') + sm;
      if (intellectSurge > 0) b = Math.round(b * 1.2);
      dmg = skillScale(b, intE);
    } else if (key === 'skill_ice_cone') {
      dmg = skillScale(rollDice('1d7') + sm, intE);
    } else if (key === 'skill_intellect_surge') {
      intellectSurge = 3;
      log('智识涌动：后续法术强化');
      return;
    } else if (key === 'skill_mana_shield') {
      defending = true;
      log('法力护盾：本回合减伤');
      return;
    } else {
      dmg = Math.max(1, Math.round((rollDice(wd) + sm) * wdm));
    }
    const hit = Math.floor(Math.random() * 20) + 1 + sm >= (Number(opponent.ac) || 10);
    if (!hit) {
      log('你的攻击未命中');
      return;
    }
    oppHp -= dmg;
    log('命中对手 -' + dmg);
    renderHp();
  }

  function runOpponentActions() {
    const sm = Number(opponent.str_mod) || 0;
    const wd = String(opponent.weapon_dice || '1d6');
    const wdm = Number(opponent.weapon_damage_mult) || 1;
    const n = 1 + Math.floor(Math.random() * 2);
    for (let i = 0; i < n; i++) {
      if (oppHp <= 0 || myHp <= 0) break;
      const hit = Math.floor(Math.random() * 20) + 1 + sm >= (Number(player.ac) || 10);
      if (!hit) {
        log('对手未命中');
        continue;
      }
      let md = Math.max(1, Math.round((rollDice(wd) + sm) * wdm * 0.85));
      if (defending) md = Math.max(1, Math.round(md * 0.45));
      myHp -= md;
      log('对手命中你 -' + md);
    }
    defending = false;
    renderHp();
  }

  async function finishBattle(won) {
    try {
      const data = await gameApi('pk_finish', { won: won ? 1 : 0 });
      const rw = (data && data.reward) || {};
      toast(won ? '胜利！+' + (rw.xp || 0) + ' 经验' : '落败，仍获得少量经验');
    } catch (e) {
      toast(String(e.message || e));
    }
    setTimeout(() => {
      location.href = 'town.html';
    }, 1500);
  }

  async function executeTurn() {
    if (!turnQueue.length) {
      toast('请先安排本回合动作');
      return;
    }
    for (const act of turnQueue.slice(0, 5)) {
      if (oppHp <= 0 || myHp <= 0) break;
      await runPlayerAction(act);
    }
    turnQueue = [];
    renderQueue();
    if (oppHp <= 0) {
      await finishBattle(true);
      return;
    }
    runOpponentActions();
    BattleDeck.refillHand(deckState, 5);
    renderHand();
    if (myHp <= 0) {
      await finishBattle(false);
    }
  }

  function initScene(opp) {
    const wrap = document.getElementById('pk-scene-wrap');
    const canvas = document.getElementById('pk-canvas');
    if (!wrap || !canvas || !window.THREE) return;
    const w = wrap.clientWidth || 640;
    const h = wrap.clientHeight || 360;
    scene = new THREE.Scene();
    scene.background = new THREE.Color(0x0a0e14);
    const camera = new THREE.PerspectiveCamera(55, w / h, 0.1, 100);
    camera.position.set(0, 1.6, 3.2);
    renderer = new THREE.WebGLRenderer({ canvas, antialias: true });
    renderer.setSize(w, h);
    scene.add(new THREE.AmbientLight(0xffffff, 0.65));
    const dl = new THREE.DirectionalLight(0xffffff, 0.85);
    dl.position.set(2, 4, 3);
    scene.add(dl);
    const floor = new THREE.Mesh(
      new THREE.PlaneGeometry(8, 8),
      new THREE.MeshStandardMaterial({ color: 0x1a2332 })
    );
    floor.rotation.x = -Math.PI / 2;
    scene.add(floor);
    const manPng = window.ManSprite ? ManSprite.resolveManPng(opp) : 1001;
    const loader = new THREE.TextureLoader();
    const mat = new THREE.SpriteMaterial({ map: null });
    if (window.ManSprite) {
      ManSprite.loadThreeFrame(loader, manPng, ManSprite.FRAME_FRONT.col, ManSprite.FRAME_FRONT.row, (tex) => {
        if (tex) mat.map = tex;
        mat.needsUpdate = true;
      });
    } else {
      const tex = loader.load('img/man/1001.png');
      tex.flipY = true;
      mat.map = tex;
    }
    oppMesh = new THREE.Sprite(mat);
    oppMesh.scale.set(1.2, 1.6, 1);
    oppMesh.position.set(0, 1.1, -1.5);
    scene.add(oppMesh);
    const hud = document.getElementById('pk-scene-hud');
    if (hud) {
      hud.textContent =
        (opp.display_name || opp.username || '对手') +
        ' · Lv' +
        (opp.level || 1) +
        ' · ' +
        (opp.profession_label || '');
    }
    function loop() {
      requestAnimationFrame(loop);
      if (renderer && scene && camera) renderer.render(scene, camera);
    }
    loop();
  }

  RpgPage.onReady(async () => {
    const pl = await gameApi('player', {});
    player = pl.player || {};
    RpgPage.renderMetaBar(player);
    myHpMax = Number(player.hp_max) || 20;
    myHp = myHpMax;
    const deckPreset = (pl.battle_deck && pl.battle_deck.cards) || [];
    if (!deckPreset.length || !pl.battle_deck.complete) {
      toast('请先在主城配置 18 张战斗牌组');
      setTimeout(() => (location.href = 'deck.html'), 1200);
      return;
    }
    deckState = BattleDeck.resetForBattle(deckPreset);
    BattleDeck.refillHand(deckState, 5);
    renderHand();
    const mk = await gameApi('pk_match', {});
    if (!mk.ok) throw new Error(mk.error || '匹配失败');
    opponent = mk.opponent || {};
    oppHpMax = Number(opponent.hp_max) || 20;
    oppHp = oppHpMax;
    renderHp();
    const info = document.getElementById('pk-match-info');
    if (info) {
      info.textContent =
        '对手：' +
        (opponent.display_name || opponent.username) +
        '（Lv' +
        (opponent.level || 1) +
        '）· 由电脑操控其数值';
    }
    initScene(opponent);
    document.getElementById('pk-btn-base-main').onclick = () => queueAction('main_attack', '主手');
    document.getElementById('pk-btn-base-off').onclick = () => queueAction('off_attack', '副手');
    document.getElementById('pk-btn-base-def').onclick = () => queueAction('defend', '防御');
    document.getElementById('pk-btn-turn').onclick = () => executeTurn();
  });
})();
