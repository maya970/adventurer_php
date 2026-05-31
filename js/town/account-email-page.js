/* global gameApi, TownUI, RpgPage */
(function () {
  const { toast, onReady } = RpgPage;

  function render(ac) {
    const intro = document.getElementById('ae-intro');
    const detail = document.getElementById('ae-detail');
    const form = document.getElementById('ae-form');
    const input = document.getElementById('ae-input');
    const btnS = document.getElementById('ae-submit');
    const btnR = document.getElementById('ae-resend');
    if (!intro || !form || !input || !btnS) return;

    if (!ac) {
      intro.textContent = '无法读取账号状态。';
      return;
    }

    const verified = ac.email_verified === true;
    const has = ac.has_email === true;
    const masked = ac.email_masked ? String(ac.email_masked) : '';

    if (verified) {
      intro.textContent = '当前账号邮箱已验证。';
      if (detail) {
        detail.hidden = false;
        detail.textContent = masked ? `绑定地址：${masked}` : '绑定地址已在服务器登记。';
      }
      form.hidden = true;
      if (btnR) btnR.hidden = true;
      return;
    }

    intro.textContent = has
      ? '邮箱已绑定，请完成验证（或重新发送验证邮件）。'
      : '绑定邮箱后可在部分功能中接收通知；验证通过前部分玩法可能受限。';
    if (detail) {
      detail.hidden = !masked;
      detail.textContent = masked ? `当前：${masked}` : '';
    }
    form.hidden = false;
    if (btnR) btnR.hidden = false;
  }

  onReady(async () => {
    const data = await gameApi('player', {});
    let account = data.account || null;
    if (data.player && typeof TownUI.renderAttrPanel === 'function') {
      TownUI.renderAttrPanel(data.player);
    }
    render(account);

    const input = document.getElementById('ae-input');
    const btnS = document.getElementById('ae-submit');
    const btnR = document.getElementById('ae-resend');

    if (btnS) {
      btnS.onclick = async () => {
        const em = input ? input.value.trim() : '';
        if (!em) {
          toast('请填写邮箱');
          return;
        }
        btnS.disabled = true;
        try {
          const d = await gameApi('account_bind_email', { email: em });
          account = d.account || account;
          render(account);
          toast(d.message || '已保存');
        } catch (e) {
          toast(String(e.message || e));
        } finally {
          btnS.disabled = false;
        }
      };
    }

    if (btnR) {
      btnR.onclick = async () => {
        btnR.disabled = true;
        try {
          const d = await gameApi('account_resend_verify', {});
          account = d.account || account;
          render(account);
          toast(d.message || '已重新发送');
        } catch (e) {
          toast(String(e.message || e));
        } finally {
          btnR.disabled = false;
        }
      };
    }
  });
})();
