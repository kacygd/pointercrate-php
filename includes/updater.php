<?php
declare(strict_types=1);

function app_updater_root(): string
{
    return dirname(__DIR__);
}

function app_updater_runtime_dir(): string
{
    return app_updater_root() . DIRECTORY_SEPARATOR . '.update';
}

function app_updater_default_repository(): string
{
    return 'kacygd/pointercrate-php';
}

function app_updater_normalize_repository(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $value = preg_replace('#^https?://github\.com/#i', '', $value) ?? $value;
    $value = preg_replace('#^git@github\.com:#i', '', $value) ?? $value;
    $value = preg_replace('#\.git/?$#i', '', $value) ?? $value;
    $value = trim($value, '/');

    if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $value) !== 1) {
        return null;
    }

    return $value;
}

function app_updater_normalize_ref(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return 'main';
    }

    if (
        strlen($value) > 120
        || preg_match('#^[A-Za-z0-9._/-]+$#', $value) !== 1
        || str_contains($value, '..')
        || str_starts_with($value, '/')
        || str_ends_with($value, '/')
    ) {
        return null;
    }

    return $value;
}

function app_updater_repository(): string
{
    $stored = app_setting_get('updates.github_repository', null);
    $configured = trim((string) config('updates.github_repository', ''));
    $repository = $stored ?? ($configured !== '' ? $configured : app_updater_default_repository());
    return app_updater_normalize_repository((string) $repository) ?? app_updater_default_repository();
}

function app_updater_ref(): string
{
    $stored = app_setting_get('updates.ref', null);
    $configured = (string) config('updates.ref', 'main');
    return app_updater_normalize_ref((string) ($stored ?? $configured)) ?? 'main';
}

function app_updater_set_source(string $repository, string $ref): bool
{
    $repository = app_updater_normalize_repository($repository) ?? '';
    $ref = app_updater_normalize_ref($ref) ?? '';
    if ($repository === '' || $ref === '') {
        return false;
    }

    return app_setting_set('updates.github_repository', $repository)
        && app_setting_set('updates.ref', $ref);
}

function app_updater_normalize_path(string $path): ?string
{
    $path = str_replace('\\', '/', trim($path));
    $path = ltrim($path, '/');
    if (
        $path === ''
        || str_contains($path, "\0")
        || str_contains($path, ':')
        || preg_match('#(^|/)\.\.(/|$)#', $path) === 1
        || preg_match('#(^|/)[.]/#', $path) === 1
    ) {
        return null;
    }

    return $path;
}

function app_updater_path_is_public_release_file(string $path): bool
{
    $path = app_updater_normalize_path($path);
    if ($path === null) {
        return false;
    }

    $lowerPath = strtolower($path);
    $rootFiles = [
        '.htaccess',
        'bootstrap.php',
        'config.example.php',
        'logo.png',
        'readme.md',
        'update_db_schema.php',
    ];
    if (in_array($lowerPath, $rootFiles, true)) {
        return true;
    }

    if ($lowerPath === 'db/schema.sql') {
        return true;
    }

    $releaseDirectories = [
        'api/',
        'assets/',
        'includes/',
        'lang/',
        'pages/',
    ];
    foreach ($releaseDirectories as $directory) {
        if (str_starts_with($lowerPath, $directory)) {
            return true;
        }
    }

    return false;
}

function app_updater_path_is_protected(string $path): bool
{
    return !app_updater_path_is_public_release_file($path);
}

function app_updater_git_blob_sha(string $content): string
{
    return sha1('blob ' . strlen($content) . "\0" . $content);
}

function app_updater_local_blob_sha(string $path): ?string
{
    if (!is_file($path)) {
        return null;
    }

    $content = file_get_contents($path);
    return is_string($content) ? app_updater_git_blob_sha($content) : null;
}

function app_updater_destination_path(string $path): string
{
    $path = app_updater_normalize_path($path);
    if ($path === null || app_updater_path_is_protected($path)) {
        throw new RuntimeException('Unsafe update path was rejected.');
    }

    $root = realpath(app_updater_root());
    if ($root === false) {
        throw new RuntimeException('Could not resolve the application directory.');
    }

    $destination = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    $ancestor = dirname($destination);
    while (!file_exists($ancestor) && $ancestor !== dirname($ancestor)) {
        $ancestor = dirname($ancestor);
    }
    $resolvedAncestor = realpath($ancestor);
    $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (
        $resolvedAncestor === false
        || ($resolvedAncestor !== $root && !str_starts_with(
            strtolower($resolvedAncestor . DIRECTORY_SEPARATOR),
            strtolower($rootPrefix)
        ))
    ) {
        throw new RuntimeException('Update path escaped the application directory.');
    }

    return $destination;
}

