<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';

startSession();
if (!isLoggedIn()) { die('Unauthorized'); }

header('Content-Type: application/json; charset=utf-8');

// בדיקת auth + שליפת מסמכים אחרונים
$body = json_encode([
    'start_date'   => date('Y-m-d', strtotime('-90 days')),
    'end_date'     => date('Y-m-d'),
    'doctype'      => 'receipt',
    'detail_level' => 2,
    'max_results'  => 5,
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://api.icount.co.il/api/v3.php/doc/search',
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . ICOUNT_API_KEY,
        'Content-Type: application/json',
    ],
]);
$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

echo json_encode([
    'api_key_prefix' => substr(ICOUNT_API_KEY, 0, 15) . '...',
    'curl_error'     => $err,
    'response'       => json_decode($response, true),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
