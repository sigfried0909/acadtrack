<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';

/*
|--------------------------------------------------------------------------
| ROLE-BASED ACCESS CONTROL
|--------------------------------------------------------------------------
*/

function requireRole(array $allowedRoles): void
{
    $userId = filter_var(
        $_SESSION['user_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $role = $_SESSION['role'] ?? null;

    $authenticated = (
        $userId !== false &&
        is_string($role) &&
        $role !== ''
    );

    $authorized = (
        $authenticated &&
        in_array($role, $allowedRoles, true)
    );

    if ($authorized) {
        return;
    }

    /*
     * Determine AcadTrack's installation directory.
     *
     * Example:
     * /acadtrack/views/student/dashboard.php
     * becomes:
     * /acadtrack
     */

    $scriptPath = parse_url(
        (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
        PHP_URL_PATH
    );

    $scriptPath = is_string($scriptPath)
        ? $scriptPath
        : '';

    $scriptDirectory = str_replace(
        '\\',
        '/',
        dirname($scriptPath)
    );

    $basePath = preg_replace(
        '~/views/(student|faculty|admin)$~i',
        '',
        $scriptDirectory
    );

    if (!is_string($basePath)) {
        $basePath = '';
    }

    if ($basePath === $scriptDirectory) {
        $basePath = dirname(
            dirname(
                dirname($scriptPath)
            )
        );
    }

    $basePath = str_replace('\\', '/', $basePath);

    if ($basePath === '.' || $basePath === '/') {
        $basePath = '';
    } else {
        $basePath = rtrim($basePath, '/');
    }

    header(
        'Location: ' . $basePath . '/index.php?error=unauthorized',
        true,
        303
    );

    exit;
}
