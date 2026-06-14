<?php
// nav.php
// Requires: $user_name, $user_email, $user_pic, $initials, $user_role, $user_building
// Optional: $current_page ('main', 'manage') for active nav highlighting

if (!function_exists('human_time_diff')) {
    function human_time_diff(string $datetime): string {
        $diff = time() - strtotime($datetime);
        if ($diff < 3600)  return round($diff/60) . 'm ago';
        if ($diff < 86400) return round($diff/3600) . 'h ago';
        return round($diff/86400) . 'd ago';
    }
}

// ── Self-contained notification data ─────────────────────────
$_nav_notif_count = 0;
$_nav_rows = [];
$_nav_role = $user_role ?? '';
$_rpt_staff_json = '[]';

if (in_array($_nav_role, ['BT','BP','MT','MM','A','MW','BC','BM'])) {
    $_ndb = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $_ndb->set_charset('utf8mb4');

    if ($_nav_role === 'BT') {
        $_bt = array_filter(array_map('trim', explode(',', $user_building ?? '')));
        if ($_bt) {
            $_ph = implode(',', array_fill(0, count($_bt), '?'));
            $_st = $_ndb->prepare("SELECT id, building, problem_type, created_at FROM orders WHERE current_handler='BT' AND type='Technology' AND building IN ($_ph) ORDER BY created_at DESC LIMIT 9");
            $_st->bind_param(str_repeat('s', count($_bt)), ...$_bt);
            $_st->execute();
            $_r = $_st->get_result();
            while ($_row = $_r->fetch_assoc()) $_nav_rows[] = $_row;
            $_st->close();
        }
    } elseif ($_nav_role === 'BP') {
        $_st = $_ndb->prepare("SELECT id, building, problem_type, created_at FROM orders WHERE current_handler='BP' AND building=? ORDER BY created_at DESC LIMIT 9");
        $_st->bind_param('s', $user_building);
        $_st->execute();
        $_r = $_st->get_result();
        while ($_row = $_r->fetch_assoc()) $_nav_rows[] = $_row;
        $_st->close();
    } elseif (in_array($_nav_role, ['MW','BC','BM'])) {
        $_st = $_ndb->prepare(
            "SELECT o.id, o.building, o.problem_type, o.created_at FROM orders o
             INNER JOIN order_assignments oa ON o.id = oa.order_id
             WHERE oa.user_email = ? AND o.current_handler = 'worker'
             ORDER BY o.created_at DESC LIMIT 9"
        );
        $_st->bind_param('s', $user_email);
        $_st->execute();
        $_r = $_st->get_result();
        while ($_row = $_r->fetch_assoc()) $_nav_rows[] = $_row;
        $_st->close();
    } elseif ($_nav_role === 'MT') {
        $_r = $_ndb->query("SELECT id, building, problem_type, created_at FROM orders WHERE current_handler='MT' AND type='Technology' ORDER BY created_at DESC LIMIT 9");
        if ($_r) while ($_row = $_r->fetch_assoc()) $_nav_rows[] = $_row;
    } elseif ($_nav_role === 'MM') {
        $_r = $_ndb->query("SELECT id, building, problem_type, created_at FROM orders WHERE current_handler='MM' AND type='Maintenance' ORDER BY created_at DESC LIMIT 9");
        if ($_r) while ($_row = $_r->fetch_assoc()) $_nav_rows[] = $_row;
    } elseif ($_nav_role === 'A') {
        $_alerts = [];

        // Open > 30 days (any stage, not yet closed)
        $_r = $_ndb->query(
            "SELECT id, building, problem_type, created_at, current_handler,
                    DATEDIFF(NOW(), created_at) AS days, NULL AS extra_name, 'overdue' AS alert_type
             FROM orders
             WHERE status NOT IN ('Completed','Rejected')
               AND DATEDIFF(NOW(), created_at) >= 30
             ORDER BY days DESC LIMIT 9"
        );
        if ($_r) while ($_row = $_r->fetch_assoc()) $_alerts[$_row['id']] = $_row;

        // Worker assigned > 14 days, still In Progress — call out by name
        $_r = $_ndb->query(
            "SELECT o.id, o.building, o.problem_type, o.created_at, o.current_handler,
                    DATEDIFF(NOW(), o.created_at) AS days, oa.user_name AS extra_name, 'worker_overdue' AS alert_type
             FROM orders o
             INNER JOIN order_assignments oa ON o.id = oa.order_id
             WHERE o.status = 'In Progress' AND o.current_handler = 'worker'
               AND DATEDIFF(NOW(), o.created_at) >= 14
             ORDER BY days DESC LIMIT 9"
        );
        if ($_r) while ($_row = $_r->fetch_assoc()) {
            if (!isset($_alerts[$_row['id']]) || $_alerts[$_row['id']]['alert_type'] === 'overdue')
                $_alerts[$_row['id']] = $_row;
        }

        // Stuck at role handler > 14 days — look up the handler's name(s)
        $_r = $_ndb->query(
            "SELECT o.id, o.building, o.problem_type, o.created_at, o.current_handler,
                    DATEDIFF(NOW(), o.created_at) AS days,
                    GROUP_CONCAT(CONCAT(u.first_name,' ',u.last_name) ORDER BY u.last_name SEPARATOR ', ') AS extra_name,
                    'handler_stuck' AS alert_type
             FROM orders o
             LEFT JOIN users u ON u.active = 1 AND (
                 (o.current_handler = 'BT' AND u.role = 'BT' AND FIND_IN_SET(o.building COLLATE utf8mb4_unicode_ci, u.building COLLATE utf8mb4_unicode_ci))
                 OR (o.current_handler = 'BP' AND u.role = 'BP' AND u.building COLLATE utf8mb4_unicode_ci = o.building COLLATE utf8mb4_unicode_ci)
                 OR (o.current_handler = 'MT' AND u.role = 'MT')
                 OR (o.current_handler = 'MM' AND u.role = 'MM')
             )
             WHERE o.status NOT IN ('Completed','Rejected')
               AND o.current_handler IN ('BT','BP','MT','MM')
               AND DATEDIFF(NOW(), o.created_at) >= 14
             GROUP BY o.id, o.building, o.problem_type, o.created_at, o.current_handler
             ORDER BY days DESC LIMIT 9"
        );
        if ($_r) while ($_row = $_r->fetch_assoc()) {
            if (!isset($_alerts[$_row['id']]) || $_alerts[$_row['id']]['alert_type'] === 'overdue')
                $_alerts[$_row['id']] = $_row;
        }

        // MM / MT queues stuck > 14 days without being assigned
        $_r = $_ndb->query(
            "SELECT id, building, problem_type, created_at, current_handler,
                    DATEDIFF(NOW(), created_at) AS days, NULL AS extra_name, 'pending_queue' AS alert_type
             FROM orders
             WHERE current_handler IN ('MT','MM')
               AND status NOT IN ('Completed','Rejected')
               AND DATEDIFF(NOW(), created_at) >= 14
             ORDER BY created_at ASC"
        );
        if ($_r) while ($_row = $_r->fetch_assoc()) {
            if (!isset($_alerts[$_row['id']]))
                $_alerts[$_row['id']] = $_row;
        }

        usort($_alerts, function($a, $b) { return (int)$b['days'] - (int)$a['days']; });
        $_nav_rows = array_values(array_slice($_alerts, 0, 9));
        unset($_alerts);
    }

    $_nav_notif_count = count($_nav_rows);

    // Staff list for Staff Member Report accordion
    if (in_array($_nav_role, ['A', 'MT', 'MM'])) {
        $_rpt_roles = $_nav_role === 'MM' ? ['MW','BC','BM']
                    : ($_nav_role === 'MT' ? ['BT']
                    : ['MW','BT','BC','BM']);
        $_rpt_ph = implode(',', array_fill(0, count($_rpt_roles), '?'));
        $_rpt_st = $_ndb->prepare(
            "SELECT first_name, last_name, email, role FROM users
             WHERE active=1 AND role IN ({$_rpt_ph})
             ORDER BY FIELD(role,'MW','BT','BC','BM'), last_name, first_name"
        );
        $_rpt_st->bind_param(str_repeat('s', count($_rpt_roles)), ...$_rpt_roles);
        $_rpt_st->execute();
        $_rpt_res = $_rpt_st->get_result();
        $_rpt_staff = [];
        while ($_rpt_row = $_rpt_res->fetch_assoc()) $_rpt_staff[] = $_rpt_row;
        $_rpt_st->close();
        $_rpt_staff_json = json_encode($_rpt_staff, JSON_HEX_TAG | JSON_HEX_APOS);
        unset($_rpt_roles, $_rpt_ph, $_rpt_st, $_rpt_res, $_rpt_staff, $_rpt_row);
    }

    $_ndb->close();
    unset($_ndb, $_nav_role, $_bt, $_ph, $_st, $_r, $_row);
}

