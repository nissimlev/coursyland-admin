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
        'api_key'         => ICOUNT_API_KEY,
        'cid'             => 'nissimcoach72',
        'payment_page_id' => $course['icount_payment_page_id'],
        'date_from'       => date('Y-m-d', strtotime('-365 days')),
        'date_to'         => date('Y-m-d'),
        'doc_type'        => 320,
    ];

    // נסה כמה endpoints שונים
    $endpoints = [
        'https://api.icount.co.il/api/v3.php/doc/getList',
        'https://api.icount.co.il/api/v3.php/payment_page/getDocList',
        'https://api.icount.co.il/api/v3.php/client/getList',
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.icount.co.il/api/v3.php/payment_page/getDocList',
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
        'payment_page_id'  => $course['icount_payment_page_id'],
        'params_sent'      => $params,
        'curl_error'       => $err,
        'post_response'    => json_decode($response, true),
        'get_response'     => json_decode($responseGet, true),
    ];
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
