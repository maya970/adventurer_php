/* global fetch */
async function gameApi(action, body) {
  const opts = {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(body ? { action, ...body } : { action }),
  };
  const res = await fetch('api.php?action=' + encodeURIComponent(action), opts);
  if (res.status === 401) {
    window.dispatchEvent(new CustomEvent('rpg:unauthorized'));
    const login = typeof window.RPG_LOGIN_URL === 'string' && window.RPG_LOGIN_URL;
    if (login) location.href = login;
    throw new Error('需要登录');
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    if (data && data.error) {
      throw new Error(String(data.error) + (data.message ? ': ' + data.message : ''));
    }
    throw new Error('服务器返回异常（HTTP ' + res.status + '），请稍后重试。');
  }
  if (data && data.ok === false && data.error) {
    throw new Error(String(data.error));
  }
  return data;
}
