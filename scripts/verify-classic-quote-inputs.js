// Isolated DOM/fetch regression for estimates, live checkout and explicit price confirmation.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function page({ quoteToken = '', confirmation = false } = {}) {
    const handlers = new Map(), timers = new Map(), requests = [], redirects = [];
    let nextTimer = 0;
    const field = (name, value) => ({
        name, value, tagName: 'INPUT', valid: true, reported: false, disabled: false,
        checkValidity() { return this.valid; }, reportValidity() { this.reported = true; }, setCustomValidity() {},
    });
    const cycle = field('billing_cycle', 'monthly');
    const option = field('options[configoption][50]', '501');
    const custom = field('options[customfield][80]', 'PRIVATE-CUSTOM');
    const token = field('quote_token', quoteToken);
    const checkoutButton = field('', '');
    checkoutButton.textContent = confirmation ? '确认新价格并创建账单' : '核价并创建账单';
    const elements = [field('_token', 'CSRF'), cycle, option, custom, token,
        field('product_id', '17'), field('request_id', 'PURCHASE-KEY'), field('password', 'HOST-SECRET'),
        field('hostname', 'my-host'), field('gateway', 'epay_alipay'), field('coupon_code', 'COUPON'),
        field('use_credit', '1'), checkoutButton];
    elements.billing_cycle = cycle;
    elements.namedItem = name => elements.find(element => element.name === name);
    const attributes = new Map();
    const form = { action: '/checkout', elements,
        dataset: { quoteUrl: '/console/products/17/quote', confirmationRequired: confirmation ? '1' : '0' },
        querySelector: () => checkoutButton, querySelectorAll: () => [],
        setAttribute: (name, value) => attributes.set(name, value), removeAttribute: name => attributes.delete(name),
        addEventListener: (name, handler) => handlers.set(name, handler) };
    const label = { textContent: confirmation ? '上游核验价格：8.00 CNY' : '' };
    class FormData {
        constructor(source) { this.values = source ? source.elements.filter(el => el.name && !el.disabled).map(el => [el.name, el.value]) : []; }
        append(name, value) { this.values.push([name, value]); }
        [Symbol.iterator]() { return this.values[Symbol.iterator](); }
    }
    vm.runInNewContext(fs.readFileSync(__dirname + '/../public/js/classic-config.js', 'utf8'), {
        document: { getElementById: id => id === 'classic-configuration' ? form : id === 'classic-price' ? label : { textContent: '[]' } },
        FormData, URLSearchParams, window: { location: { assign: url => redirects.push(url) } },
        setTimeout: callback => { const id = ++nextTimer; timers.set(id, callback); return id; },
        clearTimeout: id => timers.delete(id),
        fetch: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })),
    });
    return { cycle, option, custom, token, label, checkoutButton, elements, form, attributes, requests, redirects,
        change: target => handlers.get('change')({ target }), input: target => handlers.get('input')({ target }),
        submit: (quote = true) => {
            const event = { prevented: false, preventDefault() { this.prevented = true; },
                submitter: { hasAttribute: () => quote } };
            handlers.get('submit')(event);
            return event;
        },
        timers: () => { for (const [id, callback] of [...timers]) { timers.delete(id); callback(); } },
    };
}

const tick = () => new Promise(resolve => setImmediate(resolve));
const quote = (price, quoteToken = 'ESTIMATE-' + price) => ({ price, setup_fee: '0.00', currency: 'CNY', quote_token: quoteToken, estimate: true });
const ok = price => ({ ok: true, status: 200, json: async () => quote(price) });
const changed = (price, token = 'LIVE-' + price) => ({ ok: false, status: 409,
    json: async () => ({ price_changed: true, message: '价格已变化，请确认。', quote: quote(price, token) }) });
const payload = request => Object.fromEntries(request.options.body);

