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

// Upcoming planned baking: orders per date+doughtype (not yet delivered/cancelled)
try {
    $stmtPlanned = $pdo->prepare("
        SELECT bo.delivery_date,
               COALESCE(dt.name, 'Geen deegsoort') AS dough_type_name,
               COUNT(DISTINCT bo.id) AS order_count,
               SUM(boi.quantity) AS total_qty
        FROM business_orders bo
        JOIN business_order_items boi ON boi.order_id = bo.id
        LEFT JOIN product_variants pv ON boi.variant_id = pv.id
        LEFT JOIN baker_recipes br ON pv.recipe_id = br.id
        LEFT JOIN dough_types dt ON br.dough_type_id = dt.id
        WHERE bo.delivery_date >= CURDATE()
          AND bo.is_cancelled = 0
          AND bo.delivery_status NOT IN ('afgeleverd')
        GROUP BY bo.delivery_date, dt.id
        ORDER BY bo.delivery_date, dt.name
    ");
    $stmtPlanned->execute();
    $geplandeRows = $stmtPlanned->fetchAll();
} catch (PDOException $e) {
    $geplandeRows = [];
    error_log('logboek gepland query failed: ' . $e->getMessage());
}
$geplandeJson = json_encode($geplandeRows);
$recipeVersionIdFilter = isset($_GET['recipe_version_id']) ? (int)$_GET['recipe_version_id'] : 0;

ob_start(); ?>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    [v-cloak] { display: none; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--cream); min-height: 100vh; }
    .admin-content { padding: 0; }

    /* ── Toolbar ── */
    .page-toolbar { display: flex; gap: 0.75rem; align-items: center; padding: 0.75rem 1.5rem; background: #fff; border-bottom: 1px solid #e5e7eb; flex-wrap: wrap; }
    .page-toolbar h2 { font-size: 1rem; font-weight: 700; color: #1f2937; flex: 1; }
    .btn { padding: 0.45rem 0.875rem; border: none; border-radius: 4px; font-size: 0.8rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem; transition: all 0.15s; }
    .btn-primary { background: #2563eb; color: white; }
    .btn-primary:hover { background: #1d4ed8; }
    .btn-danger { background: #ef4444; color: white; }
    .btn-danger:hover { background: #dc2626; }
    .btn-ghost { background: transparent; color: #374151; border: 1px solid #d1d5db; }
    .btn-ghost:hover { border-color: #9ca3af; background: #f9fafb; }
    .btn-sm { padding: 0.3rem 0.6rem; font-size: 0.75rem; }
    .btn-bakactie { background: #92400e; color: white; }
    .btn-bakactie:hover { background: #78350f; }

    /* ── Filters ── */
    .filters { display: flex; gap: 0.5rem; padding: 0.75rem 1.5rem; background: #faf8f5; border-bottom: 1px solid #e8dfd2; flex-wrap: wrap; align-items: center; }
    .filter-pill { padding: 0.3rem 0.75rem; border: 1px solid #d1d5db; border-radius: 20px; font-size: 0.78rem; cursor: pointer; font-weight: 500; color: #6b7280; transition: all 0.15s; }
    .filter-pill:hover { border-color: #92400e; color: #92400e; }
    .filter-pill.active { background: #92400e; color: white; border-color: #92400e; }

    /* ── Table ── */
    .content-area { padding: 1.25rem 1.5rem; }
    .table-wrap { background: white; border: 1px solid #e8dfd2; border-radius: 6px; overflow: hidden; }
    .ba-table { width: 100%; border-collapse: collapse; }
    .ba-table thead tr { background: #f5f0e8; border-bottom: 2px solid #e8e0d5; }
    .ba-table th { padding: 0.5rem 0.875rem; text-align: left; font-size: 0.72rem; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 0.04em; white-space: nowrap; }
    .ba-table td { padding: 0.625rem 0.875rem; border-bottom: 1px solid #f0ebe5; font-size: 0.85rem; color: #333; vertical-align: middle; }
    .ba-table tbody tr:last-child td { border-bottom: none; }
    .ba-table tbody tr:hover { background: #faf8f5; }
    .ba-table tbody tr.clickable { cursor: pointer; }
    .recipe-name { font-weight: 600; color: #2d4a2d; }
    .recipe-name-orphan { font-weight: 600; color: #9ca3af; font-style: italic; }
    .bakker-tag { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.8rem; color: #6b7280; }
    .notes-preview { color: #6b7280; font-size: 0.8rem; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    /* ── Status badges ── */
    .status-badge { display: inline-flex; align-items: center; padding: 0.2rem 0.5rem; border-radius: 3px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; }
    .status-gepland   { background: #eff6ff; color: #1d4ed8; }
    .status-bezig     { background: #fff7ed; color: #c2410c; }
    .status-voltooid  { background: #f0fdf4; color: #166534; }

    /* ── Detail panel ── */
    .detail-panel { position: fixed; top: 0; right: 0; width: 560px; max-width: 100vw; height: 100vh; background: white; box-shadow: -4px 0 24px rgba(0,0,0,0.12); z-index: 200; display: flex; flex-direction: column; overflow: hidden; }
    .detail-header { padding: 1rem 1.25rem; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; gap: 0.75rem; background: #1f2937; color: white; }
    .detail-header h3 { flex: 1; font-size: 0.95rem; font-weight: 700; }
    .detail-close { background: none; border: none; color: #9ca3af; font-size: 1.3rem; cursor: pointer; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border-radius: 4px; line-height: 1; }
    .detail-close:hover { color: white; background: rgba(255,255,255,0.1); }
    .detail-body { flex: 1; overflow-y: auto; padding: 1.25rem; }
    .detail-section { background: #f9fafb; border-radius: 6px; padding: 0.875rem 1rem; margin-bottom: 0.875rem; }
    .detail-section h4 { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; margin-bottom: 0.625rem; }
    .detail-row { display: flex; justify-content: space-between; align-items: flex-start; padding: 0.3rem 0; border-bottom: 1px solid #f3f4f6; gap: 0.5rem; }
    .detail-row:last-child { border-bottom: none; }
    .detail-label { font-size: 0.8rem; color: #6b7280; white-space: nowrap; }
    .detail-value { font-size: 0.85rem; font-weight: 600; color: #1f2937; text-align: right; }
    .method-day-block { margin-bottom: 0.75rem; }
    .method-day-title { font-size: 0.75rem; font-weight: 700; color: #374151; margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.03em; }
    .method-step-item { padding: 0.3rem 0 0.3rem 0.75rem; border-left: 2px solid #e5e7eb; margin-bottom: 0.2rem; }
    .method-step-title { font-size: 0.85rem; font-weight: 600; color: #1f2937; margin-bottom: 0.15rem; }
    .method-substep-row { display: flex; gap: 0.5rem; align-items: center; padding: 0.15rem 0; font-size: 0.78rem; color: #6b7280; }
    .substep-actie-tag { background: #f3f4f6; border-radius: 3px; padding: 0.1rem 0.3rem; font-weight: 600; font-size: 0.7rem; color: #374151; }
    .substep-meta { display: flex; gap: 0.4rem; }
    .substep-meta span { background: #eff6ff; color: #1d4ed8; border-radius: 3px; padding: 0.1rem 0.3rem; font-size: 0.7rem; font-weight: 600; }
    .substep-desc { flex: 1; }

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

    /* ── Planned rows ── */
    .planned-link { display: contents; color: inherit; text-decoration: none; }
    .ba-table tbody tr.planned-row:hover { background: #eff6ff; cursor: pointer; }
    .dough-tag { display: inline-flex; align-items: center; gap: 0.3rem; background: #f0fdf4; color: #166534; border-radius: 3px; padding: 0.15rem 0.45rem; font-size: 0.78rem; font-weight: 600; }
    .dagproductie-tag { display: inline-flex; align-items: center; gap: 0.3rem; background: #fff7ed; color: #92400e; border-radius: 3px; padding: 0.15rem 0.45rem; font-size: 0.78rem; font-weight: 600; }

    /* ── Empty ── */
    .empty-state { text-align: center; padding: 3rem 1.5rem; color: #9ca3af; }
    .empty-state i { font-size: 2.5rem; display: block; margin-bottom: 0.75rem; color: #d1d5db; }
    .empty-state p { font-size: 0.85rem; }

    /* ── Toast ── */
    .toast { position: fixed; bottom: 2rem; right: 2rem; padding: 0.6rem 1.25rem; background: #1f2937; color: white; border-radius: 4px; font-size: 0.85rem; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-weight: 500; }
    .overlay-bg { position: fixed; inset: 0; z-index: 199; }
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

            <!-- Filters -->
            <div class="filters">
                <span class="filter-pill" :class="{active: statusFilter===''}" @click="statusFilter=''">Alle</span>
                <span class="filter-pill" :class="{active: statusFilter==='gepland'}" @click="statusFilter='gepland'">Gepland</span>
                <span class="filter-pill" :class="{active: statusFilter==='bezig'}" @click="statusFilter='bezig'">Bezig</span>
                <span class="filter-pill" :class="{active: statusFilter==='voltooid'}" @click="statusFilter='voltooid'">Voltooid</span>
                <span v-if="recipeVersionId" style="display:inline-flex;align-items:center;gap:0.4rem;background:#fff7ed;color:#92400e;border:1px solid #fed7aa;border-radius:20px;padding:0.3rem 0.75rem;font-size:0.78rem;font-weight:600">
                    <i class="bi bi-funnel-fill"></i> Versie {{ recipeVersionId }}
                    <a href="logboek.php" style="color:#92400e;text-decoration:none;font-size:0.85rem;line-height:1" title="Filter wissen">&times;</a>
                </span>
                <span style="margin-left:auto;font-size:0.78rem;color:#9ca3af">{{ statusFilter === 'gepland' ? geplandeDagen.length : filteredList.length }} entries</span>
            </div>

            <!-- List -->
            <div class="content-area">
                <!-- Gepland: upcoming bakdagen from orders -->
                <div v-if="statusFilter === 'gepland'" class="table-wrap">
                    <div v-if="geplandeDagen.length === 0" class="empty-state">
                        <i class="bi bi-calendar-x"></i>
                        <p>Geen geplande bakdagen gevonden.</p>
                    </div>
                    <table v-else class="ba-table">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Deegsoort</th>
                                <th>Bestellingen</th>
                                <th>Stuks</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in geplandeDagen" :key="row.delivery_date + row.dough_type_name"
                                class="planned-row" @click="goToDagproductie(row.delivery_date)">
                                <td style="white-space:nowrap;color:#374151;font-variant-numeric:tabular-nums">{{ formatDate(row.delivery_date) }}</td>
                                <td><span class="dough-tag"><i class="bi bi-fire"></i>{{ row.dough_type_name }}</span></td>
                                <td style="color:#6b7280">{{ row.order_count }} bestelling{{ row.order_count != 1 ? 'en' : '' }}</td>
                                <td style="color:#6b7280">{{ row.total_qty }}x</td>
                                <td style="text-align:right;padding-right:0.5rem">
                                    <span style="font-size:0.75rem;color:#2563eb"><i class="bi bi-arrow-right"></i> Dagproductie</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- All / Bezig / Voltooid: actual bak_acties records -->
                <div v-else class="table-wrap">
                    <div v-if="filteredList.length === 0" class="empty-state">
                        <i class="bi bi-fire"></i>
                        <p>Geen logboek entries gevonden. Start een bakactie vanuit de dagproductie.</p>
                        <a href="dagproductie.php" class="btn btn-ghost" style="margin-top:1rem"><i class="bi bi-arrow-left"></i> Naar dagproductie</a>
                    </div>
                    <table v-else class="ba-table">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Deegsoort / Recept</th>
                                <th>Bakker</th>
                                <th>Status</th>
                                <th>Notities</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="ba in filteredList" :key="ba.id" class="clickable" @click="openDetail(ba)">
                                <td style="white-space:nowrap;color:#374151;font-variant-numeric:tabular-nums">{{ formatDatum(ba.datum) }}</td>
                                <td>
                                    <span v-if="ba.dough_type_name" class="dough-tag" style="margin-right:0.35rem"><i class="bi bi-fire"></i>{{ ba.dough_type_name }}</span>
                                    <span v-else-if="ba.locked_recipe_name && ba.locked_recipe_name.startsWith('Dagproductie')" class="dagproductie-tag"><i class="bi bi-fire"></i>{{ ba.locked_recipe_name }}</span>
                                    <span v-else-if="ba.recipe_id" class="recipe-name">{{ ba.locked_recipe_name }}</span>
                                    <span v-else class="recipe-name-orphan">{{ ba.locked_recipe_name }}</span>
                                </td>
                                <td><span class="bakker-tag" v-if="ba.bakker"><i class="bi bi-person"></i>{{ ba.bakker }}</span><span v-else style="color:#d1d5db">—</span></td>
                                <td>
                                    <span class="status-badge" :class="'status-'+ba.status">{{ ba.status }}</span>
                                </td>
                                <td><span class="notes-preview" v-if="ba.notes">{{ ba.notes }}</span><span v-else style="color:#d1d5db">—</span></td>
                                <td @click.stop style="text-align:right;padding-right:0.5rem">
                                    <button class="btn btn-ghost btn-sm" @click="openEdit(ba)" title="Bewerken"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-ghost btn-sm" style="color:#ef4444;margin-left:0.25rem" @click="deleteBakactie(ba.id)" title="Verwijderen"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Detail panel -->
            <div v-if="detailItem" class="overlay-bg" @click="detailItem = null"></div>
            <div v-if="detailItem" class="detail-panel">
                <div class="detail-header">
                    <div>
                        <h3>{{ detailItem.locked_recipe_name }}</h3>
                        <div style="font-size:0.75rem;color:#9ca3af;margin-top:0.15rem">{{ formatDatum(detailItem.datum) }} &middot; <span class="status-badge" :class="'status-'+detailItem.status">{{ detailItem.status }}</span></div>
                    </div>
                    <button class="detail-close" @click="detailItem = null">&times;</button>
                </div>
                <div class="detail-body" v-if="detailItem.locked_recipe_data">
                    <!-- Meta -->
                    <div class="detail-section">
                        <h4>Bakactie info</h4>
                        <div class="detail-row"><span class="detail-label">Bakker</span><span class="detail-value">{{ detailItem.bakker || '—' }}</span></div>
                        <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value"><span class="status-badge" :class="'status-'+detailItem.status">{{ detailItem.status }}</span></span></div>
                        <div class="detail-row" v-if="detailItem.notes"><span class="detail-label">Notities</span><span class="detail-value" style="max-width:220px;text-align:right">{{ detailItem.notes }}</span></div>
                    </div>
                    <!-- DAGPRODUCTIE VIEW -->
                    <template v-if="detailItem.locked_recipe_data.dagproductie_date">
                        <div v-for="(dt, dti) in detailItem.locked_recipe_data.doughTypes" :key="dti" class="detail-section">
                            <!-- Header row -->
                            <h4 style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap">
                                <i class="bi bi-fire" style="color:#92400e;font-size:0.85rem"></i>
                                {{ dt.name }}
                                <span style="font-size:0.72rem;background:#fff7ed;color:#92400e;border-radius:3px;padding:0.1rem 0.35rem;font-weight:700">v{{ dt.version }}</span>
                                <span style="font-size:0.72rem;color:#9ca3af;font-weight:400;text-transform:none;letter-spacing:0">{{ dt.total_qty }}&times; &middot; {{ (dt.total_weight_g/1000).toFixed(1) }}kg &middot; {{ dt.calc && dt.calc.hydration }}%</span>
                            </h4>
                            <!-- Ingredients summary -->
                            <template v-if="dt.calc">
                                <div v-for="grain in dt.calc.grains" :key="grain.name" class="detail-row">
                                    <span class="detail-label">{{ grain.name }}</span>
                                    <span class="detail-value">{{ grain.weight }}g <span style="color:#9ca3af;font-weight:400">({{ grain.pct }}%)</span></span>
                                </div>
                                <div class="detail-row" v-if="dt.calc.sourdough">
                                    <span class="detail-label">Zuurdesem</span>
                                    <span class="detail-value">{{ dt.calc.sourdough.weight }}g <span style="color:#9ca3af;font-weight:400">({{ dt.calc.sourdough.hydration }}%)</span></span>
                                </div>
                                <div class="detail-row" v-if="dt.calc.preFerment">
                                    <span class="detail-label">Voordeeg</span>
                                    <span class="detail-value">{{ dt.calc.preFerment.weight }}g <span style="color:#9ca3af;font-weight:400">({{ dt.calc.preFerment.hydration }}%)</span></span>
                                </div>
                                <div class="detail-row" v-for="lev in dt.calc.leveners" :key="lev.name">
                                    <span class="detail-label">{{ lev.name }}</span>
                                    <span class="detail-value">{{ lev.weight }}g <span style="color:#9ca3af;font-weight:400">({{ lev.pct }}%)</span></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Water</span>
                                    <span class="detail-value">{{ dt.calc.mainWater }}g</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Zout</span>
                                    <span class="detail-value">{{ dt.calc.saltWeight }}g <span style="color:#9ca3af;font-weight:400">({{ dt.calc.saltPct }}%)</span></span>
                                </div>
                                <div v-for="m in dt.calc.mixins" :key="m.name" class="detail-row">
                                    <span class="detail-label">{{ m.name }}</span>
                                    <span class="detail-value">{{ m.weight }}g</span>
                                </div>
                                <div class="detail-row" style="font-weight:700">
                                    <span class="detail-label">Totaal deeg</span>
                                    <span class="detail-value" style="color:#92400e">{{ dt.calc.totalDoughWeight }}g</span>
                                </div>
                            </template>
                            <!-- Dough type note -->
                            <div v-if="dt.note" style="margin-top:0.5rem;background:#fffbf5;border-left:3px solid #c8913a;padding:0.4rem 0.6rem;border-radius:0 4px 4px 0;font-size:0.82rem;color:#5c3d1e">{{ dt.note }}</div>
                            <!-- Method -->
                            <template v-if="dt.method_days && dt.method_days.length">
                                <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;margin-top:0.75rem;margin-bottom:0.25rem">Methode</div>
                                <div v-for="(day, di) in dt.method_days" :key="di" class="method-day-block">
                                    <div class="method-day-title">{{ day.label || ('Dag ' + (di+1)) }}</div>
                                    <div v-for="(step, si) in day.steps" :key="si" class="method-step-item">
                                        <div class="method-step-title">{{ step.title || ('Stap ' + (si+1)) }}</div>
                                        <div v-if="step.substeps && step.substeps.length">
                                            <div v-for="(sub, ssi) in step.substeps" :key="ssi" class="method-substep-row">
                                                <span v-if="sub.actie" class="substep-actie-tag">{{ sub.actie }}</span>
                                                <span class="substep-meta">
                                                    <span v-if="sub.tijd">{{ sub.tijd }}min</span>
                                                    <span v-if="sub.temp">{{ sub.temp }}°C</span>
                                                </span>
                                                <span class="substep-desc">{{ sub.beschrijving }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-if="dt.method_note" style="background:#f0fdf4;border-left:3px solid #16a34a;padding:0.4rem 0.6rem;border-radius:0 4px 4px 0;font-size:0.82rem;color:#166534;margin-top:0.25rem">{{ dt.method_note }}</div>
                            </template>
                        </div>
                    </template>
                    <!-- SINGLE-RECIPE VIEW -->
                    <template v-else>
                        <!-- Dough snapshot -->
                        <div class="detail-section" v-if="detailItem.locked_recipe_data">
                            <h4>Recept snapshot</h4>
                            <div class="detail-row"><span class="detail-label">Deeggewicht</span><span class="detail-value">{{ detailItem.locked_recipe_data.doughWeight }}g</span></div>
                            <div class="detail-row"><span class="detail-label">Hydratatie</span><span class="detail-value">{{ detailItem.locked_recipe_data.hydration }}%</span></div>
                            <div class="detail-row"><span class="detail-label">Zout</span><span class="detail-value">{{ detailItem.locked_recipe_data.saltPct }}%</span></div>
                            <div class="detail-row" v-if="detailItem.locked_recipe_data.useSourdough"><span class="detail-label">Zuurdesem</span><span class="detail-value">{{ detailItem.locked_recipe_data.sourdoughPct }}%</span></div>
                            <div class="detail-row" v-if="detailItem.locked_recipe_data.useYeast"><span class="detail-label">Gist</span><span class="detail-value">{{ detailItem.locked_recipe_data.yeastPct }}%</span></div>
                            <template v-if="detailItem.locked_recipe_data.mainDoughGrains">
                                <div class="detail-row" v-for="g in detailItem.locked_recipe_data.mainDoughGrains" :key="g.type" v-if="g.pct > 0">
                                    <span class="detail-label">{{ g.type }}</span>
                                    <span class="detail-value">{{ g.pct }}%</span>
                                </div>
                            </template>
                        </div>
                        <!-- Method snapshot -->
                        <div class="detail-section" v-if="detailItem.locked_recipe_data.methodDays && detailItem.locked_recipe_data.methodDays.length">
                            <h4>Methode</h4>
                            <div v-for="(day, di) in detailItem.locked_recipe_data.methodDays" :key="di" class="method-day-block">
                                <div class="method-day-title">Dag {{ di + 1 }}</div>
                                <div v-for="(step, si) in day.steps" :key="si" class="method-step-item">
                                    <div class="method-step-title">{{ step.title || ('Stap ' + (si+1)) }}</div>
                                    <div v-if="step.substeps && step.substeps.length">
                                        <div v-for="(sub, ssi) in step.substeps" :key="ssi" class="method-substep-row">
                                            <span v-if="sub.actie" class="substep-actie-tag">{{ sub.actie }}</span>
                                            <span class="substep-meta">
                                                <span v-if="sub.tijd">{{ sub.tijd }}min</span>
                                                <span v-if="sub.temp">{{ sub.temp }}°C</span>
                                            </span>
                                            <span class="substep-desc">{{ sub.beschrijving }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                    <!-- Actions -->
                    <div style="display:flex;gap:0.5rem;margin-top:0.5rem">
                        <button class="btn btn-ghost btn-sm" @click="openEdit(detailItem)"><i class="bi bi-pencil"></i> Bewerken</button>
                        <a :href="'bakcalculator.php'" class="btn btn-ghost btn-sm" v-if="detailItem.recipe_id"><i class="bi bi-arrow-up-right"></i> Naar recept</a>
                    </div>
                </div>
            </div>

            <!-- New / Edit modal -->
            <div v-if="showModal" class="modal-overlay" @mousedown.self="showModal = false">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3><i class="bi bi-fire" style="color:#92400e"></i> {{ editingId ? 'Bakactie bewerken' : 'Nieuwe bakactie' }}</h3>
                        <button class="modal-close" @click="showModal = false">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label class="form-label">Recept naam</label>
                            <input type="text" v-model="form.locked_recipe_name" class="form-input" placeholder="Receptnaam...">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Datum & tijdstip</label>
                            <input type="datetime-local" v-model="form.datum" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Bakker</label>
                            <input type="text" v-model="form.bakker" class="form-input" placeholder="Naam van de bakker">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select v-model="form.status" class="form-select">
                                <option value="gepland">Gepland</option>
                                <option value="bezig">Bezig</option>
                                <option value="voltooid">Voltooid</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:1rem">
                            <label class="form-label">Notities</label>
                            <textarea v-model="form.notes" class="form-input" placeholder="Optionele notities..."></textarea>
                        </div>
                        <!-- Per-dough-type notes (dagproductie bakacties) -->
                        <template v-if="form.locked_recipe_data && form.locked_recipe_data.dagproductie_date && form.locked_recipe_data.doughTypes && form.locked_recipe_data.doughTypes.length">
                            <div style="border-top:1px solid #e5e7eb;padding-top:0.75rem;margin-bottom:0.75rem">
                                <div class="form-label" style="margin-bottom:0.5rem">Notities per deegsoort</div>
                                <div v-for="(dt, dti) in form.locked_recipe_data.doughTypes" :key="dti" style="margin-bottom:0.625rem;padding:0.625rem;background:#faf8f5;border-radius:4px">
                                    <div style="font-size:0.8rem;font-weight:600;color:#374151;display:flex;align-items:center;gap:0.35rem;margin-bottom:0.35rem">
                                        <i class="bi bi-fire" style="color:#92400e"></i> {{ dt.name }}
                                        <span style="font-size:0.72rem;color:#9ca3af;font-weight:400">{{ dt.total_qty }}&times;</span>
                                    </div>
                                    <textarea v-model="dt.note" class="form-input" :placeholder="'Notitie voor ' + dt.name + '...'" style="min-height:2.5rem;margin-bottom:0.25rem"></textarea>
                                    <template v-if="dt.method_days && dt.method_days.length">
                                        <div class="form-label" style="font-size:0.68rem;margin-top:0.25rem">Methode notitie</div>
                                        <textarea v-model="dt.method_note" class="form-input" placeholder="Notitie over de methode..." style="min-height:2rem"></textarea>
                                    </template>
                                </div>
                            </div>
                        </template>
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
                geplandeDagen: <?= $geplandeJson ?>,
                statusFilter: '',
                recipeVersionId: <?= $recipeVersionIdFilter ?>,
                detailItem: null,
                showModal: false,
                editingId: null,
                saving: false,
                toastMsg: '',
                form: { locked_recipe_name: '', datum: '', bakker: '', notes: '', status: 'gepland', locked_recipe_data: {} },
            };
        },
        computed: {
            filteredList() {
                if (!this.statusFilter) return this.list;
                return this.list.filter(b => b.status === this.statusFilter);
            },
        },
        methods: {
            async load() {
                const url = this.recipeVersionId
                    ? '../../api/bak-acties.php?recipe_version_id=' + this.recipeVersionId
                    : '../../api/bak-acties.php';
                const res = await fetch(url);
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
            goToDagproductie(date) {
                window.location.href = 'dagproductie.php?date=' + date;
            },
            async openDetail(ba) {
                this.detailItem = { ...ba };
                const res = await fetch('../../api/bak-acties.php?id=' + ba.id);
                const data = await res.json();
                if (data.success) this.detailItem = data.bak_actie;
            },
            openNewModal() {
                const now = new Date();
                const pad = n => String(n).padStart(2, '0');
                this.editingId = null;
                this.form = {
                    locked_recipe_name: '',
                    datum: `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`,
                    bakker: '', notes: '', status: 'gepland', locked_recipe_data: {},
                };
                this.showModal = true;
            },
            async openEdit(ba) {
                this.editingId = ba.id;
                let fullBa = ba;
                if (!ba.locked_recipe_data) {
                    const res = await fetch('../../api/bak-acties.php?id=' + ba.id);
                    const d2 = await res.json();
                    if (d2.success) fullBa = d2.bak_actie;
                }
                const pad = n => String(n).padStart(2, '0');
                const d = new Date(fullBa.datum);
                this.form = {
                    locked_recipe_name: fullBa.locked_recipe_name,
                    datum: `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`,
                    bakker: fullBa.bakker || '',
                    notes: fullBa.notes || '',
                    status: fullBa.status,
                    locked_recipe_data: fullBa.locked_recipe_data || {},
                };
                this.showModal = true;
                this.detailItem = null;
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
                        locked_recipe_data: this.form.locked_recipe_data,
                    };
                    if (this.editingId) {
                        body.id = this.editingId;
                        await fetch('../../api/bak-acties.php', { method: 'PUT', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
                    } else {
                        await fetch('../../api/bak-acties.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
                    }
                    this.showModal = false;
                    await this.load();
                    this.showToast(this.editingId ? 'Bakactie bijgewerkt!' : 'Bakactie aangemaakt!');
                } catch (e) { console.error(e); }
                this.saving = false;
            },
            async deleteBakactie(id) {
                if (!await showConfirm('Bakactie verwijderen?')) return;
                await fetch('../../api/bak-acties.php?id=' + id, { method: 'DELETE' });
                if (this.detailItem?.id === id) this.detailItem = null;
                await this.load();
                this.showToast('Bakactie verwijderd');
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
