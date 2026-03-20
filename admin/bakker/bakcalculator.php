<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$adminPageTitle = 'Recepten';
$currentPage = 'bakcalculator';
$adminBasePath = '../';

try {
    $doughTypes = $pdo->query("SELECT id, name, recipe_data FROM dough_types ORDER BY sort_order ASC, name ASC")->fetchAll();
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
ob_start(); ?>
<style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        [v-cloak] { display: none; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--cream); min-height: 100vh; }
        .admin-content { padding: 0; max-width: 1200px; }

        /* ── Monospace for numbers ── */
        .calc-value, .summary-value, .weight-tag, .overview-item .value, .overview-total span:last-child,
        .bp-table td:last-child, .input-unit { font-variant-numeric: tabular-nums; font-feature-settings: "tnum"; }
        /* ── Toolbar ── */
        .top-bar { display: flex; gap: 0.5rem; align-items: center; padding: 0.75rem 1.5rem; background: #fff; border-bottom: 1px solid #e5e7eb; flex-wrap: wrap; }
        .recipe-name-group { flex: 1; min-width: 180px; display: flex; flex-direction: column; gap: 0.2rem; }
        .recipe-name-input { width: 100%; padding: 0.45rem 0.75rem; border: 1px solid #d1d5db; border-radius: 4px; font-size: 1rem; font-weight: 600; color: #1f2937; background: #f9fafb; }
        .recipe-name-input:focus { outline: none; border-color: #c8913a; background: #fff; box-shadow: 0 0 0 2px rgba(200,145,58,0.15); }
        .recipe-name-input::placeholder { color: #9ca3af; font-weight: 400; }
        .recipe-desc-input { width: 100%; padding: 0.3rem 0.75rem; border: 1px solid #e5e7eb; border-radius: 4px; font-size: 0.8rem; color: #6b7280; background: #f9fafb; }
        .recipe-desc-input:focus { outline: none; border-color: #c8913a; background: #fff; box-shadow: 0 0 0 2px rgba(200,145,58,0.15); }
        .recipe-desc-input::placeholder { color: #d1d5db; }
        .dough-type-select { display: flex; gap: 0.25rem; align-items: center; }
        .form-select-sm { padding: 0.45rem 0.6rem; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.85rem; background: #f9fafb; color: #1f2937; min-width: 130px; }
        .form-select-sm:focus { outline: none; border-color: #c8913a; box-shadow: 0 0 0 2px rgba(200,145,58,0.15); }
        .btn-icon { width: 32px; height: 32px; border: 1px solid #d1d5db; border-radius: 4px; background: #f9fafb; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b7280; font-size: 0.85rem; }
        .btn-icon:hover { border-color: #c8913a; color: #c8913a; background: #fff; }
        /* ── Modal ── */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(2px); }
        .modal-content { background: white; border-radius: 8px; width: 90%; max-width: 400px; box-shadow: 0 20px 60px rgba(0,0,0,0.15), 0 0 0 1px rgba(0,0,0,0.05); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 0.875rem 1.25rem; border-bottom: 1px solid #e5e7eb; }
        .modal-header h3 { font-size: 0.95rem; color: #1f2937; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
        .modal-close { background: none; border: none; font-size: 1.3rem; color: #9ca3af; cursor: pointer; line-height: 1; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border-radius: 4px; }
        .modal-close:hover { color: #ef4444; background: #fef2f2; }
        .modal-body { padding: 1.25rem; }
        .dough-type-list { max-height: 250px; overflow-y: auto; margin-bottom: 1rem; }
        .dough-type-item { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0.75rem; border-radius: 4px; background: #f9fafb; border: 1px solid #f3f4f6; margin-bottom: 0.375rem; }
        .dough-type-item span { font-weight: 500; color: #1f2937; font-size: 0.9rem; }
        .btn-icon-danger { width: 26px; height: 26px; border: none; border-radius: 4px; background: transparent; cursor: pointer; color: #9ca3af; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; }
        .btn-icon-danger:hover { background: #fef2f2; color: #ef4444; }
        .empty-msg { text-align: center; color: #9ca3af; padding: 1rem; font-size: 0.85rem; }
        .add-dough-type { display: flex; gap: 0.5rem; }
        .add-dough-type .form-input { flex: 1; }
        /* ── Buttons ── */
        .btn { padding: 0.45rem 0.875rem; border: none; border-radius: 4px; font-size: 0.8rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem; transition: all 0.15s; letter-spacing: 0.01em; }
        .btn-primary { background: #2563eb; color: white; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-success { background: #2563eb; color: white; }
        .btn-success:hover { background: #1d4ed8; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-ghost { background: transparent; color: #374151; border: 1px solid #d1d5db; }
        .btn-ghost:hover { border-color: #9ca3af; background: #f9fafb; }
        .btn-sm { padding: 0.3rem 0.6rem; font-size: 0.75rem; }
        /* ── Tabs ── */
        .tabs { display: flex; gap: 0; border-bottom: 1px solid #e5e7eb; margin: 0; padding: 0 1.5rem; background: #fff; overflow-x: auto; scrollbar-width: none; }
        .tabs::-webkit-scrollbar { display: none; }
        .tab { padding: 0.6rem 1rem; cursor: pointer; font-weight: 500; color: #6b7280; border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap; transition: all 0.15s; user-select: none; font-size: 0.82rem; display: flex; align-items: center; gap: 0.35rem; }
        .tab:hover { color: #1f2937; background: #f9fafb; }
        .tab.active { color: #c8913a; border-bottom-color: #c8913a; font-weight: 600; }
        /* ── Layout ── */
        .layout { display: grid; grid-template-columns: 1fr 320px; gap: 1.25rem; align-items: start; padding: 1.25rem 1.5rem; }
        @media (max-width: 900px) { .layout { grid-template-columns: 1fr; padding: 1rem; } }
        /* ── Panel ── */
        .panel { background: white; border-radius: 6px; border: 1px solid #e5e7eb; padding: 1.25rem; margin-bottom: 1.25rem; }
        .panel-title { font-size: 0.8rem; font-weight: 700; color: #374151; margin-bottom: 0.875rem; text-transform: uppercase; letter-spacing: 0.04em; }
        /* ── Forms ── */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.875rem; }
        @media (max-width: 500px) { .form-grid { grid-template-columns: 1fr; } }
        .form-group { display: flex; flex-direction: column; gap: 0.25rem; }
        .form-group.full { grid-column: 1 / -1; }
        .form-label { font-size: 0.7rem; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; }
        .form-input, .form-select { padding: 0.45rem 0.6rem; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.9rem; color: #1f2937; background: white; }
        .form-input:focus, .form-select:focus { outline: none; border-color: #c8913a; box-shadow: 0 0 0 2px rgba(200,145,58,0.12); }
        .form-input[type="number"] { -moz-appearance: textfield; font-variant-numeric: tabular-nums; }
        .form-input[type="number"]::-webkit-inner-spin-button { opacity: 1; }
        .input-with-unit { display: flex; align-items: stretch; }
        .input-with-unit .form-input { border-top-right-radius: 0; border-bottom-right-radius: 0; flex: 1; min-width: 0; }
        .input-unit { padding: 0.45rem 0.5rem; background: #f3f4f6; border: 1px solid #d1d5db; border-left: none; border-radius: 0 4px 4px 0; font-size: 0.8rem; color: #6b7280; font-weight: 600; display: flex; align-items: center; }
        .calc-value { font-size: 1.2rem; font-weight: 700; color: #c8913a; font-variant-numeric: tabular-nums; }
        .calc-unit { font-size: 0.8rem; color: #9ca3af; font-weight: 400; margin-left: 0.15rem; }
        .divider { border: none; border-top: 1px solid #f3f4f6; margin: 1.25rem 0; }
        /* ── Grain/mixin rows ── */
        .grain-row, .mixin-row, .topping-row { display: flex; gap: 0.5rem; align-items: end; margin-bottom: 0.625rem; flex-wrap: wrap; }
        .grain-row .form-group, .mixin-row .form-group, .topping-row .form-group { flex: 1; min-width: 100px; }
        .grain-row .form-group:first-child, .mixin-row .form-group:first-child, .topping-row .form-group:first-child { flex: 2; min-width: 150px; }
        .btn-remove { width: 32px; height: 32px; padding: 0; display: flex; align-items: center; justify-content: center; border-radius: 4px; background: transparent; color: #9ca3af; border: 1px solid #e5e7eb; cursor: pointer; flex-shrink: 0; margin-bottom: 0; font-size: 0.85rem; }
        .btn-remove:hover { background: #fef2f2; color: #ef4444; border-color: #fecaca; }
        .btn-add { width: 100%; padding: 0.4rem; border: 1px dashed #d1d5db; border-radius: 4px; background: transparent; color: #9ca3af; cursor: pointer; font-size: 0.8rem; display: flex; align-items: center; justify-content: center; gap: 0.3rem; }
        .btn-add:hover { border-color: #c8913a; color: #c8913a; background: #fffbf5; }
        .weight-tag { display: inline-flex; align-items: center; padding: 0.15rem 0.45rem; background: #f3f4f6; border-radius: 3px; font-size: 0.75rem; font-weight: 700; color: #374151; font-variant-numeric: tabular-nums; }
        /* ── Toggle ── */
        .toggle-row { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.875rem; }
        .toggle { position: relative; width: 40px; height: 22px; background: #d1d5db; border-radius: 11px; cursor: pointer; transition: background 0.2s; flex-shrink: 0; }
        .toggle.on { background: #c8913a; }
        .toggle::after { content: ''; position: absolute; width: 18px; height: 18px; background: white; border-radius: 50%; top: 2px; left: 2px; transition: transform 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.15); }
        .toggle.on::after { transform: translateX(18px); }
        .toggle-label { font-weight: 600; color: #1f2937; font-size: 0.875rem; }
        /* ── Sidebar ── */
        .calc-sidebar { position: sticky; top: 1.5rem; }
        .summary-card { background: white; border-radius: 6px; border: 1px solid #e5e7eb; overflow: hidden; }
        .summary-header { background: #1f2937; color: white; padding: 0.75rem 1rem; border-bottom: 2px solid #c8913a; }
        .summary-header h3 { font-size: 0.8rem; display: flex; align-items: center; gap: 0.4rem; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; }
        .summary-body { padding: 0.875rem 1rem; }
        .summary-row { display: flex; justify-content: space-between; align-items: center; padding: 0.35rem 0; }
        .summary-row.total { border-top: 1px solid #e5e7eb; margin-top: 0.5rem; padding-top: 0.625rem; }
        .summary-label { font-size: 0.8rem; color: #6b7280; }
        .summary-value { font-weight: 700; color: #1f2937; font-size: 0.85rem; font-variant-numeric: tabular-nums; }
        .summary-value.accent { color: #c8913a; font-size: 1rem; }
        .summary-section-title { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.06em; color: #9ca3af; font-weight: 700; margin-top: 0.625rem; margin-bottom: 0.2rem; }
        .pct-bar { height: 4px; background: #f3f4f6; border-radius: 2px; margin-top: 0.625rem; overflow: hidden; display: flex; }
        .pct-bar-fill { height: 100%; transition: width 0.3s; }
        .pct-bar-flour { background: #c8913a; }
        .pct-bar-water { background: #3b82f6; }
        .pct-bar-other { background: #22c55e; }
        /* ── Recipe list (landing) ── */
        .recipes-view { padding: 1.25rem 1.5rem; }
        .recipes-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; }
        .recipes-header h2 { font-size: 1.1rem; font-weight: 700; color: #1f2937; display: flex; align-items: center; gap: 0.5rem; }
        .recipes-header h2 i { color: #c8913a; }
        .recipes-header-actions { display: flex; gap: 0.5rem; }
        .recipe-table { width: 100%; border-collapse: collapse; background: white; border: 1px solid #e8dfd2; border-radius: 6px; overflow: hidden; }
        .recipe-table thead tr { background: #f5f0e8; border-bottom: 2px solid #e8e0d5; }
        .recipe-table th { padding: 0.5rem 0.875rem; text-align: left; font-size: 0.72rem; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 0.04em; white-space: nowrap; }
        .recipe-table td { padding: 0.625rem 0.875rem; border-bottom: 1px solid #f0ebe5; font-size: 0.85rem; color: #333; vertical-align: middle; }
        .recipe-table tbody tr:last-child td { border-bottom: none; }
        .recipe-table tbody tr.recipe-row:hover { background: #faf8f5; cursor: pointer; }
        .recipe-table-name { font-weight: 600; color: #2d4a2d; display: flex; align-items: center; gap: 0.35rem; }
        .recipe-table-desc { color: #888; font-size: 0.8rem; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .recipe-table-date { color: #888; font-size: 0.8rem; white-space: nowrap; }
        .recipe-table-actions { width: 40px; text-align: right; padding-right: 0.5rem !important; }
        .recipe-group-row td { padding: 0.35rem 0.875rem; background: #f5f0e8; font-size: 0.72rem; font-weight: 700; color: #2d4a2d; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 1px solid #e8dfd2; cursor: pointer; user-select: none; }
        .recipe-group-row:hover td { background: #ede8e0 !important; }
        .recipe-group-chevron { display: inline-flex; align-items: center; margin-right: 0.35rem; font-size: 0.75rem; color: #a09080; transition: transform 0.15s; }
        .recipe-group-chevron.collapsed { transform: rotate(-90deg); }
        .deegsoort-badge { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.3rem 0.7rem; background: #f0fdf4; border: 1px solid #86efac; border-radius: 4px; font-size: 0.8rem; font-weight: 600; color: #166534; }
        .deegsoort-badge i { color: #16a34a; }
        .btn-is-deegsoort { font-size: 0.75rem; color: #6b7280; border: 1px dashed #d1d5db; background: transparent; border-radius: 4px; padding: 0.3rem 0.6rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.3rem; }
        .btn-is-deegsoort:hover { border-color: #16a34a; color: #166534; background: #f0fdf4; }
        .recipe-table .is-dough-type-icon { color: #16a34a; font-size: 0.8rem; margin-left: 0.25rem; }
        .recipe-group-count { display: inline-flex; align-items: center; justify-content: center; min-width: 1.2rem; height: 1.2rem; background: #e0d5c7; color: #2d4a2d; border-radius: 10px; font-size: 0.65rem; font-weight: 700; padding: 0 0.3rem; margin-left: 0.4rem; }
        .drag-handle { color: #c8bfb5; cursor: grab; padding: 0 0.35rem 0 0; font-size: 0.9rem; display: inline-flex; align-items: center; }
        .drag-handle:active { cursor: grabbing; }
        .recipe-group-row.drag-over td { background: #dbeafe !important; }
        .recipe-row.drag-over td { background: #dbeafe !important; }
        .recipe-table td.drag-cell { width: 22px; padding-right: 0 !important; }
        .recipe-actions-menu { position: relative; }
        .recipe-menu-btn { width: 30px; height: 30px; border: none; border-radius: 4px; background: transparent; cursor: pointer; color: #9ca3af; display: flex; align-items: center; justify-content: center; font-size: 1rem; }
        .recipe-menu-btn:hover { background: #f3f4f6; color: #374151; }
        .recipe-dropdown { position: absolute; right: 0; top: 100%; background: white; border: 1px solid #e5e7eb; border-radius: 6px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); z-index: 50; min-width: 180px; padding: 0.25rem 0; }
        .recipe-dropdown-item { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.875rem; font-size: 0.8rem; color: #374151; cursor: pointer; border: none; background: none; width: 100%; text-align: left; font-weight: 500; }
        .recipe-dropdown-item:hover { background: #f9fafb; }
        .recipe-dropdown-item i { font-size: 0.85rem; width: 1rem; text-align: center; color: #6b7280; }
        .recipe-dropdown-item.danger { color: #dc2626; }
        .recipe-dropdown-item.danger i { color: #dc2626; }
        .recipe-dropdown-divider { height: 1px; background: #f3f4f6; margin: 0.25rem 0; }
        .recipe-empty { text-align: center; padding: 3rem 1.5rem; color: #9ca3af; }
        .recipe-empty i { font-size: 2.5rem; display: block; margin-bottom: 0.75rem; color: #d1d5db; }
        .recipe-empty p { font-size: 0.85rem; margin-bottom: 1.25rem; }
        /* ── Back button in top-bar ── */
        .btn-back { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.4rem 0.75rem; border: 1px solid #d1d5db; border-radius: 4px; background: #f9fafb; color: #374151; font-size: 0.8rem; font-weight: 500; cursor: pointer; }
        .btn-back:hover { background: #f3f4f6; border-color: #9ca3af; }
        .btn-back i { font-size: 0.85rem; }
        /* ── Overview ── */
        .overview-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 700px) { .overview-grid { grid-template-columns: 1fr; } }
        .overview-section { background: #f9fafb; border-radius: 6px; padding: 0.875rem; border: 1px solid #f3f4f6; }
        .overview-section h4 { font-size: 0.75rem; color: #374151; margin-bottom: 0.625rem; display: flex; align-items: center; gap: 0.35rem; text-transform: uppercase; letter-spacing: 0.03em; font-weight: 700; }
        .overview-item { display: flex; justify-content: space-between; padding: 0.25rem 0; font-size: 0.85rem; }
        .overview-item .name { color: #6b7280; }
        .overview-item .value { font-weight: 600; color: #1f2937; font-variant-numeric: tabular-nums; }
        .overview-item.sub { padding-left: 0.875rem; font-size: 0.8rem; }
        .overview-item.sub .name { color: #9ca3af; }
        .overview-total { display: flex; justify-content: space-between; padding: 0.4rem 0; border-top: 1px solid #e5e7eb; margin-top: 0.4rem; font-weight: 700; color: #1f2937; font-size: 0.85rem; }
        .bp-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .bp-table th { text-align: left; padding: 0.4rem 0.5rem; color: #888; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 2px solid #e8e0d5; font-weight: 700; }
        .bp-table td { padding: 0.4rem 0.5rem; border-bottom: 1px solid #f0ebe5; color: #333; }
        .bp-table td:last-child { text-align: right; font-weight: 700; color: #c8913a; }
        .bp-table tr:last-child td { border-bottom: none; }
        /* ── Radio pills ── */
        .radio-group { display: flex; gap: 0.25rem; flex-wrap: wrap; }
        .radio-pill { padding: 0.25rem 0.6rem; border: 1px solid #d1d5db; border-radius: 3px; font-size: 0.75rem; cursor: pointer; transition: all 0.15s; user-select: none; font-weight: 500; color: #6b7280; }
        .radio-pill.active { background: #c8913a; color: white; border-color: #c8913a; }
        .radio-pill:hover:not(.active) { border-color: #c8913a; color: #c8913a; }
        .empty-state { text-align: center; padding: 2rem; color: #d1d5db; }
        .empty-state i { font-size: 2rem; display: block; margin-bottom: 0.5rem; }
        /* ── Method ── */
        .method-day { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 0.875rem; margin-bottom: 0.875rem; }
        .method-day-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.625rem; }
        .method-day-header h4 { margin: 0; font-size: 0.9rem; color: #1f2937; display: flex; align-items: center; gap: 0.35rem; }
        .method-step { display: flex; align-items: flex-start; gap: 0.4rem; margin-bottom: 0.4rem; border-radius: 4px; padding: 0.15rem; transition: background 0.15s, opacity 0.15s; }
        .method-step.dragging { opacity: 0.4; }
        .method-step.drag-over { background: #eff6ff; box-shadow: inset 0 -2px 0 #c8913a; }
        .method-step-handle { cursor: grab; color: #d1d5db; display: flex; align-items: center; padding: 0.25rem 0; margin-top: 0.3rem; font-size: 0.9rem; }
        .method-step-handle:active { cursor: grabbing; }
        .method-step-num { min-width: 1.4rem; height: 1.4rem; background: #c8913a; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.65rem; font-weight: 700; margin-top: 0.35rem; flex-shrink: 0; }
        .method-step textarea { flex: 1; padding: 0.4rem 0.6rem; border: 1px solid #d1d5db; border-radius: 4px; font-family: inherit; font-size: 0.85rem; resize: vertical; min-height: 2.2rem; color: #1f2937; }
        .method-step textarea:focus { outline: none; border-color: #c8913a; box-shadow: 0 0 0 2px rgba(200,145,58,0.12); }
        .method-add-step { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.25rem 0.6rem; font-size: 0.75rem; color: #6b7280; background: none; border: 1px dashed #d1d5db; border-radius: 4px; cursor: pointer; margin-top: 0.2rem; }
        .method-add-step:hover { background: #f9fafb; border-color: #c8913a; color: #c8913a; }
        .method-add-day { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.4rem 0.875rem; font-size: 0.8rem; color: #374151; background: none; border: 1px dashed #d1d5db; border-radius: 4px; cursor: pointer; }
        .method-add-day:hover { background: #f9fafb; border-color: #c8913a; color: #c8913a; }
        .method-apply-btn { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.3rem 0.6rem; font-size: 0.75rem; color: #2563eb; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 4px; cursor: pointer; font-weight: 500; }
        .method-apply-btn:hover { background: #dbeafe; }
        /* ── Category labels ── */
        .category-label { font-size: 0.65rem; padding: 0.1rem 0.35rem; border-radius: 3px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.02em; }
        .cat-integrated { background: #dcfce7; color: #166534; }
        .cat-non-integrated { background: #ffedd5; color: #9a3412; }
        .cat-liquid { background: #dbeafe; color: #1e40af; }
        /* ── Misc ── */
        .fade-enter-active, .fade-leave-active { transition: opacity 0.15s; }
        .fade-enter-from, .fade-leave-to { opacity: 0; }
        .toast { position: fixed; bottom: 2rem; right: 2rem; padding: 0.6rem 1.25rem; background: #1f2937; color: white; border-radius: 4px; font-size: 0.85rem; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-weight: 500; }
        .toast.success { background: #166534; }
        .grain-warning { font-size: 0.75rem; color: #ef4444; font-weight: 600; margin-top: 0.4rem; display: flex; align-items: center; gap: 0.3rem; }
        .inherited-banner { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 4px; padding: 0.5rem 0.875rem; margin-bottom: 0.875rem; display: flex; align-items: center; gap: 0.4rem; color: #2563eb; font-size: 0.8rem; }
        .inherited-banner i { flex-shrink: 0; }
        .inherited-field { background: #f3f4f6 !important; color: #9ca3af !important; border-color: #e5e7eb !important; cursor: not-allowed !important; }
        .inherited-locked { opacity: 0.45; pointer-events: none; }
        .modal-wide { max-width: 680px !important; }
        .modal-body-scroll { max-height: 75vh; overflow-y: auto; padding-right: 0.25rem; }
    </style>
<?php $adminExtraHead = ob_get_clean();
require_once '../components/sidebar.php'; ?>

            <header class="topbar">
                <div class="topbar-left">
                    <span class="topbar-title">Recepten</span>
                </div>
                <div class="topbar-right"></div>
            </header>

            <div class="admin-content">
                <div id="app" v-cloak>
        <!-- ═══ RECIPE LIST VIEW ═══ -->
        <div v-if="!calculatorActive" class="recipes-view" @click="closeMenuIfOpen">
            <div class="recipes-header">
                <h2>Recepten</h2>
                <div class="recipes-header-actions">
                    <button class="btn btn-primary" @click="newRecipe"><i class="bi bi-plus"></i> Nieuw recept</button>
                </div>
            </div>

            <div v-if="savedRecipes.length === 0" class="recipe-empty">
                <p>Nog geen recepten. Maak je eerste recept aan.</p>
                <button class="btn btn-primary" @click="newRecipe"><i class="bi bi-plus"></i> Nieuw recept</button>
            </div>

            <table v-else class="recipe-table">
                <thead>
                    <tr>
                        <th class="drag-cell"></th>
                        <th>Naam</th>
                        <th>Omschrijving</th>
                        <th>Bijgewerkt</th>
                        <th></th>
                    </tr>
                </thead>
                <template v-for="group in groupedRecipes" :key="group.id || '__uncategorized'">
                    <tbody>
                        <tr class="recipe-group-row"
                            :class="{ 'drag-over': draggingGroupOverId === (group.id || '__uncategorized') }"
                            :draggable="!!group.id"
                            @dragstart="group.id && onGroupDragStart($event, group.id)"
                            @dragover="group.id && onGroupDragOver($event, group.id)"
                            @dragleave="draggingGroupOverId = null"
                            @drop="group.id && onGroupDrop($event, group.id)"
                            @dragend="draggingGroupId = null; draggingGroupOverId = null"
                            @click="toggleGroup(group.id)">
                            <td class="drag-cell" @click.stop>
                                <span v-if="group.id" class="drag-handle"><i class="bi bi-grip-vertical"></i></span>
                            </td>
                            <td colspan="2">
                                <span class="recipe-group-chevron" :class="{ collapsed: isGroupCollapsed(group.id) }"><i class="bi bi-chevron-down"></i></span>
                                {{ group.name }}
                                <span class="recipe-group-count">{{ group.recipes.length }}</span>
                            </td>
                            <td class="recipe-table-date" style="color:#9ca3af;font-size:0.75rem">{{ group.description || '' }}</td>
                            <td class="recipe-table-actions" @click.stop>
                                <div style="display:flex;gap:0.25rem;justify-content:flex-end;align-items:center">
                                    <button v-if="group.id" class="btn-icon" style="width:26px;height:26px;font-size:0.75rem" @click="editGroupDoughType(group)" title="Deegsoort bewerken"><i class="bi bi-pencil"></i></button>
                                </div>
                            </td>
                        </tr>
                        <template v-if="!isGroupCollapsed(group.id)">
                            <tr v-for="r in group.recipes" :key="r.id" class="recipe-row"
                                :class="{ 'drag-over': draggingRecipeOverId === r.id }"
                                draggable="true"
                                @dragstart="onRecipeDragStart($event, r.id)"
                                @dragover="onRecipeDragOver($event, r.id)"
                                @dragleave="draggingRecipeOverId = null"
                                @drop="onRecipeDrop($event, r.id, group.id)"
                                @dragend="draggingRecipeId = null; draggingRecipeOverId = null"
                                @click="loadRecipe(r.id)">
                                <td class="drag-cell" @click.stop>
                                    <span class="drag-handle"><i class="bi bi-grip-vertical"></i></span>
                                </td>
                                <td>
                                    <span class="recipe-table-name">
                                        {{ r.name }}
                                        <span v-if="r.is_dough_type == 1" class="is-dough-type-icon" title="Is deegsoort definitie"><i class="bi bi-layers-fill"></i></span>
                                        <span v-if="r.linked_to_product == 1" style="color:#2e7d32" title="Gekoppeld aan product"><i class="bi bi-link-45deg"></i></span>
                                    </span>
                                </td>
                                <td class="recipe-table-desc">{{ r.recipe_data && r.recipe_data.description ? r.recipe_data.description : '' }}</td>
                                <td class="recipe-table-date">{{ formatDate(r.updated_at) }}</td>
                                <td class="recipe-table-actions" @click.stop>
                                    <div class="recipe-actions-menu">
                                        <button class="recipe-menu-btn" @click="toggleRecipeMenu(r.id)" title="Acties"><i class="bi bi-three-dots-vertical"></i></button>
                                        <div class="recipe-dropdown" v-if="activeRecipeMenu === r.id">
                                            <button class="recipe-dropdown-item" @click="loadRecipe(r.id); activeRecipeMenu = null"><i class="bi bi-pencil"></i> Recept bewerken</button>
                                            <button class="recipe-dropdown-item" @click="duplicateAndOpen(r.id)"><i class="bi bi-copy"></i> Dupliceren</button>
                                            <button class="recipe-dropdown-item" @click="printRecipeById(r.id)"><i class="bi bi-printer"></i> PDF downloaden</button>
                                            <div class="recipe-dropdown-divider"></div>
                                            <button class="recipe-dropdown-item danger" @click="deleteRecipe(r.id); activeRecipeMenu = null"><i class="bi bi-trash"></i> Verwijderen</button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!isGroupCollapsed(group.id)" class="recipe-add-row" @click="newRecipeInGroup(group.id)">
                                <td class="drag-cell"></td>
                                <td colspan="4" style="padding:0.35rem 0.875rem">
                                    <button class="btn-add" style="font-size:0.78rem;padding:0.25rem 0.5rem;width:auto;justify-content:flex-start;gap:0.3rem" @click.stop="newRecipeInGroup(group.id)"><i class="bi bi-plus"></i> Nieuw recept</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </template>
            </table>
        </div>

        <!-- ═══ CALCULATOR VIEW ═══ -->
        <div class="top-bar" v-show="calculatorActive">
            <button class="btn-back" @click="backToRecipes"><i class="bi bi-arrow-left"></i> Recepten</button>
            <div class="recipe-name-group">
                <input type="text" v-model="recipeName" class="recipe-name-input" placeholder="Receptnaam...">
                <input type="text" v-model="recipeDescription" class="recipe-desc-input" placeholder="Omschrijving (optioneel)...">
            </div>
            <div class="dough-type-select">
                <template v-if="isDoughType">
                    <span class="deegsoort-badge"><i class="bi bi-layers-fill"></i> Is deegsoort</span>
                    <button type="button" class="btn-icon" style="width:26px;height:26px;font-size:0.75rem" @click="isDoughType = false; doughTypeId = null" title="Verwijder deegsoort markering"><i class="bi bi-x"></i></button>
                </template>
                <template v-else>
                    <select :value="doughTypeId" @change="onDoughTypeChange($event.target.value ? parseInt($event.target.value) : null)" class="form-select-sm">
                        <option :value="null">— Geen basis —</option>
                        <option v-for="dt in doughTypes" :key="dt.id" :value="dt.id">{{ dt.name }}</option>
                    </select>
                    <button type="button" class="btn-is-deegsoort" @click="isDoughType = true; doughTypeId = null; mixins = []; toppings = []; if (activeTab === 'toevoegingen') activeTab = 'recept'" title="Dit recept definieert een deegsoort"><i class="bi bi-layers"></i> Is deegsoort</button>
                </template>
            </div>
            <button class="btn btn-success" @click="saveRecipe" :disabled="saving"><i class="bi bi-save"></i> {{ currentRecipeId ? 'Opslaan' : 'Bewaar' }}</button>
            <button class="btn btn-ghost" @click="duplicateRecipe" v-if="currentRecipeId"><i class="bi bi-copy"></i> Dupliceer</button>
        </div>

        <div class="tabs" v-show="calculatorActive">
            <div class="tab" :class="{active: activeTab==='recept'}" @click="activeTab='recept'">Recept</div>
            <div class="tab" :class="{active: activeTab==='meel'}" @click="activeTab='meel'">Meel & Voordeeg</div>
            <div v-if="!isDoughType" class="tab" :class="{active: activeTab==='toevoegingen'}" @click="activeTab='toevoegingen'">Toevoegingen</div>
            <div class="tab" :class="{active: activeTab==='overzicht'}" @click="activeTab='overzicht'">Overzicht</div>
            <div class="tab" :class="{active: activeTab==='methode'}" @click="activeTab='methode'">Methode</div>
        </div>

        <div class="layout">
            <div class="main-content">

                <div v-show="calculatorActive && activeTab==='recept'">
                    <div v-if="isDoughType" class="inherited-banner" style="background:#f0fdf4;border-color:#86efac;color:#166534">
                        <i class="bi bi-layers-fill" style="color:#16a34a"></i>
                        <span>Dit is een <strong>deegsoort definitie</strong>. De samenstelling hier wordt als basis overgenomen door recepten die deze deegsoort gebruiken. Mix-ins en toppings worden niet opgeslagen.</span>
                    </div>
                    <div v-if="isInherited" class="inherited-banner">
                        <i class="bi bi-link-45deg"></i>
                        <span>Deeg samenstelling overgenomen van deegsoort <strong>{{ doughTypes.find(d => d.id == doughTypeId)?.name }}</strong>. Bewerk de deegsoort om te wijzigen.</span>
                    </div>
                    <div class="panel">
                        <div class="panel-title">Basisrecept</div>
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
                        <div class="panel-title">Rijsmiddelen</div>
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
                        <div class="panel-title">Zuurdesem meelsoorten</div>
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
                            <span class="form-label">Zuurdesem meel: <strong style="color:#1f2937">{{ formatW(sourdoughFlour) }}g</strong></span>
                            <span class="form-label">Zuurdesem water: <strong style="color:#3b82f6">{{ formatW(sourdoughWater) }}g</strong></span>
                            <span class="form-label">Zuurdesem totaal: <strong style="color:#c8913a">{{ formatW(sourdoughWeight) }}g</strong></span>
                        </div>
                    </div>

                    <div class="panel" v-if="usePreFerment">
                        <div class="panel-title">Voordeeg (Pre-ferment)</div>
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
                            <span class="form-label">Voordeeg meel: <strong style="color:#1f2937">{{ formatW(preFermentFlour) }}g</strong></span>
                            <span class="form-label">Voordeeg water: <strong style="color:#3b82f6">{{ formatW(preFermentWater) }}g</strong></span>
                            <span class="form-label">Voordeeg totaal: <strong style="color:#c8913a">{{ formatW(preFermentWeight) }}g</strong></span>
                        </div>
                    </div>

                    <div class="panel">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
                            <div class="panel-title" style="margin-bottom:0">Hoofddeeg</div>
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
                            <span class="form-label">Hoofddeeg meel: <strong style="color:#1f2937">{{ formatW(mainDoughFlour) }}g</strong></span>
                            <span class="form-label">Hoofddeeg water: <strong style="color:#3b82f6">{{ formatW(mainDoughWater) }}g</strong></span>
                            <span class="form-label">Effectieve hydratatie: <strong style="color:#c8913a">{{ formatP(effectiveMainDoughHydration) }}%</strong></span>
                        </div>
                    </div>
                </div>

                <div v-show="calculatorActive && activeTab==='toevoegingen'">
                    <div class="panel">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
                            <div class="panel-title" style="margin-bottom:0">Mix-ins</div>
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
                        <div class="panel-title">Toppings</div>
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
                            <div class="panel-title" style="margin-bottom:0">Recept Overzicht</div>
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
                                <h4>Zuurdesem</h4>
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
                                <h4>Voordeeg</h4>
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
                                <h4>Hoofddeeg</h4>
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
                                <h4>Mix-ins</h4>
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
                                <h4>Toppings</h4>
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
                                <h4>Meelverdeling</h4>
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
                        <div class="panel-title">Baker's Percentages</div>
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
                        <div class="panel-title" v-if="ingredientsLoaded">Kostprijsberekening</div>
                        <div v-if="ingredientsLoaded" class="overview-grid">
                            <div class="overview-section">
                                <h4>Ingrediënten</h4>
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
                            <div class="overview-section" style="background:#fffbeb;border-color:#fef3c7">
                                <h4 style="color:#92400e">Nutskosten</h4>
                                <div class="overview-total">
                                    <span>Per brood ({{ monthlyBreadCount }} broden deze maand)</span>
                                    <span>{{ monthlyBreadCount ? '€' + formatEuro(totalUtilityCostPerRecipe) : '—' }}</span>
                                </div>
                            </div>
                            <div class="overview-section" style="background:#f0fdf4;border-color:#dcfce7">
                                <h4 style="color:#166534">Kostprijs</h4>
                                <div class="overview-item">
                                    <span class="name">Per kg deeg</span>
                                    <span class="value" style="color:#166534;font-size:1rem">€{{ formatEuro(costPerKgDough) }}</span>
                                </div>
                                <div class="overview-item">
                                    <span class="name">Per stuk ({{ formatW(finalWeightPerBall) }}g)</span>
                                    <span class="value" style="color:#166534;font-size:1rem">€{{ formatEuro(costPerPiece) }}</span>
                                </div>
                            </div>
                        </div>
                        <p v-if="ingredientsLoaded && totalIngredientCost === 0" style="color:#888;font-size:0.85rem;margin-top:0.5rem">
                            <i class="bi bi-info-circle"></i> Vul ingrediëntprijzen in via <a href="voorraad.php" style="color:#374151">Voorraadbeheer</a> voor kostprijsberekening.
                        </p>
                    </div>
                </div>

                <div v-show="calculatorActive && activeTab==='methode'">
                    <div v-if="isInherited && inheritedMethodDays" class="inherited-banner" style="margin-bottom:1rem">
                        <i class="bi bi-link-45deg"></i>
                        <span>Aantal dagen ({{ inheritedMethodDays.length }}) vastgelegd door deegsoort. Stappen zijn vrij bewerkbaar.</span>
                        <button class="method-apply-btn" @click="applyDoughTypeMethod()" style="margin-left:auto">
                            <i class="bi bi-download"></i> Methode overnemen
                        </button>
                    </div>
                    <div class="panel">
                        <div class="panel-title">Bereidingswijze</div>
                        <div v-for="(day, di) in methodDays" :key="di" class="method-day">
                            <div class="method-day-header">
                                <h4>Dag {{ di + 1 }}</h4>
                                <button class="btn-remove" @click="removeDay(di)" v-if="!isInherited && methodDays.length > 1" title="Dag verwijderen"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <div v-for="(step, si) in day.steps" :key="di+'-'+si"
                                class="method-step"
                                :class="{ dragging: dragStep && dragStep.di === di && dragStep.si === si, 'drag-over': dragOverStep && dragOverStep.di === di && dragOverStep.si === si }"
                                draggable="true"
                                @dragstart="onStepDragStart(di, si, $event)"
                                @dragover.prevent="onStepDragOver(di, si, $event)"
                                @dragleave="onStepDragLeave(di, si)"
                                @drop.prevent="onStepDrop(di, si)"
                                @dragend="onStepDragEnd()">
                                <span class="method-step-handle" title="Sleep om te verplaatsen"><i class="bi bi-grip-vertical"></i></span>
                                <span class="method-step-num">{{ si + 1 }}</span>
                                <textarea v-model="day.steps[si]" placeholder="Beschrijf deze stap..." rows="1" @input="autoResizeStep($event)"></textarea>
                                <button class="btn-remove" @click="removeStep(di, si)" v-if="day.steps.length > 1" title="Stap verwijderen"><i class="bi bi-x"></i></button>
                            </div>
                            <button class="method-add-step" @click="addStep(di)"><i class="bi bi-plus"></i> Stap toevoegen</button>
                        </div>
                        <button class="method-add-day" @click="addDay()" v-if="!isInherited">
                            <i class="bi bi-plus-lg"></i> Dag toevoegen
                        </button>
                    </div>
                </div>

            </div>

            <div class="calc-sidebar" v-show="calculatorActive">
                <div class="summary-card">
                    <div class="summary-header">
                        <h3>Live Berekening</h3>
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
                            <span class="summary-label" style="font-weight:700;color:#2d4a2d">Deeggewicht</span>
                            <span class="summary-value accent">{{ formatW(doughWeight) }}g</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label" style="font-weight:700;color:#2d4a2d">Totaal</span>
                            <span class="summary-value accent">{{ formatW(totalFinalWeight) }}g</span>
                        </div>

                        <div class="pct-bar">
                            <div class="pct-bar-fill pct-bar-flour" :style="{width: flourPct+'%'}"></div>
                            <div class="pct-bar-fill pct-bar-water" :style="{width: waterPct+'%'}"></div>
                            <div class="pct-bar-fill pct-bar-other" :style="{width: otherPct+'%'}"></div>
                        </div>
                        <div style="display:flex;gap:0.75rem;margin-top:0.35rem;font-size:0.65rem;color:#9ca3af">
                            <span><span style="color:#c8913a">&#9679;</span> Meel</span>
                            <span><span style="color:#3b82f6">&#9679;</span> Water</span>
                            <span><span style="color:#22c55e">&#9679;</span> Overig</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="toast success" v-if="toastMsg">{{ toastMsg }}</div>

        <div class="modal-overlay" v-if="showDoughTypeModal" @click.self="doughTypeModalView === 'list' && (showDoughTypeModal = false)">
            <div class="modal-content" :class="{'modal-wide': doughTypeModalView === 'edit'}">
                <div class="modal-header">
                    <h3 v-if="doughTypeModalView === 'list'">Deegsoorten beheren</h3>
                    <h3 v-else>{{ editingDoughType && editingDoughType.id ? editingDoughType.name : 'Nieuwe deegsoort' }}</h3>
                    <button class="modal-close" @click="showDoughTypeModal = false">&times;</button>
                </div>
                <div class="modal-body modal-body-scroll">

                    <!-- LIST VIEW -->
                    <div v-if="doughTypeModalView === 'list'">
                        <div class="dough-type-list">
                            <div v-for="dt in doughTypes" :key="dt.id" class="dough-type-item">
                                <span>{{ dt.name }}</span>
                                <div style="display:flex;gap:0.25rem">
                                    <button class="btn-icon-danger" @click="editDoughType(dt)" title="Bewerken" style="color:#374151"><i class="bi bi-pencil"></i></button>
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
                        <div class="form-group" style="margin-bottom:0.5rem">
                            <label class="form-label">Naam</label>
                            <input type="text" v-model="editingDoughType.name" class="form-input" placeholder="Bijv. Bianco, Rocca..." style="width:100%">
                        </div>
                        <div class="form-group" style="margin-bottom:1rem">
                            <label class="form-label">Omschrijving</label>
                            <input type="text" v-model="editingDoughType.description" class="form-input" placeholder="Korte omschrijving (optioneel)..." style="width:100%">
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
                        <div class="panel-title" style="margin-bottom:0.75rem">Rijsmiddelen</div>

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
                        <div class="panel-title" style="margin-bottom:0.75rem">Meelsoorten</div>

                        <!-- Sourdough grains -->
                        <div v-if="editingDoughType.useSourdough" style="margin-bottom:1rem">
                            <label class="form-label" style="margin-bottom:0.5rem;display:block">Zuurdesem meelsoorten</label>
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

                        <hr class="divider">
                        <div class="panel-title" style="margin-bottom:0.75rem">Methode</div>
                        <div v-for="(day, di) in editingDoughType.methodDays" :key="'dtday'+di" class="method-day">
                            <div class="method-day-header">
                                <h4>Dag {{ di + 1 }}</h4>
                                <button class="btn-remove" @click="editingDoughType.methodDays.splice(di, 1)" v-if="editingDoughType.methodDays.length > 1" title="Dag verwijderen"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <div v-for="(step, si) in day.steps" :key="'dtstep'+di+'-'+si"
                                class="method-step"
                                :class="{ dragging: dragStep && dragStep.di === ('dt'+di) && dragStep.si === si, 'drag-over': dragOverStep && dragOverStep.di === ('dt'+di) && dragOverStep.si === si }"
                                draggable="true"
                                @dragstart="onStepDragStart('dt'+di, si, $event)"
                                @dragover.prevent="onStepDragOver('dt'+di, si, $event)"
                                @dragleave="onStepDragLeave('dt'+di, si)"
                                @drop.prevent="onStepDropDt(di, si)"
                                @dragend="onStepDragEnd()">
                                <span class="method-step-handle" title="Sleep om te verplaatsen"><i class="bi bi-grip-vertical"></i></span>
                                <span class="method-step-num">{{ si + 1 }}</span>
                                <textarea v-model="day.steps[si]" placeholder="Beschrijf deze stap..." rows="1" @input="autoResizeStep($event)"></textarea>
                                <button class="btn-remove" @click="day.steps.splice(si, 1)" v-if="day.steps.length > 1"><i class="bi bi-x"></i></button>
                            </div>
                            <button class="method-add-step" @click="day.steps.push('')"><i class="bi bi-plus"></i> Stap toevoegen</button>
                        </div>
                        <button class="method-add-day" @click="editingDoughType.methodDays.push({ label: 'Dag ' + (editingDoughType.methodDays.length + 1), steps: [''] })">
                            <i class="bi bi-plus-lg"></i> Dag toevoegen
                        </button>

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

    <script src="../../js/ui-notifications.js?v=1"></script>
    <script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js"></script>
    <script>
    const { createApp } = Vue;
    createApp({
        data() {
            return {
                activeTab: 'recept',
                calculatorActive: false,
                activeRecipeMenu: null,
                recipeName: '',
                recipeDescription: '',
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
                methodDays: [{ label: 'Dag 1', steps: [''] }],
                dragStep: null,
                dragOverStep: null,
                isDoughType: false,
                savedRecipes: [],
                collapsedGroups: {},
                draggingGroupId: null,
                draggingGroupOverId: null,
                draggingRecipeId: null,
                draggingRecipeOverId: null,
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
                if (this.isDoughType) return false;
                if (!this.doughTypeId) return false;
                const dt = this.doughTypes.find(d => d.id == this.doughTypeId);
                return !!(dt && dt.recipe_data);
            },

            inheritedMethodDays() {
                if (!this.isInherited) return null;
                const dt = this.doughTypes.find(d => d.id == this.doughTypeId);
                return dt?.recipe_data?.methodDays || null;
            },

            groupedRecipes() {
                const groups = {};
                // Seed with all dough types so empty ones still appear
                this.doughTypes.forEach(dt => {
                    const desc = dt.recipe_data?.description || '';
                    groups[dt.id] = { id: dt.id, name: dt.name, description: desc, recipes: [] };
                });
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
                const result = this.doughTypes.map(dt => groups[dt.id]).filter(Boolean);
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

            addDay() {
                if (this.isInherited) return;
                this.methodDays.push({ label: 'Dag ' + (this.methodDays.length + 1), steps: [''] });
            },
            async removeDay(di) {
                if (this.isInherited || this.methodDays.length <= 1) return;
                const hasContent = this.methodDays[di].steps.some(s => s.trim());
                if (hasContent && !await showConfirm('Dag ' + (di + 1) + ' bevat stappen. Weet je zeker dat je deze wilt verwijderen?')) return;
                this.methodDays.splice(di, 1);
            },
            addStep(di) {
                this.methodDays[di].steps.push('');
            },
            removeStep(di, si) {
                if (this.methodDays[di].steps.length <= 1) return;
                this.methodDays[di].steps.splice(si, 1);
            },
            autoResizeStep(e) {
                const el = e.target;
                el.style.height = 'auto';
                el.style.height = el.scrollHeight + 'px';
            },
            onStepDragStart(di, si, e) {
                this.dragStep = { di, si };
                e.dataTransfer.effectAllowed = 'move';
            },
            onStepDragOver(di, si) {
                if (!this.dragStep || this.dragStep.di !== di) return;
                this.dragOverStep = { di, si };
            },
            onStepDragLeave(di, si) {
                if (this.dragOverStep && this.dragOverStep.di === di && this.dragOverStep.si === si) {
                    this.dragOverStep = null;
                }
            },
            onStepDrop(di, si) {
                if (!this.dragStep || this.dragStep.di !== di) return;
                const from = this.dragStep.si;
                if (from === si) return;
                const steps = this.methodDays[di].steps;
                const [moved] = steps.splice(from, 1);
                steps.splice(si, 0, moved);
                this.dragStep = null;
                this.dragOverStep = null;
            },
            onStepDropDt(di, si) {
                const key = 'dt' + di;
                if (!this.dragStep || this.dragStep.di !== key) return;
                const from = this.dragStep.si;
                if (from === si) return;
                const steps = this.editingDoughType.methodDays[di].steps;
                const [moved] = steps.splice(from, 1);
                steps.splice(si, 0, moved);
                this.dragStep = null;
                this.dragOverStep = null;
            },
            onStepDragEnd() {
                this.dragStep = null;
                this.dragOverStep = null;
            },
            async applyDoughTypeMethod() {
                if (!this.inheritedMethodDays) return;
                if (!await showConfirm('Methode stappen overnemen van deegsoort? Bestaande stappen worden overschreven.')) return;
                this.methodDays = JSON.parse(JSON.stringify(this.inheritedMethodDays));
            },
            syncMethodDaysToInheritedDayCount() {
                if (!this.inheritedMethodDays) return;
                const target = this.inheritedMethodDays.length;
                while (this.methodDays.length < target) {
                    this.methodDays.push({ label: 'Dag ' + (this.methodDays.length + 1), steps: [''] });
                }
                while (this.methodDays.length > target) {
                    this.methodDays.pop();
                }
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
                const data = {
                    description: this.recipeDescription,
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
                    methodDays: this.methodDays,
                };
                if (!this.isDoughType) {
                    data.mixinMode = this.mixinMode;
                    data.mixins = this.mixins;
                    data.toppings = this.toppings;
                }
                return data;
            },

            applyRecipeData(d) {
                this.recipeDescription = d.description || '';
                const fields = ['doughWeight','hydration','saltPct',
                    'useSourdough','sourdoughPct','sourdoughHydration','sourdoughGrains',
                    'useYeast','yeastType','yeastPct',
                    'usePreFerment','preFermentPct','preFermentHydration',
                    'preFermentGrains','mainDoughGrains','mixinMode','mixins','toppings','methodDays'];
                fields.forEach(f => { if (d[f] !== undefined) this[f] = d[f]; });
                // Backward compat: convert old string method to methodDays
                if (d.method && !d.methodDays) {
                    const lines = d.method.split('\n').filter(l => l.trim());
                    this.methodDays = [{ label: 'Dag 1', steps: lines.length ? lines : [''] }];
                }
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
                this.syncMethodDaysToInheritedDayCount();
            },

            async saveRecipe() {
                if (!this.recipeName.trim()) { this.recipeName = 'Naamloos recept'; }
                this.saving = true;
                try {
                    const body = { name: this.recipeName, dough_type_id: this.doughTypeId, is_dough_type: this.isDoughType ? 1 : 0, recipe_data: this.getRecipeData() };
                    if (this.currentRecipeId) body.id = this.currentRecipeId;
                    const method = this.currentRecipeId ? 'PUT' : 'POST';
                    const res = await fetch('../../api/baker-recipes.php', { method, headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
                    const data = await res.json();
                    if (data.success) {
                        if (!this.currentRecipeId && data.id) this.currentRecipeId = data.id;
                        if (data.dough_type_id) this.doughTypeId = data.dough_type_id;
                        if (this.isDoughType) await this.reloadDoughTypes();
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

            async reloadDoughTypes() {
                try {
                    const res = await fetch('../../api/dough-types.php');
                    const data = await res.json();
                    if (data.success) this.doughTypes = data.dough_types;
                } catch (e) { console.error(e); }
            },

            async loadRecipe(id) {
                try {
                    const res = await fetch('../../api/baker-recipes.php?id=' + id);
                    const data = await res.json();
                    if (data.success) {
                        this.currentRecipeId = data.recipe.id;
                        this.recipeName = data.recipe.name;
                        this.isDoughType = data.recipe.is_dough_type == 1;
                        this.doughTypeId = data.recipe.dough_type_id;
                        this.applyRecipeData(data.recipe.recipe_data);
                        this.calculatorActive = true;
                        this.activeTab = 'recept';
                        this.showToast('Recept geladen!');
                    }
                } catch (e) { console.error(e); }
            },

            async deleteRecipe(id) {
                if (!await showConfirm('Weet je zeker dat je dit recept wilt verwijderen?')) return;
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

            async duplicateAndOpen(id) {
                this.activeRecipeMenu = null;
                await this.loadRecipe(id);
                this.currentRecipeId = null;
                this.recipeName = this.recipeName + ' (kopie)';
                this.showToast('Kopie aangemaakt - pas aan en sla op');
            },

            async printRecipeById(id) {
                this.activeRecipeMenu = null;
                try {
                    const res = await fetch('../../api/baker-recipes.php?id=' + id);
                    const data = await res.json();
                    if (data.success) {
                        const pdfRes = await fetch('../../api/recipe-pdf.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ name: data.recipe.name, recipe_data: data.recipe.recipe_data })
                        });
                        if (pdfRes.ok) {
                            const blob = await pdfRes.blob();
                            const url = window.URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = 'Recept_' + data.recipe.name.replace(/[^a-zA-Z0-9]/g, '_') + '.pdf';
                            document.body.appendChild(a);
                            a.click();
                            a.remove();
                            window.URL.revokeObjectURL(url);
                            this.showToast('PDF gedownload');
                        }
                    }
                } catch (e) { console.error(e); }
            },

            backToRecipes() {
                this.calculatorActive = false;
                this.activeTab = 'recept';
                this.loadSavedRecipes();
            },

            toggleRecipeMenu(id) {
                this.activeRecipeMenu = this.activeRecipeMenu === id ? null : id;
            },

            closeMenuIfOpen(e) {
                if (this.activeRecipeMenu !== null) {
                    this.activeRecipeMenu = null;
                }
            },

            toggleGroup(groupId) {
                const key = groupId === null ? '__uncategorized' : groupId;
                this.collapsedGroups[key] = !this.collapsedGroups[key];
            },

            isGroupCollapsed(groupId) {
                const key = groupId === null ? '__uncategorized' : groupId;
                return !!this.collapsedGroups[key];
            },

            onGroupDragStart(e, groupId) {
                this.draggingGroupId = groupId;
                e.dataTransfer.effectAllowed = 'move';
            },
            onGroupDragOver(e, groupId) {
                if (!this.draggingGroupId || this.draggingGroupId === groupId) return;
                e.preventDefault();
                this.draggingGroupOverId = groupId;
            },
            onGroupDrop(e, groupId) {
                e.preventDefault();
                if (!this.draggingGroupId || this.draggingGroupId === groupId) return;
                const fromIdx = this.doughTypes.findIndex(d => d.id === this.draggingGroupId);
                const toIdx = this.doughTypes.findIndex(d => d.id === groupId);
                if (fromIdx === -1 || toIdx === -1) return;
                const moved = this.doughTypes.splice(fromIdx, 1)[0];
                this.doughTypes.splice(toIdx, 0, moved);
                this.draggingGroupId = null;
                this.draggingGroupOverId = null;
                this.saveGroupOrder();
            },
            async saveGroupOrder() {
                const items = this.doughTypes.map((d, i) => ({ id: d.id, sort_order: i }));
                await fetch('../../api/dough-types.php', {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reorder', items })
                });
            },

            onRecipeDragStart(e, recipeId) {
                this.draggingRecipeId = recipeId;
                e.dataTransfer.effectAllowed = 'move';
            },
            onRecipeDragOver(e, recipeId) {
                if (!this.draggingRecipeId || this.draggingRecipeId === recipeId) return;
                e.preventDefault();
                this.draggingRecipeOverId = recipeId;
            },
            onRecipeDrop(e, recipeId, groupId) {
                e.preventDefault();
                if (!this.draggingRecipeId || this.draggingRecipeId === recipeId) return;
                const fromIdx = this.savedRecipes.findIndex(r => r.id === this.draggingRecipeId);
                const toIdx = this.savedRecipes.findIndex(r => r.id === recipeId);
                if (fromIdx === -1 || toIdx === -1) return;
                const moved = this.savedRecipes.splice(fromIdx, 1)[0];
                this.savedRecipes.splice(toIdx, 0, moved);
                this.draggingRecipeId = null;
                this.draggingRecipeOverId = null;
                this.saveRecipeOrder(groupId);
            },
            async saveRecipeOrder(groupId) {
                // Only send order for recipes in this group
                const groupRecipes = this.savedRecipes
                    .filter(r => (r.dough_type_id ?? null) === (groupId ?? null))
                    .map((r, i) => ({ id: r.id, sort_order: i }));
                await fetch('../../api/baker-recipes.php', {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reorder', items: groupRecipes })
                });
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

            async newRecipeInGroup(doughTypeId) {
                await this.newRecipe();
                if (doughTypeId) this.onDoughTypeChange(doughTypeId);
            },

            editGroupDoughType(group) {
                // If a recipe in this group IS the dough type definition, open it
                const definingRecipe = group.recipes.find(r => r.is_dough_type == 1);
                if (definingRecipe) {
                    this.loadRecipe(definingRecipe.id);
                } else {
                    this.showDoughTypeModal = true;
                    this.editDoughType(this.doughTypes.find(d => d.id === group.id));
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
                    this.recipeDescription = '';
                    this.isDoughType = false;
                    this.doughTypeId = null;
                } else {
                    // No Standaardrecept found — open a blank calculator
                    this.currentRecipeId = null;
                    this.recipeName = '';
                    this.recipeDescription = '';
                    this.isDoughType = false;
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
                    this.methodDays = [{ label: 'Dag 1', steps: [''] }];
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
                this.syncMethodDaysToInheritedDayCount();
            },

            newDoughType() {
                this.editingDoughType = {
                    id: null,
                    name: '',
                    description: '',
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
                    methodDays: [{ label: 'Dag 1', steps: [''] }],
                };
                this.doughTypeModalView = 'edit';
            },

            editDoughType(dt) {
                const rd = dt.recipe_data || {};
                this.editingDoughType = {
                    id: dt.id,
                    name: dt.name,
                    description: rd.description || '',
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
                    methodDays: rd.methodDays ? JSON.parse(JSON.stringify(rd.methodDays)) : [{ label: 'Dag 1', steps: [''] }],
                };
                this.doughTypeModalView = 'edit';
            },

            async saveDoughType() {
                const dt = this.editingDoughType;
                if (!dt || !dt.name.trim()) return;
                const recipeData = {
                    description: dt.description || '',
                    hydration: dt.hydration, saltPct: dt.saltPct,
                    useSourdough: dt.useSourdough, sourdoughPct: dt.sourdoughPct,
                    sourdoughHydration: dt.sourdoughHydration, sourdoughGrains: dt.sourdoughGrains,
                    usePreFerment: dt.usePreFerment, preFermentPct: dt.preFermentPct,
                    preFermentHydration: dt.preFermentHydration, preFermentGrains: dt.preFermentGrains,
                    mainDoughGrains: dt.mainDoughGrains,
                    useYeast: dt.useYeast, yeastType: dt.yeastType, yeastPct: dt.yeastPct,
                    methodDays: dt.methodDays,
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
                if (!await showConfirm('Weet je zeker dat je deze deegsoort wilt verwijderen?')) return;
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
        if ('PushManager' in window) {
            navigator.serviceWorker.ready.then(async reg => {
                try {
                    let permission = Notification.permission;
                    if (permission === 'default') {
                        permission = await Notification.requestPermission();
                    }
                    if (permission !== 'granted') return;

                    let sub = await reg.pushManager.getSubscription();
                    if (!sub) {
                        const r = await fetch('/api/push-subscriptions.php?action=vapid-key');
                        const { publicKey } = await r.json();
                        const padding = '='.repeat((4 - publicKey.length % 4) % 4);
                        const raw = atob((publicKey + padding).replace(/-/g, '+').replace(/_/g, '/'));
                        const key = Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
                        sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
                    }
                    const j = sub.toJSON();
                    await fetch('/api/push-subscriptions.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: j.endpoint, keys: { p256dh: j.keys.p256dh, auth: j.keys.auth } }) });
                } catch (e) { console.error('Push setup failed:', e); }
            });
        }
    }
    </script>
</body>
</html>
