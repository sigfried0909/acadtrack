<?php
session_start();
date_default_timezone_set('Asia/Manila');

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    empty($_SESSION['registration_email'])
) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

$email = strtolower(trim($_SESSION['registration_email']));

function resendRedirect(string $query): void
{
    header('Location: ../verify_registration.php' . $query);
    exit;
}

// Validate the resend CSRF token.
$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['registration_resend_csrf'] ?? '';

if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {
    resendRedirect('?error=resend_failed');
}

$transactionStarted = false;

try {

    $pdo->beginTransaction();
    $transactionStarted = true;

    // Lock the pending registration to prevent simultaneous resending.
    $stmt = $pdo->prepare(
        'SELECT nickname,
                student_number,
                email,
                last_sent_at,
                TIMESTAMPDIFF(
                    SECOND,
                    last_sent_at,
                    NOW()
                ) AS elapsed_seconds
         FROM registration_otps
         WHERE email = :email
         ORDER BY last_sent_at DESC
         LIMIT 1
         FOR UPDATE'
    );

    $stmt->execute([
        'email' => $email
    ]);

    $pending = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pending) {

        $pdo->rollBack();
        $transactionStarted = false;

        resendRedirect('?error=not_found');
    }

    // Prevent resending if the account has already been created.
    $stmt = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE email = :email
            OR student_number = :student_number
         LIMIT 1'
    );

    $stmt->execute([
        'email' => $pending['email'],
        'student_number' => $pending['student_number']
    ]);

    if ($stmt->fetch()) {

        $pdo->rollBack();
        $transactionStarted = false;

        unset($_SESSION['registration_email']);

        header('Location: ../index.php?error=email_exists#signup');
        exit;
    }

    // Enforce the 60-second cooldown using database time.
    $elapsed = max(
        0,
        (int) $pending['elapsed_seconds']
    );

    if ($elapsed < 60) {

        $pdo->rollBack();
        $transactionStarted = false;

        resendRedirect('?error=wait');
    }

    // Generate a new six-digit OTP.
    $otp = (string) random_int(100000, 999999);

    $otpHash = password_hash(
        $otp,
        PASSWORD_DEFAULT
    );

    if ($otpHash === false) {
        throw new RuntimeException('Unable to generate OTP.');
    }

    // Update the existing pending registration.
    // Old OTP becomes invalid immediately after commit.
    $stmt = $pdo->prepare(
        'UPDATE registration_otps
         SET otp_hash = :otp_hash,
             expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE),
             attempts = 0,
             last_sent_at = NOW()
         WHERE email = :email
           AND student_number = :student_number'
    );

    $stmt->execute([
        'otp_hash' => $otpHash,
        'email' => $pending['email'],
        'student_number' => $pending['student_number']
    ]);

    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Unable to update registration OTP.');
    }

    // Configure PHPMailer.
    $mailConfig = require __DIR__ . '/../config/mail.php';

    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = $mailConfig['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $mailConfig['username'];
    $mail->Password = $mailConfig['password'];
    $mail->Port = (int) $mailConfig['port'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet = 'UTF-8';

    $mail->setFrom(
        $mailConfig['from_email'],
        $mailConfig['from_name'] ?? 'AcadTrack Account Verification'
    );

    $mail->addAddress(
        $pending['email'],
        $pending['nickname']
    );

    $safeName = htmlspecialchars(
        $pending['nickname'],
        ENT_QUOTES,
        'UTF-8'
    );

    $mail->isHTML(true);
    $mail->Subject = 'Your AcadTrack Verification Code';

    $mail->Body = "
        <div style='font-family:Arial,sans-serif;max-width:520px;margin:auto;line-height:1.6;color:#18181b'>
            <h2 style='color:#6d28d9'>Verify your AcadTrack account</h2>

            <p>Hello {$safeName},</p>

            <p>Use the new verification code below to finish creating your student account:</p>

            <div style='font-size:32px;font-weight:bold;letter-spacing:8px;padding:18px;background:#f5f0ff;text-align:center;border-radius:12px'>
                {$otp}
            </div>

            <p>This code expires in <strong>10 minutes</strong>. Do not share it with anyone.</p>

            <p>If you did not request this code, you can ignore this email.</p>

            <p>
                AcadTrack<br>
                Saint Jude College PHINMA Education
            </p>
        </div>
    ";

    $mail->AltBody =
        "Hello {$pending['nickname']}, your new AcadTrack verification code is {$otp}. " .
        "It expires in 10 minutes. Do not share this code.";

    // Send the email before committing the new OTP.
    $mail->send();

    $pdo->commit();
    $transactionStarted = false;

    // Redirect using POST-Redirect-GET.
    resendRedirect('?resent=1');

} catch (Throwable $e) {

    if (
        $transactionStarted &&
        $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    error_log(
        'AcadTrack registration OTP resend error: ' . $e->getMessage()
    );

    resendRedirect('?error=resend_failed');
}
