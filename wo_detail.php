<?php
session_start();

if (!isset($_SESSION['google_user'])) {
    header('Location: index.php');
    exit;
}

$user          = $_SESSION['google_user'];
$user_email    = $user['email'];
$user_name     = $user['name']    ?? 'User';
$user_pic      = $user['picture'] ?? '';
$name_parts    = explode(' ', trim($user_name));
$initials      = strtoupper(substr($name_parts[0], 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : ''));
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

// Role-based access check (mirrors main.php query logic)
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

// Assigned workers for this order
$assigned_workers = [];
$stmt3 = $db->prepare("SELECT user_name, user_email FROM order_assignments WHERE order_id=?");
$stmt3->bind_param('i', $order_id);
$stmt3->execute();
$r3 = $stmt3->get_result();
while ($w = $r3->fetch_assoc()) $assigned_workers[] = $w;
$stmt3->close();

// Assignable workers (for managers)
$assignable_workers = [];
if (in_array($user_role, ['MT','MM','A'])) {
    $res = $db->query("SELECT first_name, last_name, email, role, building FROM users WHERE role IN ('MW','BC','BM') AND active=1 ORDER BY FIELD(role,'MW','BC','BM'),last_name,first_name");
    if ($res) while ($w = $res->fetch_assoc()) $assignable_workers[] = $w;
    $assigned_emails = array_column($assigned_workers, 'user_email');
    $assignable_workers = array_values(array_filter($assignable_workers, fn($w) => !in_array($w['email'], $assigned_emails)));
}
$db->close();

$is_maint = $order['type'] === 'Maintenance';
$status   = $order['status'];

// Action sets (mirrors main.php openDetailModal logic)
$bt_a  = [['label'=>'↑ Approve → Principal','action'=>'bt_approve','cls'=>'primary'],['label'=>'✓ Mark Completed','action'=>'bt_complete','cls'=>'success'],['label'=>'✕ Reject','action'=>'bt_reject','cls'=>'danger']];
$bp_a  = [['label'=>'↑ Approve → Manager','action'=>'bp_approve','cls'=>'primary'],['label'=>'✓ Mark Completed','action'=>'bp_complete','cls'=>'success'],['label'=>'✕ Reject','action'=>'bp_reject','cls'=>'danger']];
$mt_a  = [['label'=>'✓ Mark Completed','action'=>'mt_complete','cls'=>'success'],['label'=>'✕ Reject','action'=>'mt_reject','cls'=>'danger']];
$mm_a  = [['label'=>'✓ Mark Completed','action'=>'mm_complete','cls'=>'success'],['label'=>'✕ Reject','action'=>'mm_reject','cls'=>'danger']];
$wk_a  = [['label'=>'✓ Mark Completed','action'=>'worker_complete','cls'=>'success']];

$page_actions = []; $show_assign = false;
if ($user_role==='BT' && $status==='Pending Approval' && !$is_maint) $page_actions=$bt_a;
if ($user_role==='BP' && $status==='Pending Approval' && $is_maint)  $page_actions=$bp_a;
if ($user_role==='BP' && $status==='Approved'         && !$is_maint) $page_actions=$bp_a;
if ($user_role==='MT' && in_array($status,['Approved','In Progress']) && !$is_maint) { $page_actions=$mt_a; $show_assign=true; }
if ($user_role==='MM' && in_array($status,['Approved','In Progress']) && $is_maint)  { $page_actions=$mm_a; $show_assign=true; }
if ($user_role==='A'  && $status==='Pending Approval' && $is_maint)  $page_actions=$bp_a;
if ($user_role==='A'  && $status==='Pending Approval' && !$is_maint) $page_actions=$bt_a;
if ($user_role==='A'  && in_array($status,['Approved','In Progress']) && !$is_maint) { $page_actions=$mt_a; $show_assign=true; }
if ($user_role==='A'  && in_array($status,['Approved','In Progress']) && $is_maint)  { $page_actions=$mm_a; $show_assign=true; }
if (in_array($user_role,['MW','BC','BM']) && $status==='In Progress') $page_actions=$wk_a;

$show_priority = in_array($user_role, ['BP','MT','MM','A']);
$show_note     = ($user_role !== 'U');

$status_cls = ['Pending Approval'=>'badge-pending','Approved'=>'badge-approved','In Progress'=>'badge-inprogress','Completed'=>'badge-completed','Rejected'=>'badge-rejected'];
$pri_cls    = ['Low'=>'pri-low','Mid'=>'pri-mid','High'=>'pri-high','Urgent'=>'pri-urgent'];
$photos     = array_filter(array_map('trim', explode('||', $order['photo_path'] ?? '')));
$notes_text = ltrim($order['notes'] ?? '', "\n");

