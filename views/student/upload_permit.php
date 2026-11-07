<?php
declare(strict_types=1);

require_once __DIR__ . '/../../auth/require_role.php';
requireRole(['student']);

require_once __DIR__ . '/../../config/database.php';

date_default_timezone_set('Asia/Manila');

/*
|--------------------------------------------------------------------------
| REDIRECT HELPER
|--------------------------------------------------------------------------
*/

function redirectToPermit(string $parameter, string $value): void
{
    header(
        'Location: ../../views/student/dashboard.php?page=permit-application&'
        . rawurlencode($parameter)
        . '='
        . rawurlencode($value)
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| REQUEST VALIDATION
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectToPermit('error', 'invalid_request');
}

/*
 * VERIFY CSRF TOKEN.
 */

$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if (
    !is_string($submittedToken)
    || !is_string($sessionToken)
    || $sessionToken === ''
    || !hash_equals($sessionToken, $submittedToken)
) {
    redirectToPermit('error', 'invalid_token');
}

/*
 * VERIFY STUDENT SESSION.
 */

$studentId = (int) ($_SESSION['user_id'] ?? 0);

if ($studentId < 1) {
    http_response_code(403);
    exit('Access denied.');
}

/*
|--------------------------------------------------------------------------
| PAYMENT VALIDATION
|--------------------------------------------------------------------------
*/

$paymentType = $_POST['payment_type'] ?? '';
$period = $_POST['period'] ?? null;

if ($paymentType === 'period') {

    if (!in_array($period, ['P1', 'P2', 'P3'], true)) {
        redirectToPermit('error', 'invalid_period');
    }

} elseif ($paymentType === 'full') {

    $period = null;

} else {

    redirectToPermit('error', 'invalid_request');
}

/*
|--------------------------------------------------------------------------
| VERIFY OFFICIAL STUDENT ENROLLMENT
|--------------------------------------------------------------------------
| Official academic placement is managed through:
|
| section_students.student_id
| section_students.section_id
| sections.id
|
| user_information.section is no longer used.
|
| The student must have a valid official enrollment.
|--------------------------------------------------------------------------
*/

$sectionQuery = $pdo->prepare(
    "SELECT
        s.id,
        s.section_name,
        s.course,
        s.year_level

     FROM section_students ss

     INNER JOIN sections s
        ON s.id = ss.section_id

     INNER JOIN users u
        ON u.id = ss.student_id
        AND u.role = 'student'
        AND u.is_active = 1

     WHERE ss.student_id = ?

     LIMIT 1"
);

$sectionQuery->execute([$studentId]);

$assignedSection = $sectionQuery->fetch(PDO::FETCH_ASSOC);

if (!$assignedSection) {
    redirectToPermit('error', 'no_section');
}

/*
|--------------------------------------------------------------------------
| CHECK DUPLICATE SUBMISSIONS
|--------------------------------------------------------------------------
| A pending or approved Full Payment submission covers P1, P2, and P3.
|--------------------------------------------------------------------------
*/

$duplicateQuery = $pdo->prepare(
    "SELECT id
     FROM permit_submissions
     WHERE student_id = ?
       AND approval_status IN ('pending', 'approved')
       AND (
            payment_type = 'full'
            OR ? = 'full'
            OR (
                ? = 'period'
                AND payment_type = 'period'
                AND period = ?
            )
       )
     LIMIT 1"
);

$duplicateQuery->execute([
    $studentId,
    $paymentType,
    $paymentType,
    $period,
]);

if ($duplicateQuery->fetchColumn()) {
    redirectToPermit('error', 'duplicate_submission');
}

/*
|--------------------------------------------------------------------------
| VALIDATE UPLOADED FILE
|--------------------------------------------------------------------------
*/

if (
    !isset($_FILES['permit_file'])
    || !is_array($_FILES['permit_file'])
) {
    redirectToPermit('error', 'no_file');
}

$file = $_FILES['permit_file'];

$uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

if ($uploadError !== UPLOAD_ERR_OK) {

    if (
        in_array(
            $uploadError,
            [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE],
            true
        )
    ) {
        redirectToPermit('error', 'file_too_large');
    }

    redirectToPermit(
        'error',
        $uploadError === UPLOAD_ERR_NO_FILE
        ? 'no_file'
        : 'upload_failed'
    );
}

/*
|--------------------------------------------------------------------------
| FILE SIZE VALIDATION
|--------------------------------------------------------------------------
*/

$maxFileSize = 5 * 1024 * 1024;

if (
    !isset($file['size'])
    || !is_numeric($file['size'])
    || (int) $file['size'] <= 0
) {
    redirectToPermit('error', 'invalid_file');
}

if ((int) $file['size'] > $maxFileSize) {
    redirectToPermit('error', 'file_too_large');
}

/*
|--------------------------------------------------------------------------
| TEMPORARY FILE VALIDATION
|--------------------------------------------------------------------------
*/

$tmpName = $file['tmp_name'] ?? '';

if (
    !is_string($tmpName)
    || !is_uploaded_file($tmpName)
    || !is_readable($tmpName)
) {
    redirectToPermit('error', 'upload_failed');
}

/*
|--------------------------------------------------------------------------
| VERIFY ACTUAL FILE MIME TYPE
|--------------------------------------------------------------------------
*/

$finfo = new finfo(FILEINFO_MIME_TYPE);

$mimeType = $finfo->file($tmpName);

$allowedTypes = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];

if (!isset($allowedTypes[$mimeType])) {
    redirectToPermit('error', 'invalid_file');
}

$extension = $allowedTypes[$mimeType];

/*
|--------------------------------------------------------------------------
| SANITIZE ORIGINAL FILENAME
|--------------------------------------------------------------------------
*/

