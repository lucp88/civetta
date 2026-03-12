<?php

function getOrCreateVapidKeys($pdo) {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('vapid_public_key', 'vapid_private_key')");
    $keys = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if (!empty($keys['vapid_public_key']) && !empty($keys['vapid_private_key'])) {
        return [
            'public' => $keys['vapid_public_key'],
            'private' => $keys['vapid_private_key']
        ];
    }

    $ecKey = openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC
    ]);
    if (!$ecKey) {
        error_log('Web Push: openssl_pkey_new failed: ' . openssl_error_string());
        throw new RuntimeException('Kan VAPID keys niet genereren');
    }
    $details = openssl_pkey_get_details($ecKey);

    $publicKeyRaw = chr(4) . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $publicKeyBase64url = base64url_encode($publicKeyRaw);

    openssl_pkey_export($ecKey, $privateKeyPem);

    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute(['vapid_public_key', $publicKeyBase64url]);
    $stmt->execute(['vapid_private_key', $privateKeyPem]);

    return [
        'public' => $publicKeyBase64url,
        'private' => $privateKeyPem
    ];
}

function sendPushNotification($pdo, $title, $body, $url = '/admin/bakker/bakker-dashboard.php') {
    $vapid = getOrCreateVapidKeys($pdo);

    $stmt = $pdo->query("SELECT * FROM push_subscriptions");
    $subscriptions = $stmt->fetchAll();

    if (empty($subscriptions)) {
        error_log('Web Push: geen subscriptions gevonden');
        return 0;
    }

    $payload = json_encode([
        'title' => $title,
        'body' => $body,
        'url' => $url,
        'timestamp' => time()
    ]);

    $expired = [];
    $sent = 0;
    foreach ($subscriptions as $sub) {
        $result = sendWebPush(
            $sub['endpoint'],
            $payload,
            $sub['key_p256dh'],
            $sub['key_auth'],
            $vapid['public'],
            $vapid['private']
        );

        if ($result === false) {
            error_log('Web Push: verzending mislukt voor endpoint ' . substr($sub['endpoint'], 0, 80));
            continue;
        }

        if (in_array($result['status'], [404, 410])) {
            $expired[] = $sub['id'];
        } elseif ($result['status'] >= 200 && $result['status'] < 300) {
            $sent++;
        } else {
            error_log('Web Push: HTTP ' . $result['status'] . ' voor endpoint ' . substr($sub['endpoint'], 0, 80) . ' - ' . $result['response']);
        }
    }

    if (!empty($expired)) {
        $placeholders = implode(',', array_fill(0, count($expired), '?'));
        $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE id IN ($placeholders)");
        $stmt->execute($expired);
        error_log('Web Push: ' . count($expired) . ' verlopen subscriptions verwijderd');
    }

    return $sent;
}

function sendWebPush($endpoint, $payload, $userPublicKey, $userAuth, $vapidPublicKeyBase64url, $vapidPrivateKeyPem) {
    try {
        $encrypted = encryptPushPayload($payload, $userPublicKey, $userAuth);
        if (!$encrypted) {
            error_log('Web Push: payload encryptie mislukt');
            return false;
        }

        $jwt = createVapidJwt($endpoint, $vapidPrivateKeyPem);
        if (!$jwt) {
            error_log('Web Push: VAPID JWT creatie mislukt');
            return false;
        }

        $headers = [
            'Authorization: vapid t=' . $jwt . ',k=' . $vapidPublicKeyBase64url,
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'Content-Length: ' . strlen($encrypted),
            'TTL: 86400'
        ];

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encrypted);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            error_log('Web Push: curl error: ' . $curlError);
            return false;
        }

        return ['status' => $status, 'response' => $response];
    } catch (\Throwable $e) {
        error_log('Web Push error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        return false;
    }
}

