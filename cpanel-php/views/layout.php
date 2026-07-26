<?php $authUser = Auth::user(); $isFa = ($_SESSION['language'] ?? 'fa') === 'fa'; $flashes = pull_flashes(); ?>
<!doctype html>
<html lang="<?= $isFa ? 'fa' : 'en' ?>" dir="<?= $isFa ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= e($pageTitle ?? '') ?> · <?= e(config('app', 'name', 'UserManager')) ?></title>
    <link rel="stylesheet" href="public/style.css?v=1">
</head>
<body class="<?= $authUser ? 'app-shell' : 'guest-shell' ?>">
<?php if ($authUser): ?>
<header class="topbar">
    <button class="menu-toggle" type="button" aria-label="Menu" data-menu-toggle>☰</button>
    <a class="brand" href="<?= e(url()) ?>"><span class="brand-mark">UM</span><span><?= e(config('app', 'name', 'UserManager')) ?></span></a>
    <div class="topbar-spacer"></div>
    <form method="post" action="<?= e(url('language')) ?>" class="language-form">
        <?= csrf_field() ?><input type="hidden" name="back" value="<?= e((string) ($_GET['r'] ?? 'dashboard')) ?>">
        <button type="submit" name="language" value="<?= $isFa ? 'en' : 'fa' ?>" class="button ghost small"><?= $isFa ? 'EN' : 'فا' ?></button>
    </form>
    <div class="user-chip"><span class="avatar"><?= e(mb_strtoupper(mb_substr($authUser['username'], 0, 1))) ?></span><span><strong><?= e($authUser['full_name'] ?: $authUser['username']) ?></strong><small><?= e($authUser['role']) ?></small></span></div>
    <form method="post" action="<?= e(url('logout')) ?>"><?= csrf_field() ?><button class="icon-button" title="<?= e(tr('خروج', 'Logout')) ?>">↪</button></form>
</header>
<aside class="sidebar" data-sidebar>
    <nav>
        <a class="nav-item <?= $route === 'dashboard' ? 'active' : '' ?>" href="<?= e(url('dashboard')) ?>"><span>⌂</span><?= e(tr('داشبورد', 'Dashboard')) ?></a>
        <a class="nav-item <?= str_starts_with($route, 'user') ? 'active' : '' ?>" href="<?= e(url('users')) ?>"><span>◉</span><?= e(tr('کاربران PPP', 'PPP users')) ?></a>
        <a class="nav-item <?= str_starts_with($route, 'plan') ? 'active' : '' ?>" href="<?= e(url('plans')) ?>"><span>◇</span><?= e(tr('پلن‌ها', 'Plans')) ?></a>
        <a class="nav-item <?= str_starts_with($route, 'invoice') ? 'active' : '' ?>" href="<?= e(url('invoices')) ?>"><span>▤</span><?= e(tr('صورتحساب‌ها', 'Invoices')) ?></a>
        <a class="nav-item <?= $route === 'report' ? 'active' : '' ?>" href="<?= e(url('report')) ?>"><span>↗</span><?= e(tr('گزارش مالی', 'Financial report')) ?></a>
        <a class="nav-item <?= str_starts_with($route, 'router') ? 'active' : '' ?>" href="<?= e(url('routers')) ?>"><span>⌁</span><?= e(tr('روترها', 'Routers')) ?></a>
        <?php if (Auth::isSuperadmin()): ?>
        <div class="nav-label"><?= e(tr('مدیریت', 'Administration')) ?></div>
        <a class="nav-item <?= str_starts_with($route, 'admin') ? 'active' : '' ?>" href="<?= e(url('admins')) ?>"><span>♙</span><?= e(tr('مدیران', 'Administrators')) ?></a>
        <a class="nav-item <?= $route === 'activity' ? 'active' : '' ?>" href="<?= e(url('activity')) ?>"><span>◷</span><?= e(tr('گزارش فعالیت', 'Activity log')) ?></a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-help"><strong>RouterOS v7</strong><small><?= e(tr('نسخه PHP مخصوص cPanel', 'cPanel PHP edition')) ?></small></div>
</aside>
<div class="sidebar-backdrop" data-sidebar-backdrop></div>
<main class="main-content">+    <?php foreach ($flashes as $flash): ?><div class="alert <?= e($flash['type']) ?>"><span><?= $flash['type'] === 'success' ? '✓' : ($flash['type'] === 'warning' ? '!' : '×') ?></span><div><?= e($flash['message']) ?></div><button type="button" data-dismiss>×</button></div><?php endforeach; ?>
    <?= $content ?>
</main>
<?php else: ?>
    <?php foreach ($flashes as $flash): ?><div class="guest-alert alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?>
    <?= $content ?>
<?php endif; ?>
<script src="public/app.js?v=1"></script>
</body>
</html>
@@
-<main class="main-content">\+    <?php foreach ($flashes as $flash): ?><div class="alert <?= e($flash['type']) ?>"><span><?= $flash['type'] === 'success' ? '✓' : ($flash['type'] === 'warning' ? '!' : '×') ?></span><div><?= e($flash['message']) ?></div><button type="button" data-dismiss>×</button></div><?php endforeach; ?>
+<main class="main-content">
+    <?php foreach ($flashes as $flash): ?><div class="alert <?= e($flash['type']) ?>"><span><?= $flash['type'] === 'success' ? '✓' : ($flash['type'] === 'warning' ? '!' : '×') ?></span><div><?= e($flash['message']) ?></div><button type="button" data-dismiss>×</button></div><?php endforeach; ?>
