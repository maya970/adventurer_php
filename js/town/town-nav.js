/**
 * 侧边栏抽屉导航：顶栏「菜单」+ 左侧滑出全站链接。
 */
(function () {
  const PRIMARY = [
    ['town.html', '主城'],
    ['deck.html', '战斗牌组'],
    ['profession.html', '技能'],
    ['shop.html', '杂货店'],
    ['enhance.html', '强化工坊'],
    ['character.html', '皮肤地产'],
    ['auction.html', '拍卖行'],
    ['leaderboard.html', '排行榜'],
  ];

  const GROUPS = [
    {
      id: 'world',
      label: '世界',
      items: [
        ['guild.html', '公会'],
        ['codex.html', '魔物图鉴'],
        ['tavern.html', '酒馆'],
      ],
    },
    {
      id: 'ritual',
      label: '仪式',
      items: [
        ['tasks.html', '每日任务'],
        ['jump.html', '跳跃法阵'],
        ['profession-change.html', '转职'],
        ['forget.html', '遗忘仪式'],
      ],
    },
  ];

  function currentFileName() {
    const p = String(window.location.pathname || '').replace(/\\/g, '/');
    const seg = p.split('/').pop() || '';
    return seg || 'town.html';
  }

  function pageLabel(file) {
    for (let i = 0; i < PRIMARY.length; i++) {
      if (PRIMARY[i][0] === file) return PRIMARY[i][1];
    }
    for (let g = 0; g < GROUPS.length; g++) {
      const items = GROUPS[g].items;
      for (let j = 0; j < items.length; j++) {
        if (items[j][0] === file) return items[j][1];
      }
    }
    return '冒险者地牢';
  }

  function linkHtml(href, label, cur, cls) {
    const active = cur === href;
    if (active) {
      return (
        '<a class="' +
        cls +
        '" href="' +
        href +
        '" aria-current="page">' +
        label +
        '</a>'
      );
    }
    return '<a class="' + cls + '" href="' + href + '">' + label + '</a>';
  }

  function ensureDrawerShell() {
    let backdrop = document.getElementById('town-nav-backdrop');
    let drawer = document.getElementById('town-nav-drawer');
    if (!drawer) {
      backdrop = document.createElement('div');
      backdrop.id = 'town-nav-backdrop';
      backdrop.className = 'town-nav-backdrop';
      drawer = document.createElement('aside');
      drawer.id = 'town-nav-drawer';
      drawer.className = 'town-nav-drawer';
      drawer.setAttribute('aria-label', '站点导航');
      document.body.appendChild(backdrop);
      document.body.appendChild(drawer);
    }
    return { backdrop, drawer };
  }

  function rebuildDrawerContent(drawer, cur) {
    let body = '<div class="town-nav-drawer-head">' +
      '<span class="town-nav-drawer-title">导航</span>' +
      '<button type="button" class="town-nav-drawer-close" aria-label="关闭菜单">×</button>' +
      '</div><div class="town-nav-drawer-body">';
    body += '<section class="town-nav-drawer-section"><h3 class="town-nav-drawer-section-title">常用</h3><div class="town-nav-drawer-links">';
    body += PRIMARY.map(([h, l]) => linkHtml(h, l, cur, 'town-nav-drawer-link')).join('');
    body += '</div></section>';
    GROUPS.forEach((g) => {
      body +=
        '<section class="town-nav-drawer-section"><h3 class="town-nav-drawer-section-title">' +
        g.label +
        '</h3><div class="town-nav-drawer-links">';
      body += g.items.map(([h, l]) => linkHtml(h, l, cur, 'town-nav-drawer-link')).join('');
      body += '</div></section>';
    });
    body += '</div>';
    drawer.innerHTML = body;
  }

  function ensurePageOverlay() {
    let o = document.getElementById('rpg-nav-overlay');
    if (!o) {
      o = document.createElement('div');
      o.id = 'rpg-nav-overlay';
      o.className = 'rpg-nav-overlay';
      o.setAttribute('aria-live', 'polite');
      o.innerHTML = '<div class="rpg-nav-overlay-card">正在打开页面…</div>';
      document.body.appendChild(o);
    }
    return o;
  }

  function wireNavLinks(root, onNavigate) {
    root.querySelectorAll('a[href]').forEach((a) => {
      if (a.dataset.navWired === '1') return;
      a.dataset.navWired = '1';
      a.addEventListener('click', function (ev) {
        const href = this.getAttribute('href');
        if (!href || href.charAt(0) === '#') return;
        if (this.getAttribute('aria-current') === 'page') {
          ev.preventDefault();
          if (typeof onNavigate === 'function') onNavigate();
          return;
        }
        ev.preventDefault();
        if (typeof onNavigate === 'function') onNavigate();
        const o = ensurePageOverlay();
        o.classList.add('on');
        window.setTimeout(() => {
          window.location.href = href;
        }, 240);
      });
    });
  }

  let drawerWired = false;

  function wireDrawer(nav, backdrop, drawer) {
    function openDrawer() {
      drawer.classList.add('is-open');
      backdrop.classList.add('is-open');
      document.body.classList.add('town-nav-drawer-open');
      const btn = nav.querySelector('.town-nav-menu-btn');
      if (btn) btn.setAttribute('aria-expanded', 'true');
    }
    function closeDrawer() {
      drawer.classList.remove('is-open');
      backdrop.classList.remove('is-open');
      document.body.classList.remove('town-nav-drawer-open');
      const btn = nav.querySelector('.town-nav-menu-btn');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    }

    if (!drawerWired) {
      drawerWired = true;
      backdrop.addEventListener('click', closeDrawer);
      drawer.addEventListener('click', (ev) => {
        if (ev.target.closest('.town-nav-drawer-close')) closeDrawer();
      });
      document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape' && drawer.classList.contains('is-open')) closeDrawer();
      });
    }

    const menuBtn = nav.querySelector('.town-nav-menu-btn');
    if (menuBtn && menuBtn.dataset.navMenuWired !== '1') {
      menuBtn.dataset.navMenuWired = '1';
      menuBtn.addEventListener('click', () => {
        if (drawer.classList.contains('is-open')) closeDrawer();
        else openDrawer();
      });
    }

    wireNavLinks(drawer, closeDrawer);
    wireNavLinks(nav, closeDrawer);
  }

  function rebuildNav(nav) {
    const cur = currentFileName();
    const label = pageLabel(cur);
    nav.innerHTML =
      '<div class="town-nav-bar">' +
      '<button type="button" class="town-nav-menu-btn" aria-controls="town-nav-drawer" aria-expanded="false">' +
      '<span class="town-nav-menu-icon" aria-hidden="true">☰</span>' +
      '<span class="town-nav-menu-text">菜单</span></button>' +
      '<span class="town-nav-current" aria-live="polite">' +
      label +
      '</span>' +
      '<a class="town-nav-home-link' +
      (cur === 'town.html' ? ' is-current' : '') +
      '" href="town.html">回主城</a>' +
      '</div>';

    const { backdrop, drawer } = ensureDrawerShell();
    rebuildDrawerContent(drawer, cur);
    wireDrawer(nav, backdrop, drawer);
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.town-nav').forEach((nav) => rebuildNav(nav));
  });
})();
