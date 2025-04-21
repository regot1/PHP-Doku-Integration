<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
date_default_timezone_set('UTC');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

try {
    // Ambil data JSON yang dikirim dari front-end
    $data = json_decode(file_get_contents('php://input'), true);

    // Validasi data minimal (pastikan semua field yang dibutuhkan ada)
    if (
        !$data || 
        empty($data['total_payment']) ||
        empty($data['name']) ||
        empty($data['email']) ||
        empty($data['phone']) ||
        empty($data['desain']) ||
        empty($data['Paket']) ||
        empty($data['domain'])
    ) {
        throw new Exception("Data input tidak valid");
    }

    // Konfigurasi Doku
    $clientId  = 'YOUR_CLIENT_ID';
    $secretKey = 'YOUR_SECRET_KEY';

    // Generate nomor invoice dan timestamp
    $invoiceNumber = 'INV-' . time();
    $timestamp = (new DateTime())->format('Y-m-d\TH:i:s\Z');
    $deskripsiBarang = $data['Paket'] . " + " . $data['desain'] . " + " . $data['domain'];

    // Buat payload request sesuai contoh:
    // - "order": memuat amount, invoice_number, dan currency
    // - "payment": misalnya menggunakan payment_due_date 60 (menit)
    // Selain itu, kita tambahkan informasi customer dan item_details
   $requestBody = [
    "order" => [
        "amount"         => (int)$data['total_payment'],
        "invoice_number" => $invoiceNumber,
        "currency"       => "IDR",
        "callback_url"   => "https://rewebid.com/",
        "line_items"     => [
            [
                "id"       => "001",               // ID produk, bisa disesuaikan
                "name"     => $deskripsiBarang,      // Gabungan nama paket, desain, dan domain
                "price"    => (int)$data['total_payment'],
                "quantity" => 1                    // Jumlah item
            ]
        ]
    ],
    "payment" => [
        "payment_due_date" => 60
    ],
    "customer" => [
        "name"  => $data['name'],
        "email" => $data['email'],
        "phone" => $data['phone']
    ],
    "additional_info" => [
        "paket"  => $data['Paket'],
        "domain" => $data['domain'],
        "desain" => $data['desain']
    ],
];

    // 1. Generate digest dari payload JSON
    $digest = base64_encode(hash('sha256', json_encode($requestBody), true));

    // 2. Buat string signature sesuai format yang dibutuhkan Doku
    $signatureString = "Client-Id:{$clientId}\n"
                     . "Request-Id:{$invoiceNumber}\n"
                     . "Request-Timestamp:{$timestamp}\n"
                     . "Request-Target:/checkout/v1/payment\n"
                     . "Digest:{$digest}";

    // 3. Generate signature dengan HMAC-SHA256
    $signature = base64_encode(hash_hmac('sha256', $signatureString, $secretKey, true));

    // 4. Kirim request ke API Doku menggunakan cURL
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.doku.com/checkout/v1/payment',
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Client-Id: ' . $clientId,
            'Request-Id: ' . $invoiceNumber,
            'Request-Timestamp: ' . $timestamp,
            'Signature: HMACSHA256=' . $signature,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($requestBody),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true // Hanya untuk testing; aktifkan di production
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        throw new Exception("cURL Error: " . curl_error($ch));
    }
    curl_close($ch);

    // Jika HTTP code bukan 200, lempar error
    if ($httpCode !== 200) {
        throw new Exception("Doku API Error (HTTP {$httpCode}): " . $response);
    }

    // Decode respon JSON dari Doku
    $responseData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON response from Doku");
    }
    
    // Pastikan struktur respon sesuai (misalnya ada payment->url)
    if (!isset($responseData['response']['payment']['url'])) {
        throw new Exception("Invalid Doku response structure");
    }

    // Kirim kembali URL checkout ke front-end
    echo json_encode([
        "success"      => true,
        "checkout_url" => $responseData['response']['payment']['url']
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage()
    ]);
}

// Debug: simpan data yang diterima (pastikan file debug.log dapat ditulisi)
file_put_contents('debug.log', print_r($data, true), FILE_APPEND);
exit;
