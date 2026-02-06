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
        .tabs { display: flex; gap: 0.25rem; border-bottom: 2px solid #e0d5c7; margin-bottom: 1.5rem; overflow-x: auto; }
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
        .recipe-list li { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid #f0f0f0; }
        .recipe-list li:last-child { border-bottom: none; }
        .recipe-info { flex: 1; }
        .recipe-info h4 { color: #5c3d1e; font-size: 0.95rem; margin-bottom: 0.2rem; }
        .recipe-info small { color: #999; font-size: 0.8rem; }
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
        <div class="top-bar">
            <input type="text" v-model="recipeName" class="recipe-name-input" placeholder="Receptnaam...">
            <button class="btn btn-success" @click="saveRecipe" :disabled="saving"><i class="bi bi-save"></i> {{ currentRecipeId ? 'Opslaan' : 'Bewaar' }}</button>
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

                <div v-show="activeTab==='recept'">
                    <div class="panel">
                        <div class="panel-title"><i class="bi bi-gear"></i> Basisrecept</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Aantal bollen / broden</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="numberOfBalls" class="form-input" min="1" step="1">
                                    <span class="input-unit">st</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Gewicht per stuk (incl. zout)</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="weightPerBall" class="form-input" min="1" step="10">
                                    <span class="input-unit">g</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Hydratatie</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="hydration" class="form-input" min="30" max="120" step="1">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Zout</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="saltPct" class="form-input" min="0" max="10" step="0.1">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                        </div>
                        <hr class="divider">
                        <div class="panel-title"><i class="bi bi-droplet"></i> Rijsmiddelen</div>
                        <div class="toggle-row">
                            <div class="toggle" :class="{on: useSourdough}" @click="useSourdough = !useSourdough"></div>
                            <span class="toggle-label">Zuurdesem</span>
                        </div>
                        <div class="form-grid" v-if="useSourdough" style="margin-bottom:1rem">
                            <div class="form-group">
                                <label class="form-label">Percentage (baker's %)</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="sourdoughPct" class="form-input" min="0" max="100" step="0.5">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Hydratatie zuurdesem</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="sourdoughHydration" class="form-input" min="50" max="200" step="1">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                        </div>
                        <div class="toggle-row" style="margin-top:0.5rem">
                            <div class="toggle" :class="{on: useYeast}" @click="useYeast = !useYeast"></div>
                            <span class="toggle-label">Gist</span>
                        </div>
                        <div class="form-grid" v-if="useYeast">
                            <div class="form-group">
                                <label class="form-label">Type</label>
                                <select v-model="yeastType" class="form-select">
                                    <option v-for="y in yeastTypes" :value="y.id">{{ y.name }}</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Percentage</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="yeastPct" class="form-input" min="0" max="10" step="0.1">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-show="activeTab==='meel'">
                    <div class="panel" v-if="useSourdough">
                        <div class="panel-title"><i class="bi bi-fire"></i> Zuurdesem meelsoorten</div>
                        <div class="grain-row" v-for="(grain, i) in sourdoughGrains" :key="'sd'+i">
                            <div class="form-group">
                                <select v-model="grain.type" class="form-select">
                                    <option v-for="g in grainTypes" :value="g.id">{{ g.name }}</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="grain.pct" class="form-input" min="0" max="100" step="1" placeholder="Aandeel">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                            <button class="btn-remove" @click="sourdoughGrains.splice(i,1)" v-if="sourdoughGrains.length > 1"><i class="bi bi-x"></i></button>
                            <span class="weight-tag">{{ formatW(sourdoughGrainDetail(i).total) }}g</span>
                        </div>
                        <button class="btn-add" @click="sourdoughGrains.push({type:'wheat',pct:0})" v-if="sourdoughGrains.length < 5">
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
                                    <input type="number" v-model.number="preFermentPct" class="form-input" min="1" max="100" step="1">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Voordeeg hydratatie</label>
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="preFermentHydration" class="form-input" min="50" max="200" step="1">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                        </div>
                        <label class="form-label" style="margin-bottom:0.5rem;display:block">Meelsoorten in voordeeg</label>
                        <div class="grain-row" v-for="(grain, i) in preFermentGrains" :key="'pf'+i">
                            <div class="form-group">
                                <select v-model="grain.type" class="form-select">
                                    <option v-for="g in grainTypes" :value="g.id">{{ g.name }}</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="grain.pct" class="form-input" min="0" max="100" step="1" placeholder="Aandeel">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                            <button class="btn-remove" @click="preFermentGrains.splice(i,1)" v-if="preFermentGrains.length > 1"><i class="bi bi-x"></i></button>
                            <span class="weight-tag">{{ formatW(preFermentGrainDetail(i).total) }}g</span>
                        </div>
                        <button class="btn-add" @click="preFermentGrains.push({type:'wheat',pct:0})" v-if="preFermentGrains.length < 5">
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
                                <div class="toggle" :class="{on: usePreFerment}" @click="usePreFerment = !usePreFerment"></div>
                            </div>
                        </div>
                        <label class="form-label" style="margin-bottom:0.5rem;display:block">Meelsoorten in hoofddeeg</label>
                        <div class="grain-row" v-for="(grain, i) in mainDoughGrains" :key="'md'+i">
                            <div class="form-group">
                                <select v-model="grain.type" class="form-select">
                                    <option v-for="g in grainTypes" :value="g.id">{{ g.name }}</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="grain.pct" class="form-input" min="0" max="100" step="1" placeholder="Aandeel">
                                    <span class="input-unit">%</span>
                                </div>
                            </div>
                            <button class="btn-remove" @click="mainDoughGrains.splice(i,1)" v-if="mainDoughGrains.length > 1"><i class="bi bi-x"></i></button>
                            <span class="weight-tag">{{ formatW(mainDoughGrainDetail(i).total) }}g</span>
                        </div>
                        <button class="btn-add" @click="mainDoughGrains.push({type:'wheat',pct:0})" v-if="mainDoughGrains.length < 5">
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

                <div v-show="activeTab==='toevoegingen'">
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
                        <datalist id="mixin-suggestions">
                            <option v-for="ing in mixinIngredients" :value="ing.name"></option>
                        </datalist>
                        <div class="mixin-row" v-for="(m, i) in mixins" :key="'mx'+i">
                            <div class="form-group">
                                <input type="text" v-model="m.ingredient" class="form-input" list="mixin-suggestions" placeholder="Ingrediënt..." @input="autoCategory(m)">
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
                        <datalist id="topping-suggestions">
                            <option v-for="ing in toppingIngredients" :value="ing"></option>
                        </datalist>
                        <div class="topping-row" v-for="(t, i) in toppings" :key="'tp'+i">
                            <div class="form-group">
                                <input type="text" v-model="t.ingredient" class="form-input" list="topping-suggestions" placeholder="Topping...">
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

                <div v-show="activeTab==='overzicht'">
                    <div class="panel">
                        <div class="panel-title"><i class="bi bi-list-check"></i> Recept Overzicht</div>
                        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:1.5rem">
                            <div><span class="form-label">Bollen/broden</span><br><span class="calc-value">{{ numberOfBalls }}</span></div>
                            <div><span class="form-label">Gewicht per stuk</span><br><span class="calc-value">{{ formatW(finalWeightPerBall) }}<span class="calc-unit">g</span></span></div>
                            <div><span class="form-label">Totaal gewicht</span><br><span class="calc-value">{{ formatW(totalFinalWeight) }}<span class="calc-unit">g</span></span></div>
                            <div><span class="form-label">Hydratatie</span><br><span class="calc-value">{{ formatP(effectiveTotalHydration) }}<span class="calc-unit">%</span></span></div>
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
                        </div>

                        <hr class="divider">
                        <div class="panel-title"><i class="bi bi-percent"></i> Baker's Percentages</div>
                        <table class="bp-table">
                            <thead><tr><th>Ingrediënt</th><th>Gewicht</th><th>Baker's %</th></tr></thead>
                            <tbody>
                                <tr><td>Totaal meel</td><td>{{ formatW(totalFlour) }}g</td><td>100%</td></tr>
                                <tr><td>Water</td><td>{{ formatW(totalWater) }}g</td><td>{{ formatP(hydration) }}%</td></tr>
                                <tr><td>Zout</td><td>{{ formatW(saltWeight) }}g</td><td>{{ formatP(saltWeight / totalFlour * 100) }}%</td></tr>
                                <tr v-if="useSourdough"><td>Zuurdesem</td><td>{{ formatW(sourdoughWeight) }}g</td><td>{{ formatP(sourdoughPct) }}%</td></tr>
                                <tr v-if="useYeast"><td>{{ yeastName }}</td><td>{{ formatW(yeastWeight) }}g</td><td>{{ formatP(yeastPct) }}%</td></tr>
                                <template v-for="(m, i) in mixins" :key="'bp'+i"><tr v-if="m.ingredient && m.pct > 0">
                                    <td>{{ m.ingredient }}</td><td>{{ formatW(mixinWeight(i)) }}g</td><td>{{ formatP(mixinWeight(i) / totalFlour * 100) }}%</td>
                                </tr></template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-show="activeTab==='methode'">
                    <div class="panel">
                        <div class="panel-title"><i class="bi bi-journal-text"></i> Bereidingswijze</div>
                        <textarea v-model="method" class="method-textarea" placeholder="Beschrijf hier je bereidingswijze, tijden, temperaturen..."></textarea>
                    </div>
                </div>

                <div v-show="activeTab==='recepten'">
                    <div class="panel">
                        <div class="panel-title"><i class="bi bi-bookmark"></i> Opgeslagen Recepten</div>
                        <div v-if="savedRecipes.length === 0" class="empty-state">
                            <i class="bi bi-bookmark-star"></i>
                            <p>Nog geen recepten opgeslagen</p>
                        </div>
                        <ul class="recipe-list" v-else>
                            <li v-for="r in savedRecipes" :key="r.id">
                                <div class="recipe-info">
                                    <h4>{{ r.name }}</h4>
                                    <small>{{ formatDate(r.updated_at) }}</small>
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

            <div class="calc-sidebar">
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
                            <span class="summary-label">Zout</span>
                            <span class="summary-value">{{ formatW(saltWeight) }}g</span>
                        </div>
                        <div class="summary-section-title" v-if="useSourdough">Zuurdesem</div>
                        <div class="summary-row" v-if="useSourdough">
                            <span class="summary-label">Meel in zuurdesem</span>
                            <span class="summary-value">{{ formatW(sourdoughFlour) }}g</span>
                        </div>
                        <div class="summary-row" v-if="useSourdough">
                            <span class="summary-label">Water in zuurdesem</span>
                            <span class="summary-value">{{ formatW(sourdoughWater) }}g</span>
                        </div>

                        <div class="summary-row" v-if="useYeast">
                            <span class="summary-label">{{ yeastName }}</span>
                            <span class="summary-value">{{ formatW(yeastWeight) }}g</span>
                        </div>

                        <div class="summary-section-title" v-if="usePreFerment">Voordeeg</div>
                        <div class="summary-row" v-if="usePreFerment">
                            <span class="summary-label">Meel in voordeeg</span>
                            <span class="summary-value">{{ formatW(preFermentFlour) }}g</span>
                        </div>
                        <div class="summary-row" v-if="usePreFerment">
                            <span class="summary-label">Water in voordeeg</span>
                            <span class="summary-value">{{ formatW(preFermentWater) }}g</span>
                        </div>

                        <div class="summary-section-title" v-if="totalMixinWeight > 0">Mix-ins</div>
                        <div class="summary-row" v-if="integratedMixinWeight > 0">
                            <span class="summary-label">Geïntegreerd</span>
                            <span class="summary-value">{{ formatW(integratedMixinWeight) }}g</span>
                        </div>
                        <div class="summary-row" v-if="nonIntegratedMixinWeight > 0">
                            <span class="summary-label">Vast</span>
                            <span class="summary-value">{{ formatW(nonIntegratedMixinWeight) }}g</span>
                        </div>
                        <div class="summary-row" v-if="liquidMixinWeight > 0">
                            <span class="summary-label">Vloeistof</span>
                            <span class="summary-value">{{ formatW(liquidMixinWeight) }}g</span>
                        </div>
                        <div class="summary-row" v-if="totalToppingWeight > 0">
                            <span class="summary-label">Toppings</span>
                            <span class="summary-value">{{ formatW(totalToppingWeight) }}g</span>
                        </div>

                        <div class="summary-row total">
                            <span class="summary-label" style="font-weight:700;color:#5c3d1e">Per stuk</span>
                            <span class="summary-value accent">{{ formatW(finalWeightPerBall) }}g</span>
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
                activeTab: 'recept',
                recipeName: '',
                currentRecipeId: null,
                numberOfBalls: 24,
                weightPerBall: 300,
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
                saving: false,
                toastMsg: '',
                grainTypes: [
                    { id: 'wheat_white', name: 'Tarwe wit' },
                    { id: 'wheat_whole', name: 'Tarwe volkoren' },
                    { id: 'spelt_white', name: 'Spelt wit' },
                    { id: 'spelt_whole', name: 'Spelt volkoren' },
                    { id: 'durum', name: 'Durum' },
                    { id: 'emmer', name: 'Emmer' },
                    { id: 'rye_white', name: 'Rogge wit' },
                    { id: 'rye_whole', name: 'Rogge volkoren' },
                    { id: 'einkorn', name: 'Einkorn' },
                    { id: 'buckwheat', name: 'Boekweit' },
                    { id: 'rice', name: 'Rijst' },
                    { id: 'barley', name: 'Gerst' },
                    { id: 'teff', name: 'Teff' },
                ],
                yeastTypes: [
                    { id: 'fresh_yeast', name: 'Verse gist', defaultPct: 4 },
                    { id: 'instant_yeast', name: 'Instant gist', defaultPct: 2.8 },
                    { id: 'sourdough_culture', name: 'Desemcultuur', defaultPct: 4 },
                ],
                mixinIngredients: [
                    { name: 'Zonnebloempitten', cat: 'non-integrated' },
                    { name: 'Pompoenpitten', cat: 'non-integrated' },
                    { name: 'Maanzaad', cat: 'non-integrated' },
                    { name: 'Sesamzaad', cat: 'non-integrated' },
                    { name: 'Walnoten', cat: 'non-integrated' },
                    { name: 'Pecannoten', cat: 'non-integrated' },
                    { name: 'Amandelen', cat: 'non-integrated' },
                    { name: 'Hazelnoten', cat: 'non-integrated' },
                    { name: 'Pijnboompitten', cat: 'non-integrated' },
                    { name: 'Rozijnen', cat: 'non-integrated' },
                    { name: 'Gedroogde cranberries', cat: 'non-integrated' },
                    { name: 'Gedroogde abrikozen', cat: 'non-integrated' },
                    { name: 'Gedroogde vijgen', cat: 'non-integrated' },
                    { name: 'Dadels', cat: 'non-integrated' },
                    { name: 'Knoflook', cat: 'non-integrated' },
                    { name: 'Uien', cat: 'non-integrated' },
                    { name: 'Kruiden', cat: 'non-integrated' },
                    { name: 'Olijven (gehakt)', cat: 'non-integrated' },
                    { name: 'Kaneel', cat: 'non-integrated' },
                    { name: 'Nootmuskaat', cat: 'non-integrated' },
                    { name: 'Karwijzaad', cat: 'non-integrated' },
                    { name: 'Anijszaad', cat: 'non-integrated' },
                    { name: 'Zwarte peper', cat: 'non-integrated' },
                    { name: 'Olijfolie', cat: 'non-integrated' },
                    { name: 'Lijnzaad', cat: 'integrated' },
                    { name: 'Chiazaad', cat: 'integrated' },
                    { name: 'Quinoa', cat: 'integrated' },
                    { name: 'Gierst', cat: 'integrated' },
                    { name: 'Havervlokken', cat: 'integrated' },
                    { name: 'Aardappelzetmeel', cat: 'integrated' },
                    { name: 'Psyllium husk', cat: 'integrated' },
                    { name: 'Cacaopoeder', cat: 'integrated' },
                    { name: 'Koffiedik', cat: 'integrated' },
                    { name: 'Bietenpoeder', cat: 'integrated' },
                    { name: 'Spinaziepoeder', cat: 'integrated' },
                    { name: 'Boter', cat: 'integrated' },
                    { name: 'Kaas', cat: 'integrated' },
                    { name: 'Ei', cat: 'integrated' },
                    { name: 'Suiker', cat: 'integrated' },
                    { name: 'Agavesiroop', cat: 'integrated' },
                    { name: 'Ahornsiroop', cat: 'integrated' },
                    { name: 'Melasse', cat: 'integrated' },
                    { name: 'Water (extra)', cat: 'liquid' },
                    { name: 'Melk', cat: 'liquid' },
                    { name: 'Karnemelk', cat: 'liquid' },
                    { name: 'Koffie', cat: 'liquid' },
                    { name: 'Bier', cat: 'liquid' },
                    { name: 'Appelciderazijn', cat: 'liquid' },
                ],
                toppingIngredients: [
                    'Zadenmix', 'Zonnebloempitten', 'Pompoenpitten', 'Lijnzaad', 'Chiazaad',
                    'Maanzaad', 'Sesamzaad', 'Havervlokken', 'Walnoten', 'Amandelen',
                    'Rozijnen', 'Knoflook', 'Uien', 'Kruiden', 'Olijven',
                    'Zeezoutvlokken', 'Zwarte peper', 'Chilivlokken', 'Kaneelsuiker',
                    'Grove suiker', 'Tomaten', 'Cherrytomaatjes', 'Ansjovis',
                ],
            };
        },

        computed: {
            totalDoughWeight() { return this.numberOfBalls * this.weightPerBall; },
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
            saltWeight() { return this.totalFlour * (this.saltPct / 100); },

            totalToppingWeight() {
                return this.toppings.reduce((s, t) => s + this.totalDoughWeight * (t.pct / 100), 0);
            },

            totalFinalWeight() {
                return this.totalDoughWeight + this.yeastWeight + this.totalMixinWeight + this.totalToppingWeight;
            },
            finalWeightPerBall() {
                if (this.numberOfBalls === 0) return 0;
                return this.totalFinalWeight / this.numberOfBalls;
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
        },

        watch: {
            yeastType(val) {
                const t = this.yeastTypes.find(y => y.id === val);
                if (t && t.defaultPct) this.yeastPct = t.defaultPct;
            }
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

            formatW(v) { return Math.round(v).toLocaleString('nl-NL'); },
            formatP(v) { return (Math.round(v * 10) / 10).toString().replace('.', ','); },
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
                    numberOfBalls: this.numberOfBalls,
                    weightPerBall: this.weightPerBall,
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
                const fields = ['numberOfBalls','weightPerBall','hydration','saltPct',
                    'useSourdough','sourdoughPct','sourdoughHydration','sourdoughGrains',
                    'useYeast','yeastType','yeastPct',
                    'usePreFerment','preFermentPct','preFermentHydration',
                    'preFermentGrains','mainDoughGrains','mixinMode','mixins','toppings','method'];
                fields.forEach(f => { if (d[f] !== undefined) this[f] = d[f]; });
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
                    const body = { name: this.recipeName, recipe_data: this.getRecipeData() };
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
                        this.applyRecipeData(data.recipe.recipe_data);
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

            newRecipe() {
                this.currentRecipeId = null;
                this.recipeName = '';
                this.numberOfBalls = 24;
                this.weightPerBall = 300;
                this.hydration = 62;
                this.saltPct = 2.6;
                this.useSourdough = false;
                this.sourdoughPct = 20;
                this.sourdoughHydration = 100;
                this.sourdoughGrains = [{ type: 'wheat', pct: 100 }];
                this.useYeast = true;
                this.yeastType = 'instant_yeast';
                this.yeastPct = 1.3;
                this.usePreFerment = false;
                this.preFermentPct = 20;
                this.preFermentHydration = 100;
                this.preFermentGrains = [{ type: 'wheat_white', pct: 100 }];
                this.mainDoughGrains = [{ type: 'wheat_white', pct: 100 }];
                this.mixinMode = 'flour';
                this.mixins = [];
                this.toppings = [];
                this.method = '';
                this.activeTab = 'recept';
            },
        },

        mounted() {
            this.loadSavedRecipes();
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
