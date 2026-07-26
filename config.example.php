<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'MikroTik UserManager',
        'timezone' => 'Asia/Tehran',
        'encryption_key' => 'INSTALLER_GENERATES_THIS_VALUE',
        'cron_token' => 'INSTALLER_GENERATES_THIS_VALUE',
        'debug' => false,
    ],
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'cpanel_username_usermanager',
        'user' => 'cpanel_username_dbuser',
        'pass' => 'change-me',
    ],
];

