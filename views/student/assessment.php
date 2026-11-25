<?php
declare(strict_types=1);

require_once __DIR__ . '/../../auth/require_role.php';
requireRole(['student']);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/assessment_access.php';
require_once __DIR__ . '/../shared/assessment_grading.php';

function assessmentEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function assessmentUnavailable(): void
{
    http_response_code(404);
    exit('This assessment is unavailable for your section or permit period.');
}

function assessmentTextAnswer($value): string
{
    if (!is_string($value)) {
        throw new DomainException('Enter a written answer.');
    }

    $value = trim($value);

    if ($value === '' || strlen($value) > 32000) {
        throw new DomainException('Enter an answer of up to 32,000 bytes.');
    }

    return $value;
}

function assessmentChoiceIndex($value, int $count): int
{
    if (!is_string($value) || !ctype_digit($value)) {
        throw new DomainException('Select a valid choice.');
    }

    $index = (int) $value;

    if ($index >= $count || (string) $index !== $value) {
        throw new DomainException('Select a valid choice.');
    }

    return $index;
}

/*
 * Prepare only the content needed by students.
 * Legacy matching pairs and ordering questions also contain private ordering
 * information, so their answer banks are sorted before display.
 */
function assessmentStudentQuestions(string $json): array
{
    $stored = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($stored) || !$stored) {
        throw new RuntimeException('Empty assessment.');
    }

    $questions = [];

    foreach ($stored as $row) {
        $type = $row['type'];
        $items = $row['items'] ?? [];

        $question = [
            'type' => $type,
            'prompt' => $row['prompt'],
            'points' => (int) $row['points'],
            'items' => [],
            'bank' => [],
            'unit' => '',
            'hint' => '',
        ];

        switch ($type) {
            case 'single':
            case 'dropdown':
            case 'multiple':
            case 'scale':
                $question['items'] = array_values($items);
                break;

            case 'boolean':
                $question['items'] = ['True', 'False'];
                break;

            case 'matching':
                if (($row['version'] ?? 1) >= 2) {
                    $question['items'] = array_values($items);
                    $bank = $row['key']['matches'];
                } else {
                    $question['items'] = array_column($items, 0);
                    $bank = array_column($items, 1);
                }

                $bank = array_values(array_unique($bank));
                sort($bank, SORT_STRING);
                $question['bank'] = $bank;
                break;

            case 'ordering':
                $bank = array_values($items);
                sort($bank, SORT_STRING);
                $question['bank'] = $bank;
                break;

            case 'numeric':
                $question['unit'] = (string) ($row['unit'] ?? '');
                break;

            case 'enumeration':
                $expectedCount = count($row['key']['answers'] ?? []);
                $question['hint'] = $expectedCount > 0
                    ? "Enter up to {$expectedCount} items, one per line. Extra items receive zero credit."
                    : 'Enter one item per line.';
                if (!empty($row['key']['ordered'])) {
                    $question['hint'] .= ' The order matters.';
                }
                break;
        }

        $questions[] = $question;
    }

    return $questions;
}

