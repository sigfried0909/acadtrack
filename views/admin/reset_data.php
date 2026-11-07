<?php
declare(strict_types=1);

require_once __DIR__ . '/../../auth/require_role.php';
requireRole(['admin']);

require_once __DIR__ . '/../../config/database.php';

date_default_timezone_set('Asia/Manila');

$adminId = (int) ($_SESSION['user_id'] ?? 0);

$returnPage = $_POST['return_page'] ?? 'dashboard';

if (
    !is_string($returnPage) ||
    !in_array($returnPage, ['dashboard', 'sections'], true)
) {
    $returnPage = 'dashboard';
}

/*
|--------------------------------------------------------------------------
| REDIRECT HELPER
|--------------------------------------------------------------------------
*/

function resetDataRedirect(string $page): void
{
    header(
        'Location: dashboard.php?' .
        http_build_query(['page' => $page])
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| REMOVE DIRECTORY TREE
|--------------------------------------------------------------------------
| Does not follow symbolic links.
*/

function resetDataRemoveTree(string $path): bool
{
    if (is_link($path) || is_file($path)) {
        return @unlink($path);
    }

    if (!is_dir($path)) {
        return !file_exists($path);
    }

    $entries = @scandir($path);

    if ($entries === false) {
        return false;
    }

    foreach ($entries as $entry) {

        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $entryPath = $path . DIRECTORY_SEPARATOR . $entry;

        if (!resetDataRemoveTree($entryPath)) {
            return false;
        }
    }

    return @rmdir($path);
}

/*
|--------------------------------------------------------------------------
| RESTORE PERMIT FILES IF RESET FAILS
|--------------------------------------------------------------------------
*/

function resetDataRestoreFiles(
    ?string $quarantineDirectory,
    array $movedFiles
): void {

    foreach (array_reverse($movedFiles) as $file) {

        $originalPath = (string) ($file['original_path'] ?? '');

        $quarantinedPath = (string) ($file['quarantined_path'] ?? '');

        if ($originalPath === '' || $quarantinedPath === '') {
            continue;
        }

        if (
            !file_exists($quarantinedPath) &&
            !is_link($quarantinedPath)
        ) {
            continue;
        }

        if (
            file_exists($originalPath) ||
            is_link($originalPath)
        ) {

            error_log(
                'AcadTrack reset rollback: original permit path is occupied; '
                . $quarantinedPath
            );

            continue;
        }

        if (!@rename($quarantinedPath, $originalPath)) {

            error_log(
                'AcadTrack reset rollback: unable to restore permit file '
                . $quarantinedPath
            );
        }
    }

    if (
        $quarantineDirectory !== null &&
        is_dir($quarantineDirectory)
    ) {

        if (!@rmdir($quarantineDirectory)) {

            error_log(
                'AcadTrack reset rollback: temporary directory could not be removed: '
                . $quarantineDirectory
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| FAILURE REDIRECT
|--------------------------------------------------------------------------
*/

function resetDataFail(
    string $message,
    string $page
): void {

    $_SESSION['admin_dashboard_error_flash'] = $message;

    resetDataRedirect($page);
}

/*
|--------------------------------------------------------------------------
| REQUEST VALIDATION
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    header('Allow: POST');

    exit('Method not allowed.');
}

if ($adminId < 1) {

    http_response_code(403);

    exit('Access denied.');
}

/*
|--------------------------------------------------------------------------
| VERIFY ACTIVE ADMIN
|--------------------------------------------------------------------------
*/

$adminCheck = $pdo->prepare(
    "SELECT id
     FROM users
     WHERE id = ?
       AND role = 'admin'
       AND is_active = 1
     LIMIT 1"
);

$adminCheck->execute([$adminId]);

if (!$adminCheck->fetchColumn()) {

    http_response_code(403);

    exit('Your administrator account is unavailable or inactive.');
}

/*
|--------------------------------------------------------------------------
| CSRF VALIDATION
|--------------------------------------------------------------------------
*/

$submittedToken = $_POST['csrf_token'] ?? '';

$sessionToken = $_SESSION['csrf_token'] ?? '';

if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {

    resetDataFail(
        'Your session token is invalid. Refresh the page and try again.',
        $returnPage
    );
}

/*
|--------------------------------------------------------------------------
| VALIDATE RESET ACTION
|--------------------------------------------------------------------------
*/

$action = $_POST['action'] ?? '';

if (
    !is_string($action) ||
    !in_array(
        $action,
        [
            'reset_permits',
            'reset_student_academic_status'
        ],
        true
    )
) {

    resetDataFail(
        'Invalid reset action.',
        $returnPage
    );
}

/*
|--------------------------------------------------------------------------
| VERIFY CURRENT ADMINISTRATOR PASSWORD
|--------------------------------------------------------------------------
*/

$currentPassword = $_POST['current_password'] ?? '';

if (
    !is_string($currentPassword) ||
    trim($currentPassword) === ''
) {

    resetDataFail(
        'Please enter your current administrator password.',
        $returnPage
    );
}

$passwordQuery = $pdo->prepare(
    "SELECT password_hash
     FROM users
     WHERE id = ?
       AND role = 'admin'
       AND is_active = 1
     LIMIT 1"
);

$passwordQuery->execute([$adminId]);

$passwordAccount = $passwordQuery->fetch(PDO::FETCH_ASSOC);

if (
    !$passwordAccount ||
    !is_string($passwordAccount['password_hash'] ?? null) ||
    !password_verify(
        $currentPassword,
        $passwordAccount['password_hash']
    )
) {

    resetDataFail(
        'The administrator password is incorrect. No data was changed.',
        $returnPage
    );
}

$quarantineDirectory = null;

$movedFiles = [];

$databaseCommitted = false;

/*
|--------------------------------------------------------------------------
| PROCESS RESET
|--------------------------------------------------------------------------
*/

try {

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | LOCK STUDENT ACCOUNTS
    |--------------------------------------------------------------------------
    | Prevents concurrent student operations from completing their
    | database portion while the reset transaction is running.
    */

    $studentLocks = $pdo->query(
        "SELECT id
         FROM users
         WHERE role = 'student'
         ORDER BY id
         FOR UPDATE"
    );

    $studentLocks->fetchAll(PDO::FETCH_COLUMN);

    /*
    |--------------------------------------------------------------------------
    | OPTION 1: RESET PERMIT
    |--------------------------------------------------------------------------
    */

    if ($action === 'reset_permits') {

        $projectRoot = realpath(dirname(__DIR__, 2));

        if ($projectRoot === false) {

            throw new RuntimeException(
                'Unable to locate the AcadTrack project directory.'
            );
        }

        $storageRoot = $projectRoot
            . DIRECTORY_SEPARATOR . 'storage';

        $permitDirectory = $storageRoot
            . DIRECTORY_SEPARATOR . 'permits';

        /*
        |----------------------------------------------------------------------
        | VERIFY STORAGE DIRECTORY
        |----------------------------------------------------------------------
        */

        if (
            is_link($storageRoot) ||
            (
                file_exists($storageRoot) &&
                !is_dir($storageRoot)
            )
        ) {

            throw new RuntimeException(
                'The storage directory is not configured safely. No permit data was reset.'
            );
        }

        if (is_dir($storageRoot)) {

            $realStorageRoot = realpath($storageRoot);

            $projectPrefix = rtrim(
                $projectRoot,
                DIRECTORY_SEPARATOR
            ) . DIRECTORY_SEPARATOR;

            if (
                $realStorageRoot === false ||
                strpos(
                    rtrim($realStorageRoot, DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR,
                    $projectPrefix
                ) !== 0
            ) {

                throw new RuntimeException(
                    'The storage directory resolves outside the AcadTrack project.'
                );
            }

            if (
                is_link($permitDirectory) ||
                (
                    file_exists($permitDirectory) &&
                    !is_dir($permitDirectory)
                )
            ) {

                throw new RuntimeException(
                    'The permit directory is not configured safely. No permit data was reset.'
                );
            }

            /*
            |------------------------------------------------------------------
            | LOCATE PERMIT FILES
            |------------------------------------------------------------------
            */

            if (is_dir($permitDirectory)) {

                $realPermitDirectory = realpath($permitDirectory);

                $storagePrefix = rtrim(
                    $realStorageRoot,
                    DIRECTORY_SEPARATOR
                ) . DIRECTORY_SEPARATOR;

                if (
                    $realPermitDirectory === false ||
                    strpos(
                        rtrim($realPermitDirectory, DIRECTORY_SEPARATOR)
                        . DIRECTORY_SEPARATOR,
                        $storagePrefix
                    ) !== 0
                ) {

                    throw new RuntimeException(
                        'The permit directory resolves outside the storage directory.'
                    );
                }

                $entries = @scandir($realPermitDirectory);

                if ($entries === false) {

                    throw new RuntimeException(
                        'Unable to read the permit directory. Check its permissions.'
                    );
                }

                $permitFiles = [];

                foreach ($entries as $entry) {

                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }

                    /*
                     * AcadTrack generates 48-character hexadecimal
                     * filenames. The document viewer also accepts
                     * 64-character filenames for compatibility.
                     */

                    if (
                        !preg_match(
                            '/\A(?:[a-f0-9]{48}|[a-f0-9]{64})\.(?:pdf|jpe?g|png)\z/i',
                            $entry
                        )
                    ) {
                        // Preserve .htaccess and other configuration files.
                        continue;
                    }

                    $sourcePath = $realPermitDirectory
                        . DIRECTORY_SEPARATOR
                        . $entry;

                    if (is_link($sourcePath)) {

                        throw new RuntimeException(
                            'A symbolic link was found among permit documents. Reset stopped for safety.'
                        );
                    }

                    if (is_file($sourcePath)) {

                        $permitFiles[] = $sourcePath;
                    }
                }

                /*
                |--------------------------------------------------------------
                | TEMPORARILY MOVE PERMIT FILES
                |--------------------------------------------------------------
                | This allows the original files to be restored if
                | a database operation fails.
                */

                if ($permitFiles !== []) {

                    $quarantineDirectory = $realStorageRoot
                        . DIRECTORY_SEPARATOR
                        . '.permits-reset-'
                        . bin2hex(random_bytes(12));

                    if (
                        file_exists($quarantineDirectory) ||
                        is_link($quarantineDirectory)
                    ) {

                        throw new RuntimeException(
                            'Unable to create a temporary permit cleanup directory.'
                        );
                    }

                    if (!@mkdir($quarantineDirectory, 0700)) {

                        $quarantineDirectory = null;

                        throw new RuntimeException(
                            'Unable to create a temporary permit cleanup directory. Check folder permissions.'
                        );
                    }

                    foreach ($permitFiles as $sourcePath) {

                        $filename = basename($sourcePath);

                        $quarantinedPath = $quarantineDirectory
                            . DIRECTORY_SEPARATOR
                            . $filename;

                        if (!@rename($sourcePath, $quarantinedPath)) {

                            throw new RuntimeException(
                                'Unable to move one or more permit documents for safe removal. Check folder permissions.'
                            );
                        }

                        $movedFiles[] = [

                            'original_path' => $sourcePath,

                            'quarantined_path' => $quarantinedPath,

                        ];
                    }
                }
            }
        }

        /*
        |----------------------------------------------------------------------
        | DELETE PERMIT SUBMISSIONS
        |----------------------------------------------------------------------
        */

        $deletedSubmissions = (int) $pdo->exec(
            "DELETE FROM permit_submissions"
        );

        /*
        |----------------------------------------------------------------------
        | RESET ALL PERMIT PERIOD STATUSES
        |----------------------------------------------------------------------
        */

        $resetStatuses = (int) $pdo->exec(
            "UPDATE student_permit_status
             SET
                status = 'no permit',
                submission_id = NULL"
        );

        /*
        |----------------------------------------------------------------------
        | COMMIT DATABASE CHANGES
        |----------------------------------------------------------------------
        */

        $pdo->commit();

        $databaseCommitted = true;

        /*
        |----------------------------------------------------------------------
        | PERMANENTLY REMOVE TEMPORARY PERMIT FILES
        |----------------------------------------------------------------------
        */

        $filesRemoved = (
            $quarantineDirectory === null ||
            resetDataRemoveTree($quarantineDirectory)
        );

        if ($filesRemoved) {

            $_SESSION['admin_dashboard_flash'] = sprintf(

                'Permit reset completed. Removed %d uploaded permit file(s), deleted %d submission record(s), and reset %d permit status record(s).',

                count($movedFiles),

                $deletedSubmissions,

                $resetStatuses
            );

        } else {

            error_log(
                'AcadTrack reset: unable to completely remove '
                . (string) $quarantineDirectory
            );

            $_SESSION['admin_dashboard_error_flash'] =
                'The permit database records were reset, but some files could not be permanently removed. Check the hidden .permits-reset-* folder inside storage.';
        }

        resetDataRedirect($returnPage);
    }

    /*
    |--------------------------------------------------------------------------
    | OPTION 2: RESET STUDENT ACADEMIC STATUS
    |--------------------------------------------------------------------------
    */

    /*
     * Remove official student section assignments.
     */

    $deletedAssignments = (int) $pdo->exec(
        "DELETE ss
         FROM section_students ss

         INNER JOIN users u
            ON u.id = ss.student_id

         WHERE u.role = 'student'"
    );

    /*
     * Clear ONLY academic placement fields.
     */

    $clearedProfiles = (int) $pdo->exec(
        "UPDATE user_information ui

         INNER JOIN users u
            ON u.id = ui.user_id

         SET
            ui.course = NULL,
            ui.year_level = NULL,
            ui.section = NULL

         WHERE u.role = 'student'"
    );

    /*
    |--------------------------------------------------------------------------
    | COMMIT ACADEMIC RESET
    |--------------------------------------------------------------------------
    */

    $pdo->commit();

    $databaseCommitted = true;

    $_SESSION['admin_dashboard_flash'] = sprintf(

        'Student academic status reset completed. Removed %d section assignment(s) and cleared Course, Year Level, and Assigned Section in %d student profile record(s). Student accounts and personal information were preserved.',

        $deletedAssignments,

        $clearedProfiles
    );

    resetDataRedirect($returnPage);

} catch (Throwable $exception) {

    /*
     * ROLLBACK DATABASE CHANGES.
     */

    if ($pdo->inTransaction()) {

        $pdo->rollBack();
    }

    /*
     * RESTORE MOVED DOCUMENTS IF DATABASE RESET FAILED.
     */

    if (!$databaseCommitted) {

        resetDataRestoreFiles(
            $quarantineDirectory,
            $movedFiles
        );
    }

    error_log(
        'AcadTrack reset data error: '
        . $exception->getMessage()
        . ' | File: ' . $exception->getFile()
        . ' | Line: ' . $exception->getLine()
    );

    $failureMessage = $databaseCommitted

        ? 'The database reset was committed, but file cleanup could not be confirmed. Check the server error log and the storage folder.'

        : 'The reset could not be completed. No database changes were committed. Check the server error log and folder permissions.';

    resetDataFail(
        $failureMessage,
        $returnPage
    );
}
