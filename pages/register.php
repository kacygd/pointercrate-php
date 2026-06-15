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
        $errors[] = 'Invalid form token. Please refresh and try again.';
    }

    if (!validate_username($form['username'])) {
        $errors[] = 'Username must be 3-24 characters using letters, numbers, or underscore.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'Password confirmation does not match.';
    }

    if ($form['email'] !== '' && filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Email format is invalid.';
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
                flash('success', 'Account created successfully. You can now submit records.');
                redirect($nextPath);
            }

            flash('success', 'Account created. Please login.');
            redirect($hasNext ? 'login.php?next=' . rawurlencode($nextPath) : 'login.php');
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $errors[] = 'Username or email already exists.';
            } else {
                $errors[] = 'Registration failed. Make sure you imported the latest db/schema.sql.';
            }
        }
    }
}

render_header('Register', 'register');
?>
<section class="panel panel-narrow fade">
    <div class="panel-head">
        <h1>Player Registration</h1>
        <p>Create an account to submit completion records.</p>
    </div>

    <?php if ($errors !== []): ?>
        <div class="info-red"><?= e(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <form class="stack-form" method="post" action="<?= e($hasNext ? base_url('register.php?next=' . rawurlencode($nextPath)) : base_url('register.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="next" value="<?= e($nextPath) ?>">

        <label class="field">
            <span>Username</span>
            <input type="text" name="username" value="<?= e($form['username']) ?>" autocomplete="username" required>
        </label>

        <label class="field">
            <span>Email (optional)</span>
            <input type="email" name="email" value="<?= e($form['email']) ?>" autocomplete="email">
        </label>

        <label class="field">
            <span>Password</span>
            <input type="password" name="password" autocomplete="new-password" required>
        </label>

        <label class="field">
            <span>Confirm Password</span>
            <input type="password" name="password_confirm" autocomplete="new-password" required>
        </label>

        <button class="button blue hover" type="submit">Create Account</button>
    </form>

    <p class="muted" style="margin-top: 12px;">
        Already registered? <a class="link" href="<?= e($hasNext ? base_url('login.php?next=' . rawurlencode($nextPath)) : base_url('login.php')) ?>">Login now</a>
    </p>
</section>
<?php render_footer(); ?>
