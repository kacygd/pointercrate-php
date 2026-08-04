<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$nextRaw = trim((string) ($_GET['next'] ?? $_POST['next'] ?? ''));
$hasNext = $nextRaw !== '';
$nextPath = auth_next_path($nextRaw, 'index.php');

if (is_logged_in()) {
    redirect($hasNext ? $nextPath : 'index.php');
}

$username = '';
$errors = [];

if (method_is_post()) {
    $username = normalize_username((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!validate_csrf($_POST['_token'] ?? null)) {
        $errors[] = t('auth.login.error_token');
    }

    if (!recaptcha_verify_response($_POST['g-recaptcha-response'] ?? null, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'login')) {
        $errors[] = t('common.recaptcha_failed');
    }

    if ($username === '' || $password === '') {
        $errors[] = t('auth.login.error_required');
    }

    if ($errors === []) {
        try {
            $lockoutReady = users_has_login_lockout_columns();
            $lockoutFields = $lockoutReady ? ', failed_login_attempts, login_locked_until' : '';

            $stmt = db()->prepare('SELECT id, username, ' . user_select_display_name_expression() . ', email, password_hash, role, created_at' . $lockoutFields . '
                                   FROM users
                                   WHERE username = :username
                                   LIMIT 1');
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            $lockSecondsRemaining = ($user !== false && $lockoutReady)
                ? login_lock_seconds_remaining($user['login_locked_until'] ?? null)
                : 0;

            if ($lockSecondsRemaining > 0) {
                $errors[] = t('auth.login.error_locked', ['minutes' => (int) ceil($lockSecondsRemaining / 60)]);
            } elseif ($user === false || !password_verify($password, (string) $user['password_hash'])) {
                if ($user !== false && $lockoutReady) {
                    login_register_failed_attempt((int) $user['id'], (int) $user['failed_login_attempts']);
                }
                $errors[] = t('auth.login.error_invalid');
            } else {
                if ($lockoutReady) {
                    login_clear_failed_attempts((int) $user['id']);
                }
                login_user($user);
                flash('success', t('auth.login.welcome', ['name' => user_display_name_from_row($user)]));
                redirect($nextPath);
            }
        } catch (Throwable) {
            $errors[] = t('auth.login.error_failed');
        }
    }
}

render_header(t('auth.login.title'), 'login');
?>
<section class="panel panel-narrow fade">
    <div class="panel-head">
        <h1><?= e(t('auth.login.heading')) ?></h1>
        <p><?= e(t('auth.login.intro')) ?></p>
    </div>

    <?php if ($errors !== []): ?>
        <div class="info-red"><?= e(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <form class="stack-form" method="post" action="<?= e($hasNext ? base_url('login.php?next=' . rawurlencode($nextPath)) : base_url('login.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="next" value="<?= e($nextPath) ?>">

        <label class="field">
            <span><?= e(t('common.username')) ?></span>
            <input type="text" name="username" value="<?= e($username) ?>" autocomplete="username" required>
        </label>

        <label class="field">
            <span><?= e(t('common.password')) ?></span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>

        <?= recaptcha_widget_html('login') ?>

        <button class="button blue hover" type="submit"><?= e(t('auth.login.button')) ?></button>
    </form>

    <p class="muted" style="margin-top: 12px;">
        <?= e(t('auth.login.new_player')) ?> <a class="link" href="<?= e($hasNext ? base_url('register.php?next=' . rawurlencode($nextPath)) : base_url('register.php')) ?>"><?= e(t('auth.login.create_account')) ?></a>
    </p>
</section>
<?php render_footer(); ?>
