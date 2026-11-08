<?php
declare(strict_types=1);

require_once __DIR__ . '/../../auth/require_role.php';
requireRole(['student']);

require_once __DIR__ . '/../../config/database.php';

date_default_timezone_set('Asia/Manila');

$studentId = (int) ($_SESSION['user_id'] ?? 0);
$studentName = $_SESSION['nickname'] ?? 'Student';

if ($studentId < 1) {
    http_response_code(403);
    exit('Access denied.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$periods = ['P1', 'P2', 'P3'];

/*
|--------------------------------------------------------------------------
| CHANGE STUDENT PASSWORD
|--------------------------------------------------------------------------
*/

$passwordErrorMessage = '';
$openPasswordModal = false;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'change_student_password'
) {
    $openPasswordModal = true;

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken)
        || !hash_equals($csrfToken, $postedToken)
    ) {
        $passwordErrorMessage =
            'Your session token is invalid. Refresh the page and try again.';

    } elseif (
        !is_string($currentPassword)
        || !is_string($newPassword)
        || !is_string($confirmPassword)
    ) {
        $passwordErrorMessage =
            'Invalid password information. Please try again.';

    } elseif (
        $currentPassword === ''
        || $newPassword === ''
        || $confirmPassword === ''
    ) {
        $passwordErrorMessage =
            'Please complete all password fields.';

    } elseif (studentProfileTextLength($newPassword) < 8) {
        $passwordErrorMessage =
            'Your new password must contain at least 8 characters.';

    } elseif (strlen($newPassword) > 72) {
        $passwordErrorMessage =
            'Your new password must not exceed 72 bytes.';

    } elseif ($newPassword !== $confirmPassword) {
        $passwordErrorMessage =
            'The new password and confirmation password do not match.';

    } elseif ($currentPassword === $newPassword) {
        $passwordErrorMessage =
            'Your new password must be different from your current password.';

    } else {

        try {

            $passwordQuery = $pdo->prepare(
                "SELECT password_hash
                 FROM users
                 WHERE id = ?
                   AND role = 'student'
                   AND is_active = 1
                 LIMIT 1"
            );

            $passwordQuery->execute([$studentId]);

            $account = $passwordQuery->fetch(PDO::FETCH_ASSOC);

            if (
                !$account
                || !is_string($account['password_hash'])
                || !password_verify(
                    $currentPassword,
                    $account['password_hash']
                )
            ) {
                $passwordErrorMessage =
                    'Your current password is incorrect.';

            } else {

                $newPasswordHash = password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );

                if ($newPasswordHash === false) {
                    throw new RuntimeException(
                        'Password hashing failed.'
                    );
                }

                $passwordUpdate = $pdo->prepare(
                    "UPDATE users
                     SET password_hash = ?
                     WHERE id = ?
                       AND role = 'student'
                       AND is_active = 1"
                );

                $passwordUpdate->execute([
                    $newPasswordHash,
                    $studentId,
                ]);

                if ($passwordUpdate->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Password update failed.'
                    );
                }

                $_SESSION['student_password_notice'] =
                    'Your password has been changed successfully.';

                session_regenerate_id(true);

                header('Location: dashboard.php');
                exit;
            }

        } catch (Throwable $exception) {

            error_log(
                'AcadTrack student password update error: '
                . $exception->getMessage()
            );

            $passwordErrorMessage =
                'Unable to update your password. Please try again.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| LOAD AVAILABLE SECTIONS
|--------------------------------------------------------------------------
*/

$sectionOptionsQuery = $pdo->query(
    "SELECT section_name
     FROM sections
     WHERE TRIM(section_name) <> ''
     ORDER BY course ASC, year_level ASC, section_name ASC"
);

$sectionOptions = array_map(
    static fn($sectionName): string => (string) $sectionName,
    $sectionOptionsQuery->fetchAll(PDO::FETCH_COLUMN)
);

/*
|--------------------------------------------------------------------------
| LOAD CURRENT ACADEMIC INFORMATION
|--------------------------------------------------------------------------
*/

$academicInfoQuery = $pdo->prepare(
    "SELECT course, year_level, section
        FROM user_information
        WHERE user_id = ?
        LIMIT 1"
);

$academicInfoQuery->execute([$studentId]);

$currentAcademicInfo = $academicInfoQuery->fetch(PDO::FETCH_ASSOC) ?: [
    'course' => null,
    'year_level' => null,
    'section' => null,
];

$currentAcademicCourse = trim(
    (string) ($currentAcademicInfo['course'] ?? '')
);

$currentAcademicYear = $currentAcademicInfo['year_level'] !== null
    ? trim((string) $currentAcademicInfo['year_level'])
    : '';

$currentAcademicSection = trim(
    (string) ($currentAcademicInfo['section'] ?? '')
);

/*
|--------------------------------------------------------------------------
| ACADEMIC FIELD LOCKS
|--------------------------------------------------------------------------
*/

$profileCourseLocked = $currentAcademicCourse !== '';
$profileYearLocked = $currentAcademicYear !== '';
$profileSectionLocked = $currentAcademicSection !== '';

if (
    $currentAcademicSection !== ''
    && !in_array($currentAcademicSection, $sectionOptions, true)
) {
    array_unshift($sectionOptions, $currentAcademicSection);
}

/*
|--------------------------------------------------------------------------
| STUDENT PROFILE — SAVE AND VALIDATE
|--------------------------------------------------------------------------
*/

$profileErrorMessage = '';

$openProfileModal = (
    isset($_GET['open_profile'])
    && $_GET['open_profile'] === '1'
);

$profileFormValues = null;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'save_student_profile'
) {
    $openProfileModal = true;

    $readProfilePost = static function (string $key): string {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    };

    $profileFormValues = [
        'firstname' => $readProfilePost('firstname'),
        'middlename' => $readProfilePost('middlename'),
        'lastname' => $readProfilePost('lastname'),
        'age' => $readProfilePost('age'),
        'address' => $readProfilePost('address'),
        'gender' => $readProfilePost('gender'),
        'mobile_number' => $readProfilePost('mobile_number'),
        'course' => $readProfilePost('course'),
        'year_level' => $readProfilePost('year_level'),
        'section' => $readProfilePost('section'),
    ];

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken)
        || !hash_equals($csrfToken, $postedToken)
    ) {
        $profileErrorMessage =
            'Your session token is invalid. Refresh the page and try again.';

    } else {

        $firstName = $profileFormValues['firstname'];
        $middleName = $profileFormValues['middlename'];
        $lastName = $profileFormValues['lastname'];
        $ageInput = $profileFormValues['age'];
        $address = $profileFormValues['address'];
        $gender = $profileFormValues['gender'];
        $mobileNumber = $profileFormValues['mobile_number'];
        $course = $profileFormValues['course'];

        $course = $profileCourseLocked
            ? $currentAcademicCourse
            : $profileFormValues['course'];

        $yearLevelInput = $profileYearLocked
            ? $currentAcademicYear
            : $profileFormValues['year_level'];

        $section = $profileSectionLocked
            ? $currentAcademicSection
            : $profileFormValues['section'];

        $allowedGenders = [
            '',
            'Male',
            'Female',
            'Other',
            'Prefer not to say',
        ];

        if ($firstName === '' || $lastName === '') {
            $profileErrorMessage =
                'Firstname and Lastname are required.';

        } elseif (
            studentProfileTextLength($firstName) > 100
            || studentProfileTextLength($middleName) > 100
            || studentProfileTextLength($lastName) > 100
        ) {
            $profileErrorMessage =
                'Names must not exceed 100 characters.';

        } elseif (
            !$profileCourseLocked
            && studentProfileTextLength($course) > 100
        ) {
            $profileErrorMessage =
                'Course must not exceed 100 characters.';

        } elseif (studentProfileTextLength($address) > 2000) {
            $profileErrorMessage =
                'Address must not exceed 2,000 characters.';

        } elseif (!in_array($gender, $allowedGenders, true)) {
            $profileErrorMessage =
                'Please select a valid gender option.';

        } elseif (
            $ageInput !== ''
            && (
                filter_var($ageInput, FILTER_VALIDATE_INT) === false
                || (int) $ageInput < 1
                || (int) $ageInput > 120
            )
        ) {
            $profileErrorMessage =
                'Age must be a whole number between 1 and 120.';

        } elseif (
            $mobileNumber !== ''
            && (
                !preg_match('/\A\+?[0-9()\s.-]{7,20}\z/', $mobileNumber)
                || strlen(preg_replace('/\D/', '', $mobileNumber) ?? '') < 7
                || strlen(preg_replace('/\D/', '', $mobileNumber) ?? '') > 15
            )
        ) {
            $profileErrorMessage =
                'Please enter a valid mobile number.';

        } elseif (
            !$profileYearLocked
            && $yearLevelInput !== ''
            && !in_array($yearLevelInput, ['1', '2', '3', '4'], true)
        ) {
            $profileErrorMessage =
                'Please select a valid year level.';

        } elseif (
            !$profileSectionLocked
            && $section !== ''
            && !in_array($section, $sectionOptions, true)
        ) {
            $profileErrorMessage =
                'Please select a valid section.';

        } else {

            try {

                $age = $ageInput === ''
                    ? null
                    : (int) $ageInput;

                $yearLevel = $yearLevelInput === ''
                    ? null
                    : (int) $yearLevelInput;

                $selectedSectionId = null;

                if ($section !== '') {

                    $sectionIdQuery = $pdo->prepare(
                        "SELECT id
                         FROM sections
                         WHERE section_name = ?
                         LIMIT 1"
                    );

                    $sectionIdQuery->execute([$section]);

                    $sectionId = $sectionIdQuery->fetchColumn();

                    if ($sectionId === false) {
                        throw new RuntimeException(
                            'The selected section does not exist in the sections table.'
                        );
                    }

                    $selectedSectionId = (int) $sectionId;
                }

                $pdo->beginTransaction();

                $profileSave = $pdo->prepare(
                    "INSERT INTO user_information (
                        user_id,
                        firstname,
                        middlename,
                        lastname,
                        age,
                        address,
                        gender,
                        mobile_number,
                        course,
                        year_level,
                        section
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)

                    ON DUPLICATE KEY UPDATE
                        firstname = VALUES(firstname),
                        middlename = VALUES(middlename),
                        lastname = VALUES(lastname),
                        age = VALUES(age),
                        address = VALUES(address),
                        gender = VALUES(gender),
                        mobile_number = VALUES(mobile_number),
                        course = VALUES(course),
                        year_level = VALUES(year_level),
                        section = VALUES(section)"
                );

                $profileSave->execute([
                    $studentId,
                    $firstName,
                    $middleName !== '' ? $middleName : null,
                    $lastName,
                    $age,
                    $address !== '' ? $address : null,
                    $gender !== '' ? $gender : null,
                    $mobileNumber !== '' ? $mobileNumber : null,
                    $course !== '' ? $course : null,
                    $yearLevel,
                    $section !== '' ? $section : null,
                ]);

                if ($selectedSectionId !== null) {

                    $sectionAssignment = $pdo->prepare(
                        "INSERT INTO section_students (
                            student_id,
                            section_id
                        ) VALUES (?, ?)

                        ON DUPLICATE KEY UPDATE
                            assigned_at = IF(
                                section_id <> VALUES(section_id),
                                CURRENT_TIMESTAMP,
                                assigned_at
                            ),
                            section_id = VALUES(section_id)"
                    );

                    $sectionAssignment->execute([
                        $studentId,
                        $selectedSectionId,
                    ]);
                }

                $pdo->commit();

                $_SESSION['student_profile_notice'] =
                    'Your profile was updated successfully.';

                header('Location: dashboard.php?profile=updated');
                exit;

            } catch (Throwable $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'AcadTrack student profile update error: '
                    . $exception->getMessage()
                    . ' | File: ' . $exception->getFile()
                    . ' | Line: ' . $exception->getLine()
                );

                $profileErrorMessage =
                    'Unable to save your profile. Please try again.';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| STUDENT PROFILE — LOAD CURRENT INFORMATION
