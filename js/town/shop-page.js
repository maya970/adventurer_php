/* global gameApi, TownUI, RpgPage */
(function () {
  const { toast, esc, onReady } = RpgPage;

  const ShopGame = RpgPage.createTownShell({ catalog: [] });

  async function loadCatalog(g) {
    const d = await gameApi('shop_catalog', {});
    g.state.catalog = Array.isArray(d.items) ? d.items : [];
  }

  function renderShop(g) {
    const list = document.getElementById('shop-items-list');
    if (!list) return;
    const p = g.state.player;
    const gold = p ? Number(p.gold) || 0 : 0;
    list.innerHTML = g.state.catalog
      .map((it) => {
        const price = Number(it.price) || 0;
        const owned = Number(it.owned) || 0;
        const maxOwn = Number(it.max_own) || 99;
        const remain = Math.max(0, maxOwn - owned);
        const maxBuy = remain > 0 ? Math.min(remain, Math.max(1, Math.floor(gold / Math.max(1, price)))) : 0;
        const disabled = remain < 1 || gold < price;
        return `<div class="shop-bonfire-row char-license-row shop-qty-row">
          <div class="shop-bonfire-meta">
            <strong>${esc(it.label || it.item_key)}</strong>
            <span>单价 ${price} 金 · 已持 ${owned}/${maxOwn}</span>
          </div>
          <label class="shop-qty-label">数量
            <input type="number" class="shop-qty-input" data-key="${esc(it.item_key)}" min="1" max="${Math.max(1, maxBuy)}" value="1" ${disabled ? 'disabled' : ''} />
          </label>
          <button type="button" class="town-dg-btn town-dg-btn-primary btn-shop-buy" data-key="${esc(it.item_key)}" ${disabled ? 'disabled' : ''}>购买</button>
        </div>`;
      })
      .join('');
    list.querySelectorAll('.btn-shop-buy').forEach((btn) => {
      btn.onclick = async () => {
        const key = btn.getAttribute('data-key');
        if (!key) return;
        const inp = list.querySelector('.shop-qty-input[data-key="' + key + '"]');
        const qty = inp ? Math.max(1, parseInt(inp.value, 10) || 1) : 1;
        try {
          const d = await gameApi('shop_buy_item', { item_key: key, quantity: qty });
          g.applyPlayerPayload(d);
          TownUI.renderAttrPanel(g.state.player);
          await loadCatalog(g);
          renderShop(g);
          const paid = Number(d.shop_paid_gold) || 0;
          const got = Number(d.shop_quantity) || qty;
          g.toast('已购入 ×' + got + (paid ? '，花费 ' + paid + ' 金' : ''));
        } catch (e) {
          g.toast(String(e.message || e));
        }
      };
    });
  }

  onReady(async () => {
    const g = ShopGame;
    const d = await gameApi('player', {});
    g.applyPlayerPayload(d);
    TownUI.initTownUI(g);
    TownUI.renderAttrPanel(g.state.player);
    await loadCatalog(g);
    renderShop(g);
  });
})();
