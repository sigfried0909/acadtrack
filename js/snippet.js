(() => {
  "use strict";

  const root = document.querySelector(".cl-login10");
  if (!root) return;
  const tablist = root.querySelector("[data-tablist]");
  const tabs = Array.from(root.querySelectorAll('[role="tab"]'));
  const panels = tabs.map((t) =>
    document.getElementById(t.getAttribute("aria-controls")),
  );
  const live = root.querySelector("[data-live]");
  const done = root.querySelector("[data-success]");
  if (tabs.length !== 2 || panels.some((p) => !p) || !done) return;

  const select = (i, focus) => {
    const from = tabs.findIndex(
      (t) => t.getAttribute("aria-selected") === "true",
    );
    tabs.forEach((t, k) => {
      const on = k === i;
      t.setAttribute("aria-selected", String(on));
      t.tabIndex = on ? 0 : -1;
      panels[k].hidden = !on;
      panels[k].classList.remove("is-from-right", "is-from-left");
    });
    if (from !== i && from !== -1)
      panels[i].classList.add(i > from ? "is-from-right" : "is-from-left");
    tablist.classList.toggle("is-second", i === 1);
    done.hidden = true;
    if (focus) tabs[i].focus();
  };
  tabs.forEach((tab, i) => {
    tab.addEventListener("click", () => select(i, false));
    tab.addEventListener("keydown", (e) => {
      const map = {
        ArrowRight: (i + 1) % 2,
        ArrowLeft: (i + 1) % 2,
        Home: 0,
        End: 1,
      };
      if (!(e.key in map)) return;
      e.preventDefault();
      select(map[e.key], true);
    });
  });
  root.querySelectorAll("[data-go]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const i = btn.dataset.go === "signup" ? 1 : 0;
      select(i, false);
      panels[i].querySelector("input").focus();
    });
  });

  const messageFor = (el) => {
    if (el.type === "checkbox") {
      return el.checked ? "" : el.dataset.msg;
    }

    const val = el.value.trim();

    if (el.required && !val) {
      return el.dataset.msg || "This field is required.";
    }

    if (el.validity.typeMismatch) {
      return "Enter an email like name@example.com.";
    }

    if (el.minLength > 0 && el.value.length < el.minLength) {
      return el.name === "name"
        ? "That looks a little short."
        : `Use at least ${el.minLength} characters.`;
    }

    if (el.validity.patternMismatch) {
      return el.dataset.pattern || "Check the format.";
    }

    // Confirm password validation
    if (el.dataset.confirmPassword) {
      const originalPassword = document.getElementById(
        el.dataset.confirmPassword,
      );

      if (originalPassword && el.value !== originalPassword.value) {
        return "Passwords do not match.";
      }
    }

    return "";
  };
  const check = (field) => {
    const el = field.querySelector("input");
    const msg = messageFor(el);
    field.querySelector("[data-error]").textContent = msg;
    field.classList.toggle("is-error", !!msg);
    if (msg) el.setAttribute("aria-invalid", "true");
    else el.removeAttribute("aria-invalid");
    return !msg;
  };
  const forms = Array.from(root.querySelectorAll("[data-form]"));
  forms.forEach((form) => {
    const fields = Array.from(form.querySelectorAll("[data-field]"));
    fields.forEach((field) => {
      field.addEventListener("focusout", (e) => {
        const el = field.querySelector("input");
        if (
          field.contains(e.relatedTarget) ||
          (e.relatedTarget && e.relatedTarget.matches("button")) ||
          el.type === "checkbox" ||
          !el.value
        )
          return;
        field.dataset.touched = "1";
        check(field);
      });
      field.addEventListener("input", () => {
        if (field.dataset.touched) {
          check(field);
        }

        const changedInput = field.querySelector("input");

        // Revalidate confirmation if the original password changes.
        root
          .querySelectorAll("[data-confirm-password]")
          .forEach((confirmInput) => {
            if (confirmInput.dataset.confirmPassword === changedInput.id) {
              const confirmField = confirmInput.closest("[data-field]");

              if (confirmField && confirmField.dataset.touched) {
                check(confirmField);
              }
            }
          });
      });
      field.addEventListener("change", () => {
        if (field.dataset.touched) check(field);
      });
    });

    form.addEventListener("submit", (e) => {
      // Validate fields before submitting.
      fields.forEach((f) => {
        f.dataset.touched = "1";
      });

      const bad = fields.filter((f) => !check(f));

      if (bad.length) {
        e.preventDefault();

        live.textContent =
          bad.length === 1
            ? "One field needs attention."
            : `${bad.length} fields need attention.`;

        bad[0].querySelector("input").focus();
        return;
      }

      // Login: allow normal submission to PHP.
      if (form.dataset.form === "login") {
        live.textContent = "Signing in...";
        return;
      }

      // Student registration
      live.textContent = "Creating your student account...";
      return;
    });
  });

  root.querySelectorAll("[data-pw-toggle]").forEach((btn) => {
    const input = document.getElementById(btn.getAttribute("aria-controls"));

    if (!input) return;

    btn.addEventListener("click", () => {
      const show = input.type === "password";

      input.type = show ? "text" : "password";
      btn.setAttribute("aria-pressed", String(show));
      btn.setAttribute("aria-label", show ? "Hide password" : "Show password");
    });
  });

  root.querySelector("[data-reset]").addEventListener("click", () => {
    forms.forEach((f) => f.reset());
    root.querySelectorAll("[data-field]").forEach((f) => {
      delete f.dataset.touched;
      f.classList.remove("is-error");
      f.querySelector("[data-error]").textContent = "";
      f.querySelector("input").removeAttribute("aria-invalid");
    });
    select(0, true);
  });

  // validation for login
  const loginError = document.getElementById("login-error");
  const urlParams = new URLSearchParams(window.location.search);

  if (loginError && urlParams.get("error") === "invalid") {
    loginError.hidden = false;

    window.history.replaceState(
      {},
      document.title,
      window.location.pathname + window.location.hash,
    );
  }
  // end

  /* Terms and Privacy Modal Controls */
  (() => {
    const modalLinks = document.querySelectorAll("[data-open-modal]");
    const modals = document.querySelectorAll(".policy-modal");

    let lastFocusedElement = null;

    function openModal(modal) {
      if (!modal) return;

      lastFocusedElement = document.activeElement;
      modal.hidden = false;
      document.body.style.overflow = "hidden";

      const content = modal.querySelector(".policy-modal__content");
      const closeButton = modal.querySelector(".policy-modal__close");

      if (content) content.scrollTop = 0;
      if (closeButton) closeButton.focus();
    }

    function closeModal(modal) {
      if (!modal) return;

      modal.hidden = true;

      if (![...modals].some((item) => !item.hidden)) {
        document.body.style.overflow = "";
      }

      if (lastFocusedElement) {
        lastFocusedElement.focus();
      }
    }

    modalLinks.forEach((link) => {
      link.addEventListener("click", (event) => {
        event.preventDefault();

        const modalId = link.dataset.openModal;
        openModal(document.getElementById(modalId));
      });
    });

    modals.forEach((modal) => {
      modal.querySelectorAll("[data-close-modal]").forEach((button) => {
        button.addEventListener("click", () => closeModal(modal));
      });
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        const openModalElement = [...modals].find((modal) => !modal.hidden);
        if (openModalElement) closeModal(openModalElement);
      }
    });
  })();

  // Student registration feedback.
  const registrationMessage = document.getElementById("registration-message");

  const signupFeedback = document.getElementById("signup-feedback");

  const params = new URLSearchParams(window.location.search);
  const registrationError = params.get("error");

  if (params.get("registered") === "1" && registrationMessage) {
    registrationMessage.textContent =
      "Student account created successfully. You can now log in.";

    registrationMessage.hidden = false;
  }

  if (params.get("reset") === "1" && registrationMessage) {
    registrationMessage.textContent =
      "Password reset successfully. You can now log in.";

    registrationMessage.hidden = false;
  }

  const signupErrors = {
    email_exists: "This email is already registered. Please use another email.",

    student_number_exists:
      "This student number is already registered. Please check your number.",

    registration_conflict:
      "The student number or email is already registered. Please check your information.",

    registration_invalid:
      "Please check your name, 12-digit student number, email, password requirements, and Terms acceptance.",

    registration_wait:
      "Please wait at least 60 seconds before requesting another verification code.",

    registration_email_failed:
      "We could not send the verification email. Please check your email address and try again.",

    registration_expired:
      "Your verification code expired or your pending registration is no longer available. Please register again.",
  };

  if (signupErrors[registrationError] && signupFeedback) {
    signupFeedback.textContent = signupErrors[registrationError];
    signupFeedback.hidden = false;

    select(1, false);
  }

  // Remove registration query parameters after displaying feedback.
  if (
    params.has("registered") ||
    params.has("reset") ||
    signupErrors[registrationError]
  ) {
    window.history.replaceState(
      {},
      document.title,
      window.location.pathname + window.location.hash,
    );
  }
})();

