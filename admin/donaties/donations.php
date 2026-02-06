<?php
require_once '../config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM donations WHERE id = ?");
    $stmt->execute([$_POST['delete_id']]);
    header('Location: donations.php?deleted=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_manual'])) {
    $amount = floatval($_POST['amount']);
    $donorName = trim($_POST['donor_name'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $paidAt = $_POST['paid_at'] ?: date('Y-m-d H:i:s');
    
    if ($amount > 0) {
        $stmt = $pdo->prepare("INSERT INTO donations (mollie_payment_id, amount, donor_name, message, status, paid_at) VALUES (?, ?, ?, ?, 'paid', ?)");
        $stmt->execute(['manual_' . time(), $amount, $donorName ?: null, $message ?: null, $paidAt]);
        header('Location: donations.php?added=1');
        exit;
    }
}

$stmt = $pdo->query("SELECT * FROM donations WHERE status = 'paid' ORDER BY paid_at DESC");
$donations = $stmt->fetchAll();

$totalAmount = 0;
foreach ($donations as $d) {
    $totalAmount += $d['amount'];
}

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$currentPage = 'donations';
$adminBasePath = '../';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donaties | Civetta Admin</title>
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
            max-width: 1000px;
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
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-box {
            background: linear-gradient(135deg, #8b5a2b, #5c3d1e);
            color: white;
            padding: 1.25rem;
            border-radius: 10px;
            text-align: center;
        }
        .stat-box .number {
            font-size: 2rem;
            font-weight: 700;
        }
        .stat-box .label {
            font-size: 0.85rem;
            opacity: 0.9;
        }
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
        .amount {
            font-weight: 600;
            color: #2e7d32;
        }
        .message {
            color: #666;
            font-style: italic;
            max-width: 300px;
        }
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
        .btn-delete {
            background: #c00;
            color: white;
            border: none;
            padding: 0.3rem 0.6rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
        }
        .btn-delete:hover { background: #900; }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            background: #d4edda;
            color: #155724;
        }
        .actions { text-align: right; }
        .add-form .form-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .add-form .form-group {
            flex: 1;
            min-width: 120px;
        }
        .add-form label {
            display: block;
            font-size: 0.85rem;
            color: #8b5a2b;
            margin-bottom: 0.25rem;
            font-weight: 600;
        }
        .add-form input {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #e8dfd2;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        .add-form input:focus {
            outline: none;
            border-color: #8b5a2b;
        }
        .btn-add {
            background: #8b5a2b;
            color: white;
            border: none;
            padding: 0.5rem 1.25rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            white-space: nowrap;
        }
        .btn-add:hover { background: #5c3d1e; }
        .method {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .method.mollie {
            background: #e3f2fd;
            color: #1565c0;
        }
        .method.manual {
            background: #fff3e0;
            color: #e65100;
        }
        @media (max-width: 768px) {
            .admin-content { padding: 1.25rem; }
        }
        @media (max-width: 600px) {
            table, thead, tbody, th, td, tr {
                display: block;
            }
            thead tr {
                display: none;
            }
            tr {
                margin-bottom: 1rem;
                border: 1px solid #e8dfd2;
                border-radius: 8px;
                padding: 0.5rem;
            }
            td {
                border: none;
                padding: 0.5rem;
                position: relative;
                padding-left: 40%;
            }
            td::before {
                content: attr(data-label);
                position: absolute;
                left: 0.5rem;
                font-weight: 600;
                color: #8b5a2b;
            }
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
                    <span class="topbar-title">Donaties</span>
                </div>
                <div class="topbar-right">
                    <a href="../index.php" class="topbar-link">
                        <i class="bi bi-arrow-left"></i> <span>Dashboard</span>
                    </a>
                </div>
            </header>

            <div class="admin-content">

        <?php if (isset($_GET['deleted'])): ?>
            <div class="alert">Donatie verwijderd.</div>
        <?php endif; ?>
        
        <?php if (isset($_GET['added'])): ?>
            <div class="alert">Donatie toegevoegd.</div>
        <?php endif; ?>

        <div class="stats">
            <div class="stat-box">
                <div class="number"><?= count($donations) ?></div>
                <div class="label">Donaties</div>
            </div>
            <div class="stat-box">
                <div class="number">€<?= number_format($totalAmount, 2, ',', '.') ?></div>
                <div class="label">Totaal opgehaald</div>
            </div>
        </div>

        <div class="card">
            <h2>Donatie toevoegen</h2>
            <form method="POST" class="add-form">
                <input type="hidden" name="add_manual" value="1">
                <div class="form-row">
                    <div class="form-group">
                        <label>Datum</label>
                        <input type="datetime-local" name="paid_at" value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                    <div class="form-group">
                        <label>Bedrag *</label>
                        <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Naam</label>
                        <input type="text" name="donor_name" placeholder="Naam donateur">
                    </div>
                    <div class="form-group">
                        <label>Bericht</label>
                        <input type="text" name="message" placeholder="Optioneel bericht">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn-add">Toevoegen</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Ontvangen Donaties</h2>
            
            <?php if (empty($donations)): ?>
                <div class="empty">Nog geen donaties ontvangen.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Bedrag</th>
                            <th>Naam</th>
                            <th>Bericht</th>
                            <th>Methode</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($donations as $donation): 
                            $isManual = str_starts_with($donation['mollie_payment_id'], 'manual_');
                        ?>
                            <tr>
                                <td data-label="Datum"><?= date('d-m-Y H:i', strtotime($donation['paid_at'])) ?></td>
                                <td data-label="Bedrag" class="amount">€<?= number_format($donation['amount'], 2, ',', '.') ?></td>
                                <td data-label="Naam"><?= $donation['donor_name'] ? htmlspecialchars($donation['donor_name']) : '-' ?></td>
                                <td data-label="Bericht" class="message"><?= $donation['message'] ? htmlspecialchars($donation['message']) : '-' ?></td>
                                <td data-label="Methode"><span class="method <?= $isManual ? 'manual' : 'mollie' ?>"><?= $isManual ? 'Manueel' : 'Mollie' ?></span></td>
                                <td class="actions">
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Weet je zeker dat je deze donatie wilt verwijderen?')">
                                        <input type="hidden" name="delete_id" value="<?= $donation['id'] ?>">
                                        <button type="submit" class="btn-delete">Verwijderen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
