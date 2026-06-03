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

// ── Handle POST actions (add / edit / toggle active) ───────
$action_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $act = $_POST['action'] ?? '';

    // ── Add new user ──────────────────────────────────────
    if ($act === 'add') {
        $fn    = trim($_POST['first_name'] ?? '');
        $ln    = trim($_POST['last_name']  ?? '');
        $em    = strtolower(trim($_POST['email'] ?? ''));
        $role  = $_POST['role']     ?? 'U';
        $bldg  = in_array($role, ['BA','BT','BC','BM']) ? (trim($_POST['building'] ?? '') ?: null) : null;

        if ($fn && $ln && $em && in_array($role, ['A','M','BA','BT','BC','BM','U'])) {
            $stmt = $conn->prepare("INSERT INTO users (first_name,last_name,email,role,building) VALUES (?,?,?,?,?)");
            $stmt->bind_param('sssss', $fn, $ln, $em, $role, $bldg);
            $stmt->execute();
            $action_msg = $stmt->affected_rows ? 'success:User added successfully.' : 'error:Failed to add user (email may already exist).';
            $stmt->close();
        } else {
            $action_msg = 'error:Please fill in all required fields.';
        }
    }

    // ── Edit existing user ────────────────────────────────
    if ($act === 'edit') {
        $id    = (int)($_POST['user_id'] ?? 0);
        $fn    = trim($_POST['first_name'] ?? '');
        $ln    = trim($_POST['last_name']  ?? '');
        $role  = $_POST['role']     ?? 'U';
        $bldg  = in_array($role, ['BA','BT','BC','BM']) ? (trim($_POST['building'] ?? '') ?: null) : null;

        if ($id && $fn && $ln && in_array($role, ['A','M','BA','BT','BC','BM','U'])) {
            $stmt = $conn->prepare("UPDATE users SET first_name=?,last_name=?,role=?,building=? WHERE id=?");
            $stmt->bind_param('ssssi', $fn, $ln, $role, $bldg, $id);
            $stmt->execute();
            $action_msg = 'success:User updated.';
            $stmt->close();
        }
    }

    // ── Toggle active/inactive ────────────────────────────
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