(() => {
  "use strict";

  const loginForm = document.querySelector('form[data-form="login"]');

  if (!loginForm) {
    return;
  }

  const loginButton = loginForm.querySelector("[data-login-submit]");

  const loginLabel = loginButton?.querySelector("[data-login-label]");

  const spinner = loginButton?.querySelector(".cl-login10__spinner");

  if (!loginButton || !loginLabel || !spinner) {
    return;
  }

  const originalLabel = loginLabel.textContent;
  let isSubmitting = false;

  /*
        |--------------------------------------------------------------------------
        | LOGIN SUBMISSION LOADING STATE
        |--------------------------------------------------------------------------
        */

  loginForm.addEventListener("submit", function (event) {
    // Respect validation or submission handling from existing scripts.
    if (event.defaultPrevented) {
      return;
    }

    // Do not show loading when form validation fails.
    if (!loginForm.checkValidity()) {
      return;
    }

    // Prevent duplicate login submissions.
    if (isSubmitting) {
      event.preventDefault();
      return;
    }

    isSubmitting = true;

    loginButton.disabled = true;
    loginButton.classList.add("is-loading");
    loginButton.setAttribute("aria-busy", "true");

    loginLabel.textContent = "Logging in...";
    spinner.hidden = false;
  });

  /*
        |--------------------------------------------------------------------------
        | RESTORE BUTTON WHEN RETURNING THROUGH BROWSER HISTORY
        |--------------------------------------------------------------------------
        */

  window.addEventListener("pageshow", function () {
    isSubmitting = false;

    loginButton.disabled = false;
    loginButton.classList.remove("is-loading");
    loginButton.removeAttribute("aria-busy");

    loginLabel.textContent = originalLabel;
    spinner.hidden = true;
  });
})();

