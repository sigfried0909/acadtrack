<?php
session_start();

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    empty($_SESSION['recovery_user_id'])
) {
    header('Location: ../forgot_password.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

$userId = (int) $_SESSION['recovery_user_id'];

function passwordResetRedirect(string $query): void
{
    header('Location: ../verify_reset.php' . $query);
    exit;
}

// Validate CSRF token.
$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['password_reset_resend_csrf'] ?? '';

if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {
    passwordResetRedirect('?error=resend_failed');
}

// A verified code cannot be resent.
if (!empty($_SESSION['password_reset_user_id'])) {
    passwordResetRedirect('?error=already_verified');
}

$transactionStarted = false;

try {

    $pdo->beginTransaction();
    $transactionStarted = true;

    /*
    |--------------------------------------------------------------------------
    | CHECK EXISTING OTP AND COOLDOWN
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'SELECT id,
                verified_at,
                COALESCE(
                    TIMESTAMPDIFF(SECOND, created_at, NOW()),
                    60
                ) AS elapsed_seconds
         FROM password_reset_otps
         WHERE user_id = :user_id
         FOR UPDATE'
    );

    $stmt->execute([
        'user_id' => $userId
    ]);

    $existingOtp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingOtp) {

        $pdo->rollBack();
        $transactionStarted = false;

        passwordResetRedirect('?error=not_found');
    }

    if ($existingOtp['verified_at'] !== null) {

        $pdo->rollBack();
        $transactionStarted = false;

        passwordResetRedirect('?error=already_verified');
    }

    // Enforce the 60-second cooldown.
    $elapsedSeconds = max(
        0,
        (int) $existingOtp['elapsed_seconds']
    );

    if ($elapsedSeconds < 60) {

        $pdo->rollBack();
        $transactionStarted = false;

        passwordResetRedirect('?error=wait');
    }


    /*
    |--------------------------------------------------------------------------
    | GET ACTIVE USER ACCOUNT
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'SELECT id, nickname, email, student_number
         FROM users
         WHERE id = :user_id
           AND is_active = 1
         LIMIT 1'
    );

    $stmt->execute([
        'user_id' => $userId
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException(
            'The recovery account is unavailable.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | GENERATE NEW OTP
    |--------------------------------------------------------------------------
    */

    $otp = (string) random_int(100000, 999999);

    $otpHash = password_hash(
        $otp,
        PASSWORD_DEFAULT
    );

    if ($otpHash === false) {
        throw new RuntimeException(
            'Unable to generate OTP.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE EXISTING OTP RECORD
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'UPDATE password_reset_otps
         SET otp_hash = :otp_hash,
             expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE),
             attempts = 0,
             verified_at = NULL,
             created_at = NOW()
         WHERE id = :id
           AND user_id = :user_id
           AND verified_at IS NULL'
    );

    $stmt->execute([
        'otp_hash' => $otpHash,
        'id' => $existingOtp['id'],
        'user_id' => $userId
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Unable to update the password reset code.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SEND NEW OTP EMAIL
    |--------------------------------------------------------------------------
    */

    $mailConfig = require __DIR__ . '/../config/mail.php';

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

    $mail->addAddress($user['email']);

    $safeName = htmlspecialchars(
        (string) $user['nickname'],
        ENT_QUOTES,
        'UTF-8'
    );

    $mail->isHTML(true);
    $mail->Subject = 'AcadTrack Password Reset Code';

    $mail->Body = '
    <div style="margin:0;padding:30px 15px;background-color:#f4f5fb;font-family:Arial,Helvetica,sans-serif;">
        <div style="max-width:520px;margin:0 auto;background-color:#ffffff;border:1px solid #e8eaf2;border-radius:16px;overflow:hidden;">

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
                    Use your new verification code below to continue resetting your AcadTrack password.
                </p>

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

                <div style="margin-top:24px;padding:14px 16px;background-color:#fffbeb;border-left:4px solid #f59e0b;border-radius:6px;">
                    <p style="margin:0;color:#92400e;font-size:13px;line-height:1.7;">
                        <strong>Security reminder:</strong> Never share this code with anyone. AcadTrack staff will never ask you to disclose your verification code.
                    </p>
                </div>

                <p style="margin:24px 0 0;color:#667085;font-size:13px;line-height:1.8;">
                    If you did not request this password reset, you can safely ignore this email.
                </p>

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
        "Your new AcadTrack password reset code is: {$otp}\n" .
        "It expires in 10 minutes.";

    $mail->send();


    /*
    |--------------------------------------------------------------------------
    | COMMIT AFTER SUCCESSFUL EMAIL DELIVERY
    |--------------------------------------------------------------------------
    */

    $pdo->commit();
    $transactionStarted = false;

    passwordResetRedirect('?resent=1');

} catch (Throwable $e) {

    if (
        $transactionStarted &&
        $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    error_log(
        'AcadTrack password reset resend error: ' . $e->getMessage()
    );

    passwordResetRedirect('?error=resend_failed');
}
