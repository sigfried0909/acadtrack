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

(() => {
    "use strict";

    const form = document.getElementById("assessment-form");
    if (!form) return;

    const list = document.getElementById("assessment-questions");
    const template = document.getElementById("assessment-question-template");
    const builder = document.getElementById("assessment-builder");
    const addButton = document.getElementById("assessment-add");
    const linkField = document.getElementById("assessment-link-field");
    const mode = form.elements.delivery_mode;
    const externalUrl = form.elements.external_url;

    let nextQuestionId = 0;

    const prompts = {
        single: ["Question", "Which SQL clause filters grouped results?"],
        multiple: ["Question", "Select all columns that belong to the composite primary key."],
        boolean: ["Statement", "A foreign key can reference a primary key in another table."],
        short: ["Question", "What does DBMS stand for?"],
        numeric: ["Problem", "A file contains 8,192 bytes. How many KiB is this?"],
        matching: ["Directions", "Match each database term to its definition."],
        enumeration: ["Question", "List the four ACID properties of a transaction."],
        essay: ["Essay prompt or problem", "Explain how an index affects read and write performance."]
    };

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function field(card, name) {
        return card.querySelector(`[data-field="${name}"]`);
    }

    function inputField(parent, labelText, name, options = {}) {
        const label = element("label", "faculty-profile-field");
        label.append(element("span", "", labelText));

        const input = element(options.multiline ? "textarea" : "input");
        if (!options.multiline) input.type = options.type || "text";
        if (options.multiline) input.rows = 3;

        input.dataset.field = name;
        input.required = options.required !== false;
        input.value = options.value ?? "";
        input.placeholder = options.placeholder || "";

        if (options.maxLength) input.maxLength = options.maxLength;
        if (options.min !== undefined) input.min = options.min;
        if (options.max !== undefined) input.max = options.max;
        if (options.step !== undefined) input.step = options.step;

        label.append(input);
        parent.append(label);
        return input;
    }

    function button(text) {
        const node = element("button", "button button-outline", text);
        node.type = "button";
        return node;
    }

    function toggle(parent, text, name, type = "checkbox") {
        const label = element("label", "assessment-toggle");
        const input = element("input");
        input.type = type;
        input.dataset.field = name;
        label.append(input, document.createTextNode(text));
        parent.append(label);
        return input;
    }

    function menu(parent, title, name, options) {
        const label = element("label", "faculty-profile-field");
        const select = element("select");
        select.dataset.field = name;
        select.required = true;

        for (const [value, text] of options) {
            select.add(new Option(text, value));
        }

        label.append(document.createTextNode(title), select);
        parent.append(label);
    }

    function repeatingRows(parent, config) {
        const rows = element("div", "assessment-entry-list");
        const add = button(config.addLabel);
        parent.append(rows, add);

        function updateRows() {
            [...rows.children].forEach((row, index) => {
                row.querySelector("legend").textContent = `${config.label} ${index + 1}`;
                row.querySelector("[data-remove-entry]").disabled =
                    rows.children.length <= config.min;
            });
            add.disabled = rows.children.length >= 50;
        }

        function appendRow(focus = false) {
            const row = element("fieldset", "assessment-entry");
            const legend = element("legend");
            row.append(legend);

            config.build(row);

            const remove = button(`Remove ${config.label.toLowerCase()}`);
            remove.dataset.removeEntry = "";
            remove.addEventListener("click", () => {
                row.remove();
                updateRows();
            });

            row.append(remove);
            rows.append(row);
            updateRows();

            if (focus) row.querySelector("input, textarea")?.focus();
        }

        add.addEventListener("click", () => appendRow(true));

        for (let i = 0; i < config.min; i++) appendRow();
    }

    function buildType(card, type, parent) {
        if (type === "single" || type === "multiple") {
            parent.append(element("p", "assessment-hint",
                type === "single"
                    ? "Select one correct choice."
                    : "Select at least two correct choices and leave at least one incorrect choice."
            ));

            repeatingRows(parent, {
                label: "Choice",
                addLabel: "+ Add choice",
                min: type === "single" ? 2 : 3,
                build(row) {
                    inputField(row, "Choice text", "text", { maxLength: 1000 });
                    const correct = toggle(row, "Correct answer", "correct",
                        type === "single" ? "radio" : "checkbox");

                    if (type === "single") correct.name = `correct_${card.dataset.questionId}`;
                }
            });
            return;
        }

        if (type === "boolean") {
            menu(parent, "Correct answer", "answer", [
                ["", "Choose the correct answer"],
                ["True", "True"],
                ["False", "False"]
            ]);
            return;
        }

        if (type === "short" || type === "enumeration") {
            const shortAnswer = type === "short";

            inputField(parent,
                shortAnswer ? "Accepted answers — one per line" : "Expected items — one per line",
                "answers",
                {
                    multiline: true,
                    maxLength: 50000,
                    placeholder: shortAnswer
                        ? "Database management system\nDatabase management systems"
                        : "Atomicity\nConsistency\nIsolation\nDurability"
                }
            );

            parent.append(element("p", "assessment-hint",
                shortAnswer
                    ? "Each line is an alternative that earns full credit."
                    : "Each line is a separate item the student should give."
            ));

            if (!shortAnswer) {
                toggle(parent, "Require the items in the order shown", "ordered");
            }
            return;
        }

        if (type === "numeric") {
            inputField(parent, "Expected number", "answer", { type: "number", step: "any" });
            inputField(parent, "Unit shown to the student (optional)", "unit", {
                required: false, maxLength: 60, placeholder: "e.g. KiB"
            });
            inputField(parent, "Absolute tolerance (±)", "tolerance", {
                type: "number", min: 0, step: "any", value: "0"
            });
            parent.append(element("p", "assessment-hint",
                "For example, 8 with a tolerance of 0.1 accepts 7.9 through 8.1."
            ));
            return;
        }

        if (type === "matching") {
            parent.append(element("p", "assessment-hint",
                "Enter each prompt beside its correct match. Each match is used once."
            ));

            repeatingRows(parent, {
                label: "Pair",
                addLabel: "+ Add pair",
                min: 2,
                build(row) {
                    inputField(row, "Left-hand prompt", "left", { maxLength: 1000 });
                    inputField(row, "Correct right-hand match", "right", { maxLength: 1000 });
                }
            });
            return;
        }

        if (type === "essay") {
            parent.append(element("p", "assessment-hint",
                "Describe how marks are awarded. The criteria must add up to the total points."
            ));

            repeatingRows(parent, {
                label: "Criterion",
                addLabel: "+ Add scoring criterion",
                min: 1,
                build(row) {
                    inputField(row, "Scoring criterion", "criterion", {
                        maxLength: 1500, placeholder: "e.g. Explains the effect on INSERT performance"
                    });
                    inputField(row, "Points for this criterion", "criterionPoints", {
                        type: "number", min: 1, max: 1000, step: 1, value: "1"
                    });
                }
            });
        }
    }

    function showType(card) {
        const type = field(card, "type").value;
        const host = card.querySelector("[data-type-fields]");

        // Preserve the teacher's entries if they switch types and switch back.
        for (const group of host.children) {
            group.hidden = true;
            group.disabled = true;
        }

        let group = host.querySelector(`[data-group="${type}"]`);

        if (!group) {
            group = element("fieldset", "assessment-format");
            group.dataset.group = type;
            group.append(element("legend", "", "Answer format and marking key"));
            buildType(card, type, group);
            host.append(group);
        }

        group.hidden = false;
        group.disabled = false;
        card.querySelector("[data-prompt-label]").textContent = prompts[type][0];
        field(card, "prompt").placeholder = prompts[type][1];
    }

    function renumber() {
        [...list.children].forEach((card, index) => {
            card.querySelector("legend").textContent = `Question ${index + 1}`;
            card.querySelector("[data-remove]").disabled = list.children.length === 1;
        });
        addButton.disabled = list.children.length >= 100;
    }

    function addQuestion(focus = false) {
        if (list.children.length >= 100) return;

        const card = template.content.firstElementChild.cloneNode(true);
        card.dataset.questionId = String(++nextQuestionId);

        field(card, "type").addEventListener("change", () => showType(card));
        card.querySelector("[data-remove]").addEventListener("click", () => {
            card.remove();
            renumber();
        });

        list.append(card);
        showType(card);
        renumber();

        if (focus) field(card, "prompt").focus();
    }

    function updateMode() {
        const internal = mode.value === "internal";
        builder.hidden = !internal;
        linkField.hidden = internal;
        externalUrl.disabled = internal;
        externalUrl.required = !internal;

        if (internal && !list.children.length) addQuestion();

        for (const card of list.children) card.disabled = !internal;
    }

    function invalid(control, message) {
        control.setCustomValidity(message);
        control.reportValidity();
        control.setCustomValidity("");
        throw new Error("assessment-validation");
    }

    function distinct(values) {
        const keys = values.map(value => value.trim().replace(/\s+/g, " ").toLowerCase());
        return new Set(keys).size === keys.length;
    }

    function readQuestion(card) {
        const type = field(card, "type").value;
        const group = card.querySelector(`[data-group="${type}"]`);
        const rows = [...group.querySelectorAll(".assessment-entry")];
        const prompt = field(card, "prompt");
        const points = field(card, "points");

        // Catch whitespace-only values before submitting the entire assessment.
        for (const input of [prompt, ...group.querySelectorAll("input[type=text], textarea")]) {
            if (input.required && !input.value.trim()) invalid(input, "Please complete this field.");
        }

        const question = { type, prompt: prompt.value.trim(), points: Number(points.value) };

        if (type === "single" || type === "multiple") {
            question.choices = rows.map(row => ({
                text: field(row, "text").value.trim(),
                correct: field(row, "correct").checked
            }));

            if (!distinct(question.choices.map(choice => choice.text))) {
                invalid(field(rows[0], "text"), "Each choice must have different text.");
            }

            const correct = question.choices.filter(choice => choice.correct).length;

            if (type === "single" && correct !== 1) {
                invalid(field(rows[0], "correct"), "Select exactly one correct choice.");
            }

            if (type === "multiple" && (correct < 2 || correct === rows.length)) {
                invalid(field(rows[0], "correct"),
                    "Mark at least two correct choices and leave one incorrect choice.");
            }
        }

        if (type === "boolean") question.answer = field(group, "answer").value;

        if (type === "short" || type === "enumeration") {
            const input = field(group, "answers");
            const answers = input.value.split(/\r?\n/).map(value => value.trim()).filter(Boolean);
            const minimum = type === "short" ? 1 : 2;

            if (answers.length < minimum || answers.length > 50 ||
                !distinct(answers) || answers.some(answer => [...answer].length > 1000)) {
                invalid(input, `Enter ${minimum} to 50 distinct lines, up to 1,000 characters per line.`);
            }

            question.answers = answers;
            if (type === "enumeration") question.ordered = field(group, "ordered").checked;
        }

        if (type === "numeric") {
            question.answer = field(group, "answer").value;
            question.unit = field(group, "unit").value.trim();
            question.tolerance = field(group, "tolerance").value;
        }

        if (type === "matching") {
            question.pairs = rows.map(row => ({
                left: field(row, "left").value.trim(),
                right: field(row, "right").value.trim()
            }));

            for (const side of ["left", "right"]) {
                if (!distinct(question.pairs.map(pair => pair[side]))) {
                    invalid(field(rows[0], side), "Each entry in this column must be different.");
                }
            }
        }

        if (type === "essay") {
            question.rubric = rows.map(row => ({
                criterion: field(row, "criterion").value.trim(),
                points: Number(field(row, "criterionPoints").value)
            }));

            const total = question.rubric.reduce((sum, item) => sum + item.points, 0);
            if (total !== question.points) {
                invalid(points, `Your rubric adds up to ${total}. Adjust the criteria or total points.`);
            }
        }

        return question;
    }

    form.addEventListener("submit", event => {
        if (mode.value !== "internal") return;

        try {
            const questions = [...list.children].map(readQuestion);
            const payload = JSON.stringify(questions);

            if (new Blob([payload]).size > 500000) {
                invalid(field(list.lastElementChild, "prompt"),
                    "The assessment is too large. Shorten the questions or split the assessment.");
            }

            form.elements.questions_json.value = payload;
        } catch (error) {
            event.preventDefault();
            if (error.message !== "assessment-validation") {
                console.error(error);
                window.alert("The assessment could not be prepared. Your entries are still on this page.");
            }
        }
    });

    addButton.addEventListener("click", () => addQuestion(true));
    mode.addEventListener("change", updateMode);
    updateMode();
})();

