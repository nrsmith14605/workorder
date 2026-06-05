<?php

session_start();

// ── Auth guard ─────────────────────────────────────────────
if (!isset($_SESSION['google_user'])) {
    header('Location: index.php');
    exit;
}

// ── Role guard — Admins only ───────────────────────────────
$user_role = $_SESSION['user_role'] ?? 'U';
if ($user_role !== 'A') {
    header('Location: main.php');
    exit;
}

// ── User identity ──────────────────────────────────────────
$user       = $_SESSION['google_user'];
$user_email = $user['email'];
$user_name  = $user['name']    ?? 'User';
$user_pic   = $user['picture'] ?? '';

$name_parts    = explode(' ', trim($user_name));
$initials      = strtoupper(substr($name_parts[0],0,1) . (isset($name_parts[1]) ? substr($name_parts[1],0,1) : ''));
$user_building = $_SESSION['user_building'] ?? null;
$current_page  = 'manage';

// ── DB connection ──────────────────────────────────────────
require_once __DIR__ . '/../../wo_config.php';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

// ── Roles that require a building assignment ───────────────
$building_roles = ['BA','BT','BC','BM'];
// MD does NOT require a building

// ── Handle POST actions ────────────────────────────────────
$action_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $act = $_POST['action'] ?? '';

    if ($act === 'add') {
        $fn    = trim($_POST['first_name'] ?? '');
        $ln    = trim($_POST['last_name']  ?? '');
        $em    = strtolower(trim($_POST['email'] ?? ''));
        $role  = $_POST['role'] ?? 'U';
        $bldg  = in_array($role, $building_roles) ? (trim($_POST['building'] ?? '') ?: null) : null;

        if ($fn && $ln && $em && in_array($role, ['A','M','BA','BT','BC','BM','MD','U'])) {
            $stmt = $conn->prepare("INSERT INTO users (first_name,last_name,email,role,building) VALUES (?,?,?,?,?)");
            $stmt->bind_param('sssss', $fn, $ln, $em, $role, $bldg);
            $stmt->execute();
            $action_msg = $stmt->affected_rows ? 'success:User added successfully.' : 'error:Failed to add user (email may already exist).';
            $stmt->close();
        } else {
            $action_msg = 'error:Please fill in all required fields.';
        }
    }

    if ($act === 'edit') {
        $id    = (int)($_POST['user_id'] ?? 0);
        $fn    = trim($_POST['first_name'] ?? '');
        $ln    = trim($_POST['last_name']  ?? '');
        $role  = $_POST['role'] ?? 'U';
        $bldg  = in_array($role, $building_roles) ? (trim($_POST['building'] ?? '') ?: null) : null;

        if ($id && $fn && $ln && in_array($role, ['A','M','BA','BT','BC','BM','MD','U'])) {
            $stmt = $conn->prepare("UPDATE users SET first_name=?,last_name=?,role=?,building=? WHERE id=?");
            $stmt->bind_param('ssssi', $fn, $ln, $role, $bldg, $id);
            $stmt->execute();
            $action_msg = 'success:User updated.';
            $stmt->close();
        }
    }

    if ($act === 'toggle') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id) {
            $stmt = $conn->prepare("UPDATE users SET active = 1 - active WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $action_msg = 'success:User status updated.';
            $stmt->close();
        }
    }
}

// ── Fetch all users ────────────────────────────────────────
$users = [];
$res = $conn->query("SELECT * FROM users ORDER BY role, last_name, first_name");
if ($res) {
    while ($row = $res->fetch_assoc()) $users[] = $row;
}
$conn->close();

// ── Buildings + groups ─────────────────────────────────────
$buildings = ['CHS','BHS','THS','WPCC','CSMS','CNMS','BMS','LUM','CHAN','ELB','JHC','LOGE','LYN','NEWB','OAK','SHAR','TEN','TMS','WEC','YANK'];
$building_groups = [
    'High Schools'   => ['CHS','BHS','THS','WPCC'],
    'Middle Schools' => ['CSMS','CNMS','BMS','LUM'],
    'Elementary'     => ['CHAN','ELB','JHC','LOGE','LYN','NEWB','OAK','SHAR','TEN','TMS','WEC','YANK'],
];

