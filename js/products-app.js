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
        }
    }
}).mount('#products-app');
