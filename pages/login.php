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
        $errors[] = 'Invalid form token. Please refresh and try again.';
    }

    if ($username === '' || $password === '') {
        $errors[] = 'Username and password are required.';
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
                $errors[] = 'Too many failed attempts. Try again in ' . (int) ceil($lockSecondsRemaining / 60) . ' minute(s).';
            } elseif ($user === false || !password_verify($password, (string) $user['password_hash'])) {
                if ($user !== false && $lockoutReady) {
                    login_register_failed_attempt((int) $user['id'], (int) $user['failed_login_attempts']);
                }
                $errors[] = 'Invalid username or password.';
            } else {
                if ($lockoutReady) {
                    login_clear_failed_attempts((int) $user['id']);
                }
                login_user($user);
                flash('success', 'Welcome back, ' . user_display_name_from_row($user) . '.');
                redirect($nextPath);
            }
        } catch (Throwable) {
            $errors[] = 'Login failed. Make sure you imported the latest db/schema.sql.';
        }
    }
}

render_header('Login', 'login');
?>
<section class="panel panel-narrow fade">
    <div class="panel-head">
        <h1>Player Login</h1>
        <p>Login to submit completion records.</p>
    </div>

    <?php if ($errors !== []): ?>
        <div class="info-red"><?= e(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <form class="stack-form" method="post" action="<?= e($hasNext ? base_url('login.php?next=' . rawurlencode($nextPath)) : base_url('login.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="next" value="<?= e($nextPath) ?>">

        <label class="field">
            <span>Username</span>
            <input type="text" name="username" value="<?= e($username) ?>" autocomplete="username" required>
        </label>

        <label class="field">
            <span>Password</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>

        <button class="button blue hover" type="submit">Login</button>
    </form>

    <p class="muted" style="margin-top: 12px;">
        New player? <a class="link" href="<?= e($hasNext ? base_url('register.php?next=' . rawurlencode($nextPath)) : base_url('register.php')) ?>">Create account</a>
    </p>
</section>
<?php render_footer(); ?>
