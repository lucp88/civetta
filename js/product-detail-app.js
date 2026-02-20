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
        selectableVariants() {
            if (!this.product || !this.product.variants) return [];
            return [...this.product.variants].sort((a, b) => {
                const nameA = (a.naam || '').toLowerCase();
                const nameB = (b.naam || '').toLowerCase();
                if (nameA !== nameB) return nameA.localeCompare(nameB);
                return (a.gewicht || 0) - (b.gewicht || 0);
            });
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
        },

        hasBiologisch() {
            return this.displayIngredients && this.displayIngredients.includes('*');
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

                // Auto-select first variant
                if (product.variants && product.variants.length > 0) {
                    this.selectedVariantId = this.selectableVariants[0].id;
                }
            } catch (e) {
                this.error = 'Er ging iets mis bij het laden van het product.';
            } finally {
                this.loading = false;
            }
        },

        variantLabel(v) {
            let label = '';
            if (v.naam && v.gewicht) label = v.naam + ' ' + v.gewicht + 'g';
            else if (v.naam) label = v.naam;
            else label = v.gewicht + 'g';
            if (parseFloat(v.prijs) > 0) label += ' — ' + this.formatPrice(v.prijs);
            return label;
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
