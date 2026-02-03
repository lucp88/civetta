<?php
require_once '../config.php';
requireLogin();

$products = $pdo->query("SELECT p.*, d.naam as deegtype_naam FROM products p LEFT JOIN deegtypes d ON p.deegtype_id = d.id ORDER BY p.naam ASC")->fetchAll();
$variants = $pdo->query("SELECT product_id, gewicht, prijs FROM product_variants ORDER BY gewicht ASC")->fetchAll();
$variantsByProduct = [];
foreach ($variants as $v) {
    $variantsByProduct[$v['product_id']][] = $v;
}

$deegtypes = $pdo->query("SELECT * FROM deegtypes ORDER BY naam ASC")->fetchAll();

$deegMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['deeg_action'] ?? '';
    
    if ($action === 'create') {
        $naam = trim($_POST['deeg_naam'] ?? '');
        $beschrijving = trim($_POST['deeg_beschrijving'] ?? '');
        if ($naam) {
            $stmt = $pdo->prepare("INSERT INTO deegtypes (naam, beschrijving) VALUES (?, ?)");
            $stmt->execute([$naam, $beschrijving]);
            header('Location: products.php?deeg_msg=aangemaakt');
            exit;
        }
    } elseif ($action === 'update') {
        $id = intval($_POST['deeg_id'] ?? 0);
        $naam = trim($_POST['deeg_naam'] ?? '');
        $beschrijving = trim($_POST['deeg_beschrijving'] ?? '');
        if ($id && $naam) {
            $stmt = $pdo->prepare("UPDATE deegtypes SET naam = ?, beschrijving = ? WHERE id = ?");
            $stmt->execute([$naam, $beschrijving, $id]);
            header('Location: products.php?deeg_msg=bijgewerkt');
            exit;
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['deeg_id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE products SET deegtype_id = NULL WHERE deegtype_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM deegtypes WHERE id = ?")->execute([$id]);
            header('Location: products.php?deeg_msg=verwijderd');
            exit;
        }
    }
}

