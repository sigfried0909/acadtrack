(() => {
    "use strict";

    const body = document.body;

    const formModal = document.getElementById("account-form-modal");
    const deleteModal = document.getElementById("account-delete-modal");

    const accountForm = document.getElementById("account-form");
    const deleteForm = document.getElementById("account-delete-form");

    const roleSelect = document.getElementById("account-role");

    const studentNumberField = document.getElementById(
        "account-student-number-field"
    );

    const studentNumberInput = document.getElementById(
        "account-student-number"
    );

    const passwordInput = document.getElementById(
        "account-password"
    );

    const passwordLabel = document.getElementById(
        "account-password-label"
    );

    const passwordHelp = document.getElementById(
        "account-password-help"
    );

    const formTitle = document.getElementById(
        "account-form-title"
    );

    const formDescription = document.getElementById(
        "account-form-description"
    );

    const formSubmit = document.getElementById(
        "account-form-submit"
    );

    const formAction = document.getElementById(
        "account-form-action"
    );

    const formId = document.getElementById(
        "account-form-id"
    );

    const searchInput = document.getElementById(
        "account-search"
    );

    const roleFilter = document.getElementById(
        "account-role-filter"
    );

    const resultsCount = document.getElementById(
        "account-results-count"
    );

    const accountCount = document.getElementById(
        "accounts-count"
    );

    const deleteId = document.getElementById(
        "account-delete-id"
    );

    const deleteName = document.getElementById(
        "account-delete-name"
    );

    const deleteMeta = document.getElementById(
        "account-delete-meta"
    );

    const deleteWarning = document.getElementById(
        "account-delete-warning-text"
    );

    const deleteSubmit = document.getElementById(
        "account-delete-submit"
    );

    const toast = document.getElementById("toast");
    const toastMessage = document.getElementById("toast-message");

    let toastTimer = null;
    let lastFocusedElement = null;

    // --------------------------------------------------
    // GENERAL HELPERS
    // --------------------------------------------------

    function initializeIcons() {
        window.lucide?.createIcons();
    }

    function showToast(message) {

        if (!toast || !toastMessage) {
            return;
        }

        toastMessage.textContent = message;
        toast.hidden = false;

        window.clearTimeout(toastTimer);

        toastTimer = window.setTimeout(() => {
            toast.hidden = true;
        }, 3500);

        initializeIcons();
    }

    // --------------------------------------------------
    // BODY SCROLL LOCK
    // --------------------------------------------------

    function updateBodyLock() {

        const isOpen = Boolean(
            (formModal && !formModal.hidden) ||
            (deleteModal && !deleteModal.hidden)
        );

        body.classList.toggle("account-modal-open", isOpen);
    }

    // --------------------------------------------------
    // OPEN MODAL
    // --------------------------------------------------

    function openModal(modal, focusTarget) {

        if (!modal) {
            return;
        }

        lastFocusedElement = document.activeElement;

        modal.hidden = false;

        updateBodyLock();

        initializeIcons();

        window.setTimeout(() => {
            focusTarget?.focus();
        }, 40);
    }

    // --------------------------------------------------
    // CLOSE ACCOUNT MODALS
    // --------------------------------------------------

    function closeAccountModals() {

        const wasOpen = Boolean(
            (formModal && !formModal.hidden) ||
            (deleteModal && !deleteModal.hidden)
        );

        if (formModal) {
            formModal.hidden = true;
        }

        if (deleteModal) {
            deleteModal.hidden = true;
        }

        updateBodyLock();

        if (
            wasOpen &&
            lastFocusedElement instanceof HTMLElement &&
            lastFocusedElement.isConnected
        ) {

            lastFocusedElement.focus();
        }

        lastFocusedElement = null;
    }

    // --------------------------------------------------
    // STUDENT NUMBER FIELD VISIBILITY
    // --------------------------------------------------

    function setStudentNumberVisibility(role, editing = false) {

        const isStudent = role === "student";

        if (studentNumberField) {
            studentNumberField.hidden = !isStudent;
        }

        if (studentNumberInput) {

            studentNumberInput.required =
                isStudent && !editing;

            studentNumberInput.disabled = !isStudent;
        }
    }

    // --------------------------------------------------
    // FORM MODE
    // CREATE / EDIT
    // --------------------------------------------------

    function setFormMode(mode) {

        const isEdit = mode === "edit";

        if (formTitle) {
            formTitle.textContent = isEdit
                ? "Edit Account"
                : "Create Account";
        }

        if (formDescription) {

            formDescription.textContent = isEdit
                ? "Update the account and personal information. Leave the password blank to keep the current password."
                : "Create a student or faculty account for AcadTrack.";
        }

        if (formAction) {

            formAction.value = isEdit
                ? "edit_account"
                : "create_account";
        }

        if (formId) {
            formId.value = "";
        }

        if (roleSelect) {

            roleSelect.disabled = isEdit;

            roleSelect.value = "student";
        }

        if (passwordInput) {

            passwordInput.value = "";

            passwordInput.required = !isEdit;

            passwordInput.minLength = 8;

            passwordInput.placeholder = isEdit
                ? "Leave blank to keep current password"
                : "Enter at least 8 characters";
        }

        if (passwordLabel) {

            passwordLabel.innerHTML = isEdit
                ? 'New Password <small>(optional)</small>'
                : 'Initial Password <span>*</span>';
        }

        if (passwordHelp) {

            passwordHelp.textContent = isEdit
                ? "Leave this blank if you do not want to change the current password."
                : "The password must contain at least 8 characters.";
        }

        if (formSubmit) {

            formSubmit.innerHTML = isEdit
                ? '<i data-lucide="save"></i><span>Save Changes</span>'
                : '<i data-lucide="user-plus"></i><span>Create Account</span>';
        }

        setStudentNumberVisibility("student", isEdit);

        initializeIcons();
    }

    // --------------------------------------------------
    // OPEN CREATE ACCOUNT
    // --------------------------------------------------

    function openCreateAccount() {

        if (!accountForm || !formModal) {
            return;
        }

        accountForm.reset();

        setFormMode("create");

        openModal(formModal, roleSelect);
    }

    // --------------------------------------------------
    // OPEN EDIT ACCOUNT
    // --------------------------------------------------

    function openEditAccount(button) {

        if (!accountForm || !formModal) {
            return;
        }

        accountForm.reset();

        setFormMode("edit");

        const data = button.dataset;

        const values = {

            account_id: data.accountId,

            role: data.role,

            nickname: data.nickname,

            email: data.email,

            student_number: data.studentNumber,

            firstname: data.firstname,

            middlename: data.middlename,

            lastname: data.lastname,

            age: data.age,

            gender: data.gender,

            mobile_number: data.mobileNumber,

            address: data.address
        };

        Object.entries(values).forEach(([name, value]) => {

            if (name === "role") {

                if (roleSelect) {
                    roleSelect.value = value || "student";
                }

                return;
            }

            const field = accountForm.querySelector(
                `[name="${name}"]`
            );

            if (field) {
                field.value = value || "";
            }
        });

        if (formId) {
            formId.value = data.accountId || "";
        }

        setStudentNumberVisibility(data.role, true);

        openModal(
            formModal,
            document.getElementById("account-nickname")
        );
    }

    // --------------------------------------------------
    // OPEN DELETE ACCOUNT CONFIRMATION
    // --------------------------------------------------

    function openDeleteAccount(button) {

        if (!deleteModal || !deleteForm) {
            return;
        }

        const data = button.dataset;

        if (deleteId) {
            deleteId.value = data.accountId || "";
        }

        if (deleteName) {

            deleteName.textContent =
                data.displayName || "Selected Account";
        }

        if (deleteMeta) {

            const role = data.role === "faculty"
                ? "Faculty"
                : "Student";

            deleteMeta.textContent =
                `${role} account · ${data.email || "No email address"}`;
        }

        if (deleteWarning) {

            deleteWarning.textContent =
                data.role === "student"

                    ? "Deleting this student account permanently removes its student profile, section enrollment, permit submissions, and permit status records. This action cannot be undone."

                    : "Deleting this faculty account permanently removes the account. Assigned SSP Adviser references and permit-review references will be cleared. This action cannot be undone.";
        }

        openModal(deleteModal, deleteSubmit);
    }

    // --------------------------------------------------
    // SEARCH AND FILTER ACCOUNTS
    // --------------------------------------------------

    function filterAccounts() {

        const rows = [
            ...document.querySelectorAll("[data-account-row]")
        ];

        if (!rows.length) {

            document.querySelector(".accounts-table-scroll")
                ?.classList.remove("has-account-scroll");

            if (resultsCount) {
                resultsCount.textContent = "0 accounts available";
            }

            return;
        }

        const query = (
            searchInput?.value || ""
        ).trim().toLowerCase();

        const selectedRole = roleFilter?.value || "all";

        let visibleCount = 0;

        rows.forEach(row => {

            const text = (
                row.dataset.accountSearch || ""
            ).toLowerCase();

            const role = row.dataset.accountRole || "";

            const matchesSearch =
                !query || text.includes(query);

            const matchesRole =
                selectedRole === "all" || role === selectedRole;

            const visible = matchesSearch && matchesRole;

            row.hidden = !visible;

            if (visible) {
                visibleCount++;
            }
        });

        // ENABLE VERTICAL SCROLL WHEN 10 OR MORE ACCOUNTS ARE VISIBLE.

        const accountTableScroll = document.querySelector(
            ".accounts-table-scroll"
        );

        accountTableScroll?.classList.toggle(
            "has-account-scroll",
            visibleCount >= 10
        );

        const emptyFilter = document.getElementById(
            "account-filter-empty"
        );

        if (emptyFilter) {
            emptyFilter.hidden = visibleCount > 0;
        }

        if (resultsCount) {

            resultsCount.textContent =
                `${visibleCount} matching account${visibleCount === 1 ? "" : "s"}`;
        }
    }

    // --------------------------------------------------
    // UPDATE ACCOUNT LIST AFTER AJAX SUBMISSION
    // --------------------------------------------------

    function updateAccountList(parsedDocument) {

        const currentWrap = document.getElementById(
            "accounts-table-wrap"
        );

        const updatedWrap = parsedDocument.getElementById(
            "accounts-table-wrap"
        );

        if (currentWrap && updatedWrap) {

            currentWrap.innerHTML = updatedWrap.innerHTML;
        }

        const updatedCount = parsedDocument.getElementById(
            "accounts-count"
        );

        if (accountCount && updatedCount) {

            accountCount.textContent =
                updatedCount.textContent.trim();
        }

        filterAccounts();

        initializeIcons();
    }

    // --------------------------------------------------
    // SERVER MESSAGE
    // --------------------------------------------------

    function getServerMessage(parsedDocument, selector) {

        const element = parsedDocument.querySelector(selector);

        return element
            ? element.textContent.replace(/\s+/g, " ").trim()
            : "";
    }

    // --------------------------------------------------
    // AJAX FORM SUBMISSION
    // --------------------------------------------------

    async function submitAccountForm(event) {

        const form = event.currentTarget;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        event.preventDefault();

        if (form.dataset.ajaxSubmitting === "true") {
            return;
        }

        if (!form.reportValidity()) {
            return;
        }

        form.dataset.ajaxSubmitting = "true";

        const submitButton = form.querySelector(
            'button[type="submit"]'
        );

        const originalMarkup = submitButton?.innerHTML || "";

        const originalDisabled =
            submitButton?.disabled ?? false;

        if (submitButton) {

            submitButton.disabled = true;

            submitButton.setAttribute("aria-busy", "true");

            submitButton.innerHTML =
                '<i data-lucide="loader-circle"></i><span>Saving...</span>';

            initializeIcons();
        }

        try {

            const requestUrl =
                window.location.pathname +
                window.location.search;

            const response = await fetch(requestUrl, {

                method: "POST",

                body: new FormData(form),

                credentials: "same-origin",

                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    "Accept": "text/html"
                },

                redirect: "follow"
            });

            const html = await response.text();

            if (!response.ok) {

                console.error("AcadTrack Account Request Failed:", {
                    status: response.status,
                    requestedUrl: requestUrl,
                    finalUrl: response.url,
                    redirected: response.redirected
                });

                throw new Error(
                    `Server error (${response.status}). Requested URL: ${response.url}`
                );
            }

            const parsedDocument = new DOMParser().parseFromString(
                html,
                "text/html"
            );

            const success = getServerMessage(
                parsedDocument,
                ".admin-alert-success"
            );

            if (!success) {

                const error = getServerMessage(
                    parsedDocument,
                    ".admin-alert-error, [role='alert'], .alert-danger"
                );

                showToast(
                    error ||
                    "The request was not completed. Please check the form and try again."
                );

                return;
            }

            updateAccountList(parsedDocument);

            closeAccountModals();

            if (form === accountForm) {

                accountForm.reset();

                setFormMode("create");

            } else {

                deleteForm?.reset();
            }

            showToast(success);

        } catch (error) {

            console.error(
                "AcadTrack account management error:",
                error
            );

            showToast(
                error instanceof Error
                    ? error.message
                    : "Unable to complete the account request. Please try again."
            );

        } finally {

            delete form.dataset.ajaxSubmitting;

            if (submitButton) {

                submitButton.disabled = originalDisabled;

                submitButton.removeAttribute("aria-busy");

                submitButton.innerHTML = originalMarkup;

                initializeIcons();
            }
        }
    }

    // --------------------------------------------------
    // ACCOUNT ROLE CHANGE
    // --------------------------------------------------

    roleSelect?.addEventListener("change", () => {

        setStudentNumberVisibility(
            roleSelect.value,
            formAction?.value === "edit_account"
        );
    });

    // --------------------------------------------------
    // MODAL BUTTON EVENTS
    // --------------------------------------------------

    document.addEventListener("click", event => {

        const target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        const createButton = target.closest(
            "[data-open-create-account]"
        );

        const editButton = target.closest(
            "[data-edit-account]"
        );

        const deleteButton = target.closest(
            "[data-delete-account]"
        );

        const closeButton = target.closest(
            "[data-close-account-modal]"
        );

        if (createButton) {

            event.preventDefault();

            openCreateAccount();

            return;
        }

        if (editButton) {

            event.preventDefault();

            openEditAccount(editButton);

            return;
        }

        if (deleteButton) {

            event.preventDefault();

            openDeleteAccount(deleteButton);

            return;
        }

        if (closeButton) {

            event.preventDefault();

            closeAccountModals();
        }
    });

    // --------------------------------------------------
    // CLOSE MODALS BY CLICKING OUTSIDE
    // --------------------------------------------------

    document.addEventListener("click", event => {

        if (
            event.target === formModal ||
            event.target === deleteModal
        ) {

            closeAccountModals();
        }
    });

    // --------------------------------------------------
    // CLOSE MODALS USING ESCAPE
    // --------------------------------------------------

    document.addEventListener("keydown", event => {

        if (
            event.key === "Escape" &&
            (
                (formModal && !formModal.hidden) ||
                (deleteModal && !deleteModal.hidden)
            )
        ) {

            closeAccountModals();
        }
    });

    // --------------------------------------------------
    // SEARCH EVENTS
    // --------------------------------------------------

    searchInput?.addEventListener(
        "input",
        filterAccounts
    );

    roleFilter?.addEventListener(
        "change",
        filterAccounts
    );

    // --------------------------------------------------
    // FORM SUBMISSION EVENTS
    // --------------------------------------------------

    accountForm?.addEventListener(
        "submit",
        submitAccountForm
    );

    deleteForm?.addEventListener(
        "submit",
        submitAccountForm
    );

    // --------------------------------------------------
    // INITIALIZE
    // --------------------------------------------------

    filterAccounts();

    initializeIcons();

})();