document.addEventListener("submit", (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || !form.matches("[data-faculty-delete]")) {
        return;
    }

    const type = form.dataset.itemType;
    const title = form.dataset.itemTitle;
    const consequence = type === "module"
        ? "Students in this section will lose access to the uploaded file."
        : "This assessment and any questions and answer keys saved in AcadTrack will be removed.";

    const confirmed = window.confirm(
        `Delete the ${type} “${title}”?\n\n${consequence}\n\nThis cannot be undone.`
    );

    if (!confirmed) {
        event.preventDefault();
    }
});

(() => {
    "use strict";

    const modal = document.getElementById("faculty-submissions-modal");
    const frame = document.getElementById("faculty-submissions-frame");
    const assessmentTitle = document.getElementById("faculty-submissions-assessment");
    const closeButton = document.getElementById("faculty-submissions-close");

    if (!modal || !frame || !assessmentTitle || !closeButton ||
        typeof modal.showModal !== "function") {
        return;
    }

    let opener = null;

    document.querySelectorAll("[data-open-submissions]").forEach(link => {
        link.addEventListener("click", event => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
                return;
            }

            event.preventDefault();
            if (modal.open) return;

            opener = link;
            assessmentTitle.textContent = link.dataset.assessmentTitle || "";
            frame.title = `Submissions for ${assessmentTitle.textContent}`;

            const url = new URL(link.href);
            url.searchParams.set("embed", "1");

            frame.src = url.href;
            modal.showModal();
            document.body.classList.add("faculty-submissions-open");
        });
    });

    function requestClose() {
        let unsaved = false;

        try {
            unsaved = frame.contentWindow?.facultyHasUnsavedScores?.() === true;
        } catch {
            // A sign-in redirect can make the frame inaccessible.
        }

        if (unsaved && !window.confirm("Close submissions and discard your unsaved scores?")) {
            return;
        }

        modal.close();
    }

    closeButton.addEventListener("click", requestClose);

    modal.addEventListener("click", (event) => {
        if (event.target !== modal) return;

        const bounds = modal.getBoundingClientRect();
        const clickedBackdrop =
            event.clientX < bounds.left ||
            event.clientX >= bounds.right ||
            event.clientY < bounds.top ||
            event.clientY >= bounds.bottom;

        if (clickedBackdrop) {
            requestClose();
        }
    });

    modal.addEventListener("cancel", event => {
        event.preventDefault();
        requestClose();
    });

    modal.addEventListener("close", () => {
        frame.src = "about:blank";
        document.body.classList.remove("faculty-submissions-open");
        opener?.focus();
        opener = null;
    });
})();

