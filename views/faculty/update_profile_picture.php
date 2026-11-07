<?php
declare(strict_types=1);

require_once __DIR__ . '/../../auth/require_role.php';
requireRole(['faculty']);

require_once __DIR__ . '/../../config/database.php';

date_default_timezone_set('Asia/Manila');

$facultyId = (int) ($_SESSION['user_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| REDIRECT WITH MESSAGE
|--------------------------------------------------------------------------
*/

function facultyProfilePictureRedirect(string $message, bool $success = false): void
{
    if ($success) {
        $_SESSION['faculty_profile_notice'] = $message;
    } else {
        $_SESSION['faculty_profile_picture_error'] = $message;
    }

    $page = $_POST['return_page'] ?? 'dashboard';

    if (
        !is_string($page)
        || !in_array($page, ['dashboard', 'sections'], true)
    ) {
        $page = 'dashboard';
    }

    $status = $_POST['return_status'] ?? 'all';

    if (
        !is_string($status)
        || !in_array($status, ['pending', 'approved', 'rejected', 'all'], true)
    ) {
        $status = 'all';
    }

    $payment = $_POST['return_payment'] ?? 'all';

    if (
        !is_string($payment)
        || !in_array($payment, ['all', 'P1', 'P2', 'P3', 'full'], true)
    ) {
        $payment = 'all';
    }

    $sectionRaw = filter_var(
        $_POST['return_section_id'] ?? 0,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );

    $sectionId = $sectionRaw === false
        ? 0
        : (int) $sectionRaw;

    $search = $_POST['return_q'] ?? '';

    $search = is_string($search)
        ? trim(substr($search, 0, 200))
        : '';

    $parameters = [
        'page' => $page,
        'status' => $status,
        'payment' => $payment,
        'open_profile' => '1',
    ];

    if ($sectionId > 0) {
        $parameters['section_id'] = $sectionId;
    }

    if ($search !== '') {
        $parameters['q'] = $search;
    }

    $fragment = (
        $page === 'sections'
        && $sectionId > 0
    )
        ? '#reviews'
        : '';

    header(
        'Location: dashboard.php?'
        . http_build_query($parameters)
        . $fragment
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| REQUEST VALIDATION
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

if ($facultyId < 1) {
    http_response_code(403);
    exit('Access denied.');
}

/*
|--------------------------------------------------------------------------
| CSRF VALIDATION
|--------------------------------------------------------------------------
*/

$csrfToken = $_SESSION['csrf_token'] ?? '';
$postedToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($csrfToken)
    || !is_string($postedToken)
    || $csrfToken === ''
    || !hash_equals($csrfToken, $postedToken)
) {
    facultyProfilePictureRedirect(
        'Your session token is invalid. Refresh the page and try again.'
    );
}

/*
|--------------------------------------------------------------------------
| VERIFY FACULTY ACCOUNT
|--------------------------------------------------------------------------
*/

try {

    $accountQuery = $pdo->prepare(
        "SELECT profile_picture
         FROM users
         WHERE id = ?
           AND role = 'faculty'
           AND is_active = 1
         LIMIT 1"
    );

    $accountQuery->execute([$facultyId]);

    $account = $accountQuery->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        http_response_code(403);
        exit('Your faculty account is unavailable or inactive.');
    }

} catch (Throwable $exception) {

    error_log(
        'AcadTrack faculty profile picture account lookup error: '
        . $exception->getMessage()
    );

    facultyProfilePictureRedirect(
        'Unable to verify your faculty account. Please try again.'
    );
}

/*
|--------------------------------------------------------------------------
| FILE VALIDATION
|--------------------------------------------------------------------------
*/

$file = $_FILES['profile_picture'] ?? null;

if (!is_array($file)) {
    facultyProfilePictureRedirect(
        'Please select a profile picture.'
    );
}

$uploadError = (int) (
    $file['error'] ?? UPLOAD_ERR_NO_FILE
);

if ($uploadError !== UPLOAD_ERR_OK) {

    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE =>
            'The image exceeds the server upload limit.',

        UPLOAD_ERR_FORM_SIZE =>
            'The image exceeds the allowed upload size.',

        UPLOAD_ERR_PARTIAL =>
            'The image upload was incomplete.',

        UPLOAD_ERR_NO_FILE =>
            'Please select a profile picture.',

        UPLOAD_ERR_NO_TMP_DIR =>
            'The server upload directory is unavailable.',

        UPLOAD_ERR_CANT_WRITE =>
            'Unable to save the uploaded image.',

        UPLOAD_ERR_EXTENSION =>
            'The server stopped the image upload.',
    ];

    facultyProfilePictureRedirect(
        $uploadErrors[$uploadError]
        ?? 'Unable to upload the selected image.'
    );
}

$tmpPath = $file['tmp_name'] ?? '';
$fileSize = (int) ($file['size'] ?? 0);

if (
    !is_string($tmpPath)
    || !is_uploaded_file($tmpPath)
    || $fileSize <= 0
) {
    facultyProfilePictureRedirect(
        'Invalid image upload.'
    );
}

/*
|--------------------------------------------------------------------------
| MAXIMUM FILE SIZE: 2 MB
|--------------------------------------------------------------------------
*/

$maximumFileSize = 2 * 1024 * 1024;

if ($fileSize > $maximumFileSize) {
    facultyProfilePictureRedirect(
        'Your profile picture must not exceed 2 MB.'
    );
}

/*
|--------------------------------------------------------------------------
| VERIFY IMAGE MIME TYPE
|--------------------------------------------------------------------------
*/

if (!class_exists('finfo')) {
    facultyProfilePictureRedirect(
        'The server image validation service is unavailable.'
    );
}

$mimeType = false;

try {

    $finfo = new finfo(FILEINFO_MIME_TYPE);

    $mimeType = $finfo->file($tmpPath);

} catch (Throwable $exception) {

    error_log(
        'AcadTrack faculty image MIME validation error: '
        . $exception->getMessage()
    );

    facultyProfilePictureRedirect(
        'Unable to validate the uploaded image.'
    );
}

$allowedMimeTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

if (
    !is_string($mimeType)
    || !isset($allowedMimeTypes[$mimeType])
) {
    facultyProfilePictureRedirect(
        'Invalid image format. Upload JPG, PNG, or WEBP only.'
    );
}

/*
|--------------------------------------------------------------------------
| VERIFY IMAGE CONTENT AND DIMENSIONS
|--------------------------------------------------------------------------
*/

$imageInformation = @getimagesize($tmpPath);

if (
    $imageInformation === false
    || ($imageInformation['mime'] ?? '') !== $mimeType
) {
    facultyProfilePictureRedirect(
        'The selected file is not a valid image.'
    );
}

$imageWidth = (int) $imageInformation[0];
$imageHeight = (int) $imageInformation[1];

if (
    $imageWidth < 1
    || $imageHeight < 1
    || $imageWidth > 6000
    || $imageHeight > 6000
    || ($imageWidth * $imageHeight) > 25000000
) {
    facultyProfilePictureRedirect(
        'The image dimensions are too large. Please choose a smaller image.'
    );
}

/*
|--------------------------------------------------------------------------
| PROFILE PICTURE DIRECTORY
|--------------------------------------------------------------------------
*/

$uploadDirectory = dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'uploads'
    . DIRECTORY_SEPARATOR
    . 'profile_pictures';

if (
    !is_dir($uploadDirectory)
    && !mkdir($uploadDirectory, 0755, true)
    && !is_dir($uploadDirectory)
) {
    facultyProfilePictureRedirect(
        'Unable to create the profile picture upload directory.'
    );
}

if (!is_writable($uploadDirectory)) {
    facultyProfilePictureRedirect(
        'The profile picture directory is not writable.'
    );
}

/*
|--------------------------------------------------------------------------
| GENERATE SECURE FILENAME
|--------------------------------------------------------------------------
*/

$extension = $allowedMimeTypes[$mimeType];

$newFilename = 'faculty_'
    . $facultyId
    . '_'
    . bin2hex(random_bytes(16))
    . '.'
    . $extension;

$newFilePath = $uploadDirectory
    . DIRECTORY_SEPARATOR
    . $newFilename;

/*
|--------------------------------------------------------------------------
| MOVE UPLOADED IMAGE
|--------------------------------------------------------------------------
*/

if (!move_uploaded_file($tmpPath, $newFilePath)) {
    facultyProfilePictureRedirect(
        'Unable to save your profile picture. Please try again.'
    );
}

/*
|--------------------------------------------------------------------------
| UPDATE DATABASE
|--------------------------------------------------------------------------
*/

try {

    $oldFilename = basename(
        trim((string) ($account['profile_picture'] ?? ''))
    );

    $updatePictureQuery = $pdo->prepare(
        "UPDATE users
         SET profile_picture = ?
         WHERE id = ?
           AND role = 'faculty'
           AND is_active = 1"
    );

    $updatePictureQuery->execute([
        $newFilename,
        $facultyId,
    ]);

    if ($updatePictureQuery->rowCount() !== 1) {
        throw new RuntimeException(
            'Profile picture database update failed.'
        );
    }

    /*
     * DELETE PREVIOUS FACULTY PROFILE PICTURE.
     *
     * Only delete filenames matching the expected
     * filename format for this faculty account.
     */

    $validOldFilename = preg_match(
        '/\Afaculty_'
        . preg_quote((string) $facultyId, '/')
        . '_[a-f0-9]{32}\.(jpg|png|webp)\z/i',
        $oldFilename
    ) === 1;

    if (
        $validOldFilename
        && $oldFilename !== $newFilename
    ) {

        $oldFilePath = $uploadDirectory
            . DIRECTORY_SEPARATOR
            . $oldFilename;

        if (is_file($oldFilePath)) {
            @unlink($oldFilePath);
        }
    }

    facultyProfilePictureRedirect(
        'Your profile picture was updated successfully.',
        true
    );

} catch (Throwable $exception) {

    if (is_file($newFilePath)) {
        @unlink($newFilePath);
    }

    error_log(
        'AcadTrack faculty profile picture update error: '
        . $exception->getMessage()
    );

    facultyProfilePictureRedirect(
        'Unable to update your profile picture. Please try again.'
    );
}