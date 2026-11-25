<?php
declare(strict_types=1);

function gradeText($value): string
{
    $text = preg_replace('/\s+/u', ' ', trim((string) $value));
    $text = trim($text ?? '');

    return function_exists('mb_strtolower')
        ? mb_strtolower($text, 'UTF-8')
        : strtolower($text);
}

function gradeTextList(array $values): array
{
    return array_map('gradeText', $values);
}

function gradeAutomatic(array $question, $answer): ?float
{
    // Older assessments store a prose guide and need faculty review.
    if (($question['version'] ?? 1) < 2) {
        return null;
    }

    $points = (float) $question['points'];
    $key = $question['key'];
    $items = $question['items'] ?? [];

    switch ($question['type']) {
        case 'single':
        case 'multiple':
            $expected = [];
            foreach ($key['correct_indexes'] as $index) {
                $expected[] = gradeText($items[$index]);
            }

            $given = gradeTextList(is_array($answer) ? $answer : [$answer]);
            sort($expected, SORT_STRING);
            sort($given, SORT_STRING);

            return $given === $expected ? $points : 0.0;

        case 'boolean':
            return gradeText($answer) === gradeText($key['answer']) ? $points : 0.0;

        case 'short':
            return in_array(gradeText($answer), gradeTextList($key['answers']), true)
                ? $points : 0.0;

        case 'numeric':
            if (!is_numeric($answer)) {
                return 0.0;
            }

            $given = (float) $answer;
            $expected = (float) $key['answer'];
            $tolerance = (float) $key['tolerance'];

            if ($given === $expected) {
                return $points;
            }
            if ($tolerance === 0.0) {
                return 0.0;
            }

            // Allow for binary rounding at a decimal tolerance boundary.
            $distance = abs($given - $expected);
            if (!is_finite($distance)) {
                return 0.0;
            }

            $rounding = PHP_FLOAT_EPSILON * max(abs($given), abs($expected), $tolerance);
            return $distance <= $tolerance + $rounding ? $points : 0.0;

        case 'matching':
            $expected = gradeTextList($key['matches']);
            $given = gradeTextList(is_array($answer) ? $answer : []);
            $correct = 0;

            foreach ($expected as $index => $match) {
                if (($given[$index] ?? null) === $match) {
                    $correct++;
                }
            }

            return round($points * $correct / count($expected), 2);

        case 'enumeration':
            $expected = gradeTextList($key['answers']);
            $lines = preg_split('/\R/u', (string) $answer) ?: [];
            $given = array_values(array_filter(
                gradeTextList($lines),
                static fn(string $line): bool => $line !== ''
            ));

            // Extra guesses cannot earn full credit by listing every possibility.
            if (count($given) > count($expected)) {
                return 0.0;
            }

            $correct = 0;
            if ($key['ordered']) {
                foreach ($expected as $index => $item) {
                    if (($given[$index] ?? null) === $item) {
                        $correct++;
                    }
                }
            } else {
                $correct = count(array_intersect($expected, array_unique($given)));
            }

            return round($points * $correct / count($expected), 2);

        default:
            return null;
    }
}

function assessmentGradeReport(array $record): array
{
    $questions = json_decode($record['questions_json'], true, 512, JSON_THROW_ON_ERROR);
    $answers = json_decode($record['answers_json'], true, 512, JSON_THROW_ON_ERROR);
    $manual = json_decode($record['manual_scores_json'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);

    if (!$questions || count($questions) !== count($answers)) {
        throw new RuntimeException('The stored questions and answers do not match.');
    }

    $report = [
        'rows' => [],
        'earned' => 0.0,
        'possible' => 0.0,
        'marked_possible' => 0.0,
        'pending' => 0,
    ];

    foreach ($questions as $index => $question) {
        if (
            ($answers[$index]['type'] ?? null) !== $question['type']
            || !array_key_exists('answer', $answers[$index])
        ) {
            throw new RuntimeException('The stored answer format does not match the question.');
        }

        $answer = $answers[$index]['answer'];
        $automatic = gradeAutomatic($question, $answer);
        $isManual = $automatic === null;
        $score = $isManual ? ($manual[$index] ?? null) : $automatic;
        $maximum = (float) $question['points'];

        $report['possible'] += $maximum;

        if ($score === null) {
            $report['pending']++;
        } else {
            $report['earned'] += (float) $score;
            $report['marked_possible'] += $maximum;
        }

        $report['rows'][] = [
            'question' => $question,
            'answer' => $answer,
            'manual' => $isManual,
            'score' => $score,
        ];
    }

    return $report;
}

function gradeNumber($number): string
{
    return rtrim(rtrim(number_format((float) $number, 2, '.', ''), '0'), '.');
}

function gradeHtml($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}