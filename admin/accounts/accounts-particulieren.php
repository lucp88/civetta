<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$currentPage = 'accounts';
$adminBasePath = '../';

$accounts = [];

try {
    $stmt = $pdo->query("SELECT * FROM business_accounts WHERE account_type = 'particulier' AND status = 'approved' ORDER BY approved_at DESC");
    $accounts = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Database fout: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Particuliere accounts | Civetta Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--cream);
            color: var(--text-primary);
            min-height: 100vh;
        }
        .admin-content {
            padding: 2rem;
            max-width: 1100px;
        }
        @media (max-width: 768px) {
            .admin-content { padding: 1.25rem; }
        }
        .breadcrumb { margin-bottom: 1.5rem; }
        .breadcrumb a { color: #3d6b3d; text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        .breadcrumb span { color: #888; margin: 0 0.5rem; }
        .section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .section h2 {
            color: #2d4a2d;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .section h2 .count {
            background: #e8dfd2;
            color: #3d6b3d;
            font-size: 0.85rem;
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
        }
        .empty {
            color: #888;
            font-style: italic;
            padding: 1rem;
            text-align: center;
        }
        .account-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .account-item {
            border: 1px solid #e8dfd2;
            border-radius: 10px;
            padding: 1.25rem;
            transition: box-shadow 0.2s;
            border-left: 4px solid #28a745;
        }
        .account-item:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .account-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }
        .account-name {
            font-size: 1.15rem;
            font-weight: 600;
            color: #2d4a2d;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .account-date {
            font-size: 0.8rem;
            color: #888;
        }
        .account-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
            font-size: 0.9rem;
            color: #666;
        }
        .account-details dt {
            font-weight: 600;
            color: #3d6b3d;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .account-details dd { margin-bottom: 0.5rem; }
        .account-details a { color: #3d6b3d; text-decoration: none; }
        .account-details a:hover { text-decoration: underline; }
        .account-actions {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e8dfd2;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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
            transition: background 0.2s;
        }
        .btn:hover { background: #2d4a2d; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #1e7e34; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #c82333; }
        .btn-small { padding: 0.35rem 0.75rem; font-size: 0.85rem; }
        .btn-secondary { background: #6c757d; }
        .btn-secondary:hover { background: #545b62; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-warning:hover { background: #e0a800; }
        .btn-info { background: #17a2b8; }
        .btn-info:hover { background: #138496; }
        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .message.success { background: #d4edda; color: #155724; }
        .message.error { background: #f8d7da; color: #721c24; }
        .balance-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .balance-badge.active {
            background: #d4edda;
            color: #155724;
        }
        .balance-badge.inactive {
            background: #e8dfd2;
            color: #3d6b3d;
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .section-header h2 { margin-bottom: 0; }
        .btn-new {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 1rem;
            background: #3d6b3d;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: background 0.2s;
        }
        .btn-new:hover { background: #2d4a2d; }
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            margin: 1rem;
        }
        .modal.modal-wide { max-width: 600px; }
        .modal h3 {
            color: #2d4a2d;
            margin-bottom: 1rem;
        }
        .modal .form-group { margin-bottom: 1rem; }
        .modal .form-group label {
            display: block;
            font-weight: 600;
            color: #2d4a2d;
            margin-bottom: 0.25rem;
            font-size: 0.85rem;
        }
        .modal .form-group input,
        .modal .form-group textarea,
        .modal .form-group select {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.95rem;
        }
        .modal .form-group input:focus,
        .modal .form-group textarea:focus {
            outline: none;
            border-color: #3d6b3d;
        }
        .modal-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1.5rem;
            justify-content: flex-end;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 1rem;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .checkbox-group input[type="checkbox"] {
            width: auto;
        }
        .transaction-list {
            max-height: 300px;
            overflow-y: auto;
            margin-top: 1rem;
        }
        .transaction-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f0ebe3;
            font-size: 0.85rem;
        }
        .transaction-item:last-child { border-bottom: none; }
        .transaction-amount {
            font-weight: 600;
        }
        .transaction-amount.credit { color: #28a745; }
        .transaction-amount.debit { color: #dc3545; }
        .transaction-desc { color: #666; }
        .transaction-date { color: #999; font-size: 0.8rem; }
        .balance-display {
            text-align: center;
            padding: 1rem;
            background: #f8f5f0;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .balance-display .amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2d4a2d;
        }
        .balance-display .label {
            font-size: 0.8rem;
            color: #888;
            text-transform: uppercase;
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
                    <span class="topbar-title">Particuliere accounts</span>
                </div>
                <div class="topbar-right">
                    <a href="accounts.php" class="topbar-link">
                        <i class="bi bi-arrow-left"></i> <span>Terug</span>
                    </a>
                </div>
            </header>

            <div class="admin-content">
        <div class="breadcrumb">
            <a href="../index.php">Dashboard</a>
            <span>›</span>
            <a href="accounts.php">Accounts beheren</a>
            <span>›</span>
            Particulieren
        </div>

        <div id="message-container"></div>

        <div class="section">
            <div class="section-header">
                <h2>
                    Particuliere accounts
                    <span class="count"><?= count($accounts) ?></span>
                </h2>
                <button class="btn-new" onclick="openCreateModal()">+ Nieuw Account</button>
            </div>

            <?php if (empty($accounts)): ?>
                <div class="empty">Nog geen particuliere accounts</div>
            <?php else: ?>
                <div class="account-list">
                    <?php foreach ($accounts as $account): ?>
                        <div class="account-item" id="account-<?= $account['id'] ?>">
                            <div class="account-header">
                                <div class="account-name">
                                    <?= htmlspecialchars($account['bedrijfsnaam']) ?>
                                    <?php if ($account['has_balance']): ?>
                                        <span class="balance-badge active">
                                            <i class="bi bi-wallet2"></i>
                                            &euro;<?= number_format($account['balance'], 2, ',', '.') ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($account['delivery_enabled'])): ?>
                                        <span class="balance-badge" style="background: #e3f2fd; color: #1565c0;">
                                            <i class="bi bi-truck"></i>
                                            Bezorging<?php if ($account['delivery_cost'] > 0): ?> (&euro;<?= number_format($account['delivery_cost'], 2, ',', '.') ?>)<?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="account-date">Aangemaakt: <?= date('d-m-Y', strtotime($account['approved_at'] ?: $account['created_at'])) ?></div>
                            </div>
                            <dl class="account-details">
                                <div>
                                    <dt>Adres</dt>
                                    <dd><?= htmlspecialchars($account['adres']) ?><br><?= htmlspecialchars($account['postcode'] . ' ' . $account['plaats']) ?></dd>
                                </div>
                                <div>
                                    <dt>Contactpersoon</dt>
                                    <dd><?= htmlspecialchars($account['contactpersoon']) ?></dd>
                                </div>
                                <div>
                                    <dt>E-mail</dt>
                                    <dd><a href="mailto:<?= htmlspecialchars($account['email']) ?>"><?= htmlspecialchars($account['email']) ?></a></dd>
                                </div>
                                <div>
                                    <dt>Telefoon</dt>
                                    <dd><?= htmlspecialchars($account['telefoon'] ?: '-') ?></dd>
                                </div>
                            </dl>
                            <div class="account-actions">
                                <button class="btn btn-small" onclick="openEditModal(<?= htmlspecialchars(json_encode($account), ENT_QUOTES) ?>)">
                                    <i class="bi bi-pencil"></i> Bewerken
                                </button>
                                <?php if ($account['has_balance']): ?>
                                <button class="btn btn-info btn-small" onclick="openBalanceModal(<?= $account['id'] ?>, '<?= htmlspecialchars($account['bedrijfsnaam'], ENT_QUOTES) ?>')">
                                    <i class="bi bi-wallet2"></i> Saldo beheren
                                </button>
                                <?php endif; ?>
                                <button class="btn btn-warning btn-small" onclick="resetPassword(<?= $account['id'] ?>, '<?= htmlspecialchars($account['email'], ENT_QUOTES) ?>')">
                                    <i class="bi bi-key"></i> Nieuw wachtwoord
                                </button>
                                <button class="btn btn-danger btn-small" onclick="deleteAccount(<?= $account['id'] ?>, '<?= htmlspecialchars($account['bedrijfsnaam'], ENT_QUOTES) ?>')">
                                    <i class="bi bi-trash"></i> Verwijderen
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
            </div>
        </div>
    </div>

    <!-- Create Modal -->
    <div class="modal-overlay" id="create-modal">
        <div class="modal">
            <h3>Nieuw Particulier Account</h3>
            <form id="create-form" onsubmit="createAccount(event)">
                <div class="form-group">
                    <label>Naam *</label>
                    <input type="text" id="create-bedrijfsnaam" required>
                </div>
                <div class="form-group">
                    <label>Adres *</label>
                    <input type="text" id="create-adres" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Postcode</label>
                        <input type="text" id="create-postcode">
                    </div>
                    <div class="form-group">
                        <label>Plaats</label>
                        <input type="text" id="create-plaats">
                    </div>
                </div>
                <div class="form-group">
                    <label>E-mail *</label>
                    <input type="email" id="create-email" required>
                </div>
                <div class="form-group">
                    <label>Telefoon</label>
                    <input type="tel" id="create-telefoon">
                </div>
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="create-has_balance">
                        <label for="create-has_balance" style="margin-bottom: 0;">Heeft saldo</label>
                    </div>
                </div>
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="create-delivery_enabled" onchange="toggleCreateDeliveryFields()">
                        <label for="create-delivery_enabled" style="margin-bottom: 0;">Bezorging ingeschakeld</label>
                    </div>
                </div>
                <div id="create-delivery-fields" style="display: none;">
                    <div class="form-group">
                        <label>Bezorgkosten (&euro;)</label>
                        <input type="number" id="create-delivery_cost" step="0.01" min="0" value="0" placeholder="0.00">
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Annuleren</button>
                    <button type="submit" class="btn btn-success">Aanmaken</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal-overlay" id="edit-modal">
        <div class="modal">
            <h3>Account bewerken</h3>
            <form id="edit-form" onsubmit="saveAccount(event)">
                <input type="hidden" id="edit-id">
                <div class="form-group">
                    <label>Naam *</label>
                    <input type="text" id="edit-bedrijfsnaam" required>
                </div>
                <div class="form-group">
                    <label>Adres *</label>
                    <input type="text" id="edit-adres" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Postcode</label>
                        <input type="text" id="edit-postcode">
                    </div>
                    <div class="form-group">
                        <label>Plaats</label>
                        <input type="text" id="edit-plaats">
                    </div>
                </div>
                <div class="form-group">
                    <label>Contactpersoon *</label>
                    <input type="text" id="edit-contactpersoon" required>
                </div>
                <div class="form-group">
                    <label>E-mail *</label>
                    <input type="email" id="edit-email" required>
                </div>
                <div class="form-group">
                    <label>Telefoon</label>
                    <input type="tel" id="edit-telefoon">
                </div>
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="edit-has_balance">
                        <label for="edit-has_balance" style="margin-bottom: 0;">Heeft saldo</label>
                    </div>
                </div>
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="edit-delivery_enabled" onchange="toggleEditDeliveryFields()">
                        <label for="edit-delivery_enabled" style="margin-bottom: 0;">Bezorging ingeschakeld</label>
                    </div>
                </div>
                <div id="edit-delivery-fields" style="display: none;">
                    <div class="form-group">
                        <label>Bezorgkosten (&euro;)</label>
                        <input type="number" id="edit-delivery_cost" step="0.01" min="0" value="0" placeholder="0.00">
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Annuleren</button>
                    <button type="submit" class="btn btn-success">Opslaan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Balance Modal -->
    <div class="modal-overlay" id="balance-modal">
        <div class="modal modal-wide">
            <h3 id="balance-modal-title">Saldo beheren</h3>

            <div class="balance-display">
                <div class="label">Huidig saldo</div>
                <div class="amount" id="balance-current">€0,00</div>
            </div>

            <form id="balance-form" onsubmit="addBalance(event)">
                <input type="hidden" id="balance-account-id">
                <div class="form-row">
                    <div class="form-group">
                        <label>Bedrag (€)</label>
                        <input type="number" id="balance-amount" step="0.01" min="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select id="balance-type">
                            <option value="credit">Opwaarderen (+)</option>
                            <option value="debit">Afboeken (-)</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Omschrijving *</label>
                    <input type="text" id="balance-description" required placeholder="bijv. Tegoed opgewaardeerd">
                </div>
                <div class="modal-actions" style="margin-top: 0.75rem; margin-bottom: 1rem;">
                    <button type="submit" class="btn btn-success btn-small">Toevoegen</button>
                </div>
            </form>

            <h4 style="color: #2d4a2d; margin-bottom: 0.5rem; font-size: 0.9rem;">Transactiehistorie</h4>
            <div class="transaction-list" id="transaction-list">
                <div class="empty">Laden...</div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeBalanceModal()">Sluiten</button>
            </div>
        </div>
    </div>

    <script src="../../js/ui-notifications.js?v=1"></script>
    <script>
        function showMessage(text, type) {
            const container = document.getElementById('message-container');
            container.innerHTML = `<div class="message ${type}">${text}</div>`;
            setTimeout(() => container.innerHTML = '', 5000);
        }

        function toggleCreateDeliveryFields() {
            document.getElementById('create-delivery-fields').style.display =
                document.getElementById('create-delivery_enabled').checked ? '' : 'none';
        }

        function toggleEditDeliveryFields() {
            document.getElementById('edit-delivery-fields').style.display =
                document.getElementById('edit-delivery_enabled').checked ? '' : 'none';
        }

        // Create
        function openCreateModal() {
            document.getElementById('create-form').reset();
            document.getElementById('create-delivery-fields').style.display = 'none';
            document.getElementById('create-modal').classList.add('active');
        }

        function closeCreateModal() {
            document.getElementById('create-modal').classList.remove('active');
        }

        async function createAccount(event) {
            event.preventDefault();

            const naam = document.getElementById('create-bedrijfsnaam').value;
            const data = {
                admin_create: true,
                account_type: 'particulier',
                bedrijfsnaam: naam,
                adres: document.getElementById('create-adres').value,
                postcode: document.getElementById('create-postcode').value,
                plaats: document.getElementById('create-plaats').value,
                contactpersoon: naam,
                email: document.getElementById('create-email').value,
                telefoon: document.getElementById('create-telefoon').value,
                website: '',
                kvk_nummer: '',
                btw_id: '',
                has_balance: document.getElementById('create-has_balance').checked ? 1 : 0,
                delivery_enabled: document.getElementById('create-delivery_enabled').checked ? 1 : 0,
                delivery_cost: parseFloat(document.getElementById('create-delivery_cost').value) || 0
            };

            try {
                const response = await fetch('../../api/business-accounts.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await response.json();

                if (result.success) {
                    showMessage(result.message, 'success');
                    closeCreateModal();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showMessage(result.error || 'Er ging iets mis', 'error');
                }
            } catch (error) {
                showMessage('Er ging iets mis', 'error');
            }
        }

        // Edit
        function openEditModal(account) {
            document.getElementById('edit-id').value = account.id;
            document.getElementById('edit-bedrijfsnaam').value = account.bedrijfsnaam || '';
            document.getElementById('edit-adres').value = account.adres || '';
            document.getElementById('edit-postcode').value = account.postcode || '';
            document.getElementById('edit-plaats').value = account.plaats || '';
            document.getElementById('edit-contactpersoon').value = account.contactpersoon || '';
            document.getElementById('edit-email').value = account.email || '';
            document.getElementById('edit-telefoon').value = account.telefoon || '';
            document.getElementById('edit-has_balance').checked = !!parseInt(account.has_balance);
            document.getElementById('edit-delivery_enabled').checked = !!parseInt(account.delivery_enabled);
            document.getElementById('edit-delivery_cost').value = parseFloat(account.delivery_cost || 0).toFixed(2);
            toggleEditDeliveryFields();
            document.getElementById('edit-modal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('edit-modal').classList.remove('active');
        }

        async function saveAccount(event) {
            event.preventDefault();

            const id = document.getElementById('edit-id').value;
            const data = {
                id: parseInt(id),
                action: 'update',
                bedrijfsnaam: document.getElementById('edit-bedrijfsnaam').value,
                adres: document.getElementById('edit-adres').value,
                postcode: document.getElementById('edit-postcode').value,
                plaats: document.getElementById('edit-plaats').value,
                contactpersoon: document.getElementById('edit-contactpersoon').value,
                email: document.getElementById('edit-email').value,
                telefoon: document.getElementById('edit-telefoon').value,
                website: '',
                kvk_nummer: '',
                btw_id: '',
                has_balance: document.getElementById('edit-has_balance').checked ? 1 : 0,
                delivery_enabled: document.getElementById('edit-delivery_enabled').checked ? 1 : 0,
                delivery_cost: parseFloat(document.getElementById('edit-delivery_cost').value) || 0
            };

            try {
                const response = await fetch('../../api/business-accounts.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await response.json();

                if (result.success) {
                    showMessage('Account bijgewerkt!', 'success');
                    closeEditModal();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showMessage(result.error || 'Er ging iets mis', 'error');
                }
            } catch (error) {
                showMessage('Er ging iets mis', 'error');
            }
        }

        // Delete
        async function deleteAccount(id, name) {
            if (!await showConfirm(`Weet je zeker dat je "${name}" permanent wilt verwijderen? Dit kan niet ongedaan worden gemaakt.`)) return;

            try {
                const response = await fetch(`../../api/business-accounts.php?id=${id}`, {
                    method: 'DELETE'
                });
                const data = await response.json();

                if (data.success) {
                    showMessage('Account verwijderd', 'success');
                    document.getElementById('account-' + id).remove();
                } else {
                    showMessage(data.error || 'Er ging iets mis', 'error');
                }
            } catch (error) {
                showMessage('Er ging iets mis', 'error');
            }
        }

        // Reset password
        async function resetPassword(id, email) {
            if (!await showConfirm(`Weet je zeker dat je een nieuw wachtwoord wilt genereren voor ${email}? Het nieuwe wachtwoord wordt per e-mail verzonden.`)) return;

            try {
                const response = await fetch('../../api/business-accounts.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id, action: 'reset_password' })
                });
                const data = await response.json();

                if (data.success) {
                    showMessage(data.message, 'success');
                } else {
                    showMessage(data.error || 'Er ging iets mis', 'error');
                }
            } catch (error) {
                showMessage('Er ging iets mis', 'error');
            }
        }

        // Balance
        function openBalanceModal(accountId, name) {
            document.getElementById('balance-account-id').value = accountId;
            document.getElementById('balance-modal-title').textContent = 'Saldo beheren — ' + name;
            document.getElementById('balance-form').reset();
            document.getElementById('balance-modal').classList.add('active');
            loadTransactions(accountId);
        }

        function closeBalanceModal() {
            document.getElementById('balance-modal').classList.remove('active');
        }

        function formatPrice(amount) {
            return '€' + parseFloat(amount).toFixed(2).replace('.', ',');
        }

        async function loadTransactions(accountId) {
            try {
                const response = await fetch(`../../api/balance.php?account_id=${accountId}`);
                const data = await response.json();

                if (data.success) {
                    document.getElementById('balance-current').textContent = formatPrice(data.balance);

                    const list = document.getElementById('transaction-list');
                    if (data.transactions.length === 0) {
                        list.innerHTML = '<div class="empty">Geen transacties</div>';
                        return;
                    }

                    list.innerHTML = data.transactions.map(t => {
                        const isPositive = parseFloat(t.amount) > 0;
                        const date = new Date(t.created_at);
                        const dateStr = date.toLocaleDateString('nl-NL') + ' ' + date.toLocaleTimeString('nl-NL', {hour: '2-digit', minute: '2-digit'});
                        return `
                            <div class="transaction-item">
                                <div>
                                    <div class="transaction-desc">${escapeHtml(t.description)}</div>
                                    <div class="transaction-date">${dateStr} — ${escapeHtml(t.created_by)}</div>
                                </div>
                                <div class="transaction-amount ${isPositive ? 'credit' : 'debit'}">
                                    ${isPositive ? '+' : ''}${formatPrice(t.amount)}
                                </div>
                            </div>
                        `;
                    }).join('');
                }
            } catch (error) {
                document.getElementById('transaction-list').innerHTML = '<div class="empty">Fout bij laden</div>';
            }
        }

        async function addBalance(event) {
            event.preventDefault();

            const accountId = document.getElementById('balance-account-id').value;
            const data = {
                account_id: parseInt(accountId),
                amount: parseFloat(document.getElementById('balance-amount').value),
                type: document.getElementById('balance-type').value,
                description: document.getElementById('balance-description').value
            };

            try {
                const response = await fetch('../../api/balance.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await response.json();

                if (result.success) {
                    showMessage('Saldo bijgewerkt!', 'success');
                    document.getElementById('balance-form').reset();
                    loadTransactions(accountId);
                } else {
                    showMessage(result.error || 'Er ging iets mis', 'error');
                }
            } catch (error) {
                showMessage('Er ging iets mis', 'error');
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Modal click-outside handlers
        document.getElementById('create-modal').addEventListener('click', function(e) {
            if (e.target === this) closeCreateModal();
        });
        document.getElementById('edit-modal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });
        document.getElementById('balance-modal').addEventListener('click', function(e) {
            if (e.target === this) closeBalanceModal();
        });
    </script>
</body>
</html>