|--------------------------------------------------------------------------
*/

$profileNotice = $_SESSION['student_profile_notice'] ?? '';
unset($_SESSION['student_profile_notice']);

$profilePictureErrorMessage =
    $_SESSION['student_profile_picture_error'] ?? '';

unset($_SESSION['student_profile_picture_error']);

$accountNotice = $_SESSION['student_password_notice'] ?? '';
unset($_SESSION['student_password_notice']);

$profileQuery = $pdo->prepare(
    "SELECT
        u.nickname,
        u.student_number,
        u.email,
        u.profile_picture,

        ui.firstname,
        ui.middlename,
        ui.lastname,
        ui.age,
        ui.address,
        ui.gender,
        ui.mobile_number,
        ui.course AS profile_course,
        ui.year_level AS profile_year_level,
        ui.section AS profile_section

     FROM users u

     LEFT JOIN user_information ui
        ON ui.user_id = u.id

     WHERE u.id = ?
       AND u.role = 'student'
       AND u.is_active = 1

     LIMIT 1"
);

$profileQuery->execute([$studentId]);

$studentProfile = $profileQuery->fetch(PDO::FETCH_ASSOC);

if (!$studentProfile) {
    http_response_code(403);
    exit('Your student account is unavailable or inactive.');
}

$studentName = (string) $studentProfile['nickname'];

$profilePictureFilename = basename(
    trim((string) ($studentProfile['profile_picture'] ?? ''))
);

$profilePictureUrl = $profilePictureFilename !== ''
    ? '../../uploads/profile_pictures/' . rawurlencode($profilePictureFilename)
    : '';

