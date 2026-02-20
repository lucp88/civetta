<?php
require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../lib/web-push.php';
require_once __DIR__ . '/email-templates.php';

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
            $customers = $stmt->fetchAll();
            foreach ($customers as &$c) {
                $c['is_internal'] = ($c['bedrijfsnaam'] === 'Civetta (Intern)') ? 1 : 0;
            }
            unset($c);
            echo json_encode(['success' => true, 'customers' => $customers]);
            exit;
        }

        if ($action === 'products') {
            $stmt = $pdo->query("SELECT id, naam, prijs FROM products ORDER BY naam ASC");
            $products = $stmt->fetchAll();

            $variantStmt = $pdo->query("SELECT id, product_id, naam, gewicht, prijs FROM product_variants ORDER BY gewicht ASC");
            $allVariants = $variantStmt->fetchAll();
            $variantsByProduct = [];
            foreach ($allVariants as $v) {
                $variantsByProduct[$v['product_id']][] = [
                    'id' => (int)$v['id'],
                    'naam' => $v['naam'],
                    'gewicht' => (int)$v['gewicht'],
                    'prijs' => (float)$v['prijs']
                ];
            }
            foreach ($products as &$p) {
                $p['variants'] = $variantsByProduct[$p['id']] ?? [];
            }
            unset($p);

            echo json_encode(['success' => true, 'products' => $products]);
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
        $isInternal = !empty($data['is_internal']);

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
                INSERT INTO business_orders (account_id, delivery_date, payment_status, total_amount, notes, payment_type, is_recurring, invoice_status, delivery_status, is_internal, created_at)
                VALUES (?, ?, 'pending', ?, ?, 'factuur', 0, 'bestelbon', 'geplaatst', ?, NOW())
            ");
            $stmt->execute([$accountId, $deliveryDate, $totalAmount, $notes, $isInternal ? 1 : 0]);
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

            $stmt = $pdo->prepare("SELECT bedrijfsnaam, contactpersoon, email FROM business_accounts WHERE id = ?");
            $stmt->execute([$accountId]);
            $accountInfo = $stmt->fetch();

            if (!$isInternal) {
                sendBestelbonEmail($pdo, $orderId);

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

                try {
                    $pushTitle = 'Nieuwe bestelling (admin)';
                    $pushBody = $accountInfo['bedrijfsnaam'] . ' - €' . number_format($totalAmount, 2, ',', '.') . ' (' . date('d-m-Y', strtotime($deliveryDate)) . ')';
                    sendPushNotification($pdo, $pushTitle, $pushBody);
                } catch (\Throwable $e) {
                    error_log('Push notification fout: ' . $e->getMessage());
                }
            }

            $message = $isInternal
                ? "Interne bestelling #$orderId geplaatst voor " . date('d-m-Y', strtotime($deliveryDate))
                : "Bestelling #$orderId geplaatst voor {$accountInfo['bedrijfsnaam']}";

            echo json_encode([
                'success' => true,
                'order_id' => $orderId,
                'bestelbon_number' => $bestelbonNumber,
                'message' => $message
            ]);

        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout bij plaatsen bestelling']);
        }
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        $orderId = intval($data['order_id'] ?? 0);
        $items = $data['items'] ?? [];
        $notes = $data['notes'] ?? null;

        if (!$orderId || empty($items)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Order ID en minimaal één product zijn verplicht']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT bo.*, ba.bedrijfsnaam, ba.contactpersoon, ba.email, ba.telefoon, ba.adres, ba.postcode, ba.plaats
            FROM business_orders bo
            JOIN business_accounts ba ON bo.account_id = ba.id
            WHERE bo.id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Bestelling niet gevonden']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $oldItems = $stmt->fetchAll();

        try {
            $pdo->beginTransaction();

            $totalAmount = 0;
            foreach ($items as $item) {
                $totalAmount += floatval($item['quantity']) * floatval($item['unit_price']);
            }

            $stmt = $pdo->prepare("DELETE FROM business_order_items WHERE order_id = ?");
            $stmt->execute([$orderId]);

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

            $updateFields = ['total_amount = ?'];
            $updateValues = [$totalAmount];

            if ($notes !== null) {
                $updateFields[] = 'notes = ?';
                $updateValues[] = trim($notes);
            }

            $updateValues[] = $orderId;
            $stmt = $pdo->prepare("UPDATE business_orders SET " . implode(', ', $updateFields) . " WHERE id = ?");
            $stmt->execute($updateValues);

            $pdo->commit();

            $order['total_amount'] = $totalAmount;
            if ($notes !== null) $order['notes'] = trim($notes);

            if (empty($order['is_internal'])) {
                $stmtBtw = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'btw_tarief'");
                $btwTarief = floatval($stmtBtw->fetchColumn() ?: 9);

                $stmtBedrijf = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'bedrijf_%'");
                $bedrijf = $stmtBedrijf->fetchAll(PDO::FETCH_KEY_PAIR);

                $emailHtml = buildAdminOrderEditEmail($order, $oldItems, $items, $bedrijf, $btwTarief);
                sendHtmlEmail(
                    $order['email'],
                    'Uw bestelling #' . $orderId . ' is aangepast door Bakkerij Civetta',
                    $emailHtml
                );

                echo json_encode([
                    'success' => true,
                    'message' => "Bestelling #$orderId bijgewerkt. De klant is per e-mail geïnformeerd."
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'message' => "Interne bestelling #$orderId bijgewerkt."
                ]);
            }

        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout bij bijwerken bestelling']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
