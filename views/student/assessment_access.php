<?php
declare(strict_types=1);

function studentAssessmentAccessSql(): string
{
    return "
        FROM faculty_assessments a
        JOIN section_students ss
          ON ss.section_id = a.section_id
        JOIN users student
          ON student.id = ss.student_id
         AND student.role = 'student'
         AND student.is_active = 1
        JOIN student_permit_status permit
          ON permit.student_id = student.id
         AND permit.period = (a.period COLLATE utf8mb4_unicode_ci)
         AND permit.status = 'with permit'
        LEFT JOIN assessment_attempts attempt
          ON attempt.assessment_id = a.id
         AND attempt.student_id = student.id
        WHERE student.id = ?
    ";
}