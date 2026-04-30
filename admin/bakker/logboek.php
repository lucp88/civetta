<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$adminPageTitle = 'Logboek';
$currentPage = 'logboek';
$adminBasePath = '../';

$recipeVersionIdFilter = isset($_GET['recipe_version_id']) ? (int)$_GET['recipe_version_id'] : 0;
$dtVersionIdFilter     = isset($_GET['dough_type_version_id']) ? (int)$_GET['dough_type_version_id'] : 0;

ob_start(); ?>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    [v-cloak] { display: none; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--cream); min-height: 100vh; }
    .admin-content { padding: 0; }

    /* ── Tabs ── */
    .tab-bar { display: flex; gap: 0; border-bottom: 2px solid #e8dfd2; background: #fff; padding: 0 1.5rem; }
    .tab-btn { padding: 0.75rem 1.25rem; background: none; border: none; border-bottom: 3px solid transparent; margin-bottom: -2px; font-size: 0.875rem; font-weight: 600; color: #6b7280; cursor: pointer; display: flex; align-items: center; gap: 0.4rem; transition: all 0.15s; }
    .tab-btn:hover { color: #374151; }
    .tab-btn.active { color: #92400e; border-bottom-color: #92400e; }

    /* ── Buttons ── */
    .btn { padding: 0.45rem 0.875rem; border: none; border-radius: 4px; font-size: 0.8rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem; transition: all 0.15s; text-decoration: none; }
    .btn-ghost { background: transparent; color: #374151; border: 1px solid #d1d5db; }
    .btn-ghost:hover { border-color: #9ca3af; background: #f9fafb; }
    .btn-sm { padding: 0.3rem 0.6rem; font-size: 0.75rem; }
    .btn-bakactie { background: #92400e; color: white; }
    .btn-bakactie:hover { background: #78350f; }

    /* ── Filters ── */
    .filters { display: flex; gap: 0.5rem; padding: 0.75rem 1.5rem; background: #faf8f5; border-bottom: 1px solid #e8dfd2; flex-wrap: wrap; align-items: center; }
    .filter-pill { padding: 0.3rem 0.75rem; border: 1px solid #d1d5db; border-radius: 20px; font-size: 0.78rem; cursor: pointer; font-weight: 500; color: #6b7280; transition: all 0.15s; white-space: nowrap; }
    .filter-pill:hover { border-color: #92400e; color: #92400e; }
    .filter-pill.active { background: #92400e; color: white; border-color: #92400e; }
    .filter-select { padding: 0.3rem 0.5rem; border: 1px solid #d1d5db; border-radius: 20px; font-size: 0.78rem; font-weight: 500; color: #6b7280; background: white; cursor: pointer; }
    .filter-select:focus { outline: none; border-color: #92400e; }

    /* ── Table ── */
    .content-area { padding: 1.25rem 1.5rem; }
    .table-wrap { background: white; border: 1px solid #e8dfd2; border-radius: 6px; overflow: hidden; }
    .ba-table { width: 100%; border-collapse: collapse; }
    .ba-table thead tr { background: #f5f0e8; border-bottom: 2px solid #e8e0d5; }
    .ba-table th { padding: 0.5rem 0.875rem; text-align: left; font-size: 0.72rem; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 0.04em; white-space: nowrap; }
    .ba-table td { padding: 0.625rem 0.875rem; border-bottom: 1px solid #f0ebe5; font-size: 0.85rem; color: #333; vertical-align: middle; }
    .ba-table tbody tr:last-child td { border-bottom: none; }
    .ba-table tbody tr.clickable:hover { background: #faf8f5; cursor: pointer; }
    .ba-table tbody tr.planned-row:hover { background: #eff6ff; cursor: pointer; }
    .bakker-tag { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.8rem; color: #6b7280; }
    .notes-preview { color: #6b7280; font-size: 0.8rem; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dough-tag { display: inline-flex; align-items: center; gap: 0.3rem; background: #f0fdf4; color: #166534; border-radius: 3px; padding: 0.15rem 0.45rem; font-size: 0.78rem; font-weight: 600; }

    /* ── Status badges ── */
    .status-badge { display: inline-flex; align-items: center; padding: 0.2rem 0.5rem; border-radius: 3px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; }
    .status-gepland  { background: #eff6ff; color: #1d4ed8; }
    .status-bezig    { background: #fff7ed; color: #c2410c; }
    .status-voltooid { background: #f0fdf4; color: #166534; }

    /* ── Action category badges ── */
    .cat-badge { display: inline-flex; align-items: center; padding: 0.1rem 0.4rem; border-radius: 3px; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
    .cat-preferment { background: #dcfce7; color: #166534; }
    .cat-deeg       { background: #dbeafe; color: #1e40af; }
    .cat-vormen     { background: #f3e8ff; color: #7e22ce; }
    .cat-bakken     { background: #ffe4e6; color: #9f1239; }

    /* ── Stars ── */
    .stars { color: #f59e0b; font-size: 0.85rem; letter-spacing: 0.05em; white-space: nowrap; }
    .stars .empty { color: #d1d5db; }

    /* ── Overig tab toolbar ── */
    .overig-toolbar { display: flex; align-items: center; padding: 0.75rem 1.5rem; background: #faf8f5; border-bottom: 1px solid #e8dfd2; gap: 0.5rem; }

    /* ── Modal ── */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 300; backdrop-filter: blur(2px); }
    .modal-content { background: white; border-radius: 8px; width: 90%; max-width: 460px; box-shadow: 0 20px 60px rgba(0,0,0,0.15); }
    .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 0.875rem 1.25rem; border-bottom: 1px solid #e5e7eb; }
    .modal-header h3 { font-size: 0.95rem; color: #1f2937; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
    .modal-close { background: none; border: none; font-size: 1.3rem; color: #9ca3af; cursor: pointer; line-height: 1; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border-radius: 4px; }
    .modal-close:hover { color: #ef4444; background: #fef2f2; }
    .modal-body { padding: 1.25rem; }
    .form-group { display: flex; flex-direction: column; gap: 0.25rem; margin-bottom: 0.75rem; }
    .form-label { font-size: 0.7rem; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; }
    .form-input, .form-select { padding: 0.45rem 0.6rem; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.9rem; color: #1f2937; background: white; width: 100%; font-family: inherit; }
    .form-input:focus, .form-select:focus { outline: none; border-color: #92400e; box-shadow: 0 0 0 2px rgba(146,64,14,0.12); }
    textarea.form-input { resize: vertical; min-height: 5rem; }

    /* ── Empty ── */
    .empty-state { text-align: center; padding: 3rem 1.5rem; color: #9ca3af; }
    .empty-state i { font-size: 2.5rem; display: block; margin-bottom: 0.75rem; color: #d1d5db; }
    .empty-state p { font-size: 0.85rem; }

    /* ── Toast ── */
    .toast { position: fixed; bottom: 2rem; right: 2rem; padding: 0.6rem 1.25rem; background: #1f2937; color: white; border-radius: 4px; font-size: 0.85rem; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-weight: 500; }
</style>
<?php $adminExtraHead = ob_get_clean();
require_once '../components/sidebar.php'; ?>

    <header class="topbar">
        <div class="topbar-left">
            <span class="topbar-title">Logboek</span>
        </div>
        <div class="topbar-right" style="display:flex;gap:0.5rem">
            <a href="dagproductie.php" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-left"></i> Dagproductie</a>
        </div>
    </header>

    <div class="admin-content">
        <div id="app" v-cloak>

            <!-- Tab bar -->
            <div class="tab-bar">
                <button class="tab-btn" :class="{active: activeTab==='bakacties'}" @click="activeTab='bakacties'">
                    <i class="bi bi-fire"></i> Bakacties
                    <span v-if="bakactieList.length" style="background:#f3f4f6;color:#6b7280;border-radius:10px;padding:0.05rem 0.45rem;font-size:0.72rem;font-weight:700">{{ bakactieList.length }}</span>
                </button>
                <button class="tab-btn" :class="{active: activeTab==='overig'}" @click="activeTab='overig'">
                    <i class="bi bi-journal-text"></i> Overig
                    <span v-if="overigList.length" style="background:#f3f4f6;color:#6b7280;border-radius:10px;padding:0.05rem 0.45rem;font-size:0.72rem;font-weight:700">{{ overigList.length }}</span>
                </button>
            </div>

            <!-- ═══════════════════ BAKACTIES TAB ═══════════════════ -->
            <template v-if="activeTab==='bakacties'">
                <div class="filters">
                    <span class="filter-pill" :class="{active: statusFilter===''}"        @click="statusFilter=''">Alle</span>
                    <span class="filter-pill" :class="{active: statusFilter==='gepland'}" @click="statusFilter='gepland'">Gepland</span>
                    <span class="filter-pill" :class="{active: statusFilter==='bezig'}"   @click="statusFilter='bezig'">Bezig</span>
                    <span class="filter-pill" :class="{active: statusFilter==='voltooid'}"@click="statusFilter='voltooid'">Voltooid</span>

                    <!-- Grade filter -->
                    <select class="filter-select" v-model.number="gradeFilter">
                        <option :value="0">★ Alle beoordelingen</option>
                        <option :value="1">★ 1+</option>
                        <option :value="2">★ 2+</option>
                        <option :value="3">★ 3+</option>
                        <option :value="4">★ 4+</option>
                        <option :value="5">★ 5</option>
                    </select>
                    <!-- Dough type filter -->
                    <select class="filter-select" v-model="doughTypeFilter" v-if="doughTypes.length > 1">
                        <option value="">Alle deegsoorten</option>
                        <option v-for="dt in doughTypes" :key="dt" :value="dt">{{ dt }}</option>
                    </select>

                    <span v-if="recipeVersionId" style="display:inline-flex;align-items:center;gap:0.4rem;background:#fff7ed;color:#92400e;border:1px solid #fed7aa;border-radius:20px;padding:0.3rem 0.75rem;font-size:0.78rem;font-weight:600">
                        <i class="bi bi-funnel-fill"></i> Broodrecept versie {{ recipeVersionId }}
                        <a href="logboek.php" style="color:#92400e;text-decoration:none;font-size:0.85rem;line-height:1" title="Filter wissen">&times;</a>
                    </span>
                    <span v-if="dtVersionId" style="display:inline-flex;align-items:center;gap:0.4rem;background:#fff7ed;color:#92400e;border:1px solid #fed7aa;border-radius:20px;padding:0.3rem 0.75rem;font-size:0.78rem;font-weight:600">
                        <i class="bi bi-funnel-fill"></i> Deegversie {{ dtVersionId }}
                        <a href="logboek.php" style="color:#92400e;text-decoration:none;font-size:0.85rem;line-height:1" title="Filter wissen">&times;</a>
                    </span>
                    <span style="margin-left:auto;font-size:0.78rem;color:#9ca3af">
                        {{ filteredBakacties.length }} entries
                    </span>
                </div>

                <div class="content-area">
                    <!-- Bakacties list -->
                    <div class="table-wrap">
                        <div v-if="filteredBakacties.length === 0" class="empty-state">
                            <i class="bi bi-fire"></i>
                            <p>Geen bakacties gevonden.</p>
                            <a href="dagproductie.php" class="btn btn-ghost" style="margin-top:1rem"><i class="bi bi-arrow-left"></i> Naar dagproductie</a>
                        </div>
                        <table v-else class="ba-table">
                            <thead><tr>
                                <th>Datum</th><th>Deegsoort</th><th>Bakker</th><th>Beoordeling</th><th>Status</th><th>Notitie</th><th></th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="ba in filteredBakacties" :key="ba.id" class="clickable"
                                    @click="goToBakactie(ba.id)">
                                    <td style="white-space:nowrap;color:#374151;font-variant-numeric:tabular-nums">{{ formatDatum(ba.datum) }}</td>
                                    <td>
                                        <span class="dough-tag"><i class="bi bi-fire"></i>{{ ba.dough_type_name }}</span>
                                        <div v-if="ba.action_categories" style="display:flex;gap:0.25rem;flex-wrap:wrap;margin-top:0.25rem">
                                            <span v-for="cat in ba.action_categories.split(',')" :key="cat" class="cat-badge" :class="catClass(cat)">{{ catLabel(cat) }}</span>
                                        </div>
                                    </td>
                                    <td><span class="bakker-tag" v-if="ba.bakker"><i class="bi bi-person"></i>{{ ba.bakker }}</span><span v-else style="color:#d1d5db">—</span></td>
                                    <td>
                                        <span v-if="ba.notes_data && ba.notes_data.quality" class="stars" v-html="starsHtml(ba.notes_data.quality)"></span>
                                        <span v-else style="color:#d1d5db">—</span>
                                    </td>
                                    <td><span class="status-badge" :class="'status-'+ba.status">{{ ba.status }}</span></td>
                                    <td><span class="notes-preview" v-if="ba.notes_data && ba.notes_data.general">{{ ba.notes_data.general }}</span><span v-else style="color:#d1d5db">—</span></td>
                                    <td style="text-align:right;padding-right:0.5rem" @click.stop>
                                        <button class="btn btn-ghost btn-sm" style="color:#ef4444" @click="deleteBakactie(ba.id)" title="Verwijderen"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

            <!-- ═══════════════════ OVERIG TAB ═══════════════════ -->
            <template v-if="activeTab==='overig'">
                <div class="overig-toolbar">
                    <button class="btn btn-bakactie btn-sm" @click="openNewModal"><i class="bi bi-plus-lg"></i> Nieuwe notitie</button>
                    <span style="margin-left:auto;font-size:0.78rem;color:#9ca3af">{{ overigList.length }} entries</span>
                </div>
                <div class="content-area">
                    <div class="table-wrap">
                        <div v-if="overigList.length === 0" class="empty-state">
                            <i class="bi bi-journal-text"></i>
                            <p>Geen overige notities. Klik op "+ Nieuwe notitie" om er een toe te voegen.</p>
                        </div>
                        <table v-else class="ba-table">
                            <thead><tr>
                                <th>Datum</th><th>Titel</th><th>Bakker</th><th>Status</th><th>Notities</th><th></th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="ba in overigList" :key="ba.id">
                                    <td style="white-space:nowrap;color:#374151;font-variant-numeric:tabular-nums">{{ formatDatum(ba.datum) }}</td>
                                    <td style="font-weight:600;color:#1f2937">{{ ba.locked_recipe_name || '—' }}</td>
                                    <td><span class="bakker-tag" v-if="ba.bakker"><i class="bi bi-person"></i>{{ ba.bakker }}</span><span v-else style="color:#d1d5db">—</span></td>
                                    <td><span class="status-badge" :class="'status-'+ba.status">{{ ba.status }}</span></td>
                                    <td><span class="notes-preview" v-if="ba.notes">{{ ba.notes }}</span><span v-else style="color:#d1d5db">—</span></td>
                                    <td style="text-align:right;padding-right:0.5rem;white-space:nowrap">
                                        <button class="btn btn-ghost btn-sm" @click="openEdit(ba)" title="Bewerken"><i class="bi bi-pencil"></i></button>
                                        <button class="btn btn-ghost btn-sm" style="color:#ef4444;margin-left:0.25rem" @click="deleteEntry(ba.id)" title="Verwijderen"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

            <!-- New / Edit modal (Overig) -->
            <div v-if="showModal" class="modal-overlay" @mousedown.self="showModal = false">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3><i class="bi bi-journal-text" style="color:#92400e"></i> {{ editingId ? 'Notitie bewerken' : 'Nieuwe notitie' }}</h3>
                        <button class="modal-close" @click="showModal = false">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label class="form-label">Titel / Onderwerp</label>
                            <input type="text" v-model="form.locked_recipe_name" class="form-input" placeholder="Bijv. Testbak rogge, Onderhoud oven...">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Datum & tijdstip</label>
                            <input type="datetime-local" v-model="form.datum" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Bakker / Persoon</label>
                            <input type="text" v-model="form.bakker" class="form-input" placeholder="Naam...">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select v-model="form.status" class="form-select">
                                <option value="bezig">Bezig</option>
                                <option value="voltooid">Voltooid</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:1rem">
                            <label class="form-label">Notities</label>
                            <textarea v-model="form.notes" class="form-input" placeholder="Notities..."></textarea>
                        </div>
                        <div style="display:flex;gap:0.5rem">
                            <button class="btn btn-ghost" @click="showModal = false">Annuleren</button>
                            <button class="btn btn-bakactie" style="flex:1" @click="saveForm" :disabled="!form.datum || !form.locked_recipe_name || saving">
                                <i class="bi bi-check-lg"></i> {{ saving ? 'Opslaan...' : 'Opslaan' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Toast -->
            <div v-if="toastMsg" class="toast">{{ toastMsg }}</div>
        </div>
    </div>

    <script src="../../js/ui-notifications.js?v=1"></script>
    <script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js"></script>
    <script>
    const { createApp } = Vue;
    const app = createApp({
        data() {
            return {
                list: [],
                activeTab: 'bakacties',
                statusFilter: '',
                gradeFilter: 0,
                doughTypeFilter: '',
                recipeVersionId: <?= $recipeVersionIdFilter ?>,
                dtVersionId: <?= $dtVersionIdFilter ?>,
                showModal: false,
                editingId: null,
                saving: false,
                toastMsg: '',
                form: { locked_recipe_name: '', datum: '', bakker: '', notes: '', status: 'bezig' },
            };
        },
        computed: {
            bakactieList() {
                let r = this.list.filter(b => b.dough_type_name);
                if (this.recipeVersionId) r = r.filter(b => b.recipe_version_id == this.recipeVersionId);
                if (this.dtVersionId) r = r.filter(b => b.dough_type_version_id == this.dtVersionId);
                return r;
            },
            overigList() {
                return this.list.filter(b => !b.dough_type_name);
            },
            filteredBakacties() {
                let r = this.bakactieList;
                if (this.statusFilter) r = r.filter(b => b.status === this.statusFilter);
                if (this.gradeFilter)  r = r.filter(b => (b.notes_data && b.notes_data.quality || 0) >= this.gradeFilter);
                if (this.doughTypeFilter) r = r.filter(b => b.dough_type_name === this.doughTypeFilter);
                return r;
            },
            doughTypes() {
                const seen = new Set();
                this.bakactieList.forEach(b => { if (b.dough_type_name) seen.add(b.dough_type_name); });
                return [...seen].sort();
            },
        },
        methods: {
            async load() {
                const res = await fetch('../../api/bak-acties.php');
                const data = await res.json();
                if (data.success) this.list = data.bak_acties;
            },
            formatDatum(d) {
                if (!d) return '—';
                const dt = new Date(d);
                return dt.toLocaleDateString('nl-NL', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) + ', ' +
                       dt.toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });
            },
            formatDate(d) {
                if (!d) return '—';
                const dt = new Date(d + 'T00:00:00');
                return dt.toLocaleDateString('nl-NL', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
            },
            starsHtml(q) {
                let h = '';
                for (let i = 1; i <= 5; i++) h += i <= q ? '★' : '<span class="empty">★</span>';
                return h;
            },
            goToBakactie(id) {
                window.location.href = 'bak-actie.php?id=' + id;
            },
            goToRow(row) {
                if (row.bakactie_id) {
                    window.location.href = 'bak-actie.php?id=' + row.bakactie_id;
                } else {
                    window.location.href = 'dagproductie.php?date=' + row.delivery_date + '&dough_type=' + encodeURIComponent(row.dough_type_name);
                }
            },
            openNewModal() {
                const now = new Date();
                const pad = n => String(n).padStart(2, '0');
                this.editingId = null;
                this.form = {
                    locked_recipe_name: '',
                    datum: `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`,
                    bakker: '', notes: '', status: 'bezig',
                };
                this.showModal = true;
            },
            async openEdit(ba) {
                this.editingId = ba.id;
                const pad = n => String(n).padStart(2, '0');
                const d = new Date(ba.datum);
                this.form = {
                    locked_recipe_name: ba.locked_recipe_name,
                    datum: `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`,
                    bakker: ba.bakker || '',
                    notes: ba.notes || '',
                    status: ba.status,
                };
                this.showModal = true;
            },
            async saveForm() {
                if (!this.form.datum || !this.form.locked_recipe_name) return;
                this.saving = true;
                try {
                    const body = {
                        locked_recipe_name: this.form.locked_recipe_name,
                        datum: this.form.datum.replace('T', ' ') + ':00',
                        bakker: this.form.bakker || null,
                        notes: this.form.notes || null,
                        status: this.form.status,
                    };
                    if (this.editingId) {
                        body.id = this.editingId;
                        await fetch('../../api/bak-acties.php', { method: 'PUT', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
                    } else {
                        await fetch('../../api/bak-acties.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
                    }
                    this.showModal = false;
                    await this.load();
                    this.showToast(this.editingId ? 'Notitie bijgewerkt!' : 'Notitie aangemaakt!');
                } catch (e) { console.error(e); }
                this.saving = false;
            },
            async deleteEntry(id) {
                if (!await showConfirm('Notitie verwijderen?')) return;
                await fetch('../../api/bak-acties.php?id=' + id, { method: 'DELETE' });
                await this.load();
                this.showToast('Notitie verwijderd');
            },
            async deleteBakactie(id) {
                const ba = this.list.find(b => b.id === id);
                const hasStock = ba && (ba.sourdough_consumed || ba.inventory_consumed);
                if (hasStock) {
                    const revert = await showConfirm(
                        'Deze bakactie heeft voorraadafschrijvingen.\n\nKies OK om de afschrijvingen terug te draaien (voorraad herstellen) en de bakactie te verwijderen.\nKies Annuleren om te stoppen.'
                    );
                    if (!revert) return;
                    await fetch('../../api/bak-acties.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ _action: 'revert_inventory', id })
                    });
                } else {
                    if (!await showConfirm('Bakactie verwijderen? Dit kan niet ongedaan worden gemaakt.')) return;
                }
                await fetch('../../api/bak-acties.php?id=' + id, { method: 'DELETE' });
                await this.load();
                this.showToast('Bakactie verwijderd');
            },
            catLabel(cat) {
                return { 'pre-ferment': 'PF', 'deeg': 'Deeg', 'vormen': 'Vormen', 'bakken': 'Bakken' }[cat] || cat;
            },
            catClass(cat) {
                return { 'pre-ferment': 'cat-preferment', 'deeg': 'cat-deeg', 'vormen': 'cat-vormen', 'bakken': 'cat-bakken' }[cat] || '';
            },
            showToast(msg) {
                this.toastMsg = msg;
                setTimeout(() => this.toastMsg = '', 2500);
            },
        },
        mounted() { this.load(); },
    });
    window.vueApp = app.mount('#app');
    </script>
</body>
