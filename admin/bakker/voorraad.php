<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$currentPage = 'voorraad';
$adminBasePath = '../';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voorraadbeheer | Civetta Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--cream); min-height: 100vh; }
        .admin-content { padding: 1.5rem; max-width: 1200px; margin: 0 auto; }
        @media (max-width: 768px) { .admin-content { padding: 1rem; } }

        .tabs { display: flex; gap: 0.25rem; border-bottom: 2px solid #e0d5c7; margin-bottom: 1.5rem; overflow-x: auto; }
        .tab { padding: 0.7rem 1.2rem; cursor: pointer; font-weight: 500; color: #888; border-bottom: 3px solid transparent; margin-bottom: -2px; white-space: nowrap; transition: all 0.2s; user-select: none; display: flex; align-items: center; gap: 0.4rem; }
        .tab:hover { color: #2d4a2d; }
        .tab.active { color: #3d6b3d; border-bottom-color: #c8913a; font-weight: 700; }

        .panel { background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); padding: 1.5rem; margin-bottom: 1.5rem; }
        .panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 1rem; }
        .panel-title { font-size: 1.1rem; font-weight: 700; color: #2d4a2d; display: flex; align-items: center; gap: 0.5rem; }
        .panel-title i { color: #c8913a; }

        .btn { padding: 0.5rem 1rem; border: none; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; transition: all 0.2s; }
        .btn-primary { background: #3d6b3d; color: white; }
        .btn-primary:hover { background: #2d4a2d; }
        .btn-success { background: #2e7d32; color: white; }
        .btn-success:hover { background: #1b5e20; }
        .btn-danger { background: #c62828; color: white; }
        .btn-danger:hover { background: #b71c1c; }
        .btn-ghost { background: transparent; color: #3d6b3d; border: 2px solid #e0d5c7; }
        .btn-ghost:hover { border-color: #3d6b3d; background: #faf6f1; }
        .btn-sm { padding: 0.35rem 0.7rem; font-size: 0.8rem; }

        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        th { text-align: left; padding: 0.75rem; color: #888; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; border-bottom: 2px solid #e8e0d5; }
        td { padding: 0.75rem; border-bottom: 1px solid #f0ebe5; vertical-align: middle; }
        tr:hover { background: #faf8f5; }
        tr.batch-expired td { background: #fff3f3 !important; }
        tr.batch-empty { opacity: 0.45; }
        tr.batch-empty:hover { opacity: 0.7; }
        .thd-expired { color: #c62828; font-weight: 700; }

        .badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
        .badge-meel { background: #fff3e0; color: #e65100; }
        .badge-gist { background: #e8f5e9; color: #2e7d32; }
        .badge-mixin { background: #e3f2fd; color: #1565c0; }
        .badge-topping { background: #fce4ec; color: #c2185b; }
        .badge-overig { background: #f5f5f5; color: #616161; }
        .badge-volkoren { background: #8d6e63; color: white; }
        .badge-wit { background: #faf6f1; color: #3d6b3d; border: 1px solid #e8dfd2; }
        .badge-ok { background: #e8f5e9; color: #2e7d32; }
        .badge-laag { background: #fff3e0; color: #e65100; }
        .badge-tekort { background: #ffebee; color: #c62828; }

        .subtabs { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
        .subtab { padding: 0.6rem 1rem; cursor: pointer; font-weight: 500; color: #888; background: #f5f0e8; border-radius: 8px; transition: all 0.2s; user-select: none; display: flex; align-items: center; gap: 0.4rem; font-size: 0.9rem; }
        .subtab:hover { background: #e8dfd2; color: #2d4a2d; }
        .subtab.active { background: #3d6b3d; color: white; }

        .grain-type-list { margin-bottom: 1rem; }
        .grain-type-item { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid #f0f0f0; }
        .grain-type-item:last-child { border-bottom: none; }
        .add-grain-type { display: flex; gap: 0.5rem; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eee; }

        .stock-bar { width: 100px; height: 8px; background: #eee; border-radius: 4px; overflow: hidden; }
        .stock-bar-fill { height: 100%; background: #3d6b3d; transition: width 0.3s; }
        .stock-bar-fill.low { background: #ff9800; }
        .stock-bar-fill.empty { background: #c62828; }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 1rem; }
        .modal-overlay.active { display: flex; }
        .modal { background: white; border-radius: 12px; max-width: 500px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .modal-wide { max-width: 800px; }
        .modal-header { background: linear-gradient(135deg, #3d6b3d, #2d4a2d); color: white; padding: 1rem 1.25rem; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { margin: 0; font-size: 1.1rem; }
        .modal-close { width: 32px; height: 32px; border: none; background: rgba(255,255,255,0.2); border-radius: 6px; cursor: pointer; font-size: 1.2rem; color: white; display: flex; align-items: center; justify-content: center; }
        .modal-close:hover { background: rgba(255,255,255,0.3); }
        .modal-body { padding: 1.25rem; }
        .modal-footer { padding: 1rem 1.25rem; border-top: 1px solid #eee; display: flex; gap: 0.75rem; justify-content: flex-end; }

        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; margin-bottom: 0.4rem; font-weight: 600; color: #2d4a2d; font-size: 0.85rem; }
        .form-input, .form-select { width: 100%; padding: 0.6rem 0.75rem; border: 2px solid #e8dfd2; border-radius: 8px; font-size: 0.95rem; font-family: inherit; }
        .form-input:focus, .form-select:focus { outline: none; border-color: #c8913a; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 500px) { .form-row { grid-template-columns: 1fr; } }

        .input-with-unit { display: flex; align-items: stretch; }
        .input-with-unit .form-input { border-top-right-radius: 0; border-bottom-right-radius: 0; flex: 1; }
        .input-unit { padding: 0.6rem 0.75rem; background: #f5f0e8; border: 2px solid #e8dfd2; border-left: none; border-radius: 0 8px 8px 0; font-size: 0.85rem; color: #888; font-weight: 600; }

        .filter-row { display: flex; gap: 0.75rem; margin-bottom: 1rem; flex-wrap: wrap; align-items: center; }
        .filter-row select { padding: 0.5rem 0.75rem; border: 2px solid #e8dfd2; border-radius: 8px; font-size: 0.85rem; background: white; }
        .toggle-label { display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; color: #666; cursor: pointer; user-select: none; }
        .toggle-label input[type=checkbox] { cursor: pointer; }

        .empty-state { text-align: center; padding: 3rem; color: #aaa; }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; display: block; }

        .stat-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: white; border-radius: 12px; padding: 1.25rem; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .stat-card-label { font-size: 0.8rem; color: #888; text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 0.25rem; }
        .stat-card-value { font-size: 1.75rem; font-weight: 700; color: #2d4a2d; }
        .stat-card-sub { font-size: 0.85rem; color: #aaa; margin-top: 0.25rem; }

        .toast { position: fixed; bottom: 2rem; right: 2rem; padding: 0.75rem 1.5rem; background: #333; color: white; border-radius: 8px; font-size: 0.9rem; z-index: 2000; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        .toast.success { background: #2e7d32; }
        .toast.error { background: #c62828; }

        .utility-card { background: #faf8f5; border: 2px solid #e8dfd2; border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; }
        .utility-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .utility-title { font-weight: 700; color: #2d4a2d; display: flex; align-items: center; gap: 0.5rem; }
        .utility-title i { color: #c8913a; }
        .estimate-badge { background: #fff3e0; color: #e65100; font-size: 0.7rem; padding: 0.15rem 0.4rem; border-radius: 4px; font-weight: 600; }

        .month-nav { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; }
        .month-nav button { background: none; border: 2px solid #e8dfd2; border-radius: 8px; padding: 0.5rem; cursor: pointer; color: #3d6b3d; }
        .month-nav button:hover { background: #faf6f1; border-color: #3d6b3d; }
        .month-nav span { font-weight: 700; color: #2d4a2d; font-size: 1.1rem; }

        .consolidation-item { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 0.5rem; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid #f0ebe5; font-size: 0.9rem; }
        .consolidation-item:last-child { border-bottom: none; }
        .consolidation-header { font-size: 0.75rem; color: #888; text-transform: uppercase; letter-spacing: 0.03em; font-weight: 600; }
        .diff-positive { color: #2e7d32; font-weight: 700; }
        .diff-negative { color: #c62828; font-weight: 700; }

        .audit-item { padding: 0.6rem 0; border-bottom: 1px solid #f0ebe5; font-size: 0.85rem; }
        .audit-item:last-child { border-bottom: none; }
        .audit-item-details { margin-top: 0.5rem; padding-left: 1rem; }
        .audit-sub { font-size: 0.8rem; color: #666; margin-top: 0.25rem; }

        .forecast-row-ok { background: #f1f8e9; }
        .forecast-row-laag { background: #fff8e1; }
        .forecast-row-tekort { background: #ffebee; }

        .spinner { display: inline-block; width: 1rem; height: 1rem; border: 2px solid #e8dfd2; border-top-color: #3d6b3d; border-radius: 50%; animation: spin 0.6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include '../components/sidebar.php'; ?>

        <div class="admin-main">
            <header class="topbar">
                <div class="topbar-left">
                    <button class="mobile-toggle" onclick="toggleSidebar()">
                        <i class="bi bi-list"></i>
                    </button>
                    <span class="topbar-title">Voorraadbeheer</span>
                </div>
                <div class="topbar-right">
                    <a href="bakcalculator.php" class="topbar-link">
                        <i class="bi bi-calculator"></i> <span>Bak Calculator</span>
                    </a>
                </div>
            </header>

            <div class="admin-content" id="app">
                <div class="tabs">
                    <div class="tab" :class="{active: activeTab==='overzicht'}" @click="activeTab='overzicht'">
                        <i class="bi bi-grid-1x2"></i> Overzicht
                    </div>
                    <div class="tab" :class="{active: activeTab==='ingredienten'}" @click="activeTab='ingredienten'">
                        <i class="bi bi-list-ul"></i> Ingrediënten
                    </div>
                    <div class="tab" :class="{active: activeTab==='voorraad'}" @click="activeTab='voorraad'">
                        <i class="bi bi-box-seam"></i> Voorraad
                    </div>
                    <div class="tab" :class="{active: activeTab==='kosten'}" @click="activeTab='kosten'">
                        <i class="bi bi-lightning"></i> Vaste Kosten
                    </div>
                    <div class="tab" :class="{active: activeTab==='prognose'}" @click="activeTab='prognose'; loadForecast()">
                        <i class="bi bi-graph-up"></i> Prognose
                    </div>
                </div>

                <!-- OVERZICHT TAB -->
                <div v-show="activeTab==='overzicht'">
                    <div class="stat-cards">
                        <div class="stat-card">
                            <div class="stat-card-label">Ingrediënten</div>
                            <div class="stat-card-value">{{ ingredients.length }}</div>
                            <div class="stat-card-sub">actief in systeem</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-label">Lage Voorraad</div>
                            <div class="stat-card-value">{{ lowStockCount }}</div>
                            <div class="stat-card-sub">< 1kg beschikbaar</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-card-label">Voorraadwaarde</div>
                            <div class="stat-card-value">€{{ formatNumber(totalStockValue) }}</div>
                            <div class="stat-card-sub">geschatte waarde</div>
                        </div>
                        <div class="stat-card" v-if="expiredBatchCount > 0" style="border: 2px solid #ffcdd2;">
                            <div class="stat-card-label" style="color:#c62828">Verlopen T.H.T.</div>
                            <div class="stat-card-value" style="color:#c62828">{{ expiredBatchCount }}</div>
                            <div class="stat-card-sub">batches verstreken</div>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-title"><i class="bi bi-exclamation-triangle"></i> Lage Voorraad</div>
                        </div>
                        <div v-if="lowStockItems.length === 0" class="empty-state" style="padding:1.5rem">
                            <i class="bi bi-check-circle" style="color:#2e7d32;font-size:2rem"></i>
                            <p style="color:#2e7d32">Alle voorraden op peil</p>
                        </div>
                        <div class="table-wrapper" v-else>
                            <table>
                                <thead><tr><th>Ingrediënt</th><th>Categorie</th><th>Voorraad</th><th>Actie</th></tr></thead>
                                <tbody>
                                    <tr v-for="item in lowStockItems" :key="item.id">
                                        <td><strong>{{ item.name }}</strong></td>
                                        <td><span class="badge" :class="'badge-'+item.category">{{ item.category }}</span></td>
                                        <td>{{ formatStock(item.total_stock) }}</td>
                                        <td><button class="btn btn-success btn-sm" @click="openBatchModal(item)"><i class="bi bi-plus"></i> Bijvullen</button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- INGREDIËNTEN TAB -->
                <div v-show="activeTab==='ingredienten'">
                    <div class="subtabs">
                        <div class="subtab" :class="{active: ingredientSubTab==='meel'}" @click="ingredientSubTab='meel'">
                            <i class="bi bi-moisture"></i> Meel
                        </div>
                        <div class="subtab" :class="{active: ingredientSubTab==='extras'}" @click="ingredientSubTab='extras'">
                            <i class="bi bi-plus-circle"></i> Toppings + Mix-ins
                        </div>
                        <div class="subtab" :class="{active: ingredientSubTab==='overig'}" @click="ingredientSubTab='overig'">
                            <i class="bi bi-grid"></i> Overig
                        </div>
                    </div>

                    <div v-show="ingredientSubTab==='meel'" class="panel">
                        <div class="panel-header">
                            <div class="panel-title"><i class="bi bi-moisture"></i> Meelsoorten</div>
                            <div style="display:flex;gap:0.5rem">
                                <button class="btn btn-ghost" @click="showGrainTypeModal=true"><i class="bi bi-tags"></i> Graansoorten</button>
                                <button class="btn btn-primary" @click="openIngredientModal(null, 'meel')"><i class="bi bi-plus"></i> Nieuw Meel</button>
                            </div>
                        </div>

                        <div class="filter-row">
                            <select v-model="filterGrainType">
                                <option value="">Alle graansoorten</option>
                                <option v-for="gt in grainTypes" :key="gt.id" :value="gt.id">{{ gt.name }}</option>
                            </select>
                            <select v-model="filterWholeGrain">
                                <option value="">Wit & Volkoren</option>
                                <option value="0">Alleen wit</option>
                                <option value="1">Alleen volkoren</option>
                            </select>
                        </div>

                        <div class="table-wrapper">
                            <table>
                                <thead><tr><th>Naam</th><th>Graansoort</th><th>Type</th><th>Voorraad</th><th>FIFO Prijs</th><th>Acties</th></tr></thead>
                                <tbody>
                                    <tr v-for="ing in filteredMeel" :key="ing.id">
                                        <td><strong>{{ ing.name }}</strong></td>
                                        <td>{{ getGrainTypeName(ing.grain_type_id) }}</td>
                                        <td>
                                            <span class="badge" :class="parseInt(ing.is_whole_grain) === 1 ? 'badge-volkoren' : 'badge-wit'">
                                                {{ parseInt(ing.is_whole_grain) === 1 ? 'Volkoren' : 'Wit' }}
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:0.5rem">
                                                <div class="stock-bar">
                                                    <div class="stock-bar-fill" :class="stockLevel(ing.total_stock)" :style="{width: stockPercent(ing.total_stock)+'%'}"></div>
                                                </div>
                                                <span>{{ formatStock(ing.total_stock) }}</span>
                                            </div>
                                        </td>
                                        <td>{{ ing.current_price_per_kg ? '€'+formatNumber(ing.current_price_per_kg)+'/kg' : '-' }}</td>
                                        <td>
                                            <button class="btn btn-ghost btn-sm" @click="openIngredientModal(ing)"><i class="bi bi-pencil"></i></button>
                                            <button class="btn btn-success btn-sm" @click="openBatchModal(ing)"><i class="bi bi-plus"></i></button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div v-if="filteredMeel.length === 0" class="empty-state" style="padding:1.5rem">
                                <i class="bi bi-inbox"></i>
                                <p>Geen meelsoorten gevonden</p>
                            </div>
                        </div>
                    </div>

                    <div v-show="ingredientSubTab==='extras'" class="panel">
                        <div class="panel-header">
                            <div class="panel-title"><i class="bi bi-plus-circle"></i> Toppings + Mix-ins</div>
                            <button class="btn btn-primary" @click="openIngredientModal(null, 'mixin')"><i class="bi bi-plus"></i> Nieuw</button>
                        </div>

                        <div class="table-wrapper">
                            <table>
                                <thead><tr><th>Naam</th><th>Voorraad</th><th>FIFO Prijs</th><th>Acties</th></tr></thead>
                                <tbody>
                                    <tr v-for="ing in extrasIngredients" :key="ing.id">
                                        <td><strong>{{ ing.name }}</strong></td>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:0.5rem">
                                                <div class="stock-bar">
                                                    <div class="stock-bar-fill" :class="stockLevel(ing.total_stock)" :style="{width: stockPercent(ing.total_stock)+'%'}"></div>
                                                </div>
                                                <span>{{ formatStock(ing.total_stock) }}</span>
                                            </div>
                                        </td>
                                        <td>{{ ing.current_price_per_kg ? '€'+formatNumber(ing.current_price_per_kg)+'/kg' : '-' }}</td>
                                        <td>
                                            <button class="btn btn-ghost btn-sm" @click="openIngredientModal(ing)"><i class="bi bi-pencil"></i></button>
                                            <button class="btn btn-success btn-sm" @click="openBatchModal(ing)"><i class="bi bi-plus"></i></button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div v-if="extrasIngredients.length === 0" class="empty-state" style="padding:1.5rem">
                                <i class="bi bi-inbox"></i>
                                <p>Geen toppings of mix-ins gevonden</p>
                            </div>
                        </div>
                    </div>

                    <div v-show="ingredientSubTab==='overig'" class="panel">
                        <div class="panel-header">
                            <div class="panel-title"><i class="bi bi-grid"></i> Overig</div>
                            <button class="btn btn-primary" @click="openIngredientModal(null, 'overig')"><i class="bi bi-plus"></i> Nieuw</button>
                        </div>

                        <div class="table-wrapper">
                            <table>
                                <thead><tr><th>Naam</th><th>Voorraad</th><th>FIFO Prijs</th><th>Acties</th></tr></thead>
                                <tbody>
                                    <tr v-for="ing in overigIngredients" :key="ing.id">
                                        <td><strong>{{ ing.name }}</strong></td>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:0.5rem">
                                                <div class="stock-bar">
                                                    <div class="stock-bar-fill" :class="stockLevel(ing.total_stock)" :style="{width: stockPercent(ing.total_stock)+'%'}"></div>
                                                </div>
                                                <span>{{ formatStock(ing.total_stock) }}</span>
                                            </div>
                                        </td>
                                        <td>{{ ing.current_price_per_kg ? '€'+formatNumber(ing.current_price_per_kg)+'/kg' : '-' }}</td>
                                        <td>
                                            <button class="btn btn-ghost btn-sm" @click="openIngredientModal(ing)"><i class="bi bi-pencil"></i></button>
                                            <button class="btn btn-success btn-sm" @click="openBatchModal(ing)"><i class="bi bi-plus"></i></button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div v-if="overigIngredients.length === 0" class="empty-state" style="padding:1.5rem">
                                <i class="bi bi-inbox"></i>
                                <p>Geen overige ingrediënten gevonden</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- VOORRAAD TAB -->
                <div v-show="activeTab==='voorraad'">
                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-title"><i class="bi bi-box-seam"></i> Voorraad Batches</div>
                            <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
                                <button class="btn btn-ghost" @click="openConsolidationModal"><i class="bi bi-clipboard-check"></i> Consolidatie</button>
                                <button class="btn btn-primary" @click="openBatchModal()"><i class="bi bi-plus"></i> Nieuwe Batch</button>
                            </div>
                        </div>

                        <div class="filter-row">
                            <select v-model="filterIngredient">
                                <option value="">Alle ingrediënten</option>
                                <option v-for="ing in ingredients" :value="ing.id">{{ ing.name }}</option>
                            </select>
                            <label class="toggle-label">
                                <input type="checkbox" v-model="hideEmptyBatches">
                                Verberg lege batches
                            </label>
                        </div>

                        <div v-if="filteredBatches.length === 0" class="empty-state">
                            <i class="bi bi-inbox"></i>
                            <p>Geen voorraad batches gevonden</p>
                        </div>
                        <div class="table-wrapper" v-else>
                            <table>
                                <thead><tr><th>Ingrediënt</th><th>Inkoopdatum</th><th>T.H.T.</th><th>Ingekocht</th><th>Resterend</th><th>Prijs/kg</th><th>Acties</th></tr></thead>
                                <tbody>
                                    <tr v-for="batch in filteredBatches" :key="batch.id"
                                        :class="{'batch-expired': isBatchExpired(batch), 'batch-empty': parseFloat(batch.quantity_remaining) === 0}">
                                        <td><strong>{{ batch.ingredient_name }}</strong></td>
                                        <td>{{ formatDate(batch.purchase_date) }}</td>
                                        <td>
                                            <span v-if="batch.thd_date" :class="{'thd-expired': isBatchExpired(batch)}">
                                                {{ formatDate(batch.thd_date) }}
                                                <i v-if="isBatchExpired(batch)" class="bi bi-exclamation-triangle-fill" style="margin-left:0.25rem"></i>
                                            </span>
                                            <span v-else style="color:#ccc">-</span>
                                        </td>
                                        <td>{{ formatStock(batch.quantity_purchased) }}</td>
                                        <td>{{ formatStock(batch.quantity_remaining) }}</td>
                                        <td>€{{ formatNumber(batch.price_per_kg) }}</td>
                                        <td style="white-space:nowrap">
                                            <button class="btn btn-ghost btn-sm" @click="editBatch(batch)"><i class="bi bi-pencil"></i></button>
                                            <button class="btn btn-danger btn-sm" @click="purgeBatch(batch)" :disabled="parseFloat(batch.quantity_remaining) === 0" style="margin-left:0.25rem" title="Weggooien"><i class="bi bi-trash3"></i></button>
                                            <button class="btn btn-ghost btn-sm" @click="deleteBatch(batch.id)" style="margin-left:0.25rem" title="Verwijderen"><i class="bi bi-x-lg"></i></button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Consolidatie audit log -->
                    <div class="panel" v-if="consolidations.length > 0">
                        <div class="panel-header">
                            <div class="panel-title"><i class="bi bi-clock-history"></i> Laatste consolidaties</div>
                        </div>
                        <div v-for="con in consolidations" :key="con.id" class="audit-item">
                            <div style="display:flex;justify-content:space-between;align-items:center;cursor:pointer" @click="con._open = !con._open">
                                <div>
                                    <strong>{{ formatDate(con.consolidation_date) }}</strong>
                                    <span style="color:#888;margin-left:0.75rem;font-size:0.85rem">{{ con.item_count }} ingrediënten</span>
                                    <span v-if="con.notes" style="color:#aaa;margin-left:0.5rem;font-size:0.8rem">– {{ con.notes }}</span>
                                </div>
                                <i class="bi" :class="con._open ? 'bi-chevron-up' : 'bi-chevron-down'" style="color:#aaa"></i>
                            </div>
                            <div v-if="con._open" class="audit-item-details">
                                <div class="consolidation-item consolidation-header" style="margin-bottom:0.25rem">
                                    <span>Ingrediënt</span><span>Verwacht</span><span>Geteld</span><span>Verschil</span>
                                </div>
                                <div v-for="it in con.items" :key="it.id" class="consolidation-item">
                                    <span>{{ it.ingredient_name }}</span>
                                    <span>{{ formatStock(it.expected_grams) }}</span>
                                    <span>{{ formatStock(it.counted_grams) }}</span>
                                    <span :class="(it.counted_grams - it.expected_grams) >= 0 ? 'diff-positive' : 'diff-negative'">
                                        {{ (it.counted_grams - it.expected_grams) >= 0 ? '+' : '' }}{{ formatStock(it.counted_grams - it.expected_grams) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- VASTE KOSTEN TAB -->
                <div v-show="activeTab==='kosten'">
                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-title"><i class="bi bi-lightning"></i> Vaste Kosten</div>
                        </div>

                        <div class="month-nav">
                            <button @click="prevMonth"><i class="bi bi-chevron-left"></i></button>
                            <span>{{ monthName }}</span>
                            <button @click="nextMonth"><i class="bi bi-chevron-right"></i></button>
                        </div>

                        <div class="utility-card">
                            <div class="utility-header">
                                <div class="utility-title"><i class="bi bi-droplet"></i> Water</div>
                            </div>
                            <div class="form-row" style="grid-template-columns:1fr 1fr auto;align-items:flex-end">
                                <div class="form-group" style="margin-bottom:0">
                                    <label class="form-label">Kosten</label>
                                    <div class="input-with-unit">
                                        <input type="number" class="form-input" v-model.number="waterCost.cost" step="0.01" min="0" placeholder="Werkelijk">
                                        <span class="input-unit">€</span>
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom:0">
                                    <label class="form-label">Geschatte Kosten</label>
                                    <div class="input-with-unit">
                                        <input type="number" class="form-input" v-model.number="waterCost.estimated_cost" step="0.01" min="0" placeholder="Schatting">
                                        <span class="input-unit">€</span>
                                    </div>
                                </div>
                                <div>
                                    <button class="btn btn-success" @click="saveUtilityCost('water')" :disabled="savingUtility">
                                        <i class="bi bi-save"></i> Invoeren
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="utility-card">
                            <div class="utility-header">
                                <div class="utility-title"><i class="bi bi-lightning-charge"></i> Elektriciteit</div>
                            </div>
                            <div class="form-row" style="grid-template-columns:1fr 1fr auto;align-items:flex-end">
                                <div class="form-group" style="margin-bottom:0">
                                    <label class="form-label">Kosten</label>
                                    <div class="input-with-unit">
                                        <input type="number" class="form-input" v-model.number="electricityCost.cost" step="0.01" min="0" placeholder="Werkelijk">
                                        <span class="input-unit">€</span>
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom:0">
                                    <label class="form-label">Geschatte Kosten</label>
                                    <div class="input-with-unit">
                                        <input type="number" class="form-input" v-model.number="electricityCost.estimated_cost" step="0.01" min="0" placeholder="Schatting">
                                        <span class="input-unit">€</span>
                                    </div>
                                </div>
                                <div>
                                    <button class="btn btn-success" @click="saveUtilityCost('electricity')" :disabled="savingUtility">
                                        <i class="bi bi-save"></i> Invoeren
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="utility-card">
                            <div class="utility-header">
                                <div class="utility-title"><i class="bi bi-calculator"></i> Kosten per brood</div>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:center">
                                <div>
                                    <div style="font-size:0.8rem;color:#888;margin-bottom:0.25rem">Broden {{ monthName }}</div>
                                    <div style="font-size:1.75rem;font-weight:700;color:#2d4a2d">{{ monthlyLoaves.totaal }}</div>
                                    <div style="font-size:0.8rem;color:#aaa;margin-top:0.2rem">{{ monthlyLoaves.gebakken }} gebakken &bull; {{ monthlyLoaves.te_bakken }} te bakken</div>
                                </div>
                                <div>
                                    <div style="font-size:0.8rem;color:#888;margin-bottom:0.25rem">Nutskosten per brood</div>
                                    <div style="font-size:1.75rem;font-weight:700;color:#c8913a" v-if="kostPerBrood !== null">&euro;{{ formatNumber(kostPerBrood) }}</div>
                                    <div style="font-size:0.95rem;color:#aaa" v-else>Vul kosten in</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PROGNOSE TAB -->
                <div v-show="activeTab==='prognose'">
                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-title"><i class="bi bi-graph-up"></i> Prognose — Komende 2 weken</div>
                            <button class="btn btn-ghost" @click="loadForecast" :disabled="loadingForecast">
                                <span v-if="loadingForecast" class="spinner"></span>
                                <i v-else class="bi bi-arrow-clockwise"></i>
                                Vernieuwen
                            </button>
                        </div>

                        <div v-if="forecastPeriod" style="font-size:0.85rem;color:#888;margin-bottom:1rem">
                            {{ formatDate(forecastPeriod.from) }} t/m {{ formatDate(forecastPeriod.to) }}
                            <span v-if="forecastMeta"> &bull; {{ forecastMeta.orders_with_recipe }} bestellingen met recept, {{ forecastMeta.orders_without_recipe }} zonder</span>
                        </div>

                        <div v-if="loadingForecast" class="empty-state">
                            <span class="spinner" style="width:2rem;height:2rem;border-width:3px"></span>
                            <p style="margin-top:1rem;color:#888">Prognose berekenen...</p>
                        </div>
                        <div v-else-if="forecastData.length === 0" class="empty-state">
                            <i class="bi bi-calendar-x"></i>
                            <p>Geen bestellingen met recepten in komende 2 weken</p>
                        </div>
                        <div class="table-wrapper" v-else>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Ingrediënt</th>
                                        <th>Benodigd</th>
                                        <th>Beschikbaar</th>
                                        <th>Verschil</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in forecastData" :key="row.ingredient_id"
                                        :class="'forecast-row-'+row.status">
                                        <td><strong>{{ row.name }}</strong></td>
                                        <td>{{ formatStock(row.needed_grams) }}</td>
                                        <td>{{ formatStock(row.available_grams) }}</td>
                                        <td :class="row.deficit_grams >= 0 ? 'diff-positive' : 'diff-negative'">
                                            {{ row.deficit_grams >= 0 ? '+' : '' }}{{ formatStock(row.deficit_grams) }}
                                        </td>
                                        <td>
                                            <span class="badge" :class="'badge-'+row.status">
                                                {{ row.status === 'ok' ? 'Voldoende' : row.status === 'laag' ? 'Krap' : 'Tekort' }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- MODALS -->

                <!-- Ingredient modal -->
                <div class="modal-overlay" :class="{active: showIngredientModal}" @click.self="showIngredientModal=false">
                    <div class="modal">
                        <div class="modal-header">
                            <h3>{{ editingIngredient ? 'Ingrediënt Bewerken' : 'Nieuw Ingrediënt' }}</h3>
                            <button class="modal-close" @click="showIngredientModal=false">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label class="form-label">Naam *</label>
                                <input type="text" class="form-input" v-model="ingredientForm.name" required>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Categorie</label>
                                    <select class="form-select" v-model="ingredientForm.category">
                                        <option value="meel">Meel</option>
                                        <option value="mixin">Toppings + Mix-ins</option>
                                        <option value="overig">Overig</option>
                                        <option value="gist" v-if="ingredientForm.category === 'gist'">Gist (oud)</option>
                                        <option value="topping" v-if="ingredientForm.category === 'topping'">Topping (oud)</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Eenheid</label>
                                    <select class="form-select" v-model="ingredientForm.unit">
                                        <option value="g">Gram (g)</option>
                                        <option value="kg">Kilogram (kg)</option>
                                        <option value="ml">Milliliter (ml)</option>
                                        <option value="l">Liter (l)</option>
                                    </select>
                                </div>
                            </div>
                            <template v-if="ingredientForm.category === 'meel'">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Graansoort</label>
                                        <select class="form-select" v-model="ingredientForm.grain_type_id">
                                            <option value="">Selecteer...</option>
                                            <option v-for="gt in grainTypes" :key="gt.id" :value="gt.id">{{ gt.name }}</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Type</label>
                                        <select class="form-select" v-model="ingredientForm.is_whole_grain">
                                            <option :value="0">Wit</option>
                                            <option :value="1">Volkoren</option>
                                        </select>
                                    </div>
                                </div>
                            </template>
                            <div class="form-group">
                                <label class="toggle-label">
                                    <input type="checkbox" v-model="ingredientForm.is_biologisch" :true-value="1" :false-value="0">
                                    Biologisch product
                                </label>
                            </div>
                            <div class="form-group">
                                <label class="toggle-label">
                                    <input type="checkbox" v-model="ingredientForm.is_allergeen" :true-value="1" :false-value="0">
                                    Allergeen
                                </label>
                            </div>
                            <div class="form-group" v-if="ingredientForm.is_allergeen">
                                <label class="form-label">Allergeen naam (optioneel)</label>
                                <select class="form-input" v-model="ingredientForm.allergeen_naam">
                                    <option value="">— Selecteer allergeen —</option>
                                    <option value="Gluten">Gluten</option>
                                    <option value="Schaaldieren">Schaaldieren</option>
                                    <option value="Eieren">Eieren</option>
                                    <option value="Vis">Vis</option>
                                    <option value="Pinda's">Pinda's</option>
                                    <option value="Soja">Soja</option>
                                    <option value="Melk">Melk</option>
                                    <option value="Noten">Noten</option>
                                    <option value="Selderij">Selderij</option>
                                    <option value="Mosterd">Mosterd</option>
                                    <option value="Sesam">Sesam</option>
                                    <option value="Sulfieten">Sulfieten</option>
                                    <option value="Lupine">Lupine</option>
                                    <option value="Weekdieren">Weekdieren</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-ghost" @click="showIngredientModal=false">Annuleren</button>
                            <button class="btn btn-primary" @click="saveIngredient" :disabled="saving">
                                {{ editingIngredient ? 'Opslaan' : 'Aanmaken' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Grain type modal -->
                <div class="modal-overlay" :class="{active: showGrainTypeModal}" @click.self="showGrainTypeModal=false">
                    <div class="modal">
                        <div class="modal-header">
                            <h3><i class="bi bi-tags"></i> Graansoorten Beheren</h3>
                            <button class="modal-close" @click="showGrainTypeModal=false">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="grain-type-list">
                                <div v-for="gt in grainTypes" :key="gt.id" class="grain-type-item">
                                    <span>{{ gt.name }}</span>
                                    <button class="btn btn-danger btn-sm" @click="deleteGrainType(gt.id)"><i class="bi bi-trash"></i></button>
                                </div>
                                <div v-if="grainTypes.length === 0" style="color:#888;padding:1rem 0;text-align:center">
                                    Nog geen graansoorten toegevoegd
                                </div>
                            </div>
                            <div class="add-grain-type">
                                <input type="text" class="form-input" v-model="newGrainTypeName" placeholder="Nieuwe graansoort..." @keyup.enter="addGrainType">
                                <button class="btn btn-primary" @click="addGrainType" :disabled="!newGrainTypeName.trim()"><i class="bi bi-plus"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Batch modal -->
                <div class="modal-overlay" :class="{active: showBatchModal}" @click.self="showBatchModal=false">
                    <div class="modal">
                        <div class="modal-header">
                            <h3>{{ editingBatch ? 'Batch Bewerken' : 'Voorraad Toevoegen' }}</h3>
                            <button class="modal-close" @click="showBatchModal=false">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label class="form-label">Ingrediënt *</label>
                                <select class="form-select" v-model="batchForm.ingredient_id" :disabled="editingBatch">
                                    <option value="">Selecteer ingrediënt</option>
                                    <option v-for="ing in ingredients" :value="ing.id">{{ ing.name }}</option>
                                </select>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Hoeveelheid *</label>
                                    <div class="input-with-unit">
                                        <input type="number" class="form-input" v-model.number="batchForm.quantity" min="0" step="0.01">
                                        <select style="border:2px solid #e8dfd2;border-left:none;border-radius:0 8px 8px 0;padding:0.5rem;background:#f5f0e8;font-weight:600;color:#888" v-model="batchForm.unit">
                                            <option value="kg">kg</option>
                                            <option value="g">g</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Prijs per kg *</label>
                                    <div class="input-with-unit">
                                        <input type="number" class="form-input" v-model.number="batchForm.price_per_kg" min="0" step="0.01">
                                        <span class="input-unit">€</span>
                                    </div>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Inkoopdatum</label>
                                    <input type="date" class="form-input" v-model="batchForm.purchase_date">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">T.H.T. datum <span style="color:#aaa;font-weight:400">(optioneel)</span></label>
                                    <input type="date" class="form-input" v-model="batchForm.thd_date">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-ghost" @click="showBatchModal=false">Annuleren</button>
                            <button class="btn btn-success" @click="saveBatch" :disabled="saving">
                                {{ editingBatch ? 'Opslaan' : 'Toevoegen' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Consolidatie modal -->
                <div class="modal-overlay" :class="{active: showConsolidationModal}" @click.self="showConsolidationModal=false">
                    <div class="modal modal-wide">
                        <div class="modal-header">
                            <h3><i class="bi bi-clipboard-check"></i> Consolidatie uitvoeren</h3>
                            <button class="modal-close" @click="showConsolidationModal=false">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="form-row" style="margin-bottom:1rem">
                                <div class="form-group">
                                    <label class="form-label">Datum van telling *</label>
                                    <input type="date" class="form-input" v-model="consolidationDate">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Notitie</label>
                                    <input type="text" class="form-input" v-model="consolidationNotes" placeholder="Optionele opmerking...">
                                </div>
                            </div>

                            <div class="consolidation-item consolidation-header" style="margin-bottom:0.5rem;padding-bottom:0.5rem;border-bottom:2px solid #e8dfd2">
                                <span>Ingrediënt</span>
                                <span>Verwacht (systeem)</span>
                                <span>Geteld (kg)</span>
                                <span>Verschil</span>
                            </div>
                            <div v-for="item in consolidationItems" :key="item.ingredient_id" class="consolidation-item">
                                <span style="font-weight:600">{{ item.name }}</span>
                                <span style="color:#888">{{ formatStock(item.expected) }}</span>
                                <div>
                                    <div class="input-with-unit" style="max-width:120px">
                                        <input type="number" class="form-input" v-model.number="item.counted_kg" min="0" step="0.001" style="padding:0.4rem 0.5rem;font-size:0.85rem">
                                        <span class="input-unit" style="padding:0.4rem 0.5rem;font-size:0.8rem">kg</span>
                                    </div>
                                </div>
                                <span :class="consolidationDiff(item) >= 0 ? 'diff-positive' : 'diff-negative'">
                                    {{ consolidationDiff(item) >= 0 ? '+' : '' }}{{ formatStock(consolidationDiff(item)) }}
                                </span>
                            </div>
                            <div v-if="consolidationItems.length === 0" style="text-align:center;padding:2rem;color:#aaa">
                                Geen actieve ingrediënten gevonden
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-ghost" @click="showConsolidationModal=false">Annuleren</button>
                            <button class="btn btn-primary" @click="saveConsolidation" :disabled="saving || !consolidationDate">
                                <i class="bi bi-save"></i> Consolidatie Opslaan
                            </button>
                        </div>
                    </div>
                </div>

                <div class="toast" :class="toastType" v-if="toastMsg">{{ toastMsg }}</div>
            </div>
        </div>
    </div>

    <script src="../../js/ui-notifications.js?v=1"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <script>
    const { createApp } = Vue;
    const TODAY = new Date().toISOString().slice(0, 10);

    createApp({
        data() {
            return {
                activeTab: 'overzicht',
                ingredientSubTab: 'meel',
                ingredients: [],
                batches: [],
                grainTypes: [],
                filterCategory: '',
                filterIngredient: '',
                filterGrainType: '',
                filterWholeGrain: '',
                hideEmptyBatches: false,

                showIngredientModal: false,
                editingIngredient: null,
                ingredientForm: { name: '', category: 'meel', unit: 'g', grain_type_id: '', is_whole_grain: 0, is_biologisch: 0, is_allergeen: 0, allergeen_naam: '' },

                showGrainTypeModal: false,
                newGrainTypeName: '',

                showBatchModal: false,
                editingBatch: null,
                batchForm: { ingredient_id: '', quantity: '', unit: 'kg', price_per_kg: '', purchase_date: '', thd_date: '' },

                currentMonth: new Date().getFullYear() + '-' + String(new Date().getMonth() + 1).padStart(2, '0'),
                waterCost: { cost: null, estimated_cost: null },
                electricityCost: { cost: null, estimated_cost: null },
                monthlyLoaves: { gebakken: 0, te_bakken: 0, totaal: 0 },

                showConsolidationModal: false,
                consolidationDate: new Date().toISOString().slice(0, 10),
                consolidationNotes: '',
                consolidationItems: [],
                consolidations: [],

                forecastData: [],
                forecastPeriod: null,
                forecastMeta: null,
                loadingForecast: false,

                saving: false,
                savingUtility: false,
                toastMsg: '',
                toastType: 'success'
            };
        },

        computed: {
            filteredIngredients() {
                if (!this.filterCategory) return this.ingredients;
                return this.ingredients.filter(i => i.category === this.filterCategory);
            },
            filteredMeel() {
                let list = this.ingredients.filter(i => i.category === 'meel');
                if (this.filterGrainType) {
                    list = list.filter(i => i.grain_type_id == this.filterGrainType);
                }
                if (this.filterWholeGrain !== '') {
                    list = list.filter(i => i.is_whole_grain == this.filterWholeGrain);
                }
                return list;
            },
            gistIngredients() {
                return this.ingredients.filter(i => i.category === 'gist');
            },
            extrasIngredients() {
                return this.ingredients.filter(i => i.category === 'mixin' || i.category === 'topping');
            },
            overigIngredients() {
                return this.ingredients.filter(i => i.category === 'overig' || i.category === 'gist');
            },
            filteredBatches() {
                let list = this.batches;
                if (this.filterIngredient) {
                    list = list.filter(b => b.ingredient_id == this.filterIngredient);
                }
                if (this.hideEmptyBatches) {
                    list = list.filter(b => parseFloat(b.quantity_remaining) > 0);
                }
                return list;
            },
            lowStockItems() {
                return this.ingredients.filter(i => parseFloat(i.total_stock || 0) < 1000);
            },
            lowStockCount() {
                return this.lowStockItems.length;
            },
            totalStockValue() {
                return this.ingredients.reduce((sum, i) => {
                    const stock = parseFloat(i.total_stock || 0) / 1000;
                    const price = parseFloat(i.current_price_per_kg || 0);
                    return sum + (stock * price);
                }, 0);
            },
            expiredBatchCount() {
                return this.batches.filter(b => this.isBatchExpired(b)).length;
            },
            kostPerBrood() {
                const totaal = this.monthlyLoaves.totaal;
                if (!totaal) return null;
                const waterVal = this.waterCost.cost !== null ? parseFloat(this.waterCost.cost) : (this.waterCost.estimated_cost !== null ? parseFloat(this.waterCost.estimated_cost) : null);
                const elecVal = this.electricityCost.cost !== null ? parseFloat(this.electricityCost.cost) : (this.electricityCost.estimated_cost !== null ? parseFloat(this.electricityCost.estimated_cost) : null);
                if (waterVal === null && elecVal === null) return null;
                return ((waterVal || 0) + (elecVal || 0)) / totaal;
            },
            monthName() {
                const [y, m] = this.currentMonth.split('-');
                const months = ['Januari','Februari','Maart','April','Mei','Juni','Juli','Augustus','September','Oktober','November','December'];
                return months[parseInt(m)-1] + ' ' + y;
            }
        },

        methods: {
            isBatchExpired(batch) {
                return batch.thd_date && batch.thd_date < TODAY && parseFloat(batch.quantity_remaining) > 0;
            },

            async loadIngredients() {
                try {
                    const res = await fetch('../../api/ingredients.php');
                    const data = await res.json();
                    if (data.success) this.ingredients = data.ingredients;
                } catch(e) { console.error(e); }
            },

            async loadBatches() {
                try {
                    const res = await fetch('../../api/inventory.php?action=batches');
                    const data = await res.json();
                    if (data.success) this.batches = data.batches;
                } catch(e) { console.error(e); }
            },

            async loadMonthlyLoaves() {
                try {
                    const res = await fetch(`../../api/analytics.php?action=monthly_loaves&year_month=${this.currentMonth}`);
                    const data = await res.json();
                    if (data.success) this.monthlyLoaves = data;
                } catch(e) { console.error(e); }
            },

            async loadUtilityCosts() {
                try {
                    const res = await fetch(`../../api/utility-costs.php?year_month=${this.currentMonth}`);
                    const data = await res.json();
                    if (data.success) {
                        const water = data.costs.water;
                        const elec = data.costs.electricity;
                        this.waterCost = {
                            cost: water.cost !== null ? parseFloat(water.cost) : null,
                            estimated_cost: water.estimated_cost !== null ? parseFloat(water.estimated_cost) : null
                        };
                        this.electricityCost = {
                            cost: elec.cost !== null ? parseFloat(elec.cost) : null,
                            estimated_cost: elec.estimated_cost !== null ? parseFloat(elec.estimated_cost) : null
                        };
                    }
                } catch(e) { console.error(e); }
            },

            async loadConsolidations() {
                try {
                    const res = await fetch('../../api/inventory.php?action=consolidations');
                    const data = await res.json();
                    if (data.success) this.consolidations = data.consolidations.map(c => ({ ...c, _open: false }));
                } catch(e) { console.error(e); }
            },

            async loadForecast() {
                this.loadingForecast = true;
                try {
                    const res = await fetch('../../api/inventory.php?action=forecast');
                    const data = await res.json();
                    if (data.success) {
                        this.forecastData = data.forecast;
                        this.forecastPeriod = data.period;
                        this.forecastMeta = { orders_with_recipe: data.orders_with_recipe, orders_without_recipe: data.orders_without_recipe };
                    }
                } catch(e) { console.error(e); }
                this.loadingForecast = false;
            },

            openIngredientModal(ing = null, defaultCategory = null) {
                this.editingIngredient = ing;
                if (ing) {
                    this.ingredientForm = {
                        name: ing.name,
                        category: ing.category,
                        unit: ing.unit,
                        grain_type_id: ing.grain_type_id || '',
                        is_whole_grain: ing.is_whole_grain || 0,
                        is_biologisch: parseInt(ing.is_biologisch) || 0,
                        is_allergeen: parseInt(ing.is_allergeen) || 0,
                        allergeen_naam: ing.allergeen_naam || ''
                    };
                } else {
                    this.ingredientForm = {
                        name: '',
                        category: defaultCategory || 'meel',
                        unit: 'g',
                        grain_type_id: '',
                        is_whole_grain: 0,
                        is_biologisch: 0,
                        is_allergeen: 0,
                        allergeen_naam: ''
                    };
                }
                this.showIngredientModal = true;
            },

            getGrainTypeName(id) {
                if (!id) return '-';
                const gt = this.grainTypes.find(g => g.id == id);
                return gt ? gt.name : '-';
            },

            async loadGrainTypes() {
                try {
                    const res = await fetch('../../api/grain-types.php');
                    const data = await res.json();
                    if (data.success) this.grainTypes = data.grain_types;
                } catch(e) { console.error(e); }
            },

            async addGrainType() {
                if (!this.newGrainTypeName.trim()) return;
                try {
                    const res = await fetch('../../api/grain-types.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ name: this.newGrainTypeName.trim() })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast('Graansoort toegevoegd');
                        this.newGrainTypeName = '';
                        this.loadGrainTypes();
                    }
                } catch(e) { this.showToast('Fout bij toevoegen', 'error'); }
            },

            async deleteGrainType(id) {
                if (!await showConfirm('Weet je zeker dat je deze graansoort wilt verwijderen?')) return;
                try {
                    const res = await fetch(`../../api/grain-types.php?id=${id}`, { method: 'DELETE' });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast('Graansoort verwijderd');
                        this.loadGrainTypes();
                    } else {
                        this.showToast(data.error || 'Fout', 'error');
                    }
                } catch(e) { this.showToast('Fout bij verwijderen', 'error'); }
            },

            async saveIngredient() {
                if (!this.ingredientForm.name.trim()) {
                    this.showToast('Vul een naam in', 'error');
                    return;
                }
                this.saving = true;
                try {
                    const method = this.editingIngredient ? 'PUT' : 'POST';
                    const body = { ...this.ingredientForm };
                    if (this.editingIngredient) body.id = this.editingIngredient.id;

                    const res = await fetch('../../api/ingredients.php', {
                        method,
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(body)
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast(this.editingIngredient ? 'Ingrediënt bijgewerkt' : 'Ingrediënt aangemaakt');
                        this.showIngredientModal = false;
                        this.loadIngredients();
                    } else {
                        this.showToast(data.error || 'Fout', 'error');
                    }
                } catch(e) { this.showToast('Fout bij opslaan', 'error'); }
                this.saving = false;
            },

            openBatchModal(ing = null) {
                this.editingBatch = null;
                this.batchForm = {
                    ingredient_id: ing ? ing.id : '',
                    quantity: '',
                    unit: 'kg',
                    price_per_kg: '',
                    purchase_date: new Date().toISOString().slice(0, 10),
                    thd_date: ''
                };
                this.showBatchModal = true;
            },

            editBatch(batch) {
                this.editingBatch = batch;
                this.batchForm = {
                    ingredient_id: batch.ingredient_id,
                    quantity: batch.quantity_remaining / 1000,
                    unit: 'kg',
                    price_per_kg: batch.price_per_kg,
                    purchase_date: batch.purchase_date,
                    thd_date: batch.thd_date || ''
                };
                this.showBatchModal = true;
            },

            async saveBatch() {
                if (!this.batchForm.ingredient_id || !this.batchForm.quantity || !this.batchForm.price_per_kg) {
                    this.showToast('Vul alle verplichte velden in', 'error');
                    return;
                }
                this.saving = true;
                try {
                    if (this.editingBatch) {
                        let qty = this.batchForm.quantity;
                        if (this.batchForm.unit === 'kg') qty *= 1000;

                        const res = await fetch('../../api/inventory.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'adjust_batch',
                                batch_id: this.editingBatch.id,
                                quantity_remaining: qty,
                                price_per_kg: this.batchForm.price_per_kg,
                                thd_date: this.batchForm.thd_date || null
                            })
                        });
                        const data = await res.json();
                        if (data.success) {
                            this.showToast('Batch bijgewerkt');
                            this.showBatchModal = false;
                            this.loadBatches();
                            this.loadIngredients();
                        }
                    } else {
                        const res = await fetch('../../api/inventory.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'add_batch',
                                ...this.batchForm
                            })
                        });
                        const data = await res.json();
                        if (data.success) {
                            this.showToast('Voorraad toegevoegd');
                            this.showBatchModal = false;
                            this.loadBatches();
                            this.loadIngredients();
                        }
                    }
                } catch(e) { this.showToast('Fout bij opslaan', 'error'); }
                this.saving = false;
            },

            async purgeBatch(batch) {
                const name = batch.ingredient_name;
                const qty = this.formatStock(batch.quantity_remaining);
                if (!await showConfirm(`"${name}" (${qty}) weggooien?\n\nDit wordt gelogd als derving en kan niet ongedaan gemaakt worden.`)) return;
                try {
                    const res = await fetch('../../api/inventory.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'purge_batch', batch_id: batch.id })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast('Batch weggegooid');
                        this.loadBatches();
                        this.loadIngredients();
                    } else {
                        this.showToast(data.error || 'Fout', 'error');
                    }
                } catch(e) { this.showToast('Fout', 'error'); }
            },

            async deleteBatch(id) {
                if (!await showConfirm('Weet je zeker dat je deze batch wilt verwijderen?')) return;
                try {
                    const res = await fetch(`../../api/inventory.php?batch_id=${id}`, { method: 'DELETE' });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast('Batch verwijderd');
                        this.loadBatches();
                        this.loadIngredients();
                    }
                } catch(e) { this.showToast('Fout bij verwijderen', 'error'); }
            },

            async openConsolidationModal() {
                // Load current stock per ingredient
                const res = await fetch('../../api/inventory.php?action=stock_summary');
                const data = await res.json();
                if (data.success) {
                    this.consolidationItems = data.summary
                        .filter(i => parseFloat(i.total_stock) > 0)
                        .map(i => ({
                            ingredient_id: i.id,
                            name: i.name,
                            expected: parseFloat(i.total_stock),
                            counted_kg: parseFloat(i.total_stock) / 1000
                        }));
                }
                this.consolidationDate = new Date().toISOString().slice(0, 10);
                this.consolidationNotes = '';
                this.showConsolidationModal = true;
            },

            consolidationDiff(item) {
                return (parseFloat(item.counted_kg) || 0) * 1000 - item.expected;
            },

            async saveConsolidation() {
                if (!this.consolidationDate) {
                    this.showToast('Vul een datum in', 'error');
                    return;
                }
                this.saving = true;
                try {
                    const items = this.consolidationItems.map(i => ({
                        ingredient_id: i.ingredient_id,
                        counted_grams: (parseFloat(i.counted_kg) || 0) * 1000
                    }));
                    const res = await fetch('../../api/inventory.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'consolidation',
                            consolidation_date: this.consolidationDate,
                            notes: this.consolidationNotes,
                            items
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast('Consolidatie opgeslagen');
                        this.showConsolidationModal = false;
                        this.loadBatches();
                        this.loadIngredients();
                        this.loadConsolidations();
                    } else {
                        this.showToast(data.error || 'Fout bij opslaan', 'error');
                    }
                } catch(e) { this.showToast('Fout bij opslaan', 'error'); }
                this.saving = false;
            },

            async saveUtilityCost(type) {
                const cost = type === 'water' ? this.waterCost : this.electricityCost;
                this.savingUtility = true;
                try {
                    const res = await fetch('../../api/utility-costs.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            type,
                            year_month: this.currentMonth,
                            cost: cost.cost !== '' ? cost.cost : null,
                            estimated_cost: cost.estimated_cost !== '' ? cost.estimated_cost : null
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast('Kosten opgeslagen');
                        this.loadUtilityCosts();
                    } else {
                        this.showToast(data.error || 'Fout bij opslaan', 'error');
                    }
                } catch(e) { this.showToast('Fout bij opslaan', 'error'); }
                this.savingUtility = false;
            },

            prevMonth() {
                const [y, m] = this.currentMonth.split('-').map(Number);
                const d = new Date(y, m - 2, 1);
                this.currentMonth = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
                this.loadUtilityCosts();
                this.loadMonthlyLoaves();
            },

            nextMonth() {
                const [y, m] = this.currentMonth.split('-').map(Number);
                const d = new Date(y, m, 1);
                this.currentMonth = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
                this.loadUtilityCosts();
                this.loadMonthlyLoaves();
            },

            formatStock(grams) {
                const g = parseFloat(grams || 0);
                if (g >= 1000) return (g / 1000).toFixed(1) + ' kg';
                return Math.round(g) + ' g';
            },

            formatNumber(n) {
                return parseFloat(n || 0).toFixed(2).replace('.', ',');
            },

            formatDate(d) {
                if (!d) return '-';
                return new Date(d).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short', year: 'numeric' });
            },

            stockLevel(grams) {
                const g = parseFloat(grams || 0);
                if (g === 0) return 'empty';
                if (g < 1000) return 'low';
                return '';
            },

            stockPercent(grams) {
                const g = parseFloat(grams || 0);
                return Math.min(100, (g / 10000) * 100);
            },

            showToast(msg, type = 'success') {
                this.toastMsg = msg;
                this.toastType = type;
                setTimeout(() => this.toastMsg = '', 2500);
            }
        },

        mounted() {
            this.loadIngredients();
            this.loadBatches();
            this.loadGrainTypes();
            this.loadUtilityCosts();
            this.loadMonthlyLoaves();
            this.loadConsolidations();
        }
    }).mount('#app');
    </script>
</body>
</html>
