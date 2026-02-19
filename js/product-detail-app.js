const { createApp } = Vue;

const productDetailApp = createApp({
    data() {
        return {
            product: null,
            loading: true,
            error: null,
            showDetails: false
        };
    },

    computed: {
        ingredientList() {
            if (!this.product) return null;
            return this.product.ingredienten_recipe || this.product.ingredienten || null;
        },
        pricedVariants() {
            if (!this.product || !this.product.variants) return [];
            return this.product.variants
                .filter(v => parseFloat(v.prijs) > 0)
                .sort((a, b) => parseFloat(a.prijs) - parseFloat(b.prijs));
        }
    },

    mounted() {
        this.loadProduct();
    },

    methods: {
        async loadProduct() {
            const params = new URLSearchParams(window.location.search);
            const id = parseInt(params.get('id'));

            if (!id) {
                this.error = 'Geen product opgegeven.';
                this.loading = false;
                return;
            }

            try {
                const response = await fetch('api/products.php');
                const data = await response.json();

                if (!data.success) {
                    this.error = 'Producten konden niet worden geladen.';
                    this.loading = false;
                    return;
                }

                const product = data.products.find(p => parseInt(p.id) === id);
                if (!product) {
                    this.error = 'Product niet gevonden.';
                    this.loading = false;
                    return;
                }

                this.product = product;
                document.title = product.naam + ' | Bakkerij Civetta';
            } catch (e) {
                this.error = 'Er ging iets mis bij het laden van het product.';
            } finally {
                this.loading = false;
            }
        },

        formatPrice(price) {
            if (!parseFloat(price)) return null;
            return new Intl.NumberFormat('nl-NL', {
                style: 'currency',
                currency: 'EUR'
            }).format(price);
        }
    }
});

productDetailApp.mount('#product-detail-app');
