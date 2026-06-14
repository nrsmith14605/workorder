<?php
session_start();

if (!isset($_SESSION['google_user'])) {
    header('Location: ../index.php');
    exit;
}

$user       = $_SESSION['google_user'];
$user_email = $user['email'];
$user_name  = $user['name'] ?? 'User';
$user_given = $user['given_name'] ?? '';
$user_pic   = $user['picture'] ?? '';
$user_role  = $_SESSION['user_role'] ?? 'U';

if (!in_array($user_role, ['MW','BC','BM','MM'])) {
    header('Location: ../main.php');
    exit;
}

$wo_param = trim($_GET['wo'] ?? '');
if (!preg_match('/^WO-\d+$/', $wo_param)) {
    header('Location: dashboard.php');
    exit;
}
$order_id = (int) substr($wo_param, 3);

require_once __DIR__ . '/../../../wo_config.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');

// Fetch order
$stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->bind_param('i', $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    $db->close();
    header('Location: dashboard.php');
    exit;
}

// Verify access
if ($user_role === 'MM') {
    if ($order['type'] !== 'Maintenance') {
        $db->close(); header('Location: dashboard.php'); exit;
    }
} else {
    // MW/BC/BM — must be assigned
    $chk = $db->prepare("SELECT 1 FROM order_assignments WHERE order_id=? AND user_email=?");
    $chk->bind_param('is', $order_id, $user_email);
    $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) {
        $chk->close(); $db->close();
        header('Location: dashboard.php'); exit;
    }
    $chk->close();
}

$db->close();

$wo_num  = 'WO-' . str_pad($order['id'], 6, '0', STR_PAD_LEFT);
$pri     = $order['priority'] ?? 'Low';
$status  = $order['status'] ?? '';

$pri_colors = [
    'Urgent' => ['bg'=>'#fee2e2','color'=>'#991b1b'],
    'High'   => ['bg'=>'#fef3c7','color'=>'#92400e'],
    'Mid'    => ['bg'=>'#dbeafe','color'=>'#1e40af'],
    'Low'    => ['bg'=>'#d1fae5','color'=>'#065f46'],
];
$pc = $pri_colors[$pri] ?? $pri_colors['Low'];

$status_colors = [
    'In Progress'      => ['bg'=>'#dbeafe','color'=>'#1e40af'],
    'Completed'        => ['bg'=>'#d1fae5','color'=>'#166534'],
    'Pending Approval' => ['bg'=>'#fef3c7','color'=>'#92400e'],
    'Approved'         => ['bg'=>'#d1fae5','color'=>'#065f46'],
    'Rejected'         => ['bg'=>'#fee2e2','color'=>'#991b1b'],
];
$sc = $status_colors[$status] ?? ['bg'=>'#f1f5f9','color'=>'#475569'];

$photos = array_filter(array_map('trim', explode('||', $order['photo_path'] ?? '')));

$date_submitted = $order['created_at'] ? date('M j, Y', strtotime($order['created_at'])) : '—';

$time_disp = '—';
if (!empty($order['time_from'])) {
    $time_disp = $order['time_from'];
    if (!empty($order['time_to']) && $order['time_to'] !== $order['time_from']) {
        $time_disp .= ' – ' . $order['time_to'];
    }
}

// Parse activity log into entries
$notes_raw = trim($order['notes'] ?? '');
$log_entries = [];
if ($notes_raw) {
    $raw_entries = preg_split('/\n(?=\[)/', $notes_raw);
    foreach ($raw_entries as $entry) {
        $entry = trim($entry);
        if ($entry !== '') $log_entries[] = htmlspecialchars($entry);
    }
    $log_entries = array_reverse($log_entries);
}

$can_complete = ($status === 'In Progress');
$complete_action = ($user_role === 'MM') ? 'mm_complete' : 'worker_complete';

$role_labels = [
    'MW' => 'Maintenance Worker',
    'BC' => 'Building Custodian',
    'BM' => 'Building Maintenance',
    'MM' => 'Maintenance Manager',
];
$role_label = $role_labels[$user_role] ?? 'Worker';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#0B1F2E">
<link rel="manifest" href="manifest.json">
<title><?= $wo_num ?> &mdash; WCSC Work Orders</title>
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
    padding-bottom:calc(24px + var(--safe-bottom));
}

