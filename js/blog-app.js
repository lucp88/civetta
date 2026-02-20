const { createApp } = Vue;

createApp({
    data() {
        return {
            posts: [],
            loading: true,
            isAuthenticated: false,
            user: null,
            showLogin: false,
            showEditor: false,
            editingPost: null,
            loginError: '',
            loginForm: {
                username: '',
                password: ''
            },
            postForm: {
                title: '',
                content: '',
                post_date: new Date().toISOString().split('T')[0]
            }
        };
    },
    
    mounted() {
        this.checkAuth();
        this.loadPosts();
    },
    
    methods: {
        formatDate(dateString) {
            const months = [
                'januari', 'februari', 'maart', 'april', 'mei', 'juni',
                'juli', 'augustus', 'september', 'oktober', 'november', 'december'
            ];
            const date = new Date(dateString);
            return `${date.getDate()} ${months[date.getMonth()]} ${date.getFullYear()}`;
        },
        
        formatContent(content) {
            return content
                .split('\n\n')
                .filter(p => p.trim())
                .map(p => `<p>${this.escapeHtml(p).replace(/\n/g, '<br>')}</p>`)
                .join('');
        },
        
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },
        
        async checkAuth() {
            try {
                const response = await fetch('api/auth.php?action=check');
                const data = await response.json();
                this.isAuthenticated = data.authenticated;
                this.user = data.user;
            } catch (error) {
                console.error('Auth check failed:', error);
            }
        },
        
        async loadPosts() {
            try {
                const response = await fetch('api/posts.php');
                const data = await response.json();
                if (data.success) {
                    this.posts = data.posts;
                }
            } catch (error) {
                console.error('Error loading posts:', error);
            } finally {
                this.loading = false;
            }
        },
        
        async login() {
            this.loginError = '';
            try {
                const response = await fetch('api/auth.php?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.loginForm)
                });
                const data = await response.json();
                if (data.success) {
                    this.isAuthenticated = true;
                    this.user = data.user;
                    this.showLogin = false;
                    this.loginForm = { username: '', password: '' };
                } else {
                    this.loginError = data.error;
                }
            } catch (error) {
                this.loginError = 'Er ging iets mis';
            }
        },
        
        async logout() {
            try {
                await fetch('api/auth.php?action=logout');
                this.isAuthenticated = false;
                this.user = null;
            } catch (error) {
                console.error('Logout failed:', error);
            }
        },
        
        editPost(post) {
            this.editingPost = post;
            this.postForm = {
                title: post.title,
                content: post.content,
                post_date: post.post_date
            };
            this.showEditor = true;
        },
        
        async savePost() {
            try {
                const method = this.editingPost ? 'PUT' : 'POST';
                const body = this.editingPost 
                    ? { ...this.postForm, id: this.editingPost.id }
                    : this.postForm;
                    
                const response = await fetch('api/posts.php', {
                    method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });
                const data = await response.json();
                if (data.success) {
                    this.showEditor = false;
                    this.editingPost = null;
                    this.postForm = {
                        title: '',
                        content: '',
                        post_date: new Date().toISOString().split('T')[0]
                    };
                    await this.loadPosts();
                }
            } catch (error) {
                console.error('Error saving post:', error);
            }
        },
        
        async deletePost(post) {
            if (!confirm(`Weet je zeker dat je "${post.title}" wilt verwijderen?`)) return;
            try {
                const response = await fetch('api/posts.php', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: post.id })
                });
                const data = await response.json();
                if (data.success) {
                    await this.loadPosts();
                }
            } catch (error) {
                console.error('Error deleting post:', error);
            }
        }
    }
}).mount('#blog-app');

