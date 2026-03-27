<?php
declare(strict_types=1);
$config = require __DIR__ . '/config.php';

$dbConfig = $config['db'];

$conn = new mysqli(
    $dbConfig['host'],
    $dbConfig['username'],
    $dbConfig['password'],
    $dbConfig['database'],
    $dbConfig['port']
);

if ($conn->connect_error) {
    die("DB Error: " . $conn->connect_error);
}

function columnExists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

if (!columnExists($conn, "users", "points")) {
    if ($conn->query("ALTER TABLE users ADD points DECIMAL(10,2) NOT NULL DEFAULT 0.00")) {
        echo "Added users.points<br>";
    } else {
        echo "Error adding users.points: " . $conn->error . "<br>";
    }
}

if (!columnExists($conn, "demons", "creator")) {
    if ($conn->query("ALTER TABLE demons ADD creator VARCHAR(160) NULL AFTER requirement")) {
        echo "Added demons.creator<br>";
    } else {
        echo "Error adding demons.creator: " . $conn->error . "<br>";
    }
}

echo "Update completed!";

register_shutdown_function(function () {
    sleep(1);
    @unlink(__FILE__);
});