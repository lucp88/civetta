<?php
require_once 'config.php';
requireLogin();

$uploadDir = '../img/producten/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$id = $_GET['id'] ?? null;
$product = null;
$variants = [];
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
    
    $stmt = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = ? ORDER BY gewicht ASC");
    $stmt->execute([$id]);
    $variants = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $naam = trim($_POST['naam'] ?? '');
    $ingredienten = trim($_POST['ingredienten'] ?? '');
    $beschrijving = trim($_POST['beschrijving'] ?? '');
    $prijs = $_POST['prijs'] !== '' ? floatval($_POST['prijs']) : null;
    $foto = $product['foto'] ?? '';
    
    $variantGewichten = $_POST['variant_gewicht'] ?? [];
    $variantPrijzen = $_POST['variant_prijs'] ?? [];
    
    if (isset($_FILES['foto_upload']) && $_FILES['foto_upload']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $fileType = $_FILES['foto_upload']['type'];
        
        if (in_array($fileType, $allowedTypes)) {
            $ext = pathinfo($_FILES['foto_upload']['name'], PATHINFO_EXTENSION);
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
                $stmt = $pdo->prepare("UPDATE products SET naam = ?, ingredienten = ?, beschrijving = ?, prijs = ?, foto = ? WHERE id = ?");
                $stmt->execute([$naam, $ingredienten, $beschrijving, $prijs, $foto, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO products (naam, ingredienten, beschrijving, prijs, foto) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$naam, $ingredienten, $beschrijving, $prijs, $foto]);
                $id = $pdo->lastInsertId();
            }
            
            $pdo->prepare("DELETE FROM product_variants WHERE product_id = ?")->execute([$id]);
            
            for ($i = 0; $i < count($variantGewichten); $i++) {
                $gewicht = intval($variantGewichten[$i] ?? 0);
                $variantPrijs = floatval($variantPrijzen[$i] ?? 0);
                
                if ($gewicht > 0 && $variantPrijs > 0) {
                    $stmt = $pdo->prepare("INSERT INTO product_variants (product_id, gewicht, prijs) VALUES (?, ?, ?)");
                    $stmt->execute([$id, $gewicht, $variantPrijs]);
                }
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
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $id ? 'Product Bewerken' : 'Nieuw Product' ?> | Civetta Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f2ed;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #8b5a2b, #5c3d1e);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 1.5rem; }
        .header a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.2);
            border-radius: 6px;
        }
        .container {
            max-width: 1100px;
            margin: 2rem auto;
            padding: 0 1rem;
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
            color: #5c3d1e;
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
        input:focus, textarea:focus {
            outline: none;
            border-color: #d4a574;
        }
        textarea {
            min-height: 120px;
            resize: vertical;
        }
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #8b5a2b;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 1rem;
            border: none;
            cursor: pointer;
        }
        .btn:hover { background: #5c3d1e; }
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
            color: #8b5a2b;
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
            content: '€';
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
        }
        .price-input input {
            padding-left: 1.75rem;
        }
        .file-input {
            padding: 0.5rem;
            background: #f5f2ed;
            direction: rtl;
        }
        .file-input::file-selector-button {
            direction: ltr;
            padding: 0.5rem 1rem;
            background: #8b5a2b;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            margin-left: 1rem;
        }
        .file-input::file-selector-button:hover {
            background: #5c3d1e;
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
            color: #5c3d1e;
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
            color: #5c3d1e;
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
            color: #8b5a2b;
        }
        .variant-row {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            align-items: center;
        }
        .variant-row input[type="number"] {
            width: 120px;
        }
        .variant-row .variant-price {
            width: 120px;
        }
        .variant-row .variant-price input {
            width: 100%;
        }
        .btn-remove-variant {
            width: 32px;
            height: 32px;
            border: none;
            background: #c00;
            color: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-remove-variant:hover {
            background: #900;
        }
        .btn-add-variant {
            margin-top: 0.5rem;
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
            color: #5c3d1e;
        }
        .preview-variant .gewicht {
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Civetta Admin</h1>
        <a href="products.php">← Terug naar producten</a>
    </div>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="index.php">Dashboard</a>
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
                    <label for="ingredienten">Ingrediënten</label>
                    <textarea id="ingredienten" name="ingredienten"><?= htmlspecialchars($product['ingredienten'] ?? '') ?></textarea>
                    <p class="help">Lijst van ingrediënten, bijv. "tarwebloem, water, zout, gist"</p>
                </div>
                
                <div class="form-group">
                    <label for="beschrijving">Beschrijving</label>
                    <textarea id="beschrijving" name="beschrijving"><?= htmlspecialchars($product['beschrijving'] ?? '') ?></textarea>
                </div>
                
                <div class="form-group">
                    <label for="prijs">Standaard Prijs</label>
                    <div class="price-input">
                        <input type="number" id="prijs" name="prijs" step="0.01" min="0" value="<?= $product['prijs'] ?? '' ?>">
                    </div>
                    <p class="help">Basisprijs (wordt overschreven als er gewichtsvarianten zijn)</p>
                </div>
                
                <div class="form-group">
                    <label>Gewichtsvarianten</label>
                    <p class="help">Voeg varianten toe met verschillende gewichten en prijzen</p>
                    <div id="variants-container">
                        <?php foreach ($variants as $variant): ?>
                        <div class="variant-row">
                            <input type="number" name="variant_gewicht[]" placeholder="Gewicht (g)" value="<?= $variant['gewicht'] ?>" min="1">
                            <div class="price-input variant-price">
                                <input type="number" name="variant_prijs[]" step="0.01" min="0" placeholder="Prijs" value="<?= $variant['prijs'] ?>">
                            </div>
                            <button type="button" class="btn-remove-variant" onclick="this.parentElement.remove(); updatePreviewVariants();">×</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-small btn-add-variant" onclick="addVariant()">+ Gewichtsoptie toevoegen</button>
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
                            <div class="preview-ingredienten" id="preview-ingredienten">
                                <strong>Ingredienten:</strong> <span id="preview-ingredienten-text"><?= htmlspecialchars($product['ingredienten'] ?? 'ingredienten...') ?></span>
                            </div>
                            <div class="preview-footer">
                                <span class="preview-prijs" id="preview-prijs"><?= $product['prijs'] ? '€ ' . number_format($product['prijs'], 2, ',', '.') : '' ?></span>
                                <div class="preview-variants" id="preview-variants">
                                    <?php foreach ($variants as $variant): ?>
                                    <div class="preview-variant">
                                        <span class="gewicht"><?= $variant['gewicht'] ?>g</span>
                                        <span class="prijs">€ <?= number_format($variant['prijs'], 2, ',', '.') ?></span>
                                    </div>
                                    <?php endforeach; ?>
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
        document.getElementById('ingredienten').addEventListener('input', function() {
            document.getElementById('preview-ingredienten-text').textContent = this.value || 'ingredienten...';
        });
        document.getElementById('prijs').addEventListener('input', function() {
            const val = parseFloat(this.value);
            document.getElementById('preview-prijs').textContent = val ? '€ ' + val.toFixed(2).replace('.', ',') : '';
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
        
        function addVariant() {
            const container = document.getElementById('variants-container');
            const row = document.createElement('div');
            row.className = 'variant-row';
            row.innerHTML = `
                <input type="number" name="variant_gewicht[]" placeholder="Gewicht (g)" min="1" oninput="updatePreviewVariants()">
                <div class="price-input variant-price">
                    <input type="number" name="variant_prijs[]" step="0.01" min="0" placeholder="Prijs" oninput="updatePreviewVariants()">
                </div>
                <button type="button" class="btn-remove-variant" onclick="this.parentElement.remove(); updatePreviewVariants();">×</button>
            `;
            container.appendChild(row);
        }
        
        function updatePreviewVariants() {
            const gewichten = document.querySelectorAll('input[name="variant_gewicht[]"]');
            const prijzen = document.querySelectorAll('input[name="variant_prijs[]"]');
            const container = document.getElementById('preview-variants');
            
            let html = '';
            for (let i = 0; i < gewichten.length; i++) {
                const g = gewichten[i].value;
                const p = parseFloat(prijzen[i].value);
                if (g && p) {
                    html += `<div class="preview-variant"><span class="gewicht">${g}g</span><span class="prijs">€ ${p.toFixed(2).replace('.', ',')}</span></div>`;
                }
            }
            container.innerHTML = html;
        }
        
        document.querySelectorAll('input[name="variant_gewicht[]"], input[name="variant_prijs[]"]').forEach(el => {
            el.addEventListener('input', updatePreviewVariants);
        });
    </script>
    </div>
</div>
</body>
</html>
