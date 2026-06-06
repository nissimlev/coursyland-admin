<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';

startSession();
if (!isLoggedIn()) { die('Unauthorized'); }

// טען PHPMailer
$autoloadPaths = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) { require_once $path; break; }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

header('Content-Type: text/plain; charset=utf-8');

echo "GMAIL_USER: " . GMAIL_USER . "\n";
echo "APP_PASSWORD length: " . strlen(GMAIL_APP_PASSWORD) . " chars\n";
echo "APP_PASSWORD (masked): " . substr(GMAIL_APP_PASSWORD, 0, 4) . "****\n\n";

$mail = new PHPMailer(true);
$mail->SMTPDebug = SMTP::DEBUG_SERVER;
$mail->Debugoutput = function($str, $level) { echo $str . "\n"; };

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = GMAIL_USER;
    $mail->Password   = GMAIL_APP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(GMAIL_USER, 'CoursyLand Test');
    $mail->addAddress(GMAIL_USER); // שלח לעצמך

    $mail->Subject = 'Test CoursyLand';
    $mail->Body    = 'בדיקת שליחת מייל מ-CoursyLand Admin';

    $mail->send();
    echo "\n✅ מייל נשלח בהצלחה!";
} catch (MailException $e) {
    echo "\n❌ שגיאה: " . $mail->ErrorInfo;
}
