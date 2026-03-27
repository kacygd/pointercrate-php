<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$requestedUser = trim((string) ($_GET['user'] ?? ''));
$destination = 'players.php';
if ($requestedUser !== '') {
    $destination .= '?user=' . rawurlencode($requestedUser);
}

redirect($destination);
