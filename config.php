<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'GDemonlist',
        'tagline' => 'A competitive Geometry Dash ranking platform for precise list management and verified records.',
        'base_url' => '/demonlist',
        'public_url' => '', // Optional absolute URL for embeds, e.g. https://your-gdps.com/demonlist
        'timezone' => 'Asia/Ho_Chi_Minh',
        'debug' => false,
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'demonlist',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'discord' => [
        'webhook_url' => '', // Discord webhook URL to receive notifications
        'server_widget_url' => '', // Full Discord widget URL (https://discord.com/widget?id=...)
        'server_id' => '', // Server ID used when server_widget_url is empty
        'server_theme' => 'dark', // dark | light
    ],
];
