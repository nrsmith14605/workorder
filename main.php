<?php



session_start();







// Redirect to login if not authenticated



if (!isset($_SESSION['google_user'])) {



    header('Location: index.php');



    exit;



}







$user = $_SESSION['google_user'];



$user_email = $user['email'];



$user_name      = $user['name']        ?? 'User';
$user_given     = $user['given_name']  ?? '';
$user_family    = $user['family_name'] ?? '';
$submitted_name = trim($user_given . ' ' . $user_family) ?: $user_name;



$user_pic   = $user['picture'] ?? '';







// Derive initials from name



$name_parts = explode(' ', trim($user_name));



$initials   = strtoupper(substr($name_parts[0], 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : ''));







// Role from session (set during login by DB lookup)



$user_role     = $_SESSION['user_role']     ?? 'U';



$user_building = $_SESSION['user_building'] ?? null;

// ── Handle work order submission ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_wo') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../../wo_config.php';
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');

    $type          = trim($_POST['type']          ?? '');
    $building      = trim($_POST['building']      ?? '');
    $room          = trim($_POST['room']          ?? '');
    $time_from     = trim($_POST['time_from']     ?? '') ?: null;
    $time_to       = trim($_POST['time_to']       ?? '') ?: null;
    $purpose       = trim($_POST['purpose']       ?? '');
    $problem_type  = trim($_POST['problem_type']  ?? '');
    $problem_other = trim($_POST['problem_other'] ?? '');
    $description   = trim($_POST['description']   ?? '');
    $priority      = trim($_POST['priority']      ?? '');
    $photo_path    = null;

    if ($problem_type === 'Other') {
        $problem_type = $problem_other ?: 'Other';
    }

    if ($type === 'Technology') $purpose = 'Technology';
    if (!$type || !$building || !$room || !$purpose || !$problem_type || !$description || !$priority) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
        exit;
    }

    if (!empty($_FILES['photo']['name'])) {
        $upload_dir = __DIR__ . '/uploads/wo/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $ext      = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $filename = uniqid('wo_') . '.' . $ext;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $filename)) {
            $photo_path = 'uploads/wo/' . $filename;
        }
    }

    $stmt = $conn->prepare("INSERT INTO orders (type, submitted_by, submitted_name, building, room, time_from, time_to, purpose, problem_type, description, priority, photo_path) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('ssssssssssss', $type, $user_email, $submitted_name, $building, $room, $time_from, $time_to, $purpose, $problem_type, $description, $priority, $photo_path);
    $stmt->execute();

    if ($stmt->affected_rows) {
        $wo_num = 'WO-' . str_pad($conn->insert_id, 6, '0', STR_PAD_LEFT);
        echo json_encode(['success' => true, 'wo_num' => $wo_num]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }

    $stmt->close();
    $conn->close();
    exit;
}







// Role display label and badge color

$role_labels = ['A' => 'Admin', 'M' => 'Manager', 'BA' => 'Building Admin', 'BT' => 'Building Tech', 'BC' => 'Building Custodian', 'BM' => 'Building Maintenance', 'U' => 'User'];

$role_label  = $role_labels[$user_role] ?? 'User';

$role_colors = [

    'A'  => 'background:#f3e8ff;color:#6b21a8',

    'M'  => 'background:#fef3c7;color:#92400e',

    'BA' => 'background:#e6f7fb;color:#1a9ab8',

    'BT' => 'background:#dcfce7;color:#166534',

    'BC' => 'background:#fef9c3;color:#854d0e',

    'BM' => 'background:#ffe4e6;color:#9f1239',

    'U'  => 'background:#f1f5f9;color:#475569',

];



$role_style = $role_colors[$user_role] ?? $role_colors['U'];

// ── Fetch work orders based on role ──────────────────────────
require_once __DIR__ . '/../../wo_config.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');
$orders = [];
if (in_array($user_role, ['A', 'M'])) {
    $res = $db->query("SELECT * FROM orders ORDER BY created_at DESC");
    if ($res) while ($row = $res->fetch_assoc()) $orders[] = $row;
} elseif ($user_role === 'BA') {
    $stmt = $db->prepare("SELECT * FROM orders WHERE building = ? ORDER BY created_at DESC");
    $stmt->bind_param('s', $user_building);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $orders[] = $row;
    $stmt->close();
} elseif ($user_role === 'BT') {
    $stmt = $db->prepare("SELECT * FROM orders WHERE building = ? AND type = 'Technology' ORDER BY created_at DESC");
    $stmt->bind_param('s', $user_building);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $orders[] = $row;
    $stmt->close();
} elseif (in_array($user_role, ['BM', 'BC'])) {
    $stmt = $db->prepare("SELECT * FROM orders WHERE building = ? AND type = 'Maintenance' ORDER BY created_at DESC");
    $stmt->bind_param('s', $user_building);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $orders[] = $row;
    $stmt->close();
} else {
    $stmt = $db->prepare("SELECT * FROM orders WHERE submitted_by = ? ORDER BY created_at DESC");
    $stmt->bind_param('s', $user_email);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $orders[] = $row;
    $stmt->close();
}
$db->close();



?>



<!DOCTYPE html>



<html lang="en">



<head>



<meta charset="UTF-8">



<meta name="viewport" content="width=device-width, initial-scale=1.0">



<title>Warrick County – Work Order Portal</title>



<link href="https://fonts.googleapis.com/css2?family=Barlow:wght@300;400;500;600;700&family=Barlow+Condensed:wght@500;600;700&display=swap" rel="stylesheet">



<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">



<style>



*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}



