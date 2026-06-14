<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['google_user'])) {
    header('Location: index.php');
    exit;
}

$user = $_SESSION['google_user'];
$user_email     = $user['email'];
$user_name      = $user['name']        ?? 'User';
$user_given     = $user['given_name']  ?? '';
$user_family    = $user['family_name'] ?? '';
$submitted_name = trim($user_given . ' ' . $user_family) ?: $user_name;
$user_pic       = $user['picture'] ?? '';

// Derive initials from name
$name_parts = explode(' ', trim($user_name));
$initials   = strtoupper(substr($name_parts[0], 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : ''));

// Role from session (set during login by DB lookup)
$user_role     = $_SESSION['user_role']     ?? 'U';
$user_building = $_SESSION['user_building'] ?? null;

// ── Mobile redirect for field roles ──────────────────────────
$_is_mobile = (bool) preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
if ($_is_mobile && in_array($user_role, ['MW','BC','BM','MM']) && ($_GET['desktop'] ?? '') !== '1') {
    header('Location: mobile/dashboard.php');
    exit;
}

// ── Handle work order submission ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_wo') {
    ini_set('display_errors', 0);
    ob_start();
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
        ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
        exit;
    }

    if (!empty($_FILES['photos']['name'][0])) {
        require_once __DIR__ . '/includes/image_upload.php';
        $saved = process_uploaded_images($_FILES['photos'], __DIR__ . '/wo_imgs/');
        if (!empty($saved)) {
            $photo_path = implode('||', $saved);
        }
    }

    $stmt = $conn->prepare("INSERT INTO orders (type, submitted_by, submitted_name, building, room, time_from, time_to, purpose, problem_type, description, priority, photo_path) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('ssssssssssss', $type, $user_email, $submitted_name, $building, $room, $time_from, $time_to, $purpose, $problem_type, $description, $priority, $photo_path);
    $stmt->execute();

    if ($stmt->affected_rows) {
        $insert_id = $conn->insert_id;
        $wo_num    = 'WO-' . str_pad($insert_id, 6, '0', STR_PAD_LEFT);

        if ($photo_path !== null) {
            $new_paths = [];
            foreach (explode('||', $photo_path) as $p) {
                $new_filename = $wo_num . '_' . date('Ymd') . '_' . substr(uniqid(), -6) . '.jpg';
                $old_full     = __DIR__ . '/' . $p;
                $new_full     = __DIR__ . '/wo_imgs/' . $new_filename;
                $new_paths[]  = rename($old_full, $new_full) ? 'wo_imgs/' . $new_filename : $p;
            }
            $photo_path = implode('||', $new_paths);
            $upd = $conn->prepare("UPDATE orders SET photo_path = ? WHERE id = ?");
            $upd->bind_param('si', $photo_path, $insert_id);
            $upd->execute();
            $upd->close();
        }

        // Set initial current_handler based on order type
        $initial_handler = ($type === 'Technology') ? 'BT' : 'BP';
        $upd2 = $conn->prepare("UPDATE orders SET current_handler=? WHERE id=?");
        $upd2->bind_param('si', $initial_handler, $insert_id);
        $upd2->execute();
        $upd2->close();

        $email_err = null;
        require_once __DIR__ . '/wo_mailer.php';
        if ($type === 'Technology') {
            try {
                send_tech_wo_email(
                    $conn,
                    $wo_num,
                    $building,
                    $room,
                    $problem_type,
                    $description,
                    $priority,
                    $submitted_name,
                    $user_email
                );
            } catch (\Throwable $e) {
                $email_err = get_class($e) . ': ' . $e->getMessage() . ' — ' . basename($e->getFile()) . ':' . $e->getLine();
                error_log('send_tech_wo_email threw: ' . $email_err);
            }
        } else {
            send_maintenance_submit_email(
                $conn,
                $wo_num,
                $building,
                $room,
                $problem_type,
                $description,
                $priority,
                $submitted_name,
                $user_email
            );
        }

        ob_end_clean(); echo json_encode(['success' => true, 'wo_num' => $wo_num, 'photo_path' => $photo_path ?? '', 'email_err' => $email_err]);
    } else {
        ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }

    $stmt->close();
    $conn->close();
    exit;
}

// Role display label and badge color
$role_labels = ['A' => 'Administrator', 'MT' => 'Technology Manager', 'MM' => 'Maintenance Manager', 'BP' => 'Building Principal', 'BT' => 'Building Technician', 'BC' => 'Building Custodian', 'BM' => 'Building Maintenance', 'MW' => 'Maintenance Worker', 'U' => 'User'];
$role_label  = $role_labels[$user_role] ?? 'User';
$role_colors = [
    'A'  => 'background:#f3e8ff;color:#6b21a8',
    'MT' => 'background:#fef3c7;color:#92400e',
    'MM' => 'background:#fce7f3;color:#9d174d',
    'BP' => 'background:#e6f7fb;color:#1a9ab8',
    'BT' => 'background:#dcfce7;color:#166534',
    'BC' => 'background:#fef9c3;color:#854d0e',
    'BM' => 'background:#ffe4e6;color:#9f1239',
    'MW' => 'background:#ede9fe;color:#5b21b6',
    'U'  => 'background:#f1f5f9;color:#475569',
];
$role_style = $role_colors[$user_role] ?? $role_colors['U'];

