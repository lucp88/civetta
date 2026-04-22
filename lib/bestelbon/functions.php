<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/delivery-status.php';

// Load DomPDF via Composer autoloader
require_once __DIR__ . '/../../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

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

// ── Template rendering ───────────────────────────────────────────────────────

function renderTemplate($templateFile, $vars) {
    $html = file_get_contents(__DIR__ . '/templates/' . $templateFile);
    foreach ($vars as $key => $value) {
        $html = str_replace('{{' . $key . '}}', $value, $html);
    }
    // Remove any unreplaced conditionals
    $html = preg_replace('/\{\{#if \w+\}\}.*?\{\{\/if\}\}/s', '', $html);
    return $html;
}

function renderPdf($html) {
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'Helvetica');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    return $dompdf;
}

function buildItemsRows($items) {
    $rows = '';
    foreach ($items as $item) {
        $lineTotal = $item['quantity'] * $item['unit_price'];
        $rows .= '<tr>';
        $rows .= '<td>' . h($item['product_name']) . '</td>';
        $rows .= '<td>' . (int)$item['quantity'] . '</td>';
        $rows .= '<td>' . formatEuro($item['unit_price']) . '</td>';
        $rows .= '<td>' . formatEuro($lineTotal) . '</td>';
        $rows .= '</tr>';
    }
    return $rows;
}

function getBedrijfVars($bedrijf) {
    $naam = $bedrijf['bedrijf_naam'] ?: 'Bakkerij Civetta';
    $plaats = ($bedrijf['bedrijf_postcode'] || $bedrijf['bedrijf_plaats'])
        ? trim($bedrijf['bedrijf_postcode'] . ' ' . $bedrijf['bedrijf_plaats'])
        : 'Leersum, Utrecht';
    $email = $bedrijf['bedrijf_email'] ?: 'info@bakkerij-civetta.nl';
    return ['bedrijf_naam' => h($naam), 'bedrijf_plaats' => h($plaats), 'bedrijf_email' => h($email)];
}

function calculateBtw($totalInclBtw, $btwTarief) {
    $btwBedrag = $totalInclBtw - ($totalInclBtw / (1 + $btwTarief / 100));
    $exclBtw = $totalInclBtw - $btwBedrag;
    return ['excl' => $exclBtw, 'btw' => $btwBedrag, 'incl' => $totalInclBtw];
}