function app_updater_ensure_runtime_dir(): string
{
    $directory = app_updater_runtime_dir();
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the update runtime directory.');
    }

    $denyFile = $directory . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($denyFile)) {
        $rules = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n\n"
            . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
        if (file_put_contents($denyFile, $rules, LOCK_EX) === false) {
            throw new RuntimeException('Could not protect the update runtime directory.');
        }
    }

    return $directory;
}

function app_updater_state_path(): string
{
    return app_updater_runtime_dir() . DIRECTORY_SEPARATOR . 'state.json';
}

function app_updater_read_state(): array
{
    $path = app_updater_state_path();
    if (!is_file($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if (!is_string($json)) {
        return [];
    }

    $state = json_decode($json, true);
    return is_array($state) ? $state : [];
}

function app_updater_write_json_atomic(string $path, array $data): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create directory for update state.');
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode update state.');
    }

    $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Could not write update state.');
    }
    if (is_file($path) && !unlink($path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not replace update state.');
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not activate update state.');
    }
}

function app_updater_write_state_from_plan(array $plan): void
{
    app_updater_ensure_runtime_dir();
    $stateFiles = [];
    foreach ((array) ($plan['files'] ?? []) as $path => $file) {
        if (is_array($file) && isset($file['sha'])) {
            $stateFiles[(string) $path] = (string) $file['sha'];
        }
    }
    app_updater_write_json_atomic(app_updater_state_path(), [
        'repository' => (string) ($plan['repository'] ?? ''),
        'ref' => (string) ($plan['ref'] ?? ''),
        'commit_sha' => (string) ($plan['commit_sha'] ?? ''),
        'updated_at' => gmdate('c'),
        'files' => $stateFiles,
    ]);
}

function app_updater_http_get(string $url, string $accept, int $maxBytes): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for automatic updates.');
    }

    $headers = [
        'Accept: ' . $accept,
        'User-Agent: Pointercrate-PHP-Updater',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    $token = trim((string) getenv('DEMONLIST_GITHUB_TOKEN'));
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Could not initialize the update request.');
    }

    $body = '';
    $responseTooLarge = false;
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$responseTooLarge, $maxBytes): int {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                $responseTooLarge = true;
                return 0;
            }

            $body .= $chunk;
            return strlen($chunk);
        },
    ]);

    $requestSucceeded = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($responseTooLarge) {
        throw new RuntimeException('Update response exceeded the allowed size.');
    }
    if ($requestSucceeded === false || $status < 200 || $status >= 300) {
        $message = $error !== '' ? $error : ('GitHub returned HTTP ' . $status . '.');
        throw new RuntimeException('Update request failed: ' . $message);
    }

    return $body;
}

function app_updater_github_json(string $endpoint, int $maxBytes = 16_000_000): array
{
    $body = app_updater_http_get(
        'https://api.github.com' . $endpoint,
        'application/vnd.github+json',
        $maxBytes
    );
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('GitHub returned invalid JSON.');
    }

    return $decoded;
}

