<?php

session_start();

// Loads wo_config.php from one folder above public_html.
// __DIR__ = /public_html/workorder  →  ../.. = above public_html
require_once __DIR__ . '/../../wo_config.php';

$client_id    = GOOGLE_CLIENT_ID;
$redirect_uri = GOOGLE_REDIRECT_URI;

// Handle Google ID token verification (POST from JavaScript)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_token'])) {

    $id_token = $_POST['id_token'];

    // Verify the ID token with Google
    $url      = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($id_token);
    $response = @file_get_contents($url);
    $payload  = json_decode($response, true);

    if (!$payload || isset($payload['error'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid Google token. Please try again.']);
        exit;
    }

    // Audience check
    if ($payload['aud'] !== $client_id) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token audience mismatch.']);
        exit;
    }

    $email = strtolower(trim($payload['email'] ?? ''));

    if (empty($email)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'No email returned from Google.']);
        exit;
    }

    // Domain check — ALLOWED_DOMAIN is defined in wo_config.php
    $allowed_suffix = '@' . ALLOWED_DOMAIN;
    $is_allowed     = str_ends_with($email, $allowed_suffix);

    if (!$is_allowed) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Your account (' . htmlspecialchars($email) . ') is not authorized. Please use your @' . ALLOWED_DOMAIN . ' account.'
        ]);
        exit;
    }

    // Login successful - store session
    $_SESSION['google_user'] = [
        'id'      => $payload['sub'],
        'email'   => $email,
        'name'    => $payload['name']           ?? 'User',
        'given_name'  => $payload['given_name']  ?? '',
        'family_name' => $payload['family_name'] ?? '',
        'picture' => $payload['picture']        ?? '',
        'verified'=> $payload['email_verified'] ?? false
    ];

    // Look up role and building from users table
    $_SESSION['user_role']     = 'U';
    $_SESSION['user_building'] = null;
    $_SESSION['db_debug']      = '';
    try {
        mysqli_report(MYSQLI_REPORT_OFF); // prevent mysqli from throwing exceptions
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            $_SESSION['db_debug'] = 'Connect failed: ' . $conn->connect_error;
        } else {
            $conn->set_charset('utf8mb4');
            $stmt = $conn->prepare("SELECT role, building FROM users WHERE email = ? AND active = 1 LIMIT 1");
            if (!$stmt) {
                $_SESSION['db_debug'] = 'Prepare failed: ' . $conn->error;
            } else {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $stmt->bind_result($db_role, $db_building);
                if ($stmt->fetch()) {
                    $_SESSION['user_role']     = $db_role;
                    $_SESSION['user_building'] = $db_building;
                    $_SESSION['db_debug']      = 'OK: found role=' . $db_role;
                } else {
                    $_SESSION['db_debug'] = 'No row found for email: ' . $email;
                }
                $stmt->close();
            }
            $conn->close();
        }
    } catch (Exception $e) {
        $_SESSION['db_debug'] = 'Exception: ' . $e->getMessage();
    }

    echo json_encode(['success' => true, 'redirect' => $redirect_uri]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Warrick County - Work Order System</title>
<link href="https://fonts.googleapis.com/css2?family=Barlow:wght@300;400;500;600;700&family=Barlow+Condensed:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/styles.css">
<style>
  /* Login feedback layered on top of styles.css */
  #message {
    margin-top: 14px;
    min-height: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    color: #c0392b;
    line-height: 1.45;
    text-align: center;
    display: none;
  }
  #message.visible { display: block; }
  #message.success { color: #27ae60; }
  #message.info    { color: #555;    }

  .google-btn.loading {
    opacity: 0.7;
    pointer-events: none;
    cursor: default;
  }

  /* Real GSI button sits hidden - we click it programmatically */
  #hidden-google-btn {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    opacity: 0;
    pointer-events: none;
  }
