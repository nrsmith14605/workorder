<?php
/**
 * wo_action.php
 * Handles all work order status transitions for the full approval chain.
 * Called via fetch() POST from main.php JavaScript.
 *
 * POST params: action, order_id, note
 * For m_assign: also assignees (JSON array of {email, name})
 *
 * Returns JSON: { success, message, new_status, log_entry }
 */

session_start();
header('Content-Type: application/json');

// ── Auth guard ──────────────────────────────────────────────────────────────
if (!isset($_SESSION['google_user'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user             = $_SESSION['google_user'];
$user_email       = $user['email'];
$user_name        = $user['name'] ?? $user_email;
$user_role        = $_SESSION['user_role'] ?? 'U';

// ── Input ───────────────────────────────────────────────────────────────────
$action   = trim($_POST['action']    ?? '');
$order_id = (int)($_POST['order_id'] ?? 0);
$note     = trim($_POST['note']      ?? '');
$assignees_raw = trim($_POST['assignees'] ?? '');

if (!$action || !$order_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters.']);
    exit;
}

// ── Role → allowed actions ──────────────────────────────────────────────────
$allowed = [
    'BT' => ['bt_complete', 'bt_reject', 'bt_approve'],
    'BA' => ['ba_approve', 'ba_reject'],
    'M'  => ['m_assign', 'm_complete', 'm_reject',
             'bt_complete', 'bt_reject', 'bt_approve', 'ba_approve', 'ba_reject'],
    'A'  => ['m_assign', 'm_complete', 'm_reject',
             'bt_complete', 'bt_reject', 'bt_approve', 'ba_approve', 'ba_reject'],
    'MD' => ['worker_complete'],
    'BC' => ['worker_complete'],
    'BM' => ['worker_complete'],
];

if (!in_array($action, $allowed[$user_role] ?? [])) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action.']);
    exit;
}

// ── DB ──────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../wo_config.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');

// ── Fetch current order ─────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->bind_param('i', $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    echo json_encode(['success' => false, 'message' => 'Work order not found.']);
    $db->close(); exit;
}

$current_status = $order['status'];

// ── Validate legal transition ───────────────────────────────────────────────
$valid_transitions = [
    'bt_approve'     => 'Pending Approval',
    'bt_reject'      => 'Pending Approval',
    'bt_complete'    => 'Pending Approval',
    'ba_approve'     => ['Pending Approval', 'Approved'],  // Pending for Maint, Approved for Tech
    'ba_reject'      => ['Pending Approval', 'Approved'],
    'm_assign'       => 'Approved',
    'm_complete'     => ['Approved', 'In Progress'],
    'm_reject'       => ['Approved', 'In Progress'],
    'worker_complete'=> 'In Progress',
];

if (!in_array($user_role, ['A', 'M'])) {
    $valid = $valid_transitions[$action] ?? null;
    $ok = is_array($valid)
        ? in_array($current_status, $valid)
        : ($valid === $current_status);
    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'This action is not valid for the current work order status.']);
        $db->close(); exit;
    }
}

// ── Role labels ─────────────────────────────────────────────────────────────
$role_labels = [
    'BT' => 'Building Tech',
    'BA' => 'Building Admin',
    'M'  => 'Manager',
    'A'  => 'Admin',
    'MD' => 'Maintenance Dept',
    'BC' => 'Building Custodian',
    'BM' => 'Building Maintenance',
];
$actor_role_label = $role_labels[$user_role] ?? $user_role;

// ── New status map ──────────────────────────────────────────────────────────
$status_map = [
    'bt_approve'      => 'Approved',
    'bt_reject'       => 'Rejected',
    'bt_complete'     => 'Completed',
    'ba_approve'      => 'Approved',
    'ba_reject'       => 'Rejected',
    'm_assign'        => 'In Progress',
    'm_complete'      => 'Completed',
    'm_reject'        => 'Rejected',
    'worker_complete' => 'Completed',
];
$new_status = $status_map[$action];

// ── Action log labels ───────────────────────────────────────────────────────
$action_labels = [
    'bt_approve'      => 'Approved — escalated to Building Admin',
    'bt_reject'       => 'Rejected',
    'bt_complete'     => 'Marked Completed',
    'ba_approve'      => 'Approved — escalated to Manager',
    'ba_reject'       => 'Rejected',
    'm_assign'        => 'Assigned — In Progress',
    'm_complete'      => 'Marked Completed',
    'm_reject'        => 'Rejected',
    'worker_complete' => 'Marked Completed',
];
$action_label = $action_labels[$action] ?? $action;

// ── Build log entry ─────────────────────────────────────────────────────────
$timestamp = date('m/d/Y g:i A');
$log_entry = "\n[{$timestamp}] {$user_name} ({$actor_role_label}) → {$action_label}";
if ($note !== '') $log_entry .= "\n{$note}";

