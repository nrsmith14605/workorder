<?php
require_once __DIR__ . '/../../wo_config.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ── 1. Test DB — can we find a BT user? ──────────────────────
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');
$res  = $conn->query("SELECT first_name, last_name, email, building FROM users WHERE role='BT' AND active=1");
echo "<h3>BT Users in DB:</h3>";
if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        echo "Name: {$row['first_name']} {$row['last_name']} | Email: {$row['email']} | Building: {$row['building']}<br>";
    }
} else {
    echo "<strong style='color:red'>No active BT users found!</strong><br>";
}
$conn->close();

// ── 2. Test PHPMailer — can we connect to Gmail? ─────────────
echo "<h3>Mail Test:</h3>";
try {
    $mail = new PHPMailer(true);
    $mail->SMTPDebug  = 2;
    $mail->isSMTP();
    $mail->Host       = 'localhost';   // A2 local mail server
    $mail->SMTPAuth   = false;         // no auth needed for localhost
    $mail->SMTPSecure = false;         // no encryption on localhost
    $mail->Port       = 25;

    $mail->setFrom('wcsc.workorders@chs-cs.com', 'Warrick County Work Order System');
    $mail->addAddress(MAIL_USER);      // sends to your Gmail to verify
    $mail->Subject = 'WO Mail Test';
    $mail->Body    = 'If you got this, local SMTP is working!';

    $mail->send();
    echo "<strong style='color:green'>Email sent successfully!</strong>";
} catch (Exception $e) {
    echo "<strong style='color:red'>FAILED: " . $e->getMessage() . "</strong>";
}
?>