body{font-family:'Barlow',sans-serif;background:#f0f4f8;color:#1a1a2e;min-height:100vh;display:flex;flex-direction:column}



:root{



    --cyan:#29b6d5;



    --cyan-dark:#1a9ab8;



    --cyan-light:#e6f7fb;



    --cyan-muted:#c5eaf3;



}







/* ── NAV ── */



.nav{



    background:#fff;



    border-bottom:1px solid #e8ecf0;



    padding:0 28px;



    height:58px;



    display:flex;



    align-items:center;



    justify-content:space-between;



    position:sticky;



    top:0;



    z-index:100;



}



.nav-left{display:flex;align-items:center;gap:14px}



.nav-logo{width:36px;height:36px;background:var(--cyan);border-radius:9px;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:background .15s}

.nav-logo:hover{background:var(--cyan-dark)}



.nav-logo i{color:#fff;font-size:19px}



.nav-title{font-family:'Barlow Condensed',sans-serif;font-size:18px;font-weight:600;letter-spacing:.02em;color:#1a1a2e}



.nav-title span{color:var(--cyan)}



.nav-right{display:flex;align-items:center;gap:10px;position:relative}



.notif-btn{width:36px;height:36px;border-radius:8px;border:1px solid #e8ecf0;background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#6b7a8d}



.notif-btn:hover{background:#f8f9fa}



.avatar{width:36px;height:36px;border-radius:50%;background:var(--cyan);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#fff;cursor:pointer;border:2px solid var(--cyan-muted);overflow:hidden;flex-shrink:0}



.avatar img{width:100%;height:100%;object-fit:cover}



.profile-dropdown{



    position:absolute;top:46px;right:0;



    width:248px;



    background:#fff;



    border:1px solid #e8ecf0;



    border-radius:12px;



    padding:16px;



    z-index:200;



    display:none;



    box-shadow:0 8px 24px rgba(0,0,0,0.08);



}



.profile-dropdown.open{display:block}



.pd-header{display:flex;align-items:center;gap:12px;margin-bottom:14px}



.pd-avatar{width:46px;height:46px;border-radius:50%;background:var(--cyan);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;color:#fff;flex-shrink:0;overflow:hidden}



.pd-avatar img{width:100%;height:100%;object-fit:cover}



.pd-name{font-weight:600;font-size:14px;color:#1a1a2e}



.pd-email{font-size:12px;color:#6b7a8d;margin-top:2px;word-break:break-all}



.pd-role-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px;margin-top:8px}



.pd-divider{border:none;border-top:1px solid #f0f4f8;margin:10px 0}



.pd-item{display:flex;align-items:center;gap:10px;padding:8px 8px;border-radius:8px;cursor:pointer;font-size:13px;color:#6b7a8d;width:100%;background:transparent;border:none;font-family:'Barlow',sans-serif;text-align:left;text-decoration:none}



.pd-item:hover{background:#f8f9fa;color:#1a1a2e}



.pd-item.danger{color:#dc2626}



.pd-item.danger:hover{background:#fff5f5}







/* ── MAIN ── */



.main{max-width:1300px;margin:0 auto;padding:32px 24px 48px;flex:1}







/* ── WELCOME ── */



.welcome-bar{margin-bottom:28px}



.welcome-bar h1{font-family:'Barlow Condensed',sans-serif;font-size:28px;font-weight:700;letter-spacing:.01em;color:#1a1a2e}



.welcome-bar p{font-size:14px;color:#6b7a8d;margin-top:5px}







/* ── TYPE CARDS ── */



.type-cards{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:36px}



.type-card{background:#fff;border:1px solid #e8ecf0;border-radius:14px;padding:20px 24px;cursor:pointer;transition:border-color .15s,transform .12s,box-shadow .15s}



.type-card:hover{border-color:var(--cyan);transform:translateY(-2px);box-shadow:0 8px 24px rgba(41,182,213,.12)}



.type-card-title{display:flex;align-items:center;gap:10px;margin-bottom:6px}



.type-card-icon{width:26px;height:26px;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px}



.type-card-icon.maint{background:#fef3c7;color:#b45309}



.type-card-icon.tech{background:#dbeafe;color:#1d4ed8}



.type-card h2{font-family:'Barlow Condensed',sans-serif;font-size:21px;font-weight:700;color:#1a1a2e}



.type-card p{font-size:13px;color:#6b7a8d;line-height:1.55}



.type-card-cta{display:inline-flex;align-items:center;gap:6px;margin-top:10px;font-size:13px;font-weight:600;color:var(--cyan)}







/* ── SECTION HEAD ── */



.section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}



.section-head h2{font-family:'Barlow Condensed',sans-serif;font-size:21px;font-weight:700;color:#1a1a2e}



.filter-tabs{display:flex;gap:6px}



.filter-tab{padding:5px 16px;border-radius:20px;border:1px solid #e8ecf0;background:transparent;font-size:12px;font-weight:600;cursor:pointer;color:#6b7a8d;font-family:'Barlow',sans-serif;transition:all .12s}



.filter-tab.active{background:var(--cyan);color:#fff;border-color:var(--cyan)}







.nav-links{display:flex;align-items:center;gap:4px;margin-left:20px}

.nav-link{display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;font-size:13px;font-weight:600;color:#6b7a8d;text-decoration:none;transition:all .12s;border:none;background:transparent;cursor:pointer;font-family:'Barlow',sans-serif}

.nav-link:hover{background:#f0f4f8;color:#1a1a2e}

.nav-link.active{background:var(--cyan-light);color:var(--cyan-dark)}

/* ── WO TABLE ── */

.wo-table-wrap{background:#fff;border:1px solid #e8ecf0;border-radius:12px;overflow:hidden}



.wo-table{width:100%;border-collapse:collapse;font-size:12px;table-layout:fixed}



.wo-table th{padding:9px 8px;text-align:left;font-weight:700;font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#6b7a8d;background:#f8f9fa;border-bottom:1px solid #e8ecf0;white-space:nowrap;overflow:hidden}



.wo-table td{padding:11px 8px;border-bottom:1px solid #f0f4f8;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}



.wo-table tr:last-child td{border-bottom:none}



.wo-table tbody tr:hover td{background:#f0f8fb;cursor:pointer}



.wo-table th.sortable{cursor:pointer;user-select:none;white-space:nowrap}

.wo-table th.sortable:hover{background:#eef1f5;color:#1a1a2e}

.wo-table th.sort-asc .sort-icon::after{content:'↑';display:inline;margin-left:2px}

.wo-table th.sort-desc .sort-icon::after{content:'↓';display:inline;margin-left:2px}

.wo-table th.sort-asc .sort-icon,

.wo-table th.sort-desc .sort-icon{color:var(--cyan)}

.sort-icon{font-size:10px;color:#d0d5dd;margin-left:3px;transition:color .12s}

.wo-table th.sort-asc,

.wo-table th.sort-desc{color:var(--cyan-dark);background:#f0f9fb}



.wo-id{font-weight:700;color:var(--cyan);font-family:'Barlow Condensed',sans-serif;font-size:14px;white-space:nowrap}



.wo-desc{color:#6b7a8d;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}







/* badges */



.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}



.badge-pending{background:#fef3c7;color:#92400e}



.badge-approved{background:#d1fae5;color:#065f46}



.badge-inprogress{background:#dbeafe;color:#1e40af}



.badge-completed{background:#f0fdf4;color:#166534}



.badge-rejected{background:#fee2e2;color:#991b1b}



.badge-maint{background:#fef3c7;color:#b45309}



.badge-tech{background:#dbeafe;color:#1d4ed8}



.pri{display:inline-block;padding:3px 10px;border-radius:5px;font-size:11px;font-weight:700}



.pri-low{background:#d1fae5;color:#065f46}



.pri-mid{background:#dbeafe;color:#1e40af}



.pri-high{background:#fef3c7;color:#92400e}



.pri-urgent{background:#fee2e2;color:#991b1b}



.empty-state{text-align:center;padding:52px 20px;color:#aab0bb}



.empty-state i{font-size:42px;color:#d0d5dd;display:block;margin-bottom:12px}







/* ── MODAL OVERLAY ── */



.modal-overlay{



    display:none;



    position:fixed;inset:0;



    background:rgba(0,0,0,0.45);



    z-index:300;



    align-items:flex-start;



    justify-content:center;



    padding:28px 16px;



    overflow-y:auto;



}



.modal-overlay.open{display:flex}



.modal{



    background:#fff;



    border-radius:16px;



    width:100%;



    max-width:860px;



    margin:auto;



    box-shadow:0 20px 60px rgba(0,0,0,0.15);



    overflow:hidden;



}



.modal-header{display:flex;align-items:center;justify-content:space-between;padding:20px 26px 16px;border-bottom:1px solid #f0f4f8}



.modal-header-left{display:flex;align-items:center;gap:14px}



.modal-type-icon{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:20px}



.modal-type-icon.maint{background:#fef3c7;color:#b45309}



.modal-type-icon.tech{background:#dbeafe;color:#1d4ed8}



.modal-title{font-family:'Barlow Condensed',sans-serif;font-size:20px;font-weight:700;color:#1a1a2e}



.modal-subtitle{font-size:12px;color:#6b7a8d;margin-top:2px}



.close-btn{width:34px;height:34px;border-radius:8px;border:1px solid #e8ecf0;background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#6b7a8d;flex-shrink:0}



.close-btn:hover{background:#f8f9fa}



.modal-body{padding:20px 26px}



.modal-footer{padding:14px 26px;border-top:1px solid #f0f4f8;display:flex;align-items:center;justify-content:flex-end;gap:10px;background:#fafafa}







/* ── TWO-COLUMN MODAL LAYOUT ── */



.modal-cols{display:grid;grid-template-columns:1fr 1fr;gap:0;align-items:start}



.modal-col-left{padding-right:22px;border-right:1px solid #f0f4f8}



.modal-col-right{padding-left:22px}



@media(max-width:660px){



    .modal-cols{grid-template-columns:1fr}



    .modal-col-left{padding-right:0;border-right:none;border-bottom:1px solid #f0f4f8;padding-bottom:16px;margin-bottom:4px}



    .modal-col-right{padding-left:0}



}







/* ── TIME RANGE PICKER ── */



.time-range-wrap{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:6px}



.time-range-sep{font-size:11px;font-weight:700;color:#aab0bb;text-align:center;white-space:nowrap}



input[type=time]{



    width:100%;border:1px solid #d0d5dd;border-radius:9px;



    padding:9px 11px;font-size:13px;font-family:'Barlow',sans-serif;



    color:#1a1a2e;background:#fff;transition:border-color .12s;



}



input[type=time]:focus{outline:none;border-color:var(--cyan);box-shadow:0 0 0 3px rgba(41,182,213,.12)}







/* ── MAINTENANCE-ONLY FIELDS ── */



.maint-only.hidden{display:none!important}







/* ── OTHER PROBLEM TEXT ── */



.other-problem-wrap{margin-top:10px;display:none}



.other-problem-wrap.visible{display:block}







/* ── FORM ── */



.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}



.form-group{margin-bottom:0}



.form-group.full{grid-column:1/-1}



label.form-label{display:block;font-size:11px;font-weight:700;color:#6b7a8d;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}



input[type=text],input[type=email],select,textarea{



    width:100%;



    border:1px solid #d0d5dd;



    border-radius:9px;



    padding:10px 13px;



    font-size:14px;



    font-family:'Barlow',sans-serif;



    color:#1a1a2e;



    background:#fff;



    transition:border-color .12s;



}



input[type=text]:focus,select:focus,textarea:focus{outline:none;border-color:var(--cyan);box-shadow:0 0 0 3px rgba(41,182,213,.12)}



input[type=email]:read-only,input[readonly]{background:#f8f9fa;color:#6b7a8d;cursor:default}



textarea{resize:vertical;min-height:100px;line-height:1.55}



select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 11px center;padding-right:34px}







/* priority pills */



.priority-group{display:flex;gap:8px;flex-wrap:wrap}



.pri-pill{



    padding:8px 18px;



    border-radius:9px;



    border:1.5px solid #e8ecf0;



    font-size:13px;font-weight:700;



    cursor:pointer;



    background:transparent;



    font-family:'Barlow',sans-serif;



    color:#6b7a8d;



    transition:all .12s;



}



.pri-pill[data-p="Low"].sel,   .pri-pill[data-p="Low"]:hover  {background:#d1fae5;border-color:#10b981;color:#065f46}



.pri-pill[data-p="Mid"].sel,   .pri-pill[data-p="Mid"]:hover  {background:#dbeafe;border-color:#3b82f6;color:#1e40af}



.pri-pill[data-p="High"].sel,  .pri-pill[data-p="High"]:hover {background:#fef3c7;border-color:#f59e0b;color:#92400e}



.pri-pill[data-p="Urgent"].sel,.pri-pill[data-p="Urgent"]:hover{background:#fee2e2;border-color:#ef4444;color:#991b1b}







/* upload zone */



.upload-zone{



    border:1.5px dashed #d0d5dd;



    border-radius:11px;



    padding:22px 16px;



    text-align:center;



    cursor:pointer;



    color:#6b7a8d;



    font-size:13px;



    transition:border-color .15s,background .15s;



}



.upload-zone:hover{border-color:var(--cyan);background:var(--cyan-light);color:var(--cyan-dark)}



.upload-zone i{font-size:30px;display:block;margin-bottom:8px;color:#aab0bb}



.upload-zone.has-file{border-color:var(--cyan);background:var(--cyan-light);color:var(--cyan-dark)}



.upload-zone.has-file i{color:var(--cyan)}



.upload-zone small{display:block;font-size:11px;margin-top:5px;color:#aab0bb}







/* buttons */



.btn{padding:10px 24px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;font-family:'Barlow',sans-serif;border:none;display:inline-flex;align-items:center;gap:7px;transition:all .12s}



.btn-primary{background:var(--cyan);color:#fff}



.btn-primary:hover{background:var(--cyan-dark)}



.btn-ghost{background:transparent;color:#6b7a8d;border:1px solid #d0d5dd}



.btn-ghost:hover{background:#f8f9fa;color:#1a1a2e}







/* success state */



.success-state{text-align:center;padding:48px 24px}



.success-icon{width:68px;height:68px;background:#d1fae5;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;font-size:32px;color:#10b981}



.success-wo-num{font-family:'Barlow Condensed',sans-serif;font-size:32px;font-weight:700;color:var(--cyan);margin:10px 0 6px}







/* form spacing helper */



.form-row .form-group{margin-bottom:0}



.form-spacer{height:16px}



/* ── DETAIL MODAL ── */

.detail-modal{max-width:700px}

.detail-section{margin-bottom:20px}

.detail-section-title{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#aab0bb;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid #f0f4f8}

.detail-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}

.detail-field label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#aab0bb;display:block;margin-bottom:3px}

.detail-field p{font-size:13px;color:#1a1a2e;font-weight:500;line-height:1.5}

.detail-field.full{grid-column:1/-1}

.detail-desc{background:#f8f9fa;border-radius:9px;padding:12px 14px;font-size:13px;color:#3d4f5e;line-height:1.65;white-space:pre-wrap}

.attachment-thumb{width:100%;border-radius:9px;border:1px solid #e8ecf0;overflow:hidden;background:#f8f9fa;display:flex;align-items:center;justify-content:center;min-height:120px}

.attachment-thumb img{width:100%;height:auto;display:block}

.attachment-placeholder{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:28px;color:#aab0bb;gap:8px;font-size:12px}

.attachment-placeholder i{font-size:28px}

.status-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}







/* ── FOOTER ── */



.site-footer{



    background:#0B1F2E;



    border-top:1px solid rgba(27,188,212,0.12);



    padding:28px 28px 24px;



    flex-shrink:0;



}



.footer-inner{



    max-width:920px;



    margin:0 auto;



    display:flex;



    align-items:center;



    justify-content:space-between;



    gap:16px;



    flex-wrap:wrap;



}



.footer-brand{display:flex;align-items:center;gap:14px}



.footer-logo{



    width:32px;



    height:auto;



    filter:brightness(0) invert(1);



    opacity:0.65;



}



.footer-brand-name{



    font-family:'Barlow Condensed',sans-serif;



    font-size:14px;



    font-weight:600;



    color:rgba(255,255,255,0.70);



    letter-spacing:0.02em;



}



.footer-brand-sub{



    font-size:11px;



    color:rgba(255,255,255,0.28);



    letter-spacing:0.1em;



    text-transform:uppercase;



    margin-top:2px;



}



.footer-copy{



    font-size:12px;



    color:rgba(255,255,255,0.25);



    letter-spacing:0.02em;



    text-align:right;



}



@media(max-width:600px){



    .footer-inner{flex-direction:column;align-items:flex-start;gap:12px}



    .footer-copy{text-align:left}



}



</style>



</head>



<body>







<!-- ============================================================



     NAV BAR



============================================================ -->



<nav class="nav">



    <div class="nav-left">



        <a href="main.php" class="nav-logo" aria-label="Home" title="Home"><i class="ti ti-home" aria-hidden="true"></i></a>



        <div class="nav-title">Warrick County <span>Work Order System</span></div>



    </div>



    <div class="nav-right">



        <button class="notif-btn" aria-label="Notifications">



            <i class="ti ti-bell" aria-hidden="true"></i>



        </button>



        <div class="avatar" id="avatar-btn" aria-label="Profile menu" role="button" tabindex="0">



            <?php if ($user_pic): ?>



                <img src="<?= htmlspecialchars($user_pic) ?>" alt="Profile photo">



            <?php else: ?>



                <?= htmlspecialchars($initials) ?>



            <?php endif; ?>



        </div>







        <!-- Profile dropdown -->



        <div class="profile-dropdown" id="profile-dd" role="menu">



            <div class="pd-header">



                <div class="pd-avatar">



                    <?php if ($user_pic): ?>



                        <img src="<?= htmlspecialchars($user_pic) ?>" alt="Profile photo">



                    <?php else: ?>



                        <?= htmlspecialchars($initials) ?>



                    <?php endif; ?>



                </div>



                <div>



                    <div class="pd-name"><?= htmlspecialchars($user_name) ?></div>



                    <div class="pd-email"><?= htmlspecialchars($user_email) ?></div>



                    <div class="pd-role-badge" style="<?= $role_style ?>">



                        <i class="ti ti-shield-check" aria-hidden="true"></i>



                        <?= htmlspecialchars($role_label) ?>



                    </div>



                </div>



            </div>



            <hr class="pd-divider">



            <button class="pd-item"><i class="ti ti-user-circle" aria-hidden="true"></i> My profile</button>



            <button class="pd-item"><i class="ti ti-settings" aria-hidden="true"></i> Settings</button>



            <?php if ($user_role === 'A'): ?>



            <a href="manage.php" class="pd-item"><i class="ti ti-users" aria-hidden="true"></i> Manage Users</a>



            <?php endif; ?>



            <hr class="pd-divider">



            <a href="logout.php" class="pd-item danger" style="text-decoration:none">



                <i class="ti ti-logout" aria-hidden="true"></i> Sign out



            </a>



        </div>



    </div>



</nav>







<!-- ============================================================



     MAIN CONTENT



============================================================ -->



<main class="main">







    <!-- Welcome -->



    <div class="welcome-bar">



        <h1>Welcome back, <?= htmlspecialchars(explode(' ', $user_name)[0]) ?> 👋</h1>



        <p>Submit a new work order or check the status of your existing requests.</p>



    </div>







    <!-- Work order type cards -->



    <div class="type-cards">



        <div class="type-card" id="card-maint" role="button" tabindex="0" aria-label="Create maintenance work order">



            <div class="type-card-title">



                <div class="type-card-icon maint"><i class="ti ti-tool" aria-hidden="true"></i></div>



                <h2>Maintenance Request</h2>



            </div>



            <p>Report a facilities issue — HVAC, plumbing, painting, doors, locks, flooring, and more.</p>



            <div class="type-card-cta"><i class="ti ti-plus" aria-hidden="true"></i> New maintenance work order</div>



        </div>



        <div class="type-card" id="card-tech" role="button" tabindex="0" aria-label="Create technology work order">



            <div class="type-card-title">



                <div class="type-card-icon tech"><i class="ti ti-device-laptop" aria-hidden="true"></i></div>



                <h2>Technology Request</h2>



            </div>



            <p>Report a technology issue — computers, projectors, printers, network, AV equipment, and more.</p>



            <div class="type-card-cta"><i class="ti ti-plus" aria-hidden="true"></i> New technology work order</div>



        </div>



    </div>







    <!-- My Work Orders -->



    <div class="section-head">



        <h2>My Work Orders</h2>



        <div class="filter-tabs">
            <button class="filter-tab active" data-filter="all">All</button>
            <button class="filter-tab" data-filter="Pending Approval">Pending</button>
            <button class="filter-tab" data-filter="Approved">Approved</button>
            <button class="filter-tab" data-filter="In Progress">In Progress</button>
            <button class="filter-tab" data-filter="Completed">Completed</button>
            <button class="filter-tab" data-filter="Rejected">Rejected</button>
        </div>



    </div>







    <div class="wo-table-wrap">



        <table class="wo-table" id="wo-table">



            <colgroup>

                <col style="width:7%">   <!-- WO # -->

                <col style="width:10%">  <!-- Submitted By -->

                <col style="width:8%">   <!-- Type -->

                <col style="width:7%">   <!-- Building -->

                <col style="width:9%">   <!-- Room -->

                <col style="width:17%">  <!-- Description -->

                <col style="width:9%">   <!-- Avail Time -->

                <col style="width:10%">  <!-- Problem Type -->

                <col style="width:6%">   <!-- Priority -->

                <col style="width:9%">   <!-- Status -->

                <col style="width:8%">   <!-- Submitted -->

            </colgroup>



            <thead>



                <tr>



                    <th data-sort="wo" class="sortable">WO # <span class="sort-icon">↕</span></th>



                    <th data-sort="submitter" class="sortable">Submitted By <span class="sort-icon">↕</span></th>



                    <th data-sort="type" class="sortable">Type <span class="sort-icon">↕</span></th>



                    <th data-sort="building" class="sortable">Building <span class="sort-icon">↕</span></th>



                    <th data-sort="room" class="sortable">Room <span class="sort-icon">↕</span></th>



                    <th>Description</th>



                    <th>Avail. Time</th>



                    <th data-sort="problem" class="sortable">Problem Type <span class="sort-icon">↕</span></th>



                    <th data-sort="priority" class="sortable">Priority <span class="sort-icon">↕</span></th>



                    <th data-sort="status" class="sortable">Status <span class="sort-icon">↕</span></th>



                    <th data-sort="submitted" class="sortable">Submitted <span class="sort-icon">↕</span></th>



                </tr>



            </thead>



            <tbody id="wo-tbody">
<?php
$status_badge = [
    'Pending Approval' => 'badge-pending',
    'Approved'         => 'badge-approved',
    'In Progress'      => 'badge-inprogress',
    'Completed'        => 'badge-completed',
    'Rejected'         => 'badge-rejected',
];
$pri_cls_map = ['Low'=>'pri-low','Mid'=>'pri-mid','High'=>'pri-high','Urgent'=>'pri-urgent'];
if (empty($orders)): ?>
<tr><td colspan="11"><div class="empty-state"><i class="ti ti-clipboard-off" aria-hidden="true"></i>No work orders found.</div></td></tr>
<?php else: foreach ($orders as $o):
    $wo_num    = 'WO-' . str_pad($o['id'], 6, '0', STR_PAD_LEFT);
    $type_cls  = $o['type'] === 'Maintenance' ? 'badge-maint' : 'badge-tech';
    $s_cls     = $status_badge[$o['status']] ?? 'badge-pending';
    $p_cls     = $pri_cls_map[$o['priority']] ?? 'pri-low';
    $date_fmt  = date('M j, Y', strtotime($o['created_at']));
    $time_disp = ($o['time_from'] && $o['time_to']) ? htmlspecialchars($o['time_from']).' – '.htmlspecialchars($o['time_to']) : '—';
    $desc_short = htmlspecialchars(mb_strimwidth($o['description'], 0, 50, '…'));
    $disp_name  = htmlspecialchars($o['submitted_name'] ?: $o['submitted_by']);
?>
<tr class="wo-row"
    data-filter="<?= htmlspecialchars($o['status']) ?>"
    data-wo="<?= $wo_num ?>"
    data-name="<?= htmlspecialchars($o['submitted_name']) ?>"
    data-email="<?= htmlspecialchars($o['submitted_by']) ?>"
    data-type="<?= htmlspecialchars($o['type']) ?>"
    data-building="<?= htmlspecialchars($o['building']) ?>"
    data-room="<?= htmlspecialchars($o['room']) ?>"
    data-desc="<?= htmlspecialchars($o['description']) ?>"
    data-time-from="<?= htmlspecialchars($o['time_from'] ?? '') ?>"
    data-time-to="<?= htmlspecialchars($o['time_to'] ?? '') ?>"
    data-purpose="<?= htmlspecialchars($o['purpose']) ?>"
    data-problem="<?= htmlspecialchars($o['problem_type']) ?>"
    data-priority="<?= htmlspecialchars($o['priority']) ?>"
    data-status="<?= htmlspecialchars($o['status']) ?>"
    data-submitted="<?= $date_fmt ?>"
    data-attachment="<?= htmlspecialchars($o['photo_path'] ?? '') ?>">
    <td><span class="wo-id"><?= $wo_num ?></span></td>
    <td style="color:#6b7a8d"><?= $disp_name ?></td>
    <td><span class="badge <?= $type_cls ?>"><?= htmlspecialchars($o['type']) ?></span></td>
    <td><strong><?= htmlspecialchars($o['building']) ?></strong></td>
    <td style="color:#6b7a8d;font-size:11px"><?= htmlspecialchars($o['room']) ?></td>
    <td class="wo-desc"><?= $desc_short ?></td>
    <td style="color:#6b7a8d;font-size:11px"><?= $time_disp ?></td>
    <td style="color:#6b7a8d;font-size:11px"><?= htmlspecialchars($o['problem_type']) ?></td>
    <td><span class="pri <?= $p_cls ?>"><?= htmlspecialchars($o['priority']) ?></span></td>
    <td><span class="badge <?= $s_cls ?>"><?= htmlspecialchars($o['status']) ?></span></td>
    <td style="color:#6b7a8d;font-size:11px"><?= $date_fmt ?></td>
</tr>
<?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</main>







<!-- ============================================================



     FOOTER



============================================================ -->



<footer class="site-footer">



    <div class="footer-inner">



        <div class="footer-brand">



            <img class="footer-logo" src="images/logo.png" alt="Warrick County School Corporation logo">



            <div>



                <div class="footer-brand-name">Warrick County School Corporation</div>



                <div class="footer-brand-sub">Work Order System</div>



            </div>



        </div>



        <div class="footer-copy">



            &copy; <?= date('Y') ?> Warrick County School Corporation<br>



            All rights reserved



        </div>



    </div>



</footer>







<!-- ============================================================



     WORK ORDER FORM MODAL



============================================================ -->



<div class="modal-overlay" id="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-title">



    <div class="modal" id="modal-box">







        <!-- Header -->



        <div class="modal-header">



            <div class="modal-header-left">



                <div class="modal-type-icon" id="modal-icon">



                    <i id="modal-icon-i" class="ti ti-tool" aria-hidden="true"></i>



                </div>



                <div>



                    <div class="modal-title" id="modal-title">Maintenance Request</div>



                    <div class="modal-subtitle">Fields marked * are required</div>



                </div>



            </div>



            <button class="close-btn" id="close-modal" aria-label="Close form">



                <i class="ti ti-x" aria-hidden="true"></i>



            </button>



        </div>







        <!-- Form body -->



        <div class="modal-body" id="modal-body">



            <form id="wo-form" novalidate>



                <input type="hidden" id="f-type" name="type" value="">







                <div class="modal-cols">







                    <!-- ═══ LEFT COLUMN ═══ -->



                    <div class="modal-col-left">







                        <div class="form-group" style="margin-bottom:14px">



                            <label class="form-label">Your email</label>



                            <input type="email" value="<?= htmlspecialchars($user_email) ?>" readonly>



                        </div>







                        <div class="form-group" style="margin-bottom:14px">



                            <label class="form-label" for="f-building">Building *</label>



                            <select id="f-building" name="building" required>



                                <?php if ($user_role === 'BA' && $user_building): ?>



                                    <option value="<?= htmlspecialchars($user_building) ?>" selected><?= htmlspecialchars($user_building) ?></option>



                                    <option disabled>──────────</option>



                                <?php else: ?>



                                    <option value="">Select building…</option>



                                <?php endif; ?>



                                <?php foreach (['CHS','BHS','THS','TMS','CSMS','BMS','CNMS','JHC'] as $b): ?>



                                    <option value="<?= $b ?>"><?= $b ?></option>



                                <?php endforeach; ?>



                            </select>



                        </div>







                        <div class="form-group" style="margin-bottom:14px">



                            <label class="form-label" for="f-room">Room / Location *</label>



                            <input type="text" id="f-room" name="room" placeholder="e.g. Room 214, Main Gym" required>



                        </div>







                        <!-- Maintenance-only: Time Available -->



                        <div class="form-group maint-only hidden" id="field-time" style="margin-bottom:14px">



                            <label class="form-label">



                                <i class="ti ti-clock" style="font-size:11px;vertical-align:middle;margin-right:3px" aria-hidden="true"></i>



                                Time Room / Area is Available



                            </label>



                            <div class="time-range-wrap">



                                <select id="f-time-from" name="time_from">



                                    <option value="">From…</option>



                                    <option>7:00 AM</option>



                                    <option>8:00 AM</option>



                                    <option>9:00 AM</option>



                                    <option>10:00 AM</option>



                                    <option>11:00 AM</option>



                                    <option>12:00 PM</option>



                                    <option>1:00 PM</option>



                                    <option>2:00 PM</option>



                                    <option>3:00 PM</option>



                                    <option>4:00 PM</option>



                                    <option>After 4:00 PM</option>



                                </select>



                                <span class="time-range-sep">to</span>



                                <select id="f-time-to" name="time_to">



                                    <option value="">To…</option>



                                    <option>7:00 AM</option>



                                    <option>8:00 AM</option>



                                    <option>9:00 AM</option>



                                    <option>10:00 AM</option>



                                    <option>11:00 AM</option>



                                    <option>12:00 PM</option>



                                    <option>1:00 PM</option>



                                    <option>2:00 PM</option>



                                    <option>3:00 PM</option>



                                    <option>4:00 PM</option>



                                    <option>After 4:00 PM</option>



                                </select>



                            </div>



                        </div>







                        <!-- Maintenance-only: Purpose -->



                        <div class="form-group maint-only hidden" id="field-purpose" style="margin-bottom:14px">



                            <label class="form-label" for="f-purpose">Purpose *</label>



                            <select id="f-purpose" name="purpose">



                                <option value="">Select purpose…</option>



                                <option>Event Setup</option>



								<option>General Custodial</option>



                                <option>General Grounds</option>



                                <option>General Maintenance</option>



                                <option>Preventative Maintenance</option>



                                <option>Vandalism</option>



                            </select>



                        </div>







                        <!-- Maintenance-only: Problem Type -->



                        <div class="form-group maint-only hidden" id="field-problem">



                            <label class="form-label" for="f-problem-type">Problem Type *</label>



                            <select id="f-problem-type" name="problem_type">
                                <option value="">- Select Problem Type -</option>
                                <optgroup label="Maintenance" class="maint-opts">
                                    <option>Cabling</option>
                                    <option>Carpentry</option>
                                    <option>Ceiling</option>
                                    <option>Clocks/Bells</option>
                                    <option>Custodial</option>
                                    <option>Doors and Hardware</option>
                                    <option>Electrical</option>
                                    <option>Equipment Maintenance</option>
                                    <option>Event Setup</option>
                                    <option>Flooring</option>
                                    <option>General Maintenance</option>
                                    <option>Glass/Window Repairs</option>
                                    <option>Grounds</option>
                                    <option>Hazmat/Waste</option>
                                    <option>Heating and Cooling</option>
                                    <option>Installation</option>
                                    <option>Keys and Locks</option>
                                    <option>Lighting</option>
                                    <option>Moving</option>
                                    <option>Mowing</option>
                                    <option>Painting</option>
                                    <option>Pest Control</option>
                                    <option>Plumbing</option>
                                    <option>Pool</option>
                                    <option>Supplies/Equipment</option>
                                    <option value="Other">Other</option>
                                </optgroup>
                                <optgroup label="Technology" class="tech-opts">
                                    <option>Admin Cell Phone</option>
                                    <option>Audio/Visual</option>
                                    <option>Chromebook</option>
                                    <option>Desktop</option>
                                    <option>Email</option>
                                    <option>Event Setup</option>
                                    <option>Filewave</option>
                                    <option>Interactive White Board</option>
                                    <option>Internet Connection</option>
                                    <option>Internet Filter</option>
                                    <option>iPad</option>
                                    <option>Laptop</option>
                                    <option>Miscellaneous/Questions (IT)</option>
                                    <option>Mouse</option>
                                    <option>Other</option>
                                    <option>Password/login</option>
                                    <option>Printers</option>
                                    <option>Projector</option>
                                    <option>Server</option>
                                    <option>Software Application</option>
                                    <option>Synergy</option>
                                    <option>Telephone</option>
                                    <option>Virus</option>
                                    <option>WCSC Website</option>
                                </optgroup>
                            </select>



                            <div class="other-problem-wrap" id="other-problem-wrap">



                                <input type="text" id="f-problem-other" name="problem_other" placeholder="Please describe the problem type…">



                            </div>



                        </div>







                    </div><!-- /modal-col-left -->







                    <!-- ═══ RIGHT COLUMN ═══ -->



                    <div class="modal-col-right">







                        <div class="form-group" style="margin-bottom:14px">



                            <label class="form-label" for="f-desc">Description *</label>



                            <textarea id="f-desc" name="description" style="min-height:185px" placeholder="Describe the issue in detail — what needs to be done, where exactly, and any relevant context…" required></textarea>



                        </div>







                        <div class="form-group" style="margin-bottom:14px">



                            <label class="form-label">Priority *</label>



                            <div class="priority-group">



                                <button type="button" class="pri-pill" data-p="Low">Low</button>



                                <button type="button" class="pri-pill" data-p="Mid">Mid</button>



                                <button type="button" class="pri-pill" data-p="High">High</button>



                                <button type="button" class="pri-pill" data-p="Urgent">Urgent</button>



                            </div>



                            <input type="hidden" id="f-priority" name="priority" value="">



                        </div>







                        <div class="form-group">



                            <label class="form-label">Photo (optional)</label>



                            <div class="upload-zone" id="upload-zone">



                                <i class="ti ti-photo-up" id="upload-icon" aria-hidden="true"></i>



                                <span id="upload-label">Click or drag a photo here</span>



                                <small>JPG, PNG or HEIC · Max 10 MB</small>



                            </div>



                            <input type="file" id="f-photo" name="photo" accept="image/*" style="display:none">



                        </div>







                    </div><!-- /modal-col-right -->







                </div><!-- /modal-cols -->







            </form>



        </div>







        <!-- Footer -->



        <div class="modal-footer" id="modal-footer">



            <button class="btn btn-ghost" id="cancel-modal">Cancel</button>



            <button class="btn btn-primary" id="submit-wo">



                <i class="ti ti-send" aria-hidden="true"></i> Submit Work Order



            </button>



        </div>







    </div>



</div>







<!-- ============================================================



     WORK ORDER DETAIL MODAL



============================================================ -->



<div class="modal-overlay" id="detail-overlay" role="dialog" aria-modal="true" aria-labelledby="detail-modal-title">



    <div class="modal detail-modal">



        <div class="modal-header">



            <div class="modal-header-left">



                <div class="modal-type-icon" id="detail-icon">



                    <i id="detail-icon-i" class="ti ti-tool" aria-hidden="true"></i>



                </div>



                <div>



                    <div class="modal-title" id="detail-modal-title">Work Order Details</div>



                    <div class="modal-subtitle" id="detail-subtitle">Loading…</div>



                </div>



            </div>



            <button class="close-btn" id="close-detail" aria-label="Close">



                <i class="ti ti-x" aria-hidden="true"></i>



            </button>



        </div>



        <div class="modal-body" style="max-height:75vh;overflow-y:auto">



            <!-- Identity row -->



            <div class="detail-section">



                <div style="display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px">



                    <p id="d-wo" style="font-family:'Barlow Condensed',sans-serif;font-size:22px;font-weight:700;color:var(--cyan)"></p>



                    <span id="d-status-badge" style="flex-shrink:0"></span>



                </div>



                <div class="detail-grid">



                    <div class="detail-field">



                        <label>Submitted By</label>



                        <p id="d-submitter"></p>



                        <p id="d-email" style="font-size:11px;color:#6b7a8d;margin-top:2px"></p>



                    </div>



                    <div class="detail-field">



                        <label>Submitted</label>



                        <p id="d-submitted"></p>



                    </div>



                </div>



            </div>



            <!-- Location row -->



            <div class="detail-section">



                <div class="detail-section-title">Location</div>



                <div class="detail-grid">



                    <div class="detail-field">



                        <label>Building</label>



                        <p id="d-building"></p>



                    </div>



                    <div class="detail-field">



                        <label>Room / Area</label>



                        <p id="d-room"></p>



                    </div>



                    <div class="detail-field" id="d-time-wrap">



                        <label>Time Available</label>



                        <p id="d-time"></p>



                    </div>



                </div>



            </div>



            <!-- Request details -->



            <div class="detail-section">



                <div class="detail-section-title">Request Details</div>



                <div class="detail-grid" style="margin-bottom:12px">



                    <div class="detail-field" id="d-purpose-wrap">



                        <label>Purpose</label>



                        <p id="d-purpose"></p>



                    </div>



                    <div class="detail-field" id="d-problem-wrap">



                        <label>Problem Type</label>



                        <p id="d-problem"></p>



                    </div>



                </div>



                <div class="detail-field full">



                    <label>Description</label>



                    <div class="detail-desc" id="d-desc"></div>



                </div>



            </div>



            <!-- Status & priority -->



            <div class="detail-section">



                <div class="detail-section-title">Priority</div>



                <div class="status-row">



                    <span id="d-priority-badge"></span>



                </div>



            </div>



            <!-- Attachment -->



            <div class="detail-section" id="d-attachment-section">



                <div class="detail-section-title">Attachment</div>



                <div class="attachment-thumb" id="d-attachment-wrap">



                    <div class="attachment-placeholder">



                        <i class="ti ti-photo-off" aria-hidden="true"></i>



                        <span>No attachment provided</span>



                    </div>



                </div>



            </div>



        </div>



        <div class="modal-footer">



            <button class="btn btn-ghost" id="close-detail-footer">Close</button>



        </div>



    </div>



</div>





<!-- ============================================================



     SUCCESS MODAL (hidden until submit)



============================================================ -->



<div class="modal-overlay" id="success-overlay" role="dialog" aria-modal="true">



    <div class="modal">



        <div class="modal-body">



            <div class="success-state">



                <div class="success-icon"><i class="ti ti-check" aria-hidden="true"></i></div>



                <h2 style="font-family:'Barlow Condensed',sans-serif;font-size:22px;font-weight:700;color:#1a1a2e">



                    Work Order Submitted!



                </h2>



                <div class="success-wo-num" id="success-wo-num">WO-100003</div>



                <p style="color:#6b7a8d;font-size:14px;line-height:1.65;max-width:360px;margin:0 auto 28px">



                    Your request is pending approval from your building administrator.



                    You'll be notified as it moves forward.



                </p>



                <div style="display:flex;gap:12px;justify-content:center">



                    <button class="btn btn-ghost" id="success-back">Back to dashboard</button>



                    <button class="btn btn-primary" id="success-new">Submit another</button>



                </div>



            </div>



        </div>



    </div>



</div>







<!-- ============================================================



     JAVASCRIPT



============================================================ -->



<script>



const USER_EMAIL = '<?= htmlspecialchars($user_email, ENT_QUOTES) ?>';
const USER_NAME  = '<?= htmlspecialchars($submitted_name, ENT_QUOTES) ?>';
const avatarBtn  = document.getElementById('avatar-btn');



const profileDd  = document.getElementById('profile-dd');







avatarBtn.addEventListener('click', function (e) {



    e.stopPropagation();



    profileDd.classList.toggle('open');



});



document.addEventListener('click', function (e) {



    if (!profileDd.contains(e.target) && e.target !== avatarBtn) {



        profileDd.classList.remove('open');



    }



});







document.getElementById('card-maint').addEventListener('click', function () { openModal('Maintenance'); });



document.getElementById('card-tech').addEventListener('click',  function () { openModal('Technology');  });







function openModal(type) {



    document.getElementById('f-type').value = type;



    const isMaint = type === 'Maintenance';



    document.getElementById('modal-title').textContent = type + ' Request';



    document.getElementById('modal-icon').className   = 'modal-type-icon ' + (isMaint ? 'maint' : 'tech');



    document.getElementById('modal-icon-i').className = 'ti ' + (isMaint ? 'ti-tool' : 'ti-device-laptop');



    document.getElementById('wo-form').reset();



    document.getElementById('f-priority').value = '';



    document.querySelectorAll('.pri-pill').forEach(p => p.classList.remove('sel'));



    document.getElementById('upload-label').textContent = 'Click or drag a photo here';



    document.getElementById('upload-zone').classList.remove('has-file');



    document.getElementById('upload-icon').className = 'ti ti-photo-up';



    document.querySelectorAll('.maint-only').forEach(function(el) {
        el.classList.remove('hidden');
    });
    document.getElementById('field-purpose').classList.toggle('hidden', !isMaint);
    document.getElementById('f-purpose').value = '';
    document.getElementById('f-problem-type').value = '';
    document.querySelector('.maint-opts').style.display = isMaint ? '' : 'none';
    document.querySelector('.tech-opts').style.display  = isMaint ? 'none' : '';



    // Reset other-problem field



    document.getElementById('other-problem-wrap').classList.remove('visible');



    document.getElementById('f-problem-other').value = '';



    document.getElementById('modal-overlay').classList.add('open');



}







function closeModal() { document.getElementById('modal-overlay').classList.remove('open'); }







document.getElementById('close-modal').addEventListener('click', closeModal);



document.getElementById('cancel-modal').addEventListener('click', closeModal);



document.getElementById('modal-overlay').addEventListener('click', function (e) { if (e.target === this) closeModal(); });







document.querySelectorAll('.pri-pill').forEach(function (pill) {



    pill.addEventListener('click', function () {



        document.querySelectorAll('.pri-pill').forEach(p => p.classList.remove('sel'));



        this.classList.add('sel');



        document.getElementById('f-priority').value = this.dataset.p;



    });



});







const uploadZone  = document.getElementById('upload-zone');



const fileInput   = document.getElementById('f-photo');



const uploadLabel = document.getElementById('upload-label');



const uploadIcon  = document.getElementById('upload-icon');







uploadZone.addEventListener('click', function () { fileInput.click(); });



fileInput.addEventListener('change', function () { if (this.files[0]) setUploadedFile(this.files[0].name); });



uploadZone.addEventListener('dragover',  function (e) { e.preventDefault(); this.style.borderColor = '#29b6d5'; });



uploadZone.addEventListener('dragleave', function ()  { this.style.borderColor = ''; });



uploadZone.addEventListener('drop', function (e) {



    e.preventDefault();



    this.style.borderColor = '';



    if (e.dataTransfer.files[0]) setUploadedFile(e.dataTransfer.files[0].name);



});







function setUploadedFile(name) {



    uploadLabel.textContent = name;



    uploadZone.classList.add('has-file');



    uploadIcon.className = 'ti ti-circle-check';



}







// Problem Type → show "Other" text field



document.getElementById('f-problem-type').addEventListener('change', function() {



    const wrap = document.getElementById('other-problem-wrap');



    wrap.classList.toggle('visible', this.value === 'Other');



    if (this.value !== 'Other') document.getElementById('f-problem-other').value = '';



});







document.getElementById('submit-wo').addEventListener('click', function () {



    const building = document.getElementById('f-building').value;



    const room     = document.getElementById('f-room').value.trim();



    const priority = document.getElementById('f-priority').value;



    const desc     = document.getElementById('f-desc').value.trim();



    const isMaint  = document.getElementById('f-type').value === 'Maintenance';



    if (!building || !room || !priority || !desc) {



        alert('Please fill in all required fields: building, room/location, priority, and description.');



        return;



    }



    const purpose = isMaint ? document.getElementById('f-purpose').value : 'Technology';
    const problem = document.getElementById('f-problem-type').value;
    if (isMaint && !purpose) { alert('Please select a Purpose.'); return; }
    if (!problem) { alert('Please select a Problem Type.'); return; }
    if (problem === 'Other' && !document.getElementById('f-problem-other').value.trim()) {
        alert('Please describe the problem type in the "Other" field.'); return;
    }



    const submitBtn = document.getElementById('submit-wo');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="ti ti-loader" aria-hidden="true"></i> Submitting…';

    const formData = new FormData(document.getElementById('wo-form'));
    formData.append('action', 'submit_wo');
    const photoFile = document.getElementById('f-photo').files[0];
    if (photoFile) formData.set('photo', photoFile);

    const newType     = document.getElementById('f-type').value;
    const newBuilding = document.getElementById('f-building').value;
    const newRoom     = document.getElementById('f-room').value.trim();
    const newDesc     = document.getElementById('f-desc').value.trim();
    const newTimeFrom = document.getElementById('f-time-from').value;
    const newTimeTo   = document.getElementById('f-time-to').value;
    const newPurpose  = isMaint ? document.getElementById('f-purpose').value : 'Technology';
    const newProblem  = document.getElementById('f-problem-type').value;
    const newPriority = document.getElementById('f-priority').value;

    fetch('', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(function(data) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="ti ti-send" aria-hidden="true"></i> Submit Work Order';
            if (data.success) {
                document.getElementById('success-wo-num').textContent = data.wo_num;
                injectNewRow({
                    wo:       data.wo_num,
                    type:     newType,
                    building: newBuilding,
                    room:     newRoom,
                    desc:     newDesc,
                    timeFrom: newTimeFrom,
                    timeTo:   newTimeTo,
                    purpose:  newPurpose,
                    problem:  newProblem,
                    priority: newPriority
                });
                closeModal();
                document.getElementById('success-overlay').classList.add('open');
            } else {
                alert(data.message || 'Submission failed. Please try again.');
            }
        })
        .catch(function() {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="ti ti-send" aria-hidden="true"></i> Submit Work Order';
            alert('Network error. Please check your connection and try again.');
        });



});







document.getElementById('success-back').addEventListener('click', function () { document.getElementById('success-overlay').classList.remove('open'); });



document.getElementById('success-new').addEventListener('click', function () {



    document.getElementById('success-overlay').classList.remove('open');



    openModal(document.getElementById('f-type').value || 'Maintenance');



});







// ── Sort + Filter System ─────────────────────────────────────────────────────



const priOrder = { 'Urgent': 0, 'High': 1, 'Mid': 2, 'Low': 3, '': 4 };

const statusOrder = { 'Pending Approval': 0, 'Approved': 1, 'In Progress': 2, 'Completed': 3, 'Rejected': 4, '': 5 };



let sortCol = null;

let sortDir = 1; // 1 = asc, -1 = desc

let activeFilter = 'all';



function getSortValue(row, col) {

    const d = row.dataset;

    switch(col) {

        case 'wo':        return d.wo || '';

        case 'submitter': return d.submitter || '';

        case 'type':      return d.type || '';

        case 'building':  return d.building || '';

        case 'room':      return d.room || '';

        case 'problem':   return d.problem || '';

        case 'priority':  return priOrder[d.priority] !== undefined ? priOrder[d.priority] : 9;

        case 'status':    return statusOrder[d.status] !== undefined ? statusOrder[d.status] : 9;

        case 'submitted': return new Date(d.submitted || 0).getTime() || 0;

        default: return '';

    }

}



function applyTable() {

    const tbody = document.getElementById('wo-tbody');

    let rows = Array.from(tbody.querySelectorAll('.wo-row'));



    // Filter

    rows.forEach(function(row) {

        const rf = row.dataset.filter;

        const show = activeFilter === 'all' || rf === activeFilter;

        row.style.display = show ? '' : 'none';

    });



    // Sort visible rows

    if (sortCol) {

        const visible = rows.filter(r => r.style.display !== 'none');

        visible.sort(function(a, b) {

            const av = getSortValue(a, sortCol);

            const bv = getSortValue(b, sortCol);

            if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * sortDir;

            return String(av).localeCompare(String(bv)) * sortDir;

        });

        visible.forEach(function(row) { tbody.appendChild(row); });

    }

}



// Filter tabs

document.querySelectorAll('.filter-tab').forEach(function (tab) {

    tab.addEventListener('click', function () {

        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));

        this.classList.add('active');

        activeFilter = this.dataset.filter;

        applyTable();

    });

});



// Sortable headers

document.querySelectorAll('.wo-table th.sortable').forEach(function(th) {

    th.addEventListener('click', function() {

        const col = this.dataset.sort;

        if (sortCol === col) {

            sortDir *= -1;

        } else {

            sortCol = col;

            sortDir = 1;

        }

        // Update header classes

        document.querySelectorAll('.wo-table th.sortable').forEach(function(h) {

            h.classList.remove('sort-asc', 'sort-desc');

            h.querySelector('.sort-icon').textContent = '↕';

        });

        this.classList.add(sortDir === 1 ? 'sort-asc' : 'sort-desc');

        this.querySelector('.sort-icon').textContent = '';

        applyTable();

    });

});







// ── Work Order Detail Modal ──────────────────────────────────────────────────



const closedStatuses = ['Completed','Rejected','Closed','Cancelled'];



const priClassMap = {'Low':'pri-low','Mid':'pri-mid','High':'pri-high','Urgent':'pri-urgent'};



const statusClassMap = {

    'Pending Approval':'badge-pending',

    'Approved':'badge-approved',

    'In Progress':'badge-inprogress',

    'Completed':'badge-completed',

    'Rejected':'badge-rejected',

};



function openDetailModal(d) {
    const isMaint = d.type === 'Maintenance';
    document.getElementById('detail-icon').className    = 'modal-type-icon ' + (isMaint ? 'maint' : 'tech');
    document.getElementById('detail-icon-i').className  = 'ti ' + (isMaint ? 'ti-tool' : 'ti-device-laptop');
    document.getElementById('detail-modal-title').textContent = d.type + ' Request';
    document.getElementById('detail-subtitle').textContent    = d.wo;
    document.getElementById('d-wo').textContent        = d.wo;
    document.getElementById('d-submitter').textContent = d.name || d.email || '—';
    document.getElementById('d-email').textContent     = d.email || '';
    document.getElementById('d-submitted').textContent = d.submitted || '—';
    document.getElementById('d-building').textContent  = d.building  || '—';
    document.getElementById('d-room').textContent      = d.room      || '—';
    document.getElementById('d-desc').textContent      = d.desc      || '—';
    const timeWrap = document.getElementById('d-time-wrap');
    if (d.timeFrom && d.timeTo) {
        document.getElementById('d-time').textContent = d.timeFrom + ' – ' + d.timeTo;
        timeWrap.style.display = '';
    } else {
        timeWrap.style.display = 'none';
    }
    const purposeWrap = document.getElementById('d-purpose-wrap');
    const problemWrap = document.getElementById('d-problem-wrap');
    if (isMaint) {
        document.getElementById('d-purpose').textContent = d.purpose || '—';
        document.getElementById('d-problem').textContent = d.problem || '—';
        purposeWrap.style.display = '';
        problemWrap.style.display = '';
    } else {
        purposeWrap.style.display = 'none';
        problemWrap.style.display = 'none';
    }
    const priEl = document.getElementById('d-priority-badge');
    priEl.className   = 'pri ' + (priClassMap[d.priority] || 'pri-low');
    priEl.textContent = d.priority || '—';
    const statusEl = document.getElementById('d-status-badge');
    statusEl.className   = 'badge ' + (statusClassMap[d.status] || 'badge-pending');
    statusEl.textContent = d.status || '—';
    const attachWrap = document.getElementById('d-attachment-wrap');
    if (d.attachment && d.attachment.trim()) {
        attachWrap.innerHTML = '<img src="' + d.attachment + '" alt="Work order attachment">';
    } else {
        attachWrap.innerHTML = '<div class="attachment-placeholder"><i class="ti ti-photo-off" aria-hidden="true"></i><span>No attachment provided</span></div>';
    }
    document.getElementById('detail-overlay').classList.add('open');
}

function injectNewRow(d) {
    const now    = new Date();
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const dateStr   = months[now.getMonth()] + ' ' + now.getDate() + ', ' + now.getFullYear();
    const timeDisp  = (d.timeFrom && d.timeTo) ? d.timeFrom + ' – ' + d.timeTo : '—';
    const typeCls   = d.type === 'Maintenance' ? 'badge-maint' : 'badge-tech';
    const priMap    = {Low:'pri-low',Mid:'pri-mid',High:'pri-high',Urgent:'pri-urgent'};
    const priCls    = priMap[d.priority] || 'pri-low';
    const descShort = d.desc.length > 50 ? d.desc.substring(0, 50) + '…' : d.desc;
    const tr = document.createElement('tr');
    tr.className = 'wo-row';
    tr.dataset.filter     = 'Pending Approval';
    tr.dataset.wo         = d.wo;
    tr.dataset.name       = USER_NAME;
    tr.dataset.email      = USER_EMAIL;
    tr.dataset.type       = d.type;
    tr.dataset.building   = d.building;
    tr.dataset.room       = d.room;
    tr.dataset.desc       = d.desc;
    tr.dataset.timeFrom   = d.timeFrom;
    tr.dataset.timeTo     = d.timeTo;
    tr.dataset.purpose    = d.purpose;
    tr.dataset.problem    = d.problem;
    tr.dataset.priority   = d.priority;
    tr.dataset.status     = 'Pending Approval';
    tr.dataset.submitted  = dateStr;
    tr.dataset.attachment = '';
    tr.innerHTML = `
        <td><span class="wo-id">${d.wo}</span></td>
        <td style="color:#6b7a8d">${USER_NAME || USER_EMAIL}</td>
        <td><span class="badge ${typeCls}">${d.type}</span></td>
        <td><strong>${d.building}</strong></td>
        <td style="color:#6b7a8d;font-size:11px">${d.room}</td>
        <td class="wo-desc">${descShort}</td>
        <td style="color:#6b7a8d;font-size:11px">${timeDisp}</td>
        <td style="color:#6b7a8d;font-size:11px">${d.problem}</td>
        <td><span class="pri ${priCls}">${d.priority}</span></td>
        <td><span class="badge badge-pending">Pending Approval</span></td>
        <td style="color:#6b7a8d;font-size:11px">${dateStr}</td>`;
    tr.addEventListener('click', function() { openDetailModal(this.dataset); });
    const tbody = document.getElementById('wo-tbody');
    const emptyRow = tbody.querySelector('.empty-state');
    if (emptyRow) emptyRow.closest('tr').remove();
    tbody.insertBefore(tr, tbody.firstChild);
}

document.querySelectorAll('.wo-row').forEach(function(row) {
    row.addEventListener('click', function() { openDetailModal(this.dataset); });
});

function closeDetailModal() { document.getElementById('detail-overlay').classList.remove('open'); }
document.getElementById('close-detail').addEventListener('click', closeDetailModal);
document.getElementById('close-detail-footer').addEventListener('click', closeDetailModal);
document.getElementById('detail-overlay').addEventListener('click', function(e){ if(e.target===this) closeDetailModal(); });







</script>







</body>



</html>