function app_updater_remote_tree(string $repository, string $ref): array
{
    $repository = app_updater_normalize_repository($repository) ?? '';
    $ref = app_updater_normalize_ref($ref) ?? '';
    if ($repository === '' || $ref === '') {
        throw new InvalidArgumentException('Configure a valid GitHub repository and branch first.');
    }

    [$owner, $repo] = explode('/', $repository, 2);
    $baseEndpoint = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);
    $commit = app_updater_github_json($baseEndpoint . '/commits/' . rawurlencode($ref), 2_000_000);
    $commitSha = strtolower(trim((string) ($commit['sha'] ?? '')));
    $treeSha = strtolower(trim((string) ($commit['commit']['tree']['sha'] ?? '')));
    if (preg_match('/^[a-f0-9]{40}$/', $commitSha) !== 1 || preg_match('/^[a-f0-9]{40}$/', $treeSha) !== 1) {
        throw new RuntimeException('GitHub did not return a valid commit tree.');
    }

    $treeResponse = app_updater_github_json($baseEndpoint . '/git/trees/' . $treeSha . '?recursive=1');
    if (!empty($treeResponse['truncated'])) {
        throw new RuntimeException('The GitHub file tree is too large for a safe incremental update.');
    }

    $files = [];
    foreach ((array) ($treeResponse['tree'] ?? []) as $entry) {
        if (!is_array($entry) || ($entry['type'] ?? '') !== 'blob') {
            continue;
        }
        $path = app_updater_normalize_path((string) ($entry['path'] ?? ''));
        $sha = strtolower(trim((string) ($entry['sha'] ?? '')));
        $size = (int) ($entry['size'] ?? 0);
        if (
            $path === null
            || app_updater_path_is_protected($path)
            || preg_match('/^[a-f0-9]{40}$/', $sha) !== 1
            || $size < 0
            || $size > 20_000_000
        ) {
            continue;
        }
        $files[$path] = ['sha' => $sha, 'size' => $size];
    }
    ksort($files, SORT_STRING);

    return [
        'repository' => $repository,
        'ref' => $ref,
        'commit_sha' => $commitSha,
        'commit_short' => substr($commitSha, 0, 7),
        'commit_message' => trim((string) ($commit['commit']['message'] ?? '')),
        'commit_date' => (string) ($commit['commit']['committer']['date'] ?? ''),
        'files' => $files,
    ];
}

function app_updater_build_plan(?string $repository = null, ?string $ref = null): array
{
    $repository = $repository ?? app_updater_repository();
    $ref = $ref ?? app_updater_ref();
    $remote = app_updater_remote_tree($repository, $ref);
    $state = app_updater_read_state();
    $stateMatches = ($state['repository'] ?? '') === $remote['repository']
        && ($state['ref'] ?? '') === $remote['ref'];
    $previousFiles = $stateMatches && is_array($state['files'] ?? null) ? $state['files'] : [];

    $changed = [];
    $conflicts = [];
    $unchanged = 0;
    foreach ($remote['files'] as $path => $remoteFile) {
        $destination = app_updater_destination_path($path);
        $localSha = app_updater_local_blob_sha($destination);
        $remoteSha = (string) $remoteFile['sha'];
        $previousSha = isset($previousFiles[$path]) ? (string) $previousFiles[$path] : null;

        if ($localSha === $remoteSha) {
            $unchanged++;
            continue;
        }

        if ($stateMatches && $previousSha !== null && $remoteSha === $previousSha) {
            if ($localSha !== null) {
                $unchanged++;
                continue;
            }
        }

        if (
            $stateMatches
            && $previousSha !== null
            && $localSha !== null
            && $localSha !== $previousSha
            && $localSha !== $remoteSha
        ) {
            $conflicts[] = $path;
            continue;
        }

        if ($stateMatches && $previousSha === null && $localSha !== null) {
            $conflicts[] = $path;
            continue;
        }

        $changed[] = [
            'path' => $path,
            'status' => $localSha === null ? 'new' : 'changed',
            'sha' => $remoteSha,
            'size' => (int) $remoteFile['size'],
        ];
    }

    $deleted = [];
    if ($stateMatches) {
        foreach ($previousFiles as $path => $previousSha) {
            $normalizedPath = app_updater_normalize_path((string) $path);
            if (
                $normalizedPath === null
                || app_updater_path_is_protected($normalizedPath)
                || isset($remote['files'][$normalizedPath])
            ) {
                continue;
            }

            $destination = app_updater_destination_path($normalizedPath);
            $localSha = app_updater_local_blob_sha($destination);
            if ($localSha === null) {
                continue;
            }
            if ($localSha !== (string) $previousSha) {
                $conflicts[] = $normalizedPath;
                continue;
            }
            $deleted[] = $normalizedPath;
        }
    }

    sort($conflicts, SORT_STRING);
    sort($deleted, SORT_STRING);

    $baselineDifferenceCount = 0;
    if (!$stateMatches) {
        // There is no reliable way to tell whether an existing file is older or locally newer.
        // Adopt the current remote commit as the baseline and only apply future remote changes.
        $baselineDifferenceCount = count($changed);
        $changed = [];
        $deleted = [];
        $conflicts = [];
    }

    $plan = $remote + [
        'changed' => $changed,
        'deleted' => $deleted,
        'conflicts' => array_values(array_unique($conflicts)),
        'unchanged_count' => $unchanged,
        'installed_commit' => $stateMatches ? (string) ($state['commit_sha'] ?? '') : '',
        'update_available' => $changed !== [] || $deleted !== [],
        'first_sync' => !$stateMatches,
        'baseline_difference_count' => $baselineDifferenceCount,
    ];

    if (!$stateMatches || (!$plan['update_available'] && $plan['conflicts'] === [])) {
        app_updater_write_state_from_plan($plan);
    }

    return $plan;
}