function assessmentReadAnswers(array $questions, $posted): array
{
    if (!is_array($posted) || count($posted) !== count($questions)) {
        throw new DomainException('Please answer every question before submitting.');
    }

    $answers = [];

    foreach ($questions as $index => $question) {
        $value = $posted[$index] ?? null;
        $type = $question['type'];

        try {
            if (in_array($type, ['single', 'dropdown', 'boolean', 'scale'], true)) {
                $choice = assessmentChoiceIndex($value, count($question['items']));
                $answer = $question['items'][$choice];

            } elseif ($type === 'multiple') {
                if (!is_array($value) || !$value) {
                    throw new DomainException('Select at least one choice.');
                }

                $selected = [];
                foreach ($value as $choice) {
                    $position = assessmentChoiceIndex($choice, count($question['items']));
                    $selected[$position] = $question['items'][$position];
                }
                $answer = array_values($selected);

            } elseif ($type === 'matching' || $type === 'ordering') {
                $count = $type === 'matching'
                    ? count($question['items'])
                    : count($question['bank']);

                if (!is_array($value) || count($value) !== $count) {
                    throw new DomainException('Complete every selection.');
                }

                $answer = [];
                for ($i = 0; $i < $count; $i++) {
                    $choice = assessmentChoiceIndex($value[$i] ?? null, count($question['bank']));
                    $answer[] = $question['bank'][$choice];
                }

                if ($type === 'ordering' && count(array_unique($answer)) !== $count) {
                    throw new DomainException('Use each item once.');
                }

            } else {
                $answer = assessmentTextAnswer($value);

                if (
                    $type === 'numeric' &&
                    (!is_numeric($answer) || !is_finite((float) $answer))
                ) {
                    throw new DomainException('Enter a valid number.');
                }
            }
        } catch (DomainException $error) {
            throw new DomainException('Question ' . ($index + 1) . ': ' . $error->getMessage());
        }

        $answers[] = ['type' => $type, 'answer' => $answer];
    }

    return $answers;
}

header('Cache-Control: private, no-store');

$studentId = (int) ($_SESSION['user_id'] ?? 0);
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if (!$id || $studentId < 1) {
    assessmentUnavailable();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$isModal = ($_GET['embed'] ?? '') === '1';

$assessmentUrl = 'assessment.php?' . http_build_query(
    $isModal
    ? ['id' => $id, 'embed' => '1']
    : ['id' => $id]
);
$errorMessage = '';
$posted = [];
$questions = [];

if ($isPost) {
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Your session token expired. Reopen the assessment and try again.');
    }
    $posted = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];
}

try {
    if ($isPost) {
        $pdo->beginTransaction();
    }

    $query = $pdo->prepare(
        "SELECT a.id, a.title, a.period, a.assessment_type, a.delivery_mode,
                a.instructions, a.external_url, a.questions_json,
                attempt.id AS attempt_id, attempt.answers_json, attempt.manual_scores_json "
        . studentAssessmentAccessSql()
        . " AND a.id = ?"
        . ($isPost ? " FOR UPDATE" : "")
    );
    $query->execute([$studentId, $id]);
    $assessment = $query->fetch(PDO::FETCH_ASSOC);

    if (!$assessment) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        assessmentUnavailable();
    }

    $submitted = $assessment['attempt_id'] !== null;
    $studentGrade = $submitted ? assessmentGradeReport($assessment) : null;
    $internal = $assessment['delivery_mode'] === 'internal';

    if ($internal && !$submitted) {
        $questions = assessmentStudentQuestions($assessment['questions_json']);
    }

    if ($isPost) {
        if (!$internal) {
            throw new DomainException('Use the external assessment link to submit your answers.');
        }

        if (!$submitted) {
            $answers = assessmentReadAnswers($questions, $posted);
            $save = $pdo->prepare(
                "INSERT INTO assessment_attempts (assessment_id, student_id, answers_json)
                 VALUES (?, ?, ?)"
            );
            $save->execute([
                $id,
                $studentId,
                json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }

        $pdo->commit();
        header('Location: ' . $assessmentUrl, true, 303);
        exit;
    }
} catch (DomainException $error) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    $errorMessage = $error->getMessage();
} catch (Throwable $error) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    error_log('AcadTrack student assessment: ' . $error->getMessage());
    http_response_code(500);
    exit('Unable to load or save the assessment. Please try again.');
}

