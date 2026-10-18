<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$nextRaw = trim((string) ($_GET['next'] ?? $_POST['next'] ?? ''));
$hasNext = $nextRaw !== '';
$nextPath = auth_next_path($nextRaw, 'submit.php');

if (is_logged_in()) {
    redirect($hasNext ? $nextPath : 'account.php');
}

$form = [
    'username' => '',
    'email' => '',
];
$errors = [];
$ipBanned = current_request_ip_banned();
$requireEmail = email_verification_required();

if ($ipBanned) {
    $errors[] = t('auth.error_ip_banned');
}

if (method_is_post() && !$ipBanned) {
    $form['username'] = normalize_username((string) ($_POST['username'] ?? ''));
    $form['email'] = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    $registerIpBucket = 'register_ip:' . current_request_ip();
    $registerWait = rate_limit_seconds_remaining($registerIpBucket, 10, 3600);
    if ($registerWait > 0) {
        $errors[] = t('security.rate_limited', ['minutes' => (int) ceil($registerWait / 60)]);
    }

    if (!validate_csrf($_POST['_token'] ?? null)) {
        $errors[] = t('auth.login.error_token');
    } else {
        rate_limit_record_failure($registerIpBucket, 10, 3600);
    }

    if (!recaptcha_verify_response($_POST['g-recaptcha-response'] ?? null, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'register')) {
        $errors[] = t('common.recaptcha_failed');
    }

    if (!validate_username($form['username'])) {
        $errors[] = t('auth.register.error_username');
    }

    if (strlen($password) < 8) {
        $errors[] = t('auth.register.error_password');
    }

    if ($password !== $passwordConfirm) {
        $errors[] = t('auth.register.error_password_match');
    }

    if ($requireEmail && $form['email'] === '') {
        $errors[] = t('auth.register.error_email_required');
    }

    if ($form['email'] !== '' && filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = t('auth.register.error_email');
    }

    if ($errors === []) {
        try {
            $pdo = db();
            $exists = $pdo->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1');
            $exists->execute([':username' => $form['username']]);
            if ($exists->fetchColumn() !== false) {
                $errors[] = t('auth.register.error_username_exists');
            }

            $verifyColumnsReady = $requireEmail && users_has_email_verification_columns($pdo);

            if ($errors === [] && $verifyColumnsReady && !email_address_available($pdo, $form['email'])) {
                $errors[] = t('auth.register.error_exists');
            }

            $emailColumn = $verifyColumnsReady ? 'pending_email' : 'email';
            $emailValue = $form['email'] !== '' ? $form['email'] : null;

            if ($errors === [] && users_has_display_name_column($pdo)) {
                $insert = $pdo->prepare('INSERT INTO users (username, display_name, ' . $emailColumn . ', password_hash, role)
                                         VALUES (:username, :display_name, :email, :password_hash, "player")');
                $insert->execute([
                    ':username' => $form['username'],
                    ':display_name' => null,
                    ':email' => $emailValue,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
            } elseif ($errors === []) {
                $insert = $pdo->prepare('INSERT INTO users (username, ' . $emailColumn . ', password_hash, role)
                                         VALUES (:username, :email, :password_hash, "player")');
                $insert->execute([
                    ':username' => $form['username'],
                    ':email' => $emailValue,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
            }

            if ($errors !== []) {
                throw new RuntimeException('validation');
            }

            $userId = (int) db()->lastInsertId();
            $selectedColumns = users_has_display_name_column($pdo)
                ? 'id, username, ' . user_select_display_name_expression() . ', email, role, created_at'
                : 'id, username, email, role, created_at';
            if ($verifyColumnsReady) {
                $selectedColumns .= ', pending_email';
            }
            $stmt = db()->prepare('SELECT ' . $selectedColumns . ' FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();

            if ($verifyColumnsReady && $user !== false) {
                $targetEmail = trim((string) ($user['pending_email'] ?? ''));

                try {
                    $verifyToken = $targetEmail !== '' ? email_create_verification_token($pdo, $userId) : null;
                } catch (RuntimeException $exception) {
                    error_log('register: could not generate verification token for account ' . $userId);
                    email_clear_verification_token($pdo, $userId);
                    $verifyToken = null;
                }

                $mailDelivered = false;
                if ($verifyToken !== null) {
                    $verifyUrl = absolute_url('verify.php?token=' . rawurlencode($verifyToken));
                    $mailDelivered = send_verification_email($targetEmail, (string) $user['username'], $verifyUrl);

                    if ($mailDelivered) {
                        flash('success', t('auth.register.verify_sent', ['email' => $targetEmail]));
                        redirect('login.php');
                    }
                }

                if (!$mailDelivered && !email_fail_open_enabled()) {
                    error_log('register: verification email could not be sent, registration refused for account ' . $userId);
                    $discard = $pdo->prepare('DELETE FROM users WHERE id = :id');
                    $discard->execute([':id' => $userId]);
                    $user = false;
                    $errors[] = t('auth.register.error_email_send');
                }

                if ($user !== false) {
                    email_promote_pending_email($pdo, $userId);
                }
            }

            if ($errors !== []) {
                $form['password'] = '';
            } elseif ($user !== false) {
                login_user($user);
                flash('success', t('auth.register.created'));
                redirect($nextPath);
            } else {
                flash('success', t('auth.register.created_login'));
                redirect($hasNext ? 'login.php?next=' . rawurlencode($nextPath) : 'login.php');
            }
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $errors[] = t('auth.register.error_exists');
            } else {
                error_log('register: database error ' . $exception->getCode() . ' - ' . $exception->getMessage());
                $errors[] = t('auth.register.error_failed');
            }
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'validation') {
                error_log('register: runtime error - ' . $exception->getMessage());
                $errors[] = t('auth.register.error_failed');
            }
        }
    }
}

render_header(t('auth.register.title'), 'register');
?>
<section class="panel panel-narrow fade">
    <div class="panel-head">
        <h1><?= e(t('auth.register.heading')) ?></h1>
        <p><?= e(t('auth.register.intro')) ?></p>
        <?php if ($requireEmail): ?>
            <p class="muted"><?= e(t('auth.register.verify_note')) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($errors !== []): ?>
        <div class="info-red"><?= e(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <?php if (!$ipBanned): ?>
    <form class="stack-form" method="post" action="<?= e($hasNext ? base_url('register.php?next=' . rawurlencode($nextPath)) : base_url('register.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="next" value="<?= e($nextPath) ?>">

        <label class="field">
            <span><?= e(t('common.username')) ?></span>
            <input type="text" name="username" value="<?= e($form['username']) ?>" autocomplete="username" required>
        </label>

        <label class="field">
            <span><?= e($requireEmail ? t('auth.register.email_required') : t('auth.register.email_optional')) ?></span>
            <input type="email" name="email" value="<?= e($form['email']) ?>" autocomplete="email"<?= $requireEmail ? ' required' : '' ?>>
        </label>

        <label class="field">
            <span><?= e(t('common.password')) ?></span>
            <input type="password" name="password" autocomplete="new-password" required>
        </label>

        <label class="field">
            <span><?= e(t('auth.register.confirm_password')) ?></span>
            <input type="password" name="password_confirm" autocomplete="new-password" required>
        </label>

        <?= recaptcha_widget_html('register') ?>

        <button class="button blue hover" type="submit"><?= e(t('auth.register.button')) ?></button>
    </form>
    <?php endif; ?>

    <div class="auth-helper">
        <p class="muted">
            <?= e(t('auth.register.already')) ?>
            <a class="link" href="<?= e($hasNext ? base_url('login.php?next=' . rawurlencode($nextPath)) : base_url('login.php')) ?>"><?= e(t('auth.register.login_now')) ?></a>
        </p>
    </div>
</section>
<?php render_footer(); ?>
