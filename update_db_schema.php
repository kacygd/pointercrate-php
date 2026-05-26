<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function out(array $lines): void
{
    $text = implode(PHP_EOL, $lines) . PHP_EOL;
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo $text;
}

$shouldRun = PHP_SAPI === 'cli' || (($_GET['run'] ?? '') === '1');
if (!$shouldRun) {
    out([
        'Schema update is ready.',
    ]);
    exit;
}

$pdo = db();
$logs = [];

try {
    if (schema_needs_update($pdo)) {
        $logs = array_merge($logs, run_schema_update($pdo));
    } else {
        $logs[] = '[OK] Schema is already up to date.';
    }

    if (app_setting_set('schema.version', schema_target_version())) {
        $logs[] = '[OK] Updated schema.version to ' . schema_target_version();
    } else {
        $logs[] = '[WARN] Could not update schema.version in app_settings.';
    }

    if (schema_set_updated_flag(1)) {
        $logs[] = '[OK] Set app.updated = 1 in config.php';
    } else {
        $logs[] = '[WARN] Could not write app.updated to config.php (check file permission).';
    }

    out($logs);
} catch (Throwable $e) {
    $logs[] = '[ERROR] ' . $e->getMessage();
    out($logs);
    exit(1);
}
