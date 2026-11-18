document.querySelectorAll('[data-machine-controls-read]').forEach((form) => {
    const button = form.querySelector('button[type="submit"]');
    const status = form.querySelector('[role="status"]');
    form.addEventListener('submit', () => {
        button.disabled = true;
        form.setAttribute('aria-busy', 'true');
        status.hidden = false;
    });
    window.addEventListener('pageshow', () => {
        button.disabled = false;
        form.removeAttribute('aria-busy');
        status.hidden = true;
    });
});
document.querySelectorAll('[data-os-selector]').forEach((container) => {
    const group = container.querySelector('[name="os_group"]');
    const os = container.querySelector('[name="os"]');
    const port = container.querySelector('[name="port"]');
    group.addEventListener('change', () => {
        os.value = '';
        os.querySelectorAll('option[data-group]').forEach((option) => {
            option.hidden = option.dataset.group !== group.value;
            option.disabled = option.hidden;
        });
        if (port) port.value = /windows/i.test(group.value) ? '3389' : '22';
    });
});
document.querySelectorAll('form[action$="/machine/command"]').forEach((form) => {
    form.addEventListener('submit', () => {
        form.querySelector('button[type="submit"]').disabled = true;
    });
});
