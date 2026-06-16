<?php
session_start();

if (!isset($_SESSION['google_user'])) {
    header('Location: ../index.php');
    exit;
}

$user          = $_SESSION['google_user'];
$user_email    = $user['email'];
$user_name     = $user['name'] ?? 'User';
$user_given    = $user['given_name'] ?? '';
$user_pic      = $user['picture'] ?? '';
$user_role     = $_SESSION['user_role'] ?? 'U';

if (!in_array($user_role, ['MW','BC','BM','MM','BT','U','MT','A'])) {
    header('Location: ../main.php');
    exit;
}

$role_labels = [
    'MW' => 'Maintenance Worker',
    'BC' => 'Building Custodian',
    'BM' => 'Building Maintenance',
    'MM' => 'Maintenance Manager',
    'BT' => 'Building Technician',
    'MT' => 'Technology Manager',
    'A'  => 'Administrator',
    'U'  => 'User',
];
$role_label = $role_labels[$user_role] ?? 'User';

require_once __DIR__ . '/../../../wo_config.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');

$orders        = [];
$completed     = [];
$user_building = $_SESSION['user_building'] ?? null;

if ($user_role === 'MM') {
    $res = $db->query(
        "SELECT *, NULL AS assigned_date FROM orders
         WHERE type = 'Maintenance' AND status IN ('Approved','In Progress')
         ORDER BY FIELD(priority,'Urgent','High','Mid','Low'), created_at ASC"
    );
    if ($res) while ($row = $res->fetch_assoc()) $orders[] = $row;

    $res2 = $db->query(
        "SELECT *, NULL AS assigned_date FROM orders
         WHERE type = 'Maintenance' AND status IN ('Completed','Rejected')
         ORDER BY created_at DESC LIMIT 50"
    );
    if ($res2) while ($row = $res2->fetch_assoc()) $completed[] = $row;

} elseif ($user_role === 'MT') {
    $res = $db->query(
        "SELECT *, NULL AS assigned_date FROM orders
         WHERE type = 'Technology' AND status IN ('Approved','In Progress')
         ORDER BY FIELD(priority,'Urgent','High','Mid','Low'), created_at ASC"
    );
    if ($res) while ($row = $res->fetch_assoc()) $orders[] = $row;

    $res2 = $db->query(
        "SELECT *, NULL AS assigned_date FROM orders
         WHERE type = 'Technology' AND status IN ('Completed','Rejected')
         ORDER BY created_at DESC LIMIT 50"
    );
    if ($res2) while ($row = $res2->fetch_assoc()) $completed[] = $row;

} elseif ($user_role === 'A') {
    $res = $db->query(
        "SELECT *, NULL AS assigned_date FROM orders
         WHERE status IN ('Pending Approval','Approved','In Progress')
         ORDER BY FIELD(priority,'Urgent','High','Mid','Low'), created_at ASC"
    );
    if ($res) while ($row = $res->fetch_assoc()) $orders[] = $row;

    $res2 = $db->query(
        "SELECT *, NULL AS assigned_date FROM orders
         WHERE status IN ('Completed','Rejected')
         ORDER BY created_at DESC LIMIT 50"
    );
    if ($res2) while ($row = $res2->fetch_assoc()) $completed[] = $row;

} elseif ($user_role === 'BT') {
    $bt_buildings = array_filter(array_map('trim', explode(',', $user_building ?? '')));
    if ($bt_buildings) {
        $ph    = implode(',', array_fill(0, count($bt_buildings), '?'));
        $types = str_repeat('s', count($bt_buildings));

        $stmt = $db->prepare(
            "SELECT *, NULL AS assigned_date FROM orders
             WHERE building IN ($ph) AND type = 'Technology'
               AND status IN ('Pending Approval','In Progress')
             ORDER BY FIELD(priority,'Urgent','High','Mid','Low'), created_at ASC"
        );
        $stmt->bind_param($types, ...$bt_buildings);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $orders[] = $row;
        $stmt->close();

        $stmt2 = $db->prepare(
            "SELECT *, NULL AS assigned_date FROM orders
             WHERE building IN ($ph) AND type = 'Technology'
               AND status IN ('Completed','Rejected')
             ORDER BY created_at DESC LIMIT 50"
        );
        $stmt2->bind_param($types, ...$bt_buildings);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        while ($row = $res2->fetch_assoc()) $completed[] = $row;
        $stmt2->close();
    }

} elseif ($user_role === 'U') {
    $stmt = $db->prepare(
        "SELECT *, NULL AS assigned_date FROM orders
         WHERE submitted_by = ? AND status NOT IN ('Completed','Rejected')
         ORDER BY created_at DESC"
    );
    $stmt->bind_param('s', $user_email);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $orders[] = $row;
    $stmt->close();

    $stmt2 = $db->prepare(
        "SELECT *, NULL AS assigned_date FROM orders
         WHERE submitted_by = ? AND status IN ('Completed','Rejected')
         ORDER BY created_at DESC LIMIT 50"
    );
    $stmt2->bind_param('s', $user_email);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($row = $res2->fetch_assoc()) $completed[] = $row;
    $stmt2->close();

} else {
    // MW / BC / BM — assigned orders only
    $stmt = $db->prepare(
        "SELECT o.*, oa.assigned_at AS assigned_date
         FROM orders o
         INNER JOIN order_assignments oa ON o.id = oa.order_id
         WHERE oa.user_email = ? AND o.status = 'In Progress'
         ORDER BY FIELD(o.priority,'Urgent','High','Mid','Low'), o.created_at ASC"
    );
    $stmt->bind_param('s', $user_email);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $orders[] = $row;
    $stmt->close();

    $stmt2 = $db->prepare(
        "SELECT o.*, oa.assigned_at AS assigned_date
         FROM orders o
         INNER JOIN order_assignments oa ON o.id = oa.order_id
         WHERE oa.user_email = ? AND o.status = 'Completed'
         ORDER BY o.created_at DESC LIMIT 50"
    );
    $stmt2->bind_param('s', $user_email);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($row = $res2->fetch_assoc()) $completed[] = $row;
    $stmt2->close();
}

