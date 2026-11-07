<?php
declare(strict_types=1);

require_once __DIR__ . '/../../auth/require_role.php';
requireRole(['admin']);

require_once __DIR__ . '/../../config/database.php';

date_default_timezone_set('Asia/Manila');

$adminId = (int) ($_SESSION['user_id'] ?? 0);
$adminName = (string) ($_SESSION['nickname'] ?? 'Administrator');

if ($adminId < 1) {
    http_response_code(403);
    exit('Access denied.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$successMessage = '';
$errorMessage = '';

/*
|--------------------------------------------------------------------------
| PAGE CONFIGURATION
|--------------------------------------------------------------------------
*/

$currentPage = $_GET['page'] ?? 'dashboard';

if (
    !is_string($currentPage)
    || !in_array($currentPage, ['dashboard', 'sections', 'accounts'], true)
) {
    $currentPage = 'dashboard';
}

$adminReturnUrl = 'dashboard.php?page=' . $currentPage;

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function adminEscape($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function adminTextLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

function adminDashboardRedirect(
    string $message,
    string $page = 'dashboard',
    bool $openProfile = false,
    bool $openPassword = false
): void {

    if (!in_array($page, ['dashboard', 'sections', 'accounts'], true)) {
        $page = 'dashboard';
    }

    $_SESSION['admin_dashboard_flash'] = $message;

    $parameters = [
        'page' => $page,
    ];

    if ($openProfile) {
        $parameters['open_profile'] = '1';
    }

    if ($openPassword) {
        $parameters['open_password'] = '1';
    }

    header(
        'Location: dashboard.php?'
        . http_build_query($parameters)
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| SYNCHRONIZE STUDENT ACADEMIC PLACEMENT
|--------------------------------------------------------------------------
| Keeps user_information synchronized with the official section assignment.
| Passing null clears the student's academic placement.
*/

function adminSyncStudentPlacement(
    PDO $pdo,
    int $studentId,
    ?array $section
): void {

    if ($section === null) {

        $clearPlacement = $pdo->prepare(
            "UPDATE user_information
             SET
                course = NULL,
                year_level = NULL,
                section = NULL
             WHERE user_id = ?"
        );

        $clearPlacement->execute([$studentId]);

        return;
    }

    $syncPlacement = $pdo->prepare(
        "INSERT INTO user_information (
            user_id,
            course,
            year_level,
            section
        ) VALUES (?, ?, ?, ?)

        ON DUPLICATE KEY UPDATE
            course = VALUES(course),
            year_level = VALUES(year_level),
            section = VALUES(section)"
    );

    $syncPlacement->execute([
        $studentId,
        $section['course'],
        (int) $section['year_level'],
        $section['section_name'],
    ]);
}

/*
|--------------------------------------------------------------------------
| VERIFY ACTIVE ADMIN ACCOUNT
|--------------------------------------------------------------------------
*/

$adminCheck = $pdo->prepare(
    "SELECT
        nickname,
        email,
        profile_picture
     FROM users
     WHERE id = ?
       AND role = 'admin'
       AND is_active = 1
     LIMIT 1"
);

$adminCheck->execute([$adminId]);

$adminAccount = $adminCheck->fetch(PDO::FETCH_ASSOC);

if (!$adminAccount) {
    http_response_code(403);
    exit('Your administrator account is unavailable or inactive.');
}

$adminName = (string) $adminAccount['nickname'];

/*
|--------------------------------------------------------------------------
| LOAD ADMIN PROFILE
|--------------------------------------------------------------------------
*/

$adminProfileQuery = $pdo->prepare(
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
       AND u.role = 'admin'
       AND u.is_active = 1

     LIMIT 1"
);

$adminProfileQuery->execute([$adminId]);

$adminProfile = $adminProfileQuery->fetch(PDO::FETCH_ASSOC);

if (!$adminProfile) {
    http_response_code(403);
    exit('Your administrator profile is unavailable.');
}

$adminProfilePictureFilename = basename(
    trim((string) ($adminProfile['profile_picture'] ?? ''))
);

$adminProfilePictureUrl = $adminProfilePictureFilename !== ''
    ? '../../uploads/profile_pictures/'
    . rawurlencode($adminProfilePictureFilename)
    : '';

$adminInitial = strtoupper(
    substr($adminName !== '' ? $adminName : 'A', 0, 1)
);

$adminProfileDefaults = [
    'firstname' => (string) ($adminProfile['firstname'] ?? ''),
    'middlename' => (string) ($adminProfile['middlename'] ?? ''),
    'lastname' => (string) ($adminProfile['lastname'] ?? ''),

    'age' => $adminProfile['age'] !== null
        ? (string) $adminProfile['age']
        : '',

    'address' => (string) ($adminProfile['address'] ?? ''),
    'gender' => (string) ($adminProfile['gender'] ?? ''),
    'mobile_number' => (string) ($adminProfile['mobile_number'] ?? ''),
    'email' => (string) ($adminProfile['email'] ?? ''),
];

/*
|--------------------------------------------------------------------------
| PROFILE AND PASSWORD MODAL STATES
|--------------------------------------------------------------------------
*/

$profileErrorMessage = '';
$passwordErrorMessage = '';

$profileFormValues = $adminProfileDefaults;

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
| SAVE ADMIN PROFILE
|--------------------------------------------------------------------------
*/

$isAdminProfilePost = (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'save_admin_profile'
);

$isAdminPasswordPost = (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'change_admin_password'
);

if ($isAdminProfilePost) {

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
        'email' => $adminProfileDefaults['email'],
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
            adminTextLength($firstName) > 100
            || adminTextLength($middleName) > 100
            || adminTextLength($lastName) > 100
        ) {

            $profileErrorMessage =
                'Names must not exceed 100 characters.';

        } elseif (adminTextLength($address) > 2000) {

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
                    $adminId,
                    $firstName,
                    $middleName !== '' ? $middleName : null,
                    $lastName,
                    $age,
                    $address !== '' ? $address : null,
                    $gender !== '' ? $gender : null,
                    $mobileNumber !== '' ? $mobileNumber : null,
                ]);

                $pdo->commit();

                $_SESSION['admin_profile_notice'] =
                    'Your profile was updated successfully.';

                header('Location: ' . $adminReturnUrl);
                exit;

            } catch (Throwable $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'AcadTrack admin profile update error: '
                    . $exception->getMessage()
                );

                $profileErrorMessage =
                    'Unable to save your profile. Please try again.';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CHANGE ADMIN PASSWORD
    |--------------------------------------------------------------------------
    */

} elseif ($isAdminPasswordPost) {

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

    } elseif (adminTextLength($newPassword) < 8) {

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
                   AND role = 'admin'
                   AND is_active = 1
                 LIMIT 1"
            );

            $passwordQuery->execute([$adminId]);

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
                       AND role = 'admin'
                       AND is_active = 1"
                );

                $passwordUpdate->execute([
                    $newPasswordHash,
                    $adminId,
                ]);

                if ($passwordUpdate->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Password update failed.'
                    );
                }

                $_SESSION['admin_password_notice'] =
                    'Your password has been changed successfully.';

                session_regenerate_id(true);

                header('Location: ' . $adminReturnUrl);
                exit;
            }

        } catch (Throwable $exception) {

            error_log(
                'AcadTrack admin password update error: '
                . $exception->getMessage()
            );

            $passwordErrorMessage =
                'Unable to update your password. Please try again.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PROCESS EXISTING ADMIN ACTIONS
    |--------------------------------------------------------------------------
    */

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    $isAjaxRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $allowedActions = [
        'create_account',
        'edit_account',
        'delete_account',
        'add_section',
        'assign_student',
        'remove_student',
        'edit_student',
        'change_student_section',
        'assign_adviser',
    ];

    /*
     * CSRF AND ACTION VALIDATION.
     */

    if (
        !is_string($postedToken)
        || !hash_equals($csrfToken, $postedToken)
    ) {

        $errorMessage =
            'Your session token is invalid. Refresh the page and try again.';

    } elseif (
        !is_string($action)
        || !in_array($action, $allowedActions, true)
    ) {

        $errorMessage = 'Invalid action.';

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | CREATE STUDENT OR FACULTY ACCOUNT
            |--------------------------------------------------------------------------
            */

            if ($action === 'create_account') {

                $readAccountField = static function (string $key): string {
                    $value = $_POST[$key] ?? '';
                    return is_string($value) ? trim($value) : '';
                };

                $role = $readAccountField('role');
                $nickname = $readAccountField('nickname');
                $email = $readAccountField('email');
                $studentNumberInput = $readAccountField('student_number');
                $password = $_POST['password'] ?? '';

                $firstname = $readAccountField('firstname');
                $middlename = $readAccountField('middlename');
                $lastname = $readAccountField('lastname');
                $ageInput = $readAccountField('age');
                $gender = $readAccountField('gender');
                $mobileNumber = $readAccountField('mobile_number');
                $address = $readAccountField('address');

                $age = $ageInput === ''
                    ? null
                    : filter_var(
                        $ageInput,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1, 'max_range' => 120]]
                    );

                $allowedGenders = ['', 'Male', 'Female', 'Other', 'Prefer not to say'];

                if (!in_array($role, ['student', 'faculty'], true)) {
                    $errorMessage = 'Please select a valid account type.';
                } elseif (
                    $nickname === ''
                    || adminTextLength($nickname) > 150
                    || !filter_var($email, FILTER_VALIDATE_EMAIL)
                    || strlen($email) > 191
                ) {
                    $errorMessage = 'Please enter a valid account name and email address.';
                } elseif (
                    $role === 'student'
                    && !preg_match('/\A[0-9]{12}\z/', $studentNumberInput)
                ) {
                    $errorMessage = 'A student account must have a valid 12-digit student number.';
                } elseif (
                    !is_string($password)
                    || adminTextLength($password) < 8
                    || strlen($password) > 72
                ) {
                    $errorMessage = 'The password must contain at least 8 characters and must not exceed 72 bytes.';
                } elseif (
                    adminTextLength($firstname) > 100
                    || adminTextLength($middlename) > 100
                    || adminTextLength($lastname) > 100
                    || adminTextLength($address) > 5000
                    || strlen($gender) > 20
                    || strlen($mobileNumber) > 20
                    || ($ageInput !== '' && $age === false)
                    || !in_array($gender, $allowedGenders, true)
                    || (
                        $mobileNumber !== ''
                        && !preg_match('/\A\+?[0-9()\s.-]{7,20}\z/', $mobileNumber)
                    )
                ) {
                    $errorMessage = 'Please check the personal information and make sure all fields are valid.';
                } else {

                    $studentNumber = $role === 'student'
                        ? $studentNumberInput
                        : null;

                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                    if (!is_string($passwordHash) || $passwordHash === '') {
                        throw new RuntimeException('Unable to hash the new account password.');
                    }

                    $pdo->beginTransaction();

                    $duplicateEmail = $pdo->prepare(
                        "SELECT id FROM users WHERE email = ? LIMIT 1"
                    );
                    $duplicateEmail->execute([$email]);

                    if ($duplicateEmail->fetchColumn()) {
                        throw new DomainException('This email address is already being used by another account.');
                    }

                    if ($studentNumber !== null) {
                        $duplicateNumber = $pdo->prepare(
                            "SELECT id FROM users WHERE student_number = ? LIMIT 1"
                        );
                        $duplicateNumber->execute([$studentNumber]);

                        if ($duplicateNumber->fetchColumn()) {
                            throw new DomainException('This student number is already assigned to another account.');
                        }
                    }

                    $insertAccount = $pdo->prepare(
                        "INSERT INTO users (
                            nickname,
                            student_number,
                            email,
                            password_hash,
                            role,
                            is_active
                        ) VALUES (?, ?, ?, ?, ?, 1)"
                    );

                    $insertAccount->execute([
                        $nickname,
                        $studentNumber,
                        $email,
                        $passwordHash,
                        $role,
                    ]);

                    $newAccountId = (int) $pdo->lastInsertId();

                    $insertInformation = $pdo->prepare(
                        "INSERT INTO user_information (
                            user_id,
                            firstname,
                            middlename,
                            lastname,
                            age,
                            address,
                            gender,
                            mobile_number
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );

                    $insertInformation->execute([
                        $newAccountId,
                        $firstname !== '' ? $firstname : null,
                        $middlename !== '' ? $middlename : null,
                        $lastname !== '' ? $lastname : null,
                        $age,
                        $address !== '' ? $address : null,
                        $gender !== '' ? $gender : null,
                        $mobileNumber !== '' ? $mobileNumber : null,
                    ]);

                    $pdo->commit();

                    $successMessage = ucfirst($role) . ' account created successfully.';

                    if (!$isAjaxRequest) {
                        adminDashboardRedirect($successMessage, 'accounts');
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | EDIT STUDENT OR FACULTY ACCOUNT
                |--------------------------------------------------------------------------
                */

            } elseif ($action === 'edit_account') {

                $readAccountField = static function (string $key): string {
                    $value = $_POST[$key] ?? '';
                    return is_string($value) ? trim($value) : '';
                };

                $accountId = filter_var(
                    $_POST['account_id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                $nickname = $readAccountField('nickname');
                $email = $readAccountField('email');
                $studentNumberInput = $readAccountField('student_number');
                $newPassword = $_POST['password'] ?? '';

                $firstname = $readAccountField('firstname');
                $middlename = $readAccountField('middlename');
                $lastname = $readAccountField('lastname');
                $ageInput = $readAccountField('age');
                $gender = $readAccountField('gender');
                $mobileNumber = $readAccountField('mobile_number');
                $address = $readAccountField('address');

                $age = $ageInput === ''
                    ? null
                    : filter_var(
                        $ageInput,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1, 'max_range' => 120]]
                    );

                $allowedGenders = ['', 'Male', 'Female', 'Other', 'Prefer not to say'];

                if (
                    !$accountId
                    || $nickname === ''
                    || adminTextLength($nickname) > 150
                    || !filter_var($email, FILTER_VALIDATE_EMAIL)
                    || strlen($email) > 191
                ) {
                    $errorMessage = 'Please enter a valid account name and email address.';
                } elseif (
                    ($studentNumberInput !== '' && !preg_match('/\A[0-9]{12}\z/', $studentNumberInput))
                    || ($ageInput !== '' && $age === false)
                    || adminTextLength($firstname) > 100
                    || adminTextLength($middlename) > 100
                    || adminTextLength($lastname) > 100
                    || adminTextLength($address) > 5000
                    || strlen($gender) > 20
                    || strlen($mobileNumber) > 20
                    || !in_array($gender, $allowedGenders, true)
                    || (
                        $mobileNumber !== ''
                        && !preg_match('/\A\+?[0-9()\s.-]{7,20}\z/', $mobileNumber)
                    )
                ) {
                    $errorMessage = 'Please check the account information and make sure all fields are valid.';
                } elseif (
                    !is_string($newPassword)
                    || ($newPassword !== '' && adminTextLength($newPassword) < 8)
                    || strlen((string) $newPassword) > 72
                ) {
                    $errorMessage = 'A new password must contain at least 8 characters and must not exceed 72 bytes.';
                } else {

                    $pdo->beginTransaction();

                    $accountCheck = $pdo->prepare(
                        "SELECT id, role
                         FROM users
                         WHERE id = ?
                           AND role IN ('student', 'faculty')
                         FOR UPDATE"
                    );
                    $accountCheck->execute([$accountId]);
                    $existingAccount = $accountCheck->fetch(PDO::FETCH_ASSOC);

                    if (!$existingAccount) {
                        throw new DomainException('The selected student or faculty account could not be found.');
                    }

                    $accountRole = (string) $existingAccount['role'];
                    $studentNumber = $accountRole === 'student' && $studentNumberInput !== ''
                        ? $studentNumberInput
                        : null;

                    $duplicateEmail = $pdo->prepare(
                        "SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1"
                    );
                    $duplicateEmail->execute([$email, $accountId]);

                    if ($duplicateEmail->fetchColumn()) {
                        throw new DomainException('This email address is already being used by another account.');
                    }

                    if ($studentNumber !== null) {
                        $duplicateNumber = $pdo->prepare(
                            "SELECT id FROM users WHERE student_number = ? AND id <> ? LIMIT 1"
                        );
                        $duplicateNumber->execute([$studentNumber, $accountId]);

                        if ($duplicateNumber->fetchColumn()) {
                            throw new DomainException('This student number is already assigned to another account.');
                        }
                    }

                    $updateAccount = $pdo->prepare(
                        "UPDATE users
                         SET nickname = ?,
                             student_number = ?,
                             email = ?
                         WHERE id = ?
                           AND role IN ('student', 'faculty')"
                    );
                    $updateAccount->execute([
                        $nickname,
                        $studentNumber,
                        $email,
                        $accountId,
                    ]);

                    if ($newPassword !== '') {
                        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

                        if (!is_string($passwordHash) || $passwordHash === '') {
                            throw new RuntimeException('Unable to hash the updated account password.');
                        }

                        $updatePassword = $pdo->prepare(
                            "UPDATE users
                             SET password_hash = ?
                             WHERE id = ?
                               AND role IN ('student', 'faculty')"
                        );
                        $updatePassword->execute([$passwordHash, $accountId]);
                    }

                    $syncInformation = $pdo->prepare(
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
                    $syncInformation->execute([
                        $accountId,
                        $firstname !== '' ? $firstname : null,
                        $middlename !== '' ? $middlename : null,
                        $lastname !== '' ? $lastname : null,
                        $age,
                        $address !== '' ? $address : null,
                        $gender !== '' ? $gender : null,
                        $mobileNumber !== '' ? $mobileNumber : null,
                    ]);

                    $pdo->commit();

                    $successMessage = ucfirst($accountRole) . ' account updated successfully.';

                    if (!$isAjaxRequest) {
                        adminDashboardRedirect($successMessage, 'accounts');
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | DELETE STUDENT OR FACULTY ACCOUNT
                |--------------------------------------------------------------------------
                */

            } elseif ($action === 'delete_account') {

                $accountId = filter_var(
                    $_POST['account_id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                if (!$accountId) {
                    $errorMessage = 'Select a valid account to delete.';
                } else {

                    $pdo->beginTransaction();

                    $accountCheck = $pdo->prepare(
                        "SELECT id, role, nickname
                         FROM users
                         WHERE id = ?
                           AND role IN ('student', 'faculty')
                         FOR UPDATE"
                    );
                    $accountCheck->execute([$accountId]);
                    $accountToDelete = $accountCheck->fetch(PDO::FETCH_ASSOC);

                    if (!$accountToDelete) {
                        throw new DomainException('The selected student or faculty account could not be found.');
                    }

                    $deleteAccount = $pdo->prepare(
                        "DELETE FROM users
                         WHERE id = ?
                           AND role IN ('student', 'faculty')"
                    );
                    $deleteAccount->execute([$accountId]);

                    if ($deleteAccount->rowCount() !== 1) {
                        throw new RuntimeException('The selected account could not be deleted.');
                    }

                    $pdo->commit();

                    $successMessage = ucfirst((string) $accountToDelete['role'])
                        . ' account for "'
                        . (string) $accountToDelete['nickname']
                        . '" deleted successfully.';

                    if (!$isAjaxRequest) {
                        adminDashboardRedirect($successMessage, 'accounts');
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | CREATE SECTION
                |--------------------------------------------------------------------------
                */

            } elseif ($action === 'add_section') {

                $course = trim((string) ($_POST['course'] ?? ''));
                $sectionName = trim((string) ($_POST['section_name'] ?? ''));

                $yearLevel = filter_var(
                    $_POST['year_level'] ?? null,
                    FILTER_VALIDATE_INT,
                    [
                        'options' => [
                            'min_range' => 1,
                            'max_range' => 4,
                        ],
                    ]
                );

                if (
                    $course === ''
                    || strlen($course) > 50
                    || $sectionName === ''
                    || strlen($sectionName) > 100
                    || $yearLevel === false
                ) {

                    $errorMessage =
                        'Please provide a valid course, section, and year level.';

                } else {

                    $displayName = $course . ' - ' . $sectionName;

                    $check = $pdo->prepare(
                        "SELECT id
                         FROM sections
                         WHERE course = ?
                           AND year_level = ?
                           AND section_name = ?
                         LIMIT 1"
                    );

                    $check->execute([
                        $course,
                        $yearLevel,
                        $displayName,
                    ]);

                    if ($check->fetchColumn()) {

                        $errorMessage =
                            'This course, year level, and section already exist.';

                    } else {

                        $insert = $pdo->prepare(
                            "INSERT INTO sections (
                                section_name,
                                course,
                                year_level
                            ) VALUES (?, ?, ?)"
                        );

                        $insert->execute([
                            $displayName,
                            $course,
                            $yearLevel,
                        ]);

                        $successMessage = 'Section created successfully.';

                        if (!$isAjaxRequest) {

                            $_SESSION['admin_dashboard_flash'] = $successMessage;

                            header('Location: dashboard.php?page=sections');
                            exit;
                        }
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | ASSIGN STUDENT TO SECTION
                |--------------------------------------------------------------------------
                */

            } elseif ($action === 'assign_student') {

                $sectionId = filter_var(
                    $_POST['section_id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                $studentId = filter_var(
                    $_POST['student_id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                if (!$sectionId || !$studentId) {

                    $errorMessage = 'Select a valid section and student.';

                } else {

                    $pdo->beginTransaction();

                    $sectionCheck = $pdo->prepare(
                        "SELECT
                id,
                section_name,
                course,
                year_level
             FROM sections
             WHERE id = ?
             FOR UPDATE"
                    );

                    $sectionCheck->execute([$sectionId]);

                    $targetSection = $sectionCheck->fetch(PDO::FETCH_ASSOC);

                    if (!$targetSection) {
                        throw new DomainException(
                            'The selected section was not found.'
                        );
                    }

                    $studentCheck = $pdo->prepare(
                        "SELECT id
             FROM users
             WHERE id = ?
               AND role = 'student'
               AND is_active = 1
             FOR UPDATE"
                    );

                    $studentCheck->execute([$studentId]);

                    if (!$studentCheck->fetchColumn()) {
                        throw new DomainException(
                            'The student account is unavailable or inactive.'
                        );
                    }

                    $assignmentCheck = $pdo->prepare(
                        "SELECT section_id
             FROM section_students
             WHERE student_id = ?
             LIMIT 1
             FOR UPDATE"
                    );

                    $assignmentCheck->execute([$studentId]);

                    $existingSection = $assignmentCheck->fetchColumn();

                    if ($existingSection !== false) {

                        if ((int) $existingSection === (int) $sectionId) {
                            throw new DomainException(
                                'This student is already assigned to this section.'
                            );
                        }

                        throw new DomainException(
                            'This student already belongs to another section. Remove the existing assignment first.'
                        );
                    }

                    $insert = $pdo->prepare(
                        "INSERT INTO section_students (
                student_id,
                section_id
            ) VALUES (?, ?)"
                    );

                    $insert->execute([
                        $studentId,
                        $sectionId,
                    ]);

                    /*
                     * UPDATE STUDENT OVERALL ACADEMIC PLACEMENT.
                     */

                    adminSyncStudentPlacement(
                        $pdo,
                        $studentId,
                        $targetSection
                    );

                    $pdo->commit();

                    $successMessage =
                        'Student account assigned to the section successfully.';

                    if (!$isAjaxRequest) {

                        $_SESSION['admin_dashboard_flash'] = $successMessage;

                        header('Location: dashboard.php?page=sections');
                        exit;
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | REMOVE STUDENT FROM SECTION
                |--------------------------------------------------------------------------
                */

            } elseif ($action === 'remove_student') {

                $sectionId = filter_var(
                    $_POST['section_id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                $studentId = filter_var(
                    $_POST['student_id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                if (!$sectionId || !$studentId) {

                    $errorMessage = 'Invalid student assignment.';

                } else {

                    $pdo->beginTransaction();

                    $sectionCheck = $pdo->prepare(
                        "SELECT id
             FROM sections
             WHERE id = ?
             FOR UPDATE"
                    );

                    $sectionCheck->execute([$sectionId]);

                    if (!$sectionCheck->fetchColumn()) {
                        throw new DomainException(
                            'The selected section could not be found.'
                        );
                    }

                    $studentCheck = $pdo->prepare(
                        "SELECT id
             FROM users
             WHERE id = ?
               AND role = 'student'
             FOR UPDATE"
                    );

                    $studentCheck->execute([$studentId]);

                    if (!$studentCheck->fetchColumn()) {
                        throw new DomainException(
                            'The selected student account could not be found.'
                        );
                    }

                    $assignmentCheck = $pdo->prepare(
                        "SELECT id
             FROM section_students
             WHERE student_id = ?
               AND section_id = ?
             LIMIT 1
             FOR UPDATE"
                    );

                    $assignmentCheck->execute([
                        $studentId,
                        $sectionId,
                    ]);

                    if ($assignmentCheck->fetchColumn() === false) {
                        throw new DomainException(
                            'The student is not assigned to this section.'
                        );
                    }

                    /*
                     * REMOVE OFFICIAL SECTION ASSIGNMENT.
                     */

                    $delete = $pdo->prepare(
                        "DELETE FROM section_students
             WHERE student_id = ?
               AND section_id = ?"
                    );

                    $delete->execute([
                        $studentId,
                        $sectionId,
                    ]);

                    if ($delete->rowCount() !== 1) {
                        throw new RuntimeException(
                            'Unable to remove the student enrollment.'
                        );
                    }

                    /*
                     * CLEAR STUDENT OVERALL ACADEMIC PLACEMENT.
                     */

                    adminSyncStudentPlacement(
                        $pdo,
                        $studentId,
                        null
                    );

                    $pdo->commit();

                    $successMessage =
                        'Student removed successfully. Their section enrollment and academic placement have been cleared.';

                    if (!$isAjaxRequest) {

                        $_SESSION['admin_dashboard_flash'] = $successMessage;

                        header('Location: dashboard.php?page=sections');
                        exit;
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | EDIT STUDENT INFORMATION
                |--------------------------------------------------------------------------
                */

            } elseif ($action === 'edit_student') {

                $studentId = filter_var(
                    $_POST['student_id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                $nickname = trim((string) ($_POST['nickname'] ?? ''));
                $studentNumberInput = trim((string) ($_POST['student_number'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));

                $firstname = trim((string) ($_POST['firstname'] ?? ''));
                $middlename = trim((string) ($_POST['middlename'] ?? ''));
                $lastname = trim((string) ($_POST['lastname'] ?? ''));

                $ageInput = trim((string) ($_POST['age'] ?? ''));

                $age = $ageInput === ''
                    ? null
                    : filter_var(
                        $ageInput,
                        FILTER_VALIDATE_INT,
                        [
                            'options' => [
                                'min_range' => 1,
                                'max_range' => 120,
                            ],
                        ]
                    );

                $gender = trim((string) ($_POST['gender'] ?? ''));
                $mobileNumber = trim((string) ($_POST['mobile_number'] ?? ''));
                $address = trim((string) ($_POST['address'] ?? ''));

                $studentNumber = $studentNumberInput === ''
                    ? null
                    : $studentNumberInput;

                if (
                    !$studentId
                    || $nickname === ''
                    || strlen($nickname) > 150
                    || !filter_var($email, FILTER_VALIDATE_EMAIL)
                    || strlen($email) > 191
                    || (
                        $studentNumber !== null
                        && (
                            strlen($studentNumber) !== 12
                            || !ctype_digit($studentNumber)
                        )
                    )
                    || ($ageInput !== '' && $age === false)
                    || strlen($firstname) > 100
                    || strlen($middlename) > 100
                    || strlen($lastname) > 100
                    || strlen($gender) > 20
                    || strlen($mobileNumber) > 20
                    || strlen($address) > 5000
                ) {

                    $errorMessage =
                        'Please check the student information and make sure all fields are valid.';

                } else {

                    $pdo->beginTransaction();

                    $studentCheck = $pdo->prepare(
                        "SELECT id
                         FROM users
                         WHERE id = ?
                           AND role = 'student'
                         FOR UPDATE"
                    );

                    $studentCheck->execute([$studentId]);

                    if (!$studentCheck->fetchColumn()) {
                        throw new DomainException(
                            'The selected student account could not be found.'
                        );
                    }

                    $emailCheck = $pdo->prepare(
                        "SELECT id
                         FROM users
                         WHERE email = ?
                           AND id <> ?
                         LIMIT 1"
                    );

                    $emailCheck->execute([
                        $email,
                        $studentId,
                    ]);

                    if ($emailCheck->fetchColumn()) {
                        throw new DomainException(
                            'This email address is already being used by another account.'
                        );
                    }

                    if ($studentNumber !== null) {

                        $numberCheck = $pdo->prepare(
                            "SELECT id
                             FROM users
                             WHERE student_number = ?
                               AND id <> ?
                             LIMIT 1"
                        );

                        $numberCheck->execute([
                            $studentNumber,
                            $studentId,
                        ]);

                        if ($numberCheck->fetchColumn()) {
                            throw new DomainException(
                                'This student number is already assigned to another account.'
                            );
                        }
                    }

                    $updateAccount = $pdo->prepare(
                        "UPDATE users
                         SET nickname = ?,
                             student_number = ?,
                             email = ?
                         WHERE id = ?
                           AND role = 'student'"
                    );

                    $updateAccount->execute([
                        $nickname,
                        $studentNumber,
                        $email,
                        $studentId,
                    ]);

                    $profileSync = $pdo->prepare(
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

                    $profileSync->execute([
                        $studentId,
                        $firstname !== '' ? $firstname : null,
                        $middlename !== '' ? $middlename : null,
                        $lastname !== '' ? $lastname : null,
                        $age,
                        $address !== '' ? $address : null,
                        $gender !== '' ? $gender : null,
                        $mobileNumber !== '' ? $mobileNumber : null,
                    ]);

                    $pdo->commit();

                    $successMessage =
                        'Student information updated successfully.';
                }

                /*
                |--------------------------------------------------------------------------
                | CHANGE STUDENT SECTION
                |--------------------------------------------------------------------------
                */

            } elseif ($action === 'change_student_section') {

                $studentId = filter_var(
                    $_POST['student_id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                $targetSectionId = filter_var(
                    $_POST['section_id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                if (!$studentId || !$targetSectionId) {

                    $errorMessage =
                        'Please select a valid student and destination section.';

                } else {

                    $pdo->beginTransaction();

                    /*
                     * GET DESTINATION SECTION INFORMATION.
                     */

                    $sectionCheck = $pdo->prepare(
                        "SELECT
                id,
                section_name,
                course,
                year_level
             FROM sections
             WHERE id = ?
             FOR UPDATE"
                    );

                    $sectionCheck->execute([$targetSectionId]);

                    $targetSection = $sectionCheck->fetch(PDO::FETCH_ASSOC);

                    if (!$targetSection) {
                        throw new DomainException(
                            'The selected destination section could not be found.'
                        );
                    }

                    /*
                     * VERIFY STUDENT.
                     */

                    $studentCheck = $pdo->prepare(
                        "SELECT id
             FROM users
             WHERE id = ?
               AND role = 'student'
             FOR UPDATE"
                    );

                    $studentCheck->execute([$studentId]);

                    if (!$studentCheck->fetchColumn()) {
                        throw new DomainException(
                            'The selected student account could not be found.'
                        );
                    }

                    /*
                     * GET CURRENT SECTION ASSIGNMENT.
                     */

                    $assignmentCheck = $pdo->prepare(
                        "SELECT section_id
             FROM section_students
             WHERE student_id = ?
             LIMIT 1
             FOR UPDATE"
                    );

                    $assignmentCheck->execute([$studentId]);

                    $currentSectionId = $assignmentCheck->fetchColumn();

                    if ($currentSectionId === false) {
                        throw new DomainException(
                            'This student does not have an existing section assignment.'
                        );
                    }

                    if ((int) $currentSectionId === (int) $targetSectionId) {
                        throw new DomainException(
                            'The student is already assigned to this section.'
                        );
                    }

                    /*
                     * UPDATE OFFICIAL SECTION ASSIGNMENT.
                     */

                    $updateAssignment = $pdo->prepare(
                        "UPDATE section_students
             SET section_id = ?
             WHERE student_id = ?
               AND section_id = ?"
                    );

                    $updateAssignment->execute([
                        $targetSectionId,
                        $studentId,
                        (int) $currentSectionId,
                    ]);

                    if ($updateAssignment->rowCount() !== 1) {
                        throw new RuntimeException(
                            'Unable to transfer the student to the selected section.'
                        );
                    }

                    /*
                     * UPDATE STUDENT OVERALL ACADEMIC PLACEMENT.
                     */

                    adminSyncStudentPlacement(
                        $pdo,
                        $studentId,
                        $targetSection
                    );

                    $pdo->commit();

                    $successMessage =
                        'Student transferred successfully. Their academic placement has also been updated.';
                }

                /*
                |--------------------------------------------------------------------------
                | ASSIGN OR REMOVE SSP ADVISER
                |--------------------------------------------------------------------------
                */

            } elseif ($action === 'assign_adviser') {

                $sectionId = filter_var(
                    $_POST['section_id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                $adviserIdRaw = $_POST['adviser_id'] ?? '';

                if (!$sectionId) {

                    $errorMessage = 'Invalid section selected.';

                } else {

                    $adviserId = $adviserIdRaw === ''
                        ? null
                        : filter_var(
                            $adviserIdRaw,
                            FILTER_VALIDATE_INT,
                            ['options' => ['min_range' => 1]]
                        );

                    if ($adviserIdRaw !== '' && $adviserId === false) {

                        $errorMessage =
                            'Please select a valid faculty member.';

                    } else {

                        $sectionCheck = $pdo->prepare(
                            "SELECT id
                             FROM sections
                             WHERE id = ?
                             LIMIT 1"
                        );

                        $sectionCheck->execute([$sectionId]);

                        if (!$sectionCheck->fetchColumn()) {

                            $errorMessage =
                                'The selected section could not be found.';

                        } else {

                            if ($adviserId !== null) {

                                $facultyCheck = $pdo->prepare(
                                    "SELECT id
                                     FROM users
                                     WHERE id = ?
                                       AND role = 'faculty'
                                       AND is_active = 1
                                     LIMIT 1"
                                );

                                $facultyCheck->execute([$adviserId]);

                                if (!$facultyCheck->fetchColumn()) {
                                    $errorMessage =
                                        'The selected faculty account is unavailable or inactive.';
                                }
                            }

                            if ($errorMessage === '') {

                                $update = $pdo->prepare(
                                    "UPDATE sections
                                     SET ssp_adviser_id = ?
                                     WHERE id = ?"
                                );

                                $update->execute([
                                    $adviserId,
                                    $sectionId,
                                ]);

                                $successMessage =
                                    'SSP Adviser assignment saved successfully.';

                                if (!$isAjaxRequest) {

                                    $_SESSION['admin_dashboard_flash'] = $successMessage;

                                    header('Location: dashboard.php?page=sections');
                                    exit;
                                }
                            }
                        }
                    }
                }
            }

        } catch (DomainException $exception) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errorMessage = $exception->getMessage();

        } catch (PDOException $exception) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'AcadTrack admin database action error: '
                . $exception->getMessage()
            );

            $errorMessage = $exception->getCode() === '23000'
                ? 'This section, email, student number, or student assignment already exists.'
                : 'Unable to complete the request. Please try again.';

        } catch (Throwable $exception) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'AcadTrack admin unexpected error: '
                . $exception->getMessage()
                . ' | File: ' . $exception->getFile()
                . ' | Line: ' . $exception->getLine()
            );

            $errorMessage =
                'An unexpected error occurred. Please try again.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| DISPLAY FLASH MESSAGES
|--------------------------------------------------------------------------
*/

if (!empty($_SESSION['admin_dashboard_flash'])) {

    if ($successMessage === '') {
        $successMessage = (string) $_SESSION['admin_dashboard_flash'];
    }

    unset($_SESSION['admin_dashboard_flash']);
}

if (!empty($_SESSION['admin_dashboard_error_flash'])) {

    if ($errorMessage === '') {
        $errorMessage = (string) $_SESSION['admin_dashboard_error_flash'];
    }

    unset($_SESSION['admin_dashboard_error_flash']);
}

$adminProfileNotice = $_SESSION['admin_profile_notice'] ?? '';
unset($_SESSION['admin_profile_notice']);

$adminPasswordNotice = $_SESSION['admin_password_notice'] ?? '';
unset($_SESSION['admin_password_notice']);

$adminProfilePictureError = $_SESSION['admin_profile_picture_error'] ?? '';
unset($_SESSION['admin_profile_picture_error']);

/*
|--------------------------------------------------------------------------
| LOAD DASHBOARD DATA
|--------------------------------------------------------------------------
*/

$facultyMembers = [];
$sections = [];
$students = [];
$studentsBySection = [];
$assignedSectionByStudent = [];
$managedAccounts = [];

try {

    /*
     * ACTIVE FACULTY MEMBERS
     */

    $facultyMembers = $pdo->query(
        "SELECT id, nickname, email
         FROM users
         WHERE role = 'faculty'
           AND is_active = 1
         ORDER BY nickname ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    /*
     * SECTIONS
     */

    $sections = $pdo->query(
        "SELECT
            s.id,
            s.section_name,
            s.course,
            s.year_level,
            s.ssp_adviser_id,
            u.nickname AS adviser_name
         FROM sections s
         LEFT JOIN users u
            ON u.id = s.ssp_adviser_id
            AND u.role = 'faculty'
         ORDER BY
            s.course ASC,
            s.year_level ASC,
            s.section_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    /*
     * ACTIVE STUDENTS
     */

    $students = $pdo->query(
        "SELECT
            u.id,
            u.nickname,
            u.student_number,
            u.email,

            ui.firstname,
            ui.middlename,
            ui.lastname,

            s.course,
            s.year_level

         FROM users u

         LEFT JOIN user_information ui
            ON ui.user_id = u.id

         LEFT JOIN section_students ss
            ON ss.student_id = u.id

         LEFT JOIN sections s
            ON s.id = ss.section_id

         WHERE u.role = 'student'
           AND u.is_active = 1

         ORDER BY
            COALESCE(NULLIF(ui.lastname, ''), u.nickname) ASC,
            ui.firstname ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    /*
     * OFFICIAL SECTION ENROLLMENTS
     */

    $assignments = $pdo->query(
        "SELECT
            ss.student_id,
            ss.section_id,

            u.nickname,
            u.student_number,
            u.email,

            ui.firstname,
            ui.middlename,
            ui.lastname,

            ui.age,
            ui.address,
            ui.gender,
            ui.mobile_number

         FROM section_students ss

         INNER JOIN users u
            ON u.id = ss.student_id

         LEFT JOIN user_information ui
            ON ui.user_id = u.id

         WHERE u.role = 'student'

         ORDER BY
            COALESCE(NULLIF(ui.lastname, ''), u.nickname) ASC,
            ui.firstname ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($assignments as $assignment) {

        $sectionId = (int) $assignment['section_id'];
        $studentId = (int) $assignment['student_id'];

        $studentsBySection[$sectionId][] = $assignment;
        $assignedSectionByStudent[$studentId] = $sectionId;
    }

    /*
     * STUDENT AND FACULTY ACCOUNTS FOR MANAGE ACCOUNTS.
     * Includes inactive accounts so administrators can review all records.
     */

    $managedAccounts = $pdo->query(
        "SELECT
            u.id,
            u.nickname,
            u.student_number,
            u.email,
            u.role,
            u.is_active,
            u.created_at,

            ui.firstname,
            ui.middlename,
            ui.lastname,
            ui.age,
            ui.address,
            ui.gender,
            ui.mobile_number,

            s.section_name AS assigned_section

         FROM users u

         LEFT JOIN user_information ui
            ON ui.user_id = u.id

         LEFT JOIN section_students ss
            ON ss.student_id = u.id
            AND u.role = 'student'

         LEFT JOIN sections s
            ON s.id = ss.section_id

         WHERE u.role IN ('student', 'faculty')

         ORDER BY
            u.role ASC,
            COALESCE(NULLIF(ui.lastname, ''), u.nickname) ASC,
            ui.firstname ASC,
            u.id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $exception) {

    error_log(
        'AcadTrack admin dashboard query error: '
        . $exception->getMessage()
    );

    $facultyMembers = [];
    $sections = [];
    $students = [];
    $studentsBySection = [];
    $assignedSectionByStudent = [];
    $managedAccounts = [];

    $errorMessage = $errorMessage
        ?: 'Unable to load dashboard data. Please check the database setup.';
}

/*
|--------------------------------------------------------------------------
| CALCULATE SUMMARY STATISTICS
|--------------------------------------------------------------------------
*/

$assignedCount = 0;

foreach ($sections as $section) {

    if (
        !empty($section['ssp_adviser_id'])
        && !empty($section['adviser_name'])
    ) {
        $assignedCount++;
    }
}

$unassignedCount = count($sections) - $assignedCount;

$assignedStudentCount = count($assignedSectionByStudent);

$unassignedStudentCount = max(
    0,
    count($students) - $assignedStudentCount
);

/*
|--------------------------------------------------------------------------
| PAGE INFORMATION
|--------------------------------------------------------------------------
*/

$pageTitles = [
    'dashboard' => 'Dashboard',
    'sections' => 'Section & Student Management',
    'accounts' => 'Manage Accounts',
];

$pageDescriptions = [
    'dashboard' => 'Here is your AcadTrack administration overview.',
    'sections' => 'Create sections, manage students, and assign SSP Advisers.',
    'accounts' => 'Create, update, and manage student and faculty accounts.',
];

$pageTitle = $pageTitles[$currentPage];
$pageDescription = $pageDescriptions[$currentPage];

$studentYearLabels = [
    '1' => '1st Year',
    '2' => '2nd Year',
    '3' => '3rd Year',
    '4' => '4th Year',
];

/*
|--------------------------------------------------------------------------
| PROFILE FORM VALUES
|--------------------------------------------------------------------------
*/

if (!$isAdminProfilePost) {
    $profileFormValues = $adminProfileDefaults;
}
?>

<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#ffffff">

    <title>AcadTrack | <?= adminEscape($pageTitle) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://unpkg.com/lucide@latest"></script>

    <link rel="stylesheet" href="../../style/admin/admin_dash.css">
    <link rel="stylesheet" href="../../style/admin/admin_account.css">
    <script src="../../js/admin/admin_dash.js" defer></script>
    <script src="../../js/admin/admin_accounts.js" defer></script>
    <script src="../../js/admin/admin_reset_data.js" defer></script>
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

                    <div class="section-caption">MENU</div>

                    <a href="dashboard.php" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>"
                        data-nav-link data-label="Dashboard">

                        <i data-lucide="layout-dashboard"></i>
                        <span class="nav-text">Dashboard</span>

                    </a>

                    <a href="dashboard.php?page=sections"
                        class="nav-item <?= $currentPage === 'sections' ? 'active' : '' ?>" data-nav-link
                        data-label="Section & Student Management">

                        <i data-lucide="users-round"></i>
                        <span class="nav-text">Section &amp; Student Mngmt.</span>

                    </a>

                    <a href="dashboard.php?page=accounts"
                        class="nav-item <?= $currentPage === 'accounts' ? 'active' : '' ?>" data-nav-link
                        data-label="Manage Accounts">

                        <i data-lucide="user-round-cog"></i>
                        <span class="nav-text">Manage Accounts</span>

                    </a>

                </div>

                <div class="nav-section">

                    <!-- <div class="section-caption">ACCOUNT</div>

                    <a href="#" class="nav-item" data-open-profile-modal>

                        <i data-lucide="user-round"></i>
                        <span class="nav-text">My Profile</span>

                    </a>

                    <a href="#" class="nav-item" data-open-password-modal>

                        <i data-lucide="settings-2"></i>
                        <span class="nav-text">Account Settings</span>

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
                        <span>Contact Developer</span>
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

                        <span>Admin Portal</span>

                        <i data-lucide="chevron-right"></i>

                        <strong><?= adminEscape($pageTitle) ?></strong>

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

                                <?php if ($adminProfilePictureUrl !== ''): ?>

                                    <img src="<?= adminEscape($adminProfilePictureUrl) ?>"
                                        alt="Administrator profile picture">

                                <?php else: ?>

                                    <?= adminEscape($adminInitial) ?>

                                <?php endif; ?>

                            </span>

                            <span class="profile-info">

                                <strong><?= adminEscape($adminName) ?></strong>

                                <small>Administrator</small>

                            </span>

                            <i class="profile-chevron" data-lucide="chevron-down"></i>

                        </button>

                        <div class="dropdown profile-menu" id="profile-menu" hidden>

                            <div class="profile-menu-user">

                                <span class="profile-avatar large">

                                    <?php if ($adminProfilePictureUrl !== ''): ?>

                                        <img src="<?= adminEscape($adminProfilePictureUrl) ?>"
                                            alt="Administrator profile picture">

                                    <?php else: ?>

                                        <?= adminEscape($adminInitial) ?>

                                    <?php endif; ?>

                                </span>

                                <div>

                                    <strong><?= adminEscape($adminName) ?></strong>

                                    <small>Administrator account</small>

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

                            <a href="#" data-open-reset-data-modal>

                                <i data-lucide="database"></i>

                                Reset Data

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

                <section class="dashboard-page" id="dashboard-home-page">

                    <div class="page-title-row">

                        <div>

                            <div class="overline">ADMIN WORKSPACE</div>

                            <h1><?= adminEscape($pageTitle) ?></h1>

                            <p class="page-subtitle">

                                Welcome, <?= adminEscape($adminName) ?>.

                                <?= adminEscape($pageDescription) ?>

                            </p>

                        </div>

                    </div>

                    <!-- SUCCESS MESSAGE -->

                    <?php if ($successMessage !== ''): ?>

                        <div class="admin-alert admin-alert-success" role="status">

                            <i data-lucide="check-circle"></i>

                            <span>
                                <?= adminEscape($successMessage) ?>
                            </span>

                        </div>

                    <?php endif; ?>

                    <!-- ERROR MESSAGE -->

                    <?php if ($errorMessage !== ''): ?>

                        <div class="admin-alert admin-alert-error" role="alert">

                            <i data-lucide="alert-circle"></i>

                            <span>
                                <?= adminEscape($errorMessage) ?>
                            </span>

                        </div>

                    <?php endif; ?>

                    <!-- DASHBOARD SUMMARY -->

                    <?php if ($currentPage === 'dashboard'): ?>

                        <div class="admin-stats-grid">

                            <article class="admin-stat-card">

                                <span class="admin-stat-icon">
                                    <i data-lucide="layers"></i>
                                </span>

                                <div>

                                    <span class="admin-stat-label">
                                        Total Sections
                                    </span>

                                    <strong><?= count($sections) ?></strong>

                                </div>

                            </article>

                            <article class="admin-stat-card">

                                <span class="admin-stat-icon stat-success">
                                    <i data-lucide="user-check"></i>
                                </span>

                                <div>

                                    <span class="admin-stat-label">
                                        Assigned Advisers
                                    </span>

                                    <strong><?= $assignedCount ?></strong>

                                </div>

                            </article>

                            <article class="admin-stat-card">

                                <span class="admin-stat-icon stat-warning">
                                    <i data-lucide="user-round-search"></i>
                                </span>

                                <div>

                                    <span class="admin-stat-label">
                                        Unassigned Sections
                                    </span>

                                    <strong><?= $unassignedCount ?></strong>

                                </div>

                            </article>

                            <article class="admin-stat-card">

                                <span class="admin-stat-icon">
                                    <i data-lucide="graduation-cap"></i>
                                </span>

                                <div>

                                    <span class="admin-stat-label">
                                        Total Students
                                    </span>

                                    <strong><?= count($students) ?></strong>

                                </div>

                            </article>

                            <article class="admin-stat-card">

                                <span class="admin-stat-icon stat-success">
                                    <i data-lucide="users-round"></i>
                                </span>

                                <div>

                                    <span class="admin-stat-label">
                                        Assigned Students
                                    </span>

                                    <strong><?= $assignedStudentCount ?></strong>

                                </div>

                            </article>

                            <article class="admin-stat-card">

                                <span class="admin-stat-icon stat-warning">
                                    <i data-lucide="user-round"></i>
                                </span>

                                <div>

                                    <span class="admin-stat-label">
                                        Unassigned Students
                                    </span>

                                    <strong><?= $unassignedStudentCount ?></strong>

                                </div>

                            </article>

                        </div>

                    <?php endif; ?>

                    <!-- SECTION MANAGEMENT -->

                    <?php if ($currentPage === 'sections'): ?>

                        <section class="admin-panel section-management-panel" id="section-management">

                            <div class="admin-panel-heading">

                                <div>

                                    <h2>Manage Sections</h2>

                                    <p>
                                        Search for a section to manage its students
                                        and SSP Adviser.
                                    </p>

                                </div>

                                <span class="admin-count">

                                    <?= count($sections) ?>

                                    section<?= count($sections) === 1 ? '' : 's' ?>

                                </span>

                            </div>

                            <!-- SECTION TOOLBAR -->

                            <div class="section-toolbar">

                                <div class="section-filter-group">

                                    <div class="section-search-box">

                                        <i data-lucide="search"></i>

                                        <input type="search" id="section-search"
                                            placeholder="Search course, section, or year level..." autocomplete="off"
                                            aria-label="Search sections">

                                    </div>

                                    <div class="section-year-filter">

                                        <i data-lucide="filter"></i>

                                        <select id="section-year-filter" aria-label="Filter sections by year level">

                                            <option value="all">Select Year</option>
                                            <option value="1">1st Year</option>
                                            <option value="2">2nd Year</option>
                                            <option value="3">3rd Year</option>
                                            <option value="4">4th Year</option>

                                        </select>

                                    </div>

                                </div>

                                <button type="button" class="button button-primary" id="open-create-section-modal">

                                    <i data-lucide="plus"></i>

                                    <span>Create Section</span>

                                </button>

                            </div>

                            <div class="section-search-status" id="section-search-status" aria-live="polite">

                                No search results yet. Start typing to find a section,
                                or create a new one.

                            </div>

                            <!-- MANAGED SECTIONS -->

                            <div class="managed-sections" id="managed-sections">

                                <?php foreach ($sections as $section): ?>

                                    <?php

                                    $sid = (int) $section['id'];

                                    $sectionStudents = $studentsBySection[$sid] ?? [];

                                    $searchText = strtolower(
                                        (string) $section['section_name']
                                        . ' '
                                        . (string) $section['course']
                                        . ' '
                                        . (string) $section['year_level']
                                    );

                                    ?>

                                    <article class="managed-section-card searchable-section-card" data-section-id="<?= $sid ?>"
                                        data-section-course="<?= adminEscape($section['course']) ?>"
                                        data-section-search="<?= adminEscape($searchText) ?>"
                                        data-section-year-level="<?= adminEscape($section['year_level']) ?>" hidden>

                                        <div class="managed-section-heading">

                                            <div class="managed-section-title">

                                                <span class="section-row-icon">
                                                    <i data-lucide="users-round"></i>
                                                </span>

                                                <div>

                                                    <h3>
                                                        <?= adminEscape($section['section_name']) ?>
                                                    </h3>

                                                    <p>

                                                        <?= count($sectionStudents) ?>

                                                        assigned student<?= count($sectionStudents) === 1 ? '' : 's' ?>

                                                    </p>

                                                    <div class="section-current-adviser">

                                                        <i data-lucide="user-check"></i>

                                                        <span>

                                                            <?php if (!empty($section['adviser_name'])): ?>

                                                                SSP Adviser:

                                                                <strong>
                                                                    <?= adminEscape($section['adviser_name']) ?>
                                                                </strong>

                                                            <?php else: ?>

                                                                <strong class="no-adviser">
                                                                    No Adviser
                                                                </strong>

                                                            <?php endif; ?>

                                                        </span>

                                                    </div>

                                                </div>

                                            </div>

                                            <div class="section-card-actions">

                                                <button type="button" class="button button-primary" data-open-student-modal
                                                    data-section-id="<?= $sid ?>"
                                                    data-section-name="<?= adminEscape($section['section_name']) ?>">

                                                    <i data-lucide="user-plus"></i>

                                                    <span>Add Student</span>

                                                </button>

                                                <button type="button" class="button button-secondary" data-open-adviser-modal
                                                    data-section-id="<?= $sid ?>"
                                                    data-section-name="<?= adminEscape($section['section_name']) ?>">

                                                    <i data-lucide="user-check"></i>

                                                    <span>Assign SSP Adviser</span>

                                                </button>
                                                <!-- SECTION EXPORT DROPDOWN -->

                                                <div class="section-export-wrap">

                                                    <button type="button" class="button button-secondary section-export-toggle"
                                                        data-section-export-toggle aria-haspopup="true" aria-expanded="false">

                                                        <i data-lucide="download"></i>

                                                        <span>Export</span>

                                                        <i data-lucide="chevron-down" class="export-chevron"></i>

                                                    </button>

                                                    <div class="section-export-menu" role="menu"
                                                        aria-label="Export section information" hidden>

                                                        <button type="button" class="section-export-option"
                                                            data-section-export="pdf" role="menuitem">

                                                            <i data-lucide="file-text"></i>

                                                            <span>Export as PDF</span>

                                                        </button>

                                                        <button type="button" class="section-export-option"
                                                            data-section-export="excel" role="menuitem">

                                                            <i data-lucide="file-spreadsheet"></i>

                                                            <span>Export as Excel</span>

                                                        </button>

                                                        <button type="button" class="section-export-option"
                                                            data-section-export="word" role="menuitem">

                                                            <i data-lucide="file-type"></i>

                                                            <span>Export as Word</span>

                                                        </button>

                                                    </div>

                                                </div>

                                            </div>

                                        </div>

                                        <!-- ENROLLED STUDENTS -->

                                        <div class="section-student-list">

                                            <div class="section-student-list-heading">

                                                <strong>Enrolled Students</strong>

                                                <span><?= count($sectionStudents) ?></span>

                                            </div>

                                            <?php if (empty($sectionStudents)): ?>

                                                <div class="section-student-empty">
                                                    No students assigned to this section yet.
                                                </div>

                                            <?php else: ?>

                                                <div class="section-student-scroll">

                                                    <?php foreach ($sectionStudents as $student): ?>

                                                        <?php

                                                        $studentFirstName = (string) ($student['firstname'] ?? '');
                                                        $studentMiddleName = (string) ($student['middlename'] ?? '');
                                                        $studentLastName = (string) ($student['lastname'] ?? '');

                                                        $studentFullName = trim(
                                                            implode(
                                                                ' ',
                                                                array_filter([
                                                                    $studentFirstName,
                                                                    $studentMiddleName,
                                                                    $studentLastName,
                                                                ])
                                                            )
                                                        );

                                                        if ($studentFullName === '') {
                                                            $studentFullName = (string) $student['nickname'];
                                                        }

                                                        ?>

                                                        <div class="section-student-row">

                                                            <span class="mini-avatar">

                                                                <?= adminEscape(
                                                                    strtoupper(
                                                                        substr(
                                                                            (string) $student['nickname'],
                                                                            0,
                                                                            1
                                                                        )
                                                                    )
                                                                ) ?>

                                                            </span>

                                                            <div class="section-student-info">

                                                                <strong>
                                                                    <?= adminEscape($student['nickname']) ?>
                                                                </strong>

                                                                <small>

                                                                    <?= adminEscape(
                                                                        $student['student_number']
                                                                        ?? 'No student number'
                                                                    ) ?>

                                                                    ·

                                                                    <?= adminEscape($student['email']) ?>

                                                                </small>

                                                            </div>

                                                            <div class="section-student-actions">

                                                                <!-- EDIT STUDENT -->

                                                                <button type="button"
                                                                    class="student-row-action-button edit-student-button"
                                                                    data-open-edit-student-modal data-section-id="<?= $sid ?>"
                                                                    data-section-name="<?= adminEscape($section['section_name']) ?>"
                                                                    data-student-id="<?= (int) $student['student_id'] ?>"
                                                                    data-student-nickname="<?= adminEscape($student['nickname']) ?>"
                                                                    data-student-number="<?= adminEscape($student['student_number'] ?? '') ?>"
                                                                    data-student-email="<?= adminEscape($student['email']) ?>"
                                                                    data-student-firstname="<?= adminEscape($studentFirstName) ?>"
                                                                    data-student-middlename="<?= adminEscape($studentMiddleName) ?>"
                                                                    data-student-lastname="<?= adminEscape($studentLastName) ?>"
                                                                    data-student-age="<?= adminEscape($student['age'] ?? '') ?>"
                                                                    data-student-gender="<?= adminEscape($student['gender'] ?? '') ?>"
                                                                    data-student-mobile-number="<?= adminEscape($student['mobile_number'] ?? '') ?>"
                                                                    data-student-address="<?= adminEscape($student['address'] ?? '') ?>"
                                                                    aria-label="Edit student information" title="Edit student">

                                                                    <i data-lucide="pencil"></i>

                                                                </button>

                                                                <!-- CHANGE SECTION -->

                                                                <button type="button"
                                                                    class="student-row-action-button change-student-section-button"
                                                                    data-open-change-section-modal data-section-id="<?= $sid ?>"
                                                                    data-section-name="<?= adminEscape($section['section_name']) ?>"
                                                                    data-student-id="<?= (int) $student['student_id'] ?>"
                                                                    data-student-full-name="<?= adminEscape($studentFullName) ?>"
                                                                    aria-label="Change student's section" title="Change section">

                                                                    <i data-lucide="arrow-left-right"></i>

                                                                </button>

                                                                <!-- REMOVE STUDENT -->

                                                                <form method="POST" action="dashboard.php?page=sections"
                                                                    onsubmit="return confirm('Remove this student from the section?');">

                                                                    <input type="hidden" name="csrf_token"
                                                                        value="<?= adminEscape($csrfToken) ?>">

                                                                    <input type="hidden" name="action" value="remove_student">

                                                                    <input type="hidden" name="section_id" value="<?= $sid ?>">

                                                                    <input type="hidden" name="student_id"
                                                                        value="<?= (int) $student['student_id'] ?>">

                                                                    <button type="submit" class="remove-student-button"
                                                                        aria-label="Remove student from section"
                                                                        title="Remove from section">

                                                                        <i data-lucide="user-minus"></i>

                                                                    </button>

                                                                </form>

                                                            </div>

                                                        </div>

                                                    <?php endforeach; ?>

                                                </div>

                                            <?php endif; ?>

                                        </div>

                                    </article>

                                <?php endforeach; ?>

                                <div class="section-search-empty" id="section-search-empty" hidden>

                                    <span>
                                        <i data-lucide="search-x"></i>
                                    </span>

                                    <strong id="section-search-empty-title">
                                        No matching sections
                                    </strong>

                                    <p id="section-search-empty-description">
                                        Try another course, section name, or year level.
                                    </p>

                                </div>

                            </div>

                            <!-- ADD STUDENT MODAL -->

                            <div class="admin-modal-overlay" id="add-student-modal" hidden>

                                <div class="admin-modal" role="dialog" aria-modal="true"
                                    aria-labelledby="add-student-modal-title">

                                    <div class="admin-modal-header">

                                        <div>

                                            <h2 id="add-student-modal-title">
                                                Add Student
                                            </h2>

                                            <p>
                                                Assign an existing student account to
                                                <strong data-modal-section-name></strong>.
                                            </p>

                                        </div>

                                        <button type="button" class="icon-btn" data-close-admin-modal
                                            aria-label="Close modal">

                                            <i data-lucide="x"></i>

                                        </button>

                                    </div>

                                    <form method="POST" action="dashboard.php?page=sections" class="admin-modal-form">

                                        <input type="hidden" name="csrf_token" value="<?= adminEscape($csrfToken) ?>">

                                        <input type="hidden" name="action" value="assign_student">

                                        <input type="hidden" name="section_id" data-modal-section-id>

                                        <label for="modal-student-search">
                                            Search Student
                                        </label>

                                        <div class="student-search-container" id="student-search-container">

                                            <input type="search" id="modal-student-search" class="modal-search-input"
                                                placeholder="Search by student name or student number..." autocomplete="off"
                                                aria-label="Search student by name or student number"
                                                aria-controls="modal-student-results" aria-autocomplete="list">

                                            <div class="student-search-results" id="modal-student-results" role="list"
                                                aria-label="Matching available students" hidden>

                                                <?php foreach ($students as $student): ?>

                                                    <?php

                                                    $uid = (int) $student['id'];

                                                    if (isset($assignedSectionByStudent[$uid])) {
                                                        continue;
                                                    }

                                                    $firstName = (string) ($student['firstname'] ?? '');
                                                    $middleName = (string) ($student['middlename'] ?? '');
                                                    $lastName = (string) ($student['lastname'] ?? '');

                                                    $fullName = trim(
                                                        implode(
                                                            ' ',
                                                            array_filter([
                                                                $firstName,
                                                                $middleName,
                                                                $lastName,
                                                            ])
                                                        )
                                                    );

                                                    if ($fullName === '') {
                                                        $fullName = (string) $student['nickname'];
                                                    }

                                                    $studentNumber = (string) ($student['student_number'] ?? '');
                                                    $course = (string) ($student['course'] ?? '');

                                                    $yearLevelValue = $student['year_level'] !== null
                                                        ? (string) $student['year_level']
                                                        : '';

                                                    $yearLevelLabel = $studentYearLabels[$yearLevelValue]
                                                        ?? (
                                                            $yearLevelValue !== ''
                                                            ? 'Year ' . $yearLevelValue
                                                            : ''
                                                        );

                                                    $searchText = strtolower(
                                                        $fullName . ' '
                                                        . $student['nickname'] . ' '
                                                        . $studentNumber . ' '
                                                        . $student['email']
                                                    );

                                                    ?>

                                                    <button type="button" class="student-search-result"
                                                        data-student-id="<?= $uid ?>"
                                                        data-search="<?= adminEscape($searchText) ?>"
                                                        data-student-number="<?= adminEscape($studentNumber) ?>"
                                                        data-firstname="<?= adminEscape($firstName) ?>"
                                                        data-middlename="<?= adminEscape($middleName) ?>"
                                                        data-lastname="<?= adminEscape($lastName) ?>"
                                                        data-course="<?= adminEscape($course) ?>"
                                                        data-year-level="<?= adminEscape($yearLevelLabel) ?>"
                                                        data-full-name="<?= adminEscape($fullName) ?>">

                                                        <span class="student-result-avatar">
                                                            <i data-lucide="user-round"></i>
                                                        </span>

                                                        <span class="student-result-text">

                                                            <strong>
                                                                <?= adminEscape($fullName) ?>
                                                            </strong>

                                                            <small>

                                                                <?= adminEscape(
                                                                    $studentNumber ?: 'No student number'
                                                                ) ?>

                                                                ·

                                                                <?= adminEscape($student['email']) ?>

                                                            </small>

                                                        </span>

                                                        <i data-lucide="chevron-right" class="student-result-arrow"></i>

                                                    </button>

                                                <?php endforeach; ?>

                                            </div>

                                        </div>

                                        <p class="student-search-hint" id="student-search-hint" aria-live="polite">
                                        </p>

                                        <div class="selected-student-card" id="selected-student-card" hidden>

                                            <div class="selected-student-heading">

                                                <span class="selected-student-icon">
                                                    <i data-lucide="user-check"></i>
                                                </span>

                                                <div class="selected-student-title">
                                                    <p id="selected-student-name" style="font-weight: bold;"></p>
                                                </div>

                                                <button type="button" class="student-change-button"
                                                    id="change-selected-student">
                                                    Change
                                                </button>

                                            </div>

                                            <div class="student-information-grid">

                                                <div class="student-information-field">
                                                    <label for="selected-student-number">Student Number</label>
                                                    <input type="text" id="selected-student-number" data-student-info
                                                        readonly>
                                                </div>

                                                <div class="student-information-field">
                                                    <label for="selected-student-firstname">Firstname</label>
                                                    <input type="text" id="selected-student-firstname" data-student-info
                                                        readonly>
                                                </div>

                                                <div class="student-information-field">
                                                    <label for="selected-student-middlename">Middlename</label>
                                                    <input type="text" id="selected-student-middlename" data-student-info
                                                        readonly>
                                                </div>

                                                <div class="student-information-field">
                                                    <label for="selected-student-lastname">Lastname</label>
                                                    <input type="text" id="selected-student-lastname" data-student-info
                                                        readonly>
                                                </div>

                                                <div class="student-information-field">
                                                    <label for="selected-student-course">Course</label>
                                                    <input type="text" id="selected-student-course" data-student-info
                                                        readonly>
                                                </div>

                                                <div class="student-information-field">
                                                    <label for="selected-student-year-level">Year Level</label>
                                                    <input type="text" id="selected-student-year-level" data-student-info
                                                        readonly>
                                                </div>

                                            </div>

                                        </div>

                                        <input type="hidden" name="student_id" id="modal-student-id" value="">

                                        <div class="admin-modal-actions">

                                            <button type="button" class="button button-secondary" data-close-admin-modal>
                                                Cancel
                                            </button>

                                            <button type="submit" class="button button-primary">
                                                <i data-lucide="user-plus"></i>
                                                Add Student
                                            </button>

                                        </div>

                                    </form>

                                </div>

                            </div>

                            <!-- ASSIGN SSP ADVISER MODAL -->

                            <div class="admin-modal-overlay" id="assign-adviser-modal" hidden>

                                <div class="admin-modal" role="dialog" aria-modal="true"
                                    aria-labelledby="assign-adviser-modal-title">

                                    <div class="admin-modal-header">

                                        <div>

                                            <h2 id="assign-adviser-modal-title">
                                                Assign SSP Adviser
                                            </h2>

                                            <p>
                                                Choose a faculty member for
                                                <strong data-modal-section-name></strong>.
                                            </p>

                                        </div>

                                        <button type="button" class="icon-btn" data-close-admin-modal
                                            aria-label="Close modal">

                                            <i data-lucide="x"></i>

                                        </button>

                                    </div>

                                    <form method="POST" action="dashboard.php?page=sections" class="admin-modal-form">

                                        <input type="hidden" name="csrf_token" value="<?= adminEscape($csrfToken) ?>">

                                        <input type="hidden" name="action" value="assign_adviser">

                                        <input type="hidden" name="section_id" data-modal-section-id>

                                        <label for="modal-adviser-search">
                                            Search Faculty
                                        </label>

                                        <div class="student-search-container" id="adviser-search-container">

                                            <input type="search" id="modal-adviser-search" class="modal-search-input"
                                                placeholder="Search by faculty name or email..." autocomplete="off"
                                                aria-label="Search faculty by name or email"
                                                aria-controls="modal-adviser-results" aria-autocomplete="list">

                                            <div class="student-search-results" id="modal-adviser-results" role="list"
                                                aria-label="Matching faculty members" hidden>

                                                <?php foreach ($facultyMembers as $faculty): ?>

                                                    <?php

                                                    $facultyId = (int) $faculty['id'];
                                                    $facultyName = (string) ($faculty['nickname'] ?? '');
                                                    $facultyEmail = (string) ($faculty['email'] ?? '');

                                                    $facultySearch = strtolower(
                                                        $facultyName . ' ' . $facultyEmail
                                                    );

                                                    ?>

                                                    <button type="button" class="student-search-result faculty-search-result"
                                                        data-faculty-id="<?= $facultyId ?>"
                                                        data-search="<?= adminEscape($facultySearch) ?>"
                                                        data-faculty-name="<?= adminEscape($facultyName) ?>"
                                                        data-faculty-email="<?= adminEscape($facultyEmail) ?>"
                                                        aria-label="Select faculty <?= adminEscape($facultyName) ?>">

                                                        <span class="student-result-avatar">
                                                            <i data-lucide="user-round"></i>
                                                        </span>

                                                        <span class="student-result-text">

                                                            <strong>
                                                                <?= adminEscape($facultyName) ?>
                                                            </strong>

                                                            <small>
                                                                <?= adminEscape($facultyEmail) ?>
                                                            </small>

                                                        </span>

                                                        <i data-lucide="chevron-right" class="student-result-arrow"></i>

                                                    </button>

                                                <?php endforeach; ?>

                                            </div>

                                        </div>

                                        <p class="student-search-hint" id="adviser-search-hint" aria-live="polite">
                                            Type a faculty member's name or email to search.
                                        </p>

                                        <input type="hidden" name="adviser_id" id="modal-adviser-id" value="">

                                        <div class="admin-modal-actions">

                                            <button type="button" class="button button-secondary" data-close-admin-modal>
                                                Cancel
                                            </button>

                                            <button type="submit" class="button button-primary">
                                                <i data-lucide="check"></i>
                                                Save Adviser
                                            </button>

                                        </div>

                                    </form>

                                </div>

                            </div>

                            <!-- EDIT STUDENT MODAL -->

                            <div class="admin-modal-overlay edit-student-modal" id="edit-student-modal" hidden>

                                <div class="admin-modal" role="dialog" aria-modal="true"
                                    aria-labelledby="edit-student-modal-title">

                                    <div class="admin-modal-header">

                                        <div>

                                            <h2 id="edit-student-modal-title">
                                                Edit Student
                                            </h2>

                                            <p>
                                                Update the student's account and personal information.
                                                Academic placement is managed separately.
                                            </p>

                                            <p class="student-modal-section-info">

                                                Current Section:

                                                <strong data-edit-current-section></strong>

                                            </p>

                                        </div>

                                        <button type="button" class="icon-btn" data-close-admin-modal
                                            aria-label="Close modal">

                                            <i data-lucide="x"></i>

                                        </button>

                                    </div>

                                    <form method="POST" action="dashboard.php?page=sections"
                                        class="admin-modal-form edit-student-form">

                                        <input type="hidden" name="csrf_token" value="<?= adminEscape($csrfToken) ?>">

                                        <input type="hidden" name="action" value="edit_student">

                                        <input type="hidden" name="student_id">

                                        <div class="student-form-section-title">
                                            Account Information
                                        </div>

                                        <div class="student-edit-grid">

                                            <div class="section-form-field">

                                                <label for="edit-student-nickname">
                                                    Nickname / Display Name
                                                </label>

                                                <input type="text" id="edit-student-nickname" name="nickname"
                                                    maxlength="150" required>

                                            </div>

                                            <div class="section-form-field">

                                                <label for="edit-student-number">
                                                    Student Number
                                                </label>

                                                <input type="text" id="edit-student-number" name="student_number"
                                                    maxlength="12" minlength="12" pattern="[0-9]{12}" inputmode="numeric"
                                                    placeholder="12-digit student number"
                                                    title="Enter exactly 12 digits or leave blank">

                                            </div>

                                            <div class="section-form-field student-edit-full-width">

                                                <label for="edit-student-email">
                                                    Email Address
                                                </label>

                                                <input type="email" id="edit-student-email" name="email" maxlength="191"
                                                    required>

                                            </div>

                                        </div>

                                        <div class="student-form-section-title">
                                            Personal Information
                                        </div>

                                        <div class="student-edit-grid">

                                            <div class="section-form-field">
                                                <label for="edit-student-firstname">Firstname</label>
                                                <input type="text" id="edit-student-firstname" name="firstname"
                                                    maxlength="100">
                                            </div>

                                            <div class="section-form-field">
                                                <label for="edit-student-middlename">Middlename</label>
                                                <input type="text" id="edit-student-middlename" name="middlename"
                                                    maxlength="100">
                                            </div>

                                            <div class="section-form-field">
                                                <label for="edit-student-lastname">Lastname</label>
                                                <input type="text" id="edit-student-lastname" name="lastname"
                                                    maxlength="100">
                                            </div>

                                            <div class="section-form-field">
                                                <label for="edit-student-age">Age</label>
                                                <input type="number" id="edit-student-age" name="age" min="1" max="120">
                                            </div>

                                            <div class="section-form-field">
                                                <label for="edit-student-gender">Gender</label>
                                                <input type="text" id="edit-student-gender" name="gender" maxlength="20">
                                            </div>

                                            <div class="section-form-field">
                                                <label for="edit-student-mobile">Mobile Number</label>
                                                <input type="text" id="edit-student-mobile" name="mobile_number"
                                                    maxlength="20">
                                            </div>

                                            <div class="section-form-field student-edit-full-width">

                                                <label for="edit-student-address">
                                                    Address
                                                </label>

                                                <textarea id="edit-student-address" name="address" maxlength="5000" rows="3"
                                                    placeholder="Student's complete address"></textarea>

                                            </div>

                                        </div>

                                        <p class="student-edit-hint">
                                            Updating personal information will not modify
                                            the student's assigned section, course,
                                            year level, password, or permit history.
                                        </p>

                                        <div class="admin-modal-actions">

                                            <button type="button" class="button button-secondary" data-close-admin-modal>
                                                Cancel
                                            </button>

                                            <button type="submit" class="button button-primary">
                                                <i data-lucide="save"></i>
                                                Save Changes
                                            </button>

                                        </div>

                                    </form>

                                </div>

                            </div>

                            <!-- CHANGE SECTION MODAL -->

                            <div class="admin-modal-overlay change-section-modal" id="change-section-modal" hidden>

                                <div class="admin-modal" role="dialog" aria-modal="true"
                                    aria-labelledby="change-section-modal-title">

                                    <div class="admin-modal-header">

                                        <div>

                                            <h2 id="change-section-modal-title">
                                                Change Student Section
                                            </h2>

                                            <p>

                                                Transfer

                                                <strong data-change-student-name></strong>

                                                from

                                                <strong data-change-current-section></strong>

                                                to another section.

                                            </p>

                                        </div>

                                        <button type="button" class="icon-btn" data-close-admin-modal
                                            aria-label="Close modal">

                                            <i data-lucide="x"></i>

                                        </button>

                                    </div>

                                    <form method="POST" action="dashboard.php?page=sections"
                                        class="admin-modal-form change-section-form">

                                        <input type="hidden" name="csrf_token" value="<?= adminEscape($csrfToken) ?>">

                                        <input type="hidden" name="action" value="change_student_section">

                                        <input type="hidden" name="student_id">

                                        <div class="section-form-field">

                                            <label for="change-student-section">
                                                Destination Section
                                            </label>

                                            <select id="change-student-section" name="section_id" required>

                                                <option value="" selected disabled>
                                                    Select destination section
                                                </option>

                                                <?php foreach ($sections as $availableSection): ?>

                                                    <?php

                                                    $availableYear =
                                                        (string) $availableSection['year_level'];

                                                    $availableYearLabel =
                                                        $studentYearLabels[$availableYear]
                                                        ?? 'Year ' . $availableYear;

                                                    ?>

                                                    <option value="<?= (int) $availableSection['id'] ?>">

                                                        <?= adminEscape(
                                                            $availableSection['course']
                                                            . ' · '
                                                            . $availableYearLabel
                                                            . ' · '
                                                            . $availableSection['section_name']
                                                        ) ?>

                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                        </div>

                                        <div class="change-section-notice">

                                            <i data-lucide="info"></i>

                                            <p>
                                                The student's official enrollment will be
                                                updated automatically. Course and year level
                                                will be determined by the destination section.
                                                Existing permit records will remain unchanged.
                                            </p>

                                        </div>

                                        <div class="admin-modal-actions">

                                            <button type="button" class="button button-secondary" data-close-admin-modal>
                                                Cancel
                                            </button>

                                            <button type="submit" class="button button-primary">
                                                <i data-lucide="arrow-left-right"></i>
                                                Change Section
                                            </button>

                                        </div>

                                    </form>

                                </div>

                            </div>

                            <?php if (empty($students)): ?>

                                <div class="admin-inline-note">

                                    <i data-lucide="info"></i>

                                    No active student accounts were found.
                                    Students must register before they can be assigned.

                                </div>

                            <?php endif; ?>

                        </section>

                        <!-- CREATE SECTION MODAL -->

                        <div class="section-modal-overlay" id="create-section-modal" hidden>

                            <div class="section-modal" role="dialog" aria-modal="true"
                                aria-labelledby="create-section-modal-title">

                                <div class="section-modal-header">

                                    <div>

                                        <h2 id="create-section-modal-title">
                                            Create Section
                                        </h2>

                                        <p>
                                            Enter the course, year level, and section name.
                                        </p>

                                    </div>

                                    <button type="button" class="icon-btn" id="close-create-section-modal"
                                        aria-label="Close modal">

                                        <i data-lucide="x"></i>

                                    </button>

                                </div>

                                <form method="POST" action="dashboard.php?page=sections" class="create-section-modal-form">

                                    <input type="hidden" name="csrf_token" value="<?= adminEscape($csrfToken) ?>">

                                    <input type="hidden" name="action" value="add_section">

                                    <div class="section-form-field">

                                        <label for="new-course">
                                            Course
                                        </label>

                                        <input type="text" id="new-course" name="course" maxlength="50"
                                            placeholder="e.g. BSIT" required>

                                    </div>

                                    <div class="section-form-field">

                                        <label for="new-year-level">
                                            Year Level
                                        </label>

                                        <select id="new-year-level" name="year_level" required>

                                            <option value="">Select year level</option>
                                            <option value="1">1st Year</option>
                                            <option value="2">2nd Year</option>
                                            <option value="3">3rd Year</option>
                                            <option value="4">4th Year</option>

                                        </select>

                                    </div>

                                    <div class="section-form-field">

                                        <label for="new-section-name">
                                            Section
                                        </label>

                                        <input type="text" id="new-section-name" name="section_name" maxlength="100"
                                            placeholder="e.g. 2-1" required>

                                    </div>

                                    <div class="section-modal-actions">

                                        <button type="button" class="button button-secondary" id="cancel-create-section">
                                            Cancel
                                        </button>

                                        <button type="submit" class="button button-primary">
                                            <i data-lucide="plus"></i>
                                            <span>Create Section</span>
                                        </button>

                                    </div>

                                </form>

                            </div>

                        </div>

                    <?php endif; ?>


                    <!-- MANAGE ACCOUNTS -->

                    <?php if ($currentPage === 'accounts'): ?>

                        <section class="admin-panel account-management-panel" id="account-management">

                            <div class="admin-panel-heading">

                                <div>
                                    <h2>Manage Accounts</h2>
                                    <p>Create, edit, and delete student or faculty accounts.</p>
                                </div>

                                <span class="admin-count" id="accounts-count">
                                    <?= count($managedAccounts) ?> account<?= count($managedAccounts) === 1 ? '' : 's' ?>
                                </span>

                            </div>

                            <div class="account-toolbar">

                                <div class="account-filter-group">

                                    <label class="account-search-box" for="account-search">
                                        <i data-lucide="search"></i>
                                        <input type="search" id="account-search"
                                            placeholder="Search name, email, or student number..." autocomplete="off">
                                    </label>

                                    <label class="account-role-filter" for="account-role-filter">
                                        <i data-lucide="filter"></i>
                                        <select id="account-role-filter" aria-label="Filter accounts by type">
                                            <option value="all">All Accounts</option>
                                            <option value="student">Students</option>
                                            <option value="faculty">Faculty</option>
                                        </select>
                                    </label>

                                </div>

                                <button type="button" class="button button-primary" data-open-create-account>
                                    <i data-lucide="plus"></i>
                                    <span>Create Account</span>
                                </button>

                            </div>

                            <div class="account-list-summary" aria-live="polite">
                                <span id="account-results-count">
                                    <?= count($managedAccounts) ?> account<?= count($managedAccounts) === 1 ? '' : 's' ?>
                                    available
                                </span>
                                <span>Student and faculty accounts only</span>
                            </div>

                            <div class="accounts-table-wrap" id="accounts-table-wrap">

                                <?php if (!$managedAccounts): ?>

                                    <div class="account-empty-state" id="account-empty-state">
                                        <span><i data-lucide="users-round"></i></span>
                                        <strong>No student or faculty accounts found</strong>
                                        <p>Create an account to get started.</p>
                                    </div>

                                <?php else: ?>

                                    <div class="accounts-table-scroll">
                                        <table class="accounts-table">
                                            <thead>
                                                <tr>
                                                    <th>Account</th>
                                                    <th>Account Type</th>
                                                    <th>Email Address</th>
                                                    <th>Student Number</th>
                                                    <th>Status</th>
                                                    <th class="account-actions-heading">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>

                                                <?php foreach ($managedAccounts as $account): ?>

                                                    <?php
                                                    $accountId = (int) $account['id'];
                                                    $accountRole = (string) $account['role'];
                                                    $accountFullName = trim(implode(' ', array_filter([
                                                        (string) ($account['firstname'] ?? ''),
                                                        (string) ($account['middlename'] ?? ''),
                                                        (string) ($account['lastname'] ?? ''),
                                                    ], static fn($part) => $part !== '')));
                                                    $accountDisplayName = $accountFullName !== ''
                                                        ? $accountFullName
                                                        : (string) $account['nickname'];
                                                    $accountInitial = strtoupper(substr(
                                                        $accountDisplayName !== '' ? $accountDisplayName : 'U',
                                                        0,
                                                        1
                                                    ));
                                                    $accountSearchText = strtolower(implode(' ', [
                                                        $accountDisplayName,
                                                        (string) $account['nickname'],
                                                        (string) $account['email'],
                                                        (string) ($account['student_number'] ?? ''),
                                                        $accountRole,
                                                        (string) ($account['assigned_section'] ?? ''),
                                                    ]));
                                                    $createdAtLabel = !empty($account['created_at'])
                                                        ? date('M d, Y', strtotime((string) $account['created_at']))
                                                        : '—';
                                                    ?>

                                                    <tr class="account-row" data-account-row
                                                        data-account-role="<?= adminEscape($accountRole) ?>"
                                                        data-account-search="<?= adminEscape($accountSearchText) ?>">

                                                        <td>
                                                            <div class="account-identity">
                                                                <span
                                                                    class="account-avatar <?= $accountRole === 'faculty' ? 'account-avatar-faculty' : '' ?>">
                                                                    <?= adminEscape($accountInitial) ?>
                                                                </span>
                                                                <span class="account-identity-text">
                                                                    <strong><?= adminEscape($accountDisplayName) ?></strong>
                                                                    <small><?= adminEscape($account['nickname']) ?> · ID
                                                                        #<?= $accountId ?></small>
                                                                    <small>Created <?= adminEscape($createdAtLabel) ?></small>
                                                                    <?php if ($accountRole === 'student' && !empty($account['assigned_section'])): ?>
                                                                        <small class="account-section-label">
                                                                            <?= adminEscape($account['assigned_section']) ?>
                                                                        </small>
                                                                    <?php endif; ?>
                                                                </span>
                                                            </div>
                                                        </td>

                                                        <td>
                                                            <span
                                                                class="account-role-badge <?= $accountRole === 'faculty' ? 'faculty' : 'student' ?>">
                                                                <?= ucfirst(adminEscape($accountRole)) ?>
                                                            </span>
                                                        </td>

                                                        <td class="account-email-cell">
                                                            <?= adminEscape($account['email']) ?>
                                                        </td>

                                                        <td class="account-number-cell">
                                                            <?= $accountRole === 'student' && !empty($account['student_number'])
                                                                ? adminEscape($account['student_number'])
                                                                : '—' ?>
                                                        </td>

                                                        <td>
                                                            <span
                                                                class="account-status-badge <?= (int) $account['is_active'] === 1 ? 'active' : 'inactive' ?>">
                                                                <?= (int) $account['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                                            </span>
                                                        </td>

                                                        <td>
                                                            <div class="account-row-actions">

                                                                <button type="button"
                                                                    class="account-icon-action account-edit-action"
                                                                    aria-label="Edit <?= adminEscape($accountRole) ?> account"
                                                                    title="Edit account" data-edit-account
                                                                    data-account-id="<?= $accountId ?>"
                                                                    data-role="<?= adminEscape($accountRole) ?>"
                                                                    data-nickname="<?= adminEscape($account['nickname']) ?>"
                                                                    data-email="<?= adminEscape($account['email']) ?>"
                                                                    data-student-number="<?= adminEscape($account['student_number'] ?? '') ?>"
                                                                    data-firstname="<?= adminEscape($account['firstname'] ?? '') ?>"
                                                                    data-middlename="<?= adminEscape($account['middlename'] ?? '') ?>"
                                                                    data-lastname="<?= adminEscape($account['lastname'] ?? '') ?>"
                                                                    data-age="<?= adminEscape($account['age'] ?? '') ?>"
                                                                    data-gender="<?= adminEscape($account['gender'] ?? '') ?>"
                                                                    data-mobile-number="<?= adminEscape($account['mobile_number'] ?? '') ?>"
                                                                    data-address="<?= adminEscape($account['address'] ?? '') ?>"
                                                                    data-display-name="<?= adminEscape($accountDisplayName) ?>">
                                                                    <i data-lucide="pencil"></i>
                                                                </button>

                                                                <button type="button"
                                                                    class="account-icon-action account-delete-action"
                                                                    aria-label="Delete <?= adminEscape($accountRole) ?> account"
                                                                    title="Delete account" data-delete-account
                                                                    data-account-id="<?= $accountId ?>"
                                                                    data-role="<?= adminEscape($accountRole) ?>"
                                                                    data-display-name="<?= adminEscape($accountDisplayName) ?>"
                                                                    data-email="<?= adminEscape($account['email']) ?>">
                                                                    <i data-lucide="trash-2"></i>
                                                                </button>

                                                            </div>
                                                        </td>

                                                    </tr>

                                                <?php endforeach; ?>

                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="account-filter-empty" id="account-filter-empty" hidden>
                                        <i data-lucide="search-x"></i>
                                        <strong>No matching accounts</strong>
                                        <p>Try another search term or account type.</p>
                                    </div>

                                <?php endif; ?>

                            </div>

                            <div class="account-management-note">
                                <i data-lucide="info"></i>
                                <p>
                                    Deleting an account is permanent. Related records are handled by the existing database
                                    relationships.
                                    Student enrollment and permit records will be deleted with the student account.
                                    Faculty adviser and permit-review references will be cleared when a faculty account is
                                    deleted.
                                </p>
                            </div>

                        </section>

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
         MANAGE ACCOUNTS — CREATE / EDIT MODAL
    ====================================================== -->

    <div class="account-modal-overlay" id="account-form-modal" hidden>

        <section class="account-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="account-form-title"
            aria-describedby="account-form-description">

            <header class="account-modal-header">
                <div>
                    <h2 id="account-form-title">Create Account</h2>
                    <p id="account-form-description">Create a student or faculty account for AcadTrack.</p>
                </div>
                <button type="button" class="icon-btn" data-close-account-modal aria-label="Close account form">
                    <i data-lucide="x"></i>
                </button>
            </header>

            <form id="account-form" class="account-modal-form" action="dashboard.php?page=accounts" method="POST"
                autocomplete="off">

                <input type="hidden" name="csrf_token" value="<?= adminEscape($csrfToken) ?>">
                <input type="hidden" name="action" id="account-form-action" value="create_account">
                <input type="hidden" name="account_id" id="account-form-id" value="">

                <div class="account-form-section-heading">Account Information</div>

                <div class="account-form-grid">

                    <div class="account-form-field account-form-full">
                        <label for="account-role">Account Type <span>*</span></label>
                        <select id="account-role" name="role" required>
                            <option value="student">Student</option>
                            <option value="faculty">Faculty</option>
                        </select>
                        <small id="account-role-help">Choose whether this account belongs to a student or faculty
                            member.</small>
                    </div>

                    <div class="account-form-field account-form-full">
                        <label for="account-nickname">Account Name / Nickname <span>*</span></label>
                        <input type="text" id="account-nickname" name="nickname" maxlength="150"
                            placeholder="Enter the account display name" required>
                    </div>

                    <div class="account-form-field account-form-full">
                        <label for="account-email">Email Address <span>*</span></label>
                        <input type="email" id="account-email" name="email" maxlength="191"
                            placeholder="name@phinmaed.com" autocomplete="off" required>
                    </div>

                    <div class="account-form-field account-form-full" id="account-student-number-field">
                        <label for="account-student-number">Student Number <span>*</span></label>
                        <input type="text" id="account-student-number" name="student_number" inputmode="numeric"
                            maxlength="12" pattern="[0-9]{12}" placeholder="12-digit student number">
                        <small>Enter exactly 12 digits. This field is only used for student accounts.</small>
                    </div>

                    <div class="account-form-field account-form-full">
                        <label for="account-password" id="account-password-label">Initial Password
                            <span>*</span></label>
                        <input type="password" id="account-password" name="password" minlength="8" maxlength="72"
                            autocomplete="new-password" placeholder="Enter at least 8 characters">
                        <small id="account-password-help">The password must contain at least 8 characters.</small>
                    </div>

                </div>

                <div class="account-form-section-heading account-form-personal-heading">Personal Information
                    <small>Optional</small>
                </div>

                <div class="account-form-grid">

                    <div class="account-form-field">
                        <label for="account-firstname">Firstname</label>
                        <input type="text" id="account-firstname" name="firstname" maxlength="100"
                            placeholder="Firstname">
                    </div>

                    <div class="account-form-field">
                        <label for="account-middlename">Middlename</label>
                        <input type="text" id="account-middlename" name="middlename" maxlength="100"
                            placeholder="Middlename">
                    </div>

                    <div class="account-form-field">
                        <label for="account-lastname">Lastname</label>
                        <input type="text" id="account-lastname" name="lastname" maxlength="100" placeholder="Lastname">
                    </div>

                    <div class="account-form-field">
                        <label for="account-age">Age</label>
                        <input type="number" id="account-age" name="age" min="1" max="120" placeholder="Age">
                    </div>

                    <div class="account-form-field">
                        <label for="account-gender">Gender</label>
                        <select id="account-gender" name="gender">
                            <option value="">Select gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                            <option value="Prefer not to say">Prefer not to say</option>
                        </select>
                    </div>

                    <div class="account-form-field">
                        <label for="account-mobile-number">Mobile Number</label>
                        <input type="tel" id="account-mobile-number" name="mobile_number" maxlength="20"
                            placeholder="e.g. 09XXXXXXXXX">
                    </div>

                    <div class="account-form-field account-form-full">
                        <label for="account-address">Address</label>
                        <textarea id="account-address" name="address" maxlength="5000" rows="3"
                            placeholder="Enter complete address"></textarea>
                    </div>

                </div>

                <div class="account-modal-actions">
                    <button type="button" class="button button-secondary" data-close-account-modal>Cancel</button>
                    <button type="submit" class="button button-primary" id="account-form-submit">
                        <i data-lucide="user-plus"></i>
                        <span>Create Account</span>
                    </button>
                </div>

            </form>

        </section>

    </div>

    <!-- =====================================================
         MANAGE ACCOUNTS — DELETE CONFIRMATION
    ====================================================== -->

    <div class="account-modal-overlay account-delete-modal" id="account-delete-modal" hidden>

        <section class="account-modal-dialog account-delete-dialog" role="dialog" aria-modal="true"
            aria-labelledby="account-delete-title" aria-describedby="account-delete-description">

            <header class="account-modal-header">
                <div class="account-delete-heading">
                    <span class="account-delete-heading-icon"><i data-lucide="user-round-x"></i></span>
                    <div>
                        <h2 id="account-delete-title">Delete Account</h2>
                        <p id="account-delete-description">Review the account before permanently deleting it.</p>
                    </div>
                </div>
                <button type="button" class="icon-btn" data-close-account-modal aria-label="Close delete confirmation">
                    <i data-lucide="x"></i>
                </button>
            </header>

            <form id="account-delete-form" class="account-delete-content" action="dashboard.php?page=accounts"
                method="POST">
                <input type="hidden" name="csrf_token" value="<?= adminEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="delete_account">
                <input type="hidden" name="account_id" id="account-delete-id" value="">

                <div class="account-delete-summary">
                    <strong id="account-delete-name">Selected Account</strong>
                    <span id="account-delete-meta"></span>
                </div>

                <div class="account-delete-warning">
                    <i data-lucide="alert-triangle"></i>
                    <p id="account-delete-warning-text">
                        This action cannot be undone. Related records will be handled by the database foreign-key rules.
                    </p>
                </div>

                <div class="account-modal-actions">
                    <button type="button" class="button button-secondary" data-close-account-modal>Cancel</button>
                    <button type="submit" class="button button-danger" id="account-delete-submit">
                        <i data-lucide="trash-2"></i>
                        <span>Delete Account</span>
                    </button>
                </div>
            </form>

        </section>

    </div>

    <!-- =====================================================
         ADMIN PROFILE MODAL
    ====================================================== -->

    <div class="admin-profile-modal" id="admin-profile-modal" <?= $openProfileModal ? '' : 'hidden' ?>>

        <button type="button" class="admin-profile-backdrop" data-close-profile-modal
            aria-label="Close administrator profile">
        </button>

        <section class="admin-profile-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-profile-title"
            aria-describedby="admin-profile-description">

            <header class="admin-profile-header">

                <div class="admin-profile-heading">

                    <span class="admin-profile-heading-icon">
                        <i data-lucide="user-round-pen"></i>
                    </span>

                    <div>

                        <h2 id="admin-profile-title">
                            My Profile
                        </h2>

                        <p id="admin-profile-description">
                            View your account information and update your personal details.
                        </p>

                    </div>

                </div>

                <button type="button" class="icon-btn admin-profile-close" data-close-profile-modal
                    aria-label="Close profile modal">

                    <i data-lucide="x"></i>

                </button>

            </header>

            <!-- PROFILE PICTURE -->

            <form id="admin-picture-form" class="admin-profile-picture-form" action="update_profile_picture.php"
                method="POST" enctype="multipart/form-data">

                <input type="hidden" name="csrf_token" value="<?= adminEscape($csrfToken) ?>">

                <input type="hidden" name="return_page" value="<?= adminEscape($currentPage) ?>">

                <div class="admin-picture-editor">

                    <div class="admin-picture-preview" id="admin-picture-preview">

                        <?php if ($adminProfilePictureUrl !== ''): ?>

                            <img src="<?= adminEscape($adminProfilePictureUrl) ?>"
                                alt="Administrator profile picture preview">

                        <?php else: ?>

                            <span><?= adminEscape($adminInitial) ?></span>

                        <?php endif; ?>

                    </div>

                    <div class="admin-picture-details">

                        <strong>Profile Picture</strong>

                        <p>
                            Upload a new picture. Supported formats: JPG, PNG, and WEBP.
                            Maximum size: 2 MB.
                        </p>

                        <div class="admin-picture-actions">

                            <label for="admin-profile-picture-input" class="button button-outline">

                                <i data-lucide="image-plus"></i>

                                Choose Picture

                            </label>

                            <input type="file" id="admin-profile-picture-input" name="profile_picture"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                class="admin-picture-file-input">

                            <button type="submit" class="button button-primary" id="admin-upload-profile-picture"
                                disabled>

                                <i data-lucide="upload"></i>

                                Upload Picture

                            </button>

                        </div>

                        <small id="admin-profile-picture-filename">
                            No new picture selected.
                        </small>

                    </div>

                </div>

                <?php if ($adminProfilePictureError !== ''): ?>

                    <div class="admin-alert admin-alert-error admin-picture-error" role="alert">

                        <i data-lucide="alert-circle"></i>

                        <span>
                            <?= adminEscape($adminProfilePictureError) ?>
                        </span>

                    </div>

                <?php endif; ?>

            </form>

            <!-- PERSONAL INFORMATION -->

            <form id="admin-profile-form" class="admin-profile-form"
                action="dashboard.php?page=<?= adminEscape($currentPage) ?>" method="POST">

                <input type="hidden" name="csrf_token" value="<?= adminEscape($csrfToken) ?>">

                <input type="hidden" name="action" value="save_admin_profile">

                <?php if ($profileErrorMessage !== ''): ?>

                    <div class="admin-alert admin-alert-error" role="alert">

                        <i data-lucide="alert-circle"></i>

                        <span>
                            <?= adminEscape($profileErrorMessage) ?>
                        </span>

                    </div>

                <?php endif; ?>

                <div class="admin-profile-section-title">

                    <i data-lucide="user-round"></i>

                    <span>Personal Information</span>

                </div>

                <div class="admin-profile-grid">

                    <div class="admin-profile-field">

                        <label for="admin-profile-firstname">
                            Firstname <span class="required-mark">*</span>
                        </label>

                        <input id="admin-profile-firstname" name="firstname" type="text" maxlength="100"
                            autocomplete="given-name" value="<?= adminEscape($profileFormValues['firstname'] ?? '') ?>"
                            placeholder="Enter your firstname" required>

                    </div>

                    <div class="admin-profile-field">

                        <label for="admin-profile-middlename">
                            Middlename
                        </label>

                        <input id="admin-profile-middlename" name="middlename" type="text" maxlength="100"
                            autocomplete="additional-name"
                            value="<?= adminEscape($profileFormValues['middlename'] ?? '') ?>"
                            placeholder="Enter your middlename">

                    </div>

                    <div class="admin-profile-field">

                        <label for="admin-profile-lastname">
                            Lastname <span class="required-mark">*</span>
                        </label>

                        <input id="admin-profile-lastname" name="lastname" type="text" maxlength="100"
                            autocomplete="family-name" value="<?= adminEscape($profileFormValues['lastname'] ?? '') ?>"
                            placeholder="Enter your lastname" required>

                    </div>

                    <div class="admin-profile-field">

                        <label for="admin-profile-age">
                            Age
                        </label>

                        <input id="admin-profile-age" name="age" type="number" min="1" max="120" step="1"
                            value="<?= adminEscape($profileFormValues['age'] ?? '') ?>" placeholder="Enter your age">

                    </div>

                    <div class="admin-profile-field">

                        <label for="admin-profile-gender">
                            Gender
                        </label>

                        <select id="admin-profile-gender" name="gender">

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

                    <div class="admin-profile-field">

                        <label for="admin-profile-mobile">
                            Mobile Number
                        </label>

                        <input id="admin-profile-mobile" name="mobile_number" type="tel" maxlength="20"
                            autocomplete="tel" value="<?= adminEscape($profileFormValues['mobile_number'] ?? '') ?>"
                            placeholder="e.g. 09171234567">

                    </div>

                    <div class="admin-profile-field admin-profile-full">

                        <label for="admin-profile-address">
                            Address
                        </label>

                        <textarea id="admin-profile-address" name="address" rows="3" maxlength="2000"
                            autocomplete="street-address"
                            placeholder="Enter your complete address"><?= adminEscape($profileFormValues['address'] ?? '') ?></textarea>

                    </div>

                </div>

                <!-- ACCOUNT INFORMATION -->

                <div class="admin-profile-section-title admin-profile-account-title">

                    <i data-lucide="shield-check"></i>

                    <span>Account Information</span>

                </div>

                <div class="admin-profile-grid">

                    <div class="admin-profile-field admin-profile-full">

                        <label for="admin-profile-email">
                            Registered Email
                        </label>

                        <input id="admin-profile-email" type="email"
                            value="<?= adminEscape($adminProfileDefaults['email']) ?>" readonly>

                        <small>
                            Your registered email address is managed by the system administrator.
                        </small>

                    </div>

                    <div class="admin-profile-field admin-profile-full">

                        <label for="admin-profile-role">
                            Account Role
                        </label>

                        <input id="admin-profile-role" type="text" value="Administrator" readonly>

                    </div>

                </div>

                <!-- PROFILE ACTIONS -->

                <footer class="admin-profile-actions">

                    <button type="button" class="button button-outline" data-close-profile-modal>
                        Cancel
                    </button>

                    <button type="submit" class="button button-primary" id="admin-profile-save">

                        <i data-lucide="save"></i>

                        Save Changes

                    </button>

                </footer>

            </form>

        </section>

    </div>

    <!-- =====================================================
         ADMIN ACCOUNT SETTINGS — CHANGE PASSWORD
    ====================================================== -->

    <div class="admin-profile-modal admin-password-modal" id="admin-password-modal" <?= $openPasswordModal ? '' : 'hidden' ?>>

        <button type="button" class="admin-profile-backdrop" data-close-password-modal
            aria-label="Close account settings">
        </button>

        <section class="admin-profile-dialog admin-password-dialog" role="dialog" aria-modal="true"
            aria-labelledby="admin-password-title" aria-describedby="admin-password-description">

            <header class="admin-profile-header">

                <div class="admin-profile-heading">

                    <span class="admin-profile-heading-icon">
                        <i data-lucide="shield-check"></i>
                    </span>

                    <div>

                        <h2 id="admin-password-title">
                            Account Settings
                        </h2>

                        <p id="admin-password-description">
                            Update your account password to keep your administrator account secure.
                        </p>

                    </div>

                </div>

                <button type="button" class="icon-btn admin-profile-close" data-close-password-modal
                    aria-label="Close account settings">

                    <i data-lucide="x"></i>

                </button>

            </header>

            <form id="admin-password-form" class="admin-profile-form admin-password-form"
                action="dashboard.php?page=<?= adminEscape($currentPage) ?>" method="POST" autocomplete="off">

                <input type="hidden" name="csrf_token" value="<?= adminEscape($csrfToken) ?>">

                <input type="hidden" name="action" value="change_admin_password">

                <?php if ($passwordErrorMessage !== ''): ?>

                    <div class="admin-alert admin-alert-error" role="alert">

                        <i data-lucide="alert-circle"></i>

                        <span>
                            <?= adminEscape($passwordErrorMessage) ?>
                        </span>

                    </div>

                <?php endif; ?>

                <div class="admin-profile-section-title">

                    <i data-lucide="lock-keyhole"></i>

                    <span>Password Information</span>

                </div>

                <div class="admin-profile-grid">

                    <div class="admin-profile-field admin-profile-full">

                        <label for="admin-current-password">

                            Current Password

                            <span class="required-mark">*</span>

                        </label>

                        <div class="admin-password-input-wrap">

                            <input type="password" id="admin-current-password" name="current_password"
                                placeholder="Enter your current password" autocomplete="current-password"
                                maxlength="255" required>

                            <button type="button" class="admin-password-toggle"
                                data-toggle-password="admin-current-password" aria-label="Show current password"
                                aria-pressed="false">

                                <i data-lucide="eye"></i>

                            </button>

                        </div>

                    </div>

                    <div class="admin-profile-field admin-profile-full">

                        <label for="admin-new-password">

                            New Password

                            <span class="required-mark">*</span>

                        </label>

                        <div class="admin-password-input-wrap">

                            <input type="password" id="admin-new-password" name="new_password"
                                placeholder="Enter your new password" autocomplete="new-password" minlength="8"
                                maxlength="72" required>

                            <button type="button" class="admin-password-toggle"
                                data-toggle-password="admin-new-password" aria-label="Show new password"
                                aria-pressed="false">

                                <i data-lucide="eye"></i>

                            </button>

                        </div>

                        <small>
                            Your new password must contain at least 8 characters
                            and must not exceed 72 bytes.
                        </small>

                    </div>

                    <div class="admin-profile-field admin-profile-full">

                        <label for="admin-confirm-password">

                            Confirm New Password

                            <span class="required-mark">*</span>

                        </label>

                        <div class="admin-password-input-wrap">

                            <input type="password" id="admin-confirm-password" name="confirm_password"
                                placeholder="Re-enter your new password" autocomplete="new-password" minlength="8"
                                maxlength="72" required>

                            <button type="button" class="admin-password-toggle"
                                data-toggle-password="admin-confirm-password" aria-label="Show confirmation password"
                                aria-pressed="false">

                                <i data-lucide="eye"></i>

                            </button>

                        </div>

                        <small id="admin-password-confirmation-help">
                            Enter the same password as above.
                        </small>

                    </div>

                </div>

                <footer class="admin-profile-actions">

                    <button type="button" class="button button-outline" data-close-password-modal>
                        Cancel
                    </button>

                    <button type="submit" class="button button-primary" id="admin-password-save">

                        <i data-lucide="save"></i>

                        Update Password

                    </button>

                </footer>

            </form>

        </section>

    </div>

    <!-- =====================================================
     RESET DATA PASSWORD VERIFICATION MODAL
====================================================== -->

    <div class="admin-profile-modal admin-reset-modal admin-reset-password-modal" id="admin-reset-password-modal"
        hidden>

        <button type="button" class="admin-profile-backdrop" data-close-reset-password-modal
            aria-label="Close password verification">
        </button>

        <section class="admin-profile-dialog admin-reset-password-dialog" role="dialog" aria-modal="true"
            aria-labelledby="reset-password-title">

            <header class="admin-profile-header">

                <div class="admin-profile-heading">

                    <span class="admin-profile-heading-icon">
                        <i data-lucide="shield-check"></i>
                    </span>

                    <div>

                        <h2 id="reset-password-title">
                            Confirm Administrator Password
                        </h2>

                        <p>
                            For security purposes, enter your current
                            administrator password before proceeding.
                        </p>

                    </div>

                </div>

                <button type="button" class="icon-btn admin-profile-close" data-close-reset-password-modal
                    aria-label="Close modal">

                    <i data-lucide="x"></i>

                </button>

            </header>

            <form id="admin-reset-password-form" class="admin-reset-content" action="reset_data.php" method="POST"
                autocomplete="off">

                <input type="hidden" name="csrf_token" value="<?= adminEscape($csrfToken) ?>">

                <input type="hidden" name="return_page" value="<?= adminEscape($currentPage) ?>">

                <input type="hidden" name="action" id="admin-reset-action" value="">

                <div class="admin-profile-field">

                    <label for="admin-reset-current-password">
                        Current Administrator Password
                    </label>

                    <div class="admin-password-input-wrap">

                        <input type="password" id="admin-reset-current-password" name="current_password"
                            placeholder="Enter your current password" autocomplete="current-password" required>

                    </div>

                    <small>
                        Your password will be verified securely before
                        any student records or uploaded documents are changed.
                    </small>

                </div>

                <footer class="admin-profile-actions admin-reset-footer">

                    <button type="button" class="button button-outline" data-cancel-reset-password>
                        Cancel
                    </button>

                    <button type="submit" class="button button-primary" id="admin-reset-submit">

                        <i data-lucide="shield-check"></i>

                        Verify and Continue

                    </button>

                </footer>

            </form>

        </section>

    </div>

    <!-- =====================================================
     ADMIN RESET DATA MODAL
====================================================== -->

    <div class="admin-profile-modal admin-reset-modal" id="admin-reset-modal" hidden>

        <button type="button" class="admin-profile-backdrop" data-close-reset-data-modal aria-label="Close reset data">
        </button>

        <section class="admin-profile-dialog admin-reset-dialog" role="dialog" aria-modal="true"
            aria-labelledby="admin-reset-title" aria-describedby="admin-reset-description">

            <!-- MODAL HEADER -->

            <header class="admin-profile-header">

                <div class="admin-profile-heading">

                    <span class="admin-profile-heading-icon admin-reset-heading-icon">
                        <i data-lucide="database"></i>
                    </span>

                    <div>

                        <h2 id="admin-reset-title">
                            Reset Data
                        </h2>

                        <p id="admin-reset-description">
                            Choose which student records you want to reset.
                            Each action affects a separate part of AcadTrack.
                        </p>

                    </div>

                </div>

                <button type="button" class="icon-btn admin-profile-close" data-close-reset-data-modal
                    aria-label="Close reset data">

                    <i data-lucide="x"></i>

                </button>

            </header>

            <!-- RESET OPTIONS -->

            <div class="admin-reset-content">

                <!-- RESET PERMIT -->

                <article class="admin-reset-option admin-reset-option-danger">

                    <span class="admin-reset-option-icon">
                        <i data-lucide="file-x"></i>
                    </span>

                    <div class="admin-reset-option-details">

                        <h3>
                            Reset Permit
                        </h3>

                        <p>
                            Permanently removes all uploaded permit documents and deletes
                            their corresponding records in
                            <code>database</code>.
                            Every existing permit period will return to
                            <strong>No Permit</strong>.
                        </p>

                        <small>
                            This does not delete student accounts,
                            academic placement, sections, or SSP Adviser assignments.
                        </small>

                    </div>

                    <form method="POST" action="reset_data.php" class="admin-reset-option-action" data-reset-form>

                        <input type="hidden" name="csrf_token" value="<?= adminEscape($csrfToken) ?>">

                        <input type="hidden" name="return_page" value="<?= adminEscape($currentPage) ?>">

                        <input type="hidden" name="action" value="reset_permits">

                        <div class="admin-reset-option-action">

                            <button type="button" class="admin-reset-action-button" data-open-reset-password
                                data-reset-action="reset_permits">

                                <i data-lucide="trash-2"></i>
                                <span>Reset Permit</span>

                            </button>

                        </div>

                    </form>

                </article>

                <!-- RESET STUDENT ACADEMIC STATUS -->

                <article class="admin-reset-option admin-reset-option-warning">

                    <span class="admin-reset-option-icon">
                        <i data-lucide="graduation-cap"></i>
                    </span>

                    <div class="admin-reset-option-details">

                        <h3>
                            Reset Student Academic Status
                        </h3>

                        <p>
                            Removes each student's official section assignment
                            and clears only
                            <strong>Course, Year Level, and Assigned Section</strong>
                            from their academic information.
                        </p>

                        <small>
                            Student login accounts, student numbers,
                            names, contact details, permits, section records,
                            and SSP Adviser assignments will remain unchanged.
                        </small>

                    </div>

                    <form method="POST" action="reset_data.php" class="admin-reset-option-action" data-reset-form>

                        <input type="hidden" name="csrf_token" value="<?= adminEscape($csrfToken) ?>">

                        <input type="hidden" name="return_page" value="<?= adminEscape($currentPage) ?>">

                        <input type="hidden" name="action" value="reset_student_academic_status">

                        <div class="admin-reset-option-action">

                            <button type="button" class="admin-reset-action-button admin-reset-academic-button"
                                data-open-reset-password data-reset-action="reset_student_academic_status">

                                <i data-lucide="rotate-ccw"></i>
                                <span>Reset Academic Status</span>

                            </button>

                        </div>

                    </form>

                </article>

                <!-- WARNING -->

                <div class="admin-reset-note">

                    <i data-lucide="alert-triangle"></i>

                    <p>
                        These actions cannot be undone.
                        Review the description carefully before proceeding.
                    </p>

                </div>

                <!-- FOOTER -->

                <footer class="admin-profile-actions admin-reset-footer">

                    <button type="button" class="button button-outline" data-close-reset-data-modal>

                        Close

                    </button>

                </footer>

            </div>

        </section>

    </div>

    <!-- PROFILE AND PASSWORD NOTIFICATIONS -->

    <div id="admin-profile-notice" data-message="<?= adminEscape($adminProfileNotice) ?>" hidden>
    </div>

    <div id="admin-password-notice" data-message="<?= adminEscape($adminPasswordNotice) ?>" hidden>
    </div>

    <!-- GLOBAL SEARCH -->

    <div class="search-overlay" id="search-overlay" hidden>

        <button class="search-scrim" type="button" aria-label="Close search" data-close-search>
        </button>

        <section class="search-dialog" role="dialog" aria-modal="true" aria-labelledby="search-title">

            <div class="search-input-row">

                <i data-lucide="search"></i>

                <input id="search-input" type="search" placeholder="Search dashboard..." autocomplete="off">

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

                <button type="button" data-search-target="Section & Student Management">

                    <i data-lucide="users-round"></i>

                    <span>Section &amp; Student Management</span>

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

</body>

</html>