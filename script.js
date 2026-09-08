/* ==========================================================================
   D7 News Telugu — ఉచిత గణేష్ విగ్రహాల నమోదు
   script.js — validation, token generation, localStorage persistence.
   Frontend only. No backend, no database.
   ========================================================================== */

   (function () {
    "use strict";
  
    /* ------------------------------ Config ------------------------------- */
    const STORAGE_KEY  = "d7_ganesh_registrations_v1"; // array of registrations
    const COUNTER_KEY  = "d7_ganesh_last_token_v1";    // last sequential number
    const TOKEN_PREFIX = "D7-GANESH-";
    const TOKEN_PAD    = 4; // D7-GANESH-0001
  
    /* ------------------------------- DOM --------------------------------- */
    const form        = document.getElementById("regForm");
    const formPanel   = document.getElementById("formPanel");
    const resultPanel = document.getElementById("resultPanel");
  
    const nameInput    = document.getElementById("name");
    const phoneInput   = document.getElementById("phone");
    const addressInput = document.getElementById("address");
    const tokenInput   = document.getElementById("token");
  
    const nameError    = document.getElementById("nameError");
    const phoneError   = document.getElementById("phoneError");
    const addressError = document.getElementById("addressError");
  
    const slipToken   = document.getElementById("slipToken");
    const slipName    = document.getElementById("slipName");
    const slipPhone   = document.getElementById("slipPhone");
    const slipAddress = document.getElementById("slipAddress");
    const slipDate    = document.getElementById("slipDate");
  
    const printBtn  = document.getElementById("printBtn");
    const againBtn  = document.getElementById("againBtn");
    const regCount  = document.getElementById("regCount");
  
    const TOKEN_PLACEHOLDER = "నమోదు తర్వాత కేటాయించబడుతుంది";
  
    /* --------------------------- Storage helpers -------------------------- */
    /* localStorage can be unavailable (private mode / disabled). Every read and
       write is guarded so the form still works, just without persistence.     */
  
    function storageAvailable() {
      try {
        const probe = "__d7_test__";
        window.localStorage.setItem(probe, "1");
        window.localStorage.removeItem(probe);
        return true;
      } catch (e) {
        return false;
      }
    }
  
    const HAS_STORAGE = storageAvailable();
  
    function getRegistrations() {
      if (!HAS_STORAGE) return [];
      try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        const list = raw ? JSON.parse(raw) : [];
        return Array.isArray(list) ? list : [];
      } catch (e) {
        return [];
      }
    }
  
    function saveRegistrations(list) {
      if (!HAS_STORAGE) return;
      try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
      } catch (e) {
        /* Quota full or blocked — the current token is still shown on screen. */
      }
    }
  
    /* ---------------------------- Token number ---------------------------- */
    /* Sequential and unique: the counter is stored separately so deleting a
       record never causes a token to be reissued.                            */
  
    function nextToken() {
      let last = 0;
  
      if (HAS_STORAGE) {
        const stored = parseInt(window.localStorage.getItem(COUNTER_KEY), 10);
        if (!isNaN(stored)) last = stored;
      }
  
      // Safety net: never fall behind the highest token already issued.
      getRegistrations().forEach(function (r) {
        const n = parseInt(String(r.token).replace(TOKEN_PREFIX, ""), 10);
        if (!isNaN(n) && n > last) last = n;
      });
  
      const next = last + 1;
  
      if (HAS_STORAGE) {
        try { window.localStorage.setItem(COUNTER_KEY, String(next)); } catch (e) {}
      }
  
      return TOKEN_PREFIX + String(next).padStart(TOKEN_PAD, "0");
    }
  
    /* ---------------------------- Validation ------------------------------ */
  
    function setError(input, errorEl, message) {
      const field = input.closest(".field");
      if (message) {
        field.classList.add("field--invalid");
        errorEl.textContent = message;
        input.setAttribute("aria-invalid", "true");
      } else {
        field.classList.remove("field--invalid");
        errorEl.textContent = "";
        input.removeAttribute("aria-invalid");
      }
    }
  
    function validateName() {
      const value = nameInput.value.trim();
      if (!value)            return setError(nameInput, nameError, "దయచేసి మీ పేరు రాయండి."), false;
      if (value.length < 3)  return setError(nameInput, nameError, "పేరు కనీసం 3 అక్షరాలు ఉండాలి."), false;
      if (/\d/.test(value))  return setError(nameInput, nameError, "పేరులో అంకెలు ఉండకూడదు."), false;
      setError(nameInput, nameError, "");
      return true;
    }
  
    function validatePhone() {
      const value = phoneInput.value.trim();
      if (!value) {
        setError(phoneInput, phoneError, "దయచేసి మీ ఫోన్ నంబర్ రాయండి.");
        return false;
      }
      if (!/^[6-9]\d{9}$/.test(value)) {
        setError(phoneInput, phoneError, "సరైన 10 అంకెల మొబైల్ నంబర్ రాయండి (6, 7, 8 లేదా 9 తో మొదలవ్వాలి).");
        return false;
      }
      // Duplicate check — one idol per phone number.
      const existing = getRegistrations().find(function (r) { return r.phone === value; });
      if (existing) {
        setError(phoneInput, phoneError,
          "ఈ ఫోన్ నంబర్ ఇప్పటికే నమోదైంది. మీ టోకెన్ నంబర్: " + existing.token);
        return false;
      }
      setError(phoneInput, phoneError, "");
      return true;
    }
  
    function validateAddress() {
      const value = addressInput.value.trim();
      if (!value)             return setError(addressInput, addressError, "దయచేసి మీ చిరునామా రాయండి."), false;
      if (value.length < 10)  return setError(addressInput, addressError, "పూర్తి చిరునామా రాయండి (ఊరు, పిన్ కోడ్ సహా)."), false;
      setError(addressInput, addressError, "");
      return true;
    }
  
    /* -------------------------- Input behaviour --------------------------- */
  
    // Phone: digits only.
    phoneInput.addEventListener("input", function () {
      this.value = this.value.replace(/\D/g, "").slice(0, 10);
      if (this.closest(".field").classList.contains("field--invalid")) validatePhone();
    });
  
    // Clear an error as soon as the person fixes the field.
    nameInput.addEventListener("input", function () {
      if (this.closest(".field").classList.contains("field--invalid")) validateName();
    });
    addressInput.addEventListener("input", function () {
      if (this.closest(".field").classList.contains("field--invalid")) validateAddress();
    });
  
    // The token field is never typed into.
    tokenInput.addEventListener("focus", function () { this.blur(); });
  
    /* ----------------------------- Formatting ----------------------------- */
  
    function formatDate(iso) {
      const d = new Date(iso);
      const pad = function (n) { return String(n).padStart(2, "0"); };
      let hours = d.getHours();
      const suffix = hours >= 12 ? "PM" : "AM";
      hours = hours % 12 || 12;
      return pad(d.getDate()) + "-" + pad(d.getMonth() + 1) + "-" + d.getFullYear() +
             ", " + pad(hours) + ":" + pad(d.getMinutes()) + " " + suffix;
    }
  
    function updateCount() {
      regCount.textContent = getRegistrations().length;
    }
  
    /* ------------------------------ Submit -------------------------------- */
  
    form.addEventListener("submit", function (event) {
      event.preventDefault();
  
      // Run all three so every problem is shown at once.
      const okName    = validateName();
      const okPhone   = validatePhone();
      const okAddress = validateAddress();
  
      if (!(okName && okPhone && okAddress)) {
        const firstInvalid = form.querySelector(".field--invalid .field__input");
        if (firstInvalid) firstInvalid.focus();
        return;
      }
  
      const registration = {
        token:     nextToken(),
        name:      nameInput.value.trim(),
        phone:     phoneInput.value.trim(),
        address:   addressInput.value.trim(),
        createdAt: new Date().toISOString()
      };
  
      const list = getRegistrations();
      list.push(registration);
      saveRegistrations(list);
  
      tokenInput.value = registration.token;
      showResult(registration);
    });
  
    /* --------------------------- Result screen ---------------------------- */
  
    function showResult(reg) {
      slipToken.textContent   = reg.token;
      slipName.textContent    = reg.name;
      slipPhone.textContent   = "+91 " + reg.phone;
      slipAddress.textContent = reg.address;
      slipDate.textContent    = formatDate(reg.createdAt);
  
      formPanel.hidden   = true;
      resultPanel.hidden = false;
      resultPanel.setAttribute("data-enter", "");
  
      resultPanel.scrollIntoView({ behavior: "smooth", block: "start" });
      printBtn.focus({ preventScroll: true });
    }
  
    /* ------------------------------ Actions -------------------------------- */
  
    printBtn.addEventListener("click", function () {
      window.print(); // Browser print dialog also offers "Save as PDF".
    });
  
    againBtn.addEventListener("click", function () {
      form.reset();
      tokenInput.value = TOKEN_PLACEHOLDER;
  
      setError(nameInput, nameError, "");
      setError(phoneInput, phoneError, "");
      setError(addressInput, addressError, "");
  
      resultPanel.hidden = true;
      resultPanel.removeAttribute("data-enter");
      formPanel.hidden   = false;
  
      updateCount();
      formPanel.scrollIntoView({ behavior: "smooth", block: "start" });
      nameInput.focus({ preventScroll: true });
    });
  
    /* ------------------------------- Init ---------------------------------- */
  
    tokenInput.value = TOKEN_PLACEHOLDER;
    updateCount();
  })();