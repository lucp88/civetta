<?php
session_start();
require_once '../admin/config.php';
require_once '../lib/fpdf.php';

class BestelbonPDF extends FPDF {
    function Header() {
        $this->SetFont('Helvetica', 'B', 20);
        $this->Cell(0, 10, 'BESTELBON', 0, 1, 'R');
        $this->Ln(5);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(128);
        $this->Cell(0, 10, 'Pagina ' . $this->PageNo(), 0, 0, 'C');
    }
}

function getBedrijfsGegevens($pdo) {
    $velden = ['bedrijf_naam', 'bedrijf_contactpersoon', 'bedrijf_adres', 'bedrijf_postcode', 'bedrijf_plaats', 'bedrijf_telefoon', 'bedrijf_email', 'bedrijf_kvk', 'bedrijf_btw_id'];
    $gegevens = [];
    foreach ($velden as $veld) {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$veld]);
        $gegevens[$veld] = $stmt->fetchColumn() ?: '';
    }
    return $gegevens;
}

function getWijzigDeadlineUren($pdo) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bestel_wijzig_deadline_uren'");
    $stmt->execute();
    return intval($stmt->fetchColumn() ?: 48);
}

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

function calculateDeliveryStatus($deliveryDate, $deadlineHours) {
    $now = new DateTime();
    $delivery = new DateTime($deliveryDate);
    $deliveryEnd = (clone $delivery)->setTime(17, 0, 0);
    $deliveryStart = (clone $delivery)->setTime(0, 0, 0);
    $prepDeadline = (clone $delivery)->modify("-{$deadlineHours} hours");
    
    if ($now >= $deliveryEnd) {
        return 'afgeleverd';
    }
    
    if ($now >= $deliveryStart && $now < $deliveryEnd) {
        return 'onderweg';
    }
    
    if ($now >= $prepDeadline) {
        return 'wordt_bereid';
    }
    
    return 'geplaatst';
}

