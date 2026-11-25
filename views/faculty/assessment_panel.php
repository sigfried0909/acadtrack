<?php
declare(strict_types=1);

if (!isset($pdo, $facultyId, $sections, $csrfToken)) {
    http_response_code(403);
    exit;
}

$assessmentTypes = facultyQuestionTypes();

$previewTypeLabels = $assessmentTypes + [
    'dropdown' => 'Dropdown',
    'blank' => 'Fill in the blanks',
    'ordering' => 'Ordering / sequencing',
    'scale' => 'Rating scale',
    'case' => 'Case analysis',
    'code' => 'Code response',
    'url' => 'Project link',
    'custom' => 'Custom written response',
];

$assessmentsByPeriod = [
    'P1' => [],
    'P2' => [],
    'P3' => [],
];

$assessmentLoadError = false;
$selectedSectionId = (int) ($sectionFilter ?? 0);

try {
    $assessmentList = $pdo->prepare(
        "SELECT
            a.id,
            a.title,
            a.period,
            a.assessment_type,
            a.delivery_mode,
            a.external_url,
            a.questions_json,
            a.instructions,
            s.section_name,
            s.course
         FROM faculty_assessments a
         INNER JOIN sections s ON s.id = a.section_id
         WHERE s.ssp_adviser_id = ?
         ORDER BY a.id DESC"
    );

    $assessmentList->execute([$facultyId]);

    while ($assessmentRow = $assessmentList->fetch(PDO::FETCH_ASSOC)) {
        $rowPeriod = $assessmentRow['period'];

        if (isset($assessmentsByPeriod[$rowPeriod])) {
            $assessmentsByPeriod[$rowPeriod][] = $assessmentRow;
        }
    }
} catch (PDOException $error) {
    error_log('AcadTrack assessment list: ' . $error->getMessage());
    $assessmentLoadError = true;
}
?>

<!-- SAVED EXAMS AND QUIZZES -->

