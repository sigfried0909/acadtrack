<?php
declare(strict_types=1);

require_once __DIR__ . '/../../auth/require_role.php';
requireRole(['student']);

require_once __DIR__ . '/../../config/database.php';

date_default_timezone_set('Asia/Manila');

$studentId = (int) ($_SESSION['user_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| REDIRECT WITH ERROR
|--------------------------------------------------------------------------
*/

function redirectProfilePictureError(string $message): void
{
    $_SESSION['student_profile_picture_error'] = $message;

    header('Location: dashboard.php?open_profile=1');
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

if ($studentId < 1) {
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
    redirectProfilePictureError(
        'Your session token is invalid. Refresh the page and try again.'
    );
}

/*
|--------------------------------------------------------------------------
| VERIFY STUDENT ACCOUNT
|--------------------------------------------------------------------------
*/

try {

    $accountQuery = $pdo->prepare(
        "SELECT profile_picture
         FROM users
         WHERE id = ?
           AND role = 'student'
           AND is_active = 1
         LIMIT 1"
    );

    $accountQuery->execute([$studentId]);

    $account = $accountQuery->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        http_response_code(403);
        exit('Your student account is unavailable or inactive.');
    }

} catch (Throwable $exception) {

    error_log(
        'AcadTrack profile picture account lookup error: '
        . $exception->getMessage()
    );

    redirectProfilePictureError(
        'Unable to verify your student account. Please try again.'
    );
}

/*
|--------------------------------------------------------------------------
| FILE VALIDATION
|--------------------------------------------------------------------------
*/

$file = $_FILES['profile_picture'] ?? null;

if (!is_array($file)) {
    redirectProfilePictureError(
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

    redirectProfilePictureError(
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
    redirectProfilePictureError(
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
    redirectProfilePictureError(
        'Your profile picture must not exceed 2 MB.'
    );
}

/*
|--------------------------------------------------------------------------
| VERIFY IMAGE MIME TYPE
|--------------------------------------------------------------------------
*/

if (!function_exists('finfo_open')) {
    redirectProfilePictureError(
        'The server image validation service is unavailable.'
    );
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);

if ($finfo === false) {
    redirectProfilePictureError(
        'Unable to validate the uploaded image.'
    );
}

$mimeType = finfo_file($finfo, $tmpPath);

$allowedMimeTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

if (
    !is_string($mimeType)
    || !isset($allowedMimeTypes[$mimeType])
) {
    redirectProfilePictureError(
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
    redirectProfilePictureError(
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
    redirectProfilePictureError(
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
    redirectProfilePictureError(
        'Unable to create the profile picture upload directory.'
    );
}

if (!is_writable($uploadDirectory)) {
    redirectProfilePictureError(
        'The profile picture directory is not writable.'
    );
}

/*
|--------------------------------------------------------------------------
| GENERATE SECURE FILENAME
|--------------------------------------------------------------------------
*/

$extension = $allowedMimeTypes[$mimeType];

$newFilename = 'student_'
    . $studentId
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
    redirectProfilePictureError(
        'Unable to save your profile picture. Please try again.'
    );
}

/*
|--------------------------------------------------------------------------
| UPDATE DATABASE
|--------------------------------------------------------------------------
*/

try {

    /*
     * Retrieve the previous picture filename.
     */

    $oldFilename = basename(
        trim((string) ($account['profile_picture'] ?? ''))
    );

    /*
     * Save the new filename.
     *
     * Database column:
     * users.profile_picture
     */

    $updatePictureQuery = $pdo->prepare(
        "UPDATE users
         SET profile_picture = ?
         WHERE id = ?
           AND role = 'student'
           AND is_active = 1"
    );

    $updatePictureQuery->execute([
        $newFilename,
        $studentId,
    ]);

    if ($updatePictureQuery->rowCount() !== 1) {
        throw new RuntimeException(
            'Profile picture database update failed.'
        );
    }

    /*
     * Delete the previous picture only if it belongs
     * to the expected student filename format.
     */

    $validOldFilename = preg_match(
        '/\Astudent_'
        . preg_quote((string) $studentId, '/')
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

    /*
     * SUCCESS NOTIFICATION
     */

    $_SESSION['student_profile_notice'] =
        'Your profile picture was updated successfully.';

    header('Location: dashboard.php?open_profile=1');
    exit;

} catch (Throwable $exception) {

    /*
     * Remove the newly uploaded image if the
     * database update fails.
     */

    if (is_file($newFilePath)) {
        @unlink($newFilePath);
    }

    error_log(
        'AcadTrack profile picture update error: '
        . $exception->getMessage()
    );

    redirectProfilePictureError(
        'Unable to update your profile picture. Please try again.'
    );
}
