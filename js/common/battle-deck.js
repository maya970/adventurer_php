/* global gameApi */
/**
 * 战斗牌组：12 技能 + 6 药水，每战重置；技能用完洗牌，药水永久消耗。
 */
(function (global) {
  const BASE_ACTIONS = ['main_attack', 'off_attack', 'defend'];

  function shuffle(arr) {
    const a = arr.slice();
    for (let i = a.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
  }

  function skillActionKey(skillKey) {
    return 'skill_' + String(skillKey || '').trim();
  }

  function cardToAction(card) {
    if (!card) return null;
    if (card.card_type === 'skill') return skillActionKey(card.card_ref);
    if (card.card_type === 'potion') return 'use_potion:' + card.card_ref;
    return null;
  }

  function createBattleDeck(presetCards) {
    const cards = Array.isArray(presetCards) ? presetCards.slice() : [];
    const skillPool = cards.filter((c) => c.card_type === 'skill');
    const potionPool = cards.filter((c) => c.card_type === 'potion');
    const draw = shuffle(skillPool);
    const hand = draw.splice(0, Math.min(5, draw.length));
    return {
      preset: cards,
      skillDraw: draw,
      skillDiscard: [],
      potions: potionPool.slice(),
      hand,
      consumedPotions: [],
    };
  }

  function reshuffleSkills(state) {
    if (!state.skillDraw.length && state.skillDiscard.length) {
      state.skillDraw = shuffle(state.skillDiscard);
      state.skillDiscard = [];
    }
  }

  function drawSkillToHand(state) {
    reshuffleSkills(state);
    if (state.skillDraw.length && state.hand.length < 7) {
      state.hand.push(state.skillDraw.shift());
    }
  }

  function findHandIndex(state, card) {
    return state.hand.findIndex(
      (c) => c.card_type === card.card_type && String(c.card_ref) === String(card.card_ref)
    );
  }

  const BattleDeck = {
    BASE_ACTIONS,
    createBattleDeck,
    cardToAction,
    skillActionKey,

    resetForBattle(presetCards) {
      return createBattleDeck(presetCards);
    },

    /** 从手牌打出一张，返回 actionKey */
    playFromHand(state, card) {
      const idx = findHandIndex(state, card);
      if (idx < 0) return null;
      const c = state.hand.splice(idx, 1)[0];
      if (c.card_type === 'skill') {
        state.skillDiscard.push(c);
        drawSkillToHand(state);
      } else if (c.card_type === 'potion') {
        const pi = state.potions.findIndex((p) => String(p.card_ref) === String(c.card_ref));
        if (pi >= 0) state.potions.splice(pi, 1);
        state.consumedPotions.push(c);
      }
      return cardToAction(c);
    },

    refillHand(state, targetSize) {
      const n = targetSize == null ? 5 : targetSize;
      while (state.hand.length < n) {
        reshuffleSkills(state);
        if (!state.skillDraw.length) break;
        state.hand.push(state.skillDraw.shift());
      }
    },

    handActions(state) {
      return (state.hand || []).map((c) => ({
        card: c,
        action: cardToAction(c),
        label: c.label || c.card_ref,
      }));
    },

    isPotionAction(actionKey) {
      return String(actionKey || '').indexOf('use_potion:') === 0;
    },

    potionItemIdFromAction(actionKey) {
      const m = String(actionKey || '').match(/^use_potion:(\d+)$/);
      return m ? parseInt(m[1], 10) : 0;
    },
  };

  global.BattleDeck = BattleDeck;
})(typeof window !== 'undefined' ? window : globalThis);
