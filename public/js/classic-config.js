(() => {
    const form = document.getElementById('classic-configuration');
    if (!form) return;
    const rules = JSON.parse(document.getElementById('classic-dependencies').textContent);
    const controls = new Map([...form.querySelectorAll('[data-option]')].map(el => [Number(el.dataset.option), el]));
    const matches = rule => {
        const control = controls.get(Number(rule.config_id));
        const value = control ? Number(control.value) : null;
        const match = Object.entries(rule.sub_id || {}).some(([id, bounds]) =>
            ['quantity', 'slider'].includes(control?.dataset.optionType)
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
            if (el.dataset.qtyStage !== undefined) {
                const value = Number(el.value), stage = Math.max(1, Number(el.dataset.qtyStage));
                if (value % stage !== 0 && value !== Number(el.min) && value !== Number(el.max)) {
                    el.setCustomValidity(`数量须为 ${stage} 的倍数，或选择最小值 ${el.min}、最大值 ${el.max}。`);
                }
            }
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
    const token = form.elements.namedItem('quote_token');
    const checkoutButton = form.querySelector('[data-checkout-trigger]');
    let confirmationRequired = form.dataset.confirmationRequired === '1', checkoutBusy = false;
    let timer = null, revision = 0, inFlight = null, queued = null;
    const isQuoteField = name => name === 'billing_cycle' || name.startsWith('options[');
    const isPriceField = name => name === 'billing_cycle' || name.startsWith('options[configoption][');

    function clearConfirmation() {
        token.value = '';
        confirmationRequired = false;
        form.dataset.confirmationRequired = '0';
        checkoutButton.textContent = '核价并创建账单';
    }

    function showQuote(result, requiresConfirmation = false, message = '') {
        if (typeof result.price !== 'string' || typeof result.setup_fee !== 'string' || typeof result.currency !== 'string'
            || typeof result.quote_token !== 'string' || result.quote_token === '') {
            throw new Error('未取得有效价格，请重新预估或提交核验。');
        }
        token.value = result.quote_token;
        confirmationRequired = requiresConfirmation;
        form.dataset.confirmationRequired = requiresConfirmation ? '1' : '0';
        const prefix = requiresConfirmation ? '上游核验价格' : '本地预估价格';
        const hint = requiresConfirmation ? '请确认新价格后创建账单。' : '本地预估，最终以提交核验为准。';
        label.textContent = `${message ? message + ' ' : ''}${prefix}：${result.price} ${result.currency}（商品周期价）；开通费 ${result.setup_fee}。${hint}优惠券在创建账单时计算。`;
        checkoutButton.textContent = requiresConfirmation ? '确认新价格并创建账单' : '核价并创建账单';
    }

    async function quote() {
        if (checkoutBusy || inFlight || !queued) return;
        const current = queued;
        queued = null;
        inFlight = current;
        try {
            const response = await fetch(form.dataset.quoteUrl, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' }, body: current.body });
            const result = await response.json();
            if (current.revision !== revision) return;
            if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || '暂时无法预估价格，请稍后重试。');
            showQuote(result);
        } catch (_) {
            if (current.revision === revision) label.textContent = _ instanceof Error ? _.message : '预估连接失败，请稍后重试。';
        } finally {
            inFlight = null;
            if (queued && timer === null) quote();
        }
    }
    function requestQuote(immediate = false) {
        if (checkoutBusy) return;
        clearTimeout(timer);
        timer = null;
        revision++;
        clearConfirmation();
        updateLinks();
        const invalid = [...form.elements].find(el => isPriceField(el.name) && !el.checkValidity());
        if (invalid) {
            queued = null;
            label.textContent = '请先填写有效配置后报价。';
            if (immediate) invalid.reportValidity();
            return;
        }
        const body = new FormData();
        for (const [name, value] of new FormData(form)) {
            if (name === '_token' || isPriceField(name)) body.append(name, value);
        }
        const key = new URLSearchParams(body).toString();
        label.textContent = '正在计算本地预估价格…';
        if (inFlight?.key === key) {
            inFlight.revision = revision;
            queued = null;
            return;
        }
        queued = { body, key, revision };
        if (immediate) quote();
        else timer = setTimeout(() => { timer = null; quote(); }, 250);
    }

    async function checkout() {
        if (checkoutBusy) return;
        checkoutBusy = true;
        clearTimeout(timer);
        timer = null;
        queued = null;
        revision++;
        const body = new FormData(form);
        const controls = [...form.elements].map(element => [element, element.disabled]);
        controls.forEach(([element]) => { element.disabled = true; });
        form.setAttribute('aria-busy', 'true');
        checkoutButton.textContent = '正在核验…';
        label.textContent = '正在实时核验上游价格与库存，请稍候…';
        try {
            const response = await fetch(form.action, { method: 'POST', body, credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' } });
            if (response.redirected || response.status === 401 || response.status === 419) {
                throw new Error('登录已失效，请刷新页面后重试。');
            }
            const result = await response.json();
            if (response.status === 409 && result.price_changed === true && result.quote) {
                showQuote(result.quote, true, result.message || '上游价格已更新。');
                return;
            }
            if (response.status === 201 && typeof result.invoice_url === 'string') {
                window.location.assign(result.invoice_url);
                return;
            }
            throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || '暂时无法创建账单，请稍后使用原请求重试。');
        } catch (error) {
            label.textContent = error instanceof Error ? error.message : '下单连接失败，请先核对账单，再使用原请求重试。';
        } finally {
            controls.forEach(([element, disabled]) => { element.disabled = disabled; });
            checkoutBusy = false;
            form.removeAttribute('aria-busy');
            checkoutButton.textContent = confirmationRequired ? '确认新价格并创建账单' : '核价并创建账单';
        }
    }

    for (const name of ['input', 'change']) form.addEventListener(name, event => {
        if (isQuoteField(event.target.name)) requestQuote();
    });
    form.addEventListener('submit', event => {
        if (event.submitter?.hasAttribute('data-quote-trigger')) {
            event.preventDefault();
            requestQuote(true);
        } else {
            event.preventDefault();
            checkout();
        }
    });
    updateLinks();
    if (!token.value) requestQuote(true);
})();
