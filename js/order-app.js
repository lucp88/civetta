const { createApp } = Vue;

const app = createApp({
    components: {
        'product-card': ProductCard,
        'product-detail-modal': ProductDetailModal,
        'recurring-modal': RecurringModal
    },
    
    data() {
        return {
            loading: true,
            products: [],
            cart: {},
            deliveryDate: '',
            notes: '',
            error: '',
            submitting: false,
            btwTarief: 9,
            isFavoriteMode: false,
            favoriteName: '',
            favorites: [],
            loadedName: '',
            selectedFavoriteId: '',
            saveAsFavorite: false,
            selectedProduct: null,
            isRecurring: false,
            recurringName: '',
            recurringFrequency: 'weekly',
            recurringEndDate: '',
            showRecurringModal: false
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
        },
        
        canSaveFavorite() {
            return this.cartItems.length > 0 && this.favoriteName.trim();
        },
        
        minEndDate() {
            const date = new Date();
            date.setMonth(date.getMonth() + 1);
            return date.toISOString().split('T')[0];
        },
        
        frequencyLabel() {
            const labels = {
                'weekly': 'Wekelijks',
                'biweekly': 'Tweewekelijks',
                'monthly': 'Maandelijks'
            };
            return labels[this.recurringFrequency] || 'Wekelijks';
        }
    },
    
    async mounted() {
        const stored = sessionStorage.getItem('businessAccount');
        if (!stored) {
            window.location.href = 'login-bedrijven.html';
            return;
        }
        
        const params = new URLSearchParams(window.location.search);
        this.isFavoriteMode = params.get('mode') === 'favorite';
        
        await this.loadProducts();
        
        if (!this.isFavoriteMode) {
            await this.loadFavorites();
            this.checkForPreloadedData();
        }
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
        
        async loadFavorites() {
            try {
                const response = await fetch('api/business-favorites.php');
                const data = await response.json();
                if (data.success) {
                    this.favorites = data.favorites;
                }
            } catch (error) {
                console.error('Kon favorieten niet laden');
            }
        },
        
        checkForPreloadedData() {
            const favoriteData = sessionStorage.getItem('loadFavorite');
            if (favoriteData) {
                sessionStorage.removeItem('loadFavorite');
                const fav = JSON.parse(favoriteData);
                this.loadFavoriteItems(fav.items, fav.naam);
                return;
            }
            
            const reorderData = sessionStorage.getItem('reorderItems');
            if (reorderData) {
                sessionStorage.removeItem('reorderItems');
                const items = JSON.parse(reorderData);
                this.loadReorderItems(items);
            }
        },
        
        loadFavoriteItems(items, name) {
            const newCart = {};
            items.forEach(item => {
                let product = null;
                if (item.product_id) {
                    product = this.products.find(p => p.id == item.product_id);
                }
                if (!product && item.product_name) {
                    product = this.products.find(p => 
                        p.naam.toLowerCase() === item.product_name.toLowerCase()
                    );
                }
                if (product) {
                    newCart[product.id] = parseInt(item.quantity) || 0;
                }
            });
            this.cart = newCart;
            this.loadedName = name;
        },
        
        loadReorderItems(items) {
            const newCart = {};
            items.forEach(item => {
                let product = null;
                if (item.product_id) {
                    product = this.products.find(p => p.id == item.product_id);
                }
                if (!product && item.product_name) {
                    product = this.products.find(p => 
                        p.naam.toLowerCase() === item.product_name.toLowerCase()
                    );
                }
                if (product) {
                    newCart[product.id] = parseInt(item.quantity) || 0;
                }
            });
            this.cart = newCart;
            this.loadedName = 'Vorige bestelling';
            this.selectedFavoriteId = '';
        },
        
        loadFavoriteFromSelect(event) {
            const favId = event.target.value;
            if (!favId) return;
            
            const fav = this.favorites.find(f => f.id == favId);
            if (fav) {
                this.loadFavoriteItems(fav.items, fav.naam);
                this.selectedFavoriteId = favId;
            }
        },
        
        clearLoaded() {
            this.cart = {};
            this.loadedName = '';
            this.selectedFavoriteId = '';
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
        
        setQuantityDirect(product, qty) {
            if (qty === 0) {
                delete this.cart[product.id];
            } else {
                this.cart[product.id] = qty;
            }
            this.cart = { ...this.cart };
        },
        
        showProductDetail(product) {
            this.selectedProduct = product;
        },
        
        async saveFavorite() {
            this.error = '';
            
            if (!this.canSaveFavorite) {
                this.error = 'Selecteer minimaal één product en geef een naam op';
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
                
                const response = await fetch('api/business-favorites.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        naam: this.favoriteName,
                        items
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    window.location.href = 'mijn-bestellingen.html';
                } else {
                    this.error = data.error || 'Er ging iets mis bij het opslaan';
                }
            } catch (error) {
                this.error = 'Er ging iets mis. Probeer het opnieuw.';
            } finally {
                this.submitting = false;
            }
        },
        
        async saveFavoriteQuiet() {
            try {
                const items = this.cartItems.map(item => ({
                    product_id: item.product.id,
                    product_name: item.product.naam,
                    quantity: item.quantity,
                    unit_price: item.product.prijs
                }));
                
                await fetch('api/business-favorites.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        naam: this.favoriteName,
                        items
                    })
                });
            } catch (error) {
                console.error('Kon favoriet niet opslaan');
            }
        },
        
        goToCheckout() {
            if (!this.canOrder) {
                this.error = 'Selecteer minimaal één product en een leverdatum';
                return;
            }
            
            const items = this.cartItems.map(item => ({
                product_id: item.product.id,
                product_name: item.product.naam,
                product_image: item.product.foto,
                quantity: item.quantity,
                unit_price: item.product.prijs
            }));
            
            const recurringDay = this.deliveryDate ? new Date(this.deliveryDate).getDay() : 1;
            
            const checkoutData = {
                items,
                delivery_date: this.deliveryDate,
                notes: this.notes,
                total_amount: this.totalAmount,
                saveAsFavorite: this.saveAsFavorite,
                favoriteName: this.favoriteName,
                isRecurring: this.isRecurring,
                recurringName: this.recurringName,
                recurringFrequency: this.recurringFrequency,
                recurringDay: recurringDay,
                recurringEndDate: this.recurringEndDate || null
            };
            
            sessionStorage.setItem('checkoutData', JSON.stringify(checkoutData));
            window.location.href = 'checkout.html';
        },
        
        openRecurringModal() {
            this.showRecurringModal = true;
        },
        
        onRecurringConfirm(data) {
            this.isRecurring = true;
            this.recurringName = data.name;
            this.recurringFrequency = data.frequency;
            this.recurringEndDate = data.endDate;
            this.showRecurringModal = false;
        },
        
        formatDateShort(dateStr) {
            if (!dateStr) return '';
            try {
                const date = new Date(dateStr);
                if (isNaN(date.getTime())) return '';
                return date.toLocaleDateString('nl-NL', { day: 'numeric', month: 'short', year: 'numeric' });
            } catch (e) {
                console.error('Date parse error:', e);
                return '';
            }
        }
    }
});

app.mount('#order-app');
