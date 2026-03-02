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

$pendingAccounts = [];
$approvedAccounts = [];

try {
    $stmt = $pdo->query("SELECT * FROM business_accounts WHERE status = 'pending' ORDER BY created_at DESC");
    $pendingAccounts = $stmt->fetchAll();
    
    $stmt = $pdo->query("SELECT * FROM business_accounts WHERE status = 'approved' ORDER BY approved_at DESC");
    $approvedAccounts = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Database fout: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bedrijfsaccounts | Civetta Admin</title>
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
        .section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .section h2 {
            color: #5c3d1e;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .section h2 .count {
            background: #e8dfd2;
            color: #8b5a2b;
            font-size: 0.85rem;
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
        }
        .section.pending h2 .count {
            background: #fff3cd;
            color: #856404;
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
        }
        .account-item:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .account-item.pending {
            border-left: 4px solid #ffc107;
        }
        .account-item.approved {
            border-left: 4px solid #28a745;
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
            color: #5c3d1e;
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
            color: #8b5a2b;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .account-details dd {
            margin-bottom: 0.5rem;
        }
        .account-details a {
            color: #8b5a2b;
            text-decoration: none;
        }
        .account-details a:hover {
            text-decoration: underline;
        }
        .account-actions {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e8dfd2;
            display: flex;
            gap: 0.5rem;
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
            transition: background 0.2s;
        }
        .btn:hover { background: #5c3d1e; }
        .btn-success {
            background: #28a745;
        }
        .btn-success:hover {
            background: #1e7e34;
        }
        .btn-danger {
            background: #dc3545;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-small {
            padding: 0.35rem 0.75rem;
            font-size: 0.85rem;
        }
        .opmerkingen {
            grid-column: 1 / -1;
            background: #faf6f0;
            padding: 0.75rem;
            border-radius: 6px;
            margin-top: 0.5rem;
        }
        .opmerkingen dt {
            margin-bottom: 0.25rem;
        }
        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #545b62;
        }
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        .btn-warning:hover {
            background: #e0a800;
        }
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active {
            display: flex;
        }
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
        .modal h3 {
            color: #5c3d1e;
            margin-bottom: 1rem;
        }
        .modal .form-group {
            margin-bottom: 1rem;
        }
        .modal .form-group label {
            display: block;
            font-weight: 600;
            color: #5c3d1e;
            margin-bottom: 0.25rem;
            font-size: 0.85rem;
        }
        .modal .form-group input {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.95rem;
        }
        .modal .form-group input:focus {
            outline: none;
            border-color: #8b5a2b;
        }
        .modal-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1.5rem;
            justify-content: flex-end;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .checkbox-group input[type="checkbox"] {
            width: auto;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 1rem;
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
            background: #8b5a2b;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: background 0.2s;
        }
        .btn-new:hover { background: #5c3d1e; }
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
                    <span class="topbar-title">Bedrijfsaccounts</span>
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
            Bedrijven
        </div>

        <div id="message-container"></div>

        <div class="section pending">
            <h2>
                Aangevraagde accounts
                <span class="count"><?= count($pendingAccounts) ?></span>
            </h2>
            
            <?php if (empty($pendingAccounts)): ?>
                <div class="empty">Geen openstaande aanvragen</div>
            <?php else: ?>
                <div class="account-list">
                    <?php foreach ($pendingAccounts as $account): ?>
                        <div class="account-item pending" id="account-<?= $account['id'] ?>">
                            <div class="account-header">
                                <div class="account-name"><?= htmlspecialchars($account['bedrijfsnaam']) ?></div>
                                <div class="account-date">Aangevraagd: <?= date('d-m-Y H:i', strtotime($account['created_at'])) ?></div>
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
                                <div>
                                    <dt>KVK-nummer</dt>
                                    <dd><?= htmlspecialchars($account['kvk_nummer'] ?: '-') ?></dd>
                                </div>
                                <div>
                                    <dt>BTW-ID</dt>
                                    <dd><?= htmlspecialchars($account['btw_id'] ?: '-') ?></dd>
                                </div>
                                <?php if ($account['website']): ?>
                                <div>
                                    <dt>Website</dt>
                                    <dd><a href="<?= htmlspecialchars($account['website']) ?>" target="_blank"><?= htmlspecialchars($account['website']) ?></a></dd>
                                </div>
                                <?php endif; ?>
                                <?php if ($account['opmerkingen']): ?>
                                <div class="opmerkingen">
                                    <dt>Opmerkingen</dt>
                                    <dd><?= nl2br(htmlspecialchars($account['opmerkingen'])) ?></dd>
                                </div>
                                <?php endif; ?>
                            </dl>
                            <div class="account-actions">
                                <button class="btn btn-success" onclick="approveAccount(<?= $account['id'] ?>, '<?= htmlspecialchars($account['bedrijfsnaam'], ENT_QUOTES) ?>')">
                                    ✓ Goedkeuren
                                </button>
                                <button class="btn btn-danger btn-small" onclick="rejectAccount(<?= $account['id'] ?>, '<?= htmlspecialchars($account['bedrijfsnaam'], ENT_QUOTES) ?>')">
                                    ✕ Afwijzen
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="section approved">
            <div class="section-header">
                <h2>
                    Bevestigde accounts
                    <span class="count"><?= count($approvedAccounts) ?></span>
                </h2>
                <button class="btn-new" onclick="openCreateModal()">+ Nieuw Account</button>
            </div>
            
            <?php if (empty($approvedAccounts)): ?>
                <div class="empty">Nog geen goedgekeurde bedrijfsaccounts</div>
            <?php else: ?>
                <div class="account-list">
                    <?php foreach ($approvedAccounts as $account): ?>
                        <div class="account-item approved" id="approved-<?= $account['id'] ?>">
                            <div class="account-header">
                                <div class="account-name"><?= htmlspecialchars($account['bedrijfsnaam']) ?></div>
                                <div class="account-date">Goedgekeurd: <?= date('d-m-Y', strtotime($account['approved_at'])) ?></div>
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
                                <div>
                                    <dt>KVK-nummer</dt>
                                    <dd><?= htmlspecialchars($account['kvk_nummer'] ?: '-') ?></dd>
                                </div>
                                <div>
                                    <dt>BTW-ID</dt>
                                    <dd><?= htmlspecialchars($account['btw_id'] ?: '-') ?></dd>
                                </div>
                                <?php if ($account['website']): ?>
                                <div>
                                    <dt>Website</dt>
                                    <dd><a href="<?= htmlspecialchars($account['website']) ?>" target="_blank"><?= htmlspecialchars($account['website']) ?></a></dd>
                                </div>
                                <?php endif; ?>
                            </dl>
                            <div class="account-actions">
                                <button class="btn btn-small" onclick="openEditModal(<?= htmlspecialchars(json_encode($account), ENT_QUOTES) ?>)">
                                    ✏️ Bewerken
                                </button>
                                <button class="btn btn-warning btn-small" onclick="resetPassword(<?= $account['id'] ?>, '<?= htmlspecialchars($account['email'], ENT_QUOTES) ?>')">
                                    🔑 Nieuw wachtwoord
                                </button>
                                <button class="btn btn-danger btn-small" onclick="deleteAccount(<?= $account['id'] ?>, '<?= htmlspecialchars($account['bedrijfsnaam'], ENT_QUOTES) ?>')">
                                    🗑️ Verwijderen
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

    <div class="modal-overlay" id="create-modal">
        <div class="modal">
            <h3>Nieuw Account Aanmaken</h3>
            <form id="create-form" onsubmit="createAccount(event)">
                <div class="form-group">
                    <label>Bedrijfsnaam *</label>
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
                    <label>Contactpersoon *</label>
                    <input type="text" id="create-contactpersoon" required>
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
                    <label>Website</label>
                    <input type="url" id="create-website">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>KVK-nummer</label>
                        <input type="text" id="create-kvk_nummer">
                    </div>
                    <div class="form-group">
                        <label>BTW-ID</label>
                        <input type="text" id="create-btw_id">
                    </div>
                </div>
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="create-delivery_enabled" checked onchange="toggleCreateDeliveryFields()">
                        <label for="create-delivery_enabled" style="margin-bottom: 0;">Bezorging ingeschakeld</label>
                    </div>
                </div>
                <div id="create-delivery-fields">
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

    <div class="modal-overlay" id="edit-modal">
        <div class="modal">
            <h3>Account bewerken</h3>
            <form id="edit-form" onsubmit="saveAccount(event)">
                <input type="hidden" id="edit-id">
                <div class="form-group">
                    <label>Bedrijfsnaam *</label>
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
                    <label>Website</label>
                    <input type="url" id="edit-website">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>KVK-nummer</label>
                        <input type="text" id="edit-kvk_nummer">
                    </div>
                    <div class="form-group">
                        <label>BTW-ID</label>
                        <input type="text" id="edit-btw_id">
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

    <script>
        async function approveAccount(id, name) {
            if (!confirm(`Weet je zeker dat je "${name}" wilt goedkeuren? Er wordt automatisch een e-mail verzonden.`)) return;
            
            try {
                const response = await fetch('../../api/business-accounts.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id, action: 'approve' })
                });
                const data = await response.json();
                
                if (data.success) {
                    showMessage('Account goedgekeurd! E-mail is verzonden naar het bedrijf.', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showMessage(data.error || 'Er ging iets mis', 'error');
                }
            } catch (error) {
                showMessage('Er ging iets mis', 'error');
            }
        }

        async function rejectAccount(id, name) {
            if (!confirm(`Weet je zeker dat je "${name}" wilt afwijzen?`)) return;
            
            try {
                const response = await fetch('../../api/business-accounts.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id, action: 'reject' })
                });
                const data = await response.json();
                
                if (data.success) {
                    showMessage('Account afgewezen', 'success');
                    document.getElementById('account-' + id).remove();
                    updateCounts();
                } else {
                    showMessage(data.error || 'Er ging iets mis', 'error');
                }
            } catch (error) {
                showMessage('Er ging iets mis', 'error');
            }
        }

        function showMessage(text, type) {
            const container = document.getElementById('message-container');
            container.innerHTML = `<div class="message ${type}">${text}</div>`;
            setTimeout(() => container.innerHTML = '', 5000);
        }

        function updateCounts() {
            const pendingSection = document.querySelector('.section.pending');
            const pendingItems = pendingSection.querySelectorAll('.account-item').length;
            pendingSection.querySelector('.count').textContent = pendingItems;
            
            if (pendingItems === 0) {
                const list = pendingSection.querySelector('.account-list');
                if (list) {
                    list.innerHTML = '<div class="empty">Geen openstaande aanvragen</div>';
                }
            }
        }

        function toggleCreateDeliveryFields() {
            document.getElementById('create-delivery-fields').style.display =
                document.getElementById('create-delivery_enabled').checked ? '' : 'none';
        }

        function toggleEditDeliveryFields() {
            document.getElementById('edit-delivery-fields').style.display =
                document.getElementById('edit-delivery_enabled').checked ? '' : 'none';
        }

        function openEditModal(account) {
            document.getElementById('edit-id').value = account.id;
            document.getElementById('edit-bedrijfsnaam').value = account.bedrijfsnaam || '';
            document.getElementById('edit-adres').value = account.adres || '';
            document.getElementById('edit-postcode').value = account.postcode || '';
            document.getElementById('edit-plaats').value = account.plaats || '';
            document.getElementById('edit-contactpersoon').value = account.contactpersoon || '';
            document.getElementById('edit-email').value = account.email || '';
            document.getElementById('edit-telefoon').value = account.telefoon || '';
            document.getElementById('edit-website').value = account.website || '';
            document.getElementById('edit-kvk_nummer').value = account.kvk_nummer || '';
            document.getElementById('edit-btw_id').value = account.btw_id || '';
            document.getElementById('edit-delivery_enabled').checked = account.delivery_enabled === undefined || !!parseInt(account.delivery_enabled);
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
                website: document.getElementById('edit-website').value,
                kvk_nummer: document.getElementById('edit-kvk_nummer').value,
                btw_id: document.getElementById('edit-btw_id').value,
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

        async function deleteAccount(id, name) {
            if (!confirm(`Weet je zeker dat je "${name}" permanent wilt verwijderen? Dit kan niet ongedaan worden gemaakt.`)) return;
            
            try {
                const response = await fetch(`../../api/business-accounts.php?id=${id}`, {
                    method: 'DELETE'
                });
                const data = await response.json();
                
                if (data.success) {
                    showMessage('Account verwijderd', 'success');
                    document.getElementById('approved-' + id).remove();
                    updateApprovedCount();
                } else {
                    showMessage(data.error || 'Er ging iets mis', 'error');
                }
            } catch (error) {
                showMessage('Er ging iets mis', 'error');
            }
        }

        async function resetPassword(id, email) {
            if (!confirm(`Weet je zeker dat je een nieuw wachtwoord wilt genereren voor ${email}? Het nieuwe wachtwoord wordt per e-mail verzonden.`)) return;
            
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

        function updateApprovedCount() {
            const approvedSection = document.querySelector('.section.approved');
            const approvedItems = approvedSection.querySelectorAll('.account-item').length;
            approvedSection.querySelector('.count').textContent = approvedItems;
            
            if (approvedItems === 0) {
                const list = approvedSection.querySelector('.account-list');
                if (list) {
                    list.innerHTML = '<div class="empty">Nog geen goedgekeurde bedrijfsaccounts</div>';
                }
            }
        }

        function openCreateModal() {
            document.getElementById('create-form').reset();
            document.getElementById('create-delivery_enabled').checked = true;
            toggleCreateDeliveryFields();
            document.getElementById('create-modal').classList.add('active');
        }

        function closeCreateModal() {
            document.getElementById('create-modal').classList.remove('active');
        }

        async function createAccount(event) {
            event.preventDefault();
            
            const data = {
                admin_create: true,
                bedrijfsnaam: document.getElementById('create-bedrijfsnaam').value,
                adres: document.getElementById('create-adres').value,
                postcode: document.getElementById('create-postcode').value,
                plaats: document.getElementById('create-plaats').value,
                contactpersoon: document.getElementById('create-contactpersoon').value,
                email: document.getElementById('create-email').value,
                telefoon: document.getElementById('create-telefoon').value,
                website: document.getElementById('create-website').value,
                kvk_nummer: document.getElementById('create-kvk_nummer').value,
                btw_id: document.getElementById('create-btw_id').value,
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

        document.getElementById('create-modal').addEventListener('click', function(e) {
            if (e.target === this) closeCreateModal();
        });

        document.getElementById('edit-modal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });
    </script>
</body>
</html>
