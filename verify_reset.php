<?php
session_start();

require_once __DIR__ . '/config/database.php';

$recoveryUserId = (int) ($_SESSION['recovery_user_id'] ?? 0);

$error = '';
$message = '';
$cooldownRemaining = 0;

if ($recoveryUserId < 1) {
    header('Location: forgot_password.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| ERROR AND SUCCESS MESSAGES
|--------------------------------------------------------------------------
*/

$errorMessages = [
    'invalid' => 'Enter the valid 6-digit code.',
    'incorrect' => 'The code is incorrect. Please try again.',
    'expired' => 'Your verification code has expired. Request a new code.',
    'attempts' => 'Too many incorrect attempts. Request a new code.',
    'wait' => 'Please wait until the 60-second cooldown ends before requesting another code.',
    'resend_failed' => 'We could not resend your verification code. Please try again.',
    'not_found' => 'Your reset request was not found. Please start the password recovery process again.',
    'already_verified' => 'Your verification code has already been verified. Continue resetting your password.'
];

$error = $errorMessages[$_GET['error'] ?? ''] ?? '';

if (isset($_GET['resent']) && $_GET['resent'] === '1') {
    $message = 'A new verification code has been sent to your registered email address.';
}


/*
|--------------------------------------------------------------------------
| CSRF TOKEN FOR RESENDING OTP
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['password_reset_resend_csrf'])) {
    $_SESSION['password_reset_resend_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['password_reset_resend_csrf'];


/*
|--------------------------------------------------------------------------
| PHASE 1: VERIFY OTP
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['verify_otp'])
) {

    $otp = trim($_POST['otp'] ?? '');

    if (!preg_match('/^\d{6}$/', $otp)) {

        $error = 'Enter the valid 6-digit code.';

    } else {

        $stmt = $pdo->prepare(
            'SELECT r.id,
                    r.user_id,
                    r.otp_hash,
                    r.attempts,
                    r.expires_at,
                    r.verified_at
             FROM password_reset_otps r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.user_id = :user_id
               AND u.is_active = 1
             LIMIT 1'
        );

        $stmt->execute([
            'user_id' => $recoveryUserId
        ]);

        $reset = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$reset ||
            $reset['verified_at'] !== null ||
            strtotime($reset['expires_at']) <= time() ||
            (int) $reset['attempts'] >= 5
        ) {

            $error = 'The code is invalid or expired. Request a new code.';

        } else {

            // Count every syntactically valid OTP attempt.
            $stmt = $pdo->prepare(
                'UPDATE password_reset_otps
                 SET attempts = attempts + 1
                 WHERE id = :id
                   AND attempts < 5'
            );

            $stmt->execute([
                'id' => $reset['id']
            ]);

            if (
                $stmt->rowCount() === 1 &&
                password_verify($otp, $reset['otp_hash'])
            ) {

                // Atomically claim the valid OTP.
                $stmt = $pdo->prepare(
                    'UPDATE password_reset_otps
                     SET verified_at = NOW()
                     WHERE id = :id
                       AND verified_at IS NULL
                       AND expires_at > NOW()
                       AND attempts <= 5'
                );

                $stmt->execute([
                    'id' => $reset['id']
                ]);

                if ($stmt->rowCount() === 1) {

                    session_regenerate_id(true);

                    $_SESSION['password_reset_user_id'] =
                        (int) $reset['user_id'];

                    $_SESSION['password_reset_verified_at'] = time();

                    header('Location: verify_reset.php');
                    exit;
                }
            }

            $error = 'The code is incorrect or has expired.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| PHASE 2: VERIFY RECOVERY SESSION
|--------------------------------------------------------------------------
*/

$resetUserId = (int) (
    $_SESSION['password_reset_user_id'] ?? 0
);

$verifiedAt = (int) (
    $_SESSION['password_reset_verified_at'] ?? 0
);

if (
    $resetUserId &&
    (!$verifiedAt || time() - $verifiedAt > 600)
) {

    unset(
        $_SESSION['password_reset_user_id'],
        $_SESSION['password_reset_verified_at']
    );

    $resetUserId = 0;

    $error = 'Your verification session expired. Request a new code.';
}


/*
|--------------------------------------------------------------------------
| PHASE 3: UPDATE PASSWORD
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['reset_password'])
) {

    if (!$resetUserId) {

        $error = 'Verify your reset code before changing your password.';

    } else {

        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (
            strlen($password) < 8 ||
            !preg_match('/\d/', $password)
        ) {

            $error = 'Use at least 8 characters, including a number.';

        } elseif ($password !== $confirmPassword) {

            $error = 'The passwords do not match.';

        } else {

            try {

                $pdo->beginTransaction();

                // Lock and revalidate the verified recovery record.
                $stmt = $pdo->prepare(
                    'SELECT id
                     FROM password_reset_otps
                     WHERE user_id = :user_id
                       AND verified_at IS NOT NULL
                       AND expires_at > NOW()
                     FOR UPDATE'
                );

                $stmt->execute([
                    'user_id' => $resetUserId
                ]);

                $validReset = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$validReset) {

                    $pdo->rollBack();

                    $error = 'The reset code expired. Request a new one.';

                } else {

                    $passwordHash = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    if ($passwordHash === false) {
                        throw new RuntimeException(
                            'Unable to hash the new password.'
                        );
                    }

                    $stmt = $pdo->prepare(
                        'UPDATE users
                         SET password_hash = :password_hash
                         WHERE id = :user_id
                           AND is_active = 1'
                    );

                    $stmt->execute([
                        'password_hash' => $passwordHash,
                        'user_id' => $resetUserId
                    ]);

                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException(
                            'Password update failed.'
                        );
                    }

                    // A reset code must never be reused.
                    $stmt = $pdo->prepare(
                        'DELETE FROM password_reset_otps
                         WHERE user_id = :user_id'
                    );

                    $stmt->execute([
                        'user_id' => $resetUserId
                    ]);

                    $pdo->commit();

                    unset(
                        $_SESSION['recovery_student_number'],
                        $_SESSION['recovery_user_id'],
                        $_SESSION['password_reset_user_id'],
                        $_SESSION['password_reset_verified_at'],
                        $_SESSION['password_reset_resend_csrf']
                    );

                    header('Location: index.php?reset=1');
                    exit;
                }

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Password update error: ' . $e->getMessage()
                );

                $error = 'Unable to reset your password. Please try again.';
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| PHASE 4: GET CURRENT RESEND COOLDOWN
|--------------------------------------------------------------------------
*/

if (!$resetUserId) {

    try {

        $stmt = $pdo->prepare(
            'SELECT LEAST(
                        60,
                        GREATEST(
                            0,
                            60 - COALESCE(
                                TIMESTAMPDIFF(SECOND, created_at, NOW()),
                                60
                            )
                        )
                    ) AS remaining
             FROM password_reset_otps
             WHERE user_id = :user_id
               AND verified_at IS NULL
             LIMIT 1'
        );

        $stmt->execute([
            'user_id' => $recoveryUserId
        ]);

        $cooldown = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cooldown) {
            $cooldownRemaining = (int) $cooldown['remaining'];
        }

    } catch (Throwable $e) {

        error_log(
            'Password reset cooldown error: ' . $e->getMessage()
        );
    }
}
?>