(() => {
  "use strict";

  const registerForm = document.querySelector('form[data-form="signup"]');

  if (!registerForm) {
    return;
  }

  const registerButton = registerForm.querySelector("[data-register-submit]");

  const registerLabel = registerButton?.querySelector("[data-register-label]");

  const spinner = registerButton?.querySelector(".cl-login10__spinner");

  const feedback = document.getElementById("signup-feedback");
  const successPanel = document.querySelector("[data-success]");

  if (!registerButton || !registerLabel || !spinner) {
    return;
  }

  const originalLabel = registerLabel.textContent;
  let isSubmitting = false;

  /*
        |--------------------------------------------------------------------------
        | RESTORE REGISTER BUTTON
        |--------------------------------------------------------------------------
        */

  function resetRegisterButton() {
    isSubmitting = false;

    registerButton.disabled = false;
    registerButton.classList.remove("is-loading");
    registerButton.removeAttribute("aria-busy");

    registerLabel.textContent = originalLabel;
    spinner.hidden = true;
  }

  /*
        |--------------------------------------------------------------------------
        | REGISTER BUTTON LOADING STATE
        |--------------------------------------------------------------------------
        */

  registerForm.addEventListener("submit", function (event) {
    // Prevent loading when native form validation fails.
    if (!registerForm.checkValidity()) {
      return;
    }

    // Prevent submission when passwords do not match.
    const password = registerForm.querySelector('[name="password"]');

    const confirmPassword = registerForm.querySelector(
      '[name="confirm_password"]',
    );

    if (
      password &&
      confirmPassword &&
      password.value !== confirmPassword.value
    ) {
      return;
    }

    // Prevent duplicate submissions.
    if (isSubmitting) {
      event.preventDefault();
      return;
    }

    isSubmitting = true;

    registerButton.disabled = true;
    registerButton.classList.add("is-loading");
    registerButton.setAttribute("aria-busy", "true");

    registerLabel.textContent = "Creating account...";
    spinner.hidden = false;
  });

  /*
        |--------------------------------------------------------------------------
        | RESTORE AFTER REGISTRATION FEEDBACK
        |--------------------------------------------------------------------------
        */

  if (feedback) {
    const feedbackObserver = new MutationObserver(() => {
      const hasFeedback =
        !feedback.hidden && feedback.textContent.trim() !== "";

      if (hasFeedback) {
        resetRegisterButton();
      }
    });

    feedbackObserver.observe(feedback, {
      childList: true,
      subtree: true,
      characterData: true,
      attributes: true,
      attributeFilter: ["hidden", "class"],
    });
  }

  /*
        |--------------------------------------------------------------------------
        | RESTORE AFTER SUCCESS PANEL OPENS
        |--------------------------------------------------------------------------
        */

  if (successPanel) {
    const successObserver = new MutationObserver(() => {
      if (!successPanel.hidden) {
        resetRegisterButton();
      }
    });

    successObserver.observe(successPanel, {
      attributes: true,
      attributeFilter: ["hidden"],
    });
  }

  /*
        |--------------------------------------------------------------------------
        | RESTORE WHEN FORM IS RESET
        |--------------------------------------------------------------------------
        */

  registerForm.addEventListener("reset", resetRegisterButton);

  /*
        |--------------------------------------------------------------------------
        | RESTORE WHEN RETURNING THROUGH BROWSER HISTORY
        |--------------------------------------------------------------------------
        */

  window.addEventListener("pageshow", resetRegisterButton);
})();

