<?php

declare(strict_types=1);

function config(string $section, ?string $key = null, mixed $default = null): mixed
{
    global $config;
    if ($key === null) {
        return $config[$section] ?? $default;
    }
    return $config[$section][$key] ?? $default;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tr(string $fa, string $en): string
{
    return ($_SESSION['language'] ?? 'fa') === 'en' ? $en : $fa;
}

function url(string $route = 'dashboard', array $params = []): string
{
    return 'index.php?' . http_build_query(array_merge(['r' => $route], $params));
}

function redirect_to(string $route, array $params = []): never
{
    header('Location: ' . url($route, $params));
    exit;
}

function render(string $view, array $data = []): void
{
    extract($data, EXTR_SKIP);
    ob_start();
    require APP_ROOT . '/views/' . $view . '.php';
    $content = (string) ob_get_clean();
    require APP_ROOT . '/views/layout.php';
}

function flash(string $type, string $message): void
{
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

function pull_flashes(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);
    return $flashes;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $provided = (string) ($_POST['csrf_token'] ?? '');
    if ($provided === '' || !hash_equals(csrf_token(), $provided)) {
        http_response_code(419);
        exit(tr('نشست شما منقضی شده است. صفحه را تازه کنید و دوباره تلاش کنید.', 'Your session expired. Refresh the page and try again.'));
    }
}

function require_login(): void
{
    if (!Auth::user()) {
        redirect_to('login');
    }
}

function require_write(): void
{
    require_login();
    if (!Auth::canWrite()) {
        http_response_code(403);
        exit(tr('شما اجازه انجام این عملیات را ندارید.', 'You do not have permission to perform this action.'));
    }
}

function require_superadmin(): void
{
    require_login();
    if (!Auth::isSuperadmin()) {
        http_response_code(403);
        exit(tr('این بخش فقط برای مدیر اصلی است.', 'This section is restricted to super administrators.'));
    }
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function log_activity(string $action, string $targetType = '', ?int $targetId = null, array $detail = []): void
{
    $adminId = Auth::user()['id'] ?? null;
    Database::execute(
        'INSERT INTO activity_logs (admin_id, action, target_type, target_id, detail, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
        [$adminId, $action, $targetType, $targetId, json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), client_ip()]
    );
}

function secret_key(): string
{
    $decoded = base64_decode((string) config('app', 'encryption_key'), true);
    if ($decoded === false || strlen($decoded) !== 32) {
        throw new RuntimeException('Invalid application encryption key.');
    }
    return $decoded;
}

function encrypt_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Could not encrypt secret.');
    }
    return base64_encode($iv . $tag . $cipher);
}

function decrypt_secret(?string $payload): string
{
    if (!$payload) {
        return '';
    }
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 29) {
        throw new RuntimeException('Invalid encrypted secret.');
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($plain === false) {
        throw new RuntimeException('Could not decrypt secret.');
    }
    return $plain;
}

function money(mixed $amount, string $currency = 'IRR'): string
{
    return number_format((float) $amount, 0) . ' ' . e($currency);
}

function fa_digits(string|int|float $value): string
{
    if (($_SESSION['language'] ?? 'fa') !== 'fa') {
        return (string) $value;
    }
    return strtr((string) $value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
}

function status_text(string $status): string
{
    $map = [
        'active' => ['فعال', 'Active'], 'disabled' => ['غیرفعال', 'Disabled'],
        'expired' => ['منقضی', 'Expired'], 'suspended' => ['تعلیق', 'Suspended'],
        'missing_on_device' => ['روی روتر یافت نشد', 'Missing on router'],
        'needs_plan_assignment' => ['نیازمند پلن', 'Needs plan'],
        'online' => ['آنلاین', 'Online'], 'offline' => ['آفلاین', 'Offline'], 'unknown' => ['نامشخص', 'Unknown'],
        'unpaid' => ['پرداخت‌نشده', 'Unpaid'], 'paid' => ['پرداخت‌شده', 'Paid'],
        'overdue' => ['سررسید گذشته', 'Overdue'], 'cancelled' => ['لغوشده', 'Cancelled'], 'credited' => ['بستانکاری', 'Credited'],
    ];
    return isset($map[$status]) ? tr($map[$status][0], $map[$status][1]) : $status;
}

function badge_class(string $status): string
{
    return match ($status) {
        'active', 'online', 'paid' => 'success',
        'expired', 'offline', 'overdue' => 'danger',
        'unpaid', 'needs_plan_assignment' => 'warning',
        default => 'muted',
    };
}

function page_number(): int
{
    return max(1, (int) ($_GET['page'] ?? 1));
}

function clean_username(string $username): bool
{
    return preg_match('/^[A-Za-z0-9_-]{1,100}$/', $username) === 1;
}

