<?php
declare(strict_types=1);

require_once __DIR__ . '/../../auth/require_role.php';
requireRole(['faculty']);

require_once __DIR__ . '/../../config/database.php';

date_default_timezone_set('Asia/Manila');

$facultyId = (int) ($_SESSION['user_id'] ?? 0);
$facultyName = (string) ($_SESSION['nickname'] ?? 'Faculty');

if ($facultyId < 1) {
    http_response_code(403);
    exit('Access denied.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function facultyEscape($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function facultyTextLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

function facultyFormatDate(?string $value): string
{
    if (!$value) {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp !== false
        ? date('M d, Y g:i A', $timestamp)
        : '—';
}

function facultyDashboardRedirect(
    string $type,
    string $message,
    string $status,
    int $sectionId,
    string $search,
    string $page = 'sections',
    string $payment = 'all'
): void {

    if (!in_array($page, ['dashboard', 'sections'], true)) {
        $page = 'sections';
    }

    if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
        $status = 'all';
    }

    if (!in_array($payment, ['all', 'P1', 'P2', 'P3', 'full'], true)) {
        $payment = 'all';
    }

    $_SESSION['faculty_dashboard_flash'] = [
        'type' => $type,
        'message' => $message,
    ];

    $parameters = [
        'page' => $page,
        'status' => $status,
        'payment' => $payment,
    ];

    if ($sectionId > 0) {
        $parameters['section_id'] = $sectionId;
    }

    if ($search !== '') {
        $parameters['q'] = $search;
    }

    $fragment = $page === 'sections' && $sectionId > 0
        ? '#reviews'
        : '';

    header(
        'Location: dashboard.php?'
        . http_build_query($parameters)
        . $fragment
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| PAGE AND FILTERS
|--------------------------------------------------------------------------
*/

$currentPage = $_GET['page'] ?? 'dashboard';

if (
    !is_string($currentPage)
    || !in_array($currentPage, ['dashboard', 'sections'], true)
) {
    $currentPage = 'dashboard';
}

$allowedStatuses = [
    'pending',
    'approved',
    'rejected',
    'all',
];

$statusFilter = $_GET['status'] ?? 'all';

if (
    !is_string($statusFilter)
    || !in_array($statusFilter, $allowedStatuses, true)
) {
    $statusFilter = 'all';
}

$allowedPaymentFilters = [
    'all',
    'P1',
    'P2',
    'P3',
    'full',
];

$paymentFilter = $_GET['payment'] ?? 'all';

if (
    !is_string($paymentFilter)
    || !in_array($paymentFilter, $allowedPaymentFilters, true)
) {
    $paymentFilter = 'all';
}

$sectionFilterRaw = filter_var(
    $_GET['section_id'] ?? 0,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0]]
);

$sectionFilter = $sectionFilterRaw === false
    ? 0
    : (int) $sectionFilterRaw;

$searchQuery = $_GET['q'] ?? '';

$searchQuery = is_string($searchQuery)
    ? trim(substr($searchQuery, 0, 200))
    : '';

/*
|--------------------------------------------------------------------------
| BUILD RETURN URL
|--------------------------------------------------------------------------
*/

$returnParameters = [
    'page' => $currentPage,
    'status' => $statusFilter,
    'payment' => $paymentFilter,
];

if ($sectionFilter > 0) {
    $returnParameters['section_id'] = $sectionFilter;
}

if ($searchQuery !== '') {
    $returnParameters['q'] = $searchQuery;
}

$returnFragment = (
    $currentPage === 'sections'
    && $sectionFilter > 0
)
    ? '#reviews'
    : '';

$facultyReturnUrl = 'dashboard.php?'
    . http_build_query($returnParameters)
    . $returnFragment;

/*
|--------------------------------------------------------------------------
| VERIFY ACTIVE FACULTY ACCOUNT
|--------------------------------------------------------------------------
*/

$facultyCheck = $pdo->prepare(
    "SELECT nickname
     FROM users
     WHERE id = ?
       AND role = 'faculty'
       AND is_active = 1
     LIMIT 1"
);

$facultyCheck->execute([$facultyId]);

$activeFacultyName = $facultyCheck->fetchColumn();

if ($activeFacultyName === false) {
    http_response_code(403);
    exit('Your faculty account is unavailable or inactive.');
}

$facultyName = (string) $activeFacultyName;

/*
|--------------------------------------------------------------------------
| LOAD FACULTY PROFILE
|--------------------------------------------------------------------------
*/

$facultyProfileQuery = $pdo->prepare(
    "SELECT
        u.nickname,
        u.email,
        u.profile_picture,

        ui.firstname,
        ui.middlename,
        ui.lastname,
        ui.age,
        ui.address,
        ui.gender,
        ui.mobile_number

     FROM users u

     LEFT JOIN user_information ui
        ON ui.user_id = u.id

     WHERE u.id = ?
       AND u.role = 'faculty'
       AND u.is_active = 1

     LIMIT 1"
);

$facultyProfileQuery->execute([$facultyId]);

$facultyProfile = $facultyProfileQuery->fetch(PDO::FETCH_ASSOC);

if (!$facultyProfile) {
    http_response_code(403);
    exit('Your faculty profile is unavailable.');
}

$facultyProfilePictureFilename = basename(
    trim((string) ($facultyProfile['profile_picture'] ?? ''))
);

$facultyProfilePictureUrl = $facultyProfilePictureFilename !== ''
    ? '../../uploads/profile_pictures/'
    . rawurlencode($facultyProfilePictureFilename)
    : '';

$facultyInitial = strtoupper(
    substr($facultyName !== '' ? $facultyName : 'F', 0, 1)
);

$profileDefaults = [
    'firstname' => (string) ($facultyProfile['firstname'] ?? ''),
    'middlename' => (string) ($facultyProfile['middlename'] ?? ''),
    'lastname' => (string) ($facultyProfile['lastname'] ?? ''),
    'age' => $facultyProfile['age'] !== null
        ? (string) $facultyProfile['age']
        : '',
    'address' => (string) ($facultyProfile['address'] ?? ''),
    'gender' => (string) ($facultyProfile['gender'] ?? ''),
    'mobile_number' => (string) ($facultyProfile['mobile_number'] ?? ''),
    'email' => (string) ($facultyProfile['email'] ?? ''),
];

/*
|--------------------------------------------------------------------------
| PROFILE AND PASSWORD MODAL STATES
|--------------------------------------------------------------------------
*/

$profileErrorMessage = '';
$passwordErrorMessage = '';

$profileFormValues = $profileDefaults;

$openProfileModal = (
    isset($_GET['open_profile'])
    && $_GET['open_profile'] === '1'
);

$openPasswordModal = (
    isset($_GET['open_password'])
    && $_GET['open_password'] === '1'
);

/*
|--------------------------------------------------------------------------
| SAVE FACULTY PROFILE
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'save_faculty_profile'
) {

    $openProfileModal = true;

    $readProfilePost = static function (string $key): string {

        $value = $_POST[$key] ?? '';

        return is_string($value)
            ? trim($value)
            : '';
    };

    $profileFormValues = [
        'firstname' => $readProfilePost('firstname'),
        'middlename' => $readProfilePost('middlename'),
        'lastname' => $readProfilePost('lastname'),
        'age' => $readProfilePost('age'),
        'address' => $readProfilePost('address'),
        'gender' => $readProfilePost('gender'),
        'mobile_number' => $readProfilePost('mobile_number'),
        'email' => $profileDefaults['email'],
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
            facultyTextLength($firstName) > 100
            || facultyTextLength($middleName) > 100
            || facultyTextLength($lastName) > 100
        ) {

            $profileErrorMessage =
                'Names must not exceed 100 characters.';

        } elseif (facultyTextLength($address) > 2000) {

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

        } else {

            try {

                $age = $ageInput === ''
                    ? null
                    : (int) $ageInput;

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
                        mobile_number
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)

                    ON DUPLICATE KEY UPDATE
                        firstname = VALUES(firstname),
                        middlename = VALUES(middlename),
                        lastname = VALUES(lastname),
                        age = VALUES(age),
                        address = VALUES(address),
                        gender = VALUES(gender),
                        mobile_number = VALUES(mobile_number)"
                );

                $profileSave->execute([
                    $facultyId,
                    $firstName,
                    $middleName !== '' ? $middleName : null,
                    $lastName,
                    $age,
                    $address !== '' ? $address : null,
                    $gender !== '' ? $gender : null,
                    $mobileNumber !== '' ? $mobileNumber : null,
                ]);

                $pdo->commit();

                $_SESSION['faculty_profile_notice'] =
                    'Your profile was updated successfully.';

                header('Location: ' . $facultyReturnUrl);
                exit;

            } catch (Throwable $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'AcadTrack faculty profile update error: '
                    . $exception->getMessage()
                );

                $profileErrorMessage =
                    'Unable to save your profile. Please try again.';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| CHANGE FACULTY PASSWORD
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'change_faculty_password'
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

    } elseif (facultyTextLength($newPassword) < 8) {

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
                   AND role = 'faculty'
                   AND is_active = 1
                 LIMIT 1"
            );

            $passwordQuery->execute([$facultyId]);

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
                       AND role = 'faculty'
                       AND is_active = 1"
                );

                $passwordUpdate->execute([
                    $newPasswordHash,
                    $facultyId,
                ]);

                if ($passwordUpdate->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Password update failed.'
                    );
                }

                $_SESSION['faculty_password_notice'] =
                    'Your password has been changed successfully.';

                session_regenerate_id(true);

                header('Location: ' . $facultyReturnUrl);
                exit;
            }

        } catch (Throwable $exception) {

            error_log(
                'AcadTrack faculty password update error: '
                . $exception->getMessage()
            );

            $passwordErrorMessage =
                'Unable to update your password. Please try again.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| VALIDATE UNKNOWN POST ACTIONS
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && !in_array(
        $_POST['action'] ?? '',
        [
            'save_faculty_profile',
            'change_faculty_password',
            'review_submission',
        ],
        true
    )
) {

    facultyDashboardRedirect(
        'error',
        'Invalid request.',
        $statusFilter,
        $sectionFilter,
        $searchQuery,
        $currentPage,
        $paymentFilter
    );
}

/*
|--------------------------------------------------------------------------
| APPROVE OR REJECT PERMIT SUBMISSION
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'review_submission'
) {

    $postedToken = $_POST['csrf_token'] ?? '';

    $returnPage = $_POST['return_page'] ?? 'sections';

    if (
        !is_string($returnPage)
        || !in_array($returnPage, ['dashboard', 'sections'], true)
    ) {
        $returnPage = 'sections';
    }

    $returnStatus = $_POST['return_status'] ?? 'all';

    if (
        !is_string($returnStatus)
        || !in_array($returnStatus, $allowedStatuses, true)
    ) {
        $returnStatus = 'all';
    }

    $returnPayment = $_POST['return_payment'] ?? 'all';

    if (
        !is_string($returnPayment)
        || !in_array($returnPayment, $allowedPaymentFilters, true)
    ) {
        $returnPayment = 'all';
    }

    $returnSectionRaw = filter_var(
        $_POST['return_section_id'] ?? 0,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );

    $returnSectionId = $returnSectionRaw === false
        ? 0
        : (int) $returnSectionRaw;

    $returnSearch = $_POST['return_q'] ?? '';

    $returnSearch = is_string($returnSearch)
        ? trim(substr($returnSearch, 0, 200))
        : '';

    if (
        !is_string($postedToken)
        || !hash_equals($csrfToken, $postedToken)
    ) {

        facultyDashboardRedirect(
            'error',
            'Your session token is invalid. Refresh the page and try again.',
            $returnStatus,
            $returnSectionId,
            $returnSearch,
            $returnPage,
            $returnPayment
        );
    }

    $action = $_POST['action'] ?? '';

    if ($action !== 'review_submission') {

        facultyDashboardRedirect(
            'error',
            'Invalid request.',
            $returnStatus,
            $returnSectionId,
            $returnSearch,
            $returnPage,
            $returnPayment
        );
    }

    $submissionId = filter_var(
        $_POST['submission_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $decision = $_POST['decision'] ?? '';

    $reviewNoteRaw = $_POST['review_note'] ?? '';

    $reviewNote = is_string($reviewNoteRaw)
        ? trim($reviewNoteRaw)
        : '';

    if (
        !$submissionId
        || !is_string($decision)
        || !in_array($decision, ['approved', 'rejected'], true)
    ) {

        facultyDashboardRedirect(
            'error',
            'Invalid submission or review decision.',
            $returnStatus,
            $returnSectionId,
            $returnSearch,
            $returnPage,
            $returnPayment
        );
    }

    if (facultyTextLength($reviewNote) > 500) {

        facultyDashboardRedirect(
            'error',
            'The review note cannot exceed 500 characters.',
            $returnStatus,
            $returnSectionId,
            $returnSearch,
            $returnPage,
            $returnPayment
        );
    }

    try {

        $pdo->beginTransaction();

        $submissionCheck = $pdo->prepare(
            "SELECT
                ps.id,
                ps.student_id,
                ps.payment_type,
                ps.period,
                ps.approval_status,
                s.id AS section_id

             FROM permit_submissions ps

             INNER JOIN section_students ss
                ON ss.student_id = ps.student_id

             INNER JOIN sections s
                ON s.id = ss.section_id

             WHERE ps.id = ?
               AND s.ssp_adviser_id = ?

             LIMIT 1
             FOR UPDATE"
        );

        $submissionCheck->execute([
            $submissionId,
            $facultyId,
        ]);

        $submission = $submissionCheck->fetch(PDO::FETCH_ASSOC);

        if (!$submission) {

            $pdo->rollBack();

            facultyDashboardRedirect(
                'error',
                'Submission not found or you are not the assigned SSP Adviser.',
                $returnStatus,
                $returnSectionId,
                $returnSearch,
                $returnPage,
                $returnPayment
            );
        }

        if ($submission['approval_status'] !== 'pending') {

            $pdo->rollBack();

            facultyDashboardRedirect(
                'error',
                'This submission has already been reviewed.',
                $returnStatus,
                $returnSectionId,
                $returnSearch,
                $returnPage,
                $returnPayment
            );
        }

        $reviewUpdate = $pdo->prepare(
            "UPDATE permit_submissions
             SET approval_status = ?,
                 reviewed_by = ?,
                 reviewed_at = NOW(),
                 review_note = ?
             WHERE id = ?
               AND approval_status = 'pending'"
        );

        $reviewUpdate->execute([
            $decision,
            $facultyId,
            $reviewNote !== '' ? $reviewNote : null,
            $submissionId,
        ]);

        if ($reviewUpdate->rowCount() !== 1) {
            throw new RuntimeException(
                'The submission could not be updated. Please try again.'
            );
        }

        if ($decision === 'approved') {

            $statusUpdate = $pdo->prepare(
                "INSERT INTO student_permit_status
                    (student_id, period, status, submission_id)

                 VALUES (?, ?, 'with permit', ?)

                 ON DUPLICATE KEY UPDATE
                    status = 'with permit',
                    submission_id = VALUES(submission_id)"
            );

            if ($submission['payment_type'] === 'full') {

                foreach (['P1', 'P2', 'P3'] as $period) {

                    $statusUpdate->execute([
                        (int) $submission['student_id'],
                        $period,
                        $submissionId,
                    ]);
                }

            } else {

                $statusUpdate->execute([
                    (int) $submission['student_id'],
                    $submission['period'],
                    $submissionId,
                ]);
            }

        } else {

            $resetStatus = $pdo->prepare(
                "UPDATE student_permit_status
                 SET status = 'no permit',
                     submission_id = NULL
                 WHERE student_id = ?
                   AND submission_id = ?"
            );

            $resetStatus->execute([
                (int) $submission['student_id'],
                $submissionId,
            ]);
        }

        $pdo->commit();

        $message = $decision === 'approved'
            ? 'Permit submission approved successfully.'
            : 'Permit submission rejected successfully.';

        facultyDashboardRedirect(
            'success',
            $message,
            $returnStatus,
            $returnSectionId,
            $returnSearch,
            $returnPage,
            $returnPayment
        );

    } catch (Throwable $exception) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'AcadTrack faculty permit review error: '
            . $exception->getMessage()
        );

        facultyDashboardRedirect(
            'error',
            'Unable to review this submission. Please try again.',
            $returnStatus,
            $returnSectionId,
            $returnSearch,
            $returnPage,
            $returnPayment
        );
    }
}

/*
|--------------------------------------------------------------------------
| ONE-TIME NOTIFICATIONS
|--------------------------------------------------------------------------
*/

$flash = $_SESSION['faculty_dashboard_flash'] ?? null;

unset($_SESSION['faculty_dashboard_flash']);

$successMessage = '';
$errorMessage = '';

if (is_array($flash)) {

    if (($flash['type'] ?? '') === 'success') {
        $successMessage = (string) ($flash['message'] ?? '');
    } else {
        $errorMessage = (string) ($flash['message'] ?? '');
    }
}

$profileNotice = $_SESSION['faculty_profile_notice'] ?? '';
unset($_SESSION['faculty_profile_notice']);

$passwordNotice = $_SESSION['faculty_password_notice'] ?? '';
unset($_SESSION['faculty_password_notice']);

$profilePictureErrorMessage =
    $_SESSION['faculty_profile_picture_error'] ?? '';

unset($_SESSION['faculty_profile_picture_error']);

/*
|--------------------------------------------------------------------------
| LOAD ASSIGNED SECTIONS
|--------------------------------------------------------------------------
*/

$sections = [];
$submissions = [];

$statusCounts = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
];

$selectedSectionName = '';

try {

    $sectionQuery = $pdo->prepare(
        "SELECT
            s.id,
            s.section_name,
            s.course,
            s.year_level,
            COUNT(ss.student_id) AS student_count

         FROM sections s

         LEFT JOIN section_students ss
            ON ss.section_id = s.id

         WHERE s.ssp_adviser_id = ?

         GROUP BY
            s.id,
            s.section_name,
            s.course,
            s.year_level

         ORDER BY
            s.course ASC,
            s.year_level ASC,
            s.section_name ASC"
    );

    $sectionQuery->execute([$facultyId]);

    $sections = $sectionQuery->fetchAll(PDO::FETCH_ASSOC);

    $ownedSectionIds = array_map(
        static fn(array $section): int => (int) $section['id'],
        $sections
    );

    if (
        $sectionFilter > 0
        && !in_array($sectionFilter, $ownedSectionIds, true)
    ) {

        $sectionFilter = 0;

        $errorMessage =
            'The selected section is not assigned to your faculty account.';
    }

    foreach ($sections as $section) {

        if ((int) $section['id'] === $sectionFilter) {
            $selectedSectionName = (string) $section['section_name'];
            break;
        }
    }

    $countQuery = $pdo->prepare(
        "SELECT
            ps.approval_status,
            COUNT(*) AS total

         FROM permit_submissions ps

         INNER JOIN section_students ss
            ON ss.student_id = ps.student_id

         INNER JOIN sections s
            ON s.id = ss.section_id

         WHERE s.ssp_adviser_id = ?

         GROUP BY ps.approval_status"
    );

    $countQuery->execute([$facultyId]);

    foreach ($countQuery->fetchAll(PDO::FETCH_ASSOC) as $countRow) {

        $status = (string) $countRow['approval_status'];

        if (isset($statusCounts[$status])) {
            $statusCounts[$status] = (int) $countRow['total'];
        }
    }

    /*
     * LOAD ALL ENROLLED STUDENTS IN THE SELECTED SECTION.
     */

    if ($currentPage === 'sections' && $sectionFilter > 0) {

        $sql = "
            SELECT
                ps.id,
                ps.student_id,
                ps.payment_type,
                ps.period,
                ps.original_filename,
                ps.uploaded_at,
                ps.approval_status,
                ps.review_note,
                ps.reviewed_at,

                u.nickname AS student_nickname,
                u.student_number,

                ui.firstname,
                ui.middlename,
                ui.lastname,

                s.id AS section_id,
                s.section_name,
                s.course,
                s.year_level,

                reviewer.nickname AS reviewer_name

            FROM section_students ss

            INNER JOIN sections s
                ON s.id = ss.section_id

            INNER JOIN users u
                ON u.id = ss.student_id
                AND u.role = 'student'

            LEFT JOIN user_information ui
                ON ui.user_id = u.id

            LEFT JOIN permit_submissions ps
                ON ps.student_id = u.id

            LEFT JOIN users reviewer
                ON reviewer.id = ps.reviewed_by

            WHERE s.ssp_adviser_id = :faculty_id
              AND s.id = :section_id
        ";

        $parameters = [
            ':faculty_id' => $facultyId,
            ':section_id' => $sectionFilter,
        ];

        if ($statusFilter !== 'all') {

            $sql .= "
                AND ps.approval_status = :status
            ";

            $parameters[':status'] = $statusFilter;
        }

        if ($paymentFilter === 'full') {

            $sql .= "
                AND ps.payment_type = 'full'
            ";

        } elseif (in_array($paymentFilter, ['P1', 'P2', 'P3'], true)) {

            $sql .= "
                AND ps.period = :payment_period
                AND (
                    ps.payment_type IS NULL
                    OR ps.payment_type <> 'full'
                )
            ";

            $parameters[':payment_period'] = $paymentFilter;
        }

        if ($searchQuery !== '') {

            $sql .= "
                AND (
                    u.student_number LIKE :search_number

                    OR ui.firstname LIKE :search_firstname

                    OR ui.lastname LIKE :search_lastname

                    OR CONCAT_WS(
                        ' ',
                        NULLIF(TRIM(ui.firstname), ''),
                        NULLIF(TRIM(ui.lastname), '')
                    ) LIKE :search_fullname
                )
            ";

            $searchPattern = '%' . $searchQuery . '%';

            $parameters[':search_number'] = $searchPattern;
            $parameters[':search_firstname'] = $searchPattern;
            $parameters[':search_lastname'] = $searchPattern;
            $parameters[':search_fullname'] = $searchPattern;
        }

        $sql .= "
            ORDER BY
                CASE
                    WHEN ps.id IS NULL THEN 1
                    ELSE 0
                END,

                FIELD(
                    ps.approval_status,
                    'pending',
                    'rejected',
                    'approved'
                ),

                ps.uploaded_at DESC,

                u.student_number ASC
        ";

        $submissionQuery = $pdo->prepare($sql);

        $submissionQuery->execute($parameters);

        $submissions = $submissionQuery->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $exception) {

    error_log(
        'AcadTrack faculty dashboard query error: '
        . $exception->getMessage()
    );

    $sections = [];
    $submissions = [];

    $statusCounts = [
        'pending' => 0,
        'approved' => 0,
        'rejected' => 0,
    ];

    $selectedSectionName = '';

    $errorMessage = $errorMessage
        ?: 'Unable to load faculty dashboard data. Please check the database connection.';
}

/*
|--------------------------------------------------------------------------
| DISPLAY DATA
|--------------------------------------------------------------------------
*/

$displayedStudentCount = count(
    array_unique(
        array_map(
            static fn(array $submission): int => (int) $submission['student_id'],
            $submissions
        )
    )
);

$yearLabels = [
    1 => '1st Year',
    2 => '2nd Year',
    3 => '3rd Year',
    4 => '4th Year',
];

$statusLabels = [
    'pending' => 'Pending Review',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
];

/*
|--------------------------------------------------------------------------
| PROFILE FORM VALUES
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    || ($_POST['action'] ?? '') !== 'save_faculty_profile'
) {
    $profileFormValues = $profileDefaults;
}

?>
<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#ffffff">

    <title>
        <?= $currentPage === 'dashboard'
            ? 'AcadTrack | Faculty Dashboard'
            : 'AcadTrack | Sections and Permit' ?>
    </title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://unpkg.com/lucide@latest"></script>

    <link rel="stylesheet" href="../../style/faculty/faculty_dash.css">

    <script src="../../js/faculty/faculty_dash.js" defer></script>
    <script src="../../js/auth/session_guard.js" defer></script>

</head>

<body>

    <div class="app" id="app">

        <div class="mobile-scrim" data-close-sidebar></div>

        <!-- SIDEBAR -->

        <aside class="sidebar" id="sidebar" aria-label="Faculty sidebar">

            <div class="brand-area">

                <a href="dashboard.php?page=dashboard" class="brand" aria-label="AcadTrack faculty dashboard">

                    <span class="brand-symbol">
                        <i data-lucide="graduation-cap"></i>
                    </span>

                    <span class="brand-word">
                        Acad<span>Track</span>
                    </span>

                </a>

                <button class="icon-btn mobile-close" type="button" aria-label="Close sidebar" data-close-sidebar>
                    <i data-lucide="x"></i>
                </button>

            </div>

            <div class="sidebar-content">

                <div class="nav-section">

                    <div class="section-caption">FACULTY</div>

                    <a href="dashboard.php?page=dashboard"
                        class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>" data-nav-link
                        <?= $currentPage === 'dashboard' ? 'aria-current="page"' : '' ?>>

                        <i data-lucide="layout-dashboard"></i>
                        <span class="nav-text">Dashboard</span>

                    </a>

                    <a href="dashboard.php?page=sections"
                        class="nav-item <?= $currentPage === 'sections' ? 'active' : '' ?>" data-nav-link
                        <?= $currentPage === 'sections' ? 'aria-current="page"' : '' ?>>

                        <i data-lucide="users-round"></i>
                        <span class="nav-text">Sections and Permit</span>

                    </a>

                </div>

                <div class="nav-section">

                    <!-- <div class="section-caption">ACCOUNT</div>

                    <a href="#" class="nav-item" data-open-profile-modal>

                        <i data-lucide="user-round"></i>
                        <span class="nav-text">My Profile</span>

                    </a>

                    <a href="#" class="nav-item" data-toast="Help Center will be connected later.">

                        <i data-lucide="life-buoy"></i>
                        <span class="nav-text">Help Center</span>

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
                        <span>Contact Program Head</span>
                    </div>

                    <button type="button" class="help-open" aria-label="Open help"
                        data-toast="Help Center will be connected later.">

                        <i data-lucide="arrow-up-right"></i>

                    </button>

                </div>

                <div class="sidebar-brand-footer">
                    PHINMA Education
                </div>

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

                        <span>Faculty Portal</span>

                        <i data-lucide="chevron-right"></i>

                        <strong id="breadcrumb-current">

                            <?= $currentPage === 'dashboard'
                                ? 'Dashboard'
                                : 'Sections and Permit' ?>

                        </strong>

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
                            data-dropdown-toggle="faculty-profile-menu">

                            <span class="profile-avatar">

                                <?php if ($facultyProfilePictureUrl !== ''): ?>

                                    <img src="<?= facultyEscape($facultyProfilePictureUrl) ?>"
                                        alt="Faculty profile picture">

                                <?php else: ?>

                                    <?= facultyEscape($facultyInitial) ?>

                                <?php endif; ?>

                            </span>

                            <span class="profile-info">

                                <strong><?= facultyEscape($facultyName) ?></strong>

                                <small>SSP Adviser</small>

                            </span>

                            <i class="profile-chevron" data-lucide="chevron-down"></i>

                        </button>

                        <div class="dropdown profile-menu" id="faculty-profile-menu" hidden>

                            <div class="profile-menu-user">

                                <span class="profile-avatar large">

                                    <?php if ($facultyProfilePictureUrl !== ''): ?>

                                        <img src="<?= facultyEscape($facultyProfilePictureUrl) ?>"
                                            alt="Faculty profile picture">

                                    <?php else: ?>

                                        <?= facultyEscape($facultyInitial) ?>

                                    <?php endif; ?>

                                </span>

                                <div>

                                    <strong><?= facultyEscape($facultyName) ?></strong>

                                    <small>Faculty account</small>

                                </div>

                            </div>

                            <a href="#" data-open-profile-modal>

                                <i data-lucide="user-round-pen"></i>

                                Edit Profile

                            </a>

                            <a href="#" data-open-password-modal>

                                <i data-lucide="settings-2"></i>

                                Account Settings

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

            <!-- MAIN CONTENT -->

            <main class="page faculty-page">

                <section class="dashboard-page">

                    <!-- PAGE TITLE -->

                    <div class="page-title-row">

                        <div>

                            <div class="overline">
                                FACULTY WORKSPACE
                            </div>

                            <h1>

                                <?= $currentPage === 'dashboard'
                                    ? 'Dashboard Overview'
                                    : 'Sections and Permit' ?>

                            </h1>

                            <p class="page-subtitle">

                                <?= $currentPage === 'dashboard'
                                    ? 'Welcome, ' . facultyEscape($facultyName) . '. Here is your permit review summary.'
                                    : 'View your assigned sections and review student permit submissions.' ?>

                            </p>

                        </div>

                    </div>

                    <!-- NOTIFICATIONS -->

                    <?php if ($successMessage !== ''): ?>

                        <div class="admin-alert admin-alert-success" role="status">

                            <i data-lucide="check-circle"></i>

                            <span>
                                <?= facultyEscape($successMessage) ?>
                            </span>

                        </div>

                    <?php endif; ?>

                    <?php if ($errorMessage !== ''): ?>

                        <div class="admin-alert admin-alert-error" role="alert">

                            <i data-lucide="alert-circle"></i>

                            <span>
                                <?= facultyEscape($errorMessage) ?>
                            </span>

                        </div>

                    <?php endif; ?>

                    <?php if ($currentPage === 'dashboard'): ?>

                        <!-- DASHBOARD SUMMARY ONLY -->

                        <div class="admin-stats-grid faculty-stats-grid">

                            <article class="admin-stat-card">

                                <span class="admin-stat-icon">
                                    <i data-lucide="users-round"></i>
                                </span>

                                <div>

                                    <span class="admin-stat-label">
                                        Assigned Sections
                                    </span>

                                    <strong>
                                        <?= count($sections) ?>
                                    </strong>

                                </div>

                            </article>

                            <article class="admin-stat-card">

                                <span class="admin-stat-icon stat-warning">
                                    <i data-lucide="clock-3"></i>
                                </span>

                                <div>

                                    <span class="admin-stat-label">
                                        Pending Reviews
                                    </span>

                                    <strong>
                                        <?= $statusCounts['pending'] ?>
                                    </strong>

                                </div>

                            </article>

                            <article class="admin-stat-card">

                                <span class="admin-stat-icon stat-success">
                                    <i data-lucide="file-check-2"></i>
                                </span>

                                <div>

                                    <span class="admin-stat-label">
                                        Approved Submissions
                                    </span>

                                    <strong>
                                        <?= $statusCounts['approved'] ?>
                                    </strong>

                                </div>

                            </article>

                            <article class="admin-stat-card">

                                <span class="admin-stat-icon stat-warning">
                                    <i data-lucide="file-x"></i>
                                </span>

                                <div>

                                    <span class="admin-stat-label">
                                        Rejected Submissions
                                    </span>

                                    <strong>
                                        <?= $statusCounts['rejected'] ?>
                                    </strong>

                                </div>

                            </article>

                        </div>

                    <?php else: ?>

                        <!-- SECTIONS AND PERMIT -->

                        <section class="admin-panel faculty-section-panel" id="sections">

                            <div class="admin-panel-heading">

                                <div>

                                    <h2>My Assigned Sections</h2>

                                    <p>
                                        Only sections assigned to you as SSP Adviser are shown.
                                    </p>

                                </div>

                                <span class="admin-count">

                                    <?= count($sections) ?>

                                    section<?= count($sections) === 1 ? '' : 's' ?>

                                </span>

                            </div>

                            <?php if (empty($sections)): ?>

                                <div class="faculty-empty-state">

                                    <span>
                                        <i data-lucide="users-round"></i>
                                    </span>

                                    <strong>
                                        No assigned sections yet
                                    </strong>

                                    <p>
                                        Your sections will appear here once an administrator
                                        assigns you as their SSP Adviser.
                                    </p>

                                </div>

                            <?php else: ?>

                                <div class="faculty-section-grid">

                                    <?php foreach ($sections as $section): ?>

                                        <?php

                                        $sectionId = (int) $section['id'];

                                        $yearLevel = (int) $section['year_level'];

                                        $yearLabel = $yearLabels[$yearLevel]
                                            ?? 'Year ' . $yearLevel;

                                        $isSelected = $sectionFilter === $sectionId;

                                        ?>

                                        <article class="faculty-section-card <?= $isSelected ? 'is-selected' : '' ?>">

                                            <span class="faculty-section-icon">
                                                <i data-lucide="users-round"></i>
                                            </span>

                                            <div class="faculty-section-info">

                                                <h3>
                                                    <?= facultyEscape($section['section_name']) ?>
                                                </h3>

                                                <p>

                                                    <?= facultyEscape($section['course']) ?>

                                                    ·

                                                    <?= facultyEscape($yearLabel) ?>

                                                </p>

                                                <span class="faculty-section-student-count">

                                                    <i data-lucide="users"></i>

                                                    <?= (int) $section['student_count'] ?>

                                                    students

                                                </span>

                                            </div>

                                            <a class="faculty-section-link"
                                                href="dashboard.php?page=sections&amp;section_id=<?= $sectionId ?>#reviews"
                                                aria-label="Review permits for <?= facultyEscape($section['section_name']) ?>">

                                                Review Permits

                                                <i data-lucide="arrow-right"></i>

                                            </a>

                                        </article>

                                    <?php endforeach; ?>

                                </div>

                            <?php endif; ?>

                        </section>

                        <!-- STUDENT LIST -->

                        <?php if ($sectionFilter > 0): ?>

                            <section class="admin-panel faculty-review-panel" id="reviews">

                                <div class="admin-panel-heading faculty-review-heading">

                                    <div>

                                        <h2>
                                            Students and Permit Submissions
                                        </h2>

                                        <p>
                                            Section:

                                            <strong>
                                                <?= facultyEscape($selectedSectionName) ?>
                                            </strong>
                                        </p>

                                    </div>

                                    <span class="admin-count">

                                        <?= $displayedStudentCount ?>

                                        student<?= $displayedStudentCount === 1 ? '' : 's' ?>

                                    </span>

                                </div>

                                <!-- SEARCH AND FILTERS -->

                                <form class="faculty-filter-form" method="GET" action="dashboard.php#reviews">

                                    <input type="hidden" name="page" value="sections">

                                    <input type="hidden" name="section_id" value="<?= $sectionFilter ?>">

                                    <div class="faculty-filter-field faculty-filter-search">

                                        <label for="faculty-search">
                                            Search students
                                        </label>

                                        <input type="search" name="q" id="faculty-search"
                                            value="<?= facultyEscape($searchQuery) ?>"
                                            placeholder="Student number, first name, or last name..." maxlength="200"
                                            autocomplete="off">

                                    </div>

                                    <div class="faculty-filter-field faculty-filter-status">

                                        <label for="faculty-status">
                                            Filter
                                        </label>

                                        <select name="status" id="faculty-status">

                                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>
                                                All Students
                                            </option>

                                            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>
                                                Pending
                                            </option>

                                            <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>
                                                Approved
                                            </option>

                                            <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>
                                                Rejected
                                            </option>

                                        </select>

                                    </div>

                                    <div class="faculty-filter-field faculty-filter-payment">

                                        <label for="faculty-payment">
                                            Payment
                                        </label>

                                        <select name="payment" id="faculty-payment">

                                            <option value="all" <?= $paymentFilter === 'all' ? 'selected' : '' ?>>
                                                All Payments
                                            </option>

                                            <option value="P1" <?= $paymentFilter === 'P1' ? 'selected' : '' ?>>
                                                P1
                                            </option>

                                            <option value="P2" <?= $paymentFilter === 'P2' ? 'selected' : '' ?>>
                                                P2
                                            </option>

                                            <option value="P3" <?= $paymentFilter === 'P3' ? 'selected' : '' ?>>
                                                P3
                                            </option>

                                            <option value="full" <?= $paymentFilter === 'full' ? 'selected' : '' ?>>
                                                Full Payment
                                            </option>

                                        </select>

                                    </div>

                                    <!-- FACULTY EXPORT DROPDOWN -->

                                    <div class="faculty-export-wrap">

                                        <button type="button" class="button button-secondary faculty-export-toggle"
                                            data-faculty-export-toggle aria-haspopup="true" aria-expanded="false">

                                            <i data-lucide="download"></i>

                                            <span>Export</span>

                                            <i data-lucide="chevron-down" class="faculty-export-chevron"></i>

                                        </button>

                                        <div class="faculty-export-menu" role="menu"
                                            aria-label="Export permit submission report" hidden>

                                            <button type="button" class="faculty-export-option" data-faculty-export="pdf"
                                                role="menuitem">

                                                <i data-lucide="file-text"></i>

                                                <span>Export as PDF</span>

                                            </button>

                                            <button type="button" class="faculty-export-option" data-faculty-export="excel"
                                                role="menuitem">

                                                <i data-lucide="file-spreadsheet"></i>

                                                <span>Export as Excel</span>

                                            </button>

                                            <button type="button" class="faculty-export-option" data-faculty-export="word"
                                                role="menuitem">

                                                <i data-lucide="file-type"></i>

                                                <span>Export as Word</span>

                                            </button>

                                        </div>

                                    </div>

                                </form>

                                <!-- STUDENT PERMIT TABLE -->

                                <div class="faculty-table-wrap">

                                    <?php if (empty($submissions)): ?>

                                        <div class="faculty-empty-state">

                                            <span>
                                                <i data-lucide="users-round"></i>
                                            </span>

                                            <strong>
                                                No students found
                                            </strong>

                                            <p>
                                                No enrolled students match the selected filters
                                                or search term. Try another filter or search term.
                                            </p>

                                        </div>

                                    <?php else: ?>

                                        <table class="faculty-submission-table">

                                            <thead>

                                                <tr>

                                                    <th scope="col">
                                                        Student Number
                                                    </th>

                                                    <th scope="col">
                                                        Firstname
                                                    </th>

                                                    <th scope="col">
                                                        Lastname
                                                    </th>

                                                    <th scope="col">
                                                        Uploaded File
                                                    </th>

                                                    <th scope="col">
                                                        Payment
                                                    </th>

                                                    <th scope="col">
                                                        Date Submitted
                                                    </th>

                                                    <th scope="col" class="faculty-actions-column">
                                                        Actions
                                                    </th>

                                                </tr>

                                            </thead>

                                            <tbody>

                                                <?php foreach ($submissions as $submission): ?>

                                                    <?php

                                                    $hasSubmission = !empty($submission['id']);

                                                    $decisionStatus = (string) (
                                                        $submission['approval_status'] ?? ''
                                                    );

                                                    $firstName = trim(
                                                        (string) ($submission['firstname'] ?? '')
                                                    );

                                                    $lastName = trim(
                                                        (string) ($submission['lastname'] ?? '')
                                                    );

                                                    if ($firstName === '') {
                                                        $firstName = '—';
                                                    }

                                                    if ($lastName === '') {
                                                        $lastName = '—';
                                                    }

                                                    $paymentLabel = '—';

                                                    if ($hasSubmission) {

                                                        $paymentLabel = $submission['payment_type'] === 'full'
                                                            ? 'Full Payment (P1–P3)'
                                                            : 'Period ' . (string) $submission['period'];
                                                    }

                                                    $statusLabel = $statusLabels[$decisionStatus]
                                                        ?? ucfirst($decisionStatus);

                                                    $submissionId = (int) ($submission['id'] ?? 0);

                                                    $originalFilename = trim(
                                                        (string) ($submission['original_filename'] ?? '')
                                                    );

                                                    ?>

                                                    <tr>

                                                        <td class="faculty-student-number">

                                                            <?= facultyEscape(
                                                                $submission['student_number'] ?: 'Not provided'
                                                            ) ?>

                                                        </td>

                                                        <td>
                                                            <?= facultyEscape($firstName) ?>
                                                        </td>

                                                        <td>
                                                            <?= facultyEscape($lastName) ?>
                                                        </td>

                                                        <td class="faculty-file-cell">

                                                            <?php if ($hasSubmission && $originalFilename !== ''): ?>

                                                                <a class="faculty-file-link" href="view_permit.php?id=<?= $submissionId ?>"
                                                                    target="_blank" rel="noopener noreferrer"
                                                                    title="View uploaded document">

                                                                    <i data-lucide="file-text"></i>

                                                                    <span>
                                                                        <?= facultyEscape($originalFilename) ?>
                                                                    </span>

                                                                    <i data-lucide="external-link"></i>

                                                                </a>

                                                            <?php else: ?>

                                                                <span class="faculty-no-file">
                                                                    No file uploaded
                                                                </span>

                                                            <?php endif; ?>

                                                        </td>

                                                        <td>

                                                            <span class="faculty-payment-label">
                                                                <?= facultyEscape($paymentLabel) ?>
                                                            </span>

                                                        </td>

                                                        <td class="faculty-date-cell">

                                                            <?= facultyEscape(
                                                                facultyFormatDate(
                                                                    $submission['uploaded_at'] ?? null
                                                                )
                                                            ) ?>

                                                        </td>

                                                        <td class="faculty-actions-cell">

                                                            <?php if (
                                                                $hasSubmission
                                                                && $decisionStatus === 'pending'
                                                            ): ?>

                                                                <form class="faculty-review-form" method="POST"
                                                                    action="dashboard.php?page=sections#reviews">

                                                                    <input type="hidden" name="csrf_token"
                                                                        value="<?= facultyEscape($csrfToken) ?>">

                                                                    <input type="hidden" name="action" value="review_submission">

                                                                    <input type="hidden" name="submission_id" value="<?= $submissionId ?>">

                                                                    <input type="hidden" name="return_page" value="sections">

                                                                    <input type="hidden" name="return_status"
                                                                        value="<?= facultyEscape($statusFilter) ?>">

                                                                    <input type="hidden" name="return_payment"
                                                                        value="<?= facultyEscape($paymentFilter) ?>">

                                                                    <input type="hidden" name="return_section_id"
                                                                        value="<?= $sectionFilter ?>">

                                                                    <input type="hidden" name="return_q"
                                                                        value="<?= facultyEscape($searchQuery) ?>">

                                                                    <details class="faculty-row-note">

                                                                        <summary title="Add an optional review note"
                                                                            aria-label="Add an optional review note">

                                                                            <i data-lucide="message-square-text"></i>

                                                                        </summary>

                                                                        <div class="faculty-row-note-popover">

                                                                            <textarea id="review-note-<?= $submissionId ?>"
                                                                                name="review_note" rows="3" maxlength="500"
                                                                                placeholder="Enter an optional review note."></textarea>

                                                                        </div>

                                                                    </details>

                                                                    <button type="submit" class="faculty-action-btn faculty-action-approve"
                                                                        name="decision" value="approved" title="Approve permit"
                                                                        aria-label="Approve permit for <?= facultyEscape($firstName . ' ' . $lastName) ?>"
                                                                        data-confirm="Approve this permit submission?">

                                                                        <i data-lucide="check"></i>

                                                                    </button>

                                                                    <button type="submit" class="faculty-action-btn faculty-action-reject"
                                                                        name="decision" value="rejected" title="Reject permit"
                                                                        aria-label="Reject permit for <?= facultyEscape($firstName . ' ' . $lastName) ?>"
                                                                        data-confirm="Reject this permit submission?">

                                                                        <i data-lucide="x"></i>

                                                                    </button>

                                                                </form>

                                                            <?php elseif ($hasSubmission): ?>

                                                                <span
                                                                    class="faculty-status faculty-status-<?= facultyEscape($decisionStatus) ?>"
                                                                    title="<?= facultyEscape($statusLabel) ?>">

                                                                    <?= facultyEscape($statusLabel) ?>

                                                                </span>

                                                            <?php else: ?>

                                                                <span class="faculty-no-file">
                                                                    No submission
                                                                </span>

                                                            <?php endif; ?>

                                                        </td>

                                                    </tr>

                                                <?php endforeach; ?>

                                            </tbody>

                                        </table>

                                    <?php endif; ?>

                                </div>

                            </section>

                        <?php endif; ?>

                    <?php endif; ?>

                </section>

                <!-- FOOTER -->

                <footer class="page-footer">

                    <span>
                        © <span id="year"></span> AcadTrack
                    </span>

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
         FACULTY PROFILE MODAL
    ====================================================== -->

    <div class="faculty-profile-modal" id="faculty-profile-modal" <?= $openProfileModal ? '' : 'hidden' ?>>

        <button type="button" class="faculty-profile-backdrop" data-close-profile-modal
            aria-label="Close faculty profile">
        </button>

        <section class="faculty-profile-dialog" role="dialog" aria-modal="true" aria-labelledby="faculty-profile-title"
            aria-describedby="faculty-profile-description">

            <!-- MODAL HEADER -->

            <header class="faculty-profile-header">

                <div class="faculty-profile-heading">

                    <span class="faculty-profile-heading-icon">
                        <i data-lucide="user-round-pen"></i>
                    </span>

                    <div>

                        <h2 id="faculty-profile-title">My Profile</h2>

                        <p id="faculty-profile-description">
                            View your account information and update your personal details.
                        </p>

                    </div>

                </div>

                <button type="button" class="icon-btn faculty-profile-close" data-close-profile-modal
                    aria-label="Close profile modal">

                    <i data-lucide="x"></i>

                </button>

            </header>

            <!-- PROFILE PICTURE FORM -->

            <form id="faculty-picture-form" class="faculty-profile-picture-form" action="update_profile_picture.php"
                method="POST" enctype="multipart/form-data">

                <input type="hidden" name="csrf_token" value="<?= facultyEscape($csrfToken) ?>">

                <input type="hidden" name="return_page" value="<?= facultyEscape($currentPage) ?>">

                <input type="hidden" name="return_status" value="<?= facultyEscape($statusFilter) ?>">

                <input type="hidden" name="return_payment" value="<?= facultyEscape($paymentFilter) ?>">

                <input type="hidden" name="return_section_id" value="<?= $sectionFilter ?>">

                <input type="hidden" name="return_q" value="<?= facultyEscape($searchQuery) ?>">

                <div class="faculty-picture-editor">

                    <div class="faculty-picture-preview" id="faculty-picture-preview">

                        <?php if ($facultyProfilePictureUrl !== ''): ?>

                            <img src="<?= facultyEscape($facultyProfilePictureUrl) ?>"
                                alt="Faculty profile picture preview">

                        <?php else: ?>

                            <span><?= facultyEscape($facultyInitial) ?></span>

                        <?php endif; ?>

                    </div>

                    <div class="faculty-picture-details">

                        <strong>Profile Picture</strong>

                        <p>
                            Upload a new picture. Supported formats: JPG, PNG, and WEBP.
                            Maximum size: 2 MB.
                        </p>

                        <div class="faculty-picture-actions">

                            <label for="faculty-profile-picture-input" class="button button-outline">

                                <i data-lucide="image-plus"></i>

                                Choose Picture

                            </label>

                            <input type="file" id="faculty-profile-picture-input" name="profile_picture"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                class="faculty-picture-file-input">

                            <button type="submit" class="button button-primary" id="faculty-upload-profile-picture"
                                disabled>

                                <i data-lucide="upload"></i>

                                Upload Picture

                            </button>

                        </div>

                        <small id="faculty-profile-picture-filename">
                            No new picture selected.
                        </small>

                    </div>

                </div>

                <?php if ($profilePictureErrorMessage !== ''): ?>

                    <div class="admin-alert admin-alert-error faculty-picture-error" role="alert">

                        <i data-lucide="alert-circle"></i>

                        <span>
                            <?= facultyEscape($profilePictureErrorMessage) ?>
                        </span>

                    </div>

                <?php endif; ?>

            </form>

            <!-- PERSONAL INFORMATION FORM -->

            <form id="faculty-profile-form" class="faculty-profile-form"
                action="<?= facultyEscape($facultyReturnUrl) ?>" method="POST">

                <input type="hidden" name="csrf_token" value="<?= facultyEscape($csrfToken) ?>">

                <input type="hidden" name="action" value="save_faculty_profile">

                <?php if ($profileErrorMessage !== ''): ?>

                    <div class="admin-alert admin-alert-error" role="alert">

                        <i data-lucide="alert-circle"></i>

                        <span>
                            <?= facultyEscape($profileErrorMessage) ?>
                        </span>

                    </div>

                <?php endif; ?>

                <!-- PERSONAL INFORMATION -->

                <div class="faculty-profile-section-title">

                    <i data-lucide="user-round"></i>

                    <span>Personal Information</span>

                </div>

                <div class="faculty-profile-grid">

                    <div class="faculty-profile-field">

                        <label for="faculty-profile-firstname">
                            Firstname <span class="required-mark">*</span>
                        </label>

                        <input id="faculty-profile-firstname" name="firstname" type="text" maxlength="100"
                            autocomplete="given-name"
                            value="<?= facultyEscape($profileFormValues['firstname'] ?? '') ?>"
                            placeholder="Enter your firstname" required>

                    </div>

                    <div class="faculty-profile-field">

                        <label for="faculty-profile-middlename">
                            Middlename
                        </label>

                        <input id="faculty-profile-middlename" name="middlename" type="text" maxlength="100"
                            autocomplete="additional-name"
                            value="<?= facultyEscape($profileFormValues['middlename'] ?? '') ?>"
                            placeholder="Enter your middlename">

                    </div>

                    <div class="faculty-profile-field">

                        <label for="faculty-profile-lastname">
                            Lastname <span class="required-mark">*</span>
                        </label>

                        <input id="faculty-profile-lastname" name="lastname" type="text" maxlength="100"
                            autocomplete="family-name"
                            value="<?= facultyEscape($profileFormValues['lastname'] ?? '') ?>"
                            placeholder="Enter your lastname" required>

                    </div>

                    <div class="faculty-profile-field">

                        <label for="faculty-profile-age">
                            Age
                        </label>

                        <input id="faculty-profile-age" name="age" type="number" min="1" max="120" step="1"
                            value="<?= facultyEscape($profileFormValues['age'] ?? '') ?>" placeholder="Enter your age">

                    </div>

                    <div class="faculty-profile-field">

                        <label for="faculty-profile-gender">
                            Gender
                        </label>

                        <select id="faculty-profile-gender" name="gender">

                            <option value="" <?= ($profileFormValues['gender'] ?? '') === '' ? 'selected' : '' ?>>
                                Select gender
                            </option>

                            <option value="Male" <?= ($profileFormValues['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>
                                Male
                            </option>

                            <option value="Female" <?= ($profileFormValues['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>
                                Female
                            </option>

                            <option value="Other" <?= ($profileFormValues['gender'] ?? '') === 'Other' ? 'selected' : '' ?>>
                                Other
                            </option>

                            <option value="Prefer not to say" <?= ($profileFormValues['gender'] ?? '') === 'Prefer not to say' ? 'selected' : '' ?>>
                                Prefer not to say
                            </option>

                        </select>

                    </div>

                    <div class="faculty-profile-field">

                        <label for="faculty-profile-mobile">
                            Mobile Number
                        </label>

                        <input id="faculty-profile-mobile" name="mobile_number" type="tel" maxlength="20"
                            autocomplete="tel" value="<?= facultyEscape($profileFormValues['mobile_number'] ?? '') ?>"
                            placeholder="e.g. 09171234567">

                    </div>

                    <div class="faculty-profile-field faculty-profile-full">

                        <label for="faculty-profile-address">
                            Address
                        </label>

                        <textarea id="faculty-profile-address" name="address" rows="3" maxlength="2000"
                            autocomplete="street-address"
                            placeholder="Enter your complete address"><?= facultyEscape($profileFormValues['address'] ?? '') ?></textarea>

                    </div>

                </div>

                <!-- ACCOUNT INFORMATION -->

                <div class="faculty-profile-section-title faculty-profile-account-title">

                    <i data-lucide="shield-check"></i>

                    <span>Account Information</span>

                </div>

                <div class="faculty-profile-grid">

                    <div class="faculty-profile-field faculty-profile-full">

                        <label for="faculty-profile-email">
                            PHINMA Email
                        </label>

                        <input id="faculty-profile-email" type="email"
                            value="<?= facultyEscape($profileDefaults['email']) ?>" readonly>

                        <small>
                            Your registered email address is managed by the system administrator.
                        </small>

                    </div>

                    <div class="faculty-profile-field faculty-profile-full">

                        <label for="faculty-profile-role">
                            Account Role
                        </label>

                        <input id="faculty-profile-role" type="text" value="Faculty / SSP Adviser" readonly>

                    </div>

                </div>

                <!-- PROFILE ACTIONS -->

                <footer class="faculty-profile-actions">

                    <button type="button" class="button button-outline" data-close-profile-modal>

                        Cancel

                    </button>

                    <button type="submit" class="button button-primary" id="faculty-profile-save">

                        <i data-lucide="save"></i>

                        Save Changes

                    </button>

                </footer>

            </form>

        </section>

    </div>

    <!-- =====================================================
         FACULTY ACCOUNT SETTINGS — CHANGE PASSWORD
    ====================================================== -->

    <div class="faculty-profile-modal faculty-password-modal" id="faculty-password-modal" <?= $openPasswordModal ? '' : 'hidden' ?>>

        <button type="button" class="faculty-profile-backdrop" data-close-password-modal
            aria-label="Close account settings">
        </button>

        <section class="faculty-profile-dialog faculty-password-dialog" role="dialog" aria-modal="true"
            aria-labelledby="faculty-password-title" aria-describedby="faculty-password-description">

            <header class="faculty-profile-header">

                <div class="faculty-profile-heading">

                    <span class="faculty-profile-heading-icon">
                        <i data-lucide="shield-check"></i>
                    </span>

                    <div>

                        <h2 id="faculty-password-title">
                            Account Settings
                        </h2>

                        <p id="faculty-password-description">
                            Update your account password to keep your faculty account secure.
                        </p>

                    </div>

                </div>

                <button type="button" class="icon-btn faculty-profile-close" data-close-password-modal
                    aria-label="Close account settings">

                    <i data-lucide="x"></i>

                </button>

            </header>

            <form id="faculty-password-form" class="faculty-profile-form faculty-password-form"
                action="<?= facultyEscape($facultyReturnUrl) ?>" method="POST" autocomplete="off">

                <input type="hidden" name="csrf_token" value="<?= facultyEscape($csrfToken) ?>">

                <input type="hidden" name="action" value="change_faculty_password">

                <?php if ($passwordErrorMessage !== ''): ?>

                    <div class="admin-alert admin-alert-error" role="alert">

                        <i data-lucide="alert-circle"></i>

                        <span>
                            <?= facultyEscape($passwordErrorMessage) ?>
                        </span>

                    </div>

                <?php endif; ?>

                <div class="faculty-profile-section-title">

                    <i data-lucide="lock-keyhole"></i>

                    <span>Password Information</span>

                </div>

                <div class="faculty-profile-grid">

                    <!-- CURRENT PASSWORD -->

                    <div class="faculty-profile-field faculty-profile-full">

                        <label for="faculty-current-password">

                            Current Password

                            <span class="required-mark">*</span>

                        </label>

                        <div class="faculty-password-input-wrap">

                            <input type="password" id="faculty-current-password" name="current_password"
                                placeholder="Enter your current password" autocomplete="current-password"
                                maxlength="255" required>

                            <button type="button" class="faculty-password-toggle"
                                data-toggle-password="faculty-current-password" aria-label="Show current password"
                                aria-pressed="false">

                                <i data-lucide="eye"></i>

                            </button>

                        </div>

                    </div>

                    <!-- NEW PASSWORD -->

                    <div class="faculty-profile-field faculty-profile-full">

                        <label for="faculty-new-password">

                            New Password

                            <span class="required-mark">*</span>

                        </label>

                        <div class="faculty-password-input-wrap">

                            <input type="password" id="faculty-new-password" name="new_password"
                                placeholder="Enter your new password" autocomplete="new-password" minlength="8"
                                maxlength="72" required>

                            <button type="button" class="faculty-password-toggle"
                                data-toggle-password="faculty-new-password" aria-label="Show new password"
                                aria-pressed="false">

                                <i data-lucide="eye"></i>

                            </button>

                        </div>

                        <small>
                            Your new password must contain at least 8 characters
                            and must not exceed 72 bytes.
                        </small>

                    </div>

                    <!-- CONFIRM PASSWORD -->

                    <div class="faculty-profile-field faculty-profile-full">

                        <label for="faculty-confirm-password">

                            Confirm New Password

                            <span class="required-mark">*</span>

                        </label>

                        <div class="faculty-password-input-wrap">

                            <input type="password" id="faculty-confirm-password" name="confirm_password"
                                placeholder="Re-enter your new password" autocomplete="new-password" minlength="8"
                                maxlength="72" required>

                            <button type="button" class="faculty-password-toggle"
                                data-toggle-password="faculty-confirm-password" aria-label="Show confirmation password"
                                aria-pressed="false">

                                <i data-lucide="eye"></i>

                            </button>

                        </div>

                        <small id="faculty-password-confirmation-help">
                            Enter the same password as above.
                        </small>

                    </div>

                </div>

                <!-- PASSWORD ACTIONS -->

                <footer class="faculty-profile-actions">

                    <button type="button" class="button button-outline" data-close-password-modal>

                        Cancel

                    </button>

                    <button type="submit" class="button button-primary" id="faculty-password-save">

                        <i data-lucide="save"></i>

                        Update Password

                    </button>

                </footer>

            </form>

        </section>

    </div>

    <!-- PROFILE NOTIFICATIONS -->

    <div id="faculty-profile-notice" data-message="<?= facultyEscape($profileNotice) ?>" hidden>
    </div>

    <div id="faculty-password-notice" data-message="<?= facultyEscape($passwordNotice) ?>" hidden>
    </div>

    <!-- TOAST -->

    <div class="toast" id="toast" role="status" aria-live="polite" hidden>

        <i data-lucide="info"></i>

        <span id="toast-message"></span>

    </div>

</body>

</html>