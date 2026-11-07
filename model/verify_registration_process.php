<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

$email = $_SESSION['registration_email'] ?? '';
$otp = trim($_POST['otp'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: ../index.php');
    exit;
}

function verificationRedirect(string $error): void
{
    header('Location: ../verify_registration.php?error=' . urlencode($error));
    exit;
}

if (!preg_match('/^[0-9]{6}$/', $otp)) {
    verificationRedirect('invalid');
}

try {

    $pdo->beginTransaction();

    // Lock the pending registration while checking the OTP.
    $stmt = $pdo->prepare(
        'SELECT
            id,
            nickname,
            student_number,
            email,
            password_hash,
            otp_hash,
            expires_at,
            attempts,
            (expires_at <= NOW()) AS is_expired
         FROM registration_otps
         WHERE email = :email
         LIMIT 1
         FOR UPDATE'
    );

    $stmt->execute([
        'email' => $email
    ]);

    $pending = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check whether the pending registration exists.
    if (!$pending) {

        $pdo->commit();

        unset($_SESSION['registration_email']);

        header('Location: ../index.php?error=registration_expired#signup');
        exit;
    }

    // Check expiration using MySQL's clock.
    if ((int) $pending['is_expired'] === 1) {

        $stmt = $pdo->prepare(
            'DELETE FROM registration_otps
             WHERE id = ?'
        );

        $stmt->execute([
            $pending['id']
        ]);

        $pdo->commit();

        unset($_SESSION['registration_email']);

        header('Location: ../index.php?error=registration_expired#signup');
        exit;
    }

    // Enforce the maximum of five attempts.
    if ((int) $pending['attempts'] >= 5) {

        $pdo->commit();

        verificationRedirect('attempts');
    }

    // Verify the submitted OTP.
    if (!password_verify($otp, $pending['otp_hash'])) {

        $stmt = $pdo->prepare(
            'UPDATE registration_otps
             SET attempts = attempts + 1
             WHERE id = ?'
        );

        $stmt->execute([
            $pending['id']
        ]);

        $pdo->commit();

        verificationRedirect('incorrect');
    }

    // Recheck uniqueness immediately before creating the account.
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

        $stmt = $pdo->prepare(
            'DELETE FROM registration_otps
             WHERE id = ?'
        );

        $stmt->execute([
            $pending['id']
        ]);

        $pdo->commit();

        unset($_SESSION['registration_email']);

        header('Location: ../index.php?error=registration_conflict#signup');
        exit;
    }

    // Create the student account only after successful OTP verification.
    $stmt = $pdo->prepare(
        'INSERT INTO users
            (nickname, student_number, email, password_hash, role, is_active)
         VALUES
            (:nickname, :student_number, :email, :password_hash, :role, :is_active)'
    );

    $stmt->execute([
        'nickname' => $pending['nickname'],
        'student_number' => $pending['student_number'],
        'email' => $pending['email'],
        'password_hash' => $pending['password_hash'],
        'role' => 'student',
        'is_active' => 1
    ]);

    // Remove the OTP after successful registration.
    $stmt = $pdo->prepare(
        'DELETE FROM registration_otps
         WHERE id = ?'
    );

    $stmt->execute([
        $pending['id']
    ]);

    $pdo->commit();

    unset($_SESSION['registration_email']);

    header('Location: ../index.php?registered=1');
    exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Registration OTP verification error: ' . $e->getMessage()
    );

    if (
        $e instanceof PDOException &&
        $e->getCode() === '23000'
    ) {

        unset($_SESSION['registration_email']);

        header('Location: ../index.php?error=registration_conflict#signup');
        exit;
    }

    verificationRedirect('failed');
}
