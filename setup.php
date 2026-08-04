<?php
declare(strict_types=1);

session_start();

/** One-time browser installer. It removes itself after a successful install when permissions allow it. */

$root = __DIR__;
$configPath = $root . '/config.php';
$existingConfig = is_file($configPath) ? require $configPath : null;
$isInstalled = is_array($existingConfig);

function setup_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @return array<string, string> */
function setup_supported_languages(string $root): array
{
    $languages = [];
    foreach (glob($root . '/lang/*.php') ?: [] as $file) {
        $code = basename($file, '.php');
        if (preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/', $code) !== 1) {
            continue;
        }

        $translation = require $file;
        if (is_array($translation)) {
            $languages[$code] = (string) ($translation['_name'] ?? $code);
        }
    }

    return $languages !== [] ? $languages : ['en' => 'English'];
}

/** @return array<string, string> */
function setup_translation(string $root, string $language): array
{
    $fallbackFile = $root . '/lang/en.php';
    $languageFile = $root . '/lang/' . $language . '.php';
    $fallback = is_file($fallbackFile) ? require $fallbackFile : [];
    $loaded = is_file($languageFile) ? require $languageFile : [];

    return array_replace(is_array($fallback) ? $fallback : [], is_array($loaded) ? $loaded : []);
}

function setup_t(array $copy, string $key, array $replace = []): string
{
    $text = (string) ($copy[$key] ?? $key);
    foreach ($replace as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }

    return $text;
}

function setup_base_path(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/setup.php'));
    $path = rtrim(dirname($script), '/');

    return $path === '' || $path === '.' ? '/' : $path;
}

function setup_main_url(): string
{
    return rtrim(setup_base_path(), '/') . '/';
}

function setup_schema(PDO $pdo, string $path): void
{
    $schema = file_get_contents($path);
    if (!is_string($schema)) {
        throw new RuntimeException('db/schema.sql could not be read.');
    }

    foreach (preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [] as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function setup_remove_self(): bool
{
    $file = __FILE__;
    if (!is_file($file)) {
        return true;
    }

    if (@unlink($file) || !is_file($file)) {
        return true;
    }

    register_shutdown_function(static function () use ($file): void {
        if (is_file($file)) {
            @unlink($file);
        }
    });

    return false;
}

function setup_optional_url(string $value, string $label): string
{
    $value = trim($value);
    if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException($label . ' must be a valid URL.');
    }

    return $value;
}

function setup_recaptcha_verify(string $secretKey, string $responseToken, string $verifyUrl = 'https://www.google.com/recaptcha/api/siteverify', ?string $remoteIp = null): bool
{
    $secretKey = trim($secretKey);
    $responseToken = trim($responseToken);
    if ($secretKey === '' || $responseToken === '') {
        return false;
    }

    $payload = [
        'secret' => $secretKey,
        'response' => $responseToken,
    ];
    if ($remoteIp !== null && $remoteIp !== '') {
        $payload['remoteip'] = $remoteIp;
    }

    $encodedPayload = http_build_query($payload);
    $raw = false;

    if (function_exists('curl_init')) {
        $curl = curl_init($verifyUrl);
        if ($curl !== false) {
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $encodedPayload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 8,
            ]);
            $raw = curl_exec($curl);
            curl_close($curl);
        }
    }

    if (!is_string($raw) || $raw === '') {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $encodedPayload,
                'timeout' => 8,
            ],
        ]);
        $raw = @file_get_contents($verifyUrl, false, $context);
    }
    if (!is_string($raw) || $raw === '') {
        return false;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) && ($decoded['success'] ?? false) === true;
}

function setup_recaptcha_site_key_valid(string $siteKey): bool
{
    return preg_match('/^[A-Za-z0-9_-]{20,}$/', trim($siteKey)) === 1;
}

$languages = setup_supported_languages($root);
$language = (string) ($_GET['lang'] ?? $_POST['setup_language'] ?? $_POST['default_language'] ?? 'en');
if (!isset($languages[$language])) {
    $language = array_key_exists('en', $languages) ? 'en' : array_key_first($languages);
}
$copy = setup_translation($root, $language);
$defaultLanguage = (string) ($_POST['default_language'] ?? $language);
if (!isset($languages[$defaultLanguage])) {
    $defaultLanguage = $language;
}