(() => {
    "use strict";

    const modal = document.getElementById("saved-assessment-modal");
    const body = document.getElementById("saved-assessment-body");
    const title = document.getElementById("saved-assessment-title");
    const meta = document.getElementById("saved-assessment-meta");
    const closeButton = document.getElementById("saved-assessment-close");

    if (!modal || !body || !title || !meta || !closeButton) return;

    let opener = null;
    let preview = null;
    let originalPosition = null;

    document.querySelectorAll("[data-view-saved-assessment]").forEach(button => {
        button.addEventListener("click", () => {
            if (modal.open) return;

            const selected = document.getElementById(button.dataset.previewId);
            if (!selected) return;

            opener = button;
            preview = selected;
            originalPosition = document.createComment("Assessment preview");

            preview.replaceWith(originalPosition);
            body.appendChild(preview);
            preview.hidden = false;

            title.textContent = button.dataset.title;
            meta.textContent = button.dataset.meta;

            modal.showModal();
            body.scrollTop = 0;
            document.body.classList.add("saved-assessment-open");
        });
    });

    closeButton.addEventListener("click", () => modal.close());

    modal.addEventListener("click", event => {
        if (event.target !== modal) return;

        const bounds = modal.getBoundingClientRect();
        const outside =
            event.clientX < bounds.left || event.clientX >= bounds.right ||
            event.clientY < bounds.top || event.clientY >= bounds.bottom;

        if (outside) modal.close();
    });

    modal.addEventListener("close", () => {
        if (preview && originalPosition) {
            preview.hidden = true;
            originalPosition.replaceWith(preview);
        }

        document.body.classList.remove("saved-assessment-open");
        opener?.focus();

        opener = null;
        preview = null;
        originalPosition = null;
    });
})();

