<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../admin/config.php';

try {
    $stmt = $pdo->query("SELECT id, title, content, post_date FROM blog_posts ORDER BY post_date DESC, id DESC LIMIT 20");
    $posts = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'posts' => $posts
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database fout'
    ]);
}
?>