$db->close();

$pri_colors = [
    'Urgent' => ['bg'=>'#fee2e2','color'=>'#991b1b','bar'=>'#ef4444'],
    'High'   => ['bg'=>'#fef3c7','color'=>'#92400e','bar'=>'#f59e0b'],
    'Mid'    => ['bg'=>'#dbeafe','color'=>'#1e40af','bar'=>'#3b82f6'],
    'Low'    => ['bg'=>'#d1fae5','color'=>'#065f46','bar'=>'#10b981'],
];

function render_card(array $o, array $pri_colors, bool $is_completed = false): void {
    $wo_num   = 'WO-' . str_pad($o['id'], 6, '0', STR_PAD_LEFT);
    $pri      = $o['priority'] ?? 'Low';
    $pc       = $pri_colors[$pri] ?? $pri_colors['Low'];
    $building = htmlspecialchars($o['building'] ?? '');
    $room     = htmlspecialchars($o['room'] ?? '');
    $problem  = htmlspecialchars($o['problem_type'] ?? '');
    $desc     = htmlspecialchars(mb_strimwidth($o['description'] ?? '', 0, 80, '…'));
    $date_val = $o['assigned_date'] ?? $o['created_at'] ?? '';
    $date_fmt = $date_val ? date('M j', strtotime($date_val)) : '—';
    $opacity  = $is_completed ? 'opacity:.55' : '';
    ?>
    <a href="order_detail.php?wo=<?= urlencode($wo_num) ?>" class="card" style="<?= $opacity ?>">
        <div class="card-bar" style="background:<?= $pc['bar'] ?>"></div>
        <div class="card-body">
            <div class="card-top">
                <span class="wo-num"><?= $wo_num ?></span>
                <span class="pri-badge" style="background:<?= $pc['bg'] ?>;color:<?= $pc['color'] ?>"><?= htmlspecialchars($pri) ?></span>
            </div>
            <div class="card-location">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                <?= $building ?> &mdash; <?= $room ?>
            </div>
            <div class="card-problem"><?= $problem ?></div>
            <div class="card-desc"><?= $desc ?></div>
            <div class="card-footer">
                <?php
                $s_map = [
                    'Pending Approval' => ['class'=>'pending',    'label'=>'Pending'],
                    'Approved'         => ['class'=>'approved',   'label'=>'Approved'],
                    'In Progress'      => ['class'=>'inprogress', 'label'=>'In Progress'],
                    'Completed'        => ['class'=>'completed',  'label'=>'Completed'],
                    'Rejected'         => ['class'=>'rejected',   'label'=>'Rejected'],
                ];
                $s_key = $o['status'] ?? 'In Progress';
                $sd    = $s_map[$s_key] ?? ['class'=>'inprogress','label'=>$s_key];
                ?>
                <span class="card-status <?= $sd['class'] ?>"><?= $sd['label'] ?></span>
                <span class="card-date"><?= $date_fmt ?></span>
            </div>
        </div>
    </a>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="WCSC Work Orders">
<meta name="theme-color" content="#0B1F2E">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="icon-192.png">
<title>WCSC Work Orders</title>
<link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;600;700&family=Barlow+Condensed:wght@600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --cyan:#29b6d5;
    --cyan-dark:#1a9ab8;
    --cyan-light:#e6f7fb;
    --navy:#0B1F2E;
    --safe-top:env(safe-area-inset-top,0px);
    --safe-bottom:env(safe-area-inset-bottom,0px);
}
html{height:100%}
body{
    font-family:'Barlow',sans-serif;
    background:#f0f4f8;
    color:#1a1a2e;
    min-height:100%;
    padding-bottom:calc(16px + var(--safe-bottom));
}

