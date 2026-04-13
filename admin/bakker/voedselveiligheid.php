<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$adminPageTitle = 'Voedselveiligheid';
$currentPage   = 'schoonmaak';
$adminBasePath = '../';
ob_start(); ?>
<style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--cream); min-height: 100vh; }
        .admin-content { padding: 1.5rem; }
        @media (max-width: 768px) { .admin-content { padding: 1rem; } }

        /* Tabs */
        .tabs { display: flex; gap: 0.25rem; border-bottom: 2px solid #e0d5c7; margin-bottom: 1.5rem; overflow-x: auto; }
        .tab { padding: 0.7rem 1.2rem; cursor: pointer; font-weight: 500; color: #888; border-bottom: 3px solid transparent; margin-bottom: -2px; white-space: nowrap; transition: all 0.2s; user-select: none; display: flex; align-items: center; gap: 0.4rem; }
        .tab:hover { color: #2d4a2d; }
        .tab.active { color: #3d6b3d; border-bottom-color: #c8913a; font-weight: 700; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Panels */
        .panel { background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); padding: 1.5rem; margin-bottom: 1.5rem; }
        .panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem; }
        .panel-title { font-size: 1.05rem; font-weight: 700; color: #2d4a2d; display: flex; align-items: center; gap: 0.5rem; }
        .panel-title i { color: #c8913a; }

        /* Buttons */
        .btn { padding: 0.5rem 1rem; border: none; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; transition: all 0.2s; text-decoration: none; }
        .btn-primary { background: #3d6b3d; color: white; }
        .btn-primary:hover { background: #2d4a2d; }
        .btn-success { background: #2e7d32; color: white; }
        .btn-success:hover { background: #1b5e20; }
        .btn-danger { background: #c62828; color: white; }
        .btn-ghost { background: transparent; color: #3d6b3d; border: 2px solid #e0d5c7; }
        .btn-ghost:hover { border-color: #3d6b3d; background: #faf6f1; }
        .btn-sm { padding: 0.35rem 0.7rem; font-size: 0.8rem; }
        .btn-icon { padding: 0.3rem 0.45rem; background: transparent; border: 1px solid #e0d5c7; color: #666; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; transition: all 0.15s; font-size: 0.85rem; }
        .btn-icon:hover { background: #f5f0e8; color: #2d4a2d; border-color: #3d6b3d; }
        .btn-icon.danger:hover { background: #ffebee; color: #c62828; border-color: #c62828; }

        /* Forms */
        .form-control { width: 100%; padding: 0.5rem 0.75rem; border: 1.5px solid #e0d5c7; border-radius: 8px; font-size: 0.9rem; color: #333; background: white; font-family: inherit; }
        .form-control:focus { outline: none; border-color: #3d6b3d; box-shadow: 0 0 0 3px rgba(139,90,43,0.1); }
        select.form-control { height: 38px; }
        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; font-size: 0.85rem; font-weight: 600; color: #2d4a2d; margin-bottom: 0.35rem; }

        /* Date input */
        .date-input { padding: 0.5rem 0.75rem; border: 1.5px solid #e0d5c7; border-radius: 8px; font-size: 0.9rem; color: #333; background: white; font-family: inherit; }
        .date-input:focus { outline: none; border-color: #3d6b3d; }

        /* Status bar */
        .status-bar { display: flex; align-items: center; gap: 0.75rem; padding: 0.65rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.87rem; flex-wrap: wrap; }
        .status-bar-normal { background: #f8f5f0; border: 1px solid #e8e0d5; }
        .status-bar-late   { background: #fff8e1; border: 1px solid #ffe082; }

        /* Checklist table */
        .table-wrapper { overflow-x: auto; }
        table.checklist { width: 100%; border-collapse: collapse; font-size: 0.87rem; }
        table.checklist th { text-align: left; padding: 0.55rem 0.7rem; color: #888; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 2px solid #e8e0d5; white-space: nowrap; }
        table.checklist td { padding: 0.5rem 0.7rem; border-bottom: 1px solid #f0ebe5; vertical-align: middle; }
        table.checklist tr.cat-header td { background: #f5f0e8; font-weight: 700; color: #2d4a2d; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; padding: 0.45rem 0.7rem; border-bottom: 1px solid #e8dfd2; }
        table.checklist tr.item-checked td { background: #f0fff4; }
        table.checklist tr.item-not-due td { opacity: 0.55; }
        table.checklist tr:not(.cat-header):hover td { background: #faf8f5; }
        table.checklist tr.item-checked:hover td { background: #e8f5e9; }

        /* Inline inputs */
        .td-input { width: 100%; border: 1px solid transparent; border-radius: 6px; padding: 0.28rem 0.45rem; font-size: 0.84rem; background: transparent; color: #333; font-family: inherit; }
        .td-input:hover:not(:focus) { border-color: #e0d5c7; }
        .td-input:focus { border-color: #3d6b3d; background: white; outline: none; }

        /* Checkbox */
        .cb-wrap { display: flex; justify-content: center; align-items: center; }
        .cb-wrap input[type=checkbox] { width: 20px; height: 20px; cursor: pointer; accent-color: #2e7d32; }

        /* Badges */
        .badge { display: inline-block; padding: 0.18rem 0.5rem; border-radius: 4px; font-size: 0.72rem; font-weight: 700; white-space: nowrap; }
        .badge-due         { background: #fff3e0; color: #e65100; }
        .badge-not-due     { background: #f5f5f5; color: #9e9e9e; }
        .badge-status-volledig   { background: #e8f5e9; color: #2e7d32; }
        .badge-status-afwijking  { background: #fff3e0; color: #e65100; }
        .badge-status-onvolledig { background: #ffebee; color: #c62828; }

        /* Master items table */
        table.items { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        table.items th { padding: 0.65rem 0.75rem; text-align: left; color: #888; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 2px solid #e8e0d5; }
        table.items td { padding: 0.65rem 0.75rem; border-bottom: 1px solid #f0ebe5; vertical-align: middle; }
        table.items tr.cat-header td { background: #f5f0e8; font-weight: 700; color: #2d4a2d; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; }
        table.items tr.inactive td { opacity: 0.45; }
        table.items tr:not(.cat-header):hover td { background: #faf8f5; }

        /* Overzicht */
        table.overzicht { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        table.overzicht th { padding: 0.75rem; text-align: left; color: #888; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 2px solid #e8e0d5; }
        table.overzicht td { padding: 0.75rem; border-bottom: 1px solid #f0ebe5; vertical-align: middle; }
        table.overzicht tr:hover td { background: #faf8f5; }
        .progress-wrap { display: flex; align-items: center; gap: 0.5rem; }
        .progress-bar-bg { width: 80px; height: 6px; background: #e8e0d5; border-radius: 3px; overflow: hidden; flex-shrink: 0; }
        .progress-bar-fill { height: 100%; background: #2e7d32; border-radius: 3px; }

        /* Allergen table */
        table.allergen { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        table.allergen th { padding: 0.65rem 0.75rem; text-align: left; color: #888; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 2px solid #e8e0d5; }
        table.allergen td { padding: 0.65rem 0.75rem; border-bottom: 1px solid #f0ebe5; vertical-align: middle; }
        table.allergen tr.row-in-stock td { background: #fffbf5; }
        table.allergen tr.row-depleted td { background: #fff8f8; }
        table.allergen tr.row-cleared td { background: #f0fff4; }
        table.allergen tr:hover td { filter: brightness(0.97); }
        .allergen-days-bar { display: flex; align-items: center; gap: 0.5rem; }
        .allergen-days-bar .progress-bar-bg { width: 60px; }
        .allergen-days-bar .progress-bar-fill.low { background: #c62828; }
        .allergen-days-bar .progress-bar-fill.mid { background: #e65100; }
        .allergen-days-bar .progress-bar-fill.ok  { background: #2e7d32; }
        .release-by { font-size: 0.78rem; color: #888; display: flex; align-items: center; gap: 0.3rem; }

        /* Category management (inline in items table) */
        .cat-header-cell { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
        .cat-header-label-wrap { display: flex; align-items: center; gap: 0.35rem; }
        .cat-header-actions { display: flex; gap: 0.25rem; opacity: 0; transition: opacity 0.15s; }
        table.items tr.item-cat-header:hover .cat-header-actions { opacity: 1; }

        /* Unified products-table style for items */
        table.items tr.item-cat-header { cursor: pointer; }
        table.items tr.item-cat-header td { background: #f5f0e8; font-weight: 700; color: #2d4a2d; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e8dfd2; padding: 0.45rem 0.75rem; }
        table.items tr.item-cat-header:hover td { background: #ede8e0 !important; }
        .item-cat-chevron { display: inline-flex; align-items: center; margin-right: 0.35rem; color: #888; transition: transform 0.15s; font-size: 0.75rem; }
        .item-cat-chevron.collapsed { transform: rotate(-90deg); }
        .category-header-count { display: inline-block; background: #e8dfd2; color: #5c3d1e; font-size: 0.7rem; font-weight: 700; border-radius: 10px; padding: 0.1rem 0.4rem; margin-left: 0.3rem; vertical-align: middle; }
        table.items tr.item-row td { background: #fafaf8; border-bottom: 1px solid #f0ebe5; }
        table.items tr.item-row { cursor: pointer; }
        table.items tr.item-row:hover td { background: #f5f2ed; }
        table.items tr.item-row.item-inactive td { opacity: 0.45; }
        .item-naam { display: flex; align-items: center; gap: 0.4rem; padding-left: 0.5rem; color: #4a433d; font-size: 0.9rem; }
        .drag-cell { width: 28px; padding-right: 0 !important; }
        .drag-handle { color: #ccc; cursor: grab; padding: 0 0.25rem; font-size: 1rem; display: inline-flex; align-items: center; }
        .drag-handle:active { cursor: grabbing; }
        table.items tr.drag-over td { background: #dbeafe !important; }
        table.items tr.dragging { opacity: 0.4; }
        table.items tr.item-add-row td { background: #fafaf8; border-bottom: 1px solid #e8dfd2; padding: 0.3rem 0.75rem !important; }
        table.items tr.item-add-row { cursor: pointer; }
        table.items tr.item-add-row:hover td { background: #f5f2ed; }
        .btn-add { border: 1px dashed #d1d5db; border-radius: 4px; background: transparent; color: #9ca3af; cursor: pointer; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.25rem 0.5rem; }
        .btn-add:hover { border-color: #3d6b3d; color: #3d6b3d; background: #f0f7f0; }
        tr.item-edit-row td { background: #f0ece6; border-bottom: 1px solid #c8bfb5; padding: 0.75rem !important; }
        .ie-form { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: flex-end; }
        .ie-form input[type="text"] { padding: 0.4rem 0.6rem; border: 1.5px solid #d4c8b8; border-radius: 6px; font-size: 0.88rem; font-family: inherit; background: white; width: 200px; }
        .ie-form select { padding: 0.4rem 0.6rem; border: 1.5px solid #d4c8b8; border-radius: 6px; font-size: 0.88rem; font-family: inherit; background: white; }
        .ie-form input[type="text"]:focus, .ie-form select:focus { outline: none; border-color: #3d6b3d; }
        .ie-check-label { font-size: 0.84rem; display: flex; align-items: center; gap: 0.4rem; color: #333; white-space: nowrap; }
        .ie-actions { display: flex; gap: 0.4rem; align-items: center; margin-top: 0.35rem; width: 100%; }
        .ie-spacer { flex: 1; }

        /* Empty state */
        .empty-state { text-align: center; padding: 2.5rem 1rem; color: #999; }
        .empty-state i { font-size: 2.2rem; margin-bottom: 0.6rem; display: block; color: #c8913a; }
        .empty-state p { font-size: 0.92rem; margin-bottom: 1rem; }

        /* Loading */
        .loading { display: flex; align-items: center; gap: 0.5rem; color: #888; padding: 2rem; justify-content: center; font-size: 0.9rem; }

        /* Modal */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-overlay.open { display: flex; }
        .modal { background: white; border-radius: 12px; padding: 1.75rem; max-width: 540px; width: 92%; max-height: 88vh; overflow-y: auto; }
        .modal-title { font-size: 1.05rem; font-weight: 700; color: #2d4a2d; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        .modal-body { margin-bottom: 1.25rem; }
        .modal-footer { display: flex; gap: 0.75rem; justify-content: flex-end; flex-wrap: wrap; }

        /* Formulier modal (large) */
        .formulier-modal { max-width: 980px; width: 96%; max-height: 94vh; padding: 0; display: flex; flex-direction: column; }
        .fmodal-header { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; padding: 1rem 1.25rem; border-bottom: 1px solid #e8e0d5; flex-shrink: 0; }
        .fmodal-title { font-size: 1rem; font-weight: 700; color: #2d4a2d; display: flex; align-items: center; gap: 0.4rem; white-space: nowrap; }
        .fmodal-title i { color: #c8913a; }
        .fmodal-divider { width: 1px; height: 22px; background: #e0d5c7; flex-shrink: 0; }
        .fmodal-body { overflow-y: auto; padding: 1.25rem; flex: 1; }

        .warning-table { width: 100%; border-collapse: collapse; font-size: 0.84rem; margin-top: 0.75rem; }
        .warning-table th { padding: 0.45rem 0.6rem; text-align: left; background: #fff3e0; color: #e65100; font-size: 0.74rem; text-transform: uppercase; }
        .warning-table td { padding: 0.45rem 0.6rem; border-bottom: 1px solid #f0ebe5; }

        /* Toast */
        .toast { position: fixed; bottom: 1.5rem; right: 1.5rem; background: #333; color: white; padding: 0.7rem 1.2rem; border-radius: 8px; font-size: 0.88rem; z-index: 9999; opacity: 0; transition: opacity 0.3s; pointer-events: none; max-width: 320px; }
        .toast.show { opacity: 1; }
        .toast.success { background: #2e7d32; }
        .toast.error   { background: #c62828; }

        /* Responsive checklist columns */
        @media (max-width: 960px) { .col-notities, .col-uitvoerder, .col-tijdstip { display: none; } }
        @media (max-width: 640px)  { .col-frequentie { display: none; } }

        /* Print */
        @media print {
            body > * { display: none !important; }
            body > #formulierModal { display: flex !important; position: static !important; background: none !important; }
            .formulier-modal { max-width: 100% !important; width: 100% !important; max-height: none !important; overflow: visible !important; box-shadow: none !important; border-radius: 0 !important; }
            .fmodal-header { display: none !important; }
            .fmodal-body { overflow: visible !important; padding: 0 !important; }
            .status-bar { display: none !important; }
            .print-header { display: block !important; }
            .table-wrapper { overflow: visible !important; }
            table.checklist { font-size: 9pt; width: 100% !important; }
            .col-notities, .col-uitvoerder, .col-tijdstip, .col-frequentie { display: table-cell !important; }
            table.checklist tr.item-not-due td { opacity: 1; }
            .td-input { border: none !important; }
            .no-print { display: none !important; }
        }
        .print-header { display: none; margin-bottom: 1rem; text-align: center; border-bottom: 2px solid #333; padding-bottom: 0.75rem; }
        .print-header h2 { font-size: 1.2rem; margin: 0 0 0.2rem; }
        .print-header p  { font-size: 0.85rem; color: #555; }

        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .spin { animation: spin 0.8s linear infinite; display: inline-block; }
    </style>
<?php $adminExtraHead = ob_get_clean();
require_once '../components/sidebar.php'; ?>

            <header class="topbar">
                <div class="topbar-left">
                    <span class="topbar-title"><i class="bi bi-check2-square"></i> Voedselveiligheid</span>
                </div>
                <div class="topbar-right" style="display:flex;gap:0.5rem;">
                    <button class="btn btn-ghost btn-sm" onclick="openNewCatModal()"><i class="bi bi-plus-lg"></i> Nieuwe Categorie</button>
                    <button class="btn btn-primary btn-sm" onclick="switchTab('items'); openItemModal()"><i class="bi bi-plus-lg"></i> Nieuw Item</button>
                </div>
            </header>

    <div class="admin-content">

        <div class="tabs">
            <div class="tab active" onclick="switchTab('overzicht')"><i class="bi bi-calendar3"></i> Overzicht</div>
            <div class="tab" onclick="switchTab('items')"><i class="bi bi-list-ul"></i> Items beheer</div>
            <div class="tab" onclick="switchTab('allergenen')"><i class="bi bi-shield-exclamation"></i> Sporenallergenen</div>
        </div>

        <!-- ==================== OVERZICHT TAB ==================== -->
        <div id="tab-overzicht" class="tab-content active">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="bi bi-calendar3"></i> Overzicht formulieren</div>
                    <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                        <label style="font-size:0.83rem; color:#666;">Van:</label>
                        <input type="date" class="date-input" id="filterVan" style="font-size:0.83rem;">
                        <label style="font-size:0.83rem; color:#666;">Tot:</label>
                        <input type="date" class="date-input" id="filterTot" style="font-size:0.83rem;">
                        <button class="btn btn-ghost btn-sm" onclick="loadOverzicht()"><i class="bi bi-search"></i> Zoeken</button>
                        <button class="btn btn-primary" onclick="nieuwFormulier()">
                            <i class="bi bi-plus-lg"></i> Nieuw formulier
                        </button>
                    </div>
                </div>
                <div id="overzichtLoading" class="loading"><i class="bi bi-arrow-clockwise spin"></i> Laden…</div>
                <div class="table-wrapper" id="overzichtTableWrap" style="display:none;">
                    <table class="overzicht">
                        <thead>
                            <tr><th>Datum</th><th>Status</th><th>Voortgang</th><th></th></tr>
                        </thead>
                        <tbody id="overzichtBody"></tbody>
                    </table>
                </div>
                <div id="overzichtEmpty" class="empty-state" style="display:none;">
                    <i class="bi bi-calendar-x"></i>
                    <p>Geen formulieren gevonden. Klik op <strong>Nieuw formulier</strong> om te beginnen.</p>
                </div>
            </div>
        </div>

        <!-- ==================== ITEMS BEHEER TAB ==================== -->
        <div id="tab-items" class="tab-content">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="bi bi-list-ul"></i> Schoonmaakitems per categorie</div>
                </div>
                <div id="itemsLoading" class="loading"><i class="bi bi-arrow-clockwise spin"></i> Laden…</div>
                <div class="table-wrapper" id="itemsTableWrap" style="display:none;">
                    <table class="items">
                        <thead>
                            <tr>
                                <th class="drag-cell"></th>
                                <th>Item naam</th>
                                <th>Frequentie</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody"></tbody>
                    </table>
                </div>
                <div id="itemsEmpty" class="empty-state" style="display:none;">
                    <i class="bi bi-inbox"></i>
                    <p>Nog geen items. Voeg eerst een categorie toe, dan kun je items toevoegen.</p>
                </div>
            </div>
        </div>

        <!-- ==================== SPORENALLERGENEN TAB ==================== -->
        <div id="tab-allergenen" class="tab-content">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="bi bi-shield-exclamation"></i> Sporenallergenen Status</div>
                </div>
                <div id="allergenLoading" class="loading" style="padding:1rem;"><i class="bi bi-arrow-clockwise spin"></i> Laden…</div>
                <div id="allergenContent" style="display:none;">
                    <div style="font-size:0.85rem; color:#666; margin-bottom:1rem; padding:0.75rem 1rem; background:#faf6f1; border:1px solid #e8dfd2; border-radius:8px; display:flex; gap:0.6rem; align-items:flex-start;">
                        <i class="bi bi-info-circle" style="color:#c8913a; margin-top:0.1rem; flex-shrink:0;"></i>
                        <span>Een ingrediënt wordt als <strong>sporenallergeen</strong> getoond op productpagina's zolang het op voorraad is, en daarna nog <strong>60 dagen</strong> na uitputting — tenzij alle allergeen-kritische schoonmaakitems zijn afgerond én de 60 dagen zijn verstreken.</span>
                    </div>
                    <div class="table-wrapper" id="allergenTableWrap" style="display:none;">
                        <table class="allergen">
                            <thead>
                                <tr>
                                    <th>Allergeen</th>
                                    <th>Status</th>
                                    <th>Uitgeput sinds</th>
                                    <th>Resterende periode</th>
                                    <th>Allergeen-kritische schoonmaak</th>
                                    <th>Actie</th>
                                </tr>
                            </thead>
                            <tbody id="allergenBody"></tbody>
                        </table>
                    </div>
                    <div id="allergenEmpty" class="empty-state" style="display:none; padding:1.5rem;">
                        <i class="bi bi-check-circle" style="color:#2e7d32; font-size:2rem;"></i>
                        <p>Geen sporenallergenen geconfigureerd.</p>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ==================== FORMULIER MODAL ==================== -->
<div class="modal-overlay" id="formulierModal">
    <div class="modal formulier-modal">

        <!-- Header: title + date nav + progress + save + close -->
        <div class="fmodal-header no-print">
            <div class="fmodal-title"><i class="bi bi-clipboard-check"></i> Formulier</div>
            <div class="fmodal-divider"></div>
            <button class="btn btn-ghost btn-sm" onclick="changeDate(-1)"><i class="bi bi-chevron-left"></i></button>
            <input type="date" class="date-input" id="listDate" value="<?= date('Y-m-d') ?>" onchange="loadList()" style="font-size:0.88rem;">
            <button class="btn btn-ghost btn-sm" onclick="changeDate(1)"><i class="bi bi-chevron-right"></i></button>
            <button class="btn btn-ghost btn-sm" onclick="goToday()"><i class="bi bi-calendar-check"></i> Vandaag</button>
            <div style="margin-left:auto; display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                <span id="progressLabel" style="font-size:0.82rem; color:#666;"></span>
                <label style="display:flex; align-items:center; gap:0.3rem; font-size:0.82rem; color:#666; cursor:pointer; user-select:none; white-space:nowrap;">
                    <input type="checkbox" id="showNonDue" onchange="renderChecklist()" style="cursor:pointer; accent-color:#3d6b3d;">
                    Niet-due
                </label>
                <button class="btn btn-ghost btn-sm" onclick="window.print()" title="Printen"><i class="bi bi-printer"></i></button>
                <button class="btn btn-success btn-sm" id="saveBtn" style="display:none;" onclick="saveList(false)">
                    <i class="bi bi-floppy"></i> Opslaan
                </button>
                <button class="btn-icon" onclick="closeModal('formulierModal')" title="Sluiten"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>

        <!-- Body -->
        <div class="fmodal-body">

            <div class="print-header">
                <h2>Bakkerij Civetta — Voedselveiligheid</h2>
                <p id="printDateLabel"></p>
            </div>

            <div id="statusBar" style="display:none;"></div>

            <div id="listLoading" class="loading">
                <i class="bi bi-arrow-clockwise spin"></i> Laden…
            </div>

            <!-- No form yet -->
            <div id="noListPanel" style="display:none;">
                <div class="empty-state">
                    <i class="bi bi-clipboard-x"></i>
                    <p>Er is nog geen formulier voor <strong id="noListDate"></strong>.</p>
                    <button class="btn btn-primary" onclick="createList()">
                        <i class="bi bi-plus-lg"></i> Formulier aanmaken
                    </button>
                </div>
            </div>

            <!-- Checklist -->
            <div id="listPanel" style="display:none;">

                <div class="table-wrapper">
                    <table class="checklist" id="checklistTable">
                        <thead>
                            <tr>
                                <th style="width:28px;">#</th>
                                <th>Item naam</th>
                                <th class="col-frequentie">Frequentie</th>
                                <th>Status</th>
                                <th style="width:68px; text-align:center;">Afgevinkt</th>
                                <th class="col-notities">Notities</th>
                                <th class="col-uitvoerder">Uitvoerder</th>
                                <th class="col-tijdstip">Tijdstip</th>
                            </tr>
                        </thead>
                        <tbody id="checklistBody"></tbody>
                    </table>
                </div>

                <div id="emptyList" class="empty-state" style="display:none; padding:1.5rem;">
                    <i class="bi bi-inbox"></i>
                    <p>Geen items geconfigureerd.</p>
                    <div style="display:flex; gap:0.5rem; justify-content:center; flex-wrap:wrap;">
                        <button class="btn btn-ghost btn-sm" onclick="closeModal('formulierModal'); switchTab('items');">
                            <i class="bi bi-list-ul"></i> Naar Items beheer
                        </button>
                        <button class="btn btn-primary btn-sm" onclick="refreshList()">
                            <i class="bi bi-arrow-clockwise"></i> Formulier vernieuwen
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- WARNING MODAL -->
<div class="modal-overlay" id="warningModal">
    <div class="modal">
        <div class="modal-title"><i class="bi bi-exclamation-triangle-fill" style="color:#e65100;"></i> Openstaande items</div>
        <div class="modal-body">
            <p>De volgende items zijn als <strong>due</strong> gemarkeerd maar nog niet afgevinkt.</p>
            <table class="warning-table">
                <thead><tr><th>Item</th><th>Frequentie</th></tr></thead>
                <tbody id="warningItems"></tbody>
            </table>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal('warningModal')"><i class="bi bi-arrow-left"></i> Terug</button>
            <button class="btn btn-danger" onclick="saveList(true)"><i class="bi bi-exclamation-triangle"></i> Toch opslaan (afwijking)</button>
        </div>
    </div>
</div>

<!-- ITEM MODAL -->
<div class="modal-overlay" id="itemModal">
    <div class="modal">
        <div class="modal-title"><i class="bi bi-pencil-square" style="color:#3d6b3d;"></i> <span id="itemModalTitle">Item toevoegen</span></div>
        <div class="modal-body">
            <input type="hidden" id="itemId">
            <div class="form-group">
                <label class="form-label">Item naam *</label>
                <input type="text" class="form-control" id="itemNaam" placeholder="Bijv. Werkblad reinigen">
            </div>
            <div class="form-group">
                <label class="form-label">Categorie</label>
                <select class="form-control" id="itemCategorie"></select>
            </div>
            <div class="form-group">
                <label class="form-label">Frequentie *</label>
                <select class="form-control" id="itemFrequentie">
                    <option value="dagelijks">Dagelijks</option>
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
            <div class="form-group">
                <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
                    <input type="checkbox" id="itemAllergeenKritisch" style="cursor:pointer; accent-color:#3d6b3d;">
                    <span>Allergeen-kritisch</span>
                </label>
                <div style="font-size:0.78rem; color:#888; margin-top:0.25rem;">
                    Moet afgerond zijn voordat sporenallergenen vrijgegeven kunnen worden na voorraaduitputting.
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal('itemModal')">Annuleren</button>
            <button class="btn btn-primary" onclick="saveItem()"><i class="bi bi-floppy"></i> Opslaan</button>
        </div>
    </div>
</div>

<!-- CAT EDIT MODAL -->
<div class="modal-overlay" id="catModal">
    <div class="modal" style="max-width:380px;">
        <div class="modal-title"><i class="bi bi-tag" style="color:#3d6b3d;"></i> <span id="catModalTitle">Categorie bewerken</span></div>
        <div class="modal-body">
            <input type="hidden" id="catEditId">
            <div class="form-group">
                <label class="form-label">Naam *</label>
                <input type="text" class="form-control" id="catEditNaam">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal('catModal')">Annuleren</button>
            <button class="btn btn-primary" onclick="saveCatEdit()"><i class="bi bi-floppy"></i> Opslaan</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script src="../../js/ui-notifications.js?v=1"></script>
<script>
const API = '../../api/voedselveiligheid.php';

let currentList  = null;
const collapsedItemCategories = new Set();
let draggingItem = null;
let activeItemEditTr = null;
let currentItems = [];
let masterItems  = [];
let categorieen  = [];

// ==================== TABS ====================
function switchTab(tab) {
    const names = ['overzicht', 'items', 'allergenen'];
    document.querySelectorAll('.tabs .tab').forEach((el, i) => el.classList.toggle('active', names[i] === tab));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.toggle('active', c.id === 'tab-' + tab));
    if (tab === 'items') loadItemsTab();
    if (tab === 'allergenen') loadAllergenStatus();
}

// ==================== FORMULIER MODAL ====================
function openFormulierModal() {
    document.getElementById('formulierModal').classList.add('open');
}

function nieuwFormulier() {
    document.getElementById('listDate').value = '<?= date('Y-m-d') ?>';
    openFormulierModal();
    loadList();
}

// ==================== DATE NAV ====================
function changeDate(delta) {
    const d = new Date(document.getElementById('listDate').value);
    d.setDate(d.getDate() + delta);
    document.getElementById('listDate').value = d.toISOString().split('T')[0];
    loadList();
}
function goToday() {
    document.getElementById('listDate').value = '<?= date('Y-m-d') ?>';
    loadList();
}

// ==================== LOAD LIST ====================
async function loadList() {
    const datum = document.getElementById('listDate').value;
    showEl('listLoading'); hideEl('listPanel'); hideEl('noListPanel'); hideEl('statusBar');
    document.getElementById('saveBtn').style.display = 'none';
    try {
        const data = await callApi(`?action=get_list&datum=${datum}`);
        hideEl('listLoading');
        document.getElementById('printDateLabel').textContent = 'Datum: ' + formatDate(datum);
        if (!data.exists) {
            document.getElementById('noListDate').textContent = formatDate(datum);
            showEl('noListPanel'); return;
        }
        applyListData(data, datum);
    } catch (e) {
        document.getElementById('listLoading').innerHTML =
            `<i class="bi bi-exclamation-triangle" style="color:#c62828;"></i> Fout: ${e.message}`;
    }
}

// ==================== CREATE LIST ====================
async function createList() {
    const datum = document.getElementById('listDate').value;
    hideEl('noListPanel'); showEl('listLoading');
    try {
        const data = await callApi(null, { action: 'create_list', datum });
        hideEl('listLoading');
        document.getElementById('printDateLabel').textContent = 'Datum: ' + formatDate(datum);
        applyListData(data, datum);
    } catch (e) {
        document.getElementById('listLoading').innerHTML =
            `<i class="bi bi-exclamation-triangle" style="color:#c62828;"></i> Fout: ${e.message}`;
    }
}

// ==================== REFRESH LIST ====================
async function refreshList() {
    if (!currentList) return;
    if (!await showConfirm('Formulier opnieuw opbouwen met de huidige actieve items?')) return;
    showEl('listLoading'); hideEl('listPanel');
    try {
        const datum = document.getElementById('listDate').value;
        const data  = await callApi(null, { action: 'refresh_list', lijst_id: currentList.id });
        hideEl('listLoading');
        applyListData(data, datum);
    } catch (e) {
        document.getElementById('listLoading').innerHTML =
            `<i class="bi bi-exclamation-triangle" style="color:#c62828;"></i> Fout: ${e.message}`;
    }
}

function applyListData(data, datum) {
    currentList  = data.lijst;
    currentItems = data.items;

    const bar = document.getElementById('statusBar');
    bar.style.display = 'flex';
    if (data.is_late_edit) {
        bar.className = 'status-bar status-bar-late';
        bar.innerHTML = '<i class="bi bi-clock-history" style="color:#e65100;"></i>'
            + ' <strong>Let op:</strong> Verstreken datum. Wijzigingen worden gelogd.';
    } else {
        bar.className = 'status-bar status-bar-normal';
        bar.innerHTML = `${statusIcon(currentList.status)} <strong>Status:</strong> ${formatStatus(currentList.status)}`
            + ` &nbsp;|&nbsp; <strong>${formatDate(datum)}</strong>`;
    }

    document.getElementById('saveBtn').style.display = '';
    renderChecklist();
    showEl('listPanel');
}

// ==================== RENDER CHECKLIST ====================
function renderChecklist() {
    const body       = document.getElementById('checklistBody');
    const table      = document.getElementById('checklistTable');
    const empty      = document.getElementById('emptyList');
    const showNonDue = document.getElementById('showNonDue').checked;

    if (!currentItems || currentItems.length === 0) {
        body.innerHTML = ''; table.style.display = 'none'; empty.style.display = 'block';
        updateProgress(); return;
    }
    table.style.display = 'table'; empty.style.display = 'none';

    // Group by category
    const groups = {};
    const order  = [];
    currentItems.forEach((item, idx) => {
        const isDue = parseInt(item.is_due) === 1;
        if (!isDue && !showNonDue) return;
        const cat = item.categorie_naam || '—';
        if (!groups[cat]) { groups[cat] = []; order.push(cat); }
        groups[cat].push({ ...item, _idx: idx });
    });

    let rowNum = 0;
    let html   = '';
    for (const cat of order) {
        html += `<tr class="cat-header"><td colspan="8"><i class="bi bi-tag-fill" style="margin-right:0.35rem;"></i>${escHtml(cat)}</td></tr>`;
        groups[cat].forEach(item => {
            rowNum++;
            const idx     = item._idx;
            const checked = parseInt(item.afgevinkt) === 1;
            const isDue   = parseInt(item.is_due) === 1;
            const rowCls  = checked ? 'item-checked' : (!isDue ? 'item-not-due' : '');
            const tijdstip = item.tijdstip_afgerond
                ? item.tijdstip_afgerond.substring(0, 16).replace('T', ' ') : '';
            const dueBadge = isDue
                ? `<span class="badge badge-due">Due</span>`
                : `<span class="badge badge-not-due">Niet due</span>`;

            html += `<tr class="${rowCls}" id="row-${idx}">
                <td style="color:#bbb; font-size:0.75rem;">${rowNum}</td>
                <td><strong>${escHtml(item.naam)}</strong></td>
                <td class="col-frequentie" style="font-size:0.8rem; color:#777;">${formatFreq(item.frequentie)}</td>
                <td>${dueBadge}</td>
                <td>
                    <div class="cb-wrap">
                        <input type="checkbox" ${checked ? 'checked' : ''} onchange="toggleItem(${idx}, this.checked)">
                    </div>
                </td>
                <td class="col-notities">
                    <input class="td-input" type="text" value="${escAttr(item.notities || '')}"
                        placeholder="Notities…" oninput="updateField(${idx}, 'notities', this.value)">
                </td>
                <td class="col-uitvoerder">
                    <input class="td-input" type="text" value="${escAttr(item.uitvoerder || '')}"
                        placeholder="Naam…" oninput="updateField(${idx}, 'uitvoerder', this.value)">
                </td>
                <td class="col-tijdstip" style="font-size:0.79rem; color:#777; white-space:nowrap;">
                    ${tijdstip || (checked ? '<span style="color:#2e7d32;">✓</span>' : '—')}
                </td>
            </tr>`;
        });
    }

    if (!html) {
        html = `<tr><td colspan="8" style="text-align:center; padding:1.5rem; color:#888; font-size:0.88rem;">
            Alle items zijn momenteel <strong>niet due</strong>. Zet "Niet-due" aan om ze toch te zien.
        </td></tr>`;
    }

    body.innerHTML = html;
    updateProgress();
}

function toggleItem(idx, checked) {
    currentItems[idx].afgevinkt = checked ? 1 : 0;
    if (checked && !currentItems[idx].tijdstip_afgerond) {
        currentItems[idx].tijdstip_afgerond = new Date().toISOString().replace('T', ' ').substring(0, 19);
    } else if (!checked) {
        currentItems[idx].tijdstip_afgerond = null;
    }
    const row   = document.getElementById(`row-${idx}`);
    const isDue = parseInt(currentItems[idx].is_due) === 1;
    if (row) row.className = checked ? 'item-checked' : (!isDue ? 'item-not-due' : '');
    updateProgress();
}

function updateField(idx, field, val) { currentItems[idx][field] = val; }

function updateProgress() {
    if (!currentItems) return;
    const due       = currentItems.filter(i => parseInt(i.is_due) === 1).length;
    const total     = currentItems.length;
    const doneTotal = currentItems.filter(i => parseInt(i.afgevinkt) === 1).length;
    const doneDue   = currentItems.filter(i => parseInt(i.is_due) === 1 && parseInt(i.afgevinkt) === 1).length;
    document.getElementById('progressLabel').textContent =
        total > 0 ? `${doneDue}/${due} due · ${doneTotal}/${total} totaal` : '';
}

// ==================== SAVE ====================
async function saveList(force) {
    if (!currentList) return;
    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';

    try {
        const data = await callApi(null, {
            action: 'save_list', lijst_id: currentList.id, items: currentItems, force,
        });

        if (data.warning) {
            document.getElementById('warningItems').innerHTML = data.overdue_items.map(i =>
                `<tr>
                    <td>${escHtml(i.naam)}</td>
                    <td style="font-size:0.82rem;">${formatFreq(i.frequentie)}</td>
                </tr>`
            ).join('');
            document.getElementById('warningModal').classList.add('open');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-floppy"></i> Opslaan';
            return;
        }

        currentList.status = data.status;
        closeModal('warningModal');
        showToast('Formulier opgeslagen', 'success');

        const bar   = document.getElementById('statusBar');
        const datum = document.getElementById('listDate').value;
        if (!bar.classList.contains('status-bar-late')) {
            bar.innerHTML = `${statusIcon(data.status)} <strong>Status:</strong> ${formatStatus(data.status)}`
                + ` &nbsp;|&nbsp; <strong>${formatDate(datum)}</strong>`;
        }
        loadOverzicht();
    } catch (e) { showToast('Fout: ' + e.message, 'error'); }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-floppy"></i> Opslaan';
}

// ==================== ITEMS TAB ====================
async function loadItemsTab() {
    await Promise.all([loadCategorieen(), loadItems()]);
}

// ── Categories ───────────────────────────────────────────────────────────────
async function loadCategorieen() {
    try {
        const data = await callApi('?action=get_categorieen');
        categorieen = data.categorieen;
        renderCategorieen();
    } catch (e) { /* silent */ }
}

function renderCategorieen() {
    // Categories are now rendered inline in renderItems() — nothing to do here separately.
}


function openNewCatModal() {
    switchTab('items');
    document.getElementById('catModalTitle').textContent = 'Nieuwe categorie';
    document.getElementById('catEditId').value   = '';
    document.getElementById('catEditNaam').value = '';
    document.getElementById('catModal').classList.add('open');
    setTimeout(() => document.getElementById('catEditNaam').focus(), 80);
}

function openCatEdit(id) {
    const c = categorieen.find(x => x.id == id);
    if (!c) return;
    document.getElementById('catModalTitle').textContent = 'Categorie bewerken';
    document.getElementById('catEditId').value   = id;
    document.getElementById('catEditNaam').value = c.naam;
    document.getElementById('catModal').classList.add('open');
    setTimeout(() => document.getElementById('catEditNaam').focus(), 80);
}

async function saveCatEdit() {
    const id   = document.getElementById('catEditId').value;
    const naam = document.getElementById('catEditNaam').value.trim();
    if (!naam) return;
    const isNew = !id;
    try {
        await callApi(null, { action: 'save_categorie', id, naam });
        closeModal('catModal');
        await loadCategorieen();
        await loadItems();
        showToast(isNew ? 'Categorie toegevoegd' : 'Categorie bijgewerkt', 'success');
    } catch (e) { showToast('Fout: ' + e.message, 'error'); }
}

async function deleteCategorie(id) {
    const c = categorieen.find(x => x.id == id);
    if (!await showConfirm(`Categorie "${c?.naam}" verwijderen? Items worden niet verwijderd.`)) return;
    try {
        await callApi(null, { action: 'delete_categorie', id });
        await loadCategorieen();
        await loadItems();
        showToast('Categorie verwijderd', 'success');
    } catch (e) { showToast('Fout: ' + e.message, 'error'); }
}

// ── Master items ──────────────────────────────────────────────────────────────
async function loadItems() {
    showEl('itemsLoading'); hideEl('itemsTableWrap'); hideEl('itemsEmpty');
    try {
        const data = await callApi('?action=get_items');
        masterItems = data.items;
        renderItems();
    } catch (e) {
        document.getElementById('itemsLoading').innerHTML =
            `<i class="bi bi-exclamation-triangle" style="color:#c62828;"></i> Fout: ${e.message}`;
        return;
    }
    hideEl('itemsLoading');
}

function renderItems() {
    const body = document.getElementById('itemsBody');
    const hasCats = categorieen && categorieen.length > 0;
    if ((!masterItems || masterItems.length === 0) && !hasCats) {
        body.innerHTML = ''; hideEl('itemsTableWrap'); showEl('itemsEmpty'); return;
    }
    showEl('itemsTableWrap'); hideEl('itemsEmpty');

    const catById = {};
    (categorieen || []).forEach(c => { catById[c.id] = c; });

    // Build groups keyed by catId, in category order
    const groups = {};
    (categorieen || []).forEach(c => { groups[c.id] = { naam: c.naam, items: [], catObj: c }; });

    masterItems.forEach(item => {
        const cid = item.categorie_id || '_none';
        if (!groups[cid]) groups[cid] = { naam: item.categorie_naam || '—', items: [], catObj: null };
        groups[cid].items.push(item);
    });

    // Category display order: categories first (already ordered by volgorde from API), then uncategorised
    const order = (categorieen || []).map(c => c.id);
    if (groups['_none'] && groups['_none'].items.length > 0) order.push('_none');

    let html = '';
    for (const catId of order) {
        const group = groups[catId];
        if (!group) continue;
        const catObj    = group.catObj;
        const isCollapsed = collapsedItemCategories.has(String(catId));
        const chevronCls = 'item-cat-chevron' + (isCollapsed ? ' collapsed' : '');
        const hiddenStyle = isCollapsed ? ' style="display:none"' : '';

        html += `<tr class="item-cat-header" data-cat-id="${catId}" onclick="toggleItemCategory('${catId}')">
            <td class="drag-cell"></td>
            <td colspan="3">
                <div class="cat-header-cell">
                    <span class="cat-header-label-wrap">
                        <i class="bi bi-chevron-down ${chevronCls}"></i>
                        <i class="bi bi-tag-fill" style="color:#c8913a;margin-left:0.1rem;"></i>
                        ${escHtml(group.naam)}
                        <span class="category-header-count">${group.items.length}</span>
                        ${group.items.filter(i => !parseInt(i.actief)).length > 0 ? `<span style="font-size:0.7rem;color:#bbb;font-weight:400;margin-left:0.25rem;">(${group.items.filter(i => !parseInt(i.actief)).length} inactief)</span>` : ''}
                    </span>
                    ${catObj ? `<div class="cat-header-actions">
                        <button class="btn-icon" onclick="event.stopPropagation();openCatEdit(${catObj.id})" title="Naam wijzigen"><i class="bi bi-pencil"></i></button>
                        <button class="btn-icon danger" onclick="event.stopPropagation();deleteCategorie(${catObj.id})" title="Verwijderen"><i class="bi bi-trash"></i></button>
                    </div>` : ''}
                </div>
            </td>
        </tr>`;

        group.items.forEach(item => {
            const active   = parseInt(item.actief) === 1;
            const kritisch = parseInt(item.is_allergeen_kritisch) === 1;
            html += `<tr class="item-row${active ? '' : ' item-inactive'}" data-id="${item.id}" data-cat-id="${catId}"
                draggable="true" onclick="if(!this._dragged) openItemInlineEdit(this)" title="Klik om te bewerken"${hiddenStyle}>
                <td class="drag-cell"><span class="drag-handle" onclick="event.stopPropagation()"><i class="bi bi-grip-vertical"></i></span></td>
                <td>
                    <div class="item-naam">
                        ${escHtml(item.naam)}
                        ${kritisch ? '<span class="badge" style="background:#fff3e0;color:#e65100;font-size:0.7rem;margin-left:0.3rem;">Allergeen-kritisch</span>' : ''}
                    </div>
                </td>
                <td style="font-size:0.84rem;color:#666;">${formatFreq(item.frequentie)}</td>
                <td><span class="badge" style="${active ? 'background:#e8f5e9;color:#2e7d32;' : 'background:#f5f5f5;color:#999;'}">${active ? 'Actief' : 'Inactief'}</span></td>
            </tr>`;
        });

        html += `<tr class="item-add-row" data-cat-id="${catId}"${hiddenStyle}>
            <td class="drag-cell"></td>
            <td colspan="3"><button class="btn-add" onclick="event.stopPropagation();openItemModal(null, ${catObj ? catObj.id : 'null'})"><i class="bi bi-plus"></i> Nieuw item</button></td>
        </tr>`;
    }

    body.innerHTML = html;
    initItemDrag();
}

function toggleItemCategory(catId) {
    const key = String(catId);
    if (collapsedItemCategories.has(key)) {
        collapsedItemCategories.delete(key);
    } else {
        collapsedItemCategories.add(key);
    }
    const headerRow = document.querySelector(`tr.item-cat-header[data-cat-id="${catId}"]`);
    if (headerRow) {
        const chevron = headerRow.querySelector('.item-cat-chevron');
        if (chevron) chevron.classList.toggle('collapsed', collapsedItemCategories.has(key));
    }
    const isHidden = collapsedItemCategories.has(key);
    document.querySelectorAll(`tr.item-row[data-cat-id="${catId}"], tr.item-add-row[data-cat-id="${catId}"]`).forEach(r => {
        r.style.display = isHidden ? 'none' : '';
    });
}

function initItemDrag() {
    document.querySelectorAll('tr.item-row').forEach(row => {
        row.addEventListener('dragstart', e => {
            draggingItem = row;
            row.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.stopPropagation();
            row._dragged = true;
        });
        row.addEventListener('dragend', () => {
            draggingItem = null;
            row.classList.remove('dragging');
            document.querySelectorAll('tr.item-row').forEach(r => r.classList.remove('drag-over'));
            setTimeout(() => { row._dragged = false; }, 50);
        });
        row.addEventListener('dragover', e => {
            if (!draggingItem || draggingItem === row) return;
            if (draggingItem.dataset.catId !== row.dataset.catId) return;
            e.preventDefault(); e.stopPropagation();
            document.querySelectorAll('tr.item-row').forEach(r => r.classList.remove('drag-over'));
            row.classList.add('drag-over');
        });
        row.addEventListener('dragleave', () => row.classList.remove('drag-over'));
        row.addEventListener('drop', e => {
            e.preventDefault(); e.stopPropagation();
            if (!draggingItem || draggingItem === row) return;
            if (draggingItem.dataset.catId !== row.dataset.catId) return;
            row.classList.remove('drag-over');
            const catId = row.dataset.catId;
            const rows = [...document.querySelectorAll(`tr.item-row[data-cat-id="${catId}"]`)];
            const fromIdx = rows.indexOf(draggingItem);
            const toIdx   = rows.indexOf(row);
            if (fromIdx === -1 || toIdx === -1) return;
            if (fromIdx < toIdx) row.after(draggingItem);
            else row.before(draggingItem);
            saveItemOrder(catId);
        });
    });
}

async function saveItemOrder(catId) {
    const rows = [...document.querySelectorAll(`tr.item-row[data-cat-id="${catId}"]`)];
    const items = rows.map((r, i) => ({ id: parseInt(r.dataset.id), sort_order: i }));
    try { await callApi(null, { action: 'reorder_items', items }); } catch (e) { /* silent */ }
}

function closeItemInlineEdit() {
    if (!activeItemEditTr) return;
    if (activeItemEditTr._originalRow) activeItemEditTr._originalRow.style.display = '';
    activeItemEditTr.remove();
    activeItemEditTr = null;
}

function openItemInlineEdit(itemRow) {
    closeItemInlineEdit();
    const id   = itemRow.dataset.id;
    const item = masterItems.find(i => String(i.id) === String(id));
    if (!item) return;

    const tr = document.createElement('tr');
    tr.className = 'item-edit-row';
    tr.innerHTML = `
        <td class="drag-cell"></td>
        <td colspan="3">
            <div class="ie-form">
                <input type="text" class="ie-naam" placeholder="Item naam" value="${escAttr(item.naam)}">
                <select class="ie-freq">
                    <option value="dagelijks"${item.frequentie === 'dagelijks' ? ' selected' : ''}>Dagelijks</option>
                    <option value="dagelijks_mits_gebruikt"${item.frequentie === 'dagelijks_mits_gebruikt' ? ' selected' : ''}>Dagelijks (mits gebruikt)</option>
                    <option value="wekelijks"${item.frequentie === 'wekelijks' ? ' selected' : ''}>Wekelijks</option>
                    <option value="maandelijks"${item.frequentie === 'maandelijks' ? ' selected' : ''}>Maandelijks</option>
                </select>
                <label class="ie-check-label"><input type="checkbox" class="ie-kritisch"${parseInt(item.is_allergeen_kritisch) ? ' checked' : ''}> Allergeen-kritisch</label>
                <div class="ie-actions">
                    <button class="btn btn-danger btn-sm" onclick="toggleItemInlineActief(this, ${item.id}, ${parseInt(item.actief) ? 0 : 1})">${parseInt(item.actief) ? 'Deactiveren' : 'Activeren'}</button>
                    <span class="ie-spacer"></span>
                    <button class="btn btn-ghost btn-sm" onclick="closeItemInlineEdit()">Annuleren</button>
                    <button class="btn btn-primary btn-sm" onclick="saveItemInlineEdit(this)"><i class="bi bi-floppy"></i> Opslaan</button>
                </div>
            </div>
        </td>`;

    tr._originalRow = itemRow;
    tr._itemId = id;
    itemRow.after(tr);
    itemRow.style.display = 'none';
    activeItemEditTr = tr;
    tr.querySelector('.ie-naam').focus();
}

async function saveItemInlineEdit(btn) {
    const tr   = btn.closest('tr.item-edit-row');
    const id   = tr._itemId;
    const naam = tr.querySelector('.ie-naam').value.trim();
    const freq = tr.querySelector('.ie-freq').value;
    const krit = tr.querySelector('.ie-kritisch').checked ? 1 : 0;
    if (!naam) return;
    const item = masterItems.find(i => String(i.id) === String(id));
    try {
        await callApi(null, {
            action: 'save_item', id: parseInt(id), naam,
            categorie_id: item?.categorie_id || null, type: 'schoonmaak',
            frequentie: freq, actief: item?.actief ?? 1, is_allergeen_kritisch: krit,
        });
        showToast('Item bijgewerkt', 'success');
        await loadItems();
    } catch (e) { showToast('Fout: ' + e.message, 'error'); }
}

async function toggleItemInlineActief(btn, id, actief) {
    const item = masterItems.find(i => i.id == id);
    if (!await showConfirm(`Item "${item?.naam || id}" ${actief ? 'activeren' : 'deactiveren'}?`)) return;
    try {
        await callApi(null, { action: 'toggle_item', id, actief });
        showToast(actief ? 'Item geactiveerd' : 'Item gedeactiveerd', 'success');
        closeItemInlineEdit();
        await loadItems();
    } catch (e) { showToast('Fout: ' + e.message, 'error'); }
}

function openItemModal(id, defaultCategorieId) {
    document.getElementById('itemId').value = id || '';
    document.getElementById('itemModalTitle').textContent = id ? 'Item bewerken' : 'Item toevoegen';
    document.getElementById('itemActiefGroup').style.display = id ? 'block' : 'none';

    const sel = document.getElementById('itemCategorie');
    sel.innerHTML = '<option value="">— Geen categorie —</option>'
        + categorieen.map(c => `<option value="${c.id}">${escHtml(c.naam)}</option>`).join('');

    if (id) {
        const item = masterItems.find(i => i.id == id);
        if (item) {
            document.getElementById('itemNaam').value       = item.naam;
            document.getElementById('itemCategorie').value  = item.categorie_id || '';
            document.getElementById('itemFrequentie').value = item.frequentie;
            document.getElementById('itemActief').value     = item.actief;
            document.getElementById('itemAllergeenKritisch').checked = parseInt(item.is_allergeen_kritisch) === 1;
        }
    } else {
        document.getElementById('itemNaam').value       = '';
        document.getElementById('itemCategorie').value  = defaultCategorieId || '';
        document.getElementById('itemFrequentie').value = 'dagelijks';
        document.getElementById('itemAllergeenKritisch').checked = false;
    }

    document.getElementById('itemModal').classList.add('open');
    setTimeout(() => document.getElementById('itemNaam').focus(), 80);
}

async function saveItem() {
    const id         = document.getElementById('itemId').value;
    const naam       = document.getElementById('itemNaam').value.trim();
    const catId      = document.getElementById('itemCategorie').value;
    const frequentie = document.getElementById('itemFrequentie').value;
    const actief     = document.getElementById('itemActief').value;
    const isAllergeenKritisch = document.getElementById('itemAllergeenKritisch').checked ? 1 : 0;

    if (!naam) { showToast('Naam is verplicht', 'error'); return; }
    try {
        await callApi(null, {
            action: 'save_item', id: id || null, naam,
            categorie_id: catId, type: 'schoonmaak', frequentie, actief,
            is_allergeen_kritisch: isAllergeenKritisch,
        });
        closeModal('itemModal');
        showToast(id ? 'Item bijgewerkt' : 'Item toegevoegd', 'success');
        await loadItems();
    } catch (e) { showToast('Fout: ' + e.message, 'error'); }
}

async function toggleItemActief(id, actief) {
    const item = masterItems.find(i => i.id == id);
    if (!item || !await showConfirm(`Item "${item.naam}" ${actief ? 'activeren' : 'deactiveren'}?`)) return;
    try {
        await callApi(null, { action: 'toggle_item', id, actief });
        showToast(actief ? 'Item geactiveerd' : 'Item gedeactiveerd', 'success');
        await loadItems();
    } catch (e) { showToast('Fout: ' + e.message, 'error'); }
}

// ==================== SPORENALLERGENEN ====================
async function loadAllergenStatus() {
    showEl('allergenLoading'); hideEl('allergenContent');
    try {
        const data = await callApi('?action=get_allergen_status');
        hideEl('allergenLoading'); showEl('allergenContent');
        renderAllergenStatus(data.statuses, data.critical_cleaning_count);
    } catch (e) {
        document.getElementById('allergenLoading').innerHTML =
            `<i class="bi bi-exclamation-triangle" style="color:#c62828;"></i> Fout: ${escHtml(e.message)}`;
    }
}

function renderAllergenStatus(statuses, criticalCount) {
    const body  = document.getElementById('allergenBody');
    const empty = document.getElementById('allergenEmpty');
    const wrap  = document.getElementById('allergenTableWrap');

    if (!statuses || statuses.length === 0) {
        body.innerHTML = '';
        hideEl('allergenTableWrap'); showEl('allergenEmpty');
        return;
    }
    showEl('allergenTableWrap'); hideEl('allergenEmpty');

    body.innerHTML = statuses.map(s => {
        const isInStock  = s.status === 'in_stock';
        const isDepleted = s.status === 'depleted';
        const isCleared  = s.status === 'cleared';

        let statusBadge, depletedSince, daysRemainingHtml, cleaningStatus, actionBtn, rowClass;

        if (isInStock) {
            rowClass       = 'row-in-stock';
            statusBadge    = '<span class="badge" style="background:#fff3e0;color:#e65100;"><i class="bi bi-exclamation-triangle-fill"></i> Op voorraad</span>';
            depletedSince  = '—';
            daysRemainingHtml = '<span style="color:#aaa;font-size:0.82rem;">Actief in gebruik</span>';
            cleaningStatus = '—';
            actionBtn      = `<span style="color:#aaa;font-size:0.82rem;" title="Zolang dit allergeen op voorraad is, wordt het automatisch als sporenallergeen getoond. Verwijder de voorraad om de 60-dagenperiode te starten.">Geen actie mogelijk</span>
                              <button class="btn btn-danger btn-sm" style="margin-left:0.5rem" onclick="deleteAllergenRecord('${escAttr(s.allergeen_naam)}')" title="Admin: verwijder dit record volledig uit de tracking-tabel (bijv. voor testdata)."><i class="bi bi-trash3"></i> Verwijderen</button>`;
        } else if (isDepleted) {
            rowClass      = 'row-depleted';
            statusBadge   = '<span class="badge" style="background:#ffebee;color:#c62828;"><i class="bi bi-clock-history"></i> Uitgeput</span>';
            depletedSince = s.stock_depleted_at ? formatDate(s.stock_depleted_at.substring(0, 10)) : '—';
            const days      = parseInt(s.days_since_depleted) || 0;
            const remaining = Math.max(0, 60 - days);
            const pct       = Math.min(100, Math.round((days / 60) * 100));
            const barClass  = remaining <= 10 ? 'low' : remaining <= 25 ? 'mid' : 'ok';
            daysRemainingHtml = remaining > 0
                ? `<div class="allergen-days-bar">
                       <div class="progress-bar-bg"><div class="progress-bar-fill ${barClass}" style="width:${pct}%"></div></div>
                       <span style="color:${barClass === 'low' ? '#c62828' : barClass === 'mid' ? '#e65100' : '#333'}">${remaining} dagen</span>
                   </div>`
                : '<span style="color:#2e7d32;font-weight:600;"><i class="bi bi-check-circle"></i> Periode verstreken</span>';
            cleaningStatus = criticalCount === 0
                ? '<span style="color:#aaa;font-size:0.82rem;">Geen kritische items</span>'
                : (s.cleaning_complete
                    ? `<span style="color:#2e7d32;"><i class="bi bi-check-circle"></i> Afgerond (${s.cleaning_done}/${s.cleaning_total})</span>`
                    : `<span style="color:#e65100;">${s.cleaning_done}/${s.cleaning_total} afgerond</span>`);
            actionBtn = `<button class="btn btn-ghost btn-sm" onclick="clearAllergen('${escAttr(s.allergeen_naam)}')" title="Handmatig vrijgeven: dit allergeen wordt niet meer als sporenallergeen getoond op productpagina's. Gebruik dit alleen als de ruimte grondig schoongemaakt is en je zeker weet dat er geen kruiscontaminatie meer mogelijk is."><i class="bi bi-check-lg"></i> Vrijgeven</button>
                         <button class="btn btn-danger btn-sm" style="margin-left:0.35rem" onclick="deleteAllergenRecord('${escAttr(s.allergeen_naam)}')" title="Record volledig verwijderen uit de tracking-tabel."><i class="bi bi-trash3"></i></button>`;
        } else {
            rowClass      = 'row-cleared';
            statusBadge   = '<span class="badge" style="background:#e8f5e9;color:#2e7d32;"><i class="bi bi-check-circle-fill"></i> Vrijgegeven</span>';
            depletedSince = s.stock_depleted_at ? formatDate(s.stock_depleted_at.substring(0, 10)) : '—';
            daysRemainingHtml = '<span style="color:#2e7d32;font-size:0.82rem;">Periode afgerond</span>';
            const clearedBy = s.cleared_by === 'auto' ? 'Automatisch' : escHtml(s.cleared_by || '?');
            cleaningStatus = `<span class="release-by"><i class="bi bi-person-check"></i> ${clearedBy}</span>`;
            actionBtn = `<button class="btn btn-ghost btn-sm" onclick="resetAllergen('${escAttr(s.allergeen_naam)}')" title="Terugzetten naar 'Uitgeput': het allergeen wordt weer als sporenallergeen getoond. Gebruik dit als blijkt dat de vrijgave te vroeg was of per ongeluk is gedaan."><i class="bi bi-arrow-counterclockwise"></i> Terugzetten</button>
                         <button class="btn btn-danger btn-sm" style="margin-left:0.35rem" onclick="deleteAllergenRecord('${escAttr(s.allergeen_naam)}')" title="Record volledig verwijderen uit de tracking-tabel."><i class="bi bi-trash3"></i></button>`;
        }

        return `<tr class="${rowClass}">
            <td><strong>${escHtml(s.allergeen_naam)}</strong></td>
            <td>${statusBadge}</td>
            <td>${depletedSince}</td>
            <td>${daysRemainingHtml}</td>
            <td>${cleaningStatus}</td>
            <td style="white-space:nowrap">${actionBtn}</td>
        </tr>`;
    }).join('');
}

async function deleteAllergenRecord(naam) {
    if (!await showConfirm(`Record "${naam}" volledig verwijderen uit de tracking?\n\nDit is een admin-override voor testdata. Het record wordt opnieuw aangemaakt zodra er voorraad wordt toegevoegd of verbruikt.`)) return;
    try {
        await callApi(null, { action: 'delete_allergen', allergeen_naam: naam });
        showToast('Record verwijderd', 'success');
        loadAllergenStatus();
    } catch (e) { showToast('Fout: ' + e.message, 'error'); }
}

async function clearAllergen(naam) {
    if (!await showConfirm(`Allergeen "${naam}" handmatig vrijgeven?\n\nDit allergeen wordt niet meer als sporenallergeen getoond op productpagina's.`)) return;
    try {
        await callApi(null, { action: 'clear_allergen', allergeen_naam: naam });
        showToast('Allergeen vrijgegeven', 'success');
        loadAllergenStatus();
    } catch (e) { showToast('Fout: ' + e.message, 'error'); }
}

async function resetAllergen(naam) {
    if (!await showConfirm(`Allergeen "${naam}" terugzetten naar uitgeput status?`)) return;
    try {
        await callApi(null, { action: 'reset_allergen', allergeen_naam: naam });
        showToast('Allergeen teruggezet', 'success');
        loadAllergenStatus();
    } catch (e) { showToast('Fout: ' + e.message, 'error'); }
}

// ==================== OVERZICHT ====================
async function loadOverzicht() {
    showEl('overzichtLoading'); hideEl('overzichtTableWrap'); hideEl('overzichtEmpty');
    try {
        const data = await callApi('?action=get_overzicht');
        const van  = document.getElementById('filterVan').value;
        const tot  = document.getElementById('filterTot').value;
        let lijsten = data.lijsten;
        if (van) lijsten = lijsten.filter(l => l.datum >= van);
        if (tot) lijsten = lijsten.filter(l => l.datum <= tot);

        const body = document.getElementById('overzichtBody');
        if (!lijsten || lijsten.length === 0) {
            body.innerHTML = ''; showEl('overzichtEmpty'); hideEl('overzichtLoading'); return;
        }

        body.innerHTML = lijsten.map(l => {
            const totaal    = parseInt(l.totaal_items)    || 0;
            const afgevinkt = parseInt(l.afgevinkt_items) || 0;
            const pct       = totaal > 0 ? Math.round((afgevinkt / totaal) * 100) : 0;
            return `<tr>
                <td><strong>${formatDate(l.datum)}</strong></td>
                <td>${statusIcon(l.status)} <span class="badge badge-status-${l.status}">${formatStatus(l.status)}</span></td>
                <td>
                    <div class="progress-wrap">
                        <div class="progress-bar-bg"><div class="progress-bar-fill" style="width:${pct}%;"></div></div>
                        <span style="font-size:0.79rem; color:#666;">${afgevinkt}/${totaal}</span>
                    </div>
                </td>
                <td style="white-space:nowrap;">
                    <button class="btn btn-ghost btn-sm" onclick="openListDate('${l.datum}')">
                        <i class="bi bi-eye"></i> Openen
                    </button>
                    <button class="btn-icon danger" style="margin-left:0.35rem;" onclick="deleteList(${l.id}, '${l.datum}')" title="Verwijderen">
                        <i class="bi bi-trash"></i> Verwijderen
                    </button>
                </td>
            </tr>`;
        }).join('');

        showEl('overzichtTableWrap');
    } catch (e) {
        document.getElementById('overzichtLoading').innerHTML =
            `<i class="bi bi-exclamation-triangle" style="color:#c62828;"></i> Fout: ${e.message}`;
    }
    hideEl('overzichtLoading');
}

function openListDate(datum) {
    document.getElementById('listDate').value = datum;
    openFormulierModal();
    loadList();
}

async function deleteList(id, datum) {
    if (!await showConfirm(`Formulier van ${formatDate(datum)} verwijderen? Dit kan niet ongedaan worden.`)) return;
    try {
        await callApi(null, { action: 'delete_list', lijst_id: id });
        showToast('Formulier verwijderd', 'success');
        if (currentList && currentList.id == id) { currentList = null; currentItems = []; }
        loadOverzicht();
    } catch (e) { showToast('Fout: ' + e.message, 'error'); }
}

// ==================== UTILS ====================
async function callApi(qs, body) {
    const url  = qs ? API + qs : API;
    const opts = body
        ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
        : {};
    const res  = await fetch(url, opts);
    const data = await res.json();
    if (!data.success && !data.warning) throw new Error(data.error || 'Onbekende fout');
    return data;
}

function showEl(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = el.classList.contains('loading') ? 'flex' : 'block';
}
function hideEl(id) { const el = document.getElementById(id); if (el) el.style.display = 'none'; }

function formatDate(d) {
    if (!d) return '—';
    const [y, m, day] = d.split('-');
    const months = ['jan','feb','mrt','apr','mei','jun','jul','aug','sep','okt','nov','dec'];
    return `${parseInt(day)} ${months[parseInt(m) - 1]} ${y}`;
}
function formatStatus(s) { return { volledig: 'Volledig', onvolledig: 'Onvolledig', afwijking: 'Afwijking' }[s] || s; }
function statusIcon(s)   { return { volledig: '✅', onvolledig: '❌', afwijking: '⚠️' }[s] || '📋'; }
function formatFreq(f)   { return { dagelijks: 'Dagelijks', wekelijks: 'Wekelijks', maandelijks: 'Maandelijks' }[f] || f; }
function escHtml(s) { return s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : ''; }
function escAttr(s) { return s ? String(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;') : ''; }
function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = `toast show ${type || ''}`;
    setTimeout(() => { t.className = 'toast'; }, 3200);
}
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', e => {
        if (e.target === el && el.id !== 'formulierModal') el.classList.remove('open');
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        if (activeItemEditTr) { closeItemInlineEdit(); return; }
        // Only close small modals on Escape, not the formulier modal
        document.querySelectorAll('.modal-overlay.open').forEach(m => {
            if (m.id !== 'formulierModal') m.classList.remove('open');
        });
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        const btn = document.getElementById('saveBtn');
        if (btn && btn.style.display !== 'none' && !btn.disabled) saveList(false);
    }
});

// Init
(function () {
    const pad = n => String(n).padStart(2, '0');
    const toLocal = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
    const today    = new Date();
    const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
    const lastDay  = new Date(today.getFullYear(), today.getMonth() + 1, 0);
    document.getElementById('filterVan').value = toLocal(firstDay);
    document.getElementById('filterTot').value = toLocal(lastDay);
    loadOverzicht();
})();
</script>
</body>
</html>
