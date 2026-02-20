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

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

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
                    $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
                    $stmt->execute([$username, $hash]);
                    $message = "Account '$username' aangemaakt.";
                }
            } catch (PDOException $e) {
                $error = 'Database fout.';
            }
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

$adminAccounts = $pdo->query("SELECT id, username, created_at FROM users ORDER BY id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin accounts | Civetta Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--cream);
            color: var(--text-primary);
            min-height: 100vh;
        }
        .admin-content { padding: 2rem; max-width: 800px; }
        @media (max-width: 768px) { .admin-content { padding: 1.25rem; } }

        .breadcrumb { margin-bottom: 1.5rem; }
        .breadcrumb a { color: #8b5a2b; text-decoration: none; }
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
        }
        .card-body { padding: 1.5rem; }

        .account-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--cream-dark);
        }
        .account-row:last-child { border-bottom: none; }
        .account-info { display: flex; flex-direction: column; gap: 0.15rem; }
        .account-name { font-weight: 600; font-size: 0.95rem; }
        .account-meta { font-size: 0.8rem; color: var(--text-muted); }
        .account-you { font-size: 0.75rem; color: #27ae60; font-weight: 600; }
        .account-actions { display: flex; gap: 0.5rem; }

        .btn {
            display: inline-flex; align-items: center; gap: 0.3rem;
            padding: 0.4rem 0.85rem; border-radius: var(--radius-sm);
            font-size: 0.8rem; font-weight: 600; border: none; cursor: pointer;
            transition: all 0.15s; text-decoration: none;
        }
        .btn-primary { background: linear-gradient(135deg, var(--brown-medium), var(--brown)); color: white; }
        .btn-primary:hover { opacity: 0.9; }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text-secondary); }
        .btn-outline:hover { border-color: var(--brown-medium); color: var(--brown); }
        .btn-danger { background: #c0392b; color: white; }
        .btn-danger:hover { background: #a93226; }

        .form-row { margin-bottom: 1rem; }
        .form-row label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--text-secondary); }
        .form-row input {
            width: 100%; padding: 0.6rem 0.85rem;
            border: 1px solid var(--border); border-radius: var(--radius-sm);
            font-size: 0.9rem;
        }
        .form-row input:focus { outline: none; border-color: var(--brown-medium); }
        .form-actions { display: flex; gap: 0.5rem; margin-top: 1rem; }

        .alert {
            padding: 0.75rem 1rem; border-radius: var(--radius-sm);
            margin-bottom: 1.5rem; font-size: 0.9rem;
        }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }

        .reset-form {
            display: none;
            margin-top: 0.5rem;
            padding: 0.75rem;
            background: var(--cream);
            border-radius: var(--radius-sm);
        }
        .reset-form.active { display: block; }
        .reset-form input { margin-bottom: 0.5rem; }
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
                    <span class="topbar-title">Admin accounts</span>
                </div>
                <div class="topbar-right">
                    <a href="accounts.php" class="topbar-link">
                        <i class="bi bi-arrow-left"></i> <span>Accounts</span>
                    </a>
                </div>
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
                    <div class="card-header">Bestaande accounts (<?= count($adminAccounts) ?>)</div>
                    <div class="card-body">
                        <?php foreach ($adminAccounts as $account): ?>
                            <div class="account-row">
                                <div class="account-info">
                                    <span class="account-name">
                                        <?= htmlspecialchars($account['username']) ?>
                                        <?php if ($account['username'] === $_SESSION['admin_user']): ?>
                                            <span class="account-you">(jij)</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="account-meta">Aangemaakt: <?= date('j M Y', strtotime($account['created_at'])) ?></span>
                                </div>
                                <div class="account-actions">
                                    <button class="btn btn-outline" onclick="toggleReset(<?= $account['id'] ?>)">
                                        <i class="bi bi-key"></i> Wachtwoord
                                    </button>
                                    <?php if ($account['username'] !== $_SESSION['admin_user']): ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Weet je zeker dat je dit account wilt verwijderen?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $account['id'] ?>">
                                            <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="reset-form" id="reset-<?= $account['id'] ?>">
                                <form method="post">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="id" value="<?= $account['id'] ?>">
                                    <input type="password" name="new_password" placeholder="Nieuw wachtwoord (min. 8 tekens)" required minlength="8">
                                    <button type="submit" class="btn btn-primary">Opslaan</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">Nieuw admin account</div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="action" value="create">
                            <div class="form-row">
                                <label>Gebruikersnaam</label>
                                <input type="text" name="username" required>
                            </div>
                            <div class="form-row">
                                <label>Wachtwoord (min. 8 tekens)</label>
                                <input type="password" name="password" required minlength="8">
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Account aanmaken</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function toggleReset(id) {
        const el = document.getElementById('reset-' + id);
        el.classList.toggle('active');
    }
    </script>
</body>
</html>
