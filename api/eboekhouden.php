<?php
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
    
    public function createFullInvoice($accountData, $orderItems, $templateId, $ledgerId, $btwCode = 'LAAG_VERK_9', $sendEmail = true) {
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
            'items' => $invoiceItems
        ];
        
        if ($sendEmail && !empty($accountData['email'])) {
            $invoiceData['email'] = [
                'fromEmail' => 'info@bakkerij-civetta.nl',
                'fromName' => 'Bakkerij Civetta',
                'subject' => 'Uw factuur van Bakkerij Civetta',
                'body' => 'Beste ' . ($accountData['contactpersoon'] ?? 'klant') . ',<br><br>Hartelijk dank voor uw bestelling. Bijgaand vindt u uw factuur.<br><br>Met vriendelijke groet,<br>Bakkerij Civetta'
            ];
        }
        
        $this->log("Invoice data te versturen: " . json_encode($invoiceData));
        
        $invoice = $this->request('POST', '/v1/invoice', $invoiceData);
        $this->log("Invoice aangemaakt: " . json_encode($invoice));
        
        $invoiceDetails = $this->getInvoice($invoice['id']);
        $this->log("Invoice details: " . json_encode($invoiceDetails));
        
        return [
            'id' => $invoice['id'],
            'invoiceNumber' => $invoiceDetails['invoiceNumber'] ?? $invoice['invoiceNumber'] ?? null,
            'pdfUrl' => $invoiceDetails['urlPdfFile'] ?? null,
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
    $keys = ['facturatie_systeem', 'eboekhouden_api_token', 'eboekhouden_template_id', 'eboekhouden_ledger_id', 'btw_tarief'];
    
    foreach ($keys as $key) {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $settings[$key] = $stmt->fetchColumn() ?: '';
    }
    
    return $settings;
}
