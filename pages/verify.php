<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$token = trim((string) ($_GET['token'] ?? ''));

if (!email_features_enabled()) {
    flash('error', t('email.disabled'));
    redirect('login.php');
}

if ($token === '') {
    flash('error', t('verify.error_token'));
    redirect('login.php');
}

try {
    $pdo = db();
    $user = email_find_user_by_verification_token($pdo, $token);

    if ($user === null) {
        flash('error', t('verify.error_token'));
        redirect('login.php');
    }

    if (email_token_expired($user['verify_token_expires_at'] ?? null)) {
        flash('error', t('verify.error_expired'));
        redirect('forgot.php?action=resend');
    }

    email_mark_verified($pdo, (int) $user['id']);
    flash('success', t('verify.success', ['name' => (string) $user['username']]));
    redirect('login.php');
} catch (Throwable) {
    flash('error', t('verify.error_failed'));
    redirect('login.php');
}