$originalFilename = basename(
    str_replace('\\', '/', (string) ($file['name'] ?? ''))
);

$originalFilename = preg_replace(
    '/[\x00-\x1F\x7F]/u',
    '',
    $originalFilename
);

$originalFilename = trim((string) $originalFilename);

if ($originalFilename === '') {
    $originalFilename = 'permit-document.' . $extension;
}

if (strlen($originalFilename) > 255) {
    $originalFilename = substr($originalFilename, 0, 255);
}

/*
|--------------------------------------------------------------------------
| PRIVATE DOCUMENT STORAGE
|--------------------------------------------------------------------------
*/

$uploadDirectory = dirname(__DIR__, 2) . '/storage/permits';

if (
    !is_dir($uploadDirectory)
    && !mkdir($uploadDirectory, 0750, true)
    && !is_dir($uploadDirectory)
) {

    error_log(
        'AcadTrack: Cannot create permit storage directory.'
    );

    redirectToPermit('error', 'server_error');
}

if (!is_writable($uploadDirectory)) {

    error_log(
        'AcadTrack: Permit storage is not writable.'
    );

    redirectToPermit('error', 'server_error');
}

/*
|--------------------------------------------------------------------------
| GENERATE SECURE STORED FILENAME
|--------------------------------------------------------------------------
*/

try {

    $storedFilename = bin2hex(random_bytes(24))
        . '.'
        . $extension;

} catch (Throwable $exception) {

    error_log(
        'AcadTrack: Cannot generate permit filename: '
        . $exception->getMessage()
    );

    redirectToPermit('error', 'server_error');
}

$destination = $uploadDirectory . '/' . $storedFilename;

/*
|--------------------------------------------------------------------------
| MOVE UPLOADED DOCUMENT
|--------------------------------------------------------------------------
*/

if (!move_uploaded_file($tmpName, $destination)) {
    redirectToPermit('error', 'upload_failed');
}

/*
|--------------------------------------------------------------------------
| SAVE PERMIT SUBMISSION
|--------------------------------------------------------------------------
*/

try {

    $pdo->beginTransaction();

    /*
     * VERIFY AND LOCK ACTIVE STUDENT ACCOUNT.
     */

    $studentCheck = $pdo->prepare(
        "SELECT id
         FROM users
         WHERE id = ?
           AND role = 'student'
           AND is_active = 1
         FOR UPDATE"
    );

    $studentCheck->execute([$studentId]);

    if (!$studentCheck->fetchColumn()) {

        throw new DomainException(
            'Your student account is unavailable or inactive.'
        );

    }

    /*
     * RECHECK OFFICIAL ENROLLMENT.
     *
     * Prevent submission if the student has been removed
     * from their section before saving the document.
     */

    $enrollmentCheck = $pdo->prepare(
        "SELECT section_id
         FROM section_students
         WHERE student_id = ?
         LIMIT 1
         FOR UPDATE"
    );

    $enrollmentCheck->execute([$studentId]);

    if ($enrollmentCheck->fetchColumn() === false) {

        throw new DomainException(
            'You no longer have an assigned section.'
        );

    }

    /*
     * RECHECK DUPLICATE SUBMISSIONS INSIDE TRANSACTION.
     */

    $duplicateCheck = $pdo->prepare(
        "SELECT id
         FROM permit_submissions
         WHERE student_id = ?
           AND approval_status IN ('pending', 'approved')
           AND (
                payment_type = 'full'
                OR ? = 'full'
                OR (
                    ? = 'period'
                    AND payment_type = 'period'
                    AND period = ?
                )
           )
         LIMIT 1"
    );

    $duplicateCheck->execute([
        $studentId,
        $paymentType,
        $paymentType,
        $period,
    ]);

    if ($duplicateCheck->fetchColumn()) {

        throw new DomainException(
            'You already have a pending or approved submission for this payment period.'
        );

    }

    /*
     * SAVE SUBMISSION AS PENDING.
     *
     * Permit status is not changed during upload.
     * Approval must come from the assigned SSP Adviser.
     */

    $insert = $pdo->prepare(
        "INSERT INTO permit_submissions (
            student_id,
            payment_type,
            period,
            stored_filename,
            original_filename,
            approval_status
        ) VALUES (?, ?, ?, ?, ?, 'pending')"
    );

    $insert->execute([
        $studentId,
        $paymentType,
        $period,
        $storedFilename,
        $originalFilename,
    ]);

    /*
     * COMMIT TRANSACTION.
     */

    $pdo->commit();

    $_SESSION['flash_success'] =
        'Document uploaded successfully. Awaiting SSP adviser review.';

    header(
        'Location: ../../views/student/dashboard.php?page=permit-application'
    );

    exit;

} catch (DomainException $exception) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    /*
     * REMOVE THE STORED FILE IF DATABASE SAVE FAILED.
     */

    if (is_file($destination)) {
        @unlink($destination);
    }

    $message = $exception->getMessage();

    error_log(
        'AcadTrack permit submission rejected: '
        . $message
    );

    /*
     * RETURN APPROPRIATE ERROR CODE.
     */

    if (strpos($message, 'pending or approved submission') !== false) {
        redirectToPermit('error', 'duplicate_submission');
    }

    if (strpos($message, 'assigned section') !== false) {
        redirectToPermit('error', 'no_section');
    }

    redirectToPermit('error', 'server_error');

} catch (Throwable $exception) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    /*
     * CLEAN UP UPLOADED FILE IF THE TRANSACTION FAILED.
     */

    if (is_file($destination)) {
        @unlink($destination);
    }

    error_log(
        'AcadTrack permit upload error: '
        . $exception->getMessage()
        . ' | File: ' . $exception->getFile()
        . ' | Line: ' . $exception->getLine()
    );

    redirectToPermit('error', 'server_error');
}
