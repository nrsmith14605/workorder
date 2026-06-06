<?php
/**
 * report_emp_search.php
 * Employee search endpoint for the reports drawer.
 * Returns JSON array of matching active users.
 *
 * GET params:
 *   q    — search string (min 2 chars, matches first/last name or email)
 *   role — optional role code filter (BT, BP, MW, BC, BM, MT, MM)
 */

session_start();
header('Content-Type: application/json');

// Auth guard — only MT, MM, A can use reports
if (!isset($_SESSION['google_user'])) {
    echo json_encode([]);
    exit;
}
$user_role = $_SESSION['user_role'] ?? 'U';
if (!in_array($user_role, ['A', 'MT', 'MM'])) {
    echo json_encode([]);
    exit;
}

$q    = trim($_GET['q']    ?? '');
$role = trim($_GET['role'] ?? '');

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

require_once __DIR__ . '/../../wo_config.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');

$role_labels = [
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

$search = '%' . $q . '%';

if ($role && array_key_exists($role, $role_labels)) {
    $stmt = $db->prepare(
        "SELECT first_name, last_name, email, role, building
           FROM users
          WHERE active = 1
            AND role = ?
            AND (CONCAT(first_name,' ',last_name) LIKE ? OR email LIKE ?)
          ORDER BY last_name, first_name
          LIMIT 12"
    );
    $stmt->bind_param('sss', $role, $search, $search);
} else {
    $stmt = $db->prepare(
        "SELECT first_name, last_name, email, role, building
           FROM users
          WHERE active = 1
            AND (CONCAT(first_name,' ',last_name) LIKE ? OR email LIKE ?)
          ORDER BY last_name, first_name
          LIMIT 12"
    );
    $stmt->bind_param('ss', $search, $search);
}

$stmt->execute();
$res = $stmt->get_result();

$results = [];
while ($row = $res->fetch_assoc()) {
    $results[] = [
        'name'       => $row['first_name'] . ' ' . $row['last_name'],
        'email'      => $row['email'],
        'role'       => $row['role'],
        'role_label' => $role_labels[$row['role']] ?? $row['role'],
        'building'   => $row['building'] ?? '',
    ];
}

$stmt->close();
$db->close();

echo json_encode($results);
