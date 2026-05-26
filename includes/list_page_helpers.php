<?php
declare(strict_types=1);

function card_thumbnail_url(array $demon): string
{
    $configured = trim((string) ($demon['thumbnail_url'] ?? ''));
    if ($configured !== '') {
        return $configured;
    }

    $videoUrl = trim((string) ($demon['video_url'] ?? ''));
    $youtubeId = youtube_video_id($videoUrl);
    if ($youtubeId !== null && $youtubeId !== '') {
        return 'https://i.ytimg.com/vi/' . rawurlencode($youtubeId) . '/hqdefault.jpg';
    }

    return '';
}

function css_background_image(string $url): string
{
    if ($url === '') {
        return 'background-image: linear-gradient(135deg, #1f3048 0%, #101824 100%);';
    }

    $safe = str_replace(
        ["\\", "'", "\r", "\n"],
        ["\\\\", "\\'", '', ''],
        $url
    );

    return "background-image: url('{$safe}');";
}

function demon_creator_name(array $demon): string
{
    return demon_primary_creator_name($demon);
}

function render_player_role_link(string $name, ?int $userId = null): string
{
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '-';
    }

    $labelText = $userId !== null && $userId > 0
        ? (user_public_name_by_id($userId, $trimmed) ?? $trimmed)
        : $trimmed;
    $label = '<b>' . e($labelText) . '</b>';
    if ($userId !== null && $userId > 0) {
        $url = base_url('players.php?uid=' . $userId);
        return '<a class="player-link" href="' . e($url) . '">' . $label . '</a>';
    }

    return $label;
}

function render_list_dropdown(string $id, string $title, string $description, array $demons): void
{
    ?>
    <div>
        <div class="button white hover no-shadow js-toggle" data-toggle-group="0" data-dropdown-id="<?= e($id) ?>">
            <?= e($title) ?>
        </div>

        <div class="see-through fade dropdown" id="<?= e($id) ?>">
            <div class="search js-search seperated" style="margin: 10px;">
                <input placeholder="Filter..." type="text">
            </div>
            <p style="margin: 10px;"><?= e($description) ?></p>
            <ul class="flex wrap space">
                <?php if ($demons === []): ?>
                    <li class="white" style="min-width: 100%; width: 100%;">No entries in this list.</li>
                <?php endif; ?>

                <?php foreach ($demons as $demon): ?>
                    <?php
                    $dropdownVerifier = trim((string) ($demon['verifier'] ?? ''));
                    $dropdownPublisher = trim((string) ($demon['publisher'] ?? ''));
                    $dropdownPublisherLabel = user_public_name_by_id((int) ($demon['publisher_user_id'] ?? 0), $dropdownPublisher) ?? $dropdownPublisher;
                    $dropdownVerifierLabel = user_public_name_by_id((int) ($demon['verifier_user_id'] ?? 0), $dropdownVerifier) ?? $dropdownVerifier;
                    ?>
                    <li class="hover white" title="#<?= (int) $demon['position'] ?> - <?= e((string) $demon['name']) ?>">
                        <a href="<?= e(base_url((string) ((int) $demon['position']))) ?>">
                            #<?= (int) $demon['position'] ?> - <?= e((string) $demon['name']) ?>
                            <br>
                            <i>published by <?= e($dropdownPublisherLabel) ?><?php if ($dropdownVerifier !== ''): ?>, verified by <?= e($dropdownVerifierLabel) ?><?php endif; ?></i>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php
}

