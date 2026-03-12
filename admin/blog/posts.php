<?php
require_once '../config.php';
requireLogin();

$stmt = $pdo->query("SELECT COUNT(*) as count FROM business_accounts WHERE status = 'pending'");
$sidebarPendingAccounts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM business_orders WHERE delivery_date = CURDATE() AND is_cancelled = 0 AND delivery_status = 'geplaatst'");
$stmt->execute();
$sidebarUnprocessedOrders = $stmt->fetch()['count'];

$adminPageTitle = 'Blog Posts';
$currentPage = 'blog';
$adminBasePath = '../';

$stmt = $pdo->query("SELECT * FROM blog_posts ORDER BY post_date DESC, id DESC");
$posts = $stmt->fetchAll();
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
            margin-bottom: 1.5rem;
        }
        .card h2 {
            color: #2d4a2d;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        }
        .btn:hover { background: #2d4a2d; }
        .btn-small {
            padding: 0.35rem 0.75rem;
            font-size: 0.85rem;
        }
        .btn-danger { background: #c00; }
        .btn-danger:hover { background: #900; }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover { background: #5a6268; }
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
            color: #3d6b3d;
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
                    <span class="topbar-title">Blog Posts</span>
                </div>
                <div class="topbar-right"></div>
            </header>

            <div class="admin-content">
                <div class="breadcrumb">
                    <a href="../index.php">Dashboard</a>
                    <span>›</span>
                    Blog Posts
                </div>

                <div class="card">
                    <h2>
                        Blog Posts
                        <a href="post-edit.php" class="btn">+ Nieuwe Post</a>
                    </h2>
                    
                    <?php if (empty($posts)): ?>
                        <div class="empty">Nog geen blog posts. Maak je eerste post!</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <th>Titel</th>
                                    <th>Acties</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($posts as $post): ?>
                                    <tr>
                                        <td><?= date('d-m-Y', strtotime($post['post_date'])) ?></td>
                                        <td><?= htmlspecialchars($post['title']) ?></td>
                                        <td class="actions">
                                            <a href="post-edit.php?id=<?= $post['id'] ?>" class="btn btn-small">Bewerken</a>
                                            <a href="post-delete.php?id=<?= $post['id'] ?>" class="btn btn-small btn-danger" onclick="return confirmLink(this.href, 'Weet je zeker dat je deze post wilt verwijderen?')">Verwijderen</a>
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
<script src="../../js/ui-notifications.js?v=1"></script>
</body>
</html>