$current_page = 'wo_detail';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($wo_num) ?> — Work Order Detail</title>
<link href="https://fonts.googleapis.com/css2?family=Barlow:wght@300;400;500;600;700&family=Barlow+Condensed:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/glightbox/dist/css/glightbox.min.css">
<style>
/* ── PAGE LAYOUT (matches main.php exactly) ── */
.main{max-width:1300px;width:100%;margin:0 auto;padding:32px 24px 48px;flex:1}

/* ── BADGES + PRIORITY ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
.badge-pending{background:#fef3c7;color:#92400e}
.badge-approved{background:#d1fae5;color:#065f46}
.badge-inprogress{background:#dbeafe;color:#1e40af}
.badge-completed{background:#f0fdf4;color:#166534}
.badge-rejected{background:#fee2e2;color:#991b1b}
.pri{display:inline-block;padding:3px 10px;border-radius:5px;font-size:11px;font-weight:700}
.pri-low{background:#d1fae5;color:#065f46}
.pri-mid{background:#dbeafe;color:#1e40af}
.pri-high{background:#fef3c7;color:#92400e}
.pri-urgent{background:#fee2e2;color:#991b1b}

/* ── BREADCRUMB ── */
.wd-bc{display:flex;align-items:center;gap:6px;font-size:13px;color:#6b7a8d;margin-bottom:18px}
.wd-bc a{color:#6b7a8d;text-decoration:none;display:inline-flex;align-items:center;gap:4px;transition:color .12s}
.wd-bc a:hover{color:var(--cyan)}
.wd-bc .sep{font-size:11px;opacity:.4}
.wd-bc span{color:#1a1a2e;font-weight:600}

/* ── PAGE HEADER ── */
.wd-head{display:flex;align-items:flex-start;gap:16px;margin-bottom:24px;flex-wrap:wrap}
.wd-type-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;margin-top:2px}
.wd-type-icon.maint{background:#fef3c7;color:#b45309}
.wd-type-icon.tech{background:#dbeafe;color:#1d4ed8}
.wd-head-body{flex:1;min-width:0}
.wd-head-title{font-family:'Barlow Condensed',sans-serif;font-size:26px;font-weight:700;color:#1a1a2e;line-height:1.15}
.wd-head-meta{font-size:13px;color:#6b7a8d;margin-top:5px;line-height:1.5}
.wd-head-meta strong{color:#3d4f5e;font-weight:600}
.wd-head-right{display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0}

/* ── 2-COLUMN GRID ── */
.wd-grid{display:grid;grid-template-columns:1fr 400px;gap:22px;align-items:start;width:100%}
@media(max-width:960px){.wd-grid{grid-template-columns:1fr}}

/* ── MAIN COLUMN CARDS ── */
.wd-card{background:#fff;border:1px solid #e8ecf0;border-radius:14px;padding:20px 22px;margin-bottom:18px}
.wd-card:last-child{margin-bottom:0}
.wd-card-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;display:block;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid #f0f4f8}

/* ── FIELD GRID ── */
.wd-fgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
@media(max-width:620px){.wd-fgrid{grid-template-columns:1fr 1fr}}
.wd-f label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#aab0bb;display:block;margin-bottom:3px}
.wd-f p{font-size:13px;color:#1a1a2e;font-weight:500;line-height:1.5}
.wd-f.span2{grid-column:span 2}
.wd-f.full{grid-column:1/-1}
.wd-desc{background:#f8f9fa;border-radius:9px;padding:11px 14px;font-size:13px;color:#3d4f5e;line-height:1.65;white-space:pre-wrap;word-break:break-word}

/* ── PHOTOS ── */
.wd-photos{display:flex;flex-wrap:wrap;gap:6px}
.wd-photo-a{display:block;width:150px;height:150px;border-radius:8px;overflow:hidden;border:1.5px solid #e8ecf0;flex-shrink:0}
.wd-photo-a img{width:100%;height:100%;object-fit:cover;display:block;cursor:zoom-in;transition:opacity .15s}
.wd-photo-a:hover img{opacity:.8}

/* ── ACTIVITY LOG ── */
.wd-log{background:#f8f9fa;border-radius:9px;padding:12px 14px;font-size:12px;color:#6b7a8d;line-height:1.7;white-space:pre-wrap;word-break:break-word;max-height:380px;overflow-y:auto}

/* ── SIDEBAR CARDS ── */
.wd-sc{background:#fff;border:1px solid #e8ecf0;border-radius:14px;padding:18px 20px;margin-bottom:14px}
.wd-sc:last-child{margin-bottom:0}
.wd-sc-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;display:block;margin-bottom:12px}

/* ── ACTIONS CARD (distinct treatment) ── */
.wd-sc-actions{background:#f8fafb;border:1.5px solid #d0e8f0;border-radius:14px;padding:18px 20px;margin-bottom:14px}
.wd-sc-actions:last-child{margin-bottom:0}
.wd-sc-actions .wd-sc-lbl{color:#5a92ac}

/* ── PRIORITY PILLS ── */
.wd-pri-pills{display:flex;gap:6px;flex-wrap:wrap}
.wd-pri-pill{padding:5px 13px;border-radius:8px;border:1.5px solid #e8ecf0;font-size:12px;font-weight:700;cursor:pointer;background:transparent;font-family:'Barlow',sans-serif;color:#6b7a8d;transition:all .12s}
.wd-pri-note{font-size:11px;color:#aab0bb;margin-top:7px}

/* ── ACTION BUTTONS ── */
.wd-act-btn{width:100%;padding:11px 18px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Barlow',sans-serif;border:none;display:flex;align-items:center;justify-content:center;gap:8px;transition:opacity .12s;margin-bottom:8px}
.wd-act-btn:last-of-type{margin-bottom:0}
.wd-act-btn:disabled{opacity:.5;cursor:not-allowed}
.wd-act-btn.primary{background:var(--cyan);color:#fff}
.wd-act-btn.primary:hover:not(:disabled){background:var(--cyan-dark)}
.wd-act-btn.success{background:#059669;color:#fff}
.wd-act-btn.success:hover:not(:disabled){background:#047857}
.wd-act-btn.danger{background:#dc2626;color:#fff}
.wd-act-btn.danger:hover:not(:disabled){background:#b91c1c}
.wd-act-btn.assign{background:#7c3aed;color:#fff}
.wd-act-btn.assign:hover:not(:disabled){background:#6d28d9}
.wd-action-msg{font-size:12px;margin-top:8px;display:none;font-weight:500}

/* ── NOTE FORM ── */
.wd-note-ta{width:100%;border:1px solid #d0d5dd;border-radius:9px;padding:9px 12px;font-size:13px;font-family:'Barlow',sans-serif;color:#1a1a2e;background:#fff;resize:vertical;min-height:68px;line-height:1.55;transition:border-color .12s;box-sizing:border-box}
.wd-note-ta:focus{outline:none;border-color:var(--cyan);box-shadow:0 0 0 3px rgba(41,182,213,.10)}
.wd-note-save{margin-top:8px;padding:8px 16px;border-radius:9px;border:none;background:var(--cyan-light);color:var(--cyan-dark);font-size:13px;font-weight:700;cursor:pointer;font-family:'Barlow',sans-serif;transition:background .12s}
.wd-note-save:hover{background:var(--cyan-muted)}
.wd-note-save:disabled{opacity:.5;cursor:not-allowed}

/* ── ASSIGNED WORKERS ── */
.wd-worker{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #f0f4f8}
.wd-worker:last-child{border-bottom:none}
.wd-wkr-av{width:30px;height:30px;border-radius:50%;background:var(--cyan-light);color:var(--cyan-dark);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0}
.wd-wkr-name{font-size:13px;font-weight:600;color:#1a1a2e}
.wd-wkr-email{font-size:11px;color:#6b7a8d}

/* ── ASSIGN PANEL ── */
.wd-as-search{width:100%;padding:8px 12px;border:1px solid #d0d5dd;border-radius:9px;font-size:13px;font-family:'Barlow',sans-serif;margin-bottom:8px;box-sizing:border-box}
.wd-as-search:focus{outline:none;border-color:var(--cyan);box-shadow:0 0 0 3px rgba(41,182,213,.10)}
.wd-as-list{max-height:190px;overflow-y:auto;border:1px solid #e8ecf0;border-radius:9px;background:#fff;margin-bottom:8px}
.wd-a-item{display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid #f0f4f8;cursor:pointer;transition:background .1s}
.wd-a-item:last-child{border-bottom:none}
.wd-a-item:hover{background:#f0f8fb}
.wd-a-item input[type=checkbox]{accent-color:var(--cyan);width:15px;height:15px;flex-shrink:0}
.wd-a-name{font-size:13px;font-weight:600;color:#1a1a2e}
.wd-a-meta{font-size:11px;color:#6b7a8d;margin-left:auto}
.wd-a-role-grp{padding:5px 12px 2px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#aab0bb;background:#f8f9fa;border-bottom:1px solid #f0f4f8}
.wd-a-count{font-size:12px;color:var(--cyan-dark);font-weight:600;min-height:18px;margin-bottom:6px}

/* ── CONFIRM MODAL ── */
.wdc-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:500;align-items:center;justify-content:center}
.wdc-ov.open{display:flex}
.wdc-box{background:#fff;border-radius:14px;padding:28px 28px 22px;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.18)}
.wdc-title{font-family:'Barlow Condensed',sans-serif;font-size:21px;font-weight:700;color:#1a1a2e;margin-bottom:3px}
.wdc-sub{font-family:'Barlow Condensed',sans-serif;font-size:14px;color:var(--cyan);font-weight:600;margin-bottom:12px}
.wdc-body{font-size:13px;color:#6b7a8d;line-height:1.6;margin-bottom:22px}
.wdc-footer{display:flex;gap:10px;justify-content:flex-end}
.wdc-cancel{padding:9px 20px;border-radius:9px;border:1px solid #d0d5dd;background:transparent;cursor:pointer;font-size:13px;font-weight:700;font-family:'Barlow',sans-serif;color:#6b7a8d}
.wdc-cancel:hover{background:#f8f9fa;color:#1a1a2e}
.wdc-ok{padding:9px 20px;border-radius:9px;border:none;cursor:pointer;font-size:13px;font-weight:700;font-family:'Barlow',sans-serif;color:#fff}
.wdc-ok:hover{opacity:.88}

/* ── SUCCESS TOAST ── */
.wd-toast{position:fixed;top:70px;right:20px;background:#059669;color:#fff;padding:11px 18px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.15);z-index:600;display:none;align-items:center;gap:8px}
.wd-toast.show{display:flex}

/* ── PRINT BUTTON ── */
.wd-print-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:8px;border:1.5px solid #d0d5dd;background:#fff;color:#6b7a8d;font-size:12px;font-weight:600;font-family:'Barlow',sans-serif;text-decoration:none;transition:all .12s;cursor:pointer}
.wd-print-btn:hover{border-color:var(--cyan);color:var(--cyan);background:#f0f8fb}
</style>
</head>
<body>

<?php require_once __DIR__ . '/includes/nav.php'; ?>

<div class="wd-toast" id="wd-toast"><i class="ti ti-circle-check"></i> Work order updated successfully.</div>

<main class="main">

    <!-- Breadcrumb -->
    <div class="wd-bc">
        <a href="main.php"><i class="ti ti-home"></i> Dashboard</a>
        <i class="ti ti-chevron-right sep"></i>
        <span><?= htmlspecialchars($wo_num) ?></span>
    </div>

    <!-- Page header -->
    <div class="wd-head">
        <div class="wd-type-icon <?= $is_maint ? 'maint' : 'tech' ?>">
            <i class="ti <?= $is_maint ? 'ti-tool' : 'ti-device-laptop' ?>"></i>
        </div>
        <div class="wd-head-body">
            <div class="wd-head-title"><?= htmlspecialchars($order['type']) ?> Request</div>
            <div class="wd-head-meta">
                <strong><?= htmlspecialchars($order['building'] ?? '—') ?></strong>
                <?php if (!empty($order['room'])): ?>&middot; <?= htmlspecialchars($order['room']) ?><?php endif; ?>
                &middot; <?= $order['created_at'] ? date('M j, Y', strtotime($order['created_at'])) : '—' ?>
                &middot; <?= htmlspecialchars($order['submitted_name'] ?: $order['submitted_by']) ?>
                &middot; <a href="mailto:<?= htmlspecialchars($order['submitted_by']) ?>" style="color:#aab0bb;text-decoration:none;transition:color .12s" onmouseover="this.style.color='var(--cyan)'" onmouseout="this.style.color='#aab0bb'"><?= htmlspecialchars($order['submitted_by']) ?></a>
            </div>
        </div>
        <div class="wd-head-right">
            <div style="display:flex;align-items:center;gap:6px">
                <span class="badge <?= $status_cls[$status] ?? 'badge-pending' ?>"><?= htmlspecialchars($status) ?></span>
                <span class="pri <?= $pri_cls[$order['priority'] ?? ''] ?? 'pri-low' ?>"><?= htmlspecialchars($order['priority'] ?? '—') ?></span>
            </div>
            <a class="wd-print-btn" href="wo_print.php?wo=<?= urlencode($wo_num) ?>" target="_blank" title="Print work order" style="margin-top:5px">
                <i class="ti ti-printer"></i> Print
            </a>
        </div>
    </div>

    <div class="wd-grid">

        <!-- ── LEFT: Content cards ── -->
        <div>

            <!-- Request Details -->
            <div class="wd-card">
                <span class="wd-card-lbl">Request Details</span>
                <div class="wd-fgrid">
                    <div class="wd-f">
                        <label>Problem Type</label>
                        <p><?= htmlspecialchars($order['problem_type'] ?? '—') ?></p>
                    </div>
                    <?php if ($is_maint): ?>
                    <div class="wd-f">
                        <label>Purpose</label>
                        <p><?= htmlspecialchars($order['purpose'] ?? '—') ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if ($order['time_from'] && $order['time_to']): ?>
                    <div class="wd-f">
                        <label>Time Available</label>
                        <p><?= htmlspecialchars($order['time_from']) ?> – <?= htmlspecialchars($order['time_to']) ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['resolved_by'])): ?>
                    <div class="wd-f full">
                        <label>Resolved By</label>
                        <p><?= htmlspecialchars($order['resolved_by']) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Description -->
            <div class="wd-card">
                <span class="wd-card-lbl">Description</span>
                <div class="wd-desc"><?= htmlspecialchars($order['description'] ?? '—') ?></div>
            </div>

            <!-- Photos -->
            <?php if (!empty($photos)): ?>
            <div class="wd-card">
                <span class="wd-card-lbl">Attached Photos</span>
                <div class="wd-photos">
                    <?php $n = count($photos); foreach ($photos as $i => $img): ?>
                    <a class="wd-photo-a" href="<?= htmlspecialchars($img) ?>" data-gallery="wd-g" data-desc="Photo <?= $i+1 ?> of <?= $n ?>">
                        <img src="<?= htmlspecialchars($img) ?>" alt="Work order photo <?= $i+1 ?>">
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Activity Log -->
            <div class="wd-card">
                <span class="wd-card-lbl">Activity Log</span>
                <div class="wd-log" id="wd-log"><?php
                    if ($notes_text) echo htmlspecialchars($notes_text);
                    else echo '<span style="color:#d0d5dd;font-style:italic">No activity yet.</span>';
                ?></div>
            </div>

        </div><!-- /left col -->

        <!-- ── RIGHT: Sidebar ── -->
        <div>

            <!-- Change Priority (managers only) -->
            <?php if ($show_priority): ?>
            <div class="wd-sc">
                <span class="wd-sc-lbl">Change Priority</span>
                <div class="wd-pri-pills">
                    <?php foreach (['Low','Mid','High','Urgent'] as $p): ?>
                    <button class="wd-pri-pill<?= ($order['priority']===$p)?' sel':'' ?>" data-p="<?= $p ?>" type="button"><?= $p ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="wd-pri-note">Priority change is applied when you take an action below.</div>
            </div>
            <?php endif; ?>

            <!-- Assigned Workers -->
            <?php if (!empty($assigned_workers)): ?>
            <div class="wd-sc">
                <span class="wd-sc-lbl">Assigned Workers</span>
                <?php foreach ($assigned_workers as $w):
                    $wp = explode(' ', trim($w['user_name']));
                    $wi = strtoupper(substr($wp[0],0,1).(isset($wp[1])?substr($wp[1],0,1):''));
                ?>
                <div class="wd-worker">
                    <div class="wd-wkr-av"><?= htmlspecialchars($wi) ?></div>
                    <div>
                        <div class="wd-wkr-name"><?= htmlspecialchars($w['user_name']) ?></div>
                        <div class="wd-wkr-email"><?= htmlspecialchars($w['user_email']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Add Note -->
            <?php if ($show_note): ?>
            <div class="wd-sc">
                <span class="wd-sc-lbl">Add Note</span>
                <textarea class="wd-note-ta" id="wd-note-ta" placeholder="Add an internal note…"></textarea>
                <div><button class="wd-note-save" id="wd-save-note" type="button">💬 Save Note</button></div>
                <div class="wd-action-msg" id="wd-note-msg"></div>
            </div>
            <?php endif; ?>

            <!-- Assign Workers (managers) -->
            <?php if ($show_assign && !empty($assignable_workers)): ?>
            <div class="wd-sc">
                <span class="wd-sc-lbl">Assign Workers</span>
                <div class="wd-a-count" id="wd-a-count"></div>
                <input type="text" class="wd-as-search" id="wd-as-search" placeholder="Search workers…" autocomplete="off">
                <div class="wd-as-list" id="wd-as-list"></div>
            </div>
            <?php endif; ?>

            <!-- Actions (last) -->
            <?php if (!empty($page_actions) || ($show_assign && !empty($assignable_workers))): ?>
            <div class="wd-sc-actions">
                <span class="wd-sc-lbl">Actions</span>
                <?php foreach ($page_actions as $a): ?>
                <button class="wd-act-btn <?= $a['cls'] ?>" data-action="<?= htmlspecialchars($a['action']) ?>" type="button"><?= htmlspecialchars($a['label']) ?></button>
                <?php endforeach; ?>
                <?php if ($show_assign && !empty($assignable_workers)): ?>
                <button class="wd-act-btn assign" id="wd-assign-btn" type="button">→ Assign &amp; Start</button>
                <?php endif; ?>
                <div class="wd-action-msg" id="wd-action-msg"></div>
            </div>
            <?php endif; ?>

        </div><!-- /sidebar -->

    </div><!-- /wd-grid -->

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<!-- Confirm Modal -->
<div class="wdc-ov" id="wdc-ov">
    <div class="wdc-box">
        <div class="wdc-title" id="wdc-title"></div>
        <div class="wdc-sub"   id="wdc-sub"></div>
        <div class="wdc-body"  id="wdc-body"></div>
        <div class="wdc-footer">
            <button class="wdc-cancel" id="wdc-cancel">Cancel</button>
            <button class="wdc-ok"     id="wdc-ok"></button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/glightbox/dist/js/glightbox.min.js"></script>
<script>
const ORDER_ID           = <?= $order_id ?>;
const WO_NUM             = <?= json_encode($wo_num) ?>;
const USER_ROLE          = <?= json_encode($user_role) ?>;
const IS_MAINT           = <?= $is_maint ? 'true' : 'false' ?>;
const OLD_PRIORITY       = <?= json_encode($order['priority'] ?? '') ?>;
const ASSIGNABLE_WORKERS = <?= json_encode($assignable_workers) ?>;
const SHOW_ASSIGN        = <?= $show_assign ? 'true' : 'false' ?>;

// ── Confirm modal ──────────────────────────────────────────────
let _cb = null;
const wdcOv = document.getElementById('wdc-ov');
document.getElementById('wdc-cancel').addEventListener('click', () => { wdcOv.classList.remove('open'); _cb = null; });
document.getElementById('wdc-ok').addEventListener('click', () => { wdcOv.classList.remove('open'); if (_cb) { _cb(); _cb = null; } });
wdcOv.addEventListener('click', e => { if (e.target === wdcOv) { wdcOv.classList.remove('open'); _cb = null; } });

function showConfirm(title, sub, body, okLabel, okColor, cb) {
    document.getElementById('wdc-title').textContent = title;
    document.getElementById('wdc-sub').textContent   = sub;
    document.getElementById('wdc-body').textContent  = body;
    const ok = document.getElementById('wdc-ok');
    ok.textContent      = okLabel;
    ok.style.background = okColor;
    _cb = cb;
    wdcOv.classList.add('open');
}

// ── Priority pills ──────────────────────────────────────────────
const priColors = { Low:['#d1fae5','#10b981','#065f46'], Mid:['#dbeafe','#3b82f6','#1e40af'], High:['#fef3c7','#f59e0b','#92400e'], Urgent:['#fee2e2','#ef4444','#991b1b'] };
document.querySelectorAll('.wd-pri-pill').forEach(p => {
    if (p.classList.contains('sel')) {
        const c = priColors[p.dataset.p];
        if (c) { p.style.background=c[0]; p.style.borderColor=c[1]; p.style.color=c[2]; }
    }
    p.addEventListener('click', () => {
        document.querySelectorAll('.wd-pri-pill').forEach(x => {
            x.classList.remove('sel');
            x.style.background='transparent'; x.style.borderColor='#e8ecf0'; x.style.color='#6b7a8d';
        });
        p.classList.add('sel');
        const c = priColors[p.dataset.p];
        if (c) { p.style.background=c[0]; p.style.borderColor=c[1]; p.style.color=c[2]; }
    });
});

function getSelPri() {
    const s = document.querySelector('.wd-pri-pill.sel');
    return s ? s.dataset.p : '';
}

// ── Submit action to wo_action.php ─────────────────────────────
const confirmLabels = {
    bt_approve:      { title:'Approve & Escalate',  color:'var(--cyan)',  body:'This will approve the work order and send it to the Building Principal for review.' },
    bt_reject:       { title:'Reject Work Order',   color:'#dc2626',      body:'This will reject the work order. The submitter will be notified.' },
    bt_complete:     { title:'Mark as Completed',   color:'#059669',      body:'This will mark the work order as completed. The submitter will be notified.' },
    bp_approve:      { title:'Approve & Escalate',  color:'var(--cyan)',  body:'This will approve the work order and escalate it to the Manager.' },
    bp_reject:       { title:'Reject Work Order',   color:'#dc2626',      body:'This will reject the work order. The submitter will be notified.' },
    bp_complete:     { title:'Mark as Completed',   color:'#059669',      body:'This will mark the work order as completed. The submitter will be notified.' },
    mt_complete:     { title:'Mark as Completed',   color:'#059669',      body:'This will mark the work order as completed. The submitter will be notified.' },
    mt_reject:       { title:'Reject Work Order',   color:'#dc2626',      body:'This will reject the work order. The submitter will be notified.' },
    mm_complete:     { title:'Mark as Completed',   color:'#059669',      body:'This will mark the work order as completed. The submitter will be notified.' },
    mm_reject:       { title:'Reject Work Order',   color:'#dc2626',      body:'This will reject the work order. The submitter will be notified.' },
    worker_complete: { title:'Mark as Completed',   color:'#059669',      body:'This will mark the work order as completed. The submitter will be notified.' },
};

function doAction(action, note, btn, assignees) {
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = 'Saving…';
    const msgEl = document.getElementById('wd-action-msg');
    if (msgEl) msgEl.style.display = 'none';

    const fd = new FormData();
    fd.append('action',   action);
    fd.append('order_id', ORDER_ID);
    fd.append('note',     note);
    fd.append('old_priority', OLD_PRIORITY);
    const np = getSelPri();
    if (np) fd.append('new_priority', np);
    if (assignees && assignees.length) fd.append('assignees', JSON.stringify(assignees));

    fetch('wo_action.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                window.location.href = 'wo_detail.php?wo=' + encodeURIComponent(WO_NUM) + '&done=1';
            } else {
                btn.disabled    = false;
                btn.textContent = orig;
                if (msgEl) { msgEl.style.display=''; msgEl.style.color='#dc2626'; msgEl.textContent=res.message||'Something went wrong.'; }
            }
        })
        .catch(() => {
            btn.disabled    = false;
            btn.textContent = orig;
            if (msgEl) { msgEl.style.display=''; msgEl.style.color='#dc2626'; msgEl.textContent='Network error. Please try again.'; }
        });
}

document.querySelectorAll('.wd-act-btn[data-action]').forEach(btn => {
    btn.addEventListener('click', () => {
        const action = btn.dataset.action;
        const note   = (document.getElementById('wd-note-ta') || {value:''}).value.trim();
        const cfg    = confirmLabels[action];
        if (cfg) {
            showConfirm(cfg.title, WO_NUM, cfg.body, btn.textContent.trim(), cfg.color, () => doAction(action, note, btn, null));
        } else {
            doAction(action, note, btn, null);
        }
    });
});

// ── Assign & Start ─────────────────────────────────────────────
const assignBtn = document.getElementById('wd-assign-btn');
if (assignBtn) {
    assignBtn.addEventListener('click', () => {
        const checked = Array.from(document.querySelectorAll('.wd-a-cb:checked'));
        const msgEl   = document.getElementById('wd-action-msg');
        if (!checked.length) {
            if (msgEl) { msgEl.style.display=''; msgEl.style.color='#dc2626'; msgEl.textContent='Please select at least one worker.'; }
            return;
        }
        const assignees    = checked.map(cb => ({ email: cb.dataset.email, name: cb.dataset.name }));
        const names        = assignees.map(a => a.name).join(', ');
        const assignAction = (USER_ROLE === 'MM' || (USER_ROLE === 'A' && IS_MAINT)) ? 'mm_assign' : 'mt_assign';
        const note = (document.getElementById('wd-note-ta') || {value:''}).value.trim();
        showConfirm('Assign & Start Work', WO_NUM,
            'Assign to: ' + names + '. This will set the order to In Progress and notify each worker.',
            '→ Assign & Start', '#7c3aed',
            () => doAction(assignAction, note, assignBtn, assignees));
    });
}

// ── Save Note (in-place, no page reload) ───────────────────────
const noteSaveBtn = document.getElementById('wd-save-note');
if (noteSaveBtn) {
    noteSaveBtn.addEventListener('click', () => {
        const ta   = document.getElementById('wd-note-ta');
        const note = ta.value.trim();
        if (!note) return;
        noteSaveBtn.disabled    = true;
        noteSaveBtn.textContent = 'Saving…';
        const msgEl = document.getElementById('wd-note-msg');
        const fd = new FormData();
        fd.append('action',   'note_only');
        fd.append('order_id', ORDER_ID);
        fd.append('note',     note);
        fetch('wo_action.php', { method:'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                noteSaveBtn.disabled    = false;
                noteSaveBtn.textContent = '💬 Save Note';
                const logEl = document.getElementById('wd-log');
                if (res.success) {
                    ta.value = '';
                    if (logEl.querySelector('span')) {
                        logEl.innerHTML   = '';
                        logEl.textContent = res.log_entry;
                    } else {
                        logEl.textContent = logEl.textContent.trim() + '\n' + res.log_entry;
                    }
                    logEl.scrollTop = logEl.scrollHeight;
                    if (msgEl) { msgEl.style.display=''; msgEl.style.color='#059669'; msgEl.textContent='Note saved.'; setTimeout(()=>{ msgEl.style.display='none'; }, 2500); }
                } else {
                    if (msgEl) { msgEl.style.display=''; msgEl.style.color='#dc2626'; msgEl.textContent=res.message||'Failed to save note.'; }
                }
            });
    });
}

// ── Build assign worker list ───────────────────────────────────
if (SHOW_ASSIGN && ASSIGNABLE_WORKERS.length) {
    (function() {
        const list    = document.getElementById('wd-as-list');
        const search  = document.getElementById('wd-as-search');
        const counter = document.getElementById('wd-a-count');
        if (!list) return;
        const roleLabels = { MW:'Maintenance Worker', BC:'Building Custodian', BM:'Building Maintenance' };
        const groups     = { MW:[], BC:[], BM:[] };
        ASSIGNABLE_WORKERS.forEach(w => { if (groups[w.role]) groups[w.role].push(w); });

        function render(filter) {
            list.innerHTML = '';
            const f = (filter || '').toLowerCase();
            ['MW','BC','BM'].forEach(role => {
                const ws = groups[role].filter(w => !f || (w.first_name + ' ' + w.last_name).toLowerCase().includes(f));
                if (!ws.length) return;
                const grp = document.createElement('div');
                grp.className   = 'wd-a-role-grp';
                grp.textContent = roleLabels[role];
                list.appendChild(grp);
                ws.forEach(w => {
                    const lbl  = document.createElement('label');
                    lbl.className = 'wd-a-item';
                    const meta = (role !== 'MW' && w.building) ? w.building : 'Corp-wide';
                    lbl.innerHTML = `<input type="checkbox" class="wd-a-cb" data-email="${w.email}" data-name="${w.first_name} ${w.last_name}"><span class="wd-a-name">${w.first_name} ${w.last_name}</span><span class="wd-a-meta">${meta}</span>`;
                    lbl.querySelector('input').addEventListener('change', () => {
                        const n = document.querySelectorAll('.wd-a-cb:checked').length;
                        counter.textContent = n > 0 ? n + ' selected' : '';
                    });
                    list.appendChild(lbl);
                });
            });
        }

        search.addEventListener('input', function() { render(this.value); });
        render('');
    })();
}

// ── Show success toast after redirect back ─────────────────────
(function() {
    const p = new URLSearchParams(window.location.search);
    if (p.get('done') === '1') {
        const toast = document.getElementById('wd-toast');
        if (toast) { toast.classList.add('show'); setTimeout(() => toast.classList.remove('show'), 3000); }
        window.history.replaceState({}, '', 'wo_detail.php?wo=' + encodeURIComponent(WO_NUM));
    }
})();

// ── GLightbox for photos ───────────────────────────────────────
if (document.querySelector('.wd-photo-a')) {
    GLightbox({ selector: '.wd-photo-a', touchNavigation: true, loop: false });
}
</script>
</body>
</html>
