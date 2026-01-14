<?php
session_start();
require_once '../admin/config.php';
require_once '../lib/fpdf.php';

class FactuurPDF extends FPDF {
    function Header() {
        $this->SetFont('Helvetica', 'B', 20);
        $this->Cell(0, 10, 'FACTUUR', 0, 1, 'R');
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

function generateFactuur($pdo, $orderId, $outputPath = null) {
    $stmt = $pdo->prepare("
        SELECT bo.*, ba.bedrijfsnaam, ba.adres, ba.postcode, ba.plaats, 
               ba.contactpersoon, ba.email, ba.telefoon, ba.kvk_nummer, ba.btw_id
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
    
    $factuurNummer = 'F' . date('Y') . '-' . str_pad($orderId, 4, '0', STR_PAD_LEFT);
    $factuurDatum = date('d-m-Y');
    $isPaid = ($order['status'] === 'paid' || $order['mollie_status'] === 'paid');
    
    $pdf = new FactuurPDF();
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 20);
    
    $bedrijfNaam = $bedrijf['bedrijf_naam'] ?: 'Bakkerij Civetta';
    $bedrijfPlaats = ($bedrijf['bedrijf_postcode'] || $bedrijf['bedrijf_plaats']) 
        ? trim($bedrijf['bedrijf_postcode'] . ' ' . $bedrijf['bedrijf_plaats']) 
        : 'Leersum, Utrecht';
    $bedrijfEmail = $bedrijf['bedrijf_email'] ?: 'laurens@bakkerij-civetta.nl';
    $bedrijfKvk = $bedrijf['bedrijf_kvk'] ?: '';
    $bedrijfBtw = $bedrijf['bedrijf_btw_id'] ?: '';
    
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(95, 5, $bedrijfNaam, 0, 0);
    $pdf->Cell(95, 5, 'Factuur aan:', 0, 1);
    
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(95, 5, $bedrijfPlaats, 0, 0);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(95, 5, $order['bedrijfsnaam'], 0, 1);
    
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(95, 5, $bedrijfEmail, 0, 0);
    $pdf->Cell(95, 5, $order['adres'], 0, 1);
    
    if ($bedrijfKvk) {
        $pdf->Cell(95, 5, 'KvK: ' . $bedrijfKvk, 0, 0);
    } else {
        $pdf->Cell(95, 5, '', 0, 0);
    }
    $pdf->Cell(95, 5, $order['postcode'] . ' ' . $order['plaats'], 0, 1);
    
    if ($bedrijfBtw) {
        $pdf->Cell(95, 5, 'BTW-id: ' . $bedrijfBtw, 0, 0);
    } else {
        $pdf->Cell(95, 5, '', 0, 0);
    }
    if ($order['kvk_nummer']) {
        $pdf->Cell(95, 5, 'KvK: ' . $order['kvk_nummer'], 0, 1);
    } else {
        $pdf->Ln();
    }
    
    $pdf->Cell(95, 5, '', 0, 0);
    if ($order['btw_id']) {
        $pdf->Cell(95, 5, 'BTW-id: ' . $order['btw_id'], 0, 1);
    } else {
        $pdf->Ln();
    }
    
    $pdf->Ln(10);
    
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(45, 6, 'Factuurnummer:', 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(45, 6, $factuurNummer, 0, 0);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(45, 6, 'Factuurdatum:', 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(45, 6, $factuurDatum, 0, 1);
    
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(45, 6, 'Bestelnummer:', 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(45, 6, '#' . $orderId, 0, 0);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(45, 6, 'Leverdatum:', 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(45, 6, date('d-m-Y', strtotime($order['delivery_date'])), 0, 1);
    
    $pdf->Ln(10);
    
    $pdf->SetFillColor(92, 61, 30);
    $pdf->SetTextColor(255);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(80, 8, ' Product', 1, 0, 'L', true);
    $pdf->Cell(25, 8, 'Aantal', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Prijs/stuk', 1, 0, 'R', true);
    $pdf->Cell(40, 8, 'Totaal', 1, 1, 'R', true);
    
    $pdf->SetTextColor(0);
    $pdf->SetFont('Helvetica', '', 9);
    
    $fill = false;
    foreach ($items as $item) {
        $pdf->SetFillColor(245, 242, 237);
        $lineTotal = $item['quantity'] * $item['unit_price'];
        $pdf->Cell(80, 7, ' ' . $item['product_name'], 1, 0, 'L', $fill);
        $pdf->Cell(25, 7, $item['quantity'], 1, 0, 'C', $fill);
        $pdf->Cell(35, 7, euro($item['unit_price']), 1, 0, 'R', $fill);
        $pdf->Cell(40, 7, euro($lineTotal), 1, 1, 'R', $fill);
        $fill = !$fill;
    }
    
    $pdf->Ln(5);
    
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(140, 6, 'Subtotaal excl. BTW:', 0, 0, 'R');
    $pdf->Cell(40, 6, euro($exclBtw), 0, 1, 'R');
    
    $pdf->Cell(140, 6, 'BTW (' . $btwTarief . '%):', 0, 0, 'R');
    $pdf->Cell(40, 6, euro($btwBedrag), 0, 1, 'R');
    
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(140, 8, 'Totaal incl. BTW:', 0, 0, 'R');
    $pdf->Cell(40, 8, euro($totalInclBtw), 0, 1, 'R');
    
    $pdf->Ln(10);
    
    if ($isPaid) {
        $pdf->SetFillColor(212, 237, 218);
        $pdf->SetTextColor(21, 87, 36);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'BETAALD', 0, 1, 'C', true);
    } else {
        $pdf->SetFillColor(255, 243, 205);
        $pdf->SetTextColor(133, 100, 4);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'OPENSTAAND', 0, 1, 'C', true);
        
        $pdf->SetTextColor(0);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Ln(5);
        $pdf->MultiCell(0, 5, 'Gelieve het bedrag van ' . euro($totalInclBtw) . ' binnen 14 dagen over te maken.', 0, 'L');
    }
    
    $pdf->SetTextColor(0);
    $pdf->Ln(15);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(128);
    $footerLine1 = $bedrijfNaam . ' | ' . $bedrijfPlaats . ' | ' . $bedrijfEmail;
    $footerLine2 = '';
    if ($bedrijfKvk) $footerLine2 .= 'KvK: ' . $bedrijfKvk;
    if ($bedrijfBtw) $footerLine2 .= ($footerLine2 ? ' | ' : '') . 'BTW-id: ' . $bedrijfBtw;
    $pdf->MultiCell(0, 4, $footerLine1 . ($footerLine2 ? "\n" . $footerLine2 : ''), 0, 'C');
    
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

function sendFactuurEmail($pdo, $orderId) {
    $facturenDir = __DIR__ . '/../facturen';
    $factuurFile = $facturenDir . '/factuur-' . $orderId . '.pdf';
    
    if (!file_exists($factuurFile)) {
        generateFactuur($pdo, $orderId, $factuurFile);
    }
    
    $stmt = $pdo->prepare("
        SELECT bo.*, ba.bedrijfsnaam, ba.contactpersoon, ba.email 
        FROM business_orders bo 
        JOIN business_accounts ba ON bo.account_id = ba.id 
        WHERE bo.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    
    if (!$order || !file_exists($factuurFile)) return false;
    
    $to = $order['email'];
    $factuurNummer = 'F' . date('Y') . '-' . str_pad($orderId, 4, '0', STR_PAD_LEFT);
    $subject = "Factuur $factuurNummer - Bakkerij Civetta";
    
    $boundary = md5(time());
    
    $headers = "From: noreply@bakkerij-civetta.nl\r\n";
    $headers .= "Reply-To: laurens@bakkerij-civetta.nl\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
    
    $message = "--$boundary\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= "Beste {$order['contactpersoon']},\n\n";
    $message .= "Bedankt voor uw bestelling! In de bijlage vindt u de factuur.\n\n";
    $message .= "Factuurnummer: $factuurNummer\n";
    $message .= "Bestelling: #{$orderId}\n";
    $message .= "Totaalbedrag: EUR " . number_format($order['total_amount'], 2, ',', '.') . "\n\n";
    $message .= "Met vriendelijke groet,\n";
    $message .= "Bakkerij Civetta\n";
    $message .= "laurens@bakkerij-civetta.nl\r\n";
    
    $message .= "--$boundary\r\n";
    $message .= "Content-Type: application/pdf; name=\"factuur-$orderId.pdf\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= "Content-Disposition: attachment; filename=\"factuur-$orderId.pdf\"\r\n\r\n";
    $message .= chunk_split(base64_encode(file_get_contents($factuurFile))) . "\r\n";
    $message .= "--$boundary--";
    
    return @mail($to, $subject, $message, $headers);
}

if (!isset($_GET['action']) && !isset($_GET['order_id'])) {
    return;
}

$action = $_GET['action'] ?? 'view';
$orderId = intval($_GET['order_id'] ?? 0);

if (!$orderId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Order ID ontbreekt']);
    exit;
}

$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];
$isBusinessUser = isset($_SESSION['business_logged_in']) && $_SESSION['business_logged_in'];

if (!$isAdmin && !$isBusinessUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Niet ingelogd']);
    exit;
}

if ($isBusinessUser && !$isAdmin) {
    $stmt = $pdo->prepare("SELECT id FROM business_orders WHERE id = ? AND account_id = ?");
    $stmt->execute([$orderId, $_SESSION['business_account_id']]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Geen toegang tot deze factuur']);
        exit;
    }
}

$facturenDir = __DIR__ . '/../facturen';
$factuurFile = $facturenDir . '/factuur-' . $orderId . '.pdf';

if ($action === 'generate' || !file_exists($factuurFile)) {
    generateFactuur($pdo, $orderId, $factuurFile);
}

if ($action === 'view' || $action === 'generate') {
    if (file_exists($factuurFile)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="factuur-' . $orderId . '.pdf"');
        readfile($factuurFile);
    } else {
        http_response_code(500);
        echo 'Kon factuur niet genereren';
    }
}
