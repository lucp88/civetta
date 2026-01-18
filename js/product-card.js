const ProductCard = {
    props: {
        product: {
            type: Object,
            required: true
        },
        showQuantity: {
            type: Boolean,
            default: false
        },
        quantity: {
            type: Number,
            default: 0
        }
    },
    
    emits: ['update:quantity', 'show-detail'],
    
    template: `
        <div class="product-card-modern" :class="{ selected: quantity > 0 }">
            <div class="product-card-image" @click="$emit('show-detail', product)">
                <img :src="product.foto || 'img/placeholder-bread.jpg'" 
                     :alt="product.naam"
                     @error="handleImageError">
            </div>
            <div class="product-card-content">
                <h4 class="product-card-title" @click="$emit('show-detail', product)">
                    {{ product.naam }}
                </h4>
                <div class="product-card-price" v-if="product.prijs">
                    {{ formatPrice(product.prijs) }}
                </div>
                <div class="product-card-price" v-else>Prijs op aanvraag</div>
                
                <div v-if="showQuantity" class="product-card-quantity">
                    <button class="qty-btn" @click="decrease" :disabled="quantity === 0">-</button>
                    <input type="number" 
                           class="qty-input" 
                           :value="quantity" 
                           @change="setQuantity($event.target.value)"
                           min="0">
                    <button class="qty-btn" @click="increase">+</button>
                </div>
            </div>
        </div>
    `,
    
    methods: {
        formatPrice(amount) {
            return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(amount);
        },
        
        handleImageError(e) {
            e.target.src = 'img/placeholder-bread.jpg';
        },
        
        increase() {
            this.$emit('update:quantity', this.quantity + 1);
        },
        
        decrease() {
            if (this.quantity > 0) {
                this.$emit('update:quantity', this.quantity - 1);
            }
        },
        
        setQuantity(value) {
            const qty = Math.max(0, parseInt(value) || 0);
            this.$emit('update:quantity', qty);
        }
    }
};

const ProductDetailModal = {
    props: {
        product: {
            type: Object,
            default: null
        }
    },
    
    emits: ['close'],
    
    template: `
        <div class="product-modal-overlay" :class="{ active: product }" @click.self="$emit('close')">
            <div class="product-modal" v-if="product">
                <button class="product-modal-close" @click="$emit('close')">&times;</button>
                
                <div class="product-modal-image" v-if="product.foto">
                    <img :src="product.foto" :alt="product.naam">
                </div>
                
                <div class="product-modal-content">
                    <h2 class="product-modal-title">{{ product.naam }}</h2>
                    
                    <p v-if="product.beschrijving" class="product-modal-description">
                        {{ product.beschrijving }}
                    </p>
                    
                    <div v-if="product.ingredienten" class="product-modal-ingredients">
                        <strong>Ingrediënten:</strong> {{ product.ingredienten }}
                    </div>
                    
                    <div class="product-modal-price" v-if="product.prijs">
                        {{ formatPrice(product.prijs) }}
                    </div>
                </div>
            </div>
        </div>
    `,
    
    methods: {
        formatPrice(amount) {
            return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(amount);
        }
    }
};

const productCardStyles = `
    .product-card-modern {
        display: flex;
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        transition: all 0.2s ease;
        border: 2px solid transparent;
    }
    .product-card-modern:hover {
        box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    }
    .product-card-modern.selected {
        border-color: var(--color-wheat);
        background: var(--color-wheat-light);
    }
    .product-card-image {
        width: 100px;
        min-height: 100px;
        flex-shrink: 0;
        cursor: pointer;
        overflow: hidden;
    }
    .product-card-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s ease;
    }
    .product-card-modern:hover .product-card-image img {
        transform: scale(1.05);
    }
    .product-card-content {
        flex: 1;
        padding: 0.75rem 1rem;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 0.25rem;
    }
    .product-card-title {
        margin: 0;
        font-size: 1rem;
        font-weight: 600;
        color: var(--color-crust-dark);
        cursor: pointer;
        transition: color 0.2s ease;
    }
    .product-card-title:hover {
        color: var(--color-terracotta);
    }
    .product-card-price {
        font-size: 0.95rem;
        font-weight: 600;
        color: var(--color-terracotta);
    }
    .product-card-quantity {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-top: 0.5rem;
        margin-left: auto;
    }
    .product-card-quantity .qty-btn {
        width: 32px;
        height: 32px;
        border: 1px solid #ddd;
        border-radius: 6px;
        background: white;
        color: #333;
        font-size: 1.2rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.15s ease;
    }
    .product-card-quantity .qty-btn:hover:not(:disabled) {
        background: var(--color-parchment);
        border-color: var(--color-crust);
    }
    .product-card-quantity .qty-btn:disabled {
        opacity: 0.4;
        cursor: not-allowed;
    }
    .product-card-quantity .qty-input {
        width: 50px;
        text-align: center;
        border: 1px solid #ddd;
        border-radius: 6px;
        padding: 0.4rem;
        font-size: 1rem;
        font-weight: 600;
        color: var(--color-crust-dark);
        -moz-appearance: textfield;
    }
    .product-card-quantity .qty-input::-webkit-outer-spin-button,
    .product-card-quantity .qty-input::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
    .product-card-quantity .qty-input:focus {
        outline: none;
        border-color: var(--color-wheat);
    }

    .product-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.6);
        z-index: 1000;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }
    .product-modal-overlay.active {
        display: flex;
    }
    .product-modal {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        width: 100%;
        max-width: 500px;
        max-height: 90vh;
        overflow-y: auto;
        position: relative;
    }
    .product-modal-close {
        position: absolute;
        top: 1rem;
        right: 1rem;
        width: 36px;
        height: 36px;
        border: none;
        background: rgba(255,255,255,0.9);
        border-radius: 50%;
        font-size: 1.5rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    .product-modal-close:hover {
        background: white;
    }
    .product-modal-image {
        width: 100%;
        aspect-ratio: 4/3;
    }
    .product-modal-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .product-modal-content {
        padding: 1.5rem;
    }
    .product-modal-title {
        font-family: var(--font-display);
        font-size: 1.5rem;
        color: var(--color-crust-dark);
        margin: 0 0 0.75rem 0;
    }
    .product-modal-description {
        color: var(--color-stone);
        line-height: 1.6;
        margin-bottom: 1rem;
    }
    .product-modal-ingredients {
        font-size: 0.9rem;
        color: var(--color-sage);
        padding: 0.75rem 1rem;
        background: var(--color-parchment);
        border-radius: 8px;
        margin-bottom: 1rem;
    }
    .product-modal-ingredients strong {
        color: var(--color-crust);
    }
    .product-modal-price {
        font-family: var(--font-display);
        font-size: 1.35rem;
        font-weight: 700;
        color: var(--color-crust);
    }
`;

if (typeof document !== 'undefined') {
    const styleEl = document.createElement('style');
    styleEl.textContent = productCardStyles;
    document.head.appendChild(styleEl);
}
