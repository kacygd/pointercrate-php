<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (is_logged_in()) {
    redirect('account.php');
}

$next = trim((string) ($_GET['next'] ?? $_POST['next'] ?? ''));
$nextPath = (str_starts_with($next, '/') && !str_starts_with($next, '//')) ? $next : base_url('submit.php');
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
            $stmt = db()->prepare('SELECT id, username, email, password_hash, role, created_at
                                   FROM users
                                   WHERE username = :username
                                   LIMIT 1');
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user === false || !password_verify($password, (string) $user['password_hash'])) {
                $errors[] = 'Invalid username or password.';
            } else {
                login_user($user);
                flash('success', 'Welcome back, ' . (string) $user['username'] . '.');
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

    <form class="stack-form" method="post" action="<?= e(base_url('login.php')) ?>">
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
        New player? <a class="link" href="<?= e(base_url('register.php')) ?>">Create account</a>
    </p>
</section>
<?php render_footer(); ?>