// ── Bestelbon generators ─────────────────────────────────────────────────────

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

    $btwTarief = floatval(getSetting($pdo, 'btw_tarief', '9'));
    $bedrijf = getBedrijfsGegevens($pdo);
    $deadlineHours = getWijzigDeadlineUren($pdo);

    $totals = calculateBtw(floatval($order['total_amount']), $btwTarief);
    $bestelbonNummer = $order['bestelbon_number'] ?: generateBestelbonNumber($pdo, $orderId);

    $deliveryAdres = $order['delivery_same_as_business'] ? $order['adres'] : ($order['delivery_adres'] ?: $order['adres']);
    $deliveryPostcode = $order['delivery_same_as_business'] ? $order['postcode'] : ($order['delivery_postcode'] ?: $order['postcode']);
    $deliveryPlaats = $order['delivery_same_as_business'] ? $order['plaats'] : ($order['delivery_plaats'] ?: $order['plaats']);

    $editDeadline = calculateEditDeadline($order['delivery_date'], $deadlineHours);
    $canEdit = canOrderBeEdited($pdo, $orderId);

    // Build status block
    $statusBlock = '';
    if (!empty($order['is_internal'])) {
        $statusBlock = '<div class="status-box status-internal">INTERNE BESTELBON - GEEN FACTUUR</div>';
        $statusBlock .= '<p class="info-text">Dit is een interne bestelling. Er wordt geen factuur aangemaakt.</p>';
    } elseif (!empty($order['payment_type']) && $order['payment_type'] === 'saldo') {
        $statusBlock = '<div class="status-box status-paid-saldo">BETAALD MET SALDO</div>';
        $statusBlock .= '<p class="info-text">Deze bestelling is betaald met uw tegoed. Er wordt geen separate factuur aangemaakt.</p>';
    } else {
        $statusBlock = '<div class="status-box status-pending">BESTELBON - FACTUUR VOLGT NA LEVERING</div>';
        if ($canEdit) {
            $statusBlock .= '<p class="info-text">Deze bestelling kan nog gewijzigd worden tot ' . h($editDeadline->format('d-m-Y H:i')) . '.</p>';
            $statusBlock .= '<p class="info-text">Wijzigingen kunt u doorvoeren via uw dashboard op onze website.</p>';
        } else {
            $statusBlock .= '<p class="info-text">De deadline voor wijzigingen is verstreken. Deze bestelling kan niet meer worden aangepast.</p>';
        }
        $statusBlock .= '<p class="info-text">Wilt u direct betalen? Dat kan via het dashboard in uw account.</p>';
        $statusBlock .= '<p class="info-text">Na levering ontvangt u de offici&euml;le factuur.</p>';
    }

    // Handle {{#if is_internal}} block
    $isInternalBlock = !empty($order['is_internal'])
        ? '<div class="status-box status-internal">INTERNE BESTELLING</div>'
        : '';

    $vars = array_merge(getBedrijfVars($bedrijf), [
        'bestelbon_nummer' => h($bestelbonNummer),
        'bestel_datum' => date('d-m-Y', strtotime($order['created_at'])),
        'lever_datum' => date('d-m-Y', strtotime($order['delivery_date'])),
        'order_id' => $orderId,
        'klant_bedrijfsnaam' => h($order['bedrijfsnaam']),
        'klant_contactpersoon' => h($order['contactpersoon']),
        'klant_email' => h($order['email']),
        'delivery_adres' => h($deliveryAdres),
        'delivery_postcode' => h($deliveryPostcode),
        'delivery_plaats' => h($deliveryPlaats),
        'items_rows' => buildItemsRows($items),
        'subtotaal' => formatEuro($totals['excl']),
        'btw_tarief' => $btwTarief,
        'btw_bedrag' => formatEuro($totals['btw']),
        'totaal' => formatEuro($totals['incl']),
        'status_block' => $statusBlock,
    ]);

    $html = renderTemplate('bestelbon.html', $vars);
    // Replace the conditional is_internal block manually
    if (!empty($order['is_internal'])) {
        $html = preg_replace('/\{\{#if is_internal\}\}(.*?)\{\{\/if\}\}/s', '$1', $html);
    }

    $dompdf = renderPdf($html);

    if ($outputPath) {
        file_put_contents($outputPath, $dompdf->output());
        return $outputPath;
    }
    return $dompdf;
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

    $btwTarief = floatval(getSetting($pdo, 'btw_tarief', '9'));
    $bedrijf = getBedrijfsGegevens($pdo);
    $deadlineHours = getWijzigDeadlineUren($pdo);

    $totalInclBtw = 0;
    foreach ($items as $item) {
        $totalInclBtw += $item['quantity'] * $item['unit_price'];
    }
    $totals = calculateBtw($totalInclBtw, $btwTarief);

    $frequentieLabels = ['weekly' => 'Wekelijks', 'biweekly' => 'Tweewekelijks', 'monthly' => 'Maandelijks'];

    // Build schedule rows
    $statusLabels = ['geplaatst' => 'Geplaatst', 'wordt_bereid' => 'Wordt bereid', 'wordt_vandaag_geleverd' => 'Vandaag', 'afgeleverd' => 'Afgeleverd'];
    $scheduleRows = '';
    foreach ($upcomingOrders as $upcoming) {
        $editDeadline = calculateEditDeadline($upcoming['delivery_date'], $deadlineHours);
        $canEdit = (new DateTime()) < $editDeadline;
        $scheduleRows .= '<tr>';
        $scheduleRows .= '<td>' . date('d-m-Y', strtotime($upcoming['delivery_date'])) . '</td>';
        $scheduleRows .= '<td>' . formatEuro($upcoming['total_amount']) . '</td>';
        $scheduleRows .= '<td>' . ($statusLabels[$upcoming['order_status']] ?? $upcoming['order_status']) . '</td>';
        $scheduleRows .= '<td>' . ($canEdit ? $editDeadline->format('d-m H:i') : '&mdash;') . '</td>';
        $scheduleRows .= '</tr>';
    }

    $vars = array_merge(getBedrijfVars($bedrijf), [
        'klant_bedrijfsnaam' => h($firstOrder['bedrijfsnaam']),
        'klant_contactpersoon' => h($firstOrder['contactpersoon']),
        'recurring_naam' => h($firstOrder['recurring_name'] ?: 'Terugkerende bestelling'),
        'frequentie' => $frequentieLabels[$firstOrder['recurring_frequency']] ?? $firstOrder['recurring_frequency'],
        'items_rows' => buildItemsRows($items),
        'subtotaal' => formatEuro($totals['excl']),
        'btw_tarief' => $btwTarief,
        'btw_bedrag' => formatEuro($totals['btw']),
        'totaal' => formatEuro($totals['incl']),
        'schedule_rows' => $scheduleRows,
        'deadline_uren' => $deadlineHours,
    ]);

    $dompdf = renderPdf(renderTemplate('bestelbon-recurring.html', $vars));

    if ($outputPath) {
        file_put_contents($outputPath, $dompdf->output());
        return $outputPath;
    }
    return $dompdf;
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

    $btwTarief = floatval(getSetting($pdo, 'btw_tarief', '9'));
    $bedrijf = getBedrijfsGegevens($pdo);
    $totals = calculateBtw(floatval($order['total_amount']), $btwTarief);
    $bestelbonNummer = $order['bestelbon_number'] ?: generateBestelbonNumber($pdo, $orderId);

    $vars = array_merge(getBedrijfVars($bedrijf), [
        'bestelbon_nummer' => h($bestelbonNummer),
        'bestel_datum' => date('d-m-Y', strtotime($order['created_at'])),
        'lever_datum' => date('d-m-Y', strtotime($order['delivery_date'])),
        'annulerings_datum' => date('d-m-Y H:i'),
        'klant_bedrijfsnaam' => h($order['bedrijfsnaam']),
        'klant_contactpersoon' => h($order['contactpersoon']),
        'klant_email' => h($order['email']),
        'items_rows' => buildItemsRows($items),
        'subtotaal' => formatEuro($totals['excl']),
        'btw_tarief' => $btwTarief,
        'btw_bedrag' => formatEuro($totals['btw']),
        'totaal' => formatEuro($totals['incl']),
    ]);

    $dompdf = renderPdf(renderTemplate('bestelbon-cancelled.html', $vars));

    if ($outputPath) {
        file_put_contents($outputPath, $dompdf->output());
        return $outputPath;
    }
    return $dompdf;
}

