<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';

startSession();
if (!isLoggedIn()) { die('Unauthorized'); }

header('Content-Type: application/json; charset=utf-8');

function icountRequest(string $endpoint, array $body): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.icount.co.il/api/v3.php/' . $endpoint,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . ICOUNT_API_KEY,
            'Content-Type: application/json',
        ],
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return json_decode($r, true) ?? [];
}

// 1. שלוף פרטים מלאים של עסקה 4070 (הרכישה הטסט שלך ב-₪1)
$docInfo = icountRequest('doc/info', [
    'doctype'       => 'invrec',
    'docnum'        => 4070,  // מספר החשבונית של הרכישה ב-₪1
    'get_items'     => true,
    'get_payments'  => true,
    'get_pdf_link'  => false,
]);

// 2. נסה endpoint של payment_page
$ppEndpoints = [
    'payment_page/get_list'  => [],
    'payment_page/getList'   => [],
    'payment_page/list'      => [],
    'payment_page/get'       => ['id' => 111],
    'payment_page/docs'      => ['id' => 111],
    'payment_page/get_docs'  => ['payment_page_id' => 111],
];

$ppResults = [];
foreach ($ppEndpoints as $ep => $body) {
    $res = icountRequest($ep, $body);
    $ppResults[$ep] = $res['reason'] ?? ($res['status'] === true ? 'OK' : 'unknown');
}

echo json_encode([
    'doc_info_4070'    => $docInfo,
    'payment_page_endpoints' => $ppResults,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
