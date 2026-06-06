<?php
// קובץ debug זמני — מחק אחרי השימוש
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config.php';

startSession();
if (!isLoggedIn()) { die('Unauthorized'); }

header('Content-Type: application/json; charset=utf-8');

$db = getDB();
$courses = $db->query("SELECT * FROM courses WHERE status='active'")->fetchAll();

$results = [];

foreach ($courses as $course) {
    $params = [
        'api_key'   => ICOUNT_API_KEY,
        'cid'       => ICOUNT_COMPANY_ID,
        'doc_type'  => 320,
        'date_from' => date('Y-m-d', strtotime('-365 days')),
        'date_to'   => date('Y-m-d'),
        'page_id'   => $course['icount_payment_page_id'],
        'limit'     => 10,
    ];

    $endpoints = [
        'https://api.icount.co.il/api/v3.php/doc/search',
        'https://api.icount.co.il/api/v3.php/receipt/getList',
        'https://api.icount.co.il/api/v3.php/receipt/search',
        'https://api.icount.co.il/api/v3.php/doc/get',
    ];

    $endpointResults = [];
    foreach ($endpoints as $ep) {
        $ch0 = curl_init();
        curl_setopt_array($ch0, [
            CURLOPT_URL            => $ep,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . ICOUNT_API_KEY, 'X-API-KEY: ' . ICOUNT_API_KEY],
        ]);
        $r = curl_exec($ch0);
        curl_close($ch0);
        $decoded = json_decode($r, true);
        $endpointResults[$ep] = $decoded['reason'] ?? $decoded['status'] ?? 'unknown';
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.icount.co.il/api/v3.php/doc/search',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . ICOUNT_API_KEY,
            'X-API-KEY: ' . ICOUNT_API_KEY,
        ],
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    // נסה גם GET
    $getUrl = 'https://api.icount.co.il/api/v3.php/doc/getList?' . http_build_query($params);
    $ch2 = curl_init($getUrl);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $responseGet = curl_exec($ch2);
    curl_close($ch2);

    $results[] = [
        'course'           => $course['name'],
        'params_sent'      => $params,
        'endpoint_results' => $endpointResults,
        'post_response'    => json_decode($response, true),
    ];
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
