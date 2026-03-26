<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = (string) config('db.host', '127.0.0.1');
    $port = (int) config('db.port', 3306);
    $database = (string) config('db.database', 'demonlist');
    $charset = (string) config('db.charset', 'utf8mb4');
    $username = (string) config('db.username', 'root');
    $password = (string) config('db.password', '');

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $host,
        $port,
        $database,
        $charset
    );

    try {
        $pdo = new PDO(
            $dsn,
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $exception) {
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
