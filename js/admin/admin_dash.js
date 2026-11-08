(() => {
  "use strict";

  // =========================================================
  // 1. GLOBAL ELEMENTS AND STATE
  // =========================================================

  const body = document.body;

  const collapseBtn = document.querySelector("[data-collapse-sidebar]");
  const openBtn = document.querySelector("[data-open-sidebar]");
  const closeButtons = document.querySelectorAll("[data-close-sidebar]");

  const mobileQuery = window.matchMedia("(max-width: 820px)");

  const searchOverlay = document.getElementById("search-overlay");
  const searchInput = document.getElementById("search-input");
  const searchResults = document.getElementById("search-results");

  const toast = document.getElementById("toast");
  const toastMessage = document.getElementById("toast-message");

  let toastTimer = null;
  let selectedIndex = -1;

  // =========================================================
  // 2. SHARED HELPERS
  // =========================================================

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
    }, 3000);

    initializeIcons();
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

  function escapeHTML(value) {
    const characters = {
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    };

    return String(value ?? "").replace(
      /[&<>"']/g,
      (character) => characters[character],
    );
  }

  // =========================================================
  // 3. LIVE PHILIPPINE DATE AND TIME
  // =========================================================

  function updatePhilippineDateTime() {
    const now = new Date();

    const dateElement = document.getElementById("live-date");
    const clockElement = document.getElementById("live-clock");

    if (!dateElement || !clockElement) {
      return;
    }

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

  window.setInterval(updatePhilippineDateTime, 1000);

  // =========================================================
  // 4. SIDEBAR
  // =========================================================

  function closeSidebar() {
    body.classList.remove("mobile-sidebar-open");
    openBtn?.setAttribute("aria-expanded", "false");
  }

  openBtn?.addEventListener("click", () => {
    body.classList.add("mobile-sidebar-open");
    openBtn.setAttribute("aria-expanded", "true");
  });

  closeButtons.forEach((button) => {
    button.addEventListener("click", closeSidebar);
  });

  document
    .querySelector(".mobile-scrim")
    ?.addEventListener("click", closeSidebar);

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

  // =========================================================
  // 5. PROFILE DROPDOWN
  // =========================================================

  document.querySelectorAll("[data-dropdown-toggle]").forEach((button) => {
    button.addEventListener("click", (event) => {
      event.stopPropagation();

      const target = document.getElementById(button.dataset.dropdownToggle);

      if (!target) {
        return;
      }

      const willOpen = target.hidden;

      closeDropdowns(button.dataset.dropdownToggle);

      target.hidden = !willOpen;

      button.setAttribute("aria-expanded", String(willOpen));
    });
  });

  document.addEventListener("click", (event) => {
    const target = event.target;

    if (!(target instanceof Element)) {
      return;
    }

    if (!target.closest(".dropdown-wrap") && !target.closest(".dropdown")) {
      closeDropdowns();
    }
  });

  // =========================================================
  // 6. ADMIN PROFILE ELEMENTS
  // =========================================================

  const adminProfileModal = document.getElementById("admin-profile-modal");

  const adminProfileForm = document.getElementById("admin-profile-form");

  const adminPictureForm = document.getElementById("admin-picture-form");

  const adminPictureInput = document.getElementById(
    "admin-profile-picture-input",
  );

  const adminPicturePreview = document.getElementById("admin-picture-preview");

  const adminPictureFilename = document.getElementById(
    "admin-profile-picture-filename",
  );

  const adminUploadPictureButton = document.getElementById(
    "admin-upload-profile-picture",
  );

  const openProfileButtons = document.querySelectorAll(
    "[data-open-profile-modal]",
  );

  const closeProfileButtons = document.querySelectorAll(
    "[data-close-profile-modal]",
  );

  const originalPicturePreview = adminPicturePreview?.innerHTML || "";

  const originalPictureFilename =
    adminPictureFilename?.textContent || "No new picture selected.";

  let profileReturnFocus = null;
  let profilePicturePreviewUrl = null;

  // =========================================================
  // 7. ADMIN PASSWORD ELEMENTS
  // =========================================================

  const adminPasswordModal = document.getElementById("admin-password-modal");

  const adminPasswordForm = document.getElementById("admin-password-form");

  const openPasswordButtons = document.querySelectorAll(
    "[data-open-password-modal]",
  );

  const closePasswordButtons = document.querySelectorAll(
    "[data-close-password-modal]",
  );

  const currentPasswordInput = document.getElementById(
    "admin-current-password",
  );

  const newPasswordInput = document.getElementById("admin-new-password");

  const confirmPasswordInput = document.getElementById(
    "admin-confirm-password",
  );

  let passwordReturnFocus = null;

  // =========================================================
  // 8. PROFILE AND PASSWORD SCROLL LOCK
  // =========================================================

  function updateProfileModalBodyLock() {
    const profileIsOpen = adminProfileModal && !adminProfileModal.hidden;

    const passwordIsOpen = adminPasswordModal && !adminPasswordModal.hidden;

    body.classList.toggle(
      "admin-profile-modal-open",
      Boolean(profileIsOpen || passwordIsOpen),
    );
  }

  // =========================================================
  // 9. PROFILE AND PASSWORD FOCUS TRAP
  // =========================================================

  function trapProfileModalFocus(modal, event) {
    if (event.key !== "Tab" || !modal || modal.hidden) {
      return;
    }

    const focusableElements = [
      ...modal.querySelectorAll(
        "button:not([disabled]):not(.admin-profile-backdrop), " +
          "input:not([disabled]), " +
          "select:not([disabled]), " +
          "textarea:not([disabled]), " +
          "a[href], " +
          '[tabindex]:not([tabindex="-1"])',
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

  // =========================================================
  // 10. RESET PROFILE PICTURE
  // =========================================================

  function resetAdminProfilePictureSelection() {
    if (profilePicturePreviewUrl) {
      URL.revokeObjectURL(profilePicturePreviewUrl);
      profilePicturePreviewUrl = null;
    }

    if (adminPictureInput) {
      adminPictureInput.value = "";
    }

    if (adminPicturePreview) {
      adminPicturePreview.innerHTML = originalPicturePreview;
    }

    if (adminPictureFilename) {
      adminPictureFilename.textContent = originalPictureFilename;
    }

    if (adminUploadPictureButton) {
      adminUploadPictureButton.disabled = true;

      delete adminUploadPictureButton.dataset.submitting;

      adminUploadPictureButton.innerHTML =
        '<i data-lucide="upload"></i> Upload Picture';
    }

    initializeIcons();
  }

  // =========================================================
  // 11. OPEN ADMIN PROFILE
  // =========================================================

  function openAdminProfileModal(trigger = null) {
    if (!adminProfileModal) {
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

    adminProfileModal.hidden = false;

    updateProfileModalBodyLock();

    initializeIcons();

    window.setTimeout(() => {
      adminProfileForm?.querySelector('input[name="firstname"]')?.focus();
    }, 30);
  }

  // =========================================================
  // 12. CLOSE ADMIN PROFILE
  // =========================================================

  function closeAdminProfileModal() {
    if (!adminProfileModal) {
      return;
    }

    adminProfileModal.hidden = true;

    updateProfileModalBodyLock();

    resetAdminProfilePictureSelection();

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
      openAdminProfileModal(button);
    });
  });

  closeProfileButtons.forEach((button) => {
    button.addEventListener("click", closeAdminProfileModal);
  });

  adminProfileModal?.addEventListener("keydown", (event) => {
    trapProfileModalFocus(adminProfileModal, event);
  });

  // =========================================================
  // 13. PROFILE FORM CONFIRMATION
  // =========================================================

  adminProfileForm?.addEventListener("submit", (event) => {
    const confirmed = window.confirm(
      "Are you sure you want to save your profile changes?",
    );

    if (!confirmed) {
      event.preventDefault();
    }
  });

  // =========================================================
  // 14. PROFILE INITIAL STATE
  // =========================================================

  if (adminProfileModal && !adminProfileModal.hidden) {
    updateProfileModalBodyLock();

    profileReturnFocus = document.querySelector(
      '[data-dropdown-toggle="profile-menu"]',
    );

    window.setTimeout(() => {
      adminProfileForm?.querySelector('input[name="firstname"]')?.focus();
    }, 30);
  }

  // =========================================================
  // 15. PROFILE PICTURE PREVIEW
  // =========================================================

  adminPictureInput?.addEventListener("change", () => {
    const file = adminPictureInput.files?.[0];

    if (!file) {
      resetAdminProfilePictureSelection();
      return;
    }

    const maximumFileSize = 2 * 1024 * 1024;

    const allowedTypes = ["image/jpeg", "image/png", "image/webp"];

    if (file.size <= 0) {
      adminPictureInput.value = "";

      resetAdminProfilePictureSelection();

      showToast("Please select a valid image.");

      return;
    }

    if (file.size > maximumFileSize) {
      adminPictureInput.value = "";

      resetAdminProfilePictureSelection();

      showToast("Your profile picture must not exceed 2 MB.");

      return;
    }

    if (!allowedTypes.includes(file.type)) {
      adminPictureInput.value = "";

      resetAdminProfilePictureSelection();

      showToast("Please select a JPG, PNG, or WEBP image.");

      return;
    }

    if (profilePicturePreviewUrl) {
      URL.revokeObjectURL(profilePicturePreviewUrl);
      profilePicturePreviewUrl = null;
    }

    profilePicturePreviewUrl = URL.createObjectURL(file);

    if (adminPicturePreview) {
      const image = document.createElement("img");

      image.src = profilePicturePreviewUrl;
      image.alt = "Selected administrator profile picture preview";

      adminPicturePreview.replaceChildren(image);
    }

    if (adminPictureFilename) {
      adminPictureFilename.textContent = file.name;
    }

    if (adminUploadPictureButton) {
      adminUploadPictureButton.disabled = false;
    }
  });

  // =========================================================
  // 16. PROFILE PICTURE UPLOAD
  // =========================================================

  adminPictureForm?.addEventListener("submit", (event) => {
    const file = adminPictureInput?.files?.[0];

    if (!file) {
      event.preventDefault();

      showToast("Please select a profile picture first.");

      return;
    }

    if (adminUploadPictureButton?.dataset.submitting === "true") {
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

    if (adminUploadPictureButton) {
      adminUploadPictureButton.dataset.submitting = "true";

      adminUploadPictureButton.disabled = true;

      adminUploadPictureButton.innerHTML =
        '<i data-lucide="loader-circle"></i> Uploading...';

      initializeIcons();
    }
  });

  // =========================================================
  // 17. OPEN PASSWORD MODAL
  // =========================================================

  function openAdminPasswordModal(trigger = null) {
    if (!adminPasswordModal) {
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

    adminPasswordModal.hidden = false;

    updateProfileModalBodyLock();

    initializeIcons();

    window.setTimeout(() => {
      currentPasswordInput?.focus();
    }, 30);
  }

  // =========================================================
  // 18. CLOSE PASSWORD MODAL
  // =========================================================

  function closeAdminPasswordModal() {
    if (!adminPasswordModal) {
      return;
    }

    adminPasswordModal.hidden = true;

    updateProfileModalBodyLock();

    adminPasswordForm?.reset();

    adminPasswordForm?.querySelectorAll("input").forEach((input) => {
      input.setCustomValidity("");

      if (input.type === "text") {
        input.type = "password";
      }
    });

    const passwordLabels = {
      "admin-current-password": "Show current password",
      "admin-new-password": "Show new password",
      "admin-confirm-password": "Show confirmation password",
    };

    adminPasswordForm
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

    initializeIcons();

    if (
      passwordReturnFocus instanceof HTMLElement &&
      passwordReturnFocus.isConnected
    ) {
      passwordReturnFocus.focus();
    }

    passwordReturnFocus = null;
  }

  openPasswordButtons.forEach((button) => {
    button.addEventListener("click", (event) => {
      event.preventDefault();
      openAdminPasswordModal(button);
    });
  });

  closePasswordButtons.forEach((button) => {
    button.addEventListener("click", closeAdminPasswordModal);
  });

  if (adminPasswordModal && !adminPasswordModal.hidden) {
    updateProfileModalBodyLock();

    passwordReturnFocus = document.querySelector(
      '[data-dropdown-toggle="profile-menu"]',
    );

    window.setTimeout(() => {
      currentPasswordInput?.focus();
    }, 30);
  }

  adminPasswordModal?.addEventListener("keydown", (event) => {
    trapProfileModalFocus(adminPasswordModal, event);
  });

  // =========================================================
  // 19. PASSWORD VISIBILITY
  // =========================================================

  adminPasswordForm
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
          "admin-current-password": isVisible
            ? "Show current password"
            : "Hide current password",

          "admin-new-password": isVisible
            ? "Show new password"
            : "Hide new password",

          "admin-confirm-password": isVisible
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

        initializeIcons();
      });
    });

  // =========================================================
  // 20. PASSWORD VALIDATION
  // =========================================================

  function validateAdminPasswords() {
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

    return adminPasswordForm?.checkValidity() ?? true;
  }

  currentPasswordInput?.addEventListener("input", validateAdminPasswords);

  newPasswordInput?.addEventListener("input", validateAdminPasswords);

  confirmPasswordInput?.addEventListener("input", validateAdminPasswords);

  // =========================================================
  // 21. PASSWORD UPDATE CONFIRMATION
  // =========================================================

  adminPasswordForm?.addEventListener("submit", (event) => {
    validateAdminPasswords();

    if (!adminPasswordForm.checkValidity()) {
      event.preventDefault();

      adminPasswordForm.reportValidity();

      return;
    }

    const confirmed = window.confirm(
      "Are you sure you want to change your password?\n\n" +
        "You will need to use your new password the next time you sign in.",
    );

    if (!confirmed) {
      event.preventDefault();
    }
  });

  // =========================================================
  // 22. PROFILE AND PASSWORD NOTIFICATIONS
  // =========================================================

  const profileNotice = document.getElementById("admin-profile-notice");

  if (profileNotice?.dataset.message) {
    showToast(profileNotice.dataset.message);
  }

  const passwordNotice = document.getElementById("admin-password-notice");

  if (passwordNotice?.dataset.message) {
    showToast(passwordNotice.dataset.message);
  }

  // =========================================================
  // 23. PLACEHOLDER NOTIFICATIONS
  // =========================================================

  document.querySelectorAll("[data-toast]").forEach((element) => {
    element.addEventListener("click", (event) => {
      event.preventDefault();

      showToast(element.dataset.toast);

      closeDropdowns();
    });
  });

  // =========================================================
  // 24. GLOBAL SEARCH HELPERS
  // =========================================================

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

    const emptyMessage = searchResults?.querySelector(".search-no-results");

    if (emptyMessage) {
      emptyMessage.hidden = visibleCount > 0;
    }

    selectedIndex = -1;
  }

  // =========================================================
  // 25. OPEN GLOBAL SEARCH
  // =========================================================

  function openSearch() {
    if (!searchOverlay) {
      return;
    }

    closeSidebar();
    closeDropdowns();

    searchOverlay.hidden = false;

    body.classList.add("search-open");

    filterSearch(searchInput?.value || "");

    window.setTimeout(() => {
      searchInput?.focus();
    }, 20);
  }

  // =========================================================
  // 26. CLOSE GLOBAL SEARCH
  // =========================================================

  function closeSearch() {
    if (!searchOverlay) {
      return;
    }

    searchOverlay.hidden = true;

    body.classList.remove("search-open");

    document.querySelector("[data-open-search]")?.focus();
  }

  // =========================================================
  // 27. GLOBAL SEARCH NAVIGATION
  // =========================================================

  function activateSearch(button) {
    if (!button) {
      return;
    }

    const destinations = {
      Dashboard: "dashboard.php",

      "Section & Student Management": "dashboard.php?page=sections",
    };

    const destination = destinations[button.dataset.searchTarget];

    if (destination) {
      window.location.href = destination;
      return;
    }

    closeSearch();
  }

  function moveSelection(direction) {
    const visibleButtons = getSearchButtons().filter(
      (button) => !button.hidden,
    );

    if (!visibleButtons.length) {
      return;
    }

    selectedIndex =
      (selectedIndex + direction + visibleButtons.length) %
      visibleButtons.length;

    visibleButtons.forEach((button, index) => {
      button.classList.toggle("selected", index === selectedIndex);
    });

    visibleButtons[selectedIndex].scrollIntoView({
      block: "nearest",
    });
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

  getSearchButtons().forEach((button) => {
    button.addEventListener("click", () => {
      activateSearch(button);
    });
  });

  // =========================================================
  // 28. SIDEBAR NAVIGATION
  // =========================================================

  document.querySelectorAll("[data-nav-link]").forEach((link) => {
    link.addEventListener("click", closeSidebar);
  });

  // =========================================================
  // 29. FOOTER YEAR
  // =========================================================

  const footerYear = document.getElementById("year");

  if (footerYear) {
    footerYear.textContent = String(new Date().getFullYear());
  }

  // =========================================================
  // 30. SECTION SEARCH AND YEAR FILTER
  // =========================================================

  const sectionSearch = document.getElementById("section-search");

  const sectionYearFilter = document.getElementById("section-year-filter");

  const sectionSearchStatus = document.getElementById("section-search-status");

  const sectionSearchEmpty = document.getElementById("section-search-empty");

  const sectionSearchEmptyTitle = document.getElementById(
    "section-search-empty-title",
  );

  const sectionSearchEmptyDescription = document.getElementById(
    "section-search-empty-description",
  );

  let sectionCards = [...document.querySelectorAll(".searchable-section-card")];

  function filterSections() {
    if (!sectionSearch && !sectionYearFilter) {
      return;
    }

    const query = sectionSearch ? sectionSearch.value.trim().toLowerCase() : "";

    const selectedYear = sectionYearFilter ? sectionYearFilter.value : "all";

    const hasSearch = query !== "";
    const hasYearFilter = selectedYear !== "all";

    sectionCards.forEach((card) => {
      card.hidden = true;
    });

    if (!hasSearch && !hasYearFilter) {
      if (sectionSearchStatus) {
        sectionSearchStatus.textContent =
          "No search results yet. Start typing to find a section, or create a new one.";
      }

      if (sectionSearchEmpty) {
        sectionSearchEmpty.hidden = true;
      }

      return;
    }

    const matchingCards = sectionCards.filter((card) => {
      const searchableText = (card.dataset.sectionSearch || "").toLowerCase();

      const cardYearLevel = String(card.dataset.sectionYearLevel || "");

      const matchesSearch = !hasSearch || searchableText.includes(query);

      const matchesYear = !hasYearFilter || cardYearLevel === selectedYear;

      return matchesSearch && matchesYear;
    });

    if (matchingCards.length > 0) {
      matchingCards.forEach((card) => {
        card.hidden = false;
      });

      if (sectionSearchStatus) {
        const count = matchingCards.length;

        sectionSearchStatus.textContent =
          count === 1
            ? "1 matching section found."
            : `${count} matching sections found.`;
      }

      if (sectionSearchEmpty) {
        sectionSearchEmpty.hidden = true;
      }

      return;
    }

    if (sectionSearchStatus) {
      sectionSearchStatus.textContent = "No matching section found.";
    }

    if (sectionSearchEmpty) {
      sectionSearchEmpty.hidden = false;
    }

    if (sectionSearchEmptyTitle) {
      sectionSearchEmptyTitle.textContent =
        sectionCards.length === 0
          ? "No sections have been created yet."
          : "No matching sections";
    }

    if (sectionSearchEmptyDescription) {
      sectionSearchEmptyDescription.textContent =
        sectionCards.length === 0
          ? "Click Create Section to add your first section."
          : "Try another search term or choose a different year level.";
    }
  }

  sectionSearch?.addEventListener("input", filterSections);

  sectionYearFilter?.addEventListener("change", filterSections);

  filterSections();

  // =========================================================
  // 31. SECTION EXPORT LIBRARY LOADER
  // =========================================================

  const sectionExportLibraries = new Map();

  const sectionExportLibraryUrls = {
    excel: "https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js",

    word: "https://unpkg.com/docx@9.15.1/dist/index.iife.js",
  };

  function loadSectionExportLibrary(url, globalName) {
    if (window[globalName]) {
      return Promise.resolve(window[globalName]);
    }

    if (sectionExportLibraries.has(url)) {
      return sectionExportLibraries.get(url);
    }

    const promise = new Promise((resolve, reject) => {
      const script = document.createElement("script");

      script.src = url;
      script.async = true;

      script.onload = () => {
        if (window[globalName]) {
          resolve(window[globalName]);
        } else {
          reject(new Error("The export library could not be initialized."));
        }
      };

      script.onerror = () => {
        reject(
          new Error(
            "Unable to load the export library. Please check your internet connection.",
          ),
        );
      };

      document.head.appendChild(script);
    });

    sectionExportLibraries.set(url, promise);

    return promise.catch((error) => {
      sectionExportLibraries.delete(url);
      throw error;
    });
  }

  // =========================================================
  // 32. SECTION EXPORT HELPERS
  // =========================================================

  function getSectionExportTimestamp() {
    return new Intl.DateTimeFormat("en-PH", {
      timeZone: "Asia/Manila",
      dateStyle: "long",
      timeStyle: "short",
    }).format(new Date());
  }

  function sanitizeSectionExportFilename(value) {
    return String(value || "")
      .normalize("NFKD")
      .replace(/[\u0300-\u036f]/g, "")
      .replace(/[^a-zA-Z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "")
      .toLowerCase();
  }

  function getSectionExportFilename(data) {
    const course = sanitizeSectionExportFilename(data.course);

    const section = sanitizeSectionExportFilename(data.sectionName);

    const filename = [course, section].filter(Boolean).join("-");

    return `acadtrack-${filename || "section"}-export`;
  }

  function escapeSectionExportHTML(value) {
    const characters = {
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    };

    return String(value ?? "").replace(
      /[&<>"']/g,
      (character) => characters[character],
    );
  }

  // =========================================================
  // 33. COLLECT SECTION AND STUDENT INFORMATION
  // =========================================================

  function collectSectionExportData(card) {
    const yearLabels = {
      1: "1st Year",
      2: "2nd Year",
      3: "3rd Year",
      4: "4th Year",
    };

    const sectionName =
      card.querySelector(".managed-section-title h3")?.textContent.trim() ||
      "Unnamed Section";

    const course = card.dataset.sectionCourse || "";

    const yearValue = card.dataset.sectionYearLevel || "";

    const yearLevel =
      yearLabels[yearValue] ||
      (yearValue ? `Year ${yearValue}` : "Not specified");

    const adviserElement = card.querySelector(".section-current-adviser");

    const adviserText =
      adviserElement?.textContent.replace(/\s+/g, " ").trim() || "No Adviser";

    const adviser = adviserText.replace(/^SSP Adviser:\s*/i, "");

    const students = [...card.querySelectorAll(".section-student-row")].map(
      (row) => {
        const editButton = row.querySelector(".edit-student-button");

        const details = editButton?.dataset || {};

        const nickname =
          details.studentNickname ||
          row
            .querySelector(".section-student-info strong")
            ?.textContent.trim() ||
          "";

        const firstname = details.studentFirstname || "";
        const middlename = details.studentMiddlename || "";
        const lastname = details.studentLastname || "";

        const fullName =
          [firstname, middlename, lastname].filter(Boolean).join(" ") ||
          nickname;

        const smallText =
          row
            .querySelector(".section-student-info small")
            ?.textContent.trim() || "";

        const studentNumber =
          details.studentNumber || smallText.split("·")[0].trim();

        return {
          studentNumber,
          firstname,
          middlename,
          lastname,
          fullName,
          nickname,
          email: details.studentEmail || "",
          age: details.studentAge || "",
          gender: details.studentGender || "",
          mobileNumber: details.studentMobileNumber || "",
          address: details.studentAddress || "",
          course,
          yearLevel,
          section: sectionName,
        };
      },
    );

    return {
      sectionName,
      course,
      yearLevel,
      adviser: adviser || "No Adviser",
      totalStudents: students.length,
      students,
      generatedAt: getSectionExportTimestamp(),
    };
  }

  // =========================================================
  // 34. DOWNLOAD EXPORTED FILE
  // =========================================================

  function downloadSectionExportFile(blob, filename) {
    const url = URL.createObjectURL(blob);

    const link = document.createElement("a");

    link.href = url;
    link.download = filename;
    link.style.display = "none";

    document.body.appendChild(link);

    link.click();

    link.remove();

    window.setTimeout(() => {
      URL.revokeObjectURL(url);
    }, 1000);
  }

  // =========================================================
  // 35. EXPORT SECTION AS PDF
  // =========================================================

  function exportSectionToPDF(data) {
    const printWindow = window.open("", "_blank");

    if (!printWindow) {
      throw new Error(
        "The PDF window was blocked. Please allow pop-ups for AcadTrack.",
      );
    }

    const tableRows = data.students.length
      ? data.students
          .map(
            (student) => `
                <tr>
                    <td>${escapeSectionExportHTML(student.studentNumber)}</td>
                    <td>${escapeSectionExportHTML(student.fullName)}</td>
                    <td>${escapeSectionExportHTML(student.email)}</td>
                    <td>${escapeSectionExportHTML(student.age || "—")}</td>
                    <td>${escapeSectionExportHTML(student.gender || "—")}</td>
                    <td>${escapeSectionExportHTML(student.mobileNumber || "—")}</td>
                </tr>
            `,
          )
          .join("")
      : `
                <tr>
                    <td colspan="6" class="empty-row">
                        No students assigned to this section yet.
                    </td>
                </tr>
            `;

    const html = `
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">

                <title>${escapeSectionExportHTML(data.sectionName)} - AcadTrack</title>

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
                        color: #101828;
                    }

                    .report-header p {
                        margin: 6px 0 0;
                        color: #667085;
                        font-size: 11px;
                    }

                    .report-subtitle {
                        margin-top: 15px;
                        font-size: 12px;
                        font-weight: 600;
                        color: #465fff;
                    }

                    .report-details {
                        display: grid;
                        grid-template-columns: repeat(3, 1fr);
                        gap: 12px;
                        margin-bottom: 23px;
                    }

                    .detail-item {
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
                        font-size: 12px;
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
                        padding: 10px 8px;
                        border: 1px solid #d0d5dd;
                        background: #f2f4f7;
                        color: #344054;
                        font-size: 9px;
                        text-align: left;
                    }

                    td {
                        padding: 9px 8px;
                        border: 1px solid #eaecf0;
                        color: #344054;
                        font-size: 9px;
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
                        margin: 14mm;
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
                        SECTION AND STUDENT ROSTER
                    </div>
                </div>

                <div class="report-details">
                    <div class="detail-item">
                        <span>Section</span>
                        <strong>${escapeSectionExportHTML(data.sectionName)}</strong>
                    </div>

                    <div class="detail-item">
                        <span>Course</span>
                        <strong>${escapeSectionExportHTML(data.course || "—")}</strong>
                    </div>

                    <div class="detail-item">
                        <span>Year Level</span>
                        <strong>${escapeSectionExportHTML(data.yearLevel)}</strong>
                    </div>

                    <div class="detail-item">
                        <span>SSP Adviser</span>
                        <strong>${escapeSectionExportHTML(data.adviser)}</strong>
                    </div>

                    <div class="detail-item">
                        <span>Total Assigned Students</span>
                        <strong>${data.totalStudents}</strong>
                    </div>

                    <div class="detail-item">
                        <span>Generated At</span>
                        <strong>${escapeSectionExportHTML(data.generatedAt)}</strong>
                    </div>
                </div>

                <h2 class="table-heading">Enrolled Students</h2>

                <table>
                    <thead>
                        <tr>
                            <th>Student Number</th>
                            <th>Full Name</th>
                            <th>Email Address</th>
                            <th>Age</th>
                            <th>Gender</th>
                            <th>Mobile Number</th>
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
        printWindow.focus();
        printWindow.print();
      }
    }, 400);

    showToast("PDF report prepared. Select Save as PDF in the print dialog.");
  }

  // =========================================================
  // 36. EXPORT SECTION AS EXCEL
  // =========================================================

  async function exportSectionToExcel(data) {
    const XLSX = await loadSectionExportLibrary(
      sectionExportLibraryUrls.excel,
      "XLSX",
    );

    const filename = getSectionExportFilename(data);

    const summaryData = [
      ["ACADTRACK - SECTION EXPORT"],
      ["Generated At", data.generatedAt],
      [],
      ["SECTION DETAILS", "INFORMATION"],
      ["Section", data.sectionName],
      ["Course", data.course],
      ["Year Level", data.yearLevel],
      ["SSP Adviser", data.adviser],
      ["Total Assigned Students", data.totalStudents],
    ];

    const summarySheet = XLSX.utils.aoa_to_sheet(summaryData);

    summarySheet["!cols"] = [{ wch: 28 }, { wch: 35 }];

    const studentHeaders = [
      "Student Number",
      "Firstname",
      "Middlename",
      "Lastname",
      "Full Name",
      "Nickname",
      "Email Address",
      "Age",
      "Gender",
      "Mobile Number",
      "Address",
      "Course",
      "Year Level",
      "Section",
    ];

    const studentRows = data.students.map((student) => [
      student.studentNumber,
      student.firstname,
      student.middlename,
      student.lastname,
      student.fullName,
      student.nickname,
      student.email,
      student.age,
      student.gender,
      student.mobileNumber,
      student.address,
      student.course,
      student.yearLevel,
      student.section,
    ]);

    const studentSheet = XLSX.utils.aoa_to_sheet([
      studentHeaders,
      ...studentRows,
    ]);

    studentSheet["!cols"] = [
      { wch: 18 },
      { wch: 18 },
      { wch: 18 },
      { wch: 18 },
      { wch: 30 },
      { wch: 20 },
      { wch: 32 },
      { wch: 8 },
      { wch: 12 },
      { wch: 18 },
      { wch: 40 },
      { wch: 12 },
      { wch: 15 },
      { wch: 22 },
    ];

    studentSheet["!autofilter"] = {
      ref: XLSX.utils.encode_range({
        s: { r: 0, c: 0 },
        e: {
          r: Math.max(studentRows.length, 1),
          c: studentHeaders.length - 1,
        },
      }),
    };

    const workbook = XLSX.utils.book_new();

    XLSX.utils.book_append_sheet(workbook, summarySheet, "Section Summary");

    XLSX.utils.book_append_sheet(workbook, studentSheet, "Enrolled Students");

    workbook.Props = {
      Title: `AcadTrack - ${data.sectionName}`,
      Subject: "Section and Enrolled Students Report",
      Author: "AcadTrack",
      Company: "Saint Jude College - PHINMA Education",
    };

    XLSX.writeFile(workbook, `${filename}.xlsx`, { compression: true });

    showToast("Excel report exported successfully.");
  }

  // =========================================================
  // 37. EXPORT SECTION AS WORD
  // =========================================================

  async function exportSectionToWord(data) {
    const docx = await loadSectionExportLibrary(
      sectionExportLibraryUrls.word,
      "docx",
    );

    const filename = getSectionExportFilename(data);

    const { Document, Paragraph, TextRun, Table, TableRow, TableCell, Packer } =
      docx;

    function createWordCell(value, bold = false) {
      return new TableCell({
        children: [
          new Paragraph({
            children: [
              new TextRun({
                text: String(value ?? ""),
                bold,
                size: 18,
              }),
            ],
          }),
        ],
      });
    }

    const documentChildren = [
      new Paragraph({
        children: [
          new TextRun({
            text: "AcadTrack",
            bold: true,
            size: 36,
            color: "465FFF",
          }),
        ],
        spacing: {
          after: 100,
        },
      }),

      new Paragraph({
        children: [
          new TextRun({
            text: "Student Permit Monitoring and Academic Tracking System",
            size: 20,
            color: "667085",
          }),
        ],
        spacing: {
          after: 300,
        },
      }),

      new Paragraph({
        children: [
          new TextRun({
            text: "SECTION AND STUDENT ROSTER",
            bold: true,
            size: 28,
          }),
        ],
        spacing: {
          before: 150,
          after: 250,
        },
      }),
    ];

    const metadata = [
      ["Section", data.sectionName],
      ["Course", data.course || "—"],
      ["Year Level", data.yearLevel],
      ["SSP Adviser", data.adviser],
      ["Total Assigned Students", String(data.totalStudents)],
      ["Generated At", data.generatedAt],
    ];

    metadata.forEach(([label, value]) => {
      documentChildren.push(
        new Paragraph({
          children: [
            new TextRun({
              text: `${label}: `,
              bold: true,
              size: 21,
            }),

            new TextRun({
              text: String(value),
              size: 21,
            }),
          ],
          spacing: {
            after: 100,
          },
        }),
      );
    });

    documentChildren.push(
      new Paragraph({
        children: [
          new TextRun({
            text: "Enrolled Students",
            bold: true,
            size: 25,
          }),
        ],
        spacing: {
          before: 350,
          after: 180,
        },
      }),
    );

    const headers = [
      "Student Number",
      "Full Name",
      "Email Address",
      "Age",
      "Gender",
      "Mobile Number",
    ];

    const tableRows = [
      new TableRow({
        children: headers.map((header) => {
          return createWordCell(header, true);
        }),
      }),
    ];

    if (data.students.length > 0) {
      data.students.forEach((student) => {
        tableRows.push(
          new TableRow({
            children: [
              createWordCell(student.studentNumber),
              createWordCell(student.fullName),
              createWordCell(student.email),
              createWordCell(student.age || "—"),
              createWordCell(student.gender || "—"),
              createWordCell(student.mobileNumber || "—"),
            ],
          }),
        );
      });
    } else {
      tableRows.push(
        new TableRow({
          children: [
            createWordCell("No students assigned yet."),
            createWordCell(""),
            createWordCell(""),
            createWordCell(""),
            createWordCell(""),
            createWordCell(""),
          ],
        }),
      );
    }

    documentChildren.push(
      new Table({
        rows: tableRows,
      }),
    );

    documentChildren.push(
      new Paragraph({
        children: [
          new TextRun({
            text: "AcadTrack | Saint Jude College - PHINMA Education",
            size: 16,
            color: "667085",
          }),
        ],
        spacing: {
          before: 300,
        },
      }),
    );

    const wordDocument = new Document({
      sections: [
        {
          properties: {
            page: {
              size: {
                width: 15840,
                height: 12240,
              },
              margin: {
                top: 720,
                right: 720,
                bottom: 720,
                left: 720,
              },
            },
          },
          children: documentChildren,
        },
      ],
    });

    const blob = await Packer.toBlob(wordDocument);

    downloadSectionExportFile(blob, `${filename}.docx`);

    showToast("Word report exported successfully.");
  }

  // =========================================================
  // 38. SECTION EXPORT FORMAT HANDLER
  // =========================================================

  async function exportSection(card, format) {
    if (!card) {
      throw new Error("Unable to identify the selected section.");
    }

    const data = collectSectionExportData(card);

    switch (format) {
      case "pdf":
        exportSectionToPDF(data);
        break;

      case "excel":
        await exportSectionToExcel(data);
        break;

      case "word":
        await exportSectionToWord(data);
        break;

      default:
        throw new Error("Invalid export format.");
    }
  }

  // =========================================================
  // 39. SECTION EXPORT DROPDOWN
  // =========================================================

  function closeSectionExportMenus(exceptWrap = null) {
    document.querySelectorAll(".section-export-wrap").forEach((wrap) => {
      if (wrap === exceptWrap) {
        return;
      }

      const menu = wrap.querySelector(".section-export-menu");

      const toggle = wrap.querySelector("[data-section-export-toggle]");

      if (menu) {
        menu.hidden = true;
      }

      toggle?.setAttribute("aria-expanded", "false");

      wrap
        .closest(".managed-section-card")
        ?.classList.remove("has-export-menu-open");
    });
  }

  document.addEventListener("click", async (event) => {
    const target = event.target;

    if (!(target instanceof Element)) {
      return;
    }

    const toggle = target.closest("[data-section-export-toggle]");

    if (toggle) {
      event.preventDefault();

      const wrap = toggle.closest(".section-export-wrap");

      const menu = wrap?.querySelector(".section-export-menu");

      if (!wrap || !menu) {
        return;
      }

      const shouldOpen = menu.hidden;

      closeSectionExportMenus(wrap);

      menu.hidden = !shouldOpen;

      toggle.setAttribute("aria-expanded", String(shouldOpen));

      wrap
        .closest(".managed-section-card")
        ?.classList.toggle("has-export-menu-open", shouldOpen);

      return;
    }

    const exportOption = target.closest("[data-section-export]");

    if (exportOption) {
      event.preventDefault();

      if (exportOption.disabled) {
        return;
      }

      const card = exportOption.closest(".searchable-section-card");

      const format = exportOption.dataset.sectionExport;

      closeSectionExportMenus();

      exportOption.disabled = true;

      try {
        await exportSection(card, format);
      } catch (error) {
        console.error("AcadTrack section export error:", error);

        showToast(
          error instanceof Error
            ? error.message
            : "Unable to export the section. Please try again.",
        );
      } finally {
        exportOption.disabled = false;
      }

      return;
    }

    if (!target.closest(".section-export-wrap")) {
      closeSectionExportMenus();
    }
  });

  // =========================================================
  // 40. SECTION MODAL ELEMENTS
  // =========================================================

  const sectionModalElements = {
    addStudent: document.getElementById("add-student-modal"),
    assignAdviser: document.getElementById("assign-adviser-modal"),
    editStudent: document.getElementById("edit-student-modal"),
    changeSection: document.getElementById("change-section-modal"),
    createSection: document.getElementById("create-section-modal"),
  };

  const studentModal = sectionModalElements.addStudent;
  const adviserModal = sectionModalElements.assignAdviser;
  const editStudentModal = sectionModalElements.editStudent;
  const changeSectionModal = sectionModalElements.changeSection;
  const createSectionModal = sectionModalElements.createSection;

  let activeSectionModal = null;
  let sectionModalReturnFocus = null;

  // =========================================================
  // 41. MODAL BODY SCROLL LOCK
  // =========================================================

  function updateSectionModalScrollLock() {
    const modalIsOpen = Object.values(sectionModalElements).some(
      (modal) => modal && !modal.hidden,
    );

    body.classList.toggle("section-modal-open", modalIsOpen);
  }

  // =========================================================
  // 42. MODAL FOCUS MANAGEMENT
  // =========================================================

  function getSectionModalFocusableElements(modal) {
    if (!modal) {
      return [];
    }

    return [
      ...modal.querySelectorAll(
        "button:not([disabled]), " +
          "input:not([disabled]), " +
          "select:not([disabled]), " +
          "textarea:not([disabled]), " +
          "a[href], " +
          '[tabindex]:not([tabindex="-1"])',
      ),
    ].filter((element) => {
      return (
        !element.closest("[hidden]") && element.getClientRects().length > 0
      );
    });
  }

  // =========================================================
  // 43. CLOSE ACTIVE SECTION MODAL
  // =========================================================

  function closeActiveSectionModal(restoreFocus = true) {
    if (!activeSectionModal) {
      updateSectionModalScrollLock();
      return;
    }

    const modalToClose = activeSectionModal;

    modalToClose.hidden = true;

    activeSectionModal = null;

    updateSectionModalScrollLock();

    const returnElement = sectionModalReturnFocus;

    sectionModalReturnFocus = null;

    if (
      restoreFocus &&
      returnElement instanceof HTMLElement &&
      returnElement.isConnected
    ) {
      returnElement.focus();
    }
  }

  // =========================================================
  // 44. OPEN SECTION MODAL
  // =========================================================

  function openSectionModal(modal, trigger, focusSelector = null) {
    if (!modal) {
      return;
    }

    if (activeSectionModal) {
      closeActiveSectionModal(false);
    }

    // Make sure only one section-management modal is open.
    Object.values(sectionModalElements).forEach((otherModal) => {
      if (otherModal && otherModal !== modal) {
        otherModal.hidden = true;
      }
    });

    sectionModalReturnFocus =
      trigger instanceof HTMLElement ? trigger : document.activeElement;

    activeSectionModal = modal;

    modal.hidden = false;

    updateSectionModalScrollLock();

    initializeIcons();

    window.requestAnimationFrame(() => {
      const initialFocus = focusSelector
        ? modal.querySelector(focusSelector)
        : getSectionModalFocusableElements(modal)[0];

      initialFocus?.focus();
    });
  }

  // =========================================================
  // 45. MODAL HINT HELPER
  // =========================================================

  function setModalHint(element, message, type = "") {
    if (!element) {
      return;
    }

    element.textContent = message;

    element.className = "student-search-hint";

    if (type) {
      element.classList.add(`is-${type}`);
    }
  }

  // =========================================================
  // 46. ADD STUDENT ELEMENTS
  // =========================================================

  const studentSearch = studentModal?.querySelector("#modal-student-search");

  const studentSearchContainer = studentModal?.querySelector(
    "#student-search-container",
  );

  const studentResults = studentModal?.querySelector("#modal-student-results");

  const studentIdField = studentModal?.querySelector("#modal-student-id");

  const selectedStudentCard = studentModal?.querySelector(
    "#selected-student-card",
  );

  const selectedStudentName = studentModal?.querySelector(
    "#selected-student-name",
  );

  const studentSearchHint = studentModal?.querySelector("#student-search-hint");

  const selectedStudentChangeButton = studentModal?.querySelector(
    "#change-selected-student",
  );

  const studentInfoFields = {
    studentNumber: studentModal?.querySelector("#selected-student-number"),

    firstname: studentModal?.querySelector("#selected-student-firstname"),

    middlename: studentModal?.querySelector("#selected-student-middlename"),

    lastname: studentModal?.querySelector("#selected-student-lastname"),

    course: studentModal?.querySelector("#selected-student-course"),

    yearLevel: studentModal?.querySelector("#selected-student-year-level"),
  };

  // =========================================================
  // 47. CLEAR SELECTED STUDENT
  // =========================================================

  function clearSelectedStudent() {
    if (studentIdField) {
      studentIdField.value = "";
    }

    if (selectedStudentCard) {
      selectedStudentCard.hidden = true;
    }

    if (selectedStudentName) {
      selectedStudentName.textContent = "";
    }

    Object.values(studentInfoFields).forEach((field) => {
      if (field) {
        field.value = "";
      }
    });
  }

  // =========================================================
  // 48. RESET ADD STUDENT MODAL
  // =========================================================

  function resetAddStudentModal() {
    if (studentSearch) {
      studentSearch.value = "";
    }

    clearSelectedStudent();

    studentResults
      ?.querySelectorAll(".student-search-result")
      .forEach((result) => {
        result.hidden = false;
      });

    if (studentResults) {
      studentResults.hidden = true;
    }

    setModalHint(
      studentSearchHint,
      "Type a student's name or student number to search.",
    );
  }

  // =========================================================
  // 49. FILTER AVAILABLE STUDENTS
  // =========================================================

  function filterStudentResults() {
    if (!studentSearch || !studentResults) {
      return;
    }

    const query = studentSearch.value.trim().toLowerCase();

    const results = [
      ...studentResults.querySelectorAll(".student-search-result"),
    ];

    if (!query) {
      results.forEach((result) => {
        result.hidden = false;
      });

      studentResults.hidden = true;

      setModalHint(
        studentSearchHint,
        "Type a student's name or student number to search.",
      );

      return;
    }

    let matchCount = 0;

    results.forEach((result) => {
      const searchableText = (result.dataset.search || "").toLowerCase();

      const matches = searchableText.includes(query);

      result.hidden = !matches;

      if (matches) {
        matchCount++;
      }
    });

    studentResults.hidden = matchCount === 0;

    if (matchCount === 0) {
      setModalHint(
        studentSearchHint,
        "No available students found. Try another name or student number.",
        "error",
      );

      return;
    }

    setModalHint(
      studentSearchHint,
      `${matchCount} matching student${matchCount === 1 ? "" : "s"}. Select a student to view their information.`,
      "success",
    );
  }

  // =========================================================
  // 50. SELECT AVAILABLE STUDENT
  // =========================================================

  function selectAvailableStudent(button) {
    if (!button || !studentIdField) {
      return;
    }

    const data = button.dataset;

    if (!data.studentId) {
      setModalHint(
        studentSearchHint,
        "Unable to select this student. Please try again.",
        "error",
      );

      return;
    }

    studentIdField.value = data.studentId;

    const values = {
      studentNumber: data.studentNumber || "",
      firstname: data.firstname || "",
      middlename: data.middlename || "",
      lastname: data.lastname || "",
      course: data.course || "",
      yearLevel: data.yearLevel || "",
    };

    Object.entries(studentInfoFields).forEach(([key, field]) => {
      if (field) {
        field.value = values[key] || "";
      }
    });

    if (selectedStudentName) {
      selectedStudentName.textContent = data.fullName || "Selected Student";
    }

    if (studentSearch) {
      studentSearch.value = data.fullName || "";
    }

    if (studentResults) {
      studentResults.hidden = true;
    }

    if (selectedStudentCard) {
      selectedStudentCard.hidden = false;
    }

    setModalHint(
      studentSearchHint,
      "Student selected. Review the information before adding.",
      "success",
    );

    initializeIcons();
  }

  // =========================================================
  // 51. ADD STUDENT SEARCH EVENTS
  // =========================================================

  studentSearch?.addEventListener("input", () => {
    clearSelectedStudent();
    filterStudentResults();
  });

  studentResults?.addEventListener("click", (event) => {
    const target = event.target;

    if (!(target instanceof Element)) {
      return;
    }

    const result = target.closest(".student-search-result");

    if (result && studentResults.contains(result)) {
      selectAvailableStudent(result);
    }
  });

  selectedStudentChangeButton?.addEventListener("click", () => {
    clearSelectedStudent();

    if (studentSearch) {
      studentSearch.value = "";
    }

    if (studentResults) {
      studentResults.hidden = true;
    }

    setModalHint(
      studentSearchHint,
      "Type a student's name or student number to search.",
    );

    studentSearch?.focus();
  });

  studentModal?.querySelector("form")?.addEventListener("submit", (event) => {
    if (!studentIdField?.value) {
      event.preventDefault();

      setModalHint(
        studentSearchHint,
        "Please search for and select a student before adding.",
        "error",
      );

      studentSearch?.focus();
    }
  });

  // =========================================================
  // 52. ASSIGN SSP ADVISER ELEMENTS
  // =========================================================

  const adviserSearch = adviserModal?.querySelector("#modal-adviser-search");

  const adviserSearchContainer = adviserModal?.querySelector(
    "#adviser-search-container",
  );

  const adviserResults = adviserModal?.querySelector("#modal-adviser-results");

  const adviserIdField = adviserModal?.querySelector("#modal-adviser-id");

  const adviserSearchHint = adviserModal?.querySelector("#adviser-search-hint");

  // =========================================================
  // 53. RESET ADVISER MODAL
  // =========================================================

  function resetAdviserModal() {
    if (adviserSearch) {
      adviserSearch.value = "";
    }

    if (adviserIdField) {
      adviserIdField.value = "";
    }

    adviserResults
      ?.querySelectorAll(".faculty-search-result")
      .forEach((result) => {
        result.hidden = false;
      });

    if (adviserResults) {
      adviserResults.hidden = true;
    }

    setModalHint(
      adviserSearchHint,
      "Type a faculty member's name or email to search.",
    );
  }

  // =========================================================
  // 54. FILTER FACULTY RESULTS
  // =========================================================

  function filterFacultyResults() {
    if (!adviserSearch || !adviserResults) {
      return;
    }

    const query = adviserSearch.value.trim().toLowerCase();

    const results = [
      ...adviserResults.querySelectorAll(".faculty-search-result"),
    ];

    if (!query) {
      results.forEach((result) => {
        result.hidden = false;
      });

      adviserResults.hidden = true;

      setModalHint(
        adviserSearchHint,
        "Type a faculty member's name or email to search.",
      );

      return;
    }

    let matchCount = 0;

    results.forEach((result) => {
      const searchableText = (result.dataset.search || "").toLowerCase();

      const matches = searchableText.includes(query);

      result.hidden = !matches;

      if (matches) {
        matchCount++;
      }
    });

    adviserResults.hidden = matchCount === 0;

    if (matchCount === 0) {
      setModalHint(
        adviserSearchHint,
        "No matching faculty members found. Try another name or email.",
        "error",
      );

      return;
    }

    setModalHint(
      adviserSearchHint,
      `${matchCount} matching faculty member${matchCount === 1 ? "" : "s"}. Select a faculty member to continue.`,
      "success",
    );
  }

  // =========================================================
  // 55. SELECT FACULTY MEMBER
  // =========================================================

  function selectFacultyMember(button) {
    if (!button || !adviserIdField) {
      return;
    }

    const data = button.dataset;

    if (!data.facultyId) {
      setModalHint(
        adviserSearchHint,
        "Unable to select this faculty member. Please try again.",
        "error",
      );

      return;
    }

    adviserIdField.value = data.facultyId;

    if (adviserSearch) {
      adviserSearch.value = data.facultyName || "";
    }

    if (adviserResults) {
      adviserResults.hidden = true;
    }

    setModalHint(
      adviserSearchHint,
      `${data.facultyName} selected. Click Save Adviser to confirm.`,
      "success",
    );
  }

  // =========================================================
  // 56. ADVISER SEARCH EVENTS
  // =========================================================

  adviserSearch?.addEventListener("input", () => {
    if (adviserIdField) {
      adviserIdField.value = "";
    }

    filterFacultyResults();
  });

  adviserResults?.addEventListener("click", (event) => {
    const target = event.target;

    if (!(target instanceof Element)) {
      return;
    }

    const result = target.closest(".faculty-search-result");

    if (result && adviserResults.contains(result)) {
      selectFacultyMember(result);
    }
  });

  adviserModal?.querySelector("form")?.addEventListener("submit", (event) => {
    const submitter = event.submitter;

    const clearingAdviser =
      submitter instanceof HTMLButtonElement &&
      submitter.hasAttribute("data-clear-adviser");

    if (clearingAdviser) {
      return;
    }

    if (!adviserIdField?.value) {
      event.preventDefault();

      setModalHint(
        adviserSearchHint,
        "Please search for and select a faculty member first, or use Clear Adviser to remove the current assignment.",
        "error",
      );

      adviserSearch?.focus();
    }
  });

  // =========================================================
  // 57. PREPARE ADD STUDENT / ADVISER MODAL
  // =========================================================

  function prepareSectionAssignmentModal(modal, button) {
    const sectionId = button.dataset.sectionId || "";

    const sectionName = button.dataset.sectionName || "this section";

    modal.querySelectorAll("[data-modal-section-id]").forEach((input) => {
      input.value = sectionId;
    });

    modal.querySelectorAll("[data-modal-section-name]").forEach((element) => {
      element.textContent = sectionName;
    });
  }

  function openAddStudentModal(button) {
    if (!studentModal) {
      return;
    }

    resetAddStudentModal();

    prepareSectionAssignmentModal(studentModal, button);

    openSectionModal(studentModal, button, "#modal-student-search");
  }

  function openAssignAdviserModal(button) {
    if (!adviserModal) {
      return;
    }

    resetAdviserModal();

    prepareSectionAssignmentModal(adviserModal, button);

    openSectionModal(adviserModal, button, "#modal-adviser-search");
  }

  // =========================================================
  // 58. EDIT STUDENT MODAL
  // =========================================================

  function prepareEditStudentModal(button) {
    if (!editStudentModal) {
      return;
    }

    const form = editStudentModal.querySelector(".edit-student-form");

    if (!form) {
      return;
    }

    const data = button.dataset;

    form.reset();

    const fields = {
      student_id: data.studentId,
      nickname: data.studentNickname,
      student_number: data.studentNumber,
      email: data.studentEmail,
      firstname: data.studentFirstname,
      middlename: data.studentMiddlename,
      lastname: data.studentLastname,
      age: data.studentAge,
      gender: data.studentGender,
      mobile_number: data.studentMobileNumber,
      address: data.studentAddress,
    };

    Object.entries(fields).forEach(([name, value]) => {
      const field = form.elements.namedItem(name);

      if (field) {
        field.value = value || "";
      }
    });

    const currentSection = editStudentModal.querySelector(
      "[data-edit-current-section]",
    );

    if (currentSection) {
      currentSection.textContent = data.sectionName || "Unknown Section";
    }

    openSectionModal(editStudentModal, button, "#edit-student-nickname");
  }

  // =========================================================
  // 59. CHANGE STUDENT SECTION MODAL
  // =========================================================

  function prepareChangeSectionModal(button) {
    if (!changeSectionModal) {
      return;
    }

    const form = changeSectionModal.querySelector(".change-section-form");

    if (!form) {
      return;
    }

    const data = button.dataset;

    form.reset();

    const studentIdField = form.elements.namedItem("student_id");

    const sectionSelect = form.querySelector("#change-student-section");

    const studentName = changeSectionModal.querySelector(
      "[data-change-student-name]",
    );

    const currentSection = changeSectionModal.querySelector(
      "[data-change-current-section]",
    );

    const currentSectionId = String(data.sectionId || "");

    if (studentIdField) {
      studentIdField.value = data.studentId || "";
    }

    if (studentName) {
      studentName.textContent = data.studentFullName || "Selected Student";
    }

    if (currentSection) {
      currentSection.textContent = data.sectionName || "Unknown Section";
    }

    if (sectionSelect) {
      sectionSelect.value = "";

      Array.from(sectionSelect.options).forEach((option) => {
        if (!option.value) {
          return;
        }

        option.disabled = option.value === currentSectionId;
      });
    }

    openSectionModal(changeSectionModal, button, "#change-student-section");
  }

  // =========================================================
  // 60. CREATE SECTION MODAL
  // =========================================================

  const openCreateSectionButton = document.getElementById(
    "open-create-section-modal",
  );

  const closeCreateSectionButton = document.getElementById(
    "close-create-section-modal",
  );

  const cancelCreateSectionButton = document.getElementById(
    "cancel-create-section",
  );

  const courseInput = document.getElementById("new-course");

  const createSectionForm = createSectionModal?.querySelector(
    ".create-section-modal-form",
  );

  function openCreateSectionModal() {
    if (!createSectionModal) {
      return;
    }

    createSectionForm?.reset();

    openSectionModal(createSectionModal, document.activeElement, "#new-course");
  }

  function closeCreateSectionModal() {
    if (!createSectionModal) {
      return;
    }

    if (activeSectionModal === createSectionModal) {
      closeActiveSectionModal();
      return;
    }

    createSectionModal.hidden = true;

    updateSectionModalScrollLock();
  }

  openCreateSectionButton?.addEventListener("click", openCreateSectionModal);

  closeCreateSectionButton?.addEventListener("click", closeCreateSectionModal);

  cancelCreateSectionButton?.addEventListener("click", closeCreateSectionModal);

  // =========================================================
  // 61. CENTRALIZED MODAL BUTTON HANDLING
  // =========================================================

  document.addEventListener("click", (event) => {
    const target = event.target;

    if (!(target instanceof Element)) {
      return;
    }

    const addStudentButton = target.closest("[data-open-student-modal]");

    const adviserButton = target.closest("[data-open-adviser-modal]");

    const editButton = target.closest("[data-open-edit-student-modal]");

    const changeSectionButton = target.closest(
      "[data-open-change-section-modal]",
    );

    const closeButton = target.closest("[data-close-admin-modal]");

    if (addStudentButton) {
      openAddStudentModal(addStudentButton);
      return;
    }

    if (adviserButton) {
      openAssignAdviserModal(adviserButton);
      return;
    }

    if (editButton) {
      prepareEditStudentModal(editButton);
      return;
    }

    if (changeSectionButton) {
      prepareChangeSectionModal(changeSectionButton);
      return;
    }

    if (closeButton && activeSectionModal?.contains(closeButton)) {
      closeActiveSectionModal();
    }
  });

  // =========================================================
  // 62. CLOSE MODAL BY CLICKING BACKDROP
  // =========================================================

  Object.values(sectionModalElements).forEach((modal) => {
    if (!modal) {
      return;
    }

    modal.addEventListener("click", (event) => {
      if (event.target === modal && activeSectionModal === modal) {
        closeActiveSectionModal();
      }
    });
  });

  // =========================================================
  // 63. CLOSE SEARCH RESULTS WHEN CLICKING OUTSIDE
  // =========================================================

  document.addEventListener("click", (event) => {
    const target = event.target;

    if (!(target instanceof Node)) {
      return;
    }

    if (
      studentResults &&
      studentSearchContainer &&
      !studentSearchContainer.contains(target)
    ) {
      studentResults.hidden = true;
    }

    if (
      adviserResults &&
      adviserSearchContainer &&
      !adviserSearchContainer.contains(target)
    ) {
      adviserResults.hidden = true;
    }
  });

  // =========================================================
  // 64. MODAL KEYBOARD ACCESSIBILITY
  // =========================================================

  function handleSectionModalKeyboard(event) {
    if (!activeSectionModal) {
      return;
    }

    if (event.key !== "Tab") {
      return;
    }

    const focusableElements =
      getSectionModalFocusableElements(activeSectionModal);

    if (!focusableElements.length) {
      return;
    }

    const first = focusableElements[0];
    const last = focusableElements[focusableElements.length - 1];

    if (
      event.shiftKey &&
      (document.activeElement === first ||
        !activeSectionModal.contains(document.activeElement))
    ) {
      event.preventDefault();
      last.focus();
    } else if (
      !event.shiftKey &&
      (document.activeElement === last ||
        !activeSectionModal.contains(document.activeElement))
    ) {
      event.preventDefault();
      first.focus();
    }
  }

  // =========================================================
  // 65. INITIAL MODAL STATE
  // =========================================================

  Object.values(sectionModalElements).forEach((modal) => {
    if (modal) {
      modal.hidden = true;
    }
  });

  updateSectionModalScrollLock();

  // =========================================================
  // 66. AJAX SUPPORTED ACTIONS
  // =========================================================

  const supportedActions = [
    "add_section",
    "assign_student",
    "remove_student",
    "edit_student",
    "change_student_section",
    "assign_adviser",
  ];

  const successMessages = {
    add_section: "Section created successfully.",

    assign_student: "Student account assigned to the section successfully.",

    remove_student:
      "Student removed successfully. Their section enrollment and academic placement have been cleared.",

    edit_student: "Student information updated successfully.",

    change_student_section:
      "Student transferred successfully. Their academic placement has also been updated.",

    assign_adviser: "SSP Adviser assignment saved successfully.",
  };

  // =========================================================
  // 67. SECTION CARD IDENTIFIER
  // =========================================================

  function getSectionKey(card) {
    return String(
      card.dataset.sectionId ||
        card.dataset.sectionSearch ||
        card.textContent.trim(),
    );
  }

  // =========================================================
  // 68. REFRESH SECTION CARDS
  // =========================================================

  function updateSectionCards(parsedDocument, action) {
    const sectionContainer = document.getElementById("managed-sections");

    if (!sectionContainer) {
      return;
    }

    const oldCards = [
      ...sectionContainer.querySelectorAll(".searchable-section-card"),
    ];

    const oldKeys = new Set(oldCards.map(getSectionKey));

    const newCards = [
      ...parsedDocument.querySelectorAll(".searchable-section-card"),
    ];

    const addedCard =
      action === "add_section"
        ? newCards.find((card) => {
            return !oldKeys.has(getSectionKey(card));
          })
        : null;

    // Remove old section cards.
    oldCards.forEach((card) => {
      card.remove();
    });

    // Add updated section cards.
    newCards.forEach((card) => {
      sectionContainer.appendChild(document.importNode(card, true));
    });

    // Refresh the section card reference.
    sectionCards = [
      ...sectionContainer.querySelectorAll(".searchable-section-card"),
    ];

    // Update section counter.
    const currentCount = document.querySelector(
      "#section-management .admin-count",
    );

    const updatedCount = parsedDocument.querySelector(
      "#section-management .admin-count",
    );

    if (currentCount && updatedCount) {
      currentCount.textContent = updatedCount.textContent.trim();
    }

    // Automatically find the newly created section.
    if (addedCard && sectionSearch) {
      sectionSearch.value = addedCard.dataset.sectionSearch || "";
    }

    filterSections();

    initializeIcons();
  }

  // =========================================================
  // 69. REFRESH AVAILABLE STUDENTS
  // =========================================================

  function updateStudentResults(parsedDocument) {
    const currentResults = document.getElementById("modal-student-results");

    const updatedResults = parsedDocument.getElementById(
      "modal-student-results",
    );

    if (!currentResults || !updatedResults) {
      return;
    }

    currentResults.innerHTML = updatedResults.innerHTML;

    currentResults.hidden = true;

    document.dispatchEvent(new Event("admin:students-updated"));

    initializeIcons();
  }

  // =========================================================
  // 70. REFRESH DESTINATION SECTION OPTIONS
  // =========================================================

  function updateSectionOptions(parsedDocument) {
    const currentSelect = document.getElementById("change-student-section");

    const updatedSelect = parsedDocument.getElementById(
      "change-student-section",
    );

    if (!currentSelect || !updatedSelect) {
      return;
    }

    const selectedValue = currentSelect.value;

    currentSelect.innerHTML = updatedSelect.innerHTML;

    if (
      selectedValue &&
      Array.from(currentSelect.options).some((option) => {
        return option.value === selectedValue;
      })
    ) {
      currentSelect.value = selectedValue;
    } else {
      currentSelect.value = "";
    }
  }

  // =========================================================
  // 71. READ SERVER ERROR
  // =========================================================

  function getServerError(parsedDocument) {
    const errorElement = parsedDocument.querySelector(
      ".admin-alert-error, [role='alert'], .alert-danger",
    );

    return errorElement ? errorElement.textContent.trim() : "";
  }

  // =========================================================
  // 72. CLOSE MODAL AFTER SUCCESSFUL AJAX
  // =========================================================

  function closeSuccessfulActionModal(action) {
    const modalMap = {
      assign_student: studentModal,
      assign_adviser: adviserModal,
      edit_student: editStudentModal,
      change_student_section: changeSectionModal,
      add_section: createSectionModal,
    };

    const modal = modalMap[action];

    if (!modal) {
      return;
    }

    if (activeSectionModal === modal) {
      closeActiveSectionModal(false);
    } else {
      modal.hidden = true;
      updateSectionModalScrollLock();
    }
  }

  // =========================================================
  // 73. AJAX FORM SUBMISSION
  // =========================================================

  document.addEventListener("submit", async (event) => {
    if (event.defaultPrevented) {
      return;
    }

    const form = event.target;

    if (!(form instanceof HTMLFormElement)) {
      return;
    }

    const action = form.querySelector('input[name="action"]')?.value;

    if (!supportedActions.includes(action)) {
      return;
    }

    const submitter = event.submitter;

    const clearingAdviser =
      action === "assign_adviser" &&
      submitter instanceof HTMLButtonElement &&
      submitter.hasAttribute("data-clear-adviser");

    // Ensure an adviser has been selected.
    if (action === "assign_adviser" && !clearingAdviser) {
      const adviserId = form.querySelector("#modal-adviser-id");

      const searchField = form.querySelector("#modal-adviser-search");

      const hint = form.querySelector("#adviser-search-hint");

      if (!adviserId?.value) {
        event.preventDefault();

        setModalHint(
          hint,
          "Please search for and select a faculty member first, or use Clear Adviser to remove the current assignment.",
          "error",
        );

        searchField?.focus();

        return;
      }
    }

    // Confirm clearing adviser.
    if (clearingAdviser) {
      if (
        !window.confirm(
          "Are you sure you want to remove the SSP Adviser assigned to this section?",
        )
      ) {
        event.preventDefault();
        return;
      }
    }

    event.preventDefault();

    // Prevent duplicate requests.
    if (form.dataset.ajaxSubmitting === "true") {
      return;
    }

    form.dataset.ajaxSubmitting = "true";

    const submitButton =
      submitter || form.querySelector('button[type="submit"]');

    const wasDisabled = submitButton?.disabled ?? false;

    if (submitButton) {
      submitButton.disabled = true;
      submitButton.setAttribute("aria-busy", "true");
    }

    try {
      const requestUrl = window.location.pathname + window.location.search;

      const response = await fetch(requestUrl, {
        method: "POST",

        body: new FormData(form),

        credentials: "same-origin",

        headers: {
          "X-Requested-With": "XMLHttpRequest",
          Accept: "text/html",
        },
      });

      const html = await response.text();

      if (!response.ok) {
        throw new Error(`Server error (${response.status}). Please try again.`);
      }

      const parsedDocument = new DOMParser().parseFromString(html, "text/html");

      const successElement = parsedDocument.querySelector(
        ".admin-alert-success",
      );

      const responseSuccessMessage = successElement?.textContent.trim() || "";

      // Confirm that PHP completed the action.
      if (responseSuccessMessage !== successMessages[action]) {
        const serverError = getServerError(parsedDocument);

        showToast(
          serverError ||
            "The request was not completed. Please check the form and try again.",
        );

        return;
      }

      // Refresh the section cards and their student lists.
      updateSectionCards(parsedDocument, action);

      // Refresh the available student search results.
      updateStudentResults(parsedDocument);

      // Refresh destination section options.
      updateSectionOptions(parsedDocument);

      showToast(successMessages[action]);

      // Close the relevant modal.
      closeSuccessfulActionModal(action);

      // Reset create section form after success.
      if (action === "add_section") {
        form.reset();
      }

      initializeIcons();
    } catch (error) {
      console.error("AcadTrack AJAX error:", error);

      showToast(
        error instanceof Error
          ? error.message
          : "Something went wrong. Please try again.",
      );
    } finally {
      delete form.dataset.ajaxSubmitting;

      if (submitButton) {
        submitButton.disabled = wasDisabled;
        submitButton.removeAttribute("aria-busy");
      }
    }
  });

  // =========================================================
  // 74. KEYBOARD SHORTCUTS AND GLOBAL ESCAPE
  // =========================================================

  document.addEventListener("keydown", (event) => {
    const isSearchOpen = searchOverlay && !searchOverlay.hidden;

    // CTRL + K / CMD + K
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") {
      event.preventDefault();

      if (
        (adminProfileModal && !adminProfileModal.hidden) ||
        (adminPasswordModal && !adminPasswordModal.hidden) ||
        activeSectionModal
      ) {
        return;
      }

      openSearch();

      return;
    }

    // ESCAPE
    if (event.key === "Escape") {
      // Password modal has the highest priority.
      if (adminPasswordModal && !adminPasswordModal.hidden) {
        closeAdminPasswordModal();
        return;
      }

      // Profile modal.
      if (adminProfileModal && !adminProfileModal.hidden) {
        closeAdminProfileModal();
        return;
      }

      // Section management modals.
      if (activeSectionModal) {
        closeActiveSectionModal();
        return;
      }

      // Global search.
      if (isSearchOpen) {
        closeSearch();
      }

      // Export dropdowns and sidebar.
      closeSectionExportMenus();
      closeSidebar();
      closeDropdowns();

      return;
    }

    // Section modal focus trap.
    if (activeSectionModal) {
      handleSectionModalKeyboard(event);
      return;
    }

    // Global search keyboard navigation.
    if (!isSearchOpen) {
      return;
    }

    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();

      moveSelection(event.key === "ArrowDown" ? 1 : -1);

      return;
    }

    if (event.key === "Enter" && event.target === searchInput) {
      event.preventDefault();

      const visibleButtons = getSearchButtons().filter(
        (button) => !button.hidden,
      );

      const selectedButton = visibleButtons.find((button) => {
        return button.classList.contains("selected");
      });

      activateSearch(selectedButton || visibleButtons[0]);
    }
  });

  // =========================================================
  // 75. INITIALIZE DASHBOARD
  // =========================================================

  initializeIcons();

  updateProfileModalBodyLock();

  updateSectionModalScrollLock();
})();
