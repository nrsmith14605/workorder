<?php
function send_push_to_emails(array $emails, string $title, string $body, string $url = ''): void {
    if (empty($emails)) return;

    require_once __DIR__ . '/../../../wo_config.php';
    if (!defined('VAPID_PUBLIC_KEY') || !defined('VAPID_PRIVATE_KEY')) return;

    require_once __DIR__ . '/../vendor/autoload.php';

    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $db->set_charset('utf8mb4');

    $placeholders = implode(',', array_fill(0, count($emails), '?'));
    $types        = str_repeat('s', count($emails));
    $stmt = $db->prepare(
        "SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_email IN ($placeholders)"
    );
    $stmt->bind_param($types, ...$emails);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();

    if (empty($rows)) { $db->close(); return; }

    $webPush = new \Minishlink\WebPush\WebPush([
        'VAPID' => [
            'subject'    => 'mailto:wcsc.workorders@chs-cs.com',
            'publicKey'  => VAPID_PUBLIC_KEY,
            'privateKey' => VAPID_PRIVATE_KEY,
        ],
    ]);

    $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url]);

    foreach ($rows as $row) {
        $sub = \Minishlink\WebPush\Subscription::create([
            'endpoint' => $row['endpoint'],
            'keys'     => ['p256dh' => $row['p256dh'], 'auth' => $row['auth']],
        ]);
        $webPush->queueNotification($sub, $payload);
    }

    $toDelete = [];
    foreach ($webPush->flush() as $report) {
        if (!$report->isSuccess()) {
            $response   = $report->getResponse();
            $statusCode = $response ? $response->getStatusCode() : 0;
            if ($statusCode === 404 || $statusCode === 410) {
                $toDelete[] = $report->getEndpoint();
            }
        }
    }

    foreach ($toDelete as $endpoint) {
        $stmt = $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
        $stmt->bind_param('s', $endpoint);
        $stmt->execute();
        $stmt->close();
    }

    $db->close();
}
