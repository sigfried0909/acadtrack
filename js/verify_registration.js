(() => {
    "use strict";

    /*
    |--------------------------------------------------------------------------
    | OTP INPUT VALIDATION
    |--------------------------------------------------------------------------
    */

    const otpInput = document.getElementById("otp");

    if (otpInput) {
        otpInput.addEventListener("input", function () {
            this.value = this.value
                .replace(/\D/g, "")
                .slice(0, 6);
        });
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFY BUTTON LOADING STATE
    |--------------------------------------------------------------------------
    */

    const verifyForm = document.getElementById(
        "verify-registration-form"
    );

    const verifyButton = document.getElementById(
        "verify-registration-button"
    );

    if (verifyForm && verifyButton) {

        const verifyText = verifyButton.querySelector(
            ".otp-verify-text"
        );

        let isVerifying = false;

        verifyForm.addEventListener("submit", function (event) {

            if (isVerifying) {
                event.preventDefault();
                return;
            }

            isVerifying = true;

            verifyButton.disabled = true;

            verifyButton.classList.add("is-loading");

            verifyButton.setAttribute(
                "aria-busy",
                "true"
            );

            verifyText.textContent = "Verifying...";

        });
    }


    /*
    |--------------------------------------------------------------------------
    | RESEND BUTTON AND LIVE COUNTDOWN
    |--------------------------------------------------------------------------
    */

    const resendForm = document.getElementById(
        "resend-registration-form"
    );

    const resendButton = document.getElementById(
        "resend-registration-button"
    );

    const status = document.getElementById(
        "resend-status"
    );

    if (!resendForm || !resendButton || !status) {
        return;
    }

    const resendText = resendButton.querySelector(
        ".otp-resend-text"
    );

    let remaining = Math.max(
        0,
        parseInt(status.dataset.remaining, 10) || 0
    );

    let unlockAt = Date.now() + (remaining * 1000);

    let isResending = false;

    function formatTime(seconds) {

        const minutes = Math.floor(seconds / 60);
        const secondsLeft = seconds % 60;

        return (
            String(minutes).padStart(2, "0") +
            ":" +
            String(secondsLeft).padStart(2, "0")
        );
    }

    function updateResendTimer() {

        // Never re-enable while email sending is in progress.
        if (isResending) {

            resendButton.disabled = true;

            resendButton.setAttribute(
                "aria-disabled",
                "true"
            );

            resendButton.setAttribute(
                "aria-busy",
                "true"
            );

            return;
        }

        remaining = Math.max(
            0,
            Math.ceil((unlockAt - Date.now()) / 1000)
        );

        if (remaining > 0) {

            status.textContent =
                "You can request another code in " +
                formatTime(remaining) + ".";

            resendButton.disabled = true;

        } else {

            status.textContent =
                "You can request a new verification code now.";

            resendButton.disabled = false;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | RESEND SUBMISSION
    |--------------------------------------------------------------------------
    */

    resendForm.addEventListener("submit", function (event) {

        if (isResending || remaining > 0) {

            event.preventDefault();

            updateResendTimer();

            return;
        }

        // Lock the button immediately.
        isResending = true;

        resendButton.disabled = true;

        resendButton.classList.add("is-loading");

        resendButton.setAttribute(
            "disabled",
            ""
        );

        resendButton.setAttribute(
            "aria-disabled",
            "true"
        );

        resendButton.setAttribute(
            "aria-busy",
            "true"
        );

        resendText.textContent = "Sending...";

        status.textContent =
            "Please wait while we send your verification code.";

    });


    /*
    |--------------------------------------------------------------------------
    | START COUNTDOWN
    |--------------------------------------------------------------------------
    */

    updateResendTimer();

    const timer = window.setInterval(() => {

        updateResendTimer();

        if (remaining <= 0 && !isResending) {
            window.clearInterval(timer);
        }

    }, 1000);


    /*
    |--------------------------------------------------------------------------
    | HANDLE BROWSER HISTORY RESTORATION
    |--------------------------------------------------------------------------
    */

    window.addEventListener("pageshow", function (event) {

        // Reload server-provided cooldown when restored from browser cache.
        if (event.persisted) {
            window.location.reload();
        }

    });

})();