// ── Fetch work orders based on role ──────────────────────────
require_once __DIR__ . '/../../wo_config.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');
$orders = [];
if ($user_role === 'A') {
    $res = $db->query("SELECT * FROM orders ORDER BY created_at DESC");
    if ($res) while ($row = $res->fetch_assoc()) $orders[] = $row;
} elseif ($user_role === 'MT') {
    $res = $db->query("SELECT * FROM orders WHERE type = 'Technology' AND (current_handler IN ('MT','worker') OR status IN ('Completed','Rejected')) ORDER BY created_at DESC");
    if ($res) while ($row = $res->fetch_assoc()) $orders[] = $row;
} elseif ($user_role === 'MM') {
    $res = $db->query("SELECT * FROM orders WHERE type = 'Maintenance' AND (current_handler IN ('MM','worker') OR status IN ('Completed','Rejected')) ORDER BY created_at DESC");
    if ($res) while ($row = $res->fetch_assoc()) $orders[] = $row;
} elseif ($user_role === 'BP') {
    $stmt = $db->prepare("SELECT * FROM orders WHERE building = ? AND NOT (type = 'Technology' AND current_handler = 'BT') ORDER BY created_at DESC");
    $stmt->bind_param('s', $user_building);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $orders[] = $row;
    $stmt->close();
} elseif ($user_role === 'BT') {
    $bt_buildings = array_filter(array_map('trim', explode(',', $user_building ?? '')));
    if ($bt_buildings) {
        $placeholders = implode(',', array_fill(0, count($bt_buildings), '?'));
        $types = str_repeat('s', count($bt_buildings));
        $stmt = $db->prepare("SELECT * FROM orders WHERE building IN ($placeholders) AND type = 'Technology' ORDER BY created_at DESC");
        $stmt->bind_param($types, ...$bt_buildings);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $orders[] = $row;
        $stmt->close();
    }
} elseif (in_array($user_role, ['BM', 'BC', 'MW'])) {
    $stmt = $db->prepare(
        "SELECT o.* FROM orders o
         INNER JOIN order_assignments oa ON o.id = oa.order_id
         WHERE oa.user_email = ?
         ORDER BY o.created_at DESC"
    );
    $stmt->bind_param('s', $user_email);
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

$_default_filter = 'all';
if (in_array($user_role, ['MW', 'BC', 'BM'])) {
    foreach ($orders as $_o) {
        if ($_o['status'] === 'In Progress') { $_default_filter = 'In Progress'; break; }
    }
} else {
    foreach ($orders as $_o) {
        if ($_o['status'] === 'Pending Approval') { $_default_filter = 'Pending Approval'; break; }
    }
}

$current_page = 'main';
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
    --navy:#0B1F2E;
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
.notif-btn{width:36px;height:36px;border-radius:8px;border:1px solid #e8ecf0;background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#6b7a8d;position:relative}
.notif-btn:hover{background:#f8f9fa}
.notif-badge{position:absolute;top:-5px;right:-5px;background:#dc2626;color:#fff;font-size:10px;font-weight:700;min-width:16px;height:16px;border-radius:8px;display:flex;align-items:center;justify-content:center;padding:0 3px;border:2px solid #fff;line-height:1;font-family:'Barlow',sans-serif}
.notif-dropdown{position:absolute;top:46px;right:40px;width:320px;background:#fff;border:1px solid #e8ecf0;border-radius:12px;padding:0;z-index:200;display:none;box-shadow:0 8px 24px rgba(0,0,0,0.10);overflow:hidden}
.notif-dropdown.open{display:block}
.notif-dd-header{padding:12px 16px;border-bottom:1px solid #f0f4f8;font-size:12px;font-weight:700;color:#6b7a8d;text-transform:uppercase;letter-spacing:.06em}
.notif-item{display:flex;flex-direction:column;padding:12px 16px;border-bottom:1px solid #f0f4f8;cursor:pointer;transition:background .1s}
.notif-item:last-child{border-bottom:none}
.notif-item:hover{background:#f0f8fb}
.notif-item-wo{font-family:'Barlow Condensed',sans-serif;font-size:15px;font-weight:700;color:var(--cyan)}
.notif-item-meta{font-size:12px;color:#6b7a8d;margin-top:2px}
.notif-empty{padding:20px 16px;text-align:center;font-size:13px;color:#aab0bb}
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

/* nav-links */
.nav-links{display:flex;align-items:center;gap:4px;margin-left:20px}
.nav-link{display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;font-size:13px;font-weight:600;color:#6b7a8d;text-decoration:none;transition:all .12s;border:none;background:transparent;cursor:pointer;font-family:'Barlow',sans-serif}
.nav-link:hover{background:#f0f4f8;color:#1a1a2e}
.nav-link.active{background:var(--cyan-light);color:var(--cyan-dark)}

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
.modal-body{padding:14px 26px}
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
.upload-zone.drag-over{border-color:var(--cyan);background:var(--cyan-light);color:var(--cyan-dark);border-style:solid}
.upload-zone small{display:block;font-size:11px;margin-top:5px;color:#aab0bb}
#upload-preview{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.img-thumb-preview{position:relative;width:64px;height:64px;border-radius:8px;overflow:hidden;border:1.5px solid #e8ecf0;flex-shrink:0}
.img-thumb-preview img{width:100%;height:100%;object-fit:cover;display:block}
.thumb-remove{position:absolute;top:2px;right:2px;width:18px;height:18px;background:rgba(0,0,0,.6);border:none;color:#fff;border-radius:50%;cursor:pointer;font-size:12px;line-height:1;padding:0;display:flex;align-items:center;justify-content:center}
@keyframes progress-shimmer{0%{background-position:100% 0}100%{background-position:-100% 0}}
#upload-progress-bar.processing{background:linear-gradient(90deg,var(--cyan) 0%,#a8eeff 50%,var(--cyan) 100%);background-size:200% 100%;animation:progress-shimmer 1.5s ease-in-out infinite}

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

/* ── MOBILE RESPONSIVE ── */

/* Swipeable filter chips — hidden on desktop */
.mobile-filter-chips{display:none}

@media(max-width:768px){

    /* Nav */
    .nav{padding:0 14px}
    .nav-title{font-size:15px}
    .nav-links{display:none}

    /* Main padding */
    .main{padding:18px 14px 40px}

    /* Welcome bar */
    .welcome-bar{margin-bottom:20px}
    .welcome-bar h1{font-size:22px}

    /* Type cards: stack vertically, maintenance first */
    .type-cards{
        grid-template-columns:1fr;
        gap:12px;
        margin-bottom:24px;
    }

    /* Section head: just the title, chips go below */
    .section-head{
        flex-direction:column;
        align-items:flex-start;
        gap:8px;
        margin-bottom:0;
    }
    .section-head h2{
        font-size:19px;
        white-space:normal;
        line-height:1.2;
    }

    /* Hide desktop pill tabs */
    .filter-tabs{display:none}

    /* Show swipeable chips */
    .mobile-filter-chips{
        display:flex;
        gap:8px;
        overflow-x:auto;
        -webkit-overflow-scrolling:touch;
        padding:10px 0 12px;
        margin-bottom:10px;
        scrollbar-width:none;
    }
    .mobile-filter-chips::-webkit-scrollbar{display:none}
    .chip{
        flex-shrink:0;
        padding:7px 16px;
        border-radius:20px;
        border:1.5px solid #e8ecf0;
        background:#fff;
        font-size:13px;
        font-weight:600;
        color:#6b7a8d;
        font-family:'Barlow',sans-serif;
        cursor:pointer;
        white-space:nowrap;
        -webkit-tap-highlight-color:transparent;
    }
    .chip.active{
        background:var(--cyan);
        color:#fff;
        border-color:var(--cyan);
    }

    /* WO table: no horizontal scroll, fixed columns that fit the screen
       Visible: WO#(1), Building(4), Room(5), Problem Type(8), Priority(9), Status(10)
       Hidden:  Submitted By(2), Type(3), Description(6), Avail.Time(7), Submitted(11) */
    .wo-table-wrap{overflow-x:hidden}
    .wo-table{table-layout:fixed;width:100%}
    .wo-table colgroup col:nth-child(2),
    .wo-table colgroup col:nth-child(3),
    .wo-table colgroup col:nth-child(6),
    .wo-table colgroup col:nth-child(7),
    .wo-table colgroup col:nth-child(11)
    {display:none;width:0}
    .wo-table th:nth-child(2),
    .wo-table td:nth-child(2),
    .wo-table th:nth-child(3),
    .wo-table td:nth-child(3),
    .wo-table th:nth-child(6),
    .wo-table td:nth-child(6),
    .wo-table th:nth-child(7),
    .wo-table td:nth-child(7),
    .wo-table th:nth-child(11),
    .wo-table td:nth-child(11)
    {display:none}

    /* Column widths for the 6 visible columns — Problem Type gets what's left and truncates */
    .wo-table colgroup col:nth-child(1){width:18%}
    .wo-table colgroup col:nth-child(4){width:16%}
    .wo-table colgroup col:nth-child(5){width:14%}
    .wo-table colgroup col:nth-child(8){width:22%}
    .wo-table colgroup col:nth-child(9){width:14%}
    .wo-table colgroup col:nth-child(10){width:16%}

    /* Problem Type cell truncates with ellipsis */
    .wo-table td:nth-child(8){
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }

    .wo-table{font-size:11px}
    .wo-table td{padding:10px 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .wo-table th{padding:8px 6px;font-size:9px}
    .wo-id{font-size:12px}
    .badge{font-size:10px;padding:3px 6px}
    .pri{font-size:10px;padding:3px 6px}
}


/* ── FOOTER ── */
.site-footer{
    background:#0B1F2E;
    border-top:1px solid rgba(27,188,212,0.12);
    padding:28px 28px 24px;
    flex-shrink:0;
}
.footer-inner{
    max-width:1300px;
    margin:0 auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
}
.footer-brand{display:flex;align-items:center;gap:14px}
.footer-logo{width:32px;height:auto;filter:brightness(0) invert(1);opacity:0.65;}
.footer-brand-name{font-family:'Barlow Condensed',sans-serif;font-size:14px;font-weight:600;color:rgba(255,255,255,0.70);letter-spacing:0.02em}
.footer-brand-sub{font-size:11px;color:rgba(255,255,255,0.28);letter-spacing:0.1em;text-transform:uppercase;margin-top:2px}
.footer-copy{font-size:12px;color:rgba(255,255,255,0.25);letter-spacing:0.02em;text-align:right}
@media(max-width:600px){
    .footer-inner{flex-direction:column;align-items:flex-start;gap:12px}
    .footer-copy{text-align:left}
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/includes/nav.php'; ?>

<!-- ============================================================
     MAIN CONTENT
============================================================ -->
<main class="main">

    <!-- Welcome -->
    <div class="welcome-bar">
        <h1>Welcome back, <?= htmlspecialchars(explode(' ', $user_name)[0]) ?> 👋</h1>
        <p>Submit a new work order or check the status of your existing requests.</p>
    </div>

    <!-- Work order type cards — hidden for workers who only act on assigned orders -->
    <?php if (!in_array($user_role, ['MW', 'BC', 'BM'])): ?>
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
    <?php endif; ?>

    <!-- Work Orders table -->
    <div class="section-head">
        <h2 id="wo-section-title"><?= in_array($user_role, ['MW','BC','BM']) ? 'My Assigned Work Orders' : 'My Work Orders' ?></h2>
        <div class="filter-tabs">
            <button class="filter-tab<?= $_default_filter==='all' ? ' active' : '' ?>" data-filter="all">All</button>
            <?php if (!in_array($user_role, ['MW','BC','BM'])): ?>
            <button class="filter-tab" data-filter="Pending Approval">Pending</button>
            <button class="filter-tab" data-filter="Approved">Approved</button>
            <?php endif; ?>
            <button class="filter-tab" data-filter="In Progress">In Progress</button>
            <button class="filter-tab" data-filter="Completed">Completed</button>
            <button class="filter-tab" data-filter="Rejected">Rejected</button>
        </div>
    </div>
    <!-- Mobile swipeable filter chips (hidden on desktop via CSS) -->
    <div class="mobile-filter-chips" id="mobile-chips">
        <button class="chip<?= $_default_filter==='all' ? ' active' : '' ?>" data-filter="all">All</button>
        <?php if (!in_array($user_role, ['MW','BC','BM'])): ?>
        <button class="chip" data-filter="Pending Approval">Pending</button>
        <button class="chip" data-filter="Approved">Approved</button>
        <?php endif; ?>
        <button class="chip" data-filter="In Progress">In Progress</button>
        <button class="chip" data-filter="Completed">Completed</button>
        <button class="chip" data-filter="Rejected">Rejected</button>
    </div>

    <div class="wo-table-wrap">
        <table class="wo-table" id="wo-table">
            <colgroup>
                <col style="width:7%">
                <col style="width:10%">
                <col style="width:8%">
                <col style="width:7%">
                <col style="width:9%">
                <col style="width:17%">
                <col style="width:9%">
                <col style="width:10%">
                <col style="width:6%">
                <col style="width:9%">
                <col style="width:8%">
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
    $time_disp = $o['time_from']
        ? (($o['time_to'] && $o['time_to'] !== $o['time_from'])
            ? htmlspecialchars($o['time_from']) . ' – ' . htmlspecialchars($o['time_to'])
            : htmlspecialchars($o['time_from']))
        : '—';
    $desc_short = htmlspecialchars(mb_strimwidth($o['description'], 0, 50, '…'));
    $disp_name  = htmlspecialchars($o['submitted_name'] ?: $o['submitted_by']);
?>
<tr class="wo-row"
    data-id="<?= (int)$o['id'] ?>"
    data-filter="<?= htmlspecialchars($o['status']) ?>"
    data-wo="<?= $wo_num ?>"
    data-notes="<?= htmlspecialchars($o['notes'] ?? '') ?>"
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
        <div class="modal-body" id="modal-body">
            <form id="wo-form" novalidate>
                <input type="hidden" id="f-type" name="type" value="">
                <div class="modal-cols">
                    <div class="modal-col-left">
                        <div class="form-group" style="margin-bottom:14px">
                            <label class="form-label">Your email</label>
                            <input type="email" value="<?= htmlspecialchars($user_email) ?>" readonly>
                        </div>
                        <div class="form-group" style="margin-bottom:14px">
                            <label class="form-label" for="f-building">Building *</label>
                            <select id="f-building" name="building" required>
                                <?php if ($user_role === 'BP' && $user_building): ?>
                                    <option value="<?= htmlspecialchars($user_building) ?>" selected><?= htmlspecialchars($user_building) ?></option>
                                    <option disabled>──────────</option>
                                <?php else: ?>
                                    <option value="">Select building…</option>
                                <?php endif; ?>
                                <optgroup label="High Schools">
                                    <?php foreach (['CHS','BHS','THS','WPCC'] as $b): ?>
                                    <option value="<?= $b ?>"><?= $b ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="Middle Schools">
                                    <?php foreach (['CSMS','CNMS','BMS','LUM'] as $b): ?>
                                    <option value="<?= $b ?>"><?= $b ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="Elementary">
                                    <?php foreach (['CHAN','ELB','JHC','LOGE','LYN','NEWB','OAK','SHAR','TEN','TMS','WEC','YANK'] as $b): ?>
                                    <option value="<?= $b ?>"><?= $b ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:14px">
                            <label class="form-label" for="f-room">Room / Location *</label>
                            <input type="text" id="f-room" name="room" placeholder="e.g. Room 214, Main Gym" required>
                        </div>
                        <div class="form-group" id="field-time" style="margin-bottom:14px">
                            <label class="form-label">
                                <i class="ti ti-clock" style="font-size:11px;vertical-align:middle;margin-right:3px" aria-hidden="true"></i>
                                Time Room / Area is Available
                            </label>
                            <div class="time-range-wrap">
                                <select id="f-time-from" name="time_from">
                                    <option value="">From…</option>
                                    <option>7:00 AM</option><option>8:00 AM</option><option>9:00 AM</option>
                                    <option>10:00 AM</option><option>11:00 AM</option><option>12:00 PM</option>
                                    <option>1:00 PM</option><option>2:00 PM</option><option>3:00 PM</option>
                                    <option>4:00 PM</option><option>After 4:00 PM</option>
                                </select>
                                <span class="time-range-sep">to</span>
                                <select id="f-time-to" name="time_to">
                                    <option value="">To…</option>
                                    <option>7:00 AM</option><option>8:00 AM</option><option>9:00 AM</option>
                                    <option>10:00 AM</option><option>11:00 AM</option><option>12:00 PM</option>
                                    <option>1:00 PM</option><option>2:00 PM</option><option>3:00 PM</option>
                                    <option>4:00 PM</option><option>After 4:00 PM</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group maint-only hidden" id="field-purpose" style="margin-bottom:14px">
                            <label class="form-label" for="f-purpose">Purpose *</label>
                            <select id="f-purpose" name="purpose">
                                <option value="">Select purpose…</option>
                                <option>Event Setup</option><option>General Custodial</option>
                                <option>General Grounds</option><option>General Maintenance</option>
                                <option>Preventative Maintenance</option><option>Vandalism</option>
                            </select>
                        </div>
                        <div class="form-group maint-only hidden" id="field-problem">
                            <label class="form-label" for="f-problem-type">Problem Type *</label>
                            <select id="f-problem-type" name="problem_type">
                                <option value="">- Select Problem Type -</option>
                                <optgroup label="Maintenance" class="maint-opts">
                                    <option>Cabling</option><option>Carpentry</option><option>Ceiling</option>
                                    <option>Clocks/Bells</option><option>Custodial</option>
                                    <option>Doors and Hardware</option><option>Electrical</option>
                                    <option>Equipment Maintenance</option><option>Event Setup</option>
                                    <option>Flooring</option><option>General Maintenance</option>
                                    <option>Glass/Window Repairs</option><option>Grounds</option>
                                    <option>Hazmat/Waste</option><option>Heating and Cooling</option>
                                    <option>Installation</option><option>Keys and Locks</option>
                                    <option>Lighting</option><option>Moving</option><option>Mowing</option>
                                    <option>Painting</option><option>Pest Control</option>
                                    <option>Plumbing</option><option>Pool</option>
                                    <option>Supplies/Equipment</option><option value="Other">Other</option>
                                </optgroup>
                                <optgroup label="Technology" class="tech-opts">
                                    <option>Admin Cell Phone</option><option>Audio/Visual</option>
                                    <option>Chromebook</option><option>Desktop</option>
                                    <option>Email</option><option>Event Setup</option>
                                    <option>Filewave</option><option>Interactive White Board</option>
                                    <option>Internet Connection</option><option>Internet Filter</option>
                                    <option>iPad</option><option>Laptop</option>
                                    <option>Miscellaneous/Questions (IT)</option><option>Mouse</option>
                                    <option>Other</option><option>Password/login</option>
                                    <option>Printers</option><option>Projector</option>
                                    <option>Server</option><option>Software Application</option>
                                    <option>Synergy</option><option>Telephone</option>
                                    <option>Virus</option><option>WCSC Website</option>
                                </optgroup>
                            </select>
                            <div class="other-problem-wrap" id="other-problem-wrap">
                                <input type="text" id="f-problem-other" name="problem_other" placeholder="Please describe the problem type…">
                            </div>
                        </div>
                    </div><!-- /modal-col-left -->

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
                            <label class="form-label">Photos (optional)</label>
                            <div class="upload-zone" id="upload-zone">
                                <i class="ti ti-photo-up" id="upload-icon" aria-hidden="true"></i>
                                <span id="upload-label">Click or drag photos to attach</span>
                                <small>JPG, PNG, WEBP or HEIC · Up to 5 photos · 10 MB each</small>
                            </div>
                            <div id="upload-preview"></div>
                            <input type="file" id="f-photo" name="photos[]" accept="image/*" multiple style="display:none">
                        </div>
                    </div><!-- /modal-col-right -->
                </div><!-- /modal-cols -->
            </form>
        </div>
        <div id="upload-progress-wrap" style="display:none;padding:10px 24px 2px">
            <div style="background:#e8ecf0;border-radius:99px;height:6px;overflow:hidden">
                <div id="upload-progress-bar" style="height:100%;width:0%;background:var(--cyan);border-radius:99px;transition:width .25s ease"></div>
            </div>
            <div id="upload-progress-label" style="font-size:11px;color:#6b7a8d;margin-top:5px;text-align:center">Uploading…</div>
        </div>
        <div class="modal-footer" id="modal-footer">
            <button class="btn btn-ghost" id="cancel-modal">Cancel</button>
            <button class="btn btn-primary" id="submit-wo">
                <i class="ti ti-send" aria-hidden="true"></i> Submit Work Order
            </button>
        </div>
    </div>
</div>

<!-- ============================================================
     SUCCESS MODAL
============================================================ -->
<div class="modal-overlay" id="success-overlay" role="dialog" aria-modal="true">
    <div class="modal">
        <div class="modal-body">
            <div class="success-state">
                <div class="success-icon"><i class="ti ti-check" aria-hidden="true"></i></div>
                <h2 style="font-family:'Barlow Condensed',sans-serif;font-size:22px;font-weight:700;color:#1a1a2e">
                    Work Order Submitted!
                </h2>
                <div class="success-wo-num" id="success-wo-num"></div>
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
const USER_ROLE  = '<?= htmlspecialchars($user_role, ENT_QUOTES) ?>';
const ROLE_LABEL = '<?= htmlspecialchars($role_label, ENT_QUOTES) ?>';

// Profile dropdown handled by nav.php

// ── Notification item clicks → wo_detail.php ─────────────────
document.querySelectorAll('.notif-item').forEach(function(item) {
    item.addEventListener('click', function() {
        document.getElementById('notif-dd').classList.remove('open');
        window.location.href = 'wo_detail.php?wo=' + encodeURIComponent(this.dataset.wo);
    });
});

// ── Open WO form modal ────────────────────────────────────────
const cardMaint = document.getElementById('card-maint');
const cardTech  = document.getElementById('card-tech');
if (cardMaint) cardMaint.addEventListener('click', function() { openModal('Maintenance'); });
if (cardTech)  cardTech.addEventListener('click',  function() { openModal('Technology');  });

function openModal(type) {
    document.getElementById('f-type').value = type;
    const isMaint = type === 'Maintenance';
    document.getElementById('modal-title').textContent    = type + ' Request';
    document.getElementById('modal-icon').className       = 'modal-type-icon ' + (isMaint ? 'maint' : 'tech');
    document.getElementById('modal-icon-i').className     = 'ti ' + (isMaint ? 'ti-tool' : 'ti-device-laptop');
    document.getElementById('wo-form').reset();
    document.getElementById('f-time-to').style.display = '';
    document.querySelector('.time-range-sep').style.display = '';
    document.getElementById('f-priority').value = '';
    document.querySelectorAll('.pri-pill').forEach(p => p.classList.remove('sel'));
    selectedFiles = [];
    renderPreviews();
    updateZoneState();
    document.getElementById('upload-progress-wrap').style.display = 'none';
    document.getElementById('upload-progress-bar').style.width = '0%';
    document.getElementById('upload-progress-bar').classList.remove('processing');
    document.querySelectorAll('.maint-only').forEach(function(el) { el.classList.remove('hidden'); });
    document.getElementById('field-time').classList.remove('hidden');
    document.getElementById('field-purpose').classList.toggle('hidden', !isMaint);
    document.getElementById('field-problem').classList.remove('hidden');
    document.getElementById('f-purpose').value = '';
    document.getElementById('f-problem-type').value = '';
    document.querySelector('.maint-opts').style.display = isMaint ? '' : 'none';
    document.querySelector('.tech-opts').style.display  = isMaint ? 'none' : '';
    document.getElementById('other-problem-wrap').classList.remove('visible');
    document.getElementById('f-problem-other').value = '';
    document.getElementById('modal-overlay').classList.add('open');
}

function closeModal() { document.getElementById('modal-overlay').classList.remove('open'); }
const closeModalBtn = document.getElementById('close-modal');
const cancelModalBtn = document.getElementById('cancel-modal');
if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
if (cancelModalBtn) cancelModalBtn.addEventListener('click', closeModal);
const modalOverlay = document.getElementById('modal-overlay');
if (modalOverlay) modalOverlay.addEventListener('click', function(e) { if (e.target === this) closeModal(); });

// ── Priority pills ────────────────────────────────────────────
document.querySelectorAll('.pri-pill').forEach(function(pill) {
    pill.addEventListener('click', function() {
        document.querySelectorAll('.pri-pill').forEach(p => p.classList.remove('sel'));
        this.classList.add('sel');
        document.getElementById('f-priority').value = this.dataset.p;
    });
});

// ── Time range — filter To options based on From selection ────
(function() {
    const times = ['7:00 AM','8:00 AM','9:00 AM','10:00 AM','11:00 AM','12:00 PM','1:00 PM','2:00 PM','3:00 PM','4:00 PM','After 4:00 PM'];
    const fromSel = document.getElementById('f-time-from');
    const toSel   = document.getElementById('f-time-to');
    if (!fromSel || !toSel) return;
    fromSel.addEventListener('change', function() {
        const fromVal = this.value;
        const fromIdx = times.indexOf(fromVal);
        const sep     = fromSel.closest('.time-range-wrap').querySelector('.time-range-sep');
        if (fromVal === 'After 4:00 PM') {
            toSel.value = '';
            toSel.style.display  = 'none';
            sep.style.display    = 'none';
            return;
        }
        toSel.style.display = '';
        sep.style.display   = '';
        const prevTo = toSel.value;
        toSel.innerHTML = '<option value="">To…</option>';
        times.forEach(function(t, i) {
            if (fromIdx === -1 || i > fromIdx) {
                const opt = document.createElement('option');
                opt.value = t;
                opt.textContent = t;
                toSel.appendChild(opt);
            }
        });
        if (prevTo && times.indexOf(prevTo) > fromIdx) toSel.value = prevTo;
    });
})();

// ── Photo upload zone ─────────────────────────────────────────
const uploadZone    = document.getElementById('upload-zone');
const fileInput     = document.getElementById('f-photo');
const uploadLabel   = document.getElementById('upload-label');
const uploadIcon    = document.getElementById('upload-icon');
const uploadPreview = document.getElementById('upload-preview');
let selectedFiles   = [];
const MAX_PHOTOS    = 5;
const MAX_BYTES     = 10 * 1024 * 1024;

function renderPreviews() {
    if (!uploadPreview) return;
    uploadPreview.innerHTML = '';
    selectedFiles.forEach(function(file, idx) {
        const wrap = document.createElement('div');
        wrap.className = 'img-thumb-preview';
        const img = document.createElement('img');
        img.src = URL.createObjectURL(file);
        img.alt = file.name;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'thumb-remove';
        btn.textContent = '×';
        btn.setAttribute('aria-label', 'Remove ' + file.name);
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            selectedFiles.splice(idx, 1);
            renderPreviews();
            updateZoneState();
        });
        wrap.appendChild(img);
        wrap.appendChild(btn);
        uploadPreview.appendChild(wrap);
    });
    uploadPreview.style.display = selectedFiles.length ? 'flex' : 'none';
}

function updateZoneState() {
    const n = selectedFiles.length;
    if (n === 0) {
        uploadLabel.textContent = 'Click or drag photos to attach';
        uploadZone.classList.remove('has-file');
        uploadIcon.className = 'ti ti-photo-up';
    } else {
        uploadLabel.textContent = n === 1 ? '1 photo selected' : n + ' photos selected';
        uploadZone.classList.add('has-file');
        uploadIcon.className = 'ti ti-circle-check';
    }
}

function addFiles(newFiles) {
    let skipped = 0;
    Array.from(newFiles).forEach(function(f) {
        if (selectedFiles.length >= MAX_PHOTOS) return;
        const ext = f.name.split('.').pop().toLowerCase();
        if (!['jpg','jpeg','png','webp','heic'].includes(ext)) return;
        if (f.size > MAX_BYTES) { skipped++; return; }
        selectedFiles.push(f);
    });
    if (skipped) alert(skipped + ' photo(s) exceeded 10 MB and were skipped.');
    renderPreviews();
    updateZoneState();
}

if (uploadZone) {
    uploadZone.addEventListener('click', function() { fileInput.click(); });
    fileInput.addEventListener('change', function() {
        if (this.files.length) addFiles(this.files);
        this.value = '';
    });
    uploadZone.addEventListener('dragover', function(e) { e.preventDefault(); e.stopPropagation(); this.classList.add('drag-over'); });
    uploadZone.addEventListener('dragleave', function(e) { e.preventDefault(); e.stopPropagation(); this.classList.remove('drag-over'); });
    uploadZone.addEventListener('drop', function(e) {
        e.preventDefault(); e.stopPropagation(); this.classList.remove('drag-over');
        if (e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
    });
}

// ── Problem Type → "Other" field ──────────────────────────────
const problemTypeEl = document.getElementById('f-problem-type');
if (problemTypeEl) {
    problemTypeEl.addEventListener('change', function() {
        const wrap = document.getElementById('other-problem-wrap');
        wrap.classList.toggle('visible', this.value === 'Other');
        if (this.value !== 'Other') document.getElementById('f-problem-other').value = '';
    });
}

// ── Submit work order ─────────────────────────────────────────
const submitWoBtn = document.getElementById('submit-wo');
if (submitWoBtn) {
    submitWoBtn.addEventListener('click', function() {
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

        const submitBtn    = document.getElementById('submit-wo');
        const progressWrap = document.getElementById('upload-progress-wrap');
        const progressBar  = document.getElementById('upload-progress-bar');
        const progressLbl  = document.getElementById('upload-progress-label');

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="ti ti-loader" aria-hidden="true"></i> Submitting…';
        progressBar.style.width = '0%';
        progressBar.classList.remove('processing');
        progressLbl.textContent = 'Submitting…';
        progressWrap.style.display = '';

        const formData = new FormData(document.getElementById('wo-form'));
        formData.append('action', 'submit_wo');
        formData.delete('photos[]');
        selectedFiles.forEach(function(f) { formData.append('photos[]', f); });

        const newType     = document.getElementById('f-type').value;
        const newBuilding = document.getElementById('f-building').value;
        const newRoom     = document.getElementById('f-room').value.trim();
        const newDesc     = document.getElementById('f-desc').value.trim();
        const newTimeFrom = document.getElementById('f-time-from').value;
        const newTimeTo   = document.getElementById('f-time-to').value;
        const newPurpose  = isMaint ? document.getElementById('f-purpose').value : 'Technology';
        const newProblem  = document.getElementById('f-problem-type').value;
        const newPriority = document.getElementById('f-priority').value;

        let animTimer  = null;
        let animPct    = 0;
        const numFiles = selectedFiles.length;
        if (numFiles > 0) {
            const stepPct = Math.round(100 / numFiles);
            const label   = numFiles === 1 ? 'Processing 1 image…' : 'Processing ' + numFiles + ' images…';
            progressLbl.textContent = label;
            animTimer = setInterval(function() {
                animPct = Math.min(animPct + stepPct, 94);
                progressBar.style.width = animPct + '%';
                if (animPct >= 94) { clearInterval(animTimer); animTimer = null; }
            }, 1000);
        }

        function resetBtn() {
            if (animTimer) { clearInterval(animTimer); animTimer = null; }
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="ti ti-send" aria-hidden="true"></i> Submit Work Order';
            progressWrap.style.display = 'none';
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '', true);

        xhr.onload = function() {
            if (animTimer) { clearInterval(animTimer); animTimer = null; }
            progressBar.style.width = '100%';
            progressLbl.textContent = 'Done!';
            setTimeout(function() {
                resetBtn();
                try {
                    const data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        document.getElementById('success-wo-num').textContent = data.wo_num;
                        injectNewRow({ wo: data.wo_num, type: newType, building: newBuilding, room: newRoom,
                            desc: newDesc, timeFrom: newTimeFrom, timeTo: newTimeTo, purpose: newPurpose,
                            problem: newProblem, priority: newPriority, attachment: data.photo_path || '' });
                        closeModal();
                        document.getElementById('success-overlay').classList.add('open');
                    } else {
                        alert(data.message || 'Submission failed. Please try again.');
                    }
                } catch(e) {
                    alert('Submission failed. Please try again.');
                }
            }, 400);
        };

        xhr.onerror = function() {
            resetBtn();
            alert('Network error. Please check your connection and try again.');
        };

        xhr.send(formData);
    });
}

const successBackBtn = document.getElementById('success-back');
const successNewBtn  = document.getElementById('success-new');
if (successBackBtn) successBackBtn.addEventListener('click', function() { document.getElementById('success-overlay').classList.remove('open'); });
if (successNewBtn)  successNewBtn.addEventListener('click', function() {
    document.getElementById('success-overlay').classList.remove('open');
    openModal(document.getElementById('f-type').value || 'Maintenance');
});

// ── Sort + Filter System ──────────────────────────────────────
const priOrder    = { 'Urgent': 0, 'High': 1, 'Mid': 2, 'Low': 3, '': 4 };
const statusOrder = { 'Pending Approval': 0, 'Approved': 1, 'In Progress': 2, 'Completed': 3, 'Rejected': 4, '': 5 };
let sortCol     = null;
let sortDir     = 1;
let activeFilter = <?= json_encode($_default_filter) ?>;

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

const _filterTitles = {
    'all':              'All Work Orders',
    'Pending Approval': 'Pending Work Orders',
    'Approved':         'Approved Work Orders',
    'In Progress':      'Work Orders in Progress',
    'Completed':        'Completed Work Orders',
    'Rejected':         'Rejected Work Orders',
};

function applyTable() {
    const _titleEl = document.getElementById('wo-section-title');
    if (_titleEl) _titleEl.textContent = _filterTitles[activeFilter] || 'Work Orders';
    const tbody = document.getElementById('wo-tbody');
    let rows = Array.from(tbody.querySelectorAll('.wo-row'));
    rows.forEach(function(row) {
        const rf = row.dataset.filter;
        const show = activeFilter === 'all' || rf === activeFilter;
        row.style.display = show ? '' : 'none';
    });
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

document.querySelectorAll('.filter-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        activeFilter = this.dataset.filter;
        syncChips(activeFilter);
        applyTable();
    });
});

// Mobile swipeable chips
function syncChips(filter) {
    document.querySelectorAll('.chip').forEach(function(c) {
        c.classList.toggle('active', c.dataset.filter === filter);
    });
}
document.querySelectorAll('.chip').forEach(function(chip) {
    chip.addEventListener('click', function() {
        activeFilter = this.dataset.filter;
        syncChips(activeFilter);
        // keep desktop tabs in sync too
        document.querySelectorAll('.filter-tab').forEach(function(t) {
            t.classList.toggle('active', t.dataset.filter === activeFilter);
        });
        applyTable();
    });
});

// Apply default filter on page load
(function() {
    document.querySelectorAll('.filter-tab').forEach(function(t) {
        t.classList.toggle('active', t.dataset.filter === activeFilter);
    });
    syncChips(activeFilter);
    applyTable();
}());

document.querySelectorAll('.wo-table th.sortable').forEach(function(th) {
    th.addEventListener('click', function() {
        const col = this.dataset.sort;
        if (sortCol === col) { sortDir *= -1; } else { sortCol = col; sortDir = 1; }
        document.querySelectorAll('.wo-table th.sortable').forEach(function(h) {
            h.classList.remove('sort-asc', 'sort-desc');
            h.querySelector('.sort-icon').textContent = '↕';
        });
        this.classList.add(sortDir === 1 ? 'sort-asc' : 'sort-desc');
        this.querySelector('.sort-icon').textContent = '';
        applyTable();
    });
});

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
    tr.dataset.id         = '0';
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
    tr.dataset.attachment = d.attachment || '';
    tr.dataset.notes      = '';
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
    tr.addEventListener('click', function() { window.location.href = 'wo_detail.php?wo=' + encodeURIComponent(this.dataset.wo); });
    const tbody = document.getElementById('wo-tbody');
    const emptyRow = tbody.querySelector('.empty-state');
    if (emptyRow) emptyRow.closest('tr').remove();
    tbody.insertBefore(tr, tbody.firstChild);
}

document.querySelectorAll('.wo-row').forEach(function(row) {
    row.addEventListener('click', function() {
        window.location.href = 'wo_detail.php?wo=' + encodeURIComponent(this.dataset.wo);
    });
});

// ── Redirect ?wo= param to wo_detail.php (email link fallback) ───────────────
(function() {
    const params  = new URLSearchParams(window.location.search);
    const woParam = params.get('wo');
    if (woParam) window.location.replace('wo_detail.php?wo=' + encodeURIComponent(woParam));
})();

</script>
</body>
</html>