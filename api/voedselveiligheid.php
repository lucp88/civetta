<?php
require_once '../admin/config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$jsonBody = json_decode(file_get_contents('php://input'), true) ?? [];
$action   = $_GET['action'] ?? ($_POST['action'] ?? ($jsonBody['action'] ?? ''));

function calculateDueDate($frequentie, $datum) {
    $date = new DateTime($datum);
    switch ($frequentie) {
        case 'dagelijks':
        case 'dagelijks_mits_gebruikt':
            return $datum;
        case 'wekelijks':
            $dayOfWeek = (int)$date->format('N'); // 1=Mon, 7=Sun
            $daysUntilSunday = 7 - $dayOfWeek;
            if ($daysUntilSunday > 0) {
                $date->modify("+{$daysUntilSunday} days");
            }
            return $date->format('Y-m-d');
        case 'maandelijks':
            return $date->format('Y-m-t'); // t = last day of month
    }
    return $datum;
}

switch ($action) {

    // ==================== GET LIST (check only, no auto-create) ====================
    case 'get_list':
        $datum = $_GET['datum'] ?? date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
            echo json_encode(['success' => false, 'error' => 'Ongeldige datum']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM schoonmaak_lijsten WHERE datum = ?");
        $stmt->execute([$datum]);
        $lijst = $stmt->fetch();

        if (!$lijst) {
            echo json_encode(['success' => true, 'exists' => false, 'datum' => $datum]);
            break;
        }

        $stmt = $pdo->prepare("SELECT * FROM schoonmaak_lijst_items WHERE lijst_id = ? ORDER BY type, id");
        $stmt->execute([$lijst['id']]);
        $lijstItems = $stmt->fetchAll();

        $listDateTs = strtotime($datum);
        $todayTs    = strtotime(date('Y-m-d'));
        $daysDiff   = (int)(($todayTs - $listDateTs) / 86400);
        $isLateEdit = $daysDiff > 0;

        echo json_encode([
            'success'      => true,
            'exists'       => true,
            'lijst'        => $lijst,
            'items'        => $lijstItems,
            'is_late_edit' => $isLateEdit,
        ]);
        break;

    // ==================== CREATE LIST (explicit) ====================
    case 'create_list':
        $datum = $jsonBody['datum'] ?? '';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
            echo json_encode(['success' => false, 'error' => 'Ongeldige datum']);
            exit;
        }

        // Check if already exists
        $stmt = $pdo->prepare("SELECT id FROM schoonmaak_lijsten WHERE datum = ?");
        $stmt->execute([$datum]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Er bestaat al een lijst voor deze datum']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO schoonmaak_lijsten (datum, status) VALUES (?, 'onvolledig')");
        $stmt->execute([$datum]);
        $lijstId = $pdo->lastInsertId();

        // Populate with all active master items
        $stmt = $pdo->query("SELECT * FROM schoonmaak_items WHERE actief = 1 ORDER BY volgorde, id");
        $masterItems = $stmt->fetchAll();

        foreach ($masterItems as $item) {
            $dueDate = calculateDueDate($item['frequentie'], $datum);
            $stmt = $pdo->prepare("
                INSERT INTO schoonmaak_lijst_items (lijst_id, item_id, naam, type, frequentie, due_date)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$lijstId, $item['id'], $item['naam'], $item['type'], $item['frequentie'], $dueDate]);
        }

        $stmt = $pdo->prepare("SELECT * FROM schoonmaak_lijsten WHERE id = ?");
        $stmt->execute([$lijstId]);
        $lijst = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT * FROM schoonmaak_lijst_items WHERE lijst_id = ? ORDER BY type, id");
        $stmt->execute([$lijstId]);
        $lijstItems = $stmt->fetchAll();

        $listDateTs = strtotime($datum);
        $todayTs    = strtotime(date('Y-m-d'));
        $daysDiff   = (int)(($todayTs - $listDateTs) / 86400);
        $isLateEdit = $daysDiff > 0;

        echo json_encode([
            'success'      => true,
            'lijst'        => $lijst,
            'items'        => $lijstItems,
            'is_late_edit' => $isLateEdit,
        ]);
        break;

    // ==================== GET MASTER ITEMS ====================
    case 'get_items':
        $stmt = $pdo->query("SELECT * FROM schoonmaak_items ORDER BY actief DESC, volgorde, naam");
        $items = $stmt->fetchAll();
        echo json_encode(['success' => true, 'items' => $items]);
        break;

    // ==================== GET OVERZICHT ====================
    case 'get_overzicht':
        $stmt = $pdo->query("
            SELECT l.*,
                   COUNT(li.id)      AS totaal_items,
                   SUM(li.afgevinkt) AS afgevinkt_items
            FROM schoonmaak_lijsten l
            LEFT JOIN schoonmaak_lijst_items li ON li.lijst_id = l.id
            GROUP BY l.id
            ORDER BY l.datum DESC
            LIMIT 365
        ");
        $lijsten = $stmt->fetchAll();
        echo json_encode(['success' => true, 'lijsten' => $lijsten]);
        break;

    // ==================== SAVE LIST ====================
    case 'save_list':
        $data    = $jsonBody;
        $lijstId = $data['lijst_id'] ?? null;
        $items   = $data['items']    ?? [];
        $force   = !empty($data['force']);

        if (!$lijstId) {
            echo json_encode(['success' => false, 'error' => 'Geen lijst ID']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM schoonmaak_lijsten WHERE id = ?");
        $stmt->execute([$lijstId]);
        $lijst = $stmt->fetch();

        if (!$lijst) {
            echo json_encode(['success' => false, 'error' => 'Lijst niet gevonden']);
            exit;
        }

        $listDateTs = strtotime($lijst['datum']);
        $todayTs    = strtotime(date('Y-m-d'));
        $daysDiff   = (int)(($todayTs - $listDateTs) / 86400);
        $isLateEdit = $daysDiff > 0;

        // Check for overdue unchecked items
        $overdueItems = [];
        foreach ($items as $item) {
            if (empty($item['afgevinkt']) && !empty($item['due_date']) && $item['due_date'] <= $lijst['datum']) {
                $overdueItems[] = $item;
            }
        }

        if (!empty($overdueItems) && !$force) {
            echo json_encode([
                'success'       => false,
                'warning'       => true,
                'overdue_items' => $overdueItems,
                'message'       => 'Er zijn verplichte items die vandaag of eerder uitgevoerd hadden moeten worden en nog niet zijn afgevinkt.',
            ]);
            exit;
        }

        // Save each item
        foreach ($items as $item) {
            $afgevinkt = !empty($item['afgevinkt']) ? 1 : 0;

            // Auto-set tijdstip when checking off
            $tijdstip = null;
            if ($afgevinkt) {
                $tijdstip = !empty($item['tijdstip_afgerond']) ? $item['tijdstip_afgerond'] : date('Y-m-d H:i:s');
            }

            $stmt = $pdo->prepare("
                UPDATE schoonmaak_lijst_items
                SET afgevinkt = ?, notities = ?, uitvoerder = ?, tijdstip_afgerond = ?
                WHERE id = ? AND lijst_id = ?
            ");
            $stmt->execute([
                $afgevinkt,
                $item['notities']         ?? null,
                $item['uitvoerder']       ?? null,
                $tijdstip,
                $item['id'],
                $lijstId,
            ]);
        }

        // Recalculate list status
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS totaal, SUM(afgevinkt) AS afgevinkt
            FROM schoonmaak_lijst_items WHERE lijst_id = ?
        ");
        $stmt->execute([$lijstId]);
        $counts = $stmt->fetch();

        $isAfwijking = !empty($overdueItems) && $force;

        if ($isAfwijking) {
            $status = 'afwijking';
        } elseif ((int)$counts['afgevinkt'] >= (int)$counts['totaal'] && (int)$counts['totaal'] > 0) {
            $status = 'volledig';
        } else {
            $status = 'onvolledig';
        }

        $stmt = $pdo->prepare("UPDATE schoonmaak_lijsten SET status = ?, heeft_afwijking = ? WHERE id = ?");
        $stmt->execute([$status, $isAfwijking ? 1 : 0, $lijstId]);

        // Audit log
        if ($isLateEdit) {
            $stmt = $pdo->prepare("
                INSERT INTO schoonmaak_audit_log (lijst_id, actie, gebruiker, details)
                VALUES (?, 'late_wijziging', ?, ?)
            ");
            $stmt->execute([$lijstId, $_SESSION['username'] ?? null, 'Lijst aangepast na vervaldatum (' . $lijst['datum'] . ')']);
        }
        if ($isAfwijking) {
            $stmt = $pdo->prepare("
                INSERT INTO schoonmaak_audit_log (lijst_id, actie, gebruiker, details)
                VALUES (?, 'afwijking_opgeslagen', ?, ?)
            ");
            $overdueNames = implode(', ', array_column($overdueItems, 'naam'));
            $stmt->execute([$lijstId, $_SESSION['username'] ?? null, 'Opgeslagen met openstaande items: ' . $overdueNames]);
        }

        echo json_encode(['success' => true, 'status' => $status]);
        break;

    // ==================== SAVE MASTER ITEM ====================
    case 'save_item':
        $data      = $jsonBody;
        $id        = $data['id']        ?? null;
        $naam      = trim($data['naam'] ?? '');
        $type      = $data['type']      ?? 'schoonmaak';
        $frequentie = $data['frequentie'] ?? 'dagelijks';
        $actief    = isset($data['actief']) ? (int)$data['actief'] : 1;

        if (empty($naam)) {
            echo json_encode(['success' => false, 'error' => 'Naam is verplicht']);
            exit;
        }
        if (!in_array($type, ['schoonmaak', 'voorraad'])) {
            echo json_encode(['success' => false, 'error' => 'Ongeldig type']);
            exit;
        }
        if (!in_array($frequentie, ['dagelijks', 'dagelijks_mits_gebruikt', 'wekelijks', 'maandelijks'])) {
            echo json_encode(['success' => false, 'error' => 'Ongeldige frequentie']);
            exit;
        }

        if ($id) {
            $stmt = $pdo->prepare("UPDATE schoonmaak_items SET naam = ?, type = ?, frequentie = ?, actief = ? WHERE id = ?");
            $stmt->execute([$naam, $type, $frequentie, $actief, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO schoonmaak_items (naam, type, frequentie, actief) VALUES (?, ?, ?, ?)");
            $stmt->execute([$naam, $type, $frequentie, $actief]);
            $id = $pdo->lastInsertId();
        }

        echo json_encode(['success' => true, 'id' => $id]);
        break;

    // ==================== DEACTIVATE MASTER ITEM ====================
    case 'delete_item':
        $data = $jsonBody;
        $id   = $data['id'] ?? null;

        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'Geen ID']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE schoonmaak_items SET actief = 0 WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Onbekende actie']);
}
