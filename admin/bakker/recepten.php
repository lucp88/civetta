<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$adminPageTitle = 'Recepten';
$currentPage = 'recepten';
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
        /* ── Spin buttons ── */
        .spin-field { display: flex; align-items: stretch; }
        .spin-field .form-input { border-radius: 0; flex: 1; min-width: 0; border-left: none; text-align: center; -moz-appearance: textfield; }
        .spin-field .form-input::-webkit-inner-spin-button,
        .spin-field .form-input::-webkit-outer-spin-button { -webkit-appearance: none; }
        .spin-field .input-unit { border-left: none; border-radius: 0; }
        .spin-btn { width: 28px; flex-shrink: 0; background: #f3f4f6; border: 1px solid #d1d5db; color: #374151; font-size: 1.05rem; font-weight: 600; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0; user-select: none; }
        .spin-btn:first-child { border-radius: 4px 0 0 4px; }
        .spin-btn-r { border-radius: 0 4px 4px 0; border-left: none; }
        .spin-field .input-unit + .spin-btn-r { border-left: none; }
        .spin-btn:hover { background: #fff5eb; color: #c8913a; border-color: #c8913a; z-index: 1; position: relative; }
        .spin-btn:active { background: #fdebd0; }
        /* ── Version history ── */
        .version-row { display: flex; justify-content: space-between; align-items: flex-start; padding: 0.6rem 0.875rem; border: 1px solid #f0ebe5; border-radius: 4px; background: #faf8f5; margin-bottom: 0; gap: 0.75rem; }
        .version-meta { display: flex; flex-direction: column; gap: 0.15rem; flex: 1; min-width: 0; }
        .version-meta strong { font-size: 0.85rem; color: #1f2937; }
        .version-meta time { font-size: 0.75rem; color: #9ca3af; }
        .version-inline-chips { display: flex; flex-wrap: wrap; gap: 0.3rem; margin-top: 0.2rem; }
        .version-inline-chip { background: #f0ebe5; color: #374151; font-size: 0.7rem; padding: 0.1rem 0.4rem; border-radius: 3px; font-weight: 600; }
        .version-inline-chip.chip-up { background: #dcfce7; color: #166534; }
        .version-inline-chip.chip-down { background: #fee2e2; color: #b91c1c; }
        .version-accordion { background: #f3f0eb; border: 1px solid #e5e0d8; border-top: none; border-radius: 0 0 4px 4px; padding: 0.75rem 0.875rem; margin-bottom: 0.4rem; }
        .version-changes-box { background: #fff; border: 1px solid #e5e7eb; border-radius: 4px; padding: 0.5rem 0.75rem; margin-bottom: 0.75rem; }
        .version-detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; }
        .version-detail-section { display: flex; flex-direction: column; gap: 0.2rem; }
        .version-detail-section-title { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #9ca3af; margin-bottom: 0.2rem; }
        .version-detail-row { font-size: 0.8rem; color: #374151; display: flex; align-items: center; gap: 0.3rem; flex-wrap: wrap; }
        .version-diff-line { display: flex; align-items: center; gap: 0.35rem; font-size: 0.8rem; padding: 0.15rem 0; flex-wrap: wrap; }
        .version-diff-label { color: #6b7280; min-width: 100px; flex-shrink: 0; }
        .version-diff-from { color: #9ca3af; text-decoration: line-through; }
        .version-diff-arrow { color: #d1d5db; }
        .version-diff-to { font-weight: 600; }
        .diff-up { color: #166534; }
        .diff-down { color: #b91c1c; }
        .diff-neutral { color: #374151; }
        .version-note-text { font-size: 0.75rem; color: #6b7280; font-style: italic; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .version-badge-active { display: inline-flex; align-items: center; padding: 0.1rem 0.4rem; background: #dcfce7; color: #166534; border-radius: 3px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
        .version-actions { display: flex; gap: 0.3rem; flex-shrink: 0; }
        .version-note-input { width: 100%; padding: 0.4rem 0.6rem; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.85rem; font-family: inherit; resize: none; }
        .version-note-input:focus { outline: none; border-color: #c8913a; box-shadow: 0 0 0 2px rgba(200,145,58,0.12); }
        /* ── Method main steps + substeps ── */
        .method-mainstep { background: white; border: 1px solid #e5e7eb; border-radius: 6px; margin-bottom: 0.625rem; overflow: hidden; }
        .mainstep-header { display: flex; align-items: center; gap: 0.4rem; padding: 0.5rem 0.625rem; background: #f9fafb; border-bottom: 1px solid #f0f0f0; }
        .mainstep-title-input { flex: 1; border: none; background: transparent; font-size: 0.875rem; font-weight: 600; color: #1f2937; padding: 0.2rem 0.3rem; border-radius: 3px; }
        .mainstep-title-input:focus { outline: none; background: white; box-shadow: 0 0 0 2px rgba(200,145,58,0.2); }
        .mainstep-title-input::placeholder { font-weight: 400; color: #d1d5db; }
        .substeps-list { padding: 0.5rem 0.625rem 0.25rem; }
        .method-substep { display: flex; align-items: center; gap: 0.35rem; margin-bottom: 0.4rem; flex-wrap: wrap; }
        .substep-actie { flex: 0 0 auto; }
        .substep-select { padding: 0.3rem 0.4rem; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.75rem; background: white; color: #374151; min-width: 100px; }
        .substep-select:focus { outline: none; border-color: #c8913a; }
        .substep-field { flex: 0 0 auto; }
        .substep-field .spin-field .form-input { width: 44px; font-size: 0.8rem; padding: 0.3rem 0.25rem; }
        .substep-field .spin-btn { width: 22px; font-size: 0.9rem; }
        .substep-field .input-unit { font-size: 0.7rem; padding: 0.3rem 0.35rem; }
        .substep-desc { flex: 1; min-width: 120px; }
        .substep-desc .form-input { padding: 0.3rem 0.5rem; font-size: 0.8rem; width: 100%; }
        .substep-add-btn { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.2rem 0.5rem; font-size: 0.72rem; color: #9ca3af; background: none; border: 1px dashed #e5e7eb; border-radius: 4px; cursor: pointer; margin: 0.25rem 0.625rem 0.5rem; }
        .substep-add-btn:hover { border-color: #c8913a; color: #c8913a; background: #fffbf5; }

    </style>
<?php $adminExtraHead = ob_get_clean();
require_once '../components/sidebar.php'; ?>

            <header class="topbar">
                <div class="topbar-left">
                    <span class="topbar-title">Recepten</span>
                </div>
                <div class="topbar-right" style="display:flex;gap:0.5rem;">
                    <button class="btn btn-ghost btn-sm" onclick="newDeegsoort()"><i class="bi bi-tags"></i> Nieuwe Deegsoort</button>
                    <button class="btn btn-primary btn-sm" onclick="nieuwRecept()"><i class="bi bi-plus-lg"></i> Nieuw Recept</button>
                </div>
            </header>

            <div class="admin-content">
                <div id="app" v-cloak>
        <!-- ═══ RECIPE LIST VIEW ═══ -->
        <div v-if="!calculatorActive && !doughTypeEditActive" class="recipes-view" @click="closeMenuIfOpen">
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
                            @click="group.id ? editGroupDoughType(group) : toggleGroup(group.id)">
                            <td class="drag-cell" @click.stop>
                                <span v-if="group.id" class="drag-handle"><i class="bi bi-grip-vertical"></i></span>
                            </td>
                            <td colspan="2">
                                <span class="recipe-group-chevron" :class="{ collapsed: isGroupCollapsed(group.id) }" @click.stop="toggleGroup(group.id)"><i class="bi bi-chevron-down"></i></span>
                                {{ group.name }}
                                <span class="recipe-group-count">{{ group.recipes.length }}</span>
                            </td>
                            <td class="recipe-table-date" style="color:#9ca3af;font-size:0.75rem">{{ group.description || '' }}</td>
                            <td class="recipe-table-actions" @click.stop>
                                <div style="display:flex;gap:0.25rem;justify-content:flex-end;align-items:center">
                                    <i v-if="group.id" class="bi bi-pencil-square" style="color:#9ca3af;font-size:0.8rem" title="Klik op de rij om te bewerken"></i>
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
                                        <span style="font-size:0.7rem;color:#bbb;font-weight:400;margin-left:0.3rem">#{{ r.id }}</span>
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

        <!-- ═══ DOUGH TYPE EDITOR VIEW ═══ -->
        <div v-if="doughTypeEditActive && editingDoughType">
            <div class="top-bar">
                <button class="btn-back" @click="backFromDoughType"><i class="bi bi-arrow-left"></i> Recepten</button>
                <div class="recipe-name-group">
                    <input type="text" v-model="editingDoughType.name" class="recipe-name-input" placeholder="Naam deegsoort...">
                    <input type="text" v-model="editingDoughType.description" class="recipe-desc-input" placeholder="Omschrijving (optioneel)...">
                </div>
                <input v-if="editingDoughType.id" type="text" v-model="dtVersionNote" class="form-input" style="width:160px;font-size:0.78rem;padding:0.35rem 0.6rem" placeholder="Versienoot (optioneel)..." title="Wordt opgeslagen bij volgende versie">
                <button class="btn btn-success" @click="saveDoughType" :disabled="!editingDoughType.name.trim()">
                    <i class="bi bi-save"></i> {{ editingDoughType.id ? 'Opslaan' : 'Aanmaken' }}
                </button>
            </div>
            <div class="tabs">
                <div class="tab" :class="{active: dtActiveTab==='recept'}" @click="setDtTab('recept')">Recept</div>
                <div v-if="editingDoughType.id" class="tab" :class="{active: dtActiveTab==='versies'}" @click="setDtTab('versies')">
                    Versies <span v-if="dtVersions.length" style="background:#e5e7eb;border-radius:10px;padding:0.05rem 0.4rem;font-size:0.7rem;margin-left:0.2rem;font-weight:700">{{ dtVersions.length }}</span>
                </div>
            </div>
            <div class="layout">
                <div class="main-content">

                    <div v-show="dtActiveTab==='recept'">
                        <div class="panel" style="max-width:680px">

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

                        <div v-if="editingDoughType.useSourdough" style="margin-bottom:1rem">
                            <label class="form-label" style="margin-bottom:0.5rem;display:block">Zuurdesem meelsoorten</label>
                            <div class="grain-row" v-for="(grain, i) in editingDoughType.sourdoughGrains" :key="'dtsd'+i">
                                <div class="form-group">
                                    <select v-model="grain.type" class="form-select" @change="grain.brand_ingredient_id = null">
                                        <option v-for="g in dtGrainsForFerments" :value="g.id">{{ g.name }}</option>
                                    </select>
                                </div>
                                <div class="form-group" v-if="grainBrandsByParent[grain.type] && grainBrandsByParent[grain.type].length" style="flex:1.5;min-width:130px">
                                    <select v-model="grain.brand_ingredient_id" class="form-select">
                                        <option :value="null">Alle merken</option>
                                        <option v-for="b in grainBrandsByParent[grain.type]" :key="b.id" :value="b.id">{{ b.label }}</option>
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
                            <button class="btn-add" @click="editingDoughType.sourdoughGrains.push({type: dtGrainsForFerments[0]?.id ?? 'wheat_white', pct: 0, brand_ingredient_id: null})" v-if="editingDoughType.sourdoughGrains.length < 5">
                                <i class="bi bi-plus"></i> Toevoegen
                            </button>
                            <div class="grain-warning" v-if="editingDoughType.sourdoughGrains.reduce((s,g)=>s+(g.pct||0),0) !== 100">
                                <i class="bi bi-exclamation-triangle"></i> Totaal is {{ editingDoughType.sourdoughGrains.reduce((s,g)=>s+(g.pct||0),0) }}% — moet 100% zijn
                            </div>
                        </div>

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
                                    <select v-model="grain.type" class="form-select" @change="grain.brand_ingredient_id = null">
                                        <option v-for="g in dtGrainsForFerments" :value="g.id">{{ g.name }}</option>
                                    </select>
                                </div>
                                <div class="form-group" v-if="grainBrandsByParent[grain.type] && grainBrandsByParent[grain.type].length" style="flex:1.5;min-width:130px">
                                    <select v-model="grain.brand_ingredient_id" class="form-select">
                                        <option :value="null">Alle merken</option>
                                        <option v-for="b in grainBrandsByParent[grain.type]" :key="b.id" :value="b.id">{{ b.label }}</option>
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
                            <button class="btn-add" @click="editingDoughType.preFermentGrains.push({type: dtGrainsForFerments[0]?.id ?? 'wheat_white', pct: 0, brand_ingredient_id: null})" v-if="editingDoughType.preFermentGrains.length < 5">
                                <i class="bi bi-plus"></i> Toevoegen
                            </button>
                        </div>

                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem">
                            <label class="form-label" style="margin:0">Hoofddeeg meelsoorten</label>
                            <div class="radio-group">
                                <span class="radio-pill" :class="{active: editingDoughType.mainDoughPctMode==='separate'}" @click="editingDoughType.mainDoughPctMode='separate'" title="Percentages van hoofddeeg meel alleen">Los</span>
                                <span class="radio-pill" :class="{active: editingDoughType.mainDoughPctMode==='integrated'}" @click="editingDoughType.mainDoughPctMode='integrated'" title="Percentages van totaal meel (inclusief zuurdesem/voordeeg)">Totaal meel</span>
                            </div>
                        </div>
                        <div class="grain-row" v-for="(grain, i) in editingDoughType.mainDoughGrains" :key="'dtmd'+i">
                            <div class="form-group">
                                <select v-model="grain.type" class="form-select" @change="grain.brand_ingredient_id = null">
                                    <option v-for="g in grainTypes" :value="g.id">{{ g.name }}</option>
                                </select>
                            </div>
                            <div class="form-group" v-if="grainBrandsByParent[grain.type] && grainBrandsByParent[grain.type].length" style="flex:1.5;min-width:130px">
                                <select v-model="grain.brand_ingredient_id" class="form-select">
                                    <option :value="null">Alle merken</option>
                                    <option v-for="b in grainBrandsByParent[grain.type]" :key="b.id" :value="b.id">{{ b.label }}</option>
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
                        <button class="btn-add" @click="editingDoughType.mainDoughGrains.push({type: grainTypes[0]?.id ?? 'wheat_white', pct: 0, brand_ingredient_id: null})" v-if="editingDoughType.mainDoughGrains.length < 5">
                            <i class="bi bi-plus"></i> Meelsoort toevoegen
                        </button>
                        <div class="grain-warning" v-if="editingDoughType.mainDoughGrains.reduce((s,g)=>s+(g.pct||0),0) !== 100">
                            <i class="bi bi-exclamation-triangle"></i> Totaal is {{ editingDoughType.mainDoughGrains.reduce((s,g)=>s+(g.pct||0),0) }}% — moet 100% zijn
                        </div>

                        <div v-if="dtGrainCharacteristics" style="background:#f5f0e8;border-radius:8px;padding:0.875rem 1rem;margin-top:1rem">
                            <div class="panel-title" style="margin-bottom:0.6rem;font-size:0.75rem">Deeg Eigenschappen</div>
                            <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-bottom:0.5rem">
                                <div>
                                    <div class="form-label" style="font-size:0.68rem">Volkoren</div>
                                    <strong style="font-size:1rem;color:#5c3d1e">{{ Math.round(dtGrainCharacteristics.wholePct * 10) / 10 }}%</strong>
                                </div>
                                <div>
                                    <div class="form-label" style="font-size:0.68rem">Wit</div>
                                    <strong style="font-size:1rem;color:#5c3d1e">{{ Math.round(dtGrainCharacteristics.whitePct * 10) / 10 }}%</strong>
                                </div>
                                <div>
                                    <div class="form-label" style="font-size:0.68rem">Hydratatie</div>
                                    <strong style="font-size:1rem;color:#5c3d1e">{{ editingDoughType.hydration }}%</strong>
                                </div>
                            </div>
                            <div v-if="dtGrainCharacteristics.grainDist.length > 0" style="display:flex;gap:1.25rem;flex-wrap:wrap">
                                <div v-for="gt in dtGrainCharacteristics.grainDist" :key="gt.name">
                                    <div class="form-label" style="font-size:0.68rem">{{ gt.name }}</div>
                                    <span style="font-size:0.85rem;color:#8b5a2b;font-weight:600">{{ Math.round(gt.pct * 10) / 10 }}%</span>
                                </div>
                            </div>
                        </div>

                        <hr class="divider">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem">
                            <div class="panel-title" style="margin-bottom:0">Mix-ins</div>
                            <div class="radio-group">
                                <span class="radio-pill" :class="{active: editingDoughType.mixinMode==='flour'}" @click="editingDoughType.mixinMode='flour'">% van meel</span>
                                <span class="radio-pill" :class="{active: editingDoughType.mixinMode==='dough'}" @click="editingDoughType.mixinMode='dough'">% van deeg</span>
                            </div>
                        </div>
                        <div v-if="editingDoughType.mixins.length === 0" class="empty-state" style="padding:0.5rem 0 0.75rem">
                            <p style="color:#bbb;font-size:0.85rem">Geen mix-ins</p>
                        </div>
                        <div class="mixin-row" v-for="(m, i) in editingDoughType.mixins" :key="'dtmx'+i">
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
                            <button class="btn-remove" @click="editingDoughType.mixins.splice(i,1)"><i class="bi bi-x"></i></button>
                        </div>
                        <button class="btn-add" @click="editingDoughType.mixins.push({ingredient:'',pct:0,category:'non-integrated'})" v-if="editingDoughType.mixins.length < 16">
                            <i class="bi bi-plus"></i> Mix-in toevoegen
                        </button>

                        <hr class="divider">
                        <div class="panel-title" style="margin-bottom:0.75rem">Methode</div>
                        <div v-for="(day, di) in editingDoughType.methodDays" :key="'dtday'+di" class="method-day">
                            <div class="method-day-header">
                                <h4>Dag {{ di + 1 }}</h4>
                                <button class="btn-remove" @click="editingDoughType.methodDays.splice(di, 1)" v-if="editingDoughType.methodDays.length > 1" title="Dag verwijderen"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <div v-for="(step, si) in day.steps" :key="'dtstep'+di+'-'+si" class="method-mainstep">
                                <div class="mainstep-header">
                                    <span class="method-step-num">{{ si + 1 }}</span>
                                    <input type="text" v-model="step.title" class="mainstep-title-input" placeholder="Naam van deze stap...">
                                    <button class="btn-remove" @click="day.steps.splice(si, 1)" v-if="day.steps.length > 1"><i class="bi bi-x"></i></button>
                                </div>
                            </div>
                            <button class="method-add-step" @click="day.steps.push({ title: '', substeps: [] })"><i class="bi bi-plus"></i> Stap toevoegen</button>
                        </div>
                        <button class="method-add-day" @click="editingDoughType.methodDays.push({ label: 'Dag ' + (editingDoughType.methodDays.length + 1), steps: [{ title: '', substeps: [] }] })">
                            <i class="bi bi-plus-lg"></i> Dag toevoegen
                        </button>

                        </div><!-- end panel -->
                    </div>

                    <div v-show="dtActiveTab==='versies' && editingDoughType.id" style="padding:1.25rem 0">
                        <div class="panel" style="margin-bottom:1rem">
                            <div class="panel-title">Versienoot bij volgende opslag</div>
                            <textarea v-model="dtVersionNote" class="version-note-input" rows="2" placeholder="Optionele toelichting bij de volgende keer opslaan..."></textarea>
                        </div>
                        <div v-if="dtVersions.length === 0" class="empty-state">
                            <i class="bi bi-clock-history" style="font-size:2rem;color:#d1d5db"></i>
                            <p>Nog geen versiegeschiedenis. Versies worden aangemaakt bij elke opslag.</p>
                        </div>
                        <div v-for="(v, vi) in dtVersions" :key="v.id" style="margin-bottom:0.5rem">
                            <div class="version-row" :style="dtExpandedVersionIds[v.id] ? 'border-radius:4px 4px 0 0' : ''">
                                <div class="version-meta">
                                    <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap">
                                        <template v-if="dtEditingVersionNumberId === v.id">
                                            <span style="font-weight:600;font-size:0.85rem">Versie</span>
                                            <input type="number" v-model.number="dtEditingVersionNumberVal" min="1" class="form-control" style="width:60px;font-size:0.85rem;padding:0.1rem 0.35rem;height:auto;font-weight:600" @keyup.enter="saveDtVersionNumber(v)" @keyup.escape="cancelEditDtVersionNumber()" />
                                            <button class="btn btn-ghost btn-sm" @click="saveDtVersionNumber(v)" style="padding:0.1rem 0.4rem;font-size:0.78rem">✓</button>
                                            <button class="btn btn-ghost btn-sm" @click="cancelEditDtVersionNumber()" style="padding:0.1rem 0.4rem;font-size:0.78rem">✕</button>
                                        </template>
                                        <strong v-else @dblclick="startEditDtVersionNumber(v)" title="Dubbelklik om versienummer te wijzigen" style="cursor:pointer">Versie {{ v.version_number }}</strong>
                                        <span v-if="v.version_number === dtCurrentVersionNumber" class="version-badge-active">Actief</span>
                                        <div class="version-inline-chips" v-if="v.recipe_data">
                                            <span class="version-inline-chip"
                                                  :class="vi < dtVersions.length-1 && dtVersions[vi+1].recipe_data ? (v.recipe_data.hydration > dtVersions[vi+1].recipe_data.hydration ? 'chip-up' : v.recipe_data.hydration < dtVersions[vi+1].recipe_data.hydration ? 'chip-down' : '') : ''">
                                                {{ v.recipe_data.hydration }}% hydr.
                                            </span>
                                            <span class="version-inline-chip"
                                                  :class="vi < dtVersions.length-1 && dtVersions[vi+1].recipe_data ? (v.recipe_data.saltPct > dtVersions[vi+1].recipe_data.saltPct ? 'chip-up' : v.recipe_data.saltPct < dtVersions[vi+1].recipe_data.saltPct ? 'chip-down' : '') : ''">
                                                {{ v.recipe_data.saltPct }}% zout
                                            </span>
                                            <span class="version-inline-chip"
                                                  :class="vi < dtVersions.length-1 && dtVersions[vi+1].recipe_data ? ((v.recipe_data.methodDays||[]).length !== (dtVersions[vi+1].recipe_data.methodDays||[]).length ? 'chip-up' : '') : ''">
                                                {{ (v.recipe_data.methodDays || []).length }} dag{{ (v.recipe_data.methodDays||[]).length !== 1 ? 'en' : '' }}
                                            </span>
                                        </div>
                                    </div>
                                    <time>{{ v.created_at }}</time>
                                    <div v-if="dtEditingNoteId === v.id" style="display:flex;align-items:center;gap:0.35rem;margin-top:0.2rem">
                                        <input type="text" v-model="dtEditingNoteText" class="form-control" style="font-size:0.78rem;padding:0.15rem 0.4rem;height:auto;max-width:220px" placeholder="Notitie..." @keyup.enter="saveDtVersionNote(v)" @keyup.escape="cancelEditDtVersionNote()" />
                                        <button class="btn btn-ghost btn-sm" @click="saveDtVersionNote(v)" style="padding:0.15rem 0.5rem;font-size:0.78rem">✓</button>
                                        <button class="btn btn-ghost btn-sm" @click="cancelEditDtVersionNote()" style="padding:0.15rem 0.5rem;font-size:0.78rem">✕</button>
                                    </div>
                                    <div v-else style="display:flex;align-items:center;gap:0.3rem;margin-top:0.1rem">
                                        <span v-if="v.note" class="version-note-text">{{ v.note }}</span>
                                        <button class="btn btn-ghost btn-sm" @click="startEditDtVersionNote(v)" style="padding:0.1rem 0.3rem;font-size:0.72rem;opacity:0.5" title="Notitie bewerken"><i class="bi bi-pencil"></i></button>
                                    </div>
                                </div>
                                <div class="version-actions">
                                    <button class="btn btn-ghost btn-sm" @click="previewDtVersion(v.id)">{{ dtExpandedVersionIds[v.id] ? '▲ Sluiten' : '▼ Bekijk' }}</button>
                                    <button class="btn btn-ghost btn-sm" @click="restoreDtVersion(v.id)" v-if="v.version_number !== dtCurrentVersionNumber" title="Herstel als nieuwe actieve versie">Herstel</button>
                                    <button class="btn btn-ghost btn-sm" @click="deleteDtVersion(v.id)" v-if="v.version_number !== dtCurrentVersionNumber" title="Verwijder versie" style="color:#dc2626;border-color:#fecaca"><i class="bi bi-trash"></i></button>
                                </div>
                            </div>
                            <div v-if="dtExpandedVersionIds[v.id] && v.recipe_data" class="version-accordion">
                                <!-- Changes vs previous version -->
                                <template v-if="vi < dtVersions.length-1 && dtVersions[vi+1].recipe_data">
                                    <div class="version-detail-section-title" style="margin-bottom:0.35rem">Wijzigingen t.o.v. versie {{ dtVersions[vi+1].version_number }}</div>
                                    <div class="version-changes-box">
                                        <template v-if="dtVersionDiff(v.recipe_data, dtVersions[vi+1].recipe_data).length">
                                            <div v-for="change in dtVersionDiff(v.recipe_data, dtVersions[vi+1].recipe_data)" :key="change.label" class="version-diff-line">
                                                <span class="version-diff-label">{{ change.label }}</span>
                                                <span class="version-diff-from">{{ change.from }}</span>
                                                <span class="version-diff-arrow">→</span>
                                                <span class="version-diff-to" :class="change.increased ? 'diff-up' : change.decreased ? 'diff-down' : 'diff-neutral'">{{ change.to }}</span>
                                            </div>
                                        </template>
                                        <span v-else style="font-size:0.78rem;color:#9ca3af;font-style:italic">Geen wijzigingen gevonden</span>
                                    </div>
                                </template>
                                <!-- Full details -->
                                <div class="version-detail-grid">
                                    <div class="version-detail-section">
                                        <div class="version-detail-section-title">Deeg</div>
                                        <div class="version-detail-row">Hydratatie: <strong>{{ v.recipe_data.hydration }}%</strong></div>
                                        <div class="version-detail-row">Zout: <strong>{{ v.recipe_data.saltPct }}%</strong></div>
                                    </div>
                                    <div class="version-detail-section">
                                        <div class="version-detail-section-title">Rijsmiddelen</div>
                                        <div class="version-detail-row" v-if="v.recipe_data.useSourdough">
                                            Zuurdesem: <strong>{{ v.recipe_data.sourdoughPct }}%</strong>
                                            <span style="color:#9ca3af;font-size:0.75rem">({{ v.recipe_data.sourdoughHydration }}% hydr.)</span>
                                        </div>
                                        <div class="version-detail-row" v-if="v.recipe_data.useYeast">
                                            Gist: <strong>{{ v.recipe_data.yeastPct }}%</strong>
                                        </div>
                                        <div class="version-detail-row" v-if="v.recipe_data.usePreFerment">
                                            Voordeeg: <strong>{{ v.recipe_data.preFermentPct }}%</strong>
                                            <span style="color:#9ca3af;font-size:0.75rem">({{ v.recipe_data.preFermentHydration }}% hydr.)</span>
                                        </div>
                                        <div v-if="!v.recipe_data.useSourdough && !v.recipe_data.useYeast && !v.recipe_data.usePreFerment" style="font-size:0.78rem;color:#9ca3af">Geen rijsmiddelen</div>
                                    </div>
                                    <div class="version-detail-section" v-if="v.recipe_data.mainDoughGrains && v.recipe_data.mainDoughGrains.length">
                                        <div class="version-detail-section-title">Meel (hoofddeeg)</div>
                                        <div v-for="g in v.recipe_data.mainDoughGrains" :key="g.type" class="version-detail-row">
                                            <strong>{{ g.pct }}%</strong> {{ grainName(g.type) }}
                                        </div>
                                    </div>
                                    <div class="version-detail-section" v-if="v.recipe_data.methodDays && v.recipe_data.methodDays.length">
                                        <div class="version-detail-section-title">Methode ({{ v.recipe_data.methodDays.length }} dag{{ v.recipe_data.methodDays.length !== 1 ? 'en' : '' }})</div>
                                        <div v-for="(day, di) in v.recipe_data.methodDays" :key="di" class="version-detail-row">
                                            <strong>{{ day.label || 'Dag ' + (di+1) }}</strong>
                                            <span style="color:#9ca3af;font-size:0.75rem"> · {{ (day.steps||[]).length }} stap{{ (day.steps||[]).length !== 1 ? 'pen' : '' }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- ═══ CALCULATOR VIEW ═══ -->
        <div class="top-bar" v-show="calculatorActive && !doughTypeEditActive">
            <button class="btn-back" @click="backToRecipes"><i class="bi bi-arrow-left"></i> Recepten</button>
            <div class="recipe-name-group">
                <input type="text" v-model="recipeName" class="recipe-name-input" placeholder="Receptnaam...">
                <input type="text" v-model="recipeDescription" class="recipe-desc-input" placeholder="Omschrijving (optioneel)...">
            </div>
            <div class="dough-type-select">
                <template v-if="isDoughType">
                    <span class="deegsoort-badge"><i class="bi bi-layers-fill"></i> Is deegsoort</span>
                    <button type="button" class="btn-icon" style="width:26px;height:26px" @click="isDoughType = false; doughTypeId = null" title="Verwijder deegsoort markering"><i class="bi bi-x"></i></button>
                </template>
                <template v-else>
                    <select :value="doughTypeId" @change="onDoughTypeChange($event.target.value ? parseInt($event.target.value) : null)" class="form-select-sm">
                        <option :value="null">— Geen basis —</option>
                        <option v-for="dt in doughTypes" :key="dt.id" :value="dt.id">{{ dt.name }}</option>
                    </select>
                    <button type="button" class="btn-is-deegsoort" @click="isDoughType = true; doughTypeId = null; mixins = []; toppings = []; if (activeTab === 'toevoegingen') activeTab = 'recept'" title="Dit recept definieert een deegsoort"><i class="bi bi-layers"></i> Is deegsoort</button>
                </template>
            </div>
            <input type="text" v-model="versionNote" class="form-input" style="width:160px;font-size:0.78rem;padding:0.35rem 0.6rem" placeholder="Versienoot (optioneel)..." title="Wordt opgeslagen bij volgende versie">
            <button class="btn btn-success" @click="saveRecipe" :disabled="saving"><i class="bi bi-save"></i> {{ currentRecipeId ? 'Opslaan' : 'Bewaar' }}</button>

            <button class="btn btn-ghost" @click="duplicateRecipe" v-if="currentRecipeId"><i class="bi bi-copy"></i> Dupliceer</button>
        </div>

        <div class="tabs" v-show="calculatorActive && !doughTypeEditActive">
            <div class="tab" :class="{active: activeTab==='recept'}" @click="setRecipeTab('recept')">Recept</div>
            <div class="tab" :class="{active: activeTab==='meel'}" @click="setRecipeTab('meel')">Meel & Voordeeg</div>
            <div v-if="!isDoughType" class="tab" :class="{active: activeTab==='toevoegingen'}" @click="setRecipeTab('toevoegingen')">Toevoegingen</div>
            <div class="tab" :class="{active: activeTab==='overzicht'}" @click="setRecipeTab('overzicht')">Overzicht</div>
            <div class="tab" :class="{active: activeTab==='methode'}" @click="setRecipeTab('methode')">Methode</div>
            <div v-if="currentRecipeId" class="tab" :class="{active: activeTab==='versies'}" @click="setRecipeTab('versies')">
                Versies <span v-if="versions.length" style="background:#e5e7eb;border-radius:10px;padding:0.05rem 0.4rem;font-size:0.7rem;margin-left:0.2rem;font-weight:700">{{ versions.length }}</span>
            </div>
        </div>

        <div class="layout" v-if="!doughTypeEditActive">
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
                            <div class="form-group" v-if="!isDoughType">
                                <label class="form-label">Deeggewicht per stuk</label>
                                <div class="spin-field">
                                    <button type="button" class="spin-btn" @click="doughWeight = Math.max(1, (doughWeight||0) - 10)">−</button>
                                    <input type="number" v-model.number="doughWeight" class="form-input" min="1" step="10">
                                    <span class="input-unit">g</span>
                                    <button type="button" class="spin-btn spin-btn-r" @click="doughWeight = (doughWeight||0) + 10">+</button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Hydratatie</label>
                                <div class="spin-field">
                                    <button type="button" class="spin-btn" @click="!isInherited && (hydration = Math.max(30, (hydration||0) - 1))" :disabled="isInherited">−</button>
                                    <input type="number" v-model.number="hydration" class="form-input" min="30" max="120" step="1" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit" :class="{'inherited-field': isInherited}">%</span>
                                    <button type="button" class="spin-btn spin-btn-r" @click="!isInherited && (hydration = Math.min(120, (hydration||0) + 1))" :disabled="isInherited">+</button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Zout</label>
                                <div class="spin-field">
                                    <button type="button" class="spin-btn" @click="!isInherited && (saltPct = Math.max(0, Math.round(((saltPct||0) - 0.1) * 10) / 10))" :disabled="isInherited">−</button>
                                    <input type="number" v-model.number="saltPct" class="form-input" min="0" max="10" step="0.1" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit" :class="{'inherited-field': isInherited}">%</span>
                                    <button type="button" class="spin-btn spin-btn-r" @click="!isInherited && (saltPct = Math.min(10, Math.round(((saltPct||0) + 0.1) * 10) / 10))" :disabled="isInherited">+</button>
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
                                <div class="spin-field">
                                    <button type="button" class="spin-btn" @click="!isInherited && (sourdoughPct = Math.max(0, (sourdoughPct||0) - 1))" :disabled="isInherited">−</button>
                                    <input type="number" v-model.number="sourdoughPct" class="form-input" min="0" max="100" step="0.5" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit" :class="{'inherited-field': isInherited}">%</span>
                                    <button type="button" class="spin-btn spin-btn-r" @click="!isInherited && (sourdoughPct = Math.min(100, (sourdoughPct||0) + 1))" :disabled="isInherited">+</button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Hydratatie zuurdesem</label>
                                <div class="spin-field">
                                    <button type="button" class="spin-btn" @click="!isInherited && (sourdoughHydration = Math.max(50, (sourdoughHydration||0) - 5))" :disabled="isInherited">−</button>
                                    <input type="number" v-model.number="sourdoughHydration" class="form-input" min="50" max="200" step="1" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit" :class="{'inherited-field': isInherited}">%</span>
                                    <button type="button" class="spin-btn spin-btn-r" @click="!isInherited && (sourdoughHydration = Math.min(200, (sourdoughHydration||0) + 5))" :disabled="isInherited">+</button>
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
                                <div class="spin-field">
                                    <button type="button" class="spin-btn" @click="!isInherited && (yeastPct = Math.max(0, Math.round(((yeastPct||0) - 0.1) * 10) / 10))" :disabled="isInherited">−</button>
                                    <input type="number" v-model.number="yeastPct" class="form-input" min="0" max="10" step="0.1" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit" :class="{'inherited-field': isInherited}">%</span>
                                    <button type="button" class="spin-btn spin-btn-r" @click="!isInherited && (yeastPct = Math.min(10, Math.round(((yeastPct||0) + 0.1) * 10) / 10))" :disabled="isInherited">+</button>
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
                                <select v-model="grain.type" class="form-select" :disabled="isInherited" :class="{'inherited-field': isInherited}" @change="grain.brand_ingredient_id = null">
                                    <option v-for="g in grainTypes" :value="g.id">{{ g.name }}</option>
                                </select>
                            </div>
                            <div class="form-group" v-if="grainBrandsByParent[grain.type] && grainBrandsByParent[grain.type].length" style="flex:1.5;min-width:130px">
                                <select v-model="grain.brand_ingredient_id" class="form-select" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <option :value="null">Alle merken</option>
                                    <option v-for="b in grainBrandsByParent[grain.type]" :key="b.id" :value="b.id">{{ b.label }}</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <div class="spin-field">
                                    <button type="button" class="spin-btn" @click="!isInherited && (grain.pct = Math.max(0, (grain.pct||0) - 1))" :disabled="isInherited">−</button>
                                    <input type="number" v-model.number="grain.pct" class="form-input" min="0" max="100" step="1" placeholder="%" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit" :class="{'inherited-field': isInherited}">%</span>
                                    <button type="button" class="spin-btn spin-btn-r" @click="!isInherited && (grain.pct = Math.min(100, (grain.pct||0) + 1))" :disabled="isInherited">+</button>
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
                                <select v-model="grain.type" class="form-select" :disabled="isInherited" :class="{'inherited-field': isInherited}" @change="grain.brand_ingredient_id = null">
                                    <option v-for="g in grainTypes" :value="g.id">{{ g.name }}</option>
                                </select>
                            </div>
                            <div class="form-group" v-if="grainBrandsByParent[grain.type] && grainBrandsByParent[grain.type].length" style="flex:1.5;min-width:130px">
                                <select v-model="grain.brand_ingredient_id" class="form-select" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <option :value="null">Alle merken</option>
                                    <option v-for="b in grainBrandsByParent[grain.type]" :key="b.id" :value="b.id">{{ b.label }}</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <div class="spin-field">
                                    <button type="button" class="spin-btn" @click="!isInherited && (grain.pct = Math.max(0, (grain.pct||0) - 1))" :disabled="isInherited">−</button>
                                    <input type="number" v-model.number="grain.pct" class="form-input" min="0" max="100" step="1" placeholder="%" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit" :class="{'inherited-field': isInherited}">%</span>
                                    <button type="button" class="spin-btn spin-btn-r" @click="!isInherited && (grain.pct = Math.min(100, (grain.pct||0) + 1))" :disabled="isInherited">+</button>
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
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem">
                            <label class="form-label" style="margin:0">Meelsoorten in hoofddeeg</label>
                            <div class="radio-group">
                                <span class="radio-pill" :class="{active: mainDoughPctMode==='separate'}" @click="!isInherited && (mainDoughPctMode='separate')" title="Percentages van hoofddeeg meel alleen">Los</span>
                                <span class="radio-pill" :class="{active: mainDoughPctMode==='integrated'}" @click="!isInherited && (mainDoughPctMode='integrated')" title="Percentages van totaal meel (inclusief zuurdesem/voordeeg)">Totaal meel</span>
                            </div>
                        </div>
                        <div v-if="mainDoughPctMode==='integrated'" class="inherited-banner" style="margin-bottom:0.75rem;font-size:0.75rem">
                            <i class="bi bi-info-circle"></i>
                            <span>Percentages zijn van <strong>totaal meel</strong> — gewicht toont hoeveel van dit meel in het hoofddeeg gaat (excl. zuurdesem/voordeeg bijdrage).</span>
                        </div>
                        <div class="grain-row" v-for="(grain, i) in mainDoughGrains" :key="'md'+i">
                            <div class="form-group">
                                <select v-model="grain.type" class="form-select" :disabled="isInherited" :class="{'inherited-field': isInherited}" @change="grain.brand_ingredient_id = null">
                                    <option v-for="g in grainTypes" :value="g.id">{{ g.name }}</option>
                                </select>
                            </div>
                            <div class="form-group" v-if="grainBrandsByParent[grain.type] && grainBrandsByParent[grain.type].length" style="flex:1.5;min-width:130px">
                                <select v-model="grain.brand_ingredient_id" class="form-select" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <option :value="null">Alle merken</option>
                                    <option v-for="b in grainBrandsByParent[grain.type]" :key="b.id" :value="b.id">{{ b.label }}</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <div class="spin-field">
                                    <button type="button" class="spin-btn" @click="!isInherited && (grain.pct = Math.max(0, (grain.pct||0) - 1))" :disabled="isInherited">−</button>
                                    <input type="number" v-model.number="grain.pct" class="form-input" min="0" max="100" step="1" placeholder="%" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit" :class="{'inherited-field': isInherited}">%</span>
                                    <button type="button" class="spin-btn spin-btn-r" @click="!isInherited && (grain.pct = Math.min(100, (grain.pct||0) + 1))" :disabled="isInherited">+</button>
                                </div>
                            </div>
                            <button class="btn-remove" @click="mainDoughGrains.splice(i,1)" v-if="!isInherited && mainDoughGrains.length > 1"><i class="bi bi-x"></i></button>
                            <span class="weight-tag">{{ formatW(mainDoughGrainDetail(i).total) }}g</span>
                        </div>
                        <button class="btn-add" @click="mainDoughGrains.push({type:'wheat',pct:0,brand_ingredient_id:null})" v-if="!isInherited && mainDoughGrains.length < 5">
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
                        <div v-if="isInherited && mixins.length" class="inherited-banner" style="margin-bottom:0.75rem">
                            <i class="bi bi-link-45deg"></i>
                            <span>Mix-ins overgenomen van deegsoort <strong>{{ doughTypes.find(d => d.id == doughTypeId)?.name }}</strong>. Bewerk de deegsoort om te wijzigen.</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
                            <div class="panel-title" style="margin-bottom:0">Mix-ins</div>
                            <div class="radio-group">
                                <span class="radio-pill" :class="{active: mixinMode==='flour'}" @click="!isInherited && (mixinMode='flour')" :style="isInherited ? 'opacity:0.5;cursor:default' : ''">% van meel</span>
                                <span class="radio-pill" :class="{active: mixinMode==='dough'}" @click="!isInherited && (mixinMode='dough')" :style="isInherited ? 'opacity:0.5;cursor:default' : ''">% van deeg</span>
                            </div>
                        </div>
                        <div v-if="mixins.length === 0" class="empty-state" style="padding:1rem">
                            <p style="color:#bbb">Nog geen mix-ins toegevoegd</p>
                        </div>
                        <div class="mixin-row" v-for="(m, i) in mixins" :key="'mx'+i">
                            <div class="form-group">
                                <select v-model="m.ingredient" class="form-select" @change="!isInherited && autoCategory(m)" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <option value="">Kies ingrediënt...</option>
                                    <option v-for="ing in mixinIngredients" :key="ing.id" :value="ing.name">{{ ing.name }}</option>
                                </select>
                            </div>
                            <div class="form-group" style="flex:0.6">
                                <div class="input-with-unit">
                                    <input type="number" v-model.number="m.pct" class="form-input" min="0" max="100" step="0.5" :disabled="isInherited" :class="{'inherited-field': isInherited}">
                                    <span class="input-unit" :class="{'inherited-field': isInherited}">%</span>
                                </div>
                            </div>
                            <div class="form-group" style="flex:0.8">
                                <div class="radio-group">
                                    <span class="radio-pill" :class="{active: m.category==='non-integrated'}" @click="!isInherited && (m.category='non-integrated')" :style="isInherited ? 'opacity:0.5;cursor:default' : ''" style="font-size:0.7rem;padding:0.2rem 0.5rem">Vast</span>
                                    <span class="radio-pill" :class="{active: m.category==='integrated'}" @click="!isInherited && (m.category='integrated')" :style="isInherited ? 'opacity:0.5;cursor:default' : ''" style="font-size:0.7rem;padding:0.2rem 0.5rem">Integratie</span>
                                    <span class="radio-pill" :class="{active: m.category==='liquid'}" @click="!isInherited && (m.category='liquid')" :style="isInherited ? 'opacity:0.5;cursor:default' : ''" style="font-size:0.7rem;padding:0.2rem 0.5rem">Vloeistof</span>
                                </div>
                            </div>
                            <button class="btn-remove" @click="mixins.splice(i,1)" v-if="!isInherited"><i class="bi bi-x"></i></button>
                            <span class="weight-tag">{{ formatW(mixinWeight(i)) }}g</span>
                        </div>
                        <button class="btn-add" @click="mixins.push({ingredient:'',pct:0,category:'non-integrated'})" v-if="mixins.length < 16 && !isInherited">
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
                                class="method-mainstep"
                                :class="{ dragging: dragStep && dragStep.di === di && dragStep.si === si, 'drag-over': dragOverStep && dragOverStep.di === di && dragOverStep.si === si }"
                                draggable="true"
                                @dragstart="onStepDragStart(di, si, $event)"
                                @dragover.prevent="onStepDragOver(di, si, $event)"
                                @dragleave="onStepDragLeave(di, si)"
                                @drop.prevent="onStepDrop(di, si)"
                                @dragend="onStepDragEnd()">
                                <div class="mainstep-header">
                                    <span class="method-step-handle" title="Sleep om te verplaatsen"><i class="bi bi-grip-vertical"></i></span>
                                    <span class="method-step-num">{{ si + 1 }}</span>
                                    <input type="text" v-model="step.title" class="mainstep-title-input" placeholder="Naam van deze stap (bijv. Deegmengen)...">
                                    <button class="btn-remove" @click="removeStep(di, si)" v-if="day.steps.length > 1" title="Stap verwijderen"><i class="bi bi-x"></i></button>
                                </div>

                                <div class="substeps-list" v-if="step.substeps && step.substeps.length > 0">
                                    <div v-for="(sub, ssi) in step.substeps" :key="ssi" class="method-substep">
                                        <div class="substep-actie">
                                            <select v-model="sub.actie" class="substep-select">
                                                <option value="">actie...</option>
                                                <option value="kneden">Kneden</option>
                                                <option value="vouwen">Vouwen</option>
                                                <option value="rust">Rust</option>
                                                <option value="toevoegen">Toevoegen</option>
                                                <option value="vormen">Vormen</option>
                                                <option value="rijzen">Rijzen</option>
                                                <option value="bakken">Bakken</option>
                                                <option value="afkoelen">Afkoelen</option>
                                                <option value="overig">Overig</option>
                                            </select>
                                        </div>
                                        <div class="substep-field" title="Tijd in minuten">
                                            <div class="spin-field">
                                                <button type="button" class="spin-btn" @click="sub.tijd = Math.max(0, (sub.tijd||0) - 1)">−</button>
                                                <input type="number" v-model.number="sub.tijd" class="form-input" min="0" placeholder="0">
                                                <span class="input-unit">min</span>
                                                <button type="button" class="spin-btn spin-btn-r" @click="sub.tijd = (sub.tijd||0) + 1">+</button>
                                            </div>
                                        </div>
                                        <div class="substep-field" title="Temperatuur in °C (optioneel)">
                                            <div class="spin-field">
                                                <button type="button" class="spin-btn" @click="sub.temp = Math.max(0, (sub.temp||0) - 5)">−</button>
                                                <input type="number" v-model.number="sub.temp" class="form-input" min="0" max="300" placeholder="°C">
                                                <span class="input-unit">°C</span>
                                                <button type="button" class="spin-btn spin-btn-r" @click="sub.temp = Math.min(300, (sub.temp||0) + 5)">+</button>
                                            </div>
                                        </div>
                                        <div class="substep-desc">
                                            <input type="text" v-model="sub.beschrijving" class="form-input" placeholder="Omschrijving...">
                                        </div>
                                        <button class="btn-remove" @click="step.substeps.splice(ssi, 1)" title="Substap verwijderen"><i class="bi bi-x"></i></button>
                                    </div>
                                </div>
                                <button class="substep-add-btn" @click="step.substeps.push({ actie: '', tijd: null, temp: null, beschrijving: '' })">
                                    <i class="bi bi-plus"></i> Substap toevoegen
                                </button>
                            </div>

                            <button class="method-add-step" @click="addStep(di)"><i class="bi bi-plus"></i> Stap toevoegen</button>
                        </div>
                        <button class="method-add-day" @click="addDay()" v-if="!isInherited">
                            <i class="bi bi-plus-lg"></i> Dag toevoegen
                        </button>
                    </div>
                </div>

                <!-- ═══ VERSIES TAB ═══ -->
                <div v-show="calculatorActive && activeTab==='versies'" style="padding:1.25rem 0">
                    <div class="panel" style="margin-bottom:0.75rem">
                        <div class="panel-title">Versienoot bij volgende opslag</div>
                        <textarea v-model="versionNote" class="version-note-input" rows="2" placeholder="Optionele toelichting bij de volgende keer opslaan..."></textarea>
                    </div>
                    <div class="panel">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.875rem">
                            <div class="panel-title" style="margin-bottom:0">Versiegeschiedenis</div>
                            <span style="font-size:0.75rem;color:#9ca3af">{{ versions.length }} versie{{ versions.length !== 1 ? 's' : '' }}</span>
                        </div>
                        <div v-if="versions.length === 0" class="empty-state">
                            <i class="bi bi-clock-history"></i>
                            <p>Nog geen versiegeschiedenis. Versies worden aangemaakt bij elke opslag.</p>
                        </div>
                        <div v-for="(v, vi) in versions" :key="v.id">
                            <div class="version-row" :style="expandedVersionIds[v.id] ? 'border-radius:4px 4px 0 0' : ''">
                                <div class="version-meta">
                                    <div style="display:flex;align-items:center;gap:0.5rem">
                                        <strong>Versie {{ v.version_number }}</strong>
                                        <span v-if="v.version_number === currentVersionNumber" class="version-badge-active">Actief</span>
                                    </div>
                                    <time>{{ formatDate(v.created_at) }}</time>
                                    <span v-if="v.note" class="version-note-text">{{ v.note }}</span>
                                </div>
                                <div class="version-actions">
                                    <button class="btn btn-ghost btn-sm" @click="previewRecipeVersion(v.id)">{{ expandedVersionIds[v.id] ? '▲ Sluiten' : '▼ Bekijk' }}</button>
                                    <button class="btn btn-ghost btn-sm" @click="restoreVersion(v.id)" v-if="v.version_number !== currentVersionNumber" title="Herstel als nieuwe actieve versie">Herstel</button>
                                    <a :href="'logboek.php?recipe_version_id=' + v.id" class="btn btn-ghost btn-sm" target="_blank" title="Bakacties voor deze versie"><i class="bi bi-journal-text"></i></a>
                                </div>
                            </div>
                            <div v-if="expandedVersionIds[v.id] && v.recipe_data" class="version-accordion">
                                <!-- Changes vs previous version -->
                                <template v-if="versions[vi+1]">
                                    <div class="version-detail-section-title" style="margin-bottom:0.35rem">Wijzigingen t.o.v. versie {{ versions[vi+1].version_number }}</div>
                                    <div class="version-changes-box">
                                        <template v-if="dtVersionDiff(v.recipe_data, versions[vi+1].recipe_data).length">
                                            <div v-for="change in dtVersionDiff(v.recipe_data, versions[vi+1].recipe_data)" :key="change.label" class="version-diff-line">
                                                <span class="version-diff-label">{{ change.label }}</span>
                                                <span class="version-diff-from">{{ change.from }}</span>
                                                <span class="version-diff-arrow">→</span>
                                                <span class="version-diff-to" :class="change.increased ? 'diff-up' : change.decreased ? 'diff-down' : 'diff-neutral'">{{ change.to }}</span>
                                            </div>
                                        </template>
                                        <span v-else style="color:#9ca3af;font-size:0.8rem">Geen gemeten wijzigingen</span>
                                    </div>
                                </template>
                                <div class="version-detail-grid">
                                    <div class="version-detail-section">
                                        <div class="version-detail-section-title">Deeg</div>
                                        <div class="version-detail-row">Hydratatie: <strong>{{ v.recipe_data.hydration }}%</strong></div>
                                        <div class="version-detail-row">Zout: <strong>{{ v.recipe_data.saltPct }}%</strong></div>
                                        <div class="version-detail-row" v-if="v.recipe_data.doughWeight">Deeggewicht: <strong>{{ v.recipe_data.doughWeight }}g</strong></div>
                                    </div>
                                    <div class="version-detail-section">
                                        <div class="version-detail-section-title">Rijsmiddelen</div>
                                        <div class="version-detail-row" v-if="v.recipe_data.useSourdough">
                                            Zuurdesem: <strong>{{ v.recipe_data.sourdoughPct }}%</strong> ({{ v.recipe_data.sourdoughHydration }}% hydr.)
                                        </div>
                                        <div class="version-detail-row" v-if="v.recipe_data.useYeast">
                                            Gist: <strong>{{ v.recipe_data.yeastPct }}%</strong>
                                        </div>
                                        <div class="version-detail-row" v-if="v.recipe_data.usePreFerment">
                                            Voordeeg: <strong>{{ v.recipe_data.preFermentPct }}%</strong> ({{ v.recipe_data.preFermentHydration }}% hydr.)
                                        </div>
                                        <div class="version-detail-row" v-if="!v.recipe_data.useSourdough && !v.recipe_data.useYeast && !v.recipe_data.usePreFerment" style="color:#9ca3af">Geen</div>
                                    </div>
                                    <div class="version-detail-section" v-if="v.recipe_data.mainDoughGrains && v.recipe_data.mainDoughGrains.length">
                                        <div class="version-detail-section-title">Meel (hoofddeeg)</div>
                                        <div v-for="g in v.recipe_data.mainDoughGrains" :key="g.type" class="version-detail-row">
                                            <strong>{{ g.pct }}%</strong> {{ grainName(g.type) }}
                                        </div>
                                    </div>
                                    <div class="version-detail-section" v-if="v.recipe_data.mixins && v.recipe_data.mixins.length">
                                        <div class="version-detail-section-title">Mix-ins</div>
                                        <div v-for="m in v.recipe_data.mixins.filter(x => x.ingredient && x.pct > 0)" :key="m.ingredient" class="version-detail-row">
                                            <strong>{{ m.pct }}%</strong> {{ m.ingredient }}
                                        </div>
                                    </div>
                                    <div class="version-detail-section" v-if="v.recipe_data.toppings && v.recipe_data.toppings.length">
                                        <div class="version-detail-section-title">Toppings</div>
                                        <div v-for="t in v.recipe_data.toppings.filter(x => x.ingredient && x.pct > 0)" :key="t.ingredient" class="version-detail-row">
                                            <strong>{{ t.pct }}%</strong> {{ t.ingredient }}
                                        </div>
                                    </div>
                                    <div class="version-detail-section" v-if="v.recipe_data.methodDays && v.recipe_data.methodDays.length">
                                        <div class="version-detail-section-title">Methode ({{ v.recipe_data.methodDays.length }} dag{{ v.recipe_data.methodDays.length !== 1 ? 'en' : '' }})</div>
                                        <div v-for="(day, di) in v.recipe_data.methodDays" :key="di" class="version-detail-row">
                                            <strong>{{ day.label || 'Dag ' + (di+1) }}</strong>
                                            <span style="color:#9ca3af;font-size:0.75rem"> · {{ (day.steps||[]).length }} stap{{ (day.steps||[]).length !== 1 ? 'pen' : '' }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>



            <div class="calc-sidebar" v-show="calculatorActive">
                <div class="summary-card" style="margin-bottom:0.75rem">
                    <div class="summary-header" style="background:#5c3d1e">
                        <h3>Deeg Eigenschappen</h3>
                    </div>
                    <div class="summary-body">
                        <div class="summary-row">
                            <span class="summary-label">Hydratatie</span>
                            <span class="summary-value">{{ formatP(hydration) }}%</span>
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
                        <div v-if="isInherited" style="font-size:0.68rem;color:#9ca3af;margin-top:0.4rem">
                            van deegsoort {{ doughTypes.find(d => d.id == doughTypeId)?.name }}
                        </div>
                    </div>
                </div>
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
                </div>
            </div>
        </div>
    </div>

    <script src="../../js/ui-notifications.js?v=1"></script>
    <script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js"></script>
    <script>
    const { createApp } = Vue;
    const app = createApp({
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
                doughTypeEditActive: false,
                dtActiveTab: 'recept',
                editingDoughType: null,
                dtVersions: [],
                dtCurrentVersionNumber: 1,
                dtVersionNote: '',
                dtExpandedVersionIds: {},
                expandedVersionIds: {},
                dtEditingNoteId: null,
                dtEditingNoteText: '',
                dtEditingVersionNumberId: null,
                dtEditingVersionNumberVal: 1,
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
                methodDays: [{ label: 'Dag 1', steps: [{ title: '', substeps: [] }] }],
                dragStep: null,
                dragOverStep: null,
                isDoughType: false,
                mainDoughPctMode: 'separate',
                savedRecipes: [],
                collapsedGroups: {},
                draggingGroupId: null,
                draggingGroupOverId: null,
                draggingRecipeId: null,
                draggingRecipeOverId: null,
                saving: false,
                toastMsg: '',
                versions: [],
                currentVersionNumber: 1,
                versionNote: '',

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

            grainBrandsByParent() {
                const map = {};
                for (const i of this.allIngredients || []) {
                    if (i.category === 'meel' && i.parent_id) {
                        const pid = parseInt(i.parent_id);
                        if (!map[pid]) map[pid] = [];
                        map[pid].push({ id: i.id, label: i.brand_name || i.name });
                    }
                }
                return map;
            },

            dtGrainsForFerments() {
                const dt = this.editingDoughType;
                if (!dt || dt.mainDoughPctMode !== 'integrated' || !dt.mainDoughGrains || dt.mainDoughGrains.length === 0) {
                    return this.grainTypes;
                }
                const selectedIds = new Set(dt.mainDoughGrains.map(g => g.type));
                return this.grainTypes.filter(g => selectedIds.has(g.id));
            },

            dtGrainCharacteristics() {
                if (!this.editingDoughType || this.grainTypes.length === 0) return null;
                const dt = this.editingDoughType;
                const sourdoughShare = dt.useSourdough ? (dt.sourdoughPct || 0) / 100 : 0;
                const preFermentShare = dt.usePreFerment ? (dt.preFermentPct || 0) / 100 : 0;
                const mainShare = Math.max(0, 1 - sourdoughShare - preFermentShare);
                const grainWeights = {};
                const addGrains = (grains, share) => {
                    if (share <= 0 || !grains) return;
                    grains.forEach(g => {
                        if (!grainWeights[g.type]) grainWeights[g.type] = 0;
                        grainWeights[g.type] += share * (g.pct || 0) / 100;
                    });
                };
                if (dt.mainDoughPctMode === 'integrated') {
                    addGrains(dt.mainDoughGrains, 1.0);
                } else {
                    if (dt.useSourdough) addGrains(dt.sourdoughGrains, sourdoughShare);
                    if (dt.usePreFerment) addGrains(dt.preFermentGrains, preFermentShare);
                    addGrains(dt.mainDoughGrains, mainShare);
                }
                const total = Object.values(grainWeights).reduce((s, v) => s + v, 0);
                if (total === 0) return null;
                let wholeGrain = 0;
                Object.entries(grainWeights).forEach(([type, w]) => {
                    const grain = this.grainTypes.find(g => g.id == type);
                    if (grain && grain.isWholeGrain) wholeGrain += w;
                });
                const wholePct = (wholeGrain / total) * 100;
                const typeMap = {};
                Object.entries(grainWeights).forEach(([type, w]) => {
                    const grain = this.grainTypes.find(g => g.id == type);
                    if (!grain || w <= 0) return;
                    const gtId = grain.grainTypeId;
                    const gtName = gtId
                        ? ((this.grainTypeNames.find(g => g.id == gtId) || {}).name || 'Onbekend')
                        : 'Onbekend';
                    const key = gtId !== null ? gtId : 'unknown';
                    if (!typeMap[key]) typeMap[key] = { name: gtName, amount: 0 };
                    typeMap[key].amount += w;
                });
                const grainDist = Object.values(typeMap)
                    .map(t => ({ name: t.name, pct: (t.amount / total) * 100 }))
                    .filter(t => t.pct > 0)
                    .sort((a, b) => b.pct - a.pct);
                return { wholePct, whitePct: 100 - Math.round(wholePct * 10) / 10, grainDist };
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
                const base = this.mainDoughPctMode === 'integrated' ? this.totalFlour : this.mainDoughFlour;
                const total = base * ((g.pct || 0) / 100);
                return { total };
            },

            addDay() {
                if (this.isInherited) return;
                this.methodDays.push({ label: 'Dag ' + (this.methodDays.length + 1), steps: [''] });
            },
            async removeDay(di) {
                if (this.isInherited || this.methodDays.length <= 1) return;
                const hasContent = this.methodDays[di].steps.some(s => {
                    const title = typeof s === 'string' ? s : (s.title || '');
                    const hasSubs = typeof s === 'object' && s.substeps && s.substeps.length > 0;
                    return title.trim() || hasSubs;
                });
                if (hasContent && !await showConfirm('Dag ' + (di + 1) + ' bevat stappen. Weet je zeker dat je deze wilt verwijderen?')) return;
                this.methodDays.splice(di, 1);
            },
            addStep(di) {
                this.methodDays[di].steps.push({ title: '', substeps: [] });
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
                this.methodDays = JSON.parse(JSON.stringify(this.inheritedMethodDays)).map(day => ({
                    ...day,
                    steps: (day.steps || []).map(s => typeof s === 'string' ? { title: s, substeps: [] } : { title: '', substeps: [], ...s })
                }));
            },
            syncMethodDaysToInheritedDayCount() {
                if (!this.inheritedMethodDays) return;
                const target = this.inheritedMethodDays.length;
                while (this.methodDays.length < target) {
                    this.methodDays.push({ label: 'Dag ' + (this.methodDays.length + 1), steps: [{ title: '', substeps: [] }] });
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
                    mainDoughPctMode: this.mainDoughPctMode,
                    methodDays: this.methodDays,
                };
                if (!this.isDoughType) {
                    data.doughWeight = this.doughWeight;
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
                // Backward compat: convert string steps to { title, substeps } objects
                this.methodDays = this.methodDays.map(day => ({
                    ...day,
                    steps: (day.steps || []).map(step =>
                        typeof step === 'string'
                            ? { title: step, substeps: [] }
                            : { title: '', substeps: [], ...step }
                    )
                }));
                if (!this.methodDays.length) {
                    this.methodDays = [{ label: 'Dag 1', steps: [{ title: '', substeps: [] }] }];
                }
                this.mainDoughPctMode = d.mainDoughPctMode || 'separate';
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
                    const body = {
                        name: this.recipeName,
                        dough_type_id: this.doughTypeId,
                        is_dough_type: this.isDoughType ? 1 : 0,
                        recipe_data: this.getRecipeData(),
                        version_note: this.versionNote || null,
                    };
                    if (this.currentRecipeId) body.id = this.currentRecipeId;
                    const method = this.currentRecipeId ? 'PUT' : 'POST';
                    const res = await fetch('../../api/baker-recipes.php', { method, headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
                    const data = await res.json();
                    if (data.success) {
                        if (!this.currentRecipeId && data.id) this.currentRecipeId = data.id;
                        if (data.dough_type_id) this.doughTypeId = data.dough_type_id;
                        if (this.isDoughType) await this.reloadDoughTypes();
                        this.versionNote = '';
                        // Refresh version list
                        if (this.currentRecipeId) await this.loadVersions(this.currentRecipeId);
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
                        this.versions = data.recipe.versions || [];
                        this.currentVersionNumber = data.recipe.current_version || 1;
                        this.versionNote = '';
                        this.applyRecipeData(data.recipe.recipe_data);
                        this.calculatorActive = true;
                        this.activeTab = 'recept';
                        history.replaceState(null, '', '#r-' + data.recipe.id);
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
                this.versions = [];
                this.currentVersionNumber = 1;
                history.replaceState(null, '', window.location.pathname);
                this.loadSavedRecipes();
            },

            async loadVersions(recipeId) {
                try {
                    const res = await fetch('../../api/baker-recipes.php?id=' + recipeId);
                    const data = await res.json();
                    if (data.success) {
                        this.versions = data.recipe.versions || [];
                        this.currentVersionNumber = data.recipe.current_version || 1;
                    }
                } catch (e) { console.error(e); }
            },

            async previewVersion(versionId) {
                try {
                    const res = await fetch('../../api/baker-recipes.php?version_id=' + versionId);
                    const data = await res.json();
                    if (data.success) {
                        const v = data.version;
                        this.applyRecipeData(v.recipe_data);
                        this.recipeName = v.name;
                        this.showToast('Versie ' + v.version_number + ' geladen (niet opgeslagen)');
                    }
                } catch (e) { console.error(e); }
            },

            async restoreVersion(versionId) {
                if (!await showConfirm('Weet je zeker dat je deze versie wilt herstellen? Dit maakt een nieuwe actieve versie aan.')) return;
                try {
                    const res = await fetch('../../api/baker-recipes.php', {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'restore_version', version_id: versionId }),
                    });
                    const data = await res.json();
                    if (data.success) {
                        await this.loadRecipe(this.currentRecipeId);
                        this.activeTab = 'versies';
                        this.showToast('Versie hersteld als nieuwe actieve versie');
                    }
                } catch (e) { console.error(e); }
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
                    this.methodDays = [{ label: 'Dag 1', steps: [{ title: '', substeps: [] }] }];
                    this.versions = [];
                    this.currentVersionNumber = 1;
                    this.versionNote = '';
                    this.mainDoughPctMode = 'separate';
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
                            .filter(i => i.category === 'meel' && !i.parent_id)
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
                        
                        this.mixinIngredients = data.ingredients
                            .filter(i => (i.category === 'mixin' || i.category === 'topping') && !i.parent_id)
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
                    if (rd.mainDoughPctMode !== undefined) this.mainDoughPctMode = rd.mainDoughPctMode;
                    if (rd.useYeast !== undefined) this.useYeast = rd.useYeast;
                    if (rd.yeastType !== undefined) this.yeastType = rd.yeastType;
                    if (rd.yeastPct !== undefined) this.yeastPct = rd.yeastPct;
                    if (rd.mixinMode !== undefined) this.mixinMode = rd.mixinMode;
                    if (rd.mixins !== undefined) this.mixins = JSON.parse(JSON.stringify(rd.mixins));
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
                    mainDoughPctMode: 'integrated',
                    useYeast: false,
                    yeastType: this.yeastTypes[0]?.id ?? 'instant_yeast',
                    yeastPct: 1.3,
                    mixinMode: 'flour',
                    mixins: [],
                    methodDays: [{ label: 'Dag 1', steps: [{ title: '', substeps: [] }] }],
                };
                this.calculatorActive = false;
                this.doughTypeEditActive = true;
                this.dtActiveTab = 'recept';
                this.dtVersions = [];
                this.dtVersionNote = '';
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
                    mainDoughPctMode: rd.mainDoughPctMode ?? 'integrated',
                    useYeast: rd.useYeast ?? false,
                    yeastType: rd.yeastType ?? (this.yeastTypes[0]?.id ?? 'instant_yeast'),
                    yeastPct: rd.yeastPct ?? 1.3,
                    mixinMode: rd.mixinMode ?? 'flour',
                    mixins: rd.mixins ? JSON.parse(JSON.stringify(rd.mixins)) : [],
                    methodDays: (rd.methodDays ? JSON.parse(JSON.stringify(rd.methodDays)) : [{ label: 'Dag 1', steps: [{ title: '', substeps: [] }] }]).map(day => ({
                        ...day,
                        steps: (day.steps || []).map(s => typeof s === 'string' ? { title: s, substeps: [] } : { title: '', substeps: [], ...s })
                    })),
                };
                this.calculatorActive = false;
                this.doughTypeEditActive = true;
                this.dtActiveTab = 'recept';
                this.dtVersionNote = '';
                this.dtVersions = [];
                this.dtCurrentVersionNumber = 1;
                this.dtExpandedVersionIds = {};
                this.loadDtVersions(dt.id);
                history.replaceState(null, '', '#dt-' + dt.id);
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
                    mainDoughPctMode: dt.mainDoughPctMode,
                    useYeast: dt.useYeast, yeastType: dt.yeastType, yeastPct: dt.yeastPct,
                    mixinMode: dt.mixinMode,
                    mixins: dt.mixins,
                    methodDays: dt.methodDays,
                };
                try {
                    if (dt.id) {
                        await fetch('../../api/dough-types.php', {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: dt.id, name: dt.name, recipe_data: recipeData, version_note: this.dtVersionNote || null })
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
                this.doughTypeEditActive = false;
                this.editingDoughType = null;
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

            backFromDoughType() {
                this.doughTypeEditActive = false;
                this.editingDoughType = null;
                history.replaceState(null, '', window.location.pathname);
            },

            async loadDtVersions(doughTypeId) {
                try {
                    const res = await fetch('../../api/dough-types.php?id=' + doughTypeId);
                    const data = await res.json();
                    if (data.success) {
                        this.dtVersions = data.dough_type.versions || [];
                        this.dtCurrentVersionNumber = data.dough_type.current_version || 1;
                    }
                } catch (e) { console.error(e); }
            },

            previewDtVersion(versionId) {
                if (this.dtExpandedVersionIds[versionId]) {
                    delete this.dtExpandedVersionIds[versionId];
                } else {
                    this.dtExpandedVersionIds[versionId] = true;
                }
            },

            previewRecipeVersion(versionId) {
                if (this.expandedVersionIds[versionId]) {
                    this.expandedVersionIds = { ...this.expandedVersionIds };
                    delete this.expandedVersionIds[versionId];
                } else {
                    this.expandedVersionIds = { ...this.expandedVersionIds, [versionId]: true };
                }
            },

            dtVersionDiff(a, b) {
                if (!a || !b) return [];
                const changes = [];
                const numCheck = (field, label, decimals = 1) => {
                    const va = parseFloat(a[field]), vb = parseFloat(b[field]);
                    if (isNaN(va) || isNaN(vb) || va === vb) return;
                    changes.push({ label, from: vb + '%', to: va + '%', increased: va > vb, decreased: va < vb });
                };
                const boolCheck = (field, label) => {
                    if (!!a[field] === !!b[field]) return;
                    changes.push({ label, from: b[field] ? 'Aan' : 'Uit', to: a[field] ? 'Aan' : 'Uit', increased: !!a[field], decreased: !a[field] });
                };
                numCheck('hydration', 'Hydratatie');
                numCheck('saltPct', 'Zout', 2);
                boolCheck('useSourdough', 'Zuurdesem');
                if (a.useSourdough && b.useSourdough) {
                    numCheck('sourdoughPct', 'Zuurdesem %');
                    numCheck('sourdoughHydration', 'Zuurdesem hydr.');
                }
                boolCheck('useYeast', 'Gist');
                if (a.useYeast && b.useYeast) numCheck('yeastPct', 'Gist %');
                boolCheck('usePreFerment', 'Voordeeg');
                if (a.usePreFerment && b.usePreFerment) {
                    numCheck('preFermentPct', 'Voordeeg %');
                    numCheck('preFermentHydration', 'Voordeeg hydr.');
                }
                const md_a = (a.methodDays || []).length, md_b = (b.methodDays || []).length;
                if (md_a !== md_b) changes.push({ label: 'Methode', from: md_b + (md_b !== 1 ? ' dagen' : ' dag'), to: md_a + (md_a !== 1 ? ' dagen' : ' dag'), increased: md_a > md_b, decreased: md_a < md_b });
                // Per-grain comparison
                const grainsA = a.mainDoughGrains || [];
                const grainsB = b.mainDoughGrains || [];
                if (JSON.stringify(grainsA) !== JSON.stringify(grainsB)) {
                    const allTypes = [...new Set([...grainsA.map(g => g.type), ...grainsB.map(g => g.type)])];
                    allTypes.forEach(type => {
                        const ga = grainsA.find(g => g.type === type);
                        const gb = grainsB.find(g => g.type === type);
                        const pctA = ga ? ga.pct : 0, pctB = gb ? gb.pct : 0;
                        if (pctA !== pctB) {
                            changes.push({ label: this.grainName(type), from: pctB + '%', to: pctA + '%', increased: pctA > pctB, decreased: pctA < pctB });
                        }
                    });
                }
                return changes;
            },

            setDtTab(tab) {
                this.dtActiveTab = tab;
                if (this.editingDoughType && this.editingDoughType.id) {
                    history.replaceState(null, '', '#dt-' + this.editingDoughType.id + (tab !== 'recept' ? '/' + tab : ''));
                }
            },

            setRecipeTab(tab) {
                this.activeTab = tab;
                if (this.currentRecipeId) {
                    history.replaceState(null, '', '#r-' + this.currentRecipeId + (tab !== 'recept' ? '/' + tab : ''));
                }
            },

            async parseAndApplyHash() {
                const hash = window.location.hash.slice(1);
                if (!hash) return;
                const match = hash.match(/^(dt|r)-(\d+)(?:\/(.+))?$/);
                if (!match) return;
                const type = match[1];
                const id = parseInt(match[2]);
                const tab = match[3] || 'recept';
                if (type === 'dt') {
                    const dt = this.doughTypes.find(d => d.id == id);
                    if (dt) {
                        this.editDoughType(dt);
                        this.$nextTick(() => { this.setDtTab(tab); });
                    }
                } else if (type === 'r') {
                    await this.loadRecipe(id);
                    this.$nextTick(() => { this.activeTab = tab; });
                }
            },

            async restoreDtVersion(versionId) {
                if (!await showConfirm('Weet je zeker dat je deze versie wilt herstellen? Dit maakt een nieuwe actieve versie aan.')) return;
                try {
                    const res = await fetch('../../api/dough-types.php', {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'restore_version', version_id: versionId }),
                    });
                    const data = await res.json();
                    if (data.success) {
                        await this.loadDtVersions(this.editingDoughType.id);
                        this.dtActiveTab = 'versies';
                        this.showToast('Versie hersteld als nieuwe actieve versie');
                    }
                } catch (e) { console.error(e); }
            },

            startEditDtVersionNumber(v) {
                this.dtEditingVersionNumberId = v.id;
                this.dtEditingVersionNumberVal = v.version_number;
            },
            cancelEditDtVersionNumber() {
                this.dtEditingVersionNumberId = null;
            },
            async saveDtVersionNumber(v) {
                const newNum = this.dtEditingVersionNumberVal;
                if (!newNum || newNum < 1) return;
                try {
                    const res = await fetch('../../api/dough-types.php', {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'update_version_number', version_id: v.id, version_number: newNum }),
                    });
                    const data = await res.json();
                    if (data.success) {
                        const wasActive = v.version_number === this.dtCurrentVersionNumber;
                        v.version_number = newNum;
                        if (wasActive) this.dtCurrentVersionNumber = newNum;
                        this.dtEditingVersionNumberId = null;
                        this.showToast('Versienummer bijgewerkt');
                    } else {
                        alert(data.error || 'Opslaan mislukt');
                    }
                } catch (e) { console.error(e); }
            },
            startEditDtVersionNote(v) {
                this.dtEditingNoteId = v.id;
                this.dtEditingNoteText = v.note || '';
            },
            cancelEditDtVersionNote() {
                this.dtEditingNoteId = null;
                this.dtEditingNoteText = '';
            },
            async saveDtVersionNote(v) {
                try {
                    const res = await fetch('../../api/dough-types.php', {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'update_version_note', version_id: v.id, note: this.dtEditingNoteText }),
                    });
                    const data = await res.json();
                    if (data.success) {
                        v.note = this.dtEditingNoteText;
                        this.dtEditingNoteId = null;
                        this.dtEditingNoteText = '';
                        this.showToast('Notitie opgeslagen');
                    } else {
                        alert(data.error || 'Opslaan mislukt');
                    }
                } catch (e) { console.error(e); }
            },
            async deleteDtVersion(versionId) {
                if (!await showConfirm('Weet je zeker dat je deze versie wilt verwijderen? Dit kan niet ongedaan worden gemaakt.')) return;
                try {
                    const res = await fetch('../../api/dough-types.php', {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete_version', version_id: versionId }),
                    });
                    const data = await res.json();
                    if (data.success) {
                        delete this.dtExpandedVersionIds[versionId];
                        await this.loadDtVersions(this.editingDoughType.id);
                        this.showToast('Versie verwijderd');
                    } else {
                        alert(data.error || 'Verwijderen mislukt');
                    }
                } catch (e) { console.error(e); }
            },
        },

        mounted() {
            this.loadIngredients();
            this.loadGrainTypeNames();
            this.loadSavedRecipes().then(() => this.parseAndApplyHash());
            this.loadUtilityCosts();
        }
    });
    window.vueApp = app.mount('#app');
    </script>
    <script>
    function newDeegsoort() { if (window.vueApp) window.vueApp.newDoughType(); }
    function nieuwRecept()   { if (window.vueApp) { if (window.vueApp.calculatorActive) window.vueApp.backToRecipes(); setTimeout(() => window.vueApp.newRecipe(), 50); } }
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
