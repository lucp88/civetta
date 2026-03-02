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
            addQuantity: 1,
            addedToCart: false
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
            <button v-if="hasVariants && showQuantity" class="btn-add-cart" :class="{ 'btn-added': addedToCart }" @click.stop="addToCart" :title="addedToCart ? 'Toegevoegd!' : 'Toevoegen aan bestelling'">
                <svg v-if="addedToCart" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                    <path d="M20 6L9 17l-5-5"/>
                </svg>
                <template v-else>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M5 7h14l-1.5 9h-11L5 7z"/>
                        <path d="M5 7l-.5-3H2"/>
                        <path d="M8 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/>
                        <path d="M16 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/>
                    </svg>
                    <span class="plus">+</span>
                </template>
            </button>
            <transition name="card-toast">
                <div v-if="addedToCart" class="card-added-toast" @click.stop>
                    Toegevoegd aan wagen
                </div>
            </transition>
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
                                {{ variantLabel(v) }}
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
        
        variantLabel(v) {
            let label = '';
            if (v.naam && v.gewicht) label = v.naam + ' - ' + v.gewicht + 'g';
            else if (v.naam) label = v.naam;
            else label = v.gewicht + 'g';
            if (parseFloat(v.prijs) > 0) label += ' — ' + this.formatPrice(v.prijs);
            return label;
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
                this.addedToCart = true;
                this.addQuantity = 1;
                setTimeout(() => { this.addedToCart = false; }, 1500);
            }
        }
    }
};

