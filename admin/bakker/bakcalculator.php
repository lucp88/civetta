<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$currentPage = 'bakcalculator';
$adminBasePath = '../';

try {
    $doughTypes = $pdo->query("SELECT id, name, recipe_data FROM dough_types ORDER BY name ASC")->fetchAll();
    foreach ($doughTypes as &$dt) {
        $dt['recipe_data'] = $dt['recipe_data'] ? json_decode($dt['recipe_data'], true) : null;
    }
    unset($dt);
} catch (PDOException $e) {
    // recipe_data column missing — migration 024 not yet run; fall back to name-only
    $doughTypes = $pdo->query("SELECT id, name FROM dough_types ORDER BY name ASC")->fetchAll();
    foreach ($doughTypes as &$dt) { $dt['recipe_data'] = null; }
    unset($dt);
}

$currentMonth = date('Y-m');
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(boi.quantity), 0) as total_breads
    FROM business_orders bo
    JOIN business_order_items boi ON bo.id = boi.order_id
    WHERE DATE_FORMAT(bo.delivery_date, '%Y-%m') = ?
    AND bo.is_cancelled = 0
");
$stmt->execute([$currentMonth]);
$monthlyBreadCount = (int)$stmt->fetch()['total_breads'];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bak Calculator | Civetta Admin</title>
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#5c3d1e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" sizes="192x192" href="/img/icon-192.png">
    <link rel="apple-touch-icon" sizes="512x512" href="/img/icon-512.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--cream); min-height: 100vh; }
        .admin-content {
            padding: 1.5rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        @media (max-width: 768px) {
            .admin-content { padding: 1rem; }
        }
        .top-bar { display: flex; gap: 1rem; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .recipe-name-input { flex: 1; min-width: 200px; padding: 0.6rem 1rem; border: 2px solid #e0d5c7; border-radius: 8px; font-size: 1.1rem; font-weight: 600; color: #5c3d1e; background: white; }
        .recipe-name-input:focus { outline: none; border-color: #c8913a; }
        .recipe-name-input::placeholder { color: #bbb; font-weight: 400; }
        .dough-type-select { display: flex; gap: 0.25rem; align-items: center; }
        .form-select-sm { padding: 0.5rem 0.75rem; border: 2px solid #e0d5c7; border-radius: 8px; font-size: 0.9rem; background: white; color: #5c3d1e; min-width: 140px; }
        .form-select-sm:focus { outline: none; border-color: #c8913a; }
        .btn-icon { width: 36px; height: 36px; border: 2px solid #e0d5c7; border-radius: 8px; background: white; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #888; }
        .btn-icon:hover { border-color: #c8913a; color: #c8913a; }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000; }
        .modal-content { background: white; border-radius: 12px; width: 90%; max-width: 400px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; border-bottom: 1px solid #e0d5c7; }
        .modal-header h3 { font-size: 1.1rem; color: #5c3d1e; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
        .modal-close { background: none; border: none; font-size: 1.5rem; color: #888; cursor: pointer; line-height: 1; }
        .modal-close:hover { color: #c62828; }
        .modal-body { padding: 1.25rem; }
        .dough-type-list { max-height: 250px; overflow-y: auto; margin-bottom: 1rem; }
        .dough-type-item { display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 0.75rem; border-radius: 6px; background: #faf6f1; margin-bottom: 0.5rem; }
        .dough-type-item span { font-weight: 500; color: #5c3d1e; }
        .btn-icon-danger { width: 28px; height: 28px; border: none; border-radius: 6px; background: transparent; cursor: pointer; color: #888; display: flex; align-items: center; justify-content: center; }
        .btn-icon-danger:hover { background: #ffebee; color: #c62828; }
        .empty-msg { text-align: center; color: #888; padding: 1rem; font-size: 0.9rem; }
        .add-dough-type { display: flex; gap: 0.5rem; }
        .add-dough-type .form-input { flex: 1; }
        .btn { padding: 0.6rem 1.2rem; border: none; border-radius: 8px; font-size: 0.9rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; transition: all 0.2s; }
        .btn-primary { background: #8b5a2b; color: white; }
        .btn-primary:hover { background: #5c3d1e; }
        .btn-success { background: #2e7d32; color: white; }
        .btn-success:hover { background: #1b5e20; }
        .btn-danger { background: #c62828; color: white; }
        .btn-danger:hover { background: #b71c1c; }
        .btn-ghost { background: transparent; color: #8b5a2b; border: 2px solid #e0d5c7; }
        .btn-ghost:hover { border-color: #8b5a2b; background: #faf6f1; }
        .btn-sm { padding: 0.35rem 0.7rem; font-size: 0.8rem; }
        .tabs { display: flex; gap: 0.25rem; border-bottom: 2px solid #e0d5c7; margin-bottom: 1.5rem; overflow-x: auto; scrollbar-width: none; }
        .tabs::-webkit-scrollbar { display: none; }
        .tab { padding: 0.7rem 1.2rem; cursor: pointer; font-weight: 500; color: #888; border-bottom: 3px solid transparent; margin-bottom: -2px; white-space: nowrap; transition: all 0.2s; user-select: none; }
        .tab:hover { color: #5c3d1e; }
        .tab.active { color: #8b5a2b; border-bottom-color: #c8913a; font-weight: 700; }
        .layout { display: grid; grid-template-columns: 1fr 340px; gap: 1.5rem; align-items: start; }
        @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
        .panel { background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); padding: 1.5rem; margin-bottom: 1.5rem; }
        .panel-title { font-size: 1.1rem; font-weight: 700; color: #5c3d1e; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        .panel-title i { color: #c8913a; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 500px) { .form-grid { grid-template-columns: 1fr; } }
        .form-group { display: flex; flex-direction: column; gap: 0.3rem; }
        .form-group.full { grid-column: 1 / -1; }
        .form-label { font-size: 0.8rem; font-weight: 600; color: #888; text-transform: uppercase; letter-spacing: 0.03em; }
        .form-input, .form-select { padding: 0.55rem 0.75rem; border: 2px solid #e8e0d5; border-radius: 8px; font-size: 0.95rem; color: #333; background: white; }
        .form-input:focus, .form-select:focus { outline: none; border-color: #c8913a; }
        .form-input[type="number"] { -moz-appearance: textfield; }
        .form-input[type="number"]::-webkit-inner-spin-button { opacity: 1; }
        .input-with-unit { display: flex; align-items: stretch; }
        .input-with-unit .form-input { border-top-right-radius: 0; border-bottom-right-radius: 0; flex: 1; min-width: 0; }
        .input-unit { padding: 0.55rem 0.7rem; background: #f5f0e8; border: 2px solid #e8e0d5; border-left: none; border-radius: 0 8px 8px 0; font-size: 0.85rem; color: #888; font-weight: 600; display: flex; align-items: center; }
        .calc-value { font-size: 1.3rem; font-weight: 700; color: #c8913a; }
        .calc-unit { font-size: 0.85rem; color: #999; font-weight: 400; margin-left: 0.2rem; }
        .divider { border: none; border-top: 1px solid #eee; margin: 1.25rem 0; }
        .grain-row, .mixin-row, .topping-row { display: flex; gap: 0.5rem; align-items: end; margin-bottom: 0.75rem; flex-wrap: wrap; }
        .grain-row .form-group, .mixin-row .form-group, .topping-row .form-group { flex: 1; min-width: 100px; }
        .grain-row .form-group:first-child, .mixin-row .form-group:first-child, .topping-row .form-group:first-child { flex: 2; min-width: 150px; }
        .btn-remove { width: 36px; height: 36px; padding: 0; display: flex; align-items: center; justify-content: center; border-radius: 8px; background: #fee; color: #c62828; border: 2px solid #fcc; cursor: pointer; flex-shrink: 0; margin-bottom: 0; }
        .btn-remove:hover { background: #c62828; color: white; border-color: #c62828; }
        .btn-add { width: 100%; padding: 0.5rem; border: 2px dashed #d5cbbf; border-radius: 8px; background: transparent; color: #999; cursor: pointer; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; gap: 0.3rem; }
        .btn-add:hover { border-color: #c8913a; color: #c8913a; background: #faf6f1; }
        .weight-tag { display: inline-block; padding: 0.15rem 0.5rem; background: #f5f0e8; border-radius: 4px; font-size: 0.8rem; font-weight: 600; color: #8b5a2b; }
        .toggle-row { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem; }
        .toggle { position: relative; width: 48px; height: 26px; background: #ddd; border-radius: 13px; cursor: pointer; transition: background 0.2s; flex-shrink: 0; }
        .toggle.on { background: #c8913a; }
        .toggle::after { content: ''; position: absolute; width: 22px; height: 22px; background: white; border-radius: 50%; top: 2px; left: 2px; transition: transform 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
        .toggle.on::after { transform: translateX(22px); }
        .toggle-label { font-weight: 600; color: #5c3d1e; font-size: 0.95rem; }
        .calc-sidebar { position: sticky; top: 1.5rem; }
        .summary-card { background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); overflow: hidden; }
        .summary-header { background: linear-gradient(135deg, #c8913a, #a0722e); color: white; padding: 1rem 1.25rem; }
        .summary-header h3 { font-size: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        .summary-body { padding: 1rem 1.25rem; }
        .summary-row { display: flex; justify-content: space-between; align-items: center; padding: 0.45rem 0; }
        .summary-row.total { border-top: 2px solid #e8e0d5; margin-top: 0.5rem; padding-top: 0.75rem; }
        .summary-label { font-size: 0.85rem; color: #888; }
        .summary-value { font-weight: 700; color: #5c3d1e; }
        .summary-value.accent { color: #c8913a; font-size: 1.1rem; }
        .summary-section-title { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; color: #bbb; font-weight: 700; margin-top: 0.75rem; margin-bottom: 0.25rem; }
        .pct-bar { height: 6px; background: #eee; border-radius: 3px; margin-top: 0.75rem; overflow: hidden; display: flex; }
        .pct-bar-fill { height: 100%; transition: width 0.3s; }
        .pct-bar-flour { background: #c8913a; }
        .pct-bar-water { background: #4a90d9; }
        .pct-bar-other { background: #8bc34a; }
        .recipe-list { list-style: none; }
        .recipe-group { margin-bottom: 1rem; }
        .recipe-group:last-child { margin-bottom: 0; }
        .recipe-group-header { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0; border-bottom: 2px solid #e8e0d5; margin-bottom: 0.25rem; cursor: pointer; user-select: none; }
        .recipe-group-header:hover { background: #faf8f4; }
        .recipe-group-header i { color: #8b5a2b; font-size: 0.9rem; transition: transform 0.2s; }
        .recipe-group-header.collapsed i { transform: rotate(-90deg); }
        .recipe-group-name { font-weight: 600; color: #5c3d1e; font-size: 0.9rem; }
        .recipe-group-count { font-size: 0.75rem; color: #999; background: #f0f0f0; padding: 0.1rem 0.4rem; border-radius: 10px; }
        .recipe-group-items { list-style: none; }
        .recipe-group-items.collapsed { display: none; }
        .recipe-item { display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 0 0.6rem 1.5rem; border-bottom: 1px solid #f5f5f5; }
        .recipe-item:last-child { border-bottom: none; }
        .recipe-item::before { content: ''; position: absolute; left: 0.5rem; width: 0.75rem; height: 1px; background: #ddd; }
        .recipe-info { flex: 1; position: relative; }
        .recipe-info h4 { color: #5c3d1e; font-size: 0.9rem; margin-bottom: 0.15rem; display: flex; align-items: center; gap: 0.5rem; }
        .recipe-info h4 .recipe-type-badge { font-size: 0.65rem; padding: 0.1rem 0.35rem; border-radius: 3px; font-weight: 500; background: #e8f5e9; color: #2e7d32; text-transform: uppercase; }
        .recipe-info small { color: #999; font-size: 0.75rem; }
        .uncategorized-header { color: #999; font-style: italic; }
        .recipe-actions { display: flex; gap: 0.5rem; }
        .overview-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        @media (max-width: 700px) { .overview-grid { grid-template-columns: 1fr; } }
        .overview-section { background: #faf8f4; border-radius: 8px; padding: 1rem; }
        .overview-section h4 { font-size: 0.85rem; color: #8b5a2b; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.4rem; }
        .overview-item { display: flex; justify-content: space-between; padding: 0.3rem 0; font-size: 0.9rem; }
        .overview-item .name { color: #666; }
        .overview-item .value { font-weight: 600; color: #333; }
        .overview-item.sub { padding-left: 1rem; font-size: 0.85rem; }
        .overview-item.sub .name { color: #aaa; }
        .overview-total { display: flex; justify-content: space-between; padding: 0.5rem 0; border-top: 2px solid #e0d5c7; margin-top: 0.5rem; font-weight: 700; color: #5c3d1e; }
        .bp-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .bp-table th { text-align: left; padding: 0.5rem; color: #888; font-size: 0.75rem; text-transform: uppercase; border-bottom: 2px solid #e8e0d5; }
        .bp-table td { padding: 0.5rem; border-bottom: 1px solid #f0f0f0; }
        .bp-table td:last-child { text-align: right; font-weight: 600; color: #c8913a; }
        .bp-table tr:last-child td { border-bottom: none; }
        .radio-group { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .radio-pill { padding: 0.35rem 0.75rem; border: 2px solid #e0d5c7; border-radius: 20px; font-size: 0.8rem; cursor: pointer; transition: all 0.2s; user-select: none; }
        .radio-pill.active { background: #c8913a; color: white; border-color: #c8913a; }
        .radio-pill:hover:not(.active) { border-color: #c8913a; }
        .empty-state { text-align: center; padding: 2rem; color: #ccc; }
        .empty-state i { font-size: 2.5rem; display: block; margin-bottom: 0.5rem; }
        .method-textarea { width: 100%; min-height: 300px; padding: 1rem; border: 2px solid #e8e0d5; border-radius: 8px; font-family: inherit; font-size: 0.95rem; resize: vertical; color: #333; }
        .method-textarea:focus { outline: none; border-color: #c8913a; }
        .category-label { font-size: 0.7rem; padding: 0.15rem 0.4rem; border-radius: 4px; font-weight: 600; text-transform: uppercase; }
        .cat-integrated { background: #e8f5e9; color: #2e7d32; }
        .cat-non-integrated { background: #fff3e0; color: #e65100; }
        .cat-liquid { background: #e3f2fd; color: #1565c0; }
        .fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
        .fade-enter-from, .fade-leave-to { opacity: 0; }
        .toast { position: fixed; bottom: 2rem; right: 2rem; padding: 0.75rem 1.5rem; background: #333; color: white; border-radius: 8px; font-size: 0.9rem; z-index: 1000; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        .toast.success { background: #2e7d32; }
        .grain-warning { font-size: 0.8rem; color: #c62828; font-weight: 600; margin-top: 0.5rem; display: flex; align-items: center; gap: 0.3rem; }
        .inherited-banner { background: #e8f0fe; border: 1px solid #90b4f5; border-radius: 8px; padding: 0.65rem 1rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; color: #1a56c4; font-size: 0.85rem; }
        .inherited-banner i { flex-shrink: 0; }
        .inherited-field { background: #f5f5f5 !important; color: #999 !important; border-color: #e0e0e0 !important; cursor: not-allowed !important; }
        .inherited-locked { opacity: 0.45; pointer-events: none; }
        .modal-wide { max-width: 680px !important; }
        .modal-body-scroll { max-height: 75vh; overflow-y: auto; padding-right: 0.25rem; }
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
                    <span class="topbar-title">Bak Calculator</span>
                </div>
                <div class="topbar-right">
                    <a href="bakker-dashboard.php" class="topbar-link">
                        <i class="bi bi-calendar3"></i> <span>Planning</span>
                    </a>
                </div>
            </header>

            <div class="admin-content">
                <div id="app">
        <div class="top-bar" v-show="calculatorActive">
            <input type="text" v-model="recipeName" class="recipe-name-input" placeholder="Receptnaam...">
            <div class="dough-type-select">
                <select :value="doughTypeId" @change="onDoughTypeChange($event.target.value ? parseInt($event.target.value) : null)" class="form-select-sm">
                    <option :value="null">Geen deegsoort</option>
                    <option v-for="dt in doughTypes" :key="dt.id" :value="dt.id">{{ dt.name }}</option>
                </select>
                <button type="button" class="btn-icon" @click="showDoughTypeModal = true; doughTypeModalView = 'list'" title="Deegsoorten beheren"><i class="bi bi-gear"></i></button>
            </div>
            <button class="btn btn-success" @click="saveRecipe" :disabled="saving"><i class="bi bi-save"></i> {{ currentRecipeId ? 'Opslaan' : 'Bewaar' }}</button>
            <button class="btn btn-ghost" @click="duplicateRecipe" v-if="currentRecipeId"><i class="bi bi-copy"></i> Dupliceer</button>
            <button class="btn btn-ghost" @click="newRecipe"><i class="bi bi-plus-lg"></i> Nieuw</button>
        </div>

        <div class="tabs">
            <div class="tab" :class="{active: activeTab==='recept'}" @click="activeTab='recept'"><i class="bi bi-sliders"></i> Recept</div>
            <div class="tab" :class="{active: activeTab==='meel'}" @click="activeTab='meel'"><i class="bi bi-moisture"></i> Meel & Voordeeg</div>
            <div class="tab" :class="{active: activeTab==='toevoegingen'}" @click="activeTab='toevoegingen'"><i class="bi bi-plus-circle"></i> Toevoegingen</div>
            <div class="tab" :class="{active: activeTab==='overzicht'}" @click="activeTab='overzicht'"><i class="bi bi-list-check"></i> Overzicht</div>
            <div class="tab" :class="{active: activeTab==='methode'}" @click="activeTab='methode'"><i class="bi bi-journal-text"></i> Methode</div>
            <div class="tab" :class="{active: activeTab==='recepten'}" @click="activeTab='recepten'; loadSavedRecipes()"><i class="bi bi-bookmark"></i> Recepten</div>
        </div>

        <div class="layout">
            <div class="main-content">

                <div v-show="calculatorActive && activeTab==='recept'">
                    <div v-if="isInherited" class="inherited-banner">
                        <i class="bi bi-link-45deg"></i>
                        <span>Deeg samenstelling overgenomen van deegsoort <strong>{{ doughTypes.find(d => d.id == doughTypeId)?.name }}</strong>. Bewerk de deegsoort om te wijzigen.</span>
                    </div>
                    <div class="panel">
                        <div class="panel-title"><i class="bi bi-gear"></i> Basisrecept</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Deeggewicht per stuk</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="doughWeight" class="form-input" min="1" step="10">
                                    <span class="input-unit">g</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Hydratatie</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="hydration" class="form-input" min="30" max="120" step="1" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Zout</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="saltPct" class="form-input" min="0" max="10" step="0.1" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                        </div>
                        <hr class="divider">
                        <div class="panel-title"><i class="bi bi-droplet"></i> Rijsmiddelen</div>
                        <div class="toggle-row">
                            <div class="toggle" :class="{on: useSourdough, 'inherited-locked': isInherited}" @click="!isInherited && (useSourdough = !useSourdough)"></div>
                            <span class="toggle-label">Zuurdesem</span>
                        </div>
                        <div class="form-grid" v-if="useSourdough" style="margin-bottom:1rem">
                            <div class="form-group">
                                <label class="form-label">Percentage (baker's %)</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="sourdoughPct" class="form-input" min="0" max="100" step="0.5" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Hydratatie zuurdesem</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="sourdoughHydration" class="form-input" min="50" max="200" step="1" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                        </div>
                        <div class="toggle-row" style="margin-top:0.5rem">
                            <div class="toggle" :class="{on: useYeast, 'inherited-locked': isInherited}" @click="!isInherited && (useYeast = !useYeast)"></div>
                            <span class="toggle-label">Gist</span>
                        </div>
                        <div class="form-grid" v-if="useYeast">
                            <div class="form-group">
                                <label class="form-label">Type</label>
                                <select v-model="yeastType" class="form-select" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <option v-for="y in yeastTypes" :value="y.id">{{ y.name }}</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Percentage</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="yeastPct" class="form-input" min="0" max="10" step="0.1" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-show="calculatorActive && activeTab==='meel'">
                    <div v-if="isInherited" class="inherited-banner">
                        <i class="bi bi-link-45deg"></i>
                        <span>Meelsamenstelling is vastgelegd door de deegsoort. Bewerk de deegsoort om de melen te wijzigen.</span>
                    </div>

                    <div class="panel" v-if="useSourdough">
                        <div class="panel-title"><i class="bi bi-fire"></i> Zuurdesem meelsoorten</div>
                        <div class="grain-row" v-for="(grain, i) in sourdoughGrains" :key="'sd'+i">
                            <div class="form-group">
                                <select v-model="grain.type" class="form-select" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <option v-for="g in grainTypes" :value="g.id">{{ g.name }}</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="grain.pct" class="form-input" min="0" max="100" step="1" placeholder="Aandeel" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                            <button class="btn-remove" @click="sourdoughGrains.splice(i,1)" v-if="!isInherited && sourdoughGrains.length > 1"><i class="bi bi-x"></i></button>
                            <span class="weight-tag">{{ formatW(sourdoughGrainDetail(i).total) }}g</span>
                        </div>
                        <button class="btn-add" @click="sourdoughGrains.push({type:'wheat',pct:0})" v-if="!isInherited && sourdoughGrains.length < 5">
                            <i class="bi bi-plus"></i> Meelsoort toevoegen
                        </button>
                        <div class="grain-warning" v-if="sourdoughGrainsPctTotal !== 100">
                            <i class="bi bi-exclamation-triangle"></i> Totaal is {{ sourdoughGrainsPctTotal }}% — moet 100% zijn
                        </div>
                        <div style="margin-top:0.75rem; display:flex; gap:1.5rem; flex-wrap:wrap">
                            <span class="form-label">Zuurdesem meel: <strong style="color:#5c3d1e">{{ formatW(sourdoughFlour) }}g</strong></span>
                            <span class="form-label">Zuurdesem water: <strong style="color:#4a90d9">{{ formatW(sourdoughWater) }}g</strong></span>
                            <span class="form-label">Zuurdesem totaal: <strong style="color:#c8913a">{{ formatW(sourdoughWeight) }}g</strong></span>
                        </div>
                    </div>

                    <div class="panel" v-if="usePreFerment">
                        <div class="panel-title"><i class="bi bi-layers"></i> Voordeeg (Pre-ferment)</div>
                        <div class="form-grid" style="margin-bottom:1rem">
                            <div class="form-group">
                                <label class="form-label">% van totaal meel (gewicht)</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="preFermentPct" class="form-input" min="1" max="100" step="1" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Voordeeg hydratatie</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="preFermentHydration" class="form-input" min="50" max="200" step="1" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                        </div>
                        <label class="form-label" style="margin-bottom:0.5rem;display:block">Meelsoorten in voordeeg</label>
                        <div class="grain-row" v-for="(grain, i) in preFermentGrains" :key="'pf'+i">
                            <div class="form-group">
                                <select v-model="grain.type" class="form-select" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <option v-for="g in grainTypes" :value="g.id">{{ g.name }}</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="grain.pct" class="form-input" min="0" max="100" step="1" placeholder="Aandeel" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                            <button class="btn-remove" @click="preFermentGrains.splice(i,1)" v-if="!isInherited && preFermentGrains.length > 1"><i class="bi bi-x"></i></button>
                            <span class="weight-tag">{{ formatW(preFermentGrainDetail(i).total) }}g</span>
                        </div>
                        <button class="btn-add" @click="preFermentGrains.push({type:'wheat',pct:0})" v-if="!isInherited && preFermentGrains.length < 5">
                            <i class="bi bi-plus"></i> Meelsoort toevoegen
                        </button>
                        <div class="grain-warning" v-if="preFermentGrainsPctTotal !== 100">
                            <i class="bi bi-exclamation-triangle"></i> Totaal is {{ preFermentGrainsPctTotal }}% — moet 100% zijn
                        </div>
                        <div style="margin-top:0.75rem; display:flex; gap:1.5rem; flex-wrap:wrap">
                            <span class="form-label">Voordeeg meel: <strong style="color:#5c3d1e">{{ formatW(preFermentFlour) }}g</strong></span>
                            <span class="form-label">Voordeeg water: <strong style="color:#4a90d9">{{ formatW(preFermentWater) }}g</strong></span>
                            <span class="form-label">Voordeeg totaal: <strong style="color:#c8913a">{{ formatW(preFermentWeight) }}g</strong></span>
                        </div>
                    </div>

                    <div class="panel">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
                            <div class="panel-title" style="margin-bottom:0"><i class="bi bi-moisture"></i> Hoofddeeg</div>
                            <div class="toggle-row" style="margin-bottom:0">
                                <span class="form-label" style="margin:0">Voordeeg</span>
                                <div class="toggle" :class="{on: usePreFerment, 'inherited-locked': isInherited}" @click="!isInherited && (usePreFerment = !usePreFerment)"></div>
                            </div>
                        </div>
                        <label class="form-label" style="margin-bottom:0.5rem;display:block">Meelsoorten in hoofddeeg</label>
                        <div class="grain-row" v-for="(grain, i) in mainDoughGrains" :key="'md'+i">
                            <div class="form-group">
                                <select v-model="grain.type" class="form-select" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <option v-for="g in grainTypes" :value="g.id">{{ g.name }}</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="grain.pct" class="form-input" min="0" max="100" step="1" placeholder="Aandeel" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                            <button class="btn-remove" @click="mainDoughGrains.splice(i,1)" v-if="!isInherited && mainDoughGrains.length > 1"><i class="bi bi-x"></i></button>
                            <span class="weight-tag">{{ formatW(mainDoughGrainDetail(i).total) }}g</span>
                        </div>
                        <button class="btn-add" @click="mainDoughGrains.push({type:'wheat',pct:0})" v-if="!isInherited && mainDoughGrains.length < 5">
                            <i class="bi bi-plus"></i> Meelsoort toevoegen
                        </button>
                        <div class="grain-warning" v-if="mainGrainsPctTotal !== 100">
                            <i class="bi bi-exclamation-triangle"></i> Totaal is {{ mainGrainsPctTotal }}% — moet 100% zijn
                        </div>
                        <div style="margin-top:0.75rem; display:flex; gap:1.5rem; flex-wrap:wrap">
                            <span class="form-label">Hoofddeeg meel: <strong style="color:#5c3d1e">{{ formatW(mainDoughFlour) }}g</strong></span>
                            <span class="form-label">Hoofddeeg water: <strong style="color:#4a90d9">{{ formatW(mainDoughWater) }}g</strong></span>
                            <span class="form-label">Effectieve hydratatie: <strong style="color:#c8913a">{{ formatP(effectiveMainDoughHydration) }}%</strong></span>
                        </div>
                    </div>
                </div>

                <div v-show="calculatorActive && activeTab==='toevoegingen'">
                    <div class="panel">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
                            <div class="panel-title" style="margin-bottom:0"><i class="bi bi-plus-circle"></i> Mix-ins</div>
                            <div class="radio-group">
                                <span class="radio-pill" :class="{active: mixinMode==='flour'}" @click="mixinMode='flour'">% van meel</span>
                                <span class="radio-pill" :class="{active: mixinMode==='dough'}" @click="mixinMode='dough'">% van deeg</span>
                            </div>
                        </div>
                        <div v-if="mixins.length === 0" class="empty-state" style="padding:1rem">
                            <p style="color:#bbb">Nog geen mix-ins toegevoegd</p>
                        </div>
                        <div class="mixin-row" v-for="(m, i) in mixins" :key="'mx'+i">
                            <div class="form-group">
                                <select v-model="m.ingredient" class="form-select" @change="autoCategory(m)">
                                    <option value="">Kies ingrediënt...</option>
                                    <option v-for="ing in mixinIngredients" :key="ing.id" :value="ing.name">{{ ing.name }}</option>
                                </select>
                            </div>
                            <div class="form-group" style="flex:0.6">
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="m.pct" class="form-input" min="0" max="100" step="0.5">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                            <div class="form-group" style="flex:0.8">
                                <div class="radio-group">
                                    <span class="radio-pill" :class="{active: m.category==='non-integrated'}" @click="m.category='non-integrated'" style="font-size:0.7rem;padding:0.2rem 0.5rem">Vast</span>
                                    <span class="radio-pill" :class="{active: m.category==='integrated'}" @click="m.category='integrated'" style="font-size:0.7rem;padding:0.2rem 0.5rem">Integratie</span>
                                    <span class="radio-pill" :class="{active: m.category==='liquid'}" @click="m.category='liquid'" style="font-size:0.7rem;padding:0.2rem 0.5rem">Vloeistof</span>
                                </div>
                            </div>
                            <button class="btn-remove" @click="mixins.splice(i,1)"><i class="bi bi-x"></i></button>
                            <span class="weight-tag">{{ formatW(mixinWeight(i)) }}g</span>
                        </div>
                        <button class="btn-add" @click="mixins.push({ingredient:'',pct:0,category:'non-integrated'})" v-if="mixins.length < 16">
                            <i class="bi bi-plus"></i> Mix-in toevoegen
                        </button>
                    </div>

                    <div class="panel">
                        <div class="panel-title"><i class="bi bi-stars"></i> Toppings</div>
                        <div v-if="toppings.length === 0" class="empty-state" style="padding:1rem">
                            <p style="color:#bbb">Nog geen toppings toegevoegd</p>
                        </div>
                        <div class="topping-row" v-for="(t, i) in toppings" :key="'tp'+i">
                            <div class="form-group">
                                <select v-model="t.ingredient" class="form-select">
                                    <option value="">Kies ingrediënt...</option>
                                    <option v-for="ing in mixinIngredients" :key="ing.id" :value="ing.name">{{ ing.name }}</option>
                                </select>
                            </div>
                            <div class="form-group" style="flex:0.6">
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="t.pct" class="form-input" min="0" max="100" step="0.5">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                            <button class="btn-remove" @click="toppings.splice(i,1)"><i class="bi bi-x"></i></button>
                            <span class="weight-tag">{{ formatW(toppingWeight(i)) }}g</span>
                        </div>
                        <button class="btn-add" @click="toppings.push({ingredient:'',pct:0})" v-if="toppings.length < 8">
                            <i class="bi bi-plus"></i> Topping toevoegen
                        </button>
                    </div>
                </div>

                <div v-show="calculatorActive && activeTab==='overzicht'">
                    <div class="panel">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
                            <div class="panel-title" style="margin-bottom:0"><i class="bi bi-list-check"></i> Recept Overzicht</div>
                            <button class="btn btn-primary" @click="printRecipe"><i class="bi bi-printer"></i> Print PDF</button>
                        </div>
                        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:1.5rem">
                            <div><span class="form-label">Deeggewicht</span><br><span class="calc-value">{{ formatW(doughWeight) }}<span class="calc-unit">g</span></span></div>
                            <div><span class="form-label">Totaal gewicht</span><br><span class="calc-value">{{ formatW(totalFinalWeight) }}<span class="calc-unit">g</span></span></div>
                            <div><span class="form-label">Hydratatie</span><br><span class="calc-value">{{ formatP(effectiveTotalHydration) }}<span class="calc-unit">%</span></span></div>
                            <div><span class="form-label">Zout</span><br><span class="calc-value">{{ formatP(saltPct) }}<span class="calc-unit">%</span></span></div>
                            <div><span class="form-label">Volkoren</span><br><span class="calc-value">{{ formatP(totalWholeGrainPct) }}<span class="calc-unit">%</span></span></div>
                            <div><span class="form-label">Wit</span><br><span class="calc-value">{{ formatP(100 - Math.round(totalWholeGrainPct * 10) / 10) }}<span class="calc-unit">%</span></span></div>
                        </div>

                        <div class="overview-grid">
                            <div class="overview-section" v-if="useSourdough">
                                <h4><i class="bi bi-fire"></i> Zuurdesem</h4>
                                <template v-for="(g, i) in sourdoughGrains" :key="'osd'+i">
                                    <div class="overview-item" v-if="g.pct > 0">
                                        <span class="name">{{ grainName(g.type) }}</span>
                                        <span class="value">{{ formatW(sourdoughGrainDetail(i).total) }}g</span>
                                    </div>
                                </template>
                                <div class="overview-item">
                                    <span class="name">Water</span>
                                    <span class="value">{{ formatW(sourdoughWater) }}g</span>
                                </div>
                                <div class="overview-total">
                                    <span>Zuurdesem totaal</span>
                                    <span>{{ formatW(sourdoughWeight) }}g</span>
                                </div>
                            </div>

                            <div class="overview-section" v-if="usePreFerment">
                                <h4><i class="bi bi-layers"></i> Voordeeg</h4>
                                <template v-for="(g, i) in preFermentGrains" :key="'opf'+i">
                                    <div class="overview-item" v-if="g.pct > 0">
                                        <span class="name">{{ grainName(g.type) }}</span>
                                        <span class="value">{{ formatW(preFermentGrainDetail(i).total) }}g</span>
                                    </div>
                                </template>
                                <div class="overview-item">
                                    <span class="name">Water</span>
                                    <span class="value">{{ formatW(preFermentWater) }}g</span>
                                </div>
                                <div class="overview-total">
                                    <span>Voordeeg totaal</span>
                                    <span>{{ formatW(preFermentWeight) }}g</span>
                                </div>
                            </div>

                            <div class="overview-section">
                                <h4><i class="bi bi-moisture"></i> Hoofddeeg</h4>
                                <template v-for="(g, i) in mainDoughGrains" :key="'omd'+i">
                                    <div class="overview-item" v-if="g.pct > 0">
                                        <span class="name">{{ grainName(g.type) }}</span>
                                        <span class="value">{{ formatW(mainDoughGrainDetail(i).total) }}g</span>
                                    </div>
                                </template>
                                <div class="overview-item">
                                    <span class="name">Water</span>
                                    <span class="value">{{ formatW(mainDoughWater) }}g</span>
                                </div>
                                <div class="overview-item">
                                    <span class="name">Zout</span>
                                    <span class="value">{{ formatW(saltWeight) }}g</span>
                                </div>
                                <div class="overview-item" v-if="useYeast">
                                    <span class="name">{{ yeastName }}</span>
                                    <span class="value">{{ formatW(yeastWeight) }}g</span>
                                </div>
                                <div class="overview-total">
                                    <span>Hoofddeeg totaal</span>
                                    <span>{{ formatW(mainDoughFlour + mainDoughWater + saltWeight + yeastWeight) }}g</span>
                                </div>
                            </div>

                            <div class="overview-section" v-if="mixins.length > 0">
                                <h4><i class="bi bi-plus-circle"></i> Mix-ins</h4>
                                <template v-for="(m, i) in mixins" :key="'omx'+i"><div class="overview-item" v-if="m.ingredient && m.pct > 0">
                                    <span class="name">{{ m.ingredient }} <span class="category-label" :class="'cat-'+m.category">{{ categoryLabel(m.category) }}</span></span>
                                    <span class="value">{{ formatW(mixinWeight(i)) }}g</span>
                                </div></template>
                                <div class="overview-total">
                                    <span>Mix-ins totaal</span>
                                    <span>{{ formatW(totalMixinWeight) }}g</span>
                                </div>
                            </div>

                            <div class="overview-section" v-if="toppings.length > 0">
                                <h4><i class="bi bi-stars"></i> Toppings</h4>
                                <template v-for="(t, i) in toppings" :key="'otp'+i"><div class="overview-item" v-if="t.ingredient && t.pct > 0">
                                    <span class="name">{{ t.ingredient }}</span>
                                    <span class="value">{{ formatW(toppingWeight(i)) }}g</span>
                                </div></template>
                                <div class="overview-total">
                                    <span>Toppings totaal</span>
                                    <span>{{ formatW(totalToppingWeight) }}g</span>
                                </div>
                            </div>

                            <div class="overview-section" v-if="grainTypeDistribution.length > 0">
                                <h4><i class="bi bi-moisture"></i> Meelverdeling</h4>
                                <div class="overview-item">
                                    <span class="name">Volkoren</span>
                                    <span class="value">{{ formatP(totalWholeGrainPct) }}%</span>
                                </div>
                                <div class="overview-item">
                                    <span class="name">Wit</span>
                                    <span class="value">{{ formatP(100 - Math.round(totalWholeGrainPct * 10) / 10) }}%</span>
                                </div>
                                <div class="overview-total" style="margin-top:0.25rem;padding-top:0.5rem">
                                    <span>Graansoort</span>
                                </div>
                                <div class="overview-item sub" v-for="gt in grainTypeDistribution" :key="gt.name">
                                    <span class="name">{{ gt.name }}</span>
                                    <span class="value">{{ formatP(gt.pct) }}%</span>
                                </div>
                            </div>
                        </div>

                        <hr class="divider">
                        <div class="panel-title"><i class="bi bi-percent"></i> Baker's Percentages</div>
                        <table class="bp-table">
                            <thead><tr><th>Ingrediënt</th><th>Gewicht</th><th>Baker's %</th></tr></thead>
                            <tbody>
                                <tr><td>Totaal meel</td><td>{{ formatW(totalFlour) }}g</td><td>100%</td></tr>
                                <tr><td>Water</td><td>{{ formatW(totalWater) }}g</td><td>{{ formatP(hydration) }}%</td></tr>
                                <tr><td>Zout</td><td>{{ formatW(saltWeight) }}g</td><td>{{ formatP(saltPct) }}%</td></tr>
                                <tr v-if="useYeast"><td>{{ yeastName }}</td><td>{{ formatW(yeastWeight) }}g</td><td>{{ formatP(yeastPct) }}%</td></tr>
                                <template v-for="(m, i) in mixins" :key="'bp'+i"><tr v-if="m.ingredient && m.pct > 0">
                                    <td>{{ m.ingredient }}</td><td>{{ formatW(mixinWeight(i)) }}g</td><td>{{ formatP(m.pct) }}%</td>
                                </tr></template>
                            </tbody>
                        </table>

                        <hr class="divider" v-if="ingredientsLoaded">
                        <div class="panel-title" v-if="ingredientsLoaded"><i class="bi bi-currency-euro"></i> Kostprijsberekening</div>
                        <div v-if="ingredientsLoaded" class="overview-grid">
                            <div class="overview-section">
                                <h4><i class="bi bi-receipt"></i> Ingrediënten</h4>
                                <div class="overview-item">
                                    <span class="name">Meel</span>
                                    <span class="value">€{{ formatEuro(totalFlourCost) }}</span>
                                </div>
                                <div class="overview-item" v-if="useYeast">
                                    <span class="name">{{ yeastName }}</span>
                                    <span class="value">€{{ formatEuro(totalYeastCost) }}</span>
                                </div>
                                <div class="overview-item">
                                    <span class="name">Zout</span>
                                    <span class="value">€{{ formatEuro(totalSaltCost) }}</span>
                                </div>
                                <div class="overview-item" v-if="mixins.length > 0">
                                    <span class="name">Mix-ins</span>
                                    <span class="value">€{{ formatEuro(totalMixinCost) }}</span>
                                </div>
                                <div class="overview-item" v-if="toppings.length > 0">
                                    <span class="name">Toppings</span>
                                    <span class="value">€{{ formatEuro(totalToppingCost) }}</span>
                                </div>
                                <div class="overview-total">
                                    <span>Subtotaal ingrediënten</span>
                                    <span>€{{ formatEuro(totalIngredientCost) }}</span>
                                </div>
                            </div>
                            <div class="overview-section" style="background:#fff3e0">
                                <h4 style="color:#e65100"><i class="bi bi-lightning-charge"></i> Nutskosten</h4>
                                <div class="overview-total">
                                    <span>Per brood ({{ monthlyBreadCount }} broden deze maand)</span>
                                    <span>{{ monthlyBreadCount ? '€' + formatEuro(totalUtilityCostPerRecipe) : '—' }}</span>
                                </div>
                            </div>
                            <div class="overview-section" style="background:#e8f5e9">
                                <h4 style="color:#2e7d32"><i class="bi bi-calculator"></i> Kostprijs</h4>
                                <div class="overview-item">
                                    <span class="name">Per kg deeg</span>
                                    <span class="value" style="color:#2e7d32;font-size:1.1rem">€{{ formatEuro(costPerKgDough) }}</span>
                                </div>
                                <div class="overview-item">
                                    <span class="name">Per stuk ({{ formatW(finalWeightPerBall) }}g)</span>
                                    <span class="value" style="color:#2e7d32;font-size:1.1rem">€{{ formatEuro(costPerPiece) }}</span>
                                </div>
                            </div>
                        </div>
                        <p v-if="ingredientsLoaded && totalIngredientCost === 0" style="color:#888;font-size:0.85rem;margin-top:0.5rem">
                            <i class="bi bi-info-circle"></i> Vul ingrediëntprijzen in via <a href="voorraad.php" style="color:#8b5a2b">Voorraadbeheer</a> voor kostprijsberekening.
                        </p>
                    </div>
                </div>

                <div v-show="calculatorActive && activeTab==='methode'">
                    <div class="panel">
                        <div class="panel-title"><i class="bi bi-journal-text"></i> Bereidingswijze</div>
                        <textarea v-model="method" class="method-textarea" placeholder="Beschrijf hier je bereidingswijze, tijden, temperaturen..."></textarea>
                    </div>
                </div>

                <div v-show="activeTab==='recepten'">
                    <div class="panel">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
                            <div class="panel-title" style="margin-bottom:0"><i class="bi bi-bookmark"></i> Opgeslagen Recepten</div>
                            <button class="btn btn-primary btn-sm" @click="newRecipe"><i class="bi bi-plus-lg"></i> Nieuw Recept</button>
                        </div>
                        <div v-if="savedRecipes.length === 0" class="empty-state">
                            <i class="bi bi-bookmark-star"></i>
                            <p>Nog geen recepten opgeslagen</p>
                        </div>
                        <div class="recipe-list" v-else>
                            <div v-for="group in groupedRecipes" :key="group.id || '__uncategorized'" class="recipe-group">
                                <div class="recipe-group-header" :class="{ collapsed: isGroupCollapsed(group.id) }" @click="toggleGroup(group.id)">
                                    <i class="bi bi-chevron-down"></i>
                                    <span class="recipe-group-name" :class="{ 'uncategorized-header': !group.id }">
                                        <i :class="group.id ? 'bi bi-layers' : 'bi bi-question-circle'"></i>
                                        {{ group.name }}
                                    </span>
                                    <span class="recipe-group-count">{{ group.recipes.length }}</span>
                                </div>
                                <ul class="recipe-group-items" :class="{ collapsed: isGroupCollapsed(group.id) }">
                                    <li v-for="r in group.recipes" :key="r.id" class="recipe-item">
                                        <div class="recipe-info">
                                            <h4>
                                                <i class="bi bi-journal-text" style="color: #c8913a;"></i>
                                                {{ r.name }}
                                            </h4>
                                            <small>{{ formatDate(r.updated_at) }}</small>
                                            <div style="margin-top:0.2rem">
                                                <span v-if="r.linked_to_product == 1" style="color:#2e7d32;font-size:0.85rem" title="Gekoppeld aan product"><i class="bi bi-link-45deg"></i></span>
                                                <span v-else style="color:#ccc;font-size:0.85rem" title="Niet gekoppeld aan product"><i class="bi bi-x"></i></span>
                                            </div>
                                        </div>
                                        <div class="recipe-actions">
                                            <button class="btn btn-primary btn-sm" @click="loadRecipe(r.id)"><i class="bi bi-folder2-open"></i> Laden</button>
                                            <button class="btn btn-danger btn-sm" @click="deleteRecipe(r.id)"><i class="bi bi-trash"></i></button>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="calc-sidebar" v-show="calculatorActive">
                <div class="summary-card">
                    <div class="summary-header">
                        <h3><i class="bi bi-calculator"></i> Live Berekening</h3>
                    </div>
                    <div class="summary-body">
                        <div class="summary-section-title">Basis</div>
                        <div class="summary-row">
                            <span class="summary-label">Totaal meel</span>
                            <span class="summary-value">{{ formatW(totalFlour) }}g</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Totaal water</span>
                            <span class="summary-value">{{ formatW(totalWater) }}g</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Hydratatie</span>
                            <span class="summary-value">{{ formatP(hydration) }}%</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Zout</span>
                            <span class="summary-value">{{ formatW(saltWeight) }}g</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Volkoren</span>
                            <span class="summary-value">{{ formatP(totalWholeGrainPct) }}%</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Wit</span>
                            <span class="summary-value">{{ formatP(100 - Math.round(totalWholeGrainPct * 10) / 10) }}%</span>
                        </div>
                        <template v-if="grainTypeDistribution.length > 0">
                            <div class="summary-section-title">Graanverdeling</div>
                            <div class="summary-row" v-for="gt in grainTypeDistribution" :key="gt.name">
                                <span class="summary-label">{{ gt.name }}</span>
                                <span class="summary-value">{{ formatP(gt.pct) }}%</span>
                            </div>
                        </template>
                        <div class="summary-section-title" v-if="useSourdough">Zuurdesem</div>
                        <div class="summary-row" v-if="useSourdough">
                            <span class="summary-label">Percentage</span>
                            <span class="summary-value">{{ formatP(sourdoughPct) }}%</span>
                        </div>
                        <div class="summary-row" v-if="useSourdough">
                            <span class="summary-label">Hydratatie</span>
                            <span class="summary-value">{{ formatP(sourdoughHydration) }}%</span>
                        </div>

                        <div class="summary-row" v-if="useYeast">
                            <span class="summary-label">{{ yeastName }}</span>
                            <span class="summary-value">{{ formatP(yeastPct) }}%</span>
                        </div>

                        <div class="summary-section-title" v-if="usePreFerment">Voordeeg</div>
                        <div class="summary-row" v-if="usePreFerment">
                            <span class="summary-label">Percentage</span>
                            <span class="summary-value">{{ formatP(preFermentPct) }}%</span>
                        </div>
                        <div class="summary-row" v-if="usePreFerment">
                            <span class="summary-label">Hydratatie</span>
                            <span class="summary-value">{{ formatP(preFermentHydration) }}%</span>
                        </div>

                        <div class="summary-section-title" v-if="mixins.length > 0">Mix-ins</div>
                        <template v-for="(m, i) in mixins" :key="'sm'+i">
                            <div class="summary-row" v-if="m.ingredient && m.pct > 0">
                                <span class="summary-label">{{ m.ingredient }}</span>
                                <span class="summary-value">{{ formatP(m.pct) }}%</span>
                            </div>
                        </template>
                        <div class="summary-section-title" v-if="toppings.length > 0">Toppings</div>
                        <template v-for="(t, i) in toppings" :key="'st'+i">
                            <div class="summary-row" v-if="t.ingredient && t.pct > 0">
                                <span class="summary-label">{{ t.ingredient }}</span>
                                <span class="summary-value">{{ formatP(t.pct) }}%</span>
                            </div>
                        </template>

                        <div class="summary-row total">
                            <span class="summary-label" style="font-weight:700;color:#5c3d1e">Deeggewicht</span>
                            <span class="summary-value accent">{{ formatW(doughWeight) }}g</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label" style="font-weight:700;color:#5c3d1e">Totaal</span>
                            <span class="summary-value accent">{{ formatW(totalFinalWeight) }}g</span>
                        </div>

                        <div class="pct-bar">
                            <div class="pct-bar-fill pct-bar-flour" :style="{width: flourPct+'%'}"></div>
                            <div class="pct-bar-fill pct-bar-water" :style="{width: waterPct+'%'}"></div>
                            <div class="pct-bar-fill pct-bar-other" :style="{width: otherPct+'%'}"></div>
                        </div>
                        <div style="display:flex;gap:1rem;margin-top:0.4rem;font-size:0.7rem;color:#aaa">
                            <span><span style="color:#c8913a">&#9679;</span> Meel</span>
                            <span><span style="color:#4a90d9">&#9679;</span> Water</span>
                            <span><span style="color:#8bc34a">&#9679;</span> Overig</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="toast success" v-if="toastMsg">{{ toastMsg }}</div>

        <div class="modal-overlay" v-if="showDoughTypeModal" @click.self="doughTypeModalView === 'list' && (showDoughTypeModal = false)">
            <div class="modal-content" :class="{'modal-wide': doughTypeModalView === 'edit'}">
                <div class="modal-header">
                    <h3 v-if="doughTypeModalView === 'list'"><i class="bi bi-layers"></i> Deegsoorten beheren</h3>
                    <h3 v-else><i class="bi bi-layers"></i> {{ editingDoughType && editingDoughType.id ? editingDoughType.name : 'Nieuwe deegsoort' }}</h3>
                    <button class="modal-close" @click="showDoughTypeModal = false">&times;</button>
                </div>
                <div class="modal-body modal-body-scroll">

                    <!-- LIST VIEW -->
                    <div v-if="doughTypeModalView === 'list'">
                        <div class="dough-type-list">
                            <div v-for="dt in doughTypes" :key="dt.id" class="dough-type-item">
                                <span>{{ dt.name }}</span>
                                <div style="display:flex;gap:0.25rem">
                                    <button class="btn-icon-danger" @click="editDoughType(dt)" title="Bewerken" style="color:#8b5a2b"><i class="bi bi-pencil"></i></button>
                                    <button class="btn-icon-danger" @click="deleteDoughType(dt.id)" title="Verwijderen"><i class="bi bi-trash"></i></button>
                                </div>
                            </div>
                            <div v-if="doughTypes.length === 0" class="empty-msg">Nog geen deegsoorten</div>
                        </div>
                        <button class="btn btn-primary" style="width:100%;margin-top:0.5rem" @click="newDoughType()">
                            <i class="bi bi-plus"></i> Nieuwe deegsoort
                        </button>
                    </div>

                    <!-- EDIT VIEW -->
                    <div v-if="doughTypeModalView === 'edit' && editingDoughType">
                        <div class="form-group" style="margin-bottom:1rem">
                            <label class="form-label">Naam</label>
                            <input type="text" v-model="editingDoughType.name" class="form-input" placeholder="Bijv. Bianco, Rocca..." style="width:100%">
                        </div>

                        <div class="form-grid" style="margin-bottom:1rem">
                            <div class="form-group">
                                <label class="form-label">Hydratatie</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="editingDoughType.hydration" class="form-input" min="30" max="120" step="1">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Zout</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="editingDoughType.saltPct" class="form-input" min="0" max="10" step="0.1">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                        </div>

                        <hr class="divider">
                        <div class="panel-title" style="margin-bottom:0.75rem"><i class="bi bi-droplet"></i> Rijsmiddelen</div>

                        <div class="toggle-row">
                            <div class="toggle" :class="{on: editingDoughType.useSourdough}" @click="editingDoughType.useSourdough = !editingDoughType.useSourdough"></div>
                            <span class="toggle-label">Zuurdesem</span>
                        </div>
                        <div class="form-grid" v-if="editingDoughType.useSourdough" style="margin-bottom:1rem">
                            <div class="form-group">
                                <label class="form-label">Percentage (baker's %)</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="editingDoughType.sourdoughPct" class="form-input" min="0" max="100" step="0.5">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Hydratatie zuurdesem</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="editingDoughType.sourdoughHydration" class="form-input" min="50" max="200" step="1">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                        </div>

                        <div class="toggle-row" style="margin-top:0.5rem">
                            <div class="toggle" :class="{on: editingDoughType.useYeast}" @click="editingDoughType.useYeast = !editingDoughType.useYeast"></div>
                            <span class="toggle-label">Gist</span>
                        </div>
                        <div class="form-grid" v-if="editingDoughType.useYeast" style="margin-bottom:1rem">
                            <div class="form-group">
                                <label class="form-label">Type</label>
                                <select v-model="editingDoughType.yeastType" class="form-select">
                                    <option v-for="y in yeastTypes" :value="y.id">{{ y.name }}</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Percentage</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="editingDoughType.yeastPct" class="form-input" min="0" max="10" step="0.1">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                        </div>

                        <hr class="divider">
                        <div class="panel-title" style="margin-bottom:0.75rem"><i class="bi bi-moisture"></i> Meelsoorten</div>

                        <!-- Sourdough grains -->
                        <div v-if="editingDoughType.useSourdough" style="margin-bottom:1rem">
                            <label class="form-label" style="margin-bottom:0.5rem;display:block"><i class="bi bi-fire"></i> Zuurdesem meelsoorten</label>
                            <div class="grain-row" v-for="(grain, i) in editingDoughType.sourdoughGrains" :key="'dtsd'+i">
                                <div class="form-group">
                                    <select v-model="grain.type" class="form-select">
                                        <option v-for="g in grainTypes" :value="g.id">{{ g.name }}</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <div class="input-with-unit">
                                        <input type="number" v-model.number="grain.pct" class="form-input" min="0" max="100" step="1">
                                        <span class="input-unit">%</span>
                                    </div>
                                </div>
                                <button class="btn-remove" @click="editingDoughType.sourdoughGrains.splice(i,1)" v-if="editingDoughType.sourdoughGrains.length > 1"><i class="bi bi-x"></i></button>
                            </div>
                            <button class="btn-add" @click="editingDoughType.sourdoughGrains.push({type: grainTypes[0]?.id ?? 'wheat_white', pct: 0})" v-if="editingDoughType.sourdoughGrains.length < 5">
                                <i class="bi bi-plus"></i> Toevoegen
                            </button>
                            <div class="grain-warning" v-if="editingDoughType.sourdoughGrains.reduce((s,g)=>s+(g.pct||0),0) !== 100">
                                <i class="bi bi-exclamation-triangle"></i> Totaal is {{ editingDoughType.sourdoughGrains.reduce((s,g)=>s+(g.pct||0),0) }}% — moet 100% zijn
                            </div>
                        </div>

                        <!-- Pre-ferment toggle + grains -->
                        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.75rem">
                            <div class="toggle" :class="{on: editingDoughType.usePreFerment}" @click="editingDoughType.usePreFerment = !editingDoughType.usePreFerment"></div>
                            <span class="toggle-label" style="font-size:0.9rem">Voordeeg</span>
                        </div>
                        <div v-if="editingDoughType.usePreFerment" style="margin-bottom:1rem">
                            <div class="form-grid" style="margin-bottom:0.75rem">
                                <div class="form-group">
                                    <label class="form-label">% van totaal meel</label>
                                    <div class="input-with-unit">
                                        <input type="number" v-model.number="editingDoughType.preFermentPct" class="form-input" min="1" max="100" step="1">
                                        <span class="input-unit">%</span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Hydratatie voordeeg</label>
                                    <div class="input-with-unit">
                                        <input type="number" v-model.number="editingDoughType.preFermentHydration" class="form-input" min="50" max="200" step="1">
                                        <span class="input-unit">%</span>
                                    </div>
                                </div>
                            </div>
                            <div class="grain-row" v-for="(grain, i) in editingDoughType.preFermentGrains" :key="'dtpf'+i">
                                <div class="form-group">
                                    <select v-model="grain.type" class="form-select">
                                        <option v-for="g in grainTypes" :value="g.id">{{ g.name }}</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <div class="input-with-unit">
                                        <input type="number" v-model.number="grain.pct" class="form-input" min="0" max="100" step="1">
                                        <span class="input-unit">%</span>
                                    </div>
                                </div>
                                <button class="btn-remove" @click="editingDoughType.preFermentGrains.splice(i,1)" v-if="editingDoughType.preFermentGrains.length > 1"><i class="bi bi-x"></i></button>
                            </div>
                            <button class="btn-add" @click="editingDoughType.preFermentGrains.push({type: grainTypes[0]?.id ?? 'wheat_white', pct: 0})" v-if="editingDoughType.preFermentGrains.length < 5">
                                <i class="bi bi-plus"></i> Toevoegen
                            </button>
                        </div>

                        <!-- Main dough grains -->
                        <label class="form-label" style="margin-bottom:0.5rem;display:block">Hoofddeeg meelsoorten</label>
                        <div class="grain-row" v-for="(grain, i) in editingDoughType.mainDoughGrains" :key="'dtmd'+i">
                            <div class="form-group">
                                <select v-model="grain.type" class="form-select">
                                    <option v-for="g in grainTypes" :value="g.id">{{ g.name }}</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="grain.pct" class="form-input" min="0" max="100" step="1">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                            <button class="btn-remove" @click="editingDoughType.mainDoughGrains.splice(i,1)" v-if="editingDoughType.mainDoughGrains.length > 1"><i class="bi bi-x"></i></button>
                        </div>
                        <button class="btn-add" @click="editingDoughType.mainDoughGrains.push({type: grainTypes[0]?.id ?? 'wheat_white', pct: 0})" v-if="editingDoughType.mainDoughGrains.length < 5">
                            <i class="bi bi-plus"></i> Meelsoort toevoegen
                        </button>
                        <div class="grain-warning" v-if="editingDoughType.mainDoughGrains.reduce((s,g)=>s+(g.pct||0),0) !== 100">
                            <i class="bi bi-exclamation-triangle"></i> Totaal is {{ editingDoughType.mainDoughGrains.reduce((s,g)=>s+(g.pct||0),0) }}% — moet 100% zijn
                        </div>

                        <div style="display:flex;gap:0.5rem;margin-top:1.5rem">
                            <button class="btn btn-ghost" @click="doughTypeModalView = 'list'">← Terug</button>
                            <button class="btn btn-primary" style="flex:1" @click="saveDoughType()" :disabled="!editingDoughType.name.trim()">
                                <i class="bi bi-check"></i> {{ editingDoughType.id ? 'Opslaan' : 'Aanmaken' }}
                            </button>
                        </div>
                    </div>

                </div>
            </div>
        </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <script>
    const { createApp } = Vue;
    createApp({
        data() {
            return {
                activeTab: 'recepten',
                calculatorActive: false,
                recipeName: '',
                currentRecipeId: null,
                doughTypeId: null,
                doughTypes: <?= json_encode($doughTypes) ?>,
                showDoughTypeModal: false,
                doughTypeModalView: 'list',
                editingDoughType: null,
                doughWeight: 300,
                hydration: 62,
                saltPct: 2.6,
                useSourdough: false,
                sourdoughPct: 20,
                sourdoughHydration: 100,
                sourdoughGrains: [{ type: 'wheat', pct: 100 }],
                useYeast: true,
                yeastType: 'instant_yeast',
                yeastPct: 1.3,
                usePreFerment: false,
                preFermentPct: 20,
                preFermentHydration: 100,
                preFermentGrains: [{ type: 'wheat_white', pct: 100 }],
                mainDoughGrains: [{ type: 'wheat_white', pct: 100 }],
                mixinMode: 'flour',
                mixins: [],
                toppings: [],
                method: '',
                savedRecipes: [],
                collapsedGroups: {},
                saving: false,
                toastMsg: '',
                grainTypes: [],
                yeastTypes: [],
                mixinIngredients: [],
                toppingIngredients: [],
                allIngredients: [],
                utilityCosts: { total: 0 },
                monthlyBreadCount: <?= $monthlyBreadCount ?>,
                ingredientsLoaded: false,
                fifoCosts: {},
                fifoLoading: false,
                grainTypeNames: [],
            };
        },

        computed: {
            totalDoughWeight() { return this.doughWeight; },
            totalFlour() { return this.totalDoughWeight / (1 + this.hydration / 100 + this.saltPct / 100); },
            totalWater() { return this.totalFlour * (this.hydration / 100); },

            sourdoughWeight() {
                if (!this.useSourdough) return 0;
                return this.totalFlour * (this.sourdoughPct / 100);
            },
            sourdoughFlour() {
                if (!this.useSourdough) return 0;
                return this.sourdoughWeight / (1 + this.sourdoughHydration / 100);
            },
            sourdoughWater() { return this.sourdoughWeight - this.sourdoughFlour; },

            preFermentWeight() {
                if (!this.usePreFerment) return 0;
                return this.totalFlour * (this.preFermentPct / 100);
            },
            preFermentFlour() {
                if (!this.usePreFerment) return 0;
                return this.preFermentWeight / (1 + this.preFermentHydration / 100);
            },
            preFermentWater() { return this.preFermentWeight - this.preFermentFlour; },

            mainDoughFlour() { return this.totalFlour - this.sourdoughFlour - this.preFermentFlour; },
            mainDoughWater() { return this.totalWater - this.sourdoughWater - this.preFermentWater; },
            effectiveMainDoughHydration() {
                if (this.mainDoughFlour === 0) return 0;
                return (this.mainDoughWater / this.mainDoughFlour) * 100;
            },

            yeastWeight() {
                if (!this.useYeast) return 0;
                return this.totalFlour * (this.yeastPct / 100);
            },
            yeastName() {
                const t = this.yeastTypes.find(y => y.id === this.yeastType);
                return t ? t.name : '';
            },

            integratedMixinWeight() {
                return this.mixins.filter(m => m.category === 'integrated').reduce((s, m) => s + this._mw(m), 0);
            },
            nonIntegratedMixinWeight() {
                return this.mixins.filter(m => m.category === 'non-integrated').reduce((s, m) => s + this._mw(m), 0);
            },
            liquidMixinWeight() {
                return this.mixins.filter(m => m.category === 'liquid').reduce((s, m) => s + this._mw(m), 0);
            },
            totalMixinWeight() {
                return this.integratedMixinWeight + this.nonIntegratedMixinWeight + this.liquidMixinWeight;
            },

            totalDryWeight() { return this.totalFlour + this.integratedMixinWeight; },
            saltWeight() { return this.totalDryWeight * (this.saltPct / 100); },

            totalToppingWeight() {
                return this.toppings.reduce((s, t) => s + this.totalDoughWeight * (t.pct / 100), 0);
            },

            totalFinalWeight() {
                return this.totalDoughWeight + this.yeastWeight + this.totalMixinWeight + this.totalToppingWeight;
            },
            finalWeightPerBall() {
                return this.totalFinalWeight;
            },
            effectiveTotalHydration() {
                const totalLiquid = this.totalWater + this.liquidMixinWeight;
                if (this.totalDryWeight === 0) return 0;
                return (totalLiquid / this.totalDryWeight) * 100;
            },

            sourdoughGrainsPctTotal() { return this.sourdoughGrains.reduce((s, g) => s + (g.pct || 0), 0); },
            preFermentGrainsPctTotal() { return this.preFermentGrains.reduce((s, g) => s + (g.pct || 0), 0); },
            mainGrainsPctTotal() { return this.mainDoughGrains.reduce((s, g) => s + (g.pct || 0), 0); },

            flourPct() { return this.totalFinalWeight > 0 ? (this.totalFlour / this.totalFinalWeight * 100) : 0; },
            waterPct() { return this.totalFinalWeight > 0 ? (this.totalWater / this.totalFinalWeight * 100) : 0; },
            otherPct() { return Math.max(0, 100 - this.flourPct - this.waterPct); },

            totalWholeGrainPct() {
                let wholeGrainFlour = 0;
                let totalGrainFlour = 0;
                const isWholeGrain = (type) => {
                    const grain = this.grainTypes.find(g => g.id == type);
                    return grain ? grain.isWholeGrain : (type && type.toString().includes('_whole'));
                };
                const addGrain = (amount, type) => {
                    totalGrainFlour += amount;
                    if (isWholeGrain(type)) wholeGrainFlour += amount;
                };
                if (this.useSourdough) {
                    this.sourdoughGrains.forEach((g, i) => addGrain(this.sourdoughGrainDetail(i).total, g.type));
                }
                if (this.usePreFerment) {
                    this.preFermentGrains.forEach((g, i) => addGrain(this.preFermentGrainDetail(i).total, g.type));
                }
                this.mainDoughGrains.forEach((g, i) => addGrain(this.mainDoughGrainDetail(i).total, g.type));
                return totalGrainFlour > 0 ? (wholeGrainFlour / totalGrainFlour) * 100 : 0;
            },

            grainTypeDistribution() {
                if (this.totalFlour === 0 || this.grainTypes.length === 0) return [];
                const typeMap = {};
                const addToMap = (grainId, flourAmount) => {
                    const grain = this.grainTypes.find(g => g.id == grainId);
                    if (!grain || flourAmount <= 0) return;
                    const gtId = grain.grainTypeId;
                    const gtName = gtId
                        ? ((this.grainTypeNames.find(g => g.id == gtId) || {}).name || 'Onbekend')
                        : 'Onbekend';
                    const key = gtId !== null ? gtId : 'unknown';
                    if (!typeMap[key]) typeMap[key] = { name: gtName, amount: 0 };
                    typeMap[key].amount += flourAmount;
                };
                if (this.useSourdough) {
                    this.sourdoughGrains.forEach((g, i) => addToMap(g.type, this.sourdoughGrainDetail(i).total));
                }
                if (this.usePreFerment) {
                    this.preFermentGrains.forEach((g, i) => addToMap(g.type, this.preFermentGrainDetail(i).total));
                }
                this.mainDoughGrains.forEach((g, i) => addToMap(g.type, this.mainDoughGrainDetail(i).total));
                return Object.values(typeMap)
                    .map(t => ({ name: t.name, pct: (t.amount / this.totalFlour) * 100 }))
                    .filter(t => t.pct > 0)
                    .sort((a, b) => b.pct - a.pct);
            },

            totalFlourCost() {
                let cost = 0;
                if (this.useSourdough) {
                    this.sourdoughGrains.forEach((g, i) => {
                        const weight = this.sourdoughGrainDetail(i).total;
                        const key = `grain_${g.type}_${Math.round(weight)}`;
                        if (this.fifoCosts[key] !== undefined) {
                            cost += this.fifoCosts[key];
                        } else {
                            const grain = this.grainTypes.find(gt => gt.id == g.type);
                            if (grain) cost += (weight / 1000) * (grain.pricePerKg || 0);
                        }
                    });
                }
                if (this.usePreFerment) {
                    this.preFermentGrains.forEach((g, i) => {
                        const weight = this.preFermentGrainDetail(i).total;
                        const key = `grain_${g.type}_${Math.round(weight)}`;
                        if (this.fifoCosts[key] !== undefined) {
                            cost += this.fifoCosts[key];
                        } else {
                            const grain = this.grainTypes.find(gt => gt.id == g.type);
                            if (grain) cost += (weight / 1000) * (grain.pricePerKg || 0);
                        }
                    });
                }
                this.mainDoughGrains.forEach((g, i) => {
                    const weight = this.mainDoughGrainDetail(i).total;
                    const key = `grain_${g.type}_${Math.round(weight)}`;
                    if (this.fifoCosts[key] !== undefined) {
                        cost += this.fifoCosts[key];
                    } else {
                        const grain = this.grainTypes.find(gt => gt.id == g.type);
                        if (grain) cost += (weight / 1000) * (grain.pricePerKg || 0);
                    }
                });
                return cost;
            },

            totalYeastCost() {
                if (!this.useYeast) return 0;
                const weight = this.yeastWeight;
                const key = `yeast_${this.yeastType}_${Math.round(weight)}`;
                if (this.fifoCosts[key] !== undefined) {
                    return this.fifoCosts[key];
                }
                const yeast = this.yeastTypes.find(y => y.id == this.yeastType);
                if (!yeast) return 0;
                return (weight / 1000) * (yeast.pricePerKg || 0);
            },

            totalSaltCost() {
                const weight = this.saltWeight;
                const saltIng = this.allIngredients.find(i => i.name === 'Zout');
                if (saltIng) {
                    const key = `ing_${saltIng.id}_${Math.round(weight)}`;
                    if (this.fifoCosts[key] !== undefined) {
                        return this.fifoCosts[key];
                    }
                }
                const saltPrice = this.getIngredientPrice('Zout');
                return (weight / 1000) * saltPrice;
            },

            totalWaterCost() {
                return 0;
            },

            totalMixinCost() {
                let cost = 0;
                this.mixins.forEach((m, i) => {
                    if (m.ingredient && m.pct > 0) {
                        const weight = this.mixinWeight(i);
                        const ing = this.allIngredients.find(x => x.name === m.ingredient);
                        if (ing) {
                            const key = `ing_${ing.id}_${Math.round(weight)}`;
                            if (this.fifoCosts[key] !== undefined) {
                                cost += this.fifoCosts[key];
                                return;
                            }
                        }
                        const price = this.getIngredientPrice(m.ingredient);
                        cost += (weight / 1000) * price;
                    }
                });
                return cost;
            },

            totalToppingCost() {
                let cost = 0;
                this.toppings.forEach((t, i) => {
                    if (t.ingredient && t.pct > 0) {
                        const weight = this.toppingWeight(i);
                        const ing = this.allIngredients.find(x => x.name === t.ingredient);
                        if (ing) {
                            const key = `ing_${ing.id}_${Math.round(weight)}`;
                            if (this.fifoCosts[key] !== undefined) {
                                cost += this.fifoCosts[key];
                                return;
                            }
                        }
                        const price = this.getIngredientPrice(t.ingredient);
                        cost += (weight / 1000) * price;
                    }
                });
                return cost;
            },

            totalIngredientCost() {
                return this.totalFlourCost + this.totalYeastCost + this.totalSaltCost + this.totalMixinCost + this.totalToppingCost;
            },

            totalUtilityCostPerRecipe() {
                if (!this.monthlyBreadCount) return 0;
                return (this.utilityCosts.total || 0) / this.monthlyBreadCount;
            },

            totalCostWithUtilities() {
                return this.totalIngredientCost + this.totalUtilityCostPerRecipe;
            },

            costPerKgDough() {
                if (this.totalFinalWeight === 0) return 0;
                return (this.totalCostWithUtilities / this.totalFinalWeight) * 1000;
            },

            costPerPiece() {
                return this.totalCostWithUtilities;
            },

            isInherited() {
                if (!this.doughTypeId) return false;
                const dt = this.doughTypes.find(d => d.id == this.doughTypeId);
                return !!(dt && dt.recipe_data);
            },

            groupedRecipes() {
                const groups = {};
                const uncategorized = [];
                this.savedRecipes.forEach(r => {
                    if (r.dough_type_id) {
                        if (!groups[r.dough_type_id]) {
                            groups[r.dough_type_id] = {
                                id: r.dough_type_id,
                                name: r.dough_type_name,
                                recipes: []
                            };
                        }
                        groups[r.dough_type_id].recipes.push(r);
                    } else {
                        uncategorized.push(r);
                    }
                });
                const result = Object.values(groups).sort((a, b) => a.name.localeCompare(b.name));
                if (uncategorized.length > 0) {
                    result.push({ id: null, name: 'Zonder deegsoort', recipes: uncategorized });
                }
                return result;
            },
        },

        watch: {
            yeastType(val) {
                const t = this.yeastTypes.find(y => y.id === val);
                if (t && t.defaultPct) this.yeastPct = t.defaultPct;
                this.debouncedFetchFifoCosts();
            },
            totalFlour: { handler() { this.debouncedFetchFifoCosts(); }, immediate: false },
            useYeast: { handler() { this.debouncedFetchFifoCosts(); }, immediate: false },
            mixins: { handler() { this.debouncedFetchFifoCosts(); }, deep: true },
            toppings: { handler() { this.debouncedFetchFifoCosts(); }, deep: true },
        },

        methods: {
            _mw(m) {
                const base = this.mixinMode === 'dough' ? this.totalDoughWeight : this.totalFlour;
                return base * ((m.pct || 0) / 100);
            },
            mixinWeight(i) { return this._mw(this.mixins[i]); },
            toppingWeight(i) { return this.totalDoughWeight * ((this.toppings[i].pct || 0) / 100); },

            sourdoughGrainDetail(i) {
                const g = this.sourdoughGrains[i];
                const total = this.sourdoughFlour * ((g.pct || 0) / 100);
                return { total };
            },
            preFermentGrainDetail(i) {
                const g = this.preFermentGrains[i];
                const total = this.preFermentFlour * ((g.pct || 0) / 100);
                return { total };
            },
            mainDoughGrainDetail(i) {
                const g = this.mainDoughGrains[i];
                const total = this.mainDoughFlour * ((g.pct || 0) / 100);
                return { total };
            },

            grainName(id) { return (this.grainTypes.find(g => g.id === id) || {}).name || id; },
            categoryLabel(cat) { return cat === 'integrated' ? 'Int.' : cat === 'liquid' ? 'Vloei.' : 'Vast'; },
            autoCategory(m) {
                const ing = this.mixinIngredients.find(x => x.name === m.ingredient);
                if (ing) m.category = ing.cat;
            },

            formatW(v) { return Math.round(v).toString(); },
            formatP(v) { return (Math.round(v * 10) / 10).toString().replace('.', ','); },
            formatEuro(v) { return (Math.round(v * 100) / 100).toFixed(2).replace('.', ','); },
            formatDate(d) {
                if (!d) return '';
                const dt = new Date(d);
                return dt.toLocaleDateString('nl-NL', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            },

            showToast(msg) {
                this.toastMsg = msg;
                setTimeout(() => this.toastMsg = '', 2500);
            },

            getRecipeData() {
                return {
                    doughWeight: this.doughWeight,
                    hydration: this.hydration,
                    saltPct: this.saltPct,
                    useSourdough: this.useSourdough,
                    sourdoughPct: this.sourdoughPct,
                    sourdoughHydration: this.sourdoughHydration,
                    sourdoughGrains: this.sourdoughGrains,
                    useYeast: this.useYeast,
                    yeastType: this.yeastType,
                    yeastPct: this.yeastPct,
                    usePreFerment: this.usePreFerment,
                    preFermentPct: this.preFermentPct,
                    preFermentHydration: this.preFermentHydration,
                    preFermentGrains: this.preFermentGrains,
                    mainDoughGrains: this.mainDoughGrains,
                    mixinMode: this.mixinMode,
                    mixins: this.mixins,
                    toppings: this.toppings,
                    method: this.method,
                };
            },

            applyRecipeData(d) {
                const fields = ['doughWeight','hydration','saltPct',
                    'useSourdough','sourdoughPct','sourdoughHydration','sourdoughGrains',
                    'useYeast','yeastType','yeastPct',
                    'usePreFerment','preFermentPct','preFermentHydration',
                    'preFermentGrains','mainDoughGrains','mixinMode','mixins','toppings','method'];
                fields.forEach(f => { if (d[f] !== undefined) this[f] = d[f]; });
                if (d.weightPerBall !== undefined && d.doughWeight === undefined) {
                    this.doughWeight = d.weightPerBall;
                }
                if (d.levenerType !== undefined && d.useSourdough === undefined) {
                    if (d.levenerType === 'sourdough') {
                        this.useSourdough = true;
                        this.sourdoughPct = d.levenerPct || 20;
                        this.useYeast = false;
                    } else if (d.levenerType !== 'none') {
                        this.useYeast = true;
                        this.yeastType = d.levenerType;
                        this.yeastPct = d.levenerPct || 2.8;
                        this.useSourdough = false;
                    }
                }
            },

            async saveRecipe() {
                if (!this.recipeName.trim()) { this.recipeName = 'Naamloos recept'; }
                this.saving = true;
                try {
                    const body = { name: this.recipeName, dough_type_id: this.doughTypeId, recipe_data: this.getRecipeData() };
                    if (this.currentRecipeId) body.id = this.currentRecipeId;
                    const method = this.currentRecipeId ? 'PUT' : 'POST';
                    const res = await fetch('../../api/baker-recipes.php', { method, headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
                    const data = await res.json();
                    if (data.success) {
                        if (!this.currentRecipeId && data.id) this.currentRecipeId = data.id;
                        this.showToast('Recept opgeslagen!');
                    }
                } catch (e) { console.error(e); }
                this.saving = false;
            },

            async loadSavedRecipes() {
                try {
                    const res = await fetch('../../api/baker-recipes.php');
                    const data = await res.json();
                    if (data.success) this.savedRecipes = data.recipes;
                } catch (e) { console.error(e); }
            },

            async loadRecipe(id) {
                try {
                    const res = await fetch('../../api/baker-recipes.php?id=' + id);
                    const data = await res.json();
                    if (data.success) {
                        this.currentRecipeId = data.recipe.id;
                        this.recipeName = data.recipe.name;
                        this.doughTypeId = data.recipe.dough_type_id;
                        this.applyRecipeData(data.recipe.recipe_data);
                        this.calculatorActive = true;
                        this.activeTab = 'recept';
                        this.showToast('Recept geladen!');
                    }
                } catch (e) { console.error(e); }
            },

            async deleteRecipe(id) {
                if (!confirm('Weet je zeker dat je dit recept wilt verwijderen?')) return;
                try {
                    await fetch('../../api/baker-recipes.php?id=' + id, { method: 'DELETE' });
                    if (this.currentRecipeId === id) { this.currentRecipeId = null; }
                    this.loadSavedRecipes();
                    this.showToast('Recept verwijderd');
                } catch (e) { console.error(e); }
            },

            duplicateRecipe() {
                this.currentRecipeId = null;
                this.recipeName = this.recipeName + ' (kopie)';
                this.showToast('Recept gedupliceerd - pas aan en sla op');
            },

            toggleGroup(groupId) {
                const key = groupId === null ? '__uncategorized' : groupId;
                this.collapsedGroups[key] = !this.collapsedGroups[key];
            },

            isGroupCollapsed(groupId) {
                const key = groupId === null ? '__uncategorized' : groupId;
                return !!this.collapsedGroups[key];
            },

            async printRecipe() {
                const data = {
                    name: this.recipeName || 'Recept',
                    recipe_data: this.getRecipeData()
                };
                try {
                    const res = await fetch('../../api/recipe-pdf.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(data)
                    });
                    if (res.ok) {
                        const blob = await res.blob();
                        const url = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = 'Recept_' + (this.recipeName || 'Recept').replace(/[^a-zA-Z0-9]/g, '_') + '.pdf';
                        document.body.appendChild(a);
                        a.click();
                        a.remove();
                        window.URL.revokeObjectURL(url);
                        this.showToast('PDF gedownload');
                    } else {
                        this.showToast('Fout bij genereren PDF');
                    }
                } catch (e) {
                    console.error(e);
                    this.showToast('Fout bij genereren PDF');
                }
            },

            async newRecipe() {
                // Try to use the saved "Standaardrecept" as the starting template
                if (this.savedRecipes.length === 0) {
                    await this.loadSavedRecipes();
                }
                const template = this.savedRecipes.find(r => r.name.toLowerCase() === 'standaardrecept');
                if (template) {
                    await this.loadRecipe(template.id);
                    // Clear identity so it's treated as a new unsaved recipe
                    this.currentRecipeId = null;
                    this.recipeName = '';
                    this.doughTypeId = null;
                } else {
                    // No Standaardrecept found — open a blank calculator
                    this.currentRecipeId = null;
                    this.recipeName = '';
                    this.doughTypeId = null;
                    this.doughWeight = null;
                    this.hydration = null;
                    this.saltPct = null;
                    this.useSourdough = false;
                    this.useYeast = false;
                    this.usePreFerment = false;
                    this.mainDoughGrains = [];
                    this.mixins = [];
                    this.toppings = [];
                    this.method = '';
                    this.calculatorActive = true;
                    this.activeTab = 'recept';
                }
            },

            async loadIngredients() {
                try {
                    const res = await fetch('../../api/ingredients.php');
                    const data = await res.json();
                    if (data.success) {
                        this.allIngredients = data.ingredients;
                        
                        this.grainTypes = data.ingredients
                            .filter(i => i.category === 'meel')
                            .map(i => ({
                                id: i.id,
                                name: i.name,
                                pricePerKg: parseFloat(i.current_price_per_kg) || 0,
                                isWholeGrain: parseInt(i.is_whole_grain) === 1,
                                grainTypeId: i.grain_type_id ? parseInt(i.grain_type_id) : null,
                            }));
                        
                        if (this.grainTypes.length === 0) {
                            this.grainTypes = [
                                { id: 'wheat_white', name: 'Tarwe wit', pricePerKg: 0 },
                                { id: 'wheat_whole', name: 'Tarwe volkoren', pricePerKg: 0, isWholeGrain: true },
                            ];
                        }
                        
                        this.yeastTypes = data.ingredients
                            .filter(i => i.category === 'gist')
                            .map(i => ({ 
                                id: i.id, 
                                name: i.name, 
                                defaultPct: i.name.toLowerCase().includes('verse') ? 4 : 2.8,
                                pricePerKg: parseFloat(i.current_price_per_kg) || 0
                            }));
                        
                        if (this.yeastTypes.length === 0) {
                            this.yeastTypes = [
                                { id: 'fresh_yeast', name: 'Verse gist', defaultPct: 4, pricePerKg: 0 },
                                { id: 'instant_yeast', name: 'Instant gist', defaultPct: 2.8, pricePerKg: 0 },
                            ];
                        }
                        
                        const seenNames = new Set();
                        this.mixinIngredients = data.ingredients
                            .filter(i => i.category === 'mixin' || i.category === 'topping')
                            .filter(i => !seenNames.has(i.name) && seenNames.add(i.name))
                            .map(i => ({
                                id: i.id,
                                name: i.name,
                                cat: 'non-integrated',
                                pricePerKg: parseFloat(i.current_price_per_kg) || 0
                            }));

                        this.toppingIngredients = this.mixinIngredients;
                        
                        this.ingredientsLoaded = true;
                    }
                } catch(e) { console.error('Error loading ingredients:', e); }
            },

            async loadGrainTypeNames() {
                try {
                    const res = await fetch('../../api/grain-types.php');
                    const data = await res.json();
                    if (data.success) this.grainTypeNames = data.grain_types;
                } catch(e) { console.error('Error loading grain types:', e); }
            },

            async loadUtilityCosts() {
                try {
                    const currentMonth = new Date().toISOString().slice(0, 7);
                    const res = await fetch(`../../api/utility-costs.php?year_month=${currentMonth}`);
                    const data = await res.json();
                    if (data.success) {
                        const pick = (obj) => obj ? (obj.cost !== null ? parseFloat(obj.cost) : (obj.estimated_cost !== null ? parseFloat(obj.estimated_cost) : 0)) : 0;
                        this.utilityCosts = { total: pick(data.costs.water) + pick(data.costs.electricity) };
                    }
                } catch(e) { console.error('Error loading utility costs:', e); }
            },

            getIngredientPrice(name) {
                const ing = this.allIngredients.find(i => i.name === name);
                return ing ? parseFloat(ing.current_price_per_kg) || 0 : 0;
            },

            getGrainPrice(grainId) {
                const grain = this.grainTypes.find(g => g.id == grainId);
                return grain ? grain.pricePerKg || 0 : 0;
            },

            calcGrainCost(grainId, weightGrams) {
                const price = this.getGrainPrice(grainId);
                return (weightGrams / 1000) * price;
            },

            calcMixinCost(ingredientName, weightGrams) {
                const price = this.getIngredientPrice(ingredientName);
                return (weightGrams / 1000) * price;
            },

            debouncedFetchFifoCosts() {
                if (this._fifoTimeout) clearTimeout(this._fifoTimeout);
                this._fifoTimeout = setTimeout(() => this.fetchFifoCosts(), 500);
            },

            async fetchFifoCosts() {
                if (!this.ingredientsLoaded) return;
                
                this.fifoLoading = true;
                const requests = [];
                const keys = [];

                const addRequest = (ingredientId, weight, keyPrefix) => {
                    if (!ingredientId || weight <= 0) return;
                    const key = `${keyPrefix}_${ingredientId}_${Math.round(weight)}`;
                    if (this.fifoCosts[key] !== undefined) return;
                    keys.push(key);
                    requests.push(
                        fetch(`../../api/inventory.php?action=calculate_cost&ingredient_id=${ingredientId}&quantity=${Math.round(weight)}`)
                            .then(r => r.json())
                            .then(data => ({ key, cost: data.success ? data.cost_calculation.total_cost : null }))
                            .catch(() => ({ key, cost: null }))
                    );
                };

                if (this.useSourdough) {
                    this.sourdoughGrains.forEach((g, i) => {
                        const weight = this.sourdoughGrainDetail(i).total;
                        addRequest(g.type, weight, 'grain');
                    });
                }
                if (this.usePreFerment) {
                    this.preFermentGrains.forEach((g, i) => {
                        const weight = this.preFermentGrainDetail(i).total;
                        addRequest(g.type, weight, 'grain');
                    });
                }
                this.mainDoughGrains.forEach((g, i) => {
                    const weight = this.mainDoughGrainDetail(i).total;
                    addRequest(g.type, weight, 'grain');
                });

                if (this.useYeast) {
                    addRequest(this.yeastType, this.yeastWeight, 'yeast');
                }

                const saltIng = this.allIngredients.find(i => i.name === 'Zout');
                if (saltIng) {
                    addRequest(saltIng.id, this.saltWeight, 'ing');
                }

                this.mixins.forEach((m, i) => {
                    if (m.ingredient && m.pct > 0) {
                        const ing = this.allIngredients.find(x => x.name === m.ingredient);
                        if (ing) addRequest(ing.id, this.mixinWeight(i), 'ing');
                    }
                });

                this.toppings.forEach((t, i) => {
                    if (t.ingredient && t.pct > 0) {
                        const ing = this.allIngredients.find(x => x.name === t.ingredient);
                        if (ing) addRequest(ing.id, this.toppingWeight(i), 'ing');
                    }
                });

                if (requests.length > 0) {
                    const results = await Promise.all(requests);
                    const newCosts = { ...this.fifoCosts };
                    results.forEach(r => {
                        if (r.cost !== null) newCosts[r.key] = r.cost;
                    });
                    this.fifoCosts = newCosts;
                }
                this.fifoLoading = false;
            },

            onDoughTypeChange(newId) {
                this.doughTypeId = newId;
                const dt = this.doughTypes.find(d => d.id == newId);
                if (dt && dt.recipe_data) {
                    const rd = dt.recipe_data;
                    if (rd.hydration !== undefined) this.hydration = rd.hydration;
                    if (rd.saltPct !== undefined) this.saltPct = rd.saltPct;
                    if (rd.useSourdough !== undefined) this.useSourdough = rd.useSourdough;
                    if (rd.sourdoughPct !== undefined) this.sourdoughPct = rd.sourdoughPct;
                    if (rd.sourdoughHydration !== undefined) this.sourdoughHydration = rd.sourdoughHydration;
                    if (rd.sourdoughGrains !== undefined) this.sourdoughGrains = JSON.parse(JSON.stringify(rd.sourdoughGrains));
                    if (rd.usePreFerment !== undefined) this.usePreFerment = rd.usePreFerment;
                    if (rd.preFermentPct !== undefined) this.preFermentPct = rd.preFermentPct;
                    if (rd.preFermentHydration !== undefined) this.preFermentHydration = rd.preFermentHydration;
                    if (rd.preFermentGrains !== undefined) this.preFermentGrains = JSON.parse(JSON.stringify(rd.preFermentGrains));
                    if (rd.mainDoughGrains !== undefined) this.mainDoughGrains = JSON.parse(JSON.stringify(rd.mainDoughGrains));
                    if (rd.useYeast !== undefined) this.useYeast = rd.useYeast;
                    if (rd.yeastType !== undefined) this.yeastType = rd.yeastType;
                    if (rd.yeastPct !== undefined) this.yeastPct = rd.yeastPct;
                }
            },

            newDoughType() {
                this.editingDoughType = {
                    id: null,
                    name: '',
                    hydration: 62,
                    saltPct: 2.6,
                    useSourdough: false,
                    sourdoughPct: 20,
                    sourdoughHydration: 100,
                    sourdoughGrains: [{ type: this.grainTypes[0]?.id ?? 'wheat_white', pct: 100 }],
                    usePreFerment: false,
                    preFermentPct: 20,
                    preFermentHydration: 100,
                    preFermentGrains: [{ type: this.grainTypes[0]?.id ?? 'wheat_white', pct: 100 }],
                    mainDoughGrains: [{ type: this.grainTypes[0]?.id ?? 'wheat_white', pct: 100 }],
                    useYeast: false,
                    yeastType: this.yeastTypes[0]?.id ?? 'instant_yeast',
                    yeastPct: 1.3,
                };
                this.doughTypeModalView = 'edit';
            },

            editDoughType(dt) {
                const rd = dt.recipe_data || {};
                this.editingDoughType = {
                    id: dt.id,
                    name: dt.name,
                    hydration: rd.hydration ?? 62,
                    saltPct: rd.saltPct ?? 2.6,
                    useSourdough: rd.useSourdough ?? false,
                    sourdoughPct: rd.sourdoughPct ?? 20,
                    sourdoughHydration: rd.sourdoughHydration ?? 100,
                    sourdoughGrains: rd.sourdoughGrains ? JSON.parse(JSON.stringify(rd.sourdoughGrains)) : [{ type: this.grainTypes[0]?.id ?? 'wheat_white', pct: 100 }],
                    usePreFerment: rd.usePreFerment ?? false,
                    preFermentPct: rd.preFermentPct ?? 20,
                    preFermentHydration: rd.preFermentHydration ?? 100,
                    preFermentGrains: rd.preFermentGrains ? JSON.parse(JSON.stringify(rd.preFermentGrains)) : [{ type: this.grainTypes[0]?.id ?? 'wheat_white', pct: 100 }],
                    mainDoughGrains: rd.mainDoughGrains ? JSON.parse(JSON.stringify(rd.mainDoughGrains)) : [{ type: this.grainTypes[0]?.id ?? 'wheat_white', pct: 100 }],
                    useYeast: rd.useYeast ?? false,
                    yeastType: rd.yeastType ?? (this.yeastTypes[0]?.id ?? 'instant_yeast'),
                    yeastPct: rd.yeastPct ?? 1.3,
                };
                this.doughTypeModalView = 'edit';
            },

            async saveDoughType() {
                const dt = this.editingDoughType;
                if (!dt || !dt.name.trim()) return;
                const recipeData = {
                    hydration: dt.hydration, saltPct: dt.saltPct,
                    useSourdough: dt.useSourdough, sourdoughPct: dt.sourdoughPct,
                    sourdoughHydration: dt.sourdoughHydration, sourdoughGrains: dt.sourdoughGrains,
                    usePreFerment: dt.usePreFerment, preFermentPct: dt.preFermentPct,
                    preFermentHydration: dt.preFermentHydration, preFermentGrains: dt.preFermentGrains,
                    mainDoughGrains: dt.mainDoughGrains,
                    useYeast: dt.useYeast, yeastType: dt.yeastType, yeastPct: dt.yeastPct,
                };
                try {
                    if (dt.id) {
                        await fetch('../../api/dough-types.php', {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: dt.id, name: dt.name, recipe_data: recipeData })
                        });
                        const idx = this.doughTypes.findIndex(d => d.id === dt.id);
                        if (idx !== -1) {
                            this.doughTypes.splice(idx, 1, { ...this.doughTypes[idx], name: dt.name, recipe_data: recipeData });
                        }
                        // If currently editing a recipe that inherits from this dough type, re-apply base fields
                        if (this.doughTypeId == dt.id && this.isInherited) {
                            this.onDoughTypeChange(this.doughTypeId);
                        }
                        this.showToast('Deegsoort bijgewerkt!');
                    } else {
                        const res = await fetch('../../api/dough-types.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ name: dt.name, recipe_data: recipeData })
                        });
                        const data = await res.json();
                        if (data.success) {
                            this.doughTypes.push({ id: data.id, name: dt.name, recipe_data: recipeData });
                            this.showToast('Deegsoort aangemaakt!');
                        }
                    }
                } catch (e) { console.error(e); }
                this.doughTypeModalView = 'list';
            },

            async deleteDoughType(id) {
                if (!confirm('Weet je zeker dat je deze deegsoort wilt verwijderen?')) return;
                try {
                    const res = await fetch('../../api/dough-types.php?id=' + id, { method: 'DELETE' });
                    const data = await res.json();
                    if (data.success) {
                        this.doughTypes = this.doughTypes.filter(dt => dt.id !== id);
                        if (this.doughTypeId === id) this.doughTypeId = null;
                        this.showToast('Deegsoort verwijderd!');
                    }
                } catch (e) { console.error(e); }
            },
        },

        mounted() {
            this.loadIngredients();
            this.loadGrainTypeNames();
            this.loadSavedRecipes();
            this.loadUtilityCosts();
        }
    }).mount('#app');
    </script>
    <script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('../sw.js', { scope: '/admin/' });
        if ('PushManager' in window && Notification.permission === 'granted') {
            navigator.serviceWorker.ready.then(async reg => {
                const sub = await reg.pushManager.getSubscription();
                if (sub) return;
                try {
                    const r = await fetch('/api/push-subscriptions.php?action=vapid-key');
                    const { publicKey } = await r.json();
                    const padding = '='.repeat((4 - publicKey.length % 4) % 4);
                    const raw = atob((publicKey + padding).replace(/-/g, '+').replace(/_/g, '/'));
                    const key = Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
                    const newSub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
                    const j = newSub.toJSON();
                    await fetch('/api/push-subscriptions.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: j.endpoint, keys: { p256dh: j.keys.p256dh, auth: j.keys.auth } }) });
                } catch (e) {}
            });
        }
    }
    </script>
</body>
</html>
