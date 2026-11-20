(() => {
    const form = document.getElementById('classic-configuration');
    if (!form) return;
    const summary = form.querySelector('[data-store-selection-summary]');
    const cycleSummary = form.querySelector('[data-store-cycle]');
    if (!summary || !cycleSummary) return;
    const cycle = form.elements.namedItem('billing_cycle');
    const controls = [...form.querySelectorAll('[data-option][data-store-option-label]')];

    function update() {
        if (form.getAttribute('aria-busy') === 'true') return;
        cycleSummary.textContent = cycle.selectedOptions[0]?.textContent.trim() || '未选择';
        const entries = [];
        for (const control of controls) {
            if (control.disabled || control.closest('[hidden]')) continue;
            const option = control.tagName === 'SELECT' ? control.selectedOptions[0] : null;
            if (option && (option.disabled || option.hidden || option.value === '')) continue;
            const value = option ? option.textContent.trim() : control.value;
            if (value === '') continue;
            const term = document.createElement('dt');
            const detail = document.createElement('dd');
            term.textContent = control.dataset.storeOptionLabel;
            detail.textContent = value;
            entries.push(term, detail);
        }
        if (entries.length === 0) {
            const term = document.createElement('dt');
            const detail = document.createElement('dd');
            term.textContent = '配置选项';
            detail.textContent = controls.length === 0 ? '默认配置' : '请完成有效配置选择';
            entries.push(term, detail);
        }
        summary.replaceChildren(...entries);
    }

    let scheduled = false;
    function schedule() {
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(() => { scheduled = false; update(); });
    }
    for (const eventName of ['input', 'change']) {
        form.addEventListener(eventName, event => {
            if (event.target === cycle || event.target.hasAttribute('data-option')) schedule();
        });
    }
    new MutationObserver(schedule).observe(form, {
        attributes: true, subtree: true, attributeFilter: ['disabled', 'hidden'],
    });
    update();
})();
