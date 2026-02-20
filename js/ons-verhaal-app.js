const { createApp } = Vue;

createApp({
    mixins: [window.AdminAuthMixin],
    data() {
        return {
            content: '',
            loading: true,
            showEditor: false,
            editContent: ''
        };
    },

    mounted() {
        this.checkAuth();
        this.loadContent();
    },

    methods: {
        async loadContent() {
            try {
                const response = await fetch('api/pages.php?slug=ons_verhaal');
                const data = await response.json();
                if (data.success && data.content) {
                    this.content = data.content;
                }
            } catch (e) {
                console.error('Error loading page:', e);
            } finally {
                this.loading = false;
            }
        },

        formatContent(text) {
            return text
                .split('\n\n')
                .filter(p => p.trim())
                .map(p => {
                    if (p.trim().startsWith('### ')) {
                        return '<h3>' + this.escapeHtml(p.trim().substring(4)) + '</h3>';
                    }
                    return '<p>' + this.escapeHtml(p).replace(/\n/g, '<br>') + '</p>';
                })
                .join('');
        },

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        openEditor() {
            this.editContent = this.content;
            this.showEditor = true;
        },

        async saveContent() {
            try {
                const response = await fetch('api/pages.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ slug: 'ons_verhaal', content: this.editContent })
                });
                const data = await response.json();
                if (data.success) {
                    this.content = this.editContent;
                    this.showEditor = false;
                }
            } catch (e) {
                console.error('Error saving:', e);
            }
        }
    }
}).mount('#verhaal-app');
