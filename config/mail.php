
<?php
declare(strict_types=1);

$localMailConfig = __DIR__ . '/mail.local.php';

if (!is_file($localMailConfig)) {
    error_log('AcadTrack: Local mail configuration is missing.');

    throw new RuntimeException(
        'Mail configuration is unavailable.'
    );
}

require_once $localMailConfig;
