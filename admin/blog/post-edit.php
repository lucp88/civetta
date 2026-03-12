<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$currentPage = 'blog';
$adminBasePath = '../';

$id = $_GET['id'] ?? null;
$post = null;
$error = '';
$success = '';

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM blog_posts WHERE id = ?");
    $stmt->execute([$id]);
    $post = $stmt->fetch();
    if (!$post) {
        header('Location: posts.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $post_date = $_POST['post_date'] ?? date('Y-m-d');
    
    if ($title && $content && $post_date) {
        try {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE blog_posts SET title = ?, content = ?, post_date = ? WHERE id = ?");
                $stmt->execute([$title, $content, $post_date, $id]);
                $success = 'Post bijgewerkt!';
            } else {
                $stmt = $pdo->prepare("INSERT INTO blog_posts (title, content, post_date) VALUES (?, ?, ?)");
                $stmt->execute([$title, $content, $post_date]);
                $success = 'Post aangemaakt!';
                $id = $pdo->lastInsertId();
            }
            
            $stmt = $pdo->prepare("SELECT * FROM blog_posts WHERE id = ?");
            $stmt->execute([$id]);
            $post = $stmt->fetch();
        } catch (PDOException $e) {
            $error = 'Fout bij opslaan: ' . $e->getMessage();
        }
    } else {
        $error = 'Vul alle velden in';
    }
}
$adminPageTitle = $id ? 'Post Bewerken' : 'Nieuwe Post';
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
        input:focus, textarea:focus {
            outline: none;
            border-color: #d4a574;
        }
        textarea {
            min-height: 300px;
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
    </style>
<?php $adminExtraHead = ob_get_clean();
require_once '../components/sidebar.php'; ?>

            <header class="topbar">
                <div class="topbar-left">
                    <span class="topbar-title"><?= $id ? 'Post Bewerken' : 'Nieuwe Post' ?></span>
                </div>
                <div class="topbar-right"></div>
            </header>

            <div class="admin-content">
                <div class="breadcrumb">
                    <a href="../index.php">Dashboard</a>
                    <span>›</span>
                    <a href="posts.php">Blog Posts</a>
                    <span>›</span>
                    <?= $id ? 'Bewerken' : 'Nieuw' ?>
                </div>

                <div class="card">
                    <h2><?= $id ? 'Post Bewerken' : 'Nieuwe Post' ?></h2>
                    
                    <?php if ($error): ?>
                        <div class="error"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="success"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="form-group">
                            <label for="title">Titel</label>
                            <input type="text" id="title" name="title" value="<?= htmlspecialchars($post['title'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="post_date">Datum</label>
                            <input type="date" id="post_date" name="post_date" value="<?= htmlspecialchars($post['post_date'] ?? date('Y-m-d')) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="content">Inhoud</label>
                            <textarea id="content" name="content" required><?= htmlspecialchars($post['content'] ?? '') ?></textarea>
                            <p class="help">Gebruik lege regels voor nieuwe paragrafen.</p>
                        </div>
                        
                        <div class="actions">
                            <button type="submit" class="btn"><?= $id ? 'Opslaan' : 'Aanmaken' ?></button>
                            <a href="posts.php" class="btn btn-secondary">Annuleren</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
