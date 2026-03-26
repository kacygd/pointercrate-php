<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$registered = db()->query('SELECT u.id,
                                  u.username,
                                  u.country_code,
                                  u.role,
                                  u.created_at,
                                  COALESCE(c.total_records, 0) AS total_records,
                                  COALESCE(c.total_completions, 0) AS total_completions,
                                  c.last_record
                           FROM users u
                           LEFT JOIN (
                                SELECT LOWER(player) AS player_key,
                                       COUNT(*) AS total_records,
                                       SUM(CASE WHEN progress = 100 THEN 1 ELSE 0 END) AS total_completions,
                                       MAX(created_at) AS last_record
                                FROM completions
                                GROUP BY LOWER(player)
                           ) c ON c.player_key = LOWER(u.username)
                           ORDER BY total_records DESC, total_completions DESC, u.username ASC')->fetchAll();

$legacy = db()->query('SELECT c.player,
                              COUNT(*) AS total_records,
                              SUM(CASE WHEN c.progress = 100 THEN 1 ELSE 0 END) AS total_completions,
                              MAX(c.created_at) AS last_record
                       FROM completions c
                       LEFT JOIN users u ON LOWER(u.username) = LOWER(c.player)
                       WHERE u.id IS NULL
                       GROUP BY c.player
                       ORDER BY total_records DESC, c.player ASC
                       LIMIT 50')->fetchAll();

render_header('Players', 'players');
?>
<section class="panel fade">
    <div class="panel-head">
        <h1>Player Leaderboard</h1>
        <p>Registered usernames with accepted records.</p>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Player</th>
                    <th>Records</th>
                    <th>Completions</th>
                    <th>Last Record</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($registered === []): ?>
                    <tr><td colspan="5" class="muted">No registered players yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($registered as $index => $player): ?>
                    <?php
                    $countryCode = normalize_country_code((string) ($player['country_code'] ?? ''));
                    $countryFlag = country_flag_html($countryCode, true);
                    $profileUrl = base_url('profile.php?user=' . rawurlencode((string) $player['username']));
                    ?>
                    <tr>
                        <td>#<?= $index + 1 ?></td>
                        <td>
                            <span class="player-inline">
                                <?= $countryFlag ?>
                                <a class="player-link" href="<?= e($profileUrl) ?>"><?= e((string) $player['username']) ?></a>
                            </span>
                            <?php if ((string) ($player['role'] ?? '') === 'admin'): ?>
                                <span class="badge">Admin</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $player['total_records'] ?></td>
                        <td><?= (int) $player['total_completions'] ?></td>
                        <td><?= $player['last_record'] !== null ? e(date('Y-m-d', strtotime((string) $player['last_record']))) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel fade">
    <div class="panel-head">
        <h2>Unlinked Record Holders</h2>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Player</th>
                    <th>Records</th>
                    <th>Completions</th>
                    <th>Last Record</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($legacy === []): ?>
                    <tr><td colspan="4" class="muted">No unlinked records.</td></tr>
                <?php endif; ?>
                <?php foreach ($legacy as $player): ?>
                    <?php $profileUrl = base_url('profile.php?user=' . rawurlencode((string) $player['player'])); ?>
                    <tr>
                        <td><a class="player-link" href="<?= e($profileUrl) ?>"><?= e((string) $player['player']) ?></a></td>
                        <td><?= (int) $player['total_records'] ?></td>
                        <td><?= (int) $player['total_completions'] ?></td>
                        <td><?= e(date('Y-m-d', strtotime((string) $player['last_record']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_footer(); ?>