function demonlist_fetch_all_demons(PDO $pdo): array
{
    $hasUserBannedColumn = users_has_is_banned_column($pdo);

    if ($hasUserBannedColumn) {
        $allDemons = $pdo->query('SELECT d.*, COALESCE(cc.completion_count, 0) AS completion_count
                                  FROM demons d
                                  LEFT JOIN (
                                      SELECT c.demon_id, COUNT(*) AS completion_count
                                      FROM completions c
                                      LEFT JOIN users banned_users
                                        ON LOWER(banned_users.username) = LOWER(c.player)
                                       AND COALESCE(banned_users.is_banned, 0) = 1
                                      WHERE banned_users.id IS NULL
                                      GROUP BY c.demon_id
                                  ) cc ON cc.demon_id = d.id
                                  ORDER BY d.position ASC')->fetchAll();
    } else {
        $allDemons = $pdo->query('SELECT d.*, COALESCE(cc.completion_count, 0) AS completion_count
                                  FROM demons d
                                  LEFT JOIN (
                                      SELECT c.demon_id, COUNT(*) AS completion_count
                                      FROM completions c
                                      GROUP BY c.demon_id
                                  ) cc ON cc.demon_id = d.id
                                  ORDER BY d.position ASC')->fetchAll();
    }

    foreach ($allDemons as &$demon) {
        $demon['position'] = (int) ($demon['position'] ?? 0);
        $demon['current_position'] = (int) ($demon['position'] ?? 0);
    }
    unset($demon);

    return $allDemons;
}

function historical_list_bucket(int $position): string
{
    if ($position < 1) {
        return 'legacy';
    }

    if ($position <= demonlist_main_list_limit()) {
        return 'main';
    }

    if ($position <= demonlist_extended_list_limit()) {
        return demonlist_show_extended_list() ? 'extended' : 'main';
    }

    return demonlist_show_legacy_list() ? 'legacy' : 'main';
}

function demonlist_partition_demons(array $allDemons, bool $historical = false): array
{
    $main = [];
    $extended = [];
    $legacy = [];
    $showcase = [];

    foreach ($allDemons as $demon) {
        $position = (int) ($demon['position'] ?? 0);
        $isLegacy = (int) ($demon['legacy'] ?? 0) === 1;
        $bucket = $historical
            ? historical_list_bucket($position)
            : demonlist_list_bucket($position, $isLegacy);

        if ($bucket === 'main') {
            $main[] = $demon;
            $showcase[] = $demon;
            continue;
        }

        if ($bucket === 'extended') {
            $extended[] = $demon;
            $showcase[] = $demon;
            continue;
        }

        $legacy[] = $demon;
    }

    return [
        'main' => $main,
        'extended' => $extended,
        'legacy' => $legacy,
        'showcase' => $showcase,
    ];
}

function time_machine_timezone(): DateTimeZone
{
    static $timezone = null;
    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    $timezone = new DateTimeZone((string) config('app.timezone', 'UTC'));
    return $timezone;
}

function time_machine_format_input(DateTimeInterface $date): string
{
    return DateTimeImmutable::createFromInterface($date)
        ->setTimezone(time_machine_timezone())
        ->format('Y-m-d\TH:i');
}

function time_machine_parse_input(string $value): ?DateTimeImmutable
{
    $normalized = trim($value);
    if ($normalized === '') {
        return null;
    }

    $timezone = time_machine_timezone();
    $formats = [
        'Y-m-d\TH:i',
        'Y-m-d\TH:i:s',
        'Y-m-d H:i',
        'Y-m-d H:i:s',
        DateTimeInterface::RFC3339,
        DateTimeInterface::RFC3339_EXTENDED,
    ];

    foreach ($formats as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $normalized, $timezone);
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }
    }

    try {
        return new DateTimeImmutable($normalized, $timezone);
    } catch (Throwable) {
        return null;
    }
}

function time_machine_format_banner(DateTimeInterface $date): string
{
    return DateTimeImmutable::createFromInterface($date)
        ->setTimezone(time_machine_timezone())
        ->format('l, F jS Y \a\t g:i:sa \G\M\TP');
}

function time_machine_available_since(PDO $pdo): ?DateTimeImmutable
{
    try {
        $value = $pdo->query('SELECT MIN(created_at) FROM demons')->fetchColumn();
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return new DateTimeImmutable($value, time_machine_timezone());
    } catch (Throwable) {
        return null;
    }
}

function time_machine_demon_created_at(array $demon): ?DateTimeImmutable
{
    $value = trim((string) ($demon['created_at'] ?? ''));
    if ($value === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value, time_machine_timezone());
    } catch (Throwable) {
        return null;
    }
}

