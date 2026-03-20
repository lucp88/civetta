<?php
require_once '../config.php';
requireLogin();

$categories = $pdo->query("SELECT * FROM product_categories ORDER BY sort_order ASC, naam ASC")->fetchAll();
$products = $pdo->query("SELECT * FROM products ORDER BY sort_order ASC, naam ASC")->fetchAll();
$variants = $pdo->query("SELECT id, product_id, naam, gewicht, prijs, recipe_id, foto FROM product_variants ORDER BY product_id ASC, sort_order ASC, gewicht ASC")->fetchAll();
$variantsByProduct = [];
foreach ($variants as $v) {
    $variantsByProduct[$v['product_id']][] = $v;
}
$productsByCategory = [];
foreach ($products as $p) {
    $productsByCategory[$p['category_id'] ?? 0][] = $p;
}

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$recipes = $pdo->query("SELECT id, name, dough_type_id FROM baker_recipes ORDER BY name ASC")->fetchAll();

$adminPageTitle = 'Producten';
$currentPage = 'products';
$adminBasePath = '../';

ob_start(); ?>
<style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--cream);
            min-height: 100vh;
        }
        .admin-content { padding: 2rem; }
        @media (max-width: 768px) { .admin-content { padding: 1.25rem; } }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .card h2 {
            color: #2d4a2d;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: #3d6b3d;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
        }
        .btn:hover { background: #2d4a2d; }
        .btn-small { padding: 0.35rem 0.75rem; font-size: 0.85rem; }
        .btn-danger { background: #c00; }
        .btn-danger:hover { background: #900; }

        table { width: 100%; border-collapse: collapse; }
        th, td {
            text-align: left;
            padding: 0.75rem;
            border-bottom: 1px solid #e8dfd2;
            vertical-align: middle;
        }
        th {
            color: #3d6b3d;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding-bottom: 0.5rem;
        }

        /* Product group rows */
        tr.product-group-row { cursor: pointer; }
        tr.product-group-row:hover td { background: #faf8f5; }
        tr.product-group-row td { border-bottom: 1px solid #e8dfd2; }
        .product-chevron {
            display: inline-flex;
            align-items: center;
            margin-right: 0.35rem;
            color: #888;
            transition: transform 0.15s;
            font-size: 0.75rem;
        }
        .product-chevron.collapsed { transform: rotate(-90deg); }
        .product-naam { font-weight: 600; color: #3d2b1f; }
        .product-count {
            display: inline-block;
            background: #e8dfd2;
            color: #5c3d1e;
            font-size: 0.72rem;
            font-weight: 700;
            border-radius: 10px;
            padding: 0.1rem 0.45rem;
            margin-left: 0.4rem;
            vertical-align: middle;
        }
        .product-beschrijving { color: #888; font-size: 0.85rem; max-width: 250px; }

        /* Variant rows */
        tr.variant-row td { background: #fafaf8; border-bottom: 1px solid #f0ebe5; }
        tr.variant-row:last-child td { border-bottom: 1px solid #e8dfd2; }
        tr.variant-row:hover td { background: #f5f2ed; }
        .variant-naam {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding-left: 1rem;
            color: #4a433d;
            font-size: 0.9rem;
        }
        .variant-weight { color: #888; font-size: 0.8rem; }
        .variant-price { font-weight: 600; color: #2d4a2d; font-size: 0.9rem; }

        /* Drag */
        .drag-handle { color: #ccc; cursor: grab; padding: 0 0.25rem; font-size: 1rem; display: inline-flex; align-items: center; }
        .drag-handle:active { cursor: grabbing; }
        tr.drag-over td { background: #dbeafe !important; }
        tr.dragging { opacity: 0.4; }
        tbody.group-drag-over tr.product-group-row td { background: #dbeafe !important; }
        tbody.group-dragging { opacity: 0.4; }

        .actions { white-space: nowrap; }
        .actions a, .actions button { margin-right: 0.35rem; }

        .empty { color: #888; font-style: italic; padding: 2rem; text-align: center; }
        .drag-cell { width: 28px; padding-right: 0 !important; }

        tr.variant-row { cursor: pointer; }

        .btn-ghost { background: transparent; border: 1.5px solid #e0d5c7; color: #555; }
        .btn-ghost:hover { border-color: #888; background: #f5f0e8; color: #333; }

        .btn-add { border: 1px dashed #d1d5db; border-radius: 4px; background: transparent; color: #9ca3af; cursor: pointer; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.25rem 0.5rem; }
        .btn-add:hover { border-color: #8b5a2b; color: #8b5a2b; background: #faf6f1; }
        tr.variant-add-row td { background: #fafaf8; border-bottom: 1px solid #e8dfd2; padding: 0.3rem 0.75rem !important; }
        tr.variant-add-row { cursor: pointer; }
        tr.variant-add-row:hover td { background: #f5f2ed; }

        /* Inline variant edit row */
        tr.variant-edit-row td { background: #f0ece6; border-bottom: 1px solid #c8bfb5; padding: 0.75rem !important; }
        .ve-form { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: flex-end; }
        .ve-form input[type="text"], .ve-form input[type="number"] {
            padding: 0.4rem 0.6rem; border: 1.5px solid #d4c8b8; border-radius: 6px;
            font-size: 0.88rem; font-family: inherit; background: white; }
        .ve-form input:focus { outline: none; border-color: #3d6b3d; }
        .ve-naam { width: 140px; }
        .ve-gewicht { width: 85px; }
        .ve-prijs { width: 85px; }
        .ve-recipe { flex: 1; min-width: 170px; padding: 0.4rem 0.6rem; border: 1.5px solid #d4c8b8; border-radius: 6px; font-size: 0.88rem; font-family: inherit; background: white; }
        .ve-recipe:focus { outline: none; border-color: #3d6b3d; }
        .ve-foto-wrap { display: flex; align-items: center; gap: 0.4rem; }
        .ve-foto-thumb { width: 44px; height: 33px; object-fit: cover; border-radius: 4px; border: 1px solid #d4c8b8; }
        .ve-foto-file { font-size: 0.78rem; max-width: 180px; }
        .ve-actions { display: flex; gap: 0.4rem; align-items: center; margin-top: 0.35rem; width: 100%; }
        .ve-spacer { flex: 1; }
        .variant-foto-thumb { width: 36px; height: 27px; object-fit: cover; border-radius: 3px; opacity: 0.85; }

        /* Category sections */
        .category-section { margin-bottom: 1.25rem; }
        .category-header {
            display: flex; align-items: center; gap: 0.6rem;
            padding: 0.65rem 1rem; background: white;
            border-radius: 10px 10px 0 0; border: 1px solid #e8dfd2;
            border-bottom: 2px solid #c8913a;
        }
        .category-section.cat-collapsed .category-header { border-radius: 10px; border-bottom: 1px solid #e8dfd2; }
        .cat-chevron { color: #888; font-size: 0.75rem; transition: transform 0.15s; cursor: pointer; }
        .cat-chevron.collapsed { transform: rotate(-90deg); }
        .cat-naam { font-weight: 700; color: #3d2b1f; font-size: 1rem; flex: 1; }
        .cat-naam-input { font-weight: 700; color: #3d2b1f; font-size: 1rem; flex: 1; border: 1.5px solid #c8913a; border-radius: 6px; padding: 0.2rem 0.5rem; font-family: inherit; outline: none; }
        .cat-actions { display: flex; gap: 0.4rem; align-items: center; }
        .cat-body { border: 1px solid #e8dfd2; border-top: none; border-radius: 0 0 10px 10px; overflow: hidden; }
        .cat-body.collapsed { display: none; }
        .cat-card { border-radius: 0; box-shadow: none; margin: 0; padding: 0; }
        .cat-card table { border-radius: 0; }

        /* Add category form */
        .add-cat-form { display: flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1rem; background: white; border: 1px dashed #c8bfb5; border-radius: 10px; }
        .add-cat-form input { padding: 0.4rem 0.7rem; border: 1.5px solid #d4c8b8; border-radius: 6px; font-size: 0.95rem; font-family: inherit; width: 220px; }
        .add-cat-form input:focus { outline: none; border-color: #c8913a; }
    </style>
<?php $adminExtraHead = ob_get_clean();
require_once '../components/sidebar.php'; ?>

            <header class="topbar">
                <div class="topbar-left">
                    <span class="topbar-title">Producten</span>
                </div>
                <div class="topbar-right">
                    <button onclick="startAddCategory()" class="btn btn-small">+ Nieuwe categorie</button>
                </div>
            </header>

            <div class="admin-content">

                <?php foreach ($categories as $cat):
                    $catId = $cat['id'];
                    $catProducts = $productsByCategory[$catId] ?? [];
                ?>
                <div class="category-section" id="cat-section-<?= $catId ?>" data-cat-id="<?= $catId ?>">
                    <div class="category-header">
                        <span class="cat-chevron" id="cat-chevron-<?= $catId ?>" onclick="toggleCategory(<?= $catId ?>)"><i class="bi bi-chevron-down"></i></span>
                        <span class="cat-naam" id="cat-naam-display-<?= $catId ?>"><?= htmlspecialchars($cat['naam']) ?></span>
                        <div class="cat-actions">
                            <button class="btn btn-ghost btn-small" onclick="startRenameCategory(<?= $catId ?>)" title="Naam wijzigen"><i class="bi bi-pencil"></i></button>
                            <?php if (empty($catProducts)): ?>
                            <button class="btn btn-danger btn-small" onclick="deleteCategory(<?= $catId ?>)" title="Verwijderen"><i class="bi bi-trash"></i></button>
                            <?php endif; ?>
                            <a href="product-edit.php?category_id=<?= $catId ?>" class="btn btn-small">+ Nieuw product</a>
                        </div>
                    </div>
                    <div class="cat-body" id="cat-body-<?= $catId ?>">
                        <div class="card cat-card">
                            <?php if (empty($catProducts)): ?>
                                <div class="empty">Geen producten. <a href="product-edit.php?category_id=<?= $catId ?>">Voeg een product toe</a></div>
                            <?php else: ?>
                                <table>
                                    <thead>
                                        <tr>
                                            <th class="drag-cell"></th>
                                            <th>Product / Variant</th>
                                            <th>Beschrijving</th>
                                            <th>Prijs</th>
                                            <th>Recept</th>
                                            <th>Acties</th>
                                        </tr>
                                    </thead>
                                    <?php foreach ($catProducts as $product):
                                        $pv = $variantsByProduct[$product['id']] ?? [];
                                    ?>
                                    <tbody id="product-group-<?= $product['id'] ?>" data-product-id="<?= $product['id'] ?>" data-dough-type-id="<?= $product['dough_type_id'] ?? '' ?>" data-cat-id="<?= $catId ?>">
                                        <tr class="product-group-row" draggable="true" data-id="<?= $product['id'] ?>"
                                            onclick="toggleGroup(<?= $product['id'] ?>)">
                                            <td class="drag-cell" onclick="event.stopPropagation()">
                                                <span class="drag-handle"><i class="bi bi-grip-vertical"></i></span>
                                            </td>
                                            <td>
                                                <span class="product-chevron" id="chevron-<?= $product['id'] ?>"><i class="bi bi-chevron-down"></i></span>
                                                <span class="product-naam"><?= htmlspecialchars($product['naam']) ?></span>
                                                <?php if (!empty($pv)): ?>
                                                    <span class="product-count"><?= count($pv) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="product-beschrijving"><?= htmlspecialchars(substr($product['beschrijving'] ?? '', 0, 60)) ?><?= strlen($product['beschrijving'] ?? '') > 60 ? '...' : '' ?></td>
                                            <td></td>
                                            <td></td>
                                            <td class="actions" onclick="event.stopPropagation()">
                                                <a href="product-edit.php?id=<?= $product['id'] ?>" class="btn btn-small">Bewerken</a>
                                                <a href="product-delete.php?id=<?= $product['id'] ?>" class="btn btn-small btn-danger"
                                                   onclick="return confirmLink(this.href, 'Weet je zeker dat je dit product wilt verwijderen?')">Verwijderen</a>
                                            </td>
                                        </tr>
                                        <?php foreach ($pv as $v): ?>
                                        <tr class="variant-row" draggable="true"
                                            data-id="<?= $v['id'] ?>"
                                            data-product-id="<?= $product['id'] ?>"
                                            data-naam="<?= htmlspecialchars($v['naam'] ?? '') ?>"
                                            data-gewicht="<?= intval($v['gewicht']) ?>"
                                            data-prijs="<?= $v['prijs'] ?>"
                                            data-recipe-id="<?= $v['recipe_id'] ?? '' ?>"
                                            data-foto="<?= htmlspecialchars($v['foto'] ?? '') ?>"
                                            onclick="if(!this._dragged)openInlineEdit(this)"
                                            title="Klik om te bewerken">
                                            <td class="drag-cell"><span class="drag-handle"><i class="bi bi-grip-vertical"></i></span></td>
                                            <td>
                                                <div class="variant-naam">
                                                    <?php
                                                    $label = $v['naam'] ?? '';
                                                    $weightStr = $v['gewicht'] ? intval($v['gewicht']) . 'g' : '';
                                                    if ($label && $weightStr) {
                                                        echo htmlspecialchars($label) . ' <span class="variant-weight">— ' . $weightStr . '</span>';
                                                    } elseif ($label) {
                                                        echo htmlspecialchars($label);
                                                    } else {
                                                        echo '<span class="variant-weight">' . $weightStr . '</span>';
                                                    }
                                                    ?>
                                                </div>
                                            </td>
                                            <td><?php if (!empty($v['foto'])): ?><img src="../../<?= htmlspecialchars($v['foto']) ?>" class="variant-foto-thumb"><?php endif; ?></td>
                                            <td class="variant-price">&euro;<?= number_format($v['prijs'], 2, ',', '.') ?></td>
                                            <td class="variant-recipe-icon">
                                                <?php if (!empty($v['recipe_id'])): ?>
                                                    <span style="color:#2e7d32;font-size:0.85rem"><i class="bi bi-check-circle-fill"></i></span>
                                                <?php else: ?>
                                                    <span style="color:#ddd;font-size:0.85rem"><i class="bi bi-dash"></i></span>
                                                <?php endif; ?>
                                            </td>
                                            <td></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <tr class="variant-add-row" onclick="openInlineEdit(null, <?= $product['id'] ?>)">
                                            <td class="drag-cell"></td>
                                            <td colspan="5"><button class="btn-add"><i class="bi bi-plus"></i> Nieuwe variant</button></td>
                                        </tr>
                                    </tbody>
                                    <?php endforeach; ?>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if (empty($categories)): ?>
                    <div class="card"><div class="empty">Nog geen categorieën. Voeg een categorie toe via de knop rechtsboven.</div></div>
                <?php endif; ?>

                <!-- Uncategorized products -->
                <?php $uncategorized = $productsByCategory[0] ?? []; if (!empty($uncategorized)): ?>
                <div class="card" style="margin-top:1rem">
                    <h2>Overige producten <span style="font-size:0.8rem;color:#888;font-weight:400">(geen categorie)</span></h2>
                    <table>
                        <thead>
                            <tr>
                                <th class="drag-cell"></th>
                                <th>Product / Variant</th>
                                <th>Beschrijving</th>
                                <th>Prijs</th>
                                <th>Recept</th>
                                <th>Acties</th>
                            </tr>
                        </thead>
                        <?php foreach ($uncategorized as $product):
                            $pv = $variantsByProduct[$product['id']] ?? [];
                        ?>
                        <tbody id="product-group-<?= $product['id'] ?>" data-product-id="<?= $product['id'] ?>" data-dough-type-id="<?= $product['dough_type_id'] ?? '' ?>" data-cat-id="0">
                            <tr class="product-group-row" draggable="true" data-id="<?= $product['id'] ?>" onclick="toggleGroup(<?= $product['id'] ?>)">
                                <td class="drag-cell" onclick="event.stopPropagation()"><span class="drag-handle"><i class="bi bi-grip-vertical"></i></span></td>
                                <td>
                                    <span class="product-chevron" id="chevron-<?= $product['id'] ?>"><i class="bi bi-chevron-down"></i></span>
                                    <span class="product-naam"><?= htmlspecialchars($product['naam']) ?></span>
                                    <?php if (!empty($pv)): ?><span class="product-count"><?= count($pv) ?></span><?php endif; ?>
                                </td>
                                <td class="product-beschrijving"><?= htmlspecialchars(substr($product['beschrijving'] ?? '', 0, 60)) ?></td>
                                <td></td><td></td>
                                <td class="actions" onclick="event.stopPropagation()">
                                    <a href="product-edit.php?id=<?= $product['id'] ?>" class="btn btn-small">Bewerken</a>
                                    <a href="product-delete.php?id=<?= $product['id'] ?>" class="btn btn-small btn-danger" onclick="return confirmLink(this.href, 'Weet je zeker dat je dit product wilt verwijderen?')">Verwijderen</a>
                                </td>
                            </tr>
                            <?php foreach ($pv as $v): ?>
                            <tr class="variant-row" draggable="true" data-id="<?= $v['id'] ?>" data-product-id="<?= $product['id'] ?>" data-naam="<?= htmlspecialchars($v['naam'] ?? '') ?>" data-gewicht="<?= intval($v['gewicht']) ?>" data-prijs="<?= $v['prijs'] ?>" data-recipe-id="<?= $v['recipe_id'] ?? '' ?>" data-foto="<?= htmlspecialchars($v['foto'] ?? '') ?>" onclick="if(!this._dragged)openInlineEdit(this)" title="Klik om te bewerken">
                                <td class="drag-cell"><span class="drag-handle"><i class="bi bi-grip-vertical"></i></span></td>
                                <td><div class="variant-naam"><?php $label=$v['naam']??'';$ws=$v['gewicht']?intval($v['gewicht']).'g':'';if($label&&$ws)echo htmlspecialchars($label).' <span class="variant-weight">— '.$ws.'</span>';elseif($label)echo htmlspecialchars($label);else echo'<span class="variant-weight">'.$ws.'</span>'; ?></div></td>
                                <td><?php if(!empty($v['foto'])):?><img src="../../<?=htmlspecialchars($v['foto'])?>" class="variant-foto-thumb"><?php endif;?></td>
                                <td class="variant-price">&euro;<?=number_format($v['prijs'],2,',','.')?></td>
                                <td><?php if(!empty($v['recipe_id'])):?><span style="color:#2e7d32;font-size:0.85rem"><i class="bi bi-check-circle-fill"></i></span><?php else:?><span style="color:#ddd;font-size:0.85rem"><i class="bi bi-dash"></i></span><?php endif;?></td>
                                <td></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="variant-add-row" onclick="openInlineEdit(null, <?= $product['id'] ?>)">
                                <td class="drag-cell"></td>
                                <td colspan="5"><button class="btn-add"><i class="bi bi-plus"></i> Nieuwe variant</button></td>
                            </tr>
                        </tbody>
                        <?php endforeach; ?>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Add category form -->
                <div id="add-cat-form" class="add-cat-form" style="display:none;margin-top:1rem">
                    <input type="text" id="new-cat-naam" placeholder="Naam nieuwe categorie (bijv. Granola)"
                           onkeydown="if(event.key==='Enter')addCategory();if(event.key==='Escape')cancelAddCategory()">
                    <button class="btn btn-small" onclick="addCategory()">Toevoegen</button>
                    <button class="btn btn-ghost btn-small" onclick="cancelAddCategory()">Annuleren</button>
                </div>

            </div>
        </div>
    </div>


<script src="../../js/ui-notifications.js?v=1"></script>
<script>
const recipesData = <?= json_encode(array_values($recipes)) ?>;

(function() {
    // ── Category collapse / expand ─────────────────────────────────────
    const catCollapsed = new Set();

    window.toggleCategory = function(catId) {
        const body = document.getElementById('cat-body-' + catId);
        const chevron = document.getElementById('cat-chevron-' + catId);
        const section = document.getElementById('cat-section-' + catId);
        if (catCollapsed.has(catId)) {
            catCollapsed.delete(catId);
            body.classList.remove('collapsed');
            chevron.classList.remove('collapsed');
            section.classList.remove('cat-collapsed');
        } else {
            catCollapsed.add(catId);
            body.classList.add('collapsed');
            chevron.classList.add('collapsed');
            section.classList.add('cat-collapsed');
        }
    };

    // ── Category rename ────────────────────────────────────────────────
    window.startRenameCategory = function(catId) {
        const span = document.getElementById('cat-naam-display-' + catId);
        if (!span) return;
        const current = span.textContent.trim();
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'cat-naam-input';
        input.value = current;
        input.onblur = () => saveRenameCategory(catId, input);
        input.onkeydown = e => {
            if (e.key === 'Enter') saveRenameCategory(catId, input);
            if (e.key === 'Escape') { input.replaceWith(span); }
        };
        span.replaceWith(input);
        input.focus();
        input.select();
    };

    window.saveRenameCategory = async function(catId, input) {
        const naam = input.value.trim();
        if (!naam) { input.focus(); return; }
        const res = await fetch('../../api/products.php', {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'rename_category', id: catId, naam })
        });
        const json = await res.json();
        if (json.success) {
            const span = document.createElement('span');
            span.className = 'cat-naam';
            span.id = 'cat-naam-display-' + catId;
            span.textContent = json.naam;
            input.replaceWith(span);
        }
    };

    // ── Add category ───────────────────────────────────────────────────
    window.startAddCategory = function() {
        const form = document.getElementById('add-cat-form');
        form.style.display = 'flex';
        document.getElementById('new-cat-naam').focus();
    };

    window.cancelAddCategory = function() {
        document.getElementById('add-cat-form').style.display = 'none';
        document.getElementById('new-cat-naam').value = '';
    };

    window.addCategory = async function() {
        const naam = document.getElementById('new-cat-naam').value.trim();
        if (!naam) return;
        const res = await fetch('../../api/products.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'create_category', naam })
        });
        const json = await res.json();
        if (json.success) window.location.reload();
    };

    // ── Delete category ────────────────────────────────────────────────
    window.deleteCategory = async function(catId) {
        if (!confirm('Categorie verwijderen?')) return;
        const res = await fetch('../../api/products.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ category_id: catId })
        });
        const json = await res.json();
        if (json.success) window.location.reload();
        else alert(json.error || 'Fout bij verwijderen');
    };

    // ── Product collapse / expand ──────────────────────────────────────
    const collapsed = new Set();

    window.toggleGroup = function(productId) {
        const tbody = document.getElementById('product-group-' + productId);
        const chevron = document.getElementById('chevron-' + productId);
        if (collapsed.has(productId)) {
            collapsed.delete(productId);
            chevron.classList.remove('collapsed');
            tbody.querySelectorAll('tr.variant-row, tr.variant-add-row').forEach(r => r.style.display = '');
        } else {
            collapsed.add(productId);
            chevron.classList.add('collapsed');
            tbody.querySelectorAll('tr.variant-row, tr.variant-add-row').forEach(r => r.style.display = 'none');
        }
    };

    // ── Product group drag-to-reorder ─────────────────────────────────
    let draggingGroup = null;
    const table = document.querySelector('table');

    document.querySelectorAll('tbody[data-product-id]').forEach(tbody => {
        const groupRow = tbody.querySelector('tr.product-group-row');
        if (!groupRow) return;

        groupRow.addEventListener('dragstart', e => {
            draggingGroup = tbody;
            tbody.classList.add('group-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.stopPropagation();
        });
        groupRow.addEventListener('dragend', () => {
            draggingGroup = null;
            tbody.classList.remove('group-dragging');
            document.querySelectorAll('tbody').forEach(b => b.classList.remove('group-drag-over'));
        });
        tbody.addEventListener('dragover', e => {
            if (!draggingGroup || draggingGroup === tbody) return;
            if (!tbody.querySelector('tr.product-group-row')) return;
            // Only allow drop within same category
            if (draggingGroup.dataset.catId !== tbody.dataset.catId) return;
            e.preventDefault();
            document.querySelectorAll('tbody').forEach(b => b.classList.remove('group-drag-over'));
            tbody.classList.add('group-drag-over');
        });
        tbody.addEventListener('dragleave', e => {
            if (!e.relatedTarget || !tbody.contains(e.relatedTarget)) {
                tbody.classList.remove('group-drag-over');
            }
        });
        tbody.addEventListener('drop', e => {
            e.preventDefault();
            if (!draggingGroup || draggingGroup === tbody) return;
            if (draggingGroup.dataset.catId !== tbody.dataset.catId) return;
            tbody.classList.remove('group-drag-over');
            const tbodies = [...document.querySelectorAll('tbody[data-product-id]')];
            const fromIdx = tbodies.indexOf(draggingGroup);
            const toIdx = tbodies.indexOf(tbody);
            if (fromIdx === -1 || toIdx === -1) return;
            if (fromIdx < toIdx) {
                tbody.after(draggingGroup);
            } else {
                tbody.before(draggingGroup);
            }
            saveGroupOrder();
        });
    });

    function saveGroupOrder() {
        const tbodies = [...document.querySelectorAll('tbody[data-product-id]')];
        const items = tbodies.map((b, i) => ({ id: parseInt(b.dataset.productId), sort_order: i }));
        fetch('../../api/products.php', {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reorder', items })
        });
    }

    // ── Variant drag-to-reorder (within same product group) ───────────
    let draggingVariant = null;

    document.querySelectorAll('tr.variant-row').forEach(row => {
        row.addEventListener('dragstart', e => {
            draggingVariant = row;
            row.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.stopPropagation();
            // prevent modal from opening when a drag starts
            row._dragged = true;
        });
        row.addEventListener('dragend', () => {
            draggingVariant = null;
            row.classList.remove('dragging');
            document.querySelectorAll('tr.variant-row').forEach(r => r.classList.remove('drag-over'));
            setTimeout(() => { row._dragged = false; }, 50);
        });
        row.addEventListener('dragover', e => {
            if (!draggingVariant || draggingVariant === row) return;
            if (draggingVariant.dataset.productId !== row.dataset.productId) return;
            e.preventDefault();
            e.stopPropagation();
            document.querySelectorAll('tr.variant-row').forEach(r => r.classList.remove('drag-over'));
            row.classList.add('drag-over');
        });
        row.addEventListener('dragleave', () => row.classList.remove('drag-over'));
        row.addEventListener('drop', e => {
            e.preventDefault();
            e.stopPropagation();
            if (!draggingVariant || draggingVariant === row) return;
            if (draggingVariant.dataset.productId !== row.dataset.productId) return;
            row.classList.remove('drag-over');
            const pid = row.dataset.productId;
            const variantRows = [...document.querySelectorAll('tr.variant-row[data-product-id="' + pid + '"]')];
            const fromIdx = variantRows.indexOf(draggingVariant);
            const toIdx = variantRows.indexOf(row);
            if (fromIdx === -1 || toIdx === -1) return;
            if (fromIdx < toIdx) {
                row.after(draggingVariant);
            } else {
                row.before(draggingVariant);
            }
            saveVariantOrder(pid);
        });
    });

    // ── Inline variant edit ────────────────────────────────────────────
    let activeEditTr = null;

    function buildRecipeHtml(doughTypeId, selectedId) {
        let html = '<option value="">Geen recept</option>';
        recipesData.forEach(r => {
            if (!doughTypeId || String(r.dough_type_id) === String(doughTypeId)) {
                const sel = String(r.id) === String(selectedId) ? 'selected' : '';
                html += `<option value="${r.id}" ${sel}>${r.name}</option>`;
            }
        });
        return html;
    }

    function closeInlineEdit() {
        if (!activeEditTr) return;
        if (activeEditTr._originalRow) activeEditTr._originalRow.style.display = '';
        activeEditTr.remove();
        activeEditTr = null;
    }

    // Called from variant row onclick (edit) and add-row onclick (new)
    window.openInlineEdit = function(variantRow, productId) {
        closeInlineEdit();

        const pid = variantRow ? variantRow.dataset.productId : String(productId);
        const tbody = document.getElementById('product-group-' + pid);
        const doughTypeId = tbody?.dataset.doughTypeId || '';
        const isNew = !variantRow;

        const naam      = isNew ? '' : (variantRow.dataset.naam    || '');
        const gewicht   = isNew ? '' : (variantRow.dataset.gewicht || '');
        const prijs     = isNew ? '' : (variantRow.dataset.prijs   || '');
        const recipeId  = isNew ? '' : (variantRow.dataset.recipeId || '');
        const foto      = isNew ? '' : (variantRow.dataset.foto    || '');

        const fotoThumb = foto
            ? `<img src="../../${foto}" class="ve-foto-thumb" id="ve-foto-thumb">`
            : `<span id="ve-foto-thumb" style="display:none"></span>`;

        const tr = document.createElement('tr');
        tr.className = 'variant-edit-row';
        tr.innerHTML = `
            <td class="drag-cell"></td>
            <td colspan="5">
                <div class="ve-form">
                    <input type="text"   class="ve-naam"    placeholder="Naam (optioneel)" value="${naam.replace(/"/g,'&quot;')}">
                    <input type="number" class="ve-gewicht" placeholder="Gewicht (g)" value="${gewicht}" min="0">
                    <input type="number" class="ve-prijs"   placeholder="Prijs (€)" value="${prijs}" min="0" step="0.01">
                    <select class="ve-recipe">${buildRecipeHtml(doughTypeId, recipeId)}</select>
                    <div class="ve-foto-wrap">
                        ${fotoThumb}
                        <input type="file" class="ve-foto-file" accept="image/jpeg,image/png,image/webp">
                    </div>
                    <div class="ve-actions">
                        ${!isNew ? '<button class="btn btn-danger btn-small" onclick="deleteInlineVariant(this)">Verwijderen</button>' : ''}
                        <span class="ve-spacer"></span>
                        <button class="btn btn-ghost btn-small" onclick="closeInlineEdit()">Annuleren</button>
                        <button class="btn btn-small" onclick="saveInlineVariant(this)">Opslaan</button>
                    </div>
                </div>
            </td>`;

        tr._originalRow = variantRow || null;
        tr._productId   = pid;
        tr._isNew       = isNew;
        if (!isNew) tr._variantId = variantRow.dataset.id;

        if (isNew) {
            const addRow = tbody.querySelector('tr.variant-add-row');
            tbody.insertBefore(tr, addRow);
        } else {
            variantRow.after(tr);
            variantRow.style.display = 'none';
        }

        activeEditTr = tr;
        tr.querySelector('.ve-naam').focus();

        // Live foto preview
        tr.querySelector('.ve-foto-file').addEventListener('change', function() {
            if (!this.files[0]) return;
            const reader = new FileReader();
            reader.onload = e => {
                const thumb = tr.querySelector('#ve-foto-thumb');
                thumb.outerHTML = `<img src="${e.target.result}" class="ve-foto-thumb" id="ve-foto-thumb">`;
            };
            reader.readAsDataURL(this.files[0]);
        });
    };

    window.saveInlineVariant = async function(btn) {
        const tr = btn.closest('tr.variant-edit-row');
        const naam     = tr.querySelector('.ve-naam').value.trim();
        const gewicht  = parseInt(tr.querySelector('.ve-gewicht').value) || 0;
        const prijs    = parseFloat(tr.querySelector('.ve-prijs').value) || 0;
        const recipeId = tr.querySelector('.ve-recipe').value || '';
        const fotoFile = tr.querySelector('.ve-foto-file').files[0];

        const fd = new FormData();
        fd.append('naam',    naam);
        fd.append('gewicht', gewicht);
        fd.append('prijs',   prijs);
        fd.append('recipe_id', recipeId);
        if (fotoFile) fd.append('foto', fotoFile);

        if (tr._isNew) {
            fd.append('action',     'create_variant');
            fd.append('product_id', tr._productId);
        } else {
            fd.append('action', 'update_variant');
            fd.append('id',     tr._variantId);
        }

        btn.disabled = true;
        const res  = await fetch('../../api/products.php', { method: 'POST', body: fd });
        const json = await res.json();
        if (json.success) {
            window.location.reload();
        } else {
            btn.disabled = false;
        }
    };

    window.deleteInlineVariant = async function(btn) {
        if (!confirm('Variant verwijderen?')) return;
        const tr = btn.closest('tr.variant-edit-row');
        const id = parseInt(tr._variantId);
        const res  = await fetch('../../api/products.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ variant_id: id })
        });
        const json = await res.json();
        if (json.success) {
            const productId = tr._productId;
            const tbody = document.getElementById('product-group-' + productId);
            const orig = tr._originalRow;
            closeInlineEdit();
            if (orig) orig.remove();
            const remaining = tbody.querySelectorAll('tr.variant-row').length;
            const badge = tbody.querySelector('.product-count');
            if (badge) { if (remaining === 0) badge.remove(); else badge.textContent = remaining; }
        }
    };

    function saveVariantOrder(productId) {
        const rows = [...document.querySelectorAll('tr.variant-row[data-product-id="' + productId + '"]')];
        const items = rows.map((r, i) => ({ id: parseInt(r.dataset.id), sort_order: i }));
        fetch('../../api/products.php', {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reorder_variants', items })
        });
    }
})();
</script>
</body>
</html>