</style>
</head>
<body>
<div class="page">
  <div class="card">

    <!-- LEFT PANEL -->
    <div class="left">
      <div class="grid-overlay"></div>
      <div class="left-content">
        <img
          class="logo-img"
          src="images/logo.png"
          alt="Warrick County School Corporation logo"
        />
        <div class="brand-name">Warrick County School Corporation</div>
        <div class="brand-sub">Work Order System</div>
        <div class="divider-line"></div>
        <p class="left-tagline">
          Submit and track facilities requests<br>
          to keep every school in the<br>
          corporation running smoothly.
        </p>
      </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="right">
      <div class="right-inner">

        <div class="welcome-label">Secure Access</div>
        <h1 class="welcome-heading">Sign In</h1>
        <p class="welcome-sub">Use your Warrick County Google account<br>to access the system.</p>

        <!-- Custom-styled button; clicking triggers the hidden GSI button -->
        <a id="custom-google-btn" href="#" class="google-btn" role="button" aria-label="Sign in with Google">
          <svg class="google-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/>
            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
          </svg>
          <span id="btn-label">Continue with Google</span>
        </a>

        <!-- GSI renders its real button here (invisible) -->
        <div id="hidden-google-btn"></div>

        <div class="sep">
          <div class="sep-line"></div>
          <span class="sep-text">DISTRICT ACCOUNTS ONLY</span>
          <div class="sep-line"></div>
        </div>

        <div class="access-note">
          <strong>Access is restricted</strong> to authorized Warrick County personnel. Sign in with your official <strong>@warrick.k12.in.us</strong> Google account.
        </div>

        <!-- Status / error message shown below the button -->
        <div id="message" role="alert" aria-live="polite"></div>

      </div>
      <div class="footer-note">&copy; 2025 Warrick County School Corporation &middot; All rights reserved</div>
    </div>

  </div>
</div>

<!-- Google Identity Services library -->
<script src="https://accounts.google.com/gsi/client" async defer></script>

<script>
// Once the GSI library loads, initialize and render the hidden real button
window.addEventListener('load', function () {
  google.accounts.id.initialize({
    client_id: '<?= htmlspecialchars($client_id) ?>',
    callback:  handleCredentialResponse,
    ux_mode:   'popup'
  });

  // Render the real GSI button inside the hidden div - this powers the popup
  google.accounts.id.renderButton(
    document.getElementById('hidden-google-btn'),
    { theme: 'outline', size: 'large' }
  );
});

// Custom button click -> click the hidden real GSI button to open the Google popup
document.getElementById('custom-google-btn').addEventListener('click', function (e) {
  e.preventDefault();
  var realBtn = document.querySelector('#hidden-google-btn div[role="button"]');
  if (realBtn) {
    realBtn.click();
  } else {
    // Fallback if renderButton has not finished yet
    google.accounts.id.prompt();
  }
});

// GSI calls this after the user selects their Google account
function handleCredentialResponse(response) {
  var msg   = document.getElementById('message');
  var btn   = document.getElementById('custom-google-btn');
  var label = document.getElementById('btn-label');

  // Loading state
  btn.classList.add('loading');
  label.textContent = 'Verifying...';
  msg.className     = 'visible info';
  msg.textContent   = 'Checking your account, please wait...';

  fetch('', {
    method:  'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body:    'id_token=' + encodeURIComponent(response.credential)
  })
  .then(function(res) { return res.json(); })
  .then(function(data) {
    if (data.success) {
      msg.className     = 'visible success';
      msg.textContent   = 'Login successful! Redirecting...';
      label.textContent = 'Redirecting...';
      window.location.href = data.redirect;
    } else {
      // Reset button, show the error from PHP
      btn.classList.remove('loading');
      label.textContent = 'Continue with Google';
      msg.className     = 'visible';
      msg.textContent   = data.message || 'Login failed. Please try again.';
    }
  })
  .catch(function () {
    btn.classList.remove('loading');
    label.textContent = 'Continue with Google';
    msg.className     = 'visible';
    msg.textContent   = 'Network error. Please check your connection and try again.';
  });
}
</script>

</body>
</html>