<?php

declare(strict_types=1);

function router_by_id(int $id): array
{
    $router = Database::fetch('SELECT * FROM routers WHERE id = ?', [$id]);
    if (!$router) {
        throw new RuntimeException(tr('روتر پیدا نشد.', 'Router not found.'));
    }
    return $router;
}

function router_client(array $router): MikrotikClient
{
    return new MikrotikClient(
        (string) $router['host'],
        (int) $router['port'],
        (string) $router['username'],
        decrypt_secret($router['password_encrypted'] ?? ''),
        decrypt_secret($router['api_key_encrypted'] ?? ''),
        (bool) $router['use_tls'],
        (bool) $router['verify_tls'],
    );
}

function next_invoice_number(): string
{
    $period = date('Ym');
    $statement = Database::pdo()->prepare(
        'INSERT INTO invoice_counters (year_month, last_value) VALUES (?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value + 1)'
    );
    $statement->execute([$period]);
    $number = (int) Database::pdo()->lastInsertId();
    return sprintf('INV-%s-%04d', $period, max(1, $number));
}

function create_invoice(int $userId, ?int $planId, float $amount, ?int $adminId): array
{
    $number = next_invoice_number();
    Database::execute(
        'INSERT INTO invoices (invoice_number, local_user_id, plan_id, amount, total, status, issue_date, due_date, created_by, created_at) VALUES (?, ?, ?, ?, ?, "unpaid", CURDATE(), DATE_ADD(CURDATE(), INTERVAL 3 DAY), ?, NOW())',
        [$number, $userId, $planId, $amount, $amount, $adminId]
    );
    $id = Database::id();
    log_activity('invoice_create', 'invoice', $id, ['amount' => $amount, 'number' => $number]);
    return ['id' => $id, 'invoice_number' => $number];
}

function create_ppp_user(array $input): array
{
    $router = router_by_id((int) $input['router_id']);
    $plan = Database::fetch('SELECT * FROM plans WHERE id = ? AND is_active = 1', [(int) $input['plan_id']]);
    if (!$plan) {
        throw new RuntimeException(tr('پلن معتبر نیست.', 'Invalid plan.'));
    }
    $pdo = Database::pdo();
    $remoteId = '';
    $client = router_client($router);
    $pdo->beginTransaction();
    try {
        Database::execute(
            'INSERT INTO ppp_users (router_id, username, password_encrypted, service, plan_id, profile, rate_limit, status, expiration_date, full_name, phone, address, comment, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, "active", DATE_ADD(CURDATE(), INTERVAL ? DAY), ?, ?, ?, ?, NOW(), NOW())',
            [
                $router['id'], $input['username'], encrypt_secret($input['password']), $input['service'], $plan['id'],
                $plan['mikrotik_profile'], $plan['rate_limit'], $plan['validity_days'], $input['full_name'], $input['phone'],
                $input['address'] ?? '', $input['comment'] ?? '',
            ]
        );
        $userId = Database::id();
        $remote = $client->createSecret($input['username'], $input['password'], $plan['mikrotik_profile'], $input['service'], 'panel:' . $userId);
        $remoteId = (string) ($remote['.id'] ?? $remote['ret'] ?? '');
        if ($remoteId === '') {
            $matches = $client->listSecrets($input['username']);
            foreach ($matches as $match) {
                if (($match['name'] ?? '') === $input['username']) {
                    $remoteId = (string) ($match['.id'] ?? '');
                    break;
                }
            }
        }
        Database::execute('UPDATE ppp_users SET mikrotik_secret_id = ?, last_synced_at = NOW() WHERE id = ?', [$remoteId, $userId]);
        $invoice = create_invoice($userId, (int) $plan['id'], (float) $plan['price'], (int) Auth::user()['id']);
        log_activity('user_create', 'ppp_user', $userId, ['username' => $input['username'], 'plan' => $plan['name']]);
        $pdo->commit();
        return ['user_id' => $userId, 'invoice' => $invoice];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($remoteId !== '') {
            try {
                $client->removeSecret($remoteId);
            } catch (Throwable) {
            }
        }
        throw $exception;
    }
}

