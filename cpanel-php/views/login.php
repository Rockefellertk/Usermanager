<div class="login-wrap">
    <div class="login-visual">
        <div class="signal"><i></i><i></i><i></i><i></i></div>
        <span class="eyebrow">RouterOS v7</span>
        <h1><?= e(tr('کنترل شبکه، ساده و یکپارچه', 'Simple, unified network control')) ?></h1>
        <p><?= e(tr('مدیریت کاربران PPP، صورتحساب و مصرف ترافیک از یک پنل امن.', 'Manage PPP users, billing and traffic from one secure panel.')) ?></p>
        <div class="feature-pills"><span>PPPoE</span><span>L2TP</span><span>SSTP</span><span>PPTP</span></div>
    </div>
    <div class="login-card">
        <div class="mobile-logo"><span class="brand-mark">UM</span><?= e(config('app', 'name', 'UserManager')) ?></div>
        <span class="eyebrow dark"><?= e(tr('ورود مدیر', 'Administrator sign in')) ?></span>
        <h2><?= e(tr('خوش آمدید', 'Welcome back')) ?></h2>
        <p class="muted"><?= e(tr('برای ادامه مشخصات حساب پنل را وارد کنید.', 'Enter your panel account details to continue.')) ?></p>
        <form method="post" autocomplete="on" class="stack-form">
            <?= csrf_field() ?>
            <label><?= e(tr('نام کاربری', 'Username')) ?><input name="username" required autofocus autocomplete="username"></label>
            <label><?= e(tr('رمز عبور', 'Password')) ?><div class="password-field"><input id="login-password" name="password" type="password" required autocomplete="current-password"><button type="button" data-password-toggle="login-password">◉</button></div></label>
            <button class="button primary wide" type="submit"><?= e(tr('ورود به پنل', 'Sign in')) ?></button>
        </form>
        <small class="security-note">◈ <?= e(tr('نشست امن و محافظت در برابر تلاش‌های مکرر ورود', 'Secure session with brute-force protection')) ?></small>
    </div>
</div>

