<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$adminPageTitle = 'Receptversies';
$currentPage    = 'recipe-versions';
$adminBasePath  = '../';

// ── Load all recipes with their versions ──────────────────────────────────────
$recipes = $pdo->query("
    SELECT br.id, br.name, br.current_version, dt.name as dough_type_name
    FROM baker_recipes br
    LEFT JOIN dough_types dt ON dt.id = br.dough_type_id
    WHERE br.is_dough_type = 0 OR br.is_dough_type IS NULL
    ORDER BY dt.sort_order ASC, dt.name ASC, br.sort_order ASC, br.name ASC
")->fetchAll();

$recipeIds = array_column($recipes, 'id');
$versionsByRecipe = [];
$outOfSync = 0;
$totalVersions = 0;

if ($recipeIds) {
    $ph = implode(',', array_fill(0, count($recipeIds), '?'));
    $vStmt = $pdo->prepare("
        SELECT recipe_id, id as version_id, version_number, dough_type_version_number, loaf_minor_version, note, created_at
        FROM baker_recipe_versions
        WHERE recipe_id IN ($ph)
        ORDER BY recipe_id ASC, version_number ASC
    ");
    $vStmt->execute($recipeIds);
    foreach ($vStmt->fetchAll() as $v) {
        $versionsByRecipe[(int)$v['recipe_id']][] = $v;
        $totalVersions++;
        $hasMajor = $v['dough_type_version_number'] !== null;
        $hasMinor = $v['loaf_minor_version'] !== null;
        if ($hasMajor !== $hasMinor) { // one field set, the other missing
            $outOfSync++;
        }
    }
}

$inSync = $totalVersions - $outOfSync;
?>
<?php include '../components/header.php'; ?>
<style>
.audit-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.audit-table th { background: #f5f2ed; color: #5c3d1e; font-weight: 600; padding: 0.5rem 0.75rem; text-align: left; border-bottom: 2px solid #d4b896; white-space: nowrap; }
.audit-table td { padding: 0.4rem 0.75rem; border-bottom: 1px solid #f0ece7; vertical-align: middle; }
.audit-table tr:last-child td { border-bottom: none; }
.recipe-block { background: white; border-radius: 10px; box-shadow: 0 1px 6px rgba(0,0,0,0.07); margin-bottom: 1.25rem; overflow: hidden; }
.recipe-header { display: flex; align-items: center; gap: 0.75rem; padding: 0.7rem 1rem; background: #faf7f3; border-bottom: 1px solid #ede8e0; }
.recipe-header h3 { margin: 0; font-size: 0.95rem; color: #1f2937; }
.recipe-header .dt-tag { font-size: 0.75rem; color: #92400e; background: #fef3c7; border-radius: 4px; padding: 0.1rem 0.4rem; }
.badge-active  { background: #dcfce7; color: #166534; font-size: 0.7rem; padding: 0.1rem 0.4rem; border-radius: 4px; font-weight: 600; }
.badge-sync    { background: #dcfce7; color: #166534; font-size: 0.7rem; padding: 0.1rem 0.4rem; border-radius: 4px; }
.badge-nosync  { background: #fee2e2; color: #991b1b; font-size: 0.7rem; padding: 0.1rem 0.4rem; border-radius: 4px; }
.badge-legacy  { background: #f3f4f6; color: #6b7280; font-size: 0.7rem; padding: 0.1rem 0.4rem; border-radius: 4px; }
.ver-display   { font-weight: 600; color: #1f2937; }
.ver-db        { font-family: monospace; color: #374151; }
.summary-bar   { display: flex; gap: 1.5rem; background: white; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; box-shadow: 0 1px 6px rgba(0,0,0,0.07); flex-wrap: wrap; align-items: center; }
.summary-stat  { display: flex; flex-direction: column; }
.summary-stat strong { font-size: 1.4rem; font-weight: 700; }
.summary-stat span   { font-size: 0.75rem; color: #6b7280; }
.stat-good  strong { color: #166534; }
.stat-bad   strong { color: #991b1b; }
.stat-total strong { color: #1f2937; }
.filter-bar { display: flex; gap: 0.75rem; margin-bottom: 1.25rem; flex-wrap: wrap; align-items: center; }
.filter-bar label { font-size: 0.85rem; color: #374151; display: flex; align-items: center; gap: 0.35rem; cursor: pointer; }
</style>

<div style="padding: 1.5rem 2rem; max-width: 1100px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:0.75rem">
        <h1 style="margin:0;font-size:1.3rem;color:#1f2937">Receptversie audit</h1>
        <a href="../migrations/072_renumber_version_numbers.php" target="_blank"
           style="background:#8b5a2b;color:white;padding:0.5rem 1rem;border-radius:8px;text-decoration:none;font-size:0.85rem">
            ↻ Hernummer versies (migratie 072)
        </a>
    </div>

    <!-- Summary -->
    <div class="summary-bar">
        <div class="summary-stat stat-total">
            <strong><?= $totalVersions ?></strong>
            <span>Versies totaal</span>
        </div>
        <div class="summary-stat stat-good">
            <strong><?= $inSync ?></strong>
            <span>In sync</span>
        </div>
        <div class="summary-stat stat-bad">
            <strong><?= $outOfSync ?></strong>
            <span>Niet in sync</span>
        </div>
        <div class="summary-stat stat-total" style="margin-left:auto">
            <strong><?= count($recipes) ?></strong>
            <span>Recepten</span>
        </div>
    </div>

    <!-- Filter -->
    <div class="filter-bar">
        <label><input type="checkbox" id="filterOutOfSync" onchange="applyFilter()"> Alleen niet-gesyncte versies tonen</label>
        <label><input type="checkbox" id="filterOnlyActive" onchange="applyFilter()"> Alleen actieve versie per recept</label>
    </div>

    <!-- Per-recipe blocks -->
    <?php foreach ($recipes as $recipe):
        $versions = $versionsByRecipe[(int)$recipe['id']] ?? [];
        $hasOos   = false;
        foreach ($versions as $v) {
            if (($v['dough_type_version_number'] !== null) !== ($v['loaf_minor_version'] !== null)) { $hasOos = true; break; }
        }
    ?>
    <div class="recipe-block" data-has-oos="<?= $hasOos ? '1' : '0' ?>">
        <div class="recipe-header">
            <h3><?= htmlspecialchars($recipe['name']) ?></h3>
            <?php if ($recipe['dough_type_name']): ?>
            <span class="dt-tag"><?= htmlspecialchars($recipe['dough_type_name']) ?></span>
            <?php endif; ?>
            <?php if ($hasOos): ?>
            <span style="margin-left:auto;font-size:0.75rem;color:#991b1b;font-weight:600">⚠ niet in sync</span>
            <?php else: ?>
            <span style="margin-left:auto;font-size:0.75rem;color:#166534">✓ in sync</span>
            <?php endif; ?>
        </div>

        <?php if (empty($versions)): ?>
        <div style="padding:0.75rem 1rem;color:#9ca3af;font-size:0.82rem">Geen versiegeschiedenis</div>
        <?php else: ?>
        <table class="audit-table">
            <thead>
                <tr>
                    <th>Frontend display</th>
                    <th>DB version_number</th>
                    <th>dough_type_version_number</th>
                    <th>loaf_minor_version</th>
                    <th>Status</th>
                    <th>Notitie</th>
                    <th>Aangemaakt</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($versions as $v):
                $isActive  = (int)$v['version_number'] === (int)$recipe['current_version'];
                $isCompound = $v['dough_type_version_number'] !== null && $v['loaf_minor_version'] !== null;
                $isHalfFormed = ($v['dough_type_version_number'] !== null) !== ($v['loaf_minor_version'] !== null);
                $isSynced   = !$isHalfFormed;

                if ($isCompound) {
                    $displayStr = 'v' . $v['dough_type_version_number'] . '.' . $v['loaf_minor_version'];
                } else {
                    $displayStr = 'v' . $v['version_number'];
                }
            ?>
            <tr class="version-row<?= $isActive ? ' row-active' : '' ?>"
                data-oos="<?= (!$isSynced) ? '1' : '0' ?>"
                data-active="<?= $isActive ? '1' : '0' ?>"
                style="<?= !$isSynced ? 'background:#fff8f8' : '' ?>">
                <td class="ver-display"><?= htmlspecialchars($displayStr) ?></td>
                <td class="ver-db"><?= (int)$v['version_number'] ?></td>
                <td style="color:<?= $v['dough_type_version_number'] !== null ? '#374151' : '#d1d5db' ?>">
                    <?= $v['dough_type_version_number'] !== null ? (int)$v['dough_type_version_number'] : '—' ?>
                </td>
                <td style="color:<?= $v['loaf_minor_version'] !== null ? '#374151' : '#d1d5db' ?>">
                    <?= $v['loaf_minor_version'] !== null ? (int)$v['loaf_minor_version'] : '—' ?>
                </td>
                <td>
                    <?php if ($isActive): ?><span class="badge-active">Actief</span><?php endif; ?>
                    <?php if (!$isCompound): ?>
                        <span class="badge-legacy">Legacy</span>
                    <?php elseif ($isSynced): ?>
                        <span class="badge-sync">In sync</span>
                    <?php else: ?>
                        <span class="badge-nosync">Niet in sync</span>
                    <?php endif; ?>
                </td>
                <td style="color:#6b7280;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($v['note'] ?? '') ?>">
                    <?= htmlspecialchars($v['note'] ?? '—') ?>
                </td>
                <td style="color:#9ca3af;white-space:nowrap"><?= date('d M y, H:i', strtotime($v['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<script>
function applyFilter() {
    const filterOos    = document.getElementById('filterOutOfSync').checked;
    const filterActive = document.getElementById('filterOnlyActive').checked;

    document.querySelectorAll('.recipe-block').forEach(block => {
        const hasOos = block.dataset.hasOos === '1';
        if (filterOos && !hasOos) { block.style.display = 'none'; return; }
        block.style.display = '';

        block.querySelectorAll('.version-row').forEach(row => {
            const isOos    = row.dataset.oos === '1';
            const isActive = row.dataset.active === '1';
            let hide = false;
            if (filterOos && !isOos) hide = true;
            if (filterActive && !isActive) hide = true;
            row.style.display = hide ? 'none' : '';
        });
    });
}
</script>

<?php include '../components/footer.php'; ?>