function time_machine_filter_demons_created_by(array $demons, DateTimeInterface $at): array
{
    $destination = DateTimeImmutable::createFromInterface($at);

    $filtered = array_values(array_filter($demons, static function (array $demon) use ($destination): bool {
        $createdAt = time_machine_demon_created_at($demon);
        return $createdAt === null || $createdAt <= $destination;
    }));

    usort($filtered, static function (array $a, array $b): int {
        $positionCompare = (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0);
        if ($positionCompare !== 0) {
            return $positionCompare;
        }

        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    foreach ($filtered as $index => &$demon) {
        $demon['position'] = $index + 1;
    }
    unset($demon);

    return $filtered;
}

function time_machine_reconstruct_demons(array $currentDemons, array $futureEvents): array
{
    $demonsById = [];
    foreach ($currentDemons as $demon) {
        $demonId = (int) ($demon['id'] ?? 0);
        if ($demonId < 1) {
            continue;
        }

        $demon['position'] = (int) ($demon['position'] ?? 0);
        $demonsById[$demonId] = $demon;
    }

    foreach ($futureEvents as $event) {
        $demonId = (int) ($event['demon_id'] ?? 0);
        $newPosition = (int) ($event['new_position'] ?? 0);
        $oldPosition = $event['old_position'] !== null ? (int) $event['old_position'] : null;

        if ($demonId < 1 || $newPosition < 1) {
            continue;
        }

        if ($oldPosition === null) {
            if (!isset($demonsById[$demonId])) {
                continue;
            }

            unset($demonsById[$demonId]);
            foreach ($demonsById as &$otherDemon) {
                $position = (int) ($otherDemon['position'] ?? 0);
                if ($position > $newPosition) {
                    $otherDemon['position'] = $position - 1;
                }
            }
            unset($otherDemon);
            continue;
        }

        if (!isset($demonsById[$demonId]) || $oldPosition < 1) {
            continue;
        }

        if ($newPosition < $oldPosition) {
            foreach ($demonsById as $otherId => &$otherDemon) {
                if ($otherId === $demonId) {
                    continue;
                }

                $position = (int) ($otherDemon['position'] ?? 0);
                if ($position > $newPosition && $position <= $oldPosition) {
                    $otherDemon['position'] = $position - 1;
                }
            }
            unset($otherDemon);
        } elseif ($newPosition > $oldPosition) {
            foreach ($demonsById as $otherId => &$otherDemon) {
                if ($otherId === $demonId) {
                    continue;
                }

                $position = (int) ($otherDemon['position'] ?? 0);
                if ($position >= $oldPosition && $position < $newPosition) {
                    $otherDemon['position'] = $position + 1;
                }
            }
            unset($otherDemon);
        }

        $demonsById[$demonId]['position'] = $oldPosition;
    }

    $reconstructed = array_values($demonsById);
    usort($reconstructed, static function (array $a, array $b): int {
        $positionCompare = (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0);
        if ($positionCompare !== 0) {
            return $positionCompare;
        }

        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return $reconstructed;
}

function roulette_item_from_demon(array $demon, string $bucket, bool $shown): array
{
    $position = (int) ($demon['position'] ?? 0);
    $requirement = (int) ($demon['requirement'] ?? 100);
    $publisher = trim((string) ($demon['publisher'] ?? ''));
    $verifier = trim((string) ($demon['verifier'] ?? ''));
    $publisherLabel = user_public_name_by_id((int) ($demon['publisher_user_id'] ?? 0), $publisher) ?? $publisher;
    $verifierLabel = user_public_name_by_id((int) ($demon['verifier_user_id'] ?? 0), $verifier) ?? $verifier;
    $creator = demon_creator_name($demon);
    $levelId = trim((string) ($demon['level_id'] ?? ''));

    return [
        'id' => (int) ($demon['id'] ?? 0),
        'bucket' => $bucket,
        'shown' => $shown,
        'position' => $position,
        'currentPosition' => (int) ($demon['current_position'] ?? $position),
        'name' => (string) ($demon['name'] ?? ''),
        'creator' => $creator !== '' ? $creator : $publisherLabel,
        'url' => base_url((string) $position),
        'videoUrl' => (string) ($demon['video_url'] ?? ''),
        'thumb' => card_thumbnail_url($demon),
        'levelId' => $levelId,
        'byline' => 'published by ' . $publisherLabel . ($verifierLabel !== '' ? ', verified by ' . $verifierLabel : ''),
        'score' => number_format(pointercrate_score($position, $requirement, $requirement), 2) . ' (' . $requirement . '%) - '
            . number_format(pointercrate_score($position, $requirement, 100), 2) . ' (100%) points',
    ];
}
