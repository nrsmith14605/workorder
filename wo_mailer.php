<?php
/**
 * wo_mailer.php
 * Place this file inside your workorder/ folder, next to main.php.
 * PHPMailer lives at workorder/phpmailer/src/
 * Uses A2 Hosting local SMTP (no Gmail credentials needed).
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';

/**
 * Look up every active BT user assigned to $building and email them,
 * then send a confirmation copy to the submitter.
 *
 * Uses FIND_IN_SET() so it works whether building is stored as a single
 * value ("CHS") or a comma-separated list ("CHS,BHS,THS").
 */
function send_tech_wo_email(
    mysqli $conn,
    string $wo_num,
    string $building,
    string $room,
    string $problem_type,
    string $description,
    string $priority,
    string $submitted_name,
    string $submitted_by
): void {

    // ── 1. Find all active Building Techs for this building ───────────
    // FIND_IN_SET handles both single ("CHS") and comma-separated ("CHS,BHS") building values
    $stmt = $conn->prepare(
        "SELECT first_name, last_name, email
           FROM users
          WHERE role = 'BT'
            AND FIND_IN_SET(? COLLATE utf8mb4_unicode_ci, building COLLATE utf8mb4_unicode_ci)
            AND active = 1"
    );
    if (!$stmt) return;
    $stmt->bind_param('s', $building);
    $stmt->execute();
    $result = $stmt->get_result();
    $techs  = [];
    while ($row = $result->fetch_assoc()) {
        $techs[] = $row;
    }
    $stmt->close();

    // ── 2. Priority colors ────────────────────────────────────────────
    $pri_colors = [
        'Low'    => ['bg' => '#d1fae5', 'text' => '#065f46'],
        'Mid'    => ['bg' => '#dbeafe', 'text' => '#1e40af'],
        'High'   => ['bg' => '#fef3c7', 'text' => '#92400e'],
        'Urgent' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
    ];
    $pri_bg   = $pri_colors[$priority]['bg']   ?? '#f1f5f9';
    $pri_text = $pri_colors[$priority]['text'] ?? '#475569';

    // ── 3. Build the View Work Order URL ──────────────────────────────
    $view_url = 'https://chs-cs.com/workorder/main.php?wo=' . urlencode($wo_num);

    // ── 4. Escape all values for HTML ─────────────────────────────────
    $desc_safe     = nl2br(htmlspecialchars($description));
    $problem_safe  = htmlspecialchars($problem_type);
    $building_safe = htmlspecialchars($building);
    $room_safe     = htmlspecialchars($room);
    $name_safe     = htmlspecialchars($submitted_name);
    $email_safe    = htmlspecialchars($submitted_by);
    $pri_safe      = htmlspecialchars($priority);
    $wo_safe       = htmlspecialchars($wo_num);

    // ── 5. Shared HTML body builder ───────────────────────────────────
    // $header_label controls the banner text (differs for tech vs submitter)
    $build_html = function(string $header_label) use (
        $wo_safe, $pri_bg, $pri_text, $pri_safe, $view_url,
        $building_safe, $room_safe, $problem_safe,
        $name_safe, $email_safe, $desc_safe
    ): string {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Technology Work Order - {$wo_safe}</title>
</head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased">

  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f0f4f8;padding:40px 16px">
    <tr><td align="center">

      <!-- Card -->
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 32px rgba(0,0,0,0.10)">

        <!-- HEADER -->
        <tr>
          <td style="background:#0B1F2E;padding:28px 36px 24px">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td>
                  <div style="font-size:10px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:rgba(27,188,212,0.65);margin-bottom:6px">WARRICK COUNTY SCHOOL CORPORATION</div>
                  <div style="font-size:22px;font-weight:700;color:#ffffff;letter-spacing:.01em">Work Order System</div>
                </td>
                <td align="right" valign="middle">
                  <div style="display:inline-block;background:rgba(27,188,212,0.12);border:1px solid rgba(27,188,212,0.25);border-radius:8px;padding:6px 14px">
                    <span style="font-size:11px;font-weight:700;color:#29b6d5;letter-spacing:.05em">TECHNOLOGY</span>
                  </div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- LABEL ROW -->
        <tr>
          <td style="background:#f0f9fb;padding:12px 36px;border-bottom:1px solid #e8ecf0">
            <div style="font-size:13px;color:#1a9ab8;font-weight:600">{$header_label}</div>
          </td>
        </tr>

        <!-- WO NUMBER + PRIORITY -->
        <tr>
          <td style="padding:32px 36px 24px">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td>
                  <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#aab0bb;margin-bottom:6px">Work Order</div>
                  <div style="font-size:36px;font-weight:700;color:#29b6d5;letter-spacing:.02em;line-height:1">{$wo_safe}</div>
                </td>
                <td align="right" valign="top">
                  <div style="display:inline-block;background:{$pri_bg};border-radius:8px;padding:8px 18px">
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:{$pri_text};margin-bottom:2px">Priority</div>
                    <div style="font-size:16px;font-weight:700;color:{$pri_text}">{$pri_safe}</div>
                  </div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- CTA BUTTON -->
        <tr>
          <td style="padding:0 36px 28px" align="center">
            <table cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td align="center" style="background:#29b6d5;border-radius:10px">
                  <a href="{$view_url}"
                     style="display:inline-block;padding:14px 36px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;letter-spacing:.02em;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif">
                    View Work Order
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- DIVIDER -->
        <tr><td style="padding:0 36px"><div style="height:1px;background:#f0f4f8"></div></td></tr>

        <!-- DETAILS GRID -->
        <tr>
          <td style="padding:24px 36px 8px">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#aab0bb;margin-bottom:16px">Request Details</div>
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td width="50%" style="padding:0 12px 18px 0;vertical-align:top">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;margin-bottom:5px">Building</div>
                  <div style="font-size:15px;font-weight:600;color:#1a1a2e">{$building_safe}</div>
                </td>
                <td width="50%" style="padding:0 0 18px 0;vertical-align:top">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;margin-bottom:5px">Room / Location</div>
                  <div style="font-size:15px;font-weight:600;color:#1a1a2e">{$room_safe}</div>
                </td>
              </tr>
              <tr>
                <td width="50%" style="padding:0 12px 18px 0;vertical-align:top">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;margin-bottom:5px">Problem Type</div>
                  <div style="font-size:15px;font-weight:600;color:#1a1a2e">{$problem_safe}</div>
                </td>
                <td width="50%" style="padding:0 0 18px 0;vertical-align:top">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;margin-bottom:5px">Submitted By</div>
                  <div style="font-size:15px;font-weight:600;color:#1a1a2e">{$name_safe}</div>
                  <div style="font-size:12px;color:#6b7a8d;margin-top:2px">{$email_safe}</div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- DESCRIPTION -->
        <tr>
          <td style="padding:0 36px 28px">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#aab0bb;margin-bottom:10px">Description</div>
            <div style="background:#f8f9fa;border-left:3px solid #29b6d5;border-radius:0 9px 9px 0;padding:16px 18px;font-size:14px;color:#3d4f5e;line-height:1.75">{$desc_safe}</div>
          </td>
        </tr>

        <!-- FOOTER -->
        <tr>
          <td style="background:#f8f9fa;border-top:1px solid #e8ecf0;padding:18px 36px;text-align:center">
            <div style="font-size:11px;color:#aab0bb;line-height:1.7">
              This is an automated message from the <strong style="color:#6b7a8d">Warrick County Work Order System</strong>.<br>
              Please do not reply to this email.
            </div>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>

</body>
</html>
HTML;
    };

    // ── 6. Plain-text fallback ────────────────────────────────────────
    $plain_tech = "NEW TECHNOLOGY WORK ORDER\n"
                . "==========================\n\n"
                . "Work Order:   {$wo_num}\n"
                . "Priority:     {$priority}\n\n"
                . "Building:     {$building}\n"
                . "Room:         {$room}\n"
                . "Problem Type: {$problem_type}\n"
                . "Submitted By: {$submitted_name} ({$submitted_by})\n\n"
                . "Description:\n{$description}\n\n"
                . "View this work order:\n{$view_url}\n\n"
                . "---\nWarrick County Work Order System (automated — do not reply)";

    $plain_submitter = "YOUR WORK ORDER HAS BEEN SUBMITTED\n"
                     . "====================================\n\n"
                     . "Work Order:   {$wo_num}\n"
                     . "Priority:     {$priority}\n\n"
                     . "Building:     {$building}\n"
                     . "Room:         {$room}\n"
                     . "Problem Type: {$problem_type}\n\n"
                     . "Description:\n{$description}\n\n"
                     . "Your request is pending approval. You can view its status here:\n{$view_url}\n\n"
                     . "---\nWarrick County Work Order System (automated — do not reply)";

    // ── 7. SMTP helper ────────────────────────────────────────────────
    $make_mailer = function(): PHPMailer {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'localhost';
        $mail->SMTPAuth   = false;
        $mail->SMTPSecure = false;
        $mail->Port       = 25;
        $mail->setFrom('wcsc.workorders@chs-cs.com', 'Warrick County Work Order System');
        $mail->addReplyTo('noreply@chs-cs.com', 'Do Not Reply');
        $mail->isHTML(true);
        return $mail;
    };

    // ── 8. Send to each Building Tech ────────────────────────────────
    $tech_html = $build_html('New technology work order submitted and awaiting your attention');
    foreach ($techs as $tech) {
        try {
            $mail = $make_mailer();
            $mail->addAddress($tech['email'], $tech['first_name'] . ' ' . $tech['last_name']);
            $mail->Subject = 'New Technology Work Order ' . $wo_num;
            $mail->Body    = $tech_html;
            $mail->AltBody = $plain_tech;
            $mail->send();
        } catch (Exception $e) {
            error_log("WO Mailer [tech]: failed to send to {$tech['email']} — " . $e->getMessage());
        }
    }

    // ── 9. Send confirmation to submitter ─────────────────────────────
    $submitter_html = $build_html('Your work order has been submitted and is pending approval');
    try {
        $mail = $make_mailer();
        $mail->addAddress($submitted_by, $submitted_name);
        $mail->Subject = 'Work Order Submitted - ' . $wo_num;
        $mail->Body    = $submitter_html;
        $mail->AltBody = $plain_submitter;
        $mail->send();
    } catch (Exception $e) {
        error_log("WO Mailer [submitter]: failed to send to {$submitted_by} — " . $e->getMessage());
    }
}