function updateDeliveryStatusIfNeeded($pdo, $orderId, $deliveryDate, $currentStatus, $isCancelled) {
    if ($isCancelled) return $currentStatus;
    
    $deadlineHours = getWijzigDeadlineUren($pdo);
    $calculatedStatus = calculateDeliveryStatus($deliveryDate, $deadlineHours);
    
    if ($calculatedStatus !== $currentStatus) {
        $stmt = $pdo->prepare("UPDATE business_orders SET delivery_status = ? WHERE id = ?");
        $stmt->execute([$calculatedStatus, $orderId]);
        return $calculatedStatus;
    }
    
    return $currentStatus;
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
    
    $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE order_id = ?");
    $stmt->execute([$firstOrder['id']]);
    $items = $stmt->fetchAll();
    
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
    
    $orderTotal = 0;
    foreach ($items as $item) {
        $lineTotal = $item['quantity'] * $item['unit_price'];
        $orderTotal += $lineTotal;
        $pdf->Cell(100, 7, ' ' . $item['product_name'], 1, 0, 'L');
        $pdf->Cell(30, 7, $item['quantity'], 1, 0, 'C');
        $pdf->Cell(30, 7, euro($item['unit_price']), 1, 0, 'R');
        $pdf->Cell(30, 7, euro($lineTotal), 1, 1, 'R');
    }
    
    $totalInclBtw = $orderTotal * (1 + $btwTarief / 100);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(160, 7, 'Totaal per levering (incl. BTW):', 1, 0, 'R');
    $pdf->Cell(30, 7, euro($totalInclBtw), 1, 1, 'R');
    
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

function euro($amount) {
    return chr(128) . ' ' . number_format($amount, 2, ',', '.');
}

function sendBestelbonEmail($pdo, $orderId) {
    $bestelbonDir = __DIR__ . '/../bestelbonnen';
    if (!is_dir($bestelbonDir)) {
        mkdir($bestelbonDir, 0755, true);
    }
    
    $bestelbonFile = $bestelbonDir . '/bestelbon-' . $orderId . '.pdf';
    generateBestelbon($pdo, $orderId, $bestelbonFile);
    
    $stmt = $pdo->prepare("
        SELECT bo.*, ba.bedrijfsnaam, ba.contactpersoon, ba.email 
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
    
    $deadlineHours = getWijzigDeadlineUren($pdo);
    $editDeadline = calculateEditDeadline($order['delivery_date'], $deadlineHours);
    
    $to = $order['email'];
    $subject = "Bestelbon $bestelbonNummer - Bakkerij Civetta";
    
    $boundary = md5(time());
    
    $headers = "From: noreply@bakkerij-civetta.nl\r\n";
    $headers .= "Reply-To: info@bakkerij-civetta.nl\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
    
    $message = "--$boundary\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= "Beste {$order['contactpersoon']},\n\n";
    $message .= "Bedankt voor uw bestelling! In de bijlage vindt u uw bestelbon.\n\n";
    $message .= "Bestelbonnummer: $bestelbonNummer\n";
    $message .= "Bestelling: #{$orderId}\n";
    $message .= "Leverdatum: " . date('d-m-Y', strtotime($order['delivery_date'])) . "\n";
    $message .= "Totaalbedrag: EUR " . number_format($order['total_amount'], 2, ',', '.') . "\n\n";
    $message .= "BELANGRIJK:\n";
    $message .= "- Deze bestelling kan gewijzigd worden tot " . $editDeadline->format('d-m-Y H:i') . "\n";
    $message .= "- Wijzigingen kunt u doorvoeren via uw dashboard\n";
    $message .= "- De factuur ontvangt u na levering\n";
    $message .= "- Wilt u direct betalen? Dat kan via uw dashboard\n\n";
    $message .= "Met vriendelijke groet,\n";
    $message .= "Bakkerij Civetta\n";
    $message .= "info@bakkerij-civetta.nl\r\n";
    
    $message .= "--$boundary\r\n";
    $message .= "Content-Type: application/pdf; name=\"bestelbon-$orderId.pdf\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= "Content-Disposition: attachment; filename=\"bestelbon-$orderId.pdf\"\r\n\r\n";
    $message .= chunk_split(base64_encode(file_get_contents($bestelbonFile))) . "\r\n";
    $message .= "--$boundary--";
    
    return @mail($to, $subject, $message, $headers);
}

function sendRecurringBestelbonEmail($pdo, $recurringGroupId) {
    $bestelbonDir = __DIR__ . '/../bestelbonnen';
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
    
    $to = $order['email'];
    $subject = "Terugkerende bestelling bevestigd - Bakkerij Civetta";
    
    $boundary = md5(time());
    
    $headers = "From: noreply@bakkerij-civetta.nl\r\n";
    $headers .= "Reply-To: info@bakkerij-civetta.nl\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
    
    $frequentieLabels = ['weekly' => 'wekelijks', 'biweekly' => 'tweewekelijks', 'monthly' => 'maandelijks'];
    $frequentie = $frequentieLabels[$order['recurring_frequency']] ?? $order['recurring_frequency'];
    
    $message = "--$boundary\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= "Beste {$order['contactpersoon']},\n\n";
    $message .= "Uw terugkerende bestelling is bevestigd!\n\n";
    $message .= "Naam: " . ($order['recurring_name'] ?: 'Terugkerende bestelling') . "\n";
    $message .= "Frequentie: $frequentie\n";
    $message .= "Eerste levering: " . date('d-m-Y', strtotime($order['delivery_date'])) . "\n\n";
    $message .= "In de bijlage vindt u een overzicht van alle ingeplande leveringen voor de komende 3 maanden.\n\n";
    $message .= "BELANGRIJK:\n";
    $message .= "- Individuele leveringen kunnen gewijzigd worden via uw dashboard\n";
    $message .= "- 2 weken voor het einde van de 3 maanden vragen wij u om herbevestiging\n";
    $message .= "- Na elke levering ontvangt u een factuur\n\n";
    $message .= "Met vriendelijke groet,\n";
    $message .= "Bakkerij Civetta\n";
    $message .= "info@bakkerij-civetta.nl\r\n";
    
    $message .= "--$boundary\r\n";
    $message .= "Content-Type: application/pdf; name=\"overzicht-terugkerende-bestelling.pdf\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= "Content-Disposition: attachment; filename=\"overzicht-terugkerende-bestelling.pdf\"\r\n\r\n";
    $message .= chunk_split(base64_encode(file_get_contents($bestelbonFile))) . "\r\n";
    $message .= "--$boundary--";
    
    return @mail($to, $subject, $message, $headers);
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
    $bestelbonDir = __DIR__ . '/../bestelbonnen';
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
    
    $bestelbonNummer = $order['bestelbon_number'] ?: generateBestelbonNumber($pdo, $orderId);
    
    $to = $order['email'];
    $subject = "Annulering bestelling $bestelbonNummer - Bakkerij Civetta";
    
    $boundary = md5(time());
    
    $headers = "From: noreply@bakkerij-civetta.nl\r\n";
    $headers .= "Reply-To: info@bakkerij-civetta.nl\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
    
    $message = "--$boundary\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= "Beste {$order['contactpersoon']},\n\n";
    $message .= "Uw bestelling is geannuleerd.\n\n";
    $message .= "Bestelbonnummer: $bestelbonNummer\n";
    $message .= "Bestelling: #{$orderId}\n";
    $message .= "Oorspronkelijke leverdatum: " . date('d-m-Y', strtotime($order['delivery_date'])) . "\n\n";
    $message .= "In de bijlage vindt u een bevestiging van de annulering.\n\n";
    $message .= "Heeft u vragen over deze annulering? Neem dan gerust contact met ons op.\n\n";
    $message .= "Met vriendelijke groet,\n";
    $message .= "Bakkerij Civetta\n";
    $message .= "info@bakkerij-civetta.nl\r\n";
    
    if (file_exists($bestelbonFile)) {
        $message .= "--$boundary\r\n";
        $message .= "Content-Type: application/pdf; name=\"annulering-$orderId.pdf\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-Disposition: attachment; filename=\"annulering-$orderId.pdf\"\r\n\r\n";
        $message .= chunk_split(base64_encode(file_get_contents($bestelbonFile))) . "\r\n";
    }
    $message .= "--$boundary--";
    
    $customerSent = @mail($to, $subject, $message, $headers);
    
    $adminSubject = "Bestelling geannuleerd: #{$orderId} - {$order['bedrijfsnaam']}";
    $adminBody = "Een bestelling is geannuleerd door de klant.\n\n";
    $adminBody .= "══════════════════════════════════════\n";
    $adminBody .= "ANNULERING\n";
    $adminBody .= "══════════════════════════════════════\n\n";
    $adminBody .= "Bestelnummer: #{$orderId}\n";
    $adminBody .= "Bestelbonnummer: $bestelbonNummer\n";
    $adminBody .= "Bedrijf: {$order['bedrijfsnaam']}\n";
    $adminBody .= "Contactpersoon: {$order['contactpersoon']}\n";
    $adminBody .= "Email: {$order['email']}\n\n";
    $adminBody .= "Oorspronkelijke leverdatum: " . date('d-m-Y', strtotime($order['delivery_date'])) . "\n";
    $adminBody .= "Totaalbedrag: EUR " . number_format($order['total_amount'], 2, ',', '.') . "\n\n";
    $adminBody .= "De klant heeft een bevestiging van de annulering ontvangen.\n";
    
    $adminHeaders = "From: noreply@bakkerij-civetta.nl\r\n";
    $adminHeaders .= "Reply-To: {$order['email']}\r\n";
    $adminHeaders .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    @mail('laurens@bakkerij-civetta.nl', $adminSubject, $adminBody, $adminHeaders);
    
    return $customerSent;
}

if (!isset($_GET['action']) && !isset($_GET['order_id']) && !isset($_GET['recurring_group_id'])) {
    return;
}

$action = $_GET['action'] ?? 'view';
$orderId = intval($_GET['order_id'] ?? 0);
$recurringGroupId = $_GET['recurring_group_id'] ?? '';

$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];
$isBusinessUser = isset($_SESSION['business_logged_in']) && $_SESSION['business_logged_in'];

if (!$isAdmin && !$isBusinessUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Niet ingelogd']);
    exit;
}

if ($recurringGroupId) {
    if ($isBusinessUser && !$isAdmin) {
        $stmt = $pdo->prepare("SELECT id FROM business_orders WHERE recurring_group_id = ? AND account_id = ? LIMIT 1");
        $stmt->execute([$recurringGroupId, $_SESSION['business_account_id']]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Geen toegang']);
            exit;
        }
    }
    
    $bestelbonDir = __DIR__ . '/../bestelbonnen';
    if (!is_dir($bestelbonDir)) {
        mkdir($bestelbonDir, 0755, true);
    }
    
    $bestelbonFile = $bestelbonDir . '/bestelbon-recurring-' . $recurringGroupId . '.pdf';
    generateRecurringBestelbon($pdo, $recurringGroupId, $bestelbonFile);
    
    if (file_exists($bestelbonFile)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="overzicht-terugkerende-bestelling.pdf"');
        readfile($bestelbonFile);
    } else {
        http_response_code(500);
        echo 'Kon bestelbon niet genereren';
    }
    exit;
}

if (!$orderId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Order ID ontbreekt']);
    exit;
}

if ($isBusinessUser && !$isAdmin) {
    $stmt = $pdo->prepare("SELECT id FROM business_orders WHERE id = ? AND account_id = ?");
    $stmt->execute([$orderId, $_SESSION['business_account_id']]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Geen toegang tot deze bestelbon']);
        exit;
    }
}

$bestelbonDir = __DIR__ . '/../bestelbonnen';
if (!is_dir($bestelbonDir)) {
    mkdir($bestelbonDir, 0755, true);
}

$bestelbonFile = $bestelbonDir . '/bestelbon-' . $orderId . '.pdf';

if ($action === 'generate' || !file_exists($bestelbonFile)) {
    generateBestelbon($pdo, $orderId, $bestelbonFile);
}

if ($action === 'view' || $action === 'generate') {
    if (file_exists($bestelbonFile)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="bestelbon-' . $orderId . '.pdf"');
        readfile($bestelbonFile);
    } else {
        http_response_code(500);
        echo 'Kon bestelbon niet genereren';
    }
}

if ($action === 'send') {
    header('Content-Type: application/json');
    $result = sendBestelbonEmail($pdo, $orderId);
    echo json_encode(['success' => $result]);
}

if ($action === 'can_edit') {
    header('Content-Type: application/json');
    echo json_encode(['can_edit' => canOrderBeEdited($pdo, $orderId)]);
}