function update_ppp_user(int $id, array $input): void
{
    $user = Database::fetch('SELECT * FROM ppp_users WHERE id = ?', [$id]);
    if (!$user) {
        throw new RuntimeException(tr('کاربر پیدا نشد.', 'User not found.'));
    }
    $plan = Database::fetch('SELECT * FROM plans WHERE id = ?', [(int) $input['plan_id']]);
    if (!$plan) {
        throw new RuntimeException(tr('پلن معتبر نیست.', 'Invalid plan.'));
    }
    $router = router_by_id((int) $user['router_id']);
    $remoteFields = [];
    if (($input['password'] ?? '') !== '') {
        $remoteFields['password'] = $input['password'];
    }
    if ((int) $user['plan_id'] !== (int) $plan['id']) {
        $remoteFields['profile'] = $plan['mikrotik_profile'];
    }
    $rateLimit = trim((string) ($input['rate_limit'] ?? '')) ?: (string) $plan['rate_limit'];
    if ($rateLimit !== (string) $user['rate_limit']) {
        $remoteFields['rate-limit'] = $rateLimit;
    }
    if ($remoteFields) {
        router_client($router)->setSecret((string) $user['mikrotik_secret_id'], $remoteFields);
    }
    $passwordEncrypted = ($input['password'] ?? '') !== '' ? encrypt_secret($input['password']) : $user['password_encrypted'];
    Database::execute(
        'UPDATE ppp_users SET password_encrypted = ?, plan_id = ?, profile = ?, rate_limit = ?, full_name = ?, phone = ?, address = ?, comment = ?, last_synced_at = NOW() WHERE id = ?',
        [$passwordEncrypted, $plan['id'], $plan['mikrotik_profile'], $rateLimit, $input['full_name'], $input['phone'], $input['address'] ?? '', $input['comment'] ?? '', $id]
    );
    log_activity('user_update', 'ppp_user', $id, ['plan' => $plan['name'], 'rate_limit' => $rateLimit]);
}

function toggle_ppp_user(int $id): string
{
    $user = Database::fetch('SELECT * FROM ppp_users WHERE id = ?', [$id]);
    if (!$user) {
        throw new RuntimeException(tr('کاربر پیدا نشد.', 'User not found.'));
    }
    $enable = $user['status'] !== 'active';
    router_client(router_by_id((int) $user['router_id']))->setSecret((string) $user['mikrotik_secret_id'], ['disabled' => $enable ? 'no' : 'yes']);
    $status = $enable ? 'active' : 'disabled';
    Database::execute('UPDATE ppp_users SET status = ?, last_synced_at = NOW() WHERE id = ?', [$status, $id]);
    log_activity($enable ? 'user_enable' : 'user_disable', 'ppp_user', $id);
    return $status;
}

