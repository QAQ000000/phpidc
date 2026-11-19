// Exercise the actual checkout script with isolated DOM, timers and HTTP responses.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function page({ quoteToken = '', confirmation = false, rules = [], selected = {}, hiddenChildren = false } = {}) {
    const handlers = new Map(), timers = new Map(), requests = [], redirects = [];
    let nextTimer = 0;
    const field = (name, value) => ({ name, value: String(value), disabled: false, required: false, dataset: {}, tagName: 'INPUT',
        message: '', reported: false, setCustomValidity(message) { this.message = message; },
        checkValidity() { return this.disabled || (this.message === '' && (!this.required || this.value !== '')); },
        reportValidity() { this.reported = true; },
    });
    const both = ['monthly', 'annually'];
    const choice = (id, parent, cycles = both) => ({ value: String(id), disabled: false, hidden: false,
        dataset: { linkageParent: String(parent), cycles: JSON.stringify(cycles) } });
    const defaults = { 50: '501', 60: '601', 80: '801', 90: '901', 100: '1001', ...selected };
    function linkage(id, parent, choices, metadata = choices) {
        const wrapper = { hidden: true };
        return Object.assign(field(`options[configoption][${id}]`, defaults[id] || ''), {
            tagName: 'SELECT', disabled: true, required: true, wrapper,
            dataset: { option: String(id), optionType: 'linkage', linkageParent: String(parent),
                linkageParents: JSON.stringify([...new Set(metadata.map(option => Number(option.dataset.linkageParent)))]) },
            options: [{ value: '', disabled: false, dataset: {} }, ...choices],
            get selectedOptions() { return this.options.filter(option => option.value === this.value); },
            closest(selector) { assert.equal(selector, '[data-linkage-group]'); return wrapper; },
            checkValidity() { return this.disabled || (this.message === '' && this.value !== '' && !this.selectedOptions[0]?.disabled); },
        });
    }
    const root = linkage(50, 0, [choice(501, 0), choice(502, 0), choice(503, 0)]);
    const first = linkage(60, 50, [choice(601, 501), choice(602, 501)]);
    const alternate = linkage(70, 50, [choice(701, 502, ['monthly'])]);
    const last = linkage(80, 60, [choice(801, 601), choice(802, 602, ['annually'])]);
    const independent = linkage(90, 0, [choice(901, 0), choice(902, 0)]);
    const independentChoices = [choice(1001, 901), choice(1002, 902)];
    const independentChild = linkage(100, 90, hiddenChildren ? [] : independentChoices, independentChoices);
    const ordinary = Object.assign(field('options[configoption][110]', '1101'), { tagName: 'SELECT',
        dataset: { option: '110', optionType: 'select' }, options: [choice(1101, 0), choice(1102, 0)],
        get selectedOptions() { return this.options.filter(option => option.value === this.value); },
        checkValidity() { return this.disabled || (this.message === '' && !this.selectedOptions[0]?.disabled); },
    });
    // Deliberately render descendants before parents, as upstream group order may do.
    const controls = [last, alternate, independentChild, first, independent, root, ordinary];
    const cycle = field('billing_cycle', 'monthly'), token = field('quote_token', quoteToken);
    const quoteButton = Object.assign(field('', ''), { disabled: true });
    const checkoutButton = Object.assign(field('', ''), { disabled: true,
        textContent: confirmation ? '确认新价格并创建账单' : '核价并创建账单' });
    const elements = [field('_token', 'CSRF'), cycle, ...controls, token, field('request_id', 'PURCHASE-KEY'),
        field('password', 'HOST-SECRET'), field('options[customfield][120]', 'PRIVATE-CUSTOM'), quoteButton, checkoutButton];
    elements.billing_cycle = cycle;
    elements.namedItem = name => elements.find(element => element.name === name);
    const attributes = new Map();
    const form = { action: '/checkout', elements,
        dataset: { quoteUrl: '/console/products/17/quote', confirmationRequired: confirmation ? '1' : '0' },
        querySelector: selector => selector === '[data-quote-trigger]' ? quoteButton : checkoutButton,
        querySelectorAll: () => controls,
        setAttribute: (name, value) => attributes.set(name, value), removeAttribute: name => attributes.delete(name),
        addEventListener: (name, callback) => handlers.set(name, callback) };
    const label = { textContent: '' };
    class FormData {
        constructor(source) { this.values = source ? source.elements.filter(el => el.name && !el.disabled).map(el => [el.name, el.value]) : []; }
        append(name, value) { this.values.push([name, value]); }
        [Symbol.iterator]() { return this.values[Symbol.iterator](); }
    }
    vm.runInNewContext(fs.readFileSync(__dirname + '/../public/js/classic-config.js', 'utf8'), {
        document: { getElementById: id => id === 'classic-configuration' ? form : id === 'classic-price' ? label : { textContent: JSON.stringify(rules) } },
        FormData, URLSearchParams, window: { location: { assign: url => redirects.push(url) } },
        setTimeout: callback => { const id = ++nextTimer; timers.set(id, callback); return id; }, clearTimeout: id => timers.delete(id),
        fetch: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })),
    });
    return { root, first, alternate, last, independent, independentChild, ordinary, cycle, token, form, label,
        elements, checkoutButton, quoteButton, requests, redirects,
        change: target => handlers.get('change')({ target }), input: target => handlers.get('input')({ target }),
        submit: (quote = false) => handlers.get('submit')({ preventDefault() {}, submitter: { hasAttribute: () => quote } }),
        timers: () => { for (const [id, callback] of [...timers]) { timers.delete(id); callback(); } },
    };
}

