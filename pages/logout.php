<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

if (!method_is_post() || !validate_csrf($_POST['_token'] ?? null)) {
    flash('error', t('auth.logout.invalid'));
    redirect('index.php');
}

logout_user();
flash('success', t('auth.logout.success'));
redirect('index.php');
