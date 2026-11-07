
(() => {
    "use strict";

    const body = document.body;

    const resetModal = document.getElementById(
        "admin-reset-modal"
    );

    const passwordModal = document.getElementById(
        "admin-reset-password-modal"
    );

    if (!resetModal || !passwordModal) return;

    const openButtons = document.querySelectorAll(
        "[data-open-reset-data-modal]"
    );

    const closeResetButtons = document.querySelectorAll(
        "[data-close-reset-data-modal]"
    );

    const closePasswordButtons = document.querySelectorAll(
        "[data-close-reset-password-modal]"
    );

    const resetPasswordForm = document.getElementById(
        "admin-reset-password-form"
    );

    const resetActionInput = document.getElementById(
        "admin-reset-action"
    );

    const passwordInput = document.getElementById(
        "admin-reset-current-password"
    );

    const submitButton = document.getElementById(
        "admin-reset-submit"
    );

    let resetReturnFocus = null;
    let passwordReturnFocus = null;

    let submitting = false;

    // --------------------------------------------------
    // ICONS
    // --------------------------------------------------

    function initializeIcons() {
        window.lucide?.createIcons();
    }

    // --------------------------------------------------
    // BODY SCROLL LOCK
    // --------------------------------------------------

    function updateBodyLock() {

        const profileModal = document.getElementById(
            "admin-profile-modal"
        );

        const accountModal = document.getElementById(
            "admin-password-modal"
        );

        const hasOpenModal = Boolean(
            (resetModal && !resetModal.hidden) ||
            (passwordModal && !passwordModal.hidden) ||
            (profileModal && !profileModal.hidden) ||
            (accountModal && !accountModal.hidden)
        );

        body.classList.toggle(
            "admin-profile-modal-open",
            hasOpenModal
        );

    }

    // --------------------------------------------------
    // CLOSE DROPDOWNS
    // --------------------------------------------------

    function closeDropdowns() {

        document.querySelectorAll(".dropdown").forEach(menu => {
            menu.hidden = true;
        });

        document.querySelectorAll(
            "[data-dropdown-toggle]"
        ).forEach(button => {

            button.setAttribute("aria-expanded", "false");

        });

    }

    // --------------------------------------------------
    // RESTORE PASSWORD FORM
    // --------------------------------------------------

    function resetPasswordFormState() {

        resetPasswordForm?.reset();

        submitting = false;

        if (submitButton) {

            submitButton.disabled = false;

            submitButton.removeAttribute("aria-busy");

            submitButton.innerHTML =
                '<i data-lucide="shield-check"></i> Verify and Continue';

        }

        initializeIcons();

    }

    // --------------------------------------------------
    // OPEN RESET DATA MODAL
    // --------------------------------------------------

    function openResetModal(trigger) {

        if (!resetModal.hidden) return;

        resetReturnFocus = trigger instanceof HTMLElement
            ? trigger
            : document.activeElement;

        closeDropdowns();

        body.classList.remove("mobile-sidebar-open");

        document.querySelector(
            "[data-open-sidebar]"
        )?.setAttribute("aria-expanded", "false");

        const searchOverlay = document.getElementById(
            "search-overlay"
        );

        if (searchOverlay && !searchOverlay.hidden) {

            searchOverlay.hidden = true;

            body.classList.remove("search-open");

        }

        resetModal.hidden = false;

        updateBodyLock();

        initializeIcons();

    }

    // --------------------------------------------------
    // CLOSE RESET DATA MODAL
    // --------------------------------------------------

    function closeResetModal() {

        if (resetModal.hidden || !passwordModal.hidden) {
            return;
        }

        resetModal.hidden = true;

        updateBodyLock();

        if (
            resetReturnFocus instanceof HTMLElement &&
            resetReturnFocus.isConnected
        ) {
            resetReturnFocus.focus();
        }

        resetReturnFocus = null;

    }

    openButtons.forEach(button => {

        button.addEventListener("click", event => {

            event.preventDefault();

            openResetModal(button);

        });

    });

    closeResetButtons.forEach(button => {

        button.addEventListener(
            "click",
            closeResetModal
        );

    });

    // --------------------------------------------------
    // OPEN PASSWORD VERIFICATION MODAL
    // --------------------------------------------------

    document.addEventListener("click", event => {

        const target = event.target;

        if (!(target instanceof Element)) return;

        const button = target.closest(
            "[data-open-reset-password]"
        );

        if (!button || !resetModal.contains(button)) return;

        const action = button.dataset.resetAction;

        const allowedActions = [
            "reset_permits",
            "reset_student_academic_status"
        ];

        if (!allowedActions.includes(action)) return;

        event.preventDefault();

        passwordReturnFocus = button;

        resetActionInput.value = action;

        resetPasswordFormState();

        resetModal.hidden = true;

        passwordModal.hidden = false;

        updateBodyLock();

        initializeIcons();

        window.setTimeout(() => {

            passwordInput?.focus();

        }, 30);

    });

    // --------------------------------------------------
    // CLOSE PASSWORD MODAL
    // --------------------------------------------------

    function closePasswordModal() {

        if (passwordModal.hidden) return;

        passwordModal.hidden = true;

        resetPasswordFormState();

        // Return to the Reset Data options.

        resetModal.hidden = false;

        updateBodyLock();

        if (
            passwordReturnFocus instanceof HTMLElement &&
            passwordReturnFocus.isConnected
        ) {

            passwordReturnFocus.focus();

        }

        passwordReturnFocus = null;

    }

    closePasswordButtons.forEach(button => {

        button.addEventListener(
            "click",
            closePasswordModal
        );

    });

    document.querySelector(
        "[data-cancel-reset-password]"
    )?.addEventListener("click", closePasswordModal);

    // --------------------------------------------------
    // CONFIRMATION MESSAGES
    // --------------------------------------------------

    const confirmationMessages = {

        reset_permits:

            "RESET ALL PERMIT DATA?\n\n" +

            "All AcadTrack permit documents and submission records " +
            "will be deleted.\n\n" +

            "All permit periods will return to No Permit.\n\n" +

            "This action cannot be undone.",

        reset_student_academic_status:

            "RESET STUDENT ACADEMIC STATUS?\n\n" +

            "All student section assignments will be removed.\n\n" +

            "Only Course, Year Level, and Assigned Section " +
            "will be cleared from student academic information.\n\n" +

            "Student accounts, personal information, uploaded permits, " +
            "sections, and SSP Adviser assignments will remain unchanged.\n\n" +

            "This action cannot be undone."

    };

    // --------------------------------------------------
    // PASSWORD VERIFICATION FORM SUBMISSION
    // --------------------------------------------------

    resetPasswordForm?.addEventListener(
        "submit",
        event => {

            const action = resetActionInput?.value || "";

            const confirmation =
                confirmationMessages[action];

            if (!confirmation) {

                event.preventDefault();

                window.alert("Invalid reset action.");

                return;

            }

            if (!passwordInput?.value) {

                event.preventDefault();

                passwordInput?.focus();

                return;

            }

            if (submitting) {

                event.preventDefault();

                return;

            }

            const confirmed = window.confirm(confirmation);

            if (!confirmed) {

                event.preventDefault();

                return;

            }

            submitting = true;

            if (submitButton) {

                submitButton.disabled = true;

                submitButton.setAttribute(
                    "aria-busy",
                    "true"
                );

                submitButton.innerHTML =
                    '<i data-lucide="loader-circle"></i> Verifying...';

            }

            initializeIcons();

            // Normal POST submission to reset_data.php.
            // PHP verifies the administrator's password.

        }
    );

    // --------------------------------------------------
    // FOCUS TRAP
    // --------------------------------------------------

    function trapFocus(modal, event) {

        if (event.key !== "Tab" || modal.hidden) return;

        const focusable = [
            ...modal.querySelectorAll(
                'button:not([disabled]), ' +
                'input:not([disabled]), ' +
                'select:not([disabled]), ' +
                'textarea:not([disabled]), ' +
                'a[href]'
            )
        ].filter(element => {

            return (
                !element.closest("[hidden]") &&
                element.getClientRects().length > 0
            );

        });

        if (!focusable.length) return;

        const first = focusable[0];

        const last = focusable[focusable.length - 1];

        if (
            event.shiftKey &&
            (
                document.activeElement === first ||
                !modal.contains(document.activeElement)
            )
        ) {

            event.preventDefault();

            last.focus();

        } else if (
            !event.shiftKey &&
            (
                document.activeElement === last ||
                !modal.contains(document.activeElement)
            )
        ) {

            event.preventDefault();

            first.focus();

        }

    }

    resetModal.addEventListener("keydown", event => {
        trapFocus(resetModal, event);
    });

    passwordModal.addEventListener("keydown", event => {
        trapFocus(passwordModal, event);
    });

    // --------------------------------------------------
    // ESCAPE KEY
    // --------------------------------------------------

    document.addEventListener("keydown", event => {

        if (event.key !== "Escape") return;

        if (!passwordModal.hidden) {

            event.preventDefault();

            event.stopPropagation();

            closePasswordModal();

            return;

        }

        if (!resetModal.hidden) {

            event.preventDefault();

            event.stopPropagation();

            closeResetModal();

        }

    }, true);

    // --------------------------------------------------
    // INITIALIZE
    // --------------------------------------------------

    updateBodyLock();

    initializeIcons();

})();
