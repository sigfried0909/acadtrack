<?php
declare(strict_types=1);

require_once __DIR__ . '/../../auth/require_role.php';
requireRole(['faculty']);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../shared/assessment_grading.php';

header('Cache-Control: private, no-store');

$facultyId = (int) ($_SESSION['user_id'] ?? 0);
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$attemptId = filter_var($_GET['attempt'] ?? 0, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0],
]);

if (!$id || $attemptId === false || !$facultyId) {
    http_response_code(404);
    exit('Assessment unavailable.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$isModal = ($_GET['embed'] ?? '') === '1';

$listParameters = ['id' => (int) $id];

if ($isModal) {
    $listParameters['embed'] = '1';
}

$baseUrl = 'assessment_submissions.php?' . http_build_query($listParameters);

$reviewUrl = $attemptId
    ? $baseUrl . '&attempt=' . (int) $attemptId
    : $baseUrl;
$errorMessage = '';
$attempt = null;
$attempts = [];
$report = null;

if ($isPost) {
    $token = $_POST['csrf_token'] ?? null;
    if (!$attemptId || !is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Invalid grading request. Reopen the submission.');
    }
}

try {
    if ($isPost) {
        $pdo->beginTransaction();
    }

    $access = $pdo->prepare(
        "SELECT a.id, a.title, a.period, a.assessment_type, a.questions_json,
                s.section_name
         FROM faculty_assessments a
         JOIN sections s ON s.id = a.section_id
         JOIN users f ON f.id = s.ssp_adviser_id
         WHERE a.id = ? AND a.delivery_mode = 'internal'
           AND f.id = ? AND f.role = 'faculty' AND f.is_active = 1"
        . ($isPost ? ' FOR UPDATE' : '')
    );
    $access->execute([$id, $facultyId]);
    $assessment = $access->fetch(PDO::FETCH_ASSOC);

    if (!$assessment) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        http_response_code(404);
        exit('Assessment unavailable for your assigned sections.');
    }

    if ($attemptId) {
        $query = $pdo->prepare(
            "SELECT t.*, u.nickname, u.student_number
             FROM assessment_attempts t
             JOIN users u ON u.id = t.student_id
             WHERE t.assessment_id = ? AND t.id = ?"
            . ($isPost ? ' FOR UPDATE' : '')
        );
        $query->execute([$id, $attemptId]);
        $attempt = $query->fetch(PDO::FETCH_ASSOC);

        if (!$attempt) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            http_response_code(404);
            exit('Submission unavailable.');
        }

        $attempt['questions_json'] = $assessment['questions_json'];
        $report = assessmentGradeReport($attempt);

        if ($isPost) {
            $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT);
            if ($revision === false || $revision !== (int) $attempt['grading_revision']) {
                throw new DomainException(
                    'This submission was updated in another tab. Review the latest marks below before saving.'
                );
            }

            $posted = $_POST['scores'] ?? [];
            if (!is_array($posted)) {
                throw new DomainException('Invalid score form.');
            }

            $scores = [];
            foreach ($report['rows'] as $index => $row) {
                if (!$row['manual'])
                    continue;

                if (!array_key_exists($index, $posted) || !is_string($posted[$index])) {
                    throw new DomainException('The grading form was incomplete. Reload and try again.');
                }

                $raw = trim($posted[$index]);
                if ($raw === '')
                    continue; // Blank means pending; zero is an awarded score.

                if (
                    !preg_match('/\A\d+(?:\.\d{1,2})?\z/', $raw)
                    || (float) $raw > (float) $row['question']['points']
                ) {
                    throw new DomainException(
                        'Question ' . ($index + 1) . ' needs a score between 0 and '
                        . $row['question']['points'] . ', with up to two decimal places.'
                    );
                }

                $scores[$index] = (float) $raw;
            }

            $save = $pdo->prepare(
                "UPDATE assessment_attempts
                 SET manual_scores_json = ?, graded_by = ?, graded_at = NOW(),
                     grading_revision = grading_revision + 1
                 WHERE id = ? AND assessment_id = ?"
            );
            $save->execute([
                json_encode((object) $scores, JSON_THROW_ON_ERROR),
                $facultyId,
                $attemptId,
                $id,
            ]);

            $pdo->commit();
            $_SESSION['assessment_grading_notice'] = 'Scores saved.';
            header('Location: ' . $reviewUrl, true, 303);
            exit;
        }
    } else {
        $query = $pdo->prepare(
            "SELECT t.id, t.answers_json, t.manual_scores_json, t.submitted_at,
                    u.nickname, u.student_number
             FROM assessment_attempts t
             JOIN users u ON u.id = t.student_id
             WHERE t.assessment_id = ?
             ORDER BY t.submitted_at DESC, t.id DESC"
        );
        $query->execute([$id]);

        while ($entry = $query->fetch(PDO::FETCH_ASSOC)) {
            $entry['questions_json'] = $assessment['questions_json'];
            $summary = assessmentGradeReport($entry);
            $attempts[] = [
                'id' => $entry['id'],
                'nickname' => $entry['nickname'],
                'student_number' => $entry['student_number'],
                'submitted_at' => $entry['submitted_at'],
                'earned' => $summary['earned'],
                'possible' => $summary['possible'],
                'marked_possible' => $summary['marked_possible'],
                'pending' => $summary['pending'],
            ];
        }
    }
} catch (DomainException $error) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    $errorMessage = $error->getMessage();
} catch (Throwable $error) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    error_log('AcadTrack faculty grading: ' . $error->getMessage());
    http_response_code(500);
    exit('Unable to load or save the submission. Please try again.');
}

