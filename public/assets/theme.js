(() => {
    'use strict';
    const key = 'bolum.theme';
    const root = document.documentElement;
    function apply(theme) {
        root.dataset.theme = theme === 'light' ? 'light' : 'dark';
        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', root.dataset.theme === 'dark' ? '#090c10' : '#f5f7f4');
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
    const hoverSidebar = window.matchMedia('(min-width: 721px) and (hover: hover) and (pointer: fine)');
    root.dataset.sidebar = hoverSidebar.matches ? 'collapsed' : 'expanded';
    apply(saved);
    document.addEventListener('DOMContentLoaded', () => {
        apply(root.dataset.theme);
        const sidebar = document.querySelector('.sidebar');
        let hovered = false;
        let keyboardNavigation = false;
        function syncSidebar() {
            const keyboardFocus = keyboardNavigation && sidebar.contains(document.activeElement);
            root.dataset.sidebar = !hoverSidebar.matches || hovered || keyboardFocus ? 'expanded' : 'collapsed';
        }
        syncSidebar();
        sidebar.addEventListener('pointerenter', event => {
            if (event.pointerType === 'touch') return;
            hovered = true;
            syncSidebar();
        });
        sidebar.addEventListener('pointerleave', () => {
            hovered = false;
            syncSidebar();
        });
        document.addEventListener('keydown', event => {
            if (event.key !== 'Tab') return;
            keyboardNavigation = true;
            syncSidebar();
        });
        document.addEventListener('pointerdown', () => {
            keyboardNavigation = false;
            syncSidebar();
        });
        sidebar.addEventListener('focusin', syncSidebar);
        sidebar.addEventListener('focusout', () => queueMicrotask(syncSidebar));
        hoverSidebar.addEventListener('change', () => {
            hovered = hoverSidebar.matches && sidebar.matches(':hover');
            syncSidebar();
        });
        document.getElementById('theme-toggle').addEventListener('click', () => {
            apply(root.dataset.theme === 'dark' ? 'light' : 'dark');
            try { localStorage.setItem(key, root.dataset.theme); } catch { /* Keep this tab's selection. */ }
        });
    });
    window.addEventListener('storage', event => { if (event.key === key) apply(event.newValue); });
})();
