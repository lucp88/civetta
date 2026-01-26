const { createApp } = Vue;

createApp({
    data() {
        return {
            loading: true,
            orders: [],
            favorites: [],
            recurringGroups: [],
            allProducts: [],
            selectedOrder: null,
            selectedRecurringGroup: null,
            openDropdownId: null,
            editRecurringGroup: null,
            editSingleDelivery: null,
            editSingleGroupId: null,
            savingEdit: false,
            editingOrder: false,
            savingOrder: false,
            orderEditForm: {
                notes: '',
                delivery_address: '',
                items: {}
            },
            editForm: {
                name: '',
                notes: '',
                items: []
            },
            editSingleForm: {
                notes: '',
                items: []
            },
            detailForm: {
                name: '',
                notes: '',
                items: []
            },
            originalDetailForm: null,
            editingName: false,
            editingNotes: false,
            editingProducts: false,
            tempNotes: '',
            tempItems: [],
            orderActionsDropdownOpen: false,
            bankgegevens: null,
            showPaymentPanel: false,
            paymentConfirmed: false,
            selectedBank: null,
            mollieQrLoading: false,
            mollieQrError: null,
            mollieCheckoutUrl: null,
            bankOptions: [
                { id: 'ing', name: 'ING', useEpc: true },
                { id: 'bunq', name: 'Bunq', useEpc: true },
                { id: 'knab', name: 'Knab', useEpc: true },
                { id: 'asn', name: 'ASN Bank', useEpc: true },
                { id: 'sns', name: 'SNS Bank', useEpc: true },
                { id: 'rabobank', name: 'Rabobank', useEpc: false },
                { id: 'abnamro', name: 'ABN AMRO', useEpc: false },
                { id: 'triodos', name: 'Triodos', useEpc: false },
                { id: 'other', name: 'Andere bank', useEpc: false }
            ],
            showCancelledOrders: false,
            showCancelDialog: false,
            cancellingOrder: false,
            filters: {
                paid: true,
                pending: true,
                factuur: true,
                ideal: true
            }
        };
    },
    
    computed: {
        lopendeBestellingen() {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            return this.orders
                .filter(o => Number(o.is_cancelled) === 0 && new Date(o.delivery_date) >= today)
                .sort((a, b) => new Date(a.delivery_date) - new Date(b.delivery_date));
        },
        afgerondeBestellingen() {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            return this.orders
                .filter(o => Number(o.is_cancelled) === 1 || new Date(o.delivery_date) < today)
                .sort((a, b) => new Date(b.delivery_date) - new Date(a.delivery_date));
        },
        afgerondeBestellingenFiltered() {
            if (this.showCancelledOrders) {
                return this.afgerondeBestellingen;
            }
            return this.afgerondeBestellingen.filter(o => Number(o.is_cancelled) !== 1);
        },
        filteredLopendeBestellingen() {
            return this.lopendeBestellingen.filter(o => this.matchesFilters(o));
        },
        filteredAfgerondeBestellingen() {
            return this.afgerondeBestellingenFiltered.filter(o => this.matchesFilters(o));
        },
        cancelledOrdersCount() {
            return this.afgerondeBestellingen.filter(o => Number(o.is_cancelled) === 1).length;
        },
        detailFormChanged() {
            if (!this.originalDetailForm) return false;
            return JSON.stringify(this.detailForm) !== JSON.stringify(this.originalDetailForm);
        },
        editProductsList() {
            const list = [];
            const addedKeys = new Set();
            
            for (const [key, item] of Object.entries(this.orderEditForm.items)) {
                if (item.quantity > 0) {
                    list.push({
                        key: key,
                        naam: item.original_name || key,
                        prijs: item.unit_price
                    });
                    addedKeys.add(key);
                }
            }
            
            for (const product of this.allProducts) {
                const key = product.naam.toLowerCase();
                if (!addedKeys.has(key)) {
                    list.push({
                        key: key,
                        naam: product.naam,
                        prijs: parseFloat(product.prijs) || 0
                    });
                    addedKeys.add(key);
                }
            }
            
            return list;
        }
    },
    
    async mounted() {
        const stored = sessionStorage.getItem('businessAccount');
        if (!stored) {
            window.location.href = 'login-bedrijven.html';
            return;
        }
        await Promise.all([
            this.loadOrders(),
            this.loadFavorites(),
            this.loadRecurringOrders(),
            this.loadProducts()
        ]);
        this.loading = false;
        
        document.addEventListener('click', () => {
            this.openDropdownId = null;
            this.orderActionsDropdownOpen = false;
        });
    },
    
    methods: {
        toggleFilter(filter) {
            this.filters[filter] = !this.filters[filter];
        },
        
        matchesFilters(order) {
            const isPaid = order.payment_status === 'paid';
            const isPending = order.payment_status === 'pending';
            const isFactuur = order.payment_type === 'factuur' || order.payment_type === 'invoice';
            const isIdeal = order.payment_type === 'ideal' || order.payment_type === 'mollie_direct';
            
            const statusMatch = (this.filters.paid && isPaid) || (this.filters.pending && isPending);
            const typeMatch = (this.filters.factuur && isFactuur) || (this.filters.ideal && isIdeal);
            
            return statusMatch && typeMatch;
        },
        
        async loadOrders() {
            try {
                const response = await fetch('api/business-orders.php');
                const data = await response.json();
                if (data.success) {
                    this.orders = data.orders;
                    this.bankgegevens = data.bankgegevens || null;
                }
            } catch (error) {
                console.error('Kon bestellingen niet laden');
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
        
        async loadRecurringOrders() {
            try {
                const response = await fetch('api/business-recurring.php');
                const data = await response.json();
                console.log('Recurring API response:', data);
                if (data.success) {
                    this.recurringGroups = data.recurring_groups || [];
                }
            } catch (error) {
                console.error('Kon terugkerende bestellingen niet laden', error);
            }
        },
        
        async extendRecurring(group) {
            this.openDropdownId = null;
            if (!confirm(`Wil je deze terugkerende bestelling verlengen met 3 maanden?`)) return;
            try {
                const response = await fetch('api/business-recurring.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        recurring_group_id: group.recurring_group_id, 
                        action: 'extend' 
                    })
                });
                const data = await response.json();
                if (data.success) {
                    alert(data.message);
                    await this.loadRecurringOrders();
                } else {
                    alert(data.error || 'Kon niet verlengen');
                }
            } catch (error) {
                console.error('Kon niet verlengen', error);
                alert('Er ging iets mis bij het verlengen');
            }
        },
        
        async stopRecurring(group) {
            this.openDropdownId = null;
            if (!confirm('Weet je zeker dat je deze terugkerende bestelling wilt stoppen? Alle toekomstige leveringen worden geannuleerd.')) return;
            try {
                const response = await fetch('api/business-recurring.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        recurring_group_id: group.recurring_group_id, 
                        action: 'stop' 
                    })
                });
                const data = await response.json();
                if (data.success) {
                    alert(data.message);
                    await this.loadRecurringOrders();
                } else {
                    alert(data.error || 'Kon niet stoppen');
                }
            } catch (error) {
                console.error('Kon niet stoppen', error);
            }
        },
        
        showRecurringDetail(group) {
            this.openDropdownId = null;
            this.selectedRecurringGroup = group;
            this.detailForm = {
                name: group.name || '',
                notes: '',
                items: (group.items || []).map(i => ({ ...i }))
            };
            this.originalDetailForm = JSON.parse(JSON.stringify(this.detailForm));
            this.editingName = false;
            this.editingNotes = false;
            this.editingProducts = false;
        },
        
        closeDetailModal() {
            if (this.detailFormChanged) {
                if (!confirm('Je hebt onopgeslagen wijzigingen. Weet je zeker dat je wilt sluiten?')) {
                    return;
                }
            }
            this.selectedRecurringGroup = null;
            this.editingName = false;
            this.editingNotes = false;
            this.editingProducts = false;
        },
        
        startEditName() {
            this.editingName = true;
            this.$nextTick(() => {
                this.$refs.nameInput?.focus();
            });
        },
        
        stopEditName() {
            this.editingName = false;
        },
        
        startEditNotes() {
            this.tempNotes = this.detailForm.notes;
            this.editingNotes = true;
            this.$nextTick(() => {
                this.$refs.notesInput?.focus();
            });
        },
        
        stopEditNotes() {
            this.editingNotes = false;
        },
        
        cancelEditNotes() {
            this.detailForm.notes = this.tempNotes;
            this.editingNotes = false;
        },
        
        startEditProducts() {
            this.tempItems = JSON.parse(JSON.stringify(this.detailForm.items));
            this.editingProducts = true;
        },
        
        stopEditProducts() {
            this.editingProducts = false;
        },
        
        cancelEditProducts() {
            this.detailForm.items = this.tempItems;
            this.editingProducts = false;
        },
        
        decreaseQty(item) {
            if (item.quantity > 1) item.quantity--;
        },
        
        calculateDetailTotal() {
            return this.detailForm.items.reduce((sum, i) => sum + (i.quantity * i.unit_price), 0);
        },
        
        async saveDetailChanges() {
            if (this.detailForm.items.length === 0) {
                alert('Voeg minimaal één product toe');
                return;
            }
            
            this.savingEdit = true;
            try {
                const response = await fetch('api/business-recurring.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        recurring_group_id: this.selectedRecurringGroup.recurring_group_id,
                        action: 'update',
                        name: this.detailForm.name,
                        notes: this.detailForm.notes,
                        items: this.detailForm.items.filter(i => i.quantity > 0)
                    })
                });
                const data = await response.json();
                if (data.success) {
                    alert(data.message);
                    this.originalDetailForm = JSON.parse(JSON.stringify(this.detailForm));
                    await this.loadRecurringOrders();
                    const updatedGroup = this.recurringGroups.find(g => g.recurring_group_id === this.selectedRecurringGroup.recurring_group_id);
                    if (updatedGroup) {
                        this.selectedRecurringGroup = updatedGroup;
                    }
                } else {
                    alert(data.error || 'Kon niet opslaan');
                }
            } catch (error) {
                console.error('Fout bij opslaan', error);
                alert('Er ging iets mis');
            } finally {
                this.savingEdit = false;
            }
        },
        
        toggleDropdown(groupId) {
            this.openDropdownId = this.openDropdownId === groupId ? null : groupId;
        },
        
        closeDropdowns() {
            this.openDropdownId = null;
        },
        
        getDayLabelFull(day) {
            const days = ['Zondag', 'Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag'];
            return days[day] || '';
        },
        
        
        openEditSingleDelivery(delivery, group) {
            this.editSingleGroupId = group.recurring_group_id;
            this.editSingleForm.notes = '';
            this.editSingleForm.items = (group.items || []).map(i => ({
                product_name: i.product_name,
                quantity: i.quantity,
                unit_price: i.unit_price
            }));
            this.editSingleDelivery = delivery;
        },
        
        decreaseSingleQty(item) {
            if (item.quantity > 1) item.quantity--;
        },
        
        calculateSingleTotal() {
            return this.editSingleForm.items.reduce((sum, i) => sum + (i.quantity * i.unit_price), 0);
        },
        
        async saveEditSingle() {
            if (this.editSingleForm.items.length === 0) {
                alert('Voeg minimaal één product toe');
                return;
            }
            
            this.savingEdit = true;
            try {
                const response = await fetch('api/business-recurring.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        recurring_group_id: this.editSingleGroupId,
                        action: 'update_single',
                        order_id: this.editSingleDelivery.id,
                        notes: this.editSingleForm.notes,
                        items: this.editSingleForm.items.filter(i => i.quantity > 0)
                    })
                });
                const data = await response.json();
                if (data.success) {
                    alert(data.message);
                    const groupId = this.editSingleGroupId;
                    this.editSingleDelivery = null;
                    await this.loadRecurringOrders();
                    const updatedGroup = this.recurringGroups.find(g => g.recurring_group_id === groupId);
                    if (updatedGroup) {
                        this.selectedRecurringGroup = updatedGroup;
                        this.detailForm.items = (updatedGroup.items || []).map(i => ({ ...i }));
                    }
                } else {
                    alert(data.error || 'Kon niet opslaan');
                }
            } catch (error) {
                console.error('Fout bij opslaan', error);
                alert('Er ging iets mis');
            } finally {
                this.savingEdit = false;
            }
        },
        
        async skipDelivery(delivery, group) {
            if (!confirm(`Weet je zeker dat je de levering van ${this.formatDateShort(delivery.delivery_date)} wilt overslaan?`)) return;
            
            try {
                const response = await fetch('api/business-recurring.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        recurring_group_id: group.recurring_group_id,
                        action: 'update_single',
                        order_id: delivery.id,
                        skip: true
                    })
                });
                const data = await response.json();
                if (data.success) {
                    alert(data.message);
                    this.selectedRecurringGroup = null;
                    await this.loadRecurringOrders();
                } else {
                    alert(data.error || 'Kon levering niet overslaan');
                }
            } catch (error) {
                console.error('Fout bij overslaan', error);
                alert('Er ging iets mis');
            }
        },
        
        getFrequencyLabel(freq) {
            const labels = { 'weekly': 'Wekelijks', 'biweekly': 'Tweewekelijks', 'monthly': 'Maandelijks' };
            return labels[freq] || freq;
        },
        
        getDayLabel(day) {
            const days = ['Zo', 'Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za'];
            return days[day] || '';
        },
        
        showOrderDetail(order) {
            this.selectedOrder = order;
            this.editingOrder = false;
            this.orderActionsDropdownOpen = false;
        },
        
        closeOrderModal() {
            this.selectedOrder = null;
            this.editingOrder = false;
            this.orderActionsDropdownOpen = false;
        },
        
        toggleOrderActionsDropdown(event) {
            if (event) event.stopPropagation();
            this.orderActionsDropdownOpen = !this.orderActionsDropdownOpen;
        },
        
        openPaymentPanel() {
            this.showPaymentPanel = true;
            this.paymentConfirmed = false;
            this.selectedBank = null;
            this.mollieQrError = null;
            this.mollieCheckoutUrl = null;
        },
        
        closePaymentPanel() {
            this.showPaymentPanel = false;
            this.paymentConfirmed = false;
            this.selectedBank = null;
        },
        
        selectBank(bank) {
            this.selectedBank = bank;
            if (bank.useEpc) {
                this.$nextTick(() => {
                    this.generateQRCode();
                });
            } else {
                this.generateMolliePayment();
            }
        },
        
        async generateMolliePayment() {
            this.mollieQrLoading = true;
            this.mollieQrError = null;
            
            try {
                const response = await fetch('api/mollie-qr.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        order_id: this.selectedOrder.id,
                        amount: this.selectedOrder.total_amount,
                        reference: this.getPaymentReference(this.selectedOrder),
                        bank_id: this.selectedBank?.id || ''
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    this.mollieCheckoutUrl = data.checkout_url;
                    this.$nextTick(() => {
                        this.generateMollieQRCode(data.checkout_url);
                    });
                } else {
                    this.mollieQrError = data.error || 'Kon betaling niet aanmaken';
                }
            } catch (error) {
                console.error('Mollie payment error:', error);
                this.mollieQrError = 'Er ging iets mis bij het aanmaken van de betaling';
            } finally {
                this.mollieQrLoading = false;
            }
        },
        
        generateMollieQRCode(url) {
            if (typeof QRCode === 'undefined') {
                console.error('QRCode library niet geladen');
                return;
            }
            
            const container = this.$refs.mollieQrContainer;
            if (!container) return;
            
            container.innerHTML = '';
            
            new QRCode(container, {
                text: url,
                width: 200,
                height: 200,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        },
        
        closePaymentPanelOverlay(event) {
            if (event.target.classList.contains('modal-overlay')) {
                this.closePaymentPanel();
            }
        },
        
        closePaymentPanelAndReturn() {
            this.showPaymentPanel = false;
            this.paymentConfirmed = false;
            this.selectedOrder = null;
        },
        
        confirmPayment() {
            this.paymentConfirmed = true;
        },
        
        getPaymentReference(order) {
            const invoiceNr = order.eboekhouden_factuurnummer || ('F' + new Date().getFullYear() + '-' + String(order.id).padStart(4, '0'));
            return invoiceNr;
        },
        
        generateEPCString(order) {
            if (!this.bankgegevens) return '';
            
            const iban = this.bankgegevens.iban.replace(/\s/g, '');
            const bic = this.bankgegevens.bic || '';
            const naam = this.bankgegevens.naam.substring(0, 70);
            const bedrag = 'EUR' + parseFloat(order.total_amount).toFixed(2);
            const kenmerk = this.getPaymentReference(order);
            
            const lines = [
                'BCD',
                '002',
                '1',
                'SCT',
                bic,
                naam,
                iban,
                bedrag,
                '',
                kenmerk,
                '',
                ''
            ];
            
            return lines.join('\n');
        },
        
        generateQRCode() {
            if (!this.bankgegevens || !this.selectedOrder) return;
            if (typeof QRCode === 'undefined') {
                console.error('QRCode library niet geladen');
                return;
            }
            
            const container = this.$refs.qrContainer;
            if (!container) return;
            
            container.innerHTML = '';
            
            const epcString = this.generateEPCString(this.selectedOrder);
            
            new QRCode(container, {
                text: epcString,
                width: 200,
                height: 200,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        },
        
        async loadProducts() {
            try {
                const response = await fetch('api/products.php');
                const data = await response.json();
                if (data.success) {
                    this.allProducts = data.products;
                }
            } catch (error) {
                console.error('Kon producten niet laden');
            }
        },
        
        canEditOrder(order) {
            if (Number(order.is_cancelled) === 1) return false;
            
            const inBereiding = ['wordt_bereid', 'onderweg', 'afgeleverd'].includes(order.delivery_status);
            if (inBereiding) return false;
            
            const isMolliePaid = order.payment_status === 'paid' && 
                                 (order.payment_type === 'ideal' || order.payment_type === 'mollie_direct');
            if (isMolliePaid) return false;
            
            if (typeof order.can_edit !== 'undefined') {
                return order.can_edit;
            }
            if (order.edit_deadline) {
                return new Date(order.edit_deadline) > new Date();
            }
            const deliveryDate = new Date(order.delivery_date);
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            tomorrow.setHours(0, 0, 0, 0);
            return deliveryDate >= tomorrow;
        },
        
        canCancelOrder(order) {
            if (Number(order.is_cancelled) === 1) return false;
            
            const inBereiding = ['wordt_bereid', 'onderweg', 'afgeleverd'].includes(order.delivery_status);
            if (inBereiding) return false;
            
            const isMolliePaid = order.payment_status === 'paid' && 
                                 (order.payment_type === 'ideal' || order.payment_type === 'mollie_direct');
            if (isMolliePaid) return true;
            
            return this.canEditOrder(order);
        },
        
        startEditOrder() {
            const itemsMap = {};
            (this.selectedOrder.items || []).forEach(item => {
                const key = item.product_name.toLowerCase();
                itemsMap[key] = {
                    quantity: parseInt(item.quantity) || 0,
                    unit_price: parseFloat(item.unit_price) || 0,
                    original_name: item.product_name
                };
            });
            
            this.orderEditForm = {
                notes: this.selectedOrder.notes || '',
                delivery_address: this.selectedOrder.delivery_address || '',
                items: itemsMap
            };
            this.editingOrder = true;
        },
        
        cancelEditOrder() {
            this.editingOrder = false;
        },
        
        getEditQtyByKey(key) {
            const item = this.orderEditForm.items[key];
            return item ? item.quantity : 0;
        },
        
        setEditQtyByKey(product, value) {
            const key = product.key;
            const qty = parseInt(value) || 0;
            if (qty > 0) {
                this.orderEditForm.items[key] = {
                    quantity: qty,
                    unit_price: parseFloat(product.prijs) || 0,
                    original_name: product.naam
                };
            } else {
                delete this.orderEditForm.items[key];
            }
        },
        
        increaseEditQtyByKey(product) {
            const current = this.getEditQtyByKey(product.key);
            this.setEditQtyByKey(product, current + 1);
        },
        
        decreaseEditQtyByKey(product) {
            const current = this.getEditQtyByKey(product.key);
            if (current > 0) {
                this.setEditQtyByKey(product, current - 1);
            }
        },
        
        calculateEditTotal() {
            let total = 0;
            for (const [name, item] of Object.entries(this.orderEditForm.items)) {
                total += item.quantity * item.unit_price;
            }
            return total;
        },
        
        async saveEditOrder() {
            const items = [];
            for (const [key, item] of Object.entries(this.orderEditForm.items)) {
                if (item.quantity > 0) {
                    const product = this.allProducts.find(p => p.naam.toLowerCase() === key);
                    items.push({
                        product_name: product ? product.naam : (item.original_name || key),
                        quantity: item.quantity,
                        unit_price: item.unit_price
                    });
                }
            }
            
            if (items.length === 0) {
                alert('Voeg minimaal één product toe');
                return;
            }
            
            this.savingOrder = true;
            try {
                const response = await fetch('api/business-orders.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        order_id: this.selectedOrder.id,
                        notes: this.orderEditForm.notes,
                        delivery_address: this.orderEditForm.delivery_address,
                        items: items
                    })
                });
                const data = await response.json();
                if (data.success) {
                    await this.loadOrders();
                    const updated = this.orders.find(o => o.id === this.selectedOrder.id);
                    if (updated) this.selectedOrder = updated;
                    this.editingOrder = false;
                } else {
                    alert(data.error || 'Kon niet opslaan');
                }
            } catch (error) {
                console.error('Fout bij opslaan', error);
                alert('Er ging iets mis');
            } finally {
                this.savingOrder = false;
            }
        },
        
        orderHasRefund(order) {
            if (!order) return false;
            return order.payment_status === 'paid' && 
                   (order.payment_type === 'ideal' || order.payment_type === 'mollie_direct') &&
                   order.mollie_payment_id;
        },
        
        getRefundStatusLabel(status) {
            const labels = {
                'queued': 'In wachtrij',
                'pending': 'In behandeling',
                'processing': 'Wordt verwerkt',
                'refunded': 'Terugbetaald'
            };
            return labels[status] || status || 'Onbekend';
        },
        
        getRefundStatusClass(status) {
            if (status === 'refunded') return 'refund-completed';
            if (['queued', 'pending', 'processing'].includes(status)) return 'refund-pending';
            return 'refund-unknown';
        },
        
        getRefundStatusIcon(status) {
            if (status === 'refunded') return 'bi bi-check-circle-fill';
            if (['queued', 'pending', 'processing'].includes(status)) return 'bi bi-hourglass-split';
            return 'bi bi-question-circle';
        },
        
        openCancelDialog() {
            this.orderActionsDropdownOpen = false;
            this.showCancelDialog = true;
        },
        
        closeCancelDialog() {
            this.showCancelDialog = false;
        },
        
        async confirmCancelOrder() {
            this.cancellingOrder = true;
            
            try {
                const response = await fetch('api/business-orders.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        order_id: this.selectedOrder.id,
                        action: 'cancel'
                    })
                });
                const data = await response.json();
                if (data.success) {
                    this.showCancelDialog = false;
                    await this.loadOrders();
                    this.selectedOrder = null;
                    alert(data.message);
                } else {
                    alert(data.error || 'Kon niet annuleren');
                }
            } catch (error) {
                console.error('Fout bij annuleren', error);
                alert('Er ging iets mis');
            } finally {
                this.cancellingOrder = false;
            }
        },
        
        async cancelOrder() {
            if (this.orderHasRefund(this.selectedOrder)) {
                this.openCancelDialog();
                return;
            }
            
            if (!confirm('Weet je zeker dat je deze bestelling wilt annuleren?')) return;
            
            try {
                const response = await fetch('api/business-orders.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        order_id: this.selectedOrder.id,
                        action: 'cancel'
                    })
                });
                const data = await response.json();
                if (data.success) {
                    await this.loadOrders();
                    this.selectedOrder = null;
                    alert(data.message);
                } else {
                    alert(data.error || 'Kon niet annuleren');
                }
            } catch (error) {
                console.error('Fout bij annuleren', error);
                alert('Er ging iets mis');
            }
        },
        
        useFavorite(fav) {
            sessionStorage.setItem('loadFavorite', JSON.stringify(fav));
            window.location.href = 'bestelling-plaatsen.html';
        },
        
        async deleteFavorite(id) {
            if (!confirm('Weet je zeker dat je deze favoriet wilt verwijderen?')) return;
            
            try {
                const response = await fetch('api/business-favorites.php', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await response.json();
                if (data.success) {
                    this.favorites = this.favorites.filter(f => f.id !== id);
                }
            } catch (error) {
                console.error('Kon favoriet niet verwijderen');
            }
        },
        
        reorder(order) {
            const items = order.items.map(item => ({
                product_name: item.product_name,
                quantity: item.quantity,
                unit_price: item.unit_price
            }));
            sessionStorage.setItem('reorderItems', JSON.stringify(items));
            window.location.href = 'bestelling-plaatsen.html';
        },
        
        getDisplayStatus(order) {
            if (Number(order.is_cancelled) === 1) return 'cancelled';
            if (order.payment_status === 'paid') return 'paid';
            if (order.invoice_status === 'gefactureerd') return 'invoiced';
            return 'bestelbon';
        },
        
        getStatusLabel(order) {
            if (Number(order.is_cancelled) === 1) return 'Geannuleerd';
            if (order.payment_status === 'paid') return 'Betaald';
            
            const deliveryLabels = {
                'geplaatst': 'Geplaatst',
                'wordt_bereid': 'Wordt bereid',
                'onderweg': 'Onderweg',
                'afgeleverd': 'Afgeleverd'
            };
            
            if (order.invoice_status === 'gefactureerd') {
                return 'Openstaand';
            }
            
            return deliveryLabels[order.delivery_status] || 'Geplaatst';
        },
        
        getPaymentTypeIcon(order) {
            if (order.payment_type === 'ideal') return 'bi bi-credit-card';
            if (order.invoice_status === 'gefactureerd') return 'bi bi-file-earmark-text';
            return 'bi bi-receipt';
        },
        
        getPaymentTypeLabel(type) {
            const labels = {
                'ideal': 'iDEAL',
                'mollie_direct': 'iDEAL',
                'factuur': 'Factuur',
                'invoice': 'Factuur',
                'cash': 'Contant'
            };
            return labels[type] || type;
        },
        
        getInvoiceStatusLabel(order) {
            if (order.invoice_status === 'gefactureerd') return 'Factuur verstuurd';
            if (order.invoice_status === 'bestelbon') return 'Bestelbon verstuurd';
            return '';
        },
        
        getDeliveryStatusLabel(order) {
            const labels = {
                'geplaatst': 'Geplaatst',
                'wordt_bereid': 'Wordt bereid',
                'onderweg': 'Onderweg',
                'afgeleverd': 'Afgeleverd'
            };
            return labels[order.delivery_status] || 'Geplaatst';
        },
        
        getDeliveryFlowClass(order, step) {
            const steps = ['geplaatst', 'wordt_bereid', 'onderweg', 'afgeleverd'];
            const currentIndex = steps.indexOf(order.delivery_status || 'geplaatst');
            const stepIndex = steps.indexOf(step);
            
            if (stepIndex < currentIndex) return 'step-done';
            if (stepIndex === currentIndex) return 'step-active';
            return 'step-future';
        },
        
        canDownloadInvoice(order) {
            return order.invoice_status === 'gefactureerd' && (order.factuur_url || order.eboekhouden_pdf_url);
        },
        
        canDownloadBestelbon(order) {
            return order.invoice_status === 'bestelbon' && order.bestelbon_url;
        },
        
        formatDateShort(dateStr) {
            if (!dateStr) return '-';
            const date = new Date(dateStr);
            return date.toLocaleDateString('nl-NL', { weekday: 'short', day: 'numeric', month: 'short' });
        },
        
        formatDateLong(dateStr) {
            if (!dateStr) return '-';
            const date = new Date(dateStr);
            return date.toLocaleDateString('nl-NL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        },
        
        formatPrice(amount) {
            if (!amount) return '-';
            return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(amount);
        },
        
        calculateSubtotal(order) {
            const total = parseFloat(order.total_amount) || 0;
            return total / 1.09;
        },
        
        calculateBTW(order) {
            const total = parseFloat(order.total_amount) || 0;
            return total - (total / 1.09);
        }
    }
}).mount('#orders-app');
