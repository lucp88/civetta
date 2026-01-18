const RecurringModal = {
    props: {
        show: Boolean,
        recurringName: String,
        recurringFrequency: String,
        recurringEndDate: String,
        minEndDate: String,
        deliveryDate: String,
        deliveryNotes: String,
        minDeliveryDate: String,
        isRecurring: Boolean
    },
    
    emits: ['close', 'confirm'],
    
    data() {
        return {
            tempName: '',
            tempFrequency: 'weekly',
            tempEndDate: '',
            tempDeliveryDate: '',
            tempNotes: '',
            tempIsRecurring: false
        };
    },
    
    watch: {
        show(newVal) {
            if (newVal) {
                this.tempName = this.recurringName || '';
                this.tempFrequency = this.recurringFrequency || 'weekly';
                this.tempEndDate = this.recurringEndDate || '';
                this.tempDeliveryDate = this.deliveryDate || '';
                this.tempNotes = this.deliveryNotes || '';
                this.tempIsRecurring = this.isRecurring || false;
            }
        }
    },
    
    methods: {
        close() {
            this.$emit('close');
        },
        
        confirm() {
            this.$emit('confirm', {
                name: this.tempName,
                frequency: this.tempFrequency,
                endDate: this.tempEndDate,
                deliveryDate: this.tempDeliveryDate,
                notes: this.tempNotes,
                isRecurring: this.tempIsRecurring
            });
        }
    },
    
    template: `
        <div class="modal-overlay" v-if="show" @click.self="close">
            <div class="modal recurring-modal delivery-modal">
                <div class="modal-header">
                    <h3>Levering instellingen</h3>
                    <button class="modal-close" @click="close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="delivery_date">Leverdatum *</label>
                        <input type="date" 
                               id="delivery_date" 
                               v-model="tempDeliveryDate"
                               :min="minDeliveryDate">
                    </div>
                    
                    <div class="form-group">
                        <label for="delivery_notes">Opmerkingen</label>
                        <textarea id="delivery_notes" 
                                  v-model="tempNotes" 
                                  rows="2"
                                  placeholder="Speciale wensen, leverinstructies..."></textarea>
                    </div>
                    
                    <div class="recurring-toggle-section">
                        <label class="recurring-checkbox">
                            <input type="checkbox" v-model="tempIsRecurring">
                            <span>Dit is een terugkerende bestelling</span>
                        </label>
                    </div>
                    
                    <template v-if="tempIsRecurring">
                        <div class="recurring-fields">
                            <div class="form-group">
                                <label for="recurring_name">Naam voor deze bestelling</label>
                                <input type="text" 
                                       id="recurring_name" 
                                       v-model="tempName" 
                                       placeholder="Bijv. Weekbestelling kantoor">
                            </div>
                            
                            <div class="form-group">
                                <label for="recurring_frequency">Frequentie *</label>
                                <select id="recurring_frequency" v-model="tempFrequency">
                                    <option value="weekly">Wekelijks</option>
                                    <option value="biweekly">Tweewekelijks</option>
                                    <option value="monthly">Maandelijks</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="recurring_end_date">Einddatum (optioneel)</label>
                                <input type="date" 
                                       id="recurring_end_date" 
                                       v-model="tempEndDate"
                                       :min="minEndDate">
                                <p class="field-hint">Laat leeg voor doorlopende bestelling</p>
                            </div>
                            
                            <div class="recurring-info-box">
                                <p>Leveringen worden <strong>maandelijks gebundeld gefactureerd</strong>. U ontvangt aan het einde van elke maand een verzamelfactuur.</p>
                            </div>
                        </div>
                    </template>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" @click="close">Annuleren</button>
                    <button class="btn btn-primary" @click="confirm">Opslaan</button>
                </div>
            </div>
        </div>
    `
};
