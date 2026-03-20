const { createApp } = Vue;

createApp({
    data() {
        return {
            products: [],
            loading: true
        };
    },

    mounted() {
        this.loadProducts();
    },

    methods: {
        async loadProducts() {
            try {
                const response = await fetch('api/products.php');
                const data = await response.json();
                if (data.success) {
                    this.products = data.products;
                }
            } catch (error) {
                console.error('Error loading products:', error);
            } finally {
                this.loading = false;
            }
        },

        formatPrice(price) {
            if (!price) return null;
            return new Intl.NumberFormat('nl-NL', {
                style: 'currency',
                currency: 'EUR'
            }).format(price);
        },

        getLowestPrice(product) {
            if (!product.variants || product.variants.length === 0) return product.prijs;
            const priced = product.variants.filter(v => parseFloat(v.prijs) > 0);
            if (priced.length === 0) return product.prijs;
            return Math.min(...priced.map(v => parseFloat(v.prijs)));
        },

        getOtherVariants(product) {
            if (!product.variants || product.variants.length <= 1) return '';
            const sorted = [...product.variants].sort((a, b) => parseFloat(a.prijs) - parseFloat(b.prijs));
            const others = sorted.slice(1).filter(v => parseFloat(v.prijs) > 0);
            return others.map(v => {
                const label = v.naam ? (v.gewicht ? `${v.naam} ${v.gewicht}g` : v.naam) : `${v.gewicht}g`;
                return `${label} ${this.formatPrice(v.prijs)}`;
            }).join(' · ');
        }
    }
}).mount('#products-app');
