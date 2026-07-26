<?php

declare(strict_types=1);

final class Auth
{
    private static ?array $user = null;

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = (int) ($_SESSION['admin_id'] ?? 0);
        if ($id < 1) {
            return null;
        }
        self::$user = Database::fetch(
            'SELECT id, username, full_name, role, language FROM admins WHERE id = ? AND is_active = 1',
            [$id]
        );
        if (!self::$user) {
            unset($_SESSION['admin_id']);
        }
        return self::$user;
    }

    public static function attempt(string $username, string $password): bool
    {
        $username = trim($username);
        $ip = client_ip();
        Database::execute('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $attempt = Database::fetch(
            'SELECT COUNT(*) AS failures FROM login_attempts WHERE username = ? AND ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)',
            [$username, $ip]
        );
        if ((int) ($attempt['failures'] ?? 0) >= 5) {
            return false;
        }

        $user = Database::fetch('SELECT * FROM admins WHERE username = ? AND is_active = 1', [$username]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Database::execute(
                'INSERT INTO login_attempts (username, ip_address, attempted_at) VALUES (?, ?, NOW())',
                [$username, $ip]
            );
            return false;
        }

        Database::execute('DELETE FROM login_attempts WHERE username = ? AND ip_address = ?', [$username, $ip]);
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $user['id'];
        $_SESSION['language'] = $user['language'] ?: 'fa';
        self::$user = null;
        log_activity('login');
        return true;
    }

    public static function logout(): void
    {
        if (self::user()) {
            log_activity('logout');
        }
        self::$user = null;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function canWrite(): bool
    {
        $user = self::user();
        return $user && in_array($user['role'], ['superadmin', 'operator', 'billing'], true);
    }

    public static function isSuperadmin(): bool
    {
        return (self::user()['role'] ?? '') === 'superadmin';
    }
}

