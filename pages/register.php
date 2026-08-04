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

if (method_is_post()) {
    $form['username'] = normalize_username((string) ($_POST['username'] ?? ''));
    $form['email'] = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if (!validate_csrf($_POST['_token'] ?? null)) {
        $errors[] = t('auth.login.error_token');
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

    if ($form['email'] !== '' && filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = t('auth.register.error_email');
    }

    if ($errors === []) {
        try {
            $pdo = db();

            if (users_has_display_name_column($pdo)) {
                $insert = $pdo->prepare('INSERT INTO users (username, display_name, email, password_hash, role)
                                         VALUES (:username, :display_name, :email, :password_hash, "player")');
                $insert->execute([
                    ':username' => $form['username'],
                    ':display_name' => null,
                    ':email' => $form['email'] !== '' ? $form['email'] : null,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
            } else {
                $insert = $pdo->prepare('INSERT INTO users (username, email, password_hash, role)
                                         VALUES (:username, :email, :password_hash, "player")');
                $insert->execute([
                    ':username' => $form['username'],
                    ':email' => $form['email'] !== '' ? $form['email'] : null,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
            }

            $userId = (int) db()->lastInsertId();
            $stmt = db()->prepare('SELECT id, username, ' . user_select_display_name_expression() . ', email, role, created_at FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();

            if ($user !== false) {
                login_user($user);
                flash('success', t('auth.register.created'));
                redirect($nextPath);
            }

            flash('success', t('auth.register.created_login'));
            redirect($hasNext ? 'login.php?next=' . rawurlencode($nextPath) : 'login.php');
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $errors[] = t('auth.register.error_exists');
            } else {
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
    </div>

    <?php if ($errors !== []): ?>
        <div class="info-red"><?= e(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <form class="stack-form" method="post" action="<?= e($hasNext ? base_url('register.php?next=' . rawurlencode($nextPath)) : base_url('register.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="next" value="<?= e($nextPath) ?>">

        <label class="field">
            <span><?= e(t('common.username')) ?></span>
            <input type="text" name="username" value="<?= e($form['username']) ?>" autocomplete="username" required>
        </label>

        <label class="field">
            <span><?= e(t('auth.register.email_optional')) ?></span>
            <input type="email" name="email" value="<?= e($form['email']) ?>" autocomplete="email">
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

    <p class="muted" style="margin-top: 12px;">
        <?= e(t('auth.register.already')) ?> <a class="link" href="<?= e($hasNext ? base_url('login.php?next=' . rawurlencode($nextPath)) : base_url('login.php')) ?>"><?= e(t('auth.register.login_now')) ?></a>
    </p>
</section>
<?php render_footer(); ?>
