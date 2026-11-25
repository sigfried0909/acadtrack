<?php
declare(strict_types=1);

function facultyQuestionTypes(): array
{
    return [
        'single' => 'Multiple choice',
        'multiple' => 'Multiple response',
        'boolean' => 'True or false',
        'short' => 'Identification / short answer',
        'numeric' => 'Numerical answer',
        'matching' => 'Matching',
        'enumeration' => 'Enumeration',
        'essay' => 'Essay / problem solving',
    ];
}

function facultyAssessmentText(
    $value,
    string $label,
    int $limit,
    bool $required = true
): string {
    if (!is_string($value)) {
        throw new DomainException("Invalid {$label}.");
    }

    $value = trim($value);

    if ($required && $value === '') {
        throw new DomainException("Please enter {$label}.");
    }

    if (facultyTextLength($value) > $limit) {
        throw new DomainException("The {$label} exceeds {$limit} characters.");
    }

    return $value;
}

function facultyAssessmentPoints($value, string $label): int
{
    if (!is_int($value) && !is_string($value)) {
        throw new DomainException("{$label} must be a whole number.");
    }

    $points = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 1000],
    ]);

    if ($points === false) {
        throw new DomainException("{$label} must be between 1 and 1,000.");
    }

    return (int) $points;
}

function facultyAssessmentNumber($value, string $label): string
{
    $value = facultyAssessmentText($value, $label, 60);

    if (!is_numeric($value) || !is_finite((float) $value)) {
        throw new DomainException("Enter a finite number for {$label}.");
    }

    return $value;
}

