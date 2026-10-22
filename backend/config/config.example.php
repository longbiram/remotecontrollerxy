<?php
/**
 * PikaShow Backend Configuration Example
 * This file will be generated automatically during the installation wizard.
 */

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'pikashow_db',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'PikaShow Admin',
        'url' => 'http://localhost/backend',
        'upload_max_mb' => 250,
        'secret_key' => 'CHANGE_ME_SECRET_KEY',
    ],
];
