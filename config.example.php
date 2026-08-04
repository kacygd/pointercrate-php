<?php
declare(strict_types=1);

// This file is only a reference. Open setup.php in the browser to generate config.php.
return [
    'app' => [
        'name' => 'My Demonlist',
        'brand_mode' => 2, // 1 = text, 2 = logo
        'logo_path' => 'logo.png',
        'logo_height' => 22,
        'logo_max_width' => 130,
        'tagline' => 'A competitive Geometry Dash ranking platform.',
        'base_url' => '/demonlist',
        'public_url' => '', // Optional absolute URL for embeds, e.g. https://your-domain.com/demonlist
        'timezone' => 'UTC',
        'default_language' => 'en',
        'debug' => false,
        'updated' => 0,
        'installation_complete' => false,
    ],
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'demonlist',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'discord' => [
        'webhook_url' => '', // Optional: paste Discord webhook URL to receive notifications
        'bot_token' => '', // Optional: Discord bot token used for account linking DMs. Keep this server-side only.
        'bot_api_base_url' => 'https://discord.com/api/v10',
        'server_widget_url' => '', // Optional: full Discord widget URL (https://discord.com/widget?id=...)
        'server_id' => '', // Optional: server ID used when server_widget_url is empty
        'server_theme' => 'light', // dark | light
    ],
    'security' => [
        'captcha_enabled' => false,
        'setup_captcha_enabled' => true,
        'captcha_login_enabled' => true,
        'captcha_register_enabled' => true,
        'captcha_submit_enabled' => true,
        'captcha_driver' => 'google_recaptcha_v2',
        'recaptcha_site_key' => '',
        'recaptcha_secret_key' => '',
        'recaptcha_verify_url' => 'https://www.google.com/recaptcha/api/siteverify',
        'csrf_enabled' => true,
        'session_cookie_httponly' => true,
        'session_cookie_samesite' => 'Lax',
        'session_cookie_secure' => false,
    ],
];
