<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'GDemonlist',
        'brand_mode' => 2, // 1 = text, 2 = logo
        'logo_path' => 'logo.png',
        'logo_height' => 22,
        'logo_max_width' => 130,
        'tagline' => 'A competitive Geometry Dash ranking platform for precise list management and verified records.',
        'base_url' => '/demonlist',
        'public_url' => '', // Optional absolute URL for embeds, e.g. https://your-domain.com/demonlist
        'timezone' => 'Asia/Ho_Chi_Minh',
        'debug' => false,
        'updated' => 0,
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
        'webhook_url' => '', // Optional: paste Discord webhook URL to receive notifications
        'server_widget_url' => '', // Optional: full Discord widget URL (https://discord.com/widget?id=...)
        'server_id' => '', // Optional: server ID used when server_widget_url is empty
        'server_theme' => 'dark', // dark | light
    ],
];