<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Verify Reset Code | AcadTrack</title>

    <link rel="stylesheet" href="style/index.css">

</head>

<body>

    <main class="cl-login10-demo">

        <section class="cl-login10">

            <!-- BRAND HEADER -->

            <header class="cl-login10__head">

                <span class="cl-login10__logo">

                    <img src="assets/bg_logo.png" alt="AcadTrack logo" style="width:40px;height:32px;">

                </span>

                <p class="cl-login10__brand">
                    AcadTrack
                </p>

                <p class="cl-login10__tagline">
                    College of Information Technology Education
                </p>

            </header>


            <h2 class="cl-login10__title">
                Reset your password
            </h2>

            <p class="otp-description">
                Enter the six-digit code sent to the email address
                associated with your account, then create a new password.
            </p>

            <!-- ERROR MESSAGE -->

            <?php if ($error !== ''): ?>
                <div class="otp-error" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <!-- SUCCESS MESSAGE -->

            <?php if ($message !== ''): ?>
                <div class="otp-success" role="status">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>


            <?php if (!$resetUserId): ?>



                <!-- OTP VERIFICATION FORM -->

                <form class="cl-login10__form" id="verify-otp-form" method="POST" action="verify_reset.php">

                    <input type="hidden" name="verify_otp" value="1">

                    <div class="cl-login10__field">

                        <label class="cl-login10__label" for="otp">
                            Enter verification code
                        </label>

                        <input class="cl-login10__input otp-input" id="otp" name="otp" type="text" inputmode="numeric"
                            pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" placeholder="000000"
                            required>

                    </div>

                    <button class="cl-login10__submit" id="verify-otp-button" type="submit">

                        <span class="cl-login10__button-text">
                            Verify Code
                        </span>

                        <span class="otp-button-spinner" aria-hidden="true">
                        </span>

                    </button>

                </form>


                <!-- RESEND RESET CODE -->

                <div class="otp-resend-wrapper">

                    <p class="otp-resend-status" id="resend-status" data-remaining="<?= (int) $cooldownRemaining ?>"
                        aria-live="polite">
                    </p>

                    <form method="POST" action="model/resend_password_reset_code.php" id="resend-otp-form">

                        <input type="hidden" name="csrf_token"
                            value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                        <button class="otp-resend-button" id="resend-button" type="submit" <?= $cooldownRemaining > 0 ? 'disabled' : '' ?>>

                            <span class="otp-resend-text">
                                Resend Code
                            </span>

                            <span class="otp-resend-spinner" aria-hidden="true">
                            </span>

                        </button>

                    </form>

                </div>

            <?php else: ?>


                <!-- =================================================
                     NEW PASSWORD FORM
                ================================================== -->

                <form class="cl-login10__form" method="POST" action="verify_reset.php">

                    <div class="cl-login10__field">

                        <label class="cl-login10__label" for="password">

                            New Password

                        </label>

                        <input class="cl-login10__input" id="password" name="password" type="password" minlength="8"
                            autocomplete="new-password" required>

                        <p class="cl-login10__hint">
                            At least 8 characters, including a number.
                        </p>

                    </div>


                    <div class="cl-login10__field">

                        <label class="cl-login10__label" for="confirm_password">

                            Confirm New Password

                        </label>

                        <input class="cl-login10__input" id="confirm_password" name="confirm_password" type="password"
                            minlength="8" autocomplete="new-password" required>

                    </div>


                    <button class="cl-login10__submit" type="submit" name="reset_password" value="1">

                        Update Password

                    </button>

                </form>


            <?php endif; ?>


            <!-- BACK TO LOGIN -->

            <p class="cl-login10__swap">

                <a class="cl-login10__link otp-back" href="index.php">
                    Back to login
                </a>

            </p>

        </section>

    </main>


    <!-- OTP VERIFICATION AND RESEND LOADING STATES -->

    <script src="js/verify_reset.js?v=3" defer></script>

</body>

</html>