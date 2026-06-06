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

$report_type   = trim($_GET['report_type']   ?? '');
$order_type    = trim($_GET['order_type']    ?? '');
$building      = trim($_GET['building']      ?? '');
$priority_f    = trim($_GET['priority']      ?? '');
$inc_notes     = ($_GET['inc_notes']     ?? '0') === '1';
$inc_submitter = ($_GET['inc_submitter'] ?? '1') === '1';
$inc_assignees = ($_GET['inc_assignees'] ?? '1') === '1';

$generated_by = $_SESSION['google_user']['name'] ?? 'Unknown';
$generated_at = date('F j, Y');

// Logo
$logo_path = __DIR__ . '/wcsc_workorder_logo_v4.png';
$logo_src  = '';
if (file_exists($logo_path)) {
    $logo_src = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path));
}

function wo_num(int $id): string { return 'WO-' . str_pad($id, 6, '0', STR_PAD_LEFT); }
function fmt_date(string $d): string { return $d ? date('m/d/Y', strtotime($d)) : '—'; }

// ── Query ─────────────────────────────────────────────────────
if ($report_type === 'active') {
    $where  = ["o.status IN ('Pending Approval','Approved','In Progress')"];
    $params = []; $types = '';
    if ($order_type) { $where[] = 'o.type = ?';     $params[] = $order_type; $types .= 's'; }
    if ($building)   { $where[] = 'o.building = ?'; $params[] = $building;   $types .= 's'; }
    if ($priority_f) { $where[] = 'o.priority = ?'; $params[] = $priority_f; $types .= 's'; }

    $sql = "SELECT o.*, GROUP_CONCAT(oa.user_name ORDER BY oa.assigned_at SEPARATOR ', ') AS assignee_names
            FROM orders o
            LEFT JOIN order_assignments oa ON o.id = oa.order_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY o.id
            ORDER BY FIELD(o.priority,'Urgent','High','Mid','Low'),
                     FIELD(o.status,'Pending Approval','Approved','In Progress'),
                     o.created_at ASC";
    $stmt = $db->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $orders = [];
    while ($row = $res->fetch_assoc()) $orders[] = $row;
    $stmt->close();

    $f_parts = [];
    if ($order_type) $f_parts[] = 'Type: ' . $order_type;
    if ($building)   $f_parts[] = 'Building: ' . $building;
    if ($priority_f) $f_parts[] = 'Priority: ' . $priority_f;
    $filter_line  = $f_parts ? implode(' | ', $f_parts) : 'All Active Orders (No Filters Applied)';
    $report_label = 'Active Work Orders';
    $filename     = 'wcsc-active-work-orders-' . date('Ymd') . '.pdf';
}

$db->close();

// ── HTML — matches your working custom_report.php pattern exactly ──
$html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { margin: 0; }
body {
    font-family: Helvetica, Arial, sans-serif;
    margin: 0;
    padding: 0;
    font-size: 9pt;
    color: #333;
}

/* ── HEADER — plain div at top of body, not fixed ── */
.header {
    background: #0B1F2E;
    width: 100%;
    padding: 0 0.5in;
    height: 1.1in;
    display: table;
}
.header-inner {
    display: table-cell;
    vertical-align: middle;
}
.header-logo {
    float: left;
    padding-top: 6px;
}
.header-logo img {
    height: 0.75in;
    width: auto;
}
.header-text {
    margin-left: 90px;
    padding-top: 14px;
}
.header-org {
    color: #ffffff;
    font-size: 18pt;
    font-weight: bold;
    margin: 0;
    line-height: 1.2;
}
.header-sub {
    color: #29b6d5;
    font-size: 10pt;
    margin: 4px 0 0 0;
}
.header-accent {
    background: #29b6d5;
    height: 3px;
    width: 100%;
}

/* ── CONTENT — margin pushes below header, leaves room for footer ── */
.content {
    margin: 0.35in 0.5in 0.9in 0.5in;
}

/* ── FILTER SUMMARY BOX ── */
.filter-box {
    background: #f3f4f6;
    padding: 10px 14px;
    margin-bottom: 14px;
    border-left: 4px solid #0B1F2E;
}
.filter-label {
    font-weight: bold;
    color: #0B1F2E;
    font-size: 9pt;
    margin-bottom: 3px;
}
.filter-detail {
    color: #555;
    font-size: 8pt;
    line-height: 1.5;
}

