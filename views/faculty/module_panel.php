<?php
declare(strict_types=1);

if (!isset($pdo, $facultyId, $sections, $csrfToken)) {
    http_response_code(403);
    exit;
}

$facultyModuleView = true;
$moduleRows = [];
$moduleListError = false;

try {
    $moduleQuery = $pdo->prepare(
        "SELECT m.id, m.period, m.title, m.description, m.original_filename,
                m.file_extension, m.file_size,
                s.section_name, s.course, s.year_level
         FROM section_modules m
         JOIN sections s ON s.id = m.section_id
         WHERE s.ssp_adviser_id = ?
         ORDER BY m.id DESC"
    );
    $moduleQuery->execute([$facultyId]);
    $moduleRows = $moduleQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $error) {
    error_log('AcadTrack faculty modules: ' . $error->getMessage());
    $moduleListError = true;
}
?>

<?php
// Use the section ID for grouping. Different courses can share a section name.
$moduleGroups = [];
$moduleCount = 0;
$moduleListError = false;

foreach ($sections as $section) {
    $moduleGroups[(int) $section['id']] = [
        'name' => (string) $section['section_name'],
        'course' => (string) $section['course'],
        'year' => (int) $section['year_level'],
        'count' => 0,
        'periods' => [],
    ];
}

try {
    $moduleQuery = $pdo->prepare(
        "SELECT m.id, m.section_id, m.period, m.title, m.description,
                m.original_filename, m.file_extension, m.file_size
         FROM section_modules m
         INNER JOIN sections s ON s.id = m.section_id
         WHERE s.ssp_adviser_id = ?
         ORDER BY m.id DESC"
    );
    $moduleQuery->execute([$facultyId]);

    while ($module = $moduleQuery->fetch(PDO::FETCH_ASSOC)) {
        $groupId = (int) $module['section_id'];

        if (!isset($moduleGroups[$groupId])) {
            continue;
        }

        $periodKey = in_array($module['period'], ['P1', 'P2', 'P3'], true)
            ? $module['period']
            : 'unassigned';

        $moduleGroups[$groupId]['periods'][$periodKey][] = $module;
        $moduleGroups[$groupId]['count']++;
        $moduleCount++;
    }
} catch (PDOException $error) {
    error_log('AcadTrack faculty module list: ' . $error->getMessage());
    $moduleListError = true;
}

$modulePeriodLabels = [
    'P1' => 'P1',
    'P2' => 'P2',
    'P3' => 'P3',
    'unassigned' => 'Period not assigned',
];

$moduleFileTypes = [
    'pdf' => ['PDF', 'file-text', 'pdf'],
    'ppt' => ['PowerPoint', 'presentation', 'slides'],
    'pptx' => ['PowerPoint', 'presentation', 'slides'],
    'doc' => ['Word', 'file-text', 'word'],
    'docx' => ['Word', 'file-text', 'word'],
    'xls' => ['Excel', 'file-spreadsheet', 'excel'],
    'xlsx' => ['Excel', 'file-spreadsheet', 'excel'],
];
?>