const tick = () => new Promise(resolve => setImmediate(resolve));
const quote = (price = '10.00', token = 'LOCAL-' + price) => ({ price, setup_fee: '0.00', currency: 'CNY', quote_token: token });
const ok = price => ({ ok: true, status: 200, json: async () => quote(price) });
const payload = request => Object.fromEntries(request.options.body);
const selections = request => Object.fromEntries([...request.options.body].filter(([name]) => name.startsWith('options[configoption]')));
const expectInactive = control => {
    assert.equal(control.disabled, true); assert.equal(control.required, false);
    assert.equal(control.wrapper.hidden, true); assert.equal(control.value, '');
};

(async () => {
    const p = page();
    assert.equal(p.requests.length, 1);
    assert.equal(p.root.disabled, false);
    assert.equal(p.first.wrapper.hidden, false);
    assert.equal(p.last.wrapper.hidden, false);
    assert.equal(p.quoteButton.disabled, false);
    assert.equal(p.checkoutButton.disabled, false);
    expectInactive(p.alternate);
    assert.deepEqual(selections(p.requests[0]), {
        'options[configoption][80]': '801', 'options[configoption][100]': '1001', 'options[configoption][60]': '601',
        'options[configoption][90]': '901', 'options[configoption][50]': '501', 'options[configoption][110]': '1101',
    });
    assert.equal(Object.hasOwn(payload(p.requests[0]), 'password'), false);
    assert.equal(Object.hasOwn(payload(p.requests[0]), 'options[customfield][120]'), false);

    p.root.value = '502';
    p.input(p.root); p.change(p.root); p.timers();
    expectInactive(p.first); expectInactive(p.last);
    assert.equal(p.alternate.value, '701');
    assert.equal(p.alternate.disabled, false);
    assert.equal(p.independentChild.value, '1001');
    assert.equal(p.requests.length, 1);
    p.requests[0].resolve(ok('1.00')); await tick();
    assert.equal(p.requests.length, 2);
    assert.equal(p.token.value, '');
    assert.doesNotMatch(p.label.textContent, /1\.00/);
    assert.equal(Object.hasOwn(selections(p.requests[1]), 'options[configoption][60]'), false);
    assert.equal(Object.hasOwn(selections(p.requests[1]), 'options[configoption][80]'), false);
    assert.equal(selections(p.requests[1])['options[configoption][70]'], '701');
    // Both DOM events for an unchanged chain reuse the one in-flight estimate.
    p.change(p.root); p.timers();
    assert.equal(p.requests.length, 2);
    p.requests[1].resolve(ok('2.00')); await tick();
    assert.equal(p.requests.length, 2);
    assert.equal(p.token.value, 'LOCAL-2.00');

    p.root.value = '501'; p.change(p.root);
    assert.equal(p.first.value, '601'); assert.equal(p.last.value, '801'); expectInactive(p.alternate);
    p.root.value = '503'; p.change(p.root); p.timers();
    expectInactive(p.first); expectInactive(p.last); expectInactive(p.alternate);
    assert.equal(p.requests.length, 3); // A legitimate root leaf needs no descendants.
    assert.equal(Object.keys(selections(p.requests[2])).length, 4);
    p.requests[2].resolve(ok('3.00')); await tick();

    const cycle = page({ quoteToken: 'SERVER' });
    assert.equal(cycle.requests.length, 0);
    cycle.root.value = '502'; cycle.change(cycle.root);
    cycle.cycle.value = 'annually'; cycle.change(cycle.cycle); cycle.timers();
    assert.equal(cycle.alternate.wrapper.hidden, false);
    assert.equal(cycle.alternate.disabled, false);
    assert.equal(cycle.alternate.required, true);
    assert.equal(cycle.alternate.value, '');
    assert.equal(cycle.alternate.checkValidity(), false);
    assert.equal(cycle.requests.length, 0);
    cycle.submit();
    assert.equal(cycle.alternate.reported, true);
    assert.equal(cycle.requests.length, 0);
    cycle.cycle.value = 'monthly'; cycle.change(cycle.cycle); cycle.timers();
    assert.equal(cycle.alternate.value, '701');
    assert.equal(cycle.alternate.checkValidity(), true);
    assert.equal(cycle.requests.length, 1);

    const hidden = page({ hiddenChildren: true });
    assert.equal(hidden.independentChild.wrapper.hidden, false);
    assert.equal(hidden.independentChild.disabled, false);
    assert.equal(hidden.independentChild.checkValidity(), false);
    assert.equal(hidden.requests.length, 0); // Metadata keeps a fully hidden required layer from becoming a free leaf.

    const zero = { qty_minimum: 0, qty_maximum: 0 };
    const inactiveRules = [
        { id: 1, type: 'condition', config_id: 70, relation: 'sneq', sub_id: { 701: zero } },
        { id: 2, type: 'result', relation_id: 1, config_id: 110, relation: 'seq', sub_id: { 1102: zero } },
        { id: 3, type: 'condition', config_id: 50, relation: 'seq', sub_id: { 501: zero } },
        { id: 4, type: 'result', relation_id: 3, config_id: 70, relation: 'seq', sub_id: { 999: zero } },
    ];
    const inactive = page({ rules: inactiveRules });
    assert.equal(inactive.ordinary.checkValidity(), true);
    assert.equal(inactive.requests.length, 1);
    const active = page({ rules: [
        { id: 1, type: 'condition', config_id: 50, relation: 'seq', sub_id: { 501: zero } },
        { id: 2, type: 'result', relation_id: 1, config_id: 60, relation: 'seq', sub_id: { 602: zero } },
    ] });
    assert.equal(active.first.value, '601'); // Active advanced constraints require explicit correction.
    assert.equal(active.first.checkValidity(), false);
    assert.equal(active.requests.length, 0);

    const checkout = page({ quoteToken: 'SERVER-LIVE', confirmation: true });
    assert.equal(checkout.requests.length, 0);
    assert.equal(checkout.token.value, 'SERVER-LIVE');
    assert.equal(checkout.form.dataset.confirmationRequired, '1');
    checkout.submit();
    assert.equal(checkout.requests.length, 1);
    assert.equal(checkout.requests[0].url, '/checkout');
    const body = payload(checkout.requests[0]);
    assert.equal(body.quote_token, 'SERVER-LIVE');
    assert.equal(body.password, 'HOST-SECRET');
    assert.equal(body['options[customfield][120]'], 'PRIVATE-CUSTOM');
    assert.equal(Object.hasOwn(body, 'options[configoption][70]'), false);
    assert.ok(checkout.elements.every(element => element.disabled));
    checkout.requests[0].resolve({ ok: false, status: 409,
        json: async () => ({ price_changed: true, quote: quote('11.00', 'LIVE-11') }) });
    await tick();
    assert.equal(checkout.requests.length, 1);
    assert.equal(checkout.token.value, 'LIVE-11');
    assert.equal(checkout.checkoutButton.textContent, '确认新价格并创建账单');
    assert.equal(checkout.root.disabled, false); expectInactive(checkout.alternate);
    checkout.submit();
    assert.equal(payload(checkout.requests[1]).quote_token, 'LIVE-11');
    checkout.requests[1].reject(new Error('offline')); await tick();
    assert.equal(checkout.root.disabled, false); expectInactive(checkout.alternate);
    assert.equal(checkout.elements.namedItem('request_id').value, 'PURCHASE-KEY');
    checkout.root.value = '502'; checkout.change(checkout.root);
    assert.equal(checkout.token.value, '');
    assert.equal(checkout.form.dataset.confirmationRequired, '0');

    const stale = page({ quoteToken: 'OLD-CONFIRMATION', confirmation: true, selected: { 50: '502' } });
    assert.equal(stale.token.value, '');
    assert.equal(stale.form.dataset.confirmationRequired, '0');
    expectInactive(stale.first); expectInactive(stale.last);
    assert.equal(stale.alternate.value, '701');
    assert.equal(stale.requests.length, 1);
    process.stdout.write('PASS: independent hierarchy roots, recursive branch clearing, leaf paths, cycle/hidden required layers, active-only payloads, advanced rules, quote dedupe and stale responses, explicit checkout confirmation and disabled-state restoration.\n');
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
