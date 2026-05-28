<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['google_user'])) {
    header('Location: index.php');
    exit;
}

$user = $_SESSION['google_user'];
$user_email = $user['email'];
$user_name  = $user['name'] ?? 'User';
$user_pic   = $user['picture'] ?? '';

// Derive initials from name
$name_parts = explode(' ', trim($user_name));
$initials   = strtoupper(substr($name_parts[0], 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : ''));

// Role from session (set during login by DB lookup)
$user_role     = $_SESSION['user_role']     ?? 'U';
$user_building = $_SESSION['user_building'] ?? null;

// Role display label and badge color
$role_labels = ['A' => 'Admin', 'M' => 'Manager', 'BA' => 'Building Admin', 'U' => 'User'];
$role_label  = $role_labels[$user_role] ?? 'User';
$role_colors = [
    'A'  => 'background:#f3e8ff;color:#6b21a8',
    'M'  => 'background:#fef3c7;color:#92400e',
    'BA' => 'background:#e6f7fb;color:#1a9ab8',
    'U'  => 'background:#f1f5f9;color:#475569',
];
$role_style = $role_colors[$user_role] ?? $role_colors['U'];
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
body{font-family:'Barlow',sans-serif;background:#f0f4f8;color:#1a1a2e;min-height:100vh}
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
.nav-logo{width:36px;height:36px;background:var(--cyan);border-radius:9px;display:flex;align-items:center;justify-content:center}
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
.main{max-width:920px;margin:0 auto;padding:32px 20px 80px}

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

/* ── WORK ORDERS TABLE ── */
.wo-table-wrap{background:#fff;border:1px solid #e8ecf0;border-radius:12px;overflow:hidden}
.wo-table{width:100%;border-collapse:collapse;font-size:13px}
.wo-table th{padding:11px 16px;text-align:left;font-weight:700;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#6b7a8d;background:#f8f9fa;border-bottom:1px solid #e8ecf0}
.wo-table td{padding:14px 16px;border-bottom:1px solid #f0f4f8;vertical-align:middle}
.wo-table tr:last-child td{border-bottom:none}
.wo-table tr:hover td{background:#f8f9fa}
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
    padding:40px 16px;
    overflow-y:auto;
}
.modal-overlay.open{display:flex}
.modal{
    background:#fff;
    border-radius:16px;
    width:100%;
    max-width:640px;
    margin:auto;
    box-shadow:0 20px 60px rgba(0,0,0,0.15);
    overflow:hidden;
}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:22px 26px 18px;border-bottom:1px solid #f0f4f8}
.modal-header-left{display:flex;align-items:center;gap:14px}
.modal-type-icon{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:20px}
.modal-type-icon.maint{background:#fef3c7;color:#b45309}
.modal-type-icon.tech{background:#dbeafe;color:#1d4ed8}
.modal-title{font-family:'Barlow Condensed',sans-serif;font-size:20px;font-weight:700;color:#1a1a2e}
.modal-subtitle{font-size:12px;color:#6b7a8d;margin-top:2px}
.close-btn{width:34px;height:34px;border-radius:8px;border:1px solid #e8ecf0;background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#6b7a8d;flex-shrink:0}
.close-btn:hover{background:#f8f9fa}
.modal-body{padding:22px 26px}
.modal-footer{padding:16px 26px;border-top:1px solid #f0f4f8;display:flex;align-items:center;justify-content:flex-end;gap:10px;background:#fafafa}

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

/* ── FOOTER ── */
.site-footer{
    background:#0B1F2E;
    border-top:1px solid rgba(27,188,212,0.12);
    padding:28px 28px 24px;
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
        <div class="nav-logo"><i class="ti ti-tools" aria-hidden="true"></i></div>
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
            <button class="filter-tab" data-filter="active">Active</button>
            <button class="filter-tab" data-filter="completed">Completed</button>
        </div>
    </div>

    <div class="wo-table-wrap">
        <table class="wo-table" id="wo-table">
            <thead>
                <tr>
                    <th>WO #</th>
                    <th>Type</th>
                    <th>Building</th>
                    <th>Description</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody id="wo-tbody">
                <!-- Populated from DB — placeholder rows below for layout preview -->
                <tr class="wo-row" data-status="active">
                    <td><span class="wo-id">WO-100001</span></td>
                    <td><span class="badge badge-maint">Maintenance</span></td>
                    <td>CHS</td>
                    <td class="wo-desc">HVAC unit making loud grinding noise</td>
                    <td><span class="pri pri-high">High</span></td>
                    <td><span class="badge badge-pending">Pending Approval</span></td>
                    <td style="color:#6b7a8d;font-size:12px;white-space:nowrap">May 10, 2025</td>
                </tr>
                <tr class="wo-row" data-status="completed">
                    <td><span class="wo-id">WO-100002</span></td>
                    <td><span class="badge badge-tech">Technology</span></td>
                    <td>CHS</td>
                    <td class="wo-desc">Projector bulb replacement needed</td>
                    <td><span class="pri pri-low">Low</span></td>
                    <td><span class="badge badge-completed">Completed</span></td>
                    <td style="color:#6b7a8d;font-size:12px;white-space:nowrap">May 5, 2025</td>
                </tr>
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

                <div class="form-row">

                    <div class="form-group">
                        <label class="form-label">Your email</label>
                        <input type="email" value="<?= htmlspecialchars($user_email) ?>" readonly>
                    </div>

                    <div class="form-group">
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

                    <div class="form-group full">
                        <label class="form-label" for="f-room">Room / Location *</label>
                        <input type="text" id="f-room" name="room" placeholder="e.g. Room 214, Main Office, Gymnasium" required>
                    </div>

                </div>

                <div class="form-spacer"></div>

                <div class="form-group">
                    <label class="form-label">Priority *</label>
                    <div class="priority-group">
                        <button type="button" class="pri-pill" data-p="Low">Low</button>
                        <button type="button" class="pri-pill" data-p="Mid">Mid</button>
                        <button type="button" class="pri-pill" data-p="High">High</button>
                        <button type="button" class="pri-pill" data-p="Urgent">Urgent</button>
                    </div>
                    <input type="hidden" id="f-priority" name="priority" value="">
                </div>

                <div class="form-spacer"></div>

                <div class="form-group">
                    <label class="form-label" for="f-desc">Description *</label>
                    <textarea id="f-desc" name="description" placeholder="Describe the issue in detail — what needs to be done, where exactly, and any relevant context…" required></textarea>
                </div>

                <div class="form-spacer"></div>

                <div class="form-group">
                    <label class="form-label">Photo (optional)</label>
                    <div class="upload-zone" id="upload-zone">
                        <i class="ti ti-photo-up" id="upload-icon" aria-hidden="true"></i>
                        <span id="upload-label">Click or drag a photo here</span>
                        <small>JPG, PNG or HEIC · Max 10 MB</small>
                    </div>
                    <input type="file" id="f-photo" name="photo" accept="image/*" style="display:none">
                </div>

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

document.getElementById('submit-wo').addEventListener('click', function () {
    const building = document.getElementById('f-building').value;
    const room     = document.getElementById('f-room').value.trim();
    const priority = document.getElementById('f-priority').value;
    const desc     = document.getElementById('f-desc').value.trim();
    if (!building || !room || !priority || !desc) {
        alert('Please fill in all required fields: building, room/location, priority, and description.');
        return;
    }
    const mockWONum = 'WO-1000' + (Math.floor(Math.random() * 90) + 10);
    document.getElementById('success-wo-num').textContent = mockWONum;
    closeModal();
    document.getElementById('success-overlay').classList.add('open');
});

document.getElementById('success-back').addEventListener('click', function () { document.getElementById('success-overlay').classList.remove('open'); });
document.getElementById('success-new').addEventListener('click', function () {
    document.getElementById('success-overlay').classList.remove('open');
    openModal(document.getElementById('f-type').value || 'Maintenance');
});

document.querySelectorAll('.filter-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        const filter = this.dataset.filter;
        document.querySelectorAll('.wo-row').forEach(function (row) {
            const status = row.dataset.status;
            if (filter === 'all')            row.style.display = '';
            else if (filter === 'completed') row.style.display = (status === 'completed') ? '' : 'none';
            else                             row.style.display = (status === 'active')    ? '' : 'none';
        });
    });
});
</script>

</body>
</html>