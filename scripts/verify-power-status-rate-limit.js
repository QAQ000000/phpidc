// Isolated DOM/fetch check: 429 must honor Retry-After without JSON parsing or automatic replay.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

(async () => {
    for (const retryAfter of ['5', new Date(Date.now() + 60000).toUTCString()]) {
        let submit, timer, delay, fetches = 0;
        const button = { disabled: false, textContent: '' };
        const output = { classList: { remove() {}, add() {} }, textContent: '' };
        const form = { action: '/power-status', querySelector: () => button, addEventListener: (name, handler) => { submit = handler; } };
        const container = { querySelector: (selector) => selector === 'form' ? form : output, setAttribute() {}, removeAttribute() {} };
        vm.runInNewContext(fs.readFileSync(__dirname + '/../public/js/power-status.js', 'utf8'), {
            document: { querySelectorAll: () => [container] }, FormData: class {},
            window: { setTimeout: (handler, ms) => { timer = handler; delay = ms; } }, Date, Error,
            fetch: async () => { fetches++; return { status: 429, headers: { get: () => retryAfter }, json: () => { throw new Error('429 must not require JSON'); } }; },
        });
        await submit({ preventDefault() {} });
        assert.equal(fetches, 1);
        assert.equal(button.disabled, true);
        assert.ok(delay > 0 && delay <= 60000);
        assert.match(output.textContent, /秒后手动重试/);
        await submit({ preventDefault() {} });
        assert.equal(fetches, 1);
        timer();
        assert.equal(button.disabled, false);
        assert.equal(fetches, 1);
    }
    process.stdout.write('PASS: Retry-After seconds/date, disabled button, no JSON parse, no automatic replay.\n');
})().catch((error) => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
