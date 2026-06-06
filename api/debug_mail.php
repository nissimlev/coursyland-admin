<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';

startSession();
if (!isLoggedIn()) { die('Unauthorized'); }

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

echo "BREVO_SMTP_LOGIN: " . BREVO_SMTP_LOGIN . "\n";
echo "BREVO_SMTP_KEY length: " . strlen(BREVO_SMTP_KEY) . " chars\n\n";

$mail = new PHPMailer(true);
$mail->SMTPDebug  = SMTP::DEBUG_SERVER;
$mail->Debugoutput = function($str, $level) { echo $str . "\n"; };

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp-relay.brevo.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = BREVO_SMTP_LOGIN;
    $mail->Password   = BREVO_SMTP_KEY;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(GMAIL_USER, 'CoursyLand Test');
    $mail->addAddress(GMAIL_USER);

    $mail->Subject = 'Test CoursyLand - Brevo';
    $mail->Body    = 'בדיקת שליחת מייל מ-CoursyLand Admin דרך Brevo';

    $mail->send();
    echo "\n✅ מייל נשלח בהצלחה!";
} catch (MailException $e) {
    echo "\n❌ שגיאה: " . $mail->ErrorInfo;
}
