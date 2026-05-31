/**
 * 主城静态文案由 JS 注入，避免 HTML 在部分服务器/缓存下中文乱码。
 */
(function () {
  const L = {
    page_title: '主城 · 冒险者地牢',
    h1: '冒险者主城',
    email_link: ' · 邮箱',
    email_title: '绑定或修改邮箱',
    forum: '官方论坛',
    logout: '退出登录',
    panel_attr_h2: '角色属性',
    panel_attr_intro: '力量、敏捷、体质、智力与装备加成一览。',
    panel_detail_h2: '装备 · 详情 · 操作',
    panel_detail_intro: '点击背包或身上装备查看详情，可进行装备、卸下、强化或上架拍卖。',
    panel_titles_h2: '称号',
    panel_titles_intro: '达成条件后解锁称号，部分称号可装备展示。',
    panel_equip_h2: '装备栏',
    panel_equip_intro: '已穿戴的武器、护甲与饰品。',
    panel_bag_h2: '背包（未入仓）',
    panel_bag_intro: '背包中的物品可装备、出售或放入仓库。已装备与仓库内物品不会参与批量出售。',
    btn_sell_all: '全部出售',
    btn_sell_except: '出售（排除等价表）',
    panel_wh_h2: '仓库',
    panel_wh_intro: '放入仓库的装备不会参与全部出售；需先取出到背包才能使用。',
    btn_refresh: '刷新状态',
    btn_enter: '进入地下城',
    btn_pk: '匹配 PK',
    btn_branch: '分支地牢',
    btn_deck: '战斗牌组',
    btn_campfire: '从篝火开始',
    safe_loop:
      '地城「安全层折返」：进入较深楼层时，自动从第 1 层重新开始本轮探索（可按需关闭）',
    sell_one_title: '出售物品',
    sell_one_cancel: '取消',
    sell_one_confirm: '确认出售',
    sell_all_title: '全部出售确认',
    sell_all_body: '将卖出背包中所有<strong>未装备</strong>且<strong>不在仓库</strong>的物品，确认后不可撤销。',
    sell_all_cancel: '取消',
    sell_all_confirm: '确认全部出售',
    sell_equiv_title: '出售（排除等价表）',
    sell_equiv_body:
      '将卖出背包中未装备物品，但<strong>保留等价表</strong>内的装备（用于强化材料）。已装备与仓库内物品不会出售。',
    sell_equiv_cancel: '取消',
    sell_equiv_confirm: '确认出售',
    nav_aria: '页面导航',
  };

  function applyTownLabels() {
    try {
      document.title = L.page_title;
    } catch (_) {
      /* ignore */
    }
    document.querySelectorAll('[data-tlabel]').forEach((el) => {
      const key = el.getAttribute('data-tlabel');
      const text = L[key];
      if (!text) return;
      if (el.getAttribute('data-thtml') === '1') {
        el.innerHTML = text;
      } else {
        el.textContent = text;
      }
    });
    document.querySelectorAll('[data-tlabel-title]').forEach((el) => {
      const key = el.getAttribute('data-tlabel-title');
      if (L[key]) el.title = L[key];
    });
    document.querySelectorAll('[data-tlabel-aria]').forEach((el) => {
      const key = el.getAttribute('data-tlabel-aria');
      if (L[key]) el.setAttribute('aria-label', L[key]);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyTownLabels);
  } else {
    applyTownLabels();
  }

  window.TownLabels = { apply: applyTownLabels, L };
})();
