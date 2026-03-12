<?php
/**
 * Cron job voor het versturen van verlengings-herinneringen voor terugkerende bestellingen
 * 
 * Dit script draait dagelijks en stuurt herinneringen naar klanten waarvan de
 * recurring serie binnen 14 dagen afloopt (zonder einddatum).
 * 
 * Crontab: 0 9 * * * /usr/bin/php /path/to/cron/recurring-renewal-reminder.php
 * 
 * CLI opties:
 *   --dry-run     Test zonder emails te versturen
 *   -v, --verbose Uitgebreide logging
 */

require_once __DIR__ . '/../admin/config.php';

$dryRun = in_array('--dry-run', $argv ?? []);
$verbose = in_array('-v', $argv ?? []) || in_array('--verbose', $argv ?? []);

function logMessage($message) {
    global $verbose;
    $timestamp = date('Y-m-d H:i:s');
    if ($verbose) {
        echo "$timestamp - $message\n";
    }
    error_log("Renewal Reminder: $message");
}

try {
    $reminderDate = date('Y-m-d', strtotime('+14 days'));
    
    logMessage("=== Renewal Reminder Check ===");
    logMessage("Zoeken naar recurring groepen die aflopen rond: $reminderDate");
    
    if ($dryRun) {
        logMessage("*** DRY-RUN MODUS - Geen emails worden verstuurd ***");
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            recurring_group_id,
            recurring_name,
            recurring_frequency,
            recurring_confirmed_until,
            account_id,
            total_amount,
            COUNT(*) as total_orders
        FROM business_orders 
        WHERE is_recurring = 1 
        AND recurring_group_id IS NOT NULL
        AND recurring_end_date IS NULL
        AND recurring_confirmed_until IS NOT NULL
        AND recurring_confirmed_until <= ?
        AND recurring_confirmed_until >= CURDATE()
        AND status NOT IN ('cancelled')
        GROUP BY recurring_group_id, recurring_name, recurring_frequency, recurring_confirmed_until, account_id, total_amount
        HAVING COUNT(CASE WHEN status IN ('recurring_pending', 'pending', 'confirmed') AND delivery_date >= CURDATE() THEN 1 END) > 0
    ");
    $stmt->execute([$reminderDate]);
    $expiringGroups = $stmt->fetchAll();
    
    logMessage("Gevonden: " . count($expiringGroups) . " groep(en) die bijna aflopen");
    
    $remindersSent = 0;
    $errors = 0;
    
    foreach ($expiringGroups as $group) {
        $stmt = $pdo->prepare("
            SELECT bedrijfsnaam, contactpersoon, email 
            FROM business_accounts 
            WHERE id = ?
        ");
        $stmt->execute([$group['account_id']]);
        $account = $stmt->fetch();
        
        if (!$account) {
            logMessage("  SKIP: Account {$group['account_id']} niet gevonden");
            continue;
        }
        
        $alreadySent = checkReminderAlreadySent($pdo, $group['recurring_group_id']);
        if ($alreadySent) {
            logMessage("  SKIP: {$account['bedrijfsnaam']} - herinnering al verstuurd");
            continue;
        }
        
        logMessage("Verwerken: {$account['bedrijfsnaam']} - {$group['recurring_name']}");
        logMessage("  Laatste geplande levering: {$group['recurring_confirmed_until']}");
        
        if ($dryRun) {
            logMessage("  DRY-RUN: Zou email versturen naar {$account['email']}");
            $remindersSent++;
            continue;
        }
        
        try {
            $sent = sendRenewalReminder($account, $group);
            
            if ($sent) {
                logReminderSent($pdo, $group['recurring_group_id'], $group['account_id']);
                $remindersSent++;
                logMessage("  OK: Herinnering verstuurd naar {$account['email']}");
            } else {
                $errors++;
                logMessage("  ERROR: Kon email niet versturen");
            }
            
        } catch (Exception $e) {
            $errors++;
            logMessage("  ERROR: " . $e->getMessage());
        }
    }
    
    logMessage("");
    logMessage("=== Samenvatting ===");
    logMessage("Herinneringen verstuurd: $remindersSent");
    logMessage("Fouten: $errors");
    logMessage("Klaar!");
    
} catch (Exception $e) {
    logMessage("FATAL ERROR: " . $e->getMessage());
    exit(1);
}

function checkReminderAlreadySent($pdo, $groupId) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM renewal_reminders_sent 
        WHERE recurring_group_id = ? 
        AND sent_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    
    try {
        $stmt->execute([$groupId]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS renewal_reminders_sent (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recurring_group_id VARCHAR(50) NOT NULL,
                account_id INT NOT NULL,
                sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_group (recurring_group_id),
                INDEX idx_sent (sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        return false;
    }
}

function logReminderSent($pdo, $groupId, $accountId) {
    $stmt = $pdo->prepare("
        INSERT INTO renewal_reminders_sent (recurring_group_id, account_id, sent_at)
        VALUES (?, ?, NOW())
    ");
    $stmt->execute([$groupId, $accountId]);
}

function sendRenewalReminder($account, $group) {
    $frequencyLabels = [
        'weekly' => 'wekelijkse',
        'biweekly' => 'tweewekelijkse', 
        'monthly' => 'maandelijkse'
    ];
    $frequencyLabel = $frequencyLabels[$group['recurring_frequency']] ?? 'terugkerende';
    
    $dashboardUrl = "https://bakkerij-civetta.nl/mijn-bestellingen.html";
    $lastDelivery = date('d-m-Y', strtotime($group['recurring_confirmed_until']));
    
    $subject = "Herinnering: Verleng uw {$frequencyLabel} bestelling - Bakkerij Civetta";
    
    $body = "Beste {$account['contactpersoon']},\n\n";
    $body .= "Uw terugkerende bestelling bij Bakkerij Civetta loopt bijna af.\n\n";
    $body .= "══════════════════════════════════════\n";
    $body .= "BESTELLING DETAILS\n";
    $body .= "══════════════════════════════════════\n\n";
    
    if ($group['recurring_name']) {
        $body .= "Naam: {$group['recurring_name']}\n";
    }
    $body .= "Frequentie: " . ucfirst($frequencyLabel) . "\n";
    $body .= "Laatste geplande levering: $lastDelivery\n";
    $body .= "Bedrag per levering: €" . number_format($group['total_amount'], 2, ',', '.') . "\n\n";
    
    $body .= "══════════════════════════════════════\n";
    $body .= "ACTIE VEREIST\n";
    $body .= "══════════════════════════════════════\n\n";
    $body .= "Wilt u deze bestelling voortzetten? Ga naar uw dashboard\n";
    $body .= "en klik op 'Verlengen' om de komende 3 maanden in te plannen:\n\n";
    $body .= "$dashboardUrl\n\n";
    $body .= "Als u niets doet, stopt de bestelling automatisch na $lastDelivery.\n\n";
    $body .= "Heeft u vragen? Neem gerust contact met ons op.\n\n";
    $body .= "Met vriendelijke groet,\n";
    $body .= "Bakkerij Civetta\n";
    $body .= "info@bakkerij-civetta.nl";
    
    $headers = "From: noreply@bakkerij-civetta.nl\r\n";
    $headers .= "Reply-To: info@bakkerij-civetta.nl\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    return @mail($account['email'], $subject, $body, $headers);
}
?>
