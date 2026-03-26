<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!method_is_post() || !validate_csrf($_POST['_token'] ?? null)) {
    flash('error', 'Invalid logout request.');
    redirect('index.php');
}

logout_user();
unset($_SESSION['admin_logged_in']);
flash('success', 'You have been logged out.');
redirect('index.php');
