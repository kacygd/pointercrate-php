<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $pdo = db_create_pdo_from_config();
    } catch (Throwable $exception) {
        http_response_code(500);
        echo '<h1>Database connection failed</h1>';
        echo '<p>Check MySQL service and config values.</p>';

        if ((bool) config('app.debug', true)) {
            echo '<pre>' . e($exception->getMessage()) . '</pre>';
        }
        exit;
    }

    return $pdo;
}
