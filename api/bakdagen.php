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

    // Get recurring pattern
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bakdagen_patroon'");
    $stmt->execute();
    $patroonStr = $stmt->fetchColumn() ?: '';
    $patroon = $patroonStr ? array_map('intval', explode(',', $patroonStr)) : [];

    // Get preparation days
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bakdagen_voorbereiding_dagen'");
    $stmt->execute();
    $voorbereidingDagen = (int)($stmt->fetchColumn() ?: 3);

    // Get extra days and sluitingen in range
    $stmt = $pdo->prepare("SELECT id, datum, notitie, COALESCE(type,'extra') as type FROM bakdagen_extra WHERE datum BETWEEN ? AND ? ORDER BY datum");
    $stmt->execute([$start, $end]);
    $allExtra = $stmt->fetchAll();
    $extraDagen    = array_filter($allExtra, fn($r) => $r['type'] === 'extra');
    $sluitingDagen = array_filter($allExtra, fn($r) => $r['type'] === 'sluiting');
    $extraDatums    = array_column(array_values($extraDagen), 'datum');
    $sluitingDatums = array_column(array_values($sluitingDagen), 'datum');

    // Compute all baking days in range (pattern + extras, minus sluitingen)
    $bakdagen = [];
    $current = new DateTime($start);
    $endDt = new DateTime($end);
    while ($current <= $endDt) {
        $weekday = (int)$current->format('N');
        $dateStr = $current->format('Y-m-d');
        if (!in_array($dateStr, $sluitingDatums) && (in_array($weekday, $patroon) || in_array($dateStr, $extraDatums))) {
            $bakdagen[] = $dateStr;
        }
        $current->modify('+1 day');
    }

    // Get bakery address for pickup location
    $stmtAddr = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('bedrijf_naam', 'bedrijf_adres', 'bedrijf_postcode', 'bedrijf_plaats')");
    $addrSettings = $stmtAddr->fetchAll(PDO::FETCH_KEY_PAIR);

    echo json_encode([
        'success' => true,
        'patroon' => $patroon,
        'voorbereiding_dagen' => $voorbereidingDagen,
        'extra_dagen' => array_values($extraDagen),
        'sluiting_dagen' => array_values($sluitingDagen),
        'bakdagen' => $bakdagen,
        'bakkerij' => [
            'naam' => $addrSettings['bedrijf_naam'] ?? '',
            'adres' => $addrSettings['bedrijf_adres'] ?? '',
            'postcode' => $addrSettings['bedrijf_postcode'] ?? '',
            'plaats' => $addrSettings['bedrijf_plaats'] ?? ''
        ]
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if ($action === 'save_patroon') {
        $dagen = $input['dagen'] ?? [];
        $value = implode(',', array_map('intval', $dagen));
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('bakdagen_patroon', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$value, $value]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'add_extra') {
        $datum = $input['datum'] ?? '';
        $notitie = $input['notitie'] ?? null;
        if (!$datum) {
            echo json_encode(['success' => false, 'error' => 'Datum is verplicht']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO bakdagen_extra (datum, notitie) VALUES (?, ?)");
            $stmt->execute([$datum, $notitie]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                echo json_encode(['success' => false, 'error' => 'Deze datum is al een bakdag']);
            } else {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        }
        exit;
    }

    if ($action === 'remove_extra') {
        $datum = $input['datum'] ?? '';
        if (!$datum) {
            echo json_encode(['success' => false, 'error' => 'Datum is verplicht']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM bakdagen_extra WHERE datum = ? AND COALESCE(type,'extra') = 'extra'");
        $stmt->execute([$datum]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'add_sluiting') {
        $datum = $input['datum'] ?? '';
        $notitie = $input['notitie'] ?? null;
        if (!$datum) {
            echo json_encode(['success' => false, 'error' => 'Datum is verplicht']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO bakdagen_extra (datum, notitie, type) VALUES (?, ?, 'sluiting') ON DUPLICATE KEY UPDATE notitie = VALUES(notitie), type = 'sluiting'");
            $stmt->execute([$datum, $notitie]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'remove_sluiting') {
        $datum = $input['datum'] ?? '';
        if (!$datum) {
            echo json_encode(['success' => false, 'error' => 'Datum is verplicht']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM bakdagen_extra WHERE datum = ? AND type = 'sluiting'");
        $stmt->execute([$datum]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Onbekende actie']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