$profileDefaults = [
    'firstname' => (string) ($studentProfile['firstname'] ?? ''),
    'middlename' => (string) ($studentProfile['middlename'] ?? ''),
    'lastname' => (string) ($studentProfile['lastname'] ?? ''),

    'age' => $studentProfile['age'] !== null
        ? (string) $studentProfile['age']
        : '',

    'address' => (string) ($studentProfile['address'] ?? ''),
    'gender' => (string) ($studentProfile['gender'] ?? ''),
    'mobile_number' => (string) ($studentProfile['mobile_number'] ?? ''),
    'student_number' => (string) ($studentProfile['student_number'] ?? ''),
    'email' => (string) ($studentProfile['email'] ?? ''),
    'course' => (string) ($studentProfile['profile_course'] ?? ''),

    'year_level' => $studentProfile['profile_year_level'] !== null
        ? (string) $studentProfile['profile_year_level']
        : '',

    'section' => (string) ($studentProfile['profile_section'] ?? ''),
];

/*
|--------------------------------------------------------------------------
| DETERMINE STUDENT SECTION AVAILABILITY
|--------------------------------------------------------------------------
*/

$assignedSectionName = trim($profileDefaults['section']);

$hasAssignedSection = false;

if ($assignedSectionName !== '') {

    $assignedSectionCheck = $pdo->prepare(
        "SELECT id
         FROM sections
         WHERE section_name = ?
         LIMIT 1"
    );

    $assignedSectionCheck->execute([$assignedSectionName]);

    $hasAssignedSection =
        $assignedSectionCheck->fetchColumn() !== false;
}

/*
 * Merge submitted values with existing profile information.
 */

if ($profileFormValues === null) {
    $profileFormValues = $profileDefaults;

} else {
    $profileFormValues = array_merge(
        $profileDefaults,
        $profileFormValues
    );
}

if ($profileCourseLocked) {
    $profileFormValues['course'] = $currentAcademicCourse;
}

if ($profileYearLocked) {
    $profileFormValues['year_level'] = $currentAcademicYear;
}

if ($profileSectionLocked) {
    $profileFormValues['section'] = $currentAcademicSection;
}

/*
|--------------------------------------------------------------------------
| YEAR LEVEL LABELS
|--------------------------------------------------------------------------
*/

$yearLabels = [
    '1' => '1st Year',
    '2' => '2nd Year',
    '3' => '3rd Year',
    '4' => '4th Year',
];

/*
|--------------------------------------------------------------------------
| INITIALIZE MISSING PERMIT STATUS RECORDS
|--------------------------------------------------------------------------
*/

$initialize = $pdo->prepare(
    "INSERT IGNORE INTO student_permit_status
        (student_id, period, status)
     VALUES
        (?, 'P1', 'no permit'),
        (?, 'P2', 'no permit'),
        (?, 'P3', 'no permit')"
);

$initialize->execute([
    $studentId,
    $studentId,
    $studentId,
]);

/*
|--------------------------------------------------------------------------
| RETRIEVE PERMIT STATUSES
|--------------------------------------------------------------------------
*/

$statusQuery = $pdo->prepare(
    "SELECT period, status, submission_id, updated_at
     FROM student_permit_status
     WHERE student_id = ?
     ORDER BY FIELD(period, 'P1', 'P2', 'P3')"
);

$statusQuery->execute([$studentId]);

$permitStatuses = [];

foreach ($statusQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $permitStatuses[$row['period']] = $row;
}

/*
|--------------------------------------------------------------------------
| RETRIEVE RECENT SUBMISSIONS
|--------------------------------------------------------------------------
*/

$submissionQuery = $pdo->prepare(
    "SELECT
        id,
        payment_type,
        period,
        original_filename,
        uploaded_at,
        approval_status,
        review_note
     FROM permit_submissions
     WHERE student_id = :student_id
     ORDER BY uploaded_at DESC, id DESC
     LIMIT 10"
);

$submissionQuery->execute([
    ':student_id' => $studentId,
]);

$recentSubmissions = $submissionQuery->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| DETERMINE ACTIVE SUBMISSIONS AND LOCKED PERIODS
|--------------------------------------------------------------------------
*/

$submissionStatusQuery = $pdo->prepare(
    "SELECT payment_type, period, approval_status
     FROM permit_submissions
     WHERE student_id = ?
       AND approval_status IN ('pending', 'approved')"
);

$submissionStatusQuery->execute([$studentId]);

$activeSubmissions = $submissionStatusQuery->fetchAll(PDO::FETCH_ASSOC);

$lockedPeriods = [];
$fullPaymentLocked = false;
$pendingPeriods = [];

foreach ($activeSubmissions as $submission) {

    $paymentType = $submission['payment_type'];
    $period = $submission['period'];
    $approvalStatus = $submission['approval_status'];

    if ($paymentType === 'full') {

        $fullPaymentLocked = true;
        $lockedPeriods = $periods;

        if ($approvalStatus === 'pending') {
            foreach ($periods as $pendingPeriod) {
                $pendingPeriods[$pendingPeriod] = true;
            }
        }

        continue;
    }

    if (
        $paymentType === 'period'
        && in_array($period, $periods, true)
    ) {
        $lockedPeriods[] = $period;

        if ($approvalStatus === 'pending') {
            $pendingPeriods[$period] = true;
        }
    }
}

$lockedPeriods = array_values(array_unique($lockedPeriods));

$fullPaymentLocked =
    $fullPaymentLocked || count($lockedPeriods) > 0;

/*
|--------------------------------------------------------------------------
| FLASH AND ERROR MESSAGES
|--------------------------------------------------------------------------
*/

$errorMessages = [
    'invalid_request' => 'Invalid request. Please try again.',
    'invalid_token' => 'Your session token is invalid. Refresh the page and try again.',
    'invalid_period' => 'Please select a valid payment period.',
    'no_section' => 'You must add an assigned section in Edit Profile before uploading a permit.',
    'no_file' => 'Please select a permit or receipt to upload.',
    'invalid_file' => 'File type not allowed. Upload a PDF, JPG, JPEG, or PNG file.',
    'file_too_large' => 'The file exceeds the 5 MB upload limit.',
    'upload_failed' => 'The upload failed. Please try again.',
    'server_error' => 'Something went wrong. Please try again later.',
    'duplicate_submission' => 'You already have a pending or approved submission for this payment period.',
];

$successMessage = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

$flashErrorMessage = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

$errorCode = $_GET['error'] ?? '';

$errorMessage = is_string($errorCode)
    ? ($errorMessages[$errorCode] ?? '')
    : '';

/*
|--------------------------------------------------------------------------
| HTML HELPERS
|--------------------------------------------------------------------------
*/

