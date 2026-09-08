(function () {
  "use strict";

  var API_BASE = (window.D7_API_BASE || "/wp-json/d7-ganesh/v1").replace(/\/+$/, "");
  var KEY_STORAGE = "d7_ganesh_admin_key";
  var accessPanel = document.getElementById("accessPanel");
  var accessForm = document.getElementById("accessForm");
  var adminKey = document.getElementById("adminKey");
  var accessAlert = document.getElementById("accessAlert");
  var dashboard = document.getElementById("dashboard");
  var dashboardAlert = document.getElementById("dashboardAlert");
  var signOutBtn = document.getElementById("signOutBtn");
  var refreshBtn = document.getElementById("refreshBtn");
  var filterForm = document.getElementById("filterForm");
  var searchInput = document.getElementById("searchInput");
  var statusInput = document.getElementById("statusInput");
  var recordsBody = document.getElementById("recordsBody");
  var resultCount = document.getElementById("resultCount");
  var pagination = document.getElementById("pagination");
  var detailDialog = document.getElementById("detailDialog");
  var closeDialogBtn = document.getElementById("closeDialogBtn");
  var currentPage = 1;
  var totalPages = 1;

  function getKey() {
    try { return sessionStorage.getItem(KEY_STORAGE) || ""; } catch (error) { return ""; }
  }

  function setKey(value) {
    try { sessionStorage.setItem(KEY_STORAGE, value); } catch (error) {}
  }

  function clearKey() {
    try { sessionStorage.removeItem(KEY_STORAGE); } catch (error) {}
  }

  function request(path) {
    var headers = { "Content-Type": "application/json" };
    var key = getKey();
    if (key) headers["X-D7-Admin-Key"] = key;

    return fetch(API_BASE + path, { headers: headers, credentials: "include" })
      .then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (json) {
          if (!response.ok) {
            var error = new Error(json.message || "Request failed");
            error.status = response.status;
            throw error;
          }
          return { data: json, headers: response.headers };
        });
      });
  }

  function showAlert(element, message) {
    element.textContent = message;
    element.hidden = !message;
  }

  function enterDashboard() {
    accessPanel.hidden = true;
    dashboard.hidden = false;
    signOutBtn.hidden = false;
    loadDashboard();
  }

  function rejectAccess(message) {
    dashboard.hidden = true;
    accessPanel.hidden = false;
    signOutBtn.hidden = true;
    showAlert(accessAlert, message || "Access denied. Check the administrator key.");
    adminKey.focus();
  }

  function formatNumber(value) {
    return Number(value || 0).toLocaleString("en-IN");
  }

  function loadStats() {
    return request("/admin-stats").then(function (result) {
      document.getElementById("totalStat").textContent = formatNumber(result.data.total);
      document.getElementById("todayStat").textContent = formatNumber(result.data.today);
      document.getElementById("pendingStat").textContent = formatNumber(result.data.pending);
      document.getElementById("collectedStat").textContent = formatNumber(result.data.collected);
    });
  }

  function loadRecords() {
    var query = new URLSearchParams({
      search: searchInput.value.trim(),
      status: statusInput.value,
      page: String(currentPage),
      per_page: "20"
    });
    recordsBody.innerHTML = '<tr><td class="empty" colspan="7">Loading registrations...</td></tr>';

    return request("/registrations?" + query.toString()).then(function (result) {
      var rows = result.data;
      var total = Number(result.headers.get("X-WP-Total") || rows.length);
      totalPages = Number(result.headers.get("X-WP-TotalPages") || 1);
      resultCount.textContent = total + " result" + (total === 1 ? "" : "s");
      recordsBody.innerHTML = "";

      if (!rows.length) {
        recordsBody.innerHTML = '<tr><td class="empty" colspan="7">No registrations match this filter.</td></tr>';
      } else {
        rows.forEach(function (row) { recordsBody.appendChild(createRow(row)); });
      }
      renderPagination();
      return rows;
    });
  }

  function createRow(row) {
    var tr = document.createElement("tr");
    tr.innerHTML = "<td><strong></strong></td><td></td><td></td><td></td><td></td><td><span class=\"status\"></span></td><td><button class=\"view-button\" type=\"button\">View</button></td>";
    tr.children[0].firstChild.textContent = row.token || "-";
    tr.children[1].textContent = row.name || "-";
    tr.children[2].textContent = row.phone || "-";
    tr.children[3].textContent = row.address || "-";
    tr.children[4].textContent = row.created_display || row.created_at || "-";
    var status = tr.children[5].firstChild;
    status.textContent = row.status || "-";
    status.classList.add(row.status === "collected" ? "status--collected" : "status--pending");
    tr.lastChild.firstChild.addEventListener("click", function () { openDetails(row); });
    return tr;
  }

  function renderPagination() {
    pagination.innerHTML = "";
    if (totalPages <= 1) return;
    [
      { label: "Previous", page: currentPage - 1, disabled: currentPage === 1 },
      { label: "Page " + currentPage + " of " + totalPages, page: currentPage, disabled: true },
      { label: "Next", page: currentPage + 1, disabled: currentPage === totalPages }
    ].forEach(function (item) {
      var button = document.createElement("button");
      button.type = "button";
      button.textContent = item.label;
      button.disabled = item.disabled;
      button.addEventListener("click", function () {
        currentPage = item.page;
        loadRecords().catch(handleDashboardError);
      });
      pagination.appendChild(button);
    });
  }

  function loadDashboard() {
    showAlert(accessAlert, "");
    showAlert(dashboardAlert, "");
    Promise.all([loadStats(), loadRecords()]).catch(handleDashboardError);
  }

  function handleDashboardError(error) {
    if (error && (error.status === 401 || error.status === 403)) {
      clearKey();
      rejectAccess("Access denied. Check the administrator key or sign in through WordPress.");
      return;
    }
    showAlert(dashboardAlert, error.message || "Could not load registration data.");
  }

  function openDetails(row) {
    document.getElementById("detailToken").textContent = row.token || "Registration";
    var fields = [["Name", row.name], ["Phone", row.phone], ["Address", row.address], ["Registered", row.created_display || row.created_at], ["Status", row.status]];
    var detailList = document.getElementById("detailList");
    detailList.innerHTML = "";
    fields.forEach(function (field) {
      var dt = document.createElement("dt");
      var dd = document.createElement("dd");
      dt.textContent = field[0];
      dd.textContent = field[1] || "-";
      detailList.appendChild(dt);
      detailList.appendChild(dd);
    });
    detailDialog.showModal();
  }

  accessForm.addEventListener("submit", function (event) {
    event.preventDefault();
    var value = adminKey.value.trim();
    if (!value) return;
    setKey(value);
    request("/registrations?per_page=1").then(function () {
      adminKey.value = "";
      enterDashboard();
    }).catch(function () {
      clearKey();
      rejectAccess("Access denied. Check the administrator key.");
    });
  });

  filterForm.addEventListener("submit", function (event) {
    event.preventDefault();
    currentPage = 1;
    showAlert(dashboardAlert, "");
    loadRecords().catch(handleDashboardError);
  });

  refreshBtn.addEventListener("click", function () { loadDashboard(); });
  signOutBtn.addEventListener("click", function () { clearKey(); location.reload(); });
  closeDialogBtn.addEventListener("click", function () { detailDialog.close(); });
  detailDialog.addEventListener("click", function (event) { if (event.target === detailDialog) detailDialog.close(); });

  if (getKey()) enterDashboard();
})();
