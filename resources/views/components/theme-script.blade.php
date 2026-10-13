{{-- Pasang tema sebelum halaman tampil agar tidak berkedip. Pilihan: light, dark, system (default). --}}
<script>
    (function () {
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        window.optimaTheme = {
            get() {
                try { return localStorage.getItem('theme') || 'system'; } catch (e) { return 'system'; }
            },
            set(theme) {
                try { localStorage.setItem('theme', theme); } catch (e) {}
                this.apply();
            },
            apply() {
                const theme = this.get();
                const dark = theme === 'dark' || (theme === 'system' && media.matches);
                document.documentElement.classList.toggle('dark', dark);
            },
        };
        window.optimaTheme.apply();
        media.addEventListener('change', () => window.optimaTheme.apply());
    })();
</script>
