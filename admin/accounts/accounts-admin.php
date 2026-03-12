<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$adminPageTitle = 'Admin Accounts';
$currentPage = 'accounts';
$adminBasePath = '../';

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $displayName = trim($_POST['display_name'] ?? '');

        if (!$username || !$password) {
            $error = 'Vul alle velden in.';
        } elseif (strlen($password) < 8) {
            $error = 'Wachtwoord moet minimaal 8 tekens zijn.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetchColumn() > 0) {
                    $error = 'Gebruikersnaam bestaat al.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, display_name) VALUES (?, ?, ?)");
                    $stmt->execute([$username, $hash, $displayName ?: null]);
                    $message = "Account '$username' aangemaakt.";
                }
            } catch (PDOException $e) {
                $error = 'Database fout.';
            }
        }
    } elseif ($action === 'update_name') {
        $id = intval($_POST['id'] ?? 0);
        $displayName = trim($_POST['display_name'] ?? '');
        try {
            $stmt = $pdo->prepare("UPDATE users SET display_name = ? WHERE id = ?");
            $stmt->execute([$displayName ?: null, $id]);
            $message = 'Naam bijgewerkt.';
        } catch (PDOException $e) {
            $error = 'Database fout.';
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        // Don't allow deleting your own account
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if ($user && $user['username'] === $_SESSION['admin_user']) {
            $error = 'Je kunt je eigen account niet verwijderen.';
        } elseif ($user) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $message = "Account '{$user['username']}' verwijderd.";
        }
    } elseif ($action === 'reset_password') {
        $id = intval($_POST['id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        if (strlen($newPassword) < 8) {
            $error = 'Wachtwoord moet minimaal 8 tekens zijn.';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hash, $id]);
            $message = 'Wachtwoord gewijzigd.';
        }
    }
}