function renew_ppp_user(int $id): array
{
    $user = Database::fetch('SELECT u.*, p.price, p.validity_days FROM ppp_users u LEFT JOIN plans p ON p.id = u.plan_id WHERE u.id = ?', [$id]);
    if (!$user || !$user['plan_id']) {
        throw new RuntimeException(tr('برای تمدید، ابتدا یک پلن به کاربر اختصاص دهید.', 'Assign a plan before renewing the user.'));
    }
    if ($user['status'] !== 'active') {
        router_client(router_by_id((int) $user['router_id']))->setSecret((string) $user['mikrotik_secret_id'], ['disabled' => 'no']);
    }
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        Database::execute(
            'UPDATE ppp_users SET expiration_date = DATE_ADD(GREATEST(COALESCE(expiration_date, CURDATE()), CURDATE()), INTERVAL ? DAY), status = "active" WHERE id = ?',
            [(int) $user['validity_days'], $id]
        );
        $invoice = create_invoice($id, (int) $user['plan_id'], (float) $user['price'], (int) Auth::user()['id']);
        log_activity('user_renew', 'ppp_user', $id, ['days' => (int) $user['validity_days']]);
        $pdo->commit();
        return $invoice;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function delete_ppp_user(int $id): void
{
    $user = Database::fetch('SELECT * FROM ppp_users WHERE id = ?', [$id]);
    if (!$user) {
        return;
    }
    router_client(router_by_id((int) $user['router_id']))->removeSecret((string) $user['mikrotik_secret_id']);
    Database::execute('DELETE FROM ppp_users WHERE id = ?', [$id]);
    log_activity('user_delete', 'ppp_user', $id, ['username' => $user['username']]);
}

function sync_router(int $routerId): array
{
    $router = router_by_id($routerId);
    try {
        $remote = router_client($router)->listSecrets();
    } catch (Throwable $exception) {
        Database::execute('UPDATE routers SET last_status = "offline" WHERE id = ?', [$routerId]);
        throw $exception;
    }
    $remoteByName = [];
    foreach ($remote as $item) {
        if (!empty($item['name'])) {
            $remoteByName[(string) $item['name']] = $item;
        }
    }
    $locals = Database::fetchAll('SELECT * FROM ppp_users WHERE router_id = ?', [$routerId]);
    $localByName = [];
    foreach ($locals as $local) {
        $localByName[$local['username']] = $local;
    }
    $created = $updated = $missing = 0;
    foreach ($remoteByName as $name => $item) {
        if (!isset($localByName[$name])) {
            $service = in_array(($item['service'] ?? ''), ['pppoe', 'pptp', 'l2tp', 'sstp', 'any'], true) ? $item['service'] : 'pppoe';
            Database::execute(
                'INSERT INTO ppp_users (router_id, mikrotik_secret_id, username, service, profile, status, comment, last_synced_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, "needs_plan_assignment", ?, NOW(), NOW(), NOW())',
                [$routerId, $item['.id'] ?? '', $name, $service, $item['profile'] ?? '', $item['comment'] ?? '']
            );
            $created++;
            continue;
        }
        $local = $localByName[$name];
        $remoteDisabled = in_array($item['disabled'] ?? false, [true, 'true', 'yes'], true);
        $status = $local['status'];
        if (in_array($status, ['active', 'disabled'], true)) {
            $status = $remoteDisabled ? 'disabled' : 'active';
        }
        if ($status !== $local['status'] || ($item['profile'] ?? '') !== $local['profile'] || ($item['.id'] ?? '') !== $local['mikrotik_secret_id']) {
            Database::execute('UPDATE ppp_users SET status = ?, profile = ?, mikrotik_secret_id = ?, last_synced_at = NOW() WHERE id = ?', [$status, $item['profile'] ?? $local['profile'], $item['.id'] ?? $local['mikrotik_secret_id'], $local['id']]);
            $updated++;
        }
    }
    $missingNames = array_diff(array_keys($localByName), array_keys($remoteByName));
    if ($locals && count($missingNames) / count($locals) > 0.30) {
        log_activity('sync_paused_suspected_restore', 'router', $routerId, ['missing' => count($missingNames), 'total' => count($locals)]);
        Database::execute('UPDATE routers SET last_status = "online", last_sync_at = NOW() WHERE id = ?', [$routerId]);
        return ['created' => $created, 'updated' => $updated, 'paused' => true, 'missing' => count($missingNames)];
    }
    foreach ($missingNames as $name) {
        Database::execute('UPDATE ppp_users SET status = "missing_on_device" WHERE router_id = ? AND username = ?', [$routerId, $name]);
        $missing++;
    }
    Database::execute('UPDATE routers SET last_status = "online", last_sync_at = NOW() WHERE id = ?', [$routerId]);
    log_activity('router_sync', 'router', $routerId, compact('created', 'updated', 'missing'));
    return compact('created', 'updated', 'missing');
}

function parse_uptime(string $value): int
{
    if ($value === '') {
        return 0;
    }
    if (preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $value, $match)) {
        return (((int) ($match[1] ?? 0) * 7 + (int) ($match[2] ?? 0)) * 24 + (int) ($match[3] ?? 0)) * 3600
            + (int) ($match[4] ?? 0) * 60 + (int) ($match[5] ?? 0);
    }
    if (preg_match('/^(?:(\d+)d)?(\d+):(\d+):(\d+)$/', $value, $match)) {
        return ((int) ($match[1] ?? 0) * 24 + (int) $match[2]) * 3600 + (int) $match[3] * 60 + (int) $match[4];
    }
    return 0;
}

