<?php
session_start();
if (!isset($_SESSION['google_user'])) { http_response_code(403); exit('Access denied.'); }
$user_role = $_SESSION['user_role'] ?? 'U';
if (!in_array($user_role, ['A','MT','MM'])) { http_response_code(403); exit('Access denied.'); }

require_once __DIR__ . '/dompdf/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../../wo_config.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');

// ── Inputs ───────────────────────────────────────────────────────────────────
$report_type = trim($_GET['report_type'] ?? '');
$building    = trim($_GET['building']    ?? '');
$aging_days  = max(1, (int)($_GET['aging_days']  ?? 14));
$period_days = max(1, (int)($_GET['period_days'] ?? 30));
$emp_email   = trim($_GET['emp_email']   ?? '');
$emp_name    = trim($_GET['emp_name']    ?? '');

$generated_by = $_SESSION['google_user']['name'] ?? 'Unknown';
$generated_at = date('F j, Y \a\t g:i A');

// ── Role scoping ──────────────────────────────────────────────────────────────
if ($user_role === 'MM')     $scoped_roles = ['MW','BM','BC'];
elseif ($user_role === 'MT') $scoped_roles = ['BT'];
else                          $scoped_roles = ['MW','BM','BC','BT'];

$role_labels = [
    'MW' => 'Maintenance Worker',
    'BM' => 'Building Maintenance',
    'BC' => 'Building Custodian',
    'BT' => 'Building Technician',
    'MT' => 'Technology Manager',
    'MM' => 'Maintenance Manager',
    'A'  => 'Administrator',
    'BP' => 'Building Principal',
];

// ── Logo ──────────────────────────────────────────────────────────────────────
$logo_path = __DIR__ . '/images/logo.png';
$logo_src  = file_exists($logo_path)
    ? 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path))
    : '';

// ── Helpers ───────────────────────────────────────────────────────────────────
function wo_num(int $id): string { return 'WO-' . str_pad($id, 6, '0', STR_PAD_LEFT); }
function fmt_date(?string $d): string { return ($d && $d !== '0000-00-00') ? date('m/d/Y', strtotime($d)) : '—'; }
function days_color(int $d): string {
    if ($d >= 30) return '#dc2626';
    if ($d >= 14) return '#d97706';
    return '#374151';
}
function priority_color(string $p): string {
    return match($p) { 'Urgent' => '#dc2626', 'High' => '#d97706', 'Mid' => '#2563eb', default => '#6b7a8d' };
}

// ── Validate employee-scoped reports ─────────────────────────────────────────
$emp_full_name  = '';
$emp_role_label = '';
if (in_array($report_type, ['completed_staff','current_staff']) && $emp_email) {
    $roles_ph = implode(',', array_fill(0, count($scoped_roles), '?'));
    $chk = $db->prepare(
        "SELECT first_name, last_name, role FROM users WHERE email = ? AND active = 1 AND role IN ({$roles_ph})"
    );
    $bind_args = array_merge([$emp_email], $scoped_roles);
    $chk->bind_param('s' . str_repeat('s', count($scoped_roles)), ...$bind_args);
    $chk->execute();
    $emp_row = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$emp_row) { $db->close(); http_response_code(403); exit('Invalid or unauthorized staff member.'); }
    $emp_full_name  = $emp_row['first_name'] . ' ' . $emp_row['last_name'];
    $emp_role_label = $role_labels[$emp_row['role']] ?? $emp_row['role'];
} elseif (in_array($report_type, ['completed_staff','current_staff']) && !$emp_email) {
    $db->close(); http_response_code(400); exit('Staff member required.');
}

// ── Valid buildings ────────────────────────────────────────────────────────────
$valid_buildings = ['CHS','BHS','THS','WPCC','CSMS','CNMS','BMS','LUM',
                    'CHAN','ELB','JHC','LOGE','LYN','NEWB','OAK','SHAR','TEN','TMS','WEC','YANK'];

// ── Queries ───────────────────────────────────────────────────────────────────
$orders = $open_orders = $closed_orders = $workers = [];
$report_label = $filter_line = $filename = '';

