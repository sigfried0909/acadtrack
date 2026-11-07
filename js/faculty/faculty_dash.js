(() => {
    "use strict";

    const body = document.body;

    const collapseBtn = document.querySelector("[data-collapse-sidebar]");
    const openBtn = document.querySelector("[data-open-sidebar]");
    const closeButtons = document.querySelectorAll("[data-close-sidebar]");

    const mobileQuery = window.matchMedia("(max-width: 820px)");

    let toastTimer = null;
    let filterDebounceTimer = null;
    let activeFilterController = null;
    let filterRequestId = 0;

    // --------------------------------------------------
    // LIVE PHILIPPINE DATE AND TIME (12-HOUR FORMAT)
    // --------------------------------------------------

    function updatePhilippineDateTime() {
        const now = new Date();

        const dateElement = document.getElementById("live-date");
        const clockElement = document.getElementById("live-clock");

        if (!dateElement || !clockElement) return;

        dateElement.textContent = new Intl.DateTimeFormat("en-PH", {
            timeZone: "Asia/Manila",
            weekday: "long",
            year: "numeric",
            month: "long",
            day: "numeric"
        }).format(now);

        clockElement.textContent = new Intl.DateTimeFormat("en-US", {
            timeZone: "Asia/Manila",
            hour: "numeric",
            minute: "2-digit",
            second: "2-digit",
            hour12: true
        }).format(now);
    }

    updatePhilippineDateTime();
    setInterval(updatePhilippineDateTime, 1000);

    // --------------------------------------------------
    // ELEMENT REFERENCES
    // --------------------------------------------------

    const facultyProfileModal = document.getElementById(
        "faculty-profile-modal"
    );

    const facultyProfileForm = document.getElementById(
        "faculty-profile-form"
    );

    const facultyPictureForm = document.getElementById(
        "faculty-picture-form"
    );

    const facultyPictureInput = document.getElementById(
        "faculty-profile-picture-input"
    );

    const facultyPicturePreview = document.getElementById(
        "faculty-picture-preview"
    );

    const facultyPictureFilename = document.getElementById(
        "faculty-profile-picture-filename"
    );

    const facultyUploadPictureButton = document.getElementById(
        "faculty-upload-profile-picture"
    );

    const facultyPasswordModal = document.getElementById(
        "faculty-password-modal"
    );

    const facultyPasswordForm = document.getElementById(
        "faculty-password-form"
    );

    const currentPasswordInput = document.getElementById(
        "faculty-current-password"
    );

    const newPasswordInput = document.getElementById(
        "faculty-new-password"
    );

    const confirmPasswordInput = document.getElementById(
        "faculty-confirm-password"
    );

    const openProfileButtons = document.querySelectorAll(
        "[data-open-profile-modal]"
    );

    const closeProfileButtons = document.querySelectorAll(
        "[data-close-profile-modal]"
    );

    const openPasswordButtons = document.querySelectorAll(
        "[data-open-password-modal]"
    );

    const closePasswordButtons = document.querySelectorAll(
        "[data-close-password-modal]"
    );

    // --------------------------------------------------
    // PROFILE PICTURE ORIGINAL STATE
    // --------------------------------------------------

    const originalPicturePreview =
        facultyPicturePreview?.innerHTML || "";

    const originalPictureFilename =
        facultyPictureFilename?.textContent || "No new picture selected.";

    // --------------------------------------------------
    // MODAL STATE
    // --------------------------------------------------

    let profileReturnFocus = null;
    let passwordReturnFocus = null;

    let profilePicturePreviewUrl = null;

    // --------------------------------------------------
    // GENERAL HELPERS
    // --------------------------------------------------

    function initializeIcons() {
        window.lucide?.createIcons();
    }

    function showToast(message) {

        const toast = document.getElementById("toast");
        const toastMessage = document.getElementById("toast-message");

        if (!toast || !toastMessage) {
            return;
        }

        toastMessage.textContent = message;
        toast.hidden = false;

        window.clearTimeout(toastTimer);

        toastTimer = window.setTimeout(() => {
            toast.hidden = true;
        }, 3000);

        initializeIcons();

    }

    // --------------------------------------------------
    // SIDEBAR
    // --------------------------------------------------

    function closeSidebar() {

        body.classList.remove("mobile-sidebar-open");

        openBtn?.setAttribute("aria-expanded", "false");

    }

    openBtn?.addEventListener("click", () => {

        body.classList.add("mobile-sidebar-open");

        openBtn.setAttribute("aria-expanded", "true");

    });

    closeButtons.forEach(button => {

        button.addEventListener("click", closeSidebar);

    });

    document.querySelector(".mobile-scrim")?.addEventListener(
        "click",
        closeSidebar
    );

    collapseBtn?.addEventListener("click", () => {

        if (mobileQuery.matches) {
            return;
        }

        body.classList.toggle("sidebar-collapsed");

        const collapsed = body.classList.contains("sidebar-collapsed");

        collapseBtn.setAttribute("aria-pressed", String(collapsed));

        collapseBtn.setAttribute(
            "aria-label",
            collapsed ? "Expand sidebar" : "Collapse sidebar"
        );

    });

    document.querySelectorAll("[data-nav-link]").forEach(link => {

        link.addEventListener("click", closeSidebar);

    });

    // --------------------------------------------------
    // DROPDOWNS
    // --------------------------------------------------

    function closeDropdowns(exceptId = null) {

        document.querySelectorAll(".dropdown").forEach(menu => {

            if (menu.id !== exceptId) {
                menu.hidden = true;
            }

        });

        document.querySelectorAll("[data-dropdown-toggle]").forEach(button => {

            if (button.dataset.dropdownToggle !== exceptId) {

                button.setAttribute("aria-expanded", "false");

            }

        });

    }

    document.querySelectorAll("[data-dropdown-toggle]").forEach(button => {

        button.addEventListener("click", event => {

            event.stopPropagation();

            const target = document.getElementById(
                button.dataset.dropdownToggle
            );

            if (!target) {
                return;
            }

            const willOpen = target.hidden;

            closeDropdowns(button.dataset.dropdownToggle);

            target.hidden = !willOpen;

            button.setAttribute("aria-expanded", String(willOpen));

        });

    });

    document.addEventListener("click", event => {

        if (
            !event.target.closest(".dropdown-wrap") &&
            !event.target.closest(".dropdown")
        ) {

            closeDropdowns();

        }

    });

    // --------------------------------------------------
    // MODAL BODY SCROLL LOCK
    // --------------------------------------------------

    function updateModalBodyLock() {

        const profileIsOpen =
            facultyProfileModal &&
            !facultyProfileModal.hidden;

        const passwordIsOpen =
            facultyPasswordModal &&
            !facultyPasswordModal.hidden;

        body.classList.toggle(
            "faculty-profile-modal-open",
            Boolean(profileIsOpen || passwordIsOpen)
        );

    }

    // --------------------------------------------------
    // PROFILE PICTURE RESET
    // --------------------------------------------------

    function resetProfilePictureSelection() {

        if (profilePicturePreviewUrl) {

            URL.revokeObjectURL(profilePicturePreviewUrl);

            profilePicturePreviewUrl = null;

        }

        if (facultyPictureInput) {
            facultyPictureInput.value = "";
        }

        if (facultyPicturePreview) {
            facultyPicturePreview.innerHTML = originalPicturePreview;
        }

        if (facultyPictureFilename) {
            facultyPictureFilename.textContent = originalPictureFilename;
        }

        if (facultyUploadPictureButton) {

            facultyUploadPictureButton.disabled = true;

            delete facultyUploadPictureButton.dataset.submitting;

            facultyUploadPictureButton.innerHTML =
                '<i data-lucide="upload"></i> Upload Picture';

        }

        initializeIcons();

    }

    // --------------------------------------------------
    // PROFILE MODAL FOCUS TRAP
    // --------------------------------------------------

    function trapModalFocus(modal, event) {

        if (
            event.key !== "Tab"
            || !modal
            || modal.hidden
        ) {
            return;
        }

        const focusableElements = [
            ...modal.querySelectorAll(
                'button:not([disabled]):not(.faculty-profile-backdrop), ' +
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

        if (!focusableElements.length) {
            return;
        }

        const first = focusableElements[0];
        const last = focusableElements[focusableElements.length - 1];

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

    // --------------------------------------------------
    // EDIT PROFILE — OPEN
    // --------------------------------------------------

    function openFacultyProfileModal(trigger = null) {

        if (!facultyProfileModal) {
            return;
        }

        profileReturnFocus =
            trigger ||
            document.querySelector(
                '[data-dropdown-toggle="faculty-profile-menu"]'
            ) ||
            document.activeElement;

        closeDropdowns();
        closeSidebar();

        facultyProfileModal.hidden = false;

        updateModalBodyLock();

        initializeIcons();

        window.setTimeout(() => {

            facultyProfileForm?.querySelector(
                'input[name="firstname"]'
            )?.focus();

        }, 30);

    }

    // --------------------------------------------------
    // EDIT PROFILE — CLOSE
    // --------------------------------------------------

    function closeFacultyProfileModal() {

        if (!facultyProfileModal) {
            return;
        }

        facultyProfileModal.hidden = true;

        updateModalBodyLock();

        resetProfilePictureSelection();

        if (
            profileReturnFocus instanceof HTMLElement &&
            profileReturnFocus.isConnected
        ) {

            profileReturnFocus.focus();

        }

        profileReturnFocus = null;

    }

    openProfileButtons.forEach(button => {

        button.addEventListener("click", event => {

            event.preventDefault();

            openFacultyProfileModal(button);

        });

    });

    closeProfileButtons.forEach(button => {

        button.addEventListener("click", closeFacultyProfileModal);

    });

    facultyProfileModal?.addEventListener("keydown", event => {

        trapModalFocus(facultyProfileModal, event);

    });

    // --------------------------------------------------
    // PROFILE FORM CONFIRMATION
    // --------------------------------------------------

    facultyProfileForm?.addEventListener("submit", event => {

        const confirmed = window.confirm(
            "Are you sure you want to save your profile changes?"
        );

        if (!confirmed) {
            event.preventDefault();
        }

    });

    // --------------------------------------------------
    // PROFILE MODAL INITIAL STATE
    // --------------------------------------------------

    if (
        facultyProfileModal &&
        !facultyProfileModal.hidden
    ) {

        updateModalBodyLock();

        profileReturnFocus = document.querySelector(
            '[data-dropdown-toggle="faculty-profile-menu"]'
        );

        window.setTimeout(() => {

            facultyProfileForm?.querySelector(
                'input[name="firstname"]'
            )?.focus();

        }, 30);

    }

    // --------------------------------------------------
    // PROFILE PICTURE — PREVIEW
    // --------------------------------------------------

    facultyPictureInput?.addEventListener("change", () => {

        const file = facultyPictureInput.files?.[0];

        if (!file) {

            resetProfilePictureSelection();

            return;

        }

        const maximumFileSize = 2 * 1024 * 1024;

        const allowedTypes = [
            "image/jpeg",
            "image/png",
            "image/webp"
        ];

        if (file.size <= 0) {

            facultyPictureInput.value = "";

            resetProfilePictureSelection();

            showToast("Please select a valid image.");

            return;

        }

        if (file.size > maximumFileSize) {

            facultyPictureInput.value = "";

            resetProfilePictureSelection();

            showToast("Your profile picture must not exceed 2 MB.");

            return;

        }

        if (!allowedTypes.includes(file.type)) {

            facultyPictureInput.value = "";

            resetProfilePictureSelection();

            showToast("Please select a JPG, PNG, or WEBP image.");

            return;

        }

        if (profilePicturePreviewUrl) {

            URL.revokeObjectURL(profilePicturePreviewUrl);

            profilePicturePreviewUrl = null;

        }

        profilePicturePreviewUrl = URL.createObjectURL(file);

        if (facultyPicturePreview) {

            const image = document.createElement("img");

            image.src = profilePicturePreviewUrl;
            image.alt = "Selected faculty profile picture preview";

            facultyPicturePreview.replaceChildren(image);

        }

        if (facultyPictureFilename) {
            facultyPictureFilename.textContent = file.name;
        }

        if (facultyUploadPictureButton) {
            facultyUploadPictureButton.disabled = false;
        }

    });

    // --------------------------------------------------
    // PROFILE PICTURE — UPLOAD
    // --------------------------------------------------

    facultyPictureForm?.addEventListener("submit", event => {

        const file = facultyPictureInput?.files?.[0];

        if (!file) {

            event.preventDefault();

            showToast("Please select a profile picture first.");

            return;

        }

        if (
            facultyUploadPictureButton?.dataset.submitting === "true"
        ) {

            event.preventDefault();

            return;

        }

        const maximumFileSize = 2 * 1024 * 1024;

        const allowedTypes = [
            "image/jpeg",
            "image/png",
            "image/webp"
        ];

        if (
            file.size <= 0 ||
            file.size > maximumFileSize ||
            !allowedTypes.includes(file.type)
        ) {

            event.preventDefault();

            showToast(
                "Please select a valid JPG, PNG, or WEBP image up to 2 MB."
            );

            return;

        }

        if (facultyUploadPictureButton) {

            facultyUploadPictureButton.dataset.submitting = "true";

            facultyUploadPictureButton.disabled = true;

            facultyUploadPictureButton.innerHTML =
                '<i data-lucide="loader-circle"></i> Uploading...';

            initializeIcons();

        }

    });

    // --------------------------------------------------
    // ACCOUNT SETTINGS — OPEN PASSWORD MODAL
    // --------------------------------------------------

    function openFacultyPasswordModal(trigger = null) {

        if (!facultyPasswordModal) {
            return;
        }

        passwordReturnFocus =
            trigger ||
            document.querySelector(
                '[data-dropdown-toggle="faculty-profile-menu"]'
            ) ||
            document.activeElement;

        closeDropdowns();
        closeSidebar();

        facultyPasswordModal.hidden = false;

        updateModalBodyLock();

        initializeIcons();

        window.setTimeout(() => {

            currentPasswordInput?.focus();

        }, 30);

    }

    // --------------------------------------------------
    // ACCOUNT SETTINGS — CLOSE PASSWORD MODAL
    // --------------------------------------------------

    function closeFacultyPasswordModal() {

        if (!facultyPasswordModal) {
            return;
        }

        facultyPasswordModal.hidden = true;

        updateModalBodyLock();

        facultyPasswordForm?.reset();

        facultyPasswordForm?.querySelectorAll("input").forEach(input => {

            input.setCustomValidity("");

            if (input.type === "text") {
                input.type = "password";
            }

        });

        const passwordLabels = {

            "faculty-current-password": "Show current password",

            "faculty-new-password": "Show new password",

            "faculty-confirm-password": "Show confirmation password"

        };

        facultyPasswordForm?.querySelectorAll(
            "[data-toggle-password]"
        ).forEach(button => {

            const inputId = button.dataset.togglePassword;

            button.setAttribute("aria-pressed", "false");

            button.setAttribute(
                "aria-label",
                passwordLabels[inputId] || "Show password"
            );

            button.innerHTML = '<i data-lucide="eye"></i>';

        });

        initializeIcons();

        if (
            passwordReturnFocus instanceof HTMLElement &&
            passwordReturnFocus.isConnected
        ) {

            passwordReturnFocus.focus();

        }

        passwordReturnFocus = null;

    }

    // --------------------------------------------------
    // OPEN PASSWORD MODAL
    // --------------------------------------------------

    openPasswordButtons.forEach(button => {

        button.addEventListener("click", event => {

            event.preventDefault();

            openFacultyPasswordModal(button);

        });

    });

    // --------------------------------------------------
    // CLOSE PASSWORD MODAL
    // --------------------------------------------------

    closePasswordButtons.forEach(button => {

        button.addEventListener("click", closeFacultyPasswordModal);

    });

    // --------------------------------------------------
    // PASSWORD MODAL INITIAL STATE
    // --------------------------------------------------

    if (
        facultyPasswordModal &&
        !facultyPasswordModal.hidden
    ) {

        updateModalBodyLock();

        passwordReturnFocus = document.querySelector(
            '[data-dropdown-toggle="faculty-profile-menu"]'
        );

        window.setTimeout(() => {

            currentPasswordInput?.focus();

        }, 30);

    }

    facultyPasswordModal?.addEventListener("keydown", event => {

        trapModalFocus(facultyPasswordModal, event);

    });

    // --------------------------------------------------
    // PASSWORD — SHOW AND HIDE
    // --------------------------------------------------

    facultyPasswordForm?.querySelectorAll(
        "[data-toggle-password]"
    ).forEach(button => {

        button.addEventListener("click", () => {

            const inputId = button.dataset.togglePassword;

            const input = document.getElementById(inputId);

            if (!input) {
                return;
            }

            const isVisible = input.type === "text";

            input.type = isVisible ? "password" : "text";

            button.setAttribute(
                "aria-pressed",
                String(!isVisible)
            );

            const labels = {

                "faculty-current-password": isVisible
                    ? "Show current password"
                    : "Hide current password",

                "faculty-new-password": isVisible
                    ? "Show new password"
                    : "Hide new password",

                "faculty-confirm-password": isVisible
                    ? "Show confirmation password"
                    : "Hide confirmation password"

            };

            button.setAttribute(
                "aria-label",
                labels[inputId] || "Toggle password visibility"
            );

            button.innerHTML = isVisible
                ? '<i data-lucide="eye"></i>'
                : '<i data-lucide="eye-off"></i>';

            initializeIcons();

        });

    });

    // --------------------------------------------------
    // PASSWORD VALIDATION
    // --------------------------------------------------

    function validateFacultyPasswords() {

        if (
            !currentPasswordInput ||
            !newPasswordInput ||
            !confirmPasswordInput
        ) {
            return true;
        }

        if (
            currentPasswordInput.value !== "" &&
            newPasswordInput.value !== "" &&
            currentPasswordInput.value === newPasswordInput.value
        ) {

            newPasswordInput.setCustomValidity(
                "Your new password must be different from your current password."
            );

        } else {

            newPasswordInput.setCustomValidity("");

        }

        if (
            confirmPasswordInput.value !== "" &&
            newPasswordInput.value !== confirmPasswordInput.value
        ) {

            confirmPasswordInput.setCustomValidity(
                "The confirmation password does not match."
            );

        } else {

            confirmPasswordInput.setCustomValidity("");

        }

        return facultyPasswordForm?.checkValidity() ?? true;

    }

    currentPasswordInput?.addEventListener(
        "input",
        validateFacultyPasswords
    );

    newPasswordInput?.addEventListener(
        "input",
        validateFacultyPasswords
    );

    confirmPasswordInput?.addEventListener(
        "input",
        validateFacultyPasswords
    );

    // --------------------------------------------------
    // PASSWORD UPDATE CONFIRMATION
    // --------------------------------------------------

    facultyPasswordForm?.addEventListener("submit", event => {

        validateFacultyPasswords();

        if (!facultyPasswordForm.checkValidity()) {

            event.preventDefault();

            facultyPasswordForm.reportValidity();

            return;

        }

        const confirmed = window.confirm(
            "Are you sure you want to change your password?\n\n" +
            "You will need to use your new password the next time you sign in."
        );

        if (!confirmed) {

            event.preventDefault();

            return;

        }

    });

    // --------------------------------------------------
    // TOAST NOTIFICATIONS
    // --------------------------------------------------

    document.querySelectorAll("[data-toast]").forEach(element => {

        element.addEventListener("click", event => {

            event.preventDefault();

            showToast(element.dataset.toast);

            closeDropdowns();

        });

    });

    // --------------------------------------------------
    // LIVE STUDENT SEARCH AND FILTERS
    // --------------------------------------------------

    const filterForm = document.querySelector(".faculty-filter-form");

    const searchInput = filterForm?.querySelector('[name="q"]');

    const statusSelect = filterForm?.querySelector('[name="status"]');

    const paymentSelect = filterForm?.querySelector('[name="payment"]');

    async function refreshPermitResults() {

        if (
            !filterForm ||
            !searchInput ||
            !statusSelect ||
            !paymentSelect
        ) {
            return;
        }

        const requestId = ++filterRequestId;

        activeFilterController?.abort();

        const controller = new AbortController();

        activeFilterController = controller;

        const panel = document.querySelector(".faculty-review-panel");

        const url = new URL(
            filterForm.action,
            window.location.href
        );

        const params = new URLSearchParams();

        for (const [key, value] of new FormData(filterForm).entries()) {

            params.set(key, String(value));

        }

        url.search = params.toString();

        url.hash = "reviews";

        panel?.setAttribute("aria-busy", "true");

        try {

            const response = await fetch(url.href, {

                method: "GET",

                credentials: "same-origin",

                cache: "no-store",

                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    "Accept": "text/html"
                },

                signal: controller.signal

            });

            if (!response.ok) {

                throw new Error(
                    `Request failed: ${response.status}`
                );

            }

            const html = await response.text();

            const parsed = new DOMParser().parseFromString(
                html,
                "text/html"
            );

            const newPanel = parsed.querySelector(
                ".faculty-review-panel"
            );

            const newTable = newPanel?.querySelector(
                ".faculty-table-wrap"
            );

            const currentPanel = document.querySelector(
                ".faculty-review-panel"
            );

            const currentTable = currentPanel?.querySelector(
                ".faculty-table-wrap"
            );

            if (
                !newPanel ||
                !newTable ||
                !currentPanel ||
                !currentTable
            ) {

                window.location.assign(url.href);

                return;

            }

            if (requestId !== filterRequestId) {
                return;
            }

            // UPDATE STUDENT TABLE.

            currentTable.innerHTML = newTable.innerHTML;

            // UPDATE DISPLAYED STUDENT COUNT.

            const newCount = newPanel.querySelector(
                ".faculty-review-heading .admin-count"
            );

            const currentCount = currentPanel.querySelector(
                ".faculty-review-heading .admin-count"
            );

            if (newCount && currentCount) {

                currentCount.textContent =
                    newCount.textContent.trim();

            }

            // UPDATE RESULTS NOTICE.

            const newNotice = newPanel.querySelector(
                ".faculty-results-note"
            );

            const currentNotice = currentPanel.querySelector(
                ".faculty-results-note"
            );

            if (currentNotice && newNotice) {

                currentNotice.replaceWith(newNotice);

            } else if (currentNotice) {

                currentNotice.remove();

            } else if (newNotice) {

                currentPanel.appendChild(newNotice);

            }

            // UPDATE BROWSER URL WITHOUT RELOADING.

            window.history.replaceState(
                null,
                "",
                url.pathname + url.search + "#reviews"
            );

            initializeIcons();

        } catch (error) {

            if (error.name === "AbortError") {
                return;
            }

            console.error(
                "AcadTrack live filter error:",
                error
            );

            window.location.assign(url.href);

        } finally {

            if (requestId === filterRequestId) {

                panel?.removeAttribute("aria-busy");

                activeFilterController = null;

            }

        }

    }

    if (
        filterForm &&
        searchInput &&
        statusSelect &&
        paymentSelect
    ) {

        filterForm.addEventListener("submit", event => {

            event.preventDefault();

            window.clearTimeout(filterDebounceTimer);

            refreshPermitResults();

        });

        searchInput.addEventListener("input", () => {

            window.clearTimeout(filterDebounceTimer);

            filterDebounceTimer = window.setTimeout(() => {

                refreshPermitResults();

            }, 300);

        });

        statusSelect.addEventListener("change", () => {

            window.clearTimeout(filterDebounceTimer);

            refreshPermitResults();

        });

        paymentSelect.addEventListener("change", () => {

            window.clearTimeout(filterDebounceTimer);

            refreshPermitResults();

        });

    }


    // --------------------------------------------------
    // FACULTY EXPORT — LIBRARY LOADER
    // --------------------------------------------------

    const facultyExportLibraries = new Map();

    const facultyExportLibraryUrls = {

        excel:
            "https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js",

        word:
            "https://unpkg.com/docx@9.15.1/dist/index.iife.js"

    };

    function loadFacultyExportLibrary(url, globalName) {

        if (window[globalName]) {
            return Promise.resolve(window[globalName]);
        }

        if (facultyExportLibraries.has(url)) {
            return facultyExportLibraries.get(url);
        }

        const promise = new Promise((resolve, reject) => {

            const script = document.createElement("script");

            script.src = url;
            script.async = true;

            script.onload = () => {

                if (window[globalName]) {

                    resolve(window[globalName]);

                } else {

                    reject(
                        new Error("The export library could not be initialized.")
                    );

                }

            };

            script.onerror = () => {

                reject(
                    new Error(
                        "Unable to load the export library. Check your internet connection."
                    )
                );

            };

            document.head.appendChild(script);

        });

        facultyExportLibraries.set(url, promise);

        return promise.catch(error => {

            facultyExportLibraries.delete(url);

            throw error;

        });

    }

    // --------------------------------------------------
    // FACULTY EXPORT — GENERAL HELPERS
    // --------------------------------------------------

    function getFacultyExportTimestamp() {

        return new Intl.DateTimeFormat("en-PH", {

            timeZone: "Asia/Manila",

            dateStyle: "long",

            timeStyle: "short"

        }).format(new Date());

    }

    function escapeFacultyExportHTML(value) {

        const characters = {

            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#039;"

        };

        return String(value ?? "").replace(
            /[&<>"']/g,
            character => characters[character]
        );

    }

    function sanitizeFacultyExportFilename(value) {

        return String(value || "")

            .normalize("NFKD")

            .replace(/[\u0300-\u036f]/g, "")

            .replace(/[^a-zA-Z0-9]+/g, "-")

            .replace(/^-+|-+$/g, "")

            .toLowerCase();

    }

    function getFacultyExportFilename(data) {

        const section = sanitizeFacultyExportFilename(
            data.sectionName
        );

        return `acadtrack-${section || "faculty-section"}-permit-report`;

    }

    function getFacultyFilterLabel(selectElement) {

        if (!selectElement) {
            return "All";
        }

        return selectElement.selectedOptions[0]?.textContent.trim()
            || "All";

    }

    // --------------------------------------------------
    // COLLECT CURRENT FACULTY REPORT DATA
    // --------------------------------------------------

    function collectFacultyExportData() {

        const panel = document.querySelector(
            ".faculty-review-panel"
        );

        if (!panel) {

            throw new Error(
                "The faculty permit report is not available."
            );

        }

        const filterForm = document.querySelector(
            ".faculty-filter-form"
        );

        const searchInput = filterForm?.querySelector(
            '[name="q"]'
        );

        const statusSelect = filterForm?.querySelector(
            '[name="status"]'
        );

        const paymentSelect = filterForm?.querySelector(
            '[name="payment"]'
        );

        const sectionElement = panel.querySelector(
            ".faculty-review-heading p strong"
        );

        const sectionName =
            sectionElement?.textContent.trim() || "Unknown Section";

        const table = panel.querySelector(
            ".faculty-submission-table"
        );

        // READ ONLY THE ROWS CURRENTLY DISPLAYED IN THE TABLE.

        const students = table
            ? [
                ...table.querySelectorAll("tbody tr")
            ].map(row => {

                const cells = [...row.querySelectorAll("td")];

                if (cells.length < 6) {
                    return null;
                }

                const fileCell = cells[3];

                const paymentCell = cells[4];

                const dateCell = cells[5];

                const actionCell = cells[6];

                // UPLOADED FILE.

                const fileLink = fileCell?.querySelector(
                    ".faculty-file-link span"
                );

                const noFile = fileCell?.querySelector(
                    ".faculty-no-file"
                );

                const uploadedFile = fileLink?.textContent.trim()
                    || noFile?.textContent.trim()
                    || "No file uploaded";

                // PAYMENT TYPE.

                const payment = paymentCell?.querySelector(
                    ".faculty-payment-label"
                )?.textContent.trim()
                    || paymentCell?.textContent.trim()
                    || "—";

                // REVIEW STATUS.

                const statusBadge = actionCell?.querySelector(
                    ".faculty-status"
                );

                const reviewForm = actionCell?.querySelector(
                    ".faculty-review-form"
                );

                const noSubmission = actionCell?.querySelector(
                    ".faculty-no-file"
                );

                let reviewStatus = "—";

                if (statusBadge) {

                    reviewStatus = statusBadge.textContent.trim();

                } else if (reviewForm) {

                    reviewStatus = "Pending";

                } else if (noSubmission) {

                    reviewStatus = "No submission";

                }

                return {

                    studentNumber:
                        cells[0]?.textContent.trim() || "—",

                    firstname:
                        cells[1]?.textContent.trim() || "—",

                    lastname:
                        cells[2]?.textContent.trim() || "—",

                    uploadedFile,

                    payment,

                    dateSubmitted:
                        dateCell?.textContent.trim() || "—",

                    reviewStatus

                };

            }).filter(Boolean)

            : [];

        return {

            sectionName,

            searchQuery: searchInput?.value.trim() || "",

            statusFilter: getFacultyFilterLabel(statusSelect),

            paymentFilter: getFacultyFilterLabel(paymentSelect),

            sectionId:
                filterForm?.querySelector('[name="section_id"]')?.value || "",

            totalStudents: students.length,

            students,

            generatedAt: getFacultyExportTimestamp()

        };

    }

    // --------------------------------------------------
    // EXPORT AS PDF
    // --------------------------------------------------

    function exportFacultyToPDF(data) {

        // OPEN THE WINDOW IMMEDIATELY TO AVOID POPUP BLOCKING.

        const printWindow = window.open("", "_blank");

        if (!printWindow) {

            throw new Error(
                "The PDF window was blocked. Please allow pop-ups for AcadTrack."
            );

        }

        const filename = getFacultyExportFilename(data);

        const tableRows = data.students.length

            ? data.students.map(student => `

                <tr>

                    <td>${escapeFacultyExportHTML(student.studentNumber)}</td>

                    <td>${escapeFacultyExportHTML(student.firstname)}</td>

                    <td>${escapeFacultyExportHTML(student.lastname)}</td>

                    <td>${escapeFacultyExportHTML(student.uploadedFile)}</td>

                    <td>${escapeFacultyExportHTML(student.payment)}</td>

                    <td>${escapeFacultyExportHTML(student.dateSubmitted)}</td>

                    <td>${escapeFacultyExportHTML(student.reviewStatus)}</td>

                </tr>

            `).join("")

            : `

                <tr>

                    <td colspan="7" class="empty-row">
                        No student records match the selected filters.
                    </td>

                </tr>

            `;

        const html = `

            <!DOCTYPE html>

            <html lang="en">

            <head>

                <meta charset="UTF-8">

                <meta name="viewport" content="width=device-width, initial-scale=1.0">

                <title>${escapeFacultyExportHTML(data.sectionName)} - Permit Report</title>

                <style>

                    * {
                        box-sizing: border-box;
                    }

                    body {
                        margin: 0;
                        padding: 30px;
                        color: #101828;
                        background: #fff;
                        font-family: Arial, Helvetica, sans-serif;
                        font-size: 11px;
                    }

                    .report-header {
                        padding-bottom: 18px;
                        border-bottom: 2px solid #465fff;
                        margin-bottom: 20px;
                    }

                    .report-header h1 {
                        margin: 0;
                        font-size: 23px;
                        font-weight: 700;
                    }

                    .report-header p {
                        margin: 6px 0 0;
                        color: #667085;
                        font-size: 11px;
                    }

                    .report-subtitle {
                        margin-top: 15px;
                        color: #465fff;
                        font-size: 12px;
                        font-weight: 700;
                    }

                    .report-details {
                        display: grid;
                        grid-template-columns: repeat(3, minmax(0, 1fr));
                        gap: 10px;
                        margin-bottom: 20px;
                    }

                    .detail-item {
                        min-width: 0;
                        padding: 11px;
                        border: 1px solid #eaecf0;
                        border-radius: 7px;
                    }

                    .detail-item span {
                        display: block;
                        margin-bottom: 5px;
                        color: #667085;
                        font-size: 9px;
                    }

                    .detail-item strong {
                        display: block;
                        color: #101828;
                        font-size: 11px;
                        overflow-wrap: anywhere;
                    }

                    .table-heading {
                        margin: 0 0 12px;
                        font-size: 14px;
                        font-weight: 700;
                    }

                    table {
                        width: 100%;
                        border-collapse: collapse;
                        table-layout: fixed;
                    }

                    th {
                        padding: 9px 6px;
                        border: 1px solid #d0d5dd;
                        background: #f2f4f7;
                        color: #344054;
                        font-size: 8px;
                        text-align: left;
                    }

                    td {
                        padding: 8px 6px;
                        border: 1px solid #eaecf0;
                        color: #344054;
                        font-size: 8px;
                        line-height: 1.5;
                        overflow-wrap: anywhere;
                        vertical-align: top;
                    }

                    tbody tr:nth-child(even) {
                        background: #f9fafb;
                    }

                    .empty-row {
                        padding: 20px;
                        text-align: center;
                        color: #667085;
                    }

                    .report-footer {
                        margin-top: 25px;
                        padding-top: 12px;
                        border-top: 1px solid #eaecf0;
                        color: #667085;
                        font-size: 9px;
                        text-align: center;
                    }

                    @page {
                        size: A4 landscape;
                        margin: 12mm;
                    }

                    @media print {

                        body {
                            padding: 0;
                        }

                        thead {
                            display: table-header-group;
                        }

                        tr {
                            page-break-inside: avoid;
                        }

                    }

                </style>

            </head>

            <body>

                <div class="report-header">

                    <h1>AcadTrack</h1>

                    <p>
                        Student Permit Monitoring and Academic Tracking System
                    </p>

                    <div class="report-subtitle">
                        FACULTY PERMIT SUBMISSION REPORT
                    </div>

                </div>

                <div class="report-details">

                    <div class="detail-item">

                        <span>Section</span>

                        <strong>${escapeFacultyExportHTML(data.sectionName)}</strong>

                    </div>

                    <div class="detail-item">

                        <span>Permit Status Filter</span>

                        <strong>${escapeFacultyExportHTML(data.statusFilter)}</strong>

                    </div>

                    <div class="detail-item">

                        <span>Payment Filter</span>

                        <strong>${escapeFacultyExportHTML(data.paymentFilter)}</strong>

                    </div>

                    <div class="detail-item">

                        <span>Search Query</span>

                        <strong>${escapeFacultyExportHTML(data.searchQuery || "None")}</strong>

                    </div>

                    <div class="detail-item">

                        <span>Displayed Students</span>

                        <strong>${data.totalStudents}</strong>

                    </div>

                    <div class="detail-item">

                        <span>Generated At</span>

                        <strong>${escapeFacultyExportHTML(data.generatedAt)}</strong>

                    </div>

                </div>

                <h2 class="table-heading">
                    Student Permit Submissions
                </h2>

                <table>

                    <thead>

                        <tr>

                            <th>Student Number</th>

                            <th>Firstname</th>

                            <th>Lastname</th>

                            <th>Uploaded File</th>

                            <th>Payment</th>

                            <th>Date Submitted</th>

                            <th>Review Status</th>

                        </tr>

                    </thead>

                    <tbody>

                        ${tableRows}

                    </tbody>

                </table>

                <div class="report-footer">

                    AcadTrack | Saint Jude College - PHINMA Education

                </div>

            </body>

            </html>

        `;

        printWindow.document.open();

        printWindow.document.write(html);

        printWindow.document.close();

        window.setTimeout(() => {

            if (!printWindow.closed) {

                printWindow.document.title = filename;

                printWindow.focus();

                printWindow.print();

            }

        }, 400);

        showToast(
            "Permit report prepared. Select Save as PDF in the print dialog."
        );

    }

    // --------------------------------------------------
    // EXPORT AS EXCEL (.XLSX)
    // --------------------------------------------------

    async function exportFacultyToExcel(data) {

        const XLSX = await loadFacultyExportLibrary(

            facultyExportLibraryUrls.excel,

            "XLSX"

        );

        const filename = getFacultyExportFilename(data);

        // SUMMARY WORKSHEET.

        const summaryData = [

            ["ACADTRACK - FACULTY PERMIT REPORT"],

            ["Generated At", data.generatedAt],

            [],

            ["REPORT DETAILS", "INFORMATION"],

            ["Section", data.sectionName],

            ["Permit Status Filter", data.statusFilter],

            ["Payment Filter", data.paymentFilter],

            ["Search Query", data.searchQuery || "None"],

            ["Displayed Students", data.totalStudents]

        ];

        const summarySheet = XLSX.utils.aoa_to_sheet(
            summaryData
        );

        summarySheet["!cols"] = [

            { wch: 28 },

            { wch: 40 }

        ];

        // PERMIT SUBMISSIONS WORKSHEET.

        const headers = [

            "Student Number",

            "Firstname",

            "Lastname",

            "Uploaded File",

            "Payment",

            "Date Submitted",

            "Review Status"

        ];

        const rows = data.students.map(student => [

            student.studentNumber,

            student.firstname,

            student.lastname,

            student.uploadedFile,

            student.payment,

            student.dateSubmitted,

            student.reviewStatus

        ]);

        const submissionsSheet = XLSX.utils.aoa_to_sheet([

            headers,

            ...rows

        ]);

        submissionsSheet["!cols"] = [

            { wch: 19 },

            { wch: 18 },

            { wch: 20 },

            { wch: 35 },

            { wch: 23 },

            { wch: 24 },

            { wch: 18 }

        ];

        submissionsSheet["!autofilter"] = {

            ref: XLSX.utils.encode_range({

                s: { r: 0, c: 0 },

                e: {

                    r: Math.max(rows.length, 1),

                    c: headers.length - 1

                }

            })

        };

        // CREATE WORKBOOK.

        const workbook = XLSX.utils.book_new();

        XLSX.utils.book_append_sheet(

            workbook,

            summarySheet,

            "Report Summary"

        );

        XLSX.utils.book_append_sheet(

            workbook,

            submissionsSheet,

            "Permit Submissions"

        );

        workbook.Props = {

            Title: `AcadTrack - ${data.sectionName} Permit Report`,

            Subject: "Faculty Permit Submission Report",

            Author: "AcadTrack",

            Company: "Saint Jude College - PHINMA Education"

        };

        XLSX.writeFile(

            workbook,

            `${filename}.xlsx`,

            { compression: true }

        );

        showToast(
            "Excel permit report exported successfully."
        );

    }

    // --------------------------------------------------
    // EXPORT AS WORD (.DOCX)
    // --------------------------------------------------

    async function exportFacultyToWord(data) {

        const docx = await loadFacultyExportLibrary(

            facultyExportLibraryUrls.word,

            "docx"

        );

        const filename = getFacultyExportFilename(data);

        const {

            Document,

            Paragraph,

            TextRun,

            Table,

            TableRow,

            TableCell,

            Packer

        } = docx;

        // WORD TABLE CELL.

        function createWordCell(value, bold = false) {

            return new TableCell({

                children: [

                    new Paragraph({

                        children: [

                            new TextRun({

                                text: String(value ?? ""),

                                bold,

                                size: 17

                            })

                        ]

                    })

                ]

            });

        }

        // DOCUMENT HEADER.

        const children = [

            new Paragraph({

                children: [

                    new TextRun({

                        text: "AcadTrack",

                        bold: true,

                        size: 36,

                        color: "465FFF"

                    })

                ],

                spacing: {

                    after: 100

                }

            }),

            new Paragraph({

                children: [

                    new TextRun({

                        text: "Student Permit Monitoring and Academic Tracking System",

                        size: 19,

                        color: "667085"

                    })

                ],

                spacing: {

                    after: 300

                }

            }),

            new Paragraph({

                children: [

                    new TextRun({

                        text: "FACULTY PERMIT SUBMISSION REPORT",

                        bold: true,

                        size: 27

                    })

                ],

                spacing: {

                    before: 150,

                    after: 250

                }

            })

        ];

        // REPORT INFORMATION.

        const metadata = [

            ["Section", data.sectionName],

            ["Permit Status Filter", data.statusFilter],

            ["Payment Filter", data.paymentFilter],

            ["Search Query", data.searchQuery || "None"],

            ["Displayed Students", String(data.totalStudents)],

            ["Generated At", data.generatedAt]

        ];

        metadata.forEach(([label, value]) => {

            children.push(

                new Paragraph({

                    children: [

                        new TextRun({

                            text: `${label}: `,

                            bold: true,

                            size: 20

                        }),

                        new TextRun({

                            text: String(value),

                            size: 20

                        })

                    ],

                    spacing: {

                        after: 100

                    }

                })

            );

        });

        // TABLE HEADING.

        children.push(

            new Paragraph({

                children: [

                    new TextRun({

                        text: "Student Permit Submissions",

                        bold: true,

                        size: 24

                    })

                ],

                spacing: {

                    before: 300,

                    after: 180

                }

            })

        );

        // TABLE HEADERS.

        const headers = [

            "Student Number",

            "Firstname",

            "Lastname",

            "Uploaded File",

            "Payment",

            "Date Submitted",

            "Review Status"

        ];

        const tableRows = [

            new TableRow({

                children: headers.map(header => {

                    return createWordCell(header, true);

                })

            })

        ];

        // STUDENT RECORDS.

        if (data.students.length > 0) {

            data.students.forEach(student => {

                tableRows.push(

                    new TableRow({

                        children: [

                            createWordCell(student.studentNumber),

                            createWordCell(student.firstname),

                            createWordCell(student.lastname),

                            createWordCell(student.uploadedFile),

                            createWordCell(student.payment),

                            createWordCell(student.dateSubmitted),

                            createWordCell(student.reviewStatus)

                        ]

                    })

                );

            });

        } else {

            tableRows.push(

                new TableRow({

                    children: [

                        createWordCell("No matching student records."),

                        createWordCell(""),

                        createWordCell(""),

                        createWordCell(""),

                        createWordCell(""),

                        createWordCell(""),

                        createWordCell("")

                    ]

                })

            );

        }

        children.push(

            new Table({

                rows: tableRows

            })

        );

        // FOOTER.

        children.push(

            new Paragraph({

                children: [

                    new TextRun({

                        text: "AcadTrack | Saint Jude College - PHINMA Education",

                        size: 16,

                        color: "667085"

                    })

                ],

                spacing: {

                    before: 300

                }

            })

        );

        // BUILD DOCUMENT.

        const wordDocument = new Document({

            sections: [

                {

                    properties: {

                        page: {

                            size: {

                                width: 15840,

                                height: 12240

                            },

                            margin: {

                                top: 720,

                                right: 720,

                                bottom: 720,

                                left: 720

                            }

                        }

                    },

                    children

                }

            ]

        });

        const blob = await Packer.toBlob(wordDocument);

        // DOWNLOAD.

        const url = URL.createObjectURL(blob);

        const link = document.createElement("a");

        link.href = url;

        link.download = `${filename}.docx`;

        link.style.display = "none";

        document.body.appendChild(link);

        link.click();

        link.remove();

        window.setTimeout(() => {

            URL.revokeObjectURL(url);

        }, 1000);

        showToast(
            "Word permit report exported successfully."
        );

    }

    // --------------------------------------------------
    // FACULTY EXPORT — FORMAT HANDLER
    // --------------------------------------------------

    async function exportFacultyReport(format) {

        const data = collectFacultyExportData();

        switch (format) {

            case "pdf":

                // Keep this synchronous so the browser recognizes
                // the action as a direct user-initiated popup.

                exportFacultyToPDF(data);

                break;

            case "excel":

                await exportFacultyToExcel(data);

                break;

            case "word":

                await exportFacultyToWord(data);

                break;

            default:

                throw new Error("Invalid export format.");

        }

    }

    // --------------------------------------------------
    // FACULTY EXPORT — CLOSE DROPDOWNS
    // --------------------------------------------------

    function closeFacultyExportMenus(exceptWrap = null) {

        document.querySelectorAll(
            ".faculty-export-wrap"
        ).forEach(wrap => {

            if (wrap === exceptWrap) {
                return;
            }

            const menu = wrap.querySelector(
                ".faculty-export-menu"
            );

            const toggle = wrap.querySelector(
                "[data-faculty-export-toggle]"
            );

            if (menu) {
                menu.hidden = true;
            }

            toggle?.setAttribute(
                "aria-expanded",
                "false"
            );

        });

    }

    // --------------------------------------------------
    // FACULTY EXPORT — EVENT HANDLING
    // Delegated so it continues working with live filters.
    // --------------------------------------------------

    document.addEventListener("click", async event => {

        const target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        // TOGGLE EXPORT MENU.

        const toggle = target.closest(
            "[data-faculty-export-toggle]"
        );

        if (toggle) {

            event.preventDefault();

            const wrap = toggle.closest(
                ".faculty-export-wrap"
            );

            const menu = wrap?.querySelector(
                ".faculty-export-menu"
            );

            if (!wrap || !menu) {
                return;
            }

            const shouldOpen = menu.hidden;

            closeFacultyExportMenus(wrap);

            menu.hidden = !shouldOpen;

            toggle.setAttribute(
                "aria-expanded",
                String(shouldOpen)
            );

            return;

        }

        // SELECT EXPORT FORMAT.

        const option = target.closest(
            "[data-faculty-export]"
        );

        if (option) {

            event.preventDefault();

            if (option.disabled) {
                return;
            }

            const format = option.dataset.facultyExport;

            // PDF IS OPENED BEFORE ANY ASYNC OPERATION.

            if (format === "pdf") {

                closeFacultyExportMenus();

                try {

                    await exportFacultyReport("pdf");

                } catch (error) {

                    console.error(
                        "AcadTrack faculty PDF export error:",
                        error
                    );

                    showToast(
                        error instanceof Error
                            ? error.message
                            : "Unable to export the PDF report."
                    );

                }

                return;

            }

            closeFacultyExportMenus();

            option.disabled = true;

            try {

                await exportFacultyReport(format);

            } catch (error) {

                console.error(
                    "AcadTrack faculty export error:",
                    error
                );

                showToast(

                    error instanceof Error

                        ? error.message

                        : "Unable to export the report. Please try again."

                );

            } finally {

                option.disabled = false;

            }

            return;

        }

        // CLOSE WHEN CLICKING OUTSIDE.

        if (!target.closest(".faculty-export-wrap")) {

            closeFacultyExportMenus();

        }

    });

    // --------------------------------------------------
    // FACULTY EXPORT — ESCAPE KEY
    // --------------------------------------------------

    document.addEventListener("keydown", event => {

        if (event.key === "Escape") {

            closeFacultyExportMenus();

        }

    });

    // --------------------------------------------------
    // PERMIT REVIEW CONFIRMATIONS
    // Delegated for dynamically updated rows.
    // --------------------------------------------------

    document.addEventListener("submit", event => {

        const form = event.target;

        if (
            !(form instanceof HTMLFormElement) ||
            !form.matches(".faculty-review-form")
        ) {
            return;
        }

        const submitter = event.submitter;

        if (!(submitter instanceof HTMLButtonElement)) {
            return;
        }

        const confirmation = submitter.dataset.confirm;

        if (
            confirmation &&
            !window.confirm(confirmation)
        ) {

            event.preventDefault();

        }

    });

    // --------------------------------------------------
    // OPTIONAL REVIEW NOTES
    // --------------------------------------------------

    document.addEventListener("toggle", event => {

        const note = event.target;

        if (
            !(note instanceof HTMLDetailsElement) ||
            !note.matches(".faculty-row-note") ||
            !note.open
        ) {
            return;
        }

        document.querySelectorAll(".faculty-row-note").forEach(otherNote => {

            if (otherNote !== note) {
                otherNote.open = false;
            }

        });

    }, true);

    // --------------------------------------------------
    // KEYBOARD SHORTCUTS
    // --------------------------------------------------

    document.addEventListener("keydown", event => {

        if (event.key !== "Escape") {
            return;
        }

        if (
            facultyPasswordModal &&
            !facultyPasswordModal.hidden
        ) {

            closeFacultyPasswordModal();

            return;

        }

        if (
            facultyProfileModal &&
            !facultyProfileModal.hidden
        ) {

            closeFacultyProfileModal();

            return;

        }

        closeSidebar();

        closeDropdowns();

        document.querySelectorAll(".faculty-row-note").forEach(note => {

            note.open = false;

        });

    });

    // --------------------------------------------------
    // PROFILE SAVE NOTIFICATION
    // --------------------------------------------------

    const profileNotice = document.getElementById(
        "faculty-profile-notice"
    );

    if (profileNotice?.dataset.message) {

        showToast(profileNotice.dataset.message);

    }

    // --------------------------------------------------
    // PASSWORD UPDATE NOTIFICATION
    // --------------------------------------------------

    const passwordNotice = document.getElementById(
        "faculty-password-notice"
    );

    if (passwordNotice?.dataset.message) {

        showToast(passwordNotice.dataset.message);

    }

    // --------------------------------------------------
    // FOOTER YEAR
    // --------------------------------------------------

    const year = document.getElementById("year");

    if (year) {

        year.textContent = String(
            new Date().getFullYear()
        );

    }

    // --------------------------------------------------
    // INITIALIZE
    // --------------------------------------------------

    initializeIcons();

})();