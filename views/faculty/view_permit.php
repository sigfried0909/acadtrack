<?php
declare(strict_types=1);

require_once __DIR__ . '/../../auth/require_role.php';
requireRole(['faculty']);

require_once __DIR__ . '/../../config/database.php';

date_default_timezone_set('Asia/Manila');

$facultyId = (int) ($_SESSION['user_id'] ?? 0);

if ($facultyId < 1) {
    http_response_code(403);
    exit('Access denied.');
}

$submissionId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$submissionId) {
    http_response_code(404);
    exit('Document not found.');
}

/*
 * VERIFY THAT THE SUBMISSION BELONGS TO A STUDENT
 * IN A SECTION ASSIGNED TO THIS ACTIVE FACULTY MEMBER.
 */
$query = $pdo->prepare(
    "SELECT
        ps.stored_filename,
        ps.original_filename
     FROM permit_submissions ps
     INNER JOIN section_students ss
        ON ss.student_id = ps.student_id
     INNER JOIN sections s
        ON s.id = ss.section_id
     INNER JOIN users faculty
        ON faculty.id = s.ssp_adviser_id
        AND faculty.role = 'faculty'
        AND faculty.is_active = 1
     WHERE ps.id = ?
       AND s.ssp_adviser_id = ?
     LIMIT 1"
);

$query->execute([
    $submissionId,
    $facultyId,
]);

$document = $query->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    http_response_code(404);
    exit('Document not found or access denied.');
}

/*
 * FILE STORAGE DIRECTORY.
 * This must match the directory used by upload_permit.php.
 */
$uploadDirectoryPath = dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'storage'
    . DIRECTORY_SEPARATOR . 'permits';

$uploadDirectory = realpath($uploadDirectoryPath);

if ($uploadDirectory === false || !is_dir($uploadDirectory)) {
    error_log(
        'AcadTrack: Permit upload directory not found. '
        . 'Check the configured storage/permits directory.'
    );

    http_response_code(404);
    exit('The document storage directory is unavailable.');
}

/*
 * VALIDATE THE RANDOM STORED FILENAME.
 *
 * Supports PDF, JPG, JPEG, and PNG.
 * The stored filename must contain 48 hexadecimal characters
 * followed by an allowed extension.
 */
$storedFilename = (string) $document['stored_filename'];

if (
    !preg_match(
        '/\A[a-f0-9]{48}\.(?:pdf|jpe?g|png)\z/i',
        $storedFilename
    )
) {
    error_log(
        'AcadTrack: Invalid stored permit filename for submission '
        . $submissionId
    );

    http_response_code(404);
    exit('Document not found.');
}

/*
 * RESOLVE THE FILE PATH AND PREVENT DIRECTORY TRAVERSAL.
 */
$filePath = realpath(
    $uploadDirectory . DIRECTORY_SEPARATOR . $storedFilename
);

$expectedPrefix = rtrim(
    $uploadDirectory,
    DIRECTORY_SEPARATOR
) . DIRECTORY_SEPARATOR;

if (
    $filePath === false
    || strpos($filePath, $expectedPrefix) !== 0
    || !is_file($filePath)
    || !is_readable($filePath)
) {
    error_log(
        'AcadTrack: Permit file missing or unreadable. '
        . 'Submission ID: ' . $submissionId
        . '; stored filename: ' . $storedFilename
    );

    http_response_code(404);
    exit('Uploaded document not found. Please contact the administrator.');
}

/*
 * VERIFY THE ACTUAL FILE TYPE.
 */
if (!class_exists('finfo')) {
    error_log('AcadTrack: PHP fileinfo extension is unavailable.');

    http_response_code(500);
    exit('Unable to verify the document type.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($filePath);

$allowedMimeTypes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
];

if (!is_string($mimeType) || !in_array($mimeType, $allowedMimeTypes, true)) {
    error_log(
        'AcadTrack: Unsupported permit MIME type for submission '
        . $submissionId
    );

    http_response_code(415);
    exit('Unsupported document type.');
}

/*
 * SANITIZE THE ORIGINAL FILENAME FOR THE RESPONSE.
 */
$originalFilename = basename(
    str_replace(
        '\\',
        '/',
        (string) $document['original_filename']
    )
);

$originalFilename = preg_replace(
    '/[\x00-\x1F\x7F]/',
    '',
    $originalFilename
);

if (
    !is_string($originalFilename)
    || trim($originalFilename) === ''
) {
    $fallbackExtension = match ($mimeType) {
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    };

    $originalFilename = 'permit-document.' . $fallbackExtension;
}

/*
 * SEND THE FILE INLINE SO THE BROWSER CAN DISPLAY IT.
 */
$fileSize = filesize($filePath);

if ($fileSize === false) {
    error_log(
        'AcadTrack: Unable to determine permit file size. '
        . 'Submission ID: ' . $submissionId
    );

    http_response_code(500);
    exit('Unable to read the uploaded document.');
}

/*
 * Prevent accidental buffered HTML or warnings from corrupting
 * the PDF/image response.
 */
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mimeType);

header(
    'Content-Disposition: inline; filename="permit-document"; filename*=UTF-8\'\''
    . rawurlencode($originalFilename)
);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Content-Length: ' . (string) $fileSize);

readfile($filePath);
exit;