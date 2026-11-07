
(() => {
    "use strict";

    /*
    |--------------------------------------------------------------------------
    | VERIFY OTP LOADING STATE
    |--------------------------------------------------------------------------
    */

    const verifyForm = document.getElementById("verify-otp-form");
    const verifyButton = document.getElementById("verify-otp-button");

    if (verifyForm && verifyButton) {

        const verifyText = verifyButton.querySelector(
            ".cl-login10__button-text"
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
            verifyButton.setAttribute("disabled", "");
            verifyButton.setAttribute("aria-busy", "true");

            verifyText.textContent = "Verifying...";

        });
    }


    /*
    |--------------------------------------------------------------------------
    | RESEND OTP ELEMENTS
    |--------------------------------------------------------------------------
    */

    const resendForm = document.getElementById("resend-otp-form");
    const resendButton = document.getElementById("resend-button");
    const status = document.getElementById("resend-status");

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

    let unlockAt = Date.now() + remaining * 1000;
    let isResending = false;


    /*
    |--------------------------------------------------------------------------
    | TIMER FORMAT
    |--------------------------------------------------------------------------
    */

    function formatTime(seconds) {

        const minutes = Math.floor(seconds / 60);
        const secondsLeft = seconds % 60;

        return (
            String(minutes).padStart(2, "0") +
            ":" +
            String(secondsLeft).padStart(2, "0")
        );
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE COUNTDOWN
    |--------------------------------------------------------------------------
    */

    function updateTimer() {

        if (isResending) {

            resendButton.disabled = true;
            resendButton.setAttribute("disabled", "");
            resendButton.setAttribute("aria-disabled", "true");
            resendButton.setAttribute("aria-busy", "true");

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

            resendButton.removeAttribute("aria-disabled");
            resendButton.removeAttribute("aria-busy");

        }
    }


    /*
    |--------------------------------------------------------------------------
    | RESEND SUBMISSION
    |--------------------------------------------------------------------------
    */

    resendForm.addEventListener("submit", function (event) {

        remaining = Math.max(
            0,
            Math.ceil((unlockAt - Date.now()) / 1000)
        );

        if (isResending || remaining > 0) {

            event.preventDefault();
            updateTimer();

            return;
        }

        // Completely lock the button during email sending.
        isResending = true;

        resendButton.disabled = true;
        resendButton.setAttribute("disabled", "");
        resendButton.setAttribute("aria-disabled", "true");
        resendButton.setAttribute("aria-busy", "true");

        resendButton.classList.add("is-loading");

        resendText.textContent = "Sending...";

        status.textContent =
            "Please wait while we send your verification code.";

    });


    /*
    |--------------------------------------------------------------------------
    | START COUNTDOWN
    |--------------------------------------------------------------------------
    */

    updateTimer();

    const timer = window.setInterval(() => {

        updateTimer();

        if (remaining <= 0 && !isResending) {
            window.clearInterval(timer);
        }

    }, 1000);


    /*
    |--------------------------------------------------------------------------
    | BROWSER HISTORY RESTORATION
    |--------------------------------------------------------------------------
    */

    window.addEventListener("pageshow", function (event) {

        if (event.persisted) {
            window.location.reload();
        }

    });

})();
