<?php
declare(strict_types=1);

function facultyModuleText($value, string $label, int $limit, bool $required = true): string
{
    if (!is_string($value)) {
        throw new DomainException("Invalid {$label}.");
    }

    $value = trim($value);

    if ($required && $value === '') {
        throw new DomainException("Please enter the {$label}.");
    }

    if (facultyTextLength($value) > $limit) {
        throw new DomainException("The {$label} exceeds {$limit} characters.");
    }

    return $value;
}

function facultyModuleFile($upload): array
{
    if (!is_array($upload)) {
        throw new DomainException('Choose a module file.');
    }

    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new DomainException('The file exceeds the upload limit. Choose a file up to 5 MB.');
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new DomainException('The file did not finish uploading. Choose it again.');
    }

    $path = $upload['tmp_name'] ?? '';
    if (!is_string($path) || !is_uploaded_file($path)) {
        throw new DomainException('Invalid upload. Choose the file again.');
    }

    $size = filesize($path);
    if ($size === false || $size < 1 || $size > 5 * 1024 * 1024) {
        throw new DomainException('Choose a nonempty file up to 5 MB.');
    }

    $name = $upload['name'] ?? '';
    if (!is_string($name)) {
        throw new DomainException('Invalid filename.');
    }

    $name = basename(str_replace('\\', '/', $name));

    if (!preg_match('//u', $name) || preg_match('/[\x00-\x1F\x7F]/', $name)) {
        throw new DomainException('Rename the file and upload it again.');
    }

    $name = facultyModuleText($name, 'filename', 200);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    $officeParts = [
        'pptx' => 'ppt/presentation.xml',
        'docx' => 'word/document.xml',
        'xlsx' => 'xl/workbook.xml',
    ];

    $signature = file_get_contents($path, false, null, 0, 8);

    if ($ext === 'pdf') {
        if (substr((string) $signature, 0, 5) !== '%PDF-') {
            throw new DomainException('The selected file does not have a PDF header.');
        }
    } elseif (isset($officeParts[$ext])) {
        if (!class_exists('ZipArchive')) {
            throw new DomainException('Office upload support requires the PHP zip extension.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new DomainException('The Office document could not be read.');
        }

        try {
            $valid = $zip->locateName('[Content_Types].xml') !== false
                && $zip->locateName($officeParts[$ext]) !== false;
        } finally {
            $zip->close();
        }

        if (!$valid) {
            throw new DomainException('The Office document does not match its extension.');
        }
    } elseif (in_array($ext, ['ppt', 'doc', 'xls'], true)) {
        // Legacy Office formats share a compound-document header.
        if ($signature !== hex2bin('D0CF11E0A1B11AE1')) {
            throw new DomainException('The file does not have a legacy Office document header.');
        }
    } else {
        throw new DomainException('Choose a PDF, PowerPoint, Word, or Excel file.');
    }

    $content = file_get_contents($path);
    if ($content === false || strlen($content) !== $size) {
        throw new RuntimeException('Unable to read the complete uploaded file.');
    }

    return [
        'name' => $name,
        'extension' => $ext,
        'size' => $size,
        'content' => $content,
    ];
}

function facultyUploadModule(PDO $pdo, int $facultyId, string $csrfToken): void
{
    $sectionId = 0;

    try {
        $token = $_POST['csrf_token'] ?? null;

        if (!is_string($token) || !hash_equals($csrfToken, $token)) {
            throw new DomainException('Your session token expired. Refresh the page and try again.');
        }

        $uploadKey = $_POST['upload_key'] ?? null;

        if (!is_string($uploadKey) || !preg_match('/\A[a-f0-9]{32}\z/', $uploadKey)) {
            throw new DomainException('Reopen the module form and try again.');
        }

        $sectionId = (int) filter_var($_POST['section_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($sectionId < 1) {
            throw new DomainException('Choose an assigned section.');
        }

        $period = $_POST['period'] ?? '';

        if (!in_array($period, ['P1', 'P2', 'P3'], true)) {
            throw new DomainException('Choose P1, P2, or P3 for this module.');
        }

        $title = facultyModuleText($_POST['title'] ?? '', 'module title', 180);
        $description = facultyModuleText($_POST['description'] ?? '', 'description', 3000, false);
        $file = facultyModuleFile($_FILES['module_file'] ?? null);

        $pdo->beginTransaction();

        $check = $pdo->prepare(
            "SELECT s.id
             FROM sections s
             JOIN users u ON u.id = s.ssp_adviser_id
             WHERE s.id = ? AND u.id = ? AND u.role = 'faculty' AND u.is_active = 1
             FOR UPDATE"
        );
        $check->execute([$sectionId, $facultyId]);

        if (!$check->fetchColumn()) {
            $sectionId = 0;
            throw new DomainException('This section is not assigned to your account.');
        }

        $save = $pdo->prepare(
            "INSERT INTO section_modules (
        section_id,
        period,
        uploaded_by,
        title,
        description,
        original_filename,
        file_extension,
        file_size,
        file_content,
        upload_key
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE id = id"
        );

        $save->bindValue(1, $sectionId, PDO::PARAM_INT);
        $save->bindValue(2, $period, PDO::PARAM_STR);
        $save->bindValue(3, $facultyId, PDO::PARAM_INT);
        $save->bindValue(4, $title, PDO::PARAM_STR);
        $save->bindValue(5, $description, PDO::PARAM_STR);
        $save->bindValue(6, $file['name'], PDO::PARAM_STR);
        $save->bindValue(7, $file['extension'], PDO::PARAM_STR);
        $save->bindValue(8, $file['size'], PDO::PARAM_INT);
        $save->bindValue(9, $file['content'], PDO::PARAM_LOB);
        $save->bindValue(10, $uploadKey, PDO::PARAM_STR);
        $save->execute();

        $pdo->commit();
        $type = 'success';
        $message = 'Module uploaded. Students in this section can now access it.';
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $type = 'error';
        if ($error instanceof DomainException) {
            $message = $error->getMessage();
        } else {
            error_log('AcadTrack module upload: ' . $error->getMessage());
            $message = 'Unable to save the module. Please try again.';
        }
    }

    facultyDashboardRedirect($type, $message, 'all', $sectionId, '', 'modules');
}