// ── Email senders (unchanged API, use email-templates for HTML body) ─────────

function sendBestelbonEmail($pdo, $orderId) {
    require_once __DIR__ . '/../../api/email-templates.php';

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

    $btwTarief = floatval(getSetting($pdo, 'btw_tarief', '9'));
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
    $htmlBody = buildOrderConfirmationEmail($order, $items, $bedrijf, $btwTarief, $pdo);

    $to = $order['email'];
    $subject = getEmailSubject($pdo, 'bestelbevestiging', "Bestelbevestiging $bestelbonNummer - Bakkerij Civetta");

    $attachments = [
        ['path' => $bestelbonFile, 'name' => "bestelbon-$orderId.pdf", 'type' => 'application/pdf']
    ];

    return sendHtmlEmail($to, $subject, $htmlBody, $attachments);
}

function sendRecurringBestelbonEmail($pdo, $recurringGroupId) {
    require_once __DIR__ . '/../../api/email-templates.php';

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

    $btwTarief = floatval(getSetting($pdo, 'btw_tarief', '9'));
    $bedrijf = getBedrijfsGegevens($pdo);
    $htmlBody = buildRecurringConfirmationEmail($order, $items, $upcomingOrders, $bedrijf, $btwTarief, $pdo);

    $to = $order['email'];
    $subject = getEmailSubject($pdo, 'terugkerend_bevestiging', 'Terugkerende bestelling bevestigd - Bakkerij Civetta');

    $attachments = [
        ['path' => $bestelbonFile, 'name' => 'overzicht-terugkerende-bestelling.pdf', 'type' => 'application/pdf']
    ];

    return sendHtmlEmail($to, $subject, $htmlBody, $attachments);
}

