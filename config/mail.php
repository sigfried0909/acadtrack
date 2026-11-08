<?php
declare(strict_types=1);

$localMailConfig = __DIR__ . '/mail.local.php';

if (!is_file($localMailConfig)) {
    error_log('AcadTrack: Local mail configuration is missing.');

    throw new RuntimeException(
        'Mail configuration is unavailable.'
    );
}

$mailConfig = require $localMailConfig;

if (!is_array($mailConfig)) {
    error_log('AcadTrack: Mail configuration must return an array.');

    throw new RuntimeException(
        'Invalid mail configuration.'
    );
}

$requiredKeys = [
    'host',
    'username',
    'password',
    'port',
    'from_email',
    'from_name'
];

foreach ($requiredKeys as $key) {
    if (!array_key_exists($key, $mailConfig)) {
        error_log('AcadTrack: Missing mail configuration key: ' . $key);

        throw new RuntimeException(
            'Invalid mail configuration.'
        );
    }
}

return $mailConfig;
