<?php
// includes/nav.php
// Requires $user_name, $user_email, $user_pic, $initials, $user_role
// $user_role is pulled from the users DB table: A | M | BA | U
// $current_page used to highlight active nav item (e.g. 'main', 'manage')

$role_labels = [
    'A'  => 'Admin',
    'M'  => 'Manager',
    'BA' => 'Building Admin',
    'U'  => 'User',
];
$role_label = $role_labels[$user_role ?? 'U'] ?? 'User';
?>
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
    flex-shrink:0;
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
.pd-role-badge.role-a {background:#f3e8ff;color:#6b21a8}
.pd-role-badge.role-m {background:#fef3c7;color:#92400e}
.pd-role-badge.role-ba{background:var(--cyan-light);color:var(--cyan-dark)}
.pd-role-badge.role-u {background:#f1f5f9;color:#475569}
.pd-divider{border:none;border-top:1px solid #f0f4f8;margin:10px 0}
.pd-item{display:flex;align-items:center;gap:10px;padding:8px;border-radius:8px;cursor:pointer;font-size:13px;color:#6b7a8d;width:100%;background:transparent;border:none;font-family:'Barlow',sans-serif;text-align:left;text-decoration:none}
.pd-item:hover{background:#f8f9fa;color:#1a1a2e}
.pd-item.active-page{color:var(--cyan);font-weight:600}
.pd-item.danger{color:#dc2626}
.pd-item.danger:hover{background:#fff5f5}
/* ── FOOTER ── */
.site-footer{background:var(--navy);border-top:1px solid rgba(27,188,212,0.12);padding:28px 28px 24px;margin-top:auto;flex-shrink:0}
.footer-inner{max-width:920px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.footer-brand{display:flex;align-items:center;gap:14px}
.footer-logo{width:32px;height:auto;filter:brightness(0) invert(1);opacity:0.65}
.footer-brand-name{font-family:'Barlow Condensed',sans-serif;font-size:14px;font-weight:600;color:rgba(255,255,255,0.70);letter-spacing:0.02em}
.footer-brand-sub{font-size:11px;color:rgba(255,255,255,0.28);letter-spacing:0.1em;text-transform:uppercase;margin-top:2px}
.footer-copy{font-size:12px;color:rgba(255,255,255,0.25);letter-spacing:0.02em;text-align:right}
@media(max-width:600px){.footer-inner{flex-direction:column;align-items:flex-start;gap:12px}.footer-copy{text-align:left}}
/* ── MAIN CONTENT WRAPPER ── */
.main{max-width:920px;margin:0 auto;padding:32px 20px 48px;flex:1}
</style>

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
                    <div class="pd-role-badge role-<?= strtolower(str_replace('BA','ba',$user_role ?? 'u')) ?>">
                        <i class="ti ti-shield-check" aria-hidden="true"></i>
                        <?= htmlspecialchars($role_label) ?>
                    </div>
                </div>
            </div>
            <hr class="pd-divider">
            <a href="main.php"   class="pd-item <?= ($current_page??'')==='main'   ? 'active-page' : '' ?>"><i class="ti ti-home" aria-hidden="true"></i> Dashboard</a>
            <?php if (($user_role ?? '') === 'A'): ?>
            <a href="manage.php" class="pd-item <?= ($current_page??'')==='manage' ? 'active-page' : '' ?>"><i class="ti ti-users" aria-hidden="true"></i> Manage Users</a>
            <?php endif; ?>
            <hr class="pd-divider">
            <a href="logout.php" class="pd-item danger"><i class="ti ti-logout" aria-hidden="true"></i> Sign out</a>
        </div>
    </div>
</nav>

<script>
(function(){
    const btn = document.getElementById('avatar-btn');
    const dd  = document.getElementById('profile-dd');
    btn.addEventListener('click', function(e){
        e.stopPropagation();
        dd.classList.toggle('open');
    });
    document.addEventListener('click', function(e){
        if (!dd.contains(e.target) && e.target !== btn) dd.classList.remove('open');
    });
})();
</script>