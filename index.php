<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SHARED SESSION CONFIGURATION
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/auth/session.php';

/*
|--------------------------------------------------------------------------
| SECURITY HEADERS
|--------------------------------------------------------------------------
*/

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

/*
|--------------------------------------------------------------------------
| REDIRECT ALREADY AUTHENTICATED USERS
|--------------------------------------------------------------------------
*/

$dashboardPaths = [
    'student' => 'views/student/dashboard.php',
    'faculty' => 'views/faculty/dashboard.php',
    'admin' => 'views/admin/dashboard.php',
];

$sessionUserId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$sessionRole = $_SESSION['role'] ?? null;

if (
    $sessionUserId !== false &&
    is_string($sessionRole) &&
    isset($dashboardPaths[$sessionRole])
) {

    $scriptPath = parse_url(
        (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
        PHP_URL_PATH
    );

    $scriptPath = is_string($scriptPath)
        ? $scriptPath
        : '/index.php';

    $basePath = str_replace(
        '\\',
        '/',
        dirname($scriptPath)
    );

    if ($basePath === '.' || $basePath === '/') {
        $basePath = '';
    } else {
        $basePath = rtrim($basePath, '/');
    }

    header(
        'Location: ' . $basePath . '/' . $dashboardPaths[$sessionRole],
        true,
        303
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| LOGIN AND REGISTRATION CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['login_csrf_token']) ||
    !is_string($_SESSION['login_csrf_token'])
) {
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image" href="assets/bg_logo.png">
    <title>AcadTrack - CITE</title>

    <link rel="stylesheet" href="style/index.css">
</head>

<body>
    <main class="cl-login10-demo">
        <section class="cl-login10" aria-label="Loop account">
            <header class="cl-login10__head">
                <span class="cl-login10__logo" aria-hidden="true">
                    <img src="assets/bg_logo.png" style="width: 40px; height: 32px;">
                </span>
                <p class="cl-login10__brand">AcadTrack</p>
                <p class="cl-login10__tagline">College of Information Technology Educationnn</p>
            </header>

            <div class="cl-login10__tabs" role="tablist" aria-label="Log in or create an account" data-tablist>
                <span class="cl-login10__thumb" aria-hidden="true"></span>
                <button class="cl-login10__tab" type="button" role="tab" id="cl-login10-tab-login"
                    aria-controls="cl-login10-panel-login" aria-selected="true">Log in</button>
                <button class="cl-login10__tab" type="button" role="tab" id="cl-login10-tab-signup"
                    aria-controls="cl-login10-panel-signup" aria-selected="false" tabindex="-1">Create account</button>
            </div>

            <div class="cl-login10__panels">
                <div class="cl-login10__panel" role="tabpanel" id="cl-login10-panel-login"
                    aria-labelledby="cl-login10-tab-login" tabindex="0">
                    <div id="registration-message" class="login-error" role="status" aria-live="polite" hidden></div>
                    <form class="cl-login10__form" data-form="login" action="model/login_process.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
                            $_SESSION['login_csrf_token'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>">
                        <?php if (!empty($error)): ?>
                            <div class="error-message" role="alert">
                                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                        <div class="cl-login10__field" data-field>
                            <div id="login-error" class="login-error" role="alert" aria-live="polite" hidden>
                                Incorrect student number or password. Please try again.
                            </div>
                            <label class="cl-login10__label" for="cl-login10-l-student-number">
                                Student Number
                            </label>

                            <input class="cl-login10__input" id="cl-login10-l-student-number" name="student_number"
                                type="text" inputmode="numeric" autocomplete="username" maxlength="12"
                                pattern="[0-9]{12}" required data-msg="Enter your 12-digit student number."
                                data-pattern="Student number must contain exactly 12 digits."
                                aria-describedby="cl-login10-l-student-number-err"
                                placeholder="12 digit Student Number. (e.g. 202612345678)">

                            <p class="cl-login10__error" id="cl-login10-l-student-number-err" data-error></p>
                        </div>

                        <div class="cl-login10__field" data-field>
                            <div class="cl-login10__row">
                                <label class="cl-login10__label" for="cl-login10-l-pw">Password</label>
                                <a class="cl-login10__link" href="forgot_password.php">
                                    Forgot password?
                                </a>
                            </div>
                            <div class="cl-login10__pw">
                                <input class="cl-login10__input" id="cl-login10-l-pw" name="password" type="password"
                                    autocomplete="current-password" required minlength="8"
                                    data-msg="Enter your password." aria-describedby="cl-login10-l-pw-err">
                                <button class="cl-login10__eye" type="button" aria-label="Show password"
                                    aria-pressed="false" aria-controls="cl-login10-l-pw" data-pw-toggle>
                                    <svg class="cl-login10__eye-on" viewBox="0 0 24 24" width="20" height="20"
                                        fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                        stroke-linejoin="round" aria-hidden="true">
                                        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                    <svg class="cl-login10__eye-off" viewBox="0 0 24 24" width="20" height="20"
                                        fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                        stroke-linejoin="round" aria-hidden="true">
                                        <path
                                            d="M3 3l18 18M10.6 5.1A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6C3.9 8.3 2 12 2 12s3.6 7 10 7a9.8 9.8 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2" />
                                    </svg>
                                </button>
                            </div>
                            <p class="cl-login10__error" id="cl-login10-l-pw-err" data-error></p>
                        </div>
                        <button class="cl-login10__submit" type="submit" data-login-submit>
                            <span data-login-label>Log in</span>
                            <span class="cl-login10__spinner" aria-hidden="true" hidden></span>
                        </button>
                    </form>
                </div>

                <div class="cl-login10__panel" role="tabpanel" id="cl-login10-panel-signup"
                    aria-labelledby="cl-login10-tab-signup" tabindex="0" hidden>
                    <div id="signup-feedback" class="login-error" role="alert" aria-live="polite" hidden></div>
                    <form class="cl-login10__form" data-form="signup" action="model/register_student.php" method="POST"
                        novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
                            $_SESSION['login_csrf_token'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>">
                        <div class="cl-login10__field" data-field>
                            <label class="cl-login10__label" for="cl-login10-s-student-number">
                                Student Number
                            </label>

                            <input class="cl-login10__input" id="cl-login10-s-student-number" name="student_number"
                                type="text" inputmode="numeric" autocomplete="off" maxlength="12" pattern="[0-9]{12}"
                                required data-msg="Enter your 12-digit student number."
                                data-pattern="Student number must contain exactly 12 digits."
                                aria-describedby="cl-login10-s-student-number-err"
                                placeholder="12 digit Student Number. (e.g. 202612345678)">

                            <p class="cl-login10__error" id="cl-login10-s-student-number-err" data-error></p>
                        </div>

                        <div class="cl-login10__field" data-field>
                            <label class="cl-login10__label" for="cl-login10-s-name">Nickname</label>
                            <input class="cl-login10__input" id="cl-login10-s-name" name="name" type="text"
                                autocomplete="name" required minlength="2" data-msg="Tell us what to call you."
                                aria-describedby="cl-login10-s-name-err">
                            <p class="cl-login10__error" id="cl-login10-s-name-err" data-error></p>
                        </div>
                        <div class="cl-login10__field" data-field>
                            <label class="cl-login10__label" for="cl-login10-s-email">Email</label>
                            <input class="cl-login10__input" id="cl-login10-s-email" name="email" type="email"
                                autocomplete="email" required data-msg="Enter your email."
                                aria-describedby="cl-login10-s-email-err" placeholder="Enter your PHINMA Email">
                            <p class="cl-login10__error" id="cl-login10-s-email-err" data-error></p>
                        </div>
                        <!-- Create a password -->
                        <div class="cl-login10__field" data-field>
                            <label class="cl-login10__label" for="cl-login10-s-pw">
                                Create a password
                            </label>

                            <div class="cl-login10__pw">
                                <input class="cl-login10__input" id="cl-login10-s-pw" name="password" type="password"
                                    autocomplete="new-password" required minlength="8" pattern="(?=.*\d).{8,}"
                                    data-msg="Create a password."
                                    data-pattern="Use at least 8 characters and include at least one number."
                                    aria-describedby="cl-login10-s-pw-hint cl-login10-s-pw-err">

                                <button class="cl-login10__eye" type="button" aria-label="Show password"
                                    aria-pressed="false" aria-controls="cl-login10-s-pw" data-pw-toggle>
                                    <svg class="cl-login10__eye-on" viewBox="0 0 24 24" width="20" height="20"
                                        fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                        stroke-linejoin="round" aria-hidden="true">
                                        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                    <svg class="cl-login10__eye-off" viewBox="0 0 24 24" width="20" height="20"
                                        fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                        stroke-linejoin="round" aria-hidden="true">
                                        <path
                                            d="M3 3l18 18M10.6 5.1A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6C3.9 8.3 2 12 2 12s3.6 7 10 7a9.8 9.8 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2" />
                                    </svg>
                                </button>
                            </div>

                            <p class="cl-login10__hint" id="cl-login10-s-pw-hint">
                                At least 8 characters, including a number.
                            </p>
                            <p class="cl-login10__error" id="cl-login10-s-pw-err" data-error></p>
                        </div>

                        <!-- Confirm password -->
                        <div class="cl-login10__field" data-field>
                            <label class="cl-login10__label" for="cl-login10-s-confirm-pw">
                                Confirm password
                            </label>

                            <div class="cl-login10__pw">
                                <input class="cl-login10__input" id="cl-login10-s-confirm-pw" name="confirm_password"
                                    type="password" autocomplete="new-password" required
                                    data-msg="Please confirm your password." data-confirm-password="cl-login10-s-pw"
                                    aria-describedby="cl-login10-s-confirm-pw-err">

                                <button class="cl-login10__eye" type="button" aria-label="Show password"
                                    aria-pressed="false" aria-controls="cl-login10-s-confirm-pw" data-pw-toggle>
                                    <svg class="cl-login10__eye-on" viewBox="0 0 24 24" width="20" height="20"
                                        fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                        stroke-linejoin="round" aria-hidden="true">
                                        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                    <svg class="cl-login10__eye-off" viewBox="0 0 24 24" width="20" height="20"
                                        fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                        stroke-linejoin="round" aria-hidden="true">
                                        <path
                                            d="M3 3l18 18M10.6 5.1A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6C3.9 8.3 2 12 2 12s3.6 7 10 7a9.8 9.8 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2" />
                                    </svg>
                                </button>
                            </div>

                            <p class="cl-login10__error" id="cl-login10-s-confirm-pw-err" data-error></p>
                        </div>
                        <div class="cl-login10__field" data-field>


                            <div class="cl-login10__field" data-field>
                                <label class="cl-login10__check">
                                    <input type="checkbox" name="terms" value="1" required
                                        data-msg="Please accept the Terms and acknowledge the Privacy Policy."
                                        aria-describedby="cl-login10-s-terms-err">

                                    <span>
                                        I agree to the
                                        <a class="cl-login10__link" href="#" data-open-modal="terms-modal">Terms &
                                            Privacy Policy</a>
                                    </span>
                                </label>

                                <p class="cl-login10__error" id="cl-login10-s-terms-err" data-error></p>
                            </div>
                        </div>
                        <button class="cl-login10__submit" type="submit" data-register-submit>
                            <span data-register-label>Create account</span>
                            <span class="cl-login10__spinner" aria-hidden="true" hidden></span>
                        </button>
                    </form>
                </div>

                <div class="cl-login10__done" data-success tabindex="-1" hidden>
                    <span class="cl-login10__streak" aria-hidden="true">
                        <span></span><span></span><span></span><span></span><span></span><span></span><span></span>
                    </span>
                    <h2 class="cl-login10__title" data-done-title></h2>
                    <p class="cl-login10__sub" data-done-text></p>
                    <button class="cl-login10__swap-btn" type="button" data-reset>Back to start</button>
                </div>
            </div>

            <div class="cl-login10__vh" role="status" aria-live="polite" data-live></div>
            <p class="cl-login10__demo">Saint Jude College PHINMA Education</p>
        </section>

        <!-- Terms and Conditions & Privacy Policy Modal -->
        <div class="policy-modal" id="terms-modal" role="dialog" aria-modal="true" aria-labelledby="terms-modal-title"
            hidden>
            <div class="policy-modal__backdrop" data-close-modal></div>

            <div class="policy-modal__content" tabindex="-1">
                <button type="button" class="policy-modal__close" data-close-modal aria-label="Close Terms of Service">
                    &times;
                </button>

                <p class="policy-modal__brand">AcadTrack</p>
                <h2 id="terms-modal-title">Terms & Privacy Policy</h2>

                <div class="policy-modal__body">
                    <p>
                        By accessing and using AcadTrack – Student Permit Monitoring and
                        Academic Tracking System of Saint Jude College – PHINMA Education,
                        you agree to the following terms and privacy practices.
                    </p>

                    <h3>Acceptance of Terms</h3>
                    <p>
                        AcadTrack is intended exclusively for authorized students, faculty
                        members, and administrators. By using the system, you agree to comply
                        with its policies and guidelines.
                    </p>

                    <h3>Account Security</h3>
                    <p>
                        Users are responsible for keeping their login credentials confidential.
                        Sharing accounts or allowing unauthorized access is prohibited.
                    </p>

                    <h3>Accurate Information</h3>
                    <p>
                        Users must provide accurate and updated personal and academic
                        information. Students are responsible for ensuring that submitted
                        permit documents are valid and authentic.
                    </p>

                    <h3>Proper System Usage</h3>
                    <p>
                        Users must not misuse, manipulate, damage, or attempt to gain
                        unauthorized access to the system, its records, or other users'
                        information.
                    </p>

                    <h3>Permit Applications and Approval</h3>
                    <p>
                        Submitted permit documents are subject to verification and approval
                        by the assigned SSP Adviser. Submission does not guarantee approval.
                        Students are responsible for monitoring their application status.
                    </p>

                    <h3>Collection of Personal Information</h3>
                    <p>
                        AcadTrack may collect personal information, including names, student
                        numbers, email addresses, contact details, academic records, and
                        submitted documents.
                    </p>

                    <h3>Purpose of Data Collection</h3>
                    <p>
                        Collected information is used for account management, student
                        verification, permit monitoring, academic tracking, document
                        processing, and other legitimate academic and administrative purposes.
                    </p>

                    <h3>Data Privacy and Protection</h3>
                    <p>
                        Personal information is protected through reasonable organizational,
                        physical, and technical security measures. Access is limited to
                        authorized personnel who require the information for their official
                        responsibilities.
                    </p>

                    <h3>Data Sharing and Retention</h3>
                    <p>
                        Personal information will not be disclosed to unauthorized individuals.
                        Records are retained only as necessary for legitimate institutional
                        purposes and applicable legal requirements.
                    </p>

                    <h3>User Rights</h3>
                    <p>
                        Users may exercise their rights under the Data Privacy Act of 2012
                        (Republic Act No. 10173), including the right to access and correct
                        their personal information, subject to applicable laws and limitations.
                    </p>

                    <h3>System Availability and Violations</h3>
                    <p>
                        AcadTrack may undergo maintenance or temporary interruptions.
                        Unauthorized access, fraudulent submissions, and misuse of the
                        system may result in appropriate administrative action.
                    </p>

                    <h3>Agreement</h3>
                    <p>
                        By using AcadTrack, you confirm that you have read, understood, and
                        agreed to these Terms & Privacy Policy.
                    </p>
                    <br>
                    <p>
                        For any concerns, contact CITE Department located at 2nd Floor Dimasalang Hall..
                    </p>
                </div>
            </div>
        </div>
    </main>
    <script src="js/snippet.js"></script>
</body>

</html>