function app_updater_conflict_context(string $path, string $remoteSha): array
{
    $path = app_updater_normalize_path($path) ?? '';
    $remoteSha = strtolower(trim($remoteSha));
    if ($path === '' || app_updater_path_is_protected($path)) {
        throw new RuntimeException('Invalid conflict path.');
    }
    if ($remoteSha !== '' && preg_match('/^[a-f0-9]{40}$/', $remoteSha) !== 1) {
        throw new RuntimeException('Invalid remote file checksum.');
    }

    $state = app_updater_read_state();
    if (
        ($state['repository'] ?? '') !== app_updater_repository()
        || ($state['ref'] ?? '') !== app_updater_ref()
        || !is_array($state['files'] ?? null)
    ) {
        throw new RuntimeException('Update state no longer matches the configured source.');
    }

    $previousSha = isset($state['files'][$path]) ? strtolower((string) $state['files'][$path]) : null;
    $destination = app_updater_destination_path($path);
    $localSha = app_updater_local_blob_sha($destination);
    if ($localSha === null) {
        throw new RuntimeException('The local conflict file no longer exists.');
    }

    if ($remoteSha !== '') {
        if ($localSha === $remoteSha || ($previousSha !== null && $localSha === $previousSha)) {
            throw new RuntimeException('This file is no longer in conflict.');
        }
    } elseif ($previousSha === null || $localSha === $previousSha) {
        throw new RuntimeException('This file is no longer in conflict.');
    }

    return [
        'path' => $path,
        'remote_sha' => $remoteSha,
        'previous_sha' => $previousSha,
        'local_sha' => $localSha,
        'destination' => $destination,
        'state' => $state,
    ];
}

function app_updater_write_resolved_conflict_state(array $context): void
{
    $state = (array) $context['state'];
    $path = (string) $context['path'];
    $remoteSha = (string) $context['remote_sha'];
    $state['files'] = is_array($state['files'] ?? null) ? $state['files'] : [];
    if ($remoteSha === '') {
        unset($state['files'][$path]);
    } else {
        $state['files'][$path] = $remoteSha;
    }
    $state['updated_at'] = gmdate('c');
    app_updater_write_json_atomic(app_updater_state_path(), $state);
}

function app_updater_accept_local_conflict(string $path, string $remoteSha): string
{
    $context = app_updater_conflict_context($path, $remoteSha);
    app_updater_write_resolved_conflict_state($context);
    return (string) $context['path'];
}

function app_updater_use_remote_conflict(
    string $path,
    string $remoteSha,
    string $commitSha,
    int $size
): string {
    $context = app_updater_conflict_context($path, $remoteSha);
    $commitSha = strtolower(trim($commitSha));
    if (
        $remoteSha === ''
        || preg_match('/^[a-f0-9]{40}$/', $commitSha) !== 1
        || $size < 0
        || $size > 20_000_000
    ) {
        throw new RuntimeException('Invalid remote conflict version.');
    }

    $runtime = app_updater_ensure_runtime_dir();
    $runId = 'conflict-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $staging = $runtime . DIRECTORY_SEPARATOR . 'staging-' . $runId;
    $backup = $runtime . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $runId;
    $relativePath = str_replace('/', DIRECTORY_SEPARATOR, (string) $context['path']);
    $stagedPath = $staging . DIRECTORY_SEPARATOR . $relativePath;
    $backupPath = $backup . DIRECTORY_SEPARATOR . $relativePath;
    if (!mkdir(dirname($stagedPath), 0755, true) || !mkdir(dirname($backupPath), 0755, true)) {
        throw new RuntimeException('Could not create conflict staging directories.');
    }

    $content = app_updater_http_get(
        app_updater_raw_url(app_updater_repository(), $commitSha, (string) $context['path']),
        'application/octet-stream',
        max(1_000_000, $size + 1_024)
    );
    if (app_updater_git_blob_sha($content) !== $remoteSha) {
        throw new RuntimeException('Remote conflict file failed checksum verification.');
    }
    if (file_put_contents($stagedPath, $content, LOCK_EX) === false) {
        throw new RuntimeException('Could not stage the remote conflict file.');
    }
    if (strtolower(pathinfo((string) $context['path'], PATHINFO_EXTENSION)) === 'php') {
        app_updater_lint_php_file($stagedPath);
    }

    $lockPath = $runtime . DIRECTORY_SEPARATOR . 'maintenance.lock';
    $lockHandle = fopen($lockPath, 'c+');
    if (!is_resource($lockHandle) || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        if (is_resource($lockHandle)) {
            fclose($lockHandle);
        }
        app_updater_remove_tree($staging);
        throw new RuntimeException('Another update is already running.');
    }
    ftruncate($lockHandle, 0);
    fwrite($lockHandle, (string) time());
    fflush($lockHandle);

    try {
        if (!copy((string) $context['destination'], $backupPath)) {
            throw new RuntimeException('Could not back up the local conflict file.');
        }
        app_updater_activate_file($stagedPath, (string) $context['destination'], $remoteSha, true);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate((string) $context['destination'], true);
        }
        app_updater_write_resolved_conflict_state($context);
    } catch (Throwable $throwable) {
        if (is_file($backupPath)) {
            @copy($backupPath, (string) $context['destination']);
        }
        throw $throwable;
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        @unlink($lockPath);
        app_updater_remove_tree($staging);
    }

    return (string) $context['path'];
}

