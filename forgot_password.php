<?php
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

$message = '';
$error = '';
$studentNumber = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentNumber = trim($_POST['student_number'] ?? '');

    $isStudentNumber = preg_match('/^[0-9]{12}$/', $studentNumber);
    $isEmail = filter_var($studentNumber, FILTER_VALIDATE_EMAIL);

    if (!$isStudentNumber && !$isEmail) {
        $error = 'Enter a valid 12-digit student number or email address.';
    } else {
        unset(
            $_SESSION['recovery_student_number'],
            $_SESSION['recovery_user_id'],
            $_SESSION['password_reset_user_id'],
            $_SESSION['password_reset_verified_at']
        );

        $transactionStarted = false;
        $user = null;

        try {
            // Find any active account, regardless of role.
            if ($isEmail) {
                $stmt = $pdo->prepare(
                    'SELECT id, nickname, email, student_number, role
                     FROM users
                     WHERE email = :identifier
                       AND is_active = 1
                     LIMIT 1'
                );
            } else {
                $stmt = $pdo->prepare(
                    'SELECT id, nickname, email, student_number, role
                     FROM users
                     WHERE student_number = :identifier
                       AND is_active = 1
                     LIMIT 1'
                );
            }

            $stmt->execute(['identifier' => $studentNumber]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $error = 'No active account was found. Check your details and try again.';
            } else {
                // Start transaction and lock the existing OTP record.
                $pdo->beginTransaction();
                $transactionStarted = true;

                $stmt = $pdo->prepare(
                    'SELECT verified_at,
                COALESCE(
                    TIMESTAMPDIFF(SECOND, created_at, NOW()), 0
                ) AS elapsed_seconds
         FROM password_reset_otps
         WHERE user_id = :user_id
         FOR UPDATE'
                );

                $stmt->execute([
                    'user_id' => $user['id']
                ]);

                $existingOtp = $stmt->fetch(PDO::FETCH_ASSOC);

                // Enforce a 60-second resend cooldown.
                if (
                    $existingOtp &&
                    $existingOtp['verified_at'] === null &&
                    (int) $existingOtp['elapsed_seconds'] < 60
                ) {
                    $pdo->commit();
                    $transactionStarted = false;

                    // Continue to the verification page with the existing OTP.
                    $_SESSION['recovery_user_id'] = (int) $user['id'];
                    $_SESSION['recovery_student_number'] =
                        $user['student_number'] ?? '';

                    header('Location: verify_reset.php');
                    exit;
                }

                // Generate a six-digit OTP.
                $otp = (string) random_int(100000, 999999);
                $otpHash = password_hash($otp, PASSWORD_DEFAULT);

                if ($otpHash === false) {
                    throw new RuntimeException('Unable to generate OTP.');
                }

                // Save the OTP transactionally.
                $stmt = $pdo->prepare(
                    'INSERT INTO password_reset_otps
            (user_id, otp_hash, expires_at, attempts, verified_at, created_at)
         VALUES
            (:user_id, :otp_hash,
             DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0, NULL, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE
            otp_hash = VALUES(otp_hash),
            expires_at = VALUES(expires_at),
            attempts = 0,
            verified_at = NULL,
            created_at = CURRENT_TIMESTAMP'
                );

                $stmt->execute([
                    'user_id' => $user['id'],
                    'otp_hash' => $otpHash
                ]);

                // Configure SMTP using the existing mail configuration.
                $mailConfig = require __DIR__ . '/config/mail.php';

                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $mailConfig['host'];
                $mail->SMTPAuth = true;
                $mail->Username = $mailConfig['username'];
                $mail->Password = $mailConfig['password'];
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = (int) $mailConfig['port'];
                $mail->CharSet = 'UTF-8';

                $mail->setFrom(
                    $mailConfig['from_email'],
                    $mailConfig['from_name']
                );

                $mail->addAddress(
                    $user['email']
                );

                $safeName = htmlspecialchars(
                    $user['nickname'],
                    ENT_QUOTES,
                    'UTF-8'
                );

                $mail->isHTML(true);
                $mail->Subject = 'AcadTrack Password Reset Code';

                $mail->Body = '
                <div style="margin:0;padding:30px 15px;background-color:#f4f5fb;font-family:Arial,Helvetica,sans-serif;">
                    <div style="max-width:520px;margin:0 auto;background-color:#ffffff;border:1px solid #e8eaf2;border-radius:16px;overflow:hidden;">

                        <!-- Email Content -->
                        <div style="padding:32px 28px 24px;">
                            <p style="margin:0 0 8px;color:#6d28d9;font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;">
                                Password Recovery
                            </p>

                            <h2 style="margin:0 0 16px;color:#101828;font-size:25px;line-height:1.4;">
                                Reset Your Password
                            </h2>

                            <p style="margin:0 0 12px;color:#344054;font-size:15px;line-height:1.8;">
                                Hello <strong>' . $safeName . '</strong>,
                            </p>

                            <p style="margin:0 0 24px;color:#667085;font-size:14px;line-height:1.8;">
                                We received a request to reset your AcadTrack password. Use the verification code below to continue.
                            </p>

                            <!-- OTP Card -->
                            <div style="padding:24px 16px;background-color:#f5f3ff;border:1px solid #ddd6fe;border-radius:12px;text-align:center;">
                                <p style="margin:0 0 12px;color:#6d28d9;font-size:12px;font-weight:700;letter-spacing:1.5px;">
                                    YOUR VERIFICATION CODE
                                </p>

                                <div style="font-size:32px;font-weight:700;letter-spacing:8px;color:#5b21b6;line-height:1.5;overflow-wrap:anywhere;">
                                    ' . $otp . '
                                </div>

                                <p style="margin:12px 0 0;color:#667085;font-size:12px;">
                                    This code expires in <strong style="color:#344054;">10 minutes</strong>.
                                </p>
                            </div>

                            <!-- Security Notice -->
                            <div style="margin-top:24px;padding:14px 16px;background-color:#fffbeb;border-left:4px solid #f59e0b;border-radius:6px;">
                                <p style="margin:0;color:#92400e;font-size:13px;line-height:1.7;">
                                    <strong>Security reminder:</strong> Never share this code with anyone. AcadTrack staff will never ask you to disclose your verification code.
                                </p>
                            </div>

                            <p style="margin:24px 0 0;color:#667085;font-size:13px;line-height:1.8;">
                                If you did not request a password reset, you can safely ignore this email. Your account will remain secure.
                            </p>

                            <!-- Footer -->
                            <div style="margin-top:28px;padding:20px 0 0;border-top:1px solid #eaecf0;text-align:center;">
                                <p style="margin:0 0 6px;color:#344054;font-size:13px;font-weight:700;">
                                    AcadTrack | CITE
                                </p>
                                <p style="margin:0 0 10px;color:#667085;font-size:12px;">
                                    Saint Jude College – PHINMA Education
                                </p>
                                <p style="margin:0;color:#98a2b3;font-size:11px;line-height:1.7;">
                                    This is an automated message. Please do not reply to this email.
                                    <br>
                                    &copy; ' . date('Y') . ' AcadTrack. All rights reserved.
                                </p>
                            </div>
                        </div>

                    </div>
                </div>';

                $mail->AltBody =
                    "Your AcadTrack password reset code is: {$otp}\n" .
                    "It expires in 10 minutes.";

                // Send the email before committing the new OTP.
                $mail->send();
                $pdo->commit();
                $transactionStarted = false;

                // Store the account ID because faculty/admin may not
                // have a student number.
                $_SESSION['recovery_user_id'] = (int) $user['id'];
                $_SESSION['recovery_student_number'] =
                    $user['student_number'] ?? '';

                header('Location: verify_reset.php');
                exit;
            }
        } catch (Throwable $e) {
            if ($transactionStarted && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('AcadTrack password recovery error: ' . $e->getMessage());

            unset(
                $_SESSION['recovery_student_number'],
                $_SESSION['recovery_user_id'],
                $_SESSION['password_reset_user_id'],
                $_SESSION['password_reset_verified_at']
            );

            $error = 'We could not send your reset code. Please try again later.';
        }
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password | AcadTrack</title>
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

            <h2 class="cl-login10__title">Forgot Password?</h2>

            <p>
                Enter your 12-digit student number or registered email address.
                If your active account is found, a six-digit reset code
                will be sent to your registered email.
            </p>

            <?php if ($message !== ''): ?>
                <div role="status"
                    style="color:#166534;background:#f0fdf4;border:1px solid #bbf7d0;padding:12px;border-radius:8px;margin-bottom:16px;">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>

                    <p>
                        <a class="cl-login10__link" href="verify_reset.php">
                            Continue to code verification
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="login-error" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($message === ''): ?>
                <form class="cl-login10__form" method="POST" action="forgot_password.php">

                    <div class="cl-login10__field">
                        <label class="cl-login10__label" for="student_number">
                            Student Number or Email Address
                        </label>

                        <input class="cl-login10__input" id="student_number" name="student_number" type="text"
                            maxlength="254" autocomplete="off" placeholder="Enter student number or email address"
                            value="<?= htmlspecialchars($studentNumber, ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>

                    <button class="cl-login10__submit" type="submit">
                        Send Reset Code
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