$buildings = ['CHS','BHS','THS','TMS','CSMS','BMS','CNMS','JHC'];
$role_labels = ['A'=>'Admin','M'=>'Manager','BA'=>'Building Admin','BT'=>'Building Tech','BC'=>'Building Custodian','BM'=>'Building Maintenance','U'=>'User'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Users – Warrick County Work Order System</title>
<?php require_once 'includes/nav.php'; ?>
<style>

/* ── PAGE HEADER ── */
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.page-header h1{font-family:'Barlow Condensed',sans-serif;font-size:28px;font-weight:700;color:#1a1a2e}
.page-header p{font-size:14px;color:#6b7a8d;margin-top:4px}

/* ── FLASH MESSAGE ── */
.flash{padding:12px 18px;border-radius:9px;font-size:13px;font-weight:600;margin-bottom:20px}
.flash.success{background:#d1fae5;color:#065f46}
.flash.error  {background:#fee2e2;color:#991b1b}

/* ── FILTER BAR ── */
.filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.filter-tabs{display:flex;gap:6px}
.filter-tab{padding:5px 14px;border-radius:20px;border:1px solid #e8ecf0;background:transparent;font-size:12px;font-weight:600;cursor:pointer;color:#6b7a8d;font-family:'Barlow',sans-serif;transition:all .12s}
.filter-tab.active{background:var(--cyan);color:#fff;border-color:var(--cyan)}
.search-wrap{margin-left:auto}
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
.badge-a {background:#f3e8ff;color:#6b21a8}
.badge-m {background:#fef3c7;color:#92400e}
.badge-ba{background:#e6f7fb;color:#1a9ab8}
.badge-u {background:#f1f5f9;color:#475569}
.badge-bt{background:#dcfce7;color:#166534}
.badge-bc{background:#fef9c3;color:#854d0e}
.badge-bm{background:#ffe4e6;color:#9f1239}
.badge-active  {background:#d1fae5;color:#065f46}
.badge-inactive{background:#fee2e2;color:#991b1b}

/* action buttons */
.row-actions{display:flex;gap:6px}
.btn-sm{padding:5px 12px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;font-family:'Barlow',sans-serif;border:1px solid #d0d5dd;background:transparent;color:#6b7a8d;transition:all .12s}
.btn-sm:hover{background:#f8f9fa;color:#1a1a2e}
.btn-sm.danger{border-color:#fca5a5;color:#dc2626}
.btn-sm.danger:hover{background:#fff5f5}
.btn-sm.success-btn{border-color:#86efac;color:#15803d}
.btn-sm.success-btn:hover{background:#f0fdf4}
.empty-state{text-align:center;padding:48px 20px;color:#aab0bb}
.empty-state i{font-size:40px;display:block;margin-bottom:10px;color:#d0d5dd}

/* ── MODAL ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:300;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:16px;width:100%;max-width:520px;margin:auto;box-shadow:0 20px 60px rgba(0,0,0,0.15);overflow:hidden}
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
                <col style="width:18%">
                <col style="width:24%">
                <col style="width:12%">
                <col style="width:10%">
                <col style="width:10%">
                <col style="width:26%">
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
                    $r      = $u['role'];
                    $active = (int)$u['active'];
                    $roleClass = 'badge-' . strtolower(str_replace('BA','ba',$r));
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
                            <button class="btn-sm edit-btn"
                                data-id="<?= $u['id'] ?>"
                                data-fn="<?= htmlspecialchars($u['first_name']) ?>"
                                data-ln="<?= htmlspecialchars($u['last_name']) ?>"
                                data-role="<?= htmlspecialchars($r) ?>"
                                data-building="<?= htmlspecialchars($u['building'] ?? '') ?>">
                                <i class="ti ti-edit" aria-hidden="true"></i> Edit
                            </button>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="action"  value="toggle">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn-sm <?= $active ? 'danger' : 'success-btn' ?>">
                                    <i class="ti ti-<?= $active ? 'user-off' : 'user-check' ?>" aria-hidden="true"></i>
                                    <?= $active ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

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

                <div class="form-group" id="email-group">
                    <label class="form-label" for="f-email">Email *</label>
                    <input type="email" id="f-email" name="email" required>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="f-role">Role *</label>
                        <select id="f-role" name="role" required>
                            <option value="U">User</option>
                            <option value="BA">Building Admin</option>
                            <option value="BT">Building Tech</option>
                            <option value="BC">Building Custodian</option>
                            <option value="BM">Building Maintenance</option>
                            <option value="M">Manager</option>
                            <option value="A">Admin</option>
                        </select>
                    </div>
                    <div class="form-group building-group" id="building-group">
                        <label class="form-label" for="f-building">Building *</label>
                        <select id="f-building" name="building">
                            <option value="">Select…</option>
                            <?php foreach ($buildings as $b): ?>
                            <option value="<?= $b ?>"><?= $b ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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

function filterTable() {
    const role  = document.querySelector('.filter-tab.active').dataset.role;
    const query = document.getElementById('user-search').value.toLowerCase();
    document.querySelectorAll('.user-row').forEach(function(row) {
        const roleMatch  = role === 'all' || row.dataset.role === role;
        const nameMatch  = row.dataset.name.includes(query);
        const emailMatch = row.dataset.email.includes(query);
        row.style.display = (roleMatch && (nameMatch || emailMatch)) ? '' : 'none';
    });
}

// ── Building field visibility ─────────────────────────────
document.getElementById('f-role').addEventListener('change', function() {
    const bg = document.getElementById('building-group');
    bg.classList.toggle('visible', ['BA','BT','BC','BM'].includes(this.value));
    document.getElementById('f-building').required = ['BA','BT','BC','BM'].includes(this.value);
});

// ── Add user modal ────────────────────────────────────────
document.getElementById('add-user-btn').addEventListener('click', function() {
    document.getElementById('modal-heading').textContent  = 'Add User';
    document.getElementById('f-action').value   = 'add';
    document.getElementById('f-user-id').value  = '';
    document.getElementById('f-first').value    = '';
    document.getElementById('f-last').value     = '';
    document.getElementById('f-email').value    = '';
    document.getElementById('f-email').readOnly = false;
    document.getElementById('email-group').style.display = '';
    document.getElementById('f-role').value     = 'U';
    document.getElementById('f-building').value = '';
    document.getElementById('building-group').classList.remove('visible');
    document.getElementById('user-modal').classList.add('open');
});

// ── Edit user modal ───────────────────────────────────────
document.querySelectorAll('.edit-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const role = this.dataset.role;
        document.getElementById('modal-heading').textContent  = 'Edit User';
        document.getElementById('f-action').value   = 'edit';
        document.getElementById('f-user-id').value  = this.dataset.id;
        document.getElementById('f-first').value    = this.dataset.fn;
        document.getElementById('f-last').value     = this.dataset.ln;
        document.getElementById('f-email').value    = '';
        document.getElementById('email-group').style.display = 'none'; // email not editable
        document.getElementById('f-role').value     = role;
        document.getElementById('f-building').value = this.dataset.building;
        const bg = document.getElementById('building-group');
        bg.classList.toggle('visible', ['BA','BT','BC','BM'].includes(role));
        document.getElementById('user-modal').classList.add('open');
    });
});

// ── Close modal ───────────────────────────────────────────
function closeUserModal() { document.getElementById('user-modal').classList.remove('open'); }
document.getElementById('close-user-modal').addEventListener('click',  closeUserModal);
document.getElementById('cancel-user-modal').addEventListener('click', closeUserModal);
document.getElementById('user-modal').addEventListener('click', function(e){ if(e.target===this) closeUserModal(); });
</script>

</body>
</html>
