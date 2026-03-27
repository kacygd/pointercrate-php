<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$adminPassword = (string) config('admin.password', 'changeme');

function record_position_event(PDO $pdo, int $demonId, ?int $oldPosition, int $newPosition, ?int $changedByUserId, ?string $note = null): void
{
    $stmt = $pdo->prepare('INSERT INTO demon_position_history
        (demon_id, old_position, new_position, changed_by_user_id, note)
        VALUES
        (:demon_id, :old_position, :new_position, :changed_by_user_id, :note)');

    $stmt->execute([
        ':demon_id' => $demonId,
        ':old_position' => $oldPosition,
        ':new_position' => $newPosition,
        ':changed_by_user_id' => $changedByUserId,
        ':note' => $note !== null && trim($note) !== '' ? trim($note) : null,
    ]);
}

if (method_is_post()) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', 'Invalid session token.');
            redirect('admin.php');
        }

        $password = (string) ($_POST['password'] ?? '');
        if ($password === $adminPassword) {
            $_SESSION['admin_logged_in'] = true;
            flash('success', 'Admin login successful.');
        } else {
            flash('error', 'Wrong admin password.');
        }

        redirect('admin.php');
    }

    if ($action === 'logout' && is_admin()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', 'Invalid session token.');
            redirect('admin.php');
        }

        unset($_SESSION['admin_logged_in']);
        flash('success', 'Logged out.');
        redirect('admin.php');
    }

    if ($action === 'add_level' && is_admin()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', 'Invalid session token.');
            redirect('admin.php');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $difficulty = trim((string) ($_POST['difficulty'] ?? 'Extreme Demon'));
        $positionInput = trim((string) ($_POST['position'] ?? ''));
        $requirement = (int) ($_POST['requirement'] ?? 100);
        $creator = trim((string) ($_POST['creator'] ?? ''));
        $publisher = trim((string) ($_POST['publisher'] ?? ''));
        $verifier = trim((string) ($_POST['verifier'] ?? ''));
        $videoUrl = trim((string) ($_POST['video_url'] ?? ''));
        $thumbnail = trim((string) ($_POST['thumbnail_url'] ?? ''));
        $levelId = trim((string) ($_POST['level_id'] ?? ''));
        $levelLength = trim((string) ($_POST['level_length'] ?? ''));
        $song = trim((string) ($_POST['song'] ?? ''));
        $objectCountInput = trim((string) ($_POST['object_count'] ?? ''));
        $legacy = isset($_POST['legacy']) ? 1 : 0;

        $errors = [];
        if ($name === '') {
            $errors[] = 'Level name is required.';
        }
        if ($creator === '') {
            $errors[] = 'Creator is required.';
        }
        if ($publisher === '') {
            $errors[] = 'Publisher is required.';
        }
        if ($videoUrl === '' || filter_var($videoUrl, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Valid verification video URL is required.';
        }
        if ($thumbnail !== '' && filter_var($thumbnail, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Thumbnail URL must be valid when provided.';
        }
        if ($requirement < 1 || $requirement > 100) {
            $errors[] = 'Requirement must be between 1 and 100.';
        }

        $objectCount = null;
        if ($objectCountInput !== '') {
            if (!ctype_digit($objectCountInput)) {
                $errors[] = 'Object count must be a non-negative integer.';
            } else {
                $objectCount = (int) $objectCountInput;
            }
        }

        if ($errors !== []) {
            flash('error', implode(' ', $errors));
            redirect('admin.php');
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();

            $dupStmt = $pdo->prepare('SELECT id FROM demons WHERE LOWER(name) = LOWER(:name) LIMIT 1');
            $dupStmt->execute([':name' => $name]);
            if ($dupStmt->fetch() !== false) {
                throw new RuntimeException('A level with this name already exists.');
            }

            $maxPosition = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM demons')->fetchColumn();

            if ($positionInput !== '') {
                $position = (int) $positionInput;
                if ($position < 1) {
                    throw new RuntimeException('Position must be >= 1.');
                }
                if ($position > $maxPosition + 1) {
                    throw new RuntimeException('Position cannot be greater than ' . ($maxPosition + 1) . '.');
                }

                $positionOffset = $maxPosition + 1;

                $lift = $pdo->prepare('UPDATE demons
                    SET position = position + :offset
                    WHERE position >= :position');
                $lift->execute([
                    ':offset' => $positionOffset,
                    ':position' => $position,
                ]);

                $drop = $pdo->prepare('UPDATE demons
                    SET position = position - :shift
                    WHERE position >= :lifted_from');
                $drop->execute([
                    ':shift' => $positionOffset - 1,
                    ':lifted_from' => $position + $positionOffset,
                ]);
            } else {
                $position = $maxPosition + 1;
            }

            $insert = $pdo->prepare('INSERT INTO demons
                (position, name, difficulty, requirement, creator, publisher, verifier, video_url, thumbnail_url, level_id, level_length, song, object_count, legacy)
                VALUES
                (:position, :name, :difficulty, :requirement, :creator, :publisher, :verifier, :video_url, :thumbnail_url, :level_id, :level_length, :song, :object_count, :legacy)');

            $insert->execute([
                ':position' => $position,
                ':name' => $name,
                ':difficulty' => $difficulty !== '' ? $difficulty : 'Extreme Demon',
                ':requirement' => $requirement,
                ':creator' => $creator,
                ':publisher' => $publisher,
                ':verifier' => $verifier !== '' ? $verifier : null,
                ':video_url' => $videoUrl,
                ':thumbnail_url' => $thumbnail !== '' ? $thumbnail : null,
                ':level_id' => $levelId !== '' ? $levelId : null,
                ':level_length' => $levelLength !== '' ? $levelLength : null,
                ':song' => $song !== '' ? $song : null,
                ':object_count' => $objectCount,
                ':legacy' => $legacy,
            ]);

            $newDemonId = (int) $pdo->lastInsertId();
            record_position_event($pdo, $newDemonId, null, $position, current_user_id(), 'Level added');

            $pdo->commit();

            send_discord_webhook('', [[
                'title' => 'Level Added',
                'color' => 5814783,
                'fields' => [
                    ['name' => 'Level', 'value' => '#' . $position . ' - ' . $name, 'inline' => false],
                    ['name' => 'Creator', 'value' => $creator, 'inline' => true],
                    ['name' => 'Publisher', 'value' => $publisher, 'inline' => true],
                    ['name' => 'Verifier', 'value' => $verifier !== '' ? $verifier : '-', 'inline' => true],
                    ['name' => 'Requirement', 'value' => $requirement . '%', 'inline' => true],
                    ['name' => 'By', 'value' => (string) (current_user_display_name() ?? 'System'), 'inline' => true],
                ],
                'timestamp' => gmdate('c'),
            ]]);

            flash('success', 'Level added at position #' . $position . '.');
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $throwable->getMessage());
        }

        redirect('admin.php');
    }
    if ($action === 'edit_level' && is_admin()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', 'Invalid session token.');
            redirect('admin.php');
        }

        $targetNameInput = trim((string) ($_POST['demon_name'] ?? ''));
        $newNameInput = trim((string) ($_POST['name'] ?? ''));
        $difficultyInput = trim((string) ($_POST['difficulty'] ?? ''));
        $requirementInput = trim((string) ($_POST['requirement'] ?? ''));
        $creatorInput = trim((string) ($_POST['creator'] ?? ''));
        $publisherInput = trim((string) ($_POST['publisher'] ?? ''));
        $verifierInput = trim((string) ($_POST['verifier'] ?? ''));
        $videoUrlInput = trim((string) ($_POST['video_url'] ?? ''));
        $thumbnailInput = trim((string) ($_POST['thumbnail_url'] ?? ''));
        $levelIdInput = trim((string) ($_POST['level_id'] ?? ''));
        $levelLengthInput = trim((string) ($_POST['level_length'] ?? ''));
        $songInput = trim((string) ($_POST['song'] ?? ''));
        $objectCountInput = trim((string) ($_POST['object_count'] ?? ''));
        $legacyStatus = (string) ($_POST['legacy_status'] ?? 'keep');
        $newPositionInput = trim((string) ($_POST['new_position'] ?? ''));
        $moveNote = trim((string) ($_POST['move_note'] ?? ''));

        if ($targetNameInput === '') {
            flash('error', 'Level name is required for editing.');
            redirect('admin.php');
        }

        $errors = [];
        if ($requirementInput !== '' && !ctype_digit($requirementInput)) {
            $errors[] = 'Requirement must be a whole number.';
        }
        if ($objectCountInput !== '' && !ctype_digit($objectCountInput)) {
            $errors[] = 'Object count must be a non-negative integer.';
        }
        if ($newPositionInput !== '' && !ctype_digit($newPositionInput)) {
            $errors[] = 'New position must be a positive integer.';
        }
        if ($videoUrlInput !== '' && filter_var($videoUrlInput, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Verification video URL must be valid.';
        }
        if ($thumbnailInput !== '' && filter_var($thumbnailInput, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Thumbnail URL must be valid.';
        }
        if (!in_array($legacyStatus, ['keep', 'normal', 'legacy'], true)) {
            $errors[] = 'Invalid legacy status option.';
        }

        if ($errors !== []) {
            flash('error', implode(' ', $errors));
            redirect('admin.php');
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();

            $targetStmt = $pdo->prepare('SELECT * FROM demons WHERE LOWER(name) = LOWER(:name) LIMIT 1 FOR UPDATE');
            $targetStmt->execute([':name' => $targetNameInput]);
            $target = $targetStmt->fetch();

            if ($target === false) {
                $partial = $pdo->prepare('SELECT id, name FROM demons WHERE LOWER(name) LIKE :query ORDER BY position ASC LIMIT 2');
                $partial->execute([':query' => '%' . strtolower($targetNameInput) . '%']);
                $matches = $partial->fetchAll();

                if (count($matches) === 0) {
                    throw new RuntimeException('Level not found. Please type a valid level name.');
                }
                if (count($matches) > 1) {
                    throw new RuntimeException('Multiple levels match this name. Please type the full level name.');
                }

                $targetStmt = $pdo->prepare('SELECT * FROM demons WHERE id = :id LIMIT 1 FOR UPDATE');
                $targetStmt->execute([':id' => (int) $matches[0]['id']]);
                $target = $targetStmt->fetch();
            }

            if ($target === false) {
                throw new RuntimeException('Level not found.');
            }

            $demonId = (int) $target['id'];
            $oldPosition = (int) $target['position'];
            $maxPosition = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM demons')->fetchColumn();

            $finalName = $newNameInput !== '' ? $newNameInput : (string) $target['name'];
            $finalDifficulty = $difficultyInput !== '' ? $difficultyInput : (string) $target['difficulty'];
            $finalRequirement = $requirementInput !== '' ? (int) $requirementInput : (int) $target['requirement'];
            $targetCreator = trim((string) ($target['creator'] ?? ''));
            if ($targetCreator === '') {
                $targetCreator = (string) $target['publisher'];
            }
            $finalCreator = $creatorInput !== '' ? $creatorInput : $targetCreator;
            $finalPublisher = $publisherInput !== '' ? $publisherInput : (string) $target['publisher'];
            $finalVerifier = $verifierInput !== '' ? $verifierInput : (string) ($target['verifier'] ?? '');
            $finalVideoUrl = $videoUrlInput !== '' ? $videoUrlInput : (string) $target['video_url'];
            $finalThumbnail = $thumbnailInput !== '' ? $thumbnailInput : (string) ($target['thumbnail_url'] ?? '');
            $finalLevelId = $levelIdInput !== '' ? $levelIdInput : (string) ($target['level_id'] ?? '');
            $finalLevelLength = $levelLengthInput !== '' ? $levelLengthInput : (string) ($target['level_length'] ?? '');
            $finalSong = $songInput !== '' ? $songInput : (string) ($target['song'] ?? '');
            $finalObjectCount = $objectCountInput !== ''
                ? (int) $objectCountInput
                : ($target['object_count'] !== null ? (int) $target['object_count'] : null);

            $finalLegacy = (int) $target['legacy'];
            if ($legacyStatus === 'normal') {
                $finalLegacy = 0;
            }
            if ($legacyStatus === 'legacy') {
                $finalLegacy = 1;
            }

            if ($finalName === '') {
                throw new RuntimeException('Level name cannot be empty.');
            }
            if ($finalCreator === '') {
                throw new RuntimeException('Creator cannot be empty.');
            }
            if ($finalPublisher === '') {
                throw new RuntimeException('Publisher cannot be empty.');
            }
            if ($finalRequirement < 1 || $finalRequirement > 100) {
                throw new RuntimeException('Requirement must be between 1 and 100.');
            }
            if ($finalVideoUrl === '' || filter_var($finalVideoUrl, FILTER_VALIDATE_URL) === false) {
                throw new RuntimeException('Verification video URL must be valid.');
            }
            if ($finalThumbnail !== '' && filter_var($finalThumbnail, FILTER_VALIDATE_URL) === false) {
                throw new RuntimeException('Thumbnail URL must be valid.');
            }

            $dupStmt = $pdo->prepare('SELECT id FROM demons WHERE LOWER(name) = LOWER(:name) AND id <> :id LIMIT 1');
            $dupStmt->execute([
                ':name' => $finalName,
                ':id' => $demonId,
            ]);
            if ($dupStmt->fetch() !== false) {
                throw new RuntimeException('Another level already uses this name.');
            }

            $newPosition = $oldPosition;
            if ($newPositionInput !== '') {
                $newPosition = (int) $newPositionInput;
                if ($newPosition < 1 || $newPosition > $maxPosition) {
                    throw new RuntimeException('New position must be between 1 and ' . $maxPosition . '.');
                }
            }

            if ($newPosition !== $oldPosition) {
                $positionOffset = $maxPosition + 1;

                $parkTarget = $pdo->prepare('UPDATE demons SET position = :temporary_position WHERE id = :id');
                $parkTarget->execute([
                    ':temporary_position' => $positionOffset,
                    ':id' => $demonId,
                ]);

                if ($newPosition < $oldPosition) {
                    $lift = $pdo->prepare('UPDATE demons
                        SET position = position + :offset
                        WHERE position >= :new_position
                          AND position < :old_position');
                    $lift->execute([
                        ':offset' => $positionOffset,
                        ':new_position' => $newPosition,
                        ':old_position' => $oldPosition,
                    ]);

                    $drop = $pdo->prepare('UPDATE demons
                        SET position = position - :shift
                        WHERE position >= :lifted_from
                          AND position < :lifted_to');
                    $drop->execute([
                        ':shift' => $positionOffset - 1,
                        ':lifted_from' => $newPosition + $positionOffset,
                        ':lifted_to' => $oldPosition + $positionOffset,
                    ]);
                } else {
                    $lift = $pdo->prepare('UPDATE demons
                        SET position = position + :offset
                        WHERE position > :old_position
                          AND position <= :new_position');
                    $lift->execute([
                        ':offset' => $positionOffset,
                        ':old_position' => $oldPosition,
                        ':new_position' => $newPosition,
                    ]);

                    $drop = $pdo->prepare('UPDATE demons
                        SET position = position - :shift
                        WHERE position > :lifted_from
                          AND position <= :lifted_to');
                    $drop->execute([
                        ':shift' => $positionOffset + 1,
                        ':lifted_from' => $oldPosition + $positionOffset,
                        ':lifted_to' => $newPosition + $positionOffset,
                    ]);
                }

                record_position_event(
                    $pdo,
                    $demonId,
                    $oldPosition,
                    $newPosition,
                    current_user_id(),
                    $moveNote !== '' ? $moveNote : 'Position updated in level edit'
                );
            }

            $update = $pdo->prepare('UPDATE demons
                SET position = :position,
                    name = :name,
                    difficulty = :difficulty,
                    requirement = :requirement,
                    creator = :creator,
                    publisher = :publisher,
                    verifier = :verifier,
                    video_url = :video_url,
                    thumbnail_url = :thumbnail_url,
                    level_id = :level_id,
                    level_length = :level_length,
                    song = :song,
                    object_count = :object_count,
                    legacy = :legacy
                WHERE id = :id');

            $update->execute([
                ':position' => $newPosition,
                ':name' => $finalName,
                ':difficulty' => $finalDifficulty,
                ':requirement' => $finalRequirement,
                ':creator' => $finalCreator,
                ':publisher' => $finalPublisher,
                ':verifier' => $finalVerifier !== '' ? $finalVerifier : null,
                ':video_url' => $finalVideoUrl,
                ':thumbnail_url' => $finalThumbnail !== '' ? $finalThumbnail : null,
                ':level_id' => $finalLevelId !== '' ? $finalLevelId : null,
                ':level_length' => $finalLevelLength !== '' ? $finalLevelLength : null,
                ':song' => $finalSong !== '' ? $finalSong : null,
                ':object_count' => $finalObjectCount,
                ':legacy' => $finalLegacy,
                ':id' => $demonId,
            ]);

            $pdo->commit();

            send_discord_webhook('', [[
                'title' => 'Level Updated',
                'color' => 15105570,
                'fields' => [
                    ['name' => 'Level', 'value' => '#' . $newPosition . ' - ' . $finalName, 'inline' => false],
                    ['name' => 'Old Position', 'value' => '#' . $oldPosition, 'inline' => true],
                    ['name' => 'New Position', 'value' => '#' . $newPosition, 'inline' => true],
                    ['name' => 'By', 'value' => (string) (current_user_display_name() ?? 'System'), 'inline' => true],
                ],
                'timestamp' => gmdate('c'),
            ]]);

            flash('success', 'Updated level #' . $newPosition . ' - ' . $finalName . '.');
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $throwable->getMessage());
        }

        redirect('admin.php');
    }
    if ($action === 'move_level' && is_admin()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', 'Invalid session token.');
            redirect('admin.php');
        }

        $demonId = (int) ($_POST['demon_id'] ?? 0);
        $demonNameInput = trim((string) ($_POST['demon_name'] ?? ''));
        $newPosition = (int) ($_POST['new_position'] ?? 0);
        $note = trim((string) ($_POST['move_note'] ?? ''));

        if ($newPosition < 1 || ($demonId < 1 && $demonNameInput === '')) {
            flash('error', 'Invalid level move request.');
            redirect('admin.php');
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();

            if ($demonId < 1) {
                $exactByName = $pdo->prepare('SELECT id FROM demons WHERE legacy = 0 AND LOWER(name) = LOWER(:name) LIMIT 1');
                $exactByName->execute([':name' => $demonNameInput]);
                $matchId = $exactByName->fetchColumn();

                if ($matchId === false) {
                    $partialByName = $pdo->prepare('SELECT id, name FROM demons WHERE legacy = 0 AND LOWER(name) LIKE :query ORDER BY position ASC LIMIT 2');
                    $partialByName->execute([':query' => '%' . strtolower($demonNameInput) . '%']);
                    $matches = $partialByName->fetchAll();

                    if (count($matches) === 0) {
                        throw new RuntimeException('Level not found. Please type a valid level name.');
                    }
                    if (count($matches) > 1) {
                        throw new RuntimeException('Multiple levels match this name. Please type the full level name.');
                    }

                    $matchId = (int) $matches[0]['id'];
                }

                $demonId = (int) $matchId;
            }

            $targetStmt = $pdo->prepare('SELECT id, name, position FROM demons WHERE id = :id LIMIT 1 FOR UPDATE');
            $targetStmt->execute([':id' => $demonId]);
            $target = $targetStmt->fetch();

            if ($target === false) {
                throw new RuntimeException('Level not found.');
            }

            $oldPosition = (int) $target['position'];
            $maxPosition = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM demons')->fetchColumn();

            if ($newPosition > $maxPosition) {
                throw new RuntimeException('New position cannot exceed ' . $maxPosition . '.');
            }

            if ($newPosition !== $oldPosition) {
                $positionOffset = $maxPosition + 1;

                $parkTarget = $pdo->prepare('UPDATE demons SET position = :temporary_position WHERE id = :id');
                $parkTarget->execute([
                    ':temporary_position' => $positionOffset,
                    ':id' => $demonId,
                ]);

                if ($newPosition < $oldPosition) {
                    $lift = $pdo->prepare('UPDATE demons
                        SET position = position + :offset
                        WHERE position >= :new_position
                          AND position < :old_position');
                    $lift->execute([
                        ':offset' => $positionOffset,
                        ':new_position' => $newPosition,
                        ':old_position' => $oldPosition,
                    ]);

                    $drop = $pdo->prepare('UPDATE demons
                        SET position = position - :shift
                        WHERE position >= :lifted_from
                          AND position < :lifted_to');
                    $drop->execute([
                        ':shift' => $positionOffset - 1,
                        ':lifted_from' => $newPosition + $positionOffset,
                        ':lifted_to' => $oldPosition + $positionOffset,
                    ]);
                } else {
                    $lift = $pdo->prepare('UPDATE demons
                        SET position = position + :offset
                        WHERE position > :old_position
                          AND position <= :new_position');
                    $lift->execute([
                        ':offset' => $positionOffset,
                        ':old_position' => $oldPosition,
                        ':new_position' => $newPosition,
                    ]);

                    $drop = $pdo->prepare('UPDATE demons
                        SET position = position - :shift
                        WHERE position > :lifted_from
                          AND position <= :lifted_to');
                    $drop->execute([
                        ':shift' => $positionOffset + 1,
                        ':lifted_from' => $oldPosition + $positionOffset,
                        ':lifted_to' => $newPosition + $positionOffset,
                    ]);
                }

                $updateTarget = $pdo->prepare('UPDATE demons SET position = :position WHERE id = :id');
                $updateTarget->execute([
                    ':position' => $newPosition,
                    ':id' => $demonId,
                ]);

                record_position_event(
                    $pdo,
                    $demonId,
                    $oldPosition,
                    $newPosition,
                    current_user_id(),
                    $note !== '' ? $note : 'Position moved in admin panel'
                );
            }

            $pdo->commit();

            if ($newPosition !== $oldPosition) {
                send_discord_webhook('', [[
                    'title' => 'Level Position Updated',
                    'color' => 15105570,
                    'fields' => [
                        ['name' => 'Level', 'value' => (string) $target['name'], 'inline' => false],
                        ['name' => 'Change', 'value' => '#' . $oldPosition . ' -> #' . $newPosition, 'inline' => true],
                        ['name' => 'By', 'value' => (string) (current_user_display_name() ?? 'System'), 'inline' => true],
                        ['name' => 'Reason', 'value' => $note !== '' ? $note : 'Position moved in admin panel', 'inline' => false],
                    ],
                    'timestamp' => gmdate('c'),
                ]]);
            }

            if ($newPosition === $oldPosition) {
                flash('success', 'No change made. Level is already at #' . $oldPosition . '.');
            } else {
                flash('success', 'Moved ' . (string) $target['name'] . ' from #' . $oldPosition . ' to #' . $newPosition . '.');
            }
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $throwable->getMessage());
        }

        redirect('admin.php');
    }

    if ($action === 'set_role' && is_admin()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', 'Invalid session token.');
            redirect('admin.php');
        }

        $userId = (int) ($_POST['user_id'] ?? 0);
        $role = (string) ($_POST['role'] ?? '');
        if ($userId < 1 || !in_array($role, ['player', 'admin'], true)) {
            flash('error', 'Invalid role update request.');
            redirect('admin.php');
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();

            $userStmt = $pdo->prepare('SELECT id, username, role FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
            $userStmt->execute([':id' => $userId]);
            $target = $userStmt->fetch();
            if ($target === false) {
                throw new RuntimeException('User not found.');
            }

            $currentRole = (string) $target['role'];
            if ($currentRole !== $role) {
                if ($currentRole === 'admin' && $role === 'player') {
                    $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "admin"')->fetchColumn();
                    if ($adminCount <= 1) {
                        throw new RuntimeException('Cannot demote the last admin account.');
                    }
                }

                $updateRole = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
                $updateRole->execute([
                    ':role' => $role,
                    ':id' => $userId,
                ]);

                if ((int) ($_SESSION['user_id'] ?? 0) === $userId && $role !== 'admin') {
                    unset($_SESSION['admin_logged_in']);
                }
            }

            $pdo->commit();
            flash('success', 'Role updated for ' . (string) $target['username'] . ' to ' . strtoupper($role) . '.');
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $throwable->getMessage());
        }

        redirect('admin.php');
    }

    if ($action === 'review' && is_admin()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', 'Invalid session token.');
            redirect('admin.php');
        }

        $submissionId = (int) ($_POST['submission_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $reviewNote = trim((string) ($_POST['review_note'] ?? ''));

        if ($submissionId < 1 || !in_array($decision, ['approved', 'rejected'], true)) {
            flash('error', 'Invalid review request.');
            redirect('admin.php');
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();

            $submissionStmt = $pdo->prepare('SELECT * FROM submissions WHERE id = :id FOR UPDATE');
            $submissionStmt->execute([':id' => $submissionId]);
            $submission = $submissionStmt->fetch();

            if ($submission === false) {
                throw new RuntimeException('Submission not found.');
            }

            if ((string) $submission['status'] !== 'pending') {
                throw new RuntimeException('Submission already reviewed.');
            }

            if ($decision === 'approved') {
                if ((string) $submission['type'] !== 'completion') {
                    throw new RuntimeException('Demon submissions are disabled. Add levels manually from the admin form.');
                }

                $demonStmt = $pdo->prepare('SELECT id FROM demons WHERE LOWER(name) = LOWER(:name) LIMIT 1');
                $demonStmt->execute([':name' => (string) $submission['demon_name']]);
                $demonId = $demonStmt->fetchColumn();

                if ($demonId === false) {
                    throw new RuntimeException('Cannot approve completion: demon not found.');
                }

                $player = trim((string) ($submission['player'] ?? ''));
                if ($player === '' && (int) ($submission['submitted_by_user_id'] ?? 0) > 0) {
                    $playerLookup = $pdo->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
                    $playerLookup->execute([':id' => (int) $submission['submitted_by_user_id']]);
                    $player = (string) ($playerLookup->fetchColumn() ?: '');
                }

                if ($player === '') {
                    $player = 'Unknown';
                }

                $progress = max(1, min(100, (int) ($submission['progress'] ?? 100)));

                $existingStmt = $pdo->prepare('SELECT id, progress FROM completions WHERE demon_id = :demon_id AND player = :player LIMIT 1');
                $existingStmt->execute([
                    ':demon_id' => (int) $demonId,
                    ':player' => $player,
                ]);
                $existing = $existingStmt->fetch();

                if ($existing !== false) {
                    $newProgress = max((int) $existing['progress'], $progress);
                    $updateRecord = $pdo->prepare('UPDATE completions
                        SET video_url = :video_url,
                            progress = :progress,
                            notes = :notes
                        WHERE id = :id');

                    $updateRecord->execute([
                        ':video_url' => $submission['video_url'] ?: '#',
                        ':progress' => $newProgress,
                        ':notes' => $submission['notes'] ?: null,
                        ':id' => (int) $existing['id'],
                    ]);
                } else {
                    $placementStmt = $pdo->prepare('SELECT COALESCE(MAX(placement), 0) + 1 AS next_placement
                                                    FROM completions
                                                    WHERE demon_id = :demon_id');
                    $placementStmt->execute([':demon_id' => (int) $demonId]);
                    $nextPlacement = (int) $placementStmt->fetchColumn();

                    $insertCompletion = $pdo->prepare('INSERT INTO completions
                        (demon_id, player, video_url, progress, placement, notes)
                        VALUES
                        (:demon_id, :player, :video_url, :progress, :placement, :notes)');

                    $insertCompletion->execute([
                        ':demon_id' => (int) $demonId,
                        ':player' => $player,
                        ':video_url' => $submission['video_url'] ?: '#',
                        ':progress' => $progress,
                        ':placement' => $nextPlacement,
                        ':notes' => $submission['notes'] ?: null,
                    ]);
                }
            }

            $updateStmt = $pdo->prepare('UPDATE submissions
                                         SET status = :status,
                                             review_note = :review_note,
                                             reviewed_at = NOW()
                                         WHERE id = :id');
            $updateStmt->execute([
                ':status' => $decision,
                ':review_note' => $reviewNote !== '' ? $reviewNote : null,
                ':id' => $submissionId,
            ]);

            $pdo->commit();
            $decisionLabel = strtoupper($decision);
            $decisionColor = $decision === 'approved' ? 5763719 : 15548997;
            send_discord_webhook('', [[
                'title' => 'Submission #' . $submissionId . ' ' . $decisionLabel,
                'color' => $decisionColor,
                'fields' => [
                    ['name' => 'Demon', 'value' => (string) ($submission['demon_name'] ?? '-'), 'inline' => true],
                    ['name' => 'Player', 'value' => (string) ($submission['player'] ?? '-'), 'inline' => true],
                    ['name' => 'Progress', 'value' => (int) ($submission['progress'] ?? 0) . '%', 'inline' => true],
                    ['name' => 'Reviewed By', 'value' => (string) (current_user_display_name() ?? 'System'), 'inline' => true],
                    ['name' => 'Review Note', 'value' => $reviewNote !== '' ? $reviewNote : '-', 'inline' => false],
                ],
                'timestamp' => gmdate('c'),
            ]]);
            flash('success', 'Submission #' . $submissionId . ' marked as ' . $decision . '.');
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $throwable->getMessage());
        }

        redirect('admin.php');
    }
}

if (!is_admin()) {
    render_header('Admin Login', 'admin');
    ?>
    <section class="panel panel-narrow fade">
        <div class="panel-head">
            <h1>Admin Login</h1>
            <p>Only admins can moderate submissions and add or rank levels.</p>
        </div>
        <?php if ($adminPassword === 'changeme'): ?>
            <div class="info-red">Default password is still active (`changeme`). Change it in config.</div>
        <?php endif; ?>
        <form class="stack-form" method="post" action="<?= e(base_url('admin.php')) ?>">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="login">
            <label class="field">
                <span>Password</span>
                <input type="password" name="password" required>
            </label>
            <button class="button blue hover" type="submit">Login</button>
        </form>
    </section>
    <?php
    render_footer();
    exit;
}

$pending = db()->query('SELECT s.*, u.username AS submitter_username
                        FROM submissions s
                        LEFT JOIN users u ON u.id = s.submitted_by_user_id
                        WHERE s.status = "pending"
                        ORDER BY s.created_at ASC')->fetchAll();

$reviewed = db()->query('SELECT s.*, u.username AS submitter_username
                         FROM submissions s
                         LEFT JOIN users u ON u.id = s.submitted_by_user_id
                         WHERE s.status <> "pending"
                         ORDER BY s.reviewed_at DESC
                         LIMIT 30')->fetchAll();

$stats = [
    'pending' => (int) db()->query('SELECT COUNT(*) FROM submissions WHERE status = "pending"')->fetchColumn(),
    'approved' => (int) db()->query('SELECT COUNT(*) FROM submissions WHERE status = "approved"')->fetchColumn(),
    'rejected' => (int) db()->query('SELECT COUNT(*) FROM submissions WHERE status = "rejected"')->fetchColumn(),
    'players' => (int) db()->query('SELECT COUNT(*) FROM users WHERE role = "player"')->fetchColumn(),
    'admins' => (int) db()->query('SELECT COUNT(*) FROM users WHERE role = "admin"')->fetchColumn(),
];

$users = db()->query('SELECT id, username, email, country_code, role, created_at
                      FROM users
                      ORDER BY created_at DESC
                      LIMIT 100')->fetchAll();

$maxPosition = (int) db()->query('SELECT COALESCE(MAX(position), 1) FROM demons')->fetchColumn();
$editableDemons = db()->query('SELECT id, name, position, requirement
                               FROM demons
                               ORDER BY position ASC, name ASC')->fetchAll();

render_header('Admin', 'admin');
?>
<section class="panel fade">
    <div class="panel-head split">
        <div>
            <h1>Admin Dashboard</h1>
            <p>Moderation center for records and level management.</p>
        </div>
        <form method="post" action="<?= e(base_url('admin.php')) ?>">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="button white hover small" type="submit">Logout</button>
        </form>
    </div>

    <div class="detail-grid" style="grid-template-columns: repeat(5, 1fr); gap: 10px;">
        <div class="panel subtle"><h3><?= $stats['pending'] ?></h3><p>Pending</p></div>
        <div class="panel subtle"><h3><?= $stats['approved'] ?></h3><p>Approved</p></div>
        <div class="panel subtle"><h3><?= $stats['rejected'] ?></h3><p>Rejected</p></div>
        <div class="panel subtle"><h3><?= $stats['players'] ?></h3><p>Players</p></div>
        <div class="panel subtle"><h3><?= $stats['admins'] ?></h3><p>Admins</p></div>
    </div>
</section>


<section class="panel fade">
    <div class="panel-head">
        <h2>Add Level (Admin Only)</h2>
        <p>Only admins can add demons to the list. Extra level metadata is optional.</p>
    </div>

    <form class="stack-form" method="post" action="<?= e(base_url('admin.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_level">

        <div class="detail-grid" style="grid-template-columns: 2fr 1fr 1fr;">
            <label class="field">
                <span>Level Name</span>
                <input type="text" name="name" required>
            </label>
            <label class="field">
                <span>Position (optional)</span>
                <input type="number" min="1" name="position" placeholder="Auto = end">
            </label>
            <label class="field">
                <span>Requirement (%)</span>
                <input type="number" min="1" max="100" name="requirement" value="100" required>
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr 1fr;">
            <label class="field">
                <span>Creator(s)</span>
                <input type="text" name="creator" required>
            </label>
            <label class="field">
                <span>Publisher</span>
                <input type="text" name="publisher" required>
            </label>
            <label class="field">
                <span>Verifier</span>
                <input type="text" name="verifier">
            </label>
        </div>

        <label class="field">
            <span>Difficulty</span>
            <input type="text" name="difficulty" value="Extreme Demon" required>
        </label>

        <label class="field">
            <span>Verification Video URL</span>
            <input type="url" name="video_url" required>
        </label>

        <label class="field">
            <span>Thumbnail URL (optional)</span>
            <input type="url" name="thumbnail_url">
        </label>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span>Level ID (optional)</span>
                <input type="text" name="level_id" placeholder="e.g. 12345678">
            </label>
            <label class="field">
                <span>Level Length (optional)</span>
                <input type="text" name="level_length" placeholder="e.g. Long">
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span>Song (optional)</span>
                <input type="text" name="song" placeholder="e.g. Creo - Sphere">
            </label>
            <label class="field">
                <span>Object Count (optional)</span>
                <input type="number" min="0" name="object_count" placeholder="e.g. 178945">
            </label>
        </div>

        <label class="cb-container" style="text-align: left; margin-top: 6px;">
            <input type="checkbox" name="legacy" value="1">
            <span class="checkmark"></span>
            Mark as Legacy list entry
        </label>

        <button class="button blue hover" type="submit">Add Level</button>
    </form>
</section>

<section class="panel fade">
    <div class="panel-head">
        <h2>Edit Level</h2>
        <p>Update level information and ranking in one place.</p>
    </div>

    <form class="stack-form" method="post" action="<?= e(base_url('admin.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="edit_level">

        <label class="field">
            <span>Level to Edit</span>
            <input type="text" name="demon_name" data-suggest-list="admin-demon-list" placeholder="Type level name..." autocomplete="off" required>
        </label>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span>New Name (optional)</span>
                <input type="text" name="name" placeholder="Leave blank to keep current">
            </label>
            <label class="field">
                <span>Difficulty (optional)</span>
                <input type="text" name="difficulty" placeholder="Leave blank to keep current">
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr;">
            <label class="field">
                <span>Requirement % (optional)</span>
                <input type="number" min="1" max="100" name="requirement" placeholder="Keep current">
            </label>
            <label class="field">
                <span>Creator(s) (optional)</span>
                <input type="text" name="creator" placeholder="Keep current">
            </label>
            <label class="field">
                <span>Publisher (optional)</span>
                <input type="text" name="publisher" placeholder="Keep current">
            </label>
            <label class="field">
                <span>Verifier (optional)</span>
                <input type="text" name="verifier" placeholder="Keep current">
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span>Verification Video URL (optional)</span>
                <input type="url" name="video_url" placeholder="Keep current">
            </label>
            <label class="field">
                <span>Thumbnail URL (optional)</span>
                <input type="url" name="thumbnail_url" placeholder="Keep current">
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span>Level ID (optional)</span>
                <input type="text" name="level_id" placeholder="Keep current">
            </label>
            <label class="field">
                <span>Level Length (optional)</span>
                <input type="text" name="level_length" placeholder="Keep current">
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span>Song (optional)</span>
                <input type="text" name="song" placeholder="Keep current">
            </label>
            <label class="field">
                <span>Object Count (optional)</span>
                <input type="number" min="0" name="object_count" placeholder="Keep current">
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr 1fr;">
            <label class="field">
                <span>Legacy Status</span>
                <select name="legacy_status">
                    <option value="keep">Keep current</option>
                    <option value="normal">Set as Current List</option>
                    <option value="legacy">Set as Legacy</option>
                </select>
            </label>
            <label class="field">
                <span>New Position (optional)</span>
                <input type="number" min="1" max="<?= $maxPosition ?>" name="new_position" placeholder="Keep current">
            </label>
            <label class="field">
                <span>Move Note (optional)</span>
                <input type="text" name="move_note" placeholder="Reason for rank change">
            </label>
        </div>

        <button class="button blue hover" type="submit">Save Level Changes</button>
    </form>

    <datalist id="admin-demon-list">
        <?php foreach ($editableDemons as $demon): ?>
            <option value="<?= e((string) $demon['name']) ?>" label="#<?= (int) $demon['position'] ?> (Req <?= (int) $demon['requirement'] ?>%)"></option>
        <?php endforeach; ?>
    </datalist>
</section>

<section class="panel fade">
    <div class="panel-head">
        <h2>User Role Management</h2>
        <p>Only admin users can access level creation, position management, and moderation tools.</p>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Email</th>
                    <th>Country</th>
                    <th>Role</th>
                    <th>Joined</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users === []): ?>
                    <tr><td colspan="7" class="muted">No users found.</td></tr>
                <?php endif; ?>

                <?php foreach ($users as $member): ?>
                    <?php
                    $memberIsAdmin = (string) $member['role'] === 'admin';
                    $isLastAdmin = $memberIsAdmin && $stats['admins'] <= 1;
                    $isCurrentUser = current_user_id() !== null && (int) $member['id'] === (int) current_user_id();
                    $countryCode = normalize_country_code((string) ($member['country_code'] ?? ''));
                    $countryText = country_flag_html($countryCode);
                    ?>
                    <tr>
                        <td>#<?= (int) $member['id'] ?></td>
                        <td>
                            <b><?= e((string) $member['username']) ?></b>
                        </td>
                        <td><?= e((string) ($member['email'] ?: '-')) ?></td>
                        <td><?= $countryText !== '' ? $countryText : '-' ?></td>
                        <td><span class="badge"><?= e(strtoupper((string) $member['role'])) ?></span></td>
                        <td><?= e(date('Y-m-d', strtotime((string) $member['created_at']))) ?></td>
                        <td>
                            <form method="post" action="<?= e(base_url('admin.php')) ?>" style="display: inline-flex; gap: 6px; align-items: center;">
                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="set_role">
                                <input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>">
                                <?php if ($memberIsAdmin): ?>
                                    <input type="hidden" name="role" value="player">
                                    <button
                                        class="button red hover small"
                                        type="submit"
                                        <?= $isLastAdmin ? 'disabled' : '' ?>
                                        data-confirm="Demote this admin to player?"
                                    >
                                        Demote
                                    </button>
                                <?php else: ?>
                                    <input type="hidden" name="role" value="admin">
                                    <button class="button blue hover small" type="submit" data-confirm="Promote this player to admin?">Promote</button>
                                <?php endif; ?>
                            </form>
                            <?php if ($isCurrentUser): ?>
                                <span class="muted">(you)</span>
                            <?php endif; ?>
                            <?php if ($isLastAdmin): ?>
                                <span class="muted">Last admin cannot be demoted.</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel fade">
    <div class="panel-head">
        <h2>Pending Submissions</h2>
    </div>

    <?php if ($pending === []): ?>
        <p class="muted">No pending submissions.</p>
    <?php endif; ?>

    <?php foreach ($pending as $item): ?>
        <article class="moderation-card">
            <div class="moderation-head">
                <strong>#<?= (int) $item['id'] ?></strong>
                <span class="badge"><?= e(strtoupper((string) $item['type'])) ?></span>
                <span class="muted">Submitted: <?= e(date('Y-m-d H:i', strtotime((string) $item['created_at']))) ?></span>
            </div>

            <dl class="key-value compact">
                <div><dt>Submitter</dt><dd><?= e((string) ($item['submitter_username'] ?: $item['player'] ?: 'Unknown')) ?></dd></div>
                <div><dt>Demon</dt><dd><?= e((string) $item['demon_name']) ?></dd></div>
                <div><dt>Progress</dt><dd><?= $item['progress'] !== null ? (int) $item['progress'] . '%' : '-' ?></dd></div>
                <div><dt>Platform</dt><dd><?= e((string) ($item['platform'] ?: '-')) ?></dd></div>
                <div><dt>Refresh</dt><dd><?= $item['refresh_rate'] !== null ? (int) $item['refresh_rate'] . 'Hz' : '-' ?></dd></div>
                <div><dt>Proof</dt><dd><a class="link" target="_blank" rel="noreferrer" href="<?= e((string) ($item['video_url'] ?: '#')) ?>">Open</a></dd></div>
                <div><dt>Raw Footage</dt><dd><?= !empty($item['raw_footage_url']) ? '<a class="link" target="_blank" rel="noreferrer" href="' . e((string) $item['raw_footage_url']) . '">Open</a>' : '-' ?></dd></div>
            </dl>

            <?php if (!empty($item['notes'])): ?>
                <p><strong>Notes:</strong> <?= e((string) $item['notes']) ?></p>
            <?php endif; ?>

            <form class="moderation-actions" method="post" action="<?= e(base_url('admin.php')) ?>">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="review">
                <input type="hidden" name="submission_id" value="<?= (int) $item['id'] ?>">
                <label class="field">
                    <span>Review Note</span>
                    <input type="text" name="review_note" placeholder="Optional note">
                </label>
                <button class="button blue hover small" type="submit" name="decision" value="approved" data-confirm="Approve this submission?">Approve</button>
                <button class="button red hover small" type="submit" name="decision" value="rejected" data-confirm="Reject this submission?">Reject</button>
            </form>
        </article>
    <?php endforeach; ?>
</section>

<section class="panel fade">
    <div class="panel-head">
        <h2>Recently Reviewed</h2>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Demon</th>
                    <th>Submitter</th>
                    <th>Status</th>
                    <th>Reviewed At</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($reviewed === []): ?>
                    <tr><td colspan="6" class="muted">No reviewed submissions yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($reviewed as $item): ?>
                    <tr>
                        <td>#<?= (int) $item['id'] ?></td>
                        <td><?= e((string) $item['type']) ?></td>
                        <td><?= e((string) $item['demon_name']) ?></td>
                        <td><?= e((string) ($item['submitter_username'] ?: $item['player'] ?: '-')) ?></td>
                        <td><span class="badge <?= $item['status'] === 'approved' ? 'success' : 'error' ?>"><?= e((string) $item['status']) ?></span></td>
                        <td><?= e((string) ($item['reviewed_at'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_footer(); ?>
