<?php

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

    if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'heic'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed_exts)) {
            $upload_dir = __DIR__ . '/wo_imgs/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $temp_uid  = substr(uniqid(), -6);
            $datestamp = date('Ymd');
            $filename  = 'WO-PENDING_' . $datestamp . '_' . $temp_uid . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $filename)) {
                $photo_path = 'wo_imgs/' . $filename;
            }
        }
    }

    $stmt = $conn->prepare("INSERT INTO orders (type, submitted_by, submitted_name, building, room, time_from, time_to, purpose, problem_type, description, priority, photo_path) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('ssssssssssss', $type, $user_email, $submitted_name, $building, $room, $time_from, $time_to, $purpose, $problem_type, $description, $priority, $photo_path);
    $stmt->execute();

    if ($stmt->affected_rows) {
        $insert_id = $conn->insert_id;
        $wo_num    = 'WO-' . str_pad($insert_id, 6, '0', STR_PAD_LEFT);

        if ($photo_path !== null) {
            $ext_final    = pathinfo($photo_path, PATHINFO_EXTENSION);
            $new_filename = $wo_num . '_' . date('Ymd') . '_' . substr(uniqid(), -6) . '.' . $ext_final;
            $old_full     = __DIR__ . '/' . $photo_path;
            $new_full     = __DIR__ . '/wo_imgs/' . $new_filename;
            if (rename($old_full, $new_full)) {
                $photo_path = 'wo_imgs/' . $new_filename;
                $upd = $conn->prepare("UPDATE orders SET photo_path = ? WHERE id = ?");
                $upd->bind_param('si', $photo_path, $insert_id);
                $upd->execute();
                $upd->close();
            }
        }

        // Set initial current_handler based on order type
        $initial_handler = ($type === 'Technology') ? 'BT' : 'BP';
        $upd2 = $conn->prepare("UPDATE orders SET current_handler=? WHERE id=?");
        $upd2->bind_param('si', $initial_handler, $insert_id);
        $upd2->execute();
        $upd2->close();

        require_once __DIR__ . '/wo_mailer.php';
        if ($type === 'Technology') {
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
        } else {
            // Maintenance: notify BP for this building directly on submission
            send_bp_notification_email(
                $conn,
                $wo_num,
                $building,
                $room,
                $problem_type,
                $description,
                $priority,
                $submitted_name,
                $user_email,
                $submitted_name,
                'Maintenance'
            );
        }

        echo json_encode(['success' => true, 'wo_num' => $wo_num, 'photo_path' => $photo_path ?? '']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
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

function human_time_diff(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 3600)  return round($diff/60) . 'm ago';
    if ($diff < 86400) return round($diff/3600) . 'h ago';
    return round($diff/86400) . 'd ago';
}

// ── Fetch work orders based on role ──────────────────────────
require_once __DIR__ . '/../../wo_config.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');
$orders = [];
if ($user_role === 'A') {
    $res = $db->query("SELECT * FROM orders ORDER BY created_at DESC");
    if ($res) while ($row = $res->fetch_assoc()) $orders[] = $row;
} elseif ($user_role === 'MT') {
    // Tech orders that have reached MT or beyond
    $res = $db->query("SELECT * FROM orders WHERE type = 'Technology' AND (current_handler IN ('MT','worker') OR status IN ('Completed','Rejected')) ORDER BY created_at DESC");
    if ($res) while ($row = $res->fetch_assoc()) $orders[] = $row;
} elseif ($user_role === 'MM') {
    // Maintenance orders that have reached MM or beyond
    $res = $db->query("SELECT * FROM orders WHERE type = 'Maintenance' AND (current_handler IN ('MM','worker') OR status IN ('Completed','Rejected')) ORDER BY created_at DESC");
    if ($res) while ($row = $res->fetch_assoc()) $orders[] = $row;
} elseif ($user_role === 'BP') {
    $stmt = $db->prepare("SELECT * FROM orders WHERE building = ? ORDER BY created_at DESC");
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
    // Workers only see orders assigned to them via order_assignments
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

// ── Fetch assignable workers for Manager assignment panel ─────
$assignable_workers = [];
if (in_array($user_role, ['MT', 'MM', 'A'])) {
    $db3 = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $db3->set_charset('utf8mb4');
    $res3 = $db3->query(
        "SELECT first_name, last_name, email, role, building
           FROM users
          WHERE role IN ('MW','BC','BM')
            AND active = 1
          ORDER BY
            FIELD(role,'MW','BC','BM'),
            last_name, first_name"
    );
    if ($res3) while ($row = $res3->fetch_assoc()) $assignable_workers[] = $row;
    $db3->close();
}

// ── Notification count for bell badge ────────────────────────
$notif_count = 0;
if (in_array($user_role, ['BT', 'BP', 'MT', 'MM', 'A', 'MW', 'BC', 'BM'])) {
    $db2 = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $db2->set_charset('utf8mb4');
    if ($user_role === 'BT') {
        $bt_buildings = array_filter(array_map('trim', explode(',', $user_building ?? '')));
        if ($bt_buildings) {
            $placeholders = implode(',', array_fill(0, count($bt_buildings), '?'));
            $types = str_repeat('s', count($bt_buildings));
            $stmt2 = $db2->prepare("SELECT COUNT(*) FROM orders WHERE current_handler='BT' AND building IN ($placeholders)");
            $stmt2->bind_param($types, ...$bt_buildings);
            $stmt2->execute();
            $stmt2->bind_result($notif_count);
            $stmt2->fetch();
            $stmt2->close();
        }
    } elseif ($user_role === 'BP') {
        $stmt2 = $db2->prepare("SELECT COUNT(*) FROM orders WHERE current_handler='BP' AND building=?");
        $stmt2->bind_param('s', $user_building);
        $stmt2->execute();
        $stmt2->bind_result($notif_count);
        $stmt2->fetch();
        $stmt2->close();
    } elseif (in_array($user_role, ['MW', 'BC', 'BM'])) {
        $stmt2 = $db2->prepare(
            "SELECT COUNT(*) FROM orders o
             INNER JOIN order_assignments oa ON o.id = oa.order_id
             WHERE oa.user_email = ? AND o.current_handler = 'worker'"
        );
        $stmt2->bind_param('s', $user_email);
        $stmt2->execute();
        $stmt2->bind_result($notif_count);
        $stmt2->fetch();
        $stmt2->close();
    } elseif ($user_role === 'MT') {
        $res2 = $db2->query("SELECT COUNT(*) FROM orders WHERE current_handler='MT'");
        if ($res2) { [$notif_count] = $res2->fetch_row(); }
    } elseif ($user_role === 'MM') {
        $res2 = $db2->query("SELECT COUNT(*) FROM orders WHERE current_handler='MM'");
        if ($res2) { [$notif_count] = $res2->fetch_row(); }
    } elseif ($user_role === 'A') {
        $res2 = $db2->query("SELECT COUNT(*) FROM orders WHERE current_handler IS NOT NULL");
        if ($res2) { [$notif_count] = $res2->fetch_row(); }
    }
    $db2->close();
}

// ── Pre-render notification dropdown for nav include ─────────
ob_start();
$notif_orders = array_filter($orders, function($o) use ($user_role) {
    if (in_array($user_role, ['MW','BC','BM'])) return ($o['current_handler'] ?? '') === 'worker';
    return ($o['current_handler'] ?? '') === $user_role;
});
$has_notifs = !empty($notif_orders);
?>
<div class="notif-dd-header"><?= $has_notifs ? 'Pending action' : 'No pending work orders' ?></div>
<?php if (!$has_notifs): ?>
<div class="notif-empty">You're all caught up.</div>
<?php else: foreach (array_slice(array_values($notif_orders), 0, 8) as $no):
    $no_wo  = 'WO-' . str_pad($no['id'], 6, '0', STR_PAD_LEFT);
    $no_age = human_time_diff($no['created_at']);
?>
<div class="notif-item" data-wo="<?= htmlspecialchars($no_wo) ?>">
    <span class="notif-item-wo"><?= $no_wo ?></span>
    <span class="notif-item-meta"><?= htmlspecialchars($no['building']) ?> · <?= htmlspecialchars($no['problem_type']) ?> · <?= $no_age ?></span>
</div>
<?php endforeach; endif;
$_nav_notif_html = ob_get_clean();

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

/* ── ASSIGNMENT PANEL ── */
.assign-search{width:100%;padding:8px 12px;border:1px solid #d0d5dd;border-radius:9px;font-size:13px;font-family:'Barlow',sans-serif;margin-bottom:10px}
.assign-search:focus{outline:none;border-color:var(--cyan);box-shadow:0 0 0 3px rgba(41,182,213,.12)}
.assign-list{max-height:220px;overflow-y:auto;border:1px solid #e8ecf0;border-radius:9px;background:#fff}
.assign-item{display:flex;align-items:center;gap:10px;padding:9px 14px;border-bottom:1px solid #f0f4f8;cursor:pointer;transition:background .1s}
.assign-item:last-child{border-bottom:none}
.assign-item:hover{background:#f0f8fb}
.assign-item input[type=checkbox]{accent-color:var(--cyan);width:15px;height:15px;flex-shrink:0}
.assign-item-name{font-size:13px;font-weight:600;color:#1a1a2e}
.assign-item-meta{font-size:11px;color:#6b7a8d;margin-left:auto}
.assign-role-group{padding:6px 14px 2px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#aab0bb;background:#f8f9fa;border-bottom:1px solid #f0f4f8}
.assign-selected-count{font-size:12px;color:var(--cyan-dark);font-weight:600;margin-bottom:8px;min-height:18px}

/* ── DETAIL MODAL ── */
.detail-modal{max-width:1000px}
.detail-two-col{display:grid;grid-template-columns:1fr 1fr;gap:0;align-items:start}
.detail-col-left{padding-right:20px;border-right:1px solid #f0f4f8}
.detail-col-right{padding-left:20px;display:flex;flex-direction:column;gap:0}
@media(max-width:700px){.detail-two-col{grid-template-columns:1fr}.detail-col-left{padding-right:0;border-right:none;border-bottom:1px solid #f0f4f8;padding-bottom:16px;margin-bottom:4px}.detail-col-right{padding-left:0}}
.detail-section{margin-bottom:12px}
.detail-section-title{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#aab0bb;margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid #f0f4f8}
.detail-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.detail-field label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#aab0bb;display:block;margin-bottom:3px}
.detail-field p{font-size:13px;color:#1a1a2e;font-weight:500;line-height:1.5}
.detail-field.full{grid-column:1/-1}
.detail-desc{background:#f8f9fa;border-radius:9px;padding:8px 12px;font-size:13px;color:#3d4f5e;line-height:1.6;white-space:pre-wrap}
.attachment-thumb{width:100%;border-radius:9px;border:1px solid #e8ecf0;overflow:hidden;background:#f8f9fa;display:flex;align-items:center;justify-content:center;min-height:72px}
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

<?php require_once __DIR__ . '/nav.php'; ?>

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
        <h2><?= in_array($user_role, ['MW','BC','BM']) ? 'My Assigned Work Orders' : 'My Work Orders' ?></h2>
        <div class="filter-tabs">
            <button class="filter-tab active" data-filter="all">All</button>
            <?php if (!in_array($user_role, ['MW','BC','BM'])): ?>
            <button class="filter-tab" data-filter="Pending Approval">Pending</button>
            <button class="filter-tab" data-filter="Approved">Approved</button>
            <?php endif; ?>
            <button class="filter-tab" data-filter="In Progress">In Progress</button>
            <button class="filter-tab" data-filter="Completed">Completed</button>
            <button class="filter-tab" data-filter="Rejected">Rejected</button>
        </div>
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
                            <label class="form-label">Photo (optional)</label>
                            <div class="upload-zone" id="upload-zone">
                                <i class="ti ti-photo-up" id="upload-icon" aria-hidden="true"></i>
                                <span id="upload-label">Click or drag a photo to attach</span>
                                <small>JPG, PNG, WEBP or HEIC · Max 10 MB</small>
                            </div>
                            <input type="file" id="f-photo" name="photo" accept="image/*" style="display:none">
                        </div>
                    </div><!-- /modal-col-right -->
                </div><!-- /modal-cols -->
            </form>
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
        <div class="modal-body" style="overflow-y:auto">
            <div class="detail-two-col">

                <!-- LEFT COLUMN: order info + action panel -->
                <div class="detail-col-left">

                    <div class="detail-section">
                        <div style="display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px">
                            <p id="d-wo" style="font-family:'Barlow Condensed',sans-serif;font-size:22px;font-weight:700;color:var(--cyan)"></p>
                            <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
                                <span id="d-priority-badge"></span>
                                <span id="d-status-badge"></span>
                            </div>
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

                    <!-- Attachment — only shown if image exists -->
                    <div class="detail-section" id="d-attachment-section" style="display:none">
                        <div class="detail-section-title">Attachment</div>
                        <div class="attachment-thumb" id="d-attachment-wrap"></div>
                    </div>

                    <!-- Priority panel — shown for BP, MT, MM, A -->
                    <div id="action-priority-panel" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid #f0f4f8">
                        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#aab0bb;margin-bottom:8px">Change Priority</div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            <button type="button" class="action-pri-pill" data-p="Low"    style="padding:7px 16px;border-radius:9px;border:1.5px solid #e8ecf0;font-size:13px;font-weight:700;cursor:pointer;background:transparent;font-family:'Barlow',sans-serif;color:#6b7a8d">Low</button>
                            <button type="button" class="action-pri-pill" data-p="Mid"    style="padding:7px 16px;border-radius:9px;border:1.5px solid #e8ecf0;font-size:13px;font-weight:700;cursor:pointer;background:transparent;font-family:'Barlow',sans-serif;color:#6b7a8d">Mid</button>
                            <button type="button" class="action-pri-pill" data-p="High"   style="padding:7px 16px;border-radius:9px;border:1.5px solid #e8ecf0;font-size:13px;font-weight:700;cursor:pointer;background:transparent;font-family:'Barlow',sans-serif;color:#6b7a8d">High</button>
                            <button type="button" class="action-pri-pill" data-p="Urgent" style="padding:7px 16px;border-radius:9px;border:1.5px solid #e8ecf0;font-size:13px;font-weight:700;cursor:pointer;background:transparent;font-family:'Barlow',sans-serif;color:#6b7a8d">Urgent</button>
                        </div>
                    </div>

                    <!-- Note panel — always visible for non-U -->
                    <div id="action-panel" style="margin-top:12px;padding-top:12px;border-top:1px solid #f0f4f8">
                        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#aab0bb;margin-bottom:10px">Add Note (optional)</div>
                        <textarea id="action-note" rows="3" placeholder="Add a note to be included with this action…" style="width:100%;border:1px solid #d0d5dd;border-radius:9px;padding:10px 13px;font-size:13px;font-family:'Barlow',sans-serif;resize:vertical;margin-bottom:8px"></textarea>
                        <button type="button" id="save-note-btn" style="display:none;padding:8px 16px;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;font-family:'Barlow',sans-serif;border:1.5px solid #d0d5dd;background:transparent;color:#6b7a8d">💬 Add note and autosave to log</button>
                        <div id="action-msg" style="font-size:12px;margin-top:10px;display:none"></div>
                    </div>

                </div><!-- /detail-col-left -->

                <!-- RIGHT COLUMN: activity log + assign panel + action buttons -->
                <div class="detail-col-right" style="display:flex;flex-direction:column">

                    <!-- Activity Log — always shown -->
                    <div class="detail-section">
                        <div class="detail-section-title">Activity Log</div>
                        <div class="detail-desc" id="d-notes" style="white-space:pre-wrap;font-size:12px;color:#6b7a8d;line-height:1.7;min-height:60px"></div>
                    </div>

                    <!-- Assignment panel — shown to MT/MM/A when status is Approved/In Progress -->
                    <div id="assign-panel" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid #f0f4f8">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#aab0bb">Assign To</div>
                            <div class="assign-selected-count" id="assign-count" style="font-size:12px;color:var(--cyan-dark);font-weight:600"></div>
                        </div>
                        <input type="text" class="assign-search" id="assign-search" placeholder="Search workers…">
                        <div class="assign-list" id="assign-list"></div>
                    </div>

                    <!-- Action buttons — bottom of right column -->
                    <div id="action-buttons-panel" style="display:none;margin-top:auto;padding-top:16px;border-top:1px solid #f0f4f8;margin-top:16px">
                        <div id="action-buttons" style="display:flex;gap:8px;flex-wrap:wrap"></div>
                    </div>

                </div><!-- /detail-col-right -->

            </div><!-- /detail-two-col -->
        </div>

        <div class="modal-footer">
            <button class="btn btn-ghost" id="close-detail-footer">Close</button>
        </div>
    </div>
</div>

<!-- ============================================================
     CONFIRMATION MODAL
============================================================ -->
<div class="modal-overlay" id="confirm-overlay" role="dialog" aria-modal="true">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-type-icon" id="confirm-icon" style="background:#fef3c7">
                    <i class="ti ti-alert-triangle" style="color:#b45309" aria-hidden="true"></i>
                </div>
                <div>
                    <div class="modal-title" id="confirm-title">Confirm Action</div>
                    <div class="modal-subtitle" id="confirm-subtitle"></div>
                </div>
            </div>
        </div>
        <div class="modal-body" style="padding:20px 26px">
            <p id="confirm-body" style="font-size:14px;color:#3d4f5e;line-height:1.6"></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" id="confirm-cancel">Cancel</button>
            <button class="btn" id="confirm-ok" style="background:#dc2626;color:#fff">Confirm</button>
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
const ASSIGNABLE_WORKERS = <?= json_encode($assignable_workers) ?>;

// Profile dropdown handled by nav.php

// ── Notification dropdown ─────────────────────────────────────
const notifBtn = document.getElementById('notif-btn');
const notifDd  = document.getElementById('notif-dd');
if (notifBtn) {
    notifBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        notifDd.classList.toggle('open');
        const _profileDd = document.getElementById('profile-dd');
        if (_profileDd) _profileDd.classList.remove('open');
    });
    document.addEventListener('click', function(e) {
        if (!notifDd.contains(e.target) && e.target !== notifBtn) notifDd.classList.remove('open');
    });
    document.querySelectorAll('.notif-item').forEach(function(item) {
        item.addEventListener('click', function() {
            notifDd.classList.remove('open');
            const wo = this.dataset.wo;
            const rows = document.querySelectorAll('.wo-row');
            for (let i = 0; i < rows.length; i++) {
                if (rows[i].dataset.wo === wo) {
                    openDetailModal(rows[i].dataset);
                    return;
                }
            }
        });
    });
}

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
    document.getElementById('upload-label').textContent = 'Click or drag a photo here';
    document.getElementById('upload-zone').classList.remove('has-file');
    document.getElementById('upload-icon').className = 'ti ti-photo-up';
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
        // "After 4:00 PM" has no valid end time — hide To
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
const uploadZone  = document.getElementById('upload-zone');
const fileInput   = document.getElementById('f-photo');
const uploadLabel = document.getElementById('upload-label');
const uploadIcon  = document.getElementById('upload-icon');
if (uploadZone) {
    uploadZone.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () { if (this.files[0]) setUploadedFile(this.files[0]); });
    uploadZone.addEventListener('dragover', function (e) { e.preventDefault(); e.stopPropagation(); this.classList.add('drag-over'); });
    uploadZone.addEventListener('dragleave', function (e) { e.preventDefault(); e.stopPropagation(); this.classList.remove('drag-over'); });
    uploadZone.addEventListener('drop', function (e) {
        e.preventDefault(); e.stopPropagation(); this.classList.remove('drag-over');
        const file = e.dataTransfer.files[0];
        if (file) { const dt = new DataTransfer(); dt.items.add(file); fileInput.files = dt.files; setUploadedFile(file); }
    });
}
function setUploadedFile(file) {
    uploadLabel.textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
    uploadZone.classList.add('has-file');
    uploadIcon.className = 'ti ti-circle-check';
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
                    injectNewRow({ wo: data.wo_num, type: newType, building: newBuilding, room: newRoom,
                        desc: newDesc, timeFrom: newTimeFrom, timeTo: newTimeTo, purpose: newPurpose,
                        problem: newProblem, priority: newPriority, attachment: data.photo_path || '' });
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
        applyTable();
    });
});

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

// ── Work Order Detail Modal ───────────────────────────────────
const priClassMap = {'Low':'pri-low','Mid':'pri-mid','High':'pri-high','Urgent':'pri-urgent'};
const statusClassMap = {
    'Pending Approval':'badge-pending',
    'Approved':'badge-approved',
    'In Progress':'badge-inprogress',
    'Completed':'badge-completed',
    'Rejected':'badge-rejected',
};

function inArrJS(arr, val) { return arr.indexOf(val) !== -1; }

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
    } else { timeWrap.style.display = 'none'; }
    const purposeWrap = document.getElementById('d-purpose-wrap');
    const problemWrap = document.getElementById('d-problem-wrap');
    if (isMaint) {
        document.getElementById('d-purpose').textContent = d.purpose || '—';
        document.getElementById('d-problem').textContent = d.problem || '—';
        purposeWrap.style.display = ''; problemWrap.style.display = '';
    } else { purposeWrap.style.display = 'none'; problemWrap.style.display = 'none'; }
    const priEl = document.getElementById('d-priority-badge');
    priEl.className   = 'pri ' + (priClassMap[d.priority] || 'pri-low');
    priEl.textContent = d.priority || '—';
    const statusEl = document.getElementById('d-status-badge');
    statusEl.className   = 'badge ' + (statusClassMap[d.status] || 'badge-pending');
    statusEl.textContent = d.status || '—';
    const attachSection = document.getElementById('d-attachment-section');
    const attachWrap    = document.getElementById('d-attachment-wrap');
    if (d.attachment && d.attachment.trim()) {
        attachWrap.innerHTML = '<img src="' + d.attachment + '" alt="Work order attachment">';
        attachSection.style.display = '';
    } else {
        attachWrap.innerHTML = '';
        attachSection.style.display = 'none';
    }

// Notes / activity log — always visible, empty state if no entries yet
    const notesEl = document.getElementById('d-notes');
    notesEl.textContent = (d.notes && d.notes.trim()) ? d.notes.trim() : 'No activity yet.';
    notesEl.style.color = (d.notes && d.notes.trim()) ? '#6b7a8d' : '#d0d5dd';

    // Action panel — build role+status-aware buttons
    const panel     = document.getElementById('action-panel');
    const btnWrap   = document.getElementById('action-buttons');
    const noteTA    = document.getElementById('action-note');
    const actionMsg = document.getElementById('action-msg');
    noteTA.value  = '';
    actionMsg.style.display = 'none';
    btnWrap.innerHTML = '';

    const btActions = [
        { label: '✓ Mark Completed', action: 'bt_complete', cls: 'btn-success' },
        { label: '↑ Approve → Principal', action: 'bt_approve', cls: 'btn-primary' },
        { label: '✕ Reject',          action: 'bt_reject',  cls: 'btn-danger'  },
    ];
    const bpActions = [
        { label: '↑ Approve → Manager', action: 'bp_approve', cls: 'btn-primary' },
        { label: '✓ Mark Completed',     action: 'bp_complete', cls: 'btn-success' },
        { label: '✕ Reject',            action: 'bp_reject',  cls: 'btn-danger'  },
    ];
    const mtActions = [
        { label: '✓ Mark Completed', action: 'mt_complete', cls: 'btn-success' },
        { label: '✕ Reject',         action: 'mt_reject',   cls: 'btn-danger'  },
    ];
    const mmActions = [
        { label: '✓ Mark Completed', action: 'mm_complete', cls: 'btn-success' },
        { label: '✕ Reject',         action: 'mm_reject',   cls: 'btn-danger'  },
    ];
    const workerActions = [
        { label: '✓ Mark Completed', action: 'worker_complete', cls: 'btn-success' },
    ];

    // Priority pills — shown for BP, MT, MM, A
    const priorityPanel = document.getElementById('action-priority-panel');
    if (inArrJS(['BP','MT','MM','A'], USER_ROLE)) {
        priorityPanel.style.display = '';
        const priPillColors = { Low:['#d1fae5','#10b981','#065f46'], Mid:['#dbeafe','#3b82f6','#1e40af'], High:['#fef3c7','#f59e0b','#92400e'], Urgent:['#fee2e2','#ef4444','#991b1b'] };
        priorityPanel.querySelectorAll('.action-pri-pill').forEach(function(p) {
            p.classList.remove('sel');
            p.style.background = 'transparent'; p.style.borderColor = '#e8ecf0'; p.style.color = '#6b7a8d';
            if (p.dataset.p === d.priority) {
                p.classList.add('sel');
                const c = priPillColors[p.dataset.p];
                if (c) { p.style.background = c[0]; p.style.borderColor = c[1]; p.style.color = c[2]; }
            }
        });
        priorityPanel.querySelectorAll('.action-pri-pill').forEach(function(p) {
            p.onclick = function() {
                priorityPanel.querySelectorAll('.action-pri-pill').forEach(function(x) {
                    x.classList.remove('sel');
                    x.style.background = 'transparent'; x.style.borderColor = '#e8ecf0'; x.style.color = '#6b7a8d';
                });
                p.classList.add('sel');
                const c = priPillColors[p.dataset.p];
                if (c) { p.style.background = c[0]; p.style.borderColor = c[1]; p.style.color = c[2]; }
            };
        });
    } else {
        priorityPanel.style.display = 'none';
    }

    let actions    = [];
    let showAssign = false;
    // BT: tech orders at Pending Approval
    if (USER_ROLE === 'BT' && d.status === 'Pending Approval' && d.type === 'Technology') actions = btActions;
    // BP: maintenance at Pending Approval (no BT step); tech at Approved (after BT)
    if (USER_ROLE === 'BP' && d.status === 'Pending Approval' && d.type === 'Maintenance') actions = bpActions;
    if (USER_ROLE === 'BP' && d.status === 'Approved'         && d.type === 'Technology')  actions = bpActions;
    // MT: approved tech orders (assign) or in-progress tech orders
    if (USER_ROLE === 'MT' && d.status === 'Approved'    && d.type === 'Technology') { actions = mtActions; showAssign = true; }
    if (USER_ROLE === 'MT' && d.status === 'In Progress' && d.type === 'Technology') { actions = mtActions; showAssign = true; }
    // MM: approved maintenance orders (assign) or in-progress maintenance orders
    if (USER_ROLE === 'MM' && d.status === 'Approved'    && d.type === 'Maintenance') { actions = mmActions; showAssign = true; }
    if (USER_ROLE === 'MM' && d.status === 'In Progress' && d.type === 'Maintenance') { actions = mmActions; showAssign = true; }
    // Admin: full override
    if (USER_ROLE === 'A'  && d.status === 'Pending Approval' && d.type === 'Maintenance') actions = bpActions;
    if (USER_ROLE === 'A'  && d.status === 'Pending Approval' && d.type === 'Technology')  actions = btActions;
    if (USER_ROLE === 'A'  && d.status === 'Approved' && d.type === 'Technology')   { actions = mtActions; showAssign = true; }
    if (USER_ROLE === 'A'  && d.status === 'Approved' && d.type === 'Maintenance')  { actions = mmActions; showAssign = true; }
    if (USER_ROLE === 'A'  && d.status === 'In Progress' && d.type === 'Technology')   { actions = mtActions; showAssign = true; }
    if (USER_ROLE === 'A'  && d.status === 'In Progress' && d.type === 'Maintenance')  { actions = mmActions; showAssign = true; }
    // Workers
    if (inArrJS(['MW','BC','BM'], USER_ROLE) && d.status === 'In Progress') actions = workerActions;

    // Assignment panel
    const assignPanel = document.getElementById('assign-panel');
    if (showAssign && ASSIGNABLE_WORKERS.length > 0) {
        assignPanel.style.display = '';
        buildAssignPanel();
    } else {
        assignPanel.style.display = 'none';
    }

    // Save Note button — visible for all roles except U, always
    const saveNoteBtn = document.getElementById('save-note-btn');
    if (USER_ROLE !== 'U') {
        saveNoteBtn.style.display = '';
        saveNoteBtn.dataset.orderId = d.id;
    } else {
        saveNoteBtn.style.display = 'none';
    }

    const confirmLabels = {
        'bt_approve':      { title:'Approve & Escalate',  color:'var(--cyan)',  body:'This will approve the work order and send it to the Building Principal for review.' },
        'bt_reject':       { title:'Reject Work Order',   color:'#dc2626',      body:'This will reject the work order. The submitter will be notified.' },
        'bt_complete':     { title:'Mark as Completed',   color:'#059669',      body:'This will mark the work order as completed. The submitter will be notified.' },
        'bp_approve':      { title:'Approve & Escalate',  color:'var(--cyan)',  body:'This will approve the work order and escalate it to the Manager.' },
        'bp_reject':       { title:'Reject Work Order',   color:'#dc2626',      body:'This will reject the work order. The submitter will be notified.' },
        'bp_complete':     { title:'Mark as Completed',   color:'#059669',      body:'This will mark the work order as completed. The submitter will be notified.' },
        'mt_complete':     { title:'Mark as Completed',   color:'#059669',      body:'This will mark the work order as completed. The submitter will be notified.' },
        'mt_reject':       { title:'Reject Work Order',   color:'#dc2626',      body:'This will reject the work order. The submitter will be notified.' },
        'mm_complete':     { title:'Mark as Completed',   color:'#059669',      body:'This will mark the work order as completed. The submitter will be notified.' },
        'mm_reject':       { title:'Reject Work Order',   color:'#dc2626',      body:'This will reject the work order. The submitter will be notified.' },
        'worker_complete': { title:'Mark as Completed',   color:'#059669',      body:'This will mark the work order as completed. The submitter will be notified.' },
    };

    const actionBtnsPanel = document.getElementById('action-buttons-panel');
    actionBtnsPanel.style.display = actions.length > 0 ? '' : 'none';
    const styles = {
        'btn-primary': 'background:var(--cyan);color:#fff',
        'btn-success': 'background:#059669;color:#fff',
        'btn-danger':  'background:#dc2626;color:#fff',
    };
    actions.forEach(function(a) {
        const btn = document.createElement('button');
        btn.setAttribute('style', 'padding:9px 20px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:\'Barlow\',sans-serif;border:none;' + (styles[a.cls] || ''));
        btn.textContent = a.label;
        btn.addEventListener('click', function() {
            const cfg = confirmLabels[a.action];
            if (cfg) {
                showConfirm(cfg.title, d.wo, cfg.body, a.label, cfg.color, function() {
                    submitAction(d.id, a.action, noteTA.value.trim(), btn, actionMsg, d, null);
                });
            } else {
                submitAction(d.id, a.action, noteTA.value.trim(), btn, actionMsg, d, null);
            }
        });
        btnWrap.appendChild(btn);
    });
    // Assign & Start button (only when showAssign)
    if (showAssign) {
        const assignBtn = document.createElement('button');
        assignBtn.setAttribute('style', 'padding:9px 20px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:\'Barlow\',sans-serif;border:none;background:#7c3aed;color:#fff');
        assignBtn.textContent = '→ Assign & Start';
        assignBtn.addEventListener('click', function() {
            const checked = Array.from(document.querySelectorAll('.assign-checkbox:checked'));
            if (checked.length === 0) {
                actionMsg.style.display = '';
                actionMsg.style.color = '#dc2626';
                actionMsg.textContent = 'Please select at least one worker to assign.';
                return;
            }
            const assignees = checked.map(cb => ({ email: cb.dataset.email, name: cb.dataset.name }));
            const names = assignees.map(a => a.name).join(', ');
            let assignAction = 'mt_assign';
            if (USER_ROLE === 'MM' || (USER_ROLE === 'A' && d.type === 'Maintenance')) assignAction = 'mm_assign';
            showConfirm(
                'Assign & Start Work',
                d.wo,
                'Assign to: ' + names + '. This will set the order to In Progress and notify each worker.',
                '→ Assign & Start',
                '#7c3aed',
                function() {
                    submitAction(d.id, assignAction, noteTA.value.trim(), assignBtn, actionMsg, d, assignees);
                }
            );
        });
        btnWrap.appendChild(assignBtn);
    }

    document.getElementById('detail-overlay').classList.add('open');
}

// ── Confirmation modal ────────────────────────────────────────
let _confirmCallback = null;
const confirmOverlay = document.getElementById('confirm-overlay');
document.getElementById('confirm-cancel').addEventListener('click', function() {
    confirmOverlay.classList.remove('open');
    _confirmCallback = null;
});
document.getElementById('confirm-ok').addEventListener('click', function() {
    confirmOverlay.classList.remove('open');
    if (_confirmCallback) { _confirmCallback(); _confirmCallback = null; }
});
confirmOverlay.addEventListener('click', function(e) {
    if (e.target === this) { confirmOverlay.classList.remove('open'); _confirmCallback = null; }
});

function showConfirm(title, subtitle, body, okLabel, okColor, callback) {
    document.getElementById('confirm-title').textContent    = title;
    document.getElementById('confirm-subtitle').textContent = subtitle;
    document.getElementById('confirm-body').textContent     = body;
    const okBtn = document.getElementById('confirm-ok');
    okBtn.textContent  = okLabel;
    okBtn.style.background = okColor;
    _confirmCallback = callback;
    confirmOverlay.classList.add('open');
}

// ── Save Note standalone ──────────────────────────────────────
document.getElementById('save-note-btn').addEventListener('click', function() {
    const noteTA = document.getElementById('action-note');
    const note   = noteTA.value.trim();
    if (!note) return;
    const btn    = this;
    const msgEl  = document.getElementById('action-msg');
    btn.disabled = true;
    btn.textContent = 'Saving…';
    const fd = new FormData();
    fd.append('action',   'note_only');
    fd.append('order_id', btn.dataset.orderId);
    fd.append('note',     note);
    fetch('wo_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(function(res) {
            btn.disabled = false;
            btn.textContent = '💬 Save Note';
            if (res.success) {
                noteTA.value = '';
                const notesEl = document.getElementById('d-notes');
                const existing = notesEl.textContent.trim();
                notesEl.textContent = (existing === 'No activity yet.') ? res.log_entry : existing + '\n' + res.log_entry;
                notesEl.style.color = '#6b7a8d';
                msgEl.style.display = '';
                msgEl.style.color   = '#059669';
                msgEl.textContent   = 'Note saved.';
                setTimeout(function() { msgEl.style.display = 'none'; }, 2500);
            } else {
                msgEl.style.display = '';
                msgEl.style.color   = '#dc2626';
                msgEl.textContent   = res.message || 'Failed to save note.';
            }
        });
});

function buildAssignPanel() {
    const list    = document.getElementById('assign-list');
    const search  = document.getElementById('assign-search');
    const counter = document.getElementById('assign-count');
    const roleLabels = { MW: 'Maintenance Worker', BC: 'Building Custodian', BM: 'Building Maintenance' };
    const groups     = { MW: [], BC: [], BM: [] };
    ASSIGNABLE_WORKERS.forEach(function(w) { if (groups[w.role]) groups[w.role].push(w); });

    function render(filter) {
        list.innerHTML = '';
        const f = (filter || '').toLowerCase();
        ['MW','BC','BM'].forEach(function(role) {
            const workers = groups[role].filter(function(w) {
                return !f || (w.first_name + ' ' + w.last_name).toLowerCase().includes(f);
            });
            if (!workers.length) return;
            const grpHdr = document.createElement('div');
            grpHdr.className = 'assign-role-group';
            grpHdr.textContent = roleLabels[role];
            list.appendChild(grpHdr);
            workers.forEach(function(w) {
                const item = document.createElement('label');
                item.className = 'assign-item';
                const meta = (role !== 'MW' && w.building) ? w.building : 'Corp-wide';
                item.innerHTML =
                    '<input type="checkbox" class="assign-checkbox" data-email="' + w.email + '" data-name="' + w.first_name + ' ' + w.last_name + '">' +
                    '<span class="assign-item-name">' + w.first_name + ' ' + w.last_name + '</span>' +
                    '<span class="assign-item-meta">' + meta + '</span>';
                item.querySelector('input').addEventListener('change', updateCount);
                list.appendChild(item);
            });
        });
    }

    function updateCount() {
        const n = document.querySelectorAll('.assign-checkbox:checked').length;
        counter.textContent = n > 0 ? n + ' selected' : '';
    }

    search.value = '';
    search.removeEventListener('input', search._renderHandler);
    search._renderHandler = function() { render(this.value); };
    search.addEventListener('input', search._renderHandler);
    render('');
}

// Priority pill clicks handled per-modal-open in openDetailModal

function submitAction(orderId, action, note, btn, msgEl, rowData, assignees) {
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = 'Saving…';
    msgEl.style.display = 'none';

    const selectedPriPill = document.querySelector('.action-pri-pill.sel');
    const newPriority = selectedPriPill ? selectedPriPill.dataset.p : '';
    const priorityChanged = newPriority && rowData && newPriority !== rowData.priority;

    const fd = new FormData();
    fd.append('action',       action);
    fd.append('order_id',     orderId);
    fd.append('note',         note);
    fd.append('old_priority', rowData ? (rowData.priority || '') : '');
    if (priorityChanged) fd.append('new_priority', newPriority);
    if (assignees && assignees.length > 0) {
        fd.append('assignees', JSON.stringify(assignees));
    }

    fetch('wo_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(function(res) {
            btn.disabled = false;
            btn.textContent = orig;
            if (res.success) {
                const rows = document.querySelectorAll('.wo-row');
                rows.forEach(function(row) {
                    if (row.dataset.id === String(orderId)) {
                        row.dataset.status = res.new_status;
                        row.dataset.filter = res.new_status;
                        row.dataset.notes  = (row.dataset.notes || '') + '\n' + res.log_entry;
                        const allBadges = row.querySelectorAll('td .badge');
                        const statusBadge = allBadges[allBadges.length - 1];
                        if (statusBadge) {
                            statusBadge.className = 'badge ' + (statusClassMap[res.new_status] || 'badge-pending');
                            statusBadge.textContent = res.new_status;
                        }
                    }
                });
                applyTable();
                const statusEl = document.getElementById('d-status-badge');
                statusEl.className   = 'badge ' + (statusClassMap[res.new_status] || 'badge-pending');
                statusEl.textContent = res.new_status;
                const notesEl = document.getElementById('d-notes');
                const existing = notesEl.textContent.trim();
                const wasEmpty = existing === 'No activity yet.';
                notesEl.textContent = wasEmpty ? res.log_entry : existing + '\n' + res.log_entry;
                notesEl.style.color = '#6b7a8d';
                if (res.new_priority) {
                    const priMap = {Low:'pri-low',Mid:'pri-mid',High:'pri-high',Urgent:'pri-urgent'};
                    document.querySelectorAll('.wo-row').forEach(function(row) {
                        if (row.dataset.id === String(orderId)) {
                            row.dataset.priority = res.new_priority;
                            const priSpan = row.querySelector('td .pri');
                            if (priSpan) { priSpan.className = 'pri ' + (priMap[res.new_priority] || 'pri-low'); priSpan.textContent = res.new_priority; }
                        }
                    });
                    const priEl = document.getElementById('d-priority-badge');
                    priEl.className = 'pri ' + (priMap[res.new_priority] || 'pri-low');
                    priEl.textContent = res.new_priority;
                }
                document.getElementById('action-note').value = '';
                msgEl.style.display = 'none';
                setTimeout(function() { closeDetailModal(); }, 400);
                const badge = document.querySelector('.notif-badge');
                if (badge) {
                    const cur = parseInt(badge.textContent) || 1;
                    if (cur <= 1) badge.remove();
                    else badge.textContent = cur - 1;
                }
                // Remove this WO from the notification dropdown
                const notifItem = document.querySelector('.notif-item[data-wo="' + (rowData ? rowData.wo : '') + '"]');
                if (notifItem) notifItem.remove();
            } else {
                msgEl.style.display = '';
                msgEl.style.color   = '#dc2626';
                msgEl.textContent   = res.message || 'Something went wrong.';
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = orig;
            msgEl.style.display = '';
            msgEl.style.color   = '#dc2626';
            msgEl.textContent   = 'Network error. Please try again.';
        });
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
    tr.dataset.attachment = '';
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

// ── Auto-open WO detail modal from URL ?wo=WO-000001 (email link) ─────────────
(function() {
    const params  = new URLSearchParams(window.location.search);
    const woParam = params.get('wo');
    if (!woParam) return;
    const rows = document.querySelectorAll('.wo-row');
    for (let i = 0; i < rows.length; i++) {
        if (rows[i].dataset.wo === woParam) {
            openDetailModal(rows[i].dataset);
            window.history.replaceState({}, '', window.location.pathname);
            return;
        }
    }
    alert('Work order ' + woParam + ' was not found or you do not have permission to view it.');
})();

</script>
</body>
</html>