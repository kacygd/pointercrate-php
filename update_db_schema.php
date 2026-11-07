<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$isCli = PHP_SAPI === 'cli';

function update_schema_output(array $lines, bool $isCli): never
{
    if ($isCli) {
        echo implode(PHP_EOL, $lines) . PHP_EOL;
        exit;
    }

    render_header(t('schema.title'), 'admin');
    ?>
    <section class="panel panel-narrow fade">
        <div class="panel-head"><h1><?= e(t('schema.title')) ?></h1><p><?= e(t('schema.finished')) ?></p></div>
        <pre class="admin-update-log"><?= e(implode(PHP_EOL, $lines)) ?></pre>
        <p><a class="button blue hover" href="<?= e(base_url('admin.php')) ?>"><?= e(t('schema.back_to_admin')) ?></a></p>
    </section>
    <?php
    render_footer();
    exit;
}

if (!$isCli) {
    if (!(is_logged_in() && has_owner_access())) {
        http_response_code(403);
        exit('Forbidden');
    }

    if (!method_is_post()) {
        render_header(t('schema.title'), 'admin');
        ?>
        <section class="panel panel-narrow fade">
            <div class="panel-head"><h1><?= e(t('schema.title')) ?></h1><p><?= e(t('schema.intro')) ?></p></div>
            <form method="post" action="<?= e(base_url('update_db_schema.php')) ?>">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <button class="button blue hover" type="submit"><?= e(t('schema.run')) ?></button>
            </form>
        </section>
        <?php
        render_footer();
        exit;
    }

    if (!validate_csrf($_POST['_token'] ?? null)) {
        http_response_code(403);
        exit(t('schema.invalid_token'));
    }
}

$pdo = db();
$logs = [];

try {
    if (schema_config_needs_update() || schema_needs_update($pdo)) {
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

    update_schema_output($logs, $isCli);
} catch (Throwable $e) {
    $logs[] = '[ERROR] ' . $e->getMessage();
    update_schema_output($logs, $isCli);
}
