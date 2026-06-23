<?php
require __DIR__ . '/vendor/autoload.php';
$keys = Minishlink\WebPush\VAPID::createVapidKeys();
echo '<p><strong>PUBLIC:</strong><br>' . $keys['publicKey'] . '</p>';
echo '<p><strong>PRIVATE:</strong><br>' . $keys['privateKey'] . '</p>';
