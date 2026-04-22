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
            // Get global fallback for recipe days
            $stmtFallback = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'bakdagen_voorbereiding_dagen'");
            $defaultRecipeDays = intval($stmtFallback->fetchColumn() ?: 3);

            $stmt = $pdo->query("SELECT id, naam, prijs FROM products ORDER BY naam ASC");
            $products = $stmt->fetchAll();

            $variantStmt = $pdo->query("
                SELECT pv.id, pv.product_id, pv.naam, pv.gewicht, pv.prijs, pv.recipe_id,
                       br.recipe_data
                FROM product_variants pv
                LEFT JOIN baker_recipes br ON pv.recipe_id = br.id
                ORDER BY pv.gewicht ASC
            ");
            $allVariants = $variantStmt->fetchAll();
            $variantsByProduct = [];
            foreach ($allVariants as $v) {
                $recipeDays = $defaultRecipeDays;
                if (!empty($v['recipe_data'])) {
                    $recipeData = json_decode($v['recipe_data'], true);
                    if (!empty($recipeData['methodDays']) && is_array($recipeData['methodDays'])) {
                        $recipeDays = count($recipeData['methodDays']);
                    }
                }
                $variantsByProduct[$v['product_id']][] = [
                    'id' => (int)$v['id'],
                    'naam' => $v['naam'],
                    'gewicht' => (int)$v['gewicht'],
                    'prijs' => (float)$v['prijs'],
                    'recipe_days' => $recipeDays
                ];
            }
            foreach ($products as &$p) {
                $p['variants'] = $variantsByProduct[$p['id']] ?? [];
                // Product-level recipe_days = minimum of its variants, or fallback
                if (!empty($p['variants'])) {
                    $p['recipe_days'] = min(array_column($p['variants'], 'recipe_days'));
                } else {
                    $p['recipe_days'] = $defaultRecipeDays;
                }
            }
            unset($p);

            echo json_encode(['success' => true, 'products' => $products, 'default_recipe_days' => $defaultRecipeDays]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Onbekende actie']);
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? 'create';

        // Settle internal order
        if ($action === 'settle_internal') {
            $orderId = intval($data['order_id'] ?? 0);
            $soldItems = $data['items'] ?? [];

            if (!$orderId || empty($soldItems)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Order ID en items zijn verplicht']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT id, is_internal, settled_at, total_amount FROM business_orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Bestelling niet gevonden']);
                exit;
            }
            if (empty($order['is_internal'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Alleen interne bestellingen kunnen afgehandeld worden']);
                exit;
            }

            try {
                $pdo->beginTransaction();

                $stmtUpdate = $pdo->prepare("UPDATE business_order_items SET quantity_sold = ? WHERE id = ? AND order_id = ?");
                $settledAmount = 0;
                $totalQuantity = 0;
                $totalSold = 0;
                $remainderItems = [];

                foreach ($soldItems as $item) {
                    $itemId = intval($item['item_id']);
                    $qtySold = intval($item['quantity_sold']);
                    $stmtUpdate->execute([$qtySold, $itemId, $orderId]);

                    // Fetch item details for summary
                    $stmtItem = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE id = ?");
                    $stmtItem->execute([$itemId]);
                    $itemData = $stmtItem->fetch();
                    if ($itemData) {
                        $settledAmount += $qtySold * floatval($itemData['unit_price']);
                        $totalQuantity += intval($itemData['quantity']);
                        $totalSold += $qtySold;
                        $remainder = intval($itemData['quantity']) - $qtySold;
                        if ($remainder > 0) {
                            $remainderItems[] = [
                                'product_name' => $itemData['product_name'],
                                'quantity' => $remainder,
                                'unit_price' => floatval($itemData['unit_price'])
                            ];
                        }
                    }
                }

                $stmt = $pdo->prepare("UPDATE business_orders SET settled_amount = ?, settled_at = NOW() WHERE id = ?");
                $stmt->execute([$settledAmount, $orderId]);

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'message' => "Bestelling #$orderId afgehandeld. $totalSold/$totalQuantity verkocht.",
                    'settled_amount' => $settledAmount,
                    'total_sold' => $totalSold,
                    'total_quantity' => $totalQuantity,
                    'remainder_items' => $remainderItems
                ]);

            } catch (PDOException $e) {
                $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Database fout bij afhandelen bestelling']);
            }
            break;
        }

        // Create invoice for settled internal order
        if ($action === 'create_invoice_internal') {
            $orderId = intval($data['order_id'] ?? 0);

            if (!$orderId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Order ID is verplicht']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT bo.*, ba.bedrijfsnaam, ba.contactpersoon, ba.email, ba.telefoon,
                       ba.adres, ba.postcode, ba.plaats, ba.kvk_nummer, ba.btw_id
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
            if (empty($order['is_internal'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Alleen interne bestellingen']);
                exit;
            }
            if (empty($order['settled_at'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Bestelling moet eerst afgehandeld worden']);
                exit;
            }
            if (!empty($order['eboekhouden_invoice_id']) || !empty($order['invoice_number'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Er is al een factuur aangemaakt voor deze bestelling']);
                exit;
            }

            // Fetch items with quantity_sold for invoice
            $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price, quantity_sold FROM business_order_items WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $rawItems = $stmt->fetchAll();

            $invoiceItems = [];
            foreach ($rawItems as $item) {
                $qty = ($item['quantity_sold'] !== null) ? intval($item['quantity_sold']) : intval($item['quantity']);
                if ($qty > 0) {
                    $invoiceItems[] = [
                        'product_name' => $item['product_name'],
                        'quantity' => $qty,
                        'unit_price' => floatval($item['unit_price'])
                    ];
                }
            }

            if (empty($invoiceItems)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Geen verkochte items om te factureren']);
                exit;
            }

            // Get facturatie settings
            $settingKeys = ['facturatie_systeem', 'eboekhouden_api_token', 'eboekhouden_template_id_openstaand', 'eboekhouden_ledger_id', 'eboekhouden_debiteuren_ledger_id', 'btw_tarief'];
            $settings = [];
            foreach ($settingKeys as $key) {
                $stmtS = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
                $stmtS->execute([$key]);
                $settings[$key] = $stmtS->fetchColumn() ?: '';
            }

            try {
                if ($settings['facturatie_systeem'] === 'eboekhouden' && !empty($settings['eboekhouden_api_token'])) {
                    // e-Boekhouden invoice
                    require_once __DIR__ . '/eboekhouden.php';

                    $client = new EBoekhoudenClient($settings['eboekhouden_api_token']);

                    $btwCode = 'LAAG_VERK_9';
                    if (floatval($settings['btw_tarief']) == 21) {
                        $btwCode = 'HOOG_VERK_21';
                    }

                    $accountData = [
                        'email' => $order['email'],
                        'bedrijfsnaam' => $order['bedrijfsnaam'],
                        'contactpersoon' => $order['contactpersoon'],
                        'adres' => $order['adres'],
                        'postcode' => $order['postcode'],
                        'plaats' => $order['plaats'],
                        'telefoon' => $order['telefoon'],
                        'kvk_nummer' => $order['kvk_nummer'],
                        'btw_id' => $order['btw_id'],
                        'delivery_date' => $order['delivery_date'],
                        'btw_tarief' => floatval($settings['btw_tarief'] ?? 9)
                    ];

                    $result = $client->createFullInvoice(
                        $accountData,
                        $invoiceItems,
                        $settings['eboekhouden_template_id_openstaand'],
                        $settings['eboekhouden_ledger_id'],
                        $btwCode,
                        true,
                        $settings['eboekhouden_debiteuren_ledger_id'] ?: null
                    );

                    $stmt = $pdo->prepare("
                        UPDATE business_orders
                        SET eboekhouden_invoice_id = ?,
                            eboekhouden_factuurnummer = ?,
                            eboekhouden_pdf_url = ?,
                            facturatie_systeem = 'eboekhouden',
                            invoiced_at = NOW(),
                            invoice_status = 'gefactureerd'
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $result['id'],
                        $result['invoiceNumber'],
                        $result['pdfUrl'],
                        $orderId
                    ]);

                    echo json_encode([
                        'success' => true,
                        'message' => "Factuur {$result['invoiceNumber']} aangemaakt via e-Boekhouden voor bestelling #$orderId",
                        'invoice_number' => $result['invoiceNumber']
                    ]);

                } else {
                    // Local invoice
                    $invoiceNumber = 'F' . date('Y') . '-' . str_pad($orderId, 4, '0', STR_PAD_LEFT);

                    $stmt = $pdo->prepare("
                        UPDATE business_orders
                        SET invoice_number = ?,
                            invoiced_at = NOW(),
                            facturatie_systeem = 'eigen',
                            invoice_status = 'gefactureerd'
                        WHERE id = ?
                    ");
                    $stmt->execute([$invoiceNumber, $orderId]);

                    require_once __DIR__ . '/../lib/factuur/functions.php';

                    $facturenDir = __DIR__ . '/../facturen';
                    if (!is_dir($facturenDir)) {
                        mkdir($facturenDir, 0755, true);
                    }

                    $factuurFile = $facturenDir . '/factuur-' . $orderId . '.pdf';
                    generateFactuur($pdo, $orderId, $factuurFile);
                    sendFactuurEmail($pdo, $orderId);

                    echo json_encode([
                        'success' => true,
                        'message' => "Factuur $invoiceNumber aangemaakt en verstuurd voor bestelling #$orderId",
                        'invoice_number' => $invoiceNumber
                    ]);
                }

            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Fout bij aanmaken factuur: ' . $e->getMessage()]);
            }
            break;
        }

        // Create new order (existing logic)
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

        // Bakeday check — skip for internal orders, require confirm for regular orders
        $confirmOverride = !empty($data['confirm_override']);
        if (!$confirmOverride && !$isInternal) {
            $stmtPatroon = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bakdagen_patroon'");
            $stmtPatroon->execute();
            $patroonStr = $stmtPatroon->fetchColumn() ?: '';
            $patroon = $patroonStr ? array_map('intval', explode(',', $patroonStr)) : [];

            $warnings = [];
            if (!empty($patroon)) {
                $deliveryWeekday = (int)(new DateTime($deliveryDate))->format('N');
                $isPatroonDay = in_array($deliveryWeekday, $patroon);

                $stmtExtra = $pdo->prepare("SELECT COUNT(*) FROM bakdagen_extra WHERE datum = ?");
                $stmtExtra->execute([$deliveryDate]);
                $isExtraDay = (int)$stmtExtra->fetchColumn() > 0;

                if (!$isPatroonDay && !$isExtraDay) {
                    $warnings[] = date('d-m-Y', strtotime($deliveryDate)) . ' is geen bakdag.';
                }

                // Check preparation days
                $stmtVoorbereiding = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bakdagen_voorbereiding_dagen'");
                $stmtVoorbereiding->execute();
                $voorbereidingDagen = (int)($stmtVoorbereiding->fetchColumn() ?: 3);

                $today = new DateTime();
                $today->setTime(0, 0, 0);
                $todayStr = $today->format('Y-m-d');
                $deliveryDt = new DateTime($deliveryDate);

                $stmtExtraRange = $pdo->prepare("SELECT datum FROM bakdagen_extra WHERE datum BETWEEN ? AND ?");
                $stmtExtraRange->execute([$todayStr, $deliveryDate]);
                $extraDatums = array_column($stmtExtraRange->fetchAll(), 'datum');

                $bakdagenCount = 0;
                $d = clone $today;
                while ($d <= $deliveryDt) {
                    $wd = (int)$d->format('N');
                    $ds = $d->format('Y-m-d');
                    if (in_array($wd, $patroon) || in_array($ds, $extraDatums)) {
                        $bakdagenCount++;
                    }
                    $d->modify('+1 day');
                }

                if ($bakdagenCount < $voorbereidingDagen) {
                    $warnings[] = "Niet genoeg bakdagen voor voorbereiding ($bakdagenCount van $voorbereidingDagen nodig).";
                }
            }

            if (!empty($warnings)) {
                echo json_encode([
                    'success' => false,
                    'needs_confirm' => true,
                    'warning' => implode(' ', $warnings)
                ]);
                exit;
            }
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
                INSERT INTO business_order_items (order_id, product_name, quantity, unit_price, variant_id, product_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($items as $item) {
                $stmt->execute([
                    $orderId,
                    $item['product_name'],
                    intval($item['quantity']),
                    floatval($item['unit_price']),
                    !empty($item['variant_id']) ? intval($item['variant_id']) : null,
                    !empty($item['product_id']) ? intval($item['product_id']) : null
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
            }

            $itemsList = "";
            foreach ($items as $item) {
                $itemsList .= "- {$item['quantity']}x {$item['product_name']} (€" . number_format($item['unit_price'], 2, ',', '.') . " p/st)\n";
            }

            $to = "info@bakkerij-civetta.nl";
            $typeLabel = $isInternal ? 'intern' : 'admin';
            $subject = "Nieuwe bestelling ($typeLabel) van {$accountInfo['bedrijfsnaam']} (#$orderId)";
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
                $pushTitle = $isInternal ? 'Nieuwe interne bestelling' : 'Nieuwe bestelling (admin)';
                $pushBody = $accountInfo['bedrijfsnaam'] . ' - €' . number_format($totalAmount, 2, ',', '.') . ' (' . date('d-m-Y', strtotime($deliveryDate)) . ')';
                sendPushNotification($pdo, $pushTitle, $pushBody);
            } catch (\Throwable $e) {
                error_log('Push notification fout: ' . $e->getMessage());
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
                INSERT INTO business_order_items (order_id, product_name, quantity, unit_price, variant_id, product_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($items as $item) {
                $stmt->execute([
                    $orderId,
                    $item['product_name'],
                    intval($item['quantity']),
                    floatval($item['unit_price']),
                    !empty($item['variant_id']) ? intval($item['variant_id']) : null,
                    !empty($item['product_id']) ? intval($item['product_id']) : null
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

                $emailHtml = buildAdminOrderEditEmail($order, $oldItems, $items, $bedrijf, $btwTarief, $pdo);
                sendHtmlEmail(
                    $order['email'],
                    getEmailSubject($pdo, 'bestelling_aangepast', 'Uw bestelling #' . $orderId . ' is aangepast door Bakkerij Civetta'),
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