<section class="admin-panel faculty-section-panel faculty-module-library"
    aria-labelledby="faculty-module-library-title">

    <header class="admin-panel-heading">
        <div>
            <h2 id="faculty-module-library-title">Modules in my assigned sections</h2>
            <?php if (!$moduleListError && $moduleGroups): ?>
                <p>
                    <?= $moduleCount ?>     <?= $moduleCount === 1 ? 'module' : 'modules' ?>
                    across <?= count($moduleGroups) ?> assigned
                    <?= count($moduleGroups) === 1 ? 'section' : 'sections' ?>
                </p>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($moduleListError): ?>
        <p class="module-empty" role="alert">
            Unable to load your modules. Refresh the page to try again.
        </p>

    <?php elseif (!$moduleGroups): ?>
        <p class="module-empty">Your modules will appear here once you have an assigned section.</p>

    <?php else: ?>
        <div class="faculty-module-groups">
            <?php foreach ($moduleGroups as $groupId => $group): ?>
                <section class="faculty-module-group" aria-labelledby="module-section-<?= $groupId ?>">

                    <header class="faculty-module-group-head">
                        <div>
                            <h3 id="module-section-<?= (int) $groupId ?>">
                                <?= facultyEscape($group['name']) ?>
                            </h3>
                            <p>
                                <?= facultyEscape($group['course']) ?>
                                · Year <?= (int) $group['year'] ?>
                                · <?= (int) $group['count'] ?>
                                <?= $group['count'] === 1 ? 'file' : 'files' ?>
                            </p>
                        </div>

                        <button type="button" class="button button-primary faculty-module-upload-trigger" data-module-upload
                            data-section-id="<?= (int) $groupId ?>" data-section-label="<?= facultyEscape(
                                   $group['course'] . ' · Year ' . $group['year'] . ' · ' . $group['name']
                               ) ?>" aria-label="Upload module to <?= facultyEscape($group['name']) ?>"
                            aria-haspopup="dialog" aria-controls="faculty-module-upload-modal">
                            <i data-lucide="upload" aria-hidden="true"></i>
                            Upload module
                        </button>
                    </header>

                    <?php if ($group['count'] === 0): ?>
                        <p class="faculty-module-group-empty">No modules uploaded for this section yet.</p>
                    <?php endif; ?>

                    <div class="faculty-module-scroll" <?php if ($group['count'] > 5): ?> data-module-scroll tabindex="0"
                            role="region" aria-labelledby="module-section-
                <?= (int) $groupId ?>" <?php endif; ?>>

                        <?php foreach ($modulePeriodLabels as $periodKey => $periodLabel): ?>
                            <?php if (empty($group['periods'][$periodKey]))
                                continue; ?>

                            <div class="faculty-module-period">
                                <h4 class="faculty-module-period-title">
                                    <?= facultyEscape($periodLabel) ?>
                                </h4>

                                <ul class="faculty-module-files">
                                    <?php foreach ($group['periods'][$periodKey] as $module): ?>
                                        <?php
                                        $moduleId = (int) $module['id'];
                                        $title = (string) $module['title'];
                                        $extension = strtolower((string) $module['file_extension']);

                                        [$format, $icon, $color] = $moduleFileTypes[$extension]
                                            ?? [strtoupper($extension), 'file', 'other'];

                                        $bytes = (int) $module['file_size'];
                                        $size = $bytes >= 1048576
                                            ? number_format($bytes / 1048576, 1) . ' MB'
                                            : max(1, (int) ceil($bytes / 1024)) . ' KB';

                                        $fileUrl = '../shared/module_file.php?id=' . $moduleId;
                                        ?>

                                        <li class="faculty-module-file">
                                            <span class="faculty-module-icon is-<?= facultyEscape($color) ?>" aria-hidden="true">
                                                <i data-lucide="<?= facultyEscape($icon) ?>"></i>
                                            </span>

                                            <div class="faculty-module-copy">
                                                <h5><?= facultyEscape($title) ?></h5>

                                                <?php if (trim((string) $module['description']) !== ''): ?>
                                                    <p class="faculty-module-description"><?= facultyEscape($module['description']) ?></p>
                                                <?php endif; ?>

                                                <p class="faculty-module-metadata">
                                                    <?= facultyEscape($module['original_filename']) ?>
                                                    · <?= facultyEscape($format) ?>
                                                    · <?= facultyEscape($size) ?>
                                                </p>
                                            </div>

                                            <div class="faculty-module-actions">
                                                <?php if ($extension === 'pdf'): ?>
                                                    <a class="button button-outline" href="<?= facultyEscape($fileUrl) ?>" target="_blank"
                                                        rel="noopener noreferrer" aria-label="View <?= facultyEscape($title) ?> as PDF">
                                                        <i data-lucide="eye" aria-hidden="true"></i>
                                                        View PDF
                                                    </a>
                                                <?php endif; ?>

                                                <a class="button button-outline" href="<?= facultyEscape($fileUrl) ?>&amp;download=1"
                                                    aria-label="Download <?= facultyEscape($title) ?>">
                                                    <i data-lucide="download" aria-hidden="true"></i>
                                                    Download
                                                </a>

                                                <form method="post" action="dashboard.php?page=modules" class="faculty-delete-form"
                                                    data-faculty-delete data-item-type="module"
                                                    data-item-title="<?= facultyEscape($title) ?>">

                                                    <input type="hidden" name="csrf_token" value="<?= facultyEscape($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="delete_module">
                                                    <input type="hidden" name="item_id" value="<?= $moduleId ?>">

                                                    <button type="submit" class="button faculty-delete-button"
                                                        aria-label="Delete <?= facultyEscape($title) ?>">
                                                        <i data-lucide="trash-2" aria-hidden="true"></i>
                                                        Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<dialog id="faculty-module-upload-modal" class="faculty-profile-dialog" aria-labelledby="faculty-module-upload-title"
    aria-describedby="faculty-module-upload-section">

    <header class="faculty-profile-header">
        <div class="faculty-profile-heading">
            <span class="faculty-profile-heading-icon" aria-hidden="true">
                <i data-lucide="file-up"></i>
            </span>

            <div>
                <h2 id="faculty-module-upload-title">Upload module</h2>
                <p id="faculty-module-upload-section"></p>
            </div>
        </div>

        <button type="button" class="icon-btn faculty-profile-close" data-close-module-upload
            aria-label="Close upload form">
            <i data-lucide="x" aria-hidden="true"></i>
        </button>
    </header>

    <form id="faculty-module-upload-form" class="faculty-profile-form" action="dashboard.php?page=modules" method="post"
        enctype="multipart/form-data">

        <input type="hidden" name="action" value="upload_module">
        <input type="hidden" name="csrf_token" value="<?= facultyEscape($csrfToken) ?>">
        <input type="hidden" name="upload_key" value="">
        <input type="hidden" name="section_id" value="">
        <input type="hidden" name="MAX_FILE_SIZE" value="5242880">

        <div class="faculty-profile-grid">
            <label class="faculty-profile-field">
                Period
                <select name="period" required>
                    <option value="">Choose a period</option>
                    <option value="P1">P1</option>
                    <option value="P2">P2</option>
                    <option value="P3">P3</option>
                </select>
            </label>

            <label class="faculty-profile-field">
                Module title
                <input type="text" name="title" maxlength="180" placeholder="e.g. Database Normalization" required>
            </label>

            <label class="faculty-profile-field faculty-profile-full">
                Review instructions (optional)
                <textarea name="description" rows="3" maxlength="3000"
                    placeholder="e.g. Review slides 8–15 before the P2 quiz."></textarea>
            </label>

            <label class="faculty-profile-field faculty-profile-full">
                Module file
                <input type="file" name="module_file" accept=".pdf,.ppt,.pptx,.doc,.docx,.xls,.xlsx" required>
                <small>PDF, PowerPoint, Word, or Excel. Maximum 5 MB.</small>
            </label>
        </div>

        <footer class="faculty-profile-actions">
            <button type="button" class="button button-outline" data-close-module-upload>
                Cancel
            </button>

            <button type="submit" class="button button-primary">
                <i data-lucide="upload" aria-hidden="true"></i>
                Upload module
            </button>
        </footer>
    </form>
</dialog>