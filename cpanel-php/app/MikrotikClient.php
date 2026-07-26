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
        $this->baseUrl = ($useTls ? 'https' : 'http') . '://' . $host . ':' . $port;
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
        try {
            $this->request('GET', '/rest/system/resource');
            return true;
        } catch (MikrotikException) {
            return false;
        }
    }

    public function listSecrets(?string $name = null): array
    {
        $result = $this->request('GET', '/rest/ppp/secret', null, $name ? ['name' => $name] : []);
        return is_array($result) ? $result : [];
    }

    public function createSecret(string $name, string $password, string $profile, string $service, string $comment = ''): array
    {
        $payload = ['name' => $name, 'password' => $password, 'profile' => $profile, 'service' => $service];
        if ($comment !== '') {
            $payload['comment'] = $comment;
        }
        $result = $this->request('PUT', '/rest/ppp/secret', $payload);
        return is_array($result) ? $result : [];
    }

    public function setSecret(string $id, array $fields): array
    {
        if ($id === '') {
            throw new MikrotikException(tr('شناسه کاربر روی روتر ثبت نشده است؛ ابتدا همگام‌سازی کنید.', 'The router secret ID is missing; synchronize first.'));
        }
        $result = $this->request('PATCH', '/rest/ppp/secret/' . rawurlencode($id), $fields);
        return is_array($result) ? $result : [];
    }

    public function removeSecret(string $id): void
    {
        if ($id === '') {
            throw new MikrotikException(tr('شناسه کاربر روی روتر ثبت نشده است.', 'The router secret ID is missing.'));
        }
        $this->request('DELETE', '/rest/ppp/secret/' . rawurlencode($id));
    }

    public function activeSessions(): array
    {
        $result = $this->request('GET', '/rest/ppp/active');
        return is_array($result) ? $result : [];
    }

    public function listProfiles(): array
    {
        $result = $this->request('GET', '/rest/ppp/profile');
        return is_array($result) ? $result : [];
    }
}