function app_updater_raw_url(string $repository, string $commitSha, string $path): string
{
    [$owner, $repo] = explode('/', $repository, 2);
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
    return 'https://raw.githubusercontent.com/'
        . rawurlencode($owner) . '/'
        . rawurlencode($repo) . '/'
        . rawurlencode($commitSha) . '/'
        . $encodedPath;
}

function app_updater_php_binary(): ?string
{
    $binaryName = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
    $candidates = [
        defined('PHP_BINDIR') ? rtrim(PHP_BINDIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binaryName : '',
        PHP_SAPI === 'cli' && defined('PHP_BINARY') ? PHP_BINARY : '',
    ];
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }
    return null;
}

function app_updater_lint_php_file(string $path): void
{
    $binary = app_updater_php_binary();
    if ($binary === null || !function_exists('proc_open')) {
        return;
    }

    $process = proc_open(
        [$binary, '-n', '-l', $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        app_updater_root()
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not validate downloaded PHP file.');
    }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException('Downloaded PHP file failed syntax check: ' . basename($path) . '. ' . trim($output));
    }
}

function app_updater_activate_file(
    string $stagedPath,
    string $destination,
    string $expectedSha,
    bool $hadOriginal
): void {
    $temporary = $destination . '.update-' . bin2hex(random_bytes(4));
    if (!copy($stagedPath, $temporary)) {
        throw new RuntimeException('Could not prepare replacement for ' . basename($destination) . '.');
    }

    $activated = false;
    if (PHP_OS_FAMILY === 'Windows' && $hadOriginal) {
        // Windows can keep the executing PHP script open, which prevents unlink + rename.
        $activated = copy($temporary, $destination);
        @unlink($temporary);
    } else {
        $activated = rename($temporary, $destination);
        if (!$activated) {
            @unlink($temporary);
        }
    }

    if (!$activated || app_updater_local_blob_sha($destination) !== $expectedSha) {
        throw new RuntimeException('Could not activate ' . basename($destination) . '. Check file permissions.');
    }
}