function escapeHtml($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function studentProfileTextLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#ffffff">

    <title>AcadTrack | Student Dashboard</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://unpkg.com/lucide@latest"></script>

    <link rel="stylesheet" href="../../style/student/student_dash.css">
    <script src="../../js/student/student_dash.js" defer></script>
    <script src="../../js/auth/session_guard.js" defer></script>
</head>

<body>

    <div class="app" id="app">

        <div class="mobile-scrim" data-close-sidebar></div>

        <!-- SIDEBAR -->

        <aside class="sidebar" id="sidebar" aria-label="Main sidebar">

            <div class="brand-area">

                <a href="dashboard.php" class="brand" aria-label="AcadTrack dashboard">

                    <span class="brand-symbol">
                        <i data-lucide="graduation-cap"></i>
                    </span>

                    <span class="brand-word">Acad<span>Track</span></span>

                </a>

                <button class="icon-btn mobile-close" type="button" aria-label="Close sidebar" data-close-sidebar>
                    <i data-lucide="x"></i>
                </button>

            </div>

            <div class="sidebar-content">

                <div class="nav-section">

                    <div class="section-caption">MENU</div>

                    <a href="dashboard.php" class="nav-item active" data-nav-link data-label="Dashboard">
                        <i data-lucide="layout-dashboard"></i>
                        <span class="nav-text">Dashboard</span>
                    </a>

                    <button class="nav-item nav-parent" type="button" aria-expanded="false"
                        data-submenu-toggle="academics-submenu" data-label="Academics">

                        <i data-lucide="graduation-cap"></i>
                        <span class="nav-text">Academics</span>
                        <i class="chevron" data-lucide="chevron-down"></i>

                    </button>

                    <div class="submenu" id="academics-submenu" hidden>

                        <a href="#" data-nav-link data-label="Permit Application" data-page="permit-application">
                            Permit Application
                        </a>

                        <!-- <a href="#" data-nav-link data-label="Examinations">
                            Examinations
                        </a>

                        <a href="#" data-nav-link data-label="Study Materials">
                            Study Materials
                        </a> -->

                    </div>

                    <!-- <a href="#" class="nav-item" data-nav-link data-label="GChat">
                        <i data-lucide="messages-square"></i>
                        <span class="nav-text">GChat</span>
                    </a>

                    <a href="#" class="nav-item" data-nav-link data-label="Calendar">
                        <i data-lucide="calendar-days"></i>
                        <span class="nav-text">Calendar</span>
                    </a> -->

                </div>

            </div>

            <div class="sidebar-bottom">

                <div class="sidebar-help">

                    <div class="help-bubble">
                        <i data-lucide="messages-square"></i>
                    </div>

                    <div class="help-text">
                        <strong>Need help?</strong>
                        <span>Contact CITE Department</span>
                    </div>

                    <button type="button" class="help-open" aria-label="Open help"
                        data-toast="Help Center is a design placeholder.">
                        <i data-lucide="arrow-up-right"></i>
                    </button>

                </div>

                <div class="sidebar-brand-footer">PHINMA Education</div>

            </div>

        </aside>

        <!-- MAIN SHELL -->

        <div class="main-shell">

            <!-- TOPBAR -->

            <header class="topbar">

                <div class="topbar-start">

                    <button class="icon-btn mobile-menu" type="button" aria-label="Open sidebar" aria-expanded="false"
                        data-open-sidebar>
                        <i data-lucide="menu"></i>
                    </button>

                    <button class="icon-btn desktop-sidebar-toggle" type="button" aria-label="Collapse sidebar"
                        aria-pressed="false" data-collapse-sidebar>
                        <i data-lucide="panel-left"></i>
                    </button>

                    <div class="breadcrumb">
                        <span>Student Portal</span>
                        <i data-lucide="chevron-right"></i>
                        <strong id="breadcrumb-current">Dashboard</strong>
                    </div>

                </div>

                <div class="topbar-end">

                    <!-- LIVE DATE AND TIME -->

                    <div class="datetime-wrap">
                        <div class="datetime-date" id="live-date">
                            Loading date...
                        </div>

                        <time class="datetime-clock" id="live-clock">
                            00:00:00
                        </time>
                    </div>

                    <div class="topbar-separator"></div>

                    <!-- PROFILE DROPDOWN -->

                    <div class="dropdown-wrap profile-wrap">

                        <button class="profile-trigger" type="button" aria-expanded="false"
                            data-dropdown-toggle="profile-menu">

                            <span class="profile-avatar">

                                <?php if ($profilePictureUrl !== ''): ?>

                                    <img src="<?= escapeHtml($profilePictureUrl) ?>" alt="Student profile picture">

                                <?php else: ?>

                                    ST

                                <?php endif; ?>

                            </span>

                            <span class="profile-info">
                                <strong><?= escapeHtml($studentName) ?></strong>
                                <small>Student account</small>
                            </span>

                            <i class="profile-chevron" data-lucide="chevron-down"></i>

                        </button>

                        <div class="dropdown profile-menu" id="profile-menu" hidden>

                            <div class="profile-menu-user">

                                <span class="profile-avatar large">

                                    <?php if ($profilePictureUrl !== ''): ?>

                                        <img src="<?= escapeHtml($profilePictureUrl) ?>" alt="Student profile picture">

                                    <?php else: ?>

                                        ST

                                    <?php endif; ?>

                                </span>

                                <div>
                                    <strong><?= escapeHtml($studentName) ?></strong>
                                    <small>Student account</small>
                                </div>

                            </div>

                            <a href="#" data-open-profile-modal>
                                <i data-lucide="user-round"></i>
                                Edit profile
                            </a>

                            <a href="#" data-open-password-modal>
                                <i data-lucide="settings-2"></i>
                                Account settings
                            </a>

                            <div class="dropdown-divider"></div>

                            <a href="../../logout.php" class="signout-row">
                                <i data-lucide="log-out"></i>
                                Sign out
                            </a>

                        </div>

                    </div>

                </div>

            </header>

            <!-- PAGE CONTENT -->

            <main class="page">

                <!-- DASHBOARD HOME -->

                <section class="dashboard-page" id="dashboard-home-page">

                    <div class="page-title-row">

                        <div>
                            <div class="overline">STUDENT WORKSPACE</div>
                            <h1>Dashboard</h1>

                            <p class="page-subtitle">
                                Welcome, <?= escapeHtml($studentName) ?>.
                                Access your academic services here.
                            </p>
                        </div>

                    </div>

                </section>

                <!-- PERMIT APPLICATION -->

                <section class="dashboard-page" id="permit-application-page" hidden>

                    <div class="page-title-row">

                        <div>
                            <div class="overline">ACADEMICS</div>
                            <h1>Permit Application</h1>

                            <p class="page-subtitle">
                                Upload your permit or payment receipt.
                            </p>
                        </div>

                    </div>

                    <!-- FLASH MESSAGES -->

                    <?php if ($successMessage !== ''): ?>

                        <div class="permit-alert permit-alert-success" role="status">
                            <i data-lucide="check-circle"></i>
                            <span><?= escapeHtml($successMessage) ?></span>
                        </div>

                    <?php endif; ?>

                    <?php if ($errorMessage !== ''): ?>

                        <div class="permit-alert permit-alert-error" role="alert">
                            <i data-lucide="alert-circle"></i>
                            <span><?= escapeHtml($errorMessage) ?></span>
                        </div>

                    <?php endif; ?>

                    <?php if ($flashErrorMessage !== ''): ?>

                        <div class="permit-alert permit-alert-error" role="alert">
                            <i data-lucide="alert-circle"></i>
                            <span><?= escapeHtml($flashErrorMessage) ?></span>
                        </div>

                    <?php endif; ?>

                    <!-- SECTION REQUIREMENT -->

                    <?php if (!$hasAssignedSection): ?>

                        <div class="permit-section-required" role="alert">

                            <span class="permit-section-required-icon">
                                <i data-lucide="alert-circle"></i>
                            </span>

                            <div class="permit-section-required-copy">

                                <strong>Assigned Section Required</strong>

                                <p>
                                    Your account does not have a valid assigned section.
                                    You must add your section in Edit Profile before
                                    you can upload your examination permit or payment receipt.
                                </p>

                            </div>

                            <button type="button" class="button button-primary" data-open-profile-modal>
                                <i data-lucide="user-round-pen"></i>
                                <span>Edit Profile</span>
                            </button>

                        </div>

                    <?php endif; ?>

                    <!-- PERMIT STATUS -->

                    <div class="permit-section-heading">

                        <div>
                            <h2>Examination Permit Status</h2>
                            <p>Check the current status for each payment period.</p>
                        </div>

                    </div>

                    <div class="permit-period-grid">

                        <?php foreach ($periods as $period): ?>

                            <?php
                            $periodStatus = $permitStatuses[$period]['status']
                                ?? 'no permit';

                            $hasPermit = $periodStatus === 'with permit';

                            $isPending =
                                isset($pendingPeriods[$period]) && !$hasPermit;

                            $uploadLocked = in_array(
                                $period,
                                $lockedPeriods,
                                true
                            );
                            ?>

                            <article class="permit-period-card">

                                <div class="permit-card-top">

                                    <span class="permit-period-icon">
                                        <h3><?= escapeHtml($period) ?></h3>
                                    </span>

                                    <span class="permit-status <?= $hasPermit
                                        ? 'status-approved'
                                        : ($isPending
                                            ? 'status-pending'
                                            : 'status-missing') ?>">

                                        <span class="status-dot"></span>

                                        <?= $hasPermit
                                            ? 'With Permit'
                                            : ($isPending
                                                ? 'Pending Review'
                                                : 'No Permit') ?>

                                    </span>

                                </div>

                                <?php if ($uploadLocked): ?>

                                    <small class="permit-updated">
                                        <?= escapeHtml($period) ?> has an existing pending
                                        or approved submission.
                                    </small>

                                    <button type="button" class="button button-primary" disabled>
                                        <i data-lucide="check-circle"></i>
                                        <span>Document Submitted</span>
                                    </button>

                                <?php elseif (!$hasAssignedSection): ?>

                                    <small class="permit-updated">
                                        Permit upload is disabled. Add your assigned section
                                        in Edit Profile first.
                                    </small>

                                    <button type="button" class="button button-outline" data-open-profile-modal>
                                        <i data-lucide="user-round-pen"></i>
                                        <span>Add Assigned Section</span>
                                    </button>

                                <?php else: ?>

                                    <form action="../../views/student/upload_permit.php" method="POST"
                                        enctype="multipart/form-data" class="permit-upload-form">

                                        <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">

                                        <input type="hidden" name="payment_type" value="period">

                                        <input type="hidden" name="period" value="<?= escapeHtml($period) ?>">

                                        <label for="permit-file-<?= escapeHtml($period) ?>">
                                            Submit your permit or payment receipt for this period.
                                        </label>

                                        <input type="file" id="permit-file-<?= escapeHtml($period) ?>" name="permit_file"
                                            accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>

                                        <button type="submit" class="button button-primary">
                                            <i data-lucide="upload"></i>
                                            <span>Upload <?= escapeHtml($period) ?> Receipt</span>
                                        </button>

                                    </form>

                                <?php endif; ?>

                            </article>

                        <?php endforeach; ?>

                    </div>

                    <!-- FULL PAYMENT -->

                    <article class="permit-full-payment">

                        <div class="permit-full-payment-content">

                            <span class="permit-period-icon">
                                <i data-lucide="files"></i>
                            </span>

                            <div>
                                <h2>Full Payment</h2>
                                <p>
                                    If your receipt confirms full payment, submit it here.
                                </p>
                            </div>

                        </div>

                        <?php if ($fullPaymentLocked): ?>

                            <div class="permit-alert permit-alert-error" role="status">
                                <i data-lucide="lock"></i>

                                <span>
                                    Full-payment upload is unavailable because a pending
                                    or approved submission already exists for one or more
                                    payment periods.
                                </span>
                            </div>

                        <?php elseif (!$hasAssignedSection): ?>

                            <div class="permit-alert permit-alert-warning" role="alert">
                                <i data-lucide="lock"></i>

                                <span>
                                    Full-payment upload is unavailable.
                                    Add your assigned section in Edit Profile first.
                                </span>
                            </div>

                            <button type="button" class="button button-outline" data-open-profile-modal>
                                <i data-lucide="user-round-pen"></i>
                                <span>Add Assigned Section</span>
                            </button>

                        <?php else: ?>

                            <form action="../../views/student/upload_permit.php" method="POST" enctype="multipart/form-data"
                                class="permit-upload-form">

                                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">

                                <input type="hidden" name="payment_type" value="full">

                                <label for="full-payment-file">
                                    Full-payment receipt
                                </label>

                                <input type="file" id="full-payment-file" name="permit_file"
                                    accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>

                                <button type="submit" class="button button-primary">
                                    <i data-lucide="upload"></i>
                                    <span>Upload Full-Payment Receipt</span>
                                </button>

                            </form>

                        <?php endif; ?>

                    </article>

                    <!-- RECENT SUBMISSIONS -->

                    <section class="permit-history-section">

                        <div class="permit-section-heading">

                            <div>
                                <h2>Recent Submissions</h2>
                                <p>Your recent uploaded documents.</p>
                            </div>

                        </div>

                        <div class="permit-table-wrap">

                            <table class="permit-history-table">

                                <thead>
                                    <tr>
                                        <th>Document</th>
                                        <th>Payment Type</th>
                                        <th>Period</th>
                                        <th>Date Uploaded</th>
                                        <th>Status</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php if (empty($recentSubmissions)): ?>

                                        <tr>
                                            <td colspan="6" class="permit-empty-history">
                                                No submissions yet.
                                            </td>
                                        </tr>

                                    <?php else: ?>

                                        <?php foreach ($recentSubmissions as $submission): ?>

                                            <?php
                                            $submissionStatus = strtolower(
                                                trim(
                                                    (string) (
                                                        $submission['approval_status']
                                                        ?? 'pending'
                                                    )
                                                )
                                            );

                                            if (
                                                !in_array(
                                                    $submissionStatus,
                                                    ['pending', 'approved', 'rejected'],
                                                    true
                                                )
                                            ) {
                                                $submissionStatus = 'pending';
                                            }

                                            $submissionStatusLabel = ucfirst(
                                                $submissionStatus
                                            );

                                            $reviewNote = trim(
                                                (string) (
                                                    $submission['review_note'] ?? ''
                                                )
                                            );
                                            ?>

                                            <tr>

                                                <td>
                                                    <?= escapeHtml(
                                                        $submission['original_filename']
                                                        ?? 'Untitled document'
                                                    ) ?>
                                                </td>

                                                <td>
                                                    <?= ($submission['payment_type'] ?? '') === 'full'
                                                        ? 'Full Payment'
                                                        : 'Individual Period' ?>
                                                </td>

                                                <td>
                                                    <?= ($submission['payment_type'] ?? '') === 'full'
                                                        ? 'P1, P2, P3'
                                                        : escapeHtml(
                                                            $submission['period'] ?? '—'
                                                        ) ?>
                                                </td>

                                                <td>

                                                    <?php if (!empty($submission['uploaded_at'])): ?>

                                                        <?php
                                                        $uploadedTimestamp = strtotime(
                                                            $submission['uploaded_at']
                                                        );
                                                        ?>

                                                        <?= $uploadedTimestamp !== false
                                                            ? escapeHtml(
                                                                date(
                                                                    'M d, Y g:i A',
                                                                    $uploadedTimestamp
                                                                )
                                                            )
                                                            : '—' ?>

                                                    <?php else: ?>

                                                        —

                                                    <?php endif; ?>

                                                </td>

                                                <td>

                                                    <span class="submission-status submission-status-<?= escapeHtml(
                                                        $submissionStatus
                                                    ) ?>">

                                                        <span class="submission-status-dot"></span>

                                                        <?= escapeHtml(
                                                            $submissionStatusLabel
                                                        ) ?>

                                                    </span>

                                                </td>

                                                <td class="submission-remarks">
                                                    <?= $reviewNote !== ''
                                                        ? escapeHtml($reviewNote)
                                                        : 'None' ?>
                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    <?php endif; ?>

                                </tbody>

                            </table>

                        </div>

                    </section>

                </section>

                <!-- FOOTER -->

                <footer class="page-footer">

                    <span>© <span id="year"></span> AcadTrack</span>

                    <div>

                        <span>
                            College of Information Technology Education
                        </span>

                    </div>

                </footer>

            </main>

        </div>

    </div>

    <!-- =====================================================
         STUDENT PROFILE MODAL
    ====================================================== -->

    <div class="student-profile-modal" id="student-profile-modal" <?= $openProfileModal ? '' : 'hidden' ?>>

        <button type="button" class="student-profile-backdrop" data-close-profile-modal
            aria-label="Close student profile">
        </button>

        <section class="student-profile-dialog" role="dialog" aria-modal="true" aria-labelledby="student-profile-title"
            aria-describedby="student-profile-description">

            <!-- MODAL HEADER -->

            <header class="student-profile-header">

                <div class="student-profile-heading">

                    <span class="student-profile-heading-icon">
                        <i data-lucide="user-round-pen"></i>
                    </span>

                    <div>

                        <h2 id="student-profile-title">My Profile</h2>

                        <p id="student-profile-description">
                            View your academic information and update your personal details.
                        </p>

                    </div>

                </div>

                <button type="button" class="icon-btn student-profile-close" data-close-profile-modal
                    aria-label="Close profile modal">

                    <i data-lucide="x"></i>

                </button>

            </header>

            <!-- PROFILE PICTURE FORM -->

            <form id="student-picture-form" class="student-profile-picture-form" action="update_profile_picture.php"
                method="POST" enctype="multipart/form-data">

                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">

                <div class="student-picture-editor">

                    <div class="student-picture-preview" id="student-picture-preview">

                        <?php if ($profilePictureUrl !== ''): ?>

                            <img src="<?= escapeHtml($profilePictureUrl) ?>" alt="Profile picture preview">

                        <?php else: ?>

                            <span>ST</span>

                        <?php endif; ?>

                    </div>

                    <div class="student-picture-details">

                        <strong>Profile Picture</strong>

                        <p>
                            Upload a new picture.
                            Supported formats: JPG, PNG, and WEBP. Maximum size: 2 MB.
                        </p>

                        <div class="student-picture-actions">

                            <label for="profile-picture-input" class="button button-outline">

                                <i data-lucide="image-plus"></i>
                                Choose Picture

                            </label>

                            <input type="file" id="profile-picture-input" name="profile_picture"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                class="student-picture-file-input">

                            <button type="submit" class="button button-primary" id="upload-profile-picture" disabled>

                                <i data-lucide="upload"></i>
                                Upload Picture

                            </button>

                        </div>

                        <small id="profile-picture-filename">

                        </small>

                    </div>

                </div>

                <?php if ($profilePictureErrorMessage !== ''): ?>

                    <div class="permit-alert permit-alert-error student-picture-error" role="alert">

                        <i data-lucide="alert-circle"></i>

                        <span>
                            <?= escapeHtml($profilePictureErrorMessage) ?>
                        </span>

                    </div>

                <?php endif; ?>

            </form>

            <!-- PERSONAL AND ACADEMIC INFORMATION FORM -->

            <form id="student-profile-form" class="student-profile-form" action="dashboard.php" method="POST">

                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">

                <input type="hidden" name="action" value="save_student_profile">

                <?php if ($profileErrorMessage !== ''): ?>

                    <div class="permit-alert permit-alert-error" role="alert">

                        <i data-lucide="alert-circle"></i>

                        <span>
                            <?= escapeHtml($profileErrorMessage) ?>
                        </span>

                    </div>

                <?php endif; ?>

                <!-- PERSONAL INFORMATION -->

                <div class="student-profile-section-title">

                    <i data-lucide="user-round"></i>

                    <span>Personal Information</span>

                </div>

                <div class="student-profile-grid">

                    <div class="student-profile-field">

                        <label for="profile-firstname">
                            Firstname <span class="required-mark">*</span>
                        </label>

                        <input id="profile-firstname" name="firstname" type="text" maxlength="100"
                            autocomplete="given-name" value="<?= escapeHtml($profileFormValues['firstname'] ?? '') ?>"
                            placeholder="Enter your firstname" required>

                    </div>

                    <div class="student-profile-field">

                        <label for="profile-middlename">Middlename</label>

                        <input id="profile-middlename" name="middlename" type="text" maxlength="100"
                            autocomplete="additional-name"
                            value="<?= escapeHtml($profileFormValues['middlename'] ?? '') ?>"
                            placeholder="Enter your middlename">

                    </div>

                    <div class="student-profile-field">

                        <label for="profile-lastname">
                            Lastname <span class="required-mark">*</span>
                        </label>

                        <input id="profile-lastname" name="lastname" type="text" maxlength="100"
                            autocomplete="family-name" value="<?= escapeHtml($profileFormValues['lastname'] ?? '') ?>"
                            placeholder="Enter your lastname" required>

                    </div>

                    <div class="student-profile-field">

                        <label for="profile-age">Age</label>

                        <input id="profile-age" name="age" type="number" min="1" max="120" step="1"
                            value="<?= escapeHtml($profileFormValues['age'] ?? '') ?>" placeholder="Enter your age">

                    </div>

                    <div class="student-profile-field">

                        <label for="profile-gender">Gender</label>

                        <select id="profile-gender" name="gender">

                            <option value="" <?= ($profileFormValues['gender'] ?? '') === ''
                                ? 'selected'
                                : '' ?>>
                                Select gender
                            </option>

                            <option value="Male" <?= ($profileFormValues['gender'] ?? '') === 'Male'
                                ? 'selected'
                                : '' ?>>
                                Male
                            </option>

                            <option value="Female" <?= ($profileFormValues['gender'] ?? '') === 'Female'
                                ? 'selected'
                                : '' ?>>
                                Female
                            </option>

                            <option value="Other" <?= ($profileFormValues['gender'] ?? '') === 'Other'
                                ? 'selected'
                                : '' ?>>
                                Other
                            </option>

                            <option value="Prefer not to say" <?= ($profileFormValues['gender'] ?? '') === 'Prefer not to say'
                                ? 'selected'
                                : '' ?>>
                                Prefer not to say
                            </option>

                        </select>

                    </div>

                    <div class="student-profile-field">

                        <label for="profile-mobile">Mobile Number</label>

                        <input id="profile-mobile" name="mobile_number" type="tel" maxlength="20" autocomplete="tel"
                            value="<?= escapeHtml($profileFormValues['mobile_number'] ?? '') ?>"
                            placeholder="e.g. 09171234567">

                    </div>

                    <div class="student-profile-field student-profile-full">

                        <label for="profile-address">Address</label>

                        <textarea id="profile-address" name="address" rows="3" maxlength="2000"
                            autocomplete="street-address"
                            placeholder="Enter your complete address"><?= escapeHtml($profileFormValues['address'] ?? '') ?></textarea>

                    </div>

                </div>

                <!-- ACCOUNT AND ACADEMIC INFORMATION -->

                <div class="student-profile-section-title student-profile-academic-title">

                    <i data-lucide="graduation-cap"></i>

                    <span>Account and Academic Information</span>

                </div>

                <div class="student-profile-grid">

                    <div class="student-profile-field">

                        <label for="profile-student-number">Student Number</label>

                        <input id="profile-student-number" type="text"
                            value="<?= escapeHtml($profileDefaults['student_number']) ?>" readonly>

                    </div>

                    <div class="student-profile-field">

                        <label for="profile-email">PHINMA Email</label>

                        <input id="profile-email" type="email" value="<?= escapeHtml($profileDefaults['email']) ?>"
                            readonly>

                    </div>

                    <!-- EDITABLE COURSE -->

                    <div class="student-profile-field">

                        <label for="profile-course">Course</label>

                        <input
                            id="profile-course"
                            name="course"
                            type="text"
                            maxlength="100"
                            value="<?= escapeHtml($profileFormValues['course'] ?? '') ?>"
                            placeholder="Enter your course (e.g.BSIT)"
                            autocomplete="off"
                            <?= $profileCourseLocked ? 'readonly' : '' ?>
                            aria-describedby="profile-course-help"
                        >

                        <small id="profile-course-help">

                            <?php if ($profileCourseLocked): ?>

                                Your course has been saved and is locked.

                            <?php else: ?>

                                Enter your course. Once saved, it cannot be changed through your profile.

                            <?php endif; ?>

                        </small>

                    </div>

                    <!-- YEAR LEVEL -->

                    <div class="student-profile-field">

                        <label for="profile-year-level">Year Level</label>

                        <select id="profile-year-level" name="year_level" <?= $profileYearLocked ? 'disabled' : '' ?>
                            aria-describedby="profile-year-help">

                            <option value="" <?= ($profileFormValues['year_level'] ?? '') === ''
                                ? 'selected'
                                : '' ?>>
                                Select year level
                            </option>

                            <option value="1" <?= (string) ($profileFormValues['year_level'] ?? '') === '1'
                                ? 'selected'
                                : '' ?>>
                                1st Year
                            </option>

                            <option value="2" <?= (string) ($profileFormValues['year_level'] ?? '') === '2'
                                ? 'selected'
                                : '' ?>>
                                2nd Year
                            </option>

                            <option value="3" <?= (string) ($profileFormValues['year_level'] ?? '') === '3'
                                ? 'selected'
                                : '' ?>>
                                3rd Year
                            </option>

                            <option value="4" <?= (string) ($profileFormValues['year_level'] ?? '') === '4'
                                ? 'selected'
                                : '' ?>>
                                4th Year
                            </option>

                        </select>

                        <small id="profile-year-help">

                            <?php if ($profileYearLocked): ?>

                                Your year level has been saved and is locked.

                            <?php else: ?>

                                Once saved, your year level cannot be changed through your profile.

                            <?php endif; ?>

                        </small>

                    </div>

                    <!-- ASSIGNED SECTION -->

                    <div class="student-profile-field student-profile-full">

                        <label for="profile-section">Assigned Section</label>

                        <select id="profile-section" name="section" <?= $profileSectionLocked ? 'disabled' : '' ?>
                            aria-describedby="profile-section-help">

                            <option value="" <?= ($profileFormValues['section'] ?? '') === ''
                                ? 'selected'
                                : '' ?>>
                                Select section
                            </option>

                            <?php foreach ($sectionOptions as $sectionName): ?>

                                <option value="<?= escapeHtml($sectionName) ?>" <?= ($profileFormValues['section'] ?? '') === $sectionName
                                      ? 'selected'
                                      : '' ?>>

                                    <?= escapeHtml($sectionName) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                        <small id="profile-section-help">

                            <?php if ($profileSectionLocked): ?>

                                Your assigned section has been saved and is locked.

                            <?php else: ?>

                                Select your assigned section. Once saved, your official
                                section enrollment will be created automatically and
                                the section selection will be locked.

                            <?php endif; ?>

                        </small>

                        <?php if ($profileSectionLocked): ?>

                            <small>
                                To request a change to your assigned section or year level,
                                please contact the CITE Department.
                            </small>

                        <?php endif; ?>

                    </div>

                </div>

                <!-- PROFILE ACTIONS -->

                <footer class="student-profile-actions">

                    <button type="button" class="button button-outline" data-close-profile-modal>

                        Cancel

                    </button>

                    <button type="submit" class="button button-primary" id="student-profile-save">

                        <i data-lucide="save"></i>
                        Save Changes

                    </button>

                </footer>

            </form>

        </section>

    </div>

    <!-- =====================================================
         CHANGE PASSWORD MODAL
    ====================================================== -->

    <div class="student-profile-modal student-password-modal" id="student-password-modal" <?= $openPasswordModal ? '' : 'hidden' ?>>

        <button type="button" class="student-profile-backdrop" data-close-password-modal
            aria-label="Close change password modal">
        </button>

        <section class="student-profile-dialog student-password-dialog" role="dialog" aria-modal="true"
            aria-labelledby="student-password-title" aria-describedby="student-password-description">

            <header class="student-profile-header">

                <div class="student-profile-heading">

                    <span class="student-profile-heading-icon">
                        <i data-lucide="shield-check"></i>
                    </span>

                    <div>

                        <h2 id="student-password-title">Change Password</h2>

                        <p id="student-password-description">
                            Update your account password to keep your student account secure.
                        </p>

                    </div>

                </div>

                <button type="button" class="icon-btn student-profile-close" data-close-password-modal
                    aria-label="Close change password modal">

                    <i data-lucide="x"></i>

                </button>

            </header>

            <form id="student-password-form" class="student-profile-form student-password-form" action="dashboard.php"
                method="POST" autocomplete="off">

                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">

                <input type="hidden" name="action" value="change_student_password">

                <?php if ($passwordErrorMessage !== ''): ?>

                    <div class="permit-alert permit-alert-error" role="alert">

                        <i data-lucide="alert-circle"></i>

                        <span>
                            <?= escapeHtml($passwordErrorMessage) ?>
                        </span>

                    </div>

                <?php endif; ?>

                <div class="student-profile-section-title">

                    <i data-lucide="lock-keyhole"></i>

                    <span>Password Information</span>

                </div>

                <div class="student-profile-grid">

                    <!-- CURRENT PASSWORD -->

                    <div class="student-profile-field student-profile-full">

                        <label for="current-password">
                            Current Password
                            <span class="required-mark">*</span>
                        </label>

                        <div class="student-password-input-wrap">

                            <input type="password" id="current-password" name="current_password"
                                placeholder="Enter your current password" autocomplete="current-password"
                                maxlength="255" required>

                            <button type="button" class="student-password-toggle"
                                data-toggle-password="current-password" aria-label="Show current password"
                                aria-pressed="false">

                                <i data-lucide="eye"></i>

                            </button>

                        </div>

                    </div>

                    <!-- NEW PASSWORD -->

                    <div class="student-profile-field student-profile-full">

                        <label for="new-password">
                            New Password
                            <span class="required-mark">*</span>
                        </label>

                        <div class="student-password-input-wrap">

                            <input type="password" id="new-password" name="new_password"
                                placeholder="Enter your new password" autocomplete="new-password" minlength="8"
                                maxlength="72" required>

                            <button type="button" class="student-password-toggle" data-toggle-password="new-password"
                                aria-label="Show new password" aria-pressed="false">

                                <i data-lucide="eye"></i>

                            </button>

                        </div>

                        <small>
                            Your new password must contain at least 8 characters.
                        </small>

                    </div>

                    <!-- CONFIRM PASSWORD -->

                    <div class="student-profile-field student-profile-full">

                        <label for="confirm-password">
                            Confirm New Password
                            <span class="required-mark">*</span>
                        </label>

                        <div class="student-password-input-wrap">

                            <input type="password" id="confirm-password" name="confirm_password"
                                placeholder="Re-enter your new password" autocomplete="new-password" minlength="8"
                                maxlength="72" required>

                            <button type="button" class="student-password-toggle"
                                data-toggle-password="confirm-password" aria-label="Show confirmation password"
                                aria-pressed="false">

                                <i data-lucide="eye"></i>

                            </button>

                        </div>

                        <small id="password-confirmation-help">
                            Enter the same password as above.
                        </small>

                    </div>

                </div>

                <!-- PASSWORD ACTIONS -->

                <footer class="student-profile-actions">

                    <button type="button" class="button button-outline" data-close-password-modal>

                        Cancel

                    </button>

                    <button type="submit" class="button button-primary" id="student-password-save">

                        <i data-lucide="save"></i>
                        Update Password

                    </button>

                </footer>

            </form>

        </section>

    </div>

    <!-- PROFILE SAVE NOTIFICATION -->

    <div id="student-profile-notice" data-message="<?= escapeHtml($profileNotice) ?>" hidden>
    </div>

    <!-- PASSWORD UPDATE NOTIFICATION -->

    <div id="student-password-notice" data-message="<?= escapeHtml($accountNotice) ?>" hidden>
    </div>

    <!-- SEARCH OVERLAY -->

    <div class="search-overlay" id="search-overlay" hidden>

        <button class="search-scrim" type="button" aria-label="Close search" data-close-search>
        </button>

        <section class="search-dialog" role="dialog" aria-modal="true" aria-labelledby="search-title">

            <div class="search-input-row">

                <i data-lucide="search"></i>

                <input id="search-input" type="search" placeholder="Search menu..." autocomplete="off">

                <kbd>ESC</kbd>

                <button class="icon-btn" type="button" aria-label="Close search" data-close-search>

                    <i data-lucide="x"></i>

                </button>

            </div>

            <div class="search-results-label" id="search-title">
                QUICK NAVIGATION
            </div>

            <div class="search-results" id="search-results">

                <button type="button" data-search-target="Dashboard">
                    <i data-lucide="layout-dashboard"></i>
                    <span>Dashboard</span>
                    <kbd>Enter</kbd>
                </button>

                <button type="button" data-search-target="Permit Application">
                    <i data-lucide="file-check-2"></i>
                    <span>Permit Application</span>
                </button>

                <button type="button" data-search-target="Examinations">
                    <i data-lucide="clipboard-check"></i>
                    <span>Examinations</span>
                </button>

                <button type="button" data-search-target="Study Materials">
                    <i data-lucide="book-open"></i>
                    <span>Study Materials</span>
                </button>

                <button type="button" data-search-target="GChat">
                    <i data-lucide="messages-square"></i>
                    <span>GChat</span>
                </button>

                <div class="search-no-results" hidden>
                    No matching navigation items.
                </div>

            </div>

            <div class="search-dialog-footer">
                <span>Use ↑ ↓ to browse</span>
                <span><kbd>ESC</kbd> to close</span>
            </div>

        </section>

    </div>

    <!-- TOAST -->

    <div class="toast" id="toast" role="status" aria-live="polite" hidden>

        <i data-lucide="info"></i>

        <span id="toast-message"></span>

    </div>

    <!-- UPLOAD CONFIRMATION MODAL -->

    <div class="upload-confirm-modal" id="upload-confirm-modal" hidden role="dialog" aria-modal="true"
        aria-labelledby="upload-confirm-title" aria-describedby="upload-confirm-message">

        <div class="upload-confirm-backdrop" data-close-upload-modal></div>

        <div class="upload-confirm-dialog">

            <div class="upload-confirm-icon">
                <i data-lucide="file-check"></i>
            </div>

            <h2 id="upload-confirm-title">Confirm Upload</h2>

            <p id="upload-confirm-message">
                Are you sure you want to upload this document?
            </p>

            <div class="upload-confirm-actions">

                <button type="button" class="button button-outline" id="cancel-upload-confirm">

                    Cancel

                </button>

                <button type="button" class="button button-primary" id="confirm-upload-submit">

                    <i data-lucide="upload"></i>
                    <span>Confirm Upload</span>

                </button>

            </div>

        </div>

    </div>

</body>

</html>