function facultyAssessmentDistinct(array $values, string $label): void
{
    $normalized = [];

    foreach ($values as $value) {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $normalized[] = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    if (count(array_unique($normalized)) !== count($normalized)) {
        throw new DomainException("{$label} contains a duplicate entry.");
    }
}

function facultyAssessmentRows($rows, int $minimum, string $label): array
{
    if (!is_array($rows) || count($rows) < $minimum || count($rows) > 50) {
        throw new DomainException("{$label} requires {$minimum} to 50 entries.");
    }

    return array_values($rows);
}

function facultyAssessmentQuestions($raw): array
{
    if (!is_string($raw) || strlen($raw) > 500000) {
        throw new DomainException('The question list is missing or too large.');
    }

    $rows = json_decode($raw, true);

    if (!is_array($rows) || count($rows) < 1 || count($rows) > 100) {
        throw new DomainException('Add between 1 and 100 questions.');
    }

    $types = facultyQuestionTypes();
    $questions = [];

    foreach ($rows as $row) {
        $number = count($questions) + 1;
        $label = "Question {$number}";

        if (!is_array($row)) {
            throw new DomainException("{$label} is invalid.");
        }

        $type = $row['type'] ?? null;

        if (!is_string($type) || !isset($types[$type])) {
            throw new DomainException("Select a valid type for {$label}.");
        }

        $question = [
            'version' => 2,
            'type' => $type,
            'prompt' => facultyAssessmentText($row['prompt'] ?? '', "{$label} prompt", 5000),
            'points' => facultyAssessmentPoints($row['points'] ?? null, "{$label} points"),
            'items' => [],
            'key' => [],
        ];

        switch ($type) {
            case 'single':
            case 'multiple':
                $minimum = $type === 'single' ? 2 : 3;
                $choices = facultyAssessmentRows($row['choices'] ?? null, $minimum, "{$label} choices");
                $correct = [];

                foreach ($choices as $index => $choice) {
                    if (!is_array($choice) || !is_bool($choice['correct'] ?? null)) {
                        throw new DomainException("{$label} has an invalid choice.");
                    }

                    $question['items'][] = facultyAssessmentText(
                        $choice['text'] ?? '',
                        "{$label} choice " . ($index + 1),
                        1000
                    );

                    if ($choice['correct']) {
                        $correct[] = $index;
                    }
                }

                facultyAssessmentDistinct($question['items'], "{$label} choices");

                if ($type === 'single' && count($correct) !== 1) {
                    throw new DomainException("{$label} needs exactly one correct choice.");
                }

                if (
                    $type === 'multiple' &&
                    (count($correct) < 2 || count($correct) === count($choices))
                ) {
                    throw new DomainException(
                        "{$label} needs at least two correct choices and one incorrect choice."
                    );
                }

                $question['key'] = ['correct_indexes' => $correct];
                break;

            case 'boolean':
                $answer = $row['answer'] ?? '';

                if (!in_array($answer, ['True', 'False'], true)) {
                    throw new DomainException("Choose True or False for {$label}.");
                }

                $question['items'] = ['True', 'False'];
                $question['key'] = ['answer' => $answer];
                break;

            case 'short':
            case 'enumeration':
                $minimum = $type === 'short' ? 1 : 2;
                $answers = facultyAssessmentRows($row['answers'] ?? null, $minimum, "{$label} answers");

                foreach ($answers as &$answer) {
                    $answer = facultyAssessmentText($answer, "{$label} answer", 1000);
                }
                unset($answer);

                facultyAssessmentDistinct($answers, "{$label} answers");
                $question['key'] = ['answers' => $answers];

                if ($type === 'enumeration') {
                    if (!is_bool($row['ordered'] ?? null)) {
                        throw new DomainException("Select an order setting for {$label}.");
                    }

                    $question['key']['ordered'] = $row['ordered'];
                }
                break;

            case 'numeric':
                $answer = facultyAssessmentNumber($row['answer'] ?? '', "{$label} expected number");
                $tolerance = facultyAssessmentNumber($row['tolerance'] ?? '', "{$label} tolerance");

                if ((float) $tolerance < 0) {
                    throw new DomainException("{$label} tolerance must be zero or greater.");
                }

                $question['unit'] = facultyAssessmentText(
                    $row['unit'] ?? '',
                    "{$label} unit",
                    60,
                    false
                );
                $question['key'] = ['answer' => $answer, 'tolerance' => $tolerance];
                break;

            case 'matching':
                $pairs = facultyAssessmentRows($row['pairs'] ?? null, 2, "{$label} matching pairs");
                $matches = [];

                foreach ($pairs as $pair) {
                    if (!is_array($pair)) {
                        throw new DomainException("{$label} contains an invalid pair.");
                    }

                    $question['items'][] = facultyAssessmentText(
                        $pair['left'] ?? '',
                        "{$label} matching prompt",
                        1000
                    );
                    $matches[] = facultyAssessmentText(
                        $pair['right'] ?? '',
                        "{$label} correct match",
                        1000
                    );
                }

                facultyAssessmentDistinct($question['items'], "{$label} matching prompts");
                facultyAssessmentDistinct($matches, "{$label} matching answers");
                $question['key'] = ['matches' => $matches];
                break;

            case 'essay':
                $criteria = facultyAssessmentRows($row['rubric'] ?? null, 1, "{$label} rubric");
                $rubric = [];

                foreach ($criteria as $criterion) {
                    if (!is_array($criterion)) {
                        throw new DomainException("{$label} contains an invalid rubric criterion.");
                    }

                    $rubric[] = [
                        'criterion' => facultyAssessmentText(
                            $criterion['criterion'] ?? '',
                            "{$label} scoring criterion",
                            1500
                        ),
                        'points' => facultyAssessmentPoints(
                            $criterion['points'] ?? null,
                            "{$label} criterion points"
                        ),
                    ];
                }

                if (array_sum(array_column($rubric, 'points')) !== $question['points']) {
                    throw new DomainException("{$label} rubric points must equal its total points.");
                }

                $question['key'] = ['rubric' => $rubric];
                break;
        }

        $questions[] = $question;
    }

    return $questions;
}

function facultyCreateAssessment(PDO $pdo, int $facultyId, string $csrfToken): void
{
    $sectionId = 0;

    try {
        $token = $_POST['csrf_token'] ?? null;

        if (!is_string($token) || !hash_equals($csrfToken, $token)) {
            throw new DomainException('Your session token expired. Refresh and try again.');
        }

        $requestKey = $_POST['request_key'] ?? null;

        if (!is_string($requestKey) || !preg_match('/\A[a-f0-9]{32}\z/', $requestKey)) {
            throw new DomainException('Please reopen the assessment form and try again.');
        }

        $sectionId = (int) filter_var($_POST['section_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        $kind = $_POST['assessment_type'] ?? null;
        $mode = $_POST['delivery_mode'] ?? null;
        $period = $_POST['period'] ?? null;

        if (
            !$sectionId
            || !in_array($kind, ['Exam', 'Quiz'], true)
            || !in_array($mode, ['link', 'internal'], true)
            || !in_array($period, ['P1', 'P2', 'P3'], true)
        ) {
            throw new DomainException('Select a section, assessment type, period, and delivery method.');
        }

        $title = facultyAssessmentText($_POST['title'] ?? '', 'assessment title', 180);
        $instructions = facultyAssessmentText($_POST['instructions'] ?? '', 'instructions', 5000, false);
        $url = null;
        $questionsJson = null;

        if ($mode === 'link') {
            $url = facultyAssessmentText($_POST['external_url'] ?? '', 'assessment link', 2048);

            if (
                !filter_var($url, FILTER_VALIDATE_URL)
                || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true)
                || !parse_url($url, PHP_URL_HOST)
                || strpos($url, '\\') !== false
            ) {
                throw new DomainException('Enter a complete assessment URL starting with https:// or http://.');
            }
        } else {
            $questions = facultyAssessmentQuestions($_POST['questions_json'] ?? null);
            $questionsJson = json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

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
            throw new DomainException('This section is no longer assigned to your account.');
        }

        $save = $pdo->prepare(
            "INSERT INTO faculty_assessments
                (section_id, created_by, assessment_type, delivery_mode, period,
                 title, instructions, external_url, questions_json, request_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id"
        );

        $save->execute([
            $sectionId,
            $facultyId,
            $kind,
            $mode,
            $period,
            $title,
            $instructions,
            $url,
            $questionsJson,
            $requestKey,
        ]);

        $pdo->commit();
        $type = 'success';
        $message = 'Assessment saved.';
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $type = 'error';

        if ($error instanceof DomainException) {
            $message = $error->getMessage();
        } else {
            error_log('AcadTrack assessment save: ' . $error->getMessage());
            $message = 'Unable to save the assessment. Check that the assessment table exists and try again.';
        }
    }

    facultyDashboardRedirect($type, $message, 'all', $sectionId, '', 'assessments');
}