// Build notification dropdown HTML
ob_start();
$_has_notifs = !empty($_nav_rows);
$_is_admin   = (($user_role ?? '') === 'A');
$_hl = ['BT' => 'Building Tech', 'BP' => 'Principal', 'MT' => 'Tech Manager', 'MM' => 'Maint. Manager'];
?>
<div class="notif-dd-header"><?php
    if (!$_has_notifs) echo $_is_admin ? 'No pending or overdue items' : 'No pending work orders';
    else               echo $_is_admin ? 'Alerts &amp; Pending Queue'  : 'Pending action';
?></div>
<?php if (!$_has_notifs): ?>
<div class="notif-empty"><?= $_is_admin ? 'No pending or overdue work orders.' : "You're all caught up." ?></div>
<?php else: foreach (array_slice($_nav_rows, 0, 8) as $_no):
    $_no_wo = 'WO-' . str_pad($_no['id'], 6, '0', STR_PAD_LEFT);
    if ($_is_admin && isset($_no['alert_type'])) {
        $_d = (int)$_no['days'];
        switch ($_no['alert_type']) {
            case 'worker_overdue':
                $_meta = htmlspecialchars($_no['extra_name'] ?? 'Worker') . " — assigned {$_d}d ago, not complete · " . htmlspecialchars($_no['building']);
                break;
            case 'handler_stuck':
                $_who = $_no['extra_name'] ?: ($_hl[$_no['current_handler']] ?? $_no['current_handler']);
                $_meta = 'Waiting on ' . htmlspecialchars($_who) . " — {$_d}d · " . htmlspecialchars($_no['building']) . ' · ' . htmlspecialchars($_no['problem_type']);
                break;
            case 'pending_queue':
                $_stage = $_hl[$_no['current_handler']] ?? $_no['current_handler'];
                $_meta = 'Awaiting assignment · ' . htmlspecialchars($_no['building']) . ' · ' . htmlspecialchars($_no['problem_type']) . " · at {$_stage}";
                break;
            default: // overdue
                $_stage = $_hl[$_no['current_handler']] ?? ($_no['current_handler'] === 'worker' ? 'Worker' : '?');
                $_meta = "Open {$_d} days · " . htmlspecialchars($_no['building']) . ' · ' . htmlspecialchars($_no['problem_type']) . " · at {$_stage}";
        }
    } else {
        $_meta = htmlspecialchars($_no['building']) . ' · ' . htmlspecialchars($_no['problem_type']) . ' · ' . human_time_diff($_no['created_at']);
    }
