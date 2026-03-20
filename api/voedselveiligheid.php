<?php
require_once '../admin/config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$jsonBody = json_decode(file_get_contents('php://input'), true) ?? [];
$action   = $_GET['action'] ?? ($_POST['action'] ?? ($jsonBody['action'] ?? ''));

// ── Due-calculation helpers ──────────────────────────────────────────────────

// Is this item due, given its frequentie and when it was last completed?
function isDue($frequentie, $lastCompleted) {
    if ($lastCompleted === null) return true; // nooit gedaan → altijd due

    $today    = date('Y-m-d');
    $lastDate = substr($lastCompleted, 0, 10);
    $diffDays = (int)((strtotime($today) - strtotime($lastDate)) / 86400);

    switch ($frequentie) {
        case 'dagelijks':              return $diffDays >= 1;   // elke dag opnieuw
        case 'dagelijks_mits_gebruikt': return true;             // gebruiker beslist
        case 'wekelijks':              return $diffDays >= 7;
        case 'maandelijks':            return $diffDays >= 28;
    }
    return true;
}

// Next due date = last completion + interval
function nextDueDate($frequentie, $lastCompleted) {
    $base = $lastCompleted ? new DateTime(substr($lastCompleted, 0, 10)) : new DateTime();

    switch ($frequentie) {
        case 'dagelijks':
            if ($lastCompleted) $base->modify('+1 day');
            break;
        case 'dagelijks_mits_gebruikt':
            // Show today as due date
            $base = new DateTime();
            break;
        case 'wekelijks':
            if ($lastCompleted) $base->modify('+7 days');
            break;
        case 'maandelijks':
            if ($lastCompleted) $base->modify('+28 days');
            break;
    }
    return $base->format('Y-m-d');
}

