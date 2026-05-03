<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

require_login();

$user = current_user();
if ($user === null) {
    redirect('login.php');
}

$errors = [];

if (method_is_post()) {
    $action = (string) ($_POST['action'] ?? '');

    if (!validate_csrf($_POST['_token'] ?? null)) {
        flash('error', 'Invalid session token.');
        redirect('account.php');
    }

    if ($action === 'update_profile') {
        $countryInput = trim((string) ($_POST['country_code'] ?? ''));
        $countryPicker = trim((string) ($_POST['country_picker'] ?? ''));

        if (strcasecmp($countryPicker, 'Not set') === 0) {
            $countryPicker = '';
        }

        if ($countryInput === '' && $countryPicker !== '' && preg_match('/^([a-zA-Z]{2})\\b/', $countryPicker, $match) === 1) {
            $countryInput = strtoupper($match[1]);
        }

        $countryCode = normalize_country_code($countryInput === '' ? null : $countryInput);

        if (($countryInput !== '' || $countryPicker !== '') && $countryCode === null) {
            flash('error', 'Invalid country selection.');
            redirect('account.php');
        }

        $userRole = current_user_role();
        $isStaff = in_array($userRole, ['owner', 'list_editor', 'list_helper'], true);
        
        $youtubeChannel = null;
        if ($isStaff) {
            $youtubeChannel = trim((string) ($_POST['youtube_channel'] ?? ''));
        }

        $update = db()->prepare('UPDATE users SET country_code = :country_code, youtube_channel = :youtube_channel WHERE id = :id');
        $update->execute([
            ':country_code' => $countryCode,
            ':youtube_channel' => $youtubeChannel !== '' ? $youtubeChannel : null,
            ':id' => (int) $user['id'],
        ]);

        flash('success', 'Profile updated.');
        redirect('account.php');
    } elseif ($action === 'update_email') {
        $newEmail = trim((string) ($_POST['email'] ?? ''));

        if ($newEmail === '') {
            $errors[] = 'Email cannot be empty.';
        } elseif (filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Email format is invalid.';
        } elseif ($newEmail === (string) ($user['email'] ?? '')) {
            $errors[] = 'New email is the same as current email.';
        }

        if ($errors === []) {
            try {
                $stmt = db()->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
                $stmt->execute([':email' => $newEmail, ':id' => (int) $user['id']]);
                if ($stmt->fetch() !== false) {
                    $errors[] = 'This email is already in use.';
                }
            } catch (Throwable) {
                $errors[] = 'Failed to update email.';
            }
        }

        if ($errors === []) {
            $update = db()->prepare('UPDATE users SET email = :email WHERE id = :id');
            $update->execute([
                ':email' => $newEmail,
                ':id' => (int) $user['id'],
            ]);
            flash('success', 'Email updated successfully.');
            redirect('account.php');
        } else {
            flash('error', implode(' ', $errors));
            redirect('account.php');
        }
    } elseif ($action === 'update_username') {
        $newUsername = normalize_username((string) ($_POST['username'] ?? ''));

        if (!validate_username($newUsername)) {
            $errors[] = 'Username must be 3-24 characters using letters, numbers, or underscore.';
        } elseif ($newUsername === (string) $user['username']) {
            $errors[] = 'New username is the same as current username.';
        }

        if ($errors === []) {
            try {
                $stmt = db()->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(:username) AND id != :id LIMIT 1');
                $stmt->execute([':username' => $newUsername, ':id' => (int) $user['id']]);
                if ($stmt->fetch() !== false) {
                    $errors[] = 'This username is already in use.';
                }
            } catch (Throwable) {
                $errors[] = 'Failed to update username.';
            }
        }

        if ($errors === []) {
            $update = db()->prepare('UPDATE users SET username = :username WHERE id = :id');
            $update->execute([
                ':username' => $newUsername,
                ':id' => (int) $user['id'],
            ]);
            flash('success', 'Username updated successfully.');
            redirect('account.php');
        } else {
            flash('error', implode(' ', $errors));
            redirect('account.php');
        }
    } elseif ($action === 'update_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

        if ($currentPassword === '') {
            $errors[] = 'Current password is required.';
        } elseif (!password_verify($currentPassword, (string) $user['password_hash'])) {
            $errors[] = 'Current password is incorrect.';
        }

        if ($newPassword === '') {
            $errors[] = 'New password is required.';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($newPassword !== $newPasswordConfirm) {
            $errors[] = 'New password confirmation does not match.';
        } elseif (password_verify($newPassword, (string) $user['password_hash'])) {
            $errors[] = 'New password must be different from current password.';
        }

        if ($errors === []) {
            $update = db()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
            $update->execute([
                ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':id' => (int) $user['id'],
            ]);
            flash('success', 'Password updated successfully.');
            redirect('account.php');
        } else {
            flash('error', implode(' ', $errors));
            redirect('account.php');
        }
    }
}

$stmt = db()->prepare('SELECT id, type, demon_name, progress, video_url, status, created_at, reviewed_at
                       FROM submissions
                       WHERE submitted_by_user_id = :user_id
                       ORDER BY created_at DESC
                       LIMIT 100');
$stmt->execute([':user_id' => (int) $user['id']]);
$submissions = $stmt->fetchAll();

$countryCode = normalize_country_code((string) ($user['country_code'] ?? ''));
$countryFlag = country_flag_html($countryCode);
$countryOptions = supported_countries();
$countryPickerText = ($countryCode !== null && isset($countryOptions[$countryCode]))
    ? $countryCode . ' ' . $countryOptions[$countryCode]
    : '';
$userRole = current_user_role();
$isStaff = in_array($userRole, ['owner', 'list_editor', 'list_helper'], true);

render_header('Account', 'account');
?>
<section class="panel fade">
    <div class="panel-head">
        <h1>My Account</h1>
        <p>Signed in as <b><?= e((string) $user['username']) ?></b></p>
    </div>

    <dl class="key-value" style="max-width: 640px; margin: 0 auto;">
        <div><dt>Username</dt><dd><?= e((string) $user['username']) ?></dd></div>
        <div><dt>Email</dt><dd><?= e((string) ($user['email'] ?? '-')) ?></dd></div>
        <?php if ($isStaff): ?>
            <div><dt>YouTube Channel</dt><dd><?= e((string) ($user['youtube_channel'] ?? '-')) ?></dd></div>
        <?php endif; ?>
        <div><dt>Country</dt><dd><?= $countryFlag !== '' ? $countryFlag : '-' ?></dd></div>
        <div><dt>Role</dt><dd><?= e(role_label((string) ($user['role'] ?? 'player'))) ?></dd></div>
        <div><dt>Points</dt><dd><?= e(number_format((float) ($user['points'] ?? 0.0), 2)) ?></dd></div>
        <div><dt>Joined</dt><dd><?= e(date('Y-m-d', strtotime((string) $user['created_at']))) ?></dd></div>
    </dl>
</section>

<section class="panel fade panel-narrow">
    <div class="panel-head">
        <h2>Profile Settings</h2>
        <p><?php if ($isStaff): ?>Set your country and YouTube channel to display on the list.<?php else: ?>Set your country to show your flag on the player list.<?php endif; ?></p>
    </div>

    <form class="stack-form" method="post" action="<?= e(base_url('account.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_profile">

        <label class="field">
            <span>Country</span>
            <input type="hidden" name="country_code" id="country-code-input" value="<?= e((string) ($countryCode ?? '')) ?>">
            <input
                type="text"
                name="country_picker"
                id="country-picker-input"
                value="<?= e($countryPickerText) ?>"
                data-suggest-list="country-list"
                data-suggest-hidden="country-code-input"
                placeholder="Type country code or country name..."
                autocomplete="off"
            >
            <small class="muted">Type to search. Select an item to use its flag. Clear this field to unset country.</small>
            <datalist id="country-list">
                <option value="Not set" label="No country" data-code=""></option>
                <?php foreach ($countryOptions as $code => $name): ?>
                    <?php $flagUrl = country_flag_asset_url($code); ?>
                    <option
                        value="<?= e($code . ' ' . $name) ?>"
                        label="<?= e($name) ?>"
                        data-code="<?= e($code) ?>"
                        <?= $flagUrl !== null ? 'data-flag-url="' . e($flagUrl) . '"' : '' ?>
                    ></option>
                <?php endforeach; ?>
            </datalist>
        </label>

        <?php if ($isStaff): ?>
            <label class="field">
                <span>YouTube Channel</span>
                <input
                    type="text"
                    name="youtube_channel"
                    value="<?= e((string) ($user['youtube_channel'] ?? '')) ?>"
                    placeholder="e.g., https://www.youtube.com/@YourChannel"
                    autocomplete="off"
                >
                <small class="muted">Enter your YouTube channel URL (optional). Only YouTube channels are supported.</small>
            </label>
        <?php endif; ?>

        <button class="button blue hover" type="submit">Save Profile</button>
    </form>
</section>

<section class="panel fade panel-narrow">
    <div class="panel-head">
        <h2>Change Username</h2>
    </div>

    <form class="stack-form" method="post" action="<?= e(base_url('account.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_username">

        <label class="field">
            <span>New Username</span>
            <input
                type="text"
                name="username"
                placeholder="Enter your new username"
                required
            >
            <small class="muted">3-24 characters, letters, numbers, and underscore only.</small>
        </label>

        <button class="button blue hover" type="submit">Change Username</button>
    </form>
</section>

<section class="panel fade panel-narrow">
    <div class="panel-head">
        <h2>Change Email</h2>
    </div>

    <form class="stack-form" method="post" action="<?= e(base_url('account.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_email">

        <label class="field">
            <span>New Email</span>
            <input
                type="email"
                name="email"
                value="<?= e((string) ($user['email'] ?? '')) ?>"
                placeholder="Enter your new email"
                required
            >
            <small class="muted">You will receive a confirmation at your new email address.</small>
        </label>

        <button class="button blue hover" type="submit">Change Email</button>
    </form>
</section>

<section class="panel fade panel-narrow">
    <div class="panel-head">
        <h2>Change Password</h2>
    </div>

    <form class="stack-form" method="post" action="<?= e(base_url('account.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_password">

        <label class="field">
            <span>Current Password</span>
            <input
                type="password"
                name="current_password"
                placeholder="Enter your current password"
                required
                autocomplete="current-password"
            >
        </label>

        <label class="field">
            <span>New Password</span>
            <input
                type="password"
                name="new_password"
                placeholder="Enter your new password"
                required
                autocomplete="new-password"
            >
            <small class="muted">Must be at least 8 characters.</small>
        </label>

        <label class="field">
            <span>Confirm New Password</span>
            <input
                type="password"
                name="new_password_confirm"
                placeholder="Confirm your new password"
                required
                autocomplete="new-password"
            >
        </label>

        <button class="button blue hover" type="submit">Change Password</button>
    </form>
</section>

<section class="panel fade">
    <div class="panel-head">
        <h2>My Submission History</h2>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Demon</th>
                    <th>Progress</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($submissions === []): ?>
                    <tr><td colspan="6" class="muted">No submissions yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($submissions as $item): ?>
                    <tr>
                        <td>#<?= (int) $item['id'] ?></td>
                        <td><?= e((string) $item['type']) ?></td>
                        <td><?= e((string) $item['demon_name']) ?></td>
                        <td><?= $item['progress'] !== null ? (int) $item['progress'] . '%' : '-' ?></td>
                        <td>
                            <span class="badge <?= $item['status'] === 'approved' ? 'success' : ($item['status'] === 'rejected' ? 'error' : '') ?>">
                                <?= e((string) $item['status']) ?>
                            </span>
                        </td>
                        <td><?= e(date('Y-m-d H:i', strtotime((string) $item['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_footer(); ?>



