(() => {
    const sidebar = document.getElementById('console-sidebar');
    const toggle = document.getElementById('console-menu-toggle');
    const backdrop = document.getElementById('console-nav-backdrop');
    const close = document.getElementById('console-nav-close');
    if (!sidebar || !toggle || !backdrop) return;

    const mobile = window.matchMedia('(max-width: 900px)');
    const focusable = () => [...sidebar.querySelectorAll('a[href], button, summary')]
        .filter(element => element.getClientRects().length > 0 && !element.disabled);
    let open = false;
    const setOpen = (value, restoreFocus = true) => {
        open = mobile.matches && value;
        document.body.classList.toggle('console-nav-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? '关闭导航' : '打开导航');
        sidebar.inert = mobile.matches && !open;
        if (open) {
            sidebar.setAttribute('role', 'dialog');
            sidebar.setAttribute('aria-modal', 'true');
        } else {
            sidebar.removeAttribute('role');
            sidebar.removeAttribute('aria-modal');
        }
        document.querySelector('.console-main').inert = open;
        document.querySelector('.console-topbar').inert = open;
        backdrop.hidden = !open;
        if (open) focusable()[0]?.focus();
        else if (restoreFocus && mobile.matches) toggle.focus();
    };

    document.documentElement.classList.add('console-enhanced');
    setOpen(false, false);
    toggle.addEventListener('click', () => setOpen(!open));
    backdrop.addEventListener('click', () => setOpen(false));
    close?.addEventListener('click', () => setOpen(false));
    sidebar.addEventListener('click', event => {
        if (event.target.closest('a[href]') && mobile.matches) setOpen(false);
    });
    document.addEventListener('keydown', event => {
        if (!open) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            setOpen(false);
        } else if (event.key === 'Tab') {
            const elements = focusable();
            const first = elements[0];
            const last = elements[elements.length - 1];
            if (event.shiftKey && (document.activeElement === first || !sidebar.contains(document.activeElement))) {
                event.preventDefault();
                last?.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !sidebar.contains(document.activeElement))) {
                event.preventDefault();
                first?.focus();
            }
        }
    });
    mobile.addEventListener('change', () => {
        const hiddenFocus = mobile.matches && sidebar.contains(document.activeElement);
        setOpen(false, hiddenFocus);
    });
})();
