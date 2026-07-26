<?php
$sections = [
    'profiles' => [tr('پروفایل‌ها', 'Profiles'), ['name','name-for-users','validity','starts-when','price','override-shared-users','comment']],
    'limitations' => [tr('محدودیت‌ها', 'Limitations'), ['name','rate-limit-rx','rate-limit-tx','transfer-limit','download-limit','upload-limit','uptime-limit','reset-counters-interval','comment']],
    'profile_limitations' => [tr('اتصال پروفایل و محدودیت', 'Profile limitations'), ['profile','limitation','weekdays','from-time','till-time','comment']],
    'users' => [tr('کاربران', 'Users'), ['name','group','disabled','shared-users','caller-id','comment']],
    'user_profiles' => [tr('پروفایل کاربران', 'User profiles'), ['user','profile','state','end-time']],
    'sessions' => [tr('نشست‌ها', 'Sessions'), ['user','active','user-address','calling-station-id','uptime','download','upload','started','ended','status']],
];
?>
<div class="page-head"><div><a class="back-link" href="<?= e(url('routers')) ?>">→ <?= e(tr('بازگشت به روترها','Back to routers')) ?></a><span class="eyebrow dark">RouterOS User Manager</span><h1><?= e($router['name']) ?></h1><p><?= e(tr('نمایش مستقیم اطلاعات موجود در User Manager روتر','Live data read directly from the router User Manager')) ?></p></div><form method="post" action="<?= e(url('router-sync')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($router['id']) ?>"><button class="button primary"><?= e(tr('ورود اطلاعات به پنل','Import into panel')) ?> ↻</button></form></div>

<?php foreach($sections as $key => [$title,$columns]): $rows=$snapshot[$key]??[]; ?>
<section class="panel" style="margin-bottom:18px"><div class="panel-head"><div><h2><?= e($title) ?></h2><p><?= fa_digits(count($rows)) ?> <?= e(tr('رکورد','records')) ?></p></div></div><div class="table-wrap"><table><thead><tr><?php foreach($columns as $column):?><th dir="ltr"><?= e($column) ?></th><?php endforeach;?></tr></thead><tbody>
<?php foreach($rows as $row):?><tr><?php foreach($columns as $column): $value=$row[$column]??''; if(is_array($value)){$value=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);} ?><td dir="ltr"><?= e((string)$value) ?></td><?php endforeach;?></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="<?= count($columns) ?>" class="empty"><?= e(tr('اطلاعاتی وجود ندارد.','No data found.')) ?></td></tr><?php endif;?></tbody></table></div></section>
<?php endforeach;?>