if (isset($_GET['deeg_msg'])) {
    $msgs = ['aangemaakt' => 'Deegtype aangemaakt', 'bijgewerkt' => 'Deegtype bijgewerkt', 'verwijderd' => 'Deegtype verwijderd'];
    $deegMessage = $msgs[$_GET['deeg_msg']] ?? '';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Producten | Civetta Admin</title>
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
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .card h2 {
            color: #5c3d1e;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: #8b5a2b;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
        }
        .btn:hover { background: #5c3d1e; }
        .btn-small {
            padding: 0.35rem 0.75rem;
            font-size: 0.85rem;
        }
        .btn-danger { background: #c00; }
        .btn-danger:hover { background: #900; }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            text-align: left;
            padding: 0.75rem 0.75rem;
            border-bottom: 1px solid #e8dfd2;
            vertical-align: middle;
        }
        th {
            color: #8b5a2b;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding-bottom: 0.5rem;
        }
        tbody tr:hover { background: #faf8f5; }
        tbody tr:last-child td { border-bottom: none; }
        .product-naam {
            font-weight: 600;
            color: #3d2b1f;
        }
        .product-beschrijving {
            color: #888;
            font-size: 0.85rem;
            max-width: 250px;
        }
        .variant-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
        }
        .variant-badge {
            display: inline-block;
            background: #f5f0ea;
            color: #5c3d1e;
            padding: 0.2rem 0.55rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
            white-space: nowrap;
        }
        .single-price {
            font-weight: 600;
            color: #5c3d1e;
        }
        .product-deegtype {
            font-size: 0.75rem;
            color: #a08060;
            margin-top: 0.15rem;
        }
        .actions { white-space: nowrap; }
        .actions a, .actions button { margin-right: 0.35rem; }
        .empty {
            color: #888;
            font-style: italic;
            padding: 2rem;
            text-align: center;
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
        .settings-card {
            background: #f9f7f4;
            border: 1px solid #e8dfd2;
        }
        .settings-form {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .settings-form label {
            font-weight: 500;
            color: #5c3d1e;
        }
        .settings-form input {
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 80px;
            font-size: 1rem;
        }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            background: #d4edda;
            color: #155724;
        }
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 1rem;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: white;
            border-radius: 12px;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .modal-header {
            background: linear-gradient(135deg, #8b5a2b, #5c3d1e);
            color: white;
            padding: 1rem 1.25rem;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 { margin: 0; }
        .modal-close {
            width: 32px; height: 32px;
            border: none;
            background: rgba(255,255,255,0.2);
            border-radius: 6px;
            cursor: pointer;
            font-size: 1.2rem;
            color: white;
        }
        .modal-close:hover { background: rgba(255,255,255,0.3); }
        .modal-body { padding: 1.25rem; }
        .modal-body .form-group { margin-bottom: 1rem; }
        .modal-body label {
            display: block;
            margin-bottom: 0.4rem;
            font-weight: 500;
            color: #4a433d;
        }
        .modal-body input,
        .modal-body textarea {
            width: 100%;
            padding: 0.6rem;
            border: 2px solid #e8dfd2;
            border-radius: 6px;
            font-size: 0.95rem;
            font-family: inherit;
        }
        .modal-body textarea { min-height: 80px; resize: vertical; }
        .modal-body input:focus,
        .modal-body textarea:focus { outline: none; border-color: #d4a574; }
        .modal-actions {
            padding: 1rem 1.25rem;
            border-top: 1px solid #eee;
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
        }
        .btn-secondary { background: #888; }
        .btn-secondary:hover { background: #666; }
        .deeg-beschrijving {
            font-size: 0.85rem;
            color: #888;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Civetta Admin</h1>
        <a href="../logout.php">Uitloggen</a>
    </div>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="../index.php">Dashboard</a>
            <span>›</span>
            Producten
        </div>

        <div class="card">
            <h2>
                Producten
                <a href="product-edit.php" class="btn">+ Nieuw Product</a>
            </h2>
            
            <?php if (empty($products)): ?>
                <div class="empty">Nog geen producten. Voeg je eerste product toe!</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Beschrijving</th>
                            <th>Deegtype</th>
                            <th>Prijs / Varianten</th>
                            <th>Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <?php $pv = $variantsByProduct[$product['id']] ?? []; ?>
                            <tr>
                                <td>
                                    <div class="product-naam"><?= htmlspecialchars($product['naam']) ?></div>
                                </td>
                                <td class="product-beschrijving"><?= htmlspecialchars(substr($product['beschrijving'] ?? '', 0, 60)) ?><?= strlen($product['beschrijving'] ?? '') > 60 ? '...' : '' ?></td>
                                <td class="product-deegtype"><?= htmlspecialchars($product['deegtype_naam'] ?? '-') ?></td>
                                <td>
                                    <?php if (!empty($pv)): ?>
                                        <div class="variant-badges">
                                            <?php foreach ($pv as $v): ?>
                                                <span class="variant-badge"><?= intval($v['gewicht']) ?>g &euro;<?= number_format($v['prijs'], 2, ',', '.') ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php elseif ($product['prijs']): ?>
                                        <span class="single-price">&euro;<?= number_format($product['prijs'], 2, ',', '.') ?></span>
                                    <?php else: ?>
                                        <span style="color:#aaa">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <a href="product-edit.php?id=<?= $product['id'] ?>" class="btn btn-small">Bewerken</a>
                                    <a href="product-delete.php?id=<?= $product['id'] ?>" class="btn btn-small btn-danger" onclick="return confirm('Weet je zeker dat je dit product wilt verwijderen?')">Verwijderen</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <div class="card">
            <h2>
                Deegtypes
                <button class="btn" onclick="openDeegModal()">+ Nieuw Deegtype</button>
            </h2>

            <?php if ($deegMessage): ?>
                <div class="alert"><?= htmlspecialchars($deegMessage) ?></div>
            <?php endif; ?>

            <?php if (empty($deegtypes)): ?>
                <div class="empty">Nog geen deegtypes. Voeg je eerste deegtype toe!</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Naam</th>
                            <th>Beschrijving</th>
                            <th>Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deegtypes as $dt): ?>
                            <tr>
                                <td><?= htmlspecialchars($dt['naam']) ?></td>
                                <td class="deeg-beschrijving"><?= htmlspecialchars($dt['beschrijving'] ?? '-') ?></td>
                                <td class="actions">
                                    <button class="btn btn-small" onclick="openDeegModal(<?= $dt['id'] ?>, '<?= htmlspecialchars(addslashes($dt['naam'])) ?>', `<?= htmlspecialchars(addslashes($dt['beschrijving'] ?? '')) ?>`)">Bewerken</button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Weet je zeker dat je dit deegtype wilt verwijderen?')">
                                        <input type="hidden" name="deeg_action" value="delete">
                                        <input type="hidden" name="deeg_id" value="<?= $dt['id'] ?>">
                                        <button type="submit" class="btn btn-small btn-danger">Verwijderen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal-overlay" id="deegModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="deegModalTitle">Nieuw Deegtype</h3>
                <button class="modal-close" onclick="closeDeegModal()">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="deeg_action" id="deegAction" value="create">
                    <input type="hidden" name="deeg_id" id="deegId" value="">
                    <div class="form-group">
                        <label>Naam *</label>
                        <input type="text" name="deeg_naam" id="deegNaam" required>
                    </div>
                    <div class="form-group">
                        <label>Beschrijving</label>
                        <textarea name="deeg_beschrijving" id="deegBeschrijving"></textarea>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeDeegModal()">Annuleren</button>
                    <button type="submit" class="btn">Opslaan</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openDeegModal(id, naam, beschrijving) {
        if (id) {
            document.getElementById('deegModalTitle').textContent = 'Deegtype Bewerken';
            document.getElementById('deegAction').value = 'update';
            document.getElementById('deegId').value = id;
            document.getElementById('deegNaam').value = naam;
            document.getElementById('deegBeschrijving').value = beschrijving;
        } else {
            document.getElementById('deegModalTitle').textContent = 'Nieuw Deegtype';
            document.getElementById('deegAction').value = 'create';
            document.getElementById('deegId').value = '';
            document.getElementById('deegNaam').value = '';
            document.getElementById('deegBeschrijving').value = '';
        }
        document.getElementById('deegModal').classList.add('active');
    }

    function closeDeegModal() {
        document.getElementById('deegModal').classList.remove('active');
    }

    document.getElementById('deegModal').addEventListener('click', function(e) {
        if (e.target === this) closeDeegModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeDeegModal();
    });
    </script>
</body>
</html>
