<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/session.php';

$isHttps = (
    isset($_SERVER['HTTPS']) &&
    $_SERVER['HTTPS'] !== '' &&
    strtolower((string) $_SERVER['HTTPS']) !== 'off'
);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_name('ACADTRACK_SESSION');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('UTC');

/*
|--------------------------------------------------------------------------
| Generic Authentication Failure
|--------------------------------------------------------------------------
*/

function loginFailure(): never
{
    header('Location: ../index.php?error=invalid', true, 303);
    exit;
}

/*
|--------------------------------------------------------------------------
| Validate CSRF Token
|--------------------------------------------------------------------------
*/

$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['login_csrf_token'] ?? '';

if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    $submittedToken === '' ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {
    loginFailure();
}

/*
|--------------------------------------------------------------------------
| Validate Input
|--------------------------------------------------------------------------
*/

$studentNumber = $_POST['student_number'] ?? '';
$password = $_POST['password'] ?? '';

if (
    !is_string($studentNumber) ||
    !preg_match('/^[0-9]{12}$/D', $studentNumber) ||
    !is_string($password) ||
    $password === '' ||
    strlen($password) > 4096
) {
    loginFailure();
}

/*
|--------------------------------------------------------------------------
| Prepare Rate-Limit Identifiers
|--------------------------------------------------------------------------
*/

$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

$identifierHash = hash('sha256', $studentNumber);
$ipHash = hash('sha256', $ipAddress);

/*
|--------------------------------------------------------------------------
| Login Rate Limiting
|--------------------------------------------------------------------------
*/

$now = time();
$windowSeconds = 15 * 60;
$lockSeconds = 15 * 60;
$maximumAttempts = 5;

try {
    /*
     * Lock the account/IP record while checking the current limit.
     * The unique index on (identifier_hash, ip_hash) is required.
     */
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT failed_attempts, first_attempt_at, locked_until
         FROM login_security
         WHERE identifier_hash = :identifier_hash
           AND ip_hash = :ip_hash
         FOR UPDATE'
    );

    $stmt->execute([
        'identifier_hash' => $identifierHash,
        'ip_hash' => $ipHash,
    ]);

    $securityRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($securityRecord) {
        $lockedUntil = $securityRecord['locked_until'] !== null
            ? strtotime($securityRecord['locked_until'] . ' UTC')
            : false;

        if ($lockedUntil !== false && $lockedUntil > $now) {
            $pdo->commit();
            loginFailure();
        }

        $firstAttempt = strtotime(
            $securityRecord['first_attempt_at'] . ' UTC'
        );

        if (
            $firstAttempt === false ||
            ($now - $firstAttempt) >= $windowSeconds ||
            ($lockedUntil !== false && $lockedUntil <= $now)
        ) {
            $stmt = $pdo->prepare(
                'UPDATE login_security
                 SET failed_attempts = 0,
                     first_attempt_at = UTC_TIMESTAMP(),
                     last_attempt_at = UTC_TIMESTAMP(),
                     locked_until = NULL
                 WHERE identifier_hash = :identifier_hash
                   AND ip_hash = :ip_hash'
            );

            $stmt->execute([
                'identifier_hash' => $identifierHash,
                'ip_hash' => $ipHash,
            ]);
        }
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO login_security (
                identifier_hash,
                ip_hash,
                failed_attempts,
                first_attempt_at,
                last_attempt_at,
                locked_until
            ) VALUES (
                :identifier_hash,
                :ip_hash,
                0,
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP(),
                NULL
            )'
        );

        $stmt->execute([
            'identifier_hash' => $identifierHash,
            'ip_hash' => $ipHash,
        ]);
    }

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('AcadTrack rate-limit error: ' . $e->getMessage());

    http_response_code(503);
    exit('Login is temporarily unavailable. Please try again later.');
}

/*
|--------------------------------------------------------------------------
| Fetch User
|--------------------------------------------------------------------------
*/

