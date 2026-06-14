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
$emp_email   = trim($_GET['emp_email']   ?? '');
$emp_name    = trim($_GET['emp_name']    ?? '');

$period_days = max(1, (int)($_GET['period_days'] ?? 30));

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
$emp_full_name   = '';
$emp_role_label  = '';
$validated_emps  = []; // [{email, name, role_label}] — populated for current_staff

if ($report_type === 'current_staff') {
    $raw_emails = array_unique(array_filter(array_map('trim', explode(',', $emp_email))));
    if (empty($raw_emails)) { $db->close(); http_response_code(400); exit('Staff member required.'); }
    $roles_ph = implode(',', array_fill(0, count($scoped_roles), '?'));
    foreach ($raw_emails as $_e) {
        $chk = $db->prepare("SELECT first_name, last_name, role FROM users WHERE email = ? AND active = 1 AND role IN ({$roles_ph})");
        $_chk_args = array_merge([$_e], $scoped_roles);
        $chk->bind_param('s' . str_repeat('s', count($scoped_roles)), ...$_chk_args);
        $chk->execute();
        if ($_row = $chk->get_result()->fetch_assoc()) {
            $validated_emps[] = [
                'email'      => $_e,
                'name'       => $_row['first_name'] . ' ' . $_row['last_name'],
                'role_label' => $role_labels[$_row['role']] ?? $_row['role'],
            ];
        }
        $chk->close();
    }
    if (empty($validated_emps)) { $db->close(); http_response_code(403); exit('No valid or authorized staff members.'); }
    $emp_full_name  = $validated_emps[0]['name'];
    $emp_role_label = $validated_emps[0]['role_label'];
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

    // ── Staff Member Report ───────────────────────────────────────────────────
    case 'current_staff':
        $employees_data = [];

        foreach ($validated_emps as $_emp) {
            $_e      = $_emp['email'];
            $_orders = $_closed = $_older = $_at = [];

            $stmt = $db->prepare(
                "SELECT o.id, o.building, o.room, o.problem_type, o.priority, o.status,
                        o.created_at, DATEDIFF(NOW(), o.created_at) AS days_open,
                        oa.assigned_at, DATEDIFF(NOW(), oa.assigned_at) AS days_assigned
                 FROM orders o
                 INNER JOIN order_assignments oa ON o.id = oa.order_id AND oa.user_email = ?
                 WHERE o.status NOT IN ('Completed','Rejected')
                 ORDER BY FIELD(o.priority,'Urgent','High','Mid','Low'), o.created_at ASC"
            );
            $stmt->bind_param('s', $_e);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $_orders[] = $r;
            $stmt->close();

            $stmt = $db->prepare(
                "SELECT o.id, o.building, o.room, o.problem_type, o.priority, o.created_at,
                        oa.assigned_at, DATEDIFF(NOW(), oa.assigned_at) AS days_to_close
                 FROM orders o
                 INNER JOIN order_assignments oa ON o.id = oa.order_id AND oa.user_email = ?
                 WHERE o.status = 'Completed'
                   AND o.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                 ORDER BY o.created_at DESC"
            );
            $stmt->bind_param('s', $_e);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $_closed[] = $r;
            $stmt->close();

            $stmt = $db->prepare(
                "SELECT o.id, o.building, o.room, o.problem_type, o.priority, o.created_at,
                        oa.assigned_at
                 FROM orders o
                 INNER JOIN order_assignments oa ON o.id = oa.order_id AND oa.user_email = ?
                 WHERE o.status = 'Completed'
                   AND o.created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)
                   AND o.created_at <  DATE_SUB(NOW(), INTERVAL 60 DAY)
                 ORDER BY o.created_at DESC LIMIT 60"
            );
            $stmt->bind_param('s', $_e);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $_older[] = $r;
            $stmt->close();

            $stmt = $db->prepare(
                "SELECT COUNT(*)                                                                      AS total_assigned,
                        COUNT(CASE WHEN o.status = 'Completed' THEN 1 END)                           AS total_completed,
                        COUNT(CASE WHEN o.status NOT IN ('Completed','Rejected') THEN 1 END)         AS total_open,
                        MIN(o.created_at)                                                             AS first_order_date,
                        AVG(CASE WHEN o.status = 'Completed'
                                  THEN DATEDIFF(NOW(), oa.assigned_at) END)                          AS avg_days_close,
                        COUNT(CASE WHEN o.status = 'Completed'
                                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) AS cnt_30d,
                        COUNT(CASE WHEN o.status = 'Completed'
                                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) THEN 1 END) AS cnt_60d,
                        COUNT(CASE WHEN o.status = 'Completed'
                                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 1 END) AS cnt_90d,
                        COUNT(CASE WHEN o.status = 'Completed'
                                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY) THEN 1 END) AS cnt_180d,
                        COUNT(CASE WHEN o.status = 'Completed'
                                    AND o.created_at >= MAKEDATE(YEAR(NOW()), 1) THEN 1 END)          AS cnt_ytd
                 FROM orders o
                 INNER JOIN order_assignments oa ON o.id = oa.order_id AND oa.user_email = ?"
            );
            $stmt->bind_param('s', $_e);
            $stmt->execute();
            if ($_sr = $stmt->get_result()->fetch_assoc()) $_at = $_sr;
            $stmt->close();

            $employees_data[] = [
                'emp'    => $_emp,
                'orders' => $_orders,
                'closed' => $_closed,
                'older'  => $_older,
                'stats'  => $_at,
            ];
        }

        $n = count($employees_data);
        $report_label = $n === 1
            ? "Staff Member Report — {$employees_data[0]['emp']['name']}"
            : "Staff Member Report — {$n} Staff Members";
        $filter_line  = $n === 1
            ? "{$employees_data[0]['emp']['role_label']} · Generated: " . date('m/d/Y')
            : "{$n} staff members · Generated: " . date('m/d/Y');
        $filename     = 'wcsc-staff-report-' . date('Ymd') . '.pdf';
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
        $roles_ph   = implode(',', array_fill(0, count($scoped_roles), '?'));
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$period_days} days"));
        $stmt = $db->prepare(
            "SELECT u.first_name, u.last_name, u.role,
                    COUNT(DISTINCT o.id) AS total_assigned,
                    COUNT(DISTINCT CASE WHEN o.status = 'Completed' THEN o.id END) AS completed,
                    COUNT(DISTINCT CASE WHEN o.status NOT IN ('Completed','Rejected') THEN o.id END) AS in_progress,
                    AVG(CASE WHEN o.status = 'Completed' THEN DATEDIFF(NOW(), o.created_at) END) AS avg_days_close
             FROM users u
             LEFT JOIN order_assignments oa ON oa.user_email COLLATE utf8mb4_unicode_ci = u.email COLLATE utf8mb4_unicode_ci
             LEFT JOIN orders o ON o.id = oa.order_id AND o.created_at >= ?
             WHERE u.active = 1 AND u.role IN ({$roles_ph})
             GROUP BY u.email, u.first_name, u.last_name, u.role
             ORDER BY completed DESC, u.last_name, u.first_name"
        );
        if (!$stmt) {
            $db->close();
            http_response_code(500);
            exit('Workload report query failed: ' . htmlspecialchars($db->error));
        }
        $bind_args = array_merge([$cutoff_date], $scoped_roles);
        $stmt->bind_param('s' . str_repeat('s', count($scoped_roles)), ...$bind_args);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) { while ($r = $res->fetch_assoc()) $workers[] = $r; }
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
@page { margin: 1in 0 0 0; }
body { font-family: Helvetica, Arial, sans-serif; margin: 0; padding: 0; font-size: 9pt; color: #222; }
.header { background: #0B1F2E; width: 100%; padding: 0 0.5in; height: 1.1in; display: table; margin-top: -1in; }
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
.stat-table { border-collapse: separate; border-spacing: 8px 6px; margin-bottom: 14px; width: auto; }
.stat-table td { background: #e6f7fb !important; border: 1px solid #b3e0ed;
    border-radius: 6px; padding: 7px 14px; font-size: 9pt; vertical-align: top; }
.stat-val { font-size: 16pt; font-weight: bold; color: #0B1F2E; line-height: 1.1; }
.stat-lbl { font-size: 7.5pt; color: #6b7a8d; }
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
        $html .= '<table class="stat-table"><tr>
            <td><div class="stat-val">' . $count . '</div><div class="stat-lbl">Completed Orders</div></td>
            <td><div class="stat-val">' . htmlspecialchars($emp_full_name) . '</div><div class="stat-lbl">' . htmlspecialchars($emp_role_label) . '</div></td>
        </tr></table>';
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

    // ── Staff Member Report ───────────────────────────────────────────────────
    case 'current_staff':
        $is_first_emp = true;
        foreach ($employees_data as $_ed) {
            $_emp    = $_ed['emp'];
            $_orders = $_ed['orders'];
            $_closed = $_ed['closed'];
            $_older  = $_ed['older'];
            $_at     = $_ed['stats'];

            if (!$is_first_emp) {
                $html .= '</div>'; // close previous .content div

                // For 2nd+ employees: the outer block div carries page-break-before:always
                // AND margin-top:-1in. Being the first element on the new page, Dompdf
                // treats its negative margin the same as the document's first-page header.
                // The inner .header div gets margin-top:0 to suppress the CSS class's own -1in.
                $html .= '<div style="page-break-before:always;margin-top:-1in">'
                    . '<div class="header" style="margin-top:0">'
                    . '<div class="header-inner">'
                    . '  <div class="header-logo">' . ($logo_tag ?: '') . '</div>'
                    . '  <div class="header-text">'
                    . '    <p class="header-org">Warrick County School Corporation</p>'
                    . '    <p class="header-sub">Work Order Management System</p>'
                    . '  </div>'
                    . '</div>'
                    . '</div>'
                    . '</div>';
                $html .= '<div class="header-accent"></div>';

                // Per-employee filter box
                $html .= '<div class="content"><div class="filter-box">'
                    . '<div class="filter-label">Staff Member Report &mdash; ' . htmlspecialchars($_emp['name']) . '</div>'
                    . '<div class="filter-detail">' . htmlspecialchars($_emp['role_label']) . '</div>'
                    . '<div class="filter-detail">Generated: ' . $generated_at . ' &nbsp;&middot;&nbsp; By: ' . htmlspecialchars($generated_by) . '</div>'
                    . '</div>';
            }
            $is_first_emp = false;

            // ── Stats ─────────────────────────────────────────────────────────
            $total_assigned_all  = (int)($_at['total_assigned']  ?? 0);
            $total_completed_all = (int)($_at['total_completed'] ?? 0);
            $total_open_all      = (int)($_at['total_open']      ?? 0);
            $adc_val = ($_at['avg_days_close'] ?? null) !== null ? round((float)$_at['avg_days_close']) : null;
            $adc_str = $adc_val !== null ? $adc_val . 'd' : '—';
            $adc_clr = $adc_val !== null && $adc_val <= 7 ? '#16a34a' : '#374151';

            $first_date    = $_at['first_order_date'] ?? null;
            $monthly_str   = '—';
            $months_active = 0;
            if ($first_date && $total_completed_all > 0) {
                $dt1 = new DateTime(date('Y-m-01', strtotime($first_date)));
                $dt2 = new DateTime(date('Y-m-01'));
                $diff = $dt1->diff($dt2);
                $months_active = max(1, $diff->y * 12 + $diff->m + 1);
                $monthly_str = round($total_completed_all / $months_active, 1);
            }

            $html .= '<table class="stat-table" style="width:100%"><tbody><tr>
                <td><div class="stat-val">' . (int)($_at['cnt_30d']  ?? 0) . '</div><div class="stat-lbl">Completed<br>30 Days</div></td>
                <td><div class="stat-val">' . (int)($_at['cnt_60d']  ?? 0) . '</div><div class="stat-lbl">Completed<br>60 Days</div></td>
                <td><div class="stat-val">' . (int)($_at['cnt_90d']  ?? 0) . '</div><div class="stat-lbl">Completed<br>90 Days</div></td>
                <td><div class="stat-val">' . (int)($_at['cnt_180d'] ?? 0) . '</div><div class="stat-lbl">Completed<br>180 Days</div></td>
                <td><div class="stat-val">' . (int)($_at['cnt_ytd']  ?? 0) . '</div><div class="stat-lbl">Completed<br>YTD</div></td>
            </tr></tbody></table>';

            $html .= '<table class="stat-table"><tbody><tr>
                <td><div class="stat-val">' . count($_orders)       . '</div><div class="stat-lbl">Currently Open</div></td>
                <td><div class="stat-val">' . $total_completed_all  . '</div><div class="stat-lbl">All-Time Closed</div></td>
                <td><div class="stat-val">' . $monthly_str          . '</div><div class="stat-lbl">Avg / Month</div></td>
                <td><div class="stat-val" style="color:' . $adc_clr . '">' . $adc_str . '</div><div class="stat-lbl">Avg Days to Close</div></td>
            </tr></tbody></table>';

            // ── Current Open Assignments ───────────────────────────────────────
            $html .= '<div class="section-head">Current Open Assignments (' . count($_orders) . ')</div>';
            if (empty($_orders)) {
                $html .= '<p class="no-data">No open assignments.</p>';
            } else {
                $html .= '<table><thead><tr>
                    <th>WO #</th><th>Building</th><th>Room</th><th>Problem Type</th>
                    <th style="text-align:center">Priority</th>
                    <th style="text-align:center">Status</th>
                    <th style="text-align:center">Days Open</th>
                    <th style="text-align:center">Days Assigned</th>
                </tr></thead><tbody>';
                foreach ($_orders as $o) {
                    $do = (int)$o['days_open'];
                    $da = (int)$o['days_assigned'];
                    $html .= '<tr>
                        <td><strong>' . wo_num($o['id']) . '</strong></td>
                        <td>' . htmlspecialchars($o['building']) . '</td>
                        <td>' . htmlspecialchars($o['room'] ?? '') . '</td>
                        <td>' . htmlspecialchars($o['problem_type']) . '</td>
                        <td style="text-align:center;color:' . priority_color($o['priority']) . ';font-weight:bold">' . htmlspecialchars($o['priority']) . '</td>
                        <td style="text-align:center">' . htmlspecialchars($o['status']) . '</td>
                        <td style="text-align:center;color:' . days_color($do) . ';font-weight:bold">' . $do . 'd</td>
                        <td style="text-align:center;color:' . days_color($da) . ';font-weight:bold">' . $da . 'd</td>
                    </tr>';
                }
                $html .= '</tbody></table>';
            }

            // ── Completed – Last 60 Days ───────────────────────────────────────
            $cnt_closed = count($_closed);
            $html .= '<div class="section-head">Completed &mdash; Last 60 Days (' . $cnt_closed . ')</div>';
            if (empty($_closed)) {
                $html .= '<p class="no-data">No completed orders in the last 60 days.</p>';
            } else {
                $html .= '<table><thead><tr>
                    <th>WO #</th><th>Building</th><th>Room</th><th>Problem Type</th>
                    <th style="text-align:center">Priority</th>
                    <th style="text-align:center">Submitted</th>
                    <th style="text-align:center">Days to Close</th>
                </tr></thead><tbody>';
                foreach ($_closed as $o) {
                    $dtc = (int)$o['days_to_close'];
                    $dtc_clr = $dtc <= 7 ? '#16a34a' : ($dtc <= 14 ? '#d97706' : '#dc2626');
                    $html .= '<tr>
                        <td><strong>' . wo_num($o['id']) . '</strong></td>
                        <td>' . htmlspecialchars($o['building']) . '</td>
                        <td>' . htmlspecialchars($o['room'] ?? '') . '</td>
                        <td>' . htmlspecialchars($o['problem_type']) . '</td>
                        <td style="text-align:center;color:' . priority_color($o['priority']) . ';font-weight:bold">' . htmlspecialchars($o['priority']) . '</td>
                        <td style="text-align:center">' . fmt_date($o['created_at']) . '</td>
                        <td style="text-align:center;font-weight:bold;color:' . $dtc_clr . '">' . $dtc . 'd</td>
                    </tr>';
                }
                $html .= '</tbody></table>';
            }

            // ── Completed – Days 61–365 ────────────────────────────────────────
            $cnt_older = count($_older);
            $html .= '<div class="section-head">Completed &mdash; Days 61 to 365 ('
                . $cnt_older . ($cnt_older >= 60 ? ', showing most recent 60' : '') . ')</div>';
            if (empty($_older)) {
                $html .= '<p class="no-data">No completed orders in the 61–365 day window.</p>';
            } else {
                $html .= '<table><thead><tr>
                    <th>WO #</th><th>Building</th><th>Room</th><th>Problem Type</th>
                    <th style="text-align:center">Priority</th>
                    <th style="text-align:center">Submitted</th>
                    <th style="text-align:center">Assigned Date</th>
                </tr></thead><tbody>';
                foreach ($_older as $o) {
                    $html .= '<tr>
                        <td><strong>' . wo_num($o['id']) . '</strong></td>
                        <td>' . htmlspecialchars($o['building']) . '</td>
                        <td>' . htmlspecialchars($o['room'] ?? '') . '</td>
                        <td>' . htmlspecialchars($o['problem_type']) . '</td>
                        <td style="text-align:center;color:' . priority_color($o['priority']) . ';font-weight:bold">' . htmlspecialchars($o['priority']) . '</td>
                        <td style="text-align:center">' . fmt_date($o['created_at']) . '</td>
                        <td style="text-align:center">' . fmt_date($o['assigned_at']) . '</td>
                    </tr>';
                }
                $html .= '</tbody></table>';
            }

            if ($first_date) {
                $html .= '<p style="font-size:7pt;color:#888;margin-top:12px">'
                    . 'All-Time: ' . $total_assigned_all . ' assigned · ' . $total_completed_all . ' completed · '
                    . $total_open_all . ' still open · First recorded ' . fmt_date($first_date)
                    . ' (' . $months_active . ' month' . ($months_active !== 1 ? 's' : '') . ').'
                    . ' Monthly avg: ' . $monthly_str . '. Avg days to close: ' . $adc_str . ' (green ≤7d, amber ≤14d, red &gt;14d).'
                    . '</p>';
            }
        } // end foreach employees_data
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
            // Overall summary totals
            $total_assigned  = array_sum(array_column($workers, 'total_assigned'));
            $total_completed = array_sum(array_column($workers, 'completed'));
            $total_open      = array_sum(array_column($workers, 'in_progress'));
            $html .= '<table class="stat-table"><tr>
                <td><div class="stat-val">' . $total_assigned  . '</div><div class="stat-lbl">Total Assigned</div></td>
                <td><div class="stat-val">' . $total_completed . '</div><div class="stat-lbl">Completed</div></td>
                <td><div class="stat-val">' . $total_open      . '</div><div class="stat-lbl">Still Open</div></td>
            </tr></table>';

            // Group workers by role
            $by_role = [];
            foreach ($workers as $w) $by_role[$w['role']][] = $w;

            $role_group_order = match($user_role) {
                'MM'    => ['MW', 'BC', 'BM'],
                'MT'    => ['BT'],
                default => ['MW', 'BT', 'BC', 'BM'],
            };
            $role_full = [
                'MW' => 'Maintenance Workers',
                'BT' => 'Building Technicians',
                'BC' => 'Building Custodians',
                'BM' => 'Building Maintenance',
            ];

            foreach ($role_group_order as $role) {
                $group = $by_role[$role] ?? [];
                if (empty($group)) continue;
                $html .= '<div class="section-head">' . ($role_full[$role] ?? $role) . ' (' . $role . ')</div>';
                $html .= '<table><thead><tr>
                    <th>Staff Member</th>
                    <th style="text-align:center">Assigned<br><span style="font-weight:normal;font-size:6.5pt">last ' . htmlspecialchars($period_label_long) . '</span></th>
                    <th style="text-align:center">Completed</th>
                    <th style="text-align:center">Still Open</th>
                    <th style="text-align:center">Avg Days<br><span style="font-weight:normal;font-size:6.5pt">to close</span></th>
                    <th style="text-align:center">Close Rate</th>
                </tr></thead><tbody>';
                foreach ($group as $w) {
                    $assigned  = (int)$w['total_assigned'];
                    $completed = (int)$w['completed'];
                    $in_prog   = (int)$w['in_progress'];
                    $avg_days  = $w['avg_days_close'] !== null ? round((float)$w['avg_days_close']) . 'd' : '—';
                    $avg_color = $w['avg_days_close'] !== null && (float)$w['avg_days_close'] <= 7 ? '#16a34a' : '#374151';
                    $rate      = $assigned > 0 ? round($completed / $assigned * 100) . '%' : '—';
                    $rate_color = $assigned > 0 && $completed / $assigned >= 0.7 ? '#16a34a' : '#374151';
                    $html .= '<tr>
                        <td><strong>' . htmlspecialchars($w['first_name'] . ' ' . $w['last_name']) . '</strong></td>
                        <td style="text-align:center">' . $assigned . '</td>
                        <td style="text-align:center;font-weight:bold;color:#0B1F2E">' . $completed . '</td>
                        <td style="text-align:center;color:' . ($in_prog > 0 ? '#d97706' : '#374151') . '">' . $in_prog . '</td>
                        <td style="text-align:center;font-weight:bold;color:' . $avg_color . '">' . $avg_days . '</td>
                        <td style="text-align:center;font-weight:bold;color:' . $rate_color . '">' . $rate . '</td>
                    </tr>';
                }
                $html .= '</tbody></table>';
            }
            $html .= '<p style="font-size:7pt;color:#888;margin-top:10px">
                * Counts reflect orders submitted within the selected period.
                  Avg Days to Close and Close Rate apply to completed orders only; green = ≥70% rate or ≤7d avg.
                  Each group sorted by completed orders, descending.
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
try {
    $dompdf->render();
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    exit('PDF render error: ' . $e->getMessage());
}
$dompdf->stream($filename ?: 'wcsc-report.pdf', ['Attachment' => false]);
