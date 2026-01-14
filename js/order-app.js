const { createApp } = Vue;

createApp({
    data() {
        return {
            loading: true,
            products: [],
            cart: {},
            deliveryDate: '',
            notes: '',
            error: '',
            submitting: false,
            btwTarief: 9
        };
    },
    
    computed: {
        minDeliveryDate() {
            const date = new Date();
            date.setDate(date.getDate() + 2);
            return date.toISOString().split('T')[0];
        },
        
        cartItems() {
            return Object.entries(this.cart)
                .filter(([id, qty]) => qty > 0)
                .map(([id, qty]) => {
                    const product = this.products.find(p => p.id == id);
                    return {
                        product,
                        quantity: qty,
                        subtotal: (product?.prijs || 0) * qty
                    };
                });
        },
        
        totalAmount() {
            return this.cartItems.reduce((sum, item) => sum + item.subtotal, 0);
        },
        
        btwBedrag() {
            return this.totalAmount - (this.totalAmount / (1 + this.btwTarief / 100));
        },
        
        exclBtw() {
            return this.totalAmount - this.btwBedrag;
        },
        
        canOrder() {
            return this.cartItems.length > 0 && this.deliveryDate && this.totalAmount > 0;
        }
    },
    
    async mounted() {
        const stored = sessionStorage.getItem('businessAccount');
        if (!stored) {
            window.location.href = 'login-bedrijven.html';
            return;
        }
        
        await this.loadProducts();
    },
    
    methods: {
        async loadProducts() {
            try {
                const response = await fetch('api/products.php');
                const data = await response.json();
                if (data.success) {
                    this.products = data.products.filter(p => p.prijs && p.prijs > 0);
                    if (data.btw_tarief) {
                        this.btwTarief = data.btw_tarief;
                    }
                }
            } catch (error) {
                this.error = 'Kon producten niet laden';
            } finally {
                this.loading = false;
            }
        },
        
        getQuantity(productId) {
            return this.cart[productId] || 0;
        },
        
        setQuantity(product, value) {
            const qty = Math.max(0, parseInt(value) || 0);
            if (qty === 0) {
                delete this.cart[product.id];
            } else {
                this.cart[product.id] = qty;
            }
            this.cart = { ...this.cart };
        },
        
        increaseQty(product) {
            const current = this.getQuantity(product.id);
            this.cart[product.id] = current + 1;
            this.cart = { ...this.cart };
        },
        
        decreaseQty(product) {
            const current = this.getQuantity(product.id);
            if (current > 0) {
                if (current === 1) {
                    delete this.cart[product.id];
                } else {
                    this.cart[product.id] = current - 1;
                }
                this.cart = { ...this.cart };
            }
        },
        
        formatPrice(amount) {
            return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(amount);
        },
        
        handleImageError(e) {
            e.target.src = 'img/placeholder-bread.jpg';
        },
        
        async placeOrder() {
            this.error = '';
            
            if (!this.canOrder) {
                this.error = 'Selecteer minimaal één product en een leverdatum';
                return;
            }
            
            this.submitting = true;
            
            try {
                const items = this.cartItems.map(item => ({
                    product_id: item.product.id,
                    product_name: item.product.naam,
                    quantity: item.quantity,
                    unit_price: item.product.prijs
                }));
                
                const response = await fetch('api/business-orders.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        items,
                        delivery_date: this.deliveryDate,
                        notes: this.notes,
                        total_amount: this.totalAmount
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    if (data.payment_url) {
                        window.location.href = data.payment_url;
                    } else {
                        window.location.href = 'zakelijk-dashboard.html?order=success';
                    }
                } else {
                    this.error = data.error || 'Er ging iets mis bij het plaatsen van de bestelling';
                }
            } catch (error) {
                this.error = 'Er ging iets mis. Probeer het opnieuw.';
            } finally {
                this.submitting = false;
            }
        }
    }
}).mount('#order-app');
