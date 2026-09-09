/* ==========================================================================
   D7 News Telugu — ఉచిత గణేష్ విగ్రహాల నమోదు
   script.js — front-end validation + WordPress REST API client.

   WordPress is now the source of truth. The token number is issued by the
   server (derived from the database row id), so it is unique across every
   device and browser. localStorage is kept only as a personal receipt cache
   so the devotee can reopen their own token after a refresh.
   ========================================================================== */

   (function () {
    "use strict";
  
    /* ------------------------------ Config ------------------------------- */
  
    var API_BASE = (window.D7_API_BASE || "/wp-json/d7-ganesh/v1").replace(/\/+$/, "");
    var REST_NONCE = window.D7_NONCE || "";
  
    var RECEIPT_KEY = "d7_ganesh_my_token_v2"; // this browser's own registration
    var TOKEN_PLACEHOLDER = "నమోదు తర్వాత కేటాయించబడుతుంది";
  
    /* ------------------------------- DOM --------------------------------- */
  
    var form        = document.getElementById("regForm");
    var formPanel   = document.getElementById("formPanel");
    var resultPanel = document.getElementById("resultPanel");
  
    var nameInput    = document.getElementById("name");
    var phoneInput   = document.getElementById("phone");
    var addressInput = document.getElementById("address");
    var tokenInput   = document.getElementById("token");
    var honeypot     = document.getElementById("website");
  
    var nameError    = document.getElementById("nameError");
    var phoneError   = document.getElementById("phoneError");
    var addressError = document.getElementById("addressError");
    var formAlert    = document.getElementById("formAlert");
  
    var slipToken   = document.getElementById("slipToken");
    var slipName    = document.getElementById("slipName");
    var slipPhone   = document.getElementById("slipPhone");
    var slipAddress = document.getElementById("slipAddress");
    var slipDate    = document.getElementById("slipDate");
  
    var submitBtn = document.getElementById("submitBtn");
    var printBtn  = document.getElementById("printBtn");
    var againBtn  = document.getElementById("againBtn");
    var regCount  = document.getElementById("regCount");
  
    var SUBMIT_LABEL = submitBtn.textContent;
  
    /* ---------------------------- API helper ------------------------------ */
  
    /**
     * Small fetch wrapper. Resolves with parsed JSON on 2xx, rejects with the
     * WordPress error body ({ code, message, data }) on anything else.
     */
    function apiRequest(path, options) {
      options = options || {};
  
      var headers = { "Content-Type": "application/json" };
      if (REST_NONCE) headers["X-WP-Nonce"] = REST_NONCE;
  
      return fetch(API_BASE + path, {
        method: options.method || "GET",
        headers: headers,
        body: options.body ? JSON.stringify(options.body) : undefined
      }).then(function (response) {
        return response.json().catch(function () {
          return {};
        }).then(function (json) {
          if (!response.ok) {
            json.httpStatus = response.status;
            throw json;
          }
          return json;
        });
      });
    }
  
    /* ---------------------- Local receipt (this browser) ------------------ */
  
    function saveReceipt(data) {
      try {
        window.localStorage.setItem(RECEIPT_KEY, JSON.stringify(data));
      } catch (e) {
        /* Private mode or quota — the token is still on screen and on the server. */
      }
    }
  
    function readReceipt() {
      try {
        var raw = window.localStorage.getItem(RECEIPT_KEY);
        return raw ? JSON.parse(raw) : null;
      } catch (e) {
        return null;
      }
    }
  
    function clearReceipt() {
      try {
        window.localStorage.removeItem(RECEIPT_KEY);
      } catch (e) {}
    }
  
    /* ---------------------------- Validation ------------------------------ */
    /* These rules mirror the server rules in snippet 2. The browser copy is for
       fast feedback only — the server re-checks everything.                   */
  
    function setError(input, errorEl, message) {
      var field = input.closest(".field");
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
      var value = nameInput.value.trim();
      if (!value) {
        setError(nameInput, nameError, "దయచేసి మీ పేరు రాయండి.");
        return false;
      }
      if (value.length < 3) {
        setError(nameInput, nameError, "పేరు కనీసం 3 అక్షరాలు ఉండాలి.");
        return false;
      }
      if (/\d/.test(value)) {
        setError(nameInput, nameError, "పేరులో అంకెలు ఉండకూడదు.");
        return false;
      }
      setError(nameInput, nameError, "");
      return true;
    }
  
    function validatePhone() {
      var value = phoneInput.value.trim();
      if (!value) {
        setError(phoneInput, phoneError, "దయచేసి మీ ఫోన్ నంబర్ రాయండి.");
        return false;
      }
      if (!/^[6-9]\d{9}$/.test(value)) {
        setError(phoneInput, phoneError, "సరైన 10 అంకెల మొబైల్ నంబర్ రాయండి (6, 7, 8 లేదా 9 తో మొదలవ్వాలి).");
        return false;
      }
      setError(phoneInput, phoneError, "");
      return true;
    }
  
    function validateAddress() {
      var value = addressInput.value.trim();
      if (!value) {
        setError(addressInput, addressError, "దయచేసి మీ చిరునామా రాయండి.");
        return false;
      }
      if (value.length < 10) {
        setError(addressInput, addressError, "పూర్తి చిరునామా రాయండి (ఊరు, పిన్ కోడ్ సహా).");
        return false;
      }
      setError(addressInput, addressError, "");
      return true;
    }
  
    /* -------------------------- Input behaviour --------------------------- */
  
    phoneInput.addEventListener("input", function () {
      this.value = this.value.replace(/\D/g, "").slice(0, 10);
      if (this.closest(".field").classList.contains("field--invalid")) validatePhone();
    });
  
    nameInput.addEventListener("input", function () {
      if (this.closest(".field").classList.contains("field--invalid")) validateName();
    });
  
    addressInput.addEventListener("input", function () {
      if (this.closest(".field").classList.contains("field--invalid")) validateAddress();
    });
  
    tokenInput.addEventListener("focus", function () {
      this.blur();
    });
  
    /* ------------------------------ Alerts -------------------------------- */
  
    function showAlert(message) {
      formAlert.textContent = message;
      formAlert.hidden = false;
    }
  
    function hideAlert() {
      formAlert.textContent = "";
      formAlert.hidden = true;
    }
  
    function setBusy(busy) {
      submitBtn.setAttribute("aria-busy", busy ? "true" : "false");
      submitBtn.disabled = busy;
      submitBtn.textContent = busy ? "నమోదు అవుతోంది…" : SUBMIT_LABEL;
    }
  
    /* ------------------------------ Submit -------------------------------- */
  
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      hideAlert();
  
      // Run all three so every problem is shown at once.
      var okName    = validateName();
      var okPhone   = validatePhone();
      var okAddress = validateAddress();
  
      if (!(okName && okPhone && okAddress)) {
        var firstInvalid = form.querySelector(".field--invalid .field__input");
        if (firstInvalid) firstInvalid.focus();
        return;
      }
  
      setBusy(true);
  
      apiRequest("/register", {
        method: "POST",
        body: {
          name: nameInput.value.trim(),
          phone: phoneInput.value.trim(),
          address: addressInput.value.trim(),
          website: honeypot ? honeypot.value : ""
        }
      })
        .then(function (json) {
          var data = json.data;
          tokenInput.value = data.token;
          saveReceipt(data);
          showResult(data);
          loadCount();
        })
        .catch(handleSubmitError)
        .then(function () {
          setBusy(false);
        });
    });
  
    /**
     * Map a WordPress REST error onto the right field, or the alert banner.
     */
    function handleSubmitError(error) {
      error = error || {};
      var code = error.code || "";
      var data = error.data || {};
      var message = error.message || "";
  
      // Duplicate number — show the devotee the token they already have.
      if (code === "d7_duplicate_phone") {
        var token = data.token ? " మీ టోకెన్ నంబర్: " + data.token : "";
        setError(phoneInput, phoneError, "ఈ ఫోన్ నంబర్ ఇప్పటికే నమోదైంది." + token);
        phoneInput.focus();
        return;
      }
  
      // Field-specific server rejections.
      if (data.field === "name")    { setError(nameInput, nameError, message); nameInput.focus(); return; }
      if (data.field === "phone")   { setError(phoneInput, phoneError, message); phoneInput.focus(); return; }
      if (data.field === "address") { setError(addressInput, addressError, message); addressInput.focus(); return; }
  
      if (code === "d7_rate_limited") {
        showAlert(message);
        return;
      }
  
      // Network failure, CORS problem, or the site being down.
      if (!error.httpStatus) {
        showAlert("ఇంటర్నెట్ కనెక్షన్ అందడం లేదు. కనెక్షన్ చూసుకుని మళ్లీ ప్రయత్నించండి.");
        return;
      }
  
      showAlert(message || "నమోదు పూర్తి కాలేదు. కొంత సేపటి తర్వాత మళ్లీ ప్రయత్నించండి.");
    }
  
    /* --------------------------- Result screen ---------------------------- */
  
    function showResult(data, skipScroll) {
      slipToken.textContent   = data.token;
      slipName.textContent    = data.name;
      slipPhone.textContent   = "+91 " + data.phone;
      slipAddress.textContent = data.address;
      slipDate.textContent    = data.created_display || data.created_at || "";
  
      formPanel.hidden   = true;
      resultPanel.hidden = false;
      resultPanel.setAttribute("data-enter", "");
  
      if (!skipScroll) {
        resultPanel.scrollIntoView({ behavior: "smooth", block: "start" });
        printBtn.focus({ preventScroll: true });
      }
    }
  
    /* ------------------------------ Actions -------------------------------- */
  
    printBtn.addEventListener("click", function () {
      window.print(); // The print dialog also offers "Save as PDF".
    });
  
    // "Another registration" — used when one person registers for a neighbour.
    againBtn.addEventListener("click", function () {
      form.reset();
      tokenInput.value = TOKEN_PLACEHOLDER;
      if (honeypot) honeypot.value = "";
  
      setError(nameInput, nameError, "");
      setError(phoneInput, phoneError, "");
      setError(addressInput, addressError, "");
      hideAlert();
      clearReceipt();
  
      resultPanel.hidden = true;
      resultPanel.removeAttribute("data-enter");
      formPanel.hidden = false;
  
      formPanel.scrollIntoView({ behavior: "smooth", block: "start" });
      nameInput.focus({ preventScroll: true });
    });
  
    /* ------------------------------- Count ---------------------------------- */
  
    function loadCount() {
      apiRequest("/stats")
        .then(function (json) {
          regCount.textContent = json.total;
        })
        .catch(function () {
          // Keep the last known value rather than showing a broken zero.
        });
    }

    /* --------------------------- Story sliders --------------------------- */

    function setupCarousels() {
      var carousels = document.querySelectorAll("[data-carousel]");

      Array.prototype.forEach.call(carousels, function (carousel) {
        var track = carousel.querySelector(".carousel__track");
        var slides = carousel.querySelectorAll(".carousel__slide");
        var dots = carousel.querySelector(".carousel__dots");
        var previous = carousel.querySelector(".carousel__button--prev");
        var next = carousel.querySelector(".carousel__button--next");
        var current = 0;
        var startX = 0;
        var autoplayTimer;

        function goTo(index) {
          current = (index + slides.length) % slides.length;
          track.style.transform = "translateX(-" + (current * 100) + "%)";
          Array.prototype.forEach.call(slides, function (slide, slideIndex) {
            slide.classList.toggle("is-active", slideIndex === current);
            slide.setAttribute("aria-hidden", slideIndex === current ? "false" : "true");
          });
          Array.prototype.forEach.call(dots.children, function (dot, dotIndex) {
            dot.setAttribute("aria-selected", dotIndex === current ? "true" : "false");
          });
        }

        Array.prototype.forEach.call(slides, function (_, slideIndex) {
          var dot = document.createElement("button");
          dot.type = "button";
          dot.className = "carousel__dot";
          dot.setAttribute("role", "tab");
          dot.setAttribute("aria-label", "చిత్రం " + (slideIndex + 1));
          dot.addEventListener("click", function () { goTo(slideIndex); });
          dots.appendChild(dot);
        });

        previous.addEventListener("click", function () { goTo(current - 1); });
        next.addEventListener("click", function () { goTo(current + 1); });
        carousel.addEventListener("keydown", function (event) {
          if (event.key === "ArrowLeft") goTo(current - 1);
          if (event.key === "ArrowRight") goTo(current + 1);
        });
        carousel.addEventListener("pointerdown", function (event) {
          startX = event.clientX;
        });
        carousel.addEventListener("pointerup", function (event) {
          var distance = event.clientX - startX;
          if (Math.abs(distance) > 45) goTo(distance < 0 ? current + 1 : current - 1);
        });

        goTo(0);

        if (carousel.getAttribute("data-autoplay") === "true" && slides.length > 1) {
          autoplayTimer = window.setInterval(function () {
            if (!document.hidden) goTo(current + 1);
          }, 5200);
          carousel.addEventListener("mouseenter", function () { window.clearInterval(autoplayTimer); });
          carousel.addEventListener("mouseleave", function () {
            autoplayTimer = window.setInterval(function () {
              if (!document.hidden) goTo(current + 1);
            }, 5200);
          });
        }
      });
    }
  
    /* -------------------------------- Init ---------------------------------- */
  
    tokenInput.value = TOKEN_PLACEHOLDER;
    loadCount();
    setupCarousels();
  
    // If this browser already registered, reopen that token straight away.
    var receipt = readReceipt();
    if (receipt && receipt.token) {
      showResult(receipt, true);
    }
  })();