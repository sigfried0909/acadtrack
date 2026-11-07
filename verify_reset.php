<?php
session_start();

require_once __DIR__ . '/config/database.php';

$studentNumber = $_SESSION['recovery_student_number'] ?? '';
$error = '';
$message = '';

if ($studentNumber === '') {
    header('Location: forgot_password.php');
    exit;
}

// Phase 1: Verify the emailed OTP.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['verify_otp'])
) {

    $otp = trim($_POST['otp'] ?? '');

    if (!preg_match('/^\d{6}$/', $otp)) {
        $error = 'Enter the valid 6-digit code.';
    } else {

        $stmt = $pdo->prepare(
            'SELECT r.id, r.user_id, r.otp_hash,
            r.attempts, r.expires_at, r.verified_at
            FROM password_reset_otps r
            INNER JOIN users u ON u.id = r.user_id
            WHERE u.student_number = :student_number
            AND u.is_active = 1
            LIMIT 1'
        );

        $stmt->execute([
            'student_number' => $studentNumber
        ]);

        $reset = $stmt->fetch();


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
                 WHERE id = :id AND attempts < 5'
            );
            $stmt->execute(['id' => $reset['id']]);

            if (password_verify($otp, $reset['otp_hash'])) {
                // Recheck validity and claim the code atomically.
                $stmt = $pdo->prepare(
                    'UPDATE password_reset_otps
                     SET verified_at = NOW()
                     WHERE id = :id
                       AND verified_at IS NULL
                       AND expires_at > NOW()
                       AND attempts <= 5'
                );
                $stmt->execute(['id' => $reset['id']]);

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

// Phase 2: Set the new password after successful OTP verification.
$resetUserId = $_SESSION['password_reset_user_id'] ?? 0;
$verifiedAt = $_SESSION['password_reset_verified_at'] ?? 0;

if ($resetUserId && (!$verifiedAt || time() - $verifiedAt > 600)) {
    unset(
        $_SESSION['password_reset_user_id'],
        $_SESSION['password_reset_verified_at']
    );

    $resetUserId = 0;
    $error = 'Your verification session expired. Request a new code.';
}

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
                $stmt->execute(['user_id' => $resetUserId]);
                $validReset = $stmt->fetch();

                if (!$validReset) {
                    $pdo->rollBack();
                    $error = 'The reset code expired. Request a new one.';
                } else {
                    $passwordHash = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $stmt = $pdo->prepare(
                        'UPDATE users
                         SET password_hash = :password_hash
                         WHERE id = :user_id AND is_active = 1'
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
                    $stmt->execute(['user_id' => $resetUserId]);

                    $pdo->commit();

                    unset(
                        $_SESSION['recovery_student_number'],
                        $_SESSION['password_reset_user_id'],
                        $_SESSION['password_reset_verified_at']
                    );

                    header('Location: index.php?reset=1');
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('Password update error: ' . $e->getMessage());
                $error = 'Unable to reset your password. Please try again.';
            }
        }
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
            <header class="cl-login10__head">
                <span class="cl-login10__logo">
                    <img src="assets/bg_logo.png" alt="AcadTrack logo" style="width:40px;height:32px;">
                </span>
                <p class="cl-login10__brand">AcadTrack</p>
                <p class="cl-login10__tagline">
                    College of Information Technology Education
                </p>
            </header>

            <h2 class="cl-login10__title">Reset your password</h2>

            <p>
                Enter the six-digit code sent to the email address associated
                with your student number, then create a new password.
            </p>

            <?php if ($error !== ''): ?>
                <div class="login-error" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if (!$resetUserId): ?>
                <form class="cl-login10__form" method="POST">
                    <div class="cl-login10__field">
                        <label class="cl-login10__label" for="otp">
                            Enter code
                        </label>
                        <input class="cl-login10__input" id="otp" name="otp" type="text" inputmode="numeric"
                            pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                    </div>

                    <button class="cl-login10__submit" type="submit" name="verify_otp" value="1">
                        Verify code
                    </button>
                </form>
            <?php else: ?>
                <form class="cl-login10__form" method="POST">
                    <div class="cl-login10__field">
                        <label class="cl-login10__label" for="password">
                            New password
                        </label>
                        <input class="cl-login10__input" id="password" name="password" type="password" minlength="8"
                            autocomplete="new-password" required>
                        <p class="cl-login10__hint">
                            At least 8 characters, including a number.
                        </p>
                    </div>

                    <div class="cl-login10__field">
                        <label class="cl-login10__label" for="confirm_password">
                            Confirm new password
                        </label>
                        <input class="cl-login10__input" id="confirm_password" name="confirm_password" type="password"
                            minlength="8" autocomplete="new-password" required>
                    </div>

                    <button class="cl-login10__submit" type="submit" name="reset_password" value="1">
                        Update password
                    </button>
                </form>
            <?php endif; ?>

            <p class="cl-login10__swap">
                <a class="cl-login10__link" href="index.php">
                    Back to login
                </a>
            </p>
        </section>
    </main>
</body>

</html>