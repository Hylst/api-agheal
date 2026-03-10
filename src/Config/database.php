<?php
// src/Config/database.php

return [
    'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port' => $_ENV['DB_PORT'] ?? '3306',
    'database' => $_ENV['DB_NAME'] ?? 'agheal',
    'username' => $_ENV['DB_USER'] ?? 'root',
    'password' => $_ENV['DB_PASSWORD'] ?? 'root123',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];
