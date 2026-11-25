<?php
declare(strict_types=1);

if (!isset($moduleRows) || !is_array($moduleRows)) {
    http_response_code(404);
    exit;
}

$moduleEscape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?>

<?php if (!empty($moduleListError)): ?>
    <p class="module-empty" role="alert">Unable to load modules right now.</p>
<?php elseif (!$moduleRows): ?>
    <p class="module-empty">No modules have been uploaded for your section
        <?= !empty($facultyModuleView) ? '(s)' : '' ?> yet.
    </p>
<?php else: ?>
    <div class="module-list">
        <?php foreach ($moduleRows as $module): ?>
            <?php
            $fileUrl = '../shared/module_file.php?id=' . (int) $module['id'];
            $isPdf = $module['file_extension'] === 'pdf';
            $bytes = (int) $module['file_size'];
            $sizeLabel = $bytes >= 1048576
                ? number_format($bytes / 1048576, 1) . ' MB'
                : max(1, (int) ceil($bytes / 1024)) . ' KB';
            ?>

            <article class="module-card">
                <div>
                    <h3>
                        <?= $moduleEscape($module['title']) ?>
                    </h3>

                    <p class="module-meta">
                        <strong>
                            <?= $moduleEscape($module['period'] ?? 'Period not assigned') ?>
                        </strong>
                        · Year <?= (int) $module['year_level'] ?>
                        · <?= $moduleEscape($module['section_name']) ?>
                    </p>

                    <?php if ($module['description'] !== ''): ?>
                        <p class="module-description">
                            <?= $moduleEscape($module['description']) ?>
                        </p>
                    <?php endif; ?>

                    <p class="module-meta">
                        <?= $moduleEscape($module['original_filename']) ?>
                        ·
                        <?= $moduleEscape($sizeLabel) ?>
                    </p>
                </div>

                <div class="module-links">
                    <?php if ($isPdf): ?>
                        <a class="button button-outline" href="<?= $fileUrl ?>" target="_blank" rel="noopener noreferrer">View
                            PDF</a>
                    <?php endif; ?>

                    <a class="button button-primary" href="<?= $fileUrl ?>&amp;download=1">
                        Download
                        <?= $moduleEscape(strtoupper($module['file_extension'])) ?>
                    </a>

                    <?php if (($facultyModuleView ?? false) === true): ?>
                        <form method="post" action="dashboard.php?page=modules" class="faculty-delete-form" data-faculty-delete
                            data-item-type="module" data-item-title="<?= $moduleEscape($module['title']) ?>">

                            <input type="hidden" name="csrf_token" value="<?= $moduleEscape($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_module">
                            <input type="hidden" name="item_id" value="<?= (int) $module['id'] ?>">

                            <button type="submit" class="button faculty-delete-button">
                                <i data-lucide="trash-2" aria-hidden="true"></i>
                                <span>Delete module</span>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>