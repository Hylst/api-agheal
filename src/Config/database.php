<?php
// src/Config/database.php

return [
    'host'     => getenv('DB_HOST') ?: '127.0.0.1',
    'port'     => getenv('DB_PORT') ?: '3306',
    'database' => getenv('DB_NAME') ?: 'agheal',
    'username' => getenv('DB_USER') ?: 'mariadb',
    'password' => getenv('DB_PASSWORD') ?: 'root123',
    'charset'  => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];
