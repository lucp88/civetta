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
    
    emits: ['update:quantity', 'add-to-cart', 'show-detail'],
    
    data() {
        return {
            selectedVariantId: null,
            addQuantity: 1
        };
    },
    
    computed: {
        hasVariants() {
            return this.product.variants && this.product.variants.length > 0;
        },
        currentVariant() {
            if (!this.hasVariants) return null;
            return this.product.variants.find(v => v.id == this.selectedVariantId) || this.product.variants[0];
        }
    },
    
    mounted() {
        if (this.hasVariants) {
            this.selectedVariantId = this.product.variants[0].id;
        }
    },
    
    template: `
        <div class="product-card-modern" :class="{ selected: quantity > 0 }">
            <button v-if="hasVariants && showQuantity" class="btn-add-cart" @click.stop="addToCart" title="Toevoegen aan bestelling">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M5 7h14l-1.5 9h-11L5 7z"/>
                    <path d="M5 7l-.5-3H2"/>
                    <path d="M8 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/>
                    <path d="M16 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/>
                </svg>
                <span class="plus">+</span>
            </button>
            <div class="product-card-image" @click="$emit('show-detail', product)">
                <img v-if="product.foto" :src="product.foto" :alt="product.naam" @error="onImgError">
                <div v-else class="product-card-placeholder">
                    <svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <ellipse cx="40" cy="42" rx="28" ry="18" fill="#d4a574" opacity="0.4"/>
                        <ellipse cx="40" cy="38" rx="26" ry="16" fill="#c4956a" opacity="0.6"/>
                        <ellipse cx="40" cy="36" rx="24" ry="14" fill="#b8875c"/>
                        <path d="M22 36c3-5 8-8 18-8s15 3 18 8" stroke="#a07040" stroke-width="1" fill="none" opacity="0.5"/>
                        <path d="M28 34c2-3 6-5 12-5s10 2 12 5" stroke="#a07040" stroke-width="0.8" fill="none" opacity="0.4"/>
                    </svg>
                </div>
            </div>
            <div class="product-card-content">
                <h4 class="product-card-title" @click="$emit('show-detail', product)">
                    {{ product.naam }}
                </h4>
                
                <!-- Product MET varianten: dropdown + qty -->
                <template v-if="hasVariants && showQuantity">
                    <div class="product-card-variant-row">
                        <select v-model="selectedVariantId" class="variant-select">
                            <option v-for="v in product.variants" :key="v.id" :value="v.id">
                                {{ v.gewicht }}g - {{ formatPrice(v.prijs) }}
                            </option>
                        </select>
                        <div class="add-qty-group">
                            <button class="qty-btn-small" @click="addQuantity = Math.max(1, addQuantity - 1)">-</button>
                            <input type="number" v-model.number="addQuantity" min="1" class="qty-input-small">
                            <button class="qty-btn-small" @click="addQuantity++">+</button>
                        </div>
                    </div>
                </template>
                
                <!-- Product MET varianten maar ZONDER quantity controls (producten pagina) -->
                <div v-else-if="hasVariants" class="product-card-price">
                    {{ formatPriceRange() }}
                </div>
                
                <!-- Product ZONDER varianten -->
                <template v-else>
                    <div v-if="product.prijs" class="product-card-price">
                        {{ formatPrice(product.prijs) }}
                    </div>
                    <div v-else class="product-card-price">Prijs op aanvraag</div>
                    
                    <div v-if="showQuantity" class="product-card-quantity">
                        <button class="qty-btn" @click="decrease" :disabled="quantity === 0">-</button>
                        <input type="number" 
                               class="qty-input" 
                               :value="quantity" 
                               @change="setQuantity($event.target.value)"
                               min="0">
                        <button class="qty-btn" @click="increase">+</button>
                    </div>
                </template>
            </div>
        </div>
    `,
    
    methods: {
        formatPrice(amount) {
            return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(amount);
        },
        
        formatPriceRange() {
            if (!this.hasVariants) return '';
            const prices = this.product.variants.map(v => v.prijs);
            const min = Math.min(...prices);
            const max = Math.max(...prices);
            if (min === max) return this.formatPrice(min);
            return `${this.formatPrice(min)} - ${this.formatPrice(max)}`;
        },
        
        onImgError(e) {
            e.target.style.display = 'none';
            e.target.parentElement.innerHTML = '<div class="product-card-placeholder"><svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg"><ellipse cx="40" cy="42" rx="28" ry="18" fill="#d4a574" opacity="0.4"/><ellipse cx="40" cy="38" rx="26" ry="16" fill="#c4956a" opacity="0.6"/><ellipse cx="40" cy="36" rx="24" ry="14" fill="#b8875c"/><path d="M22 36c3-5 8-8 18-8s15 3 18 8" stroke="#a07040" stroke-width="1" fill="none" opacity="0.5"/><path d="M28 34c2-3 6-5 12-5s10 2 12 5" stroke="#a07040" stroke-width="0.8" fill="none" opacity="0.4"/></svg></div>';
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
        },
        
        addToCart() {
            if (this.addQuantity > 0 && this.currentVariant) {
                this.$emit('add-to-cart', {
                    product: this.product,
                    variant: this.currentVariant,
                    quantity: this.addQuantity
                });
                this.addQuantity = 1;
            }
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

    data() {
        return {
            showDetails: false
        };
    },

    watch: {
        product(newVal) {
            if (newVal) this.showDetails = false;
        }
    },

    computed: {
        hasVariants() {
            return this.product && this.product.variants && this.product.variants.length > 0;
        },
        ingredientList() {
            if (!this.product) return null;
            return this.product.ingredienten_recipe || this.product.ingredienten || null;
        }
    },

    template: `
        <div class="product-modal-overlay" :class="{ active: product }" @click.self="$emit('close')">
            <div class="product-modal" v-if="product">
                <button class="product-modal-close" @click="$emit('close')">&times;</button>

                <div class="product-modal-image">
                    <img v-if="product.foto" :src="product.foto" :alt="product.naam">
                    <div v-else class="product-modal-placeholder">
                        <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <ellipse cx="60" cy="65" rx="40" ry="24" fill="#d4a574" opacity="0.4"/>
                            <ellipse cx="60" cy="60" rx="38" ry="22" fill="#c4956a" opacity="0.6"/>
                            <ellipse cx="60" cy="56" rx="35" ry="20" fill="#b8875c"/>
                            <path d="M32 56c4-7 12-12 28-12s24 5 28 12" stroke="#a07040" stroke-width="1.2" fill="none" opacity="0.5"/>
                            <path d="M40 53c3-4 9-7 20-7s17 3 20 7" stroke="#a07040" stroke-width="1" fill="none" opacity="0.4"/>
                        </svg>
                    </div>
                </div>

                <div class="product-modal-content">
                    <h2 class="product-modal-title">{{ product.naam }}</h2>

                    <p v-if="product.beschrijving" class="product-modal-description">
                        {{ product.beschrijving }}
                    </p>

                    <div v-if="ingredientList" class="product-modal-ingredients">
                        <strong>Ingrediënten:</strong> {{ ingredientList }}
                    </div>

                    <div v-if="product.recipe_details" class="product-modal-recipe-details">
                        <button class="recipe-details-toggle" @click="showDetails = !showDetails">
                            Samenstelling <span class="toggle-arrow">{{ showDetails ? '▲' : '▼' }}</span>
                        </button>
                        <div v-if="showDetails" class="recipe-details-content">
                            <div v-if="product.recipe_details.volkoren_pct > 0" class="recipe-detail-row recipe-detail-volkoren">
                                <span>Volkoren</span>
                                <span>{{ product.recipe_details.volkoren_pct }}%</span>
                            </div>
                            <template v-if="product.recipe_details.grains && product.recipe_details.grains.length > 0">
                                <div class="recipe-detail-subtitle">Meelsoorten</div>
                                <div v-for="grain in product.recipe_details.grains" :key="grain.name" class="recipe-detail-row">
                                    <span>{{ grain.name }}</span>
                                    <span>{{ grain.pct }}%</span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div v-if="hasVariants" class="product-modal-variants">
                        <div v-for="v in product.variants" :key="v.id" class="product-modal-variant">
                            <span class="variant-gewicht">{{ v.gewicht }}g</span>
                            <span class="variant-prijs">{{ formatPrice(v.prijs) }}</span>
                        </div>
                    </div>
                    <div v-else-if="product.prijs" class="product-modal-price">
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
        position: relative;
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
        position: relative;
    }
    .btn-add-cart {
        position: absolute;
        top: 6px;
        right: 6px;
        width: 28px;
        height: 28px;
        border: none;
        border-radius: 4px;
        background: #2d5a27;
        color: white;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        transition: all 0.15s ease;
    }
    .btn-add-cart:hover {
        background: #3d7a37;
        transform: scale(1.1);
    }
    .btn-add-cart svg {
        width: 14px;
        height: 14px;
    }
    .btn-add-cart .plus {
        position: absolute;
        top: 2px;
        right: 2px;
        font-size: 10px;
        font-weight: bold;
        line-height: 1;
    }
    .product-card-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s ease;
    }
    .product-card-placeholder {
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, #f5ebe0, #e8d5c0);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .product-card-placeholder svg {
        width: 60%;
        height: 60%;
        opacity: 0.8;
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
    
    /* Variant row styling */
    .product-card-variant-row {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-top: 0.5rem;
        flex-wrap: wrap;
    }
    .product-card-variant-row .variant-select {
        flex: 1;
        min-width: 120px;
        padding: 0.4rem 0.5rem;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 0.85rem;
        color: var(--color-crust-dark);
        background: white;
        cursor: pointer;
    }
    .product-card-variant-row .variant-select:focus {
        outline: none;
        border-color: var(--color-wheat);
    }
    .add-qty-group {
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }
    .qty-btn-small {
        width: 26px;
        height: 26px;
        border: 1px solid #ddd;
        border-radius: 4px;
        background: white;
        color: #333;
        font-size: 1rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .qty-btn-small:hover {
        background: var(--color-parchment);
        border-color: var(--color-crust);
    }
    .qty-input-small {
        width: 40px;
        text-align: center;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 0.25rem;
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--color-crust-dark);
        -moz-appearance: textfield;
    }
    .qty-input-small::-webkit-outer-spin-button,
    .qty-input-small::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
    .btn-add {
        width: 32px;
        height: 32px;
        border: none;
        border-radius: 6px;
        background: var(--color-crust);
        color: white;
        font-size: 1.2rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.15s ease;
    }
    .btn-add:hover {
        background: var(--color-crust-dark);
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
    .product-modal-placeholder {
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, #f5ebe0, #e8d5c0);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .product-modal-placeholder svg {
        width: 40%;
        opacity: 0.8;
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
        margin-bottom: 0.5rem;
    }
    .product-modal-ingredients strong {
        color: var(--color-crust);
    }
    .product-modal-recipe-details {
        margin-bottom: 1rem;
    }
    .recipe-details-toggle {
        background: none;
        border: none;
        color: var(--color-crust);
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        padding: 0.35rem 0;
        display: flex;
        align-items: center;
        gap: 0.35rem;
        text-decoration: underline;
        text-underline-offset: 3px;
    }
    .recipe-details-toggle:hover {
        color: var(--color-terracotta);
    }
    .toggle-arrow {
        font-size: 0.7rem;
    }
    .recipe-details-content {
        padding: 0.6rem 0.9rem;
        background: var(--color-parchment);
        border-radius: 8px;
        margin-top: 0.25rem;
    }
    .recipe-detail-subtitle {
        font-size: 0.75rem;
        font-weight: 700;
        color: var(--color-crust);
        text-transform: uppercase;
        letter-spacing: 0.06em;
        margin: 0.5rem 0 0.2rem 0;
    }
    .recipe-detail-row {
        display: flex;
        justify-content: space-between;
        padding: 0.2rem 0;
        font-size: 0.875rem;
        color: var(--color-stone);
        border-bottom: 1px solid rgba(139,90,43,0.08);
    }
    .recipe-detail-row:last-child {
        border-bottom: none;
    }
    .recipe-detail-volkoren {
        color: var(--color-crust);
        font-weight: 600;
        margin-bottom: 0.1rem;
    }
    .product-modal-price {
        font-family: var(--font-display);
        font-size: 1.35rem;
        font-weight: 700;
        color: var(--color-crust);
    }
    .product-modal-variants {
        margin-top: 1rem;
    }
    .product-modal-variant {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0.75rem;
        background: var(--color-parchment);
        border-radius: 6px;
        margin-bottom: 0.5rem;
    }
    .product-modal-variant .variant-gewicht {
        color: var(--color-stone);
    }
    .product-modal-variant .variant-prijs {
        font-weight: 600;
        color: var(--color-crust);
    }
`;

if (typeof document !== 'undefined') {
    const styleEl = document.createElement('style');
    styleEl.textContent = productCardStyles;
    document.head.appendChild(styleEl);
}
