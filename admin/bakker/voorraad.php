<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$adminPageTitle = 'Voorraadbeheer';
$currentPage = 'voorraad';
$adminBasePath = '../';
ob_start(); ?>
<style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--cream); min-height: 100vh; }
        .admin-content { padding: 1.5rem; }
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

        .table-wrapper { overflow-x: auto; border: 1px solid #e5e7eb; border-radius: 6px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; background: white; }
        th { text-align: left; padding: 0.5rem 0.875rem; color: #6b7280; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 1px solid #e5e7eb; background: #f9fafb; white-space: nowrap; }
        td { padding: 0.625rem 0.875rem; border-bottom: 1px solid #f3f4f6; vertical-align: middle; color: #374151; }
        tbody tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fefcf8; }
        td strong { font-weight: 600; color: #1f2937; }
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

        tr.category-row { background: #f3f4f6; cursor: pointer; user-select: none; }
        .category-row td { padding: 0.35rem 0.875rem !important; background: #f3f4f6 !important; border-bottom: 1px solid #e5e7eb !important; }
        .category-cell { display: flex; align-items: center; gap: 0.5rem; width: 100%; }
        .category-header-label { font-size: 0.72rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; }
        .category-header-count { display: inline-flex; align-items: center; justify-content: center; min-width: 1.2rem; height: 1.2rem; background: #d1d5db; color: #374151; border-radius: 10px; font-size: 0.65rem; font-weight: 700; padding: 0 0.3rem; }
        .category-row:hover td { background: #ececec !important; }
        .category-chevron { display: inline-flex; align-items: center; margin-right: 0.25rem; font-size: 0.72rem; color: #9ca3af; transition: transform 0.15s; }
        .category-chevron.collapsed { transform: rotate(-90deg); }

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
        [v-cloak] { display: none; }

        .drag-handle { color: #ccc; cursor: grab; padding: 0 0.2rem; font-size: 1rem; display: inline-flex; align-items: center; }
        .drag-handle:active { cursor: grabbing; }
        .drag-cell { width: 24px; padding-right: 0 !important; padding-left: 0.5rem !important; }
        tr.drag-over td { background: #dbeafe !important; }
        tr.dragging { opacity: 0.4; }
        .subcategory-row td { padding: 0.3rem 0.875rem 0.3rem 2rem !important; background: #f9fafb !important; border-bottom: 1px solid #e5e7eb !important; }
        .subcategory-label { font-size: 0.72rem; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.04em; }
        .btn-add { border: 1px dashed #d1d5db; border-radius: 4px; background: transparent; color: #9ca3af; cursor: pointer; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.25rem 0.5rem; }
        .btn-add:hover { border-color: #3d6b3d; color: #3d6b3d; background: #f0f7f0; }
        tr.ingredient-add-row td { padding: 0.3rem 0.875rem !important; border-bottom: none; }
        tr.ingredient-add-row { cursor: pointer; }
        tr.ingredient-add-row:hover td { background: #f9fafb; }
    </style>
<?php $adminExtraHead = ob_get_clean();
require_once '../components/sidebar.php'; ?>

            <header class="topbar">
                <div class="topbar-left">
                    <span class="topbar-title">Voorraadbeheer</span>
                </div>
                <div class="topbar-right"></div>
            </header>

            <div class="admin-content" id="app" v-cloak>
                <div class="tabs">
                    <div class="tab" :class="{active: activeTab==='overzicht'}" @click="activeTab='overzicht'">Overzicht</div>
                    <div class="tab" :class="{active: activeTab==='ingredienten'}" @click="activeTab='ingredienten'">Ingrediënten</div>
                    <div class="tab" :class="{active: activeTab==='voorraad'}" @click="activeTab='voorraad'">Voorraad</div>
                    <div class="tab" :class="{active: activeTab==='kosten'}" @click="activeTab='kosten'">Vaste Kosten</div>
                    <div class="tab" :class="{active: activeTab==='prognose'}" @click="activeTab='prognose'; loadForecast()">Prognose</div>
                    <div class="tab" :class="{active: activeTab==='afgemaakt'}" @click="activeTab='afgemaakt'; loadAfgemaakt()"><i class="bi bi-box-seam-fill"></i> Producten</div>
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
                        <template v-else>
                            <div class="table-wrapper">
                                <table>
                                    <colgroup>
                                        <col style="width:50%">
                                        <col style="width:25%">
                                        <col style="width:25%">
                                    </colgroup>
                                    <thead><tr><th>Ingrediënt</th><th>Voorraad</th><th>Actie</th></tr></thead>
                                    <tbody v-for="group in groupedLowStockDisplay" :key="group.category">
                                        <tr class="category-row">
                                            <td colspan="3">
                                                <div class="category-cell">
                                                    <span class="category-header-label">{{ categoryLabel(group.category) }}</span>
                                                    <span class="category-header-count">({{ group.items.length }})</span>
                                                </div>
                                            </td>
                                        </tr>
                                        <template v-if="group.subGroups">
                                            <template v-for="sub in group.subGroups" :key="sub.grain_type_id || '_none'">
                                                <tr class="subcategory-row">
                                                    <td colspan="3"><span class="subcategory-label">{{ sub.label }}</span></td>
                                                </tr>
                                                <tr v-for="item in sub.items" :key="item.id">
                                                    <td><strong>{{ item.name }}</strong></td>
                                                    <td>{{ formatStock(item.total_stock) }}</td>
                                                    <td><button class="btn btn-success btn-sm" @click="openBatchModal(item)"><i class="bi bi-plus"></i> Bijvullen</button></td>
                                                </tr>
                                            </template>
                                        </template>
                                        <template v-else>
                                            <tr v-for="item in group.items" :key="item.id">
                                                <td><strong>{{ item.name }}</strong></td>
                                                <td>{{ formatStock(item.total_stock) }}</td>
                                                <td><button class="btn btn-success btn-sm" @click="openBatchModal(item)"><i class="bi bi-plus"></i> Bijvullen</button></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- INGREDIËNTEN TAB -->
                <div v-show="activeTab==='ingredienten'">
                    <div class="subtabs">
                        <div class="subtab" :class="{active: ingredientSubTab==='meel'}" @click="ingredientSubTab='meel'">Meel</div>
                        <div class="subtab" :class="{active: ingredientSubTab==='extras'}" @click="ingredientSubTab='extras'">Toppings + Mix-ins</div>
                        <div class="subtab" :class="{active: ingredientSubTab==='overig'}" @click="ingredientSubTab='overig'">Overig</div>
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

                        <div v-if="filteredMeel.length === 0" class="empty-state" style="padding:1.5rem">
                            <i class="bi bi-inbox"></i>
                            <p>Geen meelsoorten gevonden</p>
                        </div>
                        <div class="table-wrapper" v-else>
                            <table>
                                <thead><tr><th class="drag-cell"></th><th>Naam</th><th>Type</th><th>Voorraad</th><th>FIFO Prijs</th><th>Acties</th></tr></thead>
                                <tbody v-for="group in groupedMeel" :key="group.grain_type_id">
                                    <tr class="category-row"
                                        :class="{'drag-over': draggingGrainTypeOverId == group.grain_type_id}"
                                        :draggable="group.grain_type_id !== '_none'"
                                        @click="toggleGrainType(group.grain_type_id)"
                                        @dragstart="group.grain_type_id !== '_none' && onGrainTypeDragStart($event, group.grain_type_id)"
                                        @dragover="group.grain_type_id !== '_none' && onGrainTypeDragOver($event, group.grain_type_id)"
                                        @dragleave="draggingGrainTypeOverId = null"
                                        @drop="group.grain_type_id !== '_none' && onGrainTypeDrop($event, group.grain_type_id)"
                                        @dragend="draggingGrainTypeId = null; draggingGrainTypeOverId = null">
                                        <td class="drag-cell">
                                            <span v-if="group.grain_type_id !== '_none'" class="drag-handle"><i class="bi bi-grip-vertical"></i></span>
                                        </td>
                                        <td colspan="5">
                                            <div class="category-cell">
                                                <i class="bi bi-chevron-down category-chevron" :class="{collapsed: collapsedGrainTypes[group.grain_type_id]}"></i>
                                                <span class="category-header-label">{{ group.label }}</span>
                                                <span class="category-header-count">{{ group.items.length }}</span>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-for="ing in group.items" :key="ing.id"
                                        v-show="!collapsedGrainTypes[group.grain_type_id]"
                                        :class="{'drag-over': draggingIngredientOverId == ing.id, 'dragging': draggingIngredientId == ing.id}"
                                        draggable="true"
                                        @dragstart.stop="onIngredientDragStart($event, ing.id)"
                                        @dragover.prevent.stop="onIngredientDragOver($event, ing.id)"
                                        @dragleave="draggingIngredientOverId = null"
                                        @drop.stop="onIngredientDrop($event, ing.id)"
                                        @dragend="draggingIngredientId = null; draggingIngredientOverId = null">
                                        <td class="drag-cell"><span class="drag-handle"><i class="bi bi-grip-vertical"></i></span></td>
                                        <td><strong>{{ ing.name }}</strong></td>
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
                                            <button class="btn btn-ghost btn-sm" @click="openIngredientModal(ing)"><i class="bi bi-pencil"></i> Bewerken</button>
                                            <button class="btn btn-success btn-sm" @click="openBatchModal(ing)"><i class="bi bi-plus"></i> Bijvullen</button>
                                        </td>
                                    </tr>
                                    <tr class="ingredient-add-row" v-show="!collapsedGrainTypes[group.grain_type_id]" @click="openIngredientModal(null, 'meel', group.grain_type_id !== '_none' ? group.grain_type_id : null)">
                                        <td class="drag-cell"></td>
                                        <td colspan="5"><button class="btn-add" @click.stop="openIngredientModal(null, 'meel', group.grain_type_id !== '_none' ? group.grain_type_id : null)"><i class="bi bi-plus"></i> Nieuw meel</button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-show="ingredientSubTab==='extras'" class="panel">
                        <div class="panel-header">
                            <div class="panel-title"><i class="bi bi-plus-circle"></i> Toppings + Mix-ins</div>
                            <button class="btn btn-primary" @click="openIngredientModal(null, 'mixin')"><i class="bi bi-plus"></i> Nieuw</button>
                        </div>

                        <div class="table-wrapper">
                            <table>
                                <thead><tr><th class="drag-cell"></th><th>Naam</th><th>Voorraad</th><th>FIFO Prijs</th><th>Acties</th></tr></thead>
                                <tbody>
                                    <tr v-for="ing in extrasIngredients" :key="ing.id"
                                        :class="{'drag-over': draggingIngredientOverId == ing.id, 'dragging': draggingIngredientId == ing.id}"
                                        draggable="true"
                                        @dragstart="onIngredientDragStart($event, ing.id)"
                                        @dragover.prevent="onIngredientDragOver($event, ing.id)"
                                        @dragleave="draggingIngredientOverId = null"
                                        @drop="onIngredientDrop($event, ing.id)"
                                        @dragend="draggingIngredientId = null; draggingIngredientOverId = null">
                                        <td class="drag-cell"><span class="drag-handle"><i class="bi bi-grip-vertical"></i></span></td>
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
                                            <button class="btn btn-ghost btn-sm" @click="openIngredientModal(ing)"><i class="bi bi-pencil"></i> Bewerken</button>
                                            <button class="btn btn-success btn-sm" @click="openBatchModal(ing)"><i class="bi bi-plus"></i> Bijvullen</button>
                                        </td>
                                    </tr>
                                    <tr class="ingredient-add-row" @click="openIngredientModal(null, 'mixin')">
                                        <td class="drag-cell"></td>
                                        <td colspan="4"><button class="btn-add" @click.stop="openIngredientModal(null, 'mixin')"><i class="bi bi-plus"></i> Nieuw topping / mix-in</button></td>
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
                                <thead><tr><th class="drag-cell"></th><th>Naam</th><th>Voorraad</th><th>FIFO Prijs</th><th>Acties</th></tr></thead>
                                <tbody>
                                    <tr v-for="ing in overigIngredients" :key="ing.id"
                                        :class="{'drag-over': draggingIngredientOverId == ing.id, 'dragging': draggingIngredientId == ing.id}"
                                        draggable="true"
                                        @dragstart="onIngredientDragStart($event, ing.id)"
                                        @dragover.prevent="onIngredientDragOver($event, ing.id)"
                                        @dragleave="draggingIngredientOverId = null"
                                        @drop="onIngredientDrop($event, ing.id)"
                                        @dragend="draggingIngredientId = null; draggingIngredientOverId = null">
                                        <td class="drag-cell"><span class="drag-handle"><i class="bi bi-grip-vertical"></i></span></td>
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
                                            <button class="btn btn-ghost btn-sm" @click="openIngredientModal(ing)"><i class="bi bi-pencil"></i> Bewerken</button>
                                            <button class="btn btn-success btn-sm" @click="openBatchModal(ing)"><i class="bi bi-plus"></i> Bijvullen</button>
                                        </td>
                                    </tr>
                                    <tr class="ingredient-add-row" @click="openIngredientModal(null, 'overig')">
                                        <td class="drag-cell"></td>
                                        <td colspan="4"><button class="btn-add" @click.stop="openIngredientModal(null, 'overig')"><i class="bi bi-plus"></i> Nieuw overig ingrediënt</button></td>
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
                                <tbody v-for="group in groupedBatches" :key="group.category">
                                    <tr class="category-row" @click="toggleBatchCategory(group.category)">
                                        <td colspan="7">
                                            <div class="category-cell">
                                                <i class="bi bi-chevron-down category-chevron" :class="{collapsed: collapsedBatchCategories[group.category]}"></i>
                                                <span class="category-header-label">{{ categoryLabel(group.category) }}</span>
                                                <span class="category-header-count">{{ group.items.length }}</span>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-for="batch in group.items" :key="batch.id"
                                        v-show="!collapsedBatchCategories[group.category]"
                                        :class="{'batch-expired': isBatchExpired(batch), 'batch-empty': parseFloat(batch.quantity_remaining) === 0}">
                                        <td>
                                            <strong>{{ batch.ingredient_name }}</strong>
                                            <span v-if="parseInt(batch.is_open)" title="Geopend — wordt afgemaakt voor volgende batch" style="display:inline-block;margin-left:0.4rem;padding:0.1rem 0.4rem;background:#fff3e0;color:#e65100;border-radius:4px;font-size:0.7rem;font-weight:700">open</span>
                                        </td>
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
                            <div class="form-group" style="border-top:1px solid #f0ebe5;padding-top:0.75rem;margin-top:0.25rem">
                                <label class="toggle-label">
                                    <input type="checkbox" v-model="ingredientForm.use_verpakkingen" :true-value="1" :false-value="0">
                                    Komt in verpakkingen
                                    <span title="Als dit ingrediënt in losse verpakkingen komt (bijv. boter in blokken): een eenmaal geopende verpakking wordt altijd afgemaakt voordat een nieuwe batch wordt geopend, ook als die nieuwere batch een eerdere T.H.T. heeft." style="display:inline-flex;align-items:center;justify-content:center;width:1rem;height:1rem;background:#e0d5c7;border-radius:50%;font-size:0.65rem;font-weight:700;color:#5c3d1e;cursor:default;margin-left:0.25rem">?</span>
                                </label>
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


                <!-- AFGEMAAKT TAB -->
                <div v-show="activeTab==='afgemaakt'">
                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-title"><i class="bi bi-box-seam-fill"></i> Producten in opslag</div>
                            <button class="btn btn-primary btn-sm" @click="showAfgemaaktModal=true; afgemaaktForm={product_variant_id:'',product_name:'',quantity:1,unit:'stuks',location:'kast',notes:'',production_date:TODAY}"><i class="bi bi-plus"></i> Toevoegen</button>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-top:1rem">
                            <div v-for="loc in [{key:'kast',label:'Kast',icon:'bi-house-fill'},{key:'koelkast',label:'Koelkast',icon:'bi-snow'},{key:'vriezer',label:'Vriezer',icon:'bi-thermometer-snow'}]" :key="loc.key" style="background:#f9fafb;border-radius:10px;padding:1rem;border:1px solid #e5e7eb">
                                <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.75rem;font-weight:700;color:#2d4a2d;font-size:0.9rem"><i class="bi" :class="loc.icon"></i> {{ loc.label }}</div>
                                <div v-if="afgemaaktByLocation(loc.key).length===0" style="color:#bbb;font-size:0.82rem;text-align:center;padding:0.75rem 0">Leeg</div>
                                <div v-for="item in afgemaaktByLocation(loc.key)" :key="item.id" style="display:flex;justify-content:space-between;align-items:flex-start;padding:0.4rem 0;border-bottom:1px solid #f0f0f0;font-size:0.85rem">
                                    <div style="flex:1;min-width:0">
                                        <div style="font-weight:600;color:#1f2937">{{ item.product_name }}</div>
                                        <div style="color:#888;font-size:0.78rem">{{ item.quantity }} {{ item.unit }} &bull; {{ formatDate(item.production_date) }}<span v-if="item.notes"> &bull; {{ item.notes }}</span></div>
                                        <div v-if="item.status==='gereserveerd'" style="color:#e65100;font-size:0.72rem;font-weight:600">Gereserveerd</div>
                                    </div>
                                    <button @click="deleteAfgemaakt(item.id)" style="background:none;border:none;color:#dc3545;cursor:pointer;font-size:1rem;padding:0.2rem 0.4rem;border-radius:4px;line-height:1;flex-shrink:0" title="Verwijderen"><i class="bi bi-trash"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Afgemaakt modal -->
                    <div class="modal-overlay" :class="{active:showAfgemaaktModal}" @click.self="showAfgemaaktModal=false">
                        <div class="modal" style="max-width:420px">
                            <div class="modal-header"><h3><i class="bi bi-plus-circle"></i> Product toevoegen aan opslag</h3><button class="modal-close" @click="showAfgemaaktModal=false">&times;</button></div>
                            <div class="modal-body">
                                <div class="form-group">
                                    <label class="form-label">Product koppelen</label>
                                    <select class="form-select" v-model="afgemaaktForm.product_variant_id" @change="onVariantSelect">
                                        <option value="">— Vrije invoer —</option>
                                        <template v-for="cat in productenLijst" :key="cat.id">
                                            <optgroup :label="cat.naam">
                                                <template v-for="product in cat.products" :key="product.id">
                                                    <option v-for="variant in product.variants" :key="variant.id" :value="variant.id">{{ product.naam }}{{ variant.label ? ' — ' + variant.label : '' }}</option>
                                                </template>
                                            </optgroup>
                                        </template>
                                    </select>
                                </div>
                                <div class="form-group"><label class="form-label">Naam *</label><input type="text" class="form-input" v-model="afgemaaktForm.product_name" placeholder="bijv. Zuurdesem 750g"></div>
                                <div class="form-group" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                                    <div><label class="form-label">Aantal *</label><input type="number" class="form-input" v-model.number="afgemaaktForm.quantity" min="0.1" step="0.1"></div>
                                    <div><label class="form-label">Eenheid</label><select class="form-select" v-model="afgemaaktForm.unit"><option>stuks</option><option>kg</option><option>g</option><option>liter</option></select></div>
                                </div>
                                <div class="form-group"><label class="form-label">Opslag *</label><select class="form-select" v-model="afgemaaktForm.location"><option value="kast">Kast</option><option value="koelkast">Koelkast</option><option value="vriezer">Vriezer</option></select></div>
                                <div class="form-group"><label class="form-label">Datum</label><input type="date" class="form-input" v-model="afgemaaktForm.production_date"></div>
                                <div class="form-group"><label class="form-label">Notitie</label><input type="text" class="form-input" v-model="afgemaaktForm.notes" placeholder="Optioneel..."></div>
                            </div>
                            <div class="modal-footer"><button class="btn btn-ghost" @click="showAfgemaaktModal=false">Annuleren</button><button class="btn btn-primary" @click="saveAfgemaakt()"><i class="bi bi-check-lg"></i> Opslaan</button></div>
                        </div>
                    </div>
                </div>
                <div class="toast" :class="toastType" v-if="toastMsg">{{ toastMsg }}</div>
            </div>
        </div>
    </div>

    <script src="../../js/ui-notifications.js?v=1"></script>
    <script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js"></script>
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
                ingredientForm: { name: '', category: 'meel', unit: 'g', grain_type_id: '', is_whole_grain: 0, is_biologisch: 0, is_allergeen: 0, allergeen_naam: '', use_verpakkingen: 0 },

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
                toastType: 'success',

                draggingGrainTypeId: null,
                draggingGrainTypeOverId: null,
                draggingIngredientId: null,
                draggingIngredientOverId: null,

                collapsedGrainTypes: {},
                collapsedBatchCategories: {},

                // Producten in opslag
                afgemaaktItems: [],
                showAfgemaaktModal: false,
                afgemaaktForm: { product_variant_id: "", product_name: "", quantity: 1, unit: "stuks", location: "kast", notes: "", production_date: "" },
                productenLijst: [],
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
            groupedLowStock() {
                return this._groupBy(this.lowStockItems, 'category', item => item.category, cat => this.categoryLabel(cat));
            },
            groupedBatches() {
                const ingMap = {};
                this.ingredients.forEach(i => ingMap[i.id] = i.category || 'overig');
                return this._groupBy(this.filteredBatches, '_cat', item => ingMap[item.ingredient_id] || 'overig', cat => this.categoryLabel(cat));
            },
            groupedMeel() {
                const order = this.grainTypes.map(g => g.id);
                const groups = {};
                this.filteredMeel.forEach(ing => {
                    const key = ing.grain_type_id || '_none';
                    if (!groups[key]) groups[key] = { grain_type_id: key, label: this.getGrainTypeName(ing.grain_type_id) || 'Overig', items: [] };
                    groups[key].items.push(ing);
                });
                return Object.values(groups).sort((a, b) => {
                    const ai = order.indexOf(parseInt(a.grain_type_id));
                    const bi = order.indexOf(parseInt(b.grain_type_id));
                    return (ai === -1 ? 999 : ai) - (bi === -1 ? 999 : bi);
                });
            },
            monthName() {
                const [y, m] = this.currentMonth.split('-');
                const months = ['Januari','Februari','Maart','April','Mei','Juni','Juli','Augustus','September','Oktober','November','December'];
                return months[parseInt(m)-1] + ' ' + y;
            },
            groupedLowStockDisplay() {
                return this.groupedLowStock.map(group => {
                    if (group.category !== 'meel') return { ...group, subGroups: null };
                    const order = this.grainTypes.map(g => g.id);
                    const subs = {};
                    group.items.forEach(item => {
                        const key = item.grain_type_id || '_none';
                        if (!subs[key]) subs[key] = { grain_type_id: item.grain_type_id, label: this.getGrainTypeName(item.grain_type_id) || 'Overig', items: [] };
                        subs[key].items.push(item);
                    });
                    const subGroups = Object.values(subs).sort((a, b) => {
                        const ai = a.grain_type_id ? order.indexOf(parseInt(a.grain_type_id)) : 999;
                        const bi = b.grain_type_id ? order.indexOf(parseInt(b.grain_type_id)) : 999;
                        return (ai === -1 ? 999 : ai) - (bi === -1 ? 999 : bi);
                    });
                    return { ...group, subGroups };
                });
            },
        },

        methods: {
            toggleGrainType(id) {
                this.collapsedGrainTypes = { ...this.collapsedGrainTypes, [id]: !this.collapsedGrainTypes[id] };
            },
            toggleBatchCategory(cat) {
                this.collapsedBatchCategories = { ...this.collapsedBatchCategories, [cat]: !this.collapsedBatchCategories[cat] };
            },
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

            // ── Grain type drag ──
            onGrainTypeDragStart(e, id) {
                this.draggingGrainTypeId = id;
                e.dataTransfer.effectAllowed = 'move';
            },
            onGrainTypeDragOver(e, id) {
                if (!this.draggingGrainTypeId || this.draggingGrainTypeId == id) return;
                e.preventDefault();
                this.draggingGrainTypeOverId = id;
            },
            onGrainTypeDrop(e, id) {
                e.preventDefault();
                if (!this.draggingGrainTypeId || this.draggingGrainTypeId == id) return;
                const fromIdx = this.grainTypes.findIndex(g => g.id == this.draggingGrainTypeId);
                const toIdx   = this.grainTypes.findIndex(g => g.id == id);
                if (fromIdx === -1 || toIdx === -1) return;
                const moved = this.grainTypes.splice(fromIdx, 1)[0];
                this.grainTypes.splice(toIdx, 0, moved);
                this.draggingGrainTypeId = null;
                this.draggingGrainTypeOverId = null;
                this.saveGrainTypeOrder();
            },
            async saveGrainTypeOrder() {
                const items = this.grainTypes.map((g, i) => ({ id: g.id, sort_order: i }));
                await fetch('../../api/grain-types.php', {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reorder', items })
                });
            },

            // ── Ingredient drag ──
            onIngredientDragStart(e, id) {
                this.draggingIngredientId = id;
                e.dataTransfer.effectAllowed = 'move';
            },
            onIngredientDragOver(e, id) {
                if (!this.draggingIngredientId || this.draggingIngredientId == id) return;
                e.preventDefault();
                this.draggingIngredientOverId = id;
            },
            onIngredientDrop(e, id) {
                e.preventDefault();
                if (!this.draggingIngredientId || this.draggingIngredientId == id) return;
                const fromIdx = this.ingredients.findIndex(i => i.id == this.draggingIngredientId);
                const toIdx   = this.ingredients.findIndex(i => i.id == id);
                if (fromIdx === -1 || toIdx === -1) return;
                const from = this.ingredients[fromIdx];
                const to   = this.ingredients[toIdx];
                if (from.category !== to.category) return;
                if (from.category === 'meel' && (from.grain_type_id || null) != (to.grain_type_id || null)) return;
                const moved = this.ingredients.splice(fromIdx, 1)[0];
                this.ingredients.splice(toIdx, 0, moved);
                this.draggingIngredientId = null;
                this.draggingIngredientOverId = null;
                this.saveIngredientOrder(moved.id);
            },
            async saveIngredientOrder(ingId) {
                const ing = this.ingredients.find(i => i.id == ingId);
                if (!ing) return;
                let group;
                if (ing.category === 'meel') {
                    const gtId = ing.grain_type_id || null;
                    group = this.ingredients.filter(i => i.category === 'meel' && (i.grain_type_id || null) == gtId);
                } else {
                    group = this.ingredients.filter(i => i.category === ing.category);
                }
                const items = group.map((i, idx) => ({ id: i.id, sort_order: idx }));
                await fetch('../../api/ingredients.php', {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reorder', items })
                });
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

            openIngredientModal(ing = null, defaultCategory = null, defaultGrainTypeId = null) {
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
                        allergeen_naam: ing.allergeen_naam || '',
                        use_verpakkingen: parseInt(ing.use_verpakkingen) || 0,
                    };
                } else {
                    this.ingredientForm = {
                        name: '',
                        category: defaultCategory || 'meel',
                        unit: 'g',
                        grain_type_id: defaultGrainTypeId || '',
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

            _groupBy(items, _key, getCat, getLabel) {
                const catOrder = ['meel', 'mixin', 'topping', 'gist', 'overig'];
                const groups = {};
                items.forEach(item => {
                    const cat = getCat(item);
                    if (!groups[cat]) groups[cat] = { category: cat, label: getLabel(cat), items: [] };
                    groups[cat].items.push(item);
                });
                return Object.values(groups).sort((a, b) => {
                    const ai = catOrder.indexOf(a.category);
                    const bi = catOrder.indexOf(b.category);
                    return (ai === -1 ? 999 : ai) - (bi === -1 ? 999 : bi);
                });
            },

            categoryLabel(cat) {
                const labels = { meel: 'Meel', mixin: 'Toppings + Mix-ins', topping: 'Toppings', gist: 'Gist', overig: 'Overig' };
                return labels[cat] || cat;
            },

            categoryIcon(cat) {
                const icons = { meel: 'bi-moisture', mixin: 'bi-plus-circle', topping: 'bi-stars', gist: 'bi-flower1', overig: 'bi-grid' };
                return icons[cat] || 'bi-grid';
            },

            afgemaaktByLocation(loc) {
                return this.afgemaaktItems.filter(i => i.location === loc);
            },

            async loadAfgemaakt() {
                try {
                    const r = await fetch("../../api/voorraad-afgemaakt.php");
                    const d = await r.json();
                    if (d.success) this.afgemaaktItems = d.items;
                } catch(e) {}
                if (this.productenLijst.length === 0) this.loadProductenLijst();
            },

            async loadProductenLijst() {
                try {
                    const r = await fetch("../../api/products.php");
                    const d = await r.json();
                    if (!d.success) return;
                    // Group by category using products array
                    const catMap = {};
                    for (const p of d.products) {
                        const catId = p.category_id ?? 0;
                        if (!catMap[catId]) catMap[catId] = { id: catId, naam: p.category_naam || 'Overig', products: [] };
                        const variants = (p.variants || []).map(v => ({
                            id: v.id,
                            label: [v.naam, v.gewicht ? v.gewicht + 'g' : ''].filter(Boolean).join(' ')
                        }));
                        if (variants.length) catMap[catId].products.push({ id: p.id, naam: p.naam, variants });
                    }
                    this.productenLijst = Object.values(catMap).filter(c => c.products.length);
                } catch(e) {}
            },

            onVariantSelect() {
                const variantId = parseInt(this.afgemaaktForm.product_variant_id);
                if (!variantId) return;
                for (const cat of this.productenLijst) {
                    for (const product of cat.products) {
                        const variant = product.variants.find(v => v.id === variantId);
                        if (variant) {
                            this.afgemaaktForm.product_name = product.naam + (variant.label ? ' — ' + variant.label : '');
                            return;
                        }
                    }
                }
            },

            async saveAfgemaakt() {
                const form = this.afgemaaktForm;
                if (!form.product_name || !form.quantity) { this.showToast("Vul naam en aantal in", "error"); return; }
                const payload = { action: "create", product_name: form.product_name, product_variant_id: form.product_variant_id ? parseInt(form.product_variant_id) : null, quantity: form.quantity, unit: form.unit, location: form.location, notes: form.notes, production_date: form.production_date };
                const r = await fetch("../../api/voorraad-afgemaakt.php", { method: "POST", headers: {"Content-Type":"application/json"}, body: JSON.stringify(payload) });
                const d = await r.json();
                if (d.success) { this.showToast("Opgeslagen"); this.showAfgemaaktModal = false; this.loadAfgemaakt(); }
                else { this.showToast(d.error || "Fout bij opslaan", "error"); }
            },

            async deleteAfgemaakt(id) {
                if (!confirm("Verwijderen uit opslag?")) return;
                const r = await fetch("../../api/voorraad-afgemaakt.php", { method: "POST", headers: {"Content-Type":"application/json"}, body: JSON.stringify({action:"delete",id:id}) });
                const d = await r.json();
                if (d.success) { this.showToast("Verwijderd"); this.loadAfgemaakt(); }
                else { this.showToast(d.error || "Fout", "error"); }
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
