<div class="page-head"><div><span class="eyebrow dark"><?= e(tr('نمای کلی شبکه', 'Network overview')) ?></span><h1><?= e(tr('داشبورد', 'Dashboard')) ?></h1><p><?= e(tr('وضعیت کاربران، روترها و درآمد در یک نگاه', 'Users, routers and revenue at a glance')) ?></p></div><div class="head-actions"><a class="button primary" href="<?= e(url('user-form')) ?>">＋ <?= e(tr('کاربر جدید', 'New user')) ?></a></div></div>

<section class="metric-grid">
    <article class="metric-card blue"><span class="metric-icon">◉</span><div><small><?= e(tr('کل کاربران', 'Total users')) ?></small><strong><?= fa_digits($stats['total_users']) ?></strong><em><?= fa_digits($stats['active_users']) ?> <?= e(tr('فعال', 'active')) ?></em></div></article>
    <article class="metric-card green"><span class="metric-icon">⌁</span><div><small><?= e(tr('آنلاین اکنون', 'Online now')) ?></small><strong><?= fa_digits($stats['online_users']) ?></strong><em><?= e(tr('آخرین پایش ۱۵ دقیقه', 'Seen in last 15 minutes')) ?></em></div></article>
    <article class="metric-card amber"><span class="metric-icon">◷</span><div><small><?= e(tr('کاربران منقضی', 'Expired users')) ?></small><strong><?= fa_digits($stats['expired_users']) ?></strong><em><?= fa_digits($stats['overdue']) ?> <?= e(tr('فاکتور معوق', 'overdue invoices')) ?></em></div></article>
    <article class="metric-card violet"><span class="metric-icon">↗</span><div><small><?= e(tr('درآمد این ماه', 'Revenue this month')) ?></small><strong class="money-value"><?= money($stats['revenue']) ?></strong><em><?= money($stats['unpaid']) ?> <?= e(tr('مطالبات', 'outstanding')) ?></em></div></article>
</section>

<div class="content-grid two-thirds">
    <section class="panel"><div class="panel-head"><div><h2><?= e(tr('وضعیت روترها', 'Router status')) ?></h2><p><?= e(tr('آخرین همگام‌سازی و پایش', 'Latest synchronization and polling')) ?></p></div><a href="<?= e(url('routers')) ?>"><?= e(tr('مشاهده همه', 'View all')) ?> ←</a></div>
        <div class="table-wrap"><table><thead><tr><th><?= e(tr('روتر', 'Router')) ?></th><th><?= e(tr('آدرس', 'Host')) ?></th><th><?= e(tr('وضعیت', 'Status')) ?></th><th><?= e(tr('آخرین همگام‌سازی', 'Last sync')) ?></th></tr></thead><tbody>
        <?php foreach ($routers as $router): ?><tr><td><strong><?= e($router['name']) ?></strong></td><td dir="ltr"><?= e($router['host']) ?>:<?= e($router['port']) ?></td><td><span class="badge <?= badge_class($router['last_status']) ?>"><?= e(status_text($router['last_status'])) ?></span></td><td><?= e($router['last_sync_at'] ?: '—') ?></td></tr><?php endforeach; ?>
        <?php if (!$routers): ?><tr><td colspan="4" class="empty"><?= e(tr('هنوز روتری ثبت نشده است.', 'No routers configured yet.')) ?></td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <section class="panel"><div class="panel-head"><div><h2><?= e(tr('ترافیک امروز', 'Traffic today')) ?></h2><p><?= e(tr('تجمیع همه روترها', 'Across all routers')) ?></p></div></div>
        <div class="traffic-total"><span class="traffic-ring"><b><?= fa_digits(number_format(($stats['traffic_in'] + $stats['traffic_out']) / 1e9, 1)) ?></b><small>GB</small></span></div>
        <div class="traffic-legend"><div><i class="dot blue-dot"></i><span><?= e(tr('دانلود', 'Download')) ?></span><strong><?= fa_digits(number_format($stats['traffic_in']/1e9, 2)) ?> GB</strong></div><div><i class="dot green-dot"></i><span><?= e(tr('آپلود', 'Upload')) ?></span><strong><?= fa_digits(number_format($stats['traffic_out']/1e9, 2)) ?> GB</strong></div></div>
    </section>
</div>

<section class="panel"><div class="panel-head"><div><h2><?= e(tr('آخرین فعالیت‌ها', 'Recent activity')) ?></h2><p><?= e(tr('رویدادهای ثبت‌شده مدیران و سیستم', 'Recent administrator and system events')) ?></p></div><?php if(Auth::isSuperadmin()):?><a href="<?= e(url('activity')) ?>"><?= e(tr('گزارش کامل', 'Full log')) ?> ←</a><?php endif;?></div>
    <div class="activity-list"><?php foreach($recent as $item):?><div class="activity-item"><span class="activity-dot"></span><div><strong><?= e($item['action']) ?></strong><small><?= e($item['admin_name'] ?: tr('سیستم','System')) ?> · <?= e($item['created_at']) ?></small></div></div><?php endforeach;?><?php if(!$recent):?><p class="empty"><?= e(tr('فعالیتی ثبت نشده است.','No activity recorded.')) ?></p><?php endif;?></div>
</section>