// ════════════════════════════════════════════════════════════════════════════
// MAINTENANCE SUBMISSION EMAIL → BP(s) for the building + submitter confirmation
// ════════════════════════════════════════════════════════════════════════════
function send_maintenance_submit_email(
    mysqli $conn,
    string $wo_num,
    string $building,
    string $room,
    string $problem_type,
    string $description,
    string $priority,
    string $submitted_name,
    string $submitted_by
): void {

    // ── 1. Find active Building Principals for this building ──────────
    $stmt = $conn->prepare(
        "SELECT first_name, last_name, email FROM users WHERE role='BP' AND building=? AND active=1"
    );
    if (!$stmt) return;
    $stmt->bind_param('s', $building);
    $stmt->execute();
    $bps = [];
    $r   = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $bps[] = $row;
    $stmt->close();

    if (empty($bps)) return;

    // ── 2. Priority colors ────────────────────────────────────────────
    $pri_colors = [
        'Low'    => ['bg' => '#d1fae5', 'text' => '#065f46'],
        'Mid'    => ['bg' => '#dbeafe', 'text' => '#1e40af'],
        'High'   => ['bg' => '#fef3c7', 'text' => '#92400e'],
        'Urgent' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
    ];
    $pri_bg   = $pri_colors[$priority]['bg']   ?? '#f1f5f9';
    $pri_text = $pri_colors[$priority]['text'] ?? '#475569';

    // ── 3. Shared values ──────────────────────────────────────────────
    $view_url      = 'https://chs-cs.com/workorder/main.php?wo=' . urlencode($wo_num);
    $desc_safe     = nl2br(htmlspecialchars($description));
    $problem_safe  = htmlspecialchars($problem_type);
    $building_safe = htmlspecialchars($building);
    $room_safe     = htmlspecialchars($room);
    $name_safe     = htmlspecialchars($submitted_name);
    $email_safe    = htmlspecialchars($submitted_by);
    $pri_safe      = htmlspecialchars($priority);
    $wo_safe       = htmlspecialchars($wo_num);

    // ── 4. HTML builder ───────────────────────────────────────────────
    $build_html = function(string $header_label) use (
        $wo_safe, $pri_bg, $pri_text, $pri_safe, $view_url,
        $building_safe, $room_safe, $problem_safe,
        $name_safe, $email_safe, $desc_safe
    ): string {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Maintenance Work Order - {$wo_safe}</title>
</head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased">
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f0f4f8;padding:40px 16px">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 32px rgba(0,0,0,0.10)">

        <!-- HEADER -->
        <tr>
          <td style="background:#0B1F2E;padding:28px 36px 24px">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td>
                  <div style="font-size:10px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:rgba(27,188,212,0.65);margin-bottom:6px">WARRICK COUNTY SCHOOL CORPORATION</div>
                  <div style="font-size:22px;font-weight:700;color:#ffffff;letter-spacing:.01em">Work Order System</div>
                </td>
                <td align="right" valign="middle">
                  <div style="display:inline-block;background:rgba(251,191,36,0.15);border:1px solid rgba(251,191,36,0.3);border-radius:8px;padding:6px 14px">
                    <span style="font-size:11px;font-weight:700;color:#f59e0b;letter-spacing:.05em">MAINTENANCE</span>
                  </div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- LABEL ROW -->
        <tr>
          <td style="background:#fffbeb;padding:12px 36px;border-bottom:1px solid #e8ecf0">
            <div style="font-size:13px;color:#92400e;font-weight:600">{$header_label}</div>
          </td>
        </tr>

        <!-- WO NUMBER + PRIORITY -->
        <tr>
          <td style="padding:32px 36px 24px">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td>
                  <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#aab0bb;margin-bottom:6px">Work Order</div>
                  <div style="font-size:36px;font-weight:700;color:#29b6d5;letter-spacing:.02em;line-height:1">{$wo_safe}</div>
                </td>
                <td align="right" valign="top">
                  <div style="display:inline-block;background:{$pri_bg};border-radius:8px;padding:8px 18px">
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:{$pri_text};margin-bottom:2px">Priority</div>
                    <div style="font-size:16px;font-weight:700;color:{$pri_text}">{$pri_safe}</div>
                  </div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- CTA BUTTON -->
        <tr>
          <td style="padding:0 36px 28px" align="center">
            <table cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td align="center" style="background:#29b6d5;border-radius:10px">
                  <a href="{$view_url}" style="display:inline-block;padding:14px 36px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;letter-spacing:.02em;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif">View Work Order</a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr><td style="padding:0 36px"><div style="height:1px;background:#f0f4f8"></div></td></tr>

        <!-- DETAILS GRID -->
        <tr>
          <td style="padding:24px 36px 8px">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#aab0bb;margin-bottom:16px">Request Details</div>
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td width="50%" style="padding:0 12px 18px 0;vertical-align:top">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;margin-bottom:5px">Building</div>
                  <div style="font-size:15px;font-weight:600;color:#1a1a2e">{$building_safe}</div>
                </td>
                <td width="50%" style="padding:0 0 18px 0;vertical-align:top">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;margin-bottom:5px">Room / Location</div>
                  <div style="font-size:15px;font-weight:600;color:#1a1a2e">{$room_safe}</div>
                </td>
              </tr>
              <tr>
                <td width="50%" style="padding:0 12px 18px 0;vertical-align:top">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;margin-bottom:5px">Problem Type</div>
                  <div style="font-size:15px;font-weight:600;color:#1a1a2e">{$problem_safe}</div>
                </td>
                <td width="50%" style="padding:0 0 18px 0;vertical-align:top">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;margin-bottom:5px">Submitted By</div>
                  <div style="font-size:15px;font-weight:600;color:#1a1a2e">{$name_safe}</div>
                  <div style="font-size:12px;color:#6b7a8d;margin-top:2px">{$email_safe}</div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- DESCRIPTION -->
        <tr>
          <td style="padding:0 36px 28px">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#aab0bb;margin-bottom:10px">Description</div>
            <div style="background:#f8f9fa;border-left:3px solid #f59e0b;border-radius:0 9px 9px 0;padding:16px 18px;font-size:14px;color:#3d4f5e;line-height:1.75">{$desc_safe}</div>
          </td>
        </tr>

        <!-- FOOTER -->
        <tr>
          <td style="background:#f8f9fa;border-top:1px solid #e8ecf0;padding:18px 36px;text-align:center">
            <div style="font-size:11px;color:#aab0bb;line-height:1.7">
              This is an automated message from the <strong style="color:#6b7a8d">Warrick County Work Order System</strong>.<br>
              Please do not reply to this email.
            </div>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    };

    // ── 5. Plain-text fallbacks ───────────────────────────────────────
    $plain_bp = "NEW MAINTENANCE WORK ORDER — ACTION REQUIRED\n"
              . "=============================================\n\n"
              . "Work Order:   {$wo_num}\n"
              . "Priority:     {$priority}\n\n"
              . "Building:     {$building}\n"
              . "Room:         {$room}\n"
              . "Problem Type: {$problem_type}\n"
              . "Submitted By: {$submitted_name} ({$submitted_by})\n\n"
              . "Description:\n{$description}\n\n"
              . "This work order requires your approval or rejection.\n"
              . "View it here: {$view_url}\n\n"
              . "---\nWarrick County Work Order System (automated — do not reply)";

    $plain_submitter = "YOUR WORK ORDER HAS BEEN SUBMITTED\n"
                     . "====================================\n\n"
                     . "Work Order:   {$wo_num}\n"
                     . "Priority:     {$priority}\n\n"
                     . "Building:     {$building}\n"
                     . "Room:         {$room}\n"
                     . "Problem Type: {$problem_type}\n\n"
                     . "Description:\n{$description}\n\n"
                     . "Your request is pending approval. You can view its status here:\n{$view_url}\n\n"
                     . "---\nWarrick County Work Order System (automated — do not reply)";

    // ── 6. Send to each Building Principal ───────────────────────────
    $bp_html = $build_html('New maintenance work order submitted — awaiting your approval');
    foreach ($bps as $bp) {
        try {
            $mail = _make_mailer();
            $mail->addAddress($bp['email'], $bp['first_name'] . ' ' . $bp['last_name']);
            $mail->Subject = 'New Maintenance Work Order ' . $wo_num;
            $mail->Body    = $bp_html;
            $mail->AltBody = $plain_bp;
            $mail->send();
        } catch (Exception $e) {
            error_log("WO Mailer [maint-bp]: failed to send to {$bp['email']} — " . $e->getMessage());
        }
    }

    // ── 7. Send confirmation to submitter ─────────────────────────────
    $sub_html = $build_html('Your work order has been submitted and is pending approval');
    try {
        $mail = _make_mailer();
        $mail->addAddress($submitted_by, $submitted_name);
        $mail->Subject = 'Work Order Submitted - ' . $wo_num;
        $mail->Body    = $sub_html;
        $mail->AltBody = $plain_submitter;
        $mail->send();
    } catch (Exception $e) {
        error_log("WO Mailer [maint-submitter]: failed to send to {$submitted_by} — " . $e->getMessage());
    }
}

