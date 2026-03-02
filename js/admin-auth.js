// Shared admin authentication mixin for Vue.js apps
window.AdminAuthMixin = {
    data() {
        return {
            isAuthenticated: false,
            adminUser: null,
            showLogin: false,
            showPasswordChange: false,
            loginError: '',
            passwordError: '',
            passwordSuccess: '',
            loginForm: { username: '', password: '' },
            passwordForm: { current: '', new_password: '', confirm: '' }
        };
    },
    methods: {
        async checkAuth() {
            try {
                const response = await fetch('api/auth.php?action=check');
                const data = await response.json();
                this.isAuthenticated = data.authenticated;
                this.adminUser = data.user;
                this.updateNavForAdmin();
            } catch (e) {
                console.error('Auth check failed:', e);
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
                    this.adminUser = data.user;
                    this.showLogin = false;
                    this.loginForm = { username: '', password: '' };
                    this.updateNavForAdmin();
                } else {
                    this.loginError = data.error || 'Inloggen mislukt';
                }
            } catch (e) {
                this.loginError = 'Er ging iets mis';
            }
        },
        async logout() {
            await fetch('api/auth.php?action=logout');
            this.isAuthenticated = false;
            this.adminUser = null;
            this.updateNavForAdmin();
        },
        async changePassword() {
            this.passwordError = '';
            this.passwordSuccess = '';
            if (this.passwordForm.new_password !== this.passwordForm.confirm) {
                this.passwordError = 'Wachtwoorden komen niet overeen';
                return;
            }
            try {
                const response = await fetch('api/auth.php?action=change_password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        current_password: this.passwordForm.current,
                        new_password: this.passwordForm.new_password
                    })
                });
                const data = await response.json();
                if (data.success) {
                    this.passwordSuccess = 'Wachtwoord gewijzigd!';
                    this.passwordForm = { current: '', new_password: '', confirm: '' };
                    setTimeout(() => {
                        this.showPasswordChange = false;
                        this.passwordSuccess = '';
                    }, 1500);
                } else {
                    this.passwordError = data.error || 'Wijzigen mislukt';
                }
            } catch (e) {
                this.passwordError = 'Er ging iets mis';
            }
        },
        updateNavForAdmin() {
            const navLogin = document.querySelector('.nav-login');
            if (!navLogin) return;

            if (this.isAuthenticated) {
                navLogin.querySelector('.login-trigger').textContent = 'Admin';
                const dropdown = navLogin.querySelector('.login-dropdown');
                dropdown.innerHTML = '';

                const pwLink = document.createElement('a');
                pwLink.href = '#';
                pwLink.textContent = 'Wachtwoord wijzigen';
                pwLink.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.showPasswordChange = true;
                });
                dropdown.appendChild(pwLink);

                const logoutLink = document.createElement('a');
                logoutLink.href = '#';
                logoutLink.textContent = 'Uitloggen';
                logoutLink.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.logout();
                });
                dropdown.appendChild(logoutLink);
            } else if (!sessionStorage.getItem('businessAccount')) {
                navLogin.querySelector('.login-trigger').textContent = 'Inloggen';
                const dropdown = navLogin.querySelector('.login-dropdown');
                if (dropdown) {
                    dropdown.innerHTML = '<a href="login-bedrijven.html">Bedrijven</a>' +
                        '<a href="#" class="disabled">Particulieren (binnenkort)</a>';
                }
            }
        }
    }
};
