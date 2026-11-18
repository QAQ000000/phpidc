document.querySelectorAll('[data-power-status]').forEach((container) => {
    const form = container.querySelector('form');
    const button = form.querySelector('button');
    const output = container.querySelector('[data-power-status-result]');
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (button.disabled) return;
        button.disabled = true;
        button.textContent = '正在查询…';
        container.setAttribute('aria-busy', 'true');
        output.classList.remove('error');
        output.textContent = '正在查询机器电源状态，请稍候…';
        let retryAfter = 0;
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: new FormData(form), credentials: 'same-origin',
                headers: { Accept: 'application/json' }, cache: 'no-store',
            });
            if (response.redirected || response.status === 401 || response.status === 419) {
                throw new Error('登录已失效，请刷新页面后重试。');
            }
            if (response.status === 429) {
                const value = response.headers.get('Retry-After');
                retryAfter = /^\d+$/.test(value || '') ? Number(value) : Math.max(1, Math.ceil((Date.parse(value) - Date.now()) / 1000) || 60);
                throw new Error(`查询过于频繁，请在 ${retryAfter} 秒后手动重试。`);
            }
            const data = await response.json();
            if (!response.ok || data.available !== true) {
                const detail = Object.values(data.errors || {}).flat()[0];
                throw new Error(detail || '上游暂未返回可用的机器电源状态，请稍后手动重试。');
            }
            const label = { on: '开机', off: '关机', unknown: '未知' }[data.state];
            if (!label) throw new Error('上游未返回有效的电源状态。');
            output.textContent = `机器电源状态：${label} · 查询时间：${new Date(data.checked_at).toLocaleString()}`;
        } catch (error) {
            output.classList.add('error');
            output.textContent = error instanceof Error ? error.message : '查询失败，请稍后手动重试。';
        } finally {
            if (retryAfter > 0) {
                window.setTimeout(() => { button.disabled = false; }, retryAfter * 1000);
            } else {
                button.disabled = false;
            }
            button.textContent = '查看机器电源状态';
            container.removeAttribute('aria-busy');
        }
    });
});
