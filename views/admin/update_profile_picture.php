<?php
declare(strict_types=1);

require_once __DIR__ . '/../../auth/require_role.php';
requireRole(['admin']);

require_once __DIR__ . '/../../config/database.php';

date_default_timezone_set('Asia/Manila');

$adminId = (int) ($_SESSION['user_id'] ?? 0);

$newFilename = '';

/*
|--------------------------------------------------------------------------
| REDIRECT WITH MESSAGE
|--------------------------------------------------------------------------
*/

function adminProfilePictureRedirect(
    string $message,
    bool $success = false
): void {

    if ($success) {
        $_SESSION['admin_profile_notice'] = $message;
    } else {
        $_SESSION['admin_profile_picture_error'] = $message;
    }

    $page = $_POST['return_page'] ?? 'dashboard';

    if (
        !is_string($page)
        || !in_array($page, ['dashboard', 'sections'], true)
    ) {
        $page = 'dashboard';
    }

    $parameters = [
        'page' => $page,
        'open_profile' => '1',
    ];

    header(
        'Location: dashboard.php?'
        . http_build_query($parameters)
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

if ($adminId < 1) {
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

    adminProfilePictureRedirect(
        'Your session token is invalid. Refresh the page and try again.'
    );
}

/*
|--------------------------------------------------------------------------
| VERIFY ADMIN ACCOUNT
|--------------------------------------------------------------------------
*/

try {

    $accountQuery = $pdo->prepare(
        "SELECT profile_picture
         FROM users
         WHERE id = ?
           AND role = 'admin'
           AND is_active = 1
         LIMIT 1"
    );

    $accountQuery->execute([$adminId]);

    $account = $accountQuery->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        http_response_code(403);
        exit('Your administrator account is unavailable or inactive.');
    }

} catch (Throwable $exception) {

    error_log(
        'AcadTrack admin profile picture account lookup error: '
        . $exception->getMessage()
    );

    adminProfilePictureRedirect(
        'Unable to verify your administrator account. Please try again.'
    );
}

/*
|--------------------------------------------------------------------------
| FILE VALIDATION
|--------------------------------------------------------------------------
*/

$file = $_FILES['profile_picture'] ?? null;

if (!is_array($file)) {
    adminProfilePictureRedirect(
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

    adminProfilePictureRedirect(
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
    adminProfilePictureRedirect(
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
    adminProfilePictureRedirect(
        'Your profile picture must not exceed 2 MB.'
    );
}

/*
|--------------------------------------------------------------------------
| VERIFY IMAGE MIME TYPE
|--------------------------------------------------------------------------
*/

if (!class_exists('finfo')) {
    adminProfilePictureRedirect(
        'The server image validation service is unavailable.'
    );
}

$mimeType = false;

try {

    $finfo = new finfo(FILEINFO_MIME_TYPE);

    $mimeType = $finfo->file($tmpPath);

} catch (Throwable $exception) {

    error_log(
        'AcadTrack admin image MIME validation error: '
        . $exception->getMessage()
    );

    adminProfilePictureRedirect(
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
    adminProfilePictureRedirect(
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
    adminProfilePictureRedirect(
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
    adminProfilePictureRedirect(
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
    adminProfilePictureRedirect(
        'Unable to create the profile picture upload directory.'
    );
}

if (!is_writable($uploadDirectory)) {
    adminProfilePictureRedirect(
        'The profile picture directory is not writable.'
    );
}

/*
|--------------------------------------------------------------------------
| GENERATE SECURE FILENAME
|--------------------------------------------------------------------------
*/

try {

    $extension = $allowedMimeTypes[$mimeType];

    $newFilename = 'admin_'
        . $adminId
        . '_'
        . bin2hex(random_bytes(16))
        . '.'
        . $extension;

} catch (Throwable $exception) {

    error_log(
        'AcadTrack admin profile picture filename error: '
        . $exception->getMessage()
    );

    adminProfilePictureRedirect(
        'Unable to prepare the profile picture. Please try again.'
    );
}

$newFilePath = $uploadDirectory
    . DIRECTORY_SEPARATOR
    . $newFilename;

/*
|--------------------------------------------------------------------------
| MOVE UPLOADED IMAGE
|--------------------------------------------------------------------------
*/

if (!move_uploaded_file($tmpPath, $newFilePath)) {
    adminProfilePictureRedirect(
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
           AND role = 'admin'
           AND is_active = 1"
    );

    $updatePictureQuery->execute([
        $newFilename,
        $adminId,
    ]);

    if ($updatePictureQuery->rowCount() !== 1) {
        throw new RuntimeException(
            'Profile picture database update failed.'
        );
    }

    /*
     * DELETE PREVIOUS ADMIN PROFILE PICTURE.
     *
     * Only remove the previous picture when its
     * filename matches this administrator's ID.
     */

    $validOldFilename = preg_match(
        '/\Aadmin_'
        . preg_quote((string) $adminId, '/')
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

    adminProfilePictureRedirect(
        'Your profile picture was updated successfully.',
        true
    );

} catch (Throwable $exception) {

    if (is_file($newFilePath)) {
        @unlink($newFilePath);
    }

    error_log(
        'AcadTrack admin profile picture update error: '
        . $exception->getMessage()
    );

    adminProfilePictureRedirect(
        'Unable to update your profile picture. Please try again.'
    );
}