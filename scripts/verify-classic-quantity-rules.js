// Isolated form regression: execute the real configuration script without HTTP.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function page({ value = 1, minimum = 1, maximum = 13, stage = 5, step = 1, choice = 601, type = 'select', rules = [] } = {}) {
    const handlers = new Map(), timers = new Map(), requests = [];
    const field = (name, value) => ({ name, value: String(value), tagName: 'INPUT', disabled: false, dataset: {},
        message: '', setCustomValidity(message) { this.message = message; },
        checkValidity() { return this.message === ''; }, reportValidity() {},
    });
    const quantity = Object.assign(field('options[configoption][50]', value), {
        type: 'number', min: String(minimum), max: String(maximum), step: String(step),
        dataset: { option: '50', optionType: 'quantity', ...(stage === null ? {} : { qtyStage: String(stage) }) },
        checkValidity() {
            const value = Number(this.value);
            return this.message === '' && Number.isInteger(value) && value >= Number(this.min) && value <= Number(this.max)
                && (value - Number(this.min)) % Number(this.step) === 0;
        },
    });
    const select = Object.assign(field('options[configoption][60]', choice), { tagName: 'SELECT',
        dataset: { option: '60', optionType: type },
        options: (type === 'toggle' ? [0, 601] : [601, 602]).map(value => ({ value: String(value), disabled: false, dataset: {} })),
        get selectedOptions() { return this.options.filter(option => option.value === this.value); },
    });
    const cycle = field('billing_cycle', 'monthly'), token = field('quote_token', 'INITIAL-QUOTE'), button = field('', '');
    const elements = [field('_token', 'CSRF'), cycle, quantity, select, token, button];
    elements.billing_cycle = cycle;
    elements.namedItem = name => elements.find(element => element.name === name);
    const form = { elements, dataset: {}, querySelector: () => button, querySelectorAll: () => [quantity, select],
        addEventListener: (name, callback) => handlers.set(name, callback) };
    const label = { textContent: '' };
    class FormData {
        constructor(form) { this.values = form ? form.elements.filter(el => el.name && !el.disabled).map(el => [el.name, el.value]) : []; }
        append(name, value) { this.values.push([name, value]); }
        [Symbol.iterator]() { return this.values[Symbol.iterator](); }
    }
    vm.runInNewContext(fs.readFileSync(__dirname + '/../public/js/classic-config.js', 'utf8'), {
        document: { getElementById: id => id === 'classic-configuration' ? form : id === 'classic-price' ? label : { textContent: JSON.stringify(rules) } },
        FormData, URLSearchParams, setTimeout: callback => { timers.set(1, callback); return 1; }, clearTimeout: id => timers.delete(id),
        fetch: (url, options) => { requests.push(options.body); return new Promise(() => {}); },
    });
    return { quantity, select, label, requests, update() {
        handlers.get('input')({ target: quantity });
        for (const [id, callback] of [...timers]) { timers.delete(id); callback(); }
    } };
}

for (const value of [1, 5, 10, 13]) {
    const p = page({ value });
    assert.equal(p.quantity.checkValidity(), true, `classic allowed quantity ${value}`);
    p.update();
    assert.equal(p.requests.length, 1);
}
for (const value of [0, 2, 6, 11, 14]) {
    const p = page({ value });
    assert.equal(p.quantity.checkValidity(), false, `classic rejected quantity ${value}`);
    p.update();
    assert.equal(p.requests.length, 0);
    assert.match(p.label.textContent, /有效配置/);
}
for (const stage of [0, 1]) assert.equal(page({ value: 7, stage }).quantity.checkValidity(), true);
for (const value of [1, 6, 11]) assert.equal(page({ value, stage: null, step: 5 }).quantity.checkValidity(), true);
for (const value of [5, 10, 13]) assert.equal(page({ value, stage: null, step: 5 }).quantity.checkValidity(), false);

const zero = { qty_minimum: 0, qty_maximum: 0 };
for (const relation of ['seq', 'sneq']) {
    const rules = [
        { id: 1, type: 'condition', config_id: 50, relation, sub_id: { 501: zero } },
        { id: 2, type: 'result', relation_id: 1, config_id: 60, relation: 'seq', sub_id: { 602: zero } },
    ];
    const matching = relation === 'seq' ? 0 : 1, nonmatching = relation === 'seq' ? 1 : 0;
    const invalid = page({ value: matching, minimum: 0, stage: 1, rules });
    assert.equal(invalid.select.checkValidity(), false);
    assert.equal(invalid.select.options.find(option => option.value === '601').disabled, true);
    assert.equal(invalid.select.options.find(option => option.value === '602').disabled, false);
    invalid.update();
    assert.equal(invalid.requests.length, 0);
    assert.equal(page({ value: matching, minimum: 0, stage: 1, choice: 602, rules }).select.checkValidity(), true);
    assert.equal(page({ value: nonmatching, minimum: 0, stage: 1, rules }).select.checkValidity(), true);
}
for (const type of ['select', 'toggle']) {
    for (const relation of ['seq', 'sneq']) {
        const rules = [
            { id: 1, type: 'condition', config_id: 60, relation: 'seq', sub_id: { 601: zero } },
            { id: 2, type: 'result', relation_id: 1, config_id: 50, relation, sub_id: { 501: zero } },
        ];
        const matching = relation === 'seq' ? 0 : 1, nonmatching = relation === 'seq' ? 1 : 0;
        assert.equal(page({ value: matching, minimum: 0, stage: 1, type, rules }).quantity.checkValidity(), true);
        const invalid = page({ value: nonmatching, minimum: 0, stage: 1, type, rules });
        assert.equal(invalid.quantity.checkValidity(), false);
        invalid.update();
        assert.equal(invalid.requests.length, 0);
        assert.equal(page({ value: nonmatching, minimum: 0, stage: 1, type, choice: type === 'toggle' ? 0 : 602, rules }).quantity.checkValidity(), true);
    }
}
process.stdout.write('PASS: classic absolute quantity stages and endpoints, zero/one stages, legacy offset steps, zero quantity conditions/results, seq/sneq, select/toggle IDs, and blocked invalid estimates.\n');