function encryptPushPayload($payload, $userPublicKeyBase64url, $userAuthBase64url) {
    if (!function_exists('openssl_pkey_derive')) {
        error_log('Web Push: openssl_pkey_derive niet beschikbaar (PHP 7.3+ vereist)');
        return false;
    }

    $userPublicKey = base64url_decode($userPublicKeyBase64url);
    $userAuth = base64url_decode($userAuthBase64url);

    if (strlen($userPublicKey) !== 65) {
        error_log('Web Push: ongeldige p256dh key lengte: ' . strlen($userPublicKey));
        return false;
    }
    if (strlen($userAuth) !== 16) {
        error_log('Web Push: ongeldige auth key lengte: ' . strlen($userAuth));
        return false;
    }

    $localKey = openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC
    ]);
    if (!$localKey) {
        error_log('Web Push: lokale EC key generatie mislukt: ' . openssl_error_string());
        return false;
    }
    $localDetails = openssl_pkey_get_details($localKey);
    $localPublicKey = chr(4) . str_pad($localDetails['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($localDetails['ec']['y'], 32, "\0", STR_PAD_LEFT);

    $peerPem = rawP256PublicKeyToPem($userPublicKey);
    $peerKey = openssl_pkey_get_public($peerPem);
    if (!$peerKey) {
        error_log('Web Push: peer public key parse mislukt: ' . openssl_error_string());
        return false;
    }

    $sharedSecret = openssl_pkey_derive($peerKey, $localKey);
    if (!$sharedSecret) {
        error_log('Web Push: ECDH key derive mislukt: ' . openssl_error_string());
        return false;
    }

    $prk = hash_hmac('sha256', $sharedSecret, $userAuth, true);
    $keyInfo = "WebPush: info\0" . $userPublicKey . $localPublicKey;
    $ikm = hkdfExpand($prk, $keyInfo, 32);

    $salt = random_bytes(16);
    $prk2 = hash_hmac('sha256', $ikm, $salt, true);

    $cek = hkdfExpand($prk2, "Content-Encoding: aes128gcm\0", 16);
    $nonce = hkdfExpand($prk2, "Content-Encoding: nonce\0", 12);

    $padded = $payload . "\x02";
    $tag = '';
    $encrypted = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($encrypted === false) {
        error_log('Web Push: AES-GCM encryptie mislukt: ' . openssl_error_string());
        return false;
    }

    $recordSize = pack('N', 4096);
    $keyLen = chr(65);

    return $salt . $recordSize . $keyLen . $localPublicKey . $encrypted . $tag;
}

function createVapidJwt($endpoint, $privateKeyPem) {
    $parsed = parse_url($endpoint);
    $audience = $parsed['scheme'] . '://' . $parsed['host'];

    $header = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = base64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 86400,
        'sub' => 'mailto:info@bakkerij-civetta.nl'
    ]));

    $input = "$header.$payload";

    $key = openssl_pkey_get_private($privateKeyPem);
    if (!$key) {
        error_log('Web Push: VAPID private key laden mislukt: ' . openssl_error_string());
        return false;
    }

    $result = openssl_sign($input, $derSignature, $key, OPENSSL_ALGO_SHA256);
    if (!$result) {
        error_log('Web Push: JWT signing mislukt: ' . openssl_error_string());
        return false;
    }

    $rawSignature = derSignatureToRaw($derSignature);

    return "$input." . base64url_encode($rawSignature);
}

function rawP256PublicKeyToPem($rawKey) {
    $header = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $der = $header . $rawKey;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return $pem;
}

function derSignatureToRaw($der) {
    $pos = 2;
    $rLen = ord($der[$pos + 1]);
    $r = substr($der, $pos + 2, $rLen);
    $pos = $pos + 2 + $rLen;
    $sLen = ord($der[$pos + 1]);
    $s = substr($der, $pos + 2, $sLen);

    $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);

    return $r . $s;
}

function hkdfExpand($prk, $info, $length) {
    $t = '';
    $lastBlock = '';
    $counter = 1;
    while (strlen($t) < $length) {
        $lastBlock = hash_hmac('sha256', $lastBlock . $info . chr($counter), $prk, true);
        $t .= $lastBlock;
        $counter++;
    }
    return substr($t, 0, $length);
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}
