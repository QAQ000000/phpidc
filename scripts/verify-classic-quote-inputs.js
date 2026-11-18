// Isolated DOM/fetch regression for slow quotations and rapidly changing inputs.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function page() {
    const handlers = new Map(), timers = new Map(), requests = [];
    let nextTimer = 0;
    const field = (name, value) => ({
        name, value, tagName: 'INPUT', valid: true, reported: false,
        checkValidity() { return this.valid; }, reportValidity() { this.reported = true; }, setCustomValidity() {},
    });
    const cycle = field('billing_cycle', 'monthly');
    const option = field('options[configoption][50]', '501');
    const custom = field('options[customfield][80]', 'PRIVATE-CUSTOM');
    const elements = [field('_token', 'CSRF'), cycle, option, custom,
        field('product_id', '17'), field('request_id', 'PURCHASE-KEY'), field('password', 'HOST-SECRET'),
        field('hostname', 'my-host'), field('gateway', 'epay_wechat'), field('coupon_code', 'COUPON')];
    elements.billing_cycle = cycle;
    const form = { elements, dataset: { quoteUrl: '/console/products/17/quote' },
        querySelectorAll: () => [], addEventListener: (name, handler) => handlers.set(name, handler) };
    const label = { textContent: '' };
    class FormData {
        constructor(source) { this.values = source ? source.elements.map(el => [el.name, el.value]) : []; }
        append(name, value) { this.values.push([name, value]); }
        [Symbol.iterator]() { return this.values[Symbol.iterator](); }
    }
    vm.runInNewContext(fs.readFileSync(__dirname + '/../public/js/classic-config.js', 'utf8'), {
        document: { getElementById: id => id === 'classic-configuration' ? form : id === 'classic-price' ? label : { textContent: '[]' } },
        FormData, URLSearchParams,
        setTimeout: callback => { const id = ++nextTimer; timers.set(id, callback); return id; },
        clearTimeout: id => timers.delete(id),
        fetch: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })),
    });
    return { cycle, option, custom, label, requests,
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
const ok = price => ({ ok: true, json: async () => ({ price, setup_fee: '0.00', currency: 'CNY' }) });

(async () => {
    // A slow response cannot replace the selected configuration, or fan out a backlog.
    const p = page();
    p.change(p.option);
    p.timers();
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
    const body = Object.fromEntries(p.requests[1].options.body);
    assert.deepEqual(body, { _token: 'CSRF', billing_cycle: 'monthly',
        'options[configoption][50]': '503', 'options[customfield][80]': 'PRIVATE-CUSTOM' });
    p.requests[1].resolve(ok('3.00'));
    await tick();
    assert.match(p.label.textContent, /3\.00 CNY/);
    assert.equal(p.requests.length, 2);

    // Repeated manual clicks share the current request; a later explicit click rechecks live.
    const manual = page();
    assert.equal(manual.submit().prevented, true);
    manual.submit();
    manual.submit();
    assert.equal(manual.requests.length, 1);
    manual.requests[0].resolve(ok('4.00'));
    await tick();
    assert.match(manual.label.textContent, /4\.00 CNY/);
    assert.equal(manual.requests.length, 1);
    manual.submit();
    assert.equal(manual.requests.length, 2);
    manual.requests[1].resolve(ok('5.00'));
    await tick();
    assert.match(manual.label.textContent, /5\.00 CNY/);

    // Invalid required inputs clear old results without issuing a useless remote request.
    const invalid = page();
    invalid.submit();
    invalid.custom.valid = false;
    invalid.input(invalid.custom);
    invalid.requests[0].resolve(ok('9.00'));
    await tick();
    assert.equal(invalid.requests.length, 1);
    assert.doesNotMatch(invalid.label.textContent, /9\.00/);
    invalid.submit();
    assert.equal(invalid.custom.reported, true);
    invalid.custom.valid = true;
    invalid.change(invalid.custom);
    invalid.timers();
    assert.equal(invalid.requests.length, 2);
    invalid.requests[1].reject(new Error('offline'));
    await tick();
    assert.match(invalid.label.textContent, /报价连接失败/);

    // Checkout remains a normal submit and cancels a pending preview quote.
    const checkout = page();
    checkout.change(checkout.cycle);
    assert.equal(checkout.submit(false).prevented, false);
    checkout.timers();
    assert.equal(checkout.requests.length, 0);
    process.stdout.write('PASS: latest selection, bounded requests, manual refresh, minimal payload, validation, checkout.\n');
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