const ProductDetailModal = {
    props: {
        product: {
            type: Object,
            default: null
        },
        showAddToCart: {
            type: Boolean,
            default: false
        },
        allAllergens: {
            type: Array,
            default: () => []
        },
        cartCounts: {
            type: Object,
            default: () => ({ simple: 0, variants: {} })
        }
    },

    emits: ['close', 'add-to-cart', 'add-simple'],

    data() {
        return {
            showDetails: false,
            showAllergens: true,
            variantQty: {},
            addedVariantId: null,
            simpleQty: 1,
            simpleAdded: false
        };
    },

    watch: {
        product(newVal) {
            if (newVal) {
                this.showDetails = false;
                this.showAllergens = true;
                this.addedVariantId = null;
                this.simpleQty = 1;
                this.variantQty = {};
                if (newVal.variants) {
                    newVal.variants.forEach(v => { this.variantQty[v.id] = 1; });
                }
            }
        }
    },

    computed: {
        hasVariants() {
            return this.product && this.product.variants && this.product.variants.length > 0;
        },
        ingredientList() {
            if (!this.product) return null;
            return this.product.ingredienten_recipe || this.product.ingredienten || null;
        },
        ingredientItems() {
            if (!this.product) return null;
            return this.product.ingredienten_items || null;
        },
        allergenList() {
            const items = this.ingredientItems;
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
                        <strong>Ingrediënten:</strong>
                        <template v-if="ingredientItems">
                            <template v-for="(item, idx) in ingredientItems"><strong v-if="item.allergeen">{{ item.name }}</strong><template v-else>{{ item.name }}</template><template v-if="idx < ingredientItems.length - 1">, </template></template>
                        </template>
                        <template v-else>{{ ingredientList }}</template>
                        <div v-if="ingredientList && ingredientList.includes('*')" class="product-modal-bio-note">* Biologisch product</div>
                        <div v-if="allergenList.length > 0" class="product-modal-bio-note">Vetgedrukt = allergeen</div>
                    </div>

                    <div v-if="allergenList.length > 0 || traceAllergens.length > 0" class="product-modal-recipe-details">
                        <button class="recipe-details-toggle" @click="showAllergens = !showAllergens">
                            Allergenen <span class="toggle-arrow">{{ showAllergens ? '▲' : '▼' }}</span>
                        </button>
                        <div v-if="showAllergens" class="recipe-details-content">
                            <div v-for="name in allergenList" :key="name" class="recipe-detail-row">
                                <span><strong>{{ name }}</strong></span>
                            </div>
                            <div v-if="traceAllergens.length > 0" class="recipe-detail-row" style="margin-top: 0.5rem; font-style: italic; font-size: 0.85rem; border-bottom: none;">
                                Kan sporen bevatten van: {{ traceAllergens.join(', ').toLowerCase() }}
                            </div>
                        </div>
                    </div>

                    <div v-if="product.recipe_details" class="product-modal-recipe-details">
                        <button class="recipe-details-toggle" @click="showDetails = !showDetails">
                            Specificaties <span class="toggle-arrow">{{ showDetails ? '▲' : '▼' }}</span>
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
                        <div v-for="v in product.variants" :key="v.id" class="product-modal-variant" :class="{ 'variant-added': addedVariantId === v.id }">
                            <div class="variant-info">
                                <span class="variant-gewicht">{{ v.naam ? v.naam + ' - ' : '' }}{{ v.gewicht }}g</span>
                                <span class="variant-prijs">{{ formatPrice(v.prijs) }}</span>
                            </div>
                            <div v-if="showAddToCart" class="variant-actions">
                                <span v-if="cartCounts.variants[v.id]" class="cart-count-hint">{{ cartCounts.variants[v.id] }} in wagen</span>
                                <div class="variant-qty-group">
                                    <button class="variant-qty-btn" @click="changeQty(v.id, -1)">-</button>
                                    <input type="number" class="variant-qty-input" :value="variantQty[v.id] || 1" @change="setQty(v.id, $event.target.value)" min="1">
                                    <button class="variant-qty-btn" @click="changeQty(v.id, 1)">+</button>
                                </div>
                                <button class="variant-add-btn" :class="{ added: addedVariantId === v.id }" @click="addVariant(v)">
                                    <span v-if="addedVariantId === v.id" class="added-label">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg>
                                        Toegevoegd
                                    </span>
                                    <span v-else class="add-label">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 7h14l-1.5 9h-11L5 7z"/><path d="M5 7l-.5-3H2"/></svg>
                                        Toevoegen
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <template v-else>
                        <div v-if="product.prijs !== undefined && product.prijs !== null" class="product-modal-price">
                            {{ formatPrice(product.prijs) }}
                        </div>
                        <div v-if="showAddToCart && (product.prijs !== undefined && product.prijs !== null)" class="modal-simple-row">
                            <span v-if="cartCounts.simple > 0" class="cart-count-hint">{{ cartCounts.simple }} in wagen</span>
                            <div class="modal-simple-actions">
                                <div class="variant-qty-group">
                                    <button class="variant-qty-btn" @click="simpleQty = Math.max(1, simpleQty - 1)">-</button>
                                    <input type="number" class="variant-qty-input" v-model.number="simpleQty" min="1">
                                    <button class="variant-qty-btn" @click="simpleQty++">+</button>
                                </div>
                                <button class="modal-add-simple-btn" :class="{ added: simpleAdded }" @click="addSimple">
                                    <template v-if="simpleAdded">Toegevoegd ✓</template>
                                    <template v-else>Toevoegen</template>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    `,

    methods: {
        formatPrice(amount) {
            return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(amount);
        },
        changeQty(variantId, delta) {
            const current = this.variantQty[variantId] || 1;
            this.variantQty[variantId] = Math.max(1, current + delta);
            this.variantQty = { ...this.variantQty };
        },
        setQty(variantId, value) {
            this.variantQty[variantId] = Math.max(1, parseInt(value) || 1);
            this.variantQty = { ...this.variantQty };
        },
        addVariant(variant) {
            const qty = this.variantQty[variant.id] || 1;
            this.$emit('add-to-cart', { product: this.product, variant, quantity: qty });
            this.addedVariantId = variant.id;
            setTimeout(() => { this.addedVariantId = null; }, 1200);
        },
        addSimple() {
            this.$emit('add-simple', { product: this.product, quantity: this.simpleQty });
            this.simpleAdded = true;
            setTimeout(() => { this.simpleAdded = false; }, 1200);
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
        width: 85px;
        min-height: 85px;
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
    .btn-add-cart.btn-added {
        background: #2e7d32;
        transform: scale(1.15);
        box-shadow: 0 2px 8px rgba(46,125,50,0.4);
    }
    .card-added-toast {
        position: absolute;
        top: 6px;
        right: 40px;
        background: #2e7d32;
        color: white;
        font-size: 0.7rem;
        font-weight: 600;
        padding: 4px 8px;
        border-radius: 4px;
        white-space: nowrap;
        z-index: 5;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }
    .card-toast-enter-active {
        animation: toastIn 0.25s ease;
    }
    .card-toast-leave-active {
        animation: toastOut 0.2s ease;
    }
    @keyframes toastIn {
        from { opacity: 0; transform: translateX(8px); }
        to { opacity: 1; transform: translateX(0); }
    }
    @keyframes toastOut {
        from { opacity: 1; transform: translateX(0); }
        to { opacity: 0; transform: translateX(8px); }
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
        min-width: 0;
        padding: 0.6rem 0.75rem;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 0.2rem;
    }
    .product-card-title {
        margin: 0;
        font-size: 0.95rem;
        font-weight: 600;
        color: var(--color-crust-dark);
        cursor: pointer;
        transition: color 0.2s ease;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
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
        gap: 0.4rem;
        margin-top: 0.5rem;
    }
    .product-card-variant-row .variant-select {
        flex: 1;
        min-width: 0;
        padding: 0.35rem 0.4rem;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 0.8rem;
        color: var(--color-crust-dark);
        background: white;
        cursor: pointer;
        text-overflow: ellipsis;
    }
    .product-card-variant-row .variant-select:focus {
        outline: none;
        border-color: var(--color-wheat);
    }
    .add-qty-group {
        display: flex;
        align-items: center;
        gap: 0.2rem;
        flex-shrink: 0;
    }
    .qty-btn-small {
        width: 24px;
        height: 24px;
        border: 1px solid #ddd;
        border-radius: 4px;
        background: white;
        color: #333;
        font-size: 0.9rem;
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
        width: 32px;
        text-align: center;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 0.2rem;
        font-size: 0.85rem;
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
    .product-modal-bio-note {
        font-size: 0.8rem;
        color: var(--color-sage);
        margin-top: 0.3rem;
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
        margin-top: 1.25rem;
        padding-top: 1rem;
        border-top: 1px solid var(--color-parchment);
    }
    .product-modal-variant {
        padding: 0.6rem 0.75rem;
        background: var(--color-parchment);
        border-radius: 8px;
        margin-bottom: 0.5rem;
        transition: background 0.3s ease;
    }
    .product-modal-variant.variant-added {
        background: #e8f5e9;
    }
    .product-modal-variant .variant-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.4rem;
    }
    .product-modal-variant .variant-gewicht {
        color: var(--color-stone);
        font-size: 0.95rem;
    }
    .product-modal-variant .variant-prijs {
        font-weight: 600;
        color: var(--color-crust);
    }
    .variant-actions {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .variant-qty-group {
        display: flex;
        align-items: center;
        gap: 0.2rem;
    }
    .variant-qty-btn {
        width: 28px;
        height: 28px;
        border: 1px solid #ddd;
        border-radius: 6px;
        background: white;
        color: var(--color-crust);
        font-size: 1rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.15s ease;
    }
    .variant-qty-btn:hover {
        background: var(--color-crust);
        color: white;
        border-color: var(--color-crust);
    }
    .variant-qty-input {
        width: 40px;
        text-align: center;
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--color-crust-dark);
        border: 1px solid #ddd;
        border-radius: 6px;
        padding: 0.2rem;
        background: white;
        -moz-appearance: textfield;
    }
    .variant-qty-input::-webkit-outer-spin-button,
    .variant-qty-input::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
    .variant-qty-input:focus {
        outline: none;
        border-color: var(--color-wheat);
    }
    .variant-add-btn {
        flex: 1;
        border: none;
        border-radius: 8px;
        background: var(--color-crust);
        color: white;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0.45rem 0.75rem;
        transition: all 0.2s ease;
        font-family: var(--font-body);
    }
    .variant-add-btn:hover {
        background: var(--color-crust-dark);
    }
    .variant-add-btn.added {
        background: #2d5a27;
    }
    .variant-add-btn .add-label,
    .variant-add-btn .added-label {
        display: flex;
        align-items: center;
        gap: 0.35rem;
    }
    .modal-simple-actions {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-top: 1rem;
    }
    .modal-add-simple-btn {
        flex: 1;
        padding: 0.6rem 0.75rem;
        border: none;
        border-radius: 8px;
        background: var(--color-crust);
        color: white;
        font-family: var(--font-body);
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s ease;
    }
    .modal-add-simple-btn:hover {
        background: var(--color-crust-dark);
    }
    .modal-add-simple-btn.added {
        background: #2d5a27;
    }
    .modal-simple-row {
        margin-top: 1rem;
    }
    .cart-count-hint {
        font-size: 0.8rem;
        color: #2d5a27;
        font-weight: 600;
        white-space: nowrap;
    }
`;

if (typeof document !== 'undefined') {
    const styleEl = document.createElement('style');
    styleEl.textContent = productCardStyles;
    document.head.appendChild(styleEl);
}
