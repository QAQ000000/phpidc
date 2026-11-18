document.querySelectorAll('[data-telemetry]').forEach((container) => {
    if (container.dataset.telemetryBound) return;
    container.dataset.telemetryBound = '1';
    const queue = [];
    let runningCharts = 0;
    const blocks = [...container.querySelectorAll('[data-telemetry-read]')].map((element) => ({
        element, form: element.querySelector('form'), button: element.querySelector('button'),
        output: element.querySelector('[data-telemetry-result]'), busy: false,
        power: element.dataset.telemetryRead === 'power',
    }));

    async function read(block) {
        block.element.setAttribute('aria-busy', 'true');
        block.output.classList.remove('error');
        block.output.textContent = '正在查询，请稍候…';
        let retryAfter = 0;
        try {
            const response = await fetch(block.form.action, {
                method: 'POST', body: new FormData(block.form), credentials: 'same-origin',
                headers: { Accept: 'application/json' }, cache: 'no-store',
            });
            if (response.redirected || response.status === 401 || response.status === 419) {
                throw new Error('登录已失效，请刷新页面后重试。');
            }
            if (response.status === 429) {
                const value = response.headers.get('Retry-After');
                retryAfter = /^\d+$/.test(value || '') ? Math.max(1, Number(value))
                    : Math.max(1, Math.ceil((Date.parse(value) - Date.now()) / 1000) || 60);
                throw new Error(`查询过于频繁，请在 ${retryAfter} 秒后手动重试。`);
            }
            const data = await response.json();
            if (!response.ok || data.available !== true) {
                throw new Error(Object.values(data.errors || {}).flat()[0] || '上游暂未返回可用数据，请稍后手动重试。');
            }
            if (block.power) {
                const label = { on: '开机', off: '关机', unknown: '未知' }[data.state];
                if (!label) throw new Error('上游未返回有效的机器电源状态。');
                block.output.textContent = `机器电源状态：${label} · 查询时间：${new Date(data.checked_at).toLocaleString()}`;
            } else {
                if (typeof data.html !== 'string') throw new Error('监控数据无效，请稍后手动重试。');
                // This endpoint renders an escaped local Blade partial, never supplier HTML.
                block.output.innerHTML = data.html;
            }
        } catch (error) {
            block.output.classList.add('error');
            block.output.textContent = error instanceof Error ? error.message : '查询失败，请稍后手动重试。';
        } finally {
            block.busy = false;
            block.element.removeAttribute('aria-busy');
            if (retryAfter > 0) {
                window.setTimeout(() => { block.button.disabled = false; }, retryAfter * 1000);
            } else {
                block.button.disabled = false;
            }
        }
    }

    function pump() {
        while (runningCharts < 3 && queue.length > 0) {
            const block = queue.shift();
            runningCharts++;
            read(block).finally(() => { runningCharts--; pump(); });
        }
    }

    function request(block) {
        if (block.busy || block.button.disabled) return;
        block.busy = true;
        block.button.disabled = true;
        block.output.textContent = '等待查询…';
        if (block.power) {
            read(block);
        } else {
            queue.push(block);
            pump();
        }
    }

    blocks.forEach((block) => {
        block.form.addEventListener('submit', (event) => { event.preventDefault(); request(block); });
        request(block);
    });
});