<section class="admin-panel faculty-section-panel" aria-labelledby="faculty-saved-assessments-title">

    <div class="admin-panel-heading">
        <div>
            <h2 id="faculty-saved-assessments-title">Saved exams and quizzes</h2>
            <p>Manage assessments for your assigned sections by period.</p>
        </div>
    </div>

    <?php if ($assessmentLoadError): ?>

        <p class="assessment-panel-notice" role="alert">
            Unable to load saved assessments. Refresh the page to try again.
        </p>
    <?php endif; ?>

    <?php if (!$sections): ?>
        <p class="assessment-panel-notice">
            You can create assessments once a section is assigned to you.
        </p>
    <?php endif; ?>

    <div class="assessment-period-list">
        <?php foreach ($assessmentsByPeriod as $period => $periodAssessments): ?>
            <?php $periodCount = count($periodAssessments); ?>

            <section class="assessment-period-card" aria-labelledby="assessment-heading-<?= facultyEscape($period) ?>">

                <header class="assessment-period-head">
                    <div>
                        <h3 id="assessment-heading-<?= facultyEscape($period) ?>">
                            <?= facultyEscape($period) ?>
                        </h3>

                        <?php if (!$assessmentLoadError): ?>
                            <p>
                                <?= $periodCount ?>
                                <?= $periodCount === 1 ? 'assessment' : 'assessments' ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <button type="button" class="button button-primary assessment-create-trigger"
                        data-create-assessment-period="<?= facultyEscape($period) ?>"
                        aria-label="Create assessment for <?= facultyEscape($period) ?>" aria-haspopup="dialog"
                        aria-controls="faculty-create-assessment-modal" <?= !$sections ? 'disabled' : '' ?>>
                        <i data-lucide="plus" aria-hidden="true"></i>
                        Create assessment
                    </button>
                </header>

                <?php if (!$assessmentLoadError && !$periodAssessments): ?>
                    <p class="assessment-period-empty">
                        No exams or quizzes saved for <?= facultyEscape($period) ?> yet.
                    </p>
                <?php endif; ?>

                <div class="assessment-period-items">
                    <?php foreach ($periodAssessments as $assessment): ?>
                        <?php
                        $savedId = (int) $assessment['id'];
                        $savedTitle = (string) $assessment['title'];
                        $isInternal = $assessment['delivery_mode'] === 'internal';
                        $previewId = 'saved-assessment-preview-' . $savedId;

                        $savedMeta = implode(' · ', [
                            $assessment['assessment_type'],
                            $assessment['period'],
                            $assessment['course'],
                            $assessment['section_name'],
                        ]);
                        ?>

                        <article class="saved-assessment-card">
                            <div class="saved-assessment-info">
                                <h3><?= facultyEscape($savedTitle) ?></h3>
                                <p><?= facultyEscape($savedMeta) ?></p>
                            </div>

                            <div class="saved-assessment-actions">
                                <button type="button" class="button button-outline" data-view-saved-assessment
                                    data-preview-id="<?= facultyEscape($previewId) ?>"
                                    data-title="<?= facultyEscape($savedTitle) ?>" data-meta="<?= facultyEscape($savedMeta) ?>"
                                    aria-haspopup="dialog" aria-controls="saved-assessment-modal"
                                    aria-label="View <?= facultyEscape($savedTitle) ?>">
                                    <i data-lucide="eye" aria-hidden="true"></i>
                                    View
                                </button>

                                <form method="post" action="dashboard.php?page=assessments" class="faculty-delete-form"
                                    data-faculty-delete
                                    data-item-type="<?= facultyEscape(strtolower($assessment['assessment_type'])) ?>"
                                    data-item-title="<?= facultyEscape($savedTitle) ?>">

                                    <input type="hidden" name="csrf_token" value="<?= facultyEscape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_assessment">
                                    <input type="hidden" name="item_id" value="<?= $savedId ?>">

                                    <button type="submit" class="button faculty-delete-button"
                                        aria-label="Delete <?= facultyEscape($savedTitle) ?>">
                                        <i data-lucide="trash-2" aria-hidden="true"></i>
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </article>

                        <!-- The View handler moves this preview into the details dialog. -->

                        <div id="<?= facultyEscape($previewId) ?>" hidden>
                            <?php if (($assessment['instructions'] ?? '') !== ''): ?>
                                <h3>Instructions</h3>
                                <p class="assessment-lines"><?= facultyEscape($assessment['instructions']) ?></p>
                            <?php endif; ?>

                            <?php if (!$isInternal): ?>
                                <a href="<?= facultyEscape($assessment['external_url']) ?>" class="faculty-file-link"
                                    target="_blank" rel="noopener noreferrer">
                                    Open assessment link ↗
                                </a>
                            <?php else: ?>
                                <a class="button button-outline" href="assessment_submissions.php?id=<?= $savedId ?>"
                                    data-open-submissions data-assessment-title="<?= facultyEscape($savedTitle) ?>"
                                    aria-haspopup="dialog" aria-controls="faculty-submissions-modal">
                                    <i data-lucide="clipboard-check" aria-hidden="true"></i>
                                    View submissions
                                </a>

                                <?php
                                $questions = json_decode((string) $assessment['questions_json'], true);
                                ?>

                                <?php if (!is_array($questions) || !$questions): ?>
                                    <p role="alert">The saved questions could not be displayed.</p>
                                <?php else: ?>
                                    <?php
                                    $questionCount = count($questions);
                                    $totalPoints = (int) array_sum(array_column($questions, 'points'));
                                    ?>

                                    <h3>Questions and answer key</h3>
                                    <p>
                                        <?= $questionCount ?>
                                        <?= $questionCount === 1 ? 'question' : 'questions' ?>
                                        · <?= $totalPoints ?> total <?= $totalPoints === 1 ? 'point' : 'points' ?>
                                    </p>

                                    <ol class="assessment-preview">
                                        <?php foreach ($questions as $question): ?>
                                            <?php
                                            $type = (string) ($question['type'] ?? '');
                                            $typeLabel = $previewTypeLabels[$type] ?? 'Question';
                                            $points = (int) ($question['points'] ?? 0);
                                            $items = $question['items'] ?? [];
                                            $key = $question['key'] ?? [];
                                            $isLegacy = (int) ($question['version'] ?? 1) < 2;
                                            ?>

                                            <li>
                                                <strong><?= facultyEscape($typeLabel) ?></strong>
                                                · <?= $points ?>                     <?= $points === 1 ? 'pt' : 'pts' ?>

                                                <p class="assessment-lines"><?= facultyEscape($question['prompt'] ?? '') ?></p>

                                                <?php if ($isLegacy): ?>
                                                    <?php if ($items): ?>
                                                        <ul>
                                                            <?php foreach ($items as $item): ?>
                                                                <li><?= facultyEscape(
                                                                    is_array($item) ? implode(' → ', $item) : $item
                                                                ) ?>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>

                                                    <?php if (($question['guide'] ?? '') !== ''): ?>
                                                        <p class="assessment-lines"><strong>Marking guide:</strong>
                                                            <?= facultyEscape($question['guide']) ?>
                                                        </p>
                                                    <?php endif; ?>

                                                <?php elseif ($type === 'single' || $type === 'multiple'): ?>
                                                    <ol type="A">
                                                        <?php foreach ($items as $index => $item): ?>
                                                            <li>
                                                                <?= facultyEscape($item) ?>
                                                                <?php if (in_array($index, $key['correct_indexes'] ?? [], true)): ?>
                                                                    <strong> — Correct</strong>
                                                                <?php endif; ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ol>

                                                <?php elseif ($type === 'boolean'): ?>
                                                    <p>
                                                        <strong>Correct answer:</strong>
                                                        <?= facultyEscape($key['answer'] ?? '') ?>
                                                    </p>

                                                <?php elseif ($type === 'short' || $type === 'enumeration'): ?>
                                                    <strong>
                                                        <?= $type === 'short' ? 'Accepted answers' : 'Expected items' ?>
                                                    </strong>

                                                    <ol>
                                                        <?php foreach (($key['answers'] ?? []) as $answer): ?>
                                                            <li><?= facultyEscape($answer) ?></li>
                                                        <?php endforeach; ?>
                                                    </ol>

                                                    <?php if ($type === 'enumeration'): ?>
                                                        <p>
                                                            <?= !empty($key['ordered'])
                                                                ? 'Items must be in this order.'
                                                                : 'Items may be in any order.' ?>
                                                        </p>
                                                    <?php endif; ?>

                                                <?php elseif ($type === 'numeric'): ?>
                                                    <p>
                                                        <strong>Expected answer:</strong>
                                                        <?= facultyEscape($key['answer'] ?? '') ?>
                                                        <?= facultyEscape($question['unit'] ?? '') ?>
                                                        · <strong>Absolute tolerance:</strong>
                                                        ±<?= facultyEscape($key['tolerance'] ?? '0') ?>
                                                    </p>

                                                <?php elseif ($type === 'matching'): ?>
                                                    <ol>
                                                        <?php foreach ($items as $index => $left): ?>
                                                            <li>
                                                                <?= facultyEscape($left) ?>
                                                                → <?= facultyEscape($key['matches'][$index] ?? '') ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ol>

                                                <?php elseif ($type === 'essay'): ?>
                                                    <strong>Scoring rubric</strong>

                                                    <ul>
                                                        <?php foreach (($key['rubric'] ?? []) as $criterion): ?>
                                                            <li>
                                                                <?= facultyEscape($criterion['criterion']) ?>
                                                                — <?= (int) $criterion['points'] ?> pts
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ol>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</section>

