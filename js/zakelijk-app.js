const { createApp } = Vue;

createApp({
    data() {
        return {
            showSuccess: false,
            submitting: false,
            error: '',
            submittedEmail: '',
            form: {
                bedrijfsnaam: '',
                adres: '',
                postcode: '',
                plaats: '',
                contactpersoon: '',
                email: '',
                telefoon: '',
                website: '',
                kvk_nummer: '',
                btw_id: '',
                opmerkingen: ''
            }
        };
    },
    
    methods: {
        async submitRegistration() {
            this.error = '';
            this.submitting = true;
            
            try {
                const response = await fetch('api/business-accounts.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.form)
                });
                
                const data = await response.json();
                
                if (data.success) {
                    this.submittedEmail = this.form.email;
                    this.showSuccess = true;
                    this.form = {
                        bedrijfsnaam: '',
                        adres: '',
                        postcode: '',
                        plaats: '',
                        contactpersoon: '',
                        email: '',
                        telefoon: '',
                        website: '',
                        kvk_nummer: '',
                        btw_id: '',
                        opmerkingen: ''
                    };
                } else {
                    this.error = data.error || 'Er ging iets mis. Probeer het opnieuw.';
                }
            } catch (error) {
                this.error = 'Er ging iets mis. Probeer het opnieuw.';
            } finally {
                this.submitting = false;
            }
        }
    }
}).mount('#zakelijk-app');
