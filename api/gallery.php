<?php
require_once '../admin/config.php';
require_once 'cors.php';

header('Content-Type: application/json');
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$uploadDir = __DIR__ . '/../img/galerij/';

try {
    switch ($method) {
        case 'GET':
            $stmt = $pdo->query("SELECT id, filename, alt_text, sort_order FROM gallery_images ORDER BY sort_order ASC, id DESC");
            $images = $stmt->fetchAll();
            echo json_encode(['success' => true, 'images' => $images]);
            break;

        case 'POST':
            if (!$isAdmin) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
                break;
            }
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'Geen afbeelding ontvangen']);
                break;
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $fileType = $finfo->file($_FILES['image']['tmp_name']);
            if (!in_array($fileType, $allowedTypes)) {
                echo json_encode(['success' => false, 'error' => 'Alleen JPG, PNG en WebP toegestaan']);
                break;
            }

            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($ext, $allowedExts)) {
                echo json_encode(['success' => false, 'error' => 'Ongeldig bestandstype']);
                break;
            }

            $filename = uniqid('galerij_') . '.' . $ext;
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $filename)) {
                echo json_encode(['success' => false, 'error' => 'Upload mislukt']);
                break;
            }

            $altText = $_POST['alt_text'] ?? '';
            $stmt = $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM gallery_images");
            $sortOrder = $stmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO gallery_images (filename, alt_text, sort_order) VALUES (?, ?, ?)");
            $stmt->execute([$filename, $altText, $sortOrder]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'filename' => $filename]);
            break;

        case 'DELETE':
            if (!$isAdmin) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $id = intval($data['id'] ?? 0);

            $stmt = $pdo->prepare("SELECT filename FROM gallery_images WHERE id = ?");
            $stmt->execute([$id]);
            $image = $stmt->fetch();
            if ($image) {
                $filepath = $uploadDir . $image['filename'];
                if (file_exists($filepath)) {
                    unlink($filepath);
                }
                $stmt = $pdo->prepare("DELETE FROM gallery_images WHERE id = ?");
                $stmt->execute([$id]);
            }
            echo json_encode(['success' => true]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database fout']);
}
?>