(async () => {
    // The first estimate is local; changing inputs only queues the newest pricing configuration.
    const p = page();
    assert.equal(p.requests.length, 1);
    p.option.value = '502';
    p.input(p.option);
    p.timers();
    p.option.value = '503';
    p.change(p.option);
    p.timers();
    assert.equal(p.requests.length, 1);
    p.requests[0].resolve(ok('1.00'));
    await tick();
    assert.equal(p.requests.length, 2);
    assert.doesNotMatch(p.label.textContent, /1\.00/);
    assert.equal(p.token.value, '');
    assert.deepEqual(payload(p.requests[1]), { _token: 'CSRF', billing_cycle: 'monthly', 'options[configoption][50]': '503' });
    p.requests[1].resolve(ok('3.00'));
    await tick();
    assert.match(p.label.textContent, /3\.00 CNY/);
    assert.match(p.label.textContent, /本地预估，最终以提交核验为准/);
    assert.equal(p.token.value, 'ESTIMATE-3.00');

    // Manual estimate clicks share an in-flight selection; an explicit later refresh re-estimates.
    const manual = page();
    assert.equal(manual.submit().prevented, true);
    manual.submit();
    assert.equal(manual.requests.length, 1);
    manual.requests[0].resolve(ok('4.00'));
    await tick();
    manual.submit();
    assert.equal(manual.requests.length, 2);
    assert.equal(manual.token.value, '');
    manual.requests[1].resolve(ok('5.00'));
    await tick();
    assert.equal(manual.token.value, 'ESTIMATE-5.00');

    // Required personal/custom fields do not enter the estimate or block local price calculation.
    const invalid = page();
    invalid.custom.valid = false;
    invalid.custom.value = 'PRIVATE-CUSTOM-PASSWORD';
    invalid.input(invalid.custom);
    invalid.timers();
    assert.equal(invalid.requests.length, 1);
    invalid.requests[0].resolve(ok('6.00'));
    await tick();
    assert.equal(invalid.token.value, 'ESTIMATE-6.00');
    assert.deepEqual(payload(invalid.requests[0]), { _token: 'CSRF', billing_cycle: 'monthly', 'options[configoption][50]': '501' });
    invalid.option.valid = false;
    invalid.input(invalid.option);
    invalid.submit();
    assert.equal(invalid.requests.length, 1);
    assert.equal(invalid.option.reported, true);
    assert.equal(invalid.token.value, '');
    invalid.option.valid = true;
    invalid.change(invalid.option);
    invalid.timers();
    assert.equal(invalid.requests.length, 2);
    invalid.requests[1].reject(new Error('offline'));
    await tick();
    assert.match(invalid.label.textContent, /预估连接失败/);

    // Checkout posts the complete original intent once, then 409 only arms a separate explicit click.
    const checkout = page();
    checkout.requests[0].resolve(ok('10.00'));
    await tick();
    assert.equal(checkout.submit(false).prevented, true);
    assert.equal(checkout.requests.length, 2);
    assert.equal(checkout.requests[1].url, '/checkout');
    const firstCheckout = payload(checkout.requests[1]);
    assert.equal(firstCheckout.quote_token, 'ESTIMATE-10.00');
    assert.equal(firstCheckout.request_id, 'PURCHASE-KEY');
    assert.equal(firstCheckout.password, 'HOST-SECRET');
    assert.equal(firstCheckout['options[customfield][80]'], 'PRIVATE-CUSTOM');
    assert.equal(firstCheckout.use_credit, '1');
    assert.equal(firstCheckout.gateway, 'epay_alipay');
    assert.equal(firstCheckout.coupon_code, 'COUPON');
    assert.ok(checkout.elements.every(element => element.disabled));
    checkout.submit(false);
    checkout.submit();
    assert.equal(checkout.requests.length, 2);
    checkout.requests[1].resolve(changed('11.00'));
    await tick();
    assert.equal(checkout.requests.length, 2); // No invoice creation automatically follows the changed price.
    assert.equal(checkout.token.value, 'LIVE-11.00');
    assert.equal(checkout.checkoutButton.textContent, '确认新价格并创建账单');
    assert.match(checkout.label.textContent, /上游核验价格：11\.00/);
    assert.equal(checkout.form.dataset.confirmationRequired, '1');
    assert.ok(checkout.elements.every(element => !element.disabled));
    checkout.submit(false);
    assert.equal(checkout.requests.length, 3);
    assert.equal(payload(checkout.requests[2]).quote_token, 'LIVE-11.00');
    assert.equal(payload(checkout.requests[2]).request_id, 'PURCHASE-KEY');
    checkout.requests[2].resolve({ ok: true, status: 201, json: async () => ({ invoice_url: '/console/invoices/22' }) });
    await tick();
    assert.deepEqual(checkout.redirects, ['/console/invoices/22']);

    // Input changes discard both the authoritative confirmation and its token immediately.
    const reset = page({ quoteToken: 'SERVER-LIVE', confirmation: true });
    assert.equal(reset.requests.length, 0); // Initial JS cannot overwrite an HTML price confirmation.
    assert.equal(reset.checkoutButton.textContent, '确认新价格并创建账单');
    reset.option.value = '502';
    reset.input(reset.option);
    assert.equal(reset.token.value, '');
    assert.equal(reset.form.dataset.confirmationRequired, '0');
    assert.equal(reset.checkoutButton.textContent, '核价并创建账单');
    reset.timers();
    reset.requests[0].resolve(ok('12.00'));
    await tick();
    assert.equal(reset.token.value, 'ESTIMATE-12.00');
    reset.custom.value = 'CHANGED-PRIVATE-CUSTOM';
    reset.change(reset.custom);
    assert.equal(reset.token.value, '');
    reset.timers();
    assert.equal(reset.requests.length, 2);
    assert.equal(Object.hasOwn(payload(reset.requests[1]), 'options[customfield][80]'), false);
    reset.requests[1].resolve(ok('12.00'));
    await tick();

    // An old estimate arriving during live checkout cannot replace its state or arm confirmation.
    const racing = page();
    racing.submit(false);
    assert.equal(racing.requests.length, 2);
    racing.requests[0].resolve(ok('99.00'));
    await tick();
    assert.equal(racing.token.value, '');
    assert.doesNotMatch(racing.label.textContent, /99\.00/);
    racing.requests[1].resolve(changed('13.00'));
    await tick();
    assert.equal(racing.token.value, 'LIVE-13.00');
    assert.equal(racing.requests.length, 2);

    // A lost checkout response preserves the purchase request key and requires a manual retry.
    const uncertain = page({ quoteToken: 'SERVER-LIVE', confirmation: true });
    uncertain.submit(false);
    uncertain.requests[0].reject(new Error('connection lost'));
    await tick();
    assert.equal(uncertain.requests.length, 1);
    assert.equal(uncertain.checkoutButton.disabled, false);
    assert.equal(uncertain.elements.namedItem('request_id').value, 'PURCHASE-KEY');
    uncertain.submit(false);
    assert.equal(uncertain.requests.length, 2);
    assert.deepEqual(payload(uncertain.requests[1]), payload(uncertain.requests[0]));
    uncertain.requests[1].resolve({ ok: false, status: 422, json: async () => ({ errors: { password: ['请重新填写有效密码。'] } }) });
    await tick();
    assert.match(uncertain.label.textContent, /请重新填写有效密码/);
    assert.equal(uncertain.requests.length, 2);
    process.stdout.write('PASS: local estimates, latest selection, minimal pricing payload, token invalidation, one preview in flight, explicit changed-price confirmation, full checkout, stable retry identity.\n');
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
