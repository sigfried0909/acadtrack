(() => {
  "use strict";

  const body = document.body;
  const mobileQuery = window.matchMedia("(max-width: 820px)");

  // --------------------------------------------------
  // ELEMENT REFERENCES
  // --------------------------------------------------

  const collapseBtn = document.querySelector("[data-collapse-sidebar]");
  const openBtn = document.querySelector("[data-open-sidebar]");
  const closeSidebarButtons = document.querySelectorAll("[data-close-sidebar]");

  const searchOverlay = document.getElementById("search-overlay");
  const searchInput = document.getElementById("search-input");
  const searchResults = document.getElementById("search-results");

  const toast = document.getElementById("toast");
  const toastMessage = document.getElementById("toast-message");

  const uploadConfirmModal = document.getElementById("upload-confirm-modal");
  const uploadConfirmMessage = document.getElementById(
    "upload-confirm-message",
  );
  const confirmUploadButton = document.getElementById("confirm-upload-submit");
  const cancelUploadButton = document.getElementById("cancel-upload-confirm");

  // STUDENT PROFILE

  const studentProfileModal = document.getElementById("student-profile-modal");
  const studentProfileForm = document.getElementById("student-profile-form");

  const openProfileButtons = document.querySelectorAll(
    "[data-open-profile-modal]",
  );
  const closeProfileButtons = document.querySelectorAll(
    "[data-close-profile-modal]",
  );

  // PROFILE PICTURE

  const studentPictureForm = document.getElementById("student-picture-form");
  const profilePictureInput = document.getElementById("profile-picture-input");
  const profilePicturePreview = document.getElementById(
    "student-picture-preview",
  );
  const profilePictureFilename = document.getElementById(
    "profile-picture-filename",
  );
  const uploadProfilePictureButton = document.getElementById(
    "upload-profile-picture",
  );

  const originalPicturePreview = profilePicturePreview?.innerHTML || "";
  const originalPictureFilename =
    profilePictureFilename?.textContent || "No new picture selected.";

  // CHANGE PASSWORD

  const studentPasswordModal = document.getElementById(
    "student-password-modal",
  );
  const studentPasswordForm = document.getElementById("student-password-form");

  const openPasswordButtons = document.querySelectorAll(
    "[data-open-password-modal]",
  );
  const closePasswordButtons = document.querySelectorAll(
    "[data-close-password-modal]",
  );

  const currentPasswordInput = document.getElementById("current-password");
  const newPasswordInput = document.getElementById("new-password");
  const confirmPasswordInput = document.getElementById("confirm-password");

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
      day: "numeric",
    }).format(now);

    clockElement.textContent = new Intl.DateTimeFormat("en-US", {
      timeZone: "Asia/Manila",
      hour: "numeric",
      minute: "2-digit",
      second: "2-digit",
      hour12: true,
    }).format(now);
  }

  updatePhilippineDateTime();
  setInterval(updatePhilippineDateTime, 1000);

  // --------------------------------------------------
  // STATE
  // --------------------------------------------------

  let toastTimeout = null;
  let searchSelectedIndex = -1;

  let pendingUploadForm = null;

  let profileReturnFocus = null;
  let passwordReturnFocus = null;

  let profilePicturePreviewUrl = null;

  // --------------------------------------------------
  // GENERAL HELPERS
  // --------------------------------------------------

  function refreshIcons() {
    window.lucide?.createIcons();
  }

  function showToast(message) {
    if (!toast || !toastMessage) {
      return;
    }

    toastMessage.textContent = message;
    toast.hidden = false;

    window.clearTimeout(toastTimeout);

    toastTimeout = window.setTimeout(() => {
      toast.hidden = true;
    }, 3000);
  }

  function closeDropdowns(exceptId = null) {
    document.querySelectorAll(".dropdown").forEach((menu) => {
      if (menu.id !== exceptId) {
        menu.hidden = true;
      }
    });

    document.querySelectorAll("[data-dropdown-toggle]").forEach((button) => {
      if (button.dataset.dropdownToggle !== exceptId) {
        button.setAttribute("aria-expanded", "false");
      }
    });
  }

  function closeSidebar() {
    body.classList.remove("mobile-sidebar-open");

    openBtn?.setAttribute("aria-expanded", "false");
  }

  function openSidebar() {
    body.classList.add("mobile-sidebar-open");

    openBtn?.setAttribute("aria-expanded", "true");
  }

  function setActive(label, element = null) {
    document.querySelectorAll("[data-nav-link]").forEach((link) => {
      link.classList.remove("active");
    });

    element?.classList.add("active");

    const breadcrumb = document.getElementById("breadcrumb-current");

    if (breadcrumb) {
      breadcrumb.textContent = label;
    }
  }

  function closeSearch() {
    if (!searchOverlay) {
      return;
    }

    searchOverlay.hidden = true;

    body.classList.remove("search-open");

    document.querySelector("[data-open-search]")?.focus();
  }

  // --------------------------------------------------
  // SIDEBAR
  // --------------------------------------------------

  collapseBtn?.addEventListener("click", () => {
    if (mobileQuery.matches) {
      return;
    }

    body.classList.toggle("sidebar-collapsed");

    const collapsed = body.classList.contains("sidebar-collapsed");

    collapseBtn.setAttribute("aria-pressed", String(collapsed));

    collapseBtn.setAttribute(
      "aria-label",
      collapsed ? "Expand sidebar" : "Collapse sidebar",
    );
  });

  openBtn?.addEventListener("click", openSidebar);

  closeSidebarButtons.forEach((button) => {
    button.addEventListener("click", closeSidebar);
  });

  document
    .querySelector(".mobile-scrim")
    ?.addEventListener("click", closeSidebar);

  document.querySelectorAll("[data-submenu-toggle]").forEach((button) => {
    button.addEventListener("click", () => {
      if (
        body.classList.contains("sidebar-collapsed") &&
        !mobileQuery.matches
      ) {
        body.classList.remove("sidebar-collapsed");

        collapseBtn?.setAttribute("aria-pressed", "false");
        collapseBtn?.setAttribute("aria-label", "Collapse sidebar");
      }

      const submenu = document.getElementById(button.dataset.submenuToggle);

      if (!submenu) {
        return;
      }

      const willOpen = submenu.hidden;

      submenu.hidden = !willOpen;

      button.setAttribute("aria-expanded", String(willOpen));
    });
  });

  // --------------------------------------------------
  // DROPDOWN
  // --------------------------------------------------

  document.querySelectorAll("[data-dropdown-toggle]").forEach((button) => {
    button.addEventListener("click", (event) => {
      event.stopPropagation();

      const targetId = button.dataset.dropdownToggle;
      const target = document.getElementById(targetId);

      if (!target) {
        return;
      }

      const willOpen = target.hidden;

      closeDropdowns(targetId);

      target.hidden = !willOpen;

      button.setAttribute("aria-expanded", String(willOpen));
    });
  });

  document.addEventListener("click", (event) => {
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
    const profileIsOpen = studentProfileModal && !studentProfileModal.hidden;

    const passwordIsOpen = studentPasswordModal && !studentPasswordModal.hidden;

    body.classList.toggle(
      "student-profile-modal-open",
      Boolean(profileIsOpen || passwordIsOpen),
    );
  }

  // --------------------------------------------------
  // STUDENT PROFILE MODAL
  // --------------------------------------------------

  function openStudentProfileModal(trigger = null) {
    if (!studentProfileModal) {
      return;
    }

    profileReturnFocus =
      trigger ||
      document.querySelector('[data-dropdown-toggle="profile-menu"]') ||
      document.activeElement;

    closeDropdowns();
    closeSidebar();

    if (searchOverlay && !searchOverlay.hidden) {
      closeSearch();
    }

    studentProfileModal.hidden = false;

    updateModalBodyLock();

    refreshIcons();

    window.setTimeout(() => {
      studentProfileForm?.querySelector('input[name="firstname"]')?.focus();
    }, 30);
  }

  function closeStudentProfileModal() {
    if (!studentProfileModal) {
      return;
    }

    studentProfileModal.hidden = true;

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

  openProfileButtons.forEach((button) => {
    button.addEventListener("click", (event) => {
      event.preventDefault();

      openStudentProfileModal(button);
    });
  });

  closeProfileButtons.forEach((button) => {
    button.addEventListener("click", closeStudentProfileModal);
  });

  // --------------------------------------------------
  // PROFILE MODAL FOCUS TRAP
  // --------------------------------------------------

  function trapModalFocus(modal, event) {
    if (event.key !== "Tab" || !modal || modal.hidden) {
      return;
    }

    const focusableElements = [
      ...modal.querySelectorAll(
        "button:not([disabled]):not(.student-profile-backdrop), " +
          "input:not([disabled]), " +
          "select:not([disabled]), " +
          "textarea:not([disabled]), " +
          "a[href]",
      ),
    ].filter((element) => {
      return (
        !element.closest("[hidden]") && element.getClientRects().length > 0
      );
    });

    if (!focusableElements.length) {
      return;
    }

    const first = focusableElements[0];
    const last = focusableElements[focusableElements.length - 1];

    if (
      event.shiftKey &&
      (document.activeElement === first ||
        !modal.contains(document.activeElement))
    ) {
      event.preventDefault();
      last.focus();
    } else if (
      !event.shiftKey &&
      (document.activeElement === last ||
        !modal.contains(document.activeElement))
    ) {
      event.preventDefault();
      first.focus();
    }
  }

  studentProfileModal?.addEventListener("keydown", (event) => {
    trapModalFocus(studentProfileModal, event);
  });

  // CONFIRM PROFILE CHANGES

  studentProfileForm?.addEventListener("submit", (event) => {
    const confirmed = window.confirm(
      "Are you sure you want to save your profile changes?\n\n" +
        "Once saved, your course, year level, and official section assignment " +
        "will be locked and cannot be changed through your profile. " +
        "Contact the CITE Department if you need to request changes.",
    );

    if (!confirmed) {
      event.preventDefault();
    }
  });

  // INITIAL PROFILE MODAL STATE

  if (studentProfileModal && !studentProfileModal.hidden) {
    updateModalBodyLock();

    profileReturnFocus = document.querySelector(
      '[data-dropdown-toggle="profile-menu"]',
    );

    window.setTimeout(() => {
      studentProfileForm?.querySelector('input[name="firstname"]')?.focus();
    }, 30);
  }

  // --------------------------------------------------
  // PROFILE PICTURE — RESET HELPERS
  // --------------------------------------------------

  function resetProfilePictureSelection() {
    if (profilePicturePreviewUrl) {
      URL.revokeObjectURL(profilePicturePreviewUrl);

      profilePicturePreviewUrl = null;
    }

    if (profilePictureInput) {
      profilePictureInput.value = "";
    }

    if (profilePicturePreview) {
      profilePicturePreview.innerHTML = originalPicturePreview;
    }

    if (profilePictureFilename) {
      profilePictureFilename.textContent = originalPictureFilename;
    }

    if (uploadProfilePictureButton) {
      uploadProfilePictureButton.disabled = true;

      delete uploadProfilePictureButton.dataset.submitting;

      uploadProfilePictureButton.innerHTML =
        '<i data-lucide="upload"></i> Upload Picture';
    }

    refreshIcons();
  }

  // --------------------------------------------------
  // PROFILE PICTURE — PREVIEW
  // --------------------------------------------------

  profilePictureInput?.addEventListener("change", () => {
    const file = profilePictureInput.files?.[0];

    if (!file) {
      resetProfilePictureSelection();

      return;
    }

    const maximumFileSize = 2 * 1024 * 1024;

    const allowedTypes = ["image/jpeg", "image/png", "image/webp"];

    if (file.size <= 0) {
      profilePictureInput.value = "";

      resetProfilePictureSelection();

      showToast("Please select a valid image.");

      return;
    }

    if (file.size > maximumFileSize) {
      profilePictureInput.value = "";

      resetProfilePictureSelection();

      showToast("Your profile picture must not exceed 2 MB.");

      return;
    }

    if (!allowedTypes.includes(file.type)) {
      profilePictureInput.value = "";

      resetProfilePictureSelection();

      showToast("Please select a JPG, PNG, or WEBP image.");

      return;
    }

    if (profilePicturePreviewUrl) {
      URL.revokeObjectURL(profilePicturePreviewUrl);

      profilePicturePreviewUrl = null;
    }

    profilePicturePreviewUrl = URL.createObjectURL(file);

    if (profilePicturePreview) {
      const image = document.createElement("img");

      image.src = profilePicturePreviewUrl;
      image.alt = "Selected profile picture preview";

      profilePicturePreview.replaceChildren(image);
    }

    if (profilePictureFilename) {
      profilePictureFilename.textContent = file.name;
    }

    if (uploadProfilePictureButton) {
      uploadProfilePictureButton.disabled = false;
    }
  });

  // --------------------------------------------------
  // PROFILE PICTURE — UPLOAD
  // --------------------------------------------------

  studentPictureForm?.addEventListener("submit", (event) => {
    const file = profilePictureInput?.files?.[0];

    if (!file) {
      event.preventDefault();

      showToast("Please select a profile picture first.");

      return;
    }

    if (uploadProfilePictureButton?.dataset.submitting === "true") {
      event.preventDefault();

      return;
    }

    const maximumFileSize = 2 * 1024 * 1024;

    const allowedTypes = ["image/jpeg", "image/png", "image/webp"];

    if (
      file.size <= 0 ||
      file.size > maximumFileSize ||
      !allowedTypes.includes(file.type)
    ) {
      event.preventDefault();

      showToast("Please select a valid JPG, PNG, or WEBP image up to 2 MB.");

      return;
    }

    if (uploadProfilePictureButton) {
      uploadProfilePictureButton.dataset.submitting = "true";
      uploadProfilePictureButton.disabled = true;

      uploadProfilePictureButton.innerHTML =
        '<i data-lucide="loader-circle"></i> Uploading...';

      refreshIcons();
    }
  });

  // --------------------------------------------------
  // ACCOUNT SETTINGS — CHANGE PASSWORD MODAL
  // --------------------------------------------------

  function openStudentPasswordModal(trigger = null) {
    if (!studentPasswordModal) {
      return;
    }

    passwordReturnFocus =
      trigger ||
      document.querySelector('[data-dropdown-toggle="profile-menu"]') ||
      document.activeElement;

    closeDropdowns();
    closeSidebar();

    if (searchOverlay && !searchOverlay.hidden) {
      closeSearch();
    }

    studentPasswordModal.hidden = false;

    updateModalBodyLock();

    refreshIcons();

    window.setTimeout(() => {
      currentPasswordInput?.focus();
    }, 30);
  }

  function closeStudentPasswordModal() {
    if (!studentPasswordModal) {
      return;
    }

    studentPasswordModal.hidden = true;

    updateModalBodyLock();

    studentPasswordForm?.reset();

    studentPasswordForm?.querySelectorAll("input").forEach((input) => {
      input.setCustomValidity("");

      if (input.type === "text") {
        input.type = "password";
      }
    });

    const passwordLabels = {
      "current-password": "Show current password",
      "new-password": "Show new password",
      "confirm-password": "Show confirmation password",
    };

    studentPasswordForm
      ?.querySelectorAll("[data-toggle-password]")
      .forEach((button) => {
        const inputId = button.dataset.togglePassword;

        button.setAttribute("aria-pressed", "false");

        button.setAttribute(
          "aria-label",
          passwordLabels[inputId] || "Show password",
        );

        button.innerHTML = '<i data-lucide="eye"></i>';
      });

    refreshIcons();

    if (
      passwordReturnFocus instanceof HTMLElement &&
      passwordReturnFocus.isConnected
    ) {
      passwordReturnFocus.focus();
    }

    passwordReturnFocus = null;
  }

  // OPEN PASSWORD MODAL

  openPasswordButtons.forEach((button) => {
    button.addEventListener("click", (event) => {
      event.preventDefault();

      openStudentPasswordModal(button);
    });
  });

  // CLOSE PASSWORD MODAL

  closePasswordButtons.forEach((button) => {
    button.addEventListener("click", closeStudentPasswordModal);
  });

  // PASSWORD MODAL INITIAL STATE

  if (studentPasswordModal && !studentPasswordModal.hidden) {
    updateModalBodyLock();

    passwordReturnFocus = document.querySelector(
      '[data-dropdown-toggle="profile-menu"]',
    );

    window.setTimeout(() => {
      currentPasswordInput?.focus();
    }, 30);
  }

  // --------------------------------------------------
  // PASSWORD — SHOW AND HIDE
  // --------------------------------------------------

  studentPasswordForm
    ?.querySelectorAll("[data-toggle-password]")
    .forEach((button) => {
      button.addEventListener("click", () => {
        const inputId = button.dataset.togglePassword;
        const input = document.getElementById(inputId);

        if (!input) {
          return;
        }

        const isVisible = input.type === "text";

        input.type = isVisible ? "password" : "text";

        button.setAttribute("aria-pressed", String(!isVisible));

        const labels = {
          "current-password": isVisible
            ? "Show current password"
            : "Hide current password",

          "new-password": isVisible ? "Show new password" : "Hide new password",

          "confirm-password": isVisible
            ? "Show confirmation password"
            : "Hide confirmation password",
        };

        button.setAttribute(
          "aria-label",
          labels[inputId] || "Toggle password visibility",
        );

        button.innerHTML = isVisible
          ? '<i data-lucide="eye"></i>'
          : '<i data-lucide="eye-off"></i>';

        refreshIcons();
      });
    });

  // --------------------------------------------------
  // PASSWORD VALIDATION
  // --------------------------------------------------

  function validateStudentPasswords() {
    if (!currentPasswordInput || !newPasswordInput || !confirmPasswordInput) {
      return true;
    }

    if (
      currentPasswordInput.value !== "" &&
      newPasswordInput.value !== "" &&
      currentPasswordInput.value === newPasswordInput.value
    ) {
      newPasswordInput.setCustomValidity(
        "Your new password must be different from your current password.",
      );
    } else {
      newPasswordInput.setCustomValidity("");
    }

    if (
      confirmPasswordInput.value !== "" &&
      newPasswordInput.value !== confirmPasswordInput.value
    ) {
      confirmPasswordInput.setCustomValidity(
        "The confirmation password does not match.",
      );
    } else {
      confirmPasswordInput.setCustomValidity("");
    }

    return studentPasswordForm?.checkValidity() ?? true;
  }

  currentPasswordInput?.addEventListener("input", validateStudentPasswords);

  newPasswordInput?.addEventListener("input", validateStudentPasswords);

  confirmPasswordInput?.addEventListener("input", validateStudentPasswords);

  // CONFIRM PASSWORD UPDATE

  studentPasswordForm?.addEventListener("submit", (event) => {
    validateStudentPasswords();

    if (!studentPasswordForm.checkValidity()) {
      event.preventDefault();

      studentPasswordForm.reportValidity();

      return;
    }

    const confirmed = window.confirm(
      "Are you sure you want to change your password?\n\n" +
        "You will need to use your new password the next time you sign in.",
    );

    if (!confirmed) {
      event.preventDefault();

      return;
    }
  });

  // --------------------------------------------------
  // PASSWORD MODAL FOCUS TRAP
  // --------------------------------------------------

  studentPasswordModal?.addEventListener("keydown", (event) => {
    trapModalFocus(studentPasswordModal, event);
  });

  // --------------------------------------------------
  // PAGE NAVIGATION
  // --------------------------------------------------

  const dashboardPages = {
    "dashboard-home": document.getElementById("dashboard-home-page"),
    "permit-application": document.getElementById("permit-application-page"),
  };

  function showDashboardPage(pageName) {
    const targetPage = dashboardPages[pageName];

    if (!targetPage) {
      return false;
    }

    Object.values(dashboardPages).forEach((page) => {
      if (page) {
        page.hidden = true;
      }
    });

    targetPage.hidden = false;

    const label =
      pageName === "permit-application" ? "Permit Application" : "Dashboard";

    const pageTitle = targetPage.querySelector(".page-title-row h1");
    const breadcrumb = document.getElementById("breadcrumb-current");

    if (pageTitle) {
      pageTitle.textContent = label;
    }

    if (breadcrumb) {
      breadcrumb.textContent = label;
    }

    document.querySelectorAll("[data-nav-link]").forEach((link) => {
      const isDashboard =
        pageName === "dashboard-home" &&
        !link.dataset.page &&
        link.dataset.label === "Dashboard";

      const isSelectedPage = link.dataset.page === pageName;

      link.classList.toggle("active", isDashboard || isSelectedPage);
    });

    const submenu = document.getElementById("academics-submenu");

    const submenuToggle = document.querySelector(
      '[data-submenu-toggle="academics-submenu"]',
    );

    if (pageName === "permit-application") {
      if (submenu) {
        submenu.hidden = false;
      }

      submenuToggle?.setAttribute("aria-expanded", "true");
    }

    return true;
  }

  const initialPage = new URLSearchParams(window.location.search).get("page");

  showDashboardPage(
    initialPage === "permit-application"
      ? "permit-application"
      : "dashboard-home",
  );

  document.querySelectorAll("[data-nav-link]").forEach((link) => {
    link.addEventListener("click", (event) => {
      const pageName = link.dataset.page;
      const label = link.dataset.label || link.textContent.trim();

      if (pageName && showDashboardPage(pageName)) {
        event.preventDefault();

        closeSidebar();
        closeDropdowns();

        return;
      }

      if (link.getAttribute("href") === "#") {
        event.preventDefault();
      }

      setActive(label, link);

      closeSidebar();
      closeDropdowns();

      if (label !== "Dashboard") {
        showToast(`${label} is a navigation placeholder for now.`);
      }
    });
  });

  document.querySelectorAll("[data-open-page]").forEach((button) => {
    button.addEventListener("click", () => {
      showDashboardPage(button.dataset.openPage);

      closeSidebar();
      closeDropdowns();
    });
  });

  // --------------------------------------------------
  // GLOBAL SEARCH
  // --------------------------------------------------

  function getSearchButtons() {
    return searchResults
      ? [...searchResults.querySelectorAll("[data-search-target]")]
      : [];
  }

  function filterSearch(query = "") {
    const normalizedQuery = query.trim().toLowerCase();

    let visibleCount = 0;

    getSearchButtons().forEach((button) => {
      const label = (button.dataset.searchTarget || "").toLowerCase();

      const matches = label.includes(normalizedQuery);

      button.hidden = !matches;

      button.classList.remove("selected");

      if (matches) {
        visibleCount++;
      }
    });

    const noResults = searchResults?.querySelector(".search-no-results");

    if (noResults) {
      noResults.hidden = visibleCount > 0;
    }

    searchSelectedIndex = -1;
  }

  function openSearch() {
    if (!searchOverlay) {
      return;
    }

    closeDropdowns();
    closeSidebar();

    searchOverlay.hidden = false;

    body.classList.add("search-open");

    filterSearch(searchInput?.value || "");

    window.setTimeout(() => {
      searchInput?.focus();
    }, 30);
  }

  document.querySelectorAll("[data-open-search]").forEach((button) => {
    button.addEventListener("click", openSearch);
  });

  document.querySelectorAll("[data-close-search]").forEach((button) => {
    button.addEventListener("click", closeSearch);
  });

  searchInput?.addEventListener("input", () => {
    filterSearch(searchInput.value);
  });

  function moveSearchSelection(direction) {
    const buttons = getSearchButtons().filter((button) => !button.hidden);

    if (!buttons.length) {
      return;
    }

    searchSelectedIndex =
      (searchSelectedIndex + direction + buttons.length) % buttons.length;

    buttons.forEach((button, index) => {
      button.classList.toggle("selected", index === searchSelectedIndex);
    });

    buttons[searchSelectedIndex].scrollIntoView({
      block: "nearest",
    });
  }

  function activateSearchResult(button) {
    if (!button) {
      return;
    }

    const label = button.dataset.searchTarget;

    const pageMap = {
      Dashboard: "dashboard-home",
      "Permit Application": "permit-application",
    };

    if (pageMap[label]) {
      showDashboardPage(pageMap[label]);
    } else {
      const navLink = [...document.querySelectorAll("[data-nav-link]")].find(
        (link) => {
          return (link.dataset.label || link.textContent.trim()) === label;
        },
      );

      setActive(label, navLink);

      if (label !== "Dashboard") {
        showToast(`${label} is a navigation placeholder for now.`);
      }
    }

    closeSearch();
  }

  getSearchButtons().forEach((button) => {
    button.addEventListener("click", () => {
      activateSearchResult(button);
    });
  });

  // --------------------------------------------------
  // TOAST NOTIFICATIONS
  // --------------------------------------------------

  document.querySelectorAll("[data-toast]").forEach((element) => {
    element.addEventListener("click", (event) => {
      event.preventDefault();

      showToast(element.dataset.toast);

      closeDropdowns();
    });
  });

  // --------------------------------------------------
  // UPLOAD CONFIRMATION MODAL
  // --------------------------------------------------

  function closeUploadConfirmModal() {
    if (!uploadConfirmModal) {
      return;
    }

    uploadConfirmModal.hidden = true;
  }

  function cancelUpload() {
    if (pendingUploadForm) {
      delete pendingUploadForm.dataset.uploadConfirmed;
      delete pendingUploadForm.dataset.submitting;
    }

    pendingUploadForm = null;

    if (confirmUploadButton) {
      confirmUploadButton.disabled = false;
    }

    closeUploadConfirmModal();
  }

  document.querySelectorAll(".permit-upload-form").forEach((form) => {
    form.addEventListener("submit", (event) => {
      if (form.dataset.uploadConfirmed === "true") {
        delete form.dataset.uploadConfirmed;

        return;
      }

      event.preventDefault();

      if (form.dataset.submitting === "true") {
        return;
      }

      if (!uploadConfirmModal || !uploadConfirmMessage) {
        showToast("Unable to open the upload confirmation dialog.");

        return;
      }

      const paymentType = form.querySelector(
        'input[name="payment_type"]',
      )?.value;

      const period = form.querySelector('input[name="period"]')?.value;

      if (paymentType === "full") {
        uploadConfirmMessage.textContent =
          "Are you sure you want to upload your Full Payment receipt? " +
          "Your document will be submitted for SSP Adviser review.";
      } else {
        uploadConfirmMessage.textContent =
          `Are you sure you want to upload your ${period || "permit"} receipt? ` +
          "Your document will be submitted for SSP Adviser review.";
      }

      pendingUploadForm = form;

      uploadConfirmModal.hidden = false;

      if (confirmUploadButton) {
        confirmUploadButton.disabled = false;
      }

      refreshIcons();
    });
  });

  /*
|--------------------------------------------------------------------------
| CONFIRM RECEIPT UPLOAD WITH LOADING SPINNER
|--------------------------------------------------------------------------
*/

  confirmUploadButton?.addEventListener("click", () => {
    const form = pendingUploadForm;

    if (!form || form.dataset.submitting === "true") {
      return;
    }

    // Get the actual receipt upload button.
    const uploadButton = form.querySelector("[data-upload-submit]");

    const uploadLabel = uploadButton?.querySelector("[data-upload-label]");

    const uploadIcon = uploadButton?.querySelector("[data-upload-icon]");

    const uploadSpinner = uploadButton?.querySelector("[data-upload-spinner]");

    // Prevent duplicate submissions.
    form.dataset.submitting = "true";
    form.dataset.uploadConfirmed = "true";

    /*
    |--------------------------------------------------------------------------
    | SHOW UPLOAD LOADING STATE
    |--------------------------------------------------------------------------
    */

    if (uploadButton) {
      uploadButton.disabled = true;

      uploadButton.classList.add("is-loading");

      uploadButton.setAttribute("aria-busy", "true");
    }

    if (uploadLabel) {
      uploadLabel.textContent = "Uploading...";
    }

    if (uploadIcon) {
      uploadIcon.hidden = true;
    }

    if (uploadSpinner) {
      uploadSpinner.hidden = false;
    }

    /*
    |--------------------------------------------------------------------------
    | CLOSE CONFIRMATION MODAL
    |--------------------------------------------------------------------------
    */

    confirmUploadButton.disabled = true;

    closeUploadConfirmModal();

    pendingUploadForm = null;

    /*
    |--------------------------------------------------------------------------
    | SUBMIT RECEIPT
    |--------------------------------------------------------------------------
    */

    if (typeof form.requestSubmit === "function") {
      form.requestSubmit();
    } else {
      form.submit();
    }
  });

  /*
|--------------------------------------------------------------------------
| STORE ORIGINAL UPLOAD BUTTON LABELS
|--------------------------------------------------------------------------
*/

  document.querySelectorAll("[data-upload-submit]").forEach((button) => {
    const label = button.querySelector("[data-upload-label]");

    if (label) {
      button.dataset.originalLabel = label.textContent.trim();
    }
  });

  /*
|--------------------------------------------------------------------------
| RESTORE UPLOAD BUTTONS WHEN RETURNING THROUGH BROWSER HISTORY
|--------------------------------------------------------------------------
*/

  window.addEventListener("pageshow", () => {
    document.querySelectorAll(".permit-upload-form").forEach((form) => {
      delete form.dataset.submitting;
      delete form.dataset.uploadConfirmed;

      const button = form.querySelector("[data-upload-submit]");

      if (!button) {
        return;
      }

      const label = button.querySelector("[data-upload-label]");

      const icon = button.querySelector("[data-upload-icon]");

      const spinner = button.querySelector("[data-upload-spinner]");

      button.disabled = false;

      button.classList.remove("is-loading");

      button.removeAttribute("aria-busy");

      if (label) {
        label.textContent = button.dataset.originalLabel || "Upload Receipt";
      }

      if (icon) {
        icon.hidden = false;
      }

      if (spinner) {
        spinner.hidden = true;
      }
    });
  });

  cancelUploadButton?.addEventListener("click", cancelUpload);

  uploadConfirmModal
    ?.querySelectorAll("[data-close-upload-modal]")
    .forEach((element) => {
      element.addEventListener("click", cancelUpload);
    });

  // --------------------------------------------------
  // KEYBOARD SHORTCUTS
  // --------------------------------------------------

  document.addEventListener("keydown", (event) => {
    const searchIsOpen = searchOverlay && !searchOverlay.hidden;

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") {
      event.preventDefault();

      openSearch();

      return;
    }

    if (event.key === "Escape") {
      if (studentPasswordModal && !studentPasswordModal.hidden) {
        closeStudentPasswordModal();

        return;
      }

      if (studentProfileModal && !studentProfileModal.hidden) {
        closeStudentProfileModal();

        return;
      }

      if (uploadConfirmModal && !uploadConfirmModal.hidden) {
        cancelUpload();

        return;
      }

      if (searchIsOpen) {
        closeSearch();
      }

      closeSidebar();
      closeDropdowns();
    }

    if (!searchIsOpen) {
      return;
    }

    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();

      moveSearchSelection(event.key === "ArrowDown" ? 1 : -1);

      return;
    }

    if (event.key === "Enter" && event.target === searchInput) {
      event.preventDefault();

      const selected = getSearchButtons().find((button) => {
        return !button.hidden && button.classList.contains("selected");
      });

      const firstVisible = getSearchButtons().find((button) => {
        return !button.hidden;
      });

      activateSearchResult(selected || firstVisible);
    }
  });

  // --------------------------------------------------
  // SUCCESS NOTIFICATIONS
  // --------------------------------------------------

  const profileNotice = document.getElementById("student-profile-notice");

  if (profileNotice?.dataset.message) {
    showToast(profileNotice.dataset.message);
  }

  const passwordNotice = document.getElementById("student-password-notice");

  if (passwordNotice?.dataset.message) {
    showToast(passwordNotice.dataset.message);
  }

  // --------------------------------------------------
  // FOOTER YEAR
  // --------------------------------------------------

  const year = document.getElementById("year");

  if (year) {
    year.textContent = String(new Date().getFullYear());
  }

  // --------------------------------------------------
  // INITIALIZE
  // --------------------------------------------------

  refreshIcons();
})();
