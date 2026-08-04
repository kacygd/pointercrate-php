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
        flash('error', t('flash.invalid_token'));
        redirect('account.php');
    }

    if ($action === 'start_discord_link') {
        if (!users_has_discord_link_columns()) {
            flash('error', t('account.error_discord_columns'));
            redirect('account.php');
        }
        if (discord_bot_token() === null) {
            flash('error', t('account.error_discord_bot'));
            redirect('account.php');
        }

        $discordInput = trim((string) ($_POST['discord_user'] ?? ''));
        $discordUserId = normalize_discord_user_id($discordInput);
        if ($discordUserId === '') {
            flash('error', t('account.error_discord_user'));
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
                throw new RuntimeException(t('account.error_discord_taken'));
            }

            $currentDiscordId = trim((string) ($user['discord_user_id'] ?? ''));
            if ($currentDiscordId !== '') {
                throw new RuntimeException(t('account.error_discord_unlink_first'));
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
                throw new RuntimeException(t('account.error_discord_dm'));
            }

            flash('success', t('account.success_discord_dm'));
        } catch (Throwable $throwable) {
            flash('error', $throwable->getMessage());
        }

        redirect('account.php');
    } elseif ($action === 'verify_discord_link') {
        if (!users_has_discord_link_columns()) {
            flash('error', t('account.error_discord_columns'));
            redirect('account.php');
        }

        $code = preg_replace('/\D+/', '', (string) ($_POST['discord_code'] ?? ''));
        if (!is_string($code) || preg_match('/^[0-9]{4}$/', $code) !== 1) {
            flash('error', t('account.error_discord_code_format'));
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
                throw new RuntimeException(t('account.error_not_found'));
            }

            $pendingDiscordId = normalize_discord_user_id((string) ($target['discord_link_pending_user_id'] ?? ''));
            $codeHash = (string) ($target['discord_link_code_hash'] ?? '');
            $expiresAt = strtotime((string) ($target['discord_link_code_expires_at'] ?? '')) ?: 0;
            if ($pendingDiscordId === '' || $codeHash === '') {
                throw new RuntimeException(t('account.error_no_discord_code'));
            }
            if ($expiresAt < time()) {
                $clear = $pdo->prepare('UPDATE users
                                        SET discord_link_pending_user_id = NULL,
                                            discord_link_code_hash = NULL,
                                            discord_link_code_expires_at = NULL,
                                            discord_link_requested_at = NULL
                                        WHERE id = :id');
                $clear->execute([':id' => (int) $user['id']]);
                throw new RuntimeException(t('account.error_discord_code_expired'));
            }
            if (!password_verify($code, $codeHash)) {
                throw new RuntimeException(t('account.error_discord_code_wrong'));
            }

            $linkedCheck = $pdo->prepare('SELECT id FROM users WHERE discord_user_id = :discord_user_id AND id != :id LIMIT 1');
            $linkedCheck->execute([
                ':discord_user_id' => $pendingDiscordId,
                ':id' => (int) $user['id'],
            ]);
            if ($linkedCheck->fetch() !== false) {
                throw new RuntimeException(t('account.error_discord_taken'));
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
            flash('success', t('account.success_discord_linked'));
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
            flash('error', t('account.error_discord_columns'));
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
        flash('success', t('account.success_discord_unlinked'));
        redirect('account.php');
    } elseif ($action === 'update_profile') {
        $displayNameInput = normalize_display_name((string) ($_POST['display_name'] ?? ''));
        $countryInput = trim((string) ($_POST['country_code'] ?? ''));
        $countryPicker = trim((string) ($_POST['country_picker'] ?? ''));

        if ($displayNameInput !== '' && !validate_display_name($displayNameInput)) {
            flash('error', t('account.error_display_name'));
            redirect('account.php');
        }

        if (strcasecmp($countryPicker, t('common.not_set')) === 0 || strcasecmp($countryPicker, 'Not set') === 0) {
            $countryPicker = '';
        }

        if ($countryInput === '' && $countryPicker !== '' && preg_match('/^([a-zA-Z]{2})\\b/', $countryPicker, $match) === 1) {
            $countryInput = strtoupper($match[1]);
        }

        $countryCode = normalize_country_code($countryInput === '' ? null : $countryInput);

        if (($countryInput !== '' || $countryPicker !== '') && $countryCode === null) {
            flash('error', t('account.error_country'));
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

        flash('success', t('account.success_profile'));
        redirect('account.php');
    } elseif ($action === 'update_email') {
        $newEmail = trim((string) ($_POST['email'] ?? ''));

        if ($newEmail === '') {
            $errors[] = t('account.error_email_empty');
        } elseif (filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = t('auth.register.error_email');
        } elseif ($newEmail === (string) ($user['email'] ?? '')) {
            $errors[] = t('account.error_email_same');
        }

        if ($errors === []) {
            try {
                $stmt = db()->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
                $stmt->execute([':email' => $newEmail, ':id' => (int) $user['id']]);
                if ($stmt->fetch() !== false) {
                    $errors[] = t('account.error_email_taken');
                }
            } catch (Throwable) {
                $errors[] = t('account.error_email_failed');
            }
        }

        if ($errors === []) {
            $update = db()->prepare('UPDATE users SET email = :email WHERE id = :id');
            $update->execute([
                ':email' => $newEmail,
                ':id' => (int) $user['id'],
            ]);
            flash('success', t('account.success_email'));
            redirect('account.php');
        } else {
            flash('error', implode(' ', $errors));
            redirect('account.php');
        }
    } elseif ($action === 'update_username') {
        $newUsername = normalize_username((string) ($_POST['username'] ?? ''));

        if (!validate_username($newUsername)) {
            $errors[] = t('auth.register.error_username');
        } elseif ($newUsername === (string) $user['username']) {
            $errors[] = t('account.error_username_same');
        }

        if ($errors === []) {
            try {
                $stmt = db()->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(:username) AND id != :id LIMIT 1');
                $stmt->execute([':username' => $newUsername, ':id' => (int) $user['id']]);
                if ($stmt->fetch() !== false) {
                    $errors[] = t('account.error_username_taken');
                }
            } catch (Throwable) {
                $errors[] = t('account.error_username_failed');
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
            flash('success', t('account.success_username'));
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
            $errors[] = t('account.error_current_password_required');
        } elseif (!password_verify($currentPassword, (string) $user['password_hash'])) {
            $errors[] = t('account.error_current_password_wrong');
        }

        if ($newPassword === '') {
            $errors[] = t('account.error_new_password_required');
        } elseif (strlen($newPassword) < 8) {
            $errors[] = t('account.error_new_password_short');
        } elseif ($newPassword !== $newPasswordConfirm) {
            $errors[] = t('account.error_new_password_match');
        } elseif (password_verify($newPassword, (string) $user['password_hash'])) {
            $errors[] = t('account.error_new_password_same');
        }

        if ($errors === []) {
            $update = db()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
            $update->execute([
                ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':id' => (int) $user['id'],
            ]);
            flash('success', t('account.success_password'));
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

render_header(t('account.title'), 'account');
?>
<section class="panel fade">
    <div class="panel-head">
        <h1><?= e(t('account.heading')) ?></h1>
        <p><?= e(t('account.signed_in_as')) ?> <b><?= e($userDisplayName) ?></b><?php if ($hasCustomDisplayName): ?> <span class="muted">(@<?= e((string) $user['username']) ?>)</span><?php endif; ?></p>
    </div>

    <dl class="key-value" style="max-width: 640px; margin: 0 auto;">
        <div><dt><?= e(t('common.username')) ?></dt><dd><?= e((string) $user['username']) ?></dd></div>
        <div><dt><?= e(t('account.display_name')) ?></dt><dd><?= e($userDisplayName) ?></dd></div>
        <div><dt><?= e(t('common.email')) ?></dt><dd><?= e((string) ($user['email'] ?? '-')) ?></dd></div>
        <?php if ($isStaff): ?>
            <div><dt><?= e(t('account.youtube_channel')) ?></dt><dd><?= e((string) ($user['youtube_channel'] ?? '-')) ?></dd></div>
        <?php endif; ?>
        <div><dt><?= e(t('account.country')) ?></dt><dd><?= $countryFlag !== '' ? $countryFlag : '-' ?></dd></div>
        <?php if ($discordColumnsReady): ?>
            <div><dt><?= e(t('account.discord')) ?></dt><dd><?= $discordLinkedId !== '' ? e($discordLinkedLabel !== '' ? $discordLinkedLabel : $discordLinkedId) : '-' ?></dd></div>
        <?php endif; ?>
        <div><dt><?= e(t('common.role')) ?></dt><dd><?= e(role_label((string) ($user['role'] ?? 'player'))) ?></dd></div>
        <div><dt><?= e(t('common.points')) ?></dt><dd><?= e(number_format((float) ($user['points'] ?? 0.0), 2)) ?></dd></div>
        <div><dt><?= e(t('common.joined')) ?></dt><dd><?= e(date('Y-m-d', strtotime((string) $user['created_at']))) ?></dd></div>
    </dl>
</section>

<section class="panel fade panel-narrow">
    <div class="panel-head">
        <h2><?= e(t('account.profile_settings')) ?></h2>
        <p><?= e($isStaff ? t('account.profile_staff_intro') : t('account.profile_player_intro')) ?></p>
    </div>

    <form class="stack-form" method="post" action="<?= e(base_url('account.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_profile">

        <label class="field">
            <span><?= e(t('account.display_name')) ?></span>
            <input
                type="text"
                name="display_name"
                value="<?= e($userDisplayName) ?>"
                maxlength="40"
                placeholder="<?= e((string) $user['username']) ?>"
                autocomplete="nickname"
            >
            <small class="muted"><?= e(t('account.display_name_help')) ?></small>
        </label>

        <label class="field">
            <span><?= e(t('account.country')) ?></span>
            <input type="hidden" name="country_code" id="country-code-input" value="<?= e((string) ($countryCode ?? '')) ?>">
            <input
                type="text"
                name="country_picker"
                id="country-picker-input"
                value="<?= e($countryPickerText) ?>"
                data-suggest-list="country-list"
                data-suggest-hidden="country-code-input"
                placeholder="<?= e(t('account.country_placeholder')) ?>"
                autocomplete="off"
            >
            <small class="muted"><?= e(t('account.country_help')) ?></small>
            <datalist id="country-list">
                <option value="<?= e(t('common.not_set')) ?>" label="<?= e(t('common.no_country')) ?>" data-code=""></option>
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
                <span><?= e(t('account.youtube_channel')) ?></span>
                <input
                    type="text"
                    name="youtube_channel"
                    value="<?= e((string) ($user['youtube_channel'] ?? '')) ?>"
                    placeholder="<?= e(t('account.youtube_placeholder')) ?>"
                    autocomplete="off"
                >
                <small class="muted"><?= e(t('account.youtube_help')) ?></small>
            </label>
        <?php endif; ?>

        <button class="button blue hover" type="submit"><?= e(t('account.save_profile')) ?></button>
    </form>
</section>

<section class="panel fade panel-narrow">
    <div class="panel-head">
        <h2><?= e(t('account.discord_link')) ?></h2>
        <p><?= e(t('account.discord_intro')) ?></p>
    </div>

    <?php if (!$discordColumnsReady): ?>
        <div class="info-red"><?= e(t('account.discord_db_missing')) ?></div>
    <?php elseif (!$discordBotReady): ?>
        <div class="info-red"><?= e(t('account.discord_bot_missing')) ?></div>
    <?php elseif ($discordLinkedId !== ''): ?>
        <dl class="key-value compact" style="margin-bottom: 12px;">
            <div><dt><?= e(t('common.status')) ?></dt><dd><?= e(t('account.linked')) ?></dd></div>
            <div><dt><?= e(t('account.discord')) ?></dt><dd><?= e($discordLinkedLabel !== '' ? $discordLinkedLabel : $discordLinkedId) ?></dd></div>
            <div><dt><?= e(t('account.user_id')) ?></dt><dd><?= e($discordLinkedId) ?></dd></div>
        </dl>
        <form class="stack-form" method="post" action="<?= e(base_url('account.php')) ?>">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="unlink_discord">
            <button class="button red hover" type="submit" data-confirm="<?= e(t('account.unlink_confirm')) ?>"><?= e(t('account.unlink_discord')) ?></button>
        </form>
    <?php else: ?>
        <?php if ($discordPendingActive): ?>
            <div class="info-green">
                <?= e(t('account.code_sent', ['id' => $discordPendingId, 'time' => $discordPendingExpiresText])) ?>
            </div>
            <form class="stack-form" method="post" action="<?= e(base_url('account.php')) ?>">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="verify_discord_link">
                <label class="field">
                    <span><?= e(t('account.verification_code')) ?></span>
                    <input type="text" name="discord_code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="1234" required>
                </label>
                <button class="button blue hover" type="submit"><?= e(t('account.verify_discord')) ?></button>
            </form>
        <?php endif; ?>

        <form class="stack-form" method="post" action="<?= e(base_url('account.php')) ?>">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="start_discord_link">
            <label class="field">
                <span><?= e(t('account.discord_user')) ?></span>
                <input type="text" name="discord_user" placeholder="<?= e(t('account.discord_user_placeholder')) ?>" autocomplete="off" required>
                <small class="muted"><?= e(t('account.discord_user_help')) ?></small>
            </label>
            <button class="button blue hover" type="submit"><?= e($discordPendingActive ? t('account.send_new_code') : t('account.send_dm_code')) ?></button>
        </form>
    <?php endif; ?>
</section>

<section class="panel fade panel-narrow">
    <div class="panel-head">
        <h2><?= e(t('account.change_username')) ?></h2>
    </div>

    <form class="stack-form" method="post" action="<?= e(base_url('account.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_username">

        <label class="field">
            <span><?= e(t('account.new_username')) ?></span>
            <input
                type="text"
                name="username"
                placeholder="<?= e(t('account.new_username_placeholder')) ?>"
                required
            >
            <small class="muted"><?= e(t('account.username_help')) ?></small>
        </label>

        <button class="button blue hover" type="submit"><?= e(t('account.change_username')) ?></button>
    </form>
</section>

<section class="panel fade panel-narrow">
    <div class="panel-head">
        <h2><?= e(t('account.change_email')) ?></h2>
    </div>

    <form class="stack-form" method="post" action="<?= e(base_url('account.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_email">

        <label class="field">
            <span><?= e(t('account.new_email')) ?></span>
            <input
                type="email"
                name="email"
                value="<?= e((string) ($user['email'] ?? '')) ?>"
                placeholder="<?= e(t('account.new_email_placeholder')) ?>"
                required
            >
            <small class="muted"><?= e(t('account.email_help')) ?></small>
        </label>

        <button class="button blue hover" type="submit"><?= e(t('account.change_email')) ?></button>
    </form>
</section>

<section class="panel fade panel-narrow">
    <div class="panel-head">
        <h2><?= e(t('account.change_password')) ?></h2>
    </div>

    <form class="stack-form" method="post" action="<?= e(base_url('account.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_password">

        <label class="field">
            <span><?= e(t('account.current_password')) ?></span>
            <input
                type="password"
                name="current_password"
                placeholder="<?= e(t('account.current_password_placeholder')) ?>"
                required
                autocomplete="current-password"
            >
        </label>

        <label class="field">
            <span><?= e(t('account.new_password')) ?></span>
            <input
                type="password"
                name="new_password"
                placeholder="<?= e(t('account.new_password_placeholder')) ?>"
                required
                autocomplete="new-password"
            >
            <small class="muted"><?= e(t('account.password_help')) ?></small>
        </label>

        <label class="field">
            <span><?= e(t('account.confirm_new_password')) ?></span>
            <input
                type="password"
                name="new_password_confirm"
                placeholder="<?= e(t('account.confirm_password_placeholder')) ?>"
                required
                autocomplete="new-password"
            >
        </label>

        <button class="button blue hover" type="submit"><?= e(t('account.change_password')) ?></button>
    </form>
</section>

<section class="panel fade">
    <div class="panel-head">
        <h2><?= e(t('account.submission_history')) ?></h2>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th><?= e(t('common.type')) ?></th>
                    <th><?= e(t('common.demon')) ?></th>
                    <th><?= e(t('common.progress')) ?></th>
                    <th><?= e(t('common.status')) ?></th>
                    <th><?= e(t('common.created')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($submissions === []): ?>
                    <tr><td colspan="6" class="muted"><?= e(t('account.no_submissions')) ?></td></tr>
                <?php endif; ?>
                <?php foreach ($submissions as $item): ?>
                    <tr>
                        <td>#<?= (int) $item['id'] ?></td>
                        <td><?= e((string) $item['type']) ?></td>
                        <td><?= e((string) $item['demon_name']) ?></td>
                        <td><?= $item['progress'] !== null ? (int) $item['progress'] . '%' : '-' ?></td>
                        <td>
                            <span class="badge <?= $item['status'] === 'approved' ? 'success' : ($item['status'] === 'rejected' ? 'error' : '') ?>">
                                <?= e(status_label((string) $item['status'])) ?>
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
