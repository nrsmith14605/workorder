<?php
session_start();
if (!isset($_SESSION['google_user'])) {
    http_response_code(401);
    exit;
}

$user_email = $_SESSION['google_user']['email'] ?? '';
if (!$user_email) { http_response_code(400); exit; }

require_once __DIR__ . '/../../../wo_config.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['endpoint'])) { http_response_code(400); exit; }

$endpoint = $data['endpoint'];
$p256dh   = $data['keys']['p256dh'] ?? '';
$auth     = $data['keys']['auth']   ?? '';

if (!$p256dh || !$auth) { http_response_code(400); exit; }

$stmt = $db->prepare(
    "INSERT INTO push_subscriptions (user_email, endpoint, p256dh, auth)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), updated_at = NOW()"
);
$stmt->bind_param('ssss', $user_email, $endpoint, $p256dh, $auth);
$stmt->execute();
$stmt->close();
$db->close();

http_response_code(201);
echo json_encode(['ok' => true]);
