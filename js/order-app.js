const { createApp } = Vue;

const app = createApp({
    components: {
        'product-card': ProductCard,
        'product-detail-modal': ProductDetailModal
    },
    
    data() {
        return {
            loading: true,
            products: [],
            cart: {},
            variantCart: [],
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
            allAllergens: [],
            isRecurring: false,
            recurringName: '',
            recurringFrequency: 'weekly',
            recurringEndDate: ''
        };
    },
    
    computed: {
        minDeliveryDate() {
            const date = new Date();
            date.setDate(date.getDate() + 2);
            return date.toISOString().split('T')[0];
        },
        
        cartItems() {
            const items = [];
            
            Object.entries(this.cart)
                .filter(([id, qty]) => qty > 0)
                .forEach(([id, qty]) => {
                    const product = this.products.find(p => p.id == id);
                    if (product) {
                        items.push({
                            key: `product-${id}`,
                            product,
                            quantity: qty,
                            variantId: null,
                            gewicht: null,
                            prijs: product.prijs,
                            subtotal: (product.prijs || 0) * qty
                        });
                    }
                });
            
            this.variantCart.forEach((item, index) => {
                const product = this.products.find(p => p.id == item.productId);
                if (product) {
                    const variant = product.variants?.find(v => v.id == item.variantId);
                    if (variant) {
                        items.push({
                            key: `variant-${index}`,
                            product,
                            quantity: item.quantity,
                            variantId: item.variantId,
                            gewicht: variant.gewicht,
                            prijs: variant.prijs,
                            subtotal: variant.prijs * item.quantity
                        });
                    }
                }
            });
            
            return items;
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
                    this.products = data.products.filter(p => (p.prijs && p.prijs > 0) || (p.variants && p.variants.length > 0));
                    this.allAllergens = data.all_allergens || [];
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
            this.cart = {};
            this.variantCart = [];
            
            items.forEach(item => {
                let product = null;
                if (item.product_id) {
                    product = this.products.find(p => p.id == item.product_id);
                }
                if (!product && item.product_name) {
                    const baseName = item.product_name.replace(/\s*\(\d+g\)$/, '');
                    product = this.products.find(p => 
                        p.naam.toLowerCase() === baseName.toLowerCase()
                    );
                }
                if (product) {
                    if (item.variant_id && product.variants?.length > 0) {
                        this.variantCart.push({
                            productId: product.id,
                            variantId: item.variant_id,
                            quantity: parseInt(item.quantity) || 1
                        });
                    } else if (product.variants?.length > 0) {
                        this.variantCart.push({
                            productId: product.id,
                            variantId: product.variants[0].id,
                            quantity: parseInt(item.quantity) || 1
                        });
                    } else {
                        this.cart[product.id] = parseInt(item.quantity) || 0;
                    }
                }
            });
            this.loadedName = name;
        },
        
        loadReorderItems(items) {
            this.loadFavoriteItems(items, 'Vorige bestelling');
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
            this.variantCart = [];
            this.loadedName = '';
            this.selectedFavoriteId = '';
        },
        
        getQuantity(productId) {
            let total = this.cart[productId] || 0;
            this.variantCart.forEach(item => {
                if (item.productId == productId) {
                    total += item.quantity;
                }
            });
            return total;
        },
        
        setQuantityDirect(product, qty) {
            if (product.variants?.length > 0) {
                return;
            }
            if (qty === 0) {
                delete this.cart[product.id];
            } else {
                this.cart[product.id] = qty;
            }
            this.cart = { ...this.cart };
        },
        
        addVariantToCart({ product, variant, quantity }) {
            const existing = this.variantCart.findIndex(
                item => item.productId == product.id && item.variantId == variant.id
            );
            
            if (existing >= 0) {
                this.variantCart[existing].quantity += quantity;
            } else {
                this.variantCart.push({
                    productId: product.id,
                    variantId: variant.id,
                    quantity
                });
            }
            this.variantCart = [...this.variantCart];
        },
        
        removeCartItem(item) {
            if (item.variantId) {
                const index = this.variantCart.findIndex(
                    v => v.productId == item.product.id && v.variantId == item.variantId
                );
                if (index >= 0) {
                    this.variantCart.splice(index, 1);
                    this.variantCart = [...this.variantCart];
                }
            } else {
                delete this.cart[item.product.id];
                this.cart = { ...this.cart };
            }
        },
        
        updateCartItemQty(item, newQty) {
            if (newQty <= 0) {
                this.removeCartItem(item);
                return;
            }
            
            if (item.variantId) {
                const index = this.variantCart.findIndex(
                    v => v.productId == item.product.id && v.variantId == item.variantId
                );
                if (index >= 0) {
                    this.variantCart[index].quantity = newQty;
                    this.variantCart = [...this.variantCart];
                }
            } else {
                this.cart[item.product.id] = newQty;
                this.cart = { ...this.cart };
            }
        },
        
        formatPrice(amount) {
            return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(amount);
        },
        
        handleImageError(e) {
            e.target.onerror = null;
            e.target.src = 'img/placeholder-bread.jpg';
        },
        
        showProductDetail(product) {
            this.selectedProduct = product;
        },
        
        buildCheckoutItems() {
            return this.cartItems.map(item => {
                let productName = item.product.naam;
                if (item.gewicht) {
                    productName = `${item.product.naam} (${item.gewicht}g)`;
                }
                return {
                    product_id: item.product.id,
                    product_name: productName,
                    product_image: item.product.foto,
                    quantity: item.quantity,
                    unit_price: item.prijs,
                    variant_id: item.variantId
                };
            });
        },
        
        async saveFavorite() {
            this.error = '';
            
            if (!this.canSaveFavorite) {
                this.error = 'Selecteer minimaal één product en geef een naam op';
                return;
            }
            
            this.submitting = true;
            
            try {
                const items = this.buildCheckoutItems();
                
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
                const items = this.buildCheckoutItems();
                
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
            
            const items = this.buildCheckoutItems();
            
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
