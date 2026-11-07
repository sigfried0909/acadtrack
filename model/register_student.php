<?php
session_start();
date_default_timezone_set('Asia/Manila');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

$fullName = trim($_POST['name'] ?? '');
$studentNumber = trim($_POST['student_number'] ?? '');
$email = strtolower(trim($_POST['email'] ?? ''));
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';
$termsAccepted = ($_POST['terms'] ?? '') === '1';

function registrationRedirect(string $error): void
{
    header('Location: ../index.php?error=' . urlencode($error) . '#signup');
    exit;
}

if (
    $fullName === '' ||
    mb_strlen($fullName) < 2 ||
    mb_strlen($fullName) > 150 ||
    !preg_match('/^[0-9]{12}$/', $studentNumber) ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    strlen($email) > 191 ||
    strlen($password) < 8 ||
    !preg_match('/\d/', $password) ||
    $password !== $confirmPassword ||
    !$termsAccepted
) {
    registrationRedirect('registration_invalid');
}

try {
    // Do not allow duplicate accounts.
    $stmt = $pdo->prepare(
        'SELECT id FROM users
         WHERE student_number = :student_number
            OR email = :email
         LIMIT 1'
    );

    $stmt->execute([
        'student_number' => $studentNumber,
        'email' => $email
    ]);

    if ($stmt->fetch()) {
        $stmt = $pdo->prepare(
            'SELECT id FROM users WHERE student_number = ? LIMIT 1'
        );
        $stmt->execute([$studentNumber]);

        if ($stmt->fetch()) {
            registrationRedirect('student_number_exists');
        }

        registrationRedirect('email_exists');
    }

    // Enforce a 60-second resend cooldown for an existing pending request.
    $stmt = $pdo->prepare(
        'SELECT last_sent_at
         FROM registration_otps
         WHERE email = :email OR student_number = :student_number
         ORDER BY last_sent_at DESC
         LIMIT 1'
    );

    $stmt->execute([
        'email' => $email,
        'student_number' => $studentNumber
    ]);

    $pending = $stmt->fetch();

    if (
        $pending &&
        (time() - strtotime($pending['last_sent_at'])) < 60
    ) {
        registrationRedirect('registration_wait');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $otp = (string) random_int(100000, 999999);
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);

    // Save pending registration; the actual user is not created yet.
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'DELETE FROM registration_otps
         WHERE email = :email OR student_number = :student_number'
    );

    $stmt->execute([
        'email' => $email,
        'student_number' => $studentNumber
    ]);

    $stmt = $pdo->prepare(
        'INSERT INTO registration_otps
        (nickname, student_number, email, password_hash,
        otp_hash, expires_at, attempts, last_sent_at)
        VALUES
        (:nickname, :student_number, :email, :password_hash,
        :otp_hash, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0, NOW())'
    );

    $stmt->execute([
        'nickname' => $fullName,
        'student_number' => $studentNumber,
        'email' => $email,
        'password_hash' => $passwordHash,
        'otp_hash' => $otpHash,
    ]);

    $pdo->commit();

    // Configure PHPMailer.
    // Assumes Composer's vendor/autoload.php and config/mail.php exist.
    require_once __DIR__ . '/../vendor/autoload.php';
    $mailConfig = require __DIR__ . '/../config/mail.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $mailConfig['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $mailConfig['username'];
    $mail->Password = $mailConfig['password'];
    $mail->Port = (int) $mailConfig['port'];
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet = 'UTF-8';

    $mail->setFrom(
        $mailConfig['from_email'],
        $mailConfig['from_name'] ?? 'AcadTrack Account Verification'
    );
    $mail->addAddress($email, $fullName);
    $mail->isHTML(true);
    $mail->Subject = 'Your AcadTrack Verification Code';

    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');

    $mail->Body = "
        <div style='font-family:Arial,sans-serif;max-width:520px;margin:auto;line-height:1.6;color:#18181b'>
            <h2 style='color:#6d28d9'>Verify your AcadTrack account</h2>
            <p>Hello {$safeName},</p>
            <p>Use the verification code below to finish creating your student account:</p>
            <div style='font-size:32px;font-weight:bold;letter-spacing:8px;padding:18px;background:#f5f0ff;text-align:center;border-radius:12px'>
                {$otp}
            </div>
            <p>This code expires in <strong>10 minutes</strong>. Do not share it with anyone.</p>
            <p>If you did not request this code, you can ignore this email.</p>
            <p>AcadTrack<br>Saint Jude College PHINMA Education</p>
        </div>
    ";

    $mail->AltBody =
        "Hello {$fullName}, your AcadTrack verification code is {$otp}. " .
        "It expires in 10 minutes. Do not share this code.";

    $mail->send();

    $_SESSION['registration_email'] = $email;

    header('Location: ../verify_registration.php');
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Student registration / OTP email error: ' . $e->getMessage());

    // Do not leave an unusable pending registration if sending failed.
    if (isset($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $stmt = $pdo->prepare(
                'DELETE FROM registration_otps WHERE email = ?'
            );
            $stmt->execute([$email]);
        } catch (Throwable $cleanupError) {
            error_log('Registration OTP cleanup error: ' . $cleanupError->getMessage());
        }
    }

    registrationRedirect('registration_email_failed');
}
