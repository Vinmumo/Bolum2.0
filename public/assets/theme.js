(() => {
    'use strict';
    const key = 'bolum.theme';
    const root = document.documentElement;
    function apply(theme) {
        root.dataset.theme = theme === 'light' ? 'light' : 'dark';
        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', root.dataset.theme === 'dark' ? '#0d1512' : '#f5f7f4');
        const button = document.getElementById('theme-toggle');
        if (button) {
            const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
            button.setAttribute('aria-label', `Switch to ${next} theme`);
            button.title = `Switch to ${next} theme`;
            button.innerHTML = `<span aria-hidden="true">${next === 'light' ? '☀' : '☾'}</span><span>${next === 'light' ? 'Light' : 'Dark'}</span>`;
        }
    }
    let saved;
    try { saved = localStorage.getItem(key); } catch { /* Theme still works when storage is unavailable. */ }
    apply(saved);
    document.addEventListener('DOMContentLoaded', () => {
        apply(root.dataset.theme);
        document.getElementById('theme-toggle').addEventListener('click', () => {
            apply(root.dataset.theme === 'dark' ? 'light' : 'dark');
            try { localStorage.setItem(key, root.dataset.theme); } catch { /* Keep this tab's selection. */ }
        });
    });
    window.addEventListener('storage', event => { if (event.key === key) apply(event.newValue); });
})();
