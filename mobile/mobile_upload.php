<?php
/**
 * mobile_upload.php
 * Handles combined note + photo upload from the mobile detail page.
 * Appends photos to order's photo_path, saves note to orders.notes.
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['google_user'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user       = $_SESSION['google_user'];
$user_email = $user['email'];
$user_name  = $user['name'] ?? $user_email;
$user_role  = $_SESSION['user_role'] ?? 'U';

if (!in_array($user_role, ['MW','BC','BM','MM'])) {
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

$order_id = (int)($_POST['order_id'] ?? 0);
$note     = trim($_POST['note'] ?? '');

if (!$order_id) {
    echo json_encode(['success' => false, 'message' => 'Missing order ID.']);
    exit;
}

require_once __DIR__ . '/../../../wo_config.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');

// Fetch order
$stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->bind_param('i', $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    $db->close();
    echo json_encode(['success' => false, 'message' => 'Order not found.']);
    exit;
}

// Verify access
if ($user_role === 'MM') {
    if ($order['type'] !== 'Maintenance') {
        $db->close();
        echo json_encode(['success' => false, 'message' => 'Not authorized.']);
        exit;
    }
} else {
    $chk = $db->prepare("SELECT 1 FROM order_assignments WHERE order_id=? AND user_email=?");
    $chk->bind_param('is', $order_id, $user_email);
    $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) {
        $chk->close(); $db->close();
        echo json_encode(['success' => false, 'message' => 'Not authorized.']);
        exit;
    }
    $chk->close();
}

// Process uploaded photos
$new_paths = [];
if (!empty($_FILES['photos']['name'][0])) {
    require_once __DIR__ . '/../includes/image_upload.php';
    $saved = process_uploaded_images($_FILES['photos'], __DIR__ . '/../wo_imgs/');

    // Rename to WO-XXXXXX prefix
    $wo_num = 'WO-' . str_pad($order_id, 6, '0', STR_PAD_LEFT);
    foreach ($saved as $p) {
        $new_filename = $wo_num . '_' . date('Ymd') . '_' . substr(uniqid(), -6) . '.jpg';
        $old_full     = __DIR__ . '/../' . $p;
        $new_full     = __DIR__ . '/../wo_imgs/' . $new_filename;
        $new_paths[]  = rename($old_full, $new_full) ? 'wo_imgs/' . $new_filename : $p;
    }
}

// Build log entry
$role_labels = [
    'MW' => 'Maintenance Worker',
    'BC' => 'Building Custodian',
    'BM' => 'Building Maintenance',
    'MM' => 'Maintenance Manager',
];
$actor_role_label = $role_labels[$user_role] ?? $user_role;
$timestamp  = date('m/d/Y g:i A');
$log_entry  = "\n[{$timestamp}] {$user_name} ({$actor_role_label}) -- Note added";
if ($note !== '') $log_entry .= "\n{$note}";
if (!empty($new_paths)) {
    $count = count($new_paths);
    $log_entry .= "\n" . $count . ' photo' . ($count > 1 ? 's' : '') . ' attached.';
}

// Append photos to photo_path
if (!empty($new_paths)) {
    $existing   = trim($order['photo_path'] ?? '');
    $all_paths  = $existing !== '' ? $existing . '||' . implode('||', $new_paths) : implode('||', $new_paths);
    $stmt = $db->prepare("UPDATE orders SET photo_path=?, notes=CONCAT(COALESCE(notes,''),?) WHERE id=?");
    $stmt->bind_param('ssi', $all_paths, $log_entry, $order_id);
} else {
    $stmt = $db->prepare("UPDATE orders SET notes=CONCAT(COALESCE(notes,''),?) WHERE id=?");
    $stmt->bind_param('si', $log_entry, $order_id);
}

$stmt->execute();
$ok = $stmt->affected_rows > 0;
$stmt->close();
$db->close();

echo json_encode([
    'success'    => $ok,
    'message'    => $ok ? 'Saved.' : 'Database error.',
    'log_entry'  => ltrim($log_entry, "\n"),
    'new_photos' => $new_paths,
]);
