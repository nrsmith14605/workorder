<style>
.site-footer{background:#0B1F2E;border-top:1px solid rgba(27,188,212,0.12);padding:28px 28px 24px;flex-shrink:0}
.footer-inner{max-width:1300px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.footer-brand{display:flex;align-items:center;gap:14px}
.footer-logo{width:32px;height:auto;filter:brightness(0) invert(1);opacity:0.65}
.footer-brand-name{font-family:'Barlow Condensed',sans-serif;font-size:14px;font-weight:600;color:rgba(255,255,255,0.70);letter-spacing:0.02em}
.footer-brand-sub{font-size:11px;color:rgba(255,255,255,0.28);letter-spacing:0.1em;text-transform:uppercase;margin-top:2px}
.footer-copy{font-size:12px;color:rgba(255,255,255,0.25);letter-spacing:0.02em;text-align:right}
@media(max-width:600px){.footer-inner{flex-direction:column;align-items:flex-start;gap:12px}.footer-copy{text-align:left}}
</style>
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
