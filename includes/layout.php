<?php
declare(strict_types=1);

function render_language_menu_form(string $class = 'nav-language-switcher', string $dropdownId = 'nav-language-dropdown', bool $showCode = false): void
{
    $current = current_language();
    $languages = supported_languages();
    $currentLanguage = $languages[$current] ?? ['name' => $current, 'flag_code' => ''];
    $currentFlag = country_flag_html((string) ($currentLanguage['flag_code'] ?? ''), true);
    ?>
    <div class="nav-group nav-tool-group <?= e($class) ?>">
        <button class="nav-item hover white nav-tool-trigger js-toggle" type="button" aria-label="<?= e(t('nav.language')) ?>" title="<?= e((string) $currentLanguage['name']) ?>" data-dropdown-id="<?= e($dropdownId) ?>">
            <?= $currentFlag ?><?php if ($showCode): ?><span class="nav-language-code"><?= e(strtoupper($current)) ?></span><?php endif; ?><i class="fas fa-chevron-down" aria-hidden="true"></i>
        </button>
        <div id="<?= e($dropdownId) ?>" class="white dropdown nav-tool-dropdown">
            <?php foreach ($languages as $code => $language): ?>
                <?php $flag = country_flag_html((string) ($language['flag_code'] ?? ''), true); ?>
                <a class="<?= $code === $current ? 'active' : '' ?>" href="<?= e(language_url($code)) ?>" title="<?= e((string) $language['name']) ?>" aria-label="<?= e((string) $language['name']) ?>">
                    <?= $flag ?><span class="nav-language-name"><?= e((string) $language['name']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function render_theme_switcher(string $class = 'nav-theme-switcher'): void
{
    $theme = current_theme();
    $nextTheme = $theme === 'dark' ? 'light' : 'dark';
    $label = $theme === 'dark' ? t('nav.theme_dark') : t('nav.theme_light');
    ?>
    <div class="nav-group nav-tool-group <?= e($class) ?>">
        <a class="nav-item hover white nav-theme-toggle" href="<?= e(theme_url($nextTheme)) ?>" aria-label="<?= e(t('nav.theme_toggle')) ?>" title="<?= e($label) ?>">
            <i class="<?= $theme === 'dark' ? 'fas fa-moon' : 'fas fa-sun' ?>" aria-hidden="true"></i>
        </a>
    </div>
    <?php
}


function render_header(string $title, string $activeNav = '', array $meta = []): void
{
    $appName = app_name();
    $brandMode = (int) config('app.brand_mode', 1);
    $logoPathRaw = trim((string) config('app.logo_path', 'logo.png'));
    $brandLogoUrl = null;
    $brandLogoStyle = '';
    if ($brandMode === 2 && $logoPathRaw !== '') {
        $logoRelativePath = ltrim(str_replace('\\', '/', $logoPathRaw), '/');
        $logoFsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logoRelativePath);
        if ($logoRelativePath !== '' && is_file($logoFsPath)) {
            $brandLogoUrl = base_url($logoRelativePath);
            $logoHeight = (int) config('app.logo_height', 34);
            if ($logoHeight < 18) {
                $logoHeight = 18;
            } elseif ($logoHeight > 52) {
                $logoHeight = 52;
            }

            $logoMaxWidth = (int) config('app.logo_max_width', 220);
            if ($logoMaxWidth < 100) {
                $logoMaxWidth = 100;
            } elseif ($logoMaxWidth > 520) {
                $logoMaxWidth = 520;
            }

            $brandLogoStyle = sprintf(
                'height:%dpx;max-height:%dpx;width:auto;max-width:min(48vw,%dpx);object-fit:contain;display:block;',
                $logoHeight,
                $logoHeight,
                $logoMaxWidth
            );
        }
    }

    $pageTitle = trim((string) ($meta['title'] ?? $title));
    if ($pageTitle === '') {
        $pageTitle = $title !== '' ? $title : $appName;
    }

    $metaDescription = trim((string) ($meta['description'] ?? app_tagline()));
    if ($metaDescription === '') {
        $metaDescription = app_tagline();
    }

    $metaType = trim((string) ($meta['type'] ?? 'website'));
    if ($metaType === '') {
        $metaType = 'website';
    }

    $metaUrl = absolute_url((string) ($meta['url'] ?? current_url_absolute()));
    $metaImageRaw = trim((string) ($meta['image'] ?? ''));
    $metaImage = $metaImageRaw !== '' ? absolute_url($metaImageRaw) : null;
    $twitterCard = $metaImage !== null ? 'summary_large_image' : 'summary';

    $success = flash('success');
    $error = flash('error');
    $user = current_user();
    $showAdminLink = is_admin();
    $profileActive = in_array($activeNav, ['account', 'submit'], true);
    $timeMachineActive = $activeNav === 'time_machine';
    $statsViewerActive = $activeNav === 'players';
    $demonlistActive = in_array($activeNav, ['list', 'guidelines', 'roulette', 'time_machine'], true);
    $loginUrl = base_url('login.php');
    if (!in_array($activeNav, ['login', 'register'], true)) {
        $loginUrl = base_url('login.php?next=' . rawurlencode(auth_next_path(current_request_path_with_query())));
    }

    $styleFilePath = dirname(__DIR__) . '/assets/css/style.css';
    $styleVersion = is_file($styleFilePath) ? (string) filemtime($styleFilePath) : '1';
    $styleHref = base_url('assets/css/style.css?v=' . rawurlencode($styleVersion));
?>
<!doctype html>
<html lang="<?= e(current_language()) ?>" data-theme="<?= e(current_theme()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e($appName) ?></title>
    <meta name="description" content="<?= e($metaDescription) ?>">
    <link rel="canonical" href="<?= e($metaUrl) ?>">
    <meta property="og:site_name" content="<?= e($appName) ?>">
    <meta property="og:type" content="<?= e($metaType) ?>">
    <meta property="og:title" content="<?= e($pageTitle . ' | ' . $appName) ?>">
    <meta property="og:description" content="<?= e($metaDescription) ?>">
    <meta property="og:url" content="<?= e($metaUrl) ?>">
    <?php if ($metaImage !== null): ?>
        <meta property="og:image" content="<?= e($metaImage) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?= e($twitterCard) ?>">
    <meta name="twitter:title" content="<?= e($pageTitle . ' | ' . $appName) ?>">
    <meta name="twitter:description" content="<?= e($metaDescription) ?>">
    <?php if ($metaImage !== null): ?>
        <meta name="twitter:image" content="<?= e($metaImage) ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="<?= e($styleHref) ?>">
    <?php if (recaptcha_is_enabled()): ?>
        <script src="<?= e(recaptcha_script_url()) ?>" async defer></script>
    <?php endif; ?>
</head>
<body>
<header>
    <nav class="center collapse underlined see-through">
        <div class="nav-icon nav-nohide nav-brand-wrap" style="margin-right: auto; font-size: 1.1em; letter-spacing: 0.05em;">
            <a href="<?= e(base_url('index.php')) ?>" class="nav-brand-link" style="font-weight: 700; color: inherit;">
                <?php if ($brandLogoUrl !== null): ?>
                    <img src="<?= e($brandLogoUrl) ?>" alt="<?= e($appName) ?>" class="nav-brand-logo" style="<?= e($brandLogoStyle) ?>">
                <?php else: ?>
                    <?= e($appName) ?>
                <?php endif; ?>
            </a>
        </div>

        <div class="nav-group nav-demonlist-menu">
            <a class="nav-item hover white nav-demonlist-trigger <?= $demonlistActive ? 'active' : '' ?>" href="<?= e(base_url('index.php')) ?>">
                <span class="nav-demonlist-title"><?= e($appName) ?> <i class="fas fa-sort-down" aria-hidden="true"></i></span>
            </a>
            <ul class="nav-hover-dropdown white nav-demonlist-dropdown">
                <li><a class="white hover <?= $activeNav === 'guidelines' ? 'active' : '' ?>" href="<?= e(base_url('guidelines.php')) ?>"><?= e(t('nav.guidelines')) ?></a></li>
                <li><a class="white hover <?= $activeNav === 'roulette' ? 'active' : '' ?>" href="<?= e(base_url('roulette.php')) ?>"><?= e(t('nav.roulette')) ?></a></li>
                <li><a class="white hover <?= $timeMachineActive ? 'active' : '' ?>" href="<?= e(base_url('time-machine.php')) ?>"><?= e(t('nav.time_machine')) ?></a></li>
            </ul>
        </div>

        <div class="nav-group">
            <a class="nav-item hover white <?= $statsViewerActive ? 'active' : '' ?>" href="<?= e(base_url('players.php')) ?>"><?= e(t('nav.stats')) ?></a>
        </div>

        <?php if ($showAdminLink): ?>
            <div class="nav-group">
                <a class="nav-item hover white <?= $activeNav === 'admin' ? 'active' : '' ?>" href="<?= e(base_url('admin.php')) ?>"><?= e(t('nav.admin')) ?></a>
            </div>
        <?php endif; ?>

        <div class="nav-right-cluster">
            <div class="nav-tools nav-tools-right">
                <?php render_language_menu_form(); ?>
                <?php render_theme_switcher(); ?>
            </div>
        <?php if ($user !== null): ?>
            <div class="nav-group nav-auth-status nav-profile-group">
                <div class="nav-item hover white <?= $profileActive ? 'active' : '' ?>"><?= e(user_display_name_from_row($user)) ?></div>
                <div class="nav-hover-dropdown white nav-profile-dropdown">
                    <a href="<?= e(base_url('account.php')) ?>"><?= e(t('nav.profile')) ?></a>
                    <a href="<?= e(base_url('submit.php')) ?>"><?= e(t('nav.submit')) ?></a>
                    <form method="post" action="<?= e(base_url('logout.php')) ?>" class="nav-profile-logout">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <button type="submit"><?= e(t('nav.logout')) ?></button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="nav-group nav-auth-status">
                <a class="nav-item nav-login-link hover white <?= in_array($activeNav, ['login', 'register'], true) ? 'active' : '' ?>" href="<?= e($loginUrl) ?>"><?= e(t('nav.login')) ?></a>
            </div>
        <?php endif; ?>
        </div>

        <?php render_language_menu_form('nav-mobile-header-language-switcher nav-nohide', 'nav-language-dropdown-mobile-header'); ?>
        <?php render_theme_switcher('nav-mobile-header-theme-switcher nav-nohide'); ?>

        <div class="nav-item collapse-button nav-nohide">
            <div class="hamburger hover" aria-label="Toggle navigation">
                <input type="checkbox" id="mobile-nav-toggle">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>
        <div class="nav-drop-down" id="mobile-nav-dropdown">
            <a class="nav-item hover white" href="<?= e(base_url('index.php')) ?>"><?= e(t('nav.main_list')) ?></a>
            <a class="nav-item hover white" href="<?= e(base_url('guidelines.php')) ?>"><?= e(t('nav.guidelines')) ?></a>
            <a class="nav-item hover white" href="<?= e(base_url('submit.php')) ?>"><?= e(t('nav.submit')) ?></a>
            <a class="nav-item hover white" href="<?= e(base_url('roulette.php')) ?>"><?= e(t('nav.roulette')) ?></a>
            <a class="nav-item hover white" href="<?= e(base_url('time-machine.php')) ?>"><?= e(t('nav.time_machine')) ?></a>
            <a class="nav-item hover white" href="<?= e(base_url('players.php')) ?>"><?= e(t('nav.stats')) ?></a>
            <?php if ($showAdminLink): ?>
                <a class="nav-item hover white" href="<?= e(base_url('admin.php')) ?>"><?= e(t('nav.admin')) ?></a>
            <?php endif; ?>
            <?php if ($user !== null): ?>
                <a class="nav-item hover white" href="<?= e(base_url('account.php')) ?>"><?= e(t('nav.profile')) ?></a>
                <form method="post" action="<?= e(base_url('logout.php')) ?>" class="nav-mobile-form">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <button type="submit" class="nav-item hover white"><?= e(t('nav.logout')) ?></button>
                </form>
            <?php else: ?>
                <a class="nav-item hover white" href="<?= e($loginUrl) ?>"><?= e(t('nav.login')) ?></a>
            <?php endif; ?>
        </div>
    </nav>
    <div></div>
</header>

<div class="content">
    <main class="page-shell">
        <?php if ($success !== null): ?>
            <div class="info-green" style="margin: 10px;"><?= e($success) ?></div>
        <?php endif; ?>
        <?php if ($error !== null): ?>
            <div class="info-red" style="margin: 10px;"><?= e($error) ?></div>
        <?php endif; ?>
<?php
}

function render_footer(): void
{
    $appName = app_name();
    $year = date('Y');
    $currentPath = trim(strtolower(current_path()), '/');
    $isAuthPage = str_ends_with($currentPath, 'login')
        || str_ends_with($currentPath, 'login.php')
        || str_ends_with($currentPath, 'register')
        || str_ends_with($currentPath, 'register.php');
    $footerLoginUrl = base_url('login.php');
    $footerRegisterUrl = base_url('register.php');
    if (!$isAuthPage) {
        $footerNextPath = auth_next_path(current_request_path_with_query());
        $footerLoginUrl = base_url('login.php?next=' . rawurlencode($footerNextPath));
        $footerRegisterUrl = base_url('register.php?next=' . rawurlencode($footerNextPath));
    }
    $scriptFilePath = dirname(__DIR__) . '/assets/js/app.js';
    $scriptVersion = is_file($scriptFilePath) ? (string) filemtime($scriptFilePath) : '1';
    $scriptSrc = base_url('assets/js/app.js?v=' . rawurlencode($scriptVersion));
    $jsTranslations = t_js([
        'roulette.open',
        'roulette.level_id',
        'roulette.at_least',
        'roulette.progress_for',
        'roulette.done',
        'roulette.give_up',
        'roulette.copy_id',
        'roulette.copied',
        'roulette.restart',
        'roulette.start',
        'roulette.alert_select_list',
        'roulette.alert_no_demons',
        'roulette.error_at_least',
        'roulette.confirm_give_up',
        'roulette.saved_filename',
        'roulette.error_invalid_save',
        'roulette.error_empty_save',
        'roulette.confirm_reset',
        'roulette.loaded',
        'roulette.load_failed',
    ]);
    ?>
    </main>
</div>

<footer class="center">
    <span class="overlined pad">
        <?= e($appName) ?> &copy; <?= e($year) ?>.
        <?= e(t('footer.rights', ['app' => $appName])) ?>
    </span>
    <div class="flex no-stretch">
        <nav>
            <h2><?= e(t('footer.navigation')) ?></h2>
            <a class="link" href="<?= e(base_url('index.php')) ?>"><?= e(t('nav.main_list')) ?></a><br>
            <a class="link" href="<?= e(base_url('players.php')) ?>"><?= e(t('nav.stats')) ?></a><br>
            <a class="link" href="<?= e(base_url('roulette.php')) ?>"><?= e(t('nav.roulette')) ?></a><br>
            <a class="link" href="<?= e(base_url('time-machine.php')) ?>"><?= e(t('nav.time_machine')) ?></a><br>
            <a class="link" href="<?= e(base_url('guidelines.php')) ?>"><?= e(t('nav.guidelines')) ?></a><br>
            <a class="link" href="<?= e(base_url('submit.php')) ?>"><?= e(t('footer.submit_record')) ?></a>
        </nav>
        <nav>
            <h2><?= e(t('footer.account')) ?></h2>
            <a class="link" href="<?= e($footerRegisterUrl) ?>"><?= e(t('footer.register')) ?></a><br>
            <a class="link" href="<?= e($footerLoginUrl) ?>"><?= e(t('nav.login')) ?></a><br>
            <a class="link" href="<?= e(base_url('account.php')) ?>"><?= e(t('footer.my_account')) ?></a>
        </nav>
    </div>
    <span class="muted" style="margin-top: 8px;"><?= e(t('footer.created_by')) ?></span>
</footer>

<script>window.DEMONLIST_I18N = <?= json_encode($jsTranslations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= e($scriptSrc) ?>" defer></script>
</body>
</html>
<?php
}
