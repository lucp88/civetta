const { createApp } = Vue;

async function pdokPostcodeOpzoeken(postcode) {
    const p = postcode.replace(/\s/g, '').toUpperCase();
    if (!/^[0-9]{4}[A-Z]{2}$/.test(p)) return null;
    try {
        const resp = await fetch(`https://api.pdok.nl/bzk/locatieserver/search/v3_1/free?q=${p}&fq=type:adres&rows=1`);
        if (!resp.ok) return null;
        const data = await resp.json();
        const hit = data.response?.docs?.[0];
        return hit ? { straat: hit.straatnaam || '', plaats: hit.woonplaatsnaam || '' } : null;
    } catch { return null; }
}

createApp({
    data() {
        return {
            showSuccess: false,
            submitting: false,
            error: '',
            submittedEmail: '',
            postcodeLoading: false,
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
        async lookupPostcode() {
            if (!this.form.postcode) return;
            this.postcodeLoading = true;
            const result = await pdokPostcodeOpzoeken(this.form.postcode);
            this.postcodeLoading = false;
            if (result) {
                if (!this.form.adres) this.form.adres = result.straat ? result.straat + ' ' : '';
                this.form.plaats = result.plaats;
            }
        },

        async submitRegistration() {
            this.error = '';
            this.submitting = true;

            try {
                const recaptchaToken = await getRecaptchaToken('register');
                const response = await fetch('api/business-accounts.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ...this.form, recaptcha_token: recaptchaToken })
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
