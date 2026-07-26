<?php

declare(strict_types=1);

final class MikrotikException extends RuntimeException
{
}

final class MikrotikClient
{
    private string $baseUrl;

    public function __construct(
        string $host,
        int $port,
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly string $apiKey = '',
        bool $useTls = true,
        private readonly bool $verifyTls = true,
    ) {
        $host = trim($host);
        if ($host === '' || str_contains($host, '/') || str_contains($host, '://')) {
            throw new InvalidArgumentException('Router host must be an IP address or hostname without scheme or path.');
        }
        $scheme = $useTls ? 'https' : 'http';
        $isDefaultPort = ($useTls && $port === 443) || (!$useTls && $port === 80);
        $this->baseUrl = $scheme . '://' . $host . ($isDefaultPort ? '' : ':' . $port);
    }

    private function request(string $method, string $path, ?array $payload = null, array $query = []): mixed
    {
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $lastError = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $handle = curl_init($url);
            $headers = ['Accept: application/json'];
            if ($this->apiKey !== '') {
                $headers[] = 'Authorization: Bearer ' . $this->apiKey;
            }
            if ($payload !== null) {
                $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $headers[] = 'Content-Type: application/json';
                $headers[] = 'Content-Length: ' . strlen($json);
                curl_setopt($handle, CURLOPT_POSTFIELDS, $json);
            }
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
                CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            ]);
            if ($this->apiKey === '') {
                curl_setopt($handle, CURLOPT_USERPWD, $this->username . ':' . $this->password);
                curl_setopt($handle, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            }
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($handle);
            curl_close($handle);

            if ($body !== false && $status > 0) {
                if ($status === 401) {
                    throw new MikrotikException(tr('احراز هویت روتر رد شد.', 'Router authentication was rejected.'));
                }
                if ($status >= 400) {
                    $decoded = json_decode((string) $body, true);
                    $message = is_array($decoded) ? ($decoded['message'] ?? $decoded['error'] ?? ('HTTP ' . $status)) : ('HTTP ' . $status);
                    if (is_array($decoded) && !empty($decoded['detail'])) {
                        $message .= ': ' . $decoded['detail'];
                    }
                    throw new MikrotikException((string) $message, $status);
                }
                if ($body === '' || $status === 204) {
                    return null;
                }
                $decoded = json_decode((string) $body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new MikrotikException(tr('پاسخ روتر JSON معتبر نیست.', 'The router returned invalid JSON.'));
                }
                return $decoded;
            }

            $lastError = $curlError ?: tr('روتر پاسخ نداد.', 'The router did not respond.');
            if ($attempt < 3) {
                usleep($attempt * 250000);
            }
        }
        throw new MikrotikException($lastError);
    }

    public function ping(): bool
    {
        // Let the original exception reach the UI. A generic false result hides
        // whether the problem is routing, firewall, TLS, credentials or policy.
        $this->request('GET', '/rest/system/resource');
        return true;
    }

    private function recordId(string $id): string
    {
        // RouterOS REST record IDs are path tokens such as *A. Encoding the
        // asterisk as %2A makes some RouterOS versions return HTTP 400.
        if (preg_match('/^\*[A-Za-z0-9]+$/', $id)) {
            return $id;
        }
        return rawurlencode($id);
    }

    public function listSecrets(?string $name = null): array
    {
        $result = $this->request('GET', '/rest/user-manager/user', null, $name ? ['name' => $name] : []);
        return is_array($result) ? $result : [];
    }

    public function createSecret(string $name, string $password, string $profile, string $service, string $comment = ''): array
    {
        $payload = ['name' => $name, 'password' => $password];
        if ($comment !== '') {
            $payload['comment'] = $comment;
        }
        $result = $this->request('PUT', '/rest/user-manager/user', $payload);
        $this->assignProfile($name, $profile);
        return is_array($result) ? $result : [];
    }

    public function setSecret(string $id, array $fields): array
    {
        if ($id === '') {
            throw new MikrotikException(tr('شناسه کاربر روی روتر ثبت نشده است؛ ابتدا همگام‌سازی کنید.', 'The router secret ID is missing; synchronize first.'));
        }
        if (isset($fields['profile'])) {
            $users = $this->listSecrets();
            foreach ($users as $user) {
                if (($user['.id'] ?? '') === $id && !empty($user['name'])) {
                    $this->assignProfile((string) $user['name'], (string) $fields['profile']);
                    break;
                }
            }
            unset($fields['profile']);
        }
        // Rate limits belong to User Manager limitations, not directly to users.
        unset($fields['rate-limit']);
        if (!$fields) {
            return [];
        }
        $result = $this->request('PATCH', '/rest/user-manager/user/' . $this->recordId($id), $fields);
        return is_array($result) ? $result : [];
    }

    public function removeSecret(string $id): void
    {
        if ($id === '') {
            throw new MikrotikException(tr('شناسه کاربر روی روتر ثبت نشده است.', 'The router secret ID is missing.'));
        }
        $username = '';
        foreach ($this->listSecrets() as $user) {
            if (($user['.id'] ?? '') === $id) {
                $username = (string) ($user['name'] ?? '');
                break;
            }
        }
        if ($username !== '') {
            foreach ($this->listUserProfiles() as $assignment) {
                if (($assignment['user'] ?? '') === $username && !empty($assignment['.id'])) {
                    try {
                        $this->request('DELETE', '/rest/user-manager/user-profile/' . $this->recordId((string) $assignment['.id']));
                    } catch (MikrotikException) {
                        // Used profile history can be immutable. User deletion below
                        // will either cascade it or return the actual RouterOS error.
                    }
                }
            }
        }
        $this->request('DELETE', '/rest/user-manager/user/' . $this->recordId($id));
    }

    public function activeSessions(): array
    {
        $result = $this->request('GET', '/rest/user-manager/session');
        if (!is_array($result)) {
            return [];
        }
        $sessions = [];
        foreach ($result as $session) {
            if (!in_array($session['active'] ?? false, [true, 'true', 'yes'], true)) {
                continue;
            }
            $sessions[] = [
                '.id' => $session['.id'] ?? ($session['acct-session-id'] ?? ''),
                'name' => $session['user'] ?? '',
                'address' => $session['user-address'] ?? '',
                'uptime' => $session['uptime'] ?? '',
                'bytes-in' => $session['download'] ?? 0,
                'bytes-out' => $session['upload'] ?? 0,
            ];
        }
        return $sessions;
    }

    public function listProfiles(): array
    {
        $result = $this->request('GET', '/rest/user-manager/profile');
        return is_array($result) ? $result : [];
    }

    public function listUserProfiles(): array
    {
        $result = $this->request('GET', '/rest/user-manager/user-profile');
        return is_array($result) ? $result : [];
    }

    public function listLimitations(): array
    {
        $result = $this->request('GET', '/rest/user-manager/limitation');
        return is_array($result) ? $result : [];
    }

    public function listProfileLimitations(): array
    {
        $result = $this->request('GET', '/rest/user-manager/profile-limitation');
        return is_array($result) ? $result : [];
    }

    public function listSessions(): array
    {
        $result = $this->request('GET', '/rest/user-manager/session');
        return is_array($result) ? $result : [];
    }

    public function userManagerSnapshot(): array
    {
        return [
            'profiles' => $this->listProfiles(),
            'limitations' => $this->listLimitations(),
            'profile_limitations' => $this->listProfileLimitations(),
            'users' => $this->listSecrets(),
            'user_profiles' => $this->listUserProfiles(),
            'sessions' => $this->listSessions(),
        ];
    }

    private function findByName(string $path, string $name): ?array
    {
        $items = $this->request('GET', $path, null, ['name' => $name]);
        if (!is_array($items)) {
            return null;
        }
        foreach ($items as $item) {
            if (($item['name'] ?? '') === $name) {
                return $item;
            }
        }
        return null;
    }

    private function assignProfile(string $username, string $profile): void
    {
        $assignments = $this->listUserProfiles();
        foreach ($assignments as $assignment) {
            if (($assignment['user'] ?? '') === $username && ($assignment['profile'] ?? '') === $profile
                && ($assignment['state'] ?? '') !== 'used') {
                return;
            }
        }
        $this->request('PUT', '/rest/user-manager/user-profile', ['user' => $username, 'profile' => $profile]);
    }

    public function saveProfile(string $name, string $rateLimit, int $validityDays = 30, ?int $dataCapGb = null, float $price = 0): array
    {
        $profilePayload = [
            'name' => $name,
            'name-for-users' => $name,
            'validity' => max(1, $validityDays) . 'd',
            'starts-when' => 'assigned',
            'price' => (string) max(0, $price),
        ];
        $profile = $this->findByName('/rest/user-manager/profile', $name);
        $result = $profile && !empty($profile['.id'])
            ? $this->request('PATCH', '/rest/user-manager/profile/' . $this->recordId((string) $profile['.id']), $profilePayload)
            : $this->request('PUT', '/rest/user-manager/profile', $profilePayload);

        $parts = array_map('trim', explode('/', $rateLimit, 2));
        if (strtolower($parts[0]) === 'unlimited') {
            $parts = ['0', '0'];
        }
        $limitationName = $name . '-limit';
        $limitationPayload = [
            'name' => $limitationName,
            'rate-limit-rx' => $parts[0],
            'rate-limit-tx' => $parts[1] ?? $parts[0],
            'transfer-limit' => $dataCapGb ? (string) ($dataCapGb * 1073741824) : '0',
        ];
        $limitation = $this->findByName('/rest/user-manager/limitation', $limitationName);
        if ($limitation && !empty($limitation['.id'])) {
            $this->request('PATCH', '/rest/user-manager/limitation/' . $this->recordId((string) $limitation['.id']), $limitationPayload);
        } else {
            $this->request('PUT', '/rest/user-manager/limitation', $limitationPayload);
        }

        $links = $this->request('GET', '/rest/user-manager/profile-limitation');
        $linked = false;
        if (is_array($links)) {
            foreach ($links as $link) {
                if (($link['profile'] ?? '') === $name && ($link['limitation'] ?? '') === $limitationName) {
                    $linked = true;
                    break;
                }
            }
        }
        if (!$linked) {
            $this->request('PUT', '/rest/user-manager/profile-limitation', [
                'profile' => $name,
                'limitation' => $limitationName,
            ]);
        }
        return is_array($result) ? $result : [];
    }
}