try {
    $stmt = $pdo->prepare(
        'SELECT
            id,
            nickname,
            student_number,
            email,
            password_hash,
            role,
            is_active
         FROM users
         WHERE student_number = :student_number
         LIMIT 1'
    );

    $stmt->execute([
        'student_number' => $studentNumber,
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    static $dummyHash = null;

    if ($dummyHash === null) {
        $dummyHash = password_hash(
            bin2hex(random_bytes(32)),
            PASSWORD_DEFAULT
        );
    }

    $hashToVerify = (
        $user &&
        isset($user['password_hash']) &&
        is_string($user['password_hash'])
    )
        ? $user['password_hash']
        : $dummyHash;

    $passwordValid = password_verify(
        $password,
        $hashToVerify
    );

    $allowedRoles = ['student', 'faculty', 'admin'];

    $accountValid = (
        $user !== false &&
        $passwordValid &&
        isset($user['is_active'], $user['role']) &&
        (int) $user['is_active'] === 1 &&
        in_array($user['role'], $allowedRoles, true)
    );

    if (!$accountValid) {
        /*
         * Record the failed attempt.
         */
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT failed_attempts, first_attempt_at
             FROM login_security
             WHERE identifier_hash = :identifier_hash
               AND ip_hash = :ip_hash
             FOR UPDATE'
        );

        $stmt->execute([
            'identifier_hash' => $identifierHash,
            'ip_hash' => $ipHash,
        ]);

        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        $firstAttempt = $record
            ? strtotime($record['first_attempt_at'] . ' UTC')
            : false;

        if (
            !$record ||
            $firstAttempt === false ||
            ($now - $firstAttempt) >= $windowSeconds
        ) {
            $attempts = 1;

            $stmt = $pdo->prepare(
                'INSERT INTO login_security (
                    identifier_hash,
                    ip_hash,
                    failed_attempts,
                    first_attempt_at,
                    last_attempt_at,
                    locked_until
                ) VALUES (
                    :identifier_hash,
                    :ip_hash,
                    1,
                    UTC_TIMESTAMP(),
                    UTC_TIMESTAMP(),
                    NULL
                )
                ON DUPLICATE KEY UPDATE
                    failed_attempts = 1,
                    first_attempt_at = UTC_TIMESTAMP(),
                    last_attempt_at = UTC_TIMESTAMP(),
                    locked_until = NULL'
            );

            $stmt->execute([
                'identifier_hash' => $identifierHash,
                'ip_hash' => $ipHash,
            ]);
        } else {
            $attempts = (int) $record['failed_attempts'] + 1;

            $lockedUntil = $attempts >= $maximumAttempts
                ? gmdate('Y-m-d H:i:s', $now + $lockSeconds)
                : null;

            $stmt = $pdo->prepare(
                'UPDATE login_security
                 SET failed_attempts = :attempts,
                     last_attempt_at = UTC_TIMESTAMP(),
                     locked_until = :locked_until
                 WHERE identifier_hash = :identifier_hash
                   AND ip_hash = :ip_hash'
            );

            $stmt->execute([
                'attempts' => $attempts,
                'locked_until' => $lockedUntil,
                'identifier_hash' => $identifierHash,
                'ip_hash' => $ipHash,
            ]);
        }

        $pdo->commit();

        loginFailure();
    }

    if (
        password_needs_rehash(
            $user['password_hash'],
            PASSWORD_DEFAULT
        )
    ) {
        $newHash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        $stmt = $pdo->prepare(
            'UPDATE users
             SET password_hash = :password_hash
             WHERE id = :id'
        );

        $stmt->execute([
            'password_hash' => $newHash,
            'id' => (int) $user['id'],
        ]);
    }

    /*
     * Clear failed attempts after successful authentication.
     */
    $stmt = $pdo->prepare(
        'DELETE FROM login_security
         WHERE identifier_hash = :identifier_hash
           AND ip_hash = :ip_hash'
    );

    $stmt->execute([
        'identifier_hash' => $identifierHash,
        'ip_hash' => $ipHash,
    ]);

    /*
     * Regenerate the session ID before assigning authenticated
     * user information.
     */
    if (!session_regenerate_id(true)) {
        throw new RuntimeException(
            'Unable to regenerate the session ID.'
        );
    }

    unset($_SESSION['login_csrf_token']);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['nickname'] = $user['nickname'];
    $_SESSION['student_number'] = $user['student_number'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];

    $_SESSION['authenticated_at'] = time();
    $_SESSION['last_activity'] = time();

    /*
     * Redirect according to the existing role structure.
     */
    switch ($user['role']) {
        case 'student':
            header('Location: ../views/student/dashboard.php', true, 303);
            break;

        case 'faculty':
            header('Location: ../views/faculty/dashboard.php', true, 303);
            break;

        case 'admin':
            header('Location: ../views/admin/dashboard.php', true, 303);
            break;

        default:
            loginFailure();
    }

    exit;

} catch (Throwable $e) {
    error_log('AcadTrack login error: ' . $e->getMessage());

    http_response_code(500);
    exit('A server error occurred. Please try again later.');
}