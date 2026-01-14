const { createApp } = Vue;

createApp({
    data() {
        return {
            loading: true,
            account: {},
            showEditModal: false,
            showPasswordModal: false,
            editForm: {},
            editError: '',
            editSuccess: '',
            saving: false,
            passwordForm: {
                current: '',
                new: '',
                confirm: ''
            },
            passwordError: '',
            passwordSuccess: '',
            savingPassword: false,
            orders: [],
            ordersLoading: true,
            detailsExpanded: false,
            openOrders: []
        };
    },
    
    async mounted() {
        const stored = sessionStorage.getItem('businessAccount');
        if (!stored) {
            window.location.href = 'login-bedrijven.html';
            return;
        }
        
        await this.loadProfile();
        await this.loadOrders();
    },
    
    methods: {
        async loadProfile() {
            try {
                const response = await fetch('api/business-dashboard.php?action=profile');
                const data = await response.json();
                
                if (data.success) {
                    this.account = data.account;
                    this.loading = false;
                } else {
                    sessionStorage.removeItem('businessAccount');
                    window.location.href = 'login-bedrijven.html';
                }
            } catch (error) {
                sessionStorage.removeItem('businessAccount');
                window.location.href = 'login-bedrijven.html';
            }
        },
        
        openEditModal() {
            this.editForm = {
                bedrijfsnaam: this.account.bedrijfsnaam,
                adres: this.account.adres,
                postcode: this.account.postcode,
                plaats: this.account.plaats,
                contactpersoon: this.account.contactpersoon,
                telefoon: this.account.telefoon,
                website: this.account.website,
                kvk_nummer: this.account.kvk_nummer,
                btw_id: this.account.btw_id
            };
            this.editError = '';
            this.editSuccess = '';
            this.showEditModal = true;
        },
        
        closeEditModal() {
            this.showEditModal = false;
        },
        
        async saveProfile() {
            this.editError = '';
            this.editSuccess = '';
            this.saving = true;
            
            try {
                const response = await fetch('api/business-dashboard.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update_profile',
                        ...this.editForm
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    this.editSuccess = 'Gegevens opgeslagen!';
                    Object.assign(this.account, this.editForm);
                    setTimeout(() => this.closeEditModal(), 1500);
                } else {
                    this.editError = data.error || 'Er ging iets mis';
                }
            } catch (error) {
                this.editError = 'Er ging iets mis';
            } finally {
                this.saving = false;
            }
        },
        
        openPasswordModal() {
            this.passwordForm = { current: '', new: '', confirm: '' };
            this.passwordError = '';
            this.passwordSuccess = '';
            this.showPasswordModal = true;
        },
        
        closePasswordModal() {
            this.showPasswordModal = false;
        },
        
        async changePassword() {
            this.passwordError = '';
            this.passwordSuccess = '';
            
            if (this.passwordForm.new !== this.passwordForm.confirm) {
                this.passwordError = 'Wachtwoorden komen niet overeen';
                return;
            }
            
            if (this.passwordForm.new.length < 8) {
                this.passwordError = 'Wachtwoord moet minimaal 8 tekens zijn';
                return;
            }
            
            this.savingPassword = true;
            
            try {
                const response = await fetch('api/business-dashboard.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'change_password',
                        current_password: this.passwordForm.current,
                        new_password: this.passwordForm.new
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    this.passwordSuccess = 'Wachtwoord gewijzigd!';
                    setTimeout(() => this.closePasswordModal(), 1500);
                } else {
                    this.passwordError = data.error || 'Er ging iets mis';
                }
            } catch (error) {
                this.passwordError = 'Er ging iets mis';
            } finally {
                this.savingPassword = false;
            }
        },
        
        logout() {
            sessionStorage.removeItem('businessAccount');
            fetch('api/business-logout.php');
            window.location.href = 'index.html';
        },
        
        toggleDetails() {
            this.detailsExpanded = !this.detailsExpanded;
        },
        
        toggleOrder(orderId) {
            const idx = this.openOrders.indexOf(orderId);
            if (idx === -1) {
                this.openOrders.push(orderId);
            } else {
                this.openOrders.splice(idx, 1);
            }
        },
        
        async loadOrders() {
            try {
                const response = await fetch('api/business-orders.php');
                const data = await response.json();
                if (data.success) {
                    this.orders = data.orders;
                }
            } catch (error) {
                console.error('Failed to load orders:', error);
            } finally {
                this.ordersLoading = false;
            }
        },
        
        getDisplayStatus(order) {
            if (order.mollie_status === 'paid') return 'paid';
            return order.status;
        },
        
        getStatusLabel(status) {
            const labels = {
                'pending': 'In afwachting',
                'paid': 'Betaald',
                'confirmed': 'Bevestigd',
                'delivered': 'Geleverd',
                'cancelled': 'Geannuleerd'
            };
            return labels[status] || status;
        },
        
        formatDate(dateStr) {
            if (!dateStr) return '-';
            const date = new Date(dateStr);
            return date.toLocaleDateString('nl-NL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        },
        
        formatDateShort(dateStr) {
            if (!dateStr) return '-';
            const date = new Date(dateStr);
            return date.toLocaleDateString('nl-NL', { weekday: 'short', day: 'numeric', month: 'short' });
        },
        
        formatPrice(amount) {
            if (!amount) return '-';
            return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(amount);
        },
        
        downloadFactuur(order) {
            if (order.factuur_url) {
                window.open(order.factuur_url, '_blank');
            }
        },
        
        hasFactuur(order) {
            return order.status === 'paid' && order.factuur_url;
        }
    }
}).mount('#dashboard-app');
