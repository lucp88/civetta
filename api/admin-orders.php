<?php
require_once __DIR__ . '/../admin/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Niet ingelogd']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? '';

        if ($action === 'customers') {
            $stmt = $pdo->query("SELECT id, bedrijfsnaam, contactpersoon, email, telefoon, adres, postcode, plaats, delivery_same_as_business, delivery_adres, delivery_postcode, delivery_plaats FROM business_accounts WHERE status = 'approved' ORDER BY bedrijfsnaam ASC");
            echo json_encode(['success' => true, 'customers' => $stmt->fetchAll()]);
            exit;
        }

        if ($action === 'products') {
            $stmt = $pdo->query("SELECT id, naam, prijs FROM products ORDER BY naam ASC");
            echo json_encode(['success' => true, 'products' => $stmt->fetchAll()]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Onbekende actie']);
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);

        $accountId = intval($data['account_id'] ?? 0);
        $deliveryDate = $data['delivery_date'] ?? '';
        $items = $data['items'] ?? [];
        $notes = trim($data['notes'] ?? '');

        if (!$accountId || !$deliveryDate || empty($items)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Klant, leverdatum en minimaal één product zijn verplicht']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, bedrijfsnaam FROM business_accounts WHERE id = ? AND status = 'approved'");
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        if (!$account) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Klant niet gevonden of niet goedgekeurd']);
            exit;
        }

        $totalAmount = 0;
        foreach ($items as $item) {
            $totalAmount += floatval($item['quantity']) * floatval($item['unit_price']);
        }

        require_once __DIR__ . '/../lib/bestelbon/functions.php';

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO business_orders (account_id, delivery_date, payment_status, total_amount, notes, payment_type, is_recurring, invoice_status, delivery_status, created_at)
                VALUES (?, ?, 'pending', ?, ?, 'factuur', 0, 'bestelbon', 'geplaatst', NOW())
            ");
            $stmt->execute([$accountId, $deliveryDate, $totalAmount, $notes]);
            $orderId = $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO business_order_items (order_id, product_name, quantity, unit_price)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($items as $item) {
                $stmt->execute([
                    $orderId,
                    $item['product_name'],
                    intval($item['quantity']),
                    floatval($item['unit_price'])
                ]);
            }

            $bestelbonNumber = generateBestelbonNumber($pdo, $orderId);
            $stmtUpdate = $pdo->prepare("
                UPDATE business_orders 
                SET bestelbon_number = ?,
                    order_status = 'geplaatst',
                    invoice_status = 'bestelbon',
                    delivery_status = 'geplaatst'
                WHERE id = ?
            ");
            $stmtUpdate->execute([$bestelbonNumber, $orderId]);

            $pdo->commit();

            sendBestelbonEmail($pdo, $orderId);

            $stmt = $pdo->prepare("SELECT bedrijfsnaam, contactpersoon, email FROM business_accounts WHERE id = ?");
            $stmt->execute([$accountId]);
            $accountInfo = $stmt->fetch();

            $itemsList = "";
            foreach ($items as $item) {
                $itemsList .= "- {$item['quantity']}x {$item['product_name']} (€" . number_format($item['unit_price'], 2, ',', '.') . " p/st)\n";
            }

            $to = "laurens@bakkerij-civetta.nl";
            $subject = "Nieuwe bestelling (admin) van {$accountInfo['bedrijfsnaam']} (#$orderId)";
            $body = "Er is een nieuwe bestelling geplaatst via het admin panel!\n\n";
            $body .= "Bestelling #$orderId\n";
            $body .= "Bedrijf: {$accountInfo['bedrijfsnaam']}\n";
            $body .= "Contactpersoon: {$accountInfo['contactpersoon']}\n";
            $body .= "E-mail: {$accountInfo['email']}\n\n";
            $body .= "Gewenste leverdatum: " . date('d-m-Y', strtotime($deliveryDate)) . "\n\n";
            $body .= "Producten:\n$itemsList\n";
            $body .= "Totaalbedrag: €" . number_format($totalAmount, 2, ',', '.') . "\n\n";
            if ($notes) {
                $body .= "Opmerkingen: $notes\n\n";
            }
            $body .= "Geplaatst door: Admin\n";

            $headers = "From: noreply@bakkerij-civetta.nl\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            @mail($to, $subject, $body, $headers);

            echo json_encode([
                'success' => true,
                'order_id' => $orderId,
                'bestelbon_number' => $bestelbonNumber,
                'message' => "Bestelling #$orderId geplaatst voor {$accountInfo['bedrijfsnaam']}"
            ]);

        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout bij plaatsen bestelling']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
