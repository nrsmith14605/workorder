<?php
// nav.php
// Requires: $user_name, $user_email, $user_pic, $initials, $user_role, $user_building
// Optional: $current_page ('main', 'manage') for active nav highlighting
// Optional: $notif_count (int) for bell badge

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
$_nav_notif_count  = $notif_count ?? 0;
?>
<style>
/* ── REPORTS DRAWER ── */
.reports-overlay{
    position:fixed;inset:0;
    background:rgba(11,31,46,0.45);
    z-index:400;
    opacity:0;
    pointer-events:none;
    transition:opacity .25s ease;
}
.reports-overlay.open{opacity:1;pointer-events:all}

.reports-drawer{
    position:fixed;
    top:0;right:0;
    width:350px;
    height:100vh;
    background:#fff;
    z-index:401;
    display:flex;
    flex-direction:column;
    box-shadow:-8px 0 40px rgba(0,0,0,0.13);
    transform:translateX(100%);
    transition:transform .28s cubic-bezier(.4,0,.2,1);
    overflow:hidden;
}
.reports-drawer.open{transform:translateX(0)}

.reports-drawer-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:20px 22px 16px;
    border-bottom:1px solid #f0f4f8;
    flex-shrink:0;
}
.reports-drawer-title{
    display:flex;align-items:center;gap:10px;
}
.reports-drawer-title-icon{
    width:36px;height:36px;
    border-radius:9px;
    background:var(--cyan-light);
    display:flex;align-items:center;justify-content:center;
    color:var(--cyan-dark);
    font-size:18px;
    flex-shrink:0;
}
.reports-drawer-title h2{
    font-family:'Barlow Condensed',sans-serif;
    font-size:19px;font-weight:700;
    color:#1a1a2e;letter-spacing:.01em;
}
.reports-drawer-title p{
    font-size:11px;color:#6b7a8d;margin-top:1px;
}
.reports-drawer-close{
    width:32px;height:32px;
    border-radius:8px;border:1px solid #e8ecf0;
    background:transparent;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    color:#6b7a8d;flex-shrink:0;
}
.reports-drawer-close:hover{background:#f8f9fa;color:#1a1a2e}

.reports-drawer-body{
    flex:1;overflow-y:auto;
    padding:20px 22px 28px;
    display:flex;flex-direction:column;gap:20px;
}

/* Field groups */
.rpt-section{
    background:#f8f9fa;
    border:1px solid #f0f4f8;
    border-radius:12px;
    padding:16px;
    display:flex;flex-direction:column;gap:12px;
}
.rpt-section-title{
    font-size:10px;font-weight:700;
    text-transform:uppercase;letter-spacing:.09em;
    color:#aab0bb;margin-bottom:2px;
}
.rpt-field{display:flex;flex-direction:column;gap:5px}
.rpt-label{
    font-size:11px;font-weight:700;
    color:#6b7a8d;
    text-transform:uppercase;letter-spacing:.05em;
}
.rpt-input{
    width:100%;
    border:1px solid #d0d5dd;
    border-radius:8px;
    padding:8px 11px;
    font-size:13px;
    font-family:'Barlow',sans-serif;
    color:#1a1a2e;background:#fff;
    transition:border-color .12s;
}
.rpt-input:focus{outline:none;border-color:var(--cyan);box-shadow:0 0 0 3px rgba(41,182,213,.10)}
select.rpt-input{
    appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
    background-repeat:no-repeat;
    background-position:right 10px center;
    padding-right:32px;
}
.rpt-date-row{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.rpt-checkbox-group{display:flex;flex-direction:column;gap:6px}
.rpt-checkbox-item{
    display:flex;align-items:center;gap:8px;
    font-size:13px;color:#3d4f5e;cursor:pointer;
    padding:6px 8px;border-radius:7px;
    transition:background .1s;
}
.rpt-checkbox-item:hover{background:#f0f4f8}
.rpt-checkbox-item input[type=checkbox]{
    accent-color:var(--cyan);
    width:15px;height:15px;flex-shrink:0;
}

.reports-drawer-footer{
    padding:16px 22px;
    border-top:1px solid #f0f4f8;
    flex-shrink:0;
    display:flex;gap:10px;
}
.rpt-btn-generate{
    flex:1;
    padding:11px 20px;
    border-radius:10px;
    border:none;
    background:var(--cyan);
    color:#fff;
    font-size:14px;font-weight:700;
    font-family:'Barlow',sans-serif;
    cursor:pointer;
    display:flex;align-items:center;justify-content:center;gap:8px;
    transition:background .12s;
}
.rpt-btn-generate:hover{background:var(--cyan-dark)}
.rpt-btn-reset{
    padding:11px 16px;
    border-radius:10px;
    border:1px solid #d0d5dd;
    background:transparent;
    color:#6b7a8d;
    font-size:13px;font-weight:700;
    font-family:'Barlow',sans-serif;
    cursor:pointer;
    transition:all .12s;
}
.rpt-btn-reset:hover{background:#f8f9fa;color:#1a1a2e}

/* Reports nav button */
.reports-nav-btn{
    display:flex;align-items:center;gap:6px;
    padding:6px 14px;
    border-radius:8px;
    border:1px solid #e8ecf0;
    background:transparent;
    color:#6b7a8d;
    font-size:13px;font-weight:600;
    font-family:'Barlow',sans-serif;
    cursor:pointer;
    transition:all .12s;
    white-space:nowrap;
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
        <?php if (!empty($_nav_notif_html)): ?>
        <div class="notif-dropdown" id="notif-dd">
            <?= $_nav_notif_html ?>
        </div>
        <?php endif; ?>

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
                <p>Generate a work order report</p>
            </div>
        </div>
        <button class="reports-drawer-close" id="reports-close" aria-label="Close reports panel">
            <i class="ti ti-x" aria-hidden="true"></i>
        </button>
    </div>

    <div class="reports-drawer-body">

        <!-- Step 1: Report Type -->
        <div class="rpt-section">
            <div class="rpt-section-title">Report Type</div>
            <div class="rpt-field">
                <label class="rpt-label" for="rpt-report-type">Select a report</label>
                <select id="rpt-report-type" class="rpt-input">
                    <option value="">Choose a report type…</option>
                    <optgroup label="Active Work">
                        <option value="active">Active Work Orders — current snapshot</option>
                        <option value="aging">Aging Report — open orders past X days</option>
                        <option value="priority">Priority Report — Urgent &amp; High orders</option>
                    </optgroup>
                    <optgroup label="People">
                        <option value="employee">Employee Performance — by staff member</option>
                        <option value="workload">Workload Distribution — worker assignments</option>
                    </optgroup>
                    <optgroup label="History">
                        <option value="completed">Completed Orders Summary</option>
                        <option value="building">Orders by Building</option>
                    </optgroup>
                </select>
            </div>
            <div id="rpt-type-desc" style="font-size:12px;color:#6b7a8d;line-height:1.55;display:none;padding:8px 10px;background:#f0f8fb;border-radius:8px;border-left:3px solid var(--cyan)"></div>
        </div>

        <!-- Date Range — shown for most reports -->
        <div class="rpt-section rpt-group" id="rpt-grp-dates" style="display:none">
            <div class="rpt-section-title">Date Range</div>
            <div class="rpt-field">
                <label class="rpt-label" for="rpt-quick-range">Quick Select</label>
                <select id="rpt-quick-range" class="rpt-input">
                    <option value="">Custom range…</option>
                    <option value="7">Last 7 days</option>
                    <option value="30">Last 30 days</option>
                    <option value="90">Last 90 days</option>
                    <option value="ytd">Year to date</option>
                    <option value="all">All time</option>
                </select>
            </div>
            <div class="rpt-date-row">
                <div class="rpt-field">
                    <label class="rpt-label" for="rpt-date-from">From</label>
                    <input type="date" id="rpt-date-from" class="rpt-input">
                </div>
                <div class="rpt-field">
                    <label class="rpt-label" for="rpt-date-to">To</label>
                    <input type="date" id="rpt-date-to" class="rpt-input">
                </div>
            </div>
        </div>

        <!-- Order Type + Building — shared filter -->
        <div class="rpt-section rpt-group" id="rpt-grp-type-building" style="display:none">
            <div class="rpt-section-title">Filters</div>
            <div class="rpt-field" id="rpt-grp-order-type">
                <label class="rpt-label" for="rpt-type">Order Type</label>
                <select id="rpt-type" class="rpt-input">
                    <option value="">All types</option>
                    <option value="Technology">Technology</option>
                    <option value="Maintenance">Maintenance</option>
                </select>
            </div>
            <div class="rpt-field" id="rpt-grp-building">
                <label class="rpt-label" for="rpt-building">Building</label>
                <select id="rpt-building" class="rpt-input">
                    <option value="">All buildings</option>
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
            <div class="rpt-field" id="rpt-grp-priority" style="display:none">
                <label class="rpt-label" for="rpt-priority">Priority</label>
                <select id="rpt-priority" class="rpt-input">
                    <option value="">All priorities</option>
                    <option value="Urgent">Urgent</option>
                    <option value="High">High</option>
                    <option value="Mid">Mid</option>
                    <option value="Low">Low</option>
                </select>
            </div>
        </div>

        <!-- Aging threshold — only for aging report -->
        <div class="rpt-section rpt-group" id="rpt-grp-aging" style="display:none">
            <div class="rpt-section-title">Aging Threshold</div>
            <div class="rpt-field">
                <label class="rpt-label" for="rpt-aging-days">Show orders open longer than</label>
                <select id="rpt-aging-days" class="rpt-input">
                    <option value="7">7 days</option>
                    <option value="14">14 days</option>
                    <option value="30" selected>30 days</option>
                    <option value="60">60 days</option>
                    <option value="90">90 days</option>
                </select>
            </div>
            <div class="rpt-field">
                <label class="rpt-label" for="rpt-aging-status">Status to include</label>
                <select id="rpt-aging-status" class="rpt-input">
                    <option value="">Any open status</option>
                    <option value="Pending Approval">Pending Approval</option>
                    <option value="Approved">Approved</option>
                    <option value="In Progress">In Progress</option>
                </select>
            </div>
        </div>

        <!-- Employee picker — for employee performance + workload -->
        <div class="rpt-section rpt-group" id="rpt-grp-employee" style="display:none">
            <div class="rpt-section-title">Staff Member</div>
            <div class="rpt-field">
                <label class="rpt-label" for="rpt-emp-role">Filter by role</label>
                <select id="rpt-emp-role" class="rpt-input">
                    <option value="">All roles</option>
                    <option value="BT">Building Technician</option>
                    <option value="BP">Building Principal</option>
                    <option value="MW">Maintenance Worker</option>
                    <option value="BC">Building Custodian</option>
                    <option value="BM">Building Maintenance</option>
                    <option value="MT">Technology Manager</option>
                    <option value="MM">Maintenance Manager</option>
                </select>
            </div>
            <div class="rpt-field">
                <label class="rpt-label" for="rpt-emp-search">Search employee</label>
                <input type="text" id="rpt-emp-search" class="rpt-input" placeholder="Type a name…" autocomplete="off">
                <div id="rpt-emp-results" style="border:1px solid #e8ecf0;border-radius:8px;background:#fff;max-height:160px;overflow-y:auto;display:none;margin-top:4px"></div>
                <input type="hidden" id="rpt-emp-email" value="">
            </div>
        </div>

        <!-- Include in report — shown for most report types -->
        <div class="rpt-section rpt-group" id="rpt-grp-include" style="display:none">
            <div class="rpt-section-title">Include in Report</div>
            <div class="rpt-checkbox-group">
                <label class="rpt-checkbox-item">
                    <input type="checkbox" id="rpt-inc-notes" checked>
                    Activity log / notes
                </label>
                <label class="rpt-checkbox-item">
                    <input type="checkbox" id="rpt-inc-submitter" checked>
                    Submitter details
                </label>
                <label class="rpt-checkbox-item">
                    <input type="checkbox" id="rpt-inc-assignees" checked>
                    Assigned workers
                </label>
                <label class="rpt-checkbox-item">
                    <input type="checkbox" id="rpt-inc-resolved" checked>
                    Resolved by
                </label>
            </div>
        </div>

    </div><!-- /reports-drawer-body -->

    <div class="reports-drawer-footer">
        <button class="rpt-btn-reset" id="rpt-reset">Reset</button>
        <button class="rpt-btn-generate" id="rpt-generate">
            <i class="ti ti-file-type-pdf" aria-hidden="true"></i>
            Generate PDF
        </button>
    </div>

</div><!-- /reports-drawer -->

<?php endif; ?>

<script>
(function(){
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
    const rptReportType  = document.getElementById('rpt-report-type');
    const rptTypeDesc    = document.getElementById('rpt-type-desc');
    const rptQuickRange  = document.getElementById('rpt-quick-range');
    const rptDateFrom    = document.getElementById('rpt-date-from');
    const rptDateTo      = document.getElementById('rpt-date-to');

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

    // ── Report type config ────────────────────────────────────
    const rptConfig = {
        active: {
            desc: 'A snapshot of all currently active work orders — pending, approved, and in progress. Filter by type, building, or priority.',
            groups: ['dates', 'type-building', 'include'],
            extras: { priority: true }
        },
        aging: {
            desc: 'Orders that have been open without resolution past a threshold you set. Useful for spotting work that is falling through the cracks.',
            groups: ['aging', 'type-building', 'include'],
            extras: {}
        },
        priority: {
            desc: 'All Urgent and High priority orders regardless of status. A quick "what\'s on fire" view for board meetings or emergency reviews.',
            groups: ['dates', 'type-building', 'include'],
            extras: { priority: true }
        },
        employee: {
            desc: 'All work orders touched by a specific staff member — what they completed, approved, or rejected — within a date range. Your primary evaluation tool.',
            groups: ['dates', 'employee', 'type-building', 'include'],
            extras: {}
        },
        workload: {
            desc: 'How many orders are currently assigned to each worker, and how many they have completed in the selected period. Helps identify staffing imbalances.',
            groups: ['dates', 'employee', 'include'],
            extras: {}
        },
        completed: {
            desc: 'All closed work orders in a date range. Filter by type, building, or who resolved them. Good for monthly and quarterly reviews.',
            groups: ['dates', 'type-building', 'include'],
            extras: {}
        },
        building: {
            desc: 'All work orders for a selected school, any status, any date range. Ideal for principal meetings or building-level reviews.',
            groups: ['dates', 'type-building', 'include'],
            extras: {}
        },
    };

    function showGroups(cfg) {
        document.querySelectorAll('.rpt-group').forEach(function(g) { g.style.display = 'none'; });
        if (!cfg) return;
        cfg.groups.forEach(function(id) {
            const el = document.getElementById('rpt-grp-' + id);
            if (el) el.style.display = '';
        });
        // Show/hide priority field within type-building group
        const priField = document.getElementById('rpt-grp-priority');
        if (priField) priField.style.display = (cfg.extras && cfg.extras.priority) ? '' : 'none';
    }

    rptReportType.addEventListener('change', function() {
        const val = this.value;
        const cfg = rptConfig[val];
        if (cfg) {
            rptTypeDesc.textContent = cfg.desc;
            rptTypeDesc.style.display = '';
            showGroups(cfg);
            rptGenerate.disabled = false;
        } else {
            rptTypeDesc.style.display = 'none';
            showGroups(null);
            rptGenerate.disabled = true;
        }
    });

    // Disable generate until report type is chosen
    rptGenerate.disabled = true;

    // ── Quick date range ──────────────────────────────────────
    rptQuickRange.addEventListener('change', function() {
        const val   = this.value;
        const today = new Date();
        const fmt   = function(d) { return d.toISOString().split('T')[0]; };
        rptDateTo.value = fmt(today);
        if (val === '7')  { const d = new Date(today); d.setDate(d.getDate()-7);  rptDateFrom.value = fmt(d); }
        if (val === '30') { const d = new Date(today); d.setDate(d.getDate()-30); rptDateFrom.value = fmt(d); }
        if (val === '90') { const d = new Date(today); d.setDate(d.getDate()-90); rptDateFrom.value = fmt(d); }
        if (val === 'ytd'){ rptDateFrom.value = today.getFullYear() + '-01-01'; }
        if (val === 'all'){ rptDateFrom.value = ''; rptDateTo.value = ''; }
    });

    // ── Employee search ───────────────────────────────────────
    const empSearch  = document.getElementById('rpt-emp-search');
    const empResults = document.getElementById('rpt-emp-results');
    const empEmail   = document.getElementById('rpt-emp-email');
    const empRole    = document.getElementById('rpt-emp-role');

    function searchEmployees() {
        const q    = empSearch.value.trim();
        const role = empRole.value;
        if (q.length < 2) { empResults.style.display = 'none'; return; }
        const params = new URLSearchParams({ q: q });
        if (role) params.append('role', role);
        fetch('report_emp_search.php?' + params.toString())
            .then(function(r) { return r.json(); })
            .then(function(data) {
                empResults.innerHTML = '';
                if (!data.length) {
                    empResults.innerHTML = '<div style="padding:10px 14px;font-size:12px;color:#aab0bb">No matches found</div>';
                } else {
                    data.forEach(function(emp) {
                        const item = document.createElement('div');
                        item.style.cssText = 'padding:9px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #f0f4f8;transition:background .1s';
                        item.innerHTML = '<strong>' + emp.name + '</strong> <span style="color:#aab0bb;font-size:11px">· ' + emp.role_label + '</span>';
                        item.addEventListener('mouseenter', function() { this.style.background = '#f0f8fb'; });
                        item.addEventListener('mouseleave', function() { this.style.background = ''; });
                        item.addEventListener('click', function() {
                            empSearch.value      = emp.name;
                            empEmail.value       = emp.email;
                            empResults.style.display = 'none';
                        });
                        empResults.appendChild(item);
                    });
                }
                empResults.style.display = '';
            })
            .catch(function() { empResults.style.display = 'none'; });
    }

    if (empSearch) {
        empSearch.addEventListener('input', searchEmployees);
        empRole.addEventListener('change', function() {
            empEmail.value = '';
            empSearch.value = '';
            empResults.style.display = 'none';
        });
        document.addEventListener('click', function(e) {
            if (!empResults.contains(e.target) && e.target !== empSearch)
                empResults.style.display = 'none';
        });
    }

    // ── Reset ─────────────────────────────────────────────────
    rptReset.addEventListener('click', function() {
        rptReportType.value   = '';
        rptTypeDesc.style.display = 'none';
        showGroups(null);
        rptDateFrom.value     = '';
        rptDateTo.value       = '';
        rptQuickRange.value   = '';
        document.getElementById('rpt-type').value         = '';
        document.getElementById('rpt-building').value     = '';
        document.getElementById('rpt-priority').value     = '';
        document.getElementById('rpt-aging-days').value   = '30';
        document.getElementById('rpt-aging-status').value = '';
        document.getElementById('rpt-emp-role').value     = '';
        empSearch.value = '';
        empEmail.value  = '';
        empResults.style.display = 'none';
        document.getElementById('rpt-inc-notes').checked     = true;
        document.getElementById('rpt-inc-submitter').checked = true;
        document.getElementById('rpt-inc-assignees').checked = true;
        document.getElementById('rpt-inc-resolved').checked  = true;
        rptGenerate.disabled = true;
    });

    // ── Generate — stream PDF in new tab ─────────────────────
    rptGenerate.addEventListener('click', function() {
        const reportType = rptReportType.value;
        if (!reportType) return;

        const params = new URLSearchParams();
        params.set('report_type',   reportType);
        params.set('date_from',     rptDateFrom.value);
        params.set('date_to',       rptDateTo.value);
        params.set('order_type',    document.getElementById('rpt-type').value);
        params.set('building',      document.getElementById('rpt-building').value);
        params.set('priority',      document.getElementById('rpt-priority').value);
        params.set('aging_days',    document.getElementById('rpt-aging-days').value);
        params.set('aging_status',  document.getElementById('rpt-aging-status').value);
        params.set('emp_email',     document.getElementById('rpt-emp-email').value);
        params.set('emp_role',      document.getElementById('rpt-emp-role').value);
        params.set('inc_notes',     document.getElementById('rpt-inc-notes').checked     ? '1' : '0');
        params.set('inc_submitter', document.getElementById('rpt-inc-submitter').checked ? '1' : '0');
        params.set('inc_assignees', document.getElementById('rpt-inc-assignees').checked ? '1' : '0');
        params.set('inc_resolved',  document.getElementById('rpt-inc-resolved').checked  ? '1' : '0');

        window.open('report_generate.php?' + params.toString(), '_blank');
    });

    <?php endif; ?>
})();
</script>