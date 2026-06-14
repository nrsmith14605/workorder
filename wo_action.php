<?php
/**
 * wo_action.php
 * Handles all work order status transitions for the full approval chain.
 * Called via fetch() POST from main.php JavaScript.
 *
 * POST params: action, order_id, note
 * For mt_assign / mm_assign: also assignees (JSON array of {email, name})
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
$note         = trim($_POST['note']         ?? '');
$new_priority = trim($_POST['new_priority'] ?? '');
$old_priority = trim($_POST['old_priority'] ?? '');
$assignees_raw = trim($_POST['assignees']   ?? '');

if (!$action || !$order_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters.']);
    exit;
}

// ── Role → allowed actions ──────────────────────────────────────────────────
$allowed = [
    'BT' => ['bt_complete', 'bt_reject', 'bt_approve', 'note_only'],
    'BP' => ['bp_approve', 'bp_reject', 'bp_complete', 'note_only'],
    'MT' => ['mt_assign', 'mt_complete', 'mt_reject', 'note_only',
             'bt_complete', 'bt_reject', 'bt_approve', 'bp_approve', 'bp_reject', 'bp_complete'],
    'MM' => ['mm_assign', 'mm_complete', 'mm_reject', 'note_only',
             'bp_approve', 'bp_reject', 'bp_complete'],
    'A'  => ['bt_complete', 'bt_reject', 'bt_approve',
             'bp_approve', 'bp_reject', 'bp_complete',
             'mt_assign', 'mt_complete', 'mt_reject',
             'mm_assign', 'mm_complete', 'mm_reject',
             'worker_complete', 'note_only'],
    'MW' => ['worker_complete', 'note_only'],
    'BC' => ['worker_complete', 'note_only'],
    'BM' => ['worker_complete', 'note_only'],
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
$order_type     = $order['type'];

// ── Validate legal transition ───────────────────────────────────────────────
$valid_transitions = [
    'bt_approve'      => 'Pending Approval',
    'bt_reject'       => 'Pending Approval',
    'bt_complete'     => 'Pending Approval',
    'bp_approve'      => ['Pending Approval', 'Approved'],  // Pending for Maint, Approved for Tech
    'bp_reject'       => ['Pending Approval', 'Approved'],
    'bp_complete'     => ['Pending Approval', 'Approved'],
    'mt_assign'       => 'Approved',
    'mt_complete'     => ['Approved', 'In Progress'],
    'mt_reject'       => ['Approved', 'In Progress'],
    'mm_assign'       => 'Approved',
    'mm_complete'     => ['Approved', 'In Progress'],
    'mm_reject'       => ['Approved', 'In Progress'],
    'worker_complete' => 'In Progress',
];

if (!in_array($action, ['note_only', 'priority_only']) && !in_array($user_role, ['A', 'MT', 'MM'])) {
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
    'BT' => 'Building Technician',
    'BP' => 'Building Principal',
    'MT' => 'Technology Manager',
    'MM' => 'Maintenance Manager',
    'A'  => 'Administrator',
    'MW' => 'Maintenance Worker',
    'BC' => 'Building Custodian',
    'BM' => 'Building Maintenance',
];
$actor_role_label = $role_labels[$user_role] ?? $user_role;

// ── New status map ──────────────────────────────────────────────────────────
$status_map = [
    'note_only'       => null,  // status unchanged
    'priority_only'   => null,  // status unchanged
    'bt_approve'      => 'Approved',
    'bt_reject'       => 'Rejected',
    'bt_complete'     => 'Completed',
    'bp_approve'      => 'Approved',
    'bp_reject'       => 'Rejected',
    'bp_complete'     => 'Completed',
    'mt_assign'       => 'In Progress',
    'mt_complete'     => 'Completed',
    'mt_reject'       => 'Rejected',
    'mm_assign'       => 'In Progress',
    'mm_complete'     => 'Completed',
    'mm_reject'       => 'Rejected',
    'worker_complete' => 'Completed',
];
$new_status = $status_map[$action] ?? $current_status;

// ── Handler map ─────────────────────────────────────────────────────────────
$handler_map = [
    'priority_only'   => $order['current_handler'],  // unchanged
    'bt_approve'      => 'BP',
    'bt_reject'       => null,
    'bt_complete'     => null,
    'bp_approve'      => $order_type === 'Technology' ? 'MT' : 'MM',
    'bp_reject'       => null,
    'bp_complete'     => null,
    'mt_assign'       => 'worker',
    'mt_complete'     => null,
    'mt_reject'       => null,
    'mm_assign'       => 'worker',
    'mm_complete'     => null,
    'mm_reject'       => null,
    'worker_complete' => null,
];
$new_handler = array_key_exists($action, $handler_map) ? $handler_map[$action] : $order['current_handler'];

// ── Action log labels ───────────────────────────────────────────────────────
$action_labels = [
    'note_only'       => 'Note added',
    'priority_only'   => 'Priority updated',
    'bt_approve'      => 'Approved — escalated to Building Principal',
    'bt_reject'       => 'Rejected',
    'bt_complete'     => 'Marked Completed',
    'bp_approve'      => 'Approved — escalated to Manager',
    'bp_reject'       => 'Rejected',
    'bp_complete'     => 'Marked Completed',
    'mt_assign'       => 'Assigned — In Progress',
    'mt_complete'     => 'Marked Completed',
    'mt_reject'       => 'Rejected',
    'mm_assign'       => 'Assigned — In Progress',
    'mm_complete'     => 'Marked Completed',
    'mm_reject'       => 'Rejected',
    'worker_complete' => 'Marked Completed',
];
$action_label = $action_labels[$action] ?? $action;

// ── Build log entry ─────────────────────────────────────────────────────────
$timestamp = date('m/d/Y g:i A');
$log_entry = "\n[{$timestamp}] {$user_name} ({$actor_role_label}) -- {$action_label}";

$valid_priorities = ['Low', 'Mid', 'High', 'Urgent'];
$priority_changed = in_array($new_priority, $valid_priorities) && $new_priority !== $order['priority'];
if ($priority_changed) {
    $from = $order['priority'] ?: $old_priority;
    $log_entry .= "\nPriority changed from {$from} to {$new_priority}";
}
if ($note !== '') $log_entry .= "\n{$note}";

// ── Terminal actions set resolved_by ────────────────────────────────────────
$terminal_actions = [
    'bt_reject', 'bt_complete',
    'bp_reject', 'bp_complete',
    'mt_reject', 'mt_complete',
    'mm_reject', 'mm_complete',
    'worker_complete',
];
$resolved_by = in_array($action, $terminal_actions) ? $user_name : null;

// ── Parse assignees for mt_assign / mm_assign ───────────────────────────────
$assignees = [];
$is_assign = in_array($action, ['mt_assign', 'mm_assign']);
if ($is_assign && $assignees_raw) {
    $assignees = json_decode($assignees_raw, true) ?? [];
    if (!empty($assignees)) {
        $names = array_column($assignees, 'name');
        $log_entry .= "\nAssigned to: " . implode(', ', $names);
    }
}

// ── Write order update to DB ────────────────────────────────────────────────
if ($resolved_by !== null) {
    $stmt = $db->prepare(
        "UPDATE orders SET status=?, current_handler=?, priority=IF(?='',priority,?), notes=CONCAT(COALESCE(notes,''),?), resolved_by=? WHERE id=?"
    );
    $stmt->bind_param('ssssssi', $new_status, $new_handler, $new_priority, $new_priority, $log_entry, $resolved_by, $order_id);
} else {
    $stmt = $db->prepare(
        "UPDATE orders SET status=?, current_handler=?, priority=IF(?='',priority,?), notes=CONCAT(COALESCE(notes,''),?) WHERE id=?"
    );
    $stmt->bind_param('sssssi', $new_status, $new_handler, $new_priority, $new_priority, $log_entry, $order_id);
}
// note_only and priority_only — update DB and return early, no emails
if (in_array($action, ['note_only', 'priority_only'])) {
    $stmt = $db->prepare(
        "UPDATE orders SET priority=IF(?='',priority,?), notes=CONCAT(COALESCE(notes,''),?) WHERE id=?"
    );
    $stmt->bind_param('sssi', $new_priority, $new_priority, $log_entry, $order_id);
    $stmt->execute();
    $stmt->close();
    $db->close();
    echo json_encode([
        'success'      => true,
        'message'      => 'Saved.',
        'new_status'   => $current_status,
        'new_priority' => $priority_changed ? $new_priority : null,
        'log_entry'    => ltrim($log_entry, "\n"),
    ]);
    exit;
}
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if (!$affected) {
    echo json_encode(['success' => false, 'message' => 'Database update failed.']);
    $db->close(); exit;
}

// ── Write assignments to order_assignments ──────────────────────────────────
if ($is_assign && !empty($assignees)) {
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

// ── Send emails (after JSON response would normally be flushed) ─────────────
require_once __DIR__ . '/wo_mailer.php';

// Rejection → email submitter
if (in_array($action, ['bt_reject', 'bp_reject', 'mt_reject', 'mm_reject'])) {
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
if (in_array($action, ['bt_complete', 'bp_complete', 'mt_complete', 'mm_complete', 'worker_complete'])) {
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

// BT approves tech order → email BP(s) for this building
if ($action === 'bt_approve') {
    send_bp_notification_email(
        $db,
        $wo_num,
        $updated_order['building'],
        $updated_order['room'],
        $updated_order['problem_type'],
        $updated_order['description'],
        $updated_order['priority'],
        $updated_order['submitted_name'],
        $updated_order['submitted_by'],
        $user_name,
        'Technology'
    );
}

// BP approves maintenance order (no BT step) → email BP first, then when BP approves → MM
// BP approves tech order → email MT
if ($action === 'bp_approve') {
    if ($order_type === 'Technology') {
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
            $user_name,
            'Technology'   // → notify MT
        );
    } else {
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
            $user_name,
            'Maintenance'  // → notify MM
        );
    }
}

// User submits maintenance → BP gets notified (this is handled in main.php on submit)
// MT or MM assigns → email each assigned worker
if ($is_assign && !empty($assignees)) {
    $manager_label = ($action === 'mt_assign') ? 'Technology Manager' : 'Maintenance Manager';
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
                $manager_label,
                $note
            );
        }
    }
}

$db->close();

echo json_encode([
    'success'      => true,
    'message'      => 'Work order updated successfully.',
    'new_status'   => $new_status,
    'new_priority' => $priority_changed ? $new_priority : null,
    'log_entry'    => ltrim($log_entry, "\n"),
]);