<!-- CREATE ASSESSMENT MODAL -->

<?php if ($sections): ?>
    <dialog id="faculty-create-assessment-modal" class="faculty-profile-dialog"
        aria-labelledby="faculty-create-assessment-title" aria-describedby="faculty-create-assessment-period">

        <header class="faculty-profile-header">
            <div class="faculty-profile-heading">
                <span class="faculty-profile-heading-icon" aria-hidden="true">
                    <i data-lucide="clipboard-plus"></i>
                </span>

                <div>
                    <h2 id="faculty-create-assessment-title">Create assessment</h2>
                    <p id="faculty-create-assessment-period"></p>
                </div>
            </div>

            <button type="button" class="icon-btn faculty-profile-close" data-close-assessment-editor
                aria-label="Close assessment editor">
                <i data-lucide="x" aria-hidden="true"></i>
            </button>
        </header>

        <form id="assessment-form" class="assessment-form" method="post" action="dashboard.php?page=assessments">

            <input type="hidden" name="action" value="create_assessment">
            <input type="hidden" name="csrf_token" value="<?= facultyEscape($csrfToken) ?>">
            <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
            <input type="hidden" name="period" value="">
            <input type="hidden" name="questions_json" value="">

            <div class="faculty-profile-grid">
                <label class="faculty-profile-field faculty-profile-full">
                    Assigned section
                    <select name="section_id" required>
                        <option value="">Choose a section</option>

                        <?php foreach ($sections as $section): ?>
                            <option value="<?= (int) $section['id'] ?>" <?= (int) $section['id'] === $selectedSectionId ? 'selected' : '' ?>>
                                <?= facultyEscape(
                                    $section['course'] . ' · Year ' . $section['year_level']
                                    . ' · ' . $section['section_name']
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="faculty-profile-field">
                    Assessment type
                    <select name="assessment_type" required>
                        <option value="Exam">Exam</option>
                        <option value="Quiz">Quiz</option>
                    </select>
                </label>

                <label class="faculty-profile-field">
                    Delivery method
                    <select name="delivery_mode" required>
                        <option value="link">External link</option>
                        <option value="internal">Build inside AcadTrack</option>
                    </select>
                </label>

                <label class="faculty-profile-field faculty-profile-full">
                    Assessment title
                    <input type="text" name="title" maxlength="180" placeholder="e.g. Database Fundamentals Quiz" required>
                </label>

                <label class="faculty-profile-field faculty-profile-full">
                    Instructions (optional)
                    <textarea name="instructions" maxlength="5000" rows="3"></textarea>
                </label>
            </div>

            <label class="faculty-profile-field" id="assessment-link-field">
                Assessment link
                <input type="url" name="external_url" maxlength="2048" placeholder="https://..." required>
            </label>

            <div id="assessment-builder" hidden>
                <div id="assessment-questions"></div>

                <button type="button" class="button button-outline" id="assessment-add">
                    + Add question
                </button>
            </div>

            <footer class="faculty-profile-actions assessment-editor-actions">
                <button type="button" class="button button-outline" data-close-assessment-editor>
                    Close
                </button>

                <button type="submit" class="button button-primary">
                    <i data-lucide="save" aria-hidden="true"></i>
                    Save assessment
                </button>
            </footer>
        </form>

        <!-- faculty_dash.js clones this template for each built-in question. -->

        <template id="assessment-question-template">
            <fieldset class="assessment-question">
                <legend></legend>

                <div class="faculty-profile-grid">
                    <label class="faculty-profile-field">
                        Question type
                        <select data-field="type">
                            <?php foreach ($assessmentTypes as $value => $label): ?>
                                <option value="<?= facultyEscape($value) ?>">
                                    <?= facultyEscape($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="faculty-profile-field">
                        Total points
                        <input data-field="points" type="number" min="1" max="1000" step="1" value="1" required>
                    </label>

                    <label class="faculty-profile-field faculty-profile-full">
                        <span data-prompt-label>Question</span>
                        <textarea data-field="prompt" rows="3" maxlength="5000" required></textarea>
                    </label>

                    <div class="faculty-profile-full assessment-type-fields" data-type-fields></div>
                </div>

                <button type="button" class="button button-outline" data-remove>
                    Remove question
                </button>
            </fieldset>
        </template>
    </dialog>
<?php endif; ?>

<!-- SAVED ASSESSMENT DETAILS MODAL -->

<dialog id="saved-assessment-modal" class="faculty-profile-dialog" aria-labelledby="saved-assessment-title"
    aria-describedby="saved-assessment-meta">

    <header class="faculty-profile-header">
        <div class="faculty-profile-heading">
            <span class="faculty-profile-heading-icon" aria-hidden="true">
                <i data-lucide="clipboard-list"></i>
            </span>

            <div>
                <h2 id="saved-assessment-title">Assessment details</h2>
                <p id="saved-assessment-meta"></p>
            </div>
        </div>

        <button type="button" class="icon-btn faculty-profile-close" id="saved-assessment-close"
            aria-label="Close assessment details" autofocus>
            <i data-lucide="x" aria-hidden="true"></i>
        </button>
    </header>

    <div id="saved-assessment-body"></div>
</dialog>

<!-- FACULTY SUBMISSIONS AND GRADING MODAL -->

<dialog id="faculty-submissions-modal" class="faculty-profile-dialog" aria-labelledby="faculty-submissions-title"
    aria-describedby="faculty-submissions-assessment">

    <header class="faculty-profile-header">
        <div class="faculty-profile-heading">
            <span class="faculty-profile-heading-icon" aria-hidden="true">
                <i data-lucide="clipboard-check"></i>
            </span>

            <div>
                <h2 id="faculty-submissions-title">View Submissions</h2>
                <p id="faculty-submissions-assessment"></p>
            </div>
        </div>

        <button type="button" class="icon-btn faculty-profile-close" id="faculty-submissions-close"
            aria-label="Close submissions" autofocus>
            <i data-lucide="x" aria-hidden="true"></i>
        </button>
    </header>

    <iframe id="faculty-submissions-frame" title="Student submissions and grading" src="about:blank"></iframe>
</dialog>