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
 * Look up every active BT user assigned to $building and email them.
 * A tech covering multiple buildings just has multiple rows in the
 * users table (same email, role=BT, different building each row).
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
    $stmt = $conn->prepare(
        "SELECT first_name, last_name, email
           FROM users
          WHERE role = 'BT'
            AND building = ?
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

    if (empty($techs)) return;

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

    // ── 5. HTML email ─────────────────────────────────────────────────
    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>New Technology Work Order - {$wo_safe}</title>
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

        <!-- CTA BUTTON — above the details so it's immediately visible -->
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

    // ── 6. Plain-text fallback ────────────────────────────────────────
    $plain = "NEW TECHNOLOGY WORK ORDER\n"
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

    // ── 7. Send one email per tech ────────────────────────────────────
    foreach ($techs as $tech) {
        try {
            $mail = new PHPMailer(true);

            // A2 Hosting local SMTP — no auth or encryption needed
            $mail->isSMTP();
            $mail->Host       = 'localhost';
            $mail->SMTPAuth   = false;
            $mail->SMTPSecure = false;
            $mail->Port       = 25;

            $mail->setFrom('wcsc.workorders@chs-cs.com', 'Warrick County Work Order System');
            $mail->addAddress($tech['email'], $tech['first_name'] . ' ' . $tech['last_name']);
            $mail->addReplyTo('noreply@chs-cs.com', 'Do Not Reply');

            $mail->isHTML(true);
            $mail->Subject = 'New Technology Work Order ' . $wo_num;
            $mail->Body    = $html;
            $mail->AltBody = $plain;

            $mail->send();

        } catch (Exception $e) {
            error_log("WO Mailer: failed to send to {$tech['email']} — " . $e->getMessage());
        }
    }
}