$labels = [
    'single' => 'Multiple choice',
    'multiple' => 'Multiple response',
    'dropdown' => 'Dropdown',
    'boolean' => 'True or false',
    'short' => 'Identification / short answer',
    'numeric' => 'Numerical answer',
    'matching' => 'Matching',
    'enumeration' => 'Enumeration',
    'essay' => 'Essay / problem solving',
    'ordering' => 'Ordering',
    'blank' => 'Fill in the blanks',
    'scale' => 'Rating scale',
    'case' => 'Case analysis',
    'code' => 'Code response',
    'url' => 'Project link',
    'custom' => 'Written response',
];
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= assessmentEscape($assessment['title']) ?> | AcadTrack</title>
    <link rel="stylesheet" href="../../style/student/student_dash.css">
    <script src="../../js/auth/session_guard.js" defer></script>
</head>

<body class="<?= $isModal ? 'assessment-embedded' : '' ?>">
    <main class="page student-assessment-page">

        <?php if (!$isModal): ?>
            <a class="button button-outline" href="dashboard.php?page=assessments">
                ← Exam and Quiz
            </a>
        <?php endif; ?>

        <header>
            <p class="overline">
                <?= assessmentEscape($assessment['period']) ?>
                · <?= assessmentEscape($assessment['assessment_type']) ?>
            </p>
            <h1><?= assessmentEscape($assessment['title']) ?></h1>

            <?php if ($assessment['instructions'] !== ''): ?>
                <p class="assessment-lines"><?= assessmentEscape($assessment['instructions']) ?></p>
            <?php endif; ?>
        </header>

        <?php if ($errorMessage !== ''): ?>
            <p class="permit-alert permit-alert-error" role="alert">
                <?= assessmentEscape($errorMessage) ?>
            </p>
        <?php endif; ?>

        <?php if (!$internal): ?>
            <section class="permit-period-card">
                <p>Complete this assessment on the teacher’s linked website.</p>
                <a class="button button-primary" href="<?= assessmentEscape($assessment['external_url']) ?>" target="_blank"
                    rel="noopener noreferrer">
                    Open external <?= assessmentEscape(strtolower($assessment['assessment_type'])) ?>
                </a>
            </section>

        <?php elseif ($submitted): ?>
            <section class="permit-period-card" aria-labelledby="assessment-result-title">
                <h2 id="assessment-result-title">
                    <?= $studentGrade['pending'] ? 'Score so far' : 'Final score' ?>
                </h2>

                <?php if ($studentGrade['pending']): ?>
                    <?php if ($studentGrade['marked_possible'] > 0): ?>
                        <p>
                            <strong>
                                <?= gradeNumber($studentGrade['earned']) ?> /
                                <?= gradeNumber($studentGrade['marked_possible']) ?>
                            </strong>
                            points on graded questions.
                        </p>
                    <?php endif; ?>
                    <p>
                        Your teacher still has <?= (int) $studentGrade['pending'] ?>
                        question(s) to review. The full assessment is worth
                        <?= gradeNumber($studentGrade['possible']) ?> points.
                    </p>
                <?php else: ?>
                    <p>
                        <strong>
                            <?= gradeNumber($studentGrade['earned']) ?> /
                            <?= gradeNumber($studentGrade['possible']) ?>
                        </strong>
                        points
                    </p>
                <?php endif; ?>

                <ol>
                    <?php foreach ($studentGrade['rows'] as $index => $row): ?>
                        <li>
                            Question <?= $index + 1 ?> —
                            <?php if ($row['score'] === null): ?>
                                Awaiting teacher review
                            <?php else: ?>
                                <?= gradeNumber($row['score']) ?> /
                                <?= gradeNumber($row['question']['points']) ?> pts
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>

        <?php else: ?>
            <form method="post" action="<?= assessmentEscape($assessmentUrl) ?>" class="student-assessment-form">

                <input type="hidden" name="csrf_token" value="<?= assessmentEscape($_SESSION['csrf_token']) ?>">

                <?php foreach ($questions as $i => $q): ?>
                    <?php
                    $type = $q['type'];
                    $name = "answers[{$i}]";
                    $old = $posted[$i] ?? '';
                    $oldText = is_string($old) ? $old : '';
                    $oldList = is_array($old) ? $old : [];
                    ?>

                    <fieldset class="permit-period-card">
                        <legend>Question <?= $i + 1 ?> · <?= $q['points'] ?> pt(s)</legend>

                        <strong><?= assessmentEscape($labels[$type] ?? 'Written response') ?></strong>
                        <p class="assessment-lines"><?= assessmentEscape($q['prompt']) ?></p>

                        <?php if (in_array($type, ['single', 'boolean', 'multiple'], true)): ?>
                            <?php if ($type === 'multiple'): ?>
                                <small>Select all that apply.</small>
                            <?php endif; ?>

                            <?php foreach ($q['items'] as $n => $item): ?>
                                <label class="assessment-choice">
                                    <?php if ($type === 'multiple'): ?>
                                        <input type="checkbox" name="<?= $name ?>[]" value="<?= $n ?>" <?= in_array((string) $n, $oldList, true) ? 'checked' : '' ?>>
                                    <?php else: ?>
                                        <input type="radio" name="<?= $name ?>" value="<?= $n ?>" required <?= $oldText === (string) $n ? 'checked' : '' ?>>
                                    <?php endif; ?>
                                    <?= assessmentEscape($item) ?>
                                </label>
                            <?php endforeach; ?>

                        <?php elseif (in_array($type, ['dropdown', 'scale'], true)): ?>
                            <label class="student-profile-field">
                                Your selection
                                <select name="<?= $name ?>" required>
                                    <option value="">Choose an option</option>
                                    <?php foreach ($q['items'] as $n => $item): ?>
                                        <option value="<?= $n ?>" <?= $oldText === (string) $n ? 'selected' : '' ?>>
                                            <?= assessmentEscape($item) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                        <?php elseif ($type === 'matching' || $type === 'ordering'): ?>
                            <?php $count = $type === 'matching' ? count($q['items']) : count($q['bank']); ?>
                            <?php for ($j = 0; $j < $count; $j++): ?>
                                <label class="student-profile-field">
                                    <?= $type === 'matching'
                                        ? assessmentEscape($q['items'][$j])
                                        : 'Position ' . ($j + 1) ?>
                                    <select name="<?= $name ?>[<?= $j ?>]" required>
                                        <option value="">Choose an item</option>
                                        <?php foreach ($q['bank'] as $n => $item): ?>
                                            <option value="<?= $n ?>" <?= ($oldList[$j] ?? '') === (string) $n ? 'selected' : '' ?>>
                                                <?= assessmentEscape($item) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            <?php endfor; ?>

                        <?php elseif ($type === 'numeric'): ?>
                            <label class="student-profile-field">
                                Your answer <?= $q['unit'] !== '' ? '(' . assessmentEscape($q['unit']) . ')' : '' ?>
                                <input type="number" step="any" name="<?= $name ?>" value="<?= assessmentEscape($oldText) ?>"
                                    required>
                            </label>

                        <?php else: ?>
                            <label class="student-profile-field">
                                Your answer
                                <?php if ($q['hint'] !== ''): ?>
                                    <small><?= assessmentEscape($q['hint']) ?></small>
                                <?php endif; ?>
                                <textarea name="<?= $name ?>" rows="<?= $type === 'essay' ? 7 : 3 ?>"
                                    required><?= assessmentEscape($oldText) ?></textarea>
                            </label>
                        <?php endif; ?>
                    </fieldset>
                <?php endforeach; ?>

                <p>Review your answers before submitting. You have one submission for this assessment.</p>
                <button type="submit" class="button button-primary">Submit answers</button>
            </form>
        <?php endif; ?>
    </main>

    <?php if ($isModal && $internal && $submitted): ?>
        <script>
            window.parent.postMessage(
                {
                    type: "acadtrack-assessment-submitted",
                    assessmentId: <?= (int) $id ?>
                },
                window.location.origin
            );
        </script>
    <?php endif; ?>
</body>

</html>