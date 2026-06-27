<?php
session_start();

if (!isset($_SESSION['google_user'])) {
    header('Location: index.php');
    exit;
}

$user          = $_SESSION['google_user'];
$user_email    = $user['email'];
$user_name     = $user['name']    ?? 'User';
$user_role     = $_SESSION['user_role']     ?? 'U';
$user_building = $_SESSION['user_building'] ?? null;

// Parse ?wo=WO-000001
$wo_param = trim($_GET['wo'] ?? '');
$order_id = 0;
if (preg_match('/^WO-(\d+)$/i', $wo_param, $m)) {
    $order_id = (int)$m[1];
} elseif (ctype_digit($wo_param) && $wo_param !== '') {
    $order_id = (int)$wo_param;
}
if (!$order_id) { header('Location: main.php'); exit; }

require_once __DIR__ . '/../../wo_config.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');

$stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->bind_param('i', $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) { $db->close(); header('Location: main.php'); exit; }

// Role-based access check (mirrors wo_detail.php exactly)
$can_view = false;
if ($user_role === 'A') {
    $can_view = true;
} elseif ($user_role === 'MT') {
    $can_view = $order['type'] === 'Technology'
        && (in_array($order['current_handler'], ['MT','worker']) || in_array($order['status'], ['Completed','Rejected']));
} elseif ($user_role === 'MM') {
    $can_view = $order['type'] === 'Maintenance'
        && (in_array($order['current_handler'], ['MM','worker']) || in_array($order['status'], ['Completed','Rejected']));
} elseif ($user_role === 'BP') {
    $can_view = ($order['building'] === $user_building);
} elseif ($user_role === 'BT') {
    $bt_blds  = array_filter(array_map('trim', explode(',', $user_building ?? '')));
    $can_view = in_array($order['building'], $bt_blds) && $order['type'] === 'Technology';
} elseif (in_array($user_role, ['MW','BC','BM'])) {
    $stmt2 = $db->prepare("SELECT 1 FROM order_assignments WHERE order_id=? AND user_email=? LIMIT 1");
    $stmt2->bind_param('is', $order_id, $user_email);
    $stmt2->execute();
    $can_view = $stmt2->get_result()->num_rows > 0;
    $stmt2->close();
} else {
    $can_view = ($order['submitted_by'] === $user_email);
}

if (!$can_view) { $db->close(); header('Location: main.php'); exit; }

$wo_num = 'WO-' . str_pad($order['id'], 6, '0', STR_PAD_LEFT);

// Assigned workers
$assigned_workers = [];
$stmt3 = $db->prepare("SELECT user_name, user_email FROM order_assignments WHERE order_id=?");
$stmt3->bind_param('i', $order_id);
$stmt3->execute();
$r3 = $stmt3->get_result();
while ($w = $r3->fetch_assoc()) $assigned_workers[] = $w;
$stmt3->close();

$db->close();

$is_maint     = $order['type'] === 'Maintenance';
$notes_text   = ltrim($order['notes'] ?? '', "\n");
$generated_by = $user_name;
$generated_at = date('F j, Y \a\t g:i A');

require_once __DIR__ . '/dompdf/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$logo_path = __DIR__ . '/images/white_logo.png';
$logo_src  = file_exists($logo_path)
    ? 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path))
    : '';

function wo_print_fmt_date(?string $d): string {
    return ($d && $d !== '0000-00-00') ? date('m/d/Y', strtotime($d)) : '—';
}

