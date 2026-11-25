<?php
declare(strict_types=1);

require_once __DIR__ . '/../../auth/require_role.php';
requireRole(['faculty', 'student']);

require_once __DIR__ . '/../../config/database.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$moduleId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if (!$moduleId || $userId < 1) {
    http_response_code(404);
    exit('Module unavailable.');
}

try {
    $query = $pdo->prepare(
        "SELECT
            m.original_filename,
            m.file_extension,
            m.file_size,
            m.file_content

        FROM section_modules AS m

        INNER JOIN sections AS s
            ON s.id = m.section_id

        INNER JOIN users AS u
            ON u.id = ?
        AND u.is_active = 1

        WHERE m.id = ?
        AND (
                (
                    u.role = 'faculty'
                    AND s.ssp_adviser_id = u.id
                )
                OR
                (
                    u.role = 'student'
                    AND EXISTS (
                        SELECT 1
                        FROM section_students AS ss
                        INNER JOIN student_permit_status AS permit
                        ON permit.student_id = ss.student_id
                        AND permit.period = (m.period COLLATE utf8mb4_unicode_ci)
                        AND permit.status = 'with permit'
                        WHERE ss.student_id = u.id
                        AND ss.section_id = m.section_id
                    )
                )
        )

        LIMIT 1"
    );
    $query->execute([$userId, $moduleId]);
    $file = $query->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $error) {
    error_log('AcadTrack module retrieval: ' . $error->getMessage());
    http_response_code(500);
    exit('Unable to open the module right now.');
}

if (!$file) {
    http_response_code(404);
    exit('Module unavailable.');
}

$isPdf = $file['file_extension'] === 'pdf';
$inline = $isPdf && ($_GET['download'] ?? '') !== '1';
$disposition = $inline ? 'inline' : 'attachment';

session_write_close();

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Content-Type: ' . ($isPdf ? 'application/pdf' : 'application/octet-stream'));
header('Content-Length: ' . (int) $file['file_size']);
header(
    'Content-Disposition: ' . $disposition
    . '; filename="module.' . preg_replace('/[^a-z0-9]/', '', $file['file_extension']) . '"'
    . "; filename*=UTF-8''" . rawurlencode($file['original_filename'])
);

echo $file['file_content'];
exit;