(() => {
    "use strict";

    const modal = document.getElementById("faculty-module-upload-modal");
    const form = document.getElementById("faculty-module-upload-form");
    const sectionLabel = document.getElementById("faculty-module-upload-section");

    if (!modal || !form || !sectionLabel) return;

    const fileInput = form.elements.namedItem("module_file");
    const submitButton = form.querySelector('[type="submit"]');
    let opener = null;

    function newUploadKey() {
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        return Array.from(bytes, byte => byte.toString(16).padStart(2, "0")).join("");
    }

    document.querySelectorAll("[data-module-upload]").forEach(button => {
        button.addEventListener("click", () => {
            if (modal.open) return;

            form.reset();
            fileInput.setCustomValidity("");
            submitButton.disabled = false;

            form.elements.namedItem("section_id").value = button.dataset.sectionId;
            form.elements.namedItem("upload_key").value = newUploadKey();
            sectionLabel.textContent = button.dataset.sectionLabel;

            opener = button;
            modal.showModal();
            document.body.classList.add("faculty-module-upload-open");
            form.elements.namedItem("period").focus();
        });
    });

    modal.querySelectorAll("[data-close-module-upload]").forEach(button => {
        button.addEventListener("click", () => modal.close());
    });

    modal.addEventListener("click", event => {
        if (event.target !== modal) return;

        const bounds = modal.getBoundingClientRect();
        if (
            event.clientX < bounds.left || event.clientX >= bounds.right ||
            event.clientY < bounds.top || event.clientY >= bounds.bottom
        ) {
            modal.close();
        }
    });

    modal.addEventListener("close", () => {
        document.body.classList.remove("faculty-module-upload-open");
        opener?.focus();
        opener = null;
    });

    fileInput.addEventListener("change", () => {
        const file = fileInput.files[0];
        fileInput.setCustomValidity(
            file && file.size > 5 * 1024 * 1024
                ? "Choose a file of 5 MB or less."
                : ""
        );
    });

    form.addEventListener("submit", () => {
        submitButton.disabled = true;
    });

    window.addEventListener("pageshow", () => {
        submitButton.disabled = false;
    });

    // Limit each section to the actual height of its first five files,
    // including any period headings above those files.
    document.querySelectorAll("[data-module-scroll]").forEach(viewport => {
        const files = viewport.querySelectorAll(".faculty-module-file");
        if (files.length <= 5) return;

        function fitFiveFiles() {
            const top = viewport.getBoundingClientRect().top;
            const fifthBottom = files[4].getBoundingClientRect().bottom;

            // Include scrollTop so resizing while scrolled keeps the correct height.
            const height = Math.ceil(fifthBottom - top + viewport.scrollTop);
            if (height > 0) viewport.style.maxHeight = `${height}px`;
        }

        fitFiveFiles();

        if ("ResizeObserver" in window) {
            const observer = new ResizeObserver(fitFiveFiles);

            // Period wrappers contain both the headings and the variable-height rows.
            viewport.querySelectorAll(".faculty-module-period").forEach(period => {
                observer.observe(period);
            });
        } else {
            window.addEventListener("resize", fitFiveFiles);
        }
    });
})();

