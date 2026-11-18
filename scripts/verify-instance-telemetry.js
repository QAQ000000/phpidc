// Isolated browser scheduling regression; no network or application database.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function page() {
    const requests = [], timers = [];
    let active = 0, activeCharts = 0, maximum = 0, maximumCharts = 0;
    const blocks = ['power', 'cpu', 'disk', 'memory', 'flow'].map((name) => {
        const handlers = new Map(), attributes = new Map();
        const output = { text: '', html: '', classList: { add() {}, remove() {} },
            set textContent(value) { this.text = value; this.html = ''; }, get textContent() { return this.text; },
            set innerHTML(value) { this.html = value; this.text = ''; }, get innerHTML() { return this.html; } };
        const button = { disabled: false };
        const form = { action: name === 'power' ? '/power-status' : '/telemetry/chart/' + name,
            fields: { _token: 'CSRF', start: '1794950000000', end: '1795036400000' },
            addEventListener: (event, callback) => handlers.set(event, callback) };
        const element = { dataset: { telemetryRead: name === 'power' ? 'power' : 'chart' },
            setAttribute: (key, value) => attributes.set(key, value), removeAttribute: key => attributes.delete(key),
            querySelector: selector => selector === 'form' ? form : selector === 'button' ? button : output };
        return { name, element, form, button, output, attributes, submit: () => handlers.get('submit')({ preventDefault() {} }) };
    });
    const container = { dataset: {}, querySelectorAll: () => blocks.map(block => block.element) };
    class FormData { constructor(form) { this.fields = { ...form.fields }; } }
    const context = {
        document: { querySelectorAll: () => [container] }, FormData,
        window: { setTimeout: callback => { timers.push(callback); } },
        fetch: (url, options) => new Promise((resolve, reject) => {
            const chart = url.includes('/chart/');
            active++;
            if (chart) activeCharts++;
            maximum = Math.max(maximum, active);
            maximumCharts = Math.max(maximumCharts, activeCharts);
            const request = { url, options, settled: false };
            const settle = callback => value => {
                assert.equal(request.settled, false);
                request.settled = true;
                active--;
                if (chart) activeCharts--;
                callback(value);
            };
            request.resolve = settle(resolve);
            request.reject = settle(reject);
            requests.push(request);
        }),
    };
    const source = fs.readFileSync(__dirname + '/../public/js/instance-telemetry.js', 'utf8');
    vm.runInNewContext(source, context);
    return { blocks, requests, timers, source, context, limits: () => ({ maximum, maximumCharts }) };
}

const tick = () => new Promise(resolve => setImmediate(resolve));
const chart = name => ({ ok: true, status: 200, json: async () => ({ available: true, html: `<svg>${name}</svg>` }) });
const power = () => ({ ok: true, status: 200, json: async () => ({ available: true, state: 'on', checked_at: '2026-11-19T00:00:00Z' }) });

(async () => {
    const p = page();
    assert.equal(p.requests.length, 4);
    assert.equal(p.requests.filter(request => request.url.includes('/chart/')).length, 3);
    assert.equal(p.blocks[4].button.disabled, true); // Fourth graph is queued, with duplicate clicks disabled.
    p.blocks[4].submit();
    assert.equal(p.requests.length, 4);
    p.blocks[1].submit();
    assert.equal(p.requests.length, 4);
    vm.runInNewContext(p.source, p.context); // Loading the script twice must not start another batch.
    assert.equal(p.requests.length, 4);

    p.requests[1].resolve(chart('cpu'));
    await tick();
    assert.equal(p.blocks[1].output.innerHTML, '<svg>cpu</svg>');
    assert.equal(p.requests.length, 5);
    assert.match(p.requests[4].url, /flow$/);
    assert.equal(p.requests[2].settled, false); // The slow disk cannot hold a completed CPU result.
    p.requests[0].resolve(power());
    await tick();
    assert.match(p.blocks[0].output.textContent, /机器电源状态：开机/);

    // A refresh clears the old result immediately, but waits for a free chart slot.
    p.blocks[1].submit();
    p.blocks[1].submit();
    assert.equal(p.requests.length, 5);
    assert.equal(p.blocks[1].output.innerHTML, '');
    p.requests[4].resolve(chart('flow'));
    await tick();
    assert.equal(p.requests.length, 6);
    p.requests[5].resolve({ ok: false, status: 429, headers: { get: () => '2' } });
    await tick();
    assert.equal(p.blocks[1].output.innerHTML, '');
    assert.match(p.blocks[1].output.textContent, /2 秒后手动重试/);
    p.blocks[1].submit();
    assert.equal(p.requests.length, 6);
    assert.equal(p.timers.length, 1);
    p.timers[0]();
    await tick();
    assert.equal(p.requests.length, 6); // Retry-After only enables the manual button.

    p.requests[3].resolve({ ok: false, status: 502, json: async () => ({ available: false }) });
    await tick();
    assert.match(p.blocks[3].output.textContent, /上游暂未返回可用数据/);
    assert.equal(p.blocks[3].output.innerHTML, '');
    p.requests[2].resolve(chart('disk'));
    await tick();
    p.blocks[1].submit();
    assert.equal(p.requests.length, 7);
    p.requests[6].reject(new Error('offline'));
    await tick();
    assert.equal(p.blocks[1].output.innerHTML, '');
    assert.equal(p.blocks[1].button.disabled, false);
    assert.equal(p.blocks[1].attributes.has('aria-busy'), false);
    assert.deepEqual(p.limits(), { maximum: 4, maximumCharts: 3 });
    const windows = p.requests.filter(request => request.url.includes('/chart/')).map(request => request.options.body.fields);
    windows.forEach(value => assert.deepEqual(value, { _token: 'CSRF', start: '1794950000000', end: '1795036400000' }));
    assert.equal(p.requests.length, 7); // No polling or automatic retries after all completions.
    process.stdout.write('PASS: independent blocks, 3 chart/4 total concurrency, common window, slow graph isolation, duplicate suppression, failure clearing, manual Retry-After.\n');
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
