(() => {
    const form = document.getElementById('classic-configuration');
    if (!form) return;
    const rules = JSON.parse(document.getElementById('classic-dependencies').textContent);
    const controls = new Map([...form.querySelectorAll('[data-option]')].map(el => [Number(el.dataset.option), el]));
    const matches = rule => {
        const control = controls.get(Number(rule.config_id));
        const value = control ? Number(control.value) : null;
        const match = Object.entries(rule.sub_id || {}).some(([id, bounds]) =>
            bounds.qty_minimum || bounds.qty_maximum
                ? value !== null && value >= Number(bounds.qty_minimum) && value <= Number(bounds.qty_maximum)
                : value !== null && value === Number(id));
        return rule.relation === 'sneq' ? !match : match;
    };
    function updateLinks() {
        for (const el of controls.values()) {
            if (el.tagName === 'SELECT') [...el.options].forEach(o => {
                const cycles = o.dataset.cycles ? JSON.parse(o.dataset.cycles) : null;
                o.disabled = cycles !== null && !cycles.includes(form.elements.billing_cycle.value);
            });
            el.setCustomValidity('');
        }
        for (const result of rules.filter(r => r.type === 'result')) {
            const condition = rules.find(r => r.type === 'condition' && Number(r.id) === Number(result.relation_id));
            if (!condition || !matches(condition)) continue;
            const el = controls.get(Number(result.config_id));
            if (!el) continue;
            if (el.tagName === 'SELECT') {
                for (const option of el.options) {
                    const included = Object.hasOwn(result.sub_id || {}, option.value);
                    option.disabled = option.disabled || (result.relation === 'sneq' ? included : !included);
                }
            }
            if (!matches(result)) el.setCustomValidity('此配置与其他选项不兼容，请重新选择。');
        }
        for (const el of controls.values()) {
            if (el.tagName === 'SELECT' && el.selectedOptions[0]?.disabled) el.setCustomValidity('当前选项不支持此周期，请重新选择。');
        }
    }
    let timer, revision = 0;
    async function quote() {
        const current = ++revision;
        const label = document.getElementById('classic-price');
        label.textContent = '正在获取实时价格…';
        try {
            const response = await fetch(form.dataset.quoteUrl, { method: 'POST', headers: { Accept: 'application/json' }, body: new FormData(form) });
            const result = await response.json();
            if (current !== revision) return;
            label.textContent = response.ok ? `实时价格：${result.price} ${result.currency}；开通费 ${result.setup_fee}` : Object.values(result.errors || {}).flat().join(' ') || '暂时无法报价，请稍后重试。';
        } catch (_) { if (current === revision) label.textContent = '报价连接失败，请稍后重试。'; }
    }
    form.addEventListener('change', event => {
        updateLinks();
        if (event.target.name === 'billing_cycle' || event.target.name.startsWith('options[')) { clearTimeout(timer); timer = setTimeout(quote, 500); }
    });
    updateLinks();
})();
