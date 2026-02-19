<?php
require_once 'cors.php';
require_once '../admin/config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $yearMonth = $_GET['year_month'] ?? null;

            if (!$yearMonth) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'year_month is verplicht']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM utility_costs WHERE `year_month` = ?");
            $stmt->execute([$yearMonth]);
            $rows = $stmt->fetchAll();

            // Return one object per type, with nulls if not yet saved
            $result = [];
            foreach (['water', 'electricity'] as $t) {
                $row = null;
                foreach ($rows as $r) {
                    if ($r['type'] === $t) { $row = $r; break; }
                }
                $result[$t] = [
                    'id'             => $row ? $row['id'] : null,
                    'type'           => $t,
                    'year_month'     => $yearMonth,
                    'cost'           => $row ? $row['cost'] : null,
                    'estimated_cost' => $row ? $row['estimated_cost'] : null,
                ];
            }

            echo json_encode(['success' => true, 'costs' => $result]);
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);

            if (empty($data['type']) || empty($data['year_month'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'type en year_month zijn verplicht']);
                exit;
            }

            if (!in_array($data['type'], ['water', 'electricity'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'type moet water of electricity zijn']);
                exit;
            }

            if (!preg_match('/^\d{4}-\d{2}$/', $data['year_month'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'year_month moet formaat YYYY-MM hebben']);
                exit;
            }

            $cost          = isset($data['cost']) && $data['cost'] !== '' ? floatval($data['cost']) : null;
            $estimatedCost = isset($data['estimated_cost']) && $data['estimated_cost'] !== '' ? floatval($data['estimated_cost']) : null;

            $stmt = $pdo->prepare("
                INSERT INTO utility_costs (type, `year_month`, cost, estimated_cost, is_estimate)
                VALUES (?, ?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE cost = VALUES(cost), estimated_cost = VALUES(estimated_cost)
            ");
            $stmt->execute([$data['type'], $data['year_month'], $cost, $estimatedCost]);

            echo json_encode(['success' => true, 'message' => 'Kosten opgeslagen']);
            break;

        case 'DELETE':
            $id = $_GET['id'] ?? null;

            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'id is verplicht']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM utility_costs WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'Kosten verwijderd']);
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