(() => {
    "use strict";

    const modal = document.getElementById("faculty-create-assessment-modal");
    const form = document.getElementById("assessment-form");
    const periodDescription = document.getElementById("faculty-create-assessment-period");

    if (!modal || !form || !periodDescription) return;

    const periodField = form.elements.namedItem("period");
    const sectionField = form.elements.namedItem("section_id");
    let opener = null;
    let edited = false;

    function markEdited() {
        edited = true;
    }

    form.addEventListener("input", markEdited);
    form.addEventListener("change", markEdited);

    // Adding or removing a question, option, pair, or rubric row is also an edit.
    form.querySelector("#assessment-builder")?.addEventListener("click", event => {
        if (event.target.closest("button")) markEdited();
    });

    document.querySelectorAll("[data-create-assessment-period]").forEach(button => {
        button.addEventListener("click", () => {
            const nextPeriod = button.dataset.createAssessmentPeriod;

            if (modal.open || !["P1", "P2", "P3"].includes(nextPeriod)) return;

            const previousPeriod = periodField.value;

            if (edited && previousPeriod && previousPeriod !== nextPeriod) {
                const moveDraft = window.confirm(
                    `Move your unfinished assessment from ${previousPeriod} to ${nextPeriod}?`
                );

                if (!moveDraft) return;
            }

            opener = button;
            periodField.value = nextPeriod;
            periodDescription.textContent = `Assessment period: ${nextPeriod}`;

            modal.showModal();
            document.body.classList.add("assessment-editor-open");
            form.scrollTop = 0;
            sectionField.focus();
        });
    });

    modal.querySelectorAll("[data-close-assessment-editor]").forEach(button => {
        button.addEventListener("click", () => modal.close());
    });

    modal.addEventListener("click", event => {
        if (event.target !== modal) return;

        const bounds = modal.getBoundingClientRect();
        const clickedBackdrop =
            event.clientX < bounds.left || event.clientX >= bounds.right ||
            event.clientY < bounds.top || event.clientY >= bounds.bottom;

        if (clickedBackdrop) modal.close();
    });

    modal.addEventListener("close", () => {
        document.body.classList.remove("assessment-editor-open");
        opener?.focus();
        opener = null;
    });
})();