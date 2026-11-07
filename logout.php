<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/session.php';

/*
|--------------------------------------------------------------------------
| CLEAR SESSION DATA
|--------------------------------------------------------------------------
*/

$_SESSION = [];

/*
|--------------------------------------------------------------------------
| REMOVE SESSION COOKIE
|--------------------------------------------------------------------------
*/

if (ini_get('session.use_cookies')) {

    $params = session_get_cookie_params();

    $cookieOptions = [
        'expires' => time() - 42000,
        'path' => $params['path'] !== ''
            ? $params['path']
            : '/',
        'secure' => (bool) $params['secure'],
        'httponly' => (bool) $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ];

    if (!empty($params['domain'])) {
        $cookieOptions['domain'] = $params['domain'];
    }

    setcookie(
        session_name(),
        '',
        $cookieOptions
    );
}

/*
|--------------------------------------------------------------------------
| DESTROY SESSION
|--------------------------------------------------------------------------
*/

session_destroy();

/*
|--------------------------------------------------------------------------
| DETERMINE APPLICATION DIRECTORY
|--------------------------------------------------------------------------
*/

$scriptPath = parse_url(
    (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
    PHP_URL_PATH
);

$scriptPath = is_string($scriptPath)
    ? $scriptPath
    : '/logout.php';

$basePath = str_replace(
    '\\',
    '/',
    dirname($scriptPath)
);

if ($basePath === '.' || $basePath === '/') {
    $basePath = '';
} else {
    $basePath = rtrim($basePath, '/');
}

/*
|--------------------------------------------------------------------------
| REDIRECT TO LOGIN
|--------------------------------------------------------------------------
*/

header(
    'Location: ' . $basePath . '/index.php?logged_out=1',
    true,
    303
);

exit;
