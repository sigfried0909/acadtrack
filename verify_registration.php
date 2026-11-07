<?php
session_start();

date_default_timezone_set('Asia/Manila');

if (empty($_SESSION['registration_email'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

$email = $_SESSION['registration_email'];

$errorMessages = [
    'invalid' => 'Enter the six-digit verification code.',
    'incorrect' => 'The code is incorrect. Please try again.',
    'expired' => 'Your verification code has expired. Start registration again.',
    'attempts' => 'Too many incorrect attempts. Start registration again.',
    'failed' => 'Verification could not be completed. Please try again.',
    'wait' => 'Please wait until the 60-second cooldown ends before requesting another code.',
    'resend_failed' => 'We could not send a new verification code. Please try again.',
    'not_found' => 'Your pending registration was not found. Please start registration again.'
];

$error = $errorMessages[$_GET['error'] ?? ''] ?? '';

$resent = isset($_GET['resent']) && $_GET['resent'] === '1';

// Generate CSRF token for resend requests.
if (empty($_SESSION['registration_resend_csrf'])) {
    $_SESSION['registration_resend_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['registration_resend_csrf'];

// Get the actual remaining cooldown from the database.
$cooldownRemaining = 0;

try {

    $stmt = $pdo->prepare(
        'SELECT GREATEST(
                    0,
                    60 - TIMESTAMPDIFF(SECOND, last_sent_at, NOW())
                ) AS remaining
         FROM registration_otps
         WHERE email = :email
         ORDER BY last_sent_at DESC
         LIMIT 1'
    );

    $stmt->execute([
        'email' => $email
    ]);

    $cooldown = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cooldown) {
        $cooldownRemaining = (int) $cooldown['remaining'];
    }

} catch (Throwable $e) {

    error_log(
        'Registration OTP cooldown error: ' . $e->getMessage()
    );
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Verify Email | AcadTrack</title>

    <link rel="stylesheet" href="style/index.css">

</head>

<body>

    <main class="cl-login10-demo">

        <section class="cl-login10" aria-label="Email verification">

            <header class="cl-login10__head">

                <span class="cl-login10__logo" aria-hidden="true">
                    <img src="assets/bg_logo.png" style="width:40px;height:32px" alt="">
                </span>

                <p class="cl-login10__brand">AcadTrack</p>

                <p class="cl-login10__tagline">
                    College of Information Technology Education
                </p>

            </header>

            <h2 class="cl-login10__title">
                Verify your email
            </h2>

            <p class="otp-description">
                We sent a six-digit verification code to

                <strong>
                    <?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>
                </strong>.

                Enter the code below to complete your student account registration.
            </p>


            <?php if ($error !== ''): ?>

                <div class="otp-error" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>

            <?php endif; ?>


            <?php if ($resent): ?>

                <div class="otp-success" role="status">
                    A new verification code has been sent to your registered email address.
                </div>

            <?php endif; ?>


            <!-- VERIFY REGISTRATION FORM -->

            <form class="cl-login10__form" id="verify-registration-form" action="model/verify_registration_process.php"
                method="POST">

                <div class="cl-login10__field">

                    <label class="cl-login10__label" for="otp">
                        Enter code
                    </label>

                    <input class="cl-login10__input otp-input" id="otp" name="otp" type="text" inputmode="numeric"
                        autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" minlength="6" required autofocus
                        placeholder="000000" aria-label="Six-digit verification code">

                </div>

                <button class="cl-login10__submit" id="verify-registration-button" type="submit">

                    <span class="otp-verify-text">
                        Verify and create account
                    </span>

                    <span class="otp-button-spinner" aria-hidden="true">
                    </span>

                </button>

            </form>


            <!-- RESEND REGISTRATION CODE -->

            <div class="otp-resend-wrapper">

                <p class="otp-resend-status" id="resend-status" data-remaining="<?= (int) $cooldownRemaining ?>"
                    aria-live="polite">
                </p>

                <form id="resend-registration-form" action="model/resend_registration_code.php" method="POST">

                    <input type="hidden" name="csrf_token"
                        value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <input type="hidden" name="resend_code" value="1">

                    <button class="otp-resend-button" id="resend-registration-button" type="submit"
                        <?= $cooldownRemaining > 0 ? 'disabled' : '' ?>>

                        <span class="otp-resend-text">
                            Resend Code
                        </span>

                        <span class="otp-resend-spinner" aria-hidden="true">
                        </span>

                    </button>

                </form>

            </div>


            <a class="cl-login10__link otp-back" href="index.php">
                Cancel and return to login
            </a>

            <p class="cl-login10__demo">
                Saint Jude College PHINMA Education
            </p>

        </section>

    </main>

    <script src="js/verify_registration.js" defer></script>

</body>

</html>