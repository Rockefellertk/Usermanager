<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    $provided = (string) ($_GET['token'] ?? '');
    $expected = (string) config('app', 'cron_token');
    if ($provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

header('Content-Type: application/json; charset=utf-8');
try {
    echo json_encode(['ok' => true, 'time' => date(DATE_ATOM), 'result' => run_maintenance()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

