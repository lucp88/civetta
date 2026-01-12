<?php
require_once 'config.php';
requireLogin();

$stmt = $pdo->query("SELECT * FROM products ORDER BY naam ASC");
$products = $stmt->fetchAll();
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
            max-width: 1000px;
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
            padding: 0.75rem;
            border-bottom: 1px solid #e8dfd2;
        }
        th {
            color: #8b5a2b;
            font-weight: 600;
        }
        .actions { white-space: nowrap; }
        .actions a { margin-right: 0.5rem; }
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
        .price {
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Civetta Admin</h1>
        <a href="logout.php">Uitloggen</a>
    </div>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="index.php">Dashboard</a>
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
                            <th>Naam</th>
                            <th>Beschrijving</th>
                            <th>Prijs</th>
                            <th>Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?= htmlspecialchars($product['naam']) ?></td>
                                <td><?= htmlspecialchars(substr($product['beschrijving'] ?? '', 0, 50)) ?><?= strlen($product['beschrijving'] ?? '') > 50 ? '...' : '' ?></td>
                                <td class="price"><?= $product['prijs'] ? '€' . number_format($product['prijs'], 2, ',', '.') : '-' ?></td>
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
    </div>
</body>
</html>