// ── Terminal actions set resolved_by ────────────────────────────────────────
$terminal_actions = ['bt_reject','bt_complete','ba_reject','m_reject','m_complete','worker_complete'];
$resolved_by = in_array($action, $terminal_actions) ? $user_name : null;

// ── Parse assignees for m_assign ───────────────────────────────────────────
$assignees = [];
if ($action === 'm_assign' && $assignees_raw) {
    $assignees = json_decode($assignees_raw, true) ?? [];
    if (!empty($assignees)) {
        $names = array_column($assignees, 'name');
        $log_entry .= "\nAssigned to: " . implode(', ', $names);
    }
}

// ── Write order update to DB ────────────────────────────────────────────────
if ($resolved_by !== null) {
    $stmt = $db->prepare(
        "UPDATE orders SET status=?, notes=CONCAT(COALESCE(notes,''),?), resolved_by=? WHERE id=?"
    );
    $stmt->bind_param('sssi', $new_status, $log_entry, $resolved_by, $order_id);
} else {
    $stmt = $db->prepare(
        "UPDATE orders SET status=?, notes=CONCAT(COALESCE(notes,''),?) WHERE id=?"
    );
    $stmt->bind_param('ssi', $new_status, $log_entry, $order_id);
}
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if (!$affected) {
    echo json_encode(['success' => false, 'message' => 'Database update failed.']);
    $db->close(); exit;
}

// ── Write assignments to order_assignments ──────────────────────────────────
if ($action === 'm_assign' && !empty($assignees)) {
    $stmt = $db->prepare(
        "INSERT IGNORE INTO order_assignments (order_id, user_email, user_name) VALUES (?,?,?)"
    );
    foreach ($assignees as $a) {
        if (!empty($a['email']) && !empty($a['name'])) {
            $stmt->bind_param('iss', $order_id, $a['email'], $a['name']);
            $stmt->execute();
        }
    }
    $stmt->close();
}

// ── Reload order ────────────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->bind_param('i', $order_id);
$stmt->execute();
$updated_order = $stmt->get_result()->fetch_assoc();
$stmt->close();

$wo_num = 'WO-' . str_pad($order_id, 6, '0', STR_PAD_LEFT);

// ── Send emails ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/wo_mailer.php';

// Rejection → email submitter
if (in_array($action, ['bt_reject', 'ba_reject', 'm_reject'])) {
    send_rejection_email(
        $wo_num,
        $updated_order['submitted_name'],
        $updated_order['submitted_by'],
        $updated_order['building'],
        $updated_order['room'],
        $updated_order['problem_type'],
        $updated_order['priority'],
        $note,
        $user_name,
        $actor_role_label
    );
}

// Completion → email submitter
if (in_array($action, ['bt_complete', 'm_complete', 'worker_complete'])) {
    send_completion_email(
        $wo_num,
        $updated_order['submitted_name'],
        $updated_order['submitted_by'],
        $updated_order['building'],
        $updated_order['room'],
        $updated_order['problem_type'],
        $updated_order['priority'],
        $note,
        $user_name,
        $actor_role_label
    );
}

// BT approves → email BA(s) only
if ($action === 'bt_approve') {
    send_ba_notification_email(
        $db,
        $wo_num,
        $updated_order['building'],
        $updated_order['room'],
        $updated_order['problem_type'],
        $updated_order['description'],
        $updated_order['priority'],
        $updated_order['submitted_name'],
        $updated_order['submitted_by'],
        $user_name
    );
}

// BA approves → email Manager(s) only — NOT Admin
if ($action === 'ba_approve') {
    send_manager_notification_email(
        $db,
        $wo_num,
        $updated_order['building'],
        $updated_order['room'],
        $updated_order['problem_type'],
        $updated_order['description'],
        $updated_order['priority'],
        $updated_order['submitted_name'],
        $updated_order['submitted_by'],
        $user_name
    );
}

// Manager assigns → email each assigned worker
if ($action === 'm_assign' && !empty($assignees)) {
    foreach ($assignees as $a) {
        if (!empty($a['email']) && !empty($a['name'])) {
            send_assignment_email(
                $wo_num,
                $a['name'],
                $a['email'],
                $updated_order['building'],
                $updated_order['room'],
                $updated_order['problem_type'],
                $updated_order['description'],
                $updated_order['priority'],
                $updated_order['submitted_name'],
                $user_name,
                $note
            );
        }
    }
}

$db->close();

echo json_encode([
    'success'    => true,
    'message'    => 'Work order updated successfully.',
    'new_status' => $new_status,
    'log_entry'  => ltrim($log_entry, "\n"),
]);
