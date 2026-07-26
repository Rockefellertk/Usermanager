<?php

declare(strict_types=1);

$root = __DIR__;
$errors = [];
$success = false;
$manualConfig = '';

if (is_file($root . '/config.php')) {
    http_response_code(403);
    exit('<!doctype html><meta charset="utf-8"><div dir="rtl" style="font-family:Tahoma,sans-serif;max-width:680px;margin:70px auto;padding:28px;border:1px solid #ddd;border-radius:16px"><h2>نصب قبلاً انجام شده است</h2><p>برای امنیت، فایل <code>install.php</code> را از File Manager حذف کنید.</p><a href="index.php">ورود به پنل</a></div>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $required = ['db_host', 'db_name', 'db_user', 'admin_user', 'admin_password', 'app_name'];
    foreach ($required as $field) {
        if (trim((string) ($_POST[$field] ?? '')) === '') {
            $errors[] = 'تمام فیلدهای الزامی را کامل کنید.';
            break;
        }
    }
    if (strlen((string) ($_POST['admin_password'] ?? '')) < 10) {
        $errors[] = 'رمز مدیر باید حداقل ۱۰ نویسه باشد.';
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{3,100}$/', (string) ($_POST['admin_user'] ?? ''))) {
        $errors[] = 'نام کاربری مدیر معتبر نیست.';
    }

    $missing = array_values(array_filter(['pdo_mysql', 'openssl', 'curl', 'mbstring'], static fn (string $ext): bool => !extension_loaded($ext)));
    if ($missing) {
        $errors[] = 'افزونه‌های PHP زیر فعال نیستند: ' . implode(', ', $missing);
    }

    if (!$errors) {
        $dbConfig = [
            'host' => trim((string) $_POST['db_host']),
            'port' => max(1, (int) ($_POST['db_port'] ?? 3306)),
            'name' => trim((string) $_POST['db_name']),
            'user' => trim((string) $_POST['db_user']),
            'pass' => (string) ($_POST['db_pass'] ?? ''),
        ];
        $appConfig = [
            'name' => trim((string) $_POST['app_name']),
            'timezone' => trim((string) ($_POST['timezone'] ?? 'Asia/Tehran')),
            'encryption_key' => base64_encode(random_bytes(32)),
            'cron_token' => bin2hex(random_bytes(32)),
            'debug' => false,
        ];

        try {
            if (PHP_VERSION_ID < 80100) {
                throw new RuntimeException('نسخه PHP باید 8.1 یا جدیدتر باشد.');
            }
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbConfig['host'], $dbConfig['port'], $dbConfig['name']);
            $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $schema = (string) file_get_contents($root . '/app/schema.sql');
            $statements = preg_split('/;\s*(?:\r?\n|$)/', trim($schema)) ?: [];
            foreach ($statements as $statement) {
                if (trim($statement) !== '') {
                    $pdo->exec($statement);
                }
            }
            $exists = $pdo->prepare('SELECT id FROM admins WHERE username = ?');
            $exists->execute([trim((string) $_POST['admin_user'])]);
            if (!$exists->fetch()) {
                $insert = $pdo->prepare('INSERT INTO admins (username, password_hash, full_name, role, language, is_active) VALUES (?, ?, ?, ?, ?, 1)');
                $insert->execute([
                    trim((string) $_POST['admin_user']),
                    password_hash((string) $_POST['admin_password'], PASSWORD_DEFAULT),
                    trim((string) ($_POST['admin_name'] ?? '')),
                    'superadmin',
                    'fa',
                ]);
            }

            $finalConfig = ['app' => $appConfig, 'db' => $dbConfig];
            $manualConfig = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($finalConfig, true) . ";\n";
            if (@file_put_contents($root . '/config.php', $manualConfig, LOCK_EX) === false) {
                throw new RuntimeException('دیتابیس ساخته شد، اما هاست اجازه ساخت config.php را نداد. متن تنظیمات پایین صفحه را در این فایل قرار دهید.');
            }
            $success = true;
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

$value = static fn (string $key, string $default = ''): string => htmlspecialchars((string) ($_POST[$key] ?? $default), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>نصب UserManager</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#172033;font-family:Tahoma,"Segoe UI",sans-serif}.wrap{max-width:820px;margin:40px auto;padding:0 18px}.card{background:#fff;border:1px solid #e4e9f2;border-radius:20px;box-shadow:0 18px 45px rgba(31,48,80,.08);overflow:hidden}.head{padding:28px;background:linear-gradient(135deg,#12345b,#1976d2);color:#fff}.body{padding:28px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.wide{grid-column:1/-1}label{display:block;font-weight:700;font-size:13px;margin-bottom:7px}input,select{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:10px;font:inherit;direction:ltr}.btn{border:0;border-radius:11px;background:#1976d2;color:#fff;padding:13px 24px;font-weight:700;cursor:pointer}.alert{padding:14px 16px;border-radius:10px;margin-bottom:18px}.error{background:#fff0f0;color:#a51616}.ok{background:#eafaf1;color:#12613b}code,textarea{direction:ltr}textarea{width:100%;min-height:240px}@media(max-width:640px){.grid{grid-template-columns:1fr}.wide{grid-column:auto}}
    </style>
</head>
<body><div class="wrap"><div class="card"><div class="head"><h1>نصب پنل MikroTik UserManager</h1><p>بدون Composer، SSH یا اجرای دستور</p></div><div class="body">
<?php if ($success): ?>
    <div class="alert ok"><strong>نصب با موفقیت انجام شد.</strong><br>اکنون وارد پنل شوید و برای امنیت، فایل <code>install.php</code> را در File Manager حذف کنید.</div>
    <p><a class="btn" href="index.php">ورود به پنل</a></p>
<?php else: ?>
    <?php foreach ($errors as $error): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
    <?php if ($manualConfig !== '' && !is_file($root . '/config.php')): ?>
        <p>فایل <code>config.php</code> را کنار <code>index.php</code> بسازید و متن زیر را در آن قرار دهید:</p>
        <textarea readonly><?= htmlspecialchars($manualConfig, ENT_QUOTES, 'UTF-8') ?></textarea>
    <?php endif; ?>
    <form method="post" autocomplete="off"><div class="grid">
        <div class="wide"><h3>اطلاعات دیتابیس ساخته‌شده در cPanel</h3></div>
        <div><label>میزبان دیتابیس *</label><input name="db_host" value="<?= $value('db_host', 'localhost') ?>" required></div>
        <div><label>پورت</label><input name="db_port" type="number" value="<?= $value('db_port', '3306') ?>" required></div>
        <div><label>نام دیتابیس *</label><input name="db_name" value="<?= $value('db_name') ?>" required></div>
        <div><label>کاربر دیتابیس *</label><input name="db_user" value="<?= $value('db_user') ?>" required></div>
        <div class="wide"><label>رمز دیتابیس</label><input name="db_pass" type="password" value="<?= $value('db_pass') ?>"></div>
        <div class="wide"><h3>مدیر پنل</h3></div>
        <div><label>نام کاربری مدیر *</label><input name="admin_user" value="<?= $value('admin_user') ?>" required></div>
        <div><label>رمز مدیر (حداقل ۱۰ نویسه) *</label><input name="admin_password" type="password" required minlength="10"></div>
        <div><label>نام کامل</label><input name="admin_name" value="<?= $value('admin_name') ?>"></div>
        <div><label>نام پنل *</label><input name="app_name" value="<?= $value('app_name', 'MikroTik UserManager') ?>" required></div>
        <div class="wide"><label>منطقه زمانی</label><select name="timezone"><option value="Asia/Tehran">Asia/Tehran</option><option value="UTC">UTC</option></select></div>
        <div class="wide"><button class="btn" type="submit">ساخت پنل</button></div>
    </div></form>
<?php endif; ?>
</div></div></div></body></html>

