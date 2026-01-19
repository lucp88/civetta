<?php
/**
 * Migratie: Vereenvoudigd Payment Status Model
 * 
 * Voegt toe:
 * - payment_status (pending/paid)
 * - is_cancelled (0/1)
 * 
 * Migreert:
 * - payment_type: direct → mollie_direct, later → invoice
 * - Oude status waarden naar nieuwe payment_status + is_cancelled
 */

require_once __DIR__ . '/../config.php';

echo "=== Migratie 001: Payment Status Model ===\n\n";

try {
    $pdo->beginTransaction();
    
    // 1. Nieuwe kolom payment_status toevoegen
    echo "1. Kolom payment_status toevoegen...\n";
    try {
        $pdo->exec("ALTER TABLE business_orders ADD COLUMN payment_status VARCHAR(20) DEFAULT 'pending'");
        echo "   ✓ payment_status toegevoegd\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "   - payment_status bestaat al, overgeslagen\n";
        } else {
            throw $e;
        }
    }
    
    // 2. Nieuwe kolom is_cancelled toevoegen
    echo "2. Kolom is_cancelled toevoegen...\n";
    try {
        $pdo->exec("ALTER TABLE business_orders ADD COLUMN is_cancelled TINYINT(1) DEFAULT 0");
        echo "   ✓ is_cancelled toegevoegd\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "   - is_cancelled bestaat al, overgeslagen\n";
        } else {
            throw $e;
        }
    }
    
    // 3. Migreer payment_status op basis van oude status en mollie_status
    echo "3. Data migreren naar payment_status...\n";
    
    $stmt = $pdo->exec("
        UPDATE business_orders 
        SET payment_status = 'paid' 
        WHERE status IN ('paid', 'delivered', 'invoiced') 
           OR mollie_status = 'paid'
    ");
    echo "   ✓ $stmt orders naar 'paid' gemigreerd\n";
    
    $stmt = $pdo->exec("
        UPDATE business_orders 
        SET payment_status = 'pending' 
        WHERE payment_status IS NULL OR payment_status = ''
    ");
    echo "   ✓ Overige orders naar 'pending' gezet\n";
    
    // 4. Migreer is_cancelled
    echo "4. Data migreren naar is_cancelled...\n";
    $stmt = $pdo->exec("
        UPDATE business_orders 
        SET is_cancelled = 1 
        WHERE status = 'cancelled'
    ");
    echo "   ✓ $stmt orders als cancelled gemarkeerd\n";
    
    // 5. Migreer payment_type waarden
    echo "5. Payment_type waarden migreren...\n";
    
    $stmt = $pdo->exec("
        UPDATE business_orders 
        SET payment_type = 'mollie_direct' 
        WHERE payment_type = 'direct'
    ");
    echo "   ✓ $stmt orders: direct → mollie_direct\n";
    
    $stmt = $pdo->exec("
        UPDATE business_orders 
        SET payment_type = 'invoice' 
        WHERE payment_type = 'later'
    ");
    echo "   ✓ $stmt orders: later → invoice\n";
    
    $pdo->commit();
    
    echo "\n=== Migratie succesvol afgerond ===\n";
    
    // Toon huidige staat
    echo "\nHuidige data verdeling:\n";
    
    $stmt = $pdo->query("SELECT payment_status, COUNT(*) as count FROM business_orders GROUP BY payment_status");
    echo "\npayment_status:\n";
    foreach ($stmt->fetchAll() as $row) {
        echo "  - {$row['payment_status']}: {$row['count']}\n";
    }
    
    $stmt = $pdo->query("SELECT payment_type, COUNT(*) as count FROM business_orders GROUP BY payment_type");
    echo "\npayment_type:\n";
    foreach ($stmt->fetchAll() as $row) {
        echo "  - {$row['payment_type']}: {$row['count']}\n";
    }
    
    $stmt = $pdo->query("SELECT is_cancelled, COUNT(*) as count FROM business_orders GROUP BY is_cancelled");
    echo "\nis_cancelled:\n";
    foreach ($stmt->fetchAll() as $row) {
        $label = $row['is_cancelled'] ? 'ja' : 'nee';
        echo "  - $label: {$row['count']}\n";
    }
    
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "\n❌ FOUT: " . $e->getMessage() . "\n";
    exit(1);
}