(() => {
  "use strict";

  const resetForm = document.querySelector(
    'form[action="forgot_password.php"]',
  );

  if (!resetForm) {
    return;
  }

  const resetButton = resetForm.querySelector("[data-reset-submit]");

  const resetLabel = resetButton?.querySelector("[data-reset-label]");

  const spinner = resetButton?.querySelector(".cl-login10__spinner");

  if (!resetButton || !resetLabel || !spinner) {
    return;
  }

  const originalLabel = resetLabel.textContent;
  let isSubmitting = false;

  /*
        |--------------------------------------------------------------------------
        | SEND RESET CODE LOADING STATE
        |--------------------------------------------------------------------------
        */

  resetForm.addEventListener("submit", function (event) {
    // Respect existing validation.
    if (!resetForm.checkValidity()) {
      return;
    }

    // Prevent duplicate submissions.
    if (isSubmitting) {
      event.preventDefault();
      return;
    }

    isSubmitting = true;

    resetButton.disabled = true;
    resetButton.classList.add("is-loading");
    resetButton.setAttribute("aria-busy", "true");

    resetLabel.textContent = "Sending reset code...";
    spinner.hidden = false;
  });

  /*
        |--------------------------------------------------------------------------
        | RESTORE BUTTON WHEN RETURNING THROUGH BROWSER HISTORY
        |--------------------------------------------------------------------------
        */

  window.addEventListener("pageshow", function () {
    isSubmitting = false;

    resetButton.disabled = false;
    resetButton.classList.remove("is-loading");
    resetButton.removeAttribute("aria-busy");

    resetLabel.textContent = originalLabel;
    spinner.hidden = true;
  });
})();
