<?php
require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/cors.php';
setCorsHeaders();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $start = $_GET['start'] ?? date('Y-m-d');
    $end = $_GET['end'] ?? date('Y-m-d', strtotime('+30 days'));

    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE appointment_date BETWEEN ? AND ? ORDER BY appointment_date, start_time");
    $stmt->execute([$start, $end]);
    $appointments = $stmt->fetchAll();

    echo json_encode(['success' => true, 'appointments' => $appointments]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? 'create';

    if ($action === 'create') {
        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $date = $input['appointment_date'] ?? '';
        $startTime = $input['start_time'] ?? null;
        $endTime = $input['end_time'] ?? null;
        $color = $input['color'] ?? '#8b5a2b';

        if (!$title || !$date) {
            echo json_encode(['success' => false, 'error' => 'Titel en datum zijn verplicht']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO appointments (title, description, appointment_date, start_time, end_time, color) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $description ?: null, $date, $startTime ?: null, $endTime ?: null, $color]);

        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'update') {
        $id = (int)($input['id'] ?? 0);
        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $date = $input['appointment_date'] ?? '';
        $startTime = $input['start_time'] ?? null;
        $endTime = $input['end_time'] ?? null;
        $color = $input['color'] ?? '#8b5a2b';

        if (!$id || !$title || !$date) {
            echo json_encode(['success' => false, 'error' => 'ID, titel en datum zijn verplicht']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE appointments SET title = ?, description = ?, appointment_date = ?, start_time = ?, end_time = ?, color = ? WHERE id = ?");
        $stmt->execute([$title, $description ?: null, $date, $startTime ?: null, $endTime ?: null, $color, $id]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID is verplicht']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM appointments WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Onbekende actie']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
