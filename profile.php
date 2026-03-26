<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$requestedUser = trim((string) ($_GET['user'] ?? ''));
if ($requestedUser === '') {
    redirect('players.php');
}

$userStmt = db()->prepare('SELECT id, username, country_code, role, created_at
                           FROM users
                           WHERE LOWER(username) = LOWER(:username)
                           LIMIT 1');
$userStmt->execute([':username' => $requestedUser]);
$account = $userStmt->fetch() ?: null;

$profileName = $account !== null ? (string) $account['username'] : $requestedUser;

$recordsStmt = db()->prepare('SELECT c.player,
                                     c.progress,
                                     c.video_url,
                                     c.placement,
                                     c.created_at,
                                     d.id AS demon_id,
                                     d.name AS demon_name,
                                     d.position AS demon_position,
                                     d.legacy AS demon_legacy
                              FROM completions c
                              LEFT JOIN demons d ON d.id = c.demon_id
                              WHERE LOWER(c.player) = LOWER(:player)
                              ORDER BY c.created_at DESC
                              LIMIT 300');
$recordsStmt->execute([':player' => $profileName]);
$records = $recordsStmt->fetchAll();

$totalRecords = count($records);
$totalCompletions = 0;
$bestMainPosition = null;
$latestRecordAt = null;

foreach ($records as $record) {
    $progress = (int) ($record['progress'] ?? 0);
    if ($progress === 100) {
        $totalCompletions++;

        $position = $record['demon_position'] !== null ? (int) $record['demon_position'] : null;
        $legacy = (int) ($record['demon_legacy'] ?? 0) === 1;
        if ($position !== null && !$legacy && $position <= 150) {
            if ($bestMainPosition === null || $position < $bestMainPosition) {
                $bestMainPosition = $position;
            }
        }
    }

    if ($latestRecordAt === null) {
        $latestRecordAt = (string) $record['created_at'];
    }
}

$countryCode = normalize_country_code((string) ($account['country_code'] ?? ''));
$countryFlag = country_flag_html($countryCode, true);

render_header('Profile: ' . $profileName, 'players');
?>
<section class="panel fade">
    <div class="panel-head">
        <h1>Player Profile</h1>
        <p><?= e($profileName) ?></p>
    </div>

    <dl class="key-value" style="max-width: 680px; margin: 0 auto;">
        <div><dt>Username</dt><dd><?= $countryFlag ?><?= e($profileName) ?></dd></div>
        <div><dt>Role</dt><dd><?= e((string) ($account['role'] ?? 'player')) ?></dd></div>
        <div><dt>Joined</dt><dd><?= $account !== null ? e(date('Y-m-d', strtotime((string) $account['created_at']))) : '-' ?></dd></div>
        <div><dt>Total Records</dt><dd><?= $totalRecords ?></dd></div>
        <div><dt>Total Completions</dt><dd><?= $totalCompletions ?></dd></div>
        <div><dt>Best Main List Completion</dt><dd><?= $bestMainPosition !== null ? '#' . $bestMainPosition : '-' ?></dd></div>
        <div><dt>Latest Record</dt><dd><?= $latestRecordAt !== null ? e(date('Y-m-d H:i', strtotime($latestRecordAt))) : '-' ?></dd></div>
    </dl>
</section>

<section class="panel fade">
    <div class="panel-head">
        <h2>Achievements</h2>
        <p>Accepted records linked to this player name.</p>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Level</th>
                    <th>Position</th>
                    <th>Progress</th>
                    <th>Placement</th>
                    <th>Video</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($records === []): ?>
                    <tr><td colspan="6" class="muted">No records found for this player.</td></tr>
                <?php endif; ?>
                <?php foreach ($records as $record): ?>
                    <?php
                    $demonId = $record['demon_id'] !== null ? (int) $record['demon_id'] : null;
                    $demonName = (string) ($record['demon_name'] ?? 'Unknown Demon');
                    $demonPosition = $record['demon_position'] !== null ? '#' . (int) $record['demon_position'] : '-';
                    $progress = (int) ($record['progress'] ?? 0);
                    ?>
                    <tr style="<?= $progress === 100 ? 'font-weight: bold;' : '' ?>">
                        <td>
                            <?php if ($demonId !== null): ?>
                                <a class="player-link" href="<?= e(base_url('demon.php?id=' . $demonId)) ?>"><?= e($demonName) ?></a>
                            <?php else: ?>
                                <?= e($demonName) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= $demonPosition ?></td>
                        <td><?= $progress ?>%</td>
                        <td><?= $record['placement'] !== null ? '#' . (int) $record['placement'] : '-' ?></td>
                        <td><a class="link" href="<?= e((string) $record['video_url']) ?>" target="_blank" rel="noreferrer">Open</a></td>
                        <td><?= e(date('Y-m-d', strtotime((string) $record['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_footer(); ?>

