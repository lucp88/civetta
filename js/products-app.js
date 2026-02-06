const { createApp } = Vue;

const productsApp = createApp({
    components: {
        'product-detail-modal': ProductDetailModal
    },
    
    data() {
        return {
            products: [],
            loading: true,
            selectedProduct: null
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
        
        showProductDetail(product) {
            this.selectedProduct = product;
        },
        
        getLowestPrice(product) {
            if (!product.variants || product.variants.length === 0) return product.prijs;
            return Math.min(...product.variants.map(v => parseFloat(v.prijs)));
        },
        
        getOtherVariants(product) {
            if (!product.variants || product.variants.length <= 1) return '';
            const sorted = [...product.variants].sort((a, b) => parseFloat(a.prijs) - parseFloat(b.prijs));
            const others = sorted.slice(1);
            return others.map(v => `${v.gewicht}g ${this.formatPrice(v.prijs)}`).join(' · ');
        }
    }
});

productsApp.mount('#products-app');