$css = '
@page { margin: 1in 0 0 0; }
body { font-family: Helvetica, Arial, sans-serif; margin: 0; padding: 0; font-size: 9pt; color: #222; }
.header { background: #19304e; width: 100%; padding: 0 0.5in; height: 1.1in; display: table; margin-top: -1in; }
.header-inner { display: table-cell; vertical-align: middle; padding-top: 0.15in; }
.header-org { color: #ffffff; font-size: 24pt; font-weight: bold; margin: 0; line-height: 1.2; }
.header-sub { color: #ffffff; font-size: 11pt; font-weight: normal; margin: 5px 0 0 0; opacity: .82; }
.content { margin: 0.3in 0.5in 0.85in; }
.footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: center;
    font-size: 7.5pt; color: #666; padding: 10px 0; border-top: 1px solid #ddd; background: #fff; }
.wo-title { font-size: 16pt; font-weight: bold; color: #19304e; margin: 0 0 4px; }
.badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 7.5pt; font-weight: bold; }
.badge-pending    { background: #fef3c7; color: #92400e; }
.badge-approved   { background: #d1fae5; color: #065f46; }
.badge-inprogress { background: #dbeafe; color: #1e40af; }
.badge-completed  { background: #f0fdf4; color: #166534; }
.badge-rejected   { background: #fee2e2; color: #991b1b; }
.section-head { font-size: 7.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: .07em;
    color: #19304e; border-bottom: 2px solid #19304e; padding-bottom: 4px; margin: 18px 0 10px; }
.field-grid { width: 100%; border-collapse: collapse; margin-bottom: 0; }
.field-grid td { padding: 4px 12px 8px 0; vertical-align: top; }
.field-label { font-size: 7pt; font-weight: bold; text-transform: uppercase;
    letter-spacing: .07em; color: #aab0bb; display: block; margin-bottom: 2px; }
.field-value { font-size: 9pt; color: #1a1a2e; font-weight: 500; }
.desc-box { background: #f8f9fa; border-left: 3px solid #19304e; padding: 10px 14px;
    font-size: 9pt; color: #3d4f5e; line-height: 1.65; white-space: pre-wrap; word-break: break-word; }
.log-box  { background: #f8f9fa; border: 1px solid #e8ecf0; padding: 10px 14px;
    font-size: 8pt; color: #555; line-height: 1.7; white-space: pre-wrap; word-break: break-word; }
.worker-row { padding: 5px 0; border-bottom: 1px solid #f0f4f8; font-size: 9pt; color: #1a1a2e; }
.worker-row:last-child { border-bottom: none; }
';

$status_cls = [
    'Pending Approval' => 'badge-pending',
    'Approved'         => 'badge-approved',
    'In Progress'      => 'badge-inprogress',
    'Completed'        => 'badge-completed',
    'Rejected'         => 'badge-rejected',
];
$pri_colors = ['Low' => '#6b7a8d', 'Mid' => '#2563eb', 'High' => '#d97706', 'Urgent' => '#dc2626'];

$status   = $order['status'];
$priority = $order['priority'] ?? '—';
$logo_tag = $logo_src ? '<img src="' . $logo_src . '" alt="WCSC Logo" style="height:0.75in;width:auto;">' : '';

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>
<div class="header"><div class="header-inner">
  <table style="border-collapse:collapse;width:auto;margin:0;border:0"><tr>
    <td style="padding:0;vertical-align:middle;text-align:left;width:66px;background:transparent;border:0">' . $logo_tag . '</td>
    <td style="padding:0 0 0 18px;vertical-align:middle;background:transparent;border:0">
      <p class="header-org">Warrick County School Corporation</p>
      <p class="header-sub">Work Order Management System</p>
    </td>
  </tr></table>
</div></div>
<div class="footer">Warrick County School Corporation &nbsp;|&nbsp; 300 E. Gum St., Boonville, IN 47601 &nbsp;|&nbsp; 812-897-6588</div>
<div class="content">

<p class="wo-title">' . htmlspecialchars($wo_num) . ' &mdash; ' . htmlspecialchars($order['type']) . ' Request</p>
<table style="border-collapse:collapse;margin:0 0 18px;font-size:8.5pt;color:#6b7a8d"><tr>
    <td style="vertical-align:middle;padding:0 8px 0 0;white-space:nowrap">
        <span class="badge ' . ($status_cls[$status] ?? 'badge-pending') . '">' . htmlspecialchars($status) . '</span>
    </td>
    <td style="vertical-align:middle;padding:0 8px 0 0;white-space:nowrap">
        <strong style="color:' . ($pri_colors[$priority] ?? '#6b7a8d') . '">' . htmlspecialchars($priority) . ' Priority</strong>
    </td>
    <td style="vertical-align:middle;padding:0;color:#6b7a8d;white-space:nowrap">
        &middot;&nbsp; Printed: ' . $generated_at . ' &nbsp;&middot;&nbsp; By: ' . htmlspecialchars($generated_by) . '
    </td>
</tr></table>

<div class="section-head">Request Details</div>
<table class="field-grid"><tbody>
<tr>
    <td style="width:25%"><span class="field-label">Building</span><span class="field-value">' . htmlspecialchars($order['building'] ?? '—') . '</span></td>
    <td style="width:25%"><span class="field-label">Room</span><span class="field-value">' . htmlspecialchars($order['room'] ?? '—') . '</span></td>
    <td style="width:25%"><span class="field-label">Problem Type</span><span class="field-value">' . htmlspecialchars($order['problem_type'] ?? '—') . '</span></td>
    <td style="width:25%"><span class="field-label">Date Submitted</span><span class="field-value">' . wo_print_fmt_date($order['created_at']) . '</span></td>
</tr>
<tr>
    <td colspan="2"><span class="field-label">Submitted By</span><span class="field-value">'
        . htmlspecialchars($order['submitted_name'] ?: $order['submitted_by'])
        . ' &lt;' . htmlspecialchars($order['submitted_by']) . '&gt;</span></td>';

if ($is_maint && !empty($order['purpose'])) {
    $html .= '<td><span class="field-label">Purpose</span><span class="field-value">' . htmlspecialchars($order['purpose']) . '</span></td>';
} else {
    $html .= '<td></td>';
}

if (!empty($order['time_from']) && !empty($order['time_to'])) {
    $html .= '<td><span class="field-label">Time Available</span><span class="field-value">' . htmlspecialchars($order['time_from']) . ' &ndash; ' . htmlspecialchars($order['time_to']) . '</span></td>';
} else {
    $html .= '<td></td>';
}

$html .= '</tr>';

if (!empty($order['resolved_by'])) {
    $html .= '<tr><td colspan="4"><span class="field-label">Resolved By</span><span class="field-value">' . htmlspecialchars($order['resolved_by']) . '</span></td></tr>';
}

$html .= '</tbody></table>

<div class="section-head">Description</div>
<div class="desc-box">' . htmlspecialchars($order['description'] ?? '—') . '</div>';

if (!empty($assigned_workers)) {
    $html .= '<div class="section-head">Assigned Workers</div>';
    foreach ($assigned_workers as $w) {
        $html .= '<div class="worker-row"><strong>' . htmlspecialchars($w['user_name']) . '</strong>'
               . ' &nbsp;<span style="color:#6b7a8d;font-size:8pt">' . htmlspecialchars($w['user_email']) . '</span></div>';
    }
}

if ($notes_text) {
    $html .= '<div class="section-head">Activity Log</div>';
    $html .= '<div class="log-box">' . htmlspecialchars($notes_text) . '</div>';
}

$html .= '</div></body></html>';

$options = new Options();
$options->set('defaultFont', 'Helvetica');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();

$filename = strtolower(str_replace('-', '', $wo_num)) . '-' . date('Ymd') . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);