/* ── TOP BAR ── */
.topbar{
    background:var(--navy);
    padding:calc(14px + var(--safe-top)) 16px 14px;
    display:flex;
    align-items:center;
    gap:12px;
    position:sticky;top:0;z-index:100;
}
.back-btn{
    width:34px;height:34px;
    background:rgba(255,255,255,.12);
    border:none;border-radius:9px;
    display:flex;align-items:center;justify-content:center;
    cursor:pointer;flex-shrink:0;
    -webkit-tap-highlight-color:transparent;
    color:#fff;
    text-decoration:none;
}
.back-btn:active{background:rgba(255,255,255,.2)}
.topbar-wo{
    font-family:'Barlow Condensed',sans-serif;
    font-size:17px;font-weight:700;color:#fff;flex:1;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.topbar-wo span{color:var(--cyan)}
.topbar-location{color:rgba(255,255,255,.65);font-weight:600}
.topbar-wo span{color:var(--cyan)}

/* ── CONTENT ── */
.content{padding:14px 14px 0}

/* ── DETAIL CARD ── */
.detail-card{
    background:#fff;border-radius:14px;
    border:1px solid #e8ecf0;
    overflow:hidden;margin-bottom:12px;
}
.detail-card-header{
    padding:12px 16px;
    border-bottom:1px solid #f0f4f8;
    display:flex;align-items:center;gap:8px;flex-wrap:wrap;
}
.badge{font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px}
.detail-body{padding:0}
.detail-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
}
.detail-cell{
    padding:11px 14px;
    border-bottom:1px solid #f8f9fa;
}
.detail-cell:nth-child(odd){border-right:1px solid #f8f9fa}
.detail-cell.full{
    grid-column:1/-1;
    border-right:none;
    border-bottom:none;
}
.detail-label{font-size:10px;font-weight:700;color:#aab0bb;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px}
.detail-value{font-size:13px;color:#1a1a2e;line-height:1.45}

/* ── PHOTOS ── */
.photos-wrap{display:flex;gap:8px;flex-wrap:wrap;padding:12px 16px 14px}
.photo-thumb{
    width:80px;height:80px;border-radius:10px;
    object-fit:cover;border:1px solid #e8ecf0;
    cursor:pointer;
}

/* ── LIGHTBOX ── */
.lightbox{
    display:none;position:fixed;inset:0;
    background:#000;z-index:500;
    align-items:center;justify-content:center;
    touch-action:pan-y;
}
.lightbox.open{display:flex}
.lightbox-img-wrap{
    display:flex;align-items:center;justify-content:center;
    width:100%;height:100%;
    transition:transform .2s ease;
}
.lightbox img{max-width:95vw;max-height:85vh;border-radius:4px;object-fit:contain;display:block}
.lightbox-close{
    position:absolute;top:calc(16px + var(--safe-top));right:16px;
    width:36px;height:36px;border-radius:50%;
    background:rgba(255,255,255,.15);border:none;
    color:#fff;font-size:20px;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    z-index:1;
}
.lightbox-counter{
    position:absolute;top:calc(18px + var(--safe-top));left:50%;
    transform:translateX(-50%);
    background:rgba(255,255,255,.15);
    color:#fff;font-size:13px;font-weight:600;
    padding:4px 14px;border-radius:20px;
    font-family:'Barlow',sans-serif;
    display:none;
    z-index:1;
}

/* ── ACTIVITY LOG ── */
.log-toggle{
    width:100%;background:transparent;border:none;
    padding:13px 16px;
    display:flex;align-items:center;justify-content:space-between;
    font-size:13px;font-weight:600;color:#6b7a8d;
    font-family:'Barlow',sans-serif;cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.log-toggle:active{background:#f8f9fa}
.log-chevron{transition:transform .2s;flex-shrink:0}
.log-chevron.open{transform:rotate(180deg)}
.log-body{display:none;padding:0 16px 14px}
.log-body.open{display:block}
.log-entry{
    font-size:12px;color:#6b7a8d;line-height:1.5;
    padding:8px 0;border-bottom:1px solid #f0f4f8;
    white-space:pre-wrap;word-break:break-word;
}
.log-entry:last-child{border-bottom:none}
.log-empty{font-size:12px;color:#aab0bb;padding:8px 0}

/* ── UPDATE FORM ── */
.update-card{
    background:#fff;border-radius:14px;
    border:1px solid #e8ecf0;
    padding:14px 16px;margin-bottom:12px;
}
.section-title{
    font-family:'Barlow Condensed',sans-serif;
    font-size:16px;font-weight:700;color:#1a1a2e;
    margin-bottom:12px;
}
textarea{
    width:100%;
    border:1px solid #d0d5dd;border-radius:10px;
    padding:11px 13px;
    font-size:15px;font-family:'Barlow',sans-serif;
    color:#1a1a2e;background:#fff;
    resize:none;line-height:1.5;
    transition:border-color .12s;
    min-height:90px;
}
textarea:focus{outline:none;border-color:var(--cyan);box-shadow:0 0 0 3px rgba(41,182,213,.12)}
textarea:disabled{background:#f8f9fa;color:#6b7a8d}

/* ── PHOTO UPLOAD ── */
.photo-upload-row{
    display:flex;align-items:center;gap:10px;
    margin-top:10px;
}
.photo-upload-btn{
    display:flex;align-items:center;gap:7px;
    padding:9px 14px;border-radius:10px;
    border:1.5px dashed #d0d5dd;background:transparent;
    font-size:13px;font-weight:600;color:#6b7a8d;
    font-family:'Barlow',sans-serif;cursor:pointer;
    -webkit-tap-highlight-color:transparent;flex-shrink:0;
}
.photo-upload-btn:active{background:#f8f9fa}
.photo-previews{display:flex;gap:6px;flex-wrap:wrap;flex:1}
.thumb-wrap{position:relative;width:52px;height:52px}
.thumb-wrap img{width:100%;height:100%;object-fit:cover;border-radius:8px;border:1px solid #e8ecf0}
.thumb-remove{
    position:absolute;top:-4px;right:-4px;
    width:18px;height:18px;border-radius:50%;
    background:#dc2626;color:#fff;border:none;
    font-size:12px;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    line-height:1;
}

/* ── SAVE BUTTON ── */
.btn-save{
    width:100%;padding:13px;border-radius:12px;
    background:var(--cyan);color:#fff;border:none;
    font-size:15px;font-weight:700;font-family:'Barlow',sans-serif;
    cursor:pointer;margin-top:12px;
    display:flex;align-items:center;justify-content:center;gap:8px;
    -webkit-tap-highlight-color:transparent;
    transition:background .12s;
}
.btn-save:active{background:var(--cyan-dark)}
.btn-save:disabled{opacity:.5;cursor:not-allowed}

/* ── COMPLETE BUTTON ── */
.complete-wrap{
    background:#fff;border-radius:14px;
    border:1px solid #e8ecf0;
    padding:16px;margin-bottom:12px;
}
.btn-complete{
    width:100%;padding:16px;border-radius:12px;
    background:#16a34a;color:#fff;border:none;
    font-size:16px;font-weight:700;font-family:'Barlow Condensed',sans-serif;
    letter-spacing:.03em;
    cursor:pointer;
    display:flex;align-items:center;justify-content:center;gap:10px;
    -webkit-tap-highlight-color:transparent;
    transition:background .12s;
}
.btn-complete:active{background:#15803d}

/* ── CONFIRM MODAL ── */
.modal-overlay{
    display:none;position:fixed;inset:0;
    background:rgba(0,0,0,0);z-index:400;
    align-items:flex-end;justify-content:center;
    transition:background .25s ease;
}
.modal-overlay.open{display:flex}
.modal-overlay.visible{background:rgba(0,0,0,.5)}
.modal{
    background:#fff;
    border-radius:20px 20px 0 0;
    padding:24px 20px calc(20px + var(--safe-bottom));
    width:100%;max-width:480px;
    transform:translateY(100%);
    transition:transform .3s cubic-bezier(.4,0,.2,1);
}
.modal-overlay.visible .modal{transform:translateY(0)}
.modal h3{
    font-family:'Barlow Condensed',sans-serif;
    font-size:20px;font-weight:700;color:#1a1a2e;
    margin-bottom:8px;
}
.modal p{font-size:14px;color:#6b7a8d;line-height:1.55;margin-bottom:20px}
.modal-actions{display:flex;flex-direction:column;gap:10px}
.btn-confirm{
    width:100%;padding:14px;border-radius:12px;
    background:#16a34a;color:#fff;border:none;
    font-size:15px;font-weight:700;font-family:'Barlow',sans-serif;
    cursor:pointer;
}
.btn-confirm:active{background:#15803d}
.btn-cancel{
    width:100%;padding:14px;border-radius:12px;
    background:transparent;color:#6b7a8d;
    border:1.5px solid #e8ecf0;
    font-size:15px;font-weight:600;font-family:'Barlow',sans-serif;
    cursor:pointer;
}
.btn-cancel:active{background:#f8f9fa}

/* ── TOAST ── */
.toast{
    position:fixed;bottom:calc(20px + var(--safe-bottom));left:50%;
    transform:translateX(-50%) translateY(20px);
    background:#1a1a2e;color:#fff;
    padding:11px 20px;border-radius:99px;
    font-size:13px;font-weight:600;
    opacity:0;transition:all .25s;
    pointer-events:none;white-space:nowrap;z-index:600;
}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}

/* ── COMPLETED STATE ── */
.completed-banner{
    background:#d1fae5;border:1px solid #6ee7b7;
    border-radius:12px;padding:14px 16px;
    display:flex;align-items:center;gap:10px;
    margin-bottom:12px;
    font-size:14px;font-weight:600;color:#065f46;
}
</style>
</head>
<body>

<!-- TOP BAR -->
<header class="topbar">
    <a href="dashboard.php" class="back-btn" aria-label="Back to dashboard">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    </a>
    <div class="topbar-wo">
        <span><?= $wo_num ?></span><span class="topbar-location"> &mdash; <?= htmlspecialchars($order['building']) ?> &mdash; <?= htmlspecialchars($order['room']) ?></span>
    </div>
</header>

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true">
    <button class="lightbox-close" id="lightbox-close" aria-label="Close photo">&times;</button>
    <div class="lightbox-counter" id="lightbox-counter"></div>
    <div class="lightbox-img-wrap" id="lightbox-img-wrap">
        <img id="lightbox-img" src="" alt="Work order photo">
    </div>
</div>

<div class="content">

    <!-- Status / Priority badges -->
    <div class="detail-card">
        <div class="detail-card-header">
            <span class="badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>"><?= htmlspecialchars($status) ?></span>
            <span class="badge" style="background:<?= $pc['bg'] ?>;color:<?= $pc['color'] ?>"><?= htmlspecialchars($pri) ?></span>
            <span class="badge" style="background:#f1f5f9;color:#475569"><?= htmlspecialchars($order['type']) ?></span>
        </div>
        <div class="detail-body">
            <div class="detail-grid">
                <div class="detail-cell">
                    <div class="detail-label">Problem Type</div>
                    <div class="detail-value"><?= htmlspecialchars($order['problem_type']) ?></div>
                </div>
                <div class="detail-cell">
                    <div class="detail-label">Submitted By</div>
                    <div class="detail-value"><?= htmlspecialchars($order['submitted_name'] ?: $order['submitted_by']) ?></div>
                </div>
                <div class="detail-cell">
                    <div class="detail-label">Available</div>
                    <div class="detail-value"><?= htmlspecialchars($time_disp) ?></div>
                </div>
                <div class="detail-cell">
                    <div class="detail-label">Date Submitted</div>
                    <div class="detail-value"><?= $date_submitted ?></div>
                </div>
                <div class="detail-cell full">
                    <div class="detail-label">Description</div>
                    <div class="detail-value"><?= nl2br(htmlspecialchars($order['description'])) ?></div>
                </div>
            </div>
        </div>

        <?php if (!empty($photos)): ?>
        <div style="border-top:1px solid #f0f4f8">
            <div style="padding:10px 16px 4px;font-size:10px;font-weight:700;color:#aab0bb;text-transform:uppercase;letter-spacing:.06em">Attached Photos</div>
            <div class="photos-wrap">
                <?php foreach ($photos as $p): ?>
                <img class="photo-thumb" src="../<?= htmlspecialchars($p) ?>" alt="Work order photo" loading="lazy">
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Activity log -->
        <div style="border-top:1px solid #f0f4f8">
            <button class="log-toggle" id="log-toggle" aria-expanded="false">
                Activity Log
                <svg class="log-chevron" id="log-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="log-body" id="log-body">
                <?php if (empty($log_entries)): ?>
                <div class="log-empty">No activity yet.</div>
                <?php else: foreach ($log_entries as $entry): ?>
                <div class="log-entry"><?= $entry ?></div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <?php if ($status === 'Completed'): ?>
    <div class="completed-banner">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        This order has been completed.
    </div>
    <?php endif; ?>

    <?php if ($status === 'In Progress'): ?>
    <!-- Update form -->
    <div class="update-card">
        <div class="section-title">Add Update</div>
        <textarea id="note-text" placeholder="Describe what you did, what's needed, any relevant details…" rows="4"></textarea>

        <div class="photo-upload-row">
            <button type="button" class="photo-upload-btn" id="photo-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                Add Photo
            </button>
            <div class="photo-previews" id="photo-previews"></div>
        </div>
        <input type="file" id="photo-input" accept="image/*" capture="environment" multiple style="display:none">

        <button class="btn-save" id="btn-save">Save Update</button>
    </div>

    <!-- Mark complete -->
    <div class="complete-wrap">
        <button class="btn-complete" id="btn-complete">Mark as Complete</button>
    </div>
    <?php endif; ?>

</div><!-- /content -->

<!-- Confirm complete modal -->
<div class="modal-overlay" id="confirm-overlay">
    <div class="modal">
        <h3>Mark Order Complete?</h3>
        <p>This will close <?= $wo_num ?> and send a completion notification to the submitter. This cannot be undone.</p>
        <div class="modal-actions">
            <button class="btn-confirm" id="btn-confirm-yes">Yes, Mark Complete</button>
            <button class="btn-cancel" id="btn-confirm-cancel">Cancel</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
const ORDER_ID      = <?= (int)$order['id'] ?>;
const WO_NUM        = '<?= $wo_num ?>';
const COMPLETE_ACTION = '<?= $complete_action ?>';
const ACTION_URL    = '../wo_action.php';

// ── Photo lightbox with swipe ─────────────────────────────────
const lightbox      = document.getElementById('lightbox');
const lightboxImg   = document.getElementById('lightbox-img');
const lightboxClose = document.getElementById('lightbox-close');
const lightboxCounter = document.getElementById('lightbox-counter');
let _lbIdx = 0;

function getLightboxSrcs() {
    return Array.from(document.querySelectorAll('.photo-thumb')).map(function(img) { return img.src; });
}

function openLightbox(idx) {
    _lbIdx = idx;
    showLightboxAt(_lbIdx);
    lightbox.classList.add('open');
}

function showLightboxAt(idx) {
    const srcs = getLightboxSrcs();
    _lbIdx = Math.max(0, Math.min(idx, srcs.length - 1));
    lightboxImg.src = srcs[_lbIdx];
    if (srcs.length > 1) {
        lightboxCounter.textContent = (_lbIdx + 1) + ' / ' + srcs.length;
        lightboxCounter.style.display = '';
    } else {
        lightboxCounter.style.display = 'none';
    }
}

function bindThumb(img, idx) {
    img.addEventListener('click', function() { openLightbox(idx); });
}
document.querySelectorAll('.photo-thumb').forEach(bindThumb);

lightboxClose.addEventListener('click', function() { lightbox.classList.remove('open'); });
lightbox.addEventListener('click', function(e) {
    if (e.target === lightbox || e.target === document.getElementById('lightbox-img-wrap')) {
        lightbox.classList.remove('open');
    }
});

// Swipe left/right between images
let _swipeX = 0;
let _swipeY = 0;
lightbox.addEventListener('touchstart', function(e) {
    _swipeX = e.touches[0].clientX;
    _swipeY = e.touches[0].clientY;
}, {passive: true});
lightbox.addEventListener('touchend', function(e) {
    const dx = e.changedTouches[0].clientX - _swipeX;
    const dy = e.changedTouches[0].clientY - _swipeY;
    if (Math.abs(dx) < 40 || Math.abs(dy) > Math.abs(dx)) return;
    const srcs = getLightboxSrcs();
    if (dx < 0 && _lbIdx < srcs.length - 1) showLightboxAt(_lbIdx + 1);
    else if (dx > 0 && _lbIdx > 0) showLightboxAt(_lbIdx - 1);
}, {passive: true});

// ── Activity log toggle ───────────────────────────────────────
const logToggle  = document.getElementById('log-toggle');
const logChevron = document.getElementById('log-chevron');
const logBody    = document.getElementById('log-body');
logToggle.addEventListener('click', function() {
    const open = logBody.classList.toggle('open');
    logChevron.classList.toggle('open', open);
    logToggle.setAttribute('aria-expanded', open);
});

// ── Photo upload previews ─────────────────────────────────────
const photoBtn      = document.getElementById('photo-btn');
const photoInput    = document.getElementById('photo-input');
const photoPreviews = document.getElementById('photo-previews');
let selectedFiles   = [];

if (photoBtn) {
    photoBtn.addEventListener('click', function() { photoInput.click(); });
    photoInput.addEventListener('change', function() {
        Array.from(this.files).forEach(function(f) {
            if (selectedFiles.length >= 5) return;
            selectedFiles.push(f);
        });
        renderPreviews();
        this.value = '';
    });
}

function renderPreviews() {
    if (!photoPreviews) return;
    photoPreviews.innerHTML = '';
    selectedFiles.forEach(function(f, idx) {
        const wrap = document.createElement('div');
        wrap.className = 'thumb-wrap';
        const img = document.createElement('img');
        img.src = URL.createObjectURL(f);
        const rm = document.createElement('button');
        rm.className = 'thumb-remove';
        rm.innerHTML = '&times;';
        rm.setAttribute('aria-label', 'Remove photo');
        rm.addEventListener('click', function() {
            selectedFiles.splice(idx, 1);
            renderPreviews();
        });
        wrap.appendChild(img);
        wrap.appendChild(rm);
        photoPreviews.appendChild(wrap);
    });
}

// ── Toast helper ──────────────────────────────────────────────
function showToast(msg, duration) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, duration || 2800);
}

// ── Save Update ───────────────────────────────────────────────
const btnSave = document.getElementById('btn-save');
if (btnSave) {
    btnSave.addEventListener('click', function() {
        const note = document.getElementById('note-text').value.trim();
        if (!note && selectedFiles.length === 0) {
            showToast('Please add a note or photo before saving.');
            return;
        }

        btnSave.disabled = true;
        btnSave.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin .8s linear infinite"><path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0"/></svg> Saving…';

        const fd = new FormData();
        fd.append('action', 'note_only');
        fd.append('order_id', ORDER_ID);
        fd.append('note', note);
        selectedFiles.forEach(function(f) { fd.append('photos[]', f); });

        // If photos present, upload them first then save note
        if (selectedFiles.length > 0) {
            const uploadFd = new FormData();
            uploadFd.append('action', 'upload_and_note');
            uploadFd.append('order_id', ORDER_ID);
            uploadFd.append('note', note);
            selectedFiles.forEach(function(f) { uploadFd.append('photos[]', f); });
            fetch('mobile_upload.php', { method: 'POST', body: uploadFd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        showToast('Update saved.');
                        document.getElementById('note-text').value = '';
                        selectedFiles = [];
                        renderPreviews();
                        if (data.log_entry) prependLogEntry(data.log_entry);
                        if (data.new_photos) appendPhotos(data.new_photos);
                    } else {
                        showToast(data.message || 'Save failed. Please try again.');
                    }
                    resetSaveBtn();
                })
                .catch(function() { showToast('Network error. Please try again.'); resetSaveBtn(); });
        } else {
            fetch(ACTION_URL, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        showToast('Note saved.');
                        document.getElementById('note-text').value = '';
                        if (data.log_entry) prependLogEntry(data.log_entry);
                    } else {
                        showToast(data.message || 'Save failed. Please try again.');
                    }
                    resetSaveBtn();
                })
                .catch(function() { showToast('Network error. Please try again.'); resetSaveBtn(); });
        }
    });
}

function resetSaveBtn() {
    if (!btnSave) return;
    btnSave.disabled = false;
    btnSave.innerHTML = 'Save Update';
}

function prependLogEntry(text) {
    const lb = document.getElementById('log-body');
    if (!lb) return;
    const empty = lb.querySelector('.log-empty');
    if (empty) empty.remove();
    const div = document.createElement('div');
    div.className = 'log-entry';
    div.textContent = text;
    lb.insertBefore(div, lb.firstChild);
    lb.classList.add('open');
    document.getElementById('log-chevron').classList.add('open');
    document.getElementById('log-toggle').setAttribute('aria-expanded', 'true');
}

function appendPhotos(paths) {
    const wrap = document.querySelector('.photos-wrap');
    const detailCard = document.querySelector('.detail-card');
    let container = wrap;
    if (!container) {
        // Create the photos section if it didn't exist before
        const section = document.createElement('div');
        section.style.borderTop = '1px solid #f0f4f8';
        section.innerHTML = '<div style="padding:10px 16px 4px;font-size:10px;font-weight:700;color:#aab0bb;text-transform:uppercase;letter-spacing:.06em">Attached Photos</div><div class="photos-wrap"></div>';
        const logSection = detailCard.querySelector('[style*="border-top"]');
        detailCard.insertBefore(section, logSection);
        container = section.querySelector('.photos-wrap');
    }
    paths.forEach(function(p) {
        const img = document.createElement('img');
        img.className = 'photo-thumb';
        img.src = '../' + p;
        img.alt = 'Work order photo';
        img.loading = 'lazy';
        img.addEventListener('click', function() {
            const allThumbs = Array.from(document.querySelectorAll('.photo-thumb'));
            openLightbox(allThumbs.indexOf(this));
        });
        container.appendChild(img);
    });
}

// ── Mark Complete ─────────────────────────────────────────────
const btnComplete     = document.getElementById('btn-complete');
const confirmOverlay  = document.getElementById('confirm-overlay');
const btnConfirmYes   = document.getElementById('btn-confirm-yes');
const btnConfirmCancel = document.getElementById('btn-confirm-cancel');

function openConfirm() {
    confirmOverlay.classList.add('open');
    requestAnimationFrame(function() {
        requestAnimationFrame(function() {
            confirmOverlay.classList.add('visible');
        });
    });
}
function closeConfirm() {
    confirmOverlay.classList.remove('visible');
    setTimeout(function() { confirmOverlay.classList.remove('open'); }, 300);
}

if (btnComplete) {
    btnComplete.addEventListener('click', openConfirm);
}
if (btnConfirmCancel) {
    btnConfirmCancel.addEventListener('click', closeConfirm);
}
confirmOverlay.addEventListener('click', function(e) {
    if (e.target === this) closeConfirm();
});

if (btnConfirmYes) {
    btnConfirmYes.addEventListener('click', function() {
        confirmOverlay.classList.remove('open');
        btnConfirmYes.disabled = true;
        btnComplete.disabled = true;
        btnComplete.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin .8s linear infinite"><path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0"/></svg> Completing…';

        const fd = new FormData();
        fd.append('action', COMPLETE_ACTION);
        fd.append('order_id', ORDER_ID);
        fd.append('note', '');

        fetch(ACTION_URL, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showToast('Order marked complete!', 2000);
                    setTimeout(function() { window.location.href = 'dashboard.php'; }, 1800);
                } else {
                    showToast(data.message || 'Error. Please try again.');
                    closeConfirm();
                    btnComplete.disabled = false;
                    btnConfirmYes.disabled = false;
                    btnComplete.innerHTML = 'Mark as Complete';
                }
            })
            .catch(function() {
                showToast('Network error. Please try again.');
                btnComplete.disabled = false;
                btnConfirmYes.disabled = false;
                btnComplete.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Mark as Complete';
            });
    });
}

// CSS spin animation
const styleEl = document.createElement('style');
styleEl.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
document.head.appendChild(styleEl);
</script>
</body>
</html>
