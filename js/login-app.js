const { createApp } = Vue;

createApp({
    data() {
        return {
            email: '',
            password: '',
            error: '',
            loading: false
        };
    },
    methods: {
        async login() {
            this.error = '';
            this.loading = true;
            
            try {
                const response = await fetch('api/business-login.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        email: this.email,
                        password: this.password
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    if (data.is_admin) {
                        sessionStorage.setItem('adminLoggedIn', '1');
                        window.location.href = 'admin/index.php';
                        return;
                    }
                    sessionStorage.setItem('businessAccount', JSON.stringify(data.account));
                    window.location.href = 'zakelijk-dashboard.html';
                } else {
                    this.error = data.error || 'Inloggen mislukt';
                }
            } catch (err) {
                this.error = 'Er ging iets mis. Probeer het later opnieuw.';
            } finally {
                this.loading = false;
            }
        }
    }
}).mount('#login-app');
