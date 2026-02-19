<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$currentPage  = 'schoonmaak';
$adminBasePath = '../';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voedselveiligheid | Civetta Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--cream); min-height: 100vh; }
        .admin-content { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
        @media (max-width: 768px) { .admin-content { padding: 1rem; } }

        /* Tabs */
        .tabs { display: flex; gap: 0.25rem; border-bottom: 2px solid #e0d5c7; margin-bottom: 1.5rem; overflow-x: auto; }
        .tab { padding: 0.7rem 1.2rem; cursor: pointer; font-weight: 500; color: #888; border-bottom: 3px solid transparent; margin-bottom: -2px; white-space: nowrap; transition: all 0.2s; user-select: none; display: flex; align-items: center; gap: 0.4rem; }
        .tab:hover { color: #5c3d1e; }
        .tab.active { color: #8b5a2b; border-bottom-color: #c8913a; font-weight: 700; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Panels */
        .panel { background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); padding: 1.5rem; margin-bottom: 1.5rem; }
        .panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 1rem; }
        .panel-title { font-size: 1.1rem; font-weight: 700; color: #5c3d1e; display: flex; align-items: center; gap: 0.5rem; }
        .panel-title i { color: #c8913a; }

        /* Buttons */
        .btn { padding: 0.5rem 1rem; border: none; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; transition: all 0.2s; text-decoration: none; }
        .btn-primary { background: #8b5a2b; color: white; }
        .btn-primary:hover { background: #5c3d1e; }
        .btn-success { background: #2e7d32; color: white; }
        .btn-success:hover { background: #1b5e20; }
        .btn-danger { background: #c62828; color: white; }
        .btn-danger:hover { background: #b71c1c; }
        .btn-ghost { background: transparent; color: #8b5a2b; border: 2px solid #e0d5c7; }
        .btn-ghost:hover { border-color: #8b5a2b; background: #faf6f1; }
        .btn-sm { padding: 0.35rem 0.7rem; font-size: 0.8rem; }
        .btn-icon { padding: 0.35rem 0.5rem; background: transparent; border: 1px solid #e0d5c7; color: #666; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; transition: all 0.15s; }
        .btn-icon:hover { background: #f5f0e8; color: #5c3d1e; border-color: #8b5a2b; }

        /* Forms */
        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; font-size: 0.85rem; font-weight: 600; color: #5c3d1e; margin-bottom: 0.35rem; }
        .form-control { width: 100%; padding: 0.5rem 0.75rem; border: 1.5px solid #e0d5c7; border-radius: 8px; font-size: 0.9rem; color: #333; background: white; }
        .form-control:focus { outline: none; border-color: #8b5a2b; box-shadow: 0 0 0 3px rgba(139,90,43,0.1); }
        select.form-control { height: 38px; }

        /* Date bar */
        .date-bar { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .date-input { padding: 0.5rem 0.75rem; border: 1.5px solid #e0d5c7; border-radius: 8px; font-size: 0.9rem; color: #333; background: white; }
        .date-input:focus { outline: none; border-color: #8b5a2b; }

        /* Checklist table */
        .table-wrapper { overflow-x: auto; }
        table.checklist { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        table.checklist th { text-align: left; padding: 0.6rem 0.75rem; color: #888; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 2px solid #e8e0d5; white-space: nowrap; }
        table.checklist td { padding: 0.55rem 0.75rem; border-bottom: 1px solid #f0ebe5; vertical-align: middle; }
        table.checklist tr:hover td { background: #faf8f5; }
        table.checklist tr.item-checked td { background: #f0fff4; }
        table.checklist tr.item-overdue:not(.item-checked) td { background: #fff8f0; }
        table.checklist tr.item-overdue:not(.item-checked) .due-date { color: #e65100; font-weight: 700; }
        table.checklist tr.item-checked .due-date { color: #2e7d32; }

        /* Checkbox */
        .cb-wrap { display: flex; align-items: center; justify-content: center; }
        .cb-wrap input[type=checkbox] { width: 20px; height: 20px; cursor: pointer; accent-color: #2e7d32; }

        /* Badges */
        .badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.74rem; font-weight: 600; }
        .badge-schoonmaak { background: #e3f2fd; color: #1565c0; }
        .badge-voorraad    { background: #f3e5f5; color: #6a1b9a; }
        .badge-status-volledig  { background: #e8f5e9; color: #2e7d32; }
        .badge-status-afwijking { background: #fff3e0; color: #e65100; }
        .badge-status-onvolledig { background: #ffebee; color: #c62828; }

        /* Inline inputs in table cells */
        .td-input { width: 100%; border: 1px solid transparent; border-radius: 6px; padding: 0.3rem 0.5rem; font-size: 0.84rem; background: transparent; color: #333; font-family: inherit; }
        .td-input:hover:not(:focus) { border-color: #e0d5c7; }
        .td-input:focus { border-color: #8b5a2b; background: white; outline: none; box-shadow: 0 0 0 2px rgba(139,90,43,0.08); }

        /* Status bar */
        .status-bar { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.88rem; flex-wrap: wrap; }
        .status-bar-normal { background: #f8f5f0; border: 1px solid #e8e0d5; }
        .status-bar-late   { background: #fff8e1; border: 1px solid #ffe082; }

        /* Modal */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-overlay.open { display: flex; }
        .modal { background: white; border-radius: 12px; padding: 2rem; max-width: 620px; width: 92%; max-height: 85vh; overflow-y: auto; }
        .modal-title { font-size: 1.1rem; font-weight: 700; color: #5c3d1e; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        .modal-body { margin-bottom: 1.5rem; }
        .modal-footer { display: flex; gap: 0.75rem; justify-content: flex-end; flex-wrap: wrap; }

        /* Warning table inside modal */
        .warning-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-top: 0.75rem; }
        .warning-table th { padding: 0.5rem 0.6rem; text-align: left; background: #fff3e0; color: #e65100; font-size: 0.75rem; text-transform: uppercase; }
        .warning-table td { padding: 0.5rem 0.6rem; border-bottom: 1px solid #f0ebe5; }

        /* Overzicht */
        table.overzicht { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        table.overzicht th { padding: 0.75rem; text-align: left; color: #888; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 2px solid #e8e0d5; }
        table.overzicht td { padding: 0.75rem; border-bottom: 1px solid #f0ebe5; vertical-align: middle; }
        table.overzicht tr:hover td { background: #faf8f5; }
        .progress-wrap { display: flex; align-items: center; gap: 0.5rem; }
        .progress-bar-bg { width: 90px; height: 7px; background: #e8e0d5; border-radius: 4px; overflow: hidden; flex-shrink: 0; }
        .progress-bar-fill { height: 100%; background: #2e7d32; border-radius: 4px; transition: width 0.3s; }

        /* Master items table */
        table.items { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        table.items th { padding: 0.75rem; text-align: left; color: #888; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 2px solid #e8e0d5; }
        table.items td { padding: 0.75rem; border-bottom: 1px solid #f0ebe5; vertical-align: middle; }
        table.items tr:hover td { background: #faf8f5; }
        table.items tr.inactive td { opacity: 0.45; }

        /* Empty state */
        .empty-state { text-align: center; padding: 3rem 1rem; color: #999; }
        .empty-state i { font-size: 2.5rem; margin-bottom: 0.75rem; display: block; }
        .empty-state p { font-size: 0.92rem; }

        /* Loading */
        .loading { display: flex; align-items: center; gap: 0.5rem; color: #888; padding: 2rem; justify-content: center; font-size: 0.9rem; }

        /* Toast */
        .toast { position: fixed; bottom: 1.5rem; right: 1.5rem; background: #333; color: white; padding: 0.7rem 1.2rem; border-radius: 8px; font-size: 0.88rem; z-index: 9999; opacity: 0; transition: opacity 0.3s; pointer-events: none; display: flex; align-items: center; gap: 0.5rem; max-width: 320px; }
        .toast.show { opacity: 1; }
        .toast.success { background: #2e7d32; }
        .toast.error   { background: #c62828; }

        /* Responsive: hide columns on narrow screens */
        @media (max-width: 960px) { .col-notities, .col-uitvoerder, .col-tijdstip { display: none; } }
        @media (max-width: 640px) { .col-frequentie, .col-type { display: none; } }

        /* Print */
        @media print {
            .sidebar, .mobile-overlay, .mobile-dropdown, .topbar,
            .tabs, .date-bar, .btn-group-actions, .no-print { display: none !important; }
            .admin-main { margin-left: 0 !important; }
            .admin-content { padding: 0 !important; max-width: 100% !important; }
            .tab-content { display: block !important; }
            #tab-items, #tab-overzicht { display: none !important; }
            .panel { box-shadow: none !important; border: 1px solid #ccc !important; padding: 0.5rem !important; }
            .status-bar { display: none !important; }
            .print-header { display: block !important; }
            table.checklist { font-size: 9pt; }
            table.checklist th { font-size: 8pt; }
            .col-notities, .col-uitvoerder, .col-tijdstip, .col-frequentie, .col-type { display: table-cell !important; }
        }

        /* Print-only header */
        .print-header { display: none; margin-bottom: 1rem; text-align: center; border-bottom: 2px solid #333; padding-bottom: 0.75rem; }
        .print-header h2 { font-size: 1.2rem; margin: 0 0 0.25rem; }
        .print-header p  { font-size: 0.88rem; color: #555; margin: 0; }

        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .spin { animation: spin 0.8s linear infinite; display: inline-block; }
    </style>
</head>
<body>
<?php include '../components/sidebar.php'; ?>

<div class="admin-main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="mobile-toggle" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
            <span class="topbar-title"><i class="bi bi-check2-square"></i> Voedselveiligheid</span>
        </div>
        <div class="topbar-right">
            <a href="bakker-dashboard.php" class="topbar-link no-print"><i class="bi bi-arrow-left"></i> <span>Dashboard</span></a>
        </div>
    </div>

    <div class="admin-content">

        <!-- Tabs -->
        <div class="tabs no-print">
            <div class="tab active" onclick="switchTab('daglijst')"><i class="bi bi-clipboard-check"></i> Daglijst</div>
            <div class="tab" onclick="switchTab('items')"><i class="bi bi-list-ul"></i> Items beheer</div>
            <div class="tab" onclick="switchTab('overzicht')"><i class="bi bi-calendar3"></i> Overzicht</div>
        </div>

        <!-- ==================== DAGLIJST TAB ==================== -->
        <div id="tab-daglijst" class="tab-content active">

            <!-- Print-only header -->
            <div class="print-header">
                <h2>Bakkerij Civetta — Voedselveiligheid</h2>
                <p id="printDateLabel"></p>
            </div>

            <!-- Date selector & action buttons -->
            <div class="date-bar no-print">
                <button class="btn btn-ghost btn-sm" onclick="changeDate(-1)"><i class="bi bi-chevron-left"></i></button>
                <input type="date" class="date-input" id="listDate" value="<?= date('Y-m-d') ?>" onchange="loadList()">
                <button class="btn btn-ghost btn-sm" onclick="changeDate(1)"><i class="bi bi-chevron-right"></i></button>
                <button class="btn btn-ghost btn-sm" onclick="goToday()"><i class="bi bi-calendar-check"></i> Vandaag</button>
                <div style="margin-left:auto; display:flex; gap:0.5rem;" class="btn-group-actions">
                    <button class="btn btn-ghost btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Printen</button>
                    <button class="btn btn-success" id="saveBtn" onclick="saveList(false)">
                        <i class="bi bi-floppy"></i> Opslaan
                    </button>
                </div>
            </div>

            <!-- Status bar -->
            <div id="statusBar" style="display:none;"></div>

            <!-- Loading indicator -->
            <div id="listLoading" class="loading">
                <i class="bi bi-arrow-clockwise spin"></i> Laden…
            </div>

            <!-- No list state -->
            <div id="noListPanel" class="panel" style="display:none;">
                <div class="empty-state">
                    <i class="bi bi-clipboard-x" style="color:#c8913a;"></i>
                    <p style="margin-bottom:1.25rem;">Er bestaat nog geen checklist voor <strong id="noListDate"></strong>.</p>
                    <button class="btn btn-primary" onclick="createList()">
                        <i class="bi bi-plus-lg"></i> Lijst aanmaken voor deze datum
                    </button>
                </div>
            </div>

            <!-- Checklist panel -->
            <div id="listPanel" class="panel" style="display:none;">
                <div class="panel-header no-print">
                    <div class="panel-title">
                        <i class="bi bi-clipboard-check"></i>
                        <span id="panelTitle">Checklist</span>
                    </div>
                    <span id="progressLabel" style="font-size:0.85rem; color:#666;"></span>
                </div>

                <div class="table-wrapper">
                    <table class="checklist" id="checklistTable">
                        <thead>
                            <tr>
                                <th style="width:30px;">#</th>
                                <th>Item naam</th>
                                <th class="col-type">Type</th>
                                <th class="col-frequentie">Frequentie</th>
                                <th>Due datum</th>
                                <th style="width:68px; text-align:center;">Afgevinkt</th>
                                <th class="col-notities">Notities</th>
                                <th class="col-uitvoerder">Uitvoerder</th>
                                <th class="col-tijdstip">Tijdstip</th>
                            </tr>
                        </thead>
                        <tbody id="checklistBody"></tbody>
                    </table>
                </div>

                <div id="emptyList" class="empty-state" style="display:none;">
                    <i class="bi bi-inbox"></i>
                    <p>Geen items geconfigureerd. Voeg items toe via het tabblad <strong>Items beheer</strong>.</p>
                </div>
            </div>
        </div><!-- /tab-daglijst -->

        <!-- ==================== ITEMS BEHEER TAB ==================== -->
        <div id="tab-items" class="tab-content">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="bi bi-list-ul"></i> Items beheer</div>
                    <button class="btn btn-primary btn-sm" onclick="openItemModal()">
                        <i class="bi bi-plus-lg"></i> Nieuw item
                    </button>
                </div>
                <div id="itemsLoading" class="loading"><i class="bi bi-arrow-clockwise spin"></i> Laden…</div>
                <div class="table-wrapper" id="itemsTableWrap" style="display:none;">
                    <table class="items">
                        <thead>
                            <tr>
                                <th>Item naam</th>
                                <th>Type</th>
                                <th>Frequentie</th>
                                <th>Status</th>
                                <th style="width:90px;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody"></tbody>
                    </table>
                </div>
                <div id="itemsEmpty" class="empty-state" style="display:none;">
                    <i class="bi bi-inbox"></i>
                    <p>Nog geen items aangemaakt. Klik op <strong>Nieuw item</strong> om te beginnen.</p>
                </div>
            </div>
        </div><!-- /tab-items -->

        <!-- ==================== OVERZICHT TAB ==================== -->
        <div id="tab-overzicht" class="tab-content">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="bi bi-calendar3"></i> Overzicht checklists</div>
                    <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                        <label style="font-size:0.83rem; color:#666;">Van:</label>
                        <input type="date" class="date-input" id="filterVan" style="font-size:0.83rem;">
                        <label style="font-size:0.83rem; color:#666;">Tot:</label>
                        <input type="date" class="date-input" id="filterTot" style="font-size:0.83rem;">
                        <button class="btn btn-ghost btn-sm" onclick="loadOverzicht()"><i class="bi bi-search"></i></button>
                    </div>
                </div>
                <div id="overzichtLoading" class="loading" style="display:none;"><i class="bi bi-arrow-clockwise spin"></i> Laden…</div>
                <div class="table-wrapper" id="overzichtTableWrap" style="display:none;">
                    <table class="overzicht">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Status</th>
                                <th>Voortgang</th>
                                <th class="no-print"></th>
                            </tr>
                        </thead>
                        <tbody id="overzichtBody"></tbody>
                    </table>
                </div>
                <div id="overzichtEmpty" class="empty-state" style="display:none;">
                    <i class="bi bi-calendar-x"></i>
                    <p>Geen checklists gevonden in de geselecteerde periode.</p>
                </div>
            </div>
        </div><!-- /tab-overzicht -->

    </div><!-- /admin-content -->
</div><!-- /admin-main -->


<!-- ==================== WARNING MODAL ==================== -->
<div class="modal-overlay" id="warningModal">
    <div class="modal">
        <div class="modal-title">
            <i class="bi bi-exclamation-triangle-fill" style="color:#e65100;"></i>
            Openstaande items
        </div>
        <div class="modal-body">
            <p>Er zijn verplichte items die vandaag of eerder uitgevoerd hadden moeten worden en nog niet zijn afgevinkt. Weet je zeker dat je wilt opslaan?</p>
            <table class="warning-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Type</th>
                        <th>Due datum</th>
                        <th>Frequentie</th>
                    </tr>
                </thead>
                <tbody id="warningItems"></tbody>
            </table>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal('warningModal')">
                <i class="bi bi-arrow-left"></i> Terug om af te vinken
            </button>
            <button class="btn btn-danger" onclick="saveList(true)">
                <i class="bi bi-exclamation-triangle"></i> Toch opslaan (afwijking)
            </button>
        </div>
    </div>
</div>

<!-- ==================== ITEM MODAL ==================== -->
<div class="modal-overlay" id="itemModal">
    <div class="modal">
        <div class="modal-title">
            <i class="bi bi-pencil-square" style="color:#8b5a2b;"></i>
            <span id="itemModalTitle">Item toevoegen</span>
        </div>
        <div class="modal-body">
            <input type="hidden" id="itemId">
            <div class="form-group">
                <label class="form-label">Item naam *</label>
                <input type="text" class="form-control" id="itemNaam" placeholder="Bijv. Werkblad reinigen">
            </div>
            <div class="form-group">
                <label class="form-label">Type *</label>
                <select class="form-control" id="itemType">
                    <option value="schoonmaak">Schoonmaak</option>
                    <option value="voorraad">Voorraad</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Frequentie *</label>
                <select class="form-control" id="itemFrequentie">
                    <option value="dagelijks">Dagelijks</option>
                    <option value="dagelijks_mits_gebruikt">Dagelijks (mits gebruikt)</option>
                    <option value="wekelijks">Wekelijks</option>
                    <option value="maandelijks">Maandelijks</option>
                </select>
            </div>
            <div class="form-group" id="itemActiefGroup" style="display:none;">
                <label class="form-label">Status</label>
                <select class="form-control" id="itemActief">
                    <option value="1">Actief</option>
                    <option value="0">Inactief</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal('itemModal')">Annuleren</button>
            <button class="btn btn-primary" onclick="saveItem()"><i class="bi bi-floppy"></i> Opslaan</button>
        </div>
    </div>
</div>

<!-- Toast notification -->
<div class="toast" id="toast"></div>

<script>
const API = '../../api/voedselveiligheid.php';

let currentList  = null;
let currentItems = [];
let masterItems  = [];

// ==================== TAB SWITCHING ====================
function switchTab(tab) {
    const tabNames = ['daglijst', 'items', 'overzicht'];
    document.querySelectorAll('.tabs .tab').forEach((el, i) => {
        el.classList.toggle('active', tabNames[i] === tab);
    });
    document.querySelectorAll('.tab-content').forEach(c => {
        c.classList.toggle('active', c.id === 'tab-' + tab);
    });

    if (tab === 'items'    && masterItems.length === 0) loadItems();
    if (tab === 'overzicht') loadOverzicht();
}

// ==================== DATE NAVIGATION ====================
function changeDate(delta) {
    const input = document.getElementById('listDate');
    const d = new Date(input.value);
    d.setDate(d.getDate() + delta);
    input.value = d.toISOString().split('T')[0];
    loadList();
}

function goToday() {
    document.getElementById('listDate').value = '<?= date('Y-m-d') ?>';
    loadList();
}

// ==================== LOAD LIST ====================
async function loadList() {
    const datum = document.getElementById('listDate').value;

    document.getElementById('listLoading').style.display  = 'flex';
    document.getElementById('listPanel').style.display    = 'none';
    document.getElementById('noListPanel').style.display  = 'none';
    document.getElementById('statusBar').style.display    = 'none';
    document.getElementById('saveBtn').style.display      = '';

    try {
        const res  = await fetch(`${API}?action=get_list&datum=${datum}`);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Fout bij laden');

        document.getElementById('listLoading').style.display = 'none';

        if (!data.exists) {
            document.getElementById('noListDate').textContent = formatDate(datum);
            document.getElementById('noListPanel').style.display = 'block';
            document.getElementById('saveBtn').style.display = 'none';
            return;
        }

        currentList  = data.lijst;
        currentItems = data.items;

        document.getElementById('printDateLabel').textContent = 'Datum: ' + formatDate(datum);

        // Status bar
        const bar = document.getElementById('statusBar');
        bar.style.display = 'flex';
        if (data.is_late_edit) {
            bar.className = 'status-bar status-bar-late';
            bar.innerHTML = '<i class="bi bi-clock-history" style="color:#e65100;"></i>'
                + ' <strong>Let op:</strong> U bewerkt een lijst van een verstreken datum. Wijzigingen worden gelogd in het audit log.';
        } else {
            bar.className = 'status-bar status-bar-normal';
            const icon = statusIcon(currentList.status);
            bar.innerHTML = `${icon} <strong>Status:</strong> ${formatStatus(currentList.status)}`
                + ` &nbsp;|&nbsp; <strong>Datum:</strong> ${formatDate(datum)}`;
        }

        renderChecklist();
        document.getElementById('listPanel').style.display = 'block';

    } catch (e) {
        document.getElementById('listLoading').innerHTML =
            `<i class="bi bi-exclamation-triangle" style="color:#c62828;"></i> Fout: ${e.message}`;
    }
}

// ==================== CREATE LIST ====================
async function createList() {
    const datum = document.getElementById('listDate').value;

    document.getElementById('noListPanel').style.display = 'none';
    document.getElementById('listLoading').style.display = 'flex';

    try {
        const res  = await fetch(API, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'create_list', datum }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Fout bij aanmaken');

        currentList  = data.lijst;
        currentItems = data.items;

        document.getElementById('listLoading').style.display = 'none';
        document.getElementById('saveBtn').style.display     = '';
        document.getElementById('printDateLabel').textContent = 'Datum: ' + formatDate(datum);

        const bar = document.getElementById('statusBar');
        bar.style.display = 'flex';
        if (data.is_late_edit) {
            bar.className = 'status-bar status-bar-late';
            bar.innerHTML = '<i class="bi bi-clock-history" style="color:#e65100;"></i>'
                + ' <strong>Let op:</strong> U bewerkt een lijst van een verstreken datum. Wijzigingen worden gelogd in het audit log.';
        } else {
            bar.className = 'status-bar status-bar-normal';
            bar.innerHTML = `📋 <strong>Status:</strong> Onvolledig &nbsp;|&nbsp; <strong>Datum:</strong> ${formatDate(datum)}`;
        }

        renderChecklist();
        document.getElementById('listPanel').style.display = 'block';

    } catch (e) {
        document.getElementById('listLoading').innerHTML =
            `<i class="bi bi-exclamation-triangle" style="color:#c62828;"></i> Fout: ${e.message}`;
    }
}

// ==================== RENDER CHECKLIST ====================
function renderChecklist() {
    const body  = document.getElementById('checklistBody');
    const empty = document.getElementById('emptyList');
    const table = document.getElementById('checklistTable');
    const datum = document.getElementById('listDate').value;

    document.getElementById('panelTitle').textContent = 'Checklist — ' + formatDate(datum);

    if (!currentItems || currentItems.length === 0) {
        body.innerHTML = '';
        table.style.display = 'none';
        empty.style.display = 'block';
        updateProgress();
        return;
    }

    table.style.display = 'table';
    empty.style.display = 'none';

    body.innerHTML = currentItems.map((item, i) => {
        const checked  = parseInt(item.afgevinkt) === 1;
        const overdue  = item.due_date && item.due_date <= datum && !checked;
        const rowClass = checked ? 'item-checked' : (overdue ? 'item-overdue' : '');
        const tijdstip = item.tijdstip_afgerond ? item.tijdstip_afgerond.substring(0, 16).replace('T', ' ') : '';

        return `<tr class="${rowClass}" id="row-${i}">
            <td style="color:#bbb; font-size:0.78rem;">${i + 1}</td>
            <td><strong>${escHtml(item.naam)}</strong></td>
            <td class="col-type"><span class="badge badge-${item.type}">${formatType(item.type)}</span></td>
            <td class="col-frequentie" style="font-size:0.82rem; color:#777;">${formatFreq(item.frequentie)}</td>
            <td><span class="due-date" style="font-size:0.85rem;">${item.due_date || '—'}</span></td>
            <td>
                <div class="cb-wrap">
                    <input type="checkbox" data-idx="${i}" ${checked ? 'checked' : ''}
                        onchange="toggleItem(${i}, this.checked)">
                </div>
            </td>
            <td class="col-notities">
                <input class="td-input" type="text" value="${escAttr(item.notities || '')}"
                    placeholder="Notities…" oninput="updateField(${i}, 'notities', this.value)">
            </td>
            <td class="col-uitvoerder">
                <input class="td-input" type="text" value="${escAttr(item.uitvoerder || '')}"
                    placeholder="Naam…" oninput="updateField(${i}, 'uitvoerder', this.value)">
            </td>
            <td class="col-tijdstip" style="font-size:0.8rem; color:#777; white-space:nowrap;">
                ${tijdstip || (checked ? '<span style="color:#2e7d32;">✓</span>' : '—')}
            </td>
        </tr>`;
    }).join('');

    updateProgress();
}

function toggleItem(idx, checked) {
    currentItems[idx].afgevinkt = checked ? 1 : 0;
    if (checked && !currentItems[idx].tijdstip_afgerond) {
        currentItems[idx].tijdstip_afgerond = new Date().toISOString().replace('T', ' ').substring(0, 19);
    } else if (!checked) {
        currentItems[idx].tijdstip_afgerond = null;
    }

    const datum   = document.getElementById('listDate').value;
    const item    = currentItems[idx];
    const overdue = item.due_date && item.due_date <= datum && !checked;
    const row     = document.getElementById(`row-${idx}`);
    if (row) row.className = checked ? 'item-checked' : (overdue ? 'item-overdue' : '');

    updateProgress();
}

function updateField(idx, field, value) {
    currentItems[idx][field] = value;
}

function updateProgress() {
    if (!currentItems) return;
    const total   = currentItems.length;
    const checked = currentItems.filter(i => parseInt(i.afgevinkt) === 1).length;
    document.getElementById('progressLabel').textContent =
        total > 0 ? `${checked} / ${total} afgevinkt` : '';
}

// ==================== SAVE LIST ====================
async function saveList(force) {
    if (!currentList) return;

    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Opslaan…';

    try {
        const res  = await fetch(API, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                action:   'save_list',
                lijst_id: currentList.id,
                items:    currentItems,
                force:    force,
            }),
        });
        const data = await res.json();

        if (data.warning) {
            // Show warning modal with overdue items
            const tbody = document.getElementById('warningItems');
            tbody.innerHTML = data.overdue_items.map(item =>
                `<tr>
                    <td>${escHtml(item.naam)}</td>
                    <td><span class="badge badge-${item.type}">${formatType(item.type)}</span></td>
                    <td style="color:#e65100; font-weight:600;">${item.due_date || '—'}</td>
                    <td style="font-size:0.82rem;">${formatFreq(item.frequentie)}</td>
                </tr>`
            ).join('');
            document.getElementById('warningModal').classList.add('open');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-floppy"></i> Opslaan';
            return;
        }

        if (!data.success) throw new Error(data.error || 'Fout bij opslaan');

        currentList.status = data.status;
        closeModal('warningModal');
        showToast('Checklist opgeslagen', 'success');

        // Update status bar
        const bar = document.getElementById('statusBar');
        if (!bar.classList.contains('status-bar-late')) {
            const datum = document.getElementById('listDate').value;
            bar.innerHTML = `${statusIcon(data.status)} <strong>Status:</strong> ${formatStatus(data.status)}`
                + ` &nbsp;|&nbsp; <strong>Datum:</strong> ${formatDate(datum)}`;
        }

    } catch (e) {
        showToast('Fout: ' + e.message, 'error');
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-floppy"></i> Opslaan';
}

// ==================== MASTER ITEMS ====================
async function loadItems() {
    document.getElementById('itemsLoading').style.display   = 'flex';
    document.getElementById('itemsTableWrap').style.display = 'none';
    document.getElementById('itemsEmpty').style.display     = 'none';

    try {
        const res  = await fetch(`${API}?action=get_items`);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Fout bij laden');
        masterItems = data.items;
        renderItems();
    } catch (e) {
        document.getElementById('itemsLoading').innerHTML =
            `<i class="bi bi-exclamation-triangle" style="color:#c62828;"></i> Fout: ${e.message}`;
        return;
    }
    document.getElementById('itemsLoading').style.display = 'none';
}

function renderItems() {
    const body = document.getElementById('itemsBody');

    if (!masterItems || masterItems.length === 0) {
        body.innerHTML = '';
        document.getElementById('itemsTableWrap').style.display = 'none';
        document.getElementById('itemsEmpty').style.display     = 'block';
        return;
    }

    document.getElementById('itemsTableWrap').style.display = 'block';
    document.getElementById('itemsEmpty').style.display     = 'none';

    body.innerHTML = masterItems.map(item => {
        const active = parseInt(item.actief) === 1;
        return `<tr class="${active ? '' : 'inactive'}">
            <td><strong>${escHtml(item.naam)}</strong></td>
            <td><span class="badge badge-${item.type}">${formatType(item.type)}</span></td>
            <td style="font-size:0.85rem; color:#666;">${formatFreq(item.frequentie)}</td>
            <td>
                <span class="badge" style="${active
                    ? 'background:#e8f5e9; color:#2e7d32;'
                    : 'background:#f5f5f5; color:#999;'}">
                    ${active ? 'Actief' : 'Inactief'}
                </span>
            </td>
            <td>
                <div style="display:flex; gap:0.4rem;">
                    <button class="btn-icon" onclick="openItemModal(${item.id})" title="Bewerken">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn-icon" onclick="toggleItemActief(${item.id}, ${active ? 0 : 1})"
                        title="${active ? 'Deactiveren' : 'Activeren'}"
                        style="color:${active ? '#c62828' : '#2e7d32'};">
                        <i class="bi bi-toggle-${active ? 'on' : 'off'}"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');
}

// ==================== ITEM MODAL ====================
function openItemModal(id) {
    document.getElementById('itemId').value           = id || '';
    document.getElementById('itemModalTitle').textContent = id ? 'Item bewerken' : 'Item toevoegen';
    document.getElementById('itemActiefGroup').style.display = id ? 'block' : 'none';

    if (id) {
        const item = masterItems.find(i => i.id == id);
        if (item) {
            document.getElementById('itemNaam').value       = item.naam;
            document.getElementById('itemType').value       = item.type;
            document.getElementById('itemFrequentie').value = item.frequentie;
            document.getElementById('itemActief').value     = item.actief;
        }
    } else {
        document.getElementById('itemNaam').value       = '';
        document.getElementById('itemType').value       = 'schoonmaak';
        document.getElementById('itemFrequentie').value = 'dagelijks';
    }

    document.getElementById('itemModal').classList.add('open');
    setTimeout(() => document.getElementById('itemNaam').focus(), 80);
}

async function saveItem() {
    const id         = document.getElementById('itemId').value;
    const naam       = document.getElementById('itemNaam').value.trim();
    const type       = document.getElementById('itemType').value;
    const frequentie = document.getElementById('itemFrequentie').value;
    const actief     = document.getElementById('itemActief').value;

    if (!naam) { showToast('Naam is verplicht', 'error'); return; }

    try {
        const res  = await fetch(API, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'save_item', id: id || null, naam, type, frequentie, actief }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Fout');

        closeModal('itemModal');
        showToast(id ? 'Item bijgewerkt' : 'Item toegevoegd', 'success');
        await loadItems();
    } catch (e) {
        showToast('Fout: ' + e.message, 'error');
    }
}

async function toggleItemActief(id, newActief) {
    const item = masterItems.find(i => i.id == id);
    if (!item) return;
    const label = newActief ? 'activeren' : 'deactiveren';
    if (!confirm(`Item "${item.naam}" ${label}?`)) return;

    try {
        const res  = await fetch(API, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'save_item', id, naam: item.naam, type: item.type, frequentie: item.frequentie, actief: newActief }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Fout');

        showToast(`Item ${newActief ? 'geactiveerd' : 'gedeactiveerd'}`, 'success');
        await loadItems();
    } catch (e) {
        showToast('Fout: ' + e.message, 'error');
    }
}

// ==================== OVERZICHT ====================
async function loadOverzicht() {
    document.getElementById('overzichtLoading').style.display   = 'flex';
    document.getElementById('overzichtTableWrap').style.display = 'none';
    document.getElementById('overzichtEmpty').style.display     = 'none';

    try {
        const res  = await fetch(`${API}?action=get_overzicht`);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Fout bij laden');

        const filterVan = document.getElementById('filterVan').value;
        const filterTot = document.getElementById('filterTot').value;

        let lijsten = data.lijsten;
        if (filterVan) lijsten = lijsten.filter(l => l.datum >= filterVan);
        if (filterTot) lijsten = lijsten.filter(l => l.datum <= filterTot);

        const body = document.getElementById('overzichtBody');

        if (!lijsten || lijsten.length === 0) {
            body.innerHTML = '';
            document.getElementById('overzichtEmpty').style.display = 'block';
            document.getElementById('overzichtLoading').style.display = 'none';
            return;
        }

        body.innerHTML = lijsten.map(l => {
            const totaal   = parseInt(l.totaal_items)    || 0;
            const afgevinkt = parseInt(l.afgevinkt_items) || 0;
            const pct      = totaal > 0 ? Math.round((afgevinkt / totaal) * 100) : 0;

            return `<tr>
                <td><strong>${formatDate(l.datum)}</strong></td>
                <td>
                    ${statusIcon(l.status)}
                    <span class="badge badge-status-${l.status}" style="margin-left:0.3rem;">
                        ${formatStatus(l.status)}
                    </span>
                </td>
                <td>
                    <div class="progress-wrap">
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" style="width:${pct}%;"></div>
                        </div>
                        <span style="font-size:0.8rem; color:#666;">${afgevinkt}/${totaal}</span>
                    </div>
                </td>
                <td class="no-print">
                    <button class="btn btn-ghost btn-sm" onclick="openListDate('${l.datum}')">
                        <i class="bi bi-eye"></i> Bekijken
                    </button>
                </td>
            </tr>`;
        }).join('');

        document.getElementById('overzichtTableWrap').style.display = 'block';

    } catch (e) {
        document.getElementById('overzichtLoading').innerHTML =
            `<i class="bi bi-exclamation-triangle" style="color:#c62828;"></i> Fout: ${e.message}`;
    }

    document.getElementById('overzichtLoading').style.display = 'none';
}

function openListDate(datum) {
    document.getElementById('listDate').value = datum;
    switchTab('daglijst');
    loadList();
}

// ==================== HELPERS ====================
function formatDate(d) {
    if (!d) return '—';
    const [y, m, day] = d.split('-');
    const months = ['jan','feb','mrt','apr','mei','jun','jul','aug','sep','okt','nov','dec'];
    return `${parseInt(day)} ${months[parseInt(m) - 1]} ${y}`;
}

function formatStatus(s) {
    return { volledig: 'Volledig', onvolledig: 'Onvolledig', afwijking: 'Afwijking' }[s] || s;
}

function statusIcon(s) {
    return { volledig: '✅', onvolledig: '❌', afwijking: '⚠️' }[s] || '📋';
}

function formatType(t) {
    return { schoonmaak: 'Schoonmaak', voorraad: 'Voorraad' }[t] || t;
}

function formatFreq(f) {
    return {
        dagelijks: 'Dagelijks',
        dagelijks_mits_gebruikt: 'Dagelijks (mits gebruikt)',
        wekelijks: 'Wekelijks',
        maandelijks: 'Maandelijks',
    }[f] || f;
}

function escHtml(str) {
    if (!str) return '';
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function escAttr(str) {
    if (!str) return '';
    return str.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = `toast show ${type || ''}`;
    setTimeout(() => { t.className = 'toast'; }, 3200);
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

// Keyboard shortcuts
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        const btn = document.getElementById('saveBtn');
        if (!btn.disabled) saveList(false);
    }
});

// ==================== INIT ====================
// Set default date range filters for overzicht (last 30 days)
(function() {
    const today    = new Date();
    const monthAgo = new Date(today);
    monthAgo.setDate(monthAgo.getDate() - 30);
    document.getElementById('filterVan').value = monthAgo.toISOString().split('T')[0];
    document.getElementById('filterTot').value = today.toISOString().split('T')[0];
})();

loadList();
</script>
</body>
</html>