/* ── TABLE ── */
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 8.5pt;
    margin-top: 10px;
}
thead th {
    background: #0B1F2E;
    color: #ffffff;
    padding: 7px 8px;
    text-align: left;
    font-size: 7pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    border: 1px solid #0B1F2E;
}
tbody td {
    padding: 7px 8px;
    border: 1px solid #d1d5db;
    vertical-align: top;
    color: #222;
}
tbody tr:nth-child(odd)  td { background: #f9fafb; }
tbody tr:nth-child(even) td { background: #ffffff; }

/* ── NOTES SECTION ── */
.notes-section {
    margin-top: 22px;
}
.notes-title {
    font-size: 8pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: #555;
    border-bottom: 1px solid #ccc;
    padding-bottom: 4px;
    margin-bottom: 10px;
}
.note-item {
    margin-bottom: 10px;
}
.note-wo {
    font-size: 8pt;
    font-weight: bold;
    color: #0B1F2E;
    margin-bottom: 2px;
}
.note-text {
    font-size: 7.5pt;
    color: #555;
    white-space: pre-wrap;
    line-height: 1.5;
}

/* ── FOOTER — fixed at bottom, dompdf handles this correctly ── */
.footer {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 7.5pt;
    color: #666;
    padding: 10px 0;
    border-top: 1px solid #ddd;
    background: #ffffff;
}
</style>
</head>
<body>

<!-- HEADER -->
<div class="header">
    <div class="header-inner">
        <div class="header-logo">
            ' . ($logo_src ? '<img src="' . $logo_src . '" alt="WCSC Logo">' : '') . '
        </div>
        <div class="header-text">
            <p class="header-org">Warrick County School Corporation</p>
            <p class="header-sub">Work Order Management System</p>
        </div>
    </div>
</div>
<div class="header-accent"></div>

<!-- FOOTER -->
<div class="footer">
    Warrick County School Corporation &nbsp;|&nbsp; 300 E. Gum St., Boonville, IN 47601 &nbsp;|&nbsp; Phone: 812-897-6588
</div>

<!-- CONTENT -->
<div class="content">

    <div class="filter-box">
        <div class="filter-label">Report Type (' . htmlspecialchars($report_label ?? 'Report') . '):</div>
        <div class="filter-detail">' . htmlspecialchars($filter_line ?? '') . '</div>
        <div class="filter-detail">Generated: ' . $generated_at . ' &nbsp;·&nbsp; By: ' . htmlspecialchars($generated_by) . '</div>
    </div>';

if ($report_type === 'active') {
    if (empty($orders)) {
        $html .= '<p style="color:#888;font-size:9pt;padding:20px 0">No active work orders found matching the selected filters.</p>';
    } else {
        // Build column list
        $cols = ['WO #', 'Type', 'Building', 'Room', 'Problem Type', 'Priority', 'Status'];
        if ($inc_submitter) $cols[] = 'Submitted By';
        if ($inc_assignees) $cols[] = 'Assigned To';
        $cols[] = 'Date';

        $html .= '<table><thead><tr>';
        foreach ($cols as $c) $html .= '<th>' . $c . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($orders as $o) {
            $html .= '<tr>';
            $html .= '<td><strong>' . wo_num($o['id']) . '</strong></td>';
            $html .= '<td>'         . htmlspecialchars($o['type'])                                   . '</td>';
            $html .= '<td><strong>' . htmlspecialchars($o['building'])                               . '</strong></td>';
            $html .= '<td>'         . htmlspecialchars($o['room'])                                   . '</td>';
            $html .= '<td>'         . htmlspecialchars($o['problem_type'])                           . '</td>';
            $html .= '<td>'         . htmlspecialchars($o['priority'])                               . '</td>';
            $html .= '<td>'         . htmlspecialchars($o['status'])                                 . '</td>';
            if ($inc_submitter) $html .= '<td>' . htmlspecialchars($o['submitted_name'] ?: $o['submitted_by']) . '</td>';
            if ($inc_assignees) $html .= '<td>' . htmlspecialchars($o['assignee_names'] ?? '—')               . '</td>';
            $html .= '<td>'         . fmt_date($o['created_at'])                                     . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        // Notes
        if ($inc_notes) {
            $with_notes = array_filter($orders, fn($o) => !empty(trim($o['notes'] ?? '')));
            if (!empty($with_notes)) {
                $html .= '<div class="notes-section"><div class="notes-title">Activity Log / Notes</div>';
                foreach ($with_notes as $o) {
                    $html .= '<div class="note-item">';
                    $html .= '<div class="note-wo">' . wo_num($o['id']) . ' &nbsp;·&nbsp; ' . htmlspecialchars($o['building']) . ' &nbsp;·&nbsp; ' . htmlspecialchars($o['problem_type']) . '</div>';
                    $html .= '<div class="note-text">' . htmlspecialchars(trim($o['notes'])) . '</div>';
                    $html .= '</div>';
                }
                $html .= '</div>';
            }
        }
    }
}

$html .= '</div></body></html>';

// ── Render ─────────────────────────────────────────────────────
$options = new Options();
$options->set('defaultFont', 'Helvetica');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();
$dompdf->stream($filename ?? 'wcsc-report.pdf', ['Attachment' => false]);