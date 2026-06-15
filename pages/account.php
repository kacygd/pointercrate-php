<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

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

    if ($action === 'start_discord_link') {
        if (!users_has_discord_link_columns()) {
            flash('error', 'Discord link columns are missing. Please run the database schema update.');
            redirect('account.php');
        }
        if (discord_bot_token() === null) {
            flash('error', 'Discord bot token is not configured.');
            redirect('account.php');
        }

        $discordInput = trim((string) ($_POST['discord_user'] ?? ''));
        $discordUserId = normalize_discord_user_id($discordInput);
        if ($discordUserId === '') {
            flash('error', 'Enter a valid Discord user ID or @mention. Username alone cannot be DM-ed reliably.');
            redirect('account.php');
        }

        $pdo = db();
        try {
            $linkedCheck = $pdo->prepare('SELECT id, username FROM users WHERE discord_user_id = :discord_user_id AND id != :id LIMIT 1');
            $linkedCheck->execute([
                ':discord_user_id' => $discordUserId,
                ':id' => (int) $user['id'],
            ]);
            if ($linkedCheck->fetch() !== false) {
                throw new RuntimeException('That Discord account is already linked to another list account.');
            }

            $currentDiscordId = trim((string) ($user['discord_user_id'] ?? ''));
            if ($currentDiscordId !== '') {
                throw new RuntimeException('Unlink your current Discord account before linking a new one.');
            }

            $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $expiresAt = (new DateTimeImmutable('+5 minutes'))->format('Y-m-d H:i:s');
            $codeHash = password_hash($code, PASSWORD_DEFAULT);

            $update = $pdo->prepare('UPDATE users
                                     SET discord_link_pending_user_id = :discord_user_id,
                                         discord_link_code_hash = :code_hash,
                                         discord_link_code_expires_at = :expires_at,
                                         discord_link_requested_at = NOW()
                                     WHERE id = :id');
            $update->execute([
                ':discord_user_id' => $discordUserId,
                ':code_hash' => $codeHash,
                ':expires_at' => $expiresAt,
                ':id' => (int) $user['id'],
            ]);

            $dmSent = send_discord_direct_message(
                $discordUserId,
                app_name() . ' Discord code: ' . $code . "\nIgnore this if it was not you."
            );
            if (!$dmSent) {
                $clear = $pdo->prepare('UPDATE users
                                        SET discord_link_pending_user_id = NULL,
                                            discord_link_code_hash = NULL,
                                            discord_link_code_expires_at = NULL,
                                            discord_link_requested_at = NULL
                                        WHERE id = :id');
                $clear->execute([':id' => (int) $user['id']]);
                throw new RuntimeException('Could not DM that Discord account. Check the ID, DM privacy settings, and bot access.');
            }

            flash('success', 'Discord DM code sent.');
        } catch (Throwable $throwable) {
            flash('error', $throwable->getMessage());
        }

        redirect('account.php');
    } elseif ($action === 'verify_discord_link') {
        if (!users_has_discord_link_columns()) {
            flash('error', 'Discord link columns are missing. Please run the database schema update.');
            redirect('account.php');
        }

        $code = preg_replace('/\D+/', '', (string) ($_POST['discord_code'] ?? ''));
        if (!is_string($code) || preg_match('/^[0-9]{4}$/', $code) !== 1) {
            flash('error', 'Enter the 4-digit Discord verification code.');
            redirect('account.php');
        }

        $pdo = db();
        $linkedDiscordId = '';
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT id, discord_link_pending_user_id, discord_link_code_hash, discord_link_code_expires_at
                                   FROM users
                                   WHERE id = :id
                                   LIMIT 1
                                   FOR UPDATE');
            $stmt->execute([':id' => (int) $user['id']]);
            $target = $stmt->fetch();
            if ($target === false) {
                throw new RuntimeException('Account not found.');
            }

            $pendingDiscordId = normalize_discord_user_id((string) ($target['discord_link_pending_user_id'] ?? ''));
            $codeHash = (string) ($target['discord_link_code_hash'] ?? '');
            $expiresAt = strtotime((string) ($target['discord_link_code_expires_at'] ?? '')) ?: 0;
            if ($pendingDiscordId === '' || $codeHash === '') {
                throw new RuntimeException('No active Discord verification code. Send a new code first.');
            }
            if ($expiresAt < time()) {
                $clear = $pdo->prepare('UPDATE users
                                        SET discord_link_pending_user_id = NULL,
                                            discord_link_code_hash = NULL,
                                            discord_link_code_expires_at = NULL,
                                            discord_link_requested_at = NULL
                                        WHERE id = :id');
                $clear->execute([':id' => (int) $user['id']]);
                throw new RuntimeException('Discord verification code expired. Send a new code.');
            }
            if (!password_verify($code, $codeHash)) {
                throw new RuntimeException('Discord verification code is incorrect.');
            }

            $linkedCheck = $pdo->prepare('SELECT id FROM users WHERE discord_user_id = :discord_user_id AND id != :id LIMIT 1');
            $linkedCheck->execute([
                ':discord_user_id' => $pendingDiscordId,
                ':id' => (int) $user['id'],
            ]);
            if ($linkedCheck->fetch() !== false) {
                throw new RuntimeException('That Discord account is already linked to another list account.');
            }

            $discordLabel = discord_user_label_from_api($pendingDiscordId);
            $update = $pdo->prepare('UPDATE users
                                     SET discord_user_id = :discord_user_id,
                                         discord_username = :discord_username,
                                         discord_link_pending_user_id = NULL,
                                         discord_link_code_hash = NULL,
                                         discord_link_code_expires_at = NULL,
                                         discord_link_requested_at = NULL
                                     WHERE id = :id');
            $update->execute([
                ':discord_user_id' => $pendingDiscordId,
                ':discord_username' => $discordLabel !== '' ? $discordLabel : null,
                ':id' => (int) $user['id'],
            ]);

            $linkedDiscordId = $pendingDiscordId;
            $pdo->commit();
            flash('success', 'Discord account linked successfully.');
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $throwable->getMessage());
        }

        if ($linkedDiscordId !== '') {
            send_discord_direct_message($linkedDiscordId, app_name() . ': Discord linked.');
        }

        redirect('account.php');
    } elseif ($action === 'unlink_discord') {
        if (!users_has_discord_link_columns()) {
            flash('error', 'Discord link columns are missing. Please run the database schema update.');
            redirect('account.php');
        }

        $oldDiscordId = normalize_discord_user_id((string) ($user['discord_user_id'] ?? ''));
        $update = db()->prepare('UPDATE users
                                 SET discord_user_id = NULL,
                                     discord_username = NULL,
                                     discord_link_pending_user_id = NULL,
                                     discord_link_code_hash = NULL,
                                     discord_link_code_expires_at = NULL,
                                     discord_link_requested_at = NULL
                                 WHERE id = :id');
        $update->execute([':id' => (int) $user['id']]);
        if ($oldDiscordId !== '') {
            send_discord_direct_message($oldDiscordId, app_name() . ': Discord unlinked.');
        }
        flash('success', 'Discord account unlinked.');
        redirect('account.php');
    } elseif ($action === 'update_profile') {
        $displayNameInput = normalize_display_name((string) ($_POST['display_name'] ?? ''));
        $countryInput = trim((string) ($_POST['country_code'] ?? ''));
        $countryPicker = trim((string) ($_POST['country_picker'] ?? ''));

        if ($displayNameInput !== '' && !validate_display_name($displayNameInput)) {
            flash('error', 'Display name must be between 1 and 40 characters.');
            redirect('account.php');
        }

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

        $displayName = $displayNameInput !== '' ? $displayNameInput : null;

        if (users_has_display_name_column()) {
            $update = db()->prepare('UPDATE users
                                     SET display_name = :display_name,
                                         country_code = :country_code,
                                         youtube_channel = :youtube_channel
                                     WHERE id = :id');
            $update->execute([
                ':display_name' => $displayName,
                ':country_code' => $countryCode,
                ':youtube_channel' => $youtubeChannel !== '' ? $youtubeChannel : null,
                ':id' => (int) $user['id'],
            ]);
        } else {
            $update = db()->prepare('UPDATE users SET country_code = :country_code, youtube_channel = :youtube_channel WHERE id = :id');
            $update->execute([
                ':country_code' => $countryCode,
                ':youtube_channel' => $youtubeChannel !== '' ? $youtubeChannel : null,
                ':id' => (int) $user['id'],
            ]);
        }

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
            $displayNameRaw = normalize_display_name((string) ($user['display_name'] ?? ''));
            $shouldFollowUsername = $displayNameRaw === '' || $displayNameRaw === (string) $user['username'];

            if (users_has_display_name_column() && $shouldFollowUsername) {
                $update = db()->prepare('UPDATE users SET username = :username, display_name = NULL WHERE id = :id');
                $update->execute([
                    ':username' => $newUsername,
                    ':id' => (int) $user['id'],
                ]);
            } else {
                $update = db()->prepare('UPDATE users SET username = :username WHERE id = :id');
                $update->execute([
                    ':username' => $newUsername,
                    ':id' => (int) $user['id'],
                ]);
            }
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
$userDisplayName = user_display_name_from_row($user);
$hasCustomDisplayName = user_has_custom_display_name($user);
$discordColumnsReady = users_has_discord_link_columns();
$discordBotReady = discord_bot_token() !== null;
$discordLinkedId = $discordColumnsReady ? normalize_discord_user_id((string) ($user['discord_user_id'] ?? '')) : '';
$discordLinkedLabel = trim((string) ($user['discord_username'] ?? ''));
$discordPendingId = $discordColumnsReady ? normalize_discord_user_id((string) ($user['discord_link_pending_user_id'] ?? '')) : '';
$discordPendingExpiresAt = $discordColumnsReady ? (strtotime((string) ($user['discord_link_code_expires_at'] ?? '')) ?: 0) : 0;
$discordPendingActive = $discordPendingId !== '' && $discordPendingExpiresAt >= time();
$discordPendingExpiresText = $discordPendingActive ? date('H:i:s', $discordPendingExpiresAt) : '';

render_header('Account', 'account');
?>
<section class="panel fade">
    <div class="panel-head">
        <h1>My Account</h1>
        <p>Signed in as <b><?= e($userDisplayName) ?></b><?php if ($hasCustomDisplayName): ?> <span class="muted">(@<?= e((string) $user['username']) ?>)</span><?php endif; ?></p>
    </div>

    <dl class="key-value" style="max-width: 640px; margin: 0 auto;">
        <div><dt>Username</dt><dd><?= e((string) $user['username']) ?></dd></div>
        <div><dt>Display Name</dt><dd><?= e($userDisplayName) ?></dd></div>
        <div><dt>Email</dt><dd><?= e((string) ($user['email'] ?? '-')) ?></dd></div>
        <?php if ($isStaff): ?>
            <div><dt>YouTube Channel</dt><dd><?= e((string) ($user['youtube_channel'] ?? '-')) ?></dd></div>
        <?php endif; ?>
        <div><dt>Country</dt><dd><?= $countryFlag !== '' ? $countryFlag : '-' ?></dd></div>
        <?php if ($discordColumnsReady): ?>
            <div><dt>Discord</dt><dd><?= $discordLinkedId !== '' ? e($discordLinkedLabel !== '' ? $discordLinkedLabel : $discordLinkedId) : '-' ?></dd></div>
        <?php endif; ?>
        <div><dt>Role</dt><dd><?= e(role_label((string) ($user['role'] ?? 'player'))) ?></dd></div>
        <div><dt>Points</dt><dd><?= e(number_format((float) ($user['points'] ?? 0.0), 2)) ?></dd></div>
        <div><dt>Joined</dt><dd><?= e(date('Y-m-d', strtotime((string) $user['created_at']))) ?></dd></div>
    </dl>
</section>

<section class="panel fade panel-narrow">
    <div class="panel-head">
        <h2>Profile Settings</h2>
        <p><?php if ($isStaff): ?>Set your display name, country, and YouTube channel for public pages.<?php else: ?>Set your display name and country for public player pages.<?php endif; ?></p>
    </div>

    <form class="stack-form" method="post" action="<?= e(base_url('account.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_profile">

        <label class="field">
            <span>Display Name</span>
            <input
                type="text"
                name="display_name"
                value="<?= e($userDisplayName) ?>"
                maxlength="40"
                placeholder="<?= e((string) $user['username']) ?>"
                autocomplete="nickname"
            >
            <small class="muted">Shown publicly across the site. Leave blank to use your username.</small>
        </label>

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
        <h2>Discord Link</h2>
        <p>Link your Discord account to receive private bot notifications about your records and levels.</p>
    </div>

    <?php if (!$discordColumnsReady): ?>
        <div class="info-red">Discord linking needs a database update. Run <code>update_db_schema.php</code>, then reload this page.</div>
    <?php elseif (!$discordBotReady): ?>
        <div class="info-red">Discord bot token is not configured yet.</div>
    <?php elseif ($discordLinkedId !== ''): ?>
        <dl class="key-value compact" style="margin-bottom: 12px;">
            <div><dt>Status</dt><dd>Linked</dd></div>
            <div><dt>Discord</dt><dd><?= e($discordLinkedLabel !== '' ? $discordLinkedLabel : $discordLinkedId) ?></dd></div>
            <div><dt>User ID</dt><dd><?= e($discordLinkedId) ?></dd></div>
        </dl>
        <form class="stack-form" method="post" action="<?= e(base_url('account.php')) ?>">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="unlink_discord">
            <button class="button red hover" type="submit" data-confirm="Unlink your Discord account?">Unlink Discord</button>
        </form>
    <?php else: ?>
        <?php if ($discordPendingActive): ?>
            <div class="info-green">
                Code sent to Discord user ID <?= e($discordPendingId) ?>. Expires at <?= e($discordPendingExpiresText) ?>.
            </div>
            <form class="stack-form" method="post" action="<?= e(base_url('account.php')) ?>">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="verify_discord_link">
                <label class="field">
                    <span>Verification Code</span>
                    <input type="text" name="discord_code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="1234" required>
                </label>
                <button class="button blue hover" type="submit">Verify Discord</button>
            </form>
        <?php endif; ?>

        <form class="stack-form" method="post" action="<?= e(base_url('account.php')) ?>">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="start_discord_link">
            <label class="field">
                <span>Discord User ID or Mention</span>
                <input type="text" name="discord_user" placeholder="123456789012345678 or @mention" autocomplete="off" required>
                <small class="muted">Username alone is not reliable for bot DMs. Copy your Discord User ID, or paste an @mention.</small>
            </label>
            <button class="button blue hover" type="submit"><?= $discordPendingActive ? 'Send New Code' : 'Send DM Code' ?></button>
        </form>
    <?php endif; ?>
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
