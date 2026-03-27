<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

require_login();

$user = current_user();
if ($user === null) {
    redirect('login.php');
}

if (method_is_post()) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_profile') {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', 'Invalid session token.');
            redirect('account.php');
        }

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

        $update = db()->prepare('UPDATE users SET country_code = :country_code WHERE id = :id');
        $update->execute([
            ':country_code' => $countryCode,
            ':id' => (int) $user['id'],
        ]);

        flash('success', 'Profile updated.');
        redirect('account.php');
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
        <div><dt>Country</dt><dd><?= $countryFlag !== '' ? $countryFlag : '-' ?></dd></div>
        <div><dt>Role</dt><dd><?= e((string) $user['role']) ?></dd></div>
        <div><dt>Points</dt><dd><?= e(number_format((float) ($user['points'] ?? 0.0), 2)) ?></dd></div>
        <div><dt>Joined</dt><dd><?= e(date('Y-m-d', strtotime((string) $user['created_at']))) ?></dd></div>
    </dl>
</section>

<section class="panel fade panel-narrow">
    <div class="panel-head">
        <h2>Profile Settings</h2>
        <p>Set your country to show your flag on the player list.</p>
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

        <button class="button blue hover" type="submit">Save Profile</button>
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


