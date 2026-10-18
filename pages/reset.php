<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$password = '';
$errors = [];

if (!email_features_enabled()) {
    flash('error', t('email.disabled'));
    redirect('login.php');
}

try {
    $pdo = db();
    $resetUser = email_find_user_by_reset_token($pdo, $token);
} catch (Throwable) {
    $pdo = null;
    $resetUser = null;
}

if ($resetUser === null) {
    flash('error', t('reset.error_token'));
    redirect('forgot.php?action=password');
}

if (email_token_expired($resetUser['reset_token_expires_at'] ?? null)) {
    flash('error', t('reset.error_expired'));
    redirect('forgot.php?action=password');
}

if (method_is_post()) {
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    $resetIpBucket = 'reset_ip:' . current_request_ip();
    $resetWait = rate_limit_seconds_remaining($resetIpBucket, 10, 3600);
    if ($resetWait > 0) {
        $errors[] = t('security.rate_limited', ['minutes' => (int) ceil($resetWait / 60)]);
    }

    if (!validate_csrf($_POST['_token'] ?? null)) {
        $errors[] = t('auth.login.error_token');
    } else {
        rate_limit_record_failure($resetIpBucket, 10, 3600);
    }

    if (!recaptcha_verify_response($_POST['g-recaptcha-response'] ?? null, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'login')) {
        $errors[] = t('common.recaptcha_failed');
    }

    if (strlen($password) < 8) {
        $errors[] = t('auth.register.error_password');
    }

    if ($password !== $passwordConfirm) {
        $errors[] = t('auth.register.error_password_match');
    }

    if ($errors === []) {
        try {
            $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
            $update->execute([
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':id' => (int) $resetUser['id'],
            ]);

            email_clear_reset_token($pdo, (int) $resetUser['id']);
            if (users_has_login_lockout_columns($pdo)) {
                login_clear_failed_attempts((int) $resetUser['id']);
            }
            rate_limit_clear($resetIpBucket);

            flash('success', t('reset.success'));
            redirect('login.php');
        } catch (Throwable) {
            $errors[] = t('reset.error_failed');
        }
    }
}

render_header(t('reset.title'), 'login');
?>
<section class="panel panel-narrow fade">
    <div class="panel-head">
        <h1><?= e(t('reset.heading')) ?></h1>
        <p><?= e(t('reset.intro')) ?></p>
    </div>

    <?php if ($errors !== []): ?>
        <div class="info-red"><?= e(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <form class="stack-form" method="post" action="<?= e(base_url('reset.php?token=' . rawurlencode($token))) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <label class="field">
            <span><?= e(t('reset.password_label')) ?></span>
            <input type="password" name="password" autocomplete="new-password" required>
        </label>

        <label class="field">
            <span><?= e(t('reset.password_confirm_label')) ?></span>
            <input type="password" name="password_confirm" autocomplete="new-password" required>
        </label>

        <?= recaptcha_widget_html('login') ?>

        <button class="button blue hover" type="submit"><?= e(t('reset.submit')) ?></button>
    </form>

    <p class="muted" style="margin-top: 12px;">
        <a class="link" href="<?= e(base_url('login.php')) ?>"><?= e(t('auth.login.button')) ?></a>
        &middot;
        <a class="link" href="<?= e(base_url('forgot.php?action=password')) ?>"><?= e(t('forgot.heading_password')) ?></a>
    </p>
</section>
<?php render_footer(); ?>