// Fetch last-completion map for all master items: item_id → tijdstip_afgerond
function getLastCompletions($pdo) {
    $stmt = $pdo->query("
        SELECT item_id, MAX(tijdstip_afgerond) AS laatste_gedaan
        FROM schoonmaak_lijst_items
        WHERE afgevinkt = 1 AND item_id IS NOT NULL
        GROUP BY item_id
    ");
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['item_id']] = $row['laatste_gedaan'];
    }
    return $map;
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

        $stmt = $pdo->prepare("
            SELECT * FROM schoonmaak_lijst_items
            WHERE lijst_id = ?
            ORDER BY categorie_naam IS NULL, categorie_naam, type, naam
        ");
        $stmt->execute([$lijst['id']]);
        $lijstItems = $stmt->fetchAll();

        $daysDiff   = (int)((strtotime(date('Y-m-d')) - strtotime($datum)) / 86400);

        echo json_encode([
            'success'      => true,
            'exists'       => true,
            'lijst'        => $lijst,
            'items'        => $lijstItems,
            'is_late_edit' => $daysDiff > 0,
        ]);
        break;

    // ==================== CREATE LIST (explicit) ====================
    case 'create_list':
        $datum = $jsonBody['datum'] ?? '';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
            echo json_encode(['success' => false, 'error' => 'Ongeldige datum']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM schoonmaak_lijsten WHERE datum = ?");
        $stmt->execute([$datum]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Er bestaat al een formulier voor deze datum']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO schoonmaak_lijsten (datum, status) VALUES (?, 'onvolledig')");
        $stmt->execute([$datum]);
        $lijstId = $pdo->lastInsertId();

        // Active master items with their category name
        $stmt = $pdo->query("
            SELECT i.*, c.naam AS categorie_naam
            FROM schoonmaak_items i
            LEFT JOIN schoonmaak_categorieen c ON c.id = i.categorie_id
            WHERE i.actief = 1
            ORDER BY c.volgorde, c.naam, i.volgorde, i.naam
        ");
        $masterItems = $stmt->fetchAll();

        // Last completion per item (for due calculation)
        $lastCompletions = getLastCompletions($pdo);

        foreach ($masterItems as $item) {
            $lastDone = $lastCompletions[(int)$item['id']] ?? null;
            $due      = isDue($item['frequentie'], $lastDone) ? 1 : 0;
            $dueDate  = nextDueDate($item['frequentie'], $lastDone);

            $stmt = $pdo->prepare("
                INSERT INTO schoonmaak_lijst_items
                    (lijst_id, item_id, naam, categorie_naam, type, frequentie, due_date, is_due)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $lijstId, $item['id'], $item['naam'],
                $item['categorie_naam'], $item['type'],
                $item['frequentie'], $dueDate, $due,
            ]);
        }

        $stmt = $pdo->prepare("SELECT * FROM schoonmaak_lijsten WHERE id = ?");
        $stmt->execute([$lijstId]);
        $lijst = $stmt->fetch();

        $stmt = $pdo->prepare("
            SELECT * FROM schoonmaak_lijst_items
            WHERE lijst_id = ?
            ORDER BY categorie_naam IS NULL, categorie_naam, type, naam
        ");
        $stmt->execute([$lijstId]);
        $lijstItems = $stmt->fetchAll();

        $daysDiff = (int)((strtotime(date('Y-m-d')) - strtotime($datum)) / 86400);

        echo json_encode([
            'success'      => true,
            'lijst'        => $lijst,
            'items'        => $lijstItems,
            'is_late_edit' => $daysDiff > 0,
        ]);
        break;

    // ==================== REFRESH LIST (rebuild items from current master) ====================
    case 'refresh_list':
        $lijstId = $jsonBody['lijst_id'] ?? null;
        if (!$lijstId) { echo json_encode(['success' => false, 'error' => 'Geen lijst ID']); exit; }

        $stmt = $pdo->prepare("SELECT * FROM schoonmaak_lijsten WHERE id = ?");
        $stmt->execute([$lijstId]);
        $lijst = $stmt->fetch();
        if (!$lijst) { echo json_encode(['success' => false, 'error' => 'Formulier niet gevonden']); exit; }

        $stmt = $pdo->prepare("DELETE FROM schoonmaak_lijst_items WHERE lijst_id = ?");
        $stmt->execute([$lijstId]);

        $stmt = $pdo->query("
            SELECT i.*, c.naam AS categorie_naam
            FROM schoonmaak_items i
            LEFT JOIN schoonmaak_categorieen c ON c.id = i.categorie_id
            WHERE i.actief = 1
            ORDER BY c.volgorde, c.naam, i.volgorde, i.naam
        ");
        $masterItems     = $stmt->fetchAll();
        $lastCompletions = getLastCompletions($pdo);

        foreach ($masterItems as $item) {
            $lastDone = $lastCompletions[(int)$item['id']] ?? null;
            $due      = isDue($item['frequentie'], $lastDone) ? 1 : 0;
            $dueDate  = nextDueDate($item['frequentie'], $lastDone);

            $stmt = $pdo->prepare("
                INSERT INTO schoonmaak_lijst_items
                    (lijst_id, item_id, naam, categorie_naam, type, frequentie, due_date, is_due)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $lijstId, $item['id'], $item['naam'],
                $item['categorie_naam'], $item['type'],
                $item['frequentie'], $dueDate, $due,
            ]);
        }

        $stmt = $pdo->prepare("
            SELECT * FROM schoonmaak_lijst_items
            WHERE lijst_id = ?
            ORDER BY categorie_naam IS NULL, categorie_naam, type, naam
        ");
        $stmt->execute([$lijstId]);
        $lijstItems = $stmt->fetchAll();

        $daysDiff = (int)((strtotime(date('Y-m-d')) - strtotime($lijst['datum'])) / 86400);

        echo json_encode([
            'success'      => true,
            'lijst'        => $lijst,
            'items'        => $lijstItems,
            'is_late_edit' => $daysDiff > 0,
        ]);
        break;

    // ==================== DELETE LIST ====================
    case 'delete_list':
        $lijstId = $jsonBody['lijst_id'] ?? null;
        if (!$lijstId) { echo json_encode(['success' => false, 'error' => 'Geen lijst ID']); exit; }

        // CASCADE deletes schoonmaak_lijst_items automatically
        $stmt = $pdo->prepare("DELETE FROM schoonmaak_lijsten WHERE id = ?");
        $stmt->execute([$lijstId]);

        echo json_encode(['success' => true]);
        break;

    // ==================== GET MASTER ITEMS ====================
    case 'get_items':
        $stmt = $pdo->query("
            SELECT i.*, c.naam AS categorie_naam
            FROM schoonmaak_items i
            LEFT JOIN schoonmaak_categorieen c ON c.id = i.categorie_id
            ORDER BY i.actief DESC, c.volgorde, c.naam, i.volgorde, i.naam
        ");
        echo json_encode(['success' => true, 'items' => $stmt->fetchAll()]);
        break;

    // ==================== GET CATEGORIES ====================
    case 'get_categorieen':
        $stmt = $pdo->query("SELECT * FROM schoonmaak_categorieen ORDER BY volgorde, naam");
        echo json_encode(['success' => true, 'categorieen' => $stmt->fetchAll()]);
        break;

    // ==================== SAVE CATEGORY ====================
    case 'save_categorie':
        $data     = $jsonBody;
        $id       = $data['id']   ?? null;
        $naam     = trim($data['naam'] ?? '');
        $volgorde = (int)($data['volgorde'] ?? 0);

        if (empty($naam)) {
            echo json_encode(['success' => false, 'error' => 'Naam is verplicht']);
            exit;
        }

        if ($id) {
            $stmt = $pdo->prepare("UPDATE schoonmaak_categorieen SET naam = ?, volgorde = ? WHERE id = ?");
            $stmt->execute([$naam, $volgorde, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO schoonmaak_categorieen (naam, volgorde) VALUES (?, ?)");
            $stmt->execute([$naam, $volgorde]);
            $id = $pdo->lastInsertId();
        }

        echo json_encode(['success' => true, 'id' => $id]);
        break;

    // ==================== DELETE CATEGORY ====================
    case 'delete_categorie':
        $id = $jsonBody['id'] ?? null;
        if (!$id) { echo json_encode(['success' => false, 'error' => 'Geen ID']); exit; }

        // Unlink items first
        $stmt = $pdo->prepare("UPDATE schoonmaak_items SET categorie_id = NULL WHERE categorie_id = ?");
        $stmt->execute([$id]);

        $stmt = $pdo->prepare("DELETE FROM schoonmaak_categorieen WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true]);
        break;

    // ==================== GET OVERZICHT ====================
    case 'get_overzicht':
        $stmt = $pdo->query("
            SELECT l.*,
                   COUNT(li.id)      AS totaal_items,
                   SUM(li.afgevinkt) AS afgevinkt_items,
                   SUM(li.is_due)    AS due_items
            FROM schoonmaak_lijsten l
            LEFT JOIN schoonmaak_lijst_items li ON li.lijst_id = l.id
            GROUP BY l.id
            ORDER BY l.datum DESC
            LIMIT 365
        ");
        echo json_encode(['success' => true, 'lijsten' => $stmt->fetchAll()]);
        break;

    // ==================== SAVE LIST ====================
    case 'save_list':
        $data    = $jsonBody;
        $lijstId = $data['lijst_id'] ?? null;
        $items   = $data['items']    ?? [];
        $force   = !empty($data['force']);

        if (!$lijstId) { echo json_encode(['success' => false, 'error' => 'Geen lijst ID']); exit; }

        $stmt = $pdo->prepare("SELECT * FROM schoonmaak_lijsten WHERE id = ?");
        $stmt->execute([$lijstId]);
        $lijst = $stmt->fetch();
        if (!$lijst) { echo json_encode(['success' => false, 'error' => 'Formulier niet gevonden']); exit; }

        $daysDiff   = (int)((strtotime(date('Y-m-d')) - strtotime($lijst['datum'])) / 86400);
        $isLateEdit = $daysDiff > 0;

        // Warn if any due item is still unchecked
        $openItems = array_filter($items, fn($i) => !empty($i['is_due']) && empty($i['afgevinkt']));

        if (!empty($openItems) && !$force) {
            echo json_encode([
                'success'       => false,
                'warning'       => true,
                'overdue_items' => array_values($openItems),
                'message'       => 'Er zijn verplichte items die nog niet zijn afgevinkt.',
            ]);
            exit;
        }

        // Persist each item
        foreach ($items as $item) {
            $afgevinkt = !empty($item['afgevinkt']) ? 1 : 0;
            $tijdstip  = null;
            if ($afgevinkt) {
                $tijdstip = !empty($item['tijdstip_afgerond'])
                    ? $item['tijdstip_afgerond']
                    : date('Y-m-d H:i:s');
            }

            $stmt = $pdo->prepare("
                UPDATE schoonmaak_lijst_items
                SET afgevinkt = ?, notities = ?, uitvoerder = ?, tijdstip_afgerond = ?
                WHERE id = ? AND lijst_id = ?
            ");
            $stmt->execute([
                $afgevinkt,
                $item['notities']   ?? null,
                $item['uitvoerder'] ?? null,
                $tijdstip,
                $item['id'],
                $lijstId,
            ]);
        }

        // Recalculate status
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS totaal, SUM(afgevinkt) AS afgevinkt, SUM(is_due) AS due_totaal
            FROM schoonmaak_lijst_items WHERE lijst_id = ?
        ");
        $stmt->execute([$lijstId]);
        $counts      = $stmt->fetch();
        $isAfwijking = !empty($openItems) && $force;

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
            $stmt = $pdo->prepare("INSERT INTO schoonmaak_audit_log (lijst_id, actie, gebruiker, details) VALUES (?, 'late_wijziging', ?, ?)");
            $stmt->execute([$lijstId, $_SESSION['username'] ?? null, 'Formulier aangepast na vervaldatum (' . $lijst['datum'] . ')']);
        }
        if ($isAfwijking) {
            $stmt = $pdo->prepare("INSERT INTO schoonmaak_audit_log (lijst_id, actie, gebruiker, details) VALUES (?, 'afwijking_opgeslagen', ?, ?)");
            $stmt->execute([$lijstId, $_SESSION['username'] ?? null,
                'Opgeslagen met openstaande items: ' . implode(', ', array_column(array_values($openItems), 'naam'))]);
        }

        echo json_encode(['success' => true, 'status' => $status]);
        break;

    // ==================== SAVE MASTER ITEM ====================
    case 'save_item':
        $data        = $jsonBody;
        $id          = $data['id']          ?? null;
        $naam        = trim($data['naam']   ?? '');
        $type        = $data['type']        ?? 'schoonmaak';
        $frequentie  = $data['frequentie']  ?? 'dagelijks';
        $actief      = isset($data['actief'])      ? (int)$data['actief']      : 1;
        $categorieId = isset($data['categorie_id']) && $data['categorie_id'] !== ''
                       ? (int)$data['categorie_id'] : null;
        $isAllergeenKritisch = isset($data['is_allergeen_kritisch']) ? (int)$data['is_allergeen_kritisch'] : 0;

        if (empty($naam)) { echo json_encode(['success' => false, 'error' => 'Naam is verplicht']); exit; }
        if (!in_array($type, ['schoonmaak', 'voorraad'])) { echo json_encode(['success' => false, 'error' => 'Ongeldig type']); exit; }
        if (!in_array($frequentie, ['dagelijks', 'dagelijks_mits_gebruikt', 'wekelijks', 'maandelijks'])) {
            echo json_encode(['success' => false, 'error' => 'Ongeldige frequentie']); exit;
        }

        if ($id) {
            $stmt = $pdo->prepare("UPDATE schoonmaak_items SET naam = ?, categorie_id = ?, type = ?, frequentie = ?, actief = ?, is_allergeen_kritisch = ? WHERE id = ?");
            $stmt->execute([$naam, $categorieId, $type, $frequentie, $actief, $isAllergeenKritisch, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO schoonmaak_items (naam, categorie_id, type, frequentie, actief, is_allergeen_kritisch) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$naam, $categorieId, $type, $frequentie, $actief, $isAllergeenKritisch]);
            $id = $pdo->lastInsertId();
        }

        echo json_encode(['success' => true, 'id' => $id]);
        break;

    // ==================== TOGGLE ITEM ACTIVE ====================
    case 'toggle_item':
        $id     = $jsonBody['id']     ?? null;
        $actief = $jsonBody['actief'] ?? null;
        if (!$id) { echo json_encode(['success' => false, 'error' => 'Geen ID']); exit; }

        $stmt = $pdo->prepare("UPDATE schoonmaak_items SET actief = ? WHERE id = ?");
        $stmt->execute([(int)$actief, $id]);
        echo json_encode(['success' => true]);
        break;

    // ==================== ALLERGEN TRACE STATUS ====================
    case 'get_allergen_status':
        $stmt = $pdo->query("
            SELECT ats.*,
                   DATEDIFF(NOW(), ats.stock_depleted_at) as days_since_depleted
            FROM allergen_trace_status ats
            ORDER BY
                FIELD(ats.status, 'in_stock', 'depleted', 'cleared'),
                ats.allergeen_naam
        ");
        $statuses = $stmt->fetchAll();

        // Count of active allergen-critical cleaning items
        $critStmt = $pdo->query("
            SELECT COUNT(*) as cnt
            FROM schoonmaak_items
            WHERE is_allergeen_kritisch = 1 AND actief = 1
        ");
        $criticalCount = intval($critStmt->fetch()['cnt']);

        // For each depleted allergen, check cleaning completion
        foreach ($statuses as &$s) {
            $s['cleaning_complete'] = false;
            $s['cleaning_done'] = 0;
            $s['cleaning_total'] = $criticalCount;

            if ($s['status'] === 'depleted' && $s['stock_depleted_at'] && $criticalCount > 0) {
                $cleanStmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT si.id) as done
                    FROM schoonmaak_items si
                    JOIN schoonmaak_lijst_items sli ON sli.item_id = si.id
                    WHERE si.is_allergeen_kritisch = 1
                      AND si.actief = 1
                      AND sli.afgevinkt = 1
                      AND sli.tijdstip_afgerond > ?
                ");
                $cleanStmt->execute([$s['stock_depleted_at']]);
                $s['cleaning_done'] = intval($cleanStmt->fetch()['done']);
                $s['cleaning_complete'] = ($s['cleaning_done'] >= $criticalCount);
            }
        }
        unset($s);

        echo json_encode([
            'success' => true,
            'statuses' => $statuses,
            'critical_cleaning_count' => $criticalCount
        ]);
        break;

    case 'clear_allergen':
        $allergeenNaam = trim($jsonBody['allergeen_naam'] ?? '');
        if (empty($allergeenNaam)) {
            echo json_encode(['success' => false, 'error' => 'Allergeen naam is verplicht']);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE allergen_trace_status
            SET status = 'cleared',
                manually_cleared_at = NOW(),
                cleared_by = ?
            WHERE allergeen_naam = ?
        ");
        $stmt->execute([
            $_SESSION['username'] ?? 'admin',
            $allergeenNaam
        ]);

        echo json_encode(['success' => true]);
        break;

    case 'reset_allergen':
        $allergeenNaam = trim($jsonBody['allergeen_naam'] ?? '');
        if (empty($allergeenNaam)) {
            echo json_encode(['success' => false, 'error' => 'Allergeen naam is verplicht']);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE allergen_trace_status
            SET status = 'depleted',
                manually_cleared_at = NULL,
                cleared_by = NULL
            WHERE allergeen_naam = ? AND status = 'cleared'
        ");
        $stmt->execute([$allergeenNaam]);

        echo json_encode(['success' => true]);
        break;

    case 'delete_allergen':
        $allergeenNaam = trim($jsonBody['allergeen_naam'] ?? '');
        if (empty($allergeenNaam)) {
            echo json_encode(['success' => false, 'error' => 'Allergeen naam is verplicht']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM allergen_trace_status WHERE allergeen_naam = ?");
        $stmt->execute([$allergeenNaam]);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Onbekende actie']);
}
