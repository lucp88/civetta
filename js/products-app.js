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
        }
    }
});

productsApp.mount('#products-app');
