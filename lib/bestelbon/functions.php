<?php
require_once __DIR__ . '/../shared.php';
require_once __DIR__ . '/BestelbonPDF.php';
require_once __DIR__ . '/../../api/email-templates.php';
require_once __DIR__ . '/../../api/delivery-status.php';

function generateBestelbonNumber($pdo, $orderId) {
    return 'B' . date('Y') . '-' . str_pad($orderId, 4, '0', STR_PAD_LEFT);
}

function calculateEditDeadline($deliveryDate, $deadlineHours) {
    $delivery = new DateTime($deliveryDate);
    $delivery->modify("-{$deadlineHours} hours");
    return $delivery;
}

function canOrderBeEdited($pdo, $orderId) {
    $stmt = $pdo->prepare("
        SELECT bo.delivery_date, bo.payment_status, bo.order_status 
        FROM business_orders bo 
        WHERE bo.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    
    if (!$order) return false;
    if ($order['payment_status'] === 'paid') return false;
    if (in_array($order['order_status'], ['wordt_vandaag_geleverd', 'afgeleverd'])) return false;
    
    $deadlineHours = getWijzigDeadlineUren($pdo);
    $deadline = calculateEditDeadline($order['delivery_date'], $deadlineHours);
    $now = new DateTime();
    
    return $now < $deadline;
}

function generateBestelbon($pdo, $orderId, $outputPath = null) {
    $stmt = $pdo->prepare("
        SELECT bo.*, ba.bedrijfsnaam, ba.adres, ba.postcode, ba.plaats, 
               ba.contactpersoon, ba.email, ba.telefoon, ba.kvk_nummer, ba.btw_id,
               ba.delivery_same_as_business, ba.delivery_adres, ba.delivery_postcode, 
               ba.delivery_plaats, ba.delivery_contactpersoon
        FROM business_orders bo
        JOIN business_accounts ba ON bo.account_id = ba.id
        WHERE bo.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    
    if (!$order) return false;
    
    $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();
    
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'btw_tarief'");
    $btwTarief = floatval($stmt->fetchColumn() ?: 9);
    
    $bedrijf = getBedrijfsGegevens($pdo);
    $deadlineHours = getWijzigDeadlineUren($pdo);
    
    $totalInclBtw = floatval($order['total_amount']);
    $btwBedrag = $totalInclBtw - ($totalInclBtw / (1 + $btwTarief / 100));
    $exclBtw = $totalInclBtw - $btwBedrag;
    
    $bestelbonNummer = $order['bestelbon_number'] ?: generateBestelbonNumber($pdo, $orderId);
    $besteldatum = date('d-m-Y', strtotime($order['created_at']));
    $leverDatum = date('d-m-Y', strtotime($order['delivery_date']));
    
    $editDeadline = calculateEditDeadline($order['delivery_date'], $deadlineHours);
    $canEdit = canOrderBeEdited($pdo, $orderId);
    
    $deliveryAdres = $order['delivery_same_as_business'] 
        ? $order['adres'] 
        : ($order['delivery_adres'] ?: $order['adres']);
    $deliveryPostcode = $order['delivery_same_as_business'] 
        ? $order['postcode'] 
        : ($order['delivery_postcode'] ?: $order['postcode']);
    $deliveryPlaats = $order['delivery_same_as_business'] 
        ? $order['plaats'] 
        : ($order['delivery_plaats'] ?: $order['plaats']);
    
    $pdf = new BestelbonPDF();
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 20);

    $bedrijfNaam = $bedrijf['bedrijf_naam'] ?: 'Bakkerij Civetta';
    $bedrijfPlaats = ($bedrijf['bedrijf_postcode'] || $bedrijf['bedrijf_plaats'])
        ? trim($bedrijf['bedrijf_postcode'] . ' ' . $bedrijf['bedrijf_plaats'])
        : 'Leersum, Utrecht';
    $bedrijfEmail = $bedrijf['bedrijf_email'] ?: 'info@bakkerij-civetta.nl';

    if (!empty($order['is_internal'])) {
        $pdf->SetFillColor(139, 90, 43);
        $pdf->SetTextColor(255);
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'INTERNE BESTELLING', 0, 1, 'C', true);
        $pdf->Ln(5);
        $pdf->SetTextColor(0);
    }

    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(95, 5, $bedrijfNaam, 0, 0);
    $pdf->Cell(95, 5, 'Klant:', 0, 1);
    
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(95, 5, $bedrijfPlaats, 0, 0);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(95, 5, $order['bedrijfsnaam'], 0, 1);
    
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(95, 5, $bedrijfEmail, 0, 0);
    $pdf->Cell(95, 5, 't.a.v. ' . $order['contactpersoon'], 0, 1);
    
    $pdf->Cell(95, 5, '', 0, 0);
    $pdf->Cell(95, 5, $order['email'], 0, 1);
    
    $pdf->Ln(10);
    
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(45, 6, 'Bestelbonnummer:', 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(45, 6, $bestelbonNummer, 0, 0);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(45, 6, 'Besteldatum:', 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(45, 6, $besteldatum, 0, 1);
    
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(45, 6, 'Bestelnummer:', 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(45, 6, '#' . $orderId, 0, 0);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(45, 6, 'Leverdatum:', 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(45, 6, $leverDatum, 0, 1);
    
    $pdf->Ln(5);
    
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(45, 6, 'Afleveradres:', 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(145, 6, $deliveryAdres . ', ' . $deliveryPostcode . ' ' . $deliveryPlaats, 0, 1);
    
    $pdf->Ln(10);
    
    $pdf->SetFillColor(92, 61, 30);
    $pdf->SetTextColor(255);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(90, 8, ' Product', 1, 0, 'L', true);
    $pdf->Cell(25, 8, 'Aantal', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Prijs/stuk', 1, 0, 'R', true);
    $pdf->Cell(40, 8, 'Totaal', 1, 1, 'R', true);
    
    $pdf->SetTextColor(0);
    $pdf->SetFont('Helvetica', '', 9);
    
    $fill = false;
    foreach ($items as $item) {
        $pdf->SetFillColor(245, 242, 237);
        $lineTotal = $item['quantity'] * $item['unit_price'];
        $pdf->Cell(90, 7, ' ' . $item['product_name'], 1, 0, 'L', $fill);
        $pdf->Cell(25, 7, $item['quantity'], 1, 0, 'C', $fill);
        $pdf->Cell(35, 7, euro($item['unit_price']), 1, 0, 'R', $fill);
        $pdf->Cell(40, 7, euro($lineTotal), 1, 1, 'R', $fill);
        $fill = !$fill;
    }
    
    $pdf->Ln(5);
    
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(150, 6, 'Subtotaal excl. BTW:', 0, 0, 'R');
    $pdf->Cell(40, 6, euro($exclBtw), 0, 1, 'R');
    
    $pdf->Cell(150, 6, 'BTW (' . $btwTarief . '%):', 0, 0, 'R');
    $pdf->Cell(40, 6, euro($btwBedrag), 0, 1, 'R');
    
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(150, 8, 'Totaal incl. BTW:', 0, 0, 'R');
    $pdf->Cell(40, 8, euro($totalInclBtw), 0, 1, 'R');
    
    $pdf->Ln(10);
    
    if (!empty($order['is_internal'])) {
        $pdf->SetFillColor(139, 90, 43);
        $pdf->SetTextColor(255);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 8, 'INTERNE BESTELBON - GEEN FACTUUR', 0, 1, 'C', true);

        $pdf->SetTextColor(0);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Ln(5);
        $pdf->MultiCell(0, 5, 'Dit is een interne bestelling. Er wordt geen factuur aangemaakt.', 0, 'L');
    } elseif (!empty($order['payment_type']) && $order['payment_type'] === 'saldo') {
        $pdf->SetFillColor(200, 230, 201);
        $pdf->SetTextColor(27, 94, 32);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 8, 'BETAALD MET SALDO', 0, 1, 'C', true);

        $pdf->SetTextColor(0);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Ln(5);
        $pdf->MultiCell(0, 5, 'Deze bestelling is betaald met uw tegoed. Er wordt geen separate factuur aangemaakt.', 0, 'L');
    } else {
        $pdf->SetFillColor(255, 243, 205);
        $pdf->SetTextColor(133, 100, 4);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 8, 'BESTELBON - FACTUUR VOLGT NA LEVERING', 0, 1, 'C', true);

        $pdf->SetTextColor(0);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Ln(5);

        if ($canEdit) {
            $pdf->MultiCell(0, 5, 'Deze bestelling kan nog gewijzigd worden tot ' . $editDeadline->format('d-m-Y H:i') . '.', 0, 'L');
            $pdf->MultiCell(0, 5, 'Wijzigingen kunt u doorvoeren via uw dashboard op onze website.', 0, 'L');
        } else {
            $pdf->MultiCell(0, 5, 'De deadline voor wijzigingen is verstreken. Deze bestelling kan niet meer worden aangepast.', 0, 'L');
        }

        $pdf->Ln(5);
        $pdf->MultiCell(0, 5, 'Wilt u direct betalen? Dat kan via het dashboard in uw account.', 0, 'L');
        $pdf->MultiCell(0, 5, 'Na levering ontvangt u de officiële factuur.', 0, 'L');
    }
    
    $pdf->SetTextColor(0);
    $pdf->Ln(10);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(128);
    $footerLine1 = $bedrijfNaam . ' | ' . $bedrijfPlaats . ' | ' . $bedrijfEmail;
    $pdf->MultiCell(0, 4, $footerLine1, 0, 'C');
    
    if ($outputPath) {
        $pdf->Output('F', $outputPath);
        return $outputPath;
    } else {
        return $pdf;
    }
}

function generateRecurringBestelbon($pdo, $recurringGroupId, $outputPath = null) {
    $stmt = $pdo->prepare("
        SELECT bo.*, ba.bedrijfsnaam, ba.adres, ba.postcode, ba.plaats, 
               ba.contactpersoon, ba.email, ba.telefoon,
               ba.delivery_same_as_business, ba.delivery_adres, ba.delivery_postcode, 
               ba.delivery_plaats
        FROM business_orders bo
        JOIN business_accounts ba ON bo.account_id = ba.id
        WHERE bo.recurring_group_id = ?
        ORDER BY bo.delivery_date ASC
        LIMIT 1
    ");
    $stmt->execute([$recurringGroupId]);
    $firstOrder = $stmt->fetch();
    
    if (!$firstOrder) return false;
    
    $stmt = $pdo->prepare("
        SELECT bo.id, bo.delivery_date, bo.total_amount, bo.order_status
        FROM business_orders bo
        WHERE bo.recurring_group_id = ?
        AND bo.delivery_date >= CURDATE()
        AND bo.delivery_date <= DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
        ORDER BY bo.delivery_date ASC
    ");
    $stmt->execute([$recurringGroupId]);
    $upcomingOrders = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("
        SELECT ti.product_name, ti.quantity, ti.unit_price 
        FROM recurring_group_template_items ti
        JOIN recurring_group_templates t ON ti.template_id = t.id
        WHERE t.recurring_group_id = ?
    ");
    $stmt->execute([$recurringGroupId]);
    $items = $stmt->fetchAll();
    
    if (empty($items)) {
        $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE order_id = ?");
        $stmt->execute([$firstOrder['id']]);
        $items = $stmt->fetchAll();
    }
    
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'btw_tarief'");
    $btwTarief = floatval($stmt->fetchColumn() ?: 9);
    
    $bedrijf = getBedrijfsGegevens($pdo);
    $deadlineHours = getWijzigDeadlineUren($pdo);
    
    $pdf = new BestelbonPDF();
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 20);
    
    $bedrijfNaam = $bedrijf['bedrijf_naam'] ?: 'Bakkerij Civetta';
    $bedrijfPlaats = ($bedrijf['bedrijf_postcode'] || $bedrijf['bedrijf_plaats']) 
        ? trim($bedrijf['bedrijf_postcode'] . ' ' . $bedrijf['bedrijf_plaats']) 
        : 'Leersum, Utrecht';
    $bedrijfEmail = $bedrijf['bedrijf_email'] ?: 'info@bakkerij-civetta.nl';
    
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'TERUGKERENDE BESTELLING - OVERZICHT', 0, 1, 'C');
    $pdf->Ln(5);
    
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(95, 5, $bedrijfNaam, 0, 0);
    $pdf->Cell(95, 5, 'Klant:', 0, 1);
    
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(95, 5, $bedrijfPlaats, 0, 0);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(95, 5, $firstOrder['bedrijfsnaam'], 0, 1);
    
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(95, 5, $bedrijfEmail, 0, 0);
    $pdf->Cell(95, 5, 't.a.v. ' . $firstOrder['contactpersoon'], 0, 1);
    
    $pdf->Ln(10);
    
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(45, 6, 'Naam bestelling:', 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(145, 6, $firstOrder['recurring_name'] ?: 'Terugkerende bestelling', 0, 1);
    
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(45, 6, 'Frequentie:', 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $frequentieLabels = ['weekly' => 'Wekelijks', 'biweekly' => 'Tweewekelijks', 'monthly' => 'Maandelijks'];
    $pdf->Cell(145, 6, $frequentieLabels[$firstOrder['recurring_frequency']] ?? $firstOrder['recurring_frequency'], 0, 1);
    
    $pdf->Ln(10);
    
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'Producten per levering:', 0, 1);
    $pdf->Ln(3);
    
    $pdf->SetFillColor(92, 61, 30);
    $pdf->SetTextColor(255);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(100, 8, ' Product', 1, 0, 'L', true);
    $pdf->Cell(30, 8, 'Aantal', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Prijs/stuk', 1, 0, 'R', true);
    $pdf->Cell(30, 8, 'Totaal', 1, 1, 'R', true);
    
    $pdf->SetTextColor(0);
    $pdf->SetFont('Helvetica', '', 9);
    
    $totalInclBtw = 0;
    foreach ($items as $item) {
        $lineTotal = $item['quantity'] * $item['unit_price'];
        $totalInclBtw += $lineTotal;
        $pdf->Cell(100, 7, ' ' . $item['product_name'], 1, 0, 'L');
        $pdf->Cell(30, 7, $item['quantity'], 1, 0, 'C');
        $pdf->Cell(30, 7, euro($item['unit_price']), 1, 0, 'R');
        $pdf->Cell(30, 7, euro($lineTotal), 1, 1, 'R');
    }
    
    $btwBedrag = $totalInclBtw - ($totalInclBtw / (1 + $btwTarief / 100));
    $exclBtw = $totalInclBtw - $btwBedrag;
    
    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(160, 6, 'Subtotaal excl. BTW:', 0, 0, 'R');
    $pdf->Cell(30, 6, euro($exclBtw), 0, 1, 'R');
    
    $pdf->Cell(160, 6, 'BTW (' . $btwTarief . '%):', 0, 0, 'R');
    $pdf->Cell(30, 6, euro($btwBedrag), 0, 1, 'R');
    
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(160, 7, 'Totaal per levering (incl. BTW):', 0, 0, 'R');
    $pdf->Cell(30, 7, euro($totalInclBtw), 0, 1, 'R');
    
    $pdf->Ln(10);
    
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'Ingeplande leveringen (komende 3 maanden):', 0, 1);
    $pdf->Ln(3);
    
    $pdf->SetFillColor(92, 61, 30);
    $pdf->SetTextColor(255);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(50, 8, ' Leverdatum', 1, 0, 'L', true);
    $pdf->Cell(50, 8, 'Bedrag', 1, 0, 'R', true);
    $pdf->Cell(50, 8, 'Status', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Wijzigbaar tot', 1, 1, 'C', true);
    
    $pdf->SetTextColor(0);
    $pdf->SetFont('Helvetica', '', 9);
    
    $fill = false;
    foreach ($upcomingOrders as $upcoming) {
        $pdf->SetFillColor(245, 242, 237);
        $editDeadline = calculateEditDeadline($upcoming['delivery_date'], $deadlineHours);
        $canEdit = (new DateTime()) < $editDeadline;
        
        $statusLabels = [
            'geplaatst' => 'Geplaatst',
            'wordt_bereid' => 'Wordt bereid',
            'wordt_vandaag_geleverd' => 'Vandaag',
            'afgeleverd' => 'Afgeleverd'
        ];
        
        $pdf->Cell(50, 7, ' ' . date('d-m-Y', strtotime($upcoming['delivery_date'])), 1, 0, 'L', $fill);
        $pdf->Cell(50, 7, euro($upcoming['total_amount']), 1, 0, 'R', $fill);
        $pdf->Cell(50, 7, $statusLabels[$upcoming['order_status']] ?? $upcoming['order_status'], 1, 0, 'C', $fill);
        $pdf->Cell(40, 7, $canEdit ? $editDeadline->format('d-m H:i') : '-', 1, 1, 'C', $fill);
        $fill = !$fill;
    }
    
    $pdf->Ln(10);
    
    $pdf->SetFillColor(232, 244, 253);
    $pdf->SetTextColor(0, 64, 133);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->MultiCell(0, 6, 'Let op: 2 weken voor het einde van deze 3 maanden zullen wij u vragen om de bestelling te herbevestigen.', 0, 'L', true);
    
    $pdf->SetTextColor(0);
    $pdf->Ln(5);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->MultiCell(0, 5, 'Individuele leveringen kunnen gewijzigd worden via uw dashboard, mits ' . $deadlineHours . ' uur voor de leverdatum.', 0, 'L');
    $pdf->MultiCell(0, 5, 'Na elke levering ontvangt u een factuur.', 0, 'L');
    
    $pdf->SetTextColor(0);
    $pdf->Ln(10);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(128);
    $footerLine1 = $bedrijfNaam . ' | ' . $bedrijfPlaats . ' | ' . $bedrijfEmail;
    $pdf->MultiCell(0, 4, $footerLine1, 0, 'C');
    
    if ($outputPath) {
        $pdf->Output('F', $outputPath);
        return $outputPath;
    } else {
        return $pdf;
    }
}

function sendBestelbonEmail($pdo, $orderId) {
    $bestelbonDir = __DIR__ . '/../../bestelbonnen';
    if (!is_dir($bestelbonDir)) {
        mkdir($bestelbonDir, 0755, true);
    }
    
    $bestelbonFile = $bestelbonDir . '/bestelbon-' . $orderId . '.pdf';
    generateBestelbon($pdo, $orderId, $bestelbonFile);
    
    $stmt = $pdo->prepare("
        SELECT bo.*, ba.bedrijfsnaam, ba.contactpersoon, ba.email, ba.adres, ba.postcode, ba.plaats,
               ba.delivery_same_as_business, ba.delivery_adres, ba.delivery_postcode, ba.delivery_plaats
        FROM business_orders bo 
        JOIN business_accounts ba ON bo.account_id = ba.id 
        WHERE bo.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    
    if (!$order || !file_exists($bestelbonFile)) return false;
    
    $bestelbonNummer = $order['bestelbon_number'] ?: generateBestelbonNumber($pdo, $orderId);
    
    if (!$order['bestelbon_number']) {
        $stmt = $pdo->prepare("UPDATE business_orders SET bestelbon_number = ?, bestelbon_sent_at = NOW() WHERE id = ?");
        $stmt->execute([$bestelbonNummer, $orderId]);
    }
    
    $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();
    
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'btw_tarief'");
    $btwTarief = floatval($stmt->fetchColumn() ?: 9);
    
    $deadlineHours = getWijzigDeadlineUren($pdo);
    $editDeadline = calculateEditDeadline($order['delivery_date'], $deadlineHours);
    $order['can_be_edited_until'] = $editDeadline->format('Y-m-d H:i:s');
    
    if (!$order['delivery_same_as_business']) {
        $order['delivery_adres'] = $order['delivery_adres'] ?: $order['adres'];
        $order['delivery_postcode'] = $order['delivery_postcode'] ?: $order['postcode'];
        $order['delivery_plaats'] = $order['delivery_plaats'] ?: $order['plaats'];
    } else {
        $order['delivery_adres'] = $order['adres'];
        $order['delivery_postcode'] = $order['postcode'];
        $order['delivery_plaats'] = $order['plaats'];
    }
    
    $bedrijf = getBedrijfsGegevens($pdo);
    
    $htmlBody = buildOrderConfirmationEmail($order, $items, $bedrijf, $btwTarief);
    
    $to = $order['email'];
    $subject = "Bestelbevestiging $bestelbonNummer - Bakkerij Civetta";
    
    $attachments = [
        ['path' => $bestelbonFile, 'name' => "bestelbon-$orderId.pdf", 'type' => 'application/pdf']
    ];
    
    return sendHtmlEmail($to, $subject, $htmlBody, $attachments);
}

function sendRecurringBestelbonEmail($pdo, $recurringGroupId) {
    $bestelbonDir = __DIR__ . '/../../bestelbonnen';
    if (!is_dir($bestelbonDir)) {
        mkdir($bestelbonDir, 0755, true);
    }
    
    $bestelbonFile = $bestelbonDir . '/bestelbon-recurring-' . $recurringGroupId . '.pdf';
    generateRecurringBestelbon($pdo, $recurringGroupId, $bestelbonFile);
    
    $stmt = $pdo->prepare("
        SELECT bo.*, ba.bedrijfsnaam, ba.contactpersoon, ba.email 
        FROM business_orders bo 
        JOIN business_accounts ba ON bo.account_id = ba.id 
        WHERE bo.recurring_group_id = ?
        ORDER BY bo.delivery_date ASC
        LIMIT 1
    ");
    $stmt->execute([$recurringGroupId]);
    $order = $stmt->fetch();
    
    if (!$order || !file_exists($bestelbonFile)) return false;
    
    $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE order_id = ?");
    $stmt->execute([$order['id']]);
    $items = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("
        SELECT id, delivery_date, total_amount, order_status
        FROM business_orders
        WHERE recurring_group_id = ?
        AND delivery_date >= CURDATE()
        ORDER BY delivery_date ASC
    ");
    $stmt->execute([$recurringGroupId]);
    $upcomingOrders = $stmt->fetchAll();
    
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'btw_tarief'");
    $btwTarief = floatval($stmt->fetchColumn() ?: 9);
    
    $bedrijf = getBedrijfsGegevens($pdo);
    
    $htmlBody = buildRecurringConfirmationEmail($order, $items, $upcomingOrders, $bedrijf, $btwTarief);
    
    $to = $order['email'];
    $subject = "Terugkerende bestelling bevestigd - Bakkerij Civetta";
    
    $attachments = [
        ['path' => $bestelbonFile, 'name' => 'overzicht-terugkerende-bestelling.pdf', 'type' => 'application/pdf']
    ];
    
    return sendHtmlEmail($to, $subject, $htmlBody, $attachments);
}

function generateCancelledBestelbon($pdo, $orderId, $outputPath = null) {
    $stmt = $pdo->prepare("
        SELECT bo.*, ba.bedrijfsnaam, ba.adres, ba.postcode, ba.plaats, 
               ba.contactpersoon, ba.email, ba.telefoon, ba.kvk_nummer, ba.btw_id,
               ba.delivery_same_as_business, ba.delivery_adres, ba.delivery_postcode, 
               ba.delivery_plaats, ba.delivery_contactpersoon
        FROM business_orders bo
        JOIN business_accounts ba ON bo.account_id = ba.id
        WHERE bo.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    
    if (!$order) return false;
    
    $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();
    
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'btw_tarief'");
    $btwTarief = floatval($stmt->fetchColumn() ?: 9);
    
    $bedrijf = getBedrijfsGegevens($pdo);
    
    $totalInclBtw = floatval($order['total_amount']);
    $btwBedrag = $totalInclBtw - ($totalInclBtw / (1 + $btwTarief / 100));
    $exclBtw = $totalInclBtw - $btwBedrag;
    
    $bestelbonNummer = $order['bestelbon_number'] ?: generateBestelbonNumber($pdo, $orderId);
    $besteldatum = date('d-m-Y', strtotime($order['created_at']));
    $leverDatum = date('d-m-Y', strtotime($order['delivery_date']));
    $annuleringsDatum = date('d-m-Y H:i');
    
    $pdf = new BestelbonPDF();
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 20);
    
    $bedrijfNaam = $bedrijf['bedrijf_naam'] ?: 'Bakkerij Civetta';
    $bedrijfPlaats = ($bedrijf['bedrijf_postcode'] || $bedrijf['bedrijf_plaats']) 
        ? trim($bedrijf['bedrijf_postcode'] . ' ' . $bedrijf['bedrijf_plaats']) 
        : 'Leersum, Utrecht';
    $bedrijfEmail = $bedrijf['bedrijf_email'] ?: 'info@bakkerij-civetta.nl';
    
    $pdf->SetFillColor(220, 53, 69);
    $pdf->SetTextColor(255);
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'GEANNULEERD', 0, 1, 'C', true);
    $pdf->Ln(5);
    
    $pdf->SetTextColor(0);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(95, 5, $bedrijfNaam, 0, 0);
    $pdf->Cell(95, 5, 'Klant:', 0, 1);
    
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(95, 5, $bedrijfPlaats, 0, 0);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(95, 5, $order['bedrijfsnaam'], 0, 1);
    
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(95, 5, $bedrijfEmail, 0, 0);
    $pdf->Cell(95, 5, 't.a.v. ' . $order['contactpersoon'], 0, 1);
    
    $pdf->Cell(95, 5, '', 0, 0);
    $pdf->Cell(95, 5, $order['email'], 0, 1);
    
    $pdf->Ln(10);
    
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(45, 6, 'Bestelbonnummer:', 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(45, 6, $bestelbonNummer, 0, 0);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(45, 6, 'Besteldatum:', 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(45, 6, $besteldatum, 0, 1);
    
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(45, 6, 'Bestelnummer:', 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(45, 6, '#' . $orderId, 0, 0);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(45, 6, 'Geannuleerd op:', 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(45, 6, $annuleringsDatum, 0, 1);
    
    $pdf->Ln(5);
    
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(45, 6, 'Oorspronkelijke leverdatum:', 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(145, 6, $leverDatum, 0, 1);
    
    $pdf->Ln(10);
    
    $pdf->SetFillColor(200, 200, 200);
    $pdf->SetTextColor(100);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(90, 8, ' Product', 1, 0, 'L', true);
    $pdf->Cell(25, 8, 'Aantal', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Prijs/stuk', 1, 0, 'R', true);
    $pdf->Cell(40, 8, 'Totaal', 1, 1, 'R', true);
    
    $pdf->SetTextColor(128);
    $pdf->SetFont('Helvetica', '', 9);
    
    foreach ($items as $item) {
        $lineTotal = $item['quantity'] * $item['unit_price'];
        $pdf->Cell(90, 7, ' ' . $item['product_name'], 1, 0, 'L');
        $pdf->Cell(25, 7, $item['quantity'], 1, 0, 'C');
        $pdf->Cell(35, 7, euro($item['unit_price']), 1, 0, 'R');
        $pdf->Cell(40, 7, euro($lineTotal), 1, 1, 'R');
    }
    
    $pdf->Ln(5);
    
    $pdf->SetTextColor(128);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(150, 6, 'Subtotaal excl. BTW:', 0, 0, 'R');
    $pdf->Cell(40, 6, euro($exclBtw), 0, 1, 'R');
    
    $pdf->Cell(150, 6, 'BTW (' . $btwTarief . '%):', 0, 0, 'R');
    $pdf->Cell(40, 6, euro($btwBedrag), 0, 1, 'R');
    
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(150, 8, 'Totaal incl. BTW:', 0, 0, 'R');
    $pdf->Cell(40, 8, euro($totalInclBtw), 0, 1, 'R');
    
    $pdf->Ln(10);
    
    $pdf->SetFillColor(220, 53, 69);
    $pdf->SetTextColor(255);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(0, 8, 'DEZE BESTELLING IS GEANNULEERD', 0, 1, 'C', true);
    
    $pdf->SetTextColor(0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Ln(5);
    $pdf->MultiCell(0, 5, 'Deze bestelling is geannuleerd en zal niet worden geleverd.', 0, 'L');
    $pdf->MultiCell(0, 5, 'Heeft u vragen? Neem dan contact met ons op.', 0, 'L');
    
    $pdf->SetTextColor(0);
    $pdf->Ln(10);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(128);
    $footerLine1 = $bedrijfNaam . ' | ' . $bedrijfPlaats . ' | ' . $bedrijfEmail;
    $pdf->MultiCell(0, 4, $footerLine1, 0, 'C');
    
    if ($outputPath) {
        $pdf->Output('F', $outputPath);
        return $outputPath;
    } else {
        return $pdf;
    }
}

function sendCancellationEmail($pdo, $orderId) {
    $bestelbonDir = __DIR__ . '/../../bestelbonnen';
    if (!is_dir($bestelbonDir)) {
        mkdir($bestelbonDir, 0755, true);
    }
    
    $bestelbonFile = $bestelbonDir . '/bestelbon-geannuleerd-' . $orderId . '.pdf';
    generateCancelledBestelbon($pdo, $orderId, $bestelbonFile);
    
    $stmt = $pdo->prepare("
        SELECT bo.*, ba.bedrijfsnaam, ba.contactpersoon, ba.email 
        FROM business_orders bo 
        JOIN business_accounts ba ON bo.account_id = ba.id 
        WHERE bo.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    
    if (!$order) return false;
    
    $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();
    
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'btw_tarief'");
    $btwTarief = floatval($stmt->fetchColumn() ?: 9);
    
    $bestelbonNummer = $order['bestelbon_number'] ?: generateBestelbonNumber($pdo, $orderId);
    
    $bedrijf = getBedrijfsGegevens($pdo);
    
    $htmlBody = buildCancellationEmail($order, $items, $bedrijf, $btwTarief);
    
    $to = $order['email'];
    $subject = "Annulering bestelling $bestelbonNummer - Bakkerij Civetta";
    
    $attachments = [];
    if (file_exists($bestelbonFile)) {
        $attachments[] = ['path' => $bestelbonFile, 'name' => "annulering-$orderId.pdf", 'type' => 'application/pdf'];
    }
    
    $customerSent = sendHtmlEmail($to, $subject, $htmlBody, $attachments);
    
    $adminHtmlBody = buildAdminOrderNotificationEmail($order, $items, false, $bedrijf);
    $adminHtmlBody = str_replace('Bestelling gewijzigd', 'Bestelling geannuleerd', $adminHtmlBody);
    
    sendHtmlEmail('info@bakkerij-civetta.nl', "Bestelling geannuleerd: #{$orderId} - {$order['bedrijfsnaam']}", $adminHtmlBody, [], $order['email']);
    
    return $customerSent;
}

function sendRecurringPauseEmail($pdo, $accountInfo, $affectedOrders, $unaffectedOrders, $isPause) {
    $bedrijf = getBedrijfsGegevens($pdo);
    
    $htmlBody = buildRecurringPauseEmail($accountInfo, $affectedOrders, $unaffectedOrders, $isPause, $bedrijf);
    
    $to = $accountInfo['email'];
    $recurringName = $accountInfo['recurring_name'] ?? 'Terugkerende bestelling';
    
    if ($isPause) {
        $subject = "Leveringen gepauzeerd: $recurringName - Bakkerij Civetta";
    } else {
        $subject = "Leveringen hervat: $recurringName - Bakkerij Civetta";
    }
    
    $customerSent = sendHtmlEmail($to, $subject, $htmlBody);
    
    $adminSubject = $isPause 
        ? "Terugkerende bestelling gepauzeerd: {$accountInfo['bedrijfsnaam']}"
        : "Terugkerende bestelling hervat: {$accountInfo['bedrijfsnaam']}";
    
    sendHtmlEmail('info@bakkerij-civetta.nl', $adminSubject, $htmlBody, [], $accountInfo['email']);
    
    return $customerSent;
}

function sendRecurringUpdateEmail($pdo, $recurringGroupId, $oldItems, $newItems) {
    $bestelbonDir = __DIR__ . '/../../bestelbonnen';
    if (!is_dir($bestelbonDir)) {
        mkdir($bestelbonDir, 0755, true);
    }
    
    $bestelbonFile = $bestelbonDir . '/bestelbon-recurring-' . $recurringGroupId . '.pdf';
    generateRecurringBestelbon($pdo, $recurringGroupId, $bestelbonFile);
    
    $stmt = $pdo->prepare("
        SELECT bo.*, ba.bedrijfsnaam, ba.contactpersoon, ba.email 
        FROM business_orders bo 
        JOIN business_accounts ba ON bo.account_id = ba.id 
        WHERE bo.recurring_group_id = ?
        ORDER BY bo.delivery_date ASC
        LIMIT 1
    ");
    $stmt->execute([$recurringGroupId]);
    $order = $stmt->fetch();
    
    if (!$order) return false;
    
    $stmt = $pdo->prepare("
        SELECT id, delivery_date, total_amount
        FROM business_orders
        WHERE recurring_group_id = ?
        AND delivery_date >= CURDATE()
        AND is_cancelled = 0
        ORDER BY delivery_date ASC
    ");
    $stmt->execute([$recurringGroupId]);
    $upcomingOrders = $stmt->fetchAll();
    
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'btw_tarief'");
    $btwTarief = floatval($stmt->fetchColumn() ?: 9);
    
    $bedrijf = getBedrijfsGegevens($pdo);
    
    $htmlBody = buildRecurringUpdateEmail($order, $oldItems, $newItems, $upcomingOrders, $bedrijf, $btwTarief);
    
    $to = $order['email'];
    $recurringName = $order['recurring_name'] ?? 'Terugkerende bestelling';
    $subject = "Bestelling gewijzigd: $recurringName - Bakkerij Civetta";
    
    $attachments = [];
    if (file_exists($bestelbonFile)) {
        $attachments[] = ['path' => $bestelbonFile, 'name' => 'gewijzigde-bestelbon.pdf', 'type' => 'application/pdf'];
    }
    
    $customerSent = sendHtmlEmail($to, $subject, $htmlBody, $attachments);
    
    sendHtmlEmail('info@bakkerij-civetta.nl', "Terugkerende bestelling gewijzigd: {$order['bedrijfsnaam']}", $htmlBody, [], $order['email']);
    
    return $customerSent;
}
