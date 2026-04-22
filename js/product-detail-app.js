const { createApp } = Vue;

createApp({
    data() {
        return {
            product: null,
            allAllergens: [],
            loading: true,
            error: null,
            showDetails: false,
            showAllergens: true,
            selectedVariantId: null,
            // Cart
            isBusinessUser: false,
            addQuantity: 1,
            addedToCart: false
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

        displayIngredientItems() {
            const variant = this.selectedVariant;
            if (variant && variant.ingredienten_items) return variant.ingredienten_items;
            if (!this.product) return null;
            return this.product.ingredienten_items || null;
        },

        displayRecipeDetails() {
            const variant = this.selectedVariant;
            if (variant && variant.recipe_details) return variant.recipe_details;
            return this.product ? this.product.recipe_details : null;
        },

        allergenList() {
            const items = this.displayIngredientItems;
            if (!items) return [];
            const seen = new Set();
            return items.filter(i => i.allergeen).reduce((list, i) => {
                const label = (i.allergeen_naam || i.name.replace(/\*$/, '')).toLowerCase();
                if (!seen.has(label)) {
                    seen.add(label);
                    list.push(i.allergeen_naam || i.name.replace(/\*$/, ''));
                }
                return list;
            }, []);
        },

        traceAllergens() {
            const productAllergens = new Set(this.allergenList.map(a => a.toLowerCase()));
            return this.allAllergens.filter(a => !productAllergens.has(a.toLowerCase()));
        },

        hasBiologisch() {
            return this.displayIngredients && this.displayIngredients.includes('*');
        },

        isComingSoon() {
            return this.selectedVariant && !this.selectedVariant.is_active;
        },

        canAddToCart() {
            return this.isBusinessUser && this.selectedVariant && this.selectedVariant.is_active;
        },

        cartCountForVariant() {
            if (!this.selectedVariant) return 0;
            try {
                const data = JSON.parse(sessionStorage.getItem('checkoutData') || '{}');
                const item = (data.items || []).find(i => i.variant_id == this.selectedVariant.id);
                return item ? item.quantity : 0;
            } catch(e) { return 0; }
        }
    },

    mounted() {
        this.isBusinessUser = !!sessionStorage.getItem('businessAccount');
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
                this.allAllergens = data.all_allergens || [];
                document.title = product.naam + ' | Bakkerij Civetta';

                // Auto-select first active variant, fall back to first
                if (product.variants && product.variants.length > 0) {
                    const firstActive = this.selectableVariants.find(v => v.is_active);
                    this.selectedVariantId = (firstActive || this.selectableVariants[0]).id;
                }
            } catch (e) {
                this.error = 'Er ging iets mis bij het laden van het product.';
            } finally {
                this.loading = false;
            }
        },

        addToCart() {
            if (!this.canAddToCart) return;

            const variant = this.selectedVariant;
            const qty = Math.max(1, parseInt(this.addQuantity) || 1);

            let productName = this.product.naam;
            if (variant.naam && variant.gewicht) productName += ' ' + variant.naam + ' (' + variant.gewicht + 'g)';
            else if (variant.naam) productName += ' ' + variant.naam;
            else if (variant.gewicht) productName += ' (' + variant.gewicht + 'g)';

            try {
                const stored = JSON.parse(sessionStorage.getItem('checkoutData') || '{}');
                const items = stored.items || [];
                const existing = items.findIndex(i => i.variant_id == variant.id);
                if (existing >= 0) {
                    items[existing].quantity += qty;
                } else {
                    items.push({
                        product_name: productName,
                        product_image: this.product.foto || null,
                        quantity: qty,
                        unit_price: parseFloat(variant.prijs),
                        variant_id: variant.id,
                        product_id: this.product.id
                    });
                }
                const total = items.reduce((s, i) => s + i.unit_price * i.quantity, 0);
                sessionStorage.setItem('checkoutData', JSON.stringify({ items, total_amount: total }));
                if (typeof updateNavCart === 'function') updateNavCart();
            } catch(e) {}

            this.addedToCart = true;
            this.addQuantity = 1;
            setTimeout(() => { this.addedToCart = false; }, 1800);
        },

        variantLabel(v) {
            let label = '';
            if (v.naam && v.gewicht) label = v.naam + ' - ' + v.gewicht + 'g';
            else if (v.naam) label = v.naam;
            else label = v.gewicht + 'g';
            if (parseFloat(v.prijs) > 0) label += ' — ' + this.formatPrice(v.prijs);
            if (!v.is_active) label += ' (binnenkort beschikbaar)';
            return label;
        },

        formatPrice(price) {
            if (!parseFloat(price)) return null;
            return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(price);
        }
    }
}).mount('#product-detail-app');
