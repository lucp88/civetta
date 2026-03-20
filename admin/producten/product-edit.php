<?php
require_once '../config.php';
require_once '../../lib/shared.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$currentPage = 'products';
$adminBasePath = '../';

$uploadDir = '../../img/producten/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$id = $_GET['id'] ?? null;
$product = null;
$variants = [];
$doughTypes = $pdo->query("SELECT id, name FROM dough_types ORDER BY name ASC")->fetchAll();
$error = '';
$success = '';

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $product = $stmt->fetch();
    if (!$product) {
        header('Location: products.php');
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT pv.*, br.name as recipe_name FROM product_variants pv LEFT JOIN baker_recipes br ON pv.recipe_id = br.id WHERE pv.product_id = ? ORDER BY pv.naam ASC, pv.gewicht ASC");
    $stmt->execute([$id]);
    $variants = $stmt->fetchAll();
}

// Compute ingredient list from first variant's recipe
$computedIngredientList = null;
$computedRecipeDetails = null;
$firstRecipeId = null;
foreach ($variants as $v) {
    if (!empty($v['recipe_id'])) { $firstRecipeId = (int)$v['recipe_id']; break; }
}
if ($firstRecipeId) {
    try {
        $rStmt = $pdo->prepare("SELECT recipe_data FROM baker_recipes WHERE id = ?");
        $rStmt->execute([$firstRecipeId]);
        $rd = json_decode($rStmt->fetchColumn() ?: '{}', true) ?? [];

        $grainIds = [];
        foreach (['mainDoughGrains', 'sourdoughGrains', 'preFermentGrains'] as $key) {
            foreach ($rd[$key] ?? [] as $grain) {
                if (is_numeric($grain['type'] ?? '')) $grainIds[] = (int)$grain['type'];
            }
        }
        $lookup = [];
        if (!empty($grainIds)) {
            $gIds = array_values(array_unique($grainIds));
            $gp = implode(',', array_fill(0, count($gIds), '?'));
            $gStmt = $pdo->prepare("SELECT id, name, is_whole_grain, is_biologisch, is_allergeen, allergeen_naam FROM ingredients WHERE id IN ($gp)");
            $gStmt->execute($gIds);
            foreach ($gStmt->fetchAll() as $ing) {
                $lookup[(int)$ing['id']] = ['name' => $ing['name'], 'is_whole_grain' => (bool)$ing['is_whole_grain'], 'is_biologisch' => (bool)$ing['is_biologisch'], 'is_allergeen' => (bool)$ing['is_allergeen'], 'allergeen_naam' => $ing['allergeen_naam']];
            }
        }

        $biologischNames = [];
        $bioStmt = $pdo->query("SELECT LOWER(name) as name FROM ingredients WHERE is_biologisch = 1 AND is_active = 1");
        foreach ($bioStmt->fetchAll() as $row) {
            $biologischNames[$row['name']] = true;
        }

        $allergeenNames = [];
        $allergeenStmt = $pdo->query("SELECT LOWER(name) as name, allergeen_naam FROM ingredients WHERE is_allergeen = 1 AND is_active = 1");
        foreach ($allergeenStmt->fetchAll() as $row) {
            $allergeenNames[$row['name']] = $row['allergeen_naam'];
        }

        $result = computeIngredientList($rd, $lookup, $biologischNames, $allergeenNames);
        $computedIngredientList = $result ? $result['text'] : null;
        $computedRecipeDetails  = computeRecipeDetails($rd, $lookup);
    } catch (Exception $e) {
        // Ingredient computation is non-critical — page still loads without it
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $naam = trim($_POST['naam'] ?? '');
    $beschrijving = trim($_POST['beschrijving'] ?? '');
    $doughTypeId = !empty($_POST['dough_type_id']) ? intval($_POST['dough_type_id']) : null;
    $foto = $product['foto'] ?? '';
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

    if (isset($_FILES['foto_upload']) && $_FILES['foto_upload']['error'] === UPLOAD_ERR_OK) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $fileType = $finfo->file($_FILES['foto_upload']['tmp_name']);

        if (in_array($fileType, $allowedTypes)) {
            $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $ext = $mimeToExt[$fileType];
            $filename = uniqid('product_') . '.' . $ext;
            $targetPath = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['foto_upload']['tmp_name'], $targetPath)) {
                $foto = 'img/producten/' . $filename;
            } else {
                $error = 'Fout bij uploaden van afbeelding';
            }
        } else {
            $error = 'Alleen JPG, PNG en WebP afbeeldingen zijn toegestaan';
        }
    }
    
    if ($naam) {
        try {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE products SET naam = ?, beschrijving = ?, foto = ?, dough_type_id = ? WHERE id = ?");
                $stmt->execute([$naam, $beschrijving, $foto, $doughTypeId, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO products (naam, beschrijving, foto, dough_type_id) VALUES (?, ?, ?, ?)");
                $stmt->execute([$naam, $beschrijving, $foto, $doughTypeId]);
                $id = $pdo->lastInsertId();
            }
            
            header('Location: products.php');
            exit;
        } catch (PDOException $e) {
            $error = 'Fout bij opslaan: ' . $e->getMessage();
        }
    } else {
        $error = 'Vul minimaal een product naam in';
    }
}
$adminPageTitle = $id ? 'Product Bewerken' : 'Nieuw Product';
ob_start(); ?>
<style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--cream);
            min-height: 100vh;
        }
        .admin-content {
            padding: 2rem;
        }

        @media (max-width: 768px) {
            .admin-content { padding: 1.25rem; }
        }
        .edit-layout {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 2rem;
            align-items: start;
        }
        @media (max-width: 900px) {
            .edit-layout {
                grid-template-columns: 1fr;
            }
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            padding: 1.5rem;
        }
        .card h2 {
            color: #2d4a2d;
            margin-bottom: 1.5rem;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #4a433d;
            font-weight: 500;
        }
        input, textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e8dfd2;
            border-radius: 8px;
            font-size: 1rem;
            font-family: inherit;
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #d4a574;
        }
        select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e8dfd2;
            border-radius: 8px;
            font-size: 1rem;
            font-family: inherit;
            background: white;
        }
        textarea {
            min-height: 120px;
            resize: vertical;
        }
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #3d6b3d;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 1rem;
            border: none;
            cursor: pointer;
        }
        .btn:hover { background: #2d4a2d; }
        .btn-secondary {
            background: #888;
        }
        .actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        .error {
            background: #fee;
            color: #c00;
            padding: 0.75rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .success {
            background: #efe;
            color: #060;
            padding: 0.75rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .help {
            font-size: 0.85rem;
            color: #888;
            margin-top: 0.5rem;
        }
        .breadcrumb {
            margin-bottom: 1.5rem;
        }
        .breadcrumb a {
            color: #3d6b3d;
            text-decoration: none;
        }
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        .breadcrumb span {
            color: #888;
            margin: 0 0.5rem;
        }
        .price-input {
            position: relative;
        }
        .price-input::before {
            content: '\20AC';
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
        }
        .price-input input {
            padding-left: 1.75rem;
        }
        .input-with-unit {
            display: flex;
            align-items: stretch;
        }
        .input-with-unit input {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
            flex: 1;
        }
        .input-unit {
            padding: 0.75rem;
            background: #f5f2ed;
            border: 2px solid #e8dfd2;
            border-left: none;
            border-radius: 0 8px 8px 0;
            font-size: 0.9rem;
            color: #666;
            display: flex;
            align-items: center;
        }
        .file-input {
            padding: 0.5rem;
            background: #f5f2ed;
            direction: rtl;
        }
        .file-input::file-selector-button {
            direction: ltr;
            padding: 0.5rem 1rem;
            background: #3d6b3d;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            margin-left: 1rem;
        }
        .file-input::file-selector-button:hover {
            background: #2d4a2d;
        }
        .current-foto {
            margin-bottom: 1rem;
            padding: 1rem;
            background: #f5f2ed;
            border-radius: 8px;
        }
        .current-foto img {
            max-width: 200px;
            max-height: 150px;
            object-fit: cover;
            border-radius: 6px;
            margin-top: 0.5rem;
        }
        .preview-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            padding: 1.5rem;
            position: sticky;
            top: 2rem;
        }
        .preview-section h3 {
            color: #2d4a2d;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        .preview-card {
            width: 100%;
            background: #fff9f3;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(139, 90, 43, 0.08);
        }
        .preview-image {
            width: 100%;
            aspect-ratio: 4/3;
            background: #f0e6d8;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #888;
            font-size: 0.9rem;
        }
        .preview-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .preview-info {
            padding: 1rem;
        }
        .preview-naam {
            font-family: Georgia, serif;
            font-size: 1.2rem;
            color: #2d4a2d;
            margin-bottom: 0.5rem;
        }
        .preview-beschrijving {
            color: #4a433d;
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 0.75rem;
        }
        .preview-ingredienten {
            font-size: 0.8rem;
            color: #7d8471;
            padding: 0.5rem 0.75rem;
            background: #f0e6d8;
            border-radius: 6px;
            margin-bottom: 0.75rem;
        }
        .preview-footer {
            padding-top: 0.75rem;
            border-top: 1px solid #f0e6d8;
        }
        .preview-prijs {
            font-family: Georgia, serif;
            font-size: 1.1rem;
            font-weight: 700;
            color: #3d6b3d;
        }
        .btn-small {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
        }
        .preview-variants {
            margin-top: 0.5rem;
        }
        .preview-variant {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            padding: 0.25rem 0;
            color: #2d4a2d;
        }
        .preview-variant .gewicht {
            color: #666;
        }
        .computed-ingredients-box {
            padding: 0.75rem 1rem;
            background: #f5f2ed;
            border: 2px solid #e8dfd2;
            border-radius: 8px;
            font-size: 0.95rem;
            color: #4a433d;
            line-height: 1.5;
        }
        .computed-ingredients-box.empty {
            color: #aaa;
            font-style: italic;
        }
        .recipe-details-box {
            margin-top: 0.75rem;
            padding: 0.75rem 1rem;
            background: #faf7f3;
            border: 1px solid #e8dfd2;
            border-radius: 8px;
            font-size: 0.875rem;
        }
        .recipe-details-box .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.2rem 0;
            color: #2d4a2d;
            border-bottom: 1px solid #f0e6d8;
        }
        .recipe-details-box .detail-row:last-child { border-bottom: none; }
        .recipe-details-box .detail-subtitle {
            font-size: 0.75rem;
            font-weight: 700;
            color: #3d6b3d;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin: 0.5rem 0 0.2rem 0;
        }
    </style>
<?php $adminExtraHead = ob_get_clean();
require_once '../components/sidebar.php'; ?>

            <header class="topbar">
                <div class="topbar-left">
                    <span class="topbar-title"><?= $id ? 'Product Bewerken' : 'Nieuw Product' ?></span>
                </div>
                <div class="topbar-right"></div>
            </header>

            <div class="admin-content">
                <div class="breadcrumb">
                    <a href="../index.php">Dashboard</a>
                    <span>›</span>
                    <a href="products.php">Producten</a>
                    <span>›</span>
                    <?= $id ? 'Bewerken' : 'Nieuw' ?>
                </div>

                <div class="edit-layout">
                    <div class="card">
                        <h2><?= $id ? 'Product Bewerken' : 'Nieuw Product' ?></h2>
                        
                        <?php if ($error): ?>
                        <div class="error"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="success"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="naam">Product Naam *</label>
                            <input type="text" id="naam" name="naam" value="<?= htmlspecialchars($product['naam'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Ingrediënten</label>
                            <?php if ($computedIngredientList): ?>
                                <div class="computed-ingredients-box"><?= htmlspecialchars($computedIngredientList) ?></div>
                                <?php if (strpos($computedIngredientList, '*') !== false): ?>
                                    <p class="help" style="margin-top:0.35rem;color:#2e7d32">* Biologisch product</p>
                                <?php endif; ?>
                                <?php if ($computedRecipeDetails && !empty($computedRecipeDetails['grains'])): ?>
                                <div class="recipe-details-box">
                                    <?php if ($computedRecipeDetails['volkoren_pct'] > 0): ?>
                                        <div class="detail-row"><span>Volkoren</span><span><?= $computedRecipeDetails['volkoren_pct'] ?>%</span></div>
                                    <?php endif; ?>
                                    <div class="detail-subtitle">Meelsoorten</div>
                                    <?php foreach ($computedRecipeDetails['grains'] as $grain): ?>
                                        <div class="detail-row"><span><?= htmlspecialchars($grain['name']) ?></span><span><?= $grain['pct'] ?>%</span></div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                <p class="help">Automatisch bepaald vanuit het recept van de variant</p>
                            <?php else: ?>
                                <div class="computed-ingredients-box empty">Wordt bepaald zodra een recept aan een variant is gekoppeld</div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="beschrijving">Beschrijving</label>
                            <textarea id="beschrijving" name="beschrijving"><?= htmlspecialchars($product['beschrijving'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="dough_type_id">Deegsoort</label>
                            <select id="dough_type_id" name="dough_type_id" onchange="filterRecipesByDoughType()">
                                <option value="">Geen deegsoort</option>
                                <?php foreach ($doughTypes as $dt): ?>
                                    <option value="<?= $dt['id'] ?>" <?= ($product['dough_type_id'] ?? '') == $dt['id'] ? 'selected' : '' ?>><?= htmlspecialchars($dt['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="help">Kies een deegsoort om recepten te filteren bij varianten</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="foto_upload">Foto</label>
                            <?php if (!empty($product['foto'])): ?>
                                <div class="current-foto">
                                    <p><strong>Huidige foto:</strong></p>
                                    <img src="../<?= htmlspecialchars($product['foto']) ?>" alt="Huidige foto">
                                </div>
                            <?php endif; ?>
                            <input type="file" id="foto_upload" name="foto_upload" accept="image/jpeg,image/png,image/webp" class="file-input">
                            <p class="help">JPG, PNG of WebP afbeelding</p>
                        </div>
                        
                        <div class="actions">
                                <button type="submit" class="btn"><?= $id ? 'Opslaan' : 'Aanmaken' ?></button>
                                <a href="products.php" class="btn btn-secondary">Annuleren</a>
                            </div>
                        </form>
                    </div>
                        
                    <div class="preview-section">
                            <h3>Preview</h3>
                            <div class="preview-card">
                                <div class="preview-image" id="preview-image">
                                    <?php if (!empty($product['foto'])): ?>
                                        <img src="../<?= htmlspecialchars($product['foto']) ?>" alt="Preview">
                                    <?php else: ?>
                                        Geen foto
                                    <?php endif; ?>
                                </div>
                                <div class="preview-info">
                                    <h4 class="preview-naam" id="preview-naam"><?= htmlspecialchars($product['naam'] ?? 'Product naam') ?></h4>
                                    <p class="preview-beschrijving" id="preview-beschrijving"><?= htmlspecialchars($product['beschrijving'] ?? 'Beschrijving...') ?></p>
                                    <?php if ($computedIngredientList): ?>
                                    <div class="preview-ingredienten">
                                        <strong>Ingrediënten:</strong> <?= htmlspecialchars($computedIngredientList) ?>
                                        <?php if (strpos($computedIngredientList, '*') !== false): ?>
                                        <div style="font-size:0.75rem;margin-top:0.25rem;color:#2e7d32">* Biologisch product</div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="preview-footer">
                                        <div class="preview-variants" id="preview-variants">
                                            <?php foreach ($variants as $variant): ?>
                                            <div class="preview-variant">
                                                <span class="gewicht"><?= $variant['gewicht'] ?>g</span>
                                                <span class="prijs">EUR <?= number_format($variant['prijs'], 2, ',', '.') ?></span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.getElementById('naam').addEventListener('input', function() {
            document.getElementById('preview-naam').textContent = this.value || 'Product naam';
        });
        document.getElementById('beschrijving').addEventListener('input', function() {
            document.getElementById('preview-beschrijving').textContent = this.value || 'Beschrijving...';
        });
        document.getElementById('foto_upload').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('preview-image').innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
                };
                reader.readAsDataURL(file);
            }
        });
        
    </script>
</body>
</html>
