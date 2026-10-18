<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'password');
if (!in_array($action, ['password', 'username', 'resend'], true)) {
    $action = 'password';
}

if (!email_features_enabled()) {
    flash('error', t('email.disabled'));
    redirect('login.php');
}

$email = '';
$errors = [];

if (method_is_post()) {
    $email = trim((string) ($_POST['email'] ?? ''));

    $recoveryIpBucket = 'recovery_ip:' . current_request_ip();
    $recoveryWait = rate_limit_seconds_remaining($recoveryIpBucket, 5, 3600);
    if ($recoveryWait > 0) {
        $errors[] = t('security.rate_limited', ['minutes' => (int) ceil($recoveryWait / 60)]);
    }

    if (!validate_csrf($_POST['_token'] ?? null)) {
        $errors[] = t('auth.login.error_token');
    } else {
        // Stale form tokens must not burn the rate-limit quota.
        rate_limit_record_failure($recoveryIpBucket, 5, 3600);
    }

    if (!recaptcha_verify_response($_POST['g-recaptcha-response'] ?? null, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'login')) {
        $errors[] = t('common.recaptcha_failed');
    }

    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = t('forgot.error_email');
    }

    if ($errors === []) {
        try {
            $pdo = db();

            if (users_has_email_verification_columns($pdo)) {
                if ($action === 'password') {
                    $matches = email_users_by_email($pdo, $email);

                    $requests = [];
                    foreach ($matches as $row) {
                        $resetToken = email_create_reset_token($pdo, (int) $row['id']);
                        $requests[] = [
                            'username' => (string) $row['username'],
                            'url' => absolute_url('reset.php?token=' . rawurlencode($resetToken)),
                        ];
                    }

                    if ($requests !== []) {
                        send_password_reset_email($email, $requests);
                    }
                } elseif ($action === 'username') {
                    $matches = email_users_by_email($pdo, $email, 'confirmed');
                    $usernames = [];
                    foreach ($matches as $row) {
                        $usernames[] = (string) $row['username'];
                    }

                    if ($usernames !== []) {
                        send_username_email($email, $usernames);
                    }
                } else {
                    $matches = email_users_by_email($pdo, $email, 'pending');
                    foreach ($matches as $row) {
                        if (trim((string) ($row['verify_token_hash'] ?? '')) === '') {
                            continue;
                        }

                        $verifyToken = email_create_verification_token($pdo, (int) $row['id']);
                        send_verification_email(
                            $email,
                            (string) $row['username'],
                            absolute_url('verify.php?token=' . rawurlencode($verifyToken))
                        );
                    }
                }
            }
        } catch (Throwable) {
            error_log('forgot: account recovery request failed.');
        }

        flash('success', t('forgot.sent'));
        redirect('login.php');
    }
}

$headings = [
    'password' => t('forgot.heading_password'),
    'username' => t('forgot.heading_username'),
    'resend' => t('forgot.heading_resend'),
];
$intros = [
    'password' => t('forgot.intro_password'),
    'username' => t('forgot.intro_username'),
    'resend' => t('forgot.intro_resend'),
];

render_header(t('forgot.title'), 'login');
?>
<section class="panel panel-narrow fade">
    <div class="panel-head">
        <h1><?= e($headings[$action]) ?></h1>
        <p><?= e($intros[$action]) ?></p>
    </div>

    <?php if ($errors !== []): ?>
        <div class="info-red"><?= e(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <form class="stack-form" method="post" action="<?= e(base_url('forgot.php?action=' . rawurlencode($action))) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= e($action) ?>">

        <label class="field">
            <span><?= e(t('forgot.email_label')) ?></span>
            <input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" required>
        </label>

        <?= recaptcha_widget_html('login') ?>

        <button class="button blue hover" type="submit"><?= e(t('forgot.submit')) ?></button>
    </form>

    <?php
    $recoveryLinks = [];
    foreach (['password', 'username', 'resend'] as $linkAction) {
        if ($linkAction !== $action) {
            $recoveryLinks[] = '<a class="link" href="'
                . e(base_url('forgot.php?action=' . $linkAction)) . '">'
                . e($headings[$linkAction]) . '</a>';
        }
    }
    $recoveryLinks[] = '<a class="link" href="' . e(base_url('login.php')) . '">'
        . e(t('auth.login.button')) . '</a>';
    ?>
    <p class="muted" style="margin-top: 12px;"><?= implode(' &middot; ', $recoveryLinks) ?></p>
</section>
<?php render_footer(); ?>
