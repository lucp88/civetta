<?php
require_once 'cors.php';
require_once '../admin/config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $type = $_GET['type'] ?? null;
            $yearMonth = $_GET['year_month'] ?? null;
            
            $where = "1=1";
            $params = [];
            
            if ($type) {
                $where .= " AND type = ?";
                $params[] = $type;
            }
            
            if ($yearMonth) {
                $where .= " AND year_month = ?";
                $params[] = $yearMonth;
            }
            
            $sql = "SELECT * FROM utility_costs WHERE $where ORDER BY year_month DESC, type";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $costs = $stmt->fetchAll();
            
            if ($yearMonth && count($costs) < 2) {
                $existing = array_column($costs, 'type');
                foreach (['water', 'electricity'] as $t) {
                    if (!in_array($t, $existing)) {
                        $prevMonth = date('Y-m', strtotime($yearMonth . '-01 -1 month'));
                        $stmt = $pdo->prepare("SELECT cost FROM utility_costs WHERE type = ? AND year_month = ?");
                        $stmt->execute([$t, $prevMonth]);
                        $prev = $stmt->fetch();
                        
                        $costs[] = [
                            'id' => null,
                            'type' => $t,
                            'year_month' => $yearMonth,
                            'cost' => $prev ? $prev['cost'] : null,
                            'is_estimate' => 1,
                            'from_previous' => true
                        ];
                    }
                }
            }
            
            echo json_encode(['success' => true, 'costs' => $costs]);
            break;
            
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['type']) || empty($data['year_month']) || !isset($data['cost'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'type, year_month en cost zijn verplicht']);
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
            
            $isEstimate = isset($data['is_estimate']) ? ($data['is_estimate'] ? 1 : 0) : 0;
            
            $stmt = $pdo->prepare("
                INSERT INTO utility_costs (type, year_month, cost, is_estimate)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE cost = VALUES(cost), is_estimate = VALUES(is_estimate)
            ");
            $stmt->execute([
                $data['type'],
                $data['year_month'],
                floatval($data['cost']),
                $isEstimate
            ]);
            
            if (!$isEstimate && $data['year_month'] >= date('Y-m')) {
                $nextMonth = date('Y-m', strtotime($data['year_month'] . '-01 +1 month'));
                $stmt = $pdo->prepare("SELECT id FROM utility_costs WHERE type = ? AND year_month = ?");
                $stmt->execute([$data['type'], $nextMonth]);
                $nextExists = $stmt->fetch();

                if (!$nextExists) {
                    $stmt = $pdo->prepare("
                        INSERT INTO utility_costs (type, year_month, cost, is_estimate)
                        VALUES (?, ?, ?, 1)
                    ");
                    $stmt->execute([
                        $data['type'],
                        $nextMonth,
                        floatval($data['cost'])
                    ]);
                }
            }
            
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
