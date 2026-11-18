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
    const label = document.getElementById('classic-price');
    let timer = null, revision = 0, inFlight = null, queued = null;
    const isQuoteField = name => name === 'billing_cycle' || name.startsWith('options[');
    async function quote() {
        if (inFlight || !queued) return;
        const current = queued;
        queued = null;
        inFlight = current;
        try {
            const response = await fetch(form.dataset.quoteUrl, { method: 'POST', headers: { Accept: 'application/json' }, body: current.body });
            const result = await response.json();
            if (current.revision !== revision) return;
            label.textContent = response.ok ? `实时价格：${result.price} ${result.currency}；开通费 ${result.setup_fee}` : Object.values(result.errors || {}).flat().join(' ') || '暂时无法报价，请稍后重试。';
        } catch (_) {
            if (current.revision === revision) label.textContent = '报价连接失败，请稍后重试。';
        } finally {
            inFlight = null;
            if (queued && timer === null) quote();
        }
    }
    function requestQuote(immediate = false) {
        clearTimeout(timer);
        timer = null;
        revision++;
        updateLinks();
        const invalid = [...form.elements].find(el => isQuoteField(el.name) && !el.checkValidity());
        if (invalid) {
            queued = null;
            label.textContent = '请先填写有效配置后报价。';
            if (immediate) invalid.reportValidity();
            return;
        }
        const body = new FormData();
        for (const [name, value] of new FormData(form)) {
            if (name === '_token' || isQuoteField(name)) body.append(name, value);
        }
        const key = new URLSearchParams(body).toString();
        label.textContent = '正在获取实时价格…';
        if (inFlight?.key === key) {
            inFlight.revision = revision;
            queued = null;
            return;
        }
        queued = { body, key, revision };
        if (immediate) quote();
        else timer = setTimeout(() => { timer = null; quote(); }, 250);
    }
    for (const name of ['input', 'change']) form.addEventListener(name, event => {
        if (isQuoteField(event.target.name)) requestQuote();
    });
    form.addEventListener('submit', event => {
        if (event.submitter?.hasAttribute('data-quote-trigger')) {
            event.preventDefault();
            requestQuote(true);
        } else {
            clearTimeout(timer);
            queued = null;
            revision++;
        }
    });
    updateLinks();
})();