$adminAccounts = $pdo->query("SELECT id, username, display_name, created_at FROM users ORDER BY id ASC")->fetchAll();
ob_start(); ?>
<style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--cream);
            color: var(--text-primary);
            min-height: 100vh;
        }
        .admin-content { padding: 2rem; }
        @media (max-width: 768px) { .admin-content { padding: 1.25rem; } }

        .breadcrumb { margin-bottom: 1.5rem; }
        .breadcrumb a { color: #3d6b3d; text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }

        .page-title { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; }
        .page-subtitle { color: var(--text-muted); margin-bottom: 2rem; }

        .card {
            background: var(--white);
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .card-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border);
            font-weight: 600;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .card-body { padding: 0; }

        /* Table */
        .accounts-table { width: 100%; border-collapse: collapse; }
        .accounts-table thead th {
            padding: 0.65rem 1.25rem;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-muted);
            background: var(--cream);
            border-bottom: 1px solid var(--border);
        }
        .accounts-table thead th.col-actions { width: 56px; text-align: center; }
        .accounts-table tbody tr { transition: background 0.1s; }
        .accounts-table tbody tr:hover { background: #faf8f5; }
        .accounts-table tbody tr.expand-row:hover { background: transparent; }
        .accounts-table tbody td {
            padding: 0.85rem 1.25rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.875rem;
            vertical-align: middle;
        }
        .accounts-table tbody tr:last-child td { border-bottom: none; }
        .accounts-table tbody tr.expand-row td { padding: 0; border-bottom: 1px solid var(--border); }
        .accounts-table tbody tr.expand-row:last-child td { border-bottom: none; }

        .account-name-cell { display: flex; align-items: center; gap: 0.75rem; }
        .account-avatar {
            width: 34px; height: 34px; border-radius: 50%;
            background: linear-gradient(135deg, var(--brown-medium), var(--brown));
            display: flex; align-items: center; justify-content: center;
            font-size: 0.8rem; font-weight: 700; color: white;
            flex-shrink: 0;
        }
        .account-name { font-weight: 600; font-size: 0.9rem; }
        .badge-you {
            display: inline-block; font-size: 0.7rem; font-weight: 600;
            background: #e8f5e9; color: #2e7d32;
            border: 1px solid #c8e6c9;
            padding: 0.1rem 0.45rem; border-radius: 999px;
            margin-left: 0.4rem; vertical-align: middle;
        }
        .account-username { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.1rem; }

        /* Dropdown menu */
        .action-menu { position: relative; display: flex; justify-content: center; }
        .action-menu-btn {
            width: 32px; height: 32px; border-radius: var(--radius-sm);
            border: 1px solid var(--border); background: var(--white);
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            color: var(--text-muted); transition: all 0.15s;
        }
        .action-menu-btn:hover { background: var(--cream); border-color: var(--brown-medium); color: var(--brown); }
        .action-menu-btn.open { background: var(--cream); border-color: var(--brown-medium); color: var(--brown); }
        .action-dropdown {
            display: none;
            position: absolute;
            right: 0; top: calc(100% + 4px);
            background: white;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            min-width: 170px;
            z-index: 100;
        }
        .action-dropdown.open { display: block; }
        .action-dropdown-item {
            display: flex; align-items: center; gap: 0.6rem;
            padding: 0.6rem 0.9rem;
            font-size: 0.85rem; cursor: pointer;
            color: var(--text-primary);
            border: none; background: none; width: 100%; text-align: left;
            transition: background 0.1s;
        }
        .action-dropdown-item:hover { background: var(--cream); }
        .action-dropdown-item.danger { color: #c0392b; }
        .action-dropdown-item.danger:hover { background: #fdf2f2; }
        .action-dropdown-divider { height: 1px; background: var(--border); margin: 0.25rem 0; }

        /* Inline edit panel */
        .inline-edit-panel {
            display: none;
            padding: 1rem 1.25rem;
            background: #faf8f5;
            border-top: 1px solid var(--border);
        }
        .inline-edit-panel.active { display: block; }
        .inline-edit-header {
            font-size: 0.8rem; font-weight: 600; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.04em;
            margin-bottom: 0.65rem;
        }
        .inline-edit-row { display: flex; gap: 0.5rem; align-items: center; }
        .inline-edit-row input {
            flex: 1; padding: 0.55rem 0.8rem;
            border: 1px solid var(--border); border-radius: var(--radius-sm);
            font-size: 0.875rem; background: white;
        }
        .inline-edit-row input:focus { outline: none; border-color: var(--brown-medium); }

        .btn {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.5rem 1rem; border-radius: var(--radius-sm);
            font-size: 0.8rem; font-weight: 600; border: none; cursor: pointer;
            transition: all 0.15s; text-decoration: none; white-space: nowrap;
        }
        .btn-primary { background: linear-gradient(135deg, var(--brown-medium), var(--brown)); color: white; box-shadow: 0 1px 3px rgba(0,0,0,0.15); }
        .btn-primary:hover { opacity: 0.9; box-shadow: 0 2px 6px rgba(0,0,0,0.2); }
        .btn-ghost {
            background: transparent; border: 1px solid var(--border);
            color: var(--text-secondary);
        }
        .btn-ghost:hover { background: var(--cream); }

        /* Create form */
        .card-body-padded { padding: 1.5rem; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 600px) { .form-grid { grid-template-columns: 1fr; } }
        .form-row { margin-bottom: 0; }
        .form-row label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--text-secondary); }
        .form-row input {
            width: 100%; padding: 0.6rem 0.85rem;
            border: 1px solid var(--border); border-radius: var(--radius-sm);
            font-size: 0.875rem;
        }
        .form-row input:focus { outline: none; border-color: var(--brown-medium); }
        .form-actions { display: flex; gap: 0.5rem; margin-top: 1.25rem; }

        .alert {
            padding: 0.75rem 1rem; border-radius: var(--radius-sm);
            margin-bottom: 1.5rem; font-size: 0.875rem;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
<?php $adminExtraHead = ob_get_clean();
require_once '../components/sidebar.php'; ?>

            <header class="topbar">
                <div class="topbar-left">
                    <span class="topbar-title">Admin accounts</span>
                </div>
                <div class="topbar-right"></div>
            </header>

            <div class="admin-content">
                <div class="breadcrumb">
                    <a href="accounts.php">Accounts</a> &rsaquo; Admin
                </div>

                <h2 class="page-title">Admin accounts</h2>
                <p class="page-subtitle">Beheer admin accounts voor het admin panel en frontend bewerking</p>

                <?php if ($message): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <span>Bestaande accounts</span>
                        <div style="display:flex;align-items:center;gap:0.5rem;">
                            <span style="font-size:0.8rem;font-weight:500;color:var(--text-muted);background:var(--cream);padding:0.2rem 0.6rem;border-radius:999px;border:1px solid var(--border);"><?= count($adminAccounts) ?></span>
                            <button class="btn btn-primary" onclick="toggleCreateForm(this)" style="padding:0.35rem 0.7rem;font-size:0.8rem;" title="Nieuw account aanmaken">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <table class="accounts-table">
                            <thead>
                                <tr>
                                    <th>Naam</th>
                                    <th>Aangemaakt</th>
                                    <th class="col-actions"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($adminAccounts as $account):
                                    $displayName = $account['display_name'] ?: $account['username'];
                                    $initials = strtoupper(substr($displayName, 0, 1));
                                    $isSelf = $account['username'] === $_SESSION['admin_user'];
                                ?>
                                <tr>
                                    <td>
                                        <div class="account-name-cell">
                                            <div class="account-avatar"><?= htmlspecialchars($initials) ?></div>
                                            <div>
                                                <div class="account-name">
                                                    <?= htmlspecialchars($displayName) ?>
                                                    <?php if ($isSelf): ?><span class="badge-you">jij</span><?php endif; ?>
                                                </div>
                                                <div class="account-username"><?= htmlspecialchars($account['username']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="color:var(--text-muted);font-size:0.85rem;"><?= date('j M Y', strtotime($account['created_at'])) ?></td>
                                    <td>
                                        <div class="action-menu">
                                            <button class="action-menu-btn" onclick="toggleMenu('menu-<?= $account['id'] ?>', this)" title="Opties">
                                                <i class="bi bi-three-dots-vertical" style="font-size:0.9rem;"></i>
                                            </button>
                                            <div class="action-dropdown" id="menu-<?= $account['id'] ?>">
                                                <button class="action-dropdown-item" onclick="closeMenus(); openPanel('name-<?= $account['id'] ?>')">
                                                    <i class="bi bi-pencil"></i> Naam wijzigen
                                                </button>
                                                <button class="action-dropdown-item" onclick="closeMenus(); openPanel('reset-<?= $account['id'] ?>')">
                                                    <i class="bi bi-key"></i> Wachtwoord resetten
                                                </button>
                                                <?php if (!$isSelf): ?>
                                                    <div class="action-dropdown-divider"></div>
                                                    <button class="action-dropdown-item danger" onclick="closeMenus(); submitDelete(<?= $account['id'] ?>, '<?= htmlspecialchars($account['username'], ENT_QUOTES) ?>')">
                                                        <i class="bi bi-trash"></i> Verwijderen
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr class="expand-row" id="name-<?= $account['id'] ?>" style="display:none;">
                                    <td colspan="3">
                                        <div class="inline-edit-panel active">
                                            <div class="inline-edit-header"><i class="bi bi-pencil"></i> Naam wijzigen</div>
                                            <form method="post">
                                                <input type="hidden" name="action" value="update_name">
                                                <input type="hidden" name="id" value="<?= $account['id'] ?>">
                                                <div class="inline-edit-row">
                                                    <input type="text" name="display_name" placeholder="Voornaam Achternaam" value="<?= htmlspecialchars($account['display_name'] ?? '') ?>">
                                                    <button type="submit" class="btn btn-primary">Opslaan</button>
                                                    <button type="button" class="btn btn-ghost" onclick="closePanel('name-<?= $account['id'] ?>')">Annuleer</button>
                                                </div>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <tr class="expand-row" id="reset-<?= $account['id'] ?>" style="display:none;">
                                    <td colspan="3">
                                        <div class="inline-edit-panel active">
                                            <div class="inline-edit-header"><i class="bi bi-key"></i> Wachtwoord resetten</div>
                                            <form method="post">
                                                <input type="hidden" name="action" value="reset_password">
                                                <input type="hidden" name="id" value="<?= $account['id'] ?>">
                                                <div class="inline-edit-row">
                                                    <input type="password" name="new_password" placeholder="Nieuw wachtwoord (min. 8 tekens)" required minlength="8">
                                                    <button type="submit" class="btn btn-primary">Opslaan</button>
                                                    <button type="button" class="btn btn-ghost" onclick="closePanel('reset-<?= $account['id'] ?>')">Annuleer</button>
                                                </div>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <!-- Hidden delete forms -->
                        <?php foreach ($adminAccounts as $account): ?>
                            <form method="post" id="delete-form-<?= $account['id'] ?>" style="display:none;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $account['id'] ?>">
                            </form>
                        <?php endforeach; ?>

                        <!-- Create form (hidden by default) -->
                        <div id="create-form-panel" style="display:none;border-top:1px solid var(--border);background:#faf8f5;">
                            <div class="card-body-padded">
                                <div class="inline-edit-header" style="margin-bottom:0.75rem;"><i class="bi bi-plus-lg"></i> Nieuw admin account</div>
                                <form method="post">
                                    <input type="hidden" name="action" value="create">
                                    <div class="form-grid">
                                        <div class="form-row">
                                            <label>Gebruikersnaam</label>
                                            <input type="text" name="username" placeholder="bv. admin-jan" required>
                                        </div>
                                        <div class="form-row">
                                            <label>Weergavenaam <span style="font-weight:400;color:var(--text-muted)">(optioneel)</span></label>
                                            <input type="text" name="display_name" placeholder="Voornaam Achternaam">
                                        </div>
                                        <div class="form-row">
                                            <label>Wachtwoord <span style="font-weight:400;color:var(--text-muted)">(min. 8 tekens)</span></label>
                                            <input type="password" name="password" required minlength="8">
                                        </div>
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Account aanmaken</button>
                                        <button type="button" class="btn btn-ghost" onclick="toggleCreateForm()">Annuleer</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../../js/ui-notifications.js?v=1"></script>
    <script>
    function toggleMenu(id, btn) {
        const dropdown = document.getElementById(id);
        const isOpen = dropdown.classList.contains('open');
        closeMenus();
        if (!isOpen) {
            dropdown.classList.add('open');
            btn.classList.add('open');
        }
    }
    function closeMenus() {
        document.querySelectorAll('.action-dropdown.open').forEach(el => el.classList.remove('open'));
        document.querySelectorAll('.action-menu-btn.open').forEach(el => el.classList.remove('open'));
    }
    function openPanel(id) {
        // Close other panels first
        document.querySelectorAll('.expand-row').forEach(row => row.style.display = 'none');
        const row = document.getElementById(id);
        if (row) row.style.display = '';
    }
    function closePanel(id) {
        const row = document.getElementById(id);
        if (row) row.style.display = 'none';
    }
    function submitDelete(id, username) {
        if (confirm('Weet je zeker dat je account "' + username + '" wilt verwijderen?')) {
            document.getElementById('delete-form-' + id).submit();
        }
    }
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.action-menu')) closeMenus();
    });
    function toggleCreateForm(btn) {
        const panel = document.getElementById('create-form-panel');
        const isOpen = panel.style.display !== 'none';
        panel.style.display = isOpen ? 'none' : '';
    }
    </script>
</body>
</html>
