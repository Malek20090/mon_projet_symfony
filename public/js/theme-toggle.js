(function () {
    var KEY = 'decides_theme_dark';

    function safeGetTheme() {
        try {
            return localStorage.getItem(KEY);
        } catch (e) {
            return null;
        }
    }

    function safeSetTheme(v) {
        try {
            localStorage.setItem(KEY, v);
        } catch (e) {}
    }

    function shouldUseDark(saved) {
        if (saved === '1' || saved === '0') {
            return saved === '1';
        }

        if (window.matchMedia) {
            return window.matchMedia('(prefers-color-scheme: dark)').matches;
        }

        return false;
    }

    function applyTheme(isDark) {
        document.body.classList.toggle('theme-dark', isDark);
    }

    function ensureToggleButton() {
        var headerBtn = document.getElementById('headerDarkModeBtn');
        if (headerBtn) {
            return headerBtn;
        }

        var existing = document.getElementById('globalThemeToggle');
        if (existing) {
            return existing;
        }

        var btn = document.createElement('button');
        btn.id = 'globalThemeToggle';
        btn.type = 'button';
        btn.className = 'theme-toggle-global';
        btn.setAttribute('aria-label', 'Toggle dark mode');
        btn.setAttribute('title', 'Mode sombre');
        btn.innerHTML = '<i class="fas fa-moon"></i>';
        document.body.appendChild(btn);

        return btn;
    }

    function updateButtonIcon(btn, isDark) {
        if (!btn) {
            return;
        }
        btn.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        btn.setAttribute('title', isDark ? 'Mode clair' : 'Mode sombre');
    }

    function init() {
        if (!document.body) {
            return;
        }

        var saved = safeGetTheme();
        var isDark = shouldUseDark(saved);
        applyTheme(isDark);

        var button = ensureToggleButton();
        updateButtonIcon(button, isDark);

        button.addEventListener('click', function () {
            var next = !document.body.classList.contains('theme-dark');
            applyTheme(next);
            safeSetTheme(next ? '1' : '0');
            updateButtonIcon(button, next);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