$notice = $_SESSION['assessment_grading_notice'] ?? '';
unset($_SESSION['assessment_grading_notice']);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AcadTrack | Assessment submissions</title>
    <link rel="stylesheet" href="../../style/faculty/faculty_dash.css">
    <script src="../../js/auth/session_guard.js" defer></script>
    <style>
        .grading-page {
            width: min(100%, 1040px);
            margin: 0 auto;
            padding: 24px;
            color: var(--text, #403b54);
            font: 14px/1.55 Outfit, system-ui, sans-serif;
        }

        .grading-page,
        .grading-page * {
            box-sizing: border-box;
        }

        body.faculty-grading-embedded .grading-page {
            padding: 16px 20px 24px;
        }

        .grading-page>p {
            margin: 10px 0 16px;
        }

        .grading-page :is(h1, h2, h3, h4, p) {
            overflow-wrap: anywhere;
        }

        .grading-page .review-roster,
        .grading-page .review-question,
        .grading-page .review-attempt-head {
            margin: 0 0 16px;
            padding: 0;
            border: 1px solid var(--line, #e8e4ef);
            border-radius: 14px;
            background: #fff;
        }

        .grading-page .review-section-head,
        .grading-page .review-question-head,
        .grading-page .review-attempt-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 20px;
        }

        .grading-page .review-section-head {
            border-bottom: 1px solid var(--line, #e8e4ef);
        }

        .grading-page .review-section-head h2,
        .grading-page .review-attempt-identity h2 {
            margin: 0;
            color: var(--ink, #211b35);
            font-size: 20px;
            line-height: 1.3;
        }

        .grading-page .review-section-head p,
        .grading-page .review-attempt-identity p,
        .grading-page .review-student span,
        .grading-page .review-roster-score>span:last-child:not(.review-status),
        .grading-page .review-question-head>div>span,
        .grading-page .review-total>span:last-child {
            margin: 4px 0 0;
            color: #676174;
            font-size: 12px;
        }

        .grading-page .review-roster-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .grading-page .review-roster-row {
            display: grid;
            grid-template-columns: minmax(120px, 1.2fr) minmax(125px, 1fr) minmax(110px, .95fr) auto;
            align-items: center;
            gap: 14px;
            padding: 15px 20px;
        }

        .grading-page .review-roster-row+.review-roster-row {
            border-top: 1px solid var(--line, #e8e4ef);
        }

        .grading-page .review-student,
        .grading-page .review-roster-date,
        .grading-page .review-roster-score,
        .grading-page .review-total {
            display: grid;
            justify-items: start;
            gap: 3px;
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .grading-page .review-label,
        .grading-page .review-mark>label,
        .grading-page .review-answer h4 {
            display: block;
            margin: 0 0 5px;
            color: #5d556d;
            font-size: 12px;
            font-weight: 650;
        }

        .grading-page .review-status {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            padding: 4px 9px;
            border: 1px solid transparent;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 650;
            white-space: nowrap;
        }

        .grading-page .is-graded {
            border-color: #c7ebd6;
            background: #edf9f1;
            color: #176d42;
        }

        .grading-page .is-pending {
            border-color: #efdbac;
            background: #fff8e9;
            color: #805712;
        }

        .grading-page .review-empty {
            padding: 30px 20px;
            text-align: center;
        }

        .grading-page .review-empty p {
            margin: 6px 0 0;
        }

        .grading-page .review-attempt-head {
            background: linear-gradient(110deg, #fff 55%, #f6f2ff);
        }

        .grading-page .review-total {
            padding-left: 18px;
            border-left: 2px solid var(--primary-border, #e5dafa);
        }

        .grading-page .review-total>strong,
        .grading-page .review-auto-score {
            color: var(--ink, #211b35);
            font-size: 22px;
            line-height: 1.25;
            font-variant-numeric: tabular-nums;
        }

        .grading-page .review-total small,
        .grading-page .review-auto-score small {
            color: #676174;
            font-size: 14px;
            font-weight: 500;
        }

        .grading-page .review-question-head {
            padding-bottom: 4px;
        }

        .grading-page .review-question-head h3 {
            margin: 0;
            color: var(--ink, #211b35);
            font-size: 16px;
        }

        .grading-page .review-prompt {
            margin: 10px 20px 16px;
            white-space: pre-wrap;
        }

        .grading-page .review-question-body {
            display: grid;
            grid-template-columns: minmax(0, 1.65fr) minmax(190px, 1fr);
            gap: 16px;
            padding: 0 20px 20px;
        }

        .grading-page .review-answer,
        .grading-page .review-mark {
            min-width: 0;
            padding: 14px;
            border-radius: 10px;
        }

        .grading-page .review-answer {
            border: 1px solid #eeebf3;
            background: #faf9fc;
        }

        .grading-page .review-mark {
            align-self: start;
            border: 1px solid #e7def5;
            background: #fcfaff;
        }

        .grading-page .review-answer-text {
            margin: 6px 0 0;
            line-height: 1.7;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .grading-page .review-answer-list {
            margin: 6px 0 0;
            padding-left: 22px;
            overflow-wrap: anywhere;
        }

        .grading-page .review-answer-list li+li {
            margin-top: 5px;
        }

        .grading-page .review-score-input {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .grading-page .review-score-input input {
            width: 95px;
            min-height: 40px;
            padding: 8px;
            border: 1px solid #cdbce7;
            border-radius: 8px;
            background: #fff;
            color: var(--ink, #211b35);
            font: inherit;
        }

        .grading-page .review-rubric {
            margin-top: 14px;
            padding-top: 10px;
            border-top: 1px solid #e8e0f2;
            font-size: 12px;
        }

        .grading-page .review-rubric summary {
            color: var(--primary-hover, #5b32bd);
            font-weight: 650;
            cursor: pointer;
        }

        .grading-page .review-rubric ul {
            display: grid;
            gap: 7px;
            margin: 10px 0 0;
            padding: 0;
            list-style: none;
        }

        .grading-page .review-rubric li {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
        }

        .grading-page .review-rubric li span {
            overflow-wrap: anywhere;
        }

        .grading-page .review-rubric li strong {
            flex-shrink: 0;
        }

        .grading-page .review-save-bar {
            position: sticky;
            bottom: 0;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 12px 16px;
            border: 1px solid var(--line, #e8e4ef);
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 -4px 16px rgba(35, 24, 66, .06);
        }

        .grading-page .review-save-bar>span {
            color: #676174;
            font-size: 12px;
        }

        .grading-page .review-save-bar button {
            flex-shrink: 0;
        }

        @media (max-width: 680px) {

            .grading-page,
            body.faculty-grading-embedded .grading-page {
                padding: 12px;
            }

            .grading-page .review-roster-row {
                grid-template-columns: minmax(0, 1fr) auto;
                padding: 14px;
            }

            .grading-page .review-roster-date {
                grid-column: 1;
                grid-row: auto;
            }

            .grading-page .review-roster-score {
                grid-column: 2;
                grid-row: 1 / span 2;
            }

            .grading-page .review-open {
                grid-column: 1 / -1;
            }

            .grading-page .review-attempt-head {
                align-items: stretch;
                flex-direction: column;
            }

            .grading-page .review-total {
                padding: 10px 0 0;
                border-left: 0;
                border-top: 1px solid var(--primary-border, #e5dafa);
            }

            .grading-page .review-question-body {
                grid-template-columns: minmax(0, 1fr);
                gap: 10px;
                padding: 0 14px 14px;
            }

            .grading-page .review-question-head,
            .grading-page .review-section-head {
                padding: 14px;
            }

            .grading-page .review-prompt {
                margin: 0 14px 14px;
            }
        }

        @media (max-width: 420px) {
            .grading-page .review-save-bar {
                position: static;
                align-items: stretch;
                flex-direction: column;
            }
        }
    </style>
</head>

<body class="<?= $isModal ? 'faculty-grading-embedded' : '' ?>">
    <main class="grading-page">

        <?php if ($attempt): ?>
            <a class="button button-outline" href="<?= gradeHtml($baseUrl) ?>">
                ← All submissions
            </a>
        <?php elseif (!$isModal): ?>
            <a class="button button-outline" href="dashboard.php?page=assessments">
                ← Exam and Quiz
            </a>
        <?php endif; ?>

        <?php if (!$isModal): ?>
            <h1><?= gradeHtml($assessment['title']) ?></h1>
        <?php endif; ?>

        <p>
            <?= gradeHtml($assessment['section_name']) ?>
            · <?= gradeHtml($assessment['period']) ?>
            · <?= gradeHtml($assessment['assessment_type']) ?>
        </p>

        <?php if ($notice): ?>
            <p role="status">
                <?= gradeHtml($notice) ?>
            </p>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <p class="admin-alert admin-alert-error" role="alert">
                <?= gradeHtml($errorMessage) ?>
            </p>
        <?php endif; ?>

        <?php if (!$attempt): ?>
            <?php
            $submissionCount = count($attempts);
            $needsReview = count(array_filter(
                $attempts,
                static fn(array $entry): bool => $entry['pending'] > 0
            ));
            ?>

            <section class="review-roster" aria-labelledby="review-roster-title">
                <header class="review-section-head">
                    <div>
                        <h2 id="review-roster-title">Student submissions</h2>
                        <p>
                            <?= $submissionCount ?> submitted
                            <?php if ($submissionCount > 0): ?>
                                · <?= $submissionCount - $needsReview ?> graded
                                · <?= $needsReview ?> awaiting review
                            <?php endif; ?>
                        </p>
                    </div>
                </header>

                <?php if (!$attempts): ?>
                    <div class="review-empty">
                        <strong>No submissions yet</strong>
                        <p>Students will appear here after they submit this assessment.</p>
                    </div>
                <?php else: ?>
                    <ul class="review-roster-list">
                        <?php foreach ($attempts as $entry): ?>
                            <?php
                            $pending = (int) $entry['pending'];
                            $partlyGraded = $pending > 0 && $entry['marked_possible'] > 0;

                            $stateLabel = $pending === 0
                                ? 'Graded'
                                : ($partlyGraded ? 'Partially graded' : 'Awaiting review');

                            $studentLabel = trim((string) $entry['nickname']);
                            $studentNumber = trim((string) $entry['student_number']);
                            ?>

                            <li class="review-roster-row">
                                <div class="review-student">
                                    <strong><?= gradeHtml($studentLabel) ?></strong>
                                    <?php if ($studentNumber !== ''): ?>
                                        <span><?= gradeHtml($studentNumber) ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="review-roster-date">
                                    <span class="review-label">Submitted</span>
                                    <span><?= gradeHtml($entry['submitted_at']) ?></span>
                                </div>

                                <div class="review-roster-score">
                                    <span class="review-status <?= $pending ? 'is-pending' : 'is-graded' ?>">
                                        <?= gradeHtml($stateLabel) ?>
                                    </span>

                                    <?php if ($pending === 0): ?>
                                        <strong>
                                            <?= gradeNumber($entry['earned']) ?> /
                                            <?= gradeNumber($entry['possible']) ?>
                                        </strong>
                                    <?php elseif ($partlyGraded): ?>
                                        <span><?= gradeNumber($entry['earned']) ?> pts awarded so far</span>
                                    <?php endif; ?>
                                </div>

                                <a class="button button-outline review-open"
                                    href="<?= gradeHtml($baseUrl) ?>&amp;attempt=<?= (int) $entry['id'] ?>"
                                    aria-label="Review answers from <?= gradeHtml($studentLabel) ?>">
                                    Review answers
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

        <?php else: ?>

            <?php
            $questionLabels = [
                'single' => 'Multiple choice',
                'multiple' => 'Multiple response',
                'boolean' => 'True or false',
                'short' => 'Identification',
                'numeric' => 'Numerical answer',
                'matching' => 'Matching',
                'enumeration' => 'Enumeration',
                'essay' => 'Essay / problem solving',
                'dropdown' => 'Dropdown',
                'blank' => 'Fill in the blanks',
                'ordering' => 'Ordering',
                'scale' => 'Rating scale',
                'case' => 'Case analysis',
                'code' => 'Code response',
                'url' => 'Project link',
                'custom' => 'Written response',
            ];

            $pendingCount = (int) $report['pending'];
            $hasManual = false;
            $studentNumber = trim((string) $attempt['student_number']);
            ?>

            <header class="review-attempt-head">
                <div class="review-attempt-identity">
                    <h2><?= gradeHtml($attempt['nickname']) ?></h2>
                    <?php if ($studentNumber !== ''): ?>
                        <p><?= gradeHtml($studentNumber) ?></p>
                    <?php endif; ?>
                    <p>Submitted <?= gradeHtml($attempt['submitted_at']) ?></p>
                </div>

                <div class="review-total">
                    <span class="review-label">
                        <?= $pendingCount ? 'Points awarded so far' : 'Final score' ?>
                    </span>

                    <?php if ($pendingCount && $report['marked_possible'] == 0): ?>
                        <strong>Awaiting review</strong>
                    <?php else: ?>
                        <strong>
                            <?= gradeNumber($report['earned']) ?>
                            <small>/ <?= gradeNumber(
                                $pendingCount ? $report['marked_possible'] : $report['possible']
                            ) ?></small>
                        </strong>
                    <?php endif; ?>

                    <span>
                        <?php if ($pendingCount): ?>
                            <?= $pendingCount ?>         <?= $pendingCount === 1 ? 'question' : 'questions' ?> to review
                            · <?= gradeNumber($report['possible']) ?> pts total
                        <?php else: ?>
                            All questions graded
                        <?php endif; ?>
                    </span>
                </div>
            </header>

            <form method="post" action="<?= gradeHtml($reviewUrl) ?>" id="faculty-grading-form">
                <input type="hidden" name="csrf_token" value="<?= gradeHtml($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="revision" value="<?= (int) $attempt['grading_revision'] ?>">

                <?php foreach ($report['rows'] as $index => $row): ?>
                    <?php
                    $q = $row['question'];
                    $manual = (bool) $row['manual'];
                    $hasManual = $hasManual || $manual;
                    $score = $row['score'];
                    $questionNumber = $index + 1;
                    ?>

                    <section class="review-question" aria-labelledby="review-question-<?= $questionNumber ?>">
                        <header class="review-question-head">
                            <div>
                                <h3 id="review-question-<?= $questionNumber ?>">
                                    Question <?= $questionNumber ?>
                                </h3>
                                <span><?= gradeHtml($questionLabels[$q['type']] ?? 'Question') ?></span>
                            </div>

                            <span class="review-status <?= $score === null ? 'is-pending' : 'is-graded' ?>">
                                <?= $manual
                                    ? ($score === null ? 'Needs grading' : 'Teacher graded')
                                    : 'Auto-scored' ?>
                            </span>
                        </header>

                        <p class="review-prompt"><?= gradeHtml($q['prompt']) ?></p>

                        <div class="review-question-body">
                            <div class="review-answer">
                                <h4>Student answer</h4>

                                <?php if (is_array($row['answer'])): ?>
                                    <ol class="review-answer-list">
                                        <?php foreach ($row['answer'] as $position => $part): ?>
                                            <li>
                                                <?php if ($q['type'] === 'matching'): ?>
                                                    <?php
                                                    $left = $q['items'][$position] ?? '';
                                                    if (is_array($left)) {
                                                        $left = $left[0] ?? '';
                                                    }
                                                    ?>
                                                    <strong><?= gradeHtml($left) ?></strong> →
                                                <?php endif; ?>
                                                <?= gradeHtml($part) ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ol>
                                <?php else: ?>
                                    <p class="review-answer-text"><?= gradeHtml($row['answer']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="review-mark">
                                <?php if ($manual): ?>
                                    <label for="review-score-<?= $index ?>">
                                        Points awarded
                                    </label>

                                    <div class="review-score-input">
                                        <input id="review-score-<?= $index ?>" type="number" name="scores[<?= $index ?>]" min="0"
                                            max="<?= (int) $q['points'] ?>" step="0.01" inputmode="decimal"
                                            value="<?= $score === null ? '' : gradeNumber($score) ?>" placeholder="Pending"
                                            aria-label="Points awarded for question <?= $questionNumber ?>"
                                            aria-describedby="review-limit-<?= $index ?>">
                                        <span id="review-limit-<?= $index ?>">
                                            / <?= gradeNumber($q['points']) ?> pts
                                        </span>
                                    </div>

                                    <?php if (!empty($q['key']['rubric'])): ?>
                                        <details class="review-rubric" open>
                                            <summary>Scoring rubric</summary>
                                            <ul>
                                                <?php foreach ($q['key']['rubric'] as $criterion): ?>
                                                    <li>
                                                        <span><?= gradeHtml($criterion['criterion']) ?></span>
                                                        <strong><?= gradeNumber($criterion['points']) ?> pts</strong>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </details>
                                    <?php elseif (!empty($q['guide'])): ?>
                                        <details class="review-rubric" open>
                                            <summary>Marking guide</summary>
                                            <p class="review-answer-text"><?= gradeHtml($q['guide']) ?></p>
                                        </details>
                                    <?php endif; ?>

                                <?php else: ?>
                                    <span class="review-label">Automatic score</span>
                                    <strong class="review-auto-score">
                                        <?= gradeNumber($score) ?>
                                        <small>/ <?= gradeNumber($q['points']) ?> pts</small>
                                    </strong>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>
                <?php endforeach; ?>

                <?php if ($hasManual): ?>
                    <footer class="review-save-bar">
                        <span>Leave an item blank to grade it later. Enter 0 to award zero points.</span>
                        <button class="button button-primary" type="submit">Save scores</button>
                    </footer>
                <?php endif; ?>
            </form>

        <?php endif; ?>
    </main>
    <script>
        (() => {
            "use strict";

            const form = document.getElementById("faculty-grading-form");
            if (!form) return;

            const scoreInputs = [...form.querySelectorAll('input[name^="scores["]')];

            function hasUnsavedScores() {
                return scoreInputs.some(input => input.value !== input.defaultValue);
            }

            // The dashboard modal checks this before closing.
            window.facultyHasUnsavedScores = hasUnsavedScores;

            // Also protect edits when the teacher returns to the submission list.
            const backLink = document.querySelector(".grading-page > a");

            backLink?.addEventListener("click", event => {
                if (
                    hasUnsavedScores() &&
                    !window.confirm("Return to all submissions and discard your unsaved scores?")
                ) {
                    event.preventDefault();
                }
            });
        })();
    </script>
</body>

</html>