/* ── TOP BAR ── */
.topbar{
    background:var(--navy);
    padding:calc(14px + var(--safe-top)) 18px 14px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    position:sticky;
    top:0;
    z-index:100;
}
.topbar-left{display:flex;align-items:center;gap:10px}
.topbar-icon{
    width:34px;height:34px;
    background:var(--cyan);
    border-radius:9px;
    display:flex;align-items:center;justify-content:center;
    flex-shrink:0;
}
.topbar-icon svg{display:block}
.topbar-title{
    font-family:'Barlow Condensed',sans-serif;
    font-size:17px;font-weight:700;
    color:#fff;letter-spacing:.02em;
    line-height:1.1;
}
.topbar-title span{color:var(--cyan)}
.topbar-right{display:flex;align-items:center;gap:10px}
.avatar{
    width:34px;height:34px;border-radius:50%;
    background:var(--cyan);
    display:flex;align-items:center;justify-content:center;
    font-weight:700;font-size:13px;color:#fff;
    overflow:hidden;flex-shrink:0;
    border:2px solid rgba(255,255,255,.2);
    cursor:pointer;
}
.avatar img{width:100%;height:100%;object-fit:cover}

/* ── PROFILE SHEET ── */
.sheet-overlay{
    display:none;position:fixed;inset:0;
    background:rgba(0,0,0,.45);z-index:200;
}
.sheet-overlay.open{display:block}
.sheet{
    position:fixed;bottom:0;left:0;right:0;
    background:#fff;
    border-radius:20px 20px 0 0;
    padding:0 0 calc(20px + var(--safe-bottom));
    z-index:201;
    transform:translateY(100%);
    transition:transform .28s cubic-bezier(.4,0,.2,1);
}
.sheet.open{transform:translateY(0)}
.sheet-handle{width:40px;height:4px;background:#e0e0e0;border-radius:2px;margin:12px auto 0}
.sheet-header{padding:16px 20px 12px;border-bottom:1px solid #f0f4f8}
.sheet-name{font-weight:700;font-size:16px;color:#1a1a2e}
.sheet-email{font-size:12px;color:#6b7a8d;margin-top:2px}
.sheet-role{
    display:inline-flex;align-items:center;gap:5px;
    font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px;
    margin-top:8px;background:#ede9fe;color:#5b21b6;
}
.sheet-item{
    display:flex;align-items:center;gap:12px;
    padding:15px 20px;font-size:15px;color:#1a1a2e;
    text-decoration:none;border:none;background:transparent;
    width:100%;text-align:left;font-family:'Barlow',sans-serif;
    cursor:pointer;
}
.sheet-item:active{background:#f8f9fa}
.sheet-item svg{flex-shrink:0;color:#6b7a8d}
.sheet-item.danger{color:#dc2626}
.sheet-item.danger svg{color:#dc2626}
.sheet-divider{border:none;border-top:1px solid #f0f4f8;margin:4px 0}

/* ── MAIN CONTENT ── */
.main{padding:16px 14px 8px}

/* ── GREETING ── */
.greeting{margin-bottom:14px}
.greeting h1{
    font-family:'Barlow Condensed',sans-serif;
    font-size:24px;font-weight:700;color:#1a1a2e;
}
.greeting p{font-size:13px;color:#6b7a8d;margin-top:3px}

/* ── STATS ROW ── */
.stats-row{
    display:grid;grid-template-columns:1fr 1fr;
    gap:10px;margin-bottom:18px;
}
.stat-card{
    background:#fff;border-radius:12px;
    border:1px solid #e8ecf0;
    padding:12px 14px;
}
.stat-num{
    font-family:'Barlow Condensed',sans-serif;
    font-size:28px;font-weight:700;color:var(--cyan);
}
.stat-label{font-size:11px;color:#6b7a8d;margin-top:2px}

/* ── SECTION LABEL ── */
.section-label{
    font-size:11px;font-weight:700;
    text-transform:uppercase;letter-spacing:.07em;
    color:#6b7a8d;margin-bottom:10px;
}

/* ── CARDS ── */
.card{
    display:flex;
    background:#fff;
    border-radius:14px;
    border:1px solid #e8ecf0;
    margin-bottom:10px;
    text-decoration:none;
    color:inherit;
    overflow:hidden;
    -webkit-tap-highlight-color:transparent;
    transition:box-shadow .15s,transform .1s;
    will-change:transform;
}
.card:active{transform:scale(.985);box-shadow:0 2px 8px rgba(0,0,0,.08)}
.card-bar{width:5px;flex-shrink:0}
.card-body{flex:1;padding:13px 14px}
.card-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}
.wo-num{
    font-family:'Barlow Condensed',sans-serif;
    font-size:15px;font-weight:700;color:var(--cyan);
}
.pri-badge{
    font-size:10px;font-weight:700;
    padding:3px 9px;border-radius:20px;
}
.card-location{
    font-size:13px;font-weight:600;color:#1a1a2e;
    display:flex;align-items:center;gap:5px;
    margin-bottom:3px;
}
.card-location svg{color:#6b7a8d;flex-shrink:0}
.card-problem{font-size:12px;color:#6b7a8d;margin-bottom:4px}
.card-desc{font-size:12px;color:#94a3b8;line-height:1.4;margin-bottom:8px}
.card-footer{display:flex;align-items:center;justify-content:space-between}
.card-status{
    font-size:10px;font-weight:700;
    padding:3px 9px;border-radius:20px;
}
.card-status.inprogress{background:#dbeafe;color:#1e40af}
.card-status.completed{background:#f0fdf4;color:#166534}
.card-status.pending{background:#fef3c7;color:#92400e}
.card-status.approved{background:#d1fae5;color:#065f46}
.card-status.rejected{background:#fee2e2;color:#991b1b}
.card-date{font-size:11px;color:#aab0bb}

/* ── EMPTY STATE ── */
.empty{
    text-align:center;padding:52px 20px 32px;color:#aab0bb;
}
.empty svg{margin-bottom:12px;opacity:.4}
.empty p{font-size:14px}

/* ── COMPLETED TOGGLE ── */
.toggle-completed{
    display:flex;align-items:center;justify-content:center;gap:8px;
    width:100%;padding:12px;
    background:transparent;border:1.5px dashed #d0d5dd;
    border-radius:12px;
    font-size:13px;font-weight:600;color:#6b7a8d;
    font-family:'Barlow',sans-serif;cursor:pointer;
    margin-top:4px;margin-bottom:10px;
    -webkit-tap-highlight-color:transparent;
}
.toggle-completed:active{background:#f8f9fa}

/* ── COMPLETED SECTION ── */
.completed-section{display:none}
.completed-section.open{display:block}

/* ── DESKTOP LINK ── */
.desktop-link{
    text-align:center;padding:20px 0 4px;
    font-size:12px;color:#aab0bb;
}
.desktop-link a{color:#aab0bb;text-decoration:underline}
</style>
</head>
<body>

<!-- TOP BAR -->
<header class="topbar">
    <div class="topbar-left">
        <div class="topbar-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 2a4 4 0 0 1 4 4c0 .93-.32 1.78-.84 2.46L20 14h-3v4a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1v-4H4L8.84 8.46A4 4 0 0 1 12 2z"/>
                <circle cx="12" cy="6" r="1.5" fill="#fff" stroke="none"/>
            </svg>
        </div>
        <div class="topbar-title">WCSC <span>Work Orders</span></div>
    </div>
    <div class="topbar-right">
        <div class="avatar" id="avatar-btn" role="button" aria-label="Profile">
            <?php if ($user_pic): ?>
                <img src="<?= htmlspecialchars($user_pic) ?>" alt="">
            <?php else:
                $parts    = explode(' ', trim($user_name));
                $initials = strtoupper(substr($parts[0],0,1) . (isset($parts[1]) ? substr($parts[1],0,1) : ''));
                echo htmlspecialchars($initials);
            endif; ?>
        </div>
    </div>
</header>

<!-- PROFILE SHEET -->
<div class="sheet-overlay" id="sheet-overlay"></div>
<div class="sheet" id="profile-sheet" role="dialog" aria-modal="true" aria-label="Profile">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
        <div class="sheet-name"><?= htmlspecialchars($user_name) ?></div>
        <div class="sheet-email"><?= htmlspecialchars($user_email) ?></div>
        <div class="sheet-role"><?= htmlspecialchars($role_label) ?></div>
    </div>
    <a href="../main.php?desktop=1" class="sheet-item">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
        Switch to Desktop Site
    </a>
    <hr class="sheet-divider">
    <a href="../logout.php" class="sheet-item danger">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sign Out
    </a>
</div>

<!-- MAIN -->
<main class="main">

    <div class="greeting">
        <h1>Hi, <?= htmlspecialchars($user_given ?: explode(' ', $user_name)[0]) ?></h1>
        <p><?= htmlspecialchars($role_label) ?> &mdash; <?= date('l, M j') ?></p>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-num"><?= count($orders) ?></div>
            <div class="stat-label">Active Orders</div>
        </div>
        <div class="stat-card">
            <?php $urgent = array_filter($orders, fn($o) => $o['priority'] === 'Urgent'); ?>
            <div class="stat-num" style="color:<?= count($urgent) > 0 ? '#ef4444' : 'var(--cyan)' ?>"><?= count($urgent) ?></div>
            <div class="stat-label">Urgent</div>
        </div>
    </div>

    <!-- Active orders -->
    <div class="section-label">
        <?php
        if ($user_role === 'MM')     echo 'All Active Maintenance Orders';
        elseif ($user_role === 'MT') echo 'All Active Tech Orders';
        elseif ($user_role === 'A')  echo 'All Active Work Orders';
        elseif ($user_role === 'BT') echo "My Building's Tech Orders";
        elseif ($user_role === 'U')  echo 'My Submitted Orders';
        else                          echo 'My Assigned Orders';
        ?>
    </div>

    <?php if (empty($orders)): ?>
    <div class="empty">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg>
        <p>No active orders right now.</p>
    </div>
    <?php else: ?>
        <?php foreach ($orders as $o): render_card($o, $pri_colors, false); endforeach; ?>
    <?php endif; ?>

    <!-- Completed toggle -->
    <?php if (!empty($completed)): ?>
    <button class="toggle-completed" id="toggle-completed-btn" aria-expanded="false">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" id="toggle-icon"><polyline points="6 9 12 15 18 9"/></svg>
        Show <?= count($completed) ?> <?= $user_role === 'U' ? 'Closed' : 'Completed' ?> Order<?= count($completed) !== 1 ? 's' : '' ?>
    </button>

    <div class="completed-section" id="completed-section">
        <div class="section-label"><?= $user_role === 'U' ? 'Closed' : 'Completed' ?></div>
        <?php foreach ($completed as $o): render_card($o, $pri_colors, true); endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="desktop-link">
        <a href="../main.php?desktop=1">Switch to full desktop site</a>
    </div>

</main>

<script>
// Profile sheet
const avatarBtn     = document.getElementById('avatar-btn');
const sheetOverlay  = document.getElementById('sheet-overlay');
const profileSheet  = document.getElementById('profile-sheet');

function openSheet() {
    profileSheet.classList.add('open');
    sheetOverlay.classList.add('open');
}
function closeSheet() {
    profileSheet.classList.remove('open');
    sheetOverlay.classList.remove('open');
}
avatarBtn.addEventListener('click', openSheet);
sheetOverlay.addEventListener('click', closeSheet);

// Swipe down to close sheet
let _sy = 0;
profileSheet.addEventListener('touchstart', function(e){ _sy = e.touches[0].clientY; }, {passive:true});
profileSheet.addEventListener('touchend', function(e){
    if (e.changedTouches[0].clientY - _sy > 60) closeSheet();
}, {passive:true});

// Completed toggle
const toggleBtn  = document.getElementById('toggle-completed-btn');
const toggleIcon = document.getElementById('toggle-icon');
const completedSection = document.getElementById('completed-section');
if (toggleBtn) {
    toggleBtn.addEventListener('click', function() {
        const open = completedSection.classList.toggle('open');
        toggleBtn.setAttribute('aria-expanded', open);
        toggleIcon.style.transform = open ? 'rotate(180deg)' : '';
        if (open) {
            toggleBtn.innerHTML = toggleBtn.innerHTML.replace('Show', 'Hide');
        } else {
            toggleBtn.innerHTML = toggleBtn.innerHTML.replace('Hide', 'Show');
        }
    });
}
</script>
</body>
</html>
