<?php
require_once '../admin/config.php';
require_once 'cors.php';

header('Content-Type: application/json');
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
    exit;
}

$action = $_GET['action'] ?? '';
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to = $_GET['to'] ?? date('Y-m-d');

try {
    switch ($action) {
        case 'stats':
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_orders,
                    COALESCE(SUM(total_amount), 0) as total_revenue,
                    COALESCE(AVG(total_amount), 0) as avg_order_value
                FROM business_orders 
                WHERE delivery_date BETWEEN ? AND ? 
                AND is_cancelled = 0
            ");
            $stmt->execute([$from, $to]);
            $orderStats = $stmt->fetch();

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(boi.quantity), 0) as total_products
                FROM business_order_items boi
                JOIN business_orders bo ON boi.order_id = bo.id
                WHERE bo.delivery_date BETWEEN ? AND ?
                AND bo.is_cancelled = 0
            ");
            $stmt->execute([$from, $to]);
            $productStats = $stmt->fetch();

            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT ba.plaats) as unique_locations
                FROM business_orders bo
                JOIN business_accounts ba ON bo.account_id = ba.id
                WHERE bo.delivery_date BETWEEN ? AND ?
                AND bo.is_cancelled = 0
            ");
            $stmt->execute([$from, $to]);
            $locationStats = $stmt->fetch();

            echo json_encode([
                'success' => true,
                'total_orders' => (int)$orderStats['total_orders'],
                'total_revenue' => (float)$orderStats['total_revenue'],
                'avg_order_value' => (float)$orderStats['avg_order_value'],
                'total_products' => (int)$productStats['total_products'],
                'unique_locations' => (int)$locationStats['unique_locations']
            ]);
            break;

        case 'revenue':
            $granularity = $_GET['granularity'] ?? 'day';
            $data = getTimeSeriesData($pdo, $from, $to, $granularity, 'revenue');
            echo json_encode(['success' => true, 'labels' => $data['labels'], 'values' => $data['values']]);
            break;

        case 'products_over_time':
            $granularity = $_GET['granularity'] ?? 'day';
            $data = getTimeSeriesData($pdo, $from, $to, $granularity, 'products');
            echo json_encode(['success' => true, 'labels' => $data['labels'], 'values' => $data['values']]);
            break;

        case 'top_products':
            $limit = min(20, max(5, (int)($_GET['limit'] ?? 10)));
            
            $stmt = $pdo->prepare("
                SELECT 
                    boi.product_name,
                    SUM(boi.quantity) as quantity,
                    SUM(boi.quantity * boi.unit_price) as revenue
                FROM business_order_items boi
                JOIN business_orders bo ON boi.order_id = bo.id
                WHERE bo.delivery_date BETWEEN ? AND ?
                AND bo.is_cancelled = 0
                GROUP BY boi.product_name
                ORDER BY quantity DESC
                LIMIT $limit
            ");
            $stmt->execute([$from, $to]);
            $products = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'products' => array_map(function($p) {
                    return [
                        'product_name' => $p['product_name'],
                        'quantity' => (int)$p['quantity'],
                        'revenue' => (float)$p['revenue']
                    ];
                }, $products)
            ]);
            break;

        case 'locations':
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(
                        CASE WHEN ba.delivery_same_as_business = 1 OR ba.delivery_plaats IS NULL OR ba.delivery_plaats = '' 
                        THEN ba.plaats 
                        ELSE ba.delivery_plaats END,
                        ba.plaats
                    ) as plaats,
                    COUNT(bo.id) as order_count,
                    SUM(bo.total_amount) as revenue
                FROM business_orders bo
                JOIN business_accounts ba ON bo.account_id = ba.id
                WHERE bo.delivery_date BETWEEN ? AND ?
                AND bo.is_cancelled = 0
                GROUP BY plaats
                ORDER BY revenue DESC
            ");
            $stmt->execute([$from, $to]);
            $locations = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'locations' => array_map(function($l) {
                    return [
                        'plaats' => $l['plaats'] ?: 'Onbekend',
                        'order_count' => (int)$l['order_count'],
                        'revenue' => (float)$l['revenue']
                    ];
                }, $locations)
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ongeldige actie']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database fout: ' . $e->getMessage()]);
}

function getTimeSeriesData($pdo, $from, $to, $granularity, $type) {
    $labels = [];
    $values = [];
    
    switch ($granularity) {
        case 'day':
            $groupBy = 'DATE(bo.delivery_date)';
            $dateFormat = '%Y-%m-%d';
            break;
        case 'week':
            $groupBy = 'YEARWEEK(bo.delivery_date, 1)';
            $dateFormat = '%x-W%v';
            break;
        case 'month':
            $groupBy = "DATE_FORMAT(bo.delivery_date, '%Y-%m')";
            $dateFormat = '%Y-%m';
            break;
        case 'year':
            $groupBy = 'YEAR(bo.delivery_date)';
            $dateFormat = '%Y';
            break;
        default:
            $groupBy = 'DATE(bo.delivery_date)';
            $dateFormat = '%Y-%m-%d';
    }

    if ($type === 'revenue') {
        $selectField = 'COALESCE(SUM(bo.total_amount), 0) as value';
        $query = "
            SELECT 
                DATE_FORMAT(bo.delivery_date, '$dateFormat') as period,
                $selectField
            FROM business_orders bo
            WHERE bo.delivery_date BETWEEN ? AND ?
            AND bo.is_cancelled = 0
            GROUP BY $groupBy
            ORDER BY bo.delivery_date ASC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$from, $to]);
    } else {
        $query = "
            SELECT 
                DATE_FORMAT(bo.delivery_date, '$dateFormat') as period,
                COALESCE(SUM(boi.quantity), 0) as value
            FROM business_orders bo
            LEFT JOIN business_order_items boi ON bo.id = boi.order_id
            WHERE bo.delivery_date BETWEEN ? AND ?
            AND bo.is_cancelled = 0
            GROUP BY $groupBy
            ORDER BY bo.delivery_date ASC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$from, $to]);
    }

    $results = $stmt->fetchAll();

    foreach ($results as $row) {
        $label = $row['period'];
        
        if ($granularity === 'day') {
            $label = date('d M', strtotime($row['period']));
        } elseif ($granularity === 'week') {
            $label = 'Week ' . substr($row['period'], -2);
        } elseif ($granularity === 'month') {
            $months = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
            $parts = explode('-', $row['period']);
            $label = $months[(int)$parts[1] - 1] . ' ' . $parts[0];
        }
        
        $labels[] = $label;
        $values[] = $type === 'revenue' ? (float)$row['value'] : (int)$row['value'];
    }

    return ['labels' => $labels, 'values' => $values];
}
