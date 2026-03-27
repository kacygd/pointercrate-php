<?php
declare(strict_types=1);

function render_header(string $title, string $activeNav = '', array $meta = []): void
{
    $appName = app_name();
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
    $profileActive = in_array($activeNav, ['account', 'submit', 'guidelines'], true);
    ?>
<!doctype html>
<html lang="en">
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
    <link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
</head>
<body>
<header>
    <nav class="center collapse underlined see-through">
        <div class="nav-icon nav-nohide" style="margin-right: auto; font-size: 1.1em; letter-spacing: 0.05em;">
            <a href="<?= e(base_url('index.php')) ?>" style="font-weight: 700; color: inherit;">
                <?= e($appName) ?>
            </a>
        </div>

        <div class="nav-group">
            <a class="nav-item hover white <?= $activeNav === 'list' ? 'active' : '' ?>" href="<?= e(base_url('index.php')) ?>">Main List</a>
        </div>
        <div class="nav-group">
            <a class="nav-item hover white <?= $activeNav === 'players' ? 'active' : '' ?>" href="<?= e(base_url('players.php')) ?>">Stats Viewer</a>
        </div>

        <?php if ($showAdminLink): ?>
            <div class="nav-group">
                <a class="nav-item hover white <?= $activeNav === 'admin' ? 'active' : '' ?>" href="<?= e(base_url('admin.php')) ?>">Admin</a>
            </div>
        <?php endif; ?>

        <?php if ($user !== null): ?>
            <div class="nav-group nav-group-right nav-auth-status">
                <div class="nav-item hover white <?= $profileActive ? 'active' : '' ?>"><?= e((string) $user['username']) ?></div>
                <div class="nav-hover-dropdown white nav-profile-dropdown">
                    <a href="<?= e(base_url('account.php')) ?>">View Profile</a>
                    <a href="<?= e(base_url('submit.php')) ?>">Submit Record</a>
                    <a href="<?= e(base_url('guidelines.php')) ?>">Guidelines</a>
                    <form method="post" action="<?= e(base_url('logout.php')) ?>" class="nav-profile-logout">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <button type="submit">Logout</button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="nav-group nav-group-right nav-auth-status">
                <a class="nav-item hover white <?= $activeNav === 'login' ? 'active' : '' ?>" href="<?= e(base_url('login.php')) ?>">Login</a>
            </div>
        <?php endif; ?>

        <div class="nav-item collapse-button nav-nohide">
            <div class="hamburger hover" aria-label="Toggle navigation">
                <input type="checkbox" id="mobile-nav-toggle">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>

        <div class="nav-drop-down" id="mobile-nav-dropdown">
            <a class="nav-item hover white" href="<?= e(base_url('index.php')) ?>">Main List</a>
            <a class="nav-item hover white" href="<?= e(base_url('players.php')) ?>">Stats Viewer</a>
            <?php if ($showAdminLink): ?>
                <a class="nav-item hover white" href="<?= e(base_url('admin.php')) ?>">Admin</a>
            <?php endif; ?>
            <?php if ($user !== null): ?>
                <a class="nav-item hover white" href="<?= e(base_url('account.php')) ?>">View Profile</a>
                <a class="nav-item hover white" href="<?= e(base_url('submit.php')) ?>">Submit Record</a>
                <a class="nav-item hover white" href="<?= e(base_url('guidelines.php')) ?>">Guidelines</a>
                <form method="post" action="<?= e(base_url('logout.php')) ?>" class="nav-mobile-form">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <button type="submit" class="nav-item hover white">Logout</button>
                </form>
            <?php else: ?>
                <a class="nav-item hover white" href="<?= e(base_url('login.php')) ?>">Login</a>
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
    ?>
    </main>
</div>

<footer class="center">
    <span class="overlined pad">
        <?= e($appName) ?> &copy; <?= e($year) ?>.
        All rights reserved <?= e($appName) ?> and the <?= e($appName) ?> are in no way affiliated with RobTopGamesAB &reg;
    </span>
    <div class="flex no-stretch">
        <nav>
            <h2>Navigation</h2>
            <a class="link" href="<?= e(base_url('index.php')) ?>">Main List</a><br>
            <a class="link" href="<?= e(base_url('players.php')) ?>">Stats Viewer</a><br>
            <a class="link" href="<?= e(base_url('guidelines.php')) ?>">Guidelines</a><br>
            <a class="link" href="<?= e(base_url('submit.php')) ?>">Submit Record</a>
        </nav>
        <nav>
            <h2>Account</h2>
            <a class="link" href="<?= e(base_url('register.php')) ?>">Register</a><br>
            <a class="link" href="<?= e(base_url('login.php')) ?>">Login</a><br>
            <a class="link" href="<?= e(base_url('account.php')) ?>">My Account</a>
        </nav>
    </div>
    <span class="muted" style="margin-top: 8px;">Created by kacygd</span>
</footer>

<script src="<?= e(base_url('assets/js/app.js')) ?>" defer></script>
</body>
</html>
<?php
}

