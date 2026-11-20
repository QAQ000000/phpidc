(() => {
    function initialize() {
        document.querySelectorAll('[data-admin-search]').forEach((root) => {
            if (root.dataset.searchReady) return;
            root.dataset.searchReady = 'true';
            const dialog = root.querySelector('[data-search-dialog]');
            const input = root.querySelector('[data-search-input]');
            const results = root.querySelector('[data-search-results]');
            const status = root.querySelector('[data-search-status]');
            let timer;
            let request;
            let generation = 0;
            const cancel = () => {
                clearTimeout(timer);
                request?.abort();
                generation += 1;
                results.setAttribute('aria-busy', 'false');
            };
            const open = () => {
                if (!dialog.open) dialog.showModal();
                input.focus();
            };
            const render = (groups) => {
                results.replaceChildren();
                let count = 0;
                for (const group of groups) {
                    if (!group.items.length) continue;
                    const section = document.createElement('section');
                    section.className = 'admin-search-group';
                    const heading = document.createElement('h3');
                    heading.textContent = group.label;
                    section.append(heading);
                    const list = document.createElement('ul');
                    for (const item of group.items) {
                        const li = document.createElement('li');
                        const link = document.createElement('a');
                        link.className = 'admin-search-result';
                        link.href = item.url;
                        const label = document.createElement('strong');
                        label.textContent = item.label;
                        const description = document.createElement('small');
                        description.textContent = item.description;
                        link.append(label, description);
                        li.append(link);
                        list.append(li);
                        count += 1;
                    }
                    section.append(list);
                    results.append(section);
                }
                status.textContent = count ? `找到 ${count} 条结果，仅显示有权限的数据。` : '没有找到匹配记录，请输入完整编号或邮箱。';
            };
            const search = async () => {
                cancel();
                const term = input.value.trim();
                results.replaceChildren();
                if (!term) {
                    status.textContent = '仅搜索你有权限查看的数据。';
                    return;
                }
                const current = generation;
                request = new AbortController();
                results.setAttribute('aria-busy', 'true');
                status.textContent = '正在搜索…';
                try {
                    const response = await fetch(`/admin/search?${new URLSearchParams({ q: term })}`, {
                        credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: request.signal,
                    });
                    if (current !== generation || !dialog.open) return;
                    if (!response.ok) {
                        status.textContent = response.status === 422 ? '搜索词格式有误，最多输入 255 个字符。' : response.status === 401 ? '登录已过期，请刷新页面后重试。' : response.status === 403 ? '当前账户没有搜索权限。' : '搜索暂时不可用，请重试。';
                        return;
                    }
                    const data = await response.json();
                    if (current === generation && dialog.open) render(data.groups);
                } catch (error) {
                    if (error.name !== 'AbortError' && current === generation && dialog.open) status.textContent = '搜索连接失败，请重试。';
                } finally {
                    if (current === generation) results.setAttribute('aria-busy', 'false');
                }
            };
            root.querySelector('[data-search-open]').addEventListener('click', open);
            root.querySelector('[data-search-close]').addEventListener('click', () => dialog.close());
            dialog.addEventListener('close', cancel);
            dialog.addEventListener('click', (event) => { if (event.target === dialog) {
                const rect = dialog.getBoundingClientRect();
                if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
            } });
            input.addEventListener('input', () => {
                cancel();
                results.replaceChildren();
                status.textContent = input.value.trim() ? '准备搜索…' : '仅搜索你有权限查看的数据。';
                if (input.value.trim()) timer = setTimeout(search, 250);
            });
            root.querySelector('[data-search-form]').addEventListener('submit', (event) => { event.preventDefault(); search(); });
            dialog.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    dialog.close();
                    return;
                }
                if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
                const links = Array.from(results.querySelectorAll('a'));
                if (!links.length) return;
                event.preventDefault();
                const active = links.indexOf(document.activeElement);
                if (event.key === 'ArrowDown') links[Math.min(active + 1, links.length - 1)].focus();
                else if (active > 0) links[active - 1].focus();
                else input.focus();
            });
            document.addEventListener('keydown', (event) => {
                if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k' && root.isConnected) {
                    event.preventDefault();
                    open();
                }
            });
        });
    }
    document.addEventListener('DOMContentLoaded', initialize);
    document.addEventListener('livewire:navigated', initialize);
    initialize();
})();