// ════════════════════════════════════════════════════════════════════════════
// SHARED SMTP FACTORY (used by all functions below)
// ════════════════════════════════════════════════════════════════════════════
function _make_mailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'localhost';
    $mail->SMTPAuth   = false;
    $mail->SMTPSecure = false;
    $mail->Port       = 25;
    $mail->setFrom('wcsc.workorders@chs-cs.com', 'Warrick County Work Order System');
    $mail->addReplyTo('noreply@chs-cs.com', 'Do Not Reply');
    $mail->isHTML(true);
    return $mail;
}

// ════════════════════════════════════════════════════════════════════════════
// SHARED HTML SHELL (status-update emails all use this wrapper)
// ════════════════════════════════════════════════════════════════════════════
function _build_status_email(
    string $wo_num,
    string $header_label,
    string $banner_color,
    string $status_label,
    string $status_bg,
    string $status_text,
    string $building,
    string $room,
    string $problem_type,
    string $priority,
    string $submitted_name,
    string $actor_name,
    string $actor_role,
    string $note
): string {

    $pri_colors = [
        'Low'    => ['bg' => '#d1fae5', 'text' => '#065f46'],
        'Mid'    => ['bg' => '#dbeafe', 'text' => '#1e40af'],
        'High'   => ['bg' => '#fef3c7', 'text' => '#92400e'],
        'Urgent' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
    ];
    $pri_bg   = $pri_colors[$priority]['bg']   ?? '#f1f5f9';
    $pri_text = $pri_colors[$priority]['text'] ?? '#475569';

    $view_url    = 'https://chs-cs.com/workorder/main.php?wo=' . urlencode($wo_num);
    $wo_safe     = htmlspecialchars($wo_num);
    $bldg_safe   = htmlspecialchars($building);
    $room_safe   = htmlspecialchars($room);
    $prob_safe   = htmlspecialchars($problem_type);
    $pri_safe    = htmlspecialchars($priority);
    $name_safe   = htmlspecialchars($submitted_name);
    $actor_safe  = htmlspecialchars($actor_name);
    $role_safe   = htmlspecialchars($actor_role);
    $note_safe   = $note ? nl2br(htmlspecialchars($note)) : '';

    $note_block = $note_safe ? <<<HTML
        <!-- NOTE -->
        <tr>
          <td style="padding:0 36px 28px">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#aab0bb;margin-bottom:10px">Message from {$role_safe}</div>
            <div style="background:#f8f9fa;border-left:3px solid {$banner_color};border-radius:0 9px 9px 0;padding:16px 18px;font-size:14px;color:#3d4f5e;line-height:1.75">{$note_safe}</div>
          </td>
        </tr>
HTML : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Work Order Update - {$wo_safe}</title>
</head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f0f4f8;padding:40px 16px">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 32px rgba(0,0,0,0.10)">

        <!-- HEADER -->
        <tr>
          <td style="background:#0B1F2E;padding:28px 36px 24px">
            <div style="font-size:10px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:rgba(27,188,212,0.65);margin-bottom:6px">WARRICK COUNTY SCHOOL CORPORATION</div>
            <div style="font-size:22px;font-weight:700;color:#ffffff;letter-spacing:.01em">Work Order System</div>
          </td>
        </tr>

        <!-- LABEL ROW -->
        <tr>
          <td style="background:{$banner_color};padding:12px 36px">
            <div style="font-size:13px;color:#ffffff;font-weight:700">{$header_label}</div>
          </td>
        </tr>

        <!-- WO + STATUS -->
        <tr>
          <td style="padding:32px 36px 24px">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td>
                  <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#aab0bb;margin-bottom:6px">Work Order</div>
                  <div style="font-size:36px;font-weight:700;color:#29b6d5;letter-spacing:.02em;line-height:1">{$wo_safe}</div>
                </td>
                <td align="right" valign="top">
                  <div style="display:inline-block;background:{$status_bg};border-radius:8px;padding:8px 18px">
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:{$status_text};margin-bottom:2px">Status</div>
                    <div style="font-size:16px;font-weight:700;color:{$status_text}">{$status_label}</div>
                  </div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- VIEW BUTTON -->
        <tr>
          <td style="padding:0 36px 28px" align="center">
            <table cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td align="center" style="background:#29b6d5;border-radius:10px">
                  <a href="{$view_url}" style="display:inline-block;padding:14px 36px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none">View Work Order</a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr><td style="padding:0 36px"><div style="height:1px;background:#f0f4f8"></div></td></tr>

        <!-- DETAILS -->
        <tr>
          <td style="padding:24px 36px 8px">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#aab0bb;margin-bottom:16px">Request Details</div>
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td width="50%" style="padding:0 12px 18px 0;vertical-align:top">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;margin-bottom:5px">Building</div>
                  <div style="font-size:15px;font-weight:600;color:#1a1a2e">{$bldg_safe}</div>
                </td>
                <td width="50%" style="padding:0 0 18px 0;vertical-align:top">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;margin-bottom:5px">Room / Location</div>
                  <div style="font-size:15px;font-weight:600;color:#1a1a2e">{$room_safe}</div>
                </td>
              </tr>
              <tr>
                <td width="50%" style="padding:0 12px 18px 0;vertical-align:top">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;margin-bottom:5px">Problem Type</div>
                  <div style="font-size:15px;font-weight:600;color:#1a1a2e">{$prob_safe}</div>
                </td>
                <td width="50%" style="padding:0 0 18px 0;vertical-align:top">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;margin-bottom:5px">Priority</div>
                  <div style="display:inline-block;background:{$pri_bg};border-radius:6px;padding:4px 12px;font-size:13px;font-weight:700;color:{$pri_text}">{$pri_safe}</div>
                </td>
              </tr>
              <tr>
                <td colspan="2" style="padding:0 0 18px 0;vertical-align:top">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;margin-bottom:5px">Actioned By</div>
                  <div style="font-size:15px;font-weight:600;color:#1a1a2e">{$actor_safe} <span style="font-size:12px;color:#6b7a8d">({$role_safe})</span></div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {$note_block}

        <!-- FOOTER -->
        <tr>
          <td style="background:#f8f9fa;border-top:1px solid #e8ecf0;padding:18px 36px;text-align:center">
            <div style="font-size:11px;color:#aab0bb;line-height:1.7">
              This is an automated message from the <strong style="color:#6b7a8d">Warrick County Work Order System</strong>.<br>
              Please do not reply to this email.
            </div>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

// ════════════════════════════════════════════════════════════════════════════
// REJECTION EMAIL → submitter
// ════════════════════════════════════════════════════════════════════════════
function send_rejection_email(
    string $wo_num,
    string $submitted_name,
    string $submitted_by,
    string $building,
    string $room,
    string $problem_type,
    string $priority,
    string $note,
    string $actor_name,
    string $actor_role
): void {
    $html = _build_status_email(
        $wo_num,
        'Your work order has been rejected — see details below',
        '#dc2626',
        'Rejected',
        '#fee2e2',
        '#991b1b',
        $building, $room, $problem_type, $priority,
        $submitted_name, $actor_name, $actor_role, $note
    );

    $plain = "WORK ORDER REJECTED\n"
           . "===================\n\n"
           . "Work Order: {$wo_num}\n"
           . "Building:   {$building} / {$room}\n"
           . "Problem:    {$problem_type}\n\n"
           . "This work order was rejected by {$actor_name} ({$actor_role}).\n\n"
           . ($note ? "Message:\n{$note}\n\n" : '')
           . "View your work order: https://chs-cs.com/workorder/main.php?wo=" . urlencode($wo_num) . "\n\n"
           . "---\nWarrick County Work Order System (automated — do not reply)";

    try {
        $mail = _make_mailer();
        $mail->addAddress($submitted_by, $submitted_name);
        $mail->Subject = "Work Order {$wo_num} Has Been Rejected";
        $mail->Body    = $html;
        $mail->AltBody = $plain;
        $mail->send();
    } catch (\Exception $e) {
        error_log("WO Mailer [rejection]: failed to send to {$submitted_by} — " . $e->getMessage());
    }
}

// ════════════════════════════════════════════════════════════════════════════
// COMPLETION EMAIL → submitter
// ════════════════════════════════════════════════════════════════════════════
function send_completion_email(
    string $wo_num,
    string $submitted_name,
    string $submitted_by,
    string $building,
    string $room,
    string $problem_type,
    string $priority,
    string $note,
    string $actor_name,
    string $actor_role
): void {
    $html = _build_status_email(
        $wo_num,
        'Your work order has been completed',
        '#059669',
        'Completed',
        '#d1fae5',
        '#065f46',
        $building, $room, $problem_type, $priority,
        $submitted_name, $actor_name, $actor_role, $note
    );

    $plain = "WORK ORDER COMPLETED\n"
           . "====================\n\n"
           . "Work Order: {$wo_num}\n"
           . "Building:   {$building} / {$room}\n"
           . "Problem:    {$problem_type}\n\n"
           . "Your work order has been completed by {$actor_name} ({$actor_role}).\n\n"
           . ($note ? "Notes:\n{$note}\n\n" : '')
           . "View your work order: https://chs-cs.com/workorder/main.php?wo=" . urlencode($wo_num) . "\n\n"
           . "---\nWarrick County Work Order System (automated — do not reply)";

    try {
        $mail = _make_mailer();
        $mail->addAddress($submitted_by, $submitted_name);
        $mail->Subject = "Work Order {$wo_num} Has Been Completed";
        $mail->Body    = $html;
        $mail->AltBody = $plain;
        $mail->send();
    } catch (\Exception $e) {
        error_log("WO Mailer [completion]: failed to send to {$submitted_by} — " . $e->getMessage());
    }
}

// ════════════════════════════════════════════════════════════════════════════
// BP NOTIFICATION EMAIL — when BT approves, notify Building Principal(s)
// ════════════════════════════════════════════════════════════════════════════
function send_bp_notification_email(
    mysqli $conn,
    string $wo_num,
    string $building,
    string $room,
    string $problem_type,
    string $description,
    string $priority,
    string $submitted_name,
    string $submitted_by,
    string $bt_name,
    string $order_type = 'Technology'
): void {

    // Find active BP (Building Principal) for this building
    $stmt = $conn->prepare(
        "SELECT first_name, last_name, email
           FROM users
          WHERE role = 'BP'
            AND building = ?
            AND active = 1"
    );
    if (!$stmt) return;
    $stmt->bind_param('s', $building);
    $stmt->execute();
    $result = $stmt->get_result();
    $admins = [];
    while ($row = $result->fetch_assoc()) $admins[] = $row;
    $stmt->close();

    if (empty($admins)) return;

    $html = _build_status_email(
        $wo_num,
        "{$order_type} work order approved by tech — awaiting your review",
        '#1a9ab8',
        'Approved',
        '#e6f7fb',
        '#1a9ab8',
        $building, $room, $problem_type, $priority,
        $submitted_name, $bt_name, 'Building Tech',
        "This work order was reviewed and approved by {$bt_name} and now requires your attention."
    );

    $plain = "WORK ORDER APPROVED BY TECH — ACTION REQUIRED ({$order_type})\n"
           . "==============================================\n\n"
           . "Work Order: {$wo_num}\n"
           . "Building:   {$building} / {$room}\n"
           . "Problem:    {$problem_type}\n"
           . "Priority:   {$priority}\n"
           . "Submitted By: {$submitted_name} ({$submitted_by})\n\n"
           . "Approved by tech: {$bt_name}\n\n"
           . "This work order now requires your approval or rejection.\n"
           . "View it here: https://chs-cs.com/workorder/main.php?wo=" . urlencode($wo_num) . "\n\n"
           . "---\nWarrick County Work Order System (automated — do not reply)";

    foreach ($admins as $admin) {
        try {
            $mail = _make_mailer();
            $mail->addAddress($admin['email'], $admin['first_name'] . ' ' . $admin['last_name']);
            $mail->Subject = "Work Order {$wo_num} Requires Your Approval";
            $mail->Body    = $html;
            $mail->AltBody = $plain;
            $mail->send();
        } catch (\Exception $e) {
            error_log("WO Mailer [bp-notify]: failed to send to {$admin['email']} — " . $e->getMessage());
        }
    }
}

// ════════════════════════════════════════════════════════════════════════════
// MANAGER NOTIFICATION EMAIL — when BP approves, notify MT (tech) or MM (maint)
// Administrator never receives emails.
// ════════════════════════════════════════════════════════════════════════════
function send_manager_notification_email(
    mysqli $conn,
    string $wo_num,
    string $building,
    string $room,
    string $problem_type,
    string $description,
    string $priority,
    string $submitted_name,
    string $submitted_by,
    string $bp_name,
    string $order_type = 'Technology'
): void {

    // Fetch MT for tech orders, MM for maintenance — never Admin
    $target_role = ($order_type === 'Technology') ? 'MT' : 'MM';
    $result = $conn->query(
        "SELECT first_name, last_name, email FROM users WHERE role = '{$target_role}' AND active = 1"
    );
    if (!$result) return;
    $managers = [];
    while ($row = $result->fetch_assoc()) $managers[] = $row;
    if (empty($managers)) return;

    $actor_label = ($order_type === 'Technology') ? 'Technology Manager' : 'Maintenance Manager';

    $html = _build_status_email(
        $wo_num,
        "A {$order_type} work order has been approved and requires your assignment",
        '#7c3aed',
        'Awaiting Assignment',
        '#f3e8ff',
        '#6b21a8',
        $building, $room, $problem_type, $priority,
        $submitted_name, $bp_name, 'Building Principal',
        "This work order was reviewed and approved by {$bp_name} and now requires your assignment."
    );

    $plain = "WORK ORDER REQUIRES YOUR ASSIGNMENT\n"
           . "====================================\n\n"
           . "Work Order: {$wo_num}\n"
           . "Type:       {$order_type}\n"
           . "Building:   {$building} / {$room}\n"
           . "Problem:    {$problem_type}\n"
           . "Priority:   {$priority}\n"
           . "Submitted By: {$submitted_name} ({$submitted_by})\n\n"
           . "Approved by: {$bp_name} (Building Principal)\n\n"
           . "Please log in to assign this work order to the appropriate staff.\n"
           . "View it here: https://chs-cs.com/workorder/main.php?wo=" . urlencode($wo_num) . "\n\n"
           . "---\nWarrick County Work Order System (automated — do not reply)";

    foreach ($managers as $mgr) {
        try {
            $mail = _make_mailer();
            $mail->addAddress($mgr['email'], $mgr['first_name'] . ' ' . $mgr['last_name']);
            $mail->Subject = "Work Order {$wo_num} Requires Assignment";
            $mail->Body    = $html;
            $mail->AltBody = $plain;
            $mail->send();
        } catch (\Exception $e) {
            error_log("WO Mailer [mgr-notify]: failed to send to {$mgr['email']} — " . $e->getMessage());
        }
    }
}

// ════════════════════════════════════════════════════════════════════════════
// ASSIGNMENT EMAIL → individual worker
// ════════════════════════════════════════════════════════════════════════════
function send_assignment_email(
    string $wo_num,
    string $worker_name,
    string $worker_email,
    string $building,
    string $room,
    string $problem_type,
    string $description,
    string $priority,
    string $submitted_name,
    string $manager_name,
    string $manager_role = 'Manager',
    string $note = ''
): void {

    $pri_colors = [
        'Low'    => ['bg' => '#d1fae5', 'text' => '#065f46'],
        'Mid'    => ['bg' => '#dbeafe', 'text' => '#1e40af'],
        'High'   => ['bg' => '#fef3c7', 'text' => '#92400e'],
        'Urgent' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
    ];
    $pri_bg   = $pri_colors[$priority]['bg']   ?? '#f1f5f9';
    $pri_text = $pri_colors[$priority]['text'] ?? '#475569';

    $view_url    = 'https://chs-cs.com/workorder/main.php?wo=' . urlencode($wo_num);
    $wo_safe     = htmlspecialchars($wo_num);
    $bldg_safe   = htmlspecialchars($building);
    $room_safe   = htmlspecialchars($room);
    $prob_safe   = htmlspecialchars($problem_type);
    $desc_safe   = nl2br(htmlspecialchars($description));
    $pri_safe    = htmlspecialchars($priority);
    $name_safe   = htmlspecialchars($submitted_name);
    $mgr_safe    = htmlspecialchars($manager_name);
    $worker_safe = htmlspecialchars($worker_name);
    $note_safe   = $note ? nl2br(htmlspecialchars($note)) : '';

    $note_block = $note_safe ? <<<HTML
        <tr>
          <td style="padding:0 36px 28px">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#aab0bb;margin-bottom:10px">Notes from Manager</div>
            <div style="background:#f8f9fa;border-left:3px solid #7c3aed;border-radius:0 9px 9px 0;padding:16px 18px;font-size:14px;color:#3d4f5e;line-height:1.75">{$note_safe}</div>
          </td>
        </tr>
HTML : '';

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Work Order Assigned - {$wo_safe}</title>
</head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f0f4f8;padding:40px 16px">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 32px rgba(0,0,0,0.10)">

        <tr>
          <td style="background:#0B1F2E;padding:28px 36px 24px">
            <div style="font-size:10px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:rgba(27,188,212,0.65);margin-bottom:6px">WARRICK COUNTY SCHOOL CORPORATION</div>
            <div style="font-size:22px;font-weight:700;color:#ffffff;letter-spacing:.01em">Work Order System</div>
          </td>
        </tr>

        <tr>
          <td style="background:#7c3aed;padding:12px 36px">
            <div style="font-size:13px;color:#ffffff;font-weight:700">A work order has been assigned to you</div>
          </td>
        </tr>

        <tr>
          <td style="padding:32px 36px 24px">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td>
                  <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#aab0bb;margin-bottom:6px">Work Order</div>
                  <div style="font-size:36px;font-weight:700;color:#29b6d5;letter-spacing:.02em;line-height:1">{$wo_safe}</div>
                  <div style="font-size:13px;color:#6b7a8d;margin-top:8px">Assigned by {$mgr_safe} ({$manager_role})</div>
                </td>
                <td align="right" valign="top">
                  <div style="display:inline-block;background:{$pri_bg};border-radius:8px;padding:8px 18px">
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:{$pri_text};margin-bottom:2px">Priority</div>
                    <div style="font-size:16px;font-weight:700;color:{$pri_text}">{$pri_safe}</div>
                  </div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding:0 36px 28px" align="center">
            <table cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td align="center" style="background:#29b6d5;border-radius:10px">
                  <a href="{$view_url}" style="display:inline-block;padding:14px 36px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none">View Work Order</a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr><td style="padding:0 36px"><div style="height:1px;background:#f0f4f8"></div></td></tr>

        <tr>
          <td style="padding:24px 36px 8px">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#aab0bb;margin-bottom:16px">Request Details</div>
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td width="50%" style="padding:0 12px 18px 0;vertical-align:top">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;margin-bottom:5px">Building</div>
                  <div style="font-size:15px;font-weight:600;color:#1a1a2e">{$bldg_safe}</div>
                </td>
                <td width="50%" style="padding:0 0 18px 0;vertical-align:top">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;margin-bottom:5px">Room / Location</div>
                  <div style="font-size:15px;font-weight:600;color:#1a1a2e">{$room_safe}</div>
                </td>
              </tr>
              <tr>
                <td width="50%" style="padding:0 12px 18px 0;vertical-align:top">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;margin-bottom:5px">Problem Type</div>
                  <div style="font-size:15px;font-weight:600;color:#1a1a2e">{$prob_safe}</div>
                </td>
                <td width="50%" style="padding:0 0 18px 0;vertical-align:top">
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#aab0bb;margin-bottom:5px">Submitted By</div>
                  <div style="font-size:15px;font-weight:600;color:#1a1a2e">{$name_safe}</div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding:0 36px 28px">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#aab0bb;margin-bottom:10px">Description</div>
            <div style="background:#f8f9fa;border-left:3px solid #29b6d5;border-radius:0 9px 9px 0;padding:16px 18px;font-size:14px;color:#3d4f5e;line-height:1.75">{$desc_safe}</div>
          </td>
        </tr>

        {$note_block}

        <tr>
          <td style="background:#f8f9fa;border-top:1px solid #e8ecf0;padding:18px 36px;text-align:center">
            <div style="font-size:11px;color:#aab0bb;line-height:1.7">
              This is an automated message from the <strong style="color:#6b7a8d">Warrick County Work Order System</strong>.<br>
              Please do not reply to this email.
            </div>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $plain = "WORK ORDER ASSIGNED TO YOU\n"
           . "===========================\n\n"
           . "Work Order: {$wo_num}\n"
           . "Assigned by: {$manager_name} ({$manager_role})\n\n"
           . "Building:   {$building} / {$room}\n"
           . "Problem:    {$problem_type}\n"
           . "Priority:   {$priority}\n"
           . "Submitted By: {$submitted_name}\n\n"
           . "Description:\n{$description}\n\n"
           . ($note ? "{$manager_role} Notes:\n{$note}\n\n" : '')
           . "View your work order: https://chs-cs.com/workorder/main.php?wo=" . urlencode($wo_num) . "\n\n"
           . "---\nWarrick County Work Order System (automated — do not reply)";

    try {
        $mail = _make_mailer();
        $mail->addAddress($worker_email, $worker_name);
        $mail->Subject = "Work Order {$wo_num} Has Been Assigned to You";
        $mail->Body    = $html;
        $mail->AltBody = $plain;
        $mail->send();
    } catch (\Exception $e) {
        error_log("WO Mailer [assignment]: failed to send to {$worker_email} — " . $e->getMessage());
    }
}