function sendCancellationEmail($pdo, $orderId) {
    require_once __DIR__ . '/../../api/email-templates.php';

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

    $btwTarief = floatval(getSetting($pdo, 'btw_tarief', '9'));
    $bestelbonNummer = $order['bestelbon_number'] ?: generateBestelbonNumber($pdo, $orderId);
    $bedrijf = getBedrijfsGegevens($pdo);
    $htmlBody = buildCancellationEmail($order, $items, $bedrijf, $btwTarief, $pdo);

    $to = $order['email'];
    $subject = getEmailSubject($pdo, 'annulering', "Annulering bestelling $bestelbonNummer - Bakkerij Civetta");

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
    require_once __DIR__ . '/../../api/email-templates.php';

    $bedrijf = getBedrijfsGegevens($pdo);
    $htmlBody = buildRecurringPauseEmail($accountInfo, $affectedOrders, $unaffectedOrders, $isPause, $bedrijf, $pdo);

    $to = $accountInfo['email'];
    $recurringName = $accountInfo['recurring_name'] ?? 'Terugkerende bestelling';

    $subject = $isPause
        ? getEmailSubject($pdo, 'terugkerend_gepauzeerd', "Leveringen gepauzeerd: $recurringName - Bakkerij Civetta")
        : getEmailSubject($pdo, 'terugkerend_hervat', "Leveringen hervat: $recurringName - Bakkerij Civetta");

    $customerSent = sendHtmlEmail($to, $subject, $htmlBody);

    $adminSubject = $isPause
        ? "Terugkerende bestelling gepauzeerd: {$accountInfo['bedrijfsnaam']}"
        : "Terugkerende bestelling hervat: {$accountInfo['bedrijfsnaam']}";
    sendHtmlEmail('info@bakkerij-civetta.nl', $adminSubject, $htmlBody, [], $accountInfo['email']);

    return $customerSent;
}

function sendRecurringUpdateEmail($pdo, $recurringGroupId, $oldItems, $newItems) {
    require_once __DIR__ . '/../../api/email-templates.php';

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

    $btwTarief = floatval(getSetting($pdo, 'btw_tarief', '9'));
    $bedrijf = getBedrijfsGegevens($pdo);
    $htmlBody = buildRecurringUpdateEmail($order, $oldItems, $newItems, $upcomingOrders, $bedrijf, $btwTarief, $pdo);

    $to = $order['email'];
    $recurringName = $order['recurring_name'] ?? 'Terugkerende bestelling';
    $subject = getEmailSubject($pdo, 'terugkerend_gewijzigd', "Bestelling gewijzigd: $recurringName - Bakkerij Civetta");

    $attachments = [];
    if (file_exists($bestelbonFile)) {
        $attachments[] = ['path' => $bestelbonFile, 'name' => 'gewijzigde-bestelbon.pdf', 'type' => 'application/pdf'];
    }

    $customerSent = sendHtmlEmail($to, $subject, $htmlBody, $attachments);
    sendHtmlEmail('info@bakkerij-civetta.nl', "Terugkerende bestelling gewijzigd: {$order['bedrijfsnaam']}", $htmlBody, [], $order['email']);

    return $customerSent;
}
