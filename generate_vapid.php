<?php
require_once __DIR__ . '/vendor/autoload.php';
use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();
echo "\nVAPID_PUBLIC_KEY=" . $keys['publicKey'] . "\n";
echo "VAPID_PRIVATE_KEY=" . $keys['privateKey'] . "\n";

file_put_contents(__DIR__ . '/.env', "\n# WEB PUSH VAPID KEYS\n", FILE_APPEND);
file_put_contents(__DIR__ . '/.env', "VAPID_PUBLIC_KEY=" . $keys['publicKey'] . "\n", FILE_APPEND);
file_put_contents(__DIR__ . '/.env', "VAPID_PRIVATE_KEY=" . $keys['privateKey'] . "\n", FILE_APPEND);

// Ne pas oublier le public key dans le .env front
file_put_contents(__DIR__ . '/../AGheal/.env', "\n# WEB PUSH VAPID PUBLIC KEY\n", FILE_APPEND);
file_put_contents(__DIR__ . '/../AGheal/.env', "VITE_VAPID_PUBLIC_KEY=" . $keys['publicKey'] . "\n", FILE_APPEND);

echo "Keys generated and mapped to .env files.\n";