function poll_router(int $routerId): array
{
    $router = router_by_id($routerId);
    $startedAt = date('Y-m-d H:i:s');
    try {
        $sessions = router_client($router)->activeSessions();
    } catch (Throwable $exception) {
        Database::execute('UPDATE routers SET last_status = "offline" WHERE id = ?', [$routerId]);
        throw $exception;
    }
    foreach ($sessions as $session) {
        $username = (string) ($session['name'] ?? '');
        if ($username === '') {
            continue;
        }
        $sessionKey = (string) ($session['.id'] ?? $username);
        $currentIn = max(0, (int) ($session['bytes-in'] ?? 0));
        $currentOut = max(0, (int) ($session['bytes-out'] ?? 0));
        $previous = Database::fetch('SELECT bytes_in, bytes_out FROM active_sessions WHERE router_id = ? AND session_key = ?', [$routerId, $sessionKey]);
        $deltaIn = $previous ? ($currentIn >= (int) $previous['bytes_in'] ? $currentIn - (int) $previous['bytes_in'] : $currentIn) : $currentIn;
        $deltaOut = $previous ? ($currentOut >= (int) $previous['bytes_out'] ? $currentOut - (int) $previous['bytes_out'] : $currentOut) : $currentOut;
        Database::execute(
            'INSERT INTO active_sessions (router_id, session_key, username, address, uptime, bytes_in, bytes_out, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE username = VALUES(username), address = VALUES(address), uptime = VALUES(uptime), bytes_in = VALUES(bytes_in), bytes_out = VALUES(bytes_out), last_seen_at = VALUES(last_seen_at)',
            [$routerId, $sessionKey, $username, $session['address'] ?? '', $session['uptime'] ?? '', $currentIn, $currentOut, $startedAt]
        );
        $user = Database::fetch('SELECT id FROM ppp_users WHERE router_id = ? AND username = ?', [$routerId, $username]);
        if ($user) {
            Database::execute(
                'INSERT INTO traffic_logs (local_user_id, log_date, bytes_in, bytes_out, session_count, uptime_seconds) VALUES (?, CURDATE(), ?, ?, ?, ?) ON DUPLICATE KEY UPDATE bytes_in = bytes_in + VALUES(bytes_in), bytes_out = bytes_out + VALUES(bytes_out), session_count = session_count + VALUES(session_count), uptime_seconds = GREATEST(uptime_seconds, VALUES(uptime_seconds))',
                [$user['id'], $deltaIn, $deltaOut, $previous ? 0 : 1, parse_uptime((string) ($session['uptime'] ?? ''))]
            );
        }
    }
    Database::execute('DELETE FROM active_sessions WHERE router_id = ? AND last_seen_at < ?', [$routerId, $startedAt]);
    Database::execute('UPDATE routers SET last_status = "online", last_poll_at = NOW() WHERE id = ?', [$routerId]);
    return ['sessions' => count($sessions)];
}

function expire_sweep(): int
{
    $users = Database::fetchAll('SELECT * FROM ppp_users WHERE status = "active" AND expiration_date < CURDATE()');
    $count = 0;
    foreach ($users as $user) {
        try {
            router_client(router_by_id((int) $user['router_id']))->setSecret((string) $user['mikrotik_secret_id'], ['disabled' => 'yes']);
            Database::execute('UPDATE ppp_users SET status = "expired" WHERE id = ?', [$user['id']]);
            log_activity('user_auto_expire', 'ppp_user', (int) $user['id']);
            $count++;
        } catch (Throwable $exception) {
            error_log('[UserManager] Expire failed for user ' . $user['id'] . ': ' . $exception->getMessage());
        }
    }
    return $count;
}

function overdue_sweep(): int
{
    return Database::execute('UPDATE invoices SET status = "overdue" WHERE status = "unpaid" AND due_date < CURDATE()');
}

function run_maintenance(): array
{
    $result = ['expired' => expire_sweep(), 'overdue' => overdue_sweep(), 'routers' => []];
    $routers = Database::fetchAll('SELECT id, last_sync_at FROM routers WHERE is_active = 1');
    foreach ($routers as $router) {
        $item = ['id' => (int) $router['id']];
        try {
            $item['poll'] = poll_router((int) $router['id']);
            $lastSync = $router['last_sync_at'] ? strtotime((string) $router['last_sync_at']) : 0;
            if ($lastSync < time() - 900) {
                $item['sync'] = sync_router((int) $router['id']);
            }
        } catch (Throwable $exception) {
            $item['error'] = $exception->getMessage();
        }
        $result['routers'][] = $item;
    }
    Database::execute('DELETE FROM active_sessions WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE)');
    return $result;
}

