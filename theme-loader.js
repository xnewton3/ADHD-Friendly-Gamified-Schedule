// Theme loader - loads saved theme from server/localStorage
const ThemeLoader = {
    currentTheme: 'default',

    async init() {
        // First check localStorage for faster load
        const savedTheme = localStorage.getItem('active_theme');
        if (savedTheme) {
            this.loadTheme(savedTheme);
        }

        // Then sync with server
        await this.syncWithServer();
    },

    async syncWithServer() {
        try {
            const res = await fetch('api.php?action=get_theme');
            if (res.ok) {
                const data = await res.json();
                if (data.activeTheme && data.activeTheme !== this.currentTheme) {
                    this.loadTheme(data.activeTheme);
                }
            }
        } catch(e) {
            console.warn('Could not sync theme with server:', e);
        }
    },

    loadTheme(themeId) {
        const link = document.getElementById('themeStylesheet');
        if (!link) return;
        let url = (themeId === 'custom') ? 'css/custom-theme.css' : `css/theme-${themeId}.css`;
        // Add cache‑busting query parameter
        url += '?_=' + Date.now();
        link.href = url;
        this.currentTheme = themeId;
        localStorage.setItem('active_theme', themeId);
        this.updateThemeColor();
    }

    async updateThemeColor() {
        // Read computed --jade value and set meta tag
        const jadeColor = getComputedStyle(document.documentElement).getPropertyValue('--jade').trim();
        if (jadeColor) {
            const metaTheme = document.querySelector('meta[name="theme-color"]');
            if (metaTheme) metaTheme.setAttribute('content', jadeColor);
        }
    },

    async saveTheme(themeId) {
        try {
            await fetch('api.php?action=set_theme', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ theme: themeId })
            });
            this.loadTheme(themeId);
        } catch(e) {
            console.warn('Could not save theme to server:', e);
            // Still save locally
            this.loadTheme(themeId);
        }
    },

    async saveCustomTheme(colors) {
        try {
            const res = await fetch('api.php?action=save_custom_theme', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ colors: colors })
            });
            if (res.ok) {
                // Reload the custom theme CSS
                const themeLink = document.getElementById('themeStylesheet');
                themeLink.href = 'css/custom-theme.css?' + Date.now(); // Cache bust
                localStorage.setItem('active_theme', 'custom');
            }
            return res.ok;
        } catch(e) {
            console.warn('Could not save custom theme:', e);
            return false;
        }
    }
};

// Auto-init when DOM is ready
document.addEventListener('DOMContentLoaded', () => ThemeLoader.init());