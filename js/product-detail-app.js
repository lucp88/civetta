const { createApp } = Vue;

const productDetailApp = createApp({
    data() {
        return {
            product: null,
            loading: true,
            error: null,
            showDetails: false,
            selectedVariantId: null
        };
    },

    computed: {
        pricedVariants() {
            if (!this.product || !this.product.variants) return [];
            return this.product.variants
                .filter(v => parseFloat(v.prijs) > 0)
                .sort((a, b) => parseFloat(a.prijs) - parseFloat(b.prijs));
        },

        selectedVariant() {
            if (!this.selectedVariantId || !this.product) return null;
            return this.product.variants.find(v => v.id === this.selectedVariantId) || null;
        },

        displayFoto() {
            const variant = this.selectedVariant;
            if (variant && variant.foto) return variant.foto;
            return this.product ? this.product.foto : null;
        },

        displayIngredients() {
            const variant = this.selectedVariant;
            if (variant && variant.ingredienten_recipe) return variant.ingredienten_recipe;
            if (!this.product) return null;
            return this.product.ingredienten_recipe || this.product.ingredienten || null;
        },

        displayRecipeDetails() {
            const variant = this.selectedVariant;
            if (variant && variant.recipe_details) return variant.recipe_details;
            return this.product ? this.product.recipe_details : null;
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

                // Auto-select first priced variant
                const priced = (product.variants || []).filter(v => parseFloat(v.prijs) > 0);
                if (priced.length > 0) {
                    this.selectedVariantId = priced.sort((a, b) => parseFloat(a.prijs) - parseFloat(b.prijs))[0].id;
                }
            } catch (e) {
                this.error = 'Er ging iets mis bij het laden van het product.';
            } finally {
                this.loading = false;
            }
        },

        variantLabel(v) {
            if (v.naam && v.gewicht) return v.naam + ' ' + v.gewicht + 'g';
            if (v.naam) return v.naam;
            return v.gewicht + 'g';
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