function app_updater_remove_tree(string $directory): void
{
    $runtime = realpath(app_updater_runtime_dir());
    $resolved = realpath($directory);
    if ($runtime === false || $resolved === false || !str_starts_with($resolved, $runtime . DIRECTORY_SEPARATOR)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($resolved);
}

function app_updater_install(): array
{
    $plan = app_updater_build_plan();
    if ($plan['conflicts'] !== []) {
        throw new RuntimeException('Update stopped because locally modified files conflict with the new version.');
    }
    if (!$plan['update_available']) {
        app_updater_write_state_from_plan($plan);
        return ['updated' => 0, 'deleted' => 0, 'backup' => null, 'commit_sha' => $plan['commit_sha']];
    }

    $runtime = app_updater_ensure_runtime_dir();
    $runId = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $staging = $runtime . DIRECTORY_SEPARATOR . 'staging-' . $runId;
    $backup = $runtime . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $runId;
    if (!mkdir($staging, 0755, true) || !mkdir($backup, 0755, true)) {
        throw new RuntimeException('Could not create update staging and backup directories.');
    }

    $lockPath = $runtime . DIRECTORY_SEPARATOR . 'maintenance.lock';
    $lockHandle = fopen($lockPath, 'c+');
    if (!is_resource($lockHandle) || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        if (is_resource($lockHandle)) {
            fclose($lockHandle);
        }
        app_updater_remove_tree($staging);
        throw new RuntimeException('Another update is already running.');
    }
    ftruncate($lockHandle, 0);
    fwrite($lockHandle, (string) time());
    fflush($lockHandle);
    $applied = [];
    $removed = [];

    try {
        foreach ($plan['changed'] as $file) {
            $path = (string) $file['path'];
            $content = app_updater_http_get(
                app_updater_raw_url((string) $plan['repository'], (string) $plan['commit_sha'], $path),
                'application/octet-stream',
                max(1_000_000, (int) $file['size'] + 1_024)
            );
            if (app_updater_git_blob_sha($content) !== (string) $file['sha']) {
                throw new RuntimeException('Checksum verification failed for ' . $path . '.');
            }

            $stagedPath = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $stagedDirectory = dirname($stagedPath);
            if (!is_dir($stagedDirectory) && !mkdir($stagedDirectory, 0755, true) && !is_dir($stagedDirectory)) {
                throw new RuntimeException('Could not stage ' . $path . '.');
            }
            if (file_put_contents($stagedPath, $content, LOCK_EX) === false) {
                throw new RuntimeException('Could not stage ' . $path . '.');
            }
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
                app_updater_lint_php_file($stagedPath);
            }
        }

        foreach ($plan['changed'] as $file) {
            $path = (string) $file['path'];
            $destination = app_updater_destination_path($path);
            $stagedPath = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $destinationDirectory = dirname($destination);
            if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0755, true) && !is_dir($destinationDirectory)) {
                throw new RuntimeException('Could not create destination for ' . $path . '.');
            }

            $hadOriginal = is_file($destination);
            if ($hadOriginal) {
                $backupPath = $backup . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
                $backupDirectory = dirname($backupPath);
                if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0755, true) && !is_dir($backupDirectory)) {
                    throw new RuntimeException('Could not back up ' . $path . '.');
                }
                if (!copy($destination, $backupPath)) {
                    throw new RuntimeException('Could not back up ' . $path . '.');
                }
            }

            $applied[] = ['path' => $path, 'had_original' => $hadOriginal];
            app_updater_activate_file($stagedPath, $destination, (string) $file['sha'], $hadOriginal);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($destination, true);
            }
        }

        foreach ($plan['deleted'] as $path) {
            $destination = app_updater_destination_path($path);
            if (!is_file($destination)) {
                continue;
            }
            $backupPath = $backup . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $backupDirectory = dirname($backupPath);
            if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0755, true) && !is_dir($backupDirectory)) {
                throw new RuntimeException('Could not back up removed file ' . $path . '.');
            }
            if (!copy($destination, $backupPath) || !unlink($destination)) {
                throw new RuntimeException('Could not remove outdated file ' . $path . '.');
            }
            $removed[] = $path;
        }

        $schemaFiles = ['db/schema.sql', 'includes/schema_update.php'];
        $schemaUpdateNeeded = false;
        foreach ($plan['changed'] as $file) {
            if (in_array((string) ($file['path'] ?? ''), $schemaFiles, true)) {
                $schemaUpdateNeeded = true;
                break;
            }
        }
        if ($schemaUpdateNeeded && function_exists('schema_set_updated_flag') && !schema_set_updated_flag(0)) {
            throw new RuntimeException('Could not schedule the database schema update.');
        }

        app_updater_write_state_from_plan($plan);
    } catch (Throwable $throwable) {
        foreach (array_reverse($removed) as $path) {
            $backupPath = $backup . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $destination = app_updater_destination_path($path);
            if (is_file($backupPath)) {
                @copy($backupPath, $destination);
            }
        }
        foreach (array_reverse($applied) as $file) {
            $path = (string) $file['path'];
            $destination = app_updater_destination_path($path);
            $backupPath = $backup . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (!empty($file['had_original']) && is_file($backupPath)) {
                @copy($backupPath, $destination);
            } elseif (is_file($destination)) {
                @unlink($destination);
            }
        }
        throw $throwable;
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        @unlink($lockPath);
        app_updater_remove_tree($staging);
    }

    return [
        'updated' => count($applied),
        'deleted' => count($removed),
        'backup' => $backup,
        'commit_sha' => $plan['commit_sha'],
    ];
}
