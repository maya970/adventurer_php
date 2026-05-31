/* global RpgPage */
(function () {
  const { esc, onReady } = RpgPage;

  onReady(async () => {
    const root = document.getElementById('tavern-root');
    if (!root) return;
    let data = { npcs: [] };
    try {
      const r = await fetch('data/tavern_dialogues.json', { cache: 'no-store' });
      data = await r.json();
    } catch (_) {
      root.innerHTML = '<p class="empty-hint">无法加载对话数据。</p>';
      return;
    }
    const npcs = Array.isArray(data.npcs) ? data.npcs : [];
    root.innerHTML = '';
    npcs.forEach((npc) => {
      const card = document.createElement('div');
      card.className = 'panel tavern-npc-card';
      const h = document.createElement('h2');
      h.textContent = npc.name || '路人';
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'town-dg-btn town-dg-btn-muted';
      btn.textContent = '交谈';
      const box = document.createElement('div');
      box.className = 'tavern-dialogue';
      box.hidden = true;
      btn.onclick = () => {
        const open = box.hidden;
        box.hidden = !open;
        btn.textContent = open ? '收起' : '交谈';
        if (open) {
          const lines = Array.isArray(npc.lines) ? npc.lines : [];
          box.innerHTML = lines.map((ln) => `<p>${esc(ln)}</p>`).join('');
        }
      };
      card.appendChild(h);
      card.appendChild(btn);
      card.appendChild(box);
      root.appendChild(card);
    });
  });
})();
