<?php

function getEmailStyles() {
    return '
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f5f2ed; }
        .email-wrapper { max-width: 600px; margin: 0 auto; background: #ffffff; }
        .email-header { background: linear-gradient(135deg, #8b5a2b, #5c3d1e); padding: 30px 40px; text-align: center; }
        .email-header img { max-width: 180px; height: auto; }
        .email-header h1 { color: #ffffff; margin: 15px 0 0 0; font-size: 24px; font-weight: 600; letter-spacing: 1px; }
        .email-body { padding: 40px; color: #333333; line-height: 1.6; }
        .email-body h2 { color: #5c3d1e; font-size: 22px; margin: 0 0 20px 0; }
        .email-body p { margin: 0 0 15px 0; font-size: 15px; }
        .greeting { font-size: 16px; color: #5c3d1e; }
        .info-box { background: #faf6f0; border-left: 4px solid #8b5a2b; padding: 20px; margin: 25px 0; border-radius: 0 8px 8px 0; }
        .info-box h3 { margin: 0 0 10px 0; color: #5c3d1e; font-size: 16px; }
        .info-box p { margin: 5px 0; font-size: 14px; }
        .order-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .order-table th { background: #5c3d1e; color: white; padding: 12px 15px; text-align: left; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        .order-table td { padding: 12px 15px; border-bottom: 1px solid #e8dfd2; font-size: 14px; }
        .order-table tr:last-child td { border-bottom: none; }
        .order-table .product-name { color: #333; }
        .order-table .quantity { text-align: center; color: #666; }
        .order-table .price { text-align: right; color: #333; }
        .totals-table { width: 100%; margin: 20px 0; }
        .totals-table td { padding: 8px 0; font-size: 14px; }
        .totals-table .label { color: #666; }
        .totals-table .value { text-align: right; color: #333; }
        .totals-table .total-row td { font-size: 18px; font-weight: 600; color: #5c3d1e; padding-top: 15px; border-top: 2px solid #8b5a2b; }
        .status-badge { display: inline-block; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; }
        .status-bestelbon { background: #fff3cd; color: #856404; }
        .status-betaald { background: #d4edda; color: #155724; }
        .status-geannuleerd { background: #f8d7da; color: #721c24; }
        .cta-button { display: inline-block; background: linear-gradient(135deg, #8b5a2b, #5c3d1e); color: #ffffff !important; padding: 14px 30px; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 15px; margin: 20px 0; }
        .cta-button:hover { background: #5c3d1e; }
        .credentials-box { background: #e8f4fd; border: 1px solid #b8daff; padding: 20px; border-radius: 8px; margin: 25px 0; }
        .credentials-box h3 { margin: 0 0 15px 0; color: #004085; font-size: 16px; }
        .credential-item { display: flex; margin: 10px 0; font-size: 14px; }
        .credential-label { color: #666; width: 120px; }
        .credential-value { color: #333; font-family: monospace; background: #f8f9fa; padding: 2px 8px; border-radius: 4px; }
        .warning-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px 20px; margin: 25px 0; border-radius: 0 8px 8px 0; }
        .warning-box p { margin: 0; font-size: 14px; color: #856404; }
        .success-box { background: #d4edda; border-left: 4px solid #28a745; padding: 15px 20px; margin: 25px 0; border-radius: 0 8px 8px 0; }
        .success-box p { margin: 0; font-size: 14px; color: #155724; }
        .cancelled-banner { background: #dc3545; color: white; text-align: center; padding: 15px; font-size: 18px; font-weight: 600; letter-spacing: 1px; }
        .delivery-info { display: flex; gap: 30px; margin: 20px 0; }
        .delivery-block { flex: 1; }
        .delivery-block h4 { margin: 0 0 8px 0; color: #8b5a2b; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        .delivery-block p { margin: 0; font-size: 14px; color: #333; }
        .email-footer { background: #5c3d1e; padding: 30px 40px; text-align: center; }
        .email-footer p { color: #d4c4b0; font-size: 13px; margin: 5px 0; }
        .email-footer a { color: #ffffff; text-decoration: none; }
        .divider { height: 1px; background: #e8dfd2; margin: 30px 0; }
        @media only screen and (max-width: 600px) {
            .email-body { padding: 25px; }
            .email-header { padding: 20px; }
            .delivery-info { flex-direction: column; gap: 15px; }
        }
    ';
}

function getEmailHeader($title = '') {
    $styles = getEmailStyles();
    return '<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . '</title>
    <style>' . $styles . '</style>
</head>
<body>
    <div class="email-wrapper">
        <div class="email-header">
            <h1>Bakkerij Civetta</h1>
        </div>';
}

function getEmailFooter($bedrijf = []) {
    $naam = $bedrijf['bedrijf_naam'] ?? 'Bakkerij Civetta';
    $adres = trim(($bedrijf['bedrijf_adres'] ?? '') . ', ' . ($bedrijf['bedrijf_postcode'] ?? '') . ' ' . ($bedrijf['bedrijf_plaats'] ?? ''));
    $email = $bedrijf['bedrijf_email'] ?? 'info@bakkerij-civetta.nl';
    $telefoon = $bedrijf['bedrijf_telefoon'] ?? '';
    
    return '
        <div class="email-footer">
            <p><strong>' . htmlspecialchars($naam) . '</strong></p>
            ' . ($adres ? '<p>' . htmlspecialchars($adres) . '</p>' : '') . '
            <p><a href="mailto:' . htmlspecialchars($email) . '">' . htmlspecialchars($email) . '</a>' . ($telefoon ? ' | ' . htmlspecialchars($telefoon) : '') . '</p>
            <p style="margin-top: 15px; font-size: 11px; color: #a69080;">Ambachtelijk gebakken met liefde</p>
        </div>
    </div>
</body>
</html>';
}

function formatEuro($amount) {
    return '€ ' . number_format($amount, 2, ',', '.');
}

function buildOrderConfirmationEmail($order, $items, $bedrijf, $btwTarief = 9) {
    $totalInclBtw = floatval($order['total_amount']);
    $btwBedrag = $totalInclBtw - ($totalInclBtw / (1 + $btwTarief / 100));
    $exclBtw = $totalInclBtw - $btwBedrag;
    
    $bestelbonNummer = $order['bestelbon_number'] ?? 'B' . date('Y') . '-' . str_pad($order['id'], 4, '0', STR_PAD_LEFT);
    $leverDatum = date('l j F Y', strtotime($order['delivery_date']));
    $dagNamen = ['Sunday' => 'zondag', 'Monday' => 'maandag', 'Tuesday' => 'dinsdag', 'Wednesday' => 'woensdag', 'Thursday' => 'donderdag', 'Friday' => 'vrijdag', 'Saturday' => 'zaterdag'];
    $maandNamen = ['January' => 'januari', 'February' => 'februari', 'March' => 'maart', 'April' => 'april', 'May' => 'mei', 'June' => 'juni', 'July' => 'juli', 'August' => 'augustus', 'September' => 'september', 'October' => 'oktober', 'November' => 'november', 'December' => 'december'];
    foreach ($dagNamen as $en => $nl) $leverDatum = str_replace($en, $nl, $leverDatum);
    foreach ($maandNamen as $en => $nl) $leverDatum = str_replace($en, $nl, $leverDatum);
    
    $isPaid = ($order['payment_status'] ?? '') === 'paid';
    $statusBadge = $isPaid 
        ? '<span class="status-badge status-betaald">Betaald</span>'
        : '<span class="status-badge status-bestelbon">Bestelbon</span>';
    
    $html = getEmailHeader('Bestelbevestiging ' . $bestelbonNummer);
    
    $html .= '
        <div class="email-body">
            <p class="greeting">Beste ' . htmlspecialchars($order['contactpersoon']) . ',</p>
            
            <h2>Bedankt voor uw bestelling!</h2>
            
            <p>Wij hebben uw bestelling in goede orde ontvangen. Hieronder vindt u een overzicht van uw bestelling.</p>
            
            <div class="info-box">
                <h3>Bestelgegevens</h3>
                <p><strong>Bestelbonnummer:</strong> ' . htmlspecialchars($bestelbonNummer) . '</p>
                <p><strong>Bestelnummer:</strong> #' . $order['id'] . '</p>
                <p><strong>Status:</strong> ' . $statusBadge . '</p>
            </div>
            
            <div class="delivery-info">
                <div class="delivery-block">
                    <h4>Leverdatum</h4>
                    <p><strong>' . $leverDatum . '</strong></p>
                </div>
                <div class="delivery-block">
                    <h4>Leveradres</h4>
                    <p>' . htmlspecialchars($order['bedrijfsnaam']) . '<br>
                    ' . htmlspecialchars($order['delivery_adres'] ?? $order['adres']) . '<br>
                    ' . htmlspecialchars(($order['delivery_postcode'] ?? $order['postcode']) . ' ' . ($order['delivery_plaats'] ?? $order['plaats'])) . '</p>
                </div>
            </div>
            
            <table class="order-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="text-align: center;">Aantal</th>
                        <th style="text-align: right;">Prijs</th>
                        <th style="text-align: right;">Totaal</th>
                    </tr>
                </thead>
                <tbody>';
    
    foreach ($items as $item) {
        $lineTotal = $item['quantity'] * $item['unit_price'];
        $html .= '
                    <tr>
                        <td class="product-name">' . htmlspecialchars($item['product_name']) . '</td>
                        <td class="quantity">' . $item['quantity'] . '</td>
                        <td class="price">' . formatEuro($item['unit_price']) . '</td>
                        <td class="price">' . formatEuro($lineTotal) . '</td>
                    </tr>';
    }
    
    $html .= '
                </tbody>
            </table>
            
            <table class="totals-table">
                <tr>
                    <td class="label">Subtotaal excl. BTW</td>
                    <td class="value">' . formatEuro($exclBtw) . '</td>
                </tr>
                <tr>
                    <td class="label">BTW (' . $btwTarief . '%)</td>
                    <td class="value">' . formatEuro($btwBedrag) . '</td>
                </tr>
                <tr class="total-row">
                    <td class="label">Totaal incl. BTW</td>
                    <td class="value">' . formatEuro($totalInclBtw) . '</td>
                </tr>
            </table>';
    
    if (!$isPaid) {
        $editDeadline = '';
        if (!empty($order['can_be_edited_until'])) {
            $deadline = new DateTime($order['can_be_edited_until']);
            $editDeadline = $deadline->format('j F Y \o\m H:i');
            foreach ($maandNamen as $en => $nl) $editDeadline = str_replace($en, $nl, $editDeadline);
        }
        
        $html .= '
            <div class="warning-box">
                <p><strong>Let op:</strong> Dit is een bestelbon, geen factuur. De factuur ontvangt u na levering.</p>
                ' . ($editDeadline ? '<p style="margin-top: 10px;">U kunt deze bestelling nog wijzigen tot <strong>' . $editDeadline . '</strong>.</p>' : '') . '
            </div>
            
            <p style="text-align: center;">
                <a href="https://bakkerij-civetta.nl/mijn-bestellingen.html" class="cta-button">Bekijk bestelling in dashboard</a>
            </p>';
    } else {
        $html .= '
            <div class="success-box">
                <p><strong>Betaling ontvangen!</strong> Uw bestelling is betaald en wordt op de aangegeven datum geleverd.</p>
            </div>';
    }
    
    if (!empty($order['notes'])) {
        $html .= '
            <div class="info-box">
                <h3>Opmerkingen</h3>
                <p>' . nl2br(htmlspecialchars($order['notes'])) . '</p>
            </div>';
    }
    
    $html .= '
            <div class="divider"></div>
            
            <p>Heeft u vragen over uw bestelling? Neem gerust contact met ons op.</p>
            <p>Met vriendelijke groet,<br><strong>Bakkerij Civetta</strong></p>
        </div>';
    
    $html .= getEmailFooter($bedrijf);
    
    return $html;
}

function buildCancellationEmail($order, $items, $bedrijf, $btwTarief = 9) {
    $totalInclBtw = floatval($order['total_amount']);
    $bestelbonNummer = $order['bestelbon_number'] ?? 'B' . date('Y') . '-' . str_pad($order['id'], 4, '0', STR_PAD_LEFT);
    $leverDatum = date('j F Y', strtotime($order['delivery_date']));
    $maandNamen = ['January' => 'januari', 'February' => 'februari', 'March' => 'maart', 'April' => 'april', 'May' => 'mei', 'June' => 'juni', 'July' => 'juli', 'August' => 'augustus', 'September' => 'september', 'October' => 'oktober', 'November' => 'november', 'December' => 'december'];
    foreach ($maandNamen as $en => $nl) $leverDatum = str_replace($en, $nl, $leverDatum);
    
    $hasRefund = !empty($order['mollie_refund_id']);
    
    $html = getEmailHeader('Annulering ' . $bestelbonNummer);
    
    $html .= '
        <div class="cancelled-banner">BESTELLING GEANNULEERD</div>
        <div class="email-body">
            <p class="greeting">Beste ' . htmlspecialchars($order['contactpersoon']) . ',</p>
            
            <h2>Uw bestelling is geannuleerd</h2>
            
            <p>Hierbij bevestigen wij de annulering van uw bestelling. De bestelling zal niet worden geleverd.</p>
            
            <div class="info-box">
                <h3>Geannuleerde bestelling</h3>
                <p><strong>Bestelbonnummer:</strong> ' . htmlspecialchars($bestelbonNummer) . '</p>
                <p><strong>Bestelnummer:</strong> #' . $order['id'] . '</p>
                <p><strong>Oorspronkelijke leverdatum:</strong> ' . $leverDatum . '</p>
                <p><strong>Totaalbedrag:</strong> ' . formatEuro($totalInclBtw) . '</p>
                <p><strong>Status:</strong> <span class="status-badge status-geannuleerd">Geannuleerd</span></p>
            </div>';
    
    if ($hasRefund) {
        $html .= '
            <div class="success-box">
                <p><strong>Terugbetaling</strong></p>
                <p style="margin-top: 10px;">Het bedrag van ' . formatEuro($totalInclBtw) . ' wordt teruggestort op uw rekening. Dit kan enkele werkdagen duren.</p>
            </div>';
    }
    
    $html .= '
            <table class="order-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="text-align: center;">Aantal</th>
                        <th style="text-align: right;">Totaal</th>
                    </tr>
                </thead>
                <tbody>';
    
    foreach ($items as $item) {
        $lineTotal = $item['quantity'] * $item['unit_price'];
        $html .= '
                    <tr style="color: #999; text-decoration: line-through;">
                        <td>' . htmlspecialchars($item['product_name']) . '</td>
                        <td style="text-align: center;">' . $item['quantity'] . '</td>
                        <td style="text-align: right;">' . formatEuro($lineTotal) . '</td>
                    </tr>';
    }
    
    $html .= '
                </tbody>
            </table>
            
            <div class="divider"></div>
            
            <p>Heeft u vragen over deze annulering? Neem gerust contact met ons op.</p>
            <p>Met vriendelijke groet,<br><strong>Bakkerij Civetta</strong></p>
        </div>';
    
    $html .= getEmailFooter($bedrijf);
    
    return $html;
}

function buildAccountApprovedEmail($account, $password, $bedrijf = []) {
    $html = getEmailHeader('Uw zakelijk account is goedgekeurd');
    
    $html .= '
        <div class="email-body">
            <p class="greeting">Beste ' . htmlspecialchars($account['contactpersoon']) . ',</p>
            
            <h2>Welkom bij Bakkerij Civetta!</h2>
            
            <p>Goed nieuws! Uw zakelijke accountaanvraag voor <strong>' . htmlspecialchars($account['bedrijfsnaam']) . '</strong> is goedgekeurd.</p>
            
            <p>U kunt nu inloggen op ons zakelijk portaal en direct beginnen met bestellen van ons ambachtelijke brood en gebak.</p>
            
            <div class="credentials-box">
                <h3>Uw inloggegevens</h3>
                <table style="width: 100%;">
                    <tr>
                        <td class="credential-label">Gebruikersnaam:</td>
                        <td><span class="credential-value">' . htmlspecialchars($account['email']) . '</span></td>
                    </tr>
                    <tr>
                        <td class="credential-label">Wachtwoord:</td>
                        <td><span class="credential-value">' . htmlspecialchars($password) . '</span></td>
                    </tr>
                </table>
            </div>
            
            <p style="text-align: center;">
                <a href="https://bakkerij-civetta.nl/login-bedrijven.html" class="cta-button">Nu inloggen</a>
            </p>
            
            <div class="warning-box">
                <p><strong>Tip:</strong> Wij raden u aan om uw wachtwoord te wijzigen na de eerste keer inloggen.</p>
            </div>
            
            <div class="info-box">
                <h3>Wat kunt u doen in uw dashboard?</h3>
                <p>• Bestellingen plaatsen voor uw bedrijf</p>
                <p>• Favoriete bestellingen opslaan</p>
                <p>• Terugkerende bestellingen instellen</p>
                <p>• Uw bestelgeschiedenis bekijken</p>
                <p>• Facturen downloaden</p>
            </div>
            
            <div class="divider"></div>
            
            <p>Heeft u vragen? Wij helpen u graag!</p>
            <p>Met vriendelijke groet,<br><strong>Bakkerij Civetta</strong></p>
        </div>';
    
    $html .= getEmailFooter($bedrijf);
    
    return $html;
}

function buildPasswordResetEmail($account, $password, $bedrijf = []) {
    $html = getEmailHeader('Nieuw wachtwoord');
    
    $html .= '
        <div class="email-body">
            <p class="greeting">Beste ' . htmlspecialchars($account['contactpersoon']) . ',</p>
            
            <h2>Nieuw wachtwoord aangemaakt</h2>
            
            <p>Er is een nieuw wachtwoord aangemaakt voor uw zakelijke account bij Bakkerij Civetta.</p>
            
            <div class="credentials-box">
                <h3>Uw nieuwe inloggegevens</h3>
                <table style="width: 100%;">
                    <tr>
                        <td class="credential-label">Gebruikersnaam:</td>
                        <td><span class="credential-value">' . htmlspecialchars($account['email']) . '</span></td>
                    </tr>
                    <tr>
                        <td class="credential-label">Wachtwoord:</td>
                        <td><span class="credential-value">' . htmlspecialchars($password) . '</span></td>
                    </tr>
                </table>
            </div>
            
            <p style="text-align: center;">
                <a href="https://bakkerij-civetta.nl/login-bedrijven.html" class="cta-button">Nu inloggen</a>
            </p>
            
            <div class="warning-box">
                <p><strong>Belangrijk:</strong> Wij raden u aan om uw wachtwoord direct te wijzigen na het inloggen.</p>
            </div>
            
            <div class="divider"></div>
            
            <p>Heeft u dit wachtwoord niet aangevraagd? Neem dan direct contact met ons op.</p>
            <p>Met vriendelijke groet,<br><strong>Bakkerij Civetta</strong></p>
        </div>';
    
    $html .= getEmailFooter($bedrijf);
    
    return $html;
}

function buildRecurringConfirmationEmail($order, $items, $upcomingOrders, $bedrijf, $btwTarief = 9) {
    $frequentieLabels = ['weekly' => 'Wekelijks', 'biweekly' => 'Tweewekelijks', 'monthly' => 'Maandelijks'];
    $frequentie = $frequentieLabels[$order['recurring_frequency']] ?? $order['recurring_frequency'];
    
    $maandNamen = ['January' => 'januari', 'February' => 'februari', 'March' => 'maart', 'April' => 'april', 'May' => 'mei', 'June' => 'juni', 'July' => 'juli', 'August' => 'augustus', 'September' => 'september', 'October' => 'oktober', 'November' => 'november', 'December' => 'december'];
    
    $eersteLevertDatum = date('j F Y', strtotime($order['delivery_date']));
    foreach ($maandNamen as $en => $nl) $eersteLevertDatum = str_replace($en, $nl, $eersteLevertDatum);
    
    $orderTotal = 0;
    foreach ($items as $item) {
        $orderTotal += $item['quantity'] * $item['unit_price'];
    }
    $totalInclBtw = $orderTotal * (1 + $btwTarief / 100);
    
    $html = getEmailHeader('Terugkerende bestelling bevestigd');
    
    $html .= '
        <div class="email-body">
            <p class="greeting">Beste ' . htmlspecialchars($order['contactpersoon']) . ',</p>
            
            <h2>Uw terugkerende bestelling is bevestigd!</h2>
            
            <p>Bedankt! Uw terugkerende bestelling is succesvol ingesteld. U ontvangt nu automatisch uw bestelling volgens onderstaand schema.</p>
            
            <div class="info-box">
                <h3>' . htmlspecialchars($order['recurring_name'] ?? 'Terugkerende bestelling') . '</h3>
                <p><strong>Frequentie:</strong> ' . $frequentie . '</p>
                <p><strong>Eerste levering:</strong> ' . $eersteLevertDatum . '</p>
                <p><strong>Bedrag per levering:</strong> ' . formatEuro($totalInclBtw) . ' incl. BTW</p>
            </div>
            
            <h3 style="color: #5c3d1e; margin-top: 30px;">Producten per levering</h3>
            <table class="order-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="text-align: center;">Aantal</th>
                        <th style="text-align: right;">Prijs</th>
                    </tr>
                </thead>
                <tbody>';
    
    foreach ($items as $item) {
        $html .= '
                    <tr>
                        <td class="product-name">' . htmlspecialchars($item['product_name']) . '</td>
                        <td class="quantity">' . $item['quantity'] . '</td>
                        <td class="price">' . formatEuro($item['unit_price']) . '</td>
                    </tr>';
    }
    
    $html .= '
                </tbody>
            </table>
            
            <h3 style="color: #5c3d1e; margin-top: 30px;">Ingeplande leveringen</h3>
            <table class="order-table">
                <thead>
                    <tr>
                        <th>Leverdatum</th>
                        <th style="text-align: right;">Bedrag</th>
                    </tr>
                </thead>
                <tbody>';
    
    $totalPeriode = 0;
    foreach (array_slice($upcomingOrders, 0, 12) as $upcoming) {
        $datum = date('j F Y', strtotime($upcoming['delivery_date']));
        foreach ($maandNamen as $en => $nl) $datum = str_replace($en, $nl, $datum);
        $totalPeriode += floatval($upcoming['total_amount']);
        $html .= '
                    <tr>
                        <td>' . $datum . '</td>
                        <td style="text-align: right;">' . formatEuro($upcoming['total_amount']) . '</td>
                    </tr>';
    }
    
    if (count($upcomingOrders) > 12) {
        $html .= '
                    <tr>
                        <td colspan="2" style="text-align: center; color: #888; font-style: italic;">+ ' . (count($upcomingOrders) - 12) . ' meer leveringen...</td>
                    </tr>';
    }
    
    $html .= '
                </tbody>
            </table>
            
            <div class="info-box" style="background: #e8f4fd; border-left-color: #007bff;">
                <p><strong>Periode totaal (' . count($upcomingOrders) . ' leveringen):</strong> ' . formatEuro($totalPeriode) . '</p>
            </div>
            
            <div class="warning-box">
                <p><strong>Let op:</strong> 2 weken voor het einde van deze periode vragen wij u om de bestelling te herbevestigen.</p>
                <p style="margin-top: 10px;">Individuele leveringen kunt u wijzigen via uw dashboard.</p>
            </div>
            
            <p style="text-align: center;">
                <a href="https://bakkerij-civetta.nl/mijn-bestellingen.html" class="cta-button">Bekijk in dashboard</a>
            </p>
            
            <div class="divider"></div>
            
            <p>Heeft u vragen? Wij helpen u graag!</p>
            <p>Met vriendelijke groet,<br><strong>Bakkerij Civetta</strong></p>
        </div>';
    
    $html .= getEmailFooter($bedrijf);
    
    return $html;
}

function buildAdminOrderNotificationEmail($order, $items, $isNew = true, $bedrijf = []) {
    $action = $isNew ? 'Nieuwe bestelling' : 'Bestelling gewijzigd';
    $leverDatum = date('j F Y', strtotime($order['delivery_date']));
    $maandNamen = ['January' => 'januari', 'February' => 'februari', 'March' => 'maart', 'April' => 'april', 'May' => 'mei', 'June' => 'juni', 'July' => 'juli', 'August' => 'augustus', 'September' => 'september', 'October' => 'oktober', 'November' => 'november', 'December' => 'december'];
    foreach ($maandNamen as $en => $nl) $leverDatum = str_replace($en, $nl, $leverDatum);
    
    $html = getEmailHeader($action . ' #' . $order['id']);
    
    $html .= '
        <div class="email-body">
            <h2>' . $action . '</h2>
            
            <div class="info-box">
                <h3>Klantgegevens</h3>
                <p><strong>Bedrijf:</strong> ' . htmlspecialchars($order['bedrijfsnaam']) . '</p>
                <p><strong>Contactpersoon:</strong> ' . htmlspecialchars($order['contactpersoon']) . '</p>
                <p><strong>Email:</strong> ' . htmlspecialchars($order['email']) . '</p>
            </div>
            
            <div class="delivery-info">
                <div class="delivery-block">
                    <h4>Bestelnummer</h4>
                    <p><strong>#' . $order['id'] . '</strong></p>
                </div>
                <div class="delivery-block">
                    <h4>Leverdatum</h4>
                    <p><strong>' . $leverDatum . '</strong></p>
                </div>
                <div class="delivery-block">
                    <h4>Betaalwijze</h4>
                    <p><strong>' . (($order['payment_type'] ?? 'factuur') === 'ideal' ? 'iDEAL' : 'Factuur') . '</strong></p>
                </div>
            </div>
            
            <table class="order-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="text-align: center;">Aantal</th>
                        <th style="text-align: right;">Totaal</th>
                    </tr>
                </thead>
                <tbody>';
    
    foreach ($items as $item) {
        $lineTotal = $item['quantity'] * $item['unit_price'];
        $html .= '
                    <tr>
                        <td>' . htmlspecialchars($item['product_name']) . '</td>
                        <td style="text-align: center;">' . $item['quantity'] . '</td>
                        <td style="text-align: right;">' . formatEuro($lineTotal) . '</td>
                    </tr>';
    }
    
    $html .= '
                </tbody>
            </table>
            
            <table class="totals-table">
                <tr class="total-row">
                    <td class="label">Totaal incl. BTW</td>
                    <td class="value">' . formatEuro($order['total_amount']) . '</td>
                </tr>
            </table>';
    
    if (!empty($order['notes'])) {
        $html .= '
            <div class="warning-box">
                <p><strong>Opmerking van klant:</strong></p>
                <p style="margin-top: 10px;">' . nl2br(htmlspecialchars($order['notes'])) . '</p>
            </div>';
    }
    
    $html .= '
            <p style="text-align: center;">
                <a href="https://bakkerij-civetta.nl/admin/orders.php" class="cta-button">Bekijk in admin</a>
            </p>
        </div>';
    
    $html .= getEmailFooter($bedrijf);
    
    return $html;
}

function buildInvoiceEmailBody($accountData, $orderItems, $invoiceNumber = null, $deliveryDate = null, $btwTarief = 9) {
    $contactpersoon = $accountData['contactpersoon'] ?? 'klant';
    $bedrijfsnaam = $accountData['bedrijfsnaam'] ?? '';
    
    $subtotal = 0;
    foreach ($orderItems as $item) {
        $subtotal += $item['quantity'] * $item['unit_price'];
    }
    $totalInclBtw = $subtotal;
    $btwBedrag = $totalInclBtw - ($totalInclBtw / (1 + $btwTarief / 100));
    $exclBtw = $totalInclBtw - $btwBedrag;
    
    $maandNamen = ['January' => 'januari', 'February' => 'februari', 'March' => 'maart', 'April' => 'april', 'May' => 'mei', 'June' => 'juni', 'July' => 'juli', 'August' => 'augustus', 'September' => 'september', 'October' => 'oktober', 'November' => 'november', 'December' => 'december'];
    
    $leverDatumStr = '';
    if ($deliveryDate) {
        $leverDatumStr = date('j F Y', strtotime($deliveryDate));
        foreach ($maandNamen as $en => $nl) $leverDatumStr = str_replace($en, $nl, $leverDatumStr);
    }
    
    $html = getEmailHeader('Factuur' . ($invoiceNumber ? ' ' . $invoiceNumber : ''));
    
    $html .= '
        <div class="email-body">
            <p class="greeting">Beste ' . htmlspecialchars($contactpersoon) . ',</p>
            
            <h2>Uw factuur van Bakkerij Civetta</h2>
            
            <p>Hartelijk dank voor uw bestelling! Bijgaand vindt u uw factuur.</p>';
    
    if ($invoiceNumber || $leverDatumStr) {
        $html .= '
            <div class="info-box">
                <h3>Factuurgegevens</h3>';
        if ($invoiceNumber) {
            $html .= '<p><strong>Factuurnummer:</strong> ' . htmlspecialchars($invoiceNumber) . '</p>';
        }
        if ($leverDatumStr) {
            $html .= '<p><strong>Leverdatum:</strong> ' . $leverDatumStr . '</p>';
        }
        if ($bedrijfsnaam) {
            $html .= '<p><strong>Bedrijf:</strong> ' . htmlspecialchars($bedrijfsnaam) . '</p>';
        }
        $html .= '
            </div>';
    }
    
    $html .= '
            <table class="order-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="text-align: center;">Aantal</th>
                        <th style="text-align: right;">Prijs</th>
                        <th style="text-align: right;">Totaal</th>
                    </tr>
                </thead>
                <tbody>';
    
    foreach ($orderItems as $item) {
        $lineTotal = $item['quantity'] * $item['unit_price'];
        $html .= '
                    <tr>
                        <td class="product-name">' . htmlspecialchars($item['product_name']) . '</td>
                        <td class="quantity">' . $item['quantity'] . '</td>
                        <td class="price">' . formatEuro($item['unit_price']) . '</td>
                        <td class="price">' . formatEuro($lineTotal) . '</td>
                    </tr>';
    }
    
    $html .= '
                </tbody>
            </table>
            
            <table class="totals-table">
                <tr>
                    <td class="label">Subtotaal excl. BTW</td>
                    <td class="value">' . formatEuro($exclBtw) . '</td>
                </tr>
                <tr>
                    <td class="label">BTW (' . $btwTarief . '%)</td>
                    <td class="value">' . formatEuro($btwBedrag) . '</td>
                </tr>
                <tr class="total-row">
                    <td class="label">Totaal incl. BTW</td>
                    <td class="value">' . formatEuro($totalInclBtw) . '</td>
                </tr>
            </table>
            
            <div class="info-box" style="background: #e8f4fd; border-left-color: #007bff;">
                <p><strong>De factuur is als PDF bijgevoegd bij deze email.</strong></p>
                <p style="margin-top: 10px;">Betaaltermijn: 14 dagen</p>
            </div>
            
            <p style="text-align: center;">
                <a href="https://bakkerij-civetta.nl/mijn-bestellingen.html" class="cta-button">Bekijk uw bestellingen</a>
            </p>
            
            <div class="divider"></div>
            
            <p>Heeft u vragen over deze factuur? Neem gerust contact met ons op.</p>
            <p>Met vriendelijke groet,<br><strong>Bakkerij Civetta</strong></p>
        </div>';
    
    $html .= getEmailFooter([]);
    
    return $html;
}

function sendHtmlEmail($to, $subject, $htmlBody, $attachments = [], $replyTo = null) {
    $boundary = md5(uniqid(time()));
    
    $headers = "From: Bakkerij Civetta <noreply@bakkerij-civetta.nl>\r\n";
    if ($replyTo) {
        $headers .= "Reply-To: $replyTo\r\n";
    } else {
        $headers .= "Reply-To: info@bakkerij-civetta.nl\r\n";
    }
    $headers .= "MIME-Version: 1.0\r\n";
    
    if (!empty($attachments)) {
        $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
        
        $message = "--$boundary\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $htmlBody . "\r\n";
        
        foreach ($attachments as $attachment) {
            if (file_exists($attachment['path'])) {
                $filename = $attachment['name'] ?? basename($attachment['path']);
                $message .= "--$boundary\r\n";
                $message .= "Content-Type: " . ($attachment['type'] ?? 'application/octet-stream') . "; name=\"$filename\"\r\n";
                $message .= "Content-Transfer-Encoding: base64\r\n";
                $message .= "Content-Disposition: attachment; filename=\"$filename\"\r\n\r\n";
                $message .= chunk_split(base64_encode(file_get_contents($attachment['path']))) . "\r\n";
            }
        }
        
        $message .= "--$boundary--";
    } else {
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message = $htmlBody;
    }
    
    return @mail($to, $subject, $message, $headers);
}