$role_labels = [
    'A'  => 'Admin',
    'M'  => 'Manager',
    'BA' => 'Building Admin',
    'BT' => 'Building Technician',
    'BC' => 'Building Custodian',
    'BM' => 'Building Maintenance',
    'MD' => 'Maintenance Dept',
    'U'  => 'User',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Users – Warrick County Work Order System</title>
<?php require_once 'includes/nav.php'; ?>
<style>

/* ── PAGE LAYOUT OVERRIDE ── */
.main{max-width:1300px}

/* ── PAGE HEADER ── */
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.page-header h1{font-family:'Barlow Condensed',sans-serif;font-size:28px;font-weight:700;color:#1a1a2e}
.page-header p{font-size:14px;color:#6b7a8d;margin-top:4px}

/* ── FLASH MESSAGE ── */
.flash{padding:12px 18px;border-radius:9px;font-size:13px;font-weight:600;margin-bottom:20px}
.flash.success{background:#d1fae5;color:#065f46}
.flash.error  {background:#fee2e2;color:#991b1b}

/* ── TWO-COLUMN LAYOUT ── */
.content-layout{display:flex;gap:24px;align-items:flex-start}
.table-section{flex:0 0 72%;min-width:0}
.summary-section{flex:1;min-width:0}

/* ── SUMMARY CARD ── */
.summary-card{background:#fff;border:1px solid #e8ecf0;border-radius:12px;overflow:hidden;margin-bottom:16px}
.summary-card-header{background:var(--navy);color:rgba(255,255,255,0.85);font-family:'Barlow Condensed',sans-serif;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:11px 18px}
.summary-stat-row{display:flex;align-items:center;justify-content:space-between;padding:10px 18px;border-bottom:1px solid #f0f4f8}
.summary-stat-row:last-child{border-bottom:none}
.summary-stat-label{font-size:13px;color:#6b7a8d}
.summary-stat-count{font-size:14px;font-weight:700;color:#1a1a2e}
.summary-stat-total{font-size:18px;color:var(--cyan)}
.summary-divider{height:4px;background:#f0f4f8}

/* ── FILTER BAR ── */
.filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.filter-tabs{display:flex;gap:6px;flex-wrap:wrap}
.filter-tab{padding:5px 14px;border-radius:20px;border:1px solid #e8ecf0;background:transparent;font-size:12px;font-weight:600;cursor:pointer;color:#6b7a8d;font-family:'Barlow',sans-serif;transition:all .12s}
.filter-tab.active{background:var(--cyan);color:#fff;border-color:var(--cyan)}
.search-wrap{margin-left:0}
.search-wrap input{padding:7px 14px;border:1px solid #d0d5dd;border-radius:20px;font-size:13px;font-family:'Barlow',sans-serif;width:220px;color:#1a1a2e}
.search-wrap input:focus{outline:none;border-color:var(--cyan)}

/* ── USERS TABLE ── */
.table-wrap{background:#fff;border:1px solid #e8ecf0;border-radius:12px;overflow:hidden;margin-bottom:32px}
.data-table{width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed}
.data-table td{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.data-table th{padding:11px 16px;text-align:left;font-weight:700;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#6b7a8d;background:#f8f9fa;border-bottom:1px solid #e8ecf0}
.data-table td{padding:13px 16px;border-bottom:1px solid #f0f4f8;vertical-align:middle}
.data-table tr:last-child td{border-bottom:none}
.data-table tr:hover td{background:#f8f9fa}
.data-table tr.inactive td{opacity:.5}

/* role badges */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
.badge-a  {background:#f3e8ff;color:#6b21a8}
.badge-m  {background:#fef3c7;color:#92400e}
.badge-ba {background:#e6f7fb;color:#1a9ab8}
.badge-u  {background:#f1f5f9;color:#475569}
.badge-bt {background:#dcfce7;color:#166534}
.badge-bc {background:#fef9c3;color:#854d0e}
.badge-bm {background:#ffe4e6;color:#9f1239}
.badge-md {background:#ede9fe;color:#5b21b6}
.badge-active  {background:#d1fae5;color:#065f46}
.badge-inactive{background:#fee2e2;color:#991b1b}

/* ── ICON ACTION BUTTONS ── */
.row-actions{display:flex;gap:4px}
.icon-btn{width:32px;height:32px;border-radius:8px;border:none;background:transparent;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:16px;color:#6b7a8d;transition:all .12s;padding:0}
.icon-btn:hover{background:#f0f4f8;color:#1a1a2e}
.icon-btn.danger{color:#dc2626}
.icon-btn.danger:hover{background:#fff5f5}
.icon-btn.success-btn{color:#15803d}
.icon-btn.success-btn:hover{background:#f0fdf4}

.empty-state{text-align:center;padding:48px 20px;color:#aab0bb}
.empty-state i{font-size:40px;display:block;margin-bottom:10px;color:#d0d5dd}

/* ── MODAL ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:300;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:16px;width:100%;max-width:560px;margin:auto;box-shadow:0 20px 60px rgba(0,0,0,0.15);overflow:hidden}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:20px 24px 16px;border-bottom:1px solid #f0f4f8}
.modal-title{font-family:'Barlow Condensed',sans-serif;font-size:20px;font-weight:700;color:#1a1a2e}
.close-btn{width:32px;height:32px;border-radius:8px;border:1px solid #e8ecf0;background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#6b7a8d}
.close-btn:hover{background:#f8f9fa}
.modal-body{padding:22px 24px}
.modal-footer{padding:14px 24px;border-top:1px solid #f0f4f8;display:flex;justify-content:flex-end;gap:10px;background:#fafafa}

/* ── FORM ── */
.form-group{margin-bottom:16px}
.form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
label.form-label{display:block;font-size:11px;font-weight:700;color:#6b7a8d;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}
input[type=text],input[type=email],select{width:100%;border:1px solid #d0d5dd;border-radius:9px;padding:10px 13px;font-size:14px;font-family:'Barlow',sans-serif;color:#1a1a2e;background:#fff}
input:focus,select:focus{outline:none;border-color:var(--cyan);box-shadow:0 0 0 3px rgba(41,182,213,.12)}
select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 11px center;padding-right:34px}
.building-group{display:none}
.building-group.visible{display:block}

/* building checkbox pills */
.bldg-check-label{transition:border-color .12s,background .12s}
.bldg-check-label:has(.bldg-checkbox:checked){border-color:var(--cyan);background:var(--cyan-light);color:var(--cyan-dark)}

/* ── BUTTONS ── */
.btn{padding:10px 22px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;font-family:'Barlow',sans-serif;border:none;display:inline-flex;align-items:center;gap:7px;transition:all .12s}
.btn-primary{background:var(--cyan);color:#fff}
.btn-primary:hover{background:var(--cyan-dark)}
.btn-ghost{background:transparent;color:#6b7a8d;border:1px solid #d0d5dd}
.btn-ghost:hover{background:#f8f9fa}

</style>
</head>
<body>

<main class="main">

    <!-- Page header -->
    <div class="page-header">
        <div>
            <h1>Manage Users</h1>
            <p>Add, edit, or deactivate system users and their roles.</p>
        </div>
        <button class="btn btn-primary" id="add-user-btn">
            <i class="ti ti-user-plus" aria-hidden="true"></i> Add User
        </button>
    </div>

    <?php if ($action_msg): ?>
        <?php [$type, $msg] = explode(':', $action_msg, 2); ?>
        <div class="flash <?= $type ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="content-layout">

        <!-- ── Table section (72%) ── -->
        <div class="table-section">

            <!-- Filter bar -->
            <div class="filter-bar">
                <div class="filter-tabs">
                    <button class="filter-tab active" data-role="all">All</button>
                    <button class="filter-tab" data-role="A">Admins</button>
                    <button class="filter-tab" data-role="M">Managers</button>
                    <button class="filter-tab" data-role="BA">Building Admins</button>
                    <button class="filter-tab" data-role="BT">Building Tech</button>
                    <button class="filter-tab" data-role="BC">Building Custodian</button>
                    <button class="filter-tab" data-role="BM">Building Maintenance</button>
                    <button class="filter-tab" data-role="MD">Maintenance Dept</button>
                    <button class="filter-tab" data-role="U">Users</button>
                </div>
                <div class="search-wrap">
                    <input type="text" id="user-search" placeholder="Search name or email…">
                </div>
            </div>

            <!-- Users table -->
            <div class="table-wrap">
                <table class="data-table" id="users-table">
                    <colgroup>
                        <col style="width:17%">
                        <col style="width:26%">
                        <col style="width:20%">
                        <col style="width:16%">
                        <col style="width:10%">
                        <col style="width:11%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Building</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="ti ti-users-group" aria-hidden="true"></i>
                                    No users found. Add one to get started.
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($users as $u):
                            $r      = trim($u['role']);
                            $r      = preg_replace('/[^A-Z]/', '', $r);
                            $active = (int)$u['active'];
                            $roleClass = 'badge-' . strtolower($r);
                        ?>
                        <tr class="user-row <?= $active ? '' : 'inactive' ?>"
                            data-role="<?= htmlspecialchars($r) ?>"
                            data-name="<?= htmlspecialchars(strtolower($u['first_name'].' '.$u['last_name'])) ?>"
                            data-email="<?= htmlspecialchars(strtolower($u['email'])) ?>">
                            <td><strong><?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?></strong></td>
                            <td style="color:#6b7a8d"><?= htmlspecialchars($u['email']) ?></td>
                            <td><span class="badge <?= $roleClass ?>"><?= htmlspecialchars($role_labels[$r] ?? $r) ?></span></td>
                            <td><?= $u['building'] ? htmlspecialchars($u['building']) : '<span style="color:#d0d5dd">—</span>' ?></td>
                            <td>
                                <span class="badge <?= $active ? 'badge-active' : 'badge-inactive' ?>">
                                    <?= $active ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <button class="icon-btn edit-btn"
                                        title="Edit user"
                                        data-id="<?= $u['id'] ?>"
                                        data-fn="<?= htmlspecialchars($u['first_name']) ?>"
                                        data-ln="<?= htmlspecialchars($u['last_name']) ?>"
                                        data-role="<?= htmlspecialchars($r) ?>"
                                        data-building="<?= htmlspecialchars($u['building'] ?? '') ?>">
                                        <i class="ti ti-edit" aria-hidden="true"></i>
                                    </button>
                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="action"  value="toggle">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="icon-btn <?= $active ? 'danger' : 'success-btn' ?>" title="<?= $active ? 'Deactivate' : 'Activate' ?> user">
                                            <i class="ti ti-<?= $active ? 'user-off' : 'user-check' ?>" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <!-- Pagination injected here by JS -->
            </div>

        </div><!-- /table-section -->

        <!-- ── Summary sidebar ── -->
        <div class="summary-section">
            <?php
            $total = count($users);
            $counts = [];
            foreach ($users as $u) {
                $rk = preg_replace('/[^A-Z]/', '', trim($u['role']));
                $counts[$rk] = ($counts[$rk] ?? 0) + 1;
            }
            $role_order = ['A','M','BA','BT','BC','BM','MD','U'];
            ?>
            <div class="summary-card">
                <div class="summary-card-header">User Summary</div>
                <div class="summary-stat-row">
                    <span class="summary-stat-label">Total Users</span>
                    <span class="summary-stat-count summary-stat-total"><?= $total ?></span>
                </div>
                <div class="summary-divider"></div>
                <?php foreach ($role_order as $rk):
                    $cls = 'badge-' . strtolower($rk);
                    $cnt = $counts[$rk] ?? 0;
                ?>
                <div class="summary-stat-row">
                    <span class="summary-stat-label"><span class="badge <?= $cls ?>"><?= htmlspecialchars($role_labels[$rk]) ?></span></span>
                    <span class="summary-stat-count"><?= $cnt ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div><!-- /summary-section -->

    </div><!-- /content-layout -->

</main>

<?php require_once 'includes/footer.php'; ?>

<!-- ── ADD / EDIT USER MODAL ──────────────────────────────── -->
<div class="modal-overlay" id="user-modal" role="dialog" aria-modal="true">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" id="modal-heading">Add User</div>
            <button class="close-btn" id="close-user-modal" aria-label="Close">
                <i class="ti ti-x" aria-hidden="true"></i>
            </button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="action"  id="f-action"  value="add">
                <input type="hidden" name="user_id" id="f-user-id" value="">

                <!-- Name row -->
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="f-first">First Name *</label>
                        <input type="text" id="f-first" name="first_name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="f-last">Last Name *</label>
                        <input type="text" id="f-last" name="last_name" required>
                    </div>
                </div>

                <!-- Email + Role row -->
                <div class="form-row-2">
                    <div class="form-group" id="email-group">
                        <label class="form-label" for="f-email">Email *</label>
                        <input type="email" id="f-email" name="email">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="f-role">Role *</label>
                        <select id="f-role" name="role" required>
                            <option value="U">User</option>
                            <option value="BA">Building Admin</option>
                            <option value="BT">Building Tech</option>
                            <option value="BC">Building Custodian</option>
                            <option value="BM">Building Maintenance</option>
                            <option value="MD">Maintenance Dept</option>
                            <option value="M">Manager</option>
                            <option value="A">Admin</option>
                        </select>
                    </div>
                </div>

                <!-- Building checkboxes (shown only for building-level roles) -->
                <div class="form-group building-group" id="building-group">
                    <label class="form-label">Building(s) *</label>
                    <div id="building-checkboxes" style="margin-top:6px">
                        <?php foreach ($building_groups as $category => $blist): ?>
                        <div style="margin-bottom:8px">
                            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aab0bb;margin-bottom:5px"><?= $category ?></div>
                            <div style="display:flex;flex-wrap:wrap;gap:6px">
                                <?php foreach ($blist as $b): ?>
                                <label style="display:flex;align-items:center;gap:5px;font-size:13px;font-weight:500;cursor:pointer;padding:4px 10px;border:1px solid #d0d5dd;border-radius:8px;background:#fff;transition:all .12s" class="bldg-check-label">
                                    <input type="checkbox" name="buildings[]" value="<?= $b ?>" class="bldg-checkbox" style="accent-color:var(--cyan);width:14px;height:14px"> <?= $b ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <!-- Hidden field synced by JS on submit -->
                    <input type="hidden" id="f-building" name="building" value="">
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" id="cancel-user-modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="modal-submit-btn">
                    <i class="ti ti-check" aria-hidden="true"></i> Save User
                </button>
            </div>
        </form>
    </div>
</div>

<script>

// ── Filter tabs ───────────────────────────────────────────
document.querySelectorAll('[data-role]').forEach(function(tab) {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        filterTable();
    });
});
document.getElementById('user-search').addEventListener('input', filterTable);

// ── Pagination ────────────────────────────────────────────
const PAGE_SIZE = 10;
let currentPage = 1;

function filterTable() {
    const role  = document.querySelector('.filter-tab.active').dataset.role;
    const query = document.getElementById('user-search').value.toLowerCase();
    const rows  = Array.from(document.querySelectorAll('.user-row'));

    rows.forEach(function(row) {
        const roleMatch  = role === 'all' || row.dataset.role === role;
        const nameMatch  = row.dataset.name.includes(query);
        const emailMatch = row.dataset.email.includes(query);
        row._matches = roleMatch && (nameMatch || emailMatch);
    });

    currentPage = 1;
    renderPage();
}

function renderPage() {
    const rows    = Array.from(document.querySelectorAll('.user-row'));
    const matched = rows.filter(r => r._matches !== false);
    const total   = matched.length;
    const pages   = Math.max(1, Math.ceil(total / PAGE_SIZE));
    if (currentPage > pages) currentPage = pages;

    const start = (currentPage - 1) * PAGE_SIZE;
    const end   = start + PAGE_SIZE;

    rows.forEach(function(row) { row.style.display = 'none'; });
    matched.forEach(function(row, i) {
        row.style.display = (i >= start && i < end) ? '' : 'none';
    });

    renderPagination(total, pages);
}

function renderPagination(total, pages) {
    let el = document.getElementById('pagination');
    if (!el) {
        el = document.createElement('div');
        el.id = 'pagination';
        el.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-top:1px solid #f0f4f8;font-size:13px;color:#6b7a8d;background:#fff;border-radius:0 0 12px 12px';
        document.querySelector('.table-wrap').appendChild(el);
    }
    const start = total === 0 ? 0 : (currentPage - 1) * PAGE_SIZE + 1;
    const end   = Math.min(currentPage * PAGE_SIZE, total);
    let btns = '';
    for (let i = 1; i <= pages; i++) {
        const active = i === currentPage ? 'background:var(--cyan);color:#fff;border-color:var(--cyan)' : '';
        btns += `<button onclick="goPage(${i})" style="padding:4px 10px;border-radius:6px;border:1px solid #e8ecf0;background:transparent;cursor:pointer;font-size:12px;font-weight:600;font-family:'Barlow',sans-serif;color:#6b7a8d;${active}">${i}</button>`;
    }
    el.innerHTML = `
        <span>Showing ${start}–${end} of ${total} users</span>
        <div style="display:flex;gap:4px">${btns}</div>
    `;
}

function goPage(n) {
    currentPage = n;
    renderPage();
}

// Init
document.querySelectorAll('.user-row').forEach(r => r._matches = true);
renderPage();

// ── Building field visibility ─────────────────────────────
// MD does NOT get a building field — they are corp-wide
const buildingRoles = ['BA','BT','BC','BM'];
document.getElementById('f-role').addEventListener('change', function() {
    const bg    = document.getElementById('building-group');
    const needs = buildingRoles.includes(this.value);
    bg.classList.toggle('visible', needs);
});

// ── Add user modal ────────────────────────────────────────
document.getElementById('add-user-btn').addEventListener('click', function() {
    document.getElementById('modal-heading').textContent = 'Add User';
    document.getElementById('f-action').value   = 'add';
    document.getElementById('f-user-id').value  = '';
    document.getElementById('f-first').value    = '';
    document.getElementById('f-last').value     = '';
    document.getElementById('f-email').value    = '';
    document.getElementById('f-email').readOnly = false;
    document.getElementById('f-email').required = true;
    document.getElementById('email-group').style.display = '';
    document.getElementById('f-role').value     = 'U';
    document.querySelectorAll('.bldg-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('f-building').value = '';
    document.getElementById('building-group').classList.remove('visible');
    document.getElementById('user-modal').classList.add('open');
});

// ── Edit user modal ───────────────────────────────────────
document.querySelectorAll('.edit-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const role              = this.dataset.role;
        const assignedBuildings = (this.dataset.building || '').split(',').map(s => s.trim()).filter(Boolean);
        document.getElementById('modal-heading').textContent = 'Edit User';
        document.getElementById('f-action').value   = 'edit';
        document.getElementById('f-user-id').value  = this.dataset.id;
        document.getElementById('f-first').value    = this.dataset.fn;
        document.getElementById('f-last').value     = this.dataset.ln;
        document.getElementById('f-email').value    = '';
        document.getElementById('f-email').required = false;
        document.getElementById('email-group').style.display = 'none';
        document.getElementById('f-role').value     = role;
        document.querySelectorAll('.bldg-checkbox').forEach(cb => {
            cb.checked = assignedBuildings.includes(cb.value);
        });
        document.getElementById('f-building').value = this.dataset.building;
        const bg = document.getElementById('building-group');
        bg.classList.toggle('visible', buildingRoles.includes(role));
        document.getElementById('user-modal').classList.add('open');
    });
});

// ── Close modal ───────────────────────────────────────────
function closeUserModal() { document.getElementById('user-modal').classList.remove('open'); }
document.getElementById('close-user-modal').addEventListener('click',  closeUserModal);
document.getElementById('cancel-user-modal').addEventListener('click', closeUserModal);
document.getElementById('user-modal').addEventListener('click', function(e){ if(e.target===this) closeUserModal(); });

// ── Sync checkboxes → hidden building field before submit ─
document.querySelector('#user-modal form').addEventListener('submit', function() {
    const checked = Array.from(document.querySelectorAll('.bldg-checkbox:checked')).map(cb => cb.value);
    document.getElementById('f-building').value = checked.join(',');
});

</script>
</body>
</html>
