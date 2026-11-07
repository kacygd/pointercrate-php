<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$errors = [];
$user = null;

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

    if (method_is_post()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            $errors[] = t('auth.login.error_token');
        } else {
            email_mark_verified($pdo, (int) $user['id']);
            flash('success', t('verify.success', ['name' => (string) $user['username']]));
            redirect('login.php');
        }
    }
} catch (Throwable) {
    $errors[] = t('verify.error_failed');
}

render_header(t('verify.title'), 'login');
?>
<section class="panel panel-narrow fade">
    <div class="panel-head">
        <h1><?= e(t('verify.heading')) ?></h1>
        <p><?= e(t('verify.confirm', ['name' => (string) ($user['username'] ?? '')])) ?></p>
    </div>
    <?php if ($errors !== []): ?>
        <div class="info-red"><?= e(implode(' ', $errors)) ?></div>
    <?php endif; ?>
    <form class="stack-form" method="post" action="<?= e(base_url('verify.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <button class="button blue hover" type="submit"><?= e(t('verify.button')) ?></button>
    </form>
</section>
<?php render_footer(); ?>
