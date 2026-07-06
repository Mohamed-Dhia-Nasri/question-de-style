import './bootstrap';

// Alpine.js is bundled with Livewire — never import it here.
// Stores are registered on `alpine:init`, which Livewire's bundled Alpine
// dispatches before it evaluates any component on the page.
document.addEventListener('alpine:init', () => {
    Alpine.store('theme', {
        theme: 'light',

        init() {
            const savedTheme = localStorage.getItem('theme');
            const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches
                ? 'dark'
                : 'light';
            this.theme = savedTheme || systemTheme;
            this.updateTheme();
        },

        toggle() {
            this.theme = this.theme === 'light' ? 'dark' : 'light';
            localStorage.setItem('theme', this.theme);
            this.updateTheme();
        },

        updateTheme() {
            const html = document.documentElement;
            const body = document.body;
            if (this.theme === 'dark') {
                html.classList.add('dark');
                body.classList.add('dark', 'bg-gray-900');
            } else {
                html.classList.remove('dark');
                body.classList.remove('dark', 'bg-gray-900');
            }
        },
    });

    Alpine.store('sidebar', {
        // Initialize based on screen size: expanded on desktop, closed on mobile.
        isExpanded: window.innerWidth >= 1280,
        isMobileOpen: false,
        isHovered: false,

        toggleExpanded() {
            this.isExpanded = !this.isExpanded;
            // When toggling the desktop sidebar, ensure the mobile menu is closed.
            this.isMobileOpen = false;
        },

        toggleMobileOpen() {
            this.isMobileOpen = !this.isMobileOpen;
        },

        setMobileOpen(val) {
            this.isMobileOpen = val;
        },

        setHovered(val) {
            // Only allow hover effects on desktop when the sidebar is collapsed.
            if (window.innerWidth >= 1280 && !this.isExpanded) {
                this.isHovered = val;
            }
        },
    });
});