?>
<div class="notif-item" data-wo="<?= htmlspecialchars($_no_wo) ?>">
    <span class="notif-item-wo"><?= $_no_wo ?></span>
    <span class="notif-item-meta"><?= $_meta ?></span>
</div>
<?php endforeach; endif;
$_nav_notif_html = ob_get_clean();
unset($_has_notifs, $_is_admin, $_hl, $_nav_rows, $_no, $_no_wo, $_meta, $_d, $_who, $_stage);

$_nav_role_labels = [
    'A'  => 'Administrator',
    'MT' => 'Technology Manager',
    'MM' => 'Maintenance Manager',
    'BP' => 'Building Principal',
    'BT' => 'Building Technician',
    'BC' => 'Building Custodian',
    'BM' => 'Building Maintenance',
    'MW' => 'Maintenance Worker',
    'U'  => 'User',
];
$_nav_role_label = $_nav_role_labels[$user_role ?? 'U'] ?? 'User';
$_nav_show_reports = in_array($user_role ?? '', ['A', 'MT', 'MM']);
?>
<style>
/* ── SHARED BASE (injected by nav so every page gets it) ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Barlow',sans-serif;background:#f0f4f8;color:#1a1a2e;min-height:100vh;display:flex;flex-direction:column}
:root{--cyan:#29b6d5;--cyan-dark:#1a9ab8;--cyan-light:#e6f7fb;--cyan-muted:#c5eaf3;--navy:#0B1F2E}
.main{max-width:1300px;width:100%;margin:0 auto;padding:32px 24px 48px;flex:1}

/* ── NAV ── */
.nav{background:#fff;border-bottom:1px solid #e8ecf0;padding:0 28px;height:58px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100}
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
.profile-dropdown{position:absolute;top:46px;right:0;width:248px;background:#fff;border:1px solid #e8ecf0;border-radius:12px;padding:16px;z-index:200;display:none;box-shadow:0 8px 24px rgba(0,0,0,0.08)}
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
.active-page{background:var(--cyan-light)!important;color:var(--cyan-dark)!important}
.nav-links{display:flex;align-items:center;gap:4px;margin-left:20px}
.nav-link{display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;font-size:13px;font-weight:600;color:#6b7a8d;text-decoration:none;transition:all .12s;border:none;background:transparent;cursor:pointer;font-family:'Barlow',sans-serif}
.nav-link:hover{background:#f0f4f8;color:#1a1a2e}
.nav-link.active{background:var(--cyan-light);color:var(--cyan-dark)}

/* ── REPORTS DRAWER ── */
.reports-overlay{
    position:fixed;inset:0;
    background:rgba(11,31,46,0.45);
    z-index:400;opacity:0;
    pointer-events:none;
    transition:opacity .25s ease;
}
.reports-overlay.open{opacity:1;pointer-events:all}

.reports-drawer{
    position:fixed;top:0;right:0;
    width:440px;height:100vh;
    background:#fff;z-index:401;
    display:flex;flex-direction:column;
    box-shadow:-8px 0 40px rgba(0,0,0,0.13);
    transform:translateX(100%);
    transition:transform .28s cubic-bezier(.4,0,.2,1);
    overflow:hidden;
}
.reports-drawer.open{transform:translateX(0)}

.reports-drawer-header{
    display:flex;align-items:center;justify-content:space-between;
    padding:20px 24px 16px;
    border-bottom:1px solid #f0f4f8;flex-shrink:0;
}
.reports-drawer-title{display:flex;align-items:center;gap:10px}
.reports-drawer-title-icon{
    width:36px;height:36px;border-radius:9px;
    background:var(--cyan-light);
    display:flex;align-items:center;justify-content:center;
    color:var(--cyan-dark);font-size:18px;flex-shrink:0;
}
.reports-drawer-title h2{
    font-family:'Barlow Condensed',sans-serif;
    font-size:19px;font-weight:700;color:#1a1a2e;letter-spacing:.01em;
}
.reports-drawer-title p{font-size:11px;color:#6b7a8d;margin-top:1px}
.reports-drawer-close{
    width:32px;height:32px;border-radius:8px;
    border:1px solid #e8ecf0;background:transparent;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    color:#6b7a8d;flex-shrink:0;
}
.reports-drawer-close:hover{background:#f8f9fa;color:#1a1a2e}

.reports-drawer-body{
    flex:1;overflow-y:auto;
    padding:20px 24px 28px;
    display:flex;flex-direction:column;gap:16px;
}

/* ── Report card grid ── */
.rpt-card-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.rpt-card{
    display:flex;flex-direction:column;gap:6px;
    padding:14px;border:2px solid #e8ecf0;border-radius:12px;
    cursor:pointer;transition:all .15s;background:#fff;user-select:none;
}
.rpt-card:hover{border-color:var(--cyan-muted);background:var(--cyan-light)}
.rpt-card.selected{border-color:var(--cyan);background:var(--cyan-light)}
.rpt-card-full{grid-column:1/-1;flex-direction:row!important;align-items:center;gap:14px}
.rpt-card-full .rpt-card-text{flex:1}
.rpt-card-icon{
    width:34px;height:34px;border-radius:8px;background:#f0f4f8;
    display:flex;align-items:center;justify-content:center;
    color:#6b7a8d;font-size:18px;flex-shrink:0;transition:all .15s;
}
.rpt-card:hover .rpt-card-icon,.rpt-card.selected .rpt-card-icon{background:var(--cyan);color:#fff}
.rpt-card-name{font-size:12px;font-weight:700;color:#1a1a2e;line-height:1.3}
.rpt-card-hint{font-size:10.5px;color:#8a96a3;line-height:1.4;margin-top:1px}

/* ── Options panel ── */
.rpt-options{
    background:#f8f9fa;border:1px solid #f0f4f8;
    border-radius:12px;padding:16px;
    display:flex;flex-direction:column;gap:14px;
}
.rpt-opt-label{
    font-size:10px;font-weight:700;
    text-transform:uppercase;letter-spacing:.09em;
    color:#aab0bb;margin-bottom:6px;
}
.rpt-pills{display:flex;gap:6px;flex-wrap:wrap}
.rpt-pill{
    padding:6px 13px;border-radius:20px;
    border:1.5px solid #d0d5dd;background:#fff;
    font-size:12px;font-weight:600;font-family:'Barlow',sans-serif;
    color:#6b7a8d;cursor:pointer;transition:all .12s;
}
.rpt-pill:hover{border-color:var(--cyan);color:var(--cyan-dark)}
.rpt-pill.active{border-color:var(--cyan);background:var(--cyan);color:#fff}
.rpt-input{
    width:100%;border:1px solid #d0d5dd;border-radius:8px;
    padding:8px 11px;font-size:13px;font-family:'Barlow',sans-serif;
    color:#1a1a2e;background:#fff;transition:border-color .12s;
}
.rpt-input:focus{outline:none;border-color:var(--cyan);box-shadow:0 0 0 3px rgba(41,182,213,.10)}
select.rpt-input{
    appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 10px center;padding-right:32px;
}
.rpt-person-results{
    border:1px solid #e8ecf0;border-radius:8px;background:#fff;
    max-height:160px;overflow-y:auto;display:none;margin-top:4px;
}

.reports-drawer-footer{
    padding:16px 24px;border-top:1px solid #f0f4f8;
    flex-shrink:0;display:flex;gap:10px;
}
.rpt-btn-generate{
    flex:1;padding:11px 20px;border-radius:10px;border:none;
    background:var(--cyan);color:#fff;
    font-size:14px;font-weight:700;font-family:'Barlow',sans-serif;
    cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;
    transition:background .12s;
}
.rpt-btn-generate:hover:not(:disabled){background:var(--cyan-dark)}
.rpt-btn-generate:disabled{opacity:.45;cursor:not-allowed}
.rpt-btn-reset{
    padding:11px 16px;border-radius:10px;
    border:1px solid #d0d5dd;background:transparent;
    color:#6b7a8d;font-size:13px;font-weight:700;
    font-family:'Barlow',sans-serif;cursor:pointer;transition:all .12s;
}
.rpt-btn-reset:hover{background:#f8f9fa;color:#1a1a2e}
@keyframes rpt-spin{to{transform:rotate(360deg)}}
.rpt-spinning{animation:rpt-spin .8s linear infinite;display:inline-block}

/* ── Staff accordion ── */
.rpt-acc-wrap{border:1px solid #e8ecf0;border-radius:8px;overflow:hidden;margin-bottom:4px}
.rpt-acc-hdr{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:#f8f9fa;cursor:pointer;user-select:none;font-size:12px;font-weight:700;color:#1a1a2e;border:none;width:100%;text-align:left;font-family:'Barlow',sans-serif}
.rpt-acc-hdr:hover{background:#f0f4f8}
.rpt-acc-hdr-left{display:flex;align-items:center;gap:6px}
.rpt-acc-chevron{font-size:9px;color:#aab0bb;transition:transform .15s;display:inline-block}
.rpt-acc-chevron.open{transform:rotate(90deg)}
.rpt-acc-count{font-size:10px;font-weight:600;color:#aab0bb;background:#e8ecf0;padding:1px 7px;border-radius:20px}
.rpt-acc-body{display:none;border-top:1px solid #e8ecf0}
.rpt-acc-body.open{display:block}
.rpt-worker-row{display:flex;align-items:center;gap:8px;padding:6px 12px;cursor:pointer;font-size:12px;color:#1a1a2e;border-bottom:1px solid #f8f9fa;transition:background .1s;width:100%;background:transparent;border:none;text-align:left;font-family:'Barlow',sans-serif}
.rpt-worker-row:last-child{border-bottom:none}
.rpt-worker-row:hover{background:#f0f8fb}
.rpt-worker-row input[type=checkbox]{accent-color:var(--cyan);cursor:pointer;flex-shrink:0;width:13px;height:13px}
.rpt-sel-chip{display:inline-flex;align-items:center;gap:4px;background:var(--cyan-light);color:var(--cyan-dark);border:1px solid var(--cyan-muted);border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600;margin:2px;cursor:pointer;font-family:'Barlow',sans-serif}
.rpt-sel-chip:hover{background:var(--cyan);color:#fff}

/* Reports nav button */
.reports-nav-btn{
    display:flex;align-items:center;gap:6px;
    padding:6px 14px;border-radius:8px;border:1px solid #e8ecf0;
    background:transparent;color:#6b7a8d;
    font-size:13px;font-weight:600;font-family:'Barlow',sans-serif;
    cursor:pointer;transition:all .12s;white-space:nowrap;
}
.reports-nav-btn:hover{background:var(--cyan-light);color:var(--cyan-dark);border-color:var(--cyan-muted)}
.reports-nav-btn i{font-size:15px}
</style>

<nav class="nav">
    <div class="nav-left">
        <a href="main.php" class="nav-logo" aria-label="Home" title="Home">
            <i class="ti ti-home" aria-hidden="true"></i>
        </a>
        <div class="nav-title">Warrick County <span>Work Order System</span></div>
    </div>
    <div class="nav-right">

        <?php if ($_nav_show_reports): ?>
        <button class="reports-nav-btn" id="reports-btn" aria-label="Reports">
            <i class="ti ti-chart-bar" aria-hidden="true"></i>
            Reports
        </button>
        <?php endif; ?>

        <button class="notif-btn" id="notif-btn" aria-label="Notifications" style="position:relative">
            <i class="ti ti-bell" aria-hidden="true"></i>
            <?php if ($_nav_notif_count > 0): ?>
            <span class="notif-badge"><?= $_nav_notif_count > 9 ? '9+' : $_nav_notif_count ?></span>
            <?php endif; ?>
        </button>
        <div class="notif-dropdown" id="notif-dd">
            <?= $_nav_notif_html ?>
        </div>

        <div class="avatar" id="avatar-btn" aria-label="Profile menu" role="button" tabindex="0">
            <?php if ($user_pic): ?>
                <img src="<?= htmlspecialchars($user_pic) ?>" alt="Profile photo">
            <?php else: ?>
                <?= htmlspecialchars($initials) ?>
            <?php endif; ?>
        </div>

        <div class="profile-dropdown" id="profile-dd" role="menu">
            <div class="pd-header">
                <div class="pd-avatar">
                    <?php if ($user_pic): ?>
                        <img src="<?= htmlspecialchars($user_pic) ?>" alt="">
                    <?php else: ?>
                        <?= htmlspecialchars($initials) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="pd-name"><?= htmlspecialchars($user_name) ?></div>
                    <div class="pd-email"><?= htmlspecialchars($user_email) ?></div>
                    <div class="pd-role-badge" style="<?= [
                        'A'  => 'background:#f3e8ff;color:#6b21a8',
                        'MT' => 'background:#fef3c7;color:#92400e',
                        'MM' => 'background:#fce7f3;color:#9d174d',
                        'BP' => 'background:#e6f7fb;color:#1a9ab8',
                        'BT' => 'background:#dcfce7;color:#166534',
                        'BC' => 'background:#fef9c3;color:#854d0e',
                        'BM' => 'background:#ffe4e6;color:#9f1239',
                        'MW' => 'background:#ede9fe;color:#5b21b6',
                        'U'  => 'background:#f1f5f9;color:#475569',
                    ][$user_role ?? 'U'] ?? 'background:#f1f5f9;color:#475569' ?>;display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px;margin-top:8px">
                        <i class="ti ti-shield-check" aria-hidden="true"></i>
                        <?= htmlspecialchars($_nav_role_label) ?>
                    </div>
                </div>
            </div>
            <hr class="pd-divider">
            <a href="main.php"   class="pd-item <?= ($current_page??'')==='main'   ? 'active-page':'' ?>">
                <i class="ti ti-home" aria-hidden="true"></i> Dashboard
            </a>
            <?php if (($user_role ?? '') === 'A'): ?>
            <a href="manage.php" class="pd-item <?= ($current_page??'')==='manage' ? 'active-page':'' ?>">
                <i class="ti ti-users" aria-hidden="true"></i> Manage Users
            </a>
            <?php endif; ?>
            <hr class="pd-divider">
            <a href="logout.php" class="pd-item danger">
                <i class="ti ti-logout" aria-hidden="true"></i> Sign out
            </a>
        </div>
    </div>
</nav>

<?php if ($_nav_show_reports): ?>

<!-- ── REPORTS OVERLAY ── -->
<div class="reports-overlay" id="reports-overlay"></div>

<!-- ── REPORTS DRAWER ── -->
<div class="reports-drawer" id="reports-drawer" aria-label="Reports panel">

    <div class="reports-drawer-header">
        <div class="reports-drawer-title">
            <div class="reports-drawer-title-icon">
                <i class="ti ti-chart-bar" aria-hidden="true"></i>
            </div>
            <div>
                <h2>Reports</h2>
                <p>Select a report type to generate</p>
            </div>
        </div>
        <button class="reports-drawer-close" id="reports-close" aria-label="Close reports panel">
            <i class="ti ti-x" aria-hidden="true"></i>
        </button>
    </div>

    <div class="reports-drawer-body">

        <!-- Report type cards -->
        <div class="rpt-card-grid">

            <div class="rpt-card" data-report="active_maint">
                <div class="rpt-card-icon"><i class="ti ti-tool"></i></div>
                <div class="rpt-card-text">
                    <div class="rpt-card-name">Active Maintenance</div>
                    <div class="rpt-card-hint">All open maintenance orders</div>
                </div>
            </div>

            <div class="rpt-card" data-report="active_tech">
                <div class="rpt-card-icon"><i class="ti ti-device-laptop"></i></div>
                <div class="rpt-card-text">
                    <div class="rpt-card-name">Active Technology</div>
                    <div class="rpt-card-hint">All open technology orders</div>
                </div>
            </div>

            <div class="rpt-card" data-report="aging">
                <div class="rpt-card-icon"><i class="ti ti-clock-exclamation"></i></div>
                <div class="rpt-card-text">
                    <div class="rpt-card-name">Aging Report</div>
                    <div class="rpt-card-hint">Orders open past a threshold</div>
                </div>
            </div>

            <div class="rpt-card" data-report="by_building">
                <div class="rpt-card-icon"><i class="ti ti-building"></i></div>
                <div class="rpt-card-text">
                    <div class="rpt-card-name">By Building</div>
                    <div class="rpt-card-hint">All orders for one school</div>
                </div>
            </div>

            <div class="rpt-card" data-report="current_staff">
                <div class="rpt-card-icon"><i class="ti ti-user-bolt"></i></div>
                <div class="rpt-card-text">
                    <div class="rpt-card-name">Staff Member Report</div>
                    <div class="rpt-card-hint">Open work, completion history &amp; performance stats</div>
                </div>
            </div>

            <div class="rpt-card" data-report="workload">
                <div class="rpt-card-icon"><i class="ti ti-chart-bar"></i></div>
                <div class="rpt-card-text">
                    <div class="rpt-card-name">Workload Distribution</div>
                    <div class="rpt-card-hint">All workers compared — assigned vs. closed, side by side</div>
                </div>
            </div>

        </div><!-- /rpt-card-grid -->

        <!-- Dynamic options — revealed on card selection -->
        <div class="rpt-options" id="rpt-options" style="display:none">

            <!-- Aging threshold -->
            <div id="rpt-opt-aging" style="display:none">
                <div class="rpt-opt-label">Show orders open longer than</div>
                <div class="rpt-pills" id="aging-pills">
                    <button class="rpt-pill" data-val="7">&gt;7 days</button>
                    <button class="rpt-pill active" data-val="14">&gt;14 days</button>
                    <button class="rpt-pill" data-val="30">&gt;30 days</button>
                </div>
            </div>

            <!-- Period — workload distribution -->
            <div id="rpt-opt-period-workload" style="display:none">
                <div class="rpt-opt-label">Period</div>
                <div class="rpt-pills" id="period-workload-pills">
                    <button class="rpt-pill active" data-val="30">30 days</button>
                    <button class="rpt-pill" data-val="60">60 days</button>
                    <button class="rpt-pill" data-val="90">90 days</button>
                    <button class="rpt-pill" data-val="180">180 days</button>
                    <button class="rpt-pill" data-val="365">1 year</button>
                </div>
            </div>

            <!-- Staff accordion picker -->
            <div id="rpt-opt-staff-accordion" style="display:none">
                <div class="rpt-opt-label">Select Staff Member(s)</div>
                <input type="text" id="rpt-acc-search" class="rpt-input" placeholder="Search by name…" autocomplete="off">
                <div id="rpt-acc-list" style="margin-top:6px;display:flex;flex-direction:column;gap:4px"></div>
                <div id="rpt-selected-wrap" style="display:none;margin-top:8px">
                    <div class="rpt-opt-label" style="margin-bottom:4px">Selected</div>
                    <div id="rpt-selected-chips"></div>
                </div>
            </div>

            <!-- Building picker -->
            <div id="rpt-opt-building" style="display:none">
                <div class="rpt-opt-label">Building</div>
                <select id="rpt-building" class="rpt-input">
                    <option value="">Select a building…</option>
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

        </div><!-- /rpt-options -->

    </div><!-- /reports-drawer-body -->

    <div class="reports-drawer-footer">
        <button class="rpt-btn-reset" id="rpt-reset">Reset</button>
        <button class="rpt-btn-generate" id="rpt-generate" disabled>
            <i class="ti ti-file-type-pdf" aria-hidden="true"></i>
            Generate PDF
        </button>
    </div>

</div><!-- /reports-drawer -->

<?php endif; ?>

<script>
(function(){
    // ── Notification dropdown ─────────────────────────────────
    const notifBtn = document.getElementById('notif-btn');
    const notifDd  = document.getElementById('notif-dd');
    if (notifBtn && notifDd) {
        notifBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            notifDd.classList.toggle('open');
            const _pd = document.getElementById('profile-dd');
            if (_pd) _pd.classList.remove('open');
        });
        document.addEventListener('click', function(e) {
            if (!notifDd.contains(e.target) && e.target !== notifBtn)
                notifDd.classList.remove('open');
        });
        // On non-main pages, clicking a notification navigates to the detail page
        <?php if (($current_page ?? '') !== 'main'): ?>
        notifDd.querySelectorAll('.notif-item').forEach(function(item) {
            item.addEventListener('click', function() {
                window.location.href = 'wo_detail.php?wo=' + encodeURIComponent(this.dataset.wo);
            });
        });
        <?php endif; ?>
    }

    // ── Profile dropdown ──────────────────────────────────────
    const avatarBtn = document.getElementById('avatar-btn');
    const profileDd = document.getElementById('profile-dd');
    avatarBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        const notifDd = document.getElementById('notif-dd');
        if (notifDd) notifDd.classList.remove('open');
        profileDd.classList.toggle('open');
    });
    document.addEventListener('click', function(e) {
        if (!profileDd.contains(e.target) && e.target !== avatarBtn)
            profileDd.classList.remove('open');
    });

    <?php if ($_nav_show_reports): ?>
    // ── Reports drawer ────────────────────────────────────────
    const reportsBtn     = document.getElementById('reports-btn');
    const reportsDrawer  = document.getElementById('reports-drawer');
    const reportsOverlay = document.getElementById('reports-overlay');
    const reportsClose   = document.getElementById('reports-close');
    const rptReset       = document.getElementById('rpt-reset');
    const rptGenerate    = document.getElementById('rpt-generate');
    const rptOptions     = document.getElementById('rpt-options');
    let   selectedReport = '';

    function openReports() {
        reportsDrawer.classList.add('open');
        reportsOverlay.classList.add('open');
        profileDd.classList.remove('open');
    }
    function closeReports() {
        reportsDrawer.classList.remove('open');
        reportsOverlay.classList.remove('open');
    }

    reportsBtn.addEventListener('click', openReports);
    reportsClose.addEventListener('click', closeReports);
    reportsOverlay.addEventListener('click', closeReports);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeReports();
    });

    // ── Card selection ────────────────────────────────────────
    const rptCards = document.querySelectorAll('.rpt-card');
    const OPT_IDS  = ['rpt-opt-aging','rpt-opt-period-workload','rpt-opt-staff-accordion','rpt-opt-building'];
    const optMap   = {
        active_maint:   [],
        active_tech:    [],
        aging:          ['rpt-opt-aging'],
        by_building:    ['rpt-opt-building'],
        current_staff:  ['rpt-opt-staff-accordion'],
        workload:       ['rpt-opt-period-workload'],
    };

    function showOptions(report) {
        OPT_IDS.forEach(function(id){ document.getElementById(id).style.display = 'none'; });
        const show = optMap[report] || [];
        if (show.length) {
            show.forEach(function(id){ document.getElementById(id).style.display = ''; });
            rptOptions.style.display = '';
        } else {
            rptOptions.style.display = 'none';
        }
    }

    // ── Staff accordion state & functions ────────────────────
    const RPT_STAFF = <?= $_rpt_staff_json ?>;
    const RPT_ROLE_LABELS = {MW:'Maintenance Workers',BT:'Building Technicians',BC:'Building Custodians',BM:'Building Maintenance'};
    const RPT_ROLE_ORDER = <?= json_encode($user_role === 'MM' ? ['MW','BC','BM'] : ($user_role === 'MT' ? ['BT'] : ['MW','BT','BC','BM'])) ?>;
    let rptSelected = {};

    function buildRptAccordion(filter) {
        var list = document.getElementById('rpt-acc-list');
        if (!list) return;
        var q = (filter || '').toLowerCase();
        var byRole = {};
        RPT_STAFF.forEach(function(w) {
            var name = (w.first_name + ' ' + w.last_name).toLowerCase();
            if (q && name.indexOf(q) === -1) return;
            if (!byRole[w.role]) byRole[w.role] = [];
            byRole[w.role].push(w);
        });
        list.innerHTML = '';
        RPT_ROLE_ORDER.forEach(function(role) {
            var group = byRole[role];
            if (!group || !group.length) return;
            var isOpen = !!q || RPT_ROLE_ORDER.length === 1;
            var div = document.createElement('div');
            div.className = 'rpt-acc-wrap';
            var rows = group.map(function(w) {
                var esc = function(s){ return s.replace(/"/g,'&quot;').replace(/'/g,'&#39;'); };
                var checked = rptSelected[w.email] ? 'checked' : '';
                return '<button type="button" class="rpt-worker-row" onclick="rptToggleWorker(this)"'
                    + ' data-email="' + esc(w.email) + '" data-name="' + esc(w.first_name + ' ' + w.last_name) + '">'
                    + '<input type="checkbox" ' + checked + ' onclick="event.stopPropagation()" style="pointer-events:none" tabindex="-1">'
                    + '<span>' + w.first_name + ' ' + w.last_name + '</span>'
                    + '</button>';
            }).join('');
            div.innerHTML = '<button type="button" class="rpt-acc-hdr"'
                + ' onclick="var b=this.nextElementSibling;b.classList.toggle(\'open\');this.querySelector(\'.rpt-acc-chevron\').classList.toggle(\'open\')">'
                + '<span class="rpt-acc-hdr-left"><span class="rpt-acc-chevron' + (isOpen ? ' open' : '') + '">&#9654;</span>'
                + (RPT_ROLE_LABELS[role] || role) + '</span>'
                + '<span class="rpt-acc-count">' + group.length + '</span></button>'
                + '<div class="rpt-acc-body' + (isOpen ? ' open' : '') + '">' + rows + '</div>';
            list.appendChild(div);
        });
    }

    window.rptToggleWorker = function(btn) {
        var email = btn.dataset.email;
        var name  = btn.dataset.name;
        var cb    = btn.querySelector('input[type=checkbox]');
        if (rptSelected[email]) {
            delete rptSelected[email];
            cb.checked = false;
        } else {
            rptSelected[email] = name;
            cb.checked = true;
        }
        updateRptSelectedUI();
        rptGenerate.disabled = selectedReport === 'current_staff' && Object.keys(rptSelected).length === 0;
    };

    window.rptRemoveWorker = function(email) {
        delete rptSelected[email];
        buildRptAccordion(document.getElementById('rpt-acc-search').value);
        updateRptSelectedUI();
        rptGenerate.disabled = selectedReport === 'current_staff' && Object.keys(rptSelected).length === 0;
    };

    function updateRptSelectedUI() {
        var wrap  = document.getElementById('rpt-selected-wrap');
        var chips = document.getElementById('rpt-selected-chips');
        if (!chips) return;
        var emails = Object.keys(rptSelected);
        if (!emails.length) { wrap.style.display = 'none'; chips.innerHTML = ''; return; }
        wrap.style.display = '';
        chips.innerHTML = emails.map(function(e) {
            var esc = e.replace(/'/g, "\\'");
            return '<span class="rpt-sel-chip" onclick="rptRemoveWorker(\'' + esc + '\')">'
                + rptSelected[e] + ' &times;</span>';
        }).join('');
    }

    var _rptAccSearch = document.getElementById('rpt-acc-search');
    if (_rptAccSearch) {
        _rptAccSearch.addEventListener('input', function() { buildRptAccordion(this.value); });
    }

    rptCards.forEach(function(card) {
        card.addEventListener('click', function() {
            rptCards.forEach(function(c){ c.classList.remove('selected'); });
            this.classList.add('selected');
            selectedReport = this.dataset.report;
            showOptions(selectedReport);
            if (selectedReport === 'current_staff') {
                rptSelected = {};
                updateRptSelectedUI();
                buildRptAccordion('');
                var ras = document.getElementById('rpt-acc-search');
                if (ras) ras.value = '';
                rptGenerate.disabled = true;
            } else {
                rptGenerate.disabled = false;
            }
        });
    });

    // ── Pill toggles ──────────────────────────────────────────
    document.querySelectorAll('.rpt-pills').forEach(function(group) {
        group.querySelectorAll('.rpt-pill').forEach(function(pill) {
            pill.addEventListener('click', function() {
                group.querySelectorAll('.rpt-pill').forEach(function(p){ p.classList.remove('active'); });
                this.classList.add('active');
            });
        });
    });


    // ── Reset ─────────────────────────────────────────────────
    rptReset.addEventListener('click', function() {
        rptCards.forEach(function(c){ c.classList.remove('selected'); });
        selectedReport = '';
        rptOptions.style.display = 'none';
        OPT_IDS.forEach(function(id){ document.getElementById(id).style.display = 'none'; });
        document.getElementById('rpt-building').value = '';
        // Restore pill defaults
        var defaults = { 'aging-pills':'14', 'period-workload-pills':'30' };
        Object.keys(defaults).forEach(function(groupId) {
            var group = document.getElementById(groupId);
            if (!group) return;
            group.querySelectorAll('.rpt-pill').forEach(function(p){ p.classList.remove('active'); });
            var def = group.querySelector('[data-val="' + defaults[groupId] + '"]');
            if (def) def.classList.add('active');
        });
        // Reset staff accordion
        rptSelected = {};
        updateRptSelectedUI();
        buildRptAccordion('');
        var ras = document.getElementById('rpt-acc-search');
        if (ras) ras.value = '';
        document.getElementById('rpt-links-panel').style.display = 'none';
        rptGenerate.disabled = true;
    });

    // ── Generate ──────────────────────────────────────────────
    rptGenerate.addEventListener('click', function() {
        if (!selectedReport) return;

        // Validate building
        if (selectedReport === 'by_building' && !document.getElementById('rpt-building').value) {
            document.getElementById('rpt-building').style.borderColor = '#dc2626';
            setTimeout(function(){ document.getElementById('rpt-building').style.borderColor = ''; }, 1500);
            return;
        }

        const params = new URLSearchParams();
        params.set('report_type', selectedReport);

        if (selectedReport === 'aging') {
            var p = document.querySelector('#aging-pills .rpt-pill.active');
            params.set('aging_days', p ? p.dataset.val : '14');
        }
        if (selectedReport === 'current_staff') {
            var emails = Object.keys(rptSelected);
            if (!emails.length) return;
            params.set('emp_email', emails.join(','));
        }
        if (selectedReport === 'by_building') {
            params.set('building', document.getElementById('rpt-building').value);
        }
        if (selectedReport === 'workload') {
            var p = document.querySelector('#period-workload-pills .rpt-pill.active');
            params.set('period_days', p ? p.dataset.val : '30');
        }

        // Loading state — restored after 3 s (PDF opens in new tab)
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="rpt-spinning"><i class="ti ti-loader-2"></i></span>&nbsp;Generating…';
        window.open('report_generate.php?' + params.toString(), '_blank');
        setTimeout(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="ti ti-file-type-pdf"></i> Generate PDF';
        }, 3000);
    });

    <?php endif; ?>
})();
</script>