switch ($report_type) {

    // ── Active Maintenance ────────────────────────────────────────────────────
    case 'active_maint':
        $res = $db->query(
            "SELECT o.id, o.building, o.room, o.problem_type, o.priority, o.status,
                    o.submitted_name, o.submitted_by, o.created_at,
                    DATEDIFF(NOW(), o.created_at) AS days_open,
                    GROUP_CONCAT(oa.user_name ORDER BY oa.assigned_at SEPARATOR ', ') AS assignee_names
             FROM orders o
             LEFT JOIN order_assignments oa ON o.id = oa.order_id
             WHERE o.status NOT IN ('Completed','Rejected') AND o.type = 'Maintenance'
             GROUP BY o.id
             ORDER BY FIELD(o.priority,'Urgent','High','Mid','Low'), o.created_at ASC"
        );
        while ($r = $res->fetch_assoc()) $orders[] = $r;
        $report_label = 'Active Maintenance Orders';
        $filter_line  = count($orders) . ' open maintenance orders as of ' . date('m/d/Y');
        $filename     = 'wcsc-active-maintenance-' . date('Ymd') . '.pdf';
        break;

    // ── Active Technology ─────────────────────────────────────────────────────
    case 'active_tech':
        $res = $db->query(
            "SELECT o.id, o.building, o.room, o.problem_type, o.priority, o.status,
                    o.submitted_name, o.submitted_by, o.created_at,
                    DATEDIFF(NOW(), o.created_at) AS days_open,
                    GROUP_CONCAT(oa.user_name ORDER BY oa.assigned_at SEPARATOR ', ') AS assignee_names
             FROM orders o
             LEFT JOIN order_assignments oa ON o.id = oa.order_id
             WHERE o.status NOT IN ('Completed','Rejected') AND o.type = 'Technology'
             GROUP BY o.id
             ORDER BY FIELD(o.priority,'Urgent','High','Mid','Low'), o.created_at ASC"
        );
        while ($r = $res->fetch_assoc()) $orders[] = $r;
        $report_label = 'Active Technology Orders';
        $filter_line  = count($orders) . ' open technology orders as of ' . date('m/d/Y');
        $filename     = 'wcsc-active-technology-' . date('Ymd') . '.pdf';
        break;

    // ── Aging ─────────────────────────────────────────────────────────────────
    case 'aging':
        $type_clause = match($user_role) {
            'MM' => " AND o.type = 'Maintenance'",
            'MT' => " AND o.type = 'Technology'",
            default => '',
        };
        $stmt = $db->prepare(
            "SELECT o.id, o.building, o.type, o.problem_type, o.priority, o.status,
                    o.created_at, DATEDIFF(NOW(), o.created_at) AS days_open,
                    GROUP_CONCAT(oa.user_name ORDER BY oa.assigned_at SEPARATOR ', ') AS assignee_names
             FROM orders o
             LEFT JOIN order_assignments oa ON o.id = oa.order_id
             WHERE o.status NOT IN ('Completed','Rejected')
               AND DATEDIFF(NOW(), o.created_at) >= ?{$type_clause}
             GROUP BY o.id
             ORDER BY days_open DESC"
        );
        $stmt->bind_param('i', $aging_days);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $orders[] = $r;
        $stmt->close();
        $report_label = "Aging Report — Open >{$aging_days} Days";
        $filter_line  = count($orders) . " orders open more than {$aging_days} days";
        $filename     = "wcsc-aging-{$aging_days}d-" . date('Ymd') . '.pdf';
        break;

    // ── Completed by Staff ────────────────────────────────────────────────────
    case 'completed_staff':
        $stmt = $db->prepare(
            "SELECT o.id, o.building, o.problem_type, o.priority, o.created_at, o.resolved_by
             FROM orders o
             INNER JOIN order_assignments oa ON o.id = oa.order_id AND oa.user_email = ?
             WHERE o.status = 'Completed'
               AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY o.created_at DESC"
        );
        $stmt->bind_param('si', $emp_email, $period_days);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $orders[] = $r;
        $stmt->close();
        $period_label  = $period_days >= 365 ? 'last year' : "last {$period_days} days";
        $report_label  = "Completed Orders — {$emp_full_name}";
        $filter_line   = "{$emp_role_label} · " . ucfirst($period_label) . ' · ' . count($orders) . ' completed orders';
        $filename      = 'wcsc-completed-' . strtolower(str_replace(' ', '-', $emp_full_name)) . '-' . date('Ymd') . '.pdf';
        break;

    // ── Current by Staff ──────────────────────────────────────────────────────
    case 'current_staff':
        $stmt = $db->prepare(
            "SELECT o.id, o.building, o.room, o.problem_type, o.priority, o.status,
                    o.created_at, DATEDIFF(NOW(), o.created_at) AS days_open
             FROM orders o
             INNER JOIN order_assignments oa ON o.id = oa.order_id AND oa.user_email = ?
             WHERE o.status NOT IN ('Completed','Rejected')
             ORDER BY FIELD(o.priority,'Urgent','High','Mid','Low'), o.created_at ASC"
        );
        $stmt->bind_param('s', $emp_email);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $orders[] = $r;
        $stmt->close();
        $report_label = "Current Assignments — {$emp_full_name}";
        $filter_line  = "{$emp_role_label} · " . count($orders) . ' active assignments';
        $filename     = 'wcsc-current-' . strtolower(str_replace(' ', '-', $emp_full_name)) . '-' . date('Ymd') . '.pdf';
        break;

    // ── Work Orders by Building ───────────────────────────────────────────────
    case 'by_building':
        if (!in_array($building, $valid_buildings)) { $db->close(); http_response_code(400); exit('Invalid building.'); }
        $type_clause = match($user_role) {
            'MM' => " AND o.type = 'Maintenance'",
            'MT' => " AND o.type = 'Technology'",
            default => '',
        };
        // Open orders
        $stmt = $db->prepare(
            "SELECT o.id, o.type, o.problem_type, o.priority, o.status,
                    o.created_at, DATEDIFF(NOW(), o.created_at) AS days_open,
                    GROUP_CONCAT(oa.user_name ORDER BY oa.assigned_at SEPARATOR ', ') AS assignee_names
             FROM orders o
             LEFT JOIN order_assignments oa ON o.id = oa.order_id
             WHERE o.building = ? AND o.status NOT IN ('Completed','Rejected'){$type_clause}
             GROUP BY o.id
             ORDER BY FIELD(o.priority,'Urgent','High','Mid','Low'), o.created_at ASC"
        );
        $stmt->bind_param('s', $building);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $open_orders[] = $r;
        $stmt->close();
        // Completed last 90 days
        $stmt = $db->prepare(
            "SELECT o.id, o.type, o.problem_type, o.priority, o.resolved_by, o.created_at
             FROM orders o
             WHERE o.building = ? AND o.status = 'Completed'
               AND o.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY){$type_clause}
             ORDER BY o.created_at DESC LIMIT 60"
        );
        $stmt->bind_param('s', $building);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $closed_orders[] = $r;
        $stmt->close();
        $report_label = "Work Orders — {$building}";
        $filter_line  = count($open_orders) . ' open · ' . count($closed_orders) . ' completed in last 90 days';
        $filename     = 'wcsc-building-' . strtolower($building) . '-' . date('Ymd') . '.pdf';
        break;

    // ── Workload Distribution ─────────────────────────────────────────────────
    case 'workload':
        $roles_ph = implode(',', array_fill(0, count($scoped_roles), '?'));
        $stmt = $db->prepare(
            "SELECT u.first_name, u.last_name, u.role,
                    COUNT(DISTINCT oa.order_id) AS total_assigned,
                    COUNT(DISTINCT CASE WHEN o.status = 'Completed' THEN o.id END) AS completed,
                    COUNT(DISTINCT CASE WHEN o.status NOT IN ('Completed','Rejected') AND o.id IS NOT NULL THEN o.id END) AS in_progress
             FROM users u
             LEFT JOIN order_assignments oa ON oa.user_email = u.email
             LEFT JOIN orders o ON o.id = oa.order_id
               AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             WHERE u.active = 1 AND u.role IN ({$roles_ph})
             GROUP BY u.email, u.first_name, u.last_name, u.role
             ORDER BY completed DESC, u.last_name, u.first_name"
        );
        $bind_args = array_merge([$period_days], $scoped_roles);
        $stmt->bind_param('i' . str_repeat('s', count($scoped_roles)), ...$bind_args);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $workers[] = $r;
        $stmt->close();
        $period_label = $period_days >= 365 ? '1 Year' : "{$period_days} Days";
        $report_label = "Workload Distribution — Last {$period_label}";
        $filter_line  = count($workers) . ' staff members · Period: last ' . strtolower($period_label);
        $filename     = "wcsc-workload-{$period_days}d-" . date('Ymd') . '.pdf';
        break;

    default:
        $db->close(); http_response_code(400); exit('Invalid report type.');
}

$db->close();

// ── Shared CSS ────────────────────────────────────────────────────────────────
$css = '
@page { margin: 0; }
body { font-family: Helvetica, Arial, sans-serif; margin: 0; padding: 0; font-size: 9pt; color: #222; }
.header { background: #0B1F2E; width: 100%; padding: 0 0.5in; height: 1.1in; display: table; }
.header-inner { display: table-cell; vertical-align: middle; }
.header-logo { float: left; padding-top: 6px; }
.header-logo img { height: 0.75in; width: auto; }
.header-text { margin-left: 90px; padding-top: 14px; }
.header-org { color: #fff; font-size: 18pt; font-weight: bold; margin: 0; line-height: 1.2; }
.header-sub { color: #29b6d5; font-size: 10pt; margin: 4px 0 0 0; }
.header-accent { background: #29b6d5; height: 3px; width: 100%; }
.content { margin: 0.3in 0.5in 0.85in; }
.filter-box { background: #f3f4f6; padding: 10px 14px; margin-bottom: 14px; border-left: 4px solid #0B1F2E; }
.filter-label { font-weight: bold; color: #0B1F2E; font-size: 9pt; margin-bottom: 3px; }
.filter-detail { color: #555; font-size: 8pt; line-height: 1.5; }
.section-head { font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: .07em;
    color: #0B1F2E; border-bottom: 2px solid #0B1F2E; padding-bottom: 4px; margin: 20px 0 8px; }
table { width: 100%; border-collapse: collapse; font-size: 8pt; margin-top: 8px; }
thead th { background: #0B1F2E; color: #fff; padding: 7px 8px; text-align: left;
    font-size: 7pt; font-weight: bold; text-transform: uppercase; letter-spacing: .06em; border: 1px solid #0B1F2E; }
tbody td { padding: 7px 8px; border: 1px solid #d1d5db; vertical-align: top; color: #222; }
tbody tr:nth-child(odd) td { background: #f9fafb; }
tbody tr:nth-child(even) td { background: #fff; }
.no-data { padding: 20px 0; color: #888; font-size: 9pt; }
.summary-stat { display: inline-block; background: #e6f7fb; border: 1px solid #b3e0ed;
    border-radius: 6px; padding: 8px 16px; margin: 0 6px 10px 0; font-size: 9pt; }
.summary-stat strong { display: block; font-size: 16pt; color: #0B1F2E; line-height: 1.1; }
.summary-stat span { font-size: 7.5pt; color: #6b7a8d; }
.footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: center;
    font-size: 7.5pt; color: #666; padding: 10px 0; border-top: 1px solid #ddd; background: #fff; }
';

// ── Shared header HTML ────────────────────────────────────────────────────────
$logo_tag = $logo_src ? '<img src="' . $logo_src . '" alt="WCSC Logo">' : '';
$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>
<div class="header"><div class="header-inner">
  <div class="header-logo">' . $logo_tag . '</div>
  <div class="header-text">
    <p class="header-org">Warrick County School Corporation</p>
    <p class="header-sub">Work Order Management System</p>
  </div>
</div></div>
<div class="header-accent"></div>
<div class="footer">Warrick County School Corporation &nbsp;|&nbsp; 300 E. Gum St., Boonville, IN 47601 &nbsp;|&nbsp; 812-897-6588</div>
<div class="content">
<div class="filter-box">
  <div class="filter-label">' . htmlspecialchars($report_label) . '</div>
  <div class="filter-detail">' . htmlspecialchars($filter_line) . '</div>
  <div class="filter-detail">Generated: ' . $generated_at . ' &nbsp;·&nbsp; By: ' . htmlspecialchars($generated_by) . '</div>
</div>';

// ── Report-specific body ───────────────────────────────────────────────────────
switch ($report_type) {

    // ── Active Maintenance / Active Technology ────────────────────────────────
    case 'active_maint':
    case 'active_tech':
        if (empty($orders)) {
            $html .= '<p class="no-data">No active orders found.</p>';
        } else {
            $html .= '<table><thead><tr>
                <th>WO #</th><th>Building</th><th>Room</th><th>Problem Type</th>
                <th>Priority</th><th>Status</th><th>Assigned To</th><th>Days Open</th><th>Submitted</th>
            </tr></thead><tbody>';
            foreach ($orders as $o) {
                $d = (int)$o['days_open'];
                $html .= '<tr>
                    <td><strong>' . wo_num($o['id']) . '</strong></td>
                    <td><strong>' . htmlspecialchars($o['building']) . '</strong></td>
                    <td>' . htmlspecialchars($o['room'] ?? '') . '</td>
                    <td>' . htmlspecialchars($o['problem_type']) . '</td>
                    <td style="color:' . priority_color($o['priority']) . ';font-weight:bold">' . htmlspecialchars($o['priority']) . '</td>
                    <td>' . htmlspecialchars($o['status']) . '</td>
                    <td>' . htmlspecialchars($o['assignee_names'] ?? '—') . '</td>
                    <td style="color:' . days_color($d) . ';font-weight:bold">' . $d . 'd</td>
                    <td>' . fmt_date($o['created_at']) . '</td>
                </tr>';
            }
            $html .= '</tbody></table>';
        }
        break;

    // ── Aging ─────────────────────────────────────────────────────────────────
    case 'aging':
        if (empty($orders)) {
            $html .= '<p class="no-data">No orders found open more than ' . $aging_days . ' days.</p>';
        } else {
            $html .= '<table><thead><tr>
                <th>WO #</th><th>Type</th><th>Building</th><th>Problem Type</th>
                <th>Priority</th><th>Status</th><th>Assigned To</th><th>Days Open</th><th>Submitted</th>
            </tr></thead><tbody>';
            foreach ($orders as $o) {
                $d = (int)$o['days_open'];
                $html .= '<tr>
                    <td><strong>' . wo_num($o['id']) . '</strong></td>
                    <td>' . htmlspecialchars($o['type']) . '</td>
                    <td><strong>' . htmlspecialchars($o['building']) . '</strong></td>
                    <td>' . htmlspecialchars($o['problem_type']) . '</td>
                    <td style="color:' . priority_color($o['priority']) . ';font-weight:bold">' . htmlspecialchars($o['priority']) . '</td>
                    <td>' . htmlspecialchars($o['status']) . '</td>
                    <td>' . htmlspecialchars($o['assignee_names'] ?? '—') . '</td>
                    <td style="color:' . days_color($d) . ';font-weight:bold">' . $d . 'd</td>
                    <td>' . fmt_date($o['created_at']) . '</td>
                </tr>';
            }
            $html .= '</tbody></table>';
        }
        break;

    // ── Completed by Staff ────────────────────────────────────────────────────
    case 'completed_staff':
        $count = count($orders);
        $html .= '<div style="margin-bottom:14px">
            <div class="summary-stat"><strong>' . $count . '</strong><span>Completed Orders</span></div>
            <div class="summary-stat"><strong>' . htmlspecialchars($emp_full_name) . '</strong><span>' . htmlspecialchars($emp_role_label) . '</span></div>
        </div>';
        if (empty($orders)) {
            $html .= '<p class="no-data">No completed orders found for this period.</p>';
        } else {
            $html .= '<table><thead><tr>
                <th>WO #</th><th>Building</th><th>Problem Type</th><th>Priority</th><th>Submitted</th><th>Resolved By</th>
            </tr></thead><tbody>';
            foreach ($orders as $o) {
                $html .= '<tr>
                    <td><strong>' . wo_num($o['id']) . '</strong></td>
                    <td><strong>' . htmlspecialchars($o['building']) . '</strong></td>
                    <td>' . htmlspecialchars($o['problem_type']) . '</td>
                    <td style="color:' . priority_color($o['priority']) . ';font-weight:bold">' . htmlspecialchars($o['priority']) . '</td>
                    <td>' . fmt_date($o['created_at']) . '</td>
                    <td>' . htmlspecialchars($o['resolved_by'] ?? '—') . '</td>
                </tr>';
            }
            $html .= '</tbody></table>';
        }
        break;

    // ── Current by Staff ──────────────────────────────────────────────────────
    case 'current_staff':
        $count = count($orders);
        $html .= '<div style="margin-bottom:14px">
            <div class="summary-stat"><strong>' . $count . '</strong><span>Active Assignments</span></div>
            <div class="summary-stat"><strong>' . htmlspecialchars($emp_full_name) . '</strong><span>' . htmlspecialchars($emp_role_label) . '</span></div>
        </div>';
        if (empty($orders)) {
            $html .= '<p class="no-data">No active assignments found.</p>';
        } else {
            $html .= '<table><thead><tr>
                <th>WO #</th><th>Building</th><th>Room</th><th>Problem Type</th><th>Priority</th><th>Status</th><th>Days Open</th><th>Submitted</th>
            </tr></thead><tbody>';
            foreach ($orders as $o) {
                $d = (int)$o['days_open'];
                $html .= '<tr>
                    <td><strong>' . wo_num($o['id']) . '</strong></td>
                    <td><strong>' . htmlspecialchars($o['building']) . '</strong></td>
                    <td>' . htmlspecialchars($o['room'] ?? '') . '</td>
                    <td>' . htmlspecialchars($o['problem_type']) . '</td>
                    <td style="color:' . priority_color($o['priority']) . ';font-weight:bold">' . htmlspecialchars($o['priority']) . '</td>
                    <td>' . htmlspecialchars($o['status']) . '</td>
                    <td style="color:' . days_color($d) . ';font-weight:bold">' . $d . 'd</td>
                    <td>' . fmt_date($o['created_at']) . '</td>
                </tr>';
            }
            $html .= '</tbody></table>';
        }
        break;

    // ── By Building ───────────────────────────────────────────────────────────
    case 'by_building':
        // Open orders
        $html .= '<div class="section-head">Open Orders (' . count($open_orders) . ')</div>';
        if (empty($open_orders)) {
            $html .= '<p class="no-data">No open orders.</p>';
        } else {
            $html .= '<table><thead><tr>
                <th>WO #</th><th>Type</th><th>Problem Type</th><th>Priority</th><th>Status</th><th>Assigned To</th><th>Days Open</th><th>Submitted</th>
            </tr></thead><tbody>';
            foreach ($open_orders as $o) {
                $d = (int)$o['days_open'];
                $html .= '<tr>
                    <td><strong>' . wo_num($o['id']) . '</strong></td>
                    <td>' . htmlspecialchars($o['type']) . '</td>
                    <td>' . htmlspecialchars($o['problem_type']) . '</td>
                    <td style="color:' . priority_color($o['priority']) . ';font-weight:bold">' . htmlspecialchars($o['priority']) . '</td>
                    <td>' . htmlspecialchars($o['status']) . '</td>
                    <td>' . htmlspecialchars($o['assignee_names'] ?? '—') . '</td>
                    <td style="color:' . days_color($d) . ';font-weight:bold">' . $d . 'd</td>
                    <td>' . fmt_date($o['created_at']) . '</td>
                </tr>';
            }
            $html .= '</tbody></table>';
        }
        // Completed last 90 days
        $html .= '<div class="section-head">Completed — Last 90 Days (' . count($closed_orders) . ')</div>';
        if (empty($closed_orders)) {
            $html .= '<p class="no-data">No orders completed in the last 90 days.</p>';
        } else {
            $html .= '<table><thead><tr>
                <th>WO #</th><th>Type</th><th>Problem Type</th><th>Priority</th><th>Submitted</th><th>Resolved By</th>
            </tr></thead><tbody>';
            foreach ($closed_orders as $o) {
                $html .= '<tr>
                    <td><strong>' . wo_num($o['id']) . '</strong></td>
                    <td>' . htmlspecialchars($o['type']) . '</td>
                    <td>' . htmlspecialchars($o['problem_type']) . '</td>
                    <td style="color:' . priority_color($o['priority']) . ';font-weight:bold">' . htmlspecialchars($o['priority']) . '</td>
                    <td>' . fmt_date($o['created_at']) . '</td>
                    <td>' . htmlspecialchars($o['resolved_by'] ?? '—') . '</td>
                </tr>';
            }
            $html .= '</tbody></table>';
        }
        break;

    // ── Workload Distribution ─────────────────────────────────────────────────
    case 'workload':
        $period_label_long = $period_days >= 365 ? '1 year' : "{$period_days} days";
        if (empty($workers)) {
            $html .= '<p class="no-data">No staff members found.</p>';
        } else {
            // Summary totals
            $total_assigned  = array_sum(array_column($workers, 'total_assigned'));
            $total_completed = array_sum(array_column($workers, 'completed'));
            $total_open      = array_sum(array_column($workers, 'in_progress'));
            $html .= '<div style="margin-bottom:14px">
                <div class="summary-stat"><strong>' . $total_assigned  . '</strong><span>Total Assigned</span></div>
                <div class="summary-stat"><strong>' . $total_completed . '</strong><span>Completed</span></div>
                <div class="summary-stat"><strong>' . $total_open      . '</strong><span>Still Open</span></div>
            </div>';
            $html .= '<table><thead><tr>
                <th>Staff Member</th><th>Role</th>
                <th style="text-align:center">Assigned<br><span style="font-weight:normal;font-size:6.5pt">last ' . htmlspecialchars($period_label_long) . '</span></th>
                <th style="text-align:center">Completed</th>
                <th style="text-align:center">Still Open</th>
                <th style="text-align:center">Close Rate</th>
            </tr></thead><tbody>';
            foreach ($workers as $w) {
                $assigned   = (int)$w['total_assigned'];
                $completed  = (int)$w['completed'];
                $in_prog    = (int)$w['in_progress'];
                $rate       = $assigned > 0 ? round($completed / $assigned * 100) . '%' : '—';
                $rate_color = $assigned > 0 && $completed / $assigned >= 0.7 ? '#16a34a' : '#374151';
                $html .= '<tr>
                    <td><strong>' . htmlspecialchars($w['first_name'] . ' ' . $w['last_name']) . '</strong></td>
                    <td style="color:#6b7a8d;font-size:7.5pt">' . htmlspecialchars($role_labels[$w['role']] ?? $w['role']) . '</td>
                    <td style="text-align:center">' . $assigned . '</td>
                    <td style="text-align:center;font-weight:bold;color:#0B1F2E">' . $completed . '</td>
                    <td style="text-align:center;color:' . ($in_prog > 0 ? '#d97706' : '#374151') . '">' . $in_prog . '</td>
                    <td style="text-align:center;font-weight:bold;color:' . $rate_color . '">' . $rate . '</td>
                </tr>';
            }
            $html .= '</tbody></table>
            <p style="font-size:7pt;color:#888;margin-top:10px">
                * Assigned and Completed counts reflect orders submitted within the selected period.
                  Close Rate = Completed ÷ Assigned. Sorted by completed orders, descending.
            </p>';
        }
        break;
}

$html .= '</div></body></html>';

// ── Render ────────────────────────────────────────────────────────────────────
$options = new Options();
$options->set('defaultFont', 'Helvetica');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();
$dompdf->stream($filename ?: 'wcsc-report.pdf', ['Attachment' => false]);
