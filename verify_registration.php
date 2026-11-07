<?php
session_start();

if (empty($_SESSION['registration_email'])) {
    header('Location: index.php');
    exit;
}

$email = $_SESSION['registration_email'];
$errorMessages = [
    'invalid' => 'Enter the six-digit verification code.',
    'incorrect' => 'The code is incorrect. Please try again.',
    'expired' => 'Your verification code has expired. Start registration again.',
    'attempts' => 'Too many incorrect attempts. Start registration again.',
    'failed' => 'Verification could not be completed. Please try again.'
];

$error = $errorMessages[$_GET['error'] ?? ''] ?? '';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Email | AcadTrack</title>
    <link rel="stylesheet" href="style/index.css">
    <style>
        .otp-description {
            color: #52525b;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .otp-input {
            text-align: center;
            letter-spacing: 10px;
            font-size: 24px !important;
            font-weight: 700;
        }

        .otp-error {
            color: #b42318;
            background: #fef3f2;
            border: 1px solid #fecdca;
            border-radius: 8px;
            padding: 10px 12px;
            margin: 12px 0;
            font-size: 13px;
        }

        .otp-back {
            display: block;
            text-align: center;
            margin-top: 16px;
            font-size: 14px;
        }
    </style>
</head>

<body>
    <main class="cl-login10-demo">
        <section class="cl-login10" aria-label="Email verification">
            <header class="cl-login10__head">
                <span class="cl-login10__logo" aria-hidden="true">
                    <img src="assets/bg_logo.png" style="width:40px;height:32px" alt="">
                </span>
                <p class="cl-login10__brand">AcadTrack</p>
                <p class="cl-login10__tagline">College of Information Technology Education</p>
            </header>

            <h2 class="cl-login10__title">Verify your email</h2>
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

            <form class="cl-login10__form" action="model/verify_registration_process.php" method="POST">
                <div class="cl-login10__field">
                    <label class="cl-login10__label" for="otp">Enter code</label>
                    <input class="cl-login10__input otp-input" id="otp" name="otp" type="text" inputmode="numeric"
                        autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" minlength="6" required autofocus
                        placeholder="000000" aria-label="Six-digit verification code">
                </div>

                <button class="cl-login10__submit" type="submit">
                    Verify and create account
                </button>
            </form>

            <a class="cl-login10__link otp-back" href="index.php">
                Cancel and return to login
            </a>

            <p class="cl-login10__demo">Saint Jude College PHINMA Education</p>
        </section>
    </main>

    <script>
        document.getElementById('otp').addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
        });
    </script>
</body>

</html>