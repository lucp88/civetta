<?php
require_once __DIR__ . '/email-templates.php';

class EBoekhoudenClient {
    private $apiToken;
    private $baseUrl = 'https://api.e-boekhouden.nl';
    private $appName = 'Civetta';
    private $sessionToken = null;
    
    public function __construct($apiToken) {
        $this->apiToken = $apiToken;
    }
    
    private function startSession() {
        if ($this->sessionToken) {
            return $this->sessionToken;
        }
        
        $ch = curl_init($this->baseUrl . '/v1/session');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'accessToken' => $this->apiToken,
            'source' => $this->appName
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 && $httpCode !== 201) {
            $decoded = json_decode($response, true);
            $errorMsg = $decoded['message'] ?? 'Kon geen sessie starten';
            throw new Exception('e-Boekhouden sessie fout: ' . $errorMsg);
        }
        
        $decoded = json_decode($response, true);
        $this->sessionToken = $decoded['token'] ?? null;
        
        if (!$this->sessionToken) {
            throw new Exception('Geen sessie token ontvangen');
        }
        
        return $this->sessionToken;
    }
    
    private function request($method, $endpoint, $data = null) {
        $token = $this->startSession();
        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $jsonData = null;
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                $jsonData = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            }
        } elseif ($method === 'PUT' || $method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data) {
                $jsonData = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            }
        } elseif ($method === 'GET' && $data) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('cURL Error: ' . $error);
        }
        
        $decoded = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $errorMsg = isset($decoded['message']) ? $decoded['message'] : 'API Error';
            $errorDetails = '';
            if (isset($decoded['errors'])) {
                $errorDetails = ' | Errors: ' . json_encode($decoded['errors']);
            }
            if (isset($decoded['error'])) {
                $errorDetails .= ' | Error: ' . (is_array($decoded['error']) ? json_encode($decoded['error']) : $decoded['error']);
            }
            $this->log("API Error Response: " . $response);
            $this->log("Request was: $method $endpoint | Data: " . ($jsonData ?? 'none'));
            throw new Exception('e-Boekhouden API Error (' . $httpCode . '): ' . $errorMsg . $errorDetails);
        }
        
        return $decoded;
    }
    
    private function log($message) {
        $logFile = __DIR__ . '/webhook-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] [EBoekhouden] $message\n", FILE_APPEND);
    }
    
    public function getRelations($params = []) {
        return $this->request('GET', '/v1/relation', $params);
    }
    
    public function getRelationByEmail($email) {
        $relations = $this->request('GET', '/v1/relation', ['search' => $email]);
        if (!empty($relations['data'])) {
            foreach ($relations['data'] as $relation) {
                if (isset($relation['emailAddress']) && strtolower($relation['emailAddress']) === strtolower($email)) {
                    return $relation;
                }
            }
        }
        return null;
    }
    
    public function createRelation($data) {
        return $this->request('POST', '/relations', $data);
    }
    
    public function getOrCreateRelation($accountData) {
        $existing = $this->getRelationByEmail($accountData['email']);
        if ($existing) {
            return $existing;
        }
        
        $phone = $accountData['telefoon'] ?? '';
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) === 11 && substr($phone, 0, 2) === '31') {
            $phone = '0' . substr($phone, 2);
        }
        
        $postalCode = $accountData['postcode'] ?? '';
        $postalCode = strtoupper(preg_replace('/\s+/', '', $postalCode));
        if (strlen($postalCode) === 6) {
            $postalCode = substr($postalCode, 0, 4) . ' ' . substr($postalCode, 4);
        }
        
        $kvkNumber = $accountData['kvk_nummer'] ?? '';
        $kvkNumber = preg_replace('/[^0-9]/', '', $kvkNumber);
        
        $relationData = [
            'type' => 'B',
            'name' => $accountData['bedrijfsnaam'],
            'contact' => $accountData['contactpersoon'] ?? '',
            'emailAddress' => $accountData['email'],
            'country' => 'Nederland'
        ];
        
        if (!empty($phone) && strlen($phone) === 10) {
            $relationData['phoneNumber'] = $phone;
        }
        if (!empty($accountData['adres'])) {
            $relationData['address'] = $accountData['adres'];
        }
        if (!empty($postalCode)) {
            $relationData['postalCode'] = $postalCode;
        }
        if (!empty($accountData['plaats'])) {
            $relationData['city'] = $accountData['plaats'];
        }
        if (!empty($kvkNumber) && strlen($kvkNumber) === 8) {
            $relationData['companyRegistrationNumber'] = $kvkNumber;
        }
        if (!empty($accountData['btw_id'])) {
            $relationData['vatNumber'] = $accountData['btw_id'];
        }
        
        return $this->request('POST', '/v1/relation', $relationData);
    }
    
    public function getInvoiceTemplates() {
        return $this->request('GET', '/v1/invoicetemplate');
    }
    
    public function createInvoice($data) {
        return $this->request('POST', '/v1/invoice', $data);
    }
    
    public function getInvoice($invoiceId) {
        return $this->request('GET', '/v1/invoice/' . $invoiceId);
    }
    
    public function getInvoices($params = []) {
        return $this->request('GET', '/v1/invoice', $params);
    }
    
    public function getLedgers() {
        return $this->request('GET', '/v1/ledger');
    }
    
    public function getMutations($params = []) {
        return $this->request('GET', '/v1/mutation', $params);
    }
    
    public function createMutation($data) {
        return $this->request('POST', '/v1/mutation', $data);
    }
    
    public function bookInvoiceToDebtors($invoiceNumber, $amountExcl, $amountIncl, $debiterenLedgerId, $omzetLedgerId, $relationId, $btwCode = 'LAAG_VERK_9', $invoiceDate = null) {
        $invoiceDate = $invoiceDate ?? date('Y-m-d');
        
        $this->log("bookInvoiceToDebtors V5 - invoiceNumber: $invoiceNumber, amountExcl: $amountExcl, relationId: $relationId");
        
        return $this->createMutation([
            'type' => '2',
            'date' => $invoiceDate,
            'ledgerId' => intval($debiterenLedgerId),
            'invoiceNumber' => strval($invoiceNumber),
            'relationId' => intval($relationId),
            'inExVat' => 'IN',
            'rows' => [
                [
                    'ledgerId' => intval($omzetLedgerId),
                    'vatCode' => $btwCode,
                    'amount' => floatval($amountExcl),
                    'description' => 'Factuur ' . $invoiceNumber
                ]
            ]
        ]);
    }
    
    public function createFullInvoice($accountData, $orderItems, $templateId, $ledgerId, $btwCode = 'LAAG_VERK_9', $sendEmail = true, $debiterenLedgerId = null) {
        $this->log("createFullInvoice gestart - accountData: " . json_encode($accountData));
        $this->log("orderItems: " . json_encode($orderItems));
        $this->log("templateId: $templateId, ledgerId: $ledgerId, btwCode: $btwCode");
        
        $relation = $this->getOrCreateRelation($accountData);
        $relationId = $relation['id'];
        $this->log("Relatie gevonden/aangemaakt: ID=$relationId");
        
        $invoiceItems = [];
        foreach ($orderItems as $item) {
            $invoiceItems[] = [
                'description' => $item['product_name'],
                'pricePerUnit' => floatval($item['unit_price']),
                'quantity' => intval($item['quantity']),
                'vatCode' => $btwCode,
                'ledgerId' => intval($ledgerId)
            ];
        }
        
        $invoiceData = [
            'relationId' => $relationId,
            'date' => date('Y-m-d'),
            'termOfPayment' => 14,
            'templateId' => intval($templateId),
            'inExVat' => 'IN',
            'items' => $invoiceItems
        ];
        
        if ($sendEmail && !empty($accountData['email'])) {
            $deliveryDate = $accountData['delivery_date'] ?? null;
            $btwTarief = $accountData['btw_tarief'] ?? 9;
            $htmlBody = buildInvoiceEmailBody($accountData, $orderItems, null, $deliveryDate, $btwTarief);
            
            $invoiceData['email'] = [
                'fromEmail' => 'info@bakkerij-civetta.nl',
                'fromName' => 'Bakkerij Civetta',
                'subject' => 'Uw factuur van Bakkerij Civetta',
                'body' => $htmlBody
            ];
        }
        
        $this->log("Invoice data te versturen: " . json_encode($invoiceData));
        
        $invoice = $this->request('POST', '/v1/invoice', $invoiceData);
        $this->log("Invoice aangemaakt: " . json_encode($invoice));
        
        $invoiceDetails = $this->getInvoice($invoice['id']);
        $this->log("Invoice details: " . json_encode($invoiceDetails));
        
        $pdfUrl = $invoiceDetails['urlPdfFile'] 
            ?? $invoiceDetails['pdfUrl'] 
            ?? $invoiceDetails['pdf_url']
            ?? $invoiceDetails['url']
            ?? $invoice['urlPdfFile']
            ?? $invoice['pdfUrl']
            ?? null;
        
        $invoiceNumber = $invoiceDetails['invoiceNumber'] 
            ?? $invoiceDetails['invoice_number']
            ?? $invoice['invoiceNumber'] 
            ?? $invoice['invoice_number']
            ?? null;
        
        $this->log("Extracted pdfUrl: " . ($pdfUrl ?? 'NULL') . ", invoiceNumber: " . ($invoiceNumber ?? 'NULL'));
        
        $totalAmountExcl = $invoiceDetails['totalExcl'] ?? 0;
        $totalAmountIncl = $invoiceDetails['totalAmount'] ?? 0;
        
        if ($debiterenLedgerId) {
            try {
                $mutation = $this->bookInvoiceToDebtors(
                    $invoiceNumber, 
                    $totalAmountExcl, 
                    $totalAmountIncl, 
                    $debiterenLedgerId, 
                    $ledgerId, 
                    $relationId,
                    $btwCode
                );
                $this->log("Factuur geboekt op debiteuren: " . json_encode($mutation));
            } catch (Exception $e) {
                $this->log("WAARSCHUWING: Kon factuur niet op debiteuren boeken: " . $e->getMessage());
            }
        } else {
            $this->log("Geen debiteuren ledger ID geconfigureerd, factuur niet geboekt op debiteuren");
        }
        
        return [
            'id' => $invoice['id'],
            'invoiceNumber' => $invoiceNumber,
            'pdfUrl' => $pdfUrl,
            'relationId' => $relationId
        ];
    }
    
    public function testConnection() {
        try {
            $this->startSession();
            return ['success' => true, 'message' => 'Verbinding succesvol!'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function getBankMutations($bankLedgerId, $dateFrom = null) {
        $params = [];
        if ($dateFrom) {
            $params['dateFrom'] = $dateFrom;
        }
        
        $mutations = $this->getMutations($params);
        
        if (empty($mutations['items'])) {
            return [];
        }
        
        $bankMutations = array_filter($mutations['items'], function($mutation) use ($bankLedgerId) {
            return intval($mutation['ledgerId'] ?? 0) === intval($bankLedgerId);
        });
        
        return array_values($bankMutations);
    }
    
    public function matchPaymentWithInvoice($bankMutations, $invoiceNumber, $expectedAmount) {
        $invoiceNumber = strtolower(trim($invoiceNumber));
        $expectedAmount = round(floatval($expectedAmount), 2);
        
        foreach ($bankMutations as $mutation) {
            $amount = round(floatval($mutation['amount'] ?? 0), 2);
            $description = strtolower($mutation['description'] ?? '');
            $mutationInvoiceNr = strtolower($mutation['invoiceNumber'] ?? '');
            $entryNumber = strtolower($mutation['entryNumber'] ?? '');
            
            if ($amount !== $expectedAmount) {
                continue;
            }
            
            if (strpos($mutationInvoiceNr, $invoiceNumber) !== false ||
                strpos($description, $invoiceNumber) !== false ||
                strpos($entryNumber, $invoiceNumber) !== false) {
                return [
                    'matched' => true,
                    'mutation_id' => $mutation['id'] ?? null,
                    'amount' => $amount,
                    'date' => $mutation['date'] ?? null,
                    'description' => $mutation['description'] ?? ''
                ];
            }
        }
        
        return ['matched' => false];
    }
}

function getEBoekhoudenClient($pdo) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    
    $stmt->execute(['facturatie_systeem']);
    $systeem = $stmt->fetchColumn();
    
    if ($systeem !== 'eboekhouden') {
        return null;
    }
    
    $stmt->execute(['eboekhouden_api_token']);
    $apiToken = $stmt->fetchColumn();
    
    if (!$apiToken) {
        return null;
    }
    
    return new EBoekhoudenClient($apiToken);
}

function getEBoekhoudenSettings($pdo) {
    $settings = [];
    $keys = ['facturatie_systeem', 'eboekhouden_api_token', 'eboekhouden_template_id_betaald', 'eboekhouden_template_id_openstaand', 'eboekhouden_ledger_id', 'eboekhouden_debiteuren_ledger_id', 'btw_tarief'];
    
    foreach ($keys as $key) {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $settings[$key] = $stmt->fetchColumn() ?: '';
    }
    
    return $settings;
}
