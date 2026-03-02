<?php
require_once '../admin/config.php';
require_once 'cors.php';

header('Content-Type: application/json');
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Admin-only endpoint
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $accountId = $_GET['account_id'] ?? null;

        if (!$accountId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'account_id is verplicht']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT has_balance, balance FROM business_accounts WHERE id = ?");
            $stmt->execute([$accountId]);
            $account = $stmt->fetch();

            if (!$account) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Account niet gevonden']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT bt.*, bo.bestelbon_number
                FROM balance_transactions bt
                LEFT JOIN business_orders bo ON bt.order_id = bo.id
                WHERE bt.account_id = ?
                ORDER BY bt.created_at DESC
            ");
            $stmt->execute([$accountId]);
            $transactions = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'balance' => floatval($account['balance']),
                'has_balance' => (bool)$account['has_balance'],
                'transactions' => $transactions
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);

        $accountId = $data['account_id'] ?? null;
        $amount = floatval($data['amount'] ?? 0);
        $type = $data['type'] ?? 'credit';
        $description = trim($data['description'] ?? '');

        if (!$accountId || $amount == 0 || !$description) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'account_id, amount en description zijn verplicht']);
            exit;
        }

        if (!in_array($type, ['credit', 'debit'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Type moet credit of debit zijn']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT has_balance, balance FROM business_accounts WHERE id = ?");
            $stmt->execute([$accountId]);
            $account = $stmt->fetch();

            if (!$account) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Account niet gevonden']);
                exit;
            }

            if (!$account['has_balance']) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Saldo is niet ingeschakeld voor dit account']);
                exit;
            }

            // Ensure amount sign matches type
            $dbAmount = abs($amount);
            if ($type === 'debit') {
                $dbAmount = -$dbAmount;
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO balance_transactions (account_id, amount, type, description, created_by)
                VALUES (?, ?, ?, ?, 'admin')
            ");
            $stmt->execute([$accountId, $dbAmount, $type, $description]);

            $stmt = $pdo->prepare("UPDATE business_accounts SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$dbAmount, $accountId]);

            $pdo->commit();

            // Fetch updated balance
            $stmt = $pdo->prepare("SELECT balance FROM business_accounts WHERE id = ?");
            $stmt->execute([$accountId]);
            $newBalance = floatval($stmt->fetchColumn());

            echo json_encode([
                'success' => true,
                'message' => 'Saldo bijgewerkt',
                'balance' => $newBalance
            ]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>