if ($isInstalled && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    setup_remove_self();
}

$values = [
    'name' => '',
    'tagline' => '',
    'base_url' => setup_base_path(),
    'public_url' => '',
    'timezone' => 'UTC',
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => '',
    'db_user' => '',
    'db_password' => '',
    'owner_name' => '',
    'owner_email' => '',
    'discord_webhook_url' => '',
    'discord_bot_token' => '',
    'discord_bot_api_base_url' => 'https://discord.com/api/v10',
    'discord_server_widget_url' => '',
    'discord_server_id' => '',
    'discord_server_theme' => 'dark',
    'recaptcha_site_key' => '',
    'recaptcha_secret_key' => '',
];

$error = '';
if (!$isInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $default) {
        $values[$key] = trim((string) ($_POST[$key] ?? $default));
    }

    $password = (string) ($_POST['owner_password'] ?? '');
    $confirm = (string) ($_POST['owner_password_confirm'] ?? '');

    try {
        if (!setup_recaptcha_site_key_valid($values['recaptcha_site_key']) || $values['recaptcha_secret_key'] === '') {
            throw new RuntimeException(setup_t($copy, 'setup.error.recaptcha_keys'));
        }
        if (!setup_recaptcha_verify(
            $values['recaptcha_secret_key'],
            (string) ($_POST['g-recaptcha-response'] ?? ''),
            'https://www.google.com/recaptcha/api/siteverify',
            (string) ($_SERVER['REMOTE_ADDR'] ?? '')
        )) {
            throw new RuntimeException(setup_t($copy, 'setup.error.recaptcha'));
        }

        if ($values['name'] === '' || $values['db_name'] === '' || $values['db_user'] === '' || $values['owner_name'] === '') {
            throw new RuntimeException(setup_t($copy, 'setup.error.required'));
        }
        if (preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $values['owner_name']) !== 1) {
            throw new RuntimeException(setup_t($copy, 'setup.error.owner_username'));
        }
        if (strlen($password) < 8) {
            throw new RuntimeException(setup_t($copy, 'setup.error.owner_password'));
        }
        if (!hash_equals($password, $confirm)) {
            throw new RuntimeException(setup_t($copy, 'setup.error.owner_password_match'));
        }
        if ($values['owner_email'] !== '' && filter_var($values['owner_email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException(setup_t($copy, 'setup.error.owner_email'));
        }

        $port = filter_var($values['db_port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            throw new RuntimeException(setup_t($copy, 'setup.error.db_port'));
        }

        $values['public_url'] = setup_optional_url($values['public_url'], setup_t($copy, 'setup.public_url'));
        $values['discord_webhook_url'] = setup_optional_url($values['discord_webhook_url'], setup_t($copy, 'setup.discord_webhook_url'));
        $values['discord_bot_api_base_url'] = setup_optional_url(
            $values['discord_bot_api_base_url'] !== '' ? $values['discord_bot_api_base_url'] : 'https://discord.com/api/v10',
            setup_t($copy, 'setup.discord_bot_api_base_url')
        );
        $values['discord_server_widget_url'] = setup_optional_url($values['discord_server_widget_url'], setup_t($copy, 'setup.discord_server_widget_url'));
        $values['discord_server_id'] = preg_replace('/\D+/', '', $values['discord_server_id']) ?? '';
        $values['discord_server_theme'] = in_array($values['discord_server_theme'], ['dark', 'light'], true)
            ? $values['discord_server_theme']
            : 'dark';

        $pdo = new PDO(
            'mysql:host=' . $values['db_host'] . ';port=' . $port . ';dbname=' . $values['db_name'] . ';charset=utf8mb4',
            $values['db_user'],
            $values['db_password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        setup_schema($pdo, $root . '/db/schema.sql');

        $owner = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $owner->execute([':username' => $values['owner_name']]);
        if (!$owner->fetchColumn()) {
            $insert = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (:username, :email, :password_hash, 'owner')");
            $insert->execute([
                ':username' => $values['owner_name'],
                ':email' => $values['owner_email'] !== '' ? $values['owner_email'] : null,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        }

        $config = [
            'app' => [
                'name' => $values['name'],
                'brand_mode' => 1,
                'logo_path' => 'logo.png',
                'logo_height' => 22,
                'logo_max_width' => 130,
                'tagline' => $values['tagline'],
                'base_url' => '/' . trim($values['base_url'], '/'),
                'public_url' => $values['public_url'],
                'timezone' => $values['timezone'],
                'default_language' => $defaultLanguage,
                'debug' => false,
                'updated' => 1,
                'installation_complete' => true,
            ],
            'db' => [
                'host' => $values['db_host'],
                'port' => (int) $port,
                'database' => $values['db_name'],
                'username' => $values['db_user'],
                'password' => $values['db_password'],
                'charset' => 'utf8mb4',
            ],
            'discord' => [
                'webhook_url' => $values['discord_webhook_url'],
                'bot_token' => $values['discord_bot_token'],
                'bot_api_base_url' => $values['discord_bot_api_base_url'],
                'server_widget_url' => $values['discord_server_widget_url'],
                'server_id' => $values['discord_server_id'],
                'server_theme' => $values['discord_server_theme'],
            ],
            'security' => [
                'captcha_enabled' => true,
                'setup_captcha_enabled' => true,
                'captcha_login_enabled' => true,
                'captcha_register_enabled' => true,
                'captcha_submit_enabled' => true,
                'captcha_driver' => 'google_recaptcha_v2',
                'recaptcha_site_key' => $values['recaptcha_site_key'],
                'recaptcha_secret_key' => $values['recaptcha_secret_key'],
                'recaptcha_verify_url' => 'https://www.google.com/recaptcha/api/siteverify',
                'csrf_enabled' => true,
                'session_cookie_httponly' => true,
                'session_cookie_samesite' => 'Lax',
                'session_cookie_secure' => false,
            ],
        ];

        $configSource = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($configPath, $configSource, LOCK_EX) === false) {
            throw new RuntimeException(setup_t($copy, 'setup.error.config_write'));
        }

        setup_remove_self();
        header('Location: ' . setup_main_url());
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="<?= setup_e($language) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= setup_e(setup_t($copy, 'setup.title')) ?></title>
    <?php if (!$isInstalled): ?>
        <script src="https://www.google.com/recaptcha/api.js?render=explicit" async defer></script>
    <?php endif; ?>
    <style>
        body{font:16px system-ui,sans-serif;background:#101824;color:#eef3fa;margin:0}
        .box{max-width:820px;margin:40px auto;padding:28px;background:#1b293a;border-radius:12px}
        h1{margin-top:0}fieldset{border:1px solid #42566f;border-radius:8px;margin:18px 0;padding:16px}
        label{display:block;margin:10px 0 4px}input,select,button{box-sizing:border-box;width:100%;padding:10px;border-radius:6px;border:1px solid #607895;font:inherit}
        button{background:#3585d4;color:white;border:0;margin-top:20px;font-weight:700;cursor:pointer}
        a{color:#87c5ff}.error{background:#6c2630;padding:12px;border-radius:6px}.lang{float:right;width:auto}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        .hint{color:#b8c8da;font-size:.9em;margin:.25rem 0 0}.recaptcha-box{margin-top:12px;min-height:78px}@media(max-width:720px){.grid{grid-template-columns:1fr}.box{margin:0;min-height:100vh;border-radius:0}}
    </style>
</head>
<body>
<div class="box">
    <form method="get" class="lang">
        <select name="lang" onchange="this.form.submit()" aria-label="<?= setup_e(setup_t($copy, 'nav.language')) ?>">
            <?php foreach ($languages as $code => $name): ?>
                <option value="<?= setup_e($code) ?>" <?= $language === $code ? 'selected' : '' ?>><?= setup_e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <h1><?= setup_e(setup_t($copy, 'setup.title')) ?></h1>

    <?php if ($isInstalled): ?>
        <p><?= setup_e(setup_t($copy, 'setup.installed')) ?></p>
        <p><a href="<?= setup_e(setup_main_url()) ?>"><?= setup_e(setup_t($copy, 'setup.open')) ?></a></p>
    <?php else: ?>
        <p><?= setup_e(setup_t($copy, 'setup.intro')) ?></p>
        <?php if ($error !== ''): ?>
            <p class="error"><?= setup_e(setup_t($copy, 'setup.error_prefix') . ' ' . $error) ?></p>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="setup_language" value="<?= setup_e($language) ?>">

            <fieldset>
                <legend><?= setup_e(setup_t($copy, 'setup.section_list')) ?></legend>
                <div class="grid">
                    <label><?= setup_e(setup_t($copy, 'setup.name')) ?><input name="name" value="<?= setup_e($values['name']) ?>" required></label>
                    <label><?= setup_e(setup_t($copy, 'setup.timezone')) ?><input name="timezone" value="<?= setup_e($values['timezone']) ?>"></label>
                </div>
                <label><?= setup_e(setup_t($copy, 'setup.tagline')) ?><input name="tagline" value="<?= setup_e($values['tagline']) ?>"></label>
                <div class="grid">
                    <label><?= setup_e(setup_t($copy, 'setup.base_url')) ?><input name="base_url" value="<?= setup_e($values['base_url']) ?>"></label>
                    <label><?= setup_e(setup_t($copy, 'setup.public_url')) ?><input name="public_url" value="<?= setup_e($values['public_url']) ?>"></label>
                </div>
                <label><?= setup_e(setup_t($copy, 'setup.default_language')) ?>
                    <select name="default_language">
                        <?php foreach ($languages as $code => $name): ?>
                            <option value="<?= setup_e($code) ?>" <?= $defaultLanguage === $code ? 'selected' : '' ?>><?= setup_e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </fieldset>

            <fieldset>
                <legend><?= setup_e(setup_t($copy, 'setup.section_database')) ?></legend>
                <div class="grid">
                    <label><?= setup_e(setup_t($copy, 'setup.db_host')) ?><input name="db_host" value="<?= setup_e($values['db_host']) ?>" required></label>
                    <label><?= setup_e(setup_t($copy, 'setup.db_port')) ?><input name="db_port" value="<?= setup_e($values['db_port']) ?>" required></label>
                </div>
                <div class="grid">
                    <label><?= setup_e(setup_t($copy, 'setup.db_name')) ?><input name="db_name" value="<?= setup_e($values['db_name']) ?>" required></label>
                    <label><?= setup_e(setup_t($copy, 'setup.db_user')) ?><input name="db_user" value="<?= setup_e($values['db_user']) ?>" required></label>
                </div>
                <label><?= setup_e(setup_t($copy, 'setup.db_password')) ?><input type="password" name="db_password"></label>
            </fieldset>

            <fieldset>
                <legend><?= setup_e(setup_t($copy, 'setup.section_discord')) ?></legend>
                <p class="hint"><?= setup_e(setup_t($copy, 'setup.discord_hint')) ?></p>
                <label><?= setup_e(setup_t($copy, 'setup.discord_webhook_url')) ?><input type="url" name="discord_webhook_url" value="<?= setup_e($values['discord_webhook_url']) ?>"></label>
                <label><?= setup_e(setup_t($copy, 'setup.discord_bot_token')) ?><input type="password" name="discord_bot_token" value="<?= setup_e($values['discord_bot_token']) ?>"></label>
                <label><?= setup_e(setup_t($copy, 'setup.discord_bot_api_base_url')) ?><input type="url" name="discord_bot_api_base_url" value="<?= setup_e($values['discord_bot_api_base_url']) ?>"></label>
                <div class="grid">
                    <label><?= setup_e(setup_t($copy, 'setup.discord_server_widget_url')) ?><input type="url" name="discord_server_widget_url" value="<?= setup_e($values['discord_server_widget_url']) ?>"></label>
                    <label><?= setup_e(setup_t($copy, 'setup.discord_server_id')) ?><input name="discord_server_id" value="<?= setup_e($values['discord_server_id']) ?>"></label>
                </div>
                <label><?= setup_e(setup_t($copy, 'setup.discord_server_theme')) ?>
                    <select name="discord_server_theme">
                        <option value="dark" <?= $values['discord_server_theme'] === 'dark' ? 'selected' : '' ?>><?= setup_e(setup_t($copy, 'setup.discord_theme_dark')) ?></option>
                        <option value="light" <?= $values['discord_server_theme'] === 'light' ? 'selected' : '' ?>><?= setup_e(setup_t($copy, 'setup.discord_theme_light')) ?></option>
                    </select>
                </label>
            </fieldset>

            <fieldset>
                <legend><?= setup_e(setup_t($copy, 'setup.section_owner')) ?></legend>
                <div class="grid">
                    <label><?= setup_e(setup_t($copy, 'setup.owner_name')) ?><input name="owner_name" value="<?= setup_e($values['owner_name']) ?>" required></label>
                    <label><?= setup_e(setup_t($copy, 'setup.owner_email')) ?><input type="email" name="owner_email" value="<?= setup_e($values['owner_email']) ?>"></label>
                </div>
                <div class="grid">
                    <label><?= setup_e(setup_t($copy, 'setup.owner_password')) ?><input type="password" name="owner_password" required></label>
                    <label><?= setup_e(setup_t($copy, 'setup.owner_password_confirm')) ?><input type="password" name="owner_password_confirm" required></label>
                </div>
            </fieldset>

            <fieldset>
                <legend><?= setup_e(setup_t($copy, 'setup.section_security')) ?></legend>
                <p class="hint"><?= setup_e(setup_t($copy, 'setup.recaptcha_hint')) ?></p>
                <div class="grid">
                    <label>
                        <?= setup_e(setup_t($copy, 'setup.recaptcha_site_key')) ?>
                        <input
                            id="recaptcha-site-key"
                            name="recaptcha_site_key"
                            value="<?= setup_e($values['recaptcha_site_key']) ?>"
                            autocomplete="off"
                            required
                        >
                    </label>
                    <label>
                        <?= setup_e(setup_t($copy, 'setup.recaptcha_secret_key')) ?>
                        <input
                            type="password"
                            name="recaptcha_secret_key"
                            value="<?= setup_e($values['recaptcha_secret_key']) ?>"
                            autocomplete="off"
                            required
                        >
                    </label>
                </div>
                <p class="hint"><?= setup_e(setup_t($copy, 'setup.recaptcha_reload_hint')) ?></p>
                <div
                    id="setup-recaptcha-widget"
                    class="recaptcha-box"
                    data-empty-message="<?= setup_e(setup_t($copy, 'setup.recaptcha_enter_site_key')) ?>"
                ></div>
            </fieldset>

            <script>
                let setupRecaptchaWidgetId = null;
                let setupRecaptchaRenderedKey = '';
                let setupRecaptchaReady = false;

                function setupRenderRecaptcha() {
                    const keyInput = document.getElementById('recaptcha-site-key');
                    const widget = document.getElementById('setup-recaptcha-widget');
                    if (!keyInput || !widget || !setupRecaptchaReady || typeof grecaptcha === 'undefined') {
                        return;
                    }

                    const siteKey = keyInput.value.trim();
                    if (siteKey.length < 20) {
                        widget.textContent = widget.dataset.emptyMessage || '';
                        setupRecaptchaWidgetId = null;
                        setupRecaptchaRenderedKey = '';
                        return;
                    }

                    if (setupRecaptchaWidgetId !== null && setupRecaptchaRenderedKey === siteKey) {
                        return;
                    }

                    widget.innerHTML = '';
                    setupRecaptchaWidgetId = null;
                    setupRecaptchaRenderedKey = '';
                    try {
                        setupRecaptchaWidgetId = grecaptcha.render(widget, { sitekey: siteKey });
                        setupRecaptchaRenderedKey = siteKey;
                    } catch (error) {
                        widget.textContent = String(error && error.message ? error.message : error);
                    }
                }

                document.addEventListener('DOMContentLoaded', () => {
                    const keyInput = document.getElementById('recaptcha-site-key');
                    if (keyInput) {
                        keyInput.addEventListener('input', setupRenderRecaptcha);
                        keyInput.addEventListener('change', setupRenderRecaptcha);
                    }
                    setupRenderRecaptcha();
                });

                const setupRecaptchaWait = window.setInterval(() => {
                    if (typeof grecaptcha !== 'undefined' && typeof grecaptcha.render === 'function') {
                        setupRecaptchaReady = true;
                        window.clearInterval(setupRecaptchaWait);
                        setupRenderRecaptcha();
                    }
                }, 250);
            </script>

            <button type="submit"><?= setup_e(setup_t($copy, 'setup.install')) ?></button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
