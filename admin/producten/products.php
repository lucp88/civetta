<?php
require_once '../config.php';
requireLogin();

$products = $pdo->query("SELECT * FROM products ORDER BY naam ASC")->fetchAll();
$variants = $pdo->query("SELECT product_id, gewicht, prijs FROM product_variants ORDER BY gewicht ASC")->fetchAll();
$variantsByProduct = [];
foreach ($variants as $v) {
    $variantsByProduct[$v['product_id']][] = $v;
}

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$currentPage = 'products';
$adminBasePath = '../';

?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Producten | Civetta Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--cream);
            min-height: 100vh;
        }
        .admin-content {
            padding: 2rem;
            max-width: 1100px;
        }

        @media (max-width: 768px) {
            .admin-content { padding: 1.25rem; }
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
                    <span class="topbar-title">Producten</span>
                </div>
                <div class="topbar-right">
                    <a href="product-edit.php" class="topbar-link">
                        <i class="bi bi-plus-lg"></i> <span>Nieuw product</span>
                    </a>
                </div>
            </header>

            <div class="admin-content">

        <div class="card">
            <h2>Producten</h2>
            
            <?php if (empty($products)): ?>
                <div class="empty">Nog geen producten. Voeg je eerste product toe!</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Beschrijving</th>
                            <th>Varianten</th>
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
            </div>
        </div>
    </div>
</body>
</html>
