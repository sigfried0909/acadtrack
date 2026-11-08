<?php
declare(strict_types=1);

$localDatabaseConfig = __DIR__ . '/database.local.php';

if (!is_file($localDatabaseConfig)) {
    error_log('AcadTrack: Local database configuration is missing.');

    http_response_code(500);
    exit('Application configuration is unavailable.');
}

require_once $localDatabaseConfig;
