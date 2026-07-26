<div class="page-head"><div><a class="back-link" href="<?= e(url('routers')) ?>">→ <?= e(tr('بازگشت به روترها','Back to routers')) ?></a><h1><?= e($pageTitle) ?></h1><p><?= e(tr('آدرس روتر باید از سرور cPanel قابل دسترس باشد.','The router must be reachable from the cPanel server.')) ?></p></div></div>
<form method="post" class="panel form-panel" autocomplete="off"><?= csrf_field() ?>
<div class="form-section"><h2><?= e(tr('مشخصات روتر','Router details')) ?></h2><div class="form-grid">
    <label><span><?= e(tr('نام نمایشی','Display name')) ?> *</span><input name="name" value="<?= e($router['name']??'') ?>" required maxlength="100"></label>
    <label><span><?= e(tr('IP یا نام میزبان','IP or hostname')) ?> *</span><input name="host" dir="ltr" value="<?= e($router['host']??'') ?>" placeholder="192.168.88.1" required></label>
    <label><span><?= e(tr('پورت','Port')) ?> *</span><input type="number" name="port" min="1" max="65535" value="<?= e($router['port']??443) ?>" required></label>
    <label><span><?= e(tr('نام کاربری RouterOS','RouterOS username')) ?> *</span><input name="username" dir="ltr" value="<?= e($router['username']??'') ?>" required></label>
</div></div>
<div class="form-section"><h2><?= e(tr('احراز هویت','Authentication')) ?></h2><div class="form-grid">
    <label class="check-card"><input type="checkbox" name="use_api_key" value="1" <?= !empty($router['use_api_key'])?'checked':'' ?>><span><strong><?= e(tr('استفاده از API Key','Use API key')) ?></strong><small>RouterOS 7.13+</small></span></label>
    <span></span>
    <label><span><?= e(tr('رمز عبور','Password')) ?><?= $id?' · '.e(tr('خالی = بدون تغییر','blank = unchanged')):' *' ?></span><input type="password" name="password" dir="ltr"></label>
    <label><span>API Key<?= $id?' · '.e(tr('خالی = بدون تغییر','blank = unchanged')):'' ?></span><input type="password" name="api_key" dir="ltr"></label>
</div></div>
<div class="form-section"><h2><?= e(tr('امنیت و وضعیت','Security and status')) ?></h2><div class="check-grid">
    <label class="check-card"><input type="checkbox" name="use_tls" value="1" <?= !isset($router['use_tls'])||!empty($router['use_tls'])?'checked':'' ?>><span><strong>HTTPS / TLS</strong><small><?= e(tr('برای محیط واقعی روشن باشد','Keep enabled in production')) ?></small></span></label>
    <label class="check-card"><input type="checkbox" name="verify_tls" value="1" <?= !isset($router['verify_tls'])||!empty($router['verify_tls'])?'checked':'' ?>><span><strong><?= e(tr('بررسی گواهی TLS','Verify TLS certificate')) ?></strong><small><?= e(tr('خاموش‌کردن امنیت اتصال را کم می‌کند','Disabling this weakens security')) ?></small></span></label>
    <label class="check-card"><input type="checkbox" name="is_active" value="1" <?= !isset($router['is_active'])||!empty($router['is_active'])?'checked':'' ?>><span><strong><?= e(tr('روتر فعال','Router enabled')) ?></strong><small><?= e(tr('در نگهداری دوره‌ای شرکت کند','Include in scheduled maintenance')) ?></small></span></label>
</div></div>
<div class="form-actions"><a class="button ghost" href="<?= e(url('routers')) ?>"><?= e(tr('انصراف','Cancel')) ?></a><button class="button primary" type="submit"><?= e(tr('ذخیره روتر','Save router')) ?></button></div></form>

