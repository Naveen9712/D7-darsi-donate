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
  var dashboardNotice = document.getElementById("dashboardNotice");
  var signOutBtn = document.getElementById("signOutBtn");
  var refreshBtn = document.getElementById("refreshBtn");
  var exportBtn = document.getElementById("exportBtn");
  var filterForm = document.getElementById("filterForm");
  var searchInput = document.getElementById("searchInput");
  var statusInput = document.getElementById("statusInput");
  var recordsBody = document.getElementById("recordsBody");
  var resultCount = document.getElementById("resultCount");
  var pagination = document.getElementById("pagination");
  var detailDialog = document.getElementById("detailDialog");
  var closeDialogBtn = document.getElementById("closeDialogBtn");
  var dialogCollectBtn = document.getElementById("dialogCollectBtn");
  var dialogDeleteBtn = document.getElementById("dialogDeleteBtn");
  var currentPage = 1;
  var totalPages = 1;
  var currentRow = null;
  var noticeTimer = null;

  /* ----------------------------- session key ---------------------------- */

  function getKey() {
    try { return sessionStorage.getItem(KEY_STORAGE) || ""; } catch (error) { return ""; }
  }

  function setKey(value) {
    try { sessionStorage.setItem(KEY_STORAGE, value); } catch (error) {}
  }

  function clearKey() {
    try { sessionStorage.removeItem(KEY_STORAGE); } catch (error) {}
  }

  function apiUrl(path, query) {
    var url = new URL(API_BASE + path, window.location.href);
    if (query) {
      Object.keys(query).forEach(function (name) {
        var value = query[name];
        if (value !== undefined && value !== null && value !== "") url.searchParams.set(name, value);
      });
    }
    return url;
  }

  function withKeyFallback(path, key) {
    // Do not send X-D7-Admin-Key from the browser. pncreators.com answers
    // OPTIONS with Allow-Headers: Authorization, Content-Type, X-Requested-With
    // (the PHP snippet never sees that preflight), so a custom header is
    // blocked by CORS even when the key is correct. admin_key on the query
    // string or POST body is a simple request and reaches WordPress.
    if (!key) return { path: path, query: null };
    if (path.indexOf("admin_key=") !== -1) return { path: path, query: null };
    return { path: path, query: { admin_key: key } };
  }

  /* ------------------------------ transport ----------------------------- */

  function handleResponse(response) {
    return response.json().catch(function () { return {}; }).then(function (json) {
      if (!response.ok) {
        var error = new Error(json.message || "Request failed");
        error.status = response.status;
        throw error;
      }
      return { data: json, headers: response.headers };
    });
  }

  function request(path) {
    var key = getKey();
    var routed = withKeyFallback(path, key);
    return fetch(apiUrl(routed.path, routed.query).toString(), {
      credentials: "omit"
    }).then(handleResponse);
  }

  function post(path, body) {
    var key = getKey();
    var payload = body || {};
    if (key && !payload.admin_key) payload.admin_key = key;
    return fetch(apiUrl(path).toString(), {
      method: "POST",
      credentials: "omit",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams(payload).toString()
    }).then(handleResponse);
  }

  /* ------------------------------- messages ------------------------------ */

  function showAlert(element, message) {
    element.textContent = message;
    element.hidden = !message;
  }

  function showNotice(message) {
    dashboardNotice.textContent = message;
    dashboardNotice.hidden = !message;

    window.clearTimeout(noticeTimer);
    if (message) {
      noticeTimer = window.setTimeout(function () { dashboardNotice.hidden = true; }, 4000);
    }
  }

  /* ------------------------------- screens ------------------------------- */

  function enterDashboard() {
    accessPanel.hidden = true;
    dashboard.hidden = false;
    signOutBtn.hidden = false;
    exportBtn.hidden = false;
    loadDashboard();
  }

  function rejectAccess(message) {
    dashboard.hidden = true;
    accessPanel.hidden = false;
    signOutBtn.hidden = true;
    exportBtn.hidden = true;
    showAlert(accessAlert, message || "Access denied. Check the administrator key.");
    adminKey.focus();
  }

  function formatNumber(value) {
    return Number(value || 0).toLocaleString("en-IN");
  }

  /* -------------------------------- data --------------------------------- */

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

  function loadDashboard() {
    showAlert(accessAlert, "");
    showAlert(dashboardAlert, "");
    return Promise.all([loadStats(), loadRecords()]).catch(handleDashboardError);
  }

  function handleDashboardError(error) {
    if (error && (error.status === 401 || error.status === 403)) {
      clearKey();
      rejectAccess("Access denied. Check the administrator key or sign in through WordPress.");
      return;
    }
    showAlert(dashboardAlert, error.message || "Could not load registration data.");
  }

  /* ------------------------------- actions -------------------------------- */

  function setStatus(row, status) {
    return post("/registrations/" + row.id + "/status", { status: status })
      .then(function () {
        showNotice(tokenLabel(row) + " marked as " + status + ".");
        return loadDashboard();
      })
      .catch(handleDashboardError);
  }

  function deleteRow(row) {
    var confirmed = window.confirm(
      "Delete " + tokenLabel(row) + " (" + row.name + ")?\n\n" +
      "This cannot be undone. The phone number becomes free to register again, " +
      "but this token number will never be reissued."
    );
    if (!confirmed) return Promise.resolve();

    return post("/registrations/" + row.id + "/delete", {})
      .then(function () {
        showNotice(tokenLabel(row) + " deleted.");
        return loadDashboard();
      })
      .catch(handleDashboardError);
  }

  /* -------------------------------- table --------------------------------- */

  function tokenLabel(row) {
    return row.token_display || row.token || "-";
  }

  function createRow(row) {
    var tr = document.createElement("tr");
    tr.innerHTML =
      "<td><strong></strong></td><td></td><td></td><td></td><td></td>" +
      '<td><span class="status"></span></td>' +
      '<td><div class="row-actions"></div></td>';

    var labels = ["Token", "Name", "Phone", "Address", "Registered", "Status", "Actions"];
    Array.prototype.forEach.call(tr.children, function (cell, index) {
      cell.setAttribute("data-label", labels[index]);
    });

    tr.children[0].firstChild.textContent = row.token || "-";
    if (row.old_token) {
      var old = document.createElement("span");
      old.className = "token-old";
      old.textContent = "(Old: " + row.old_token + ")";
      tr.children[0].appendChild(old);
    }
    tr.children[1].textContent = row.name || "-";
    tr.children[2].textContent = row.phone || "-";
    tr.children[3].textContent = row.address || "-";
    tr.children[4].textContent = row.created_display || row.created_at || "-";

    var status = tr.children[5].firstChild;
    status.textContent = row.status || "-";
    status.classList.add(row.status === "collected" ? "status--collected" : "status--pending");

    var actions = tr.children[6].firstChild;
    actions.appendChild(actionButton("View", "", function () { openDetails(row); }));

    if (row.status === "collected") {
      actions.appendChild(actionButton("Undo", "", function () { setStatus(row, "pending"); }));
    } else {
      actions.appendChild(actionButton("Collected", "is-primary", function () { setStatus(row, "collected"); }));
    }

    actions.appendChild(actionButton("Delete", "is-danger", function () { deleteRow(row); }));

    return tr;
  }

  function actionButton(label, modifier, onClick) {
    var button = document.createElement("button");
    button.type = "button";
    button.className = "row-action" + (modifier ? " row-action--" + modifier.replace("is-", "") : "");
    button.textContent = label;
    button.addEventListener("click", onClick);
    return button;
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

  /* -------------------------------- dialog -------------------------------- */

  function openDetails(row) {
    currentRow = row;
    document.getElementById("detailToken").textContent = tokenLabel(row);

    var fields = [
      ["Name", row.name],
      ["Phone", row.phone],
      ["Address", row.address],
      ["Token", row.token],
      ["Original token", row.old_token],
      ["Gift", row.is_special ? "Special idol" : "Standard idol"],
      ["Registered", row.created_display || row.created_at],
      ["Status", row.status]
    ];

    var detailList = document.getElementById("detailList");
    detailList.innerHTML = "";
    fields.forEach(function (field) {
      if (field[0] === "Original token" && !field[1]) return;
      var dt = document.createElement("dt");
      var dd = document.createElement("dd");
      dt.textContent = field[0];
      dd.textContent = field[1] || "-";
      detailList.appendChild(dt);
      detailList.appendChild(dd);
    });

    dialogCollectBtn.textContent = row.status === "collected"
      ? "Move back to pending"
      : "Mark idol collected";

    detailDialog.showModal();
  }

  /* -------------------------------- events -------------------------------- */

  accessForm.addEventListener("submit", function (event) {
    event.preventDefault();
    var value = adminKey.value.trim();
    if (!value) return;
    setKey(value);
    request("/registrations?per_page=1").then(function () {
      adminKey.value = "";
      enterDashboard();
    }).catch(function (error) {
      clearKey();
      if (error && error.status === 401) {
        rejectAccess("Access denied. Check the administrator key.");
        return;
      }
      if (error && error.status === 403) {
        rejectAccess("Access denied. This key is not allowed to view registrations.");
        return;
      }
      rejectAccess((error && error.message) || "Could not reach the registrations API. Check your connection and try again.");
    });
  });

  filterForm.addEventListener("submit", function (event) {
    event.preventDefault();
    currentPage = 1;
    showAlert(dashboardAlert, "");
    loadRecords().catch(handleDashboardError);
  });

  exportBtn.addEventListener("click", function () {
    var key = getKey();
    var routed = withKeyFallback("/export", key);
    fetch(apiUrl(routed.path, routed.query).toString(), {
      credentials: "omit"
    }).then(function (response) {
      if (!response.ok) {
        var error = new Error("Export failed");
        error.status = response.status;
        throw error;
      }
      var disposition = response.headers.get("Content-Disposition") || "";
      var filename = "d7-ganesh-registrations.csv";
      var match = disposition.match(/filename=([^;]+)/i);
      if (match) filename = match[1].replace(/["']/g, "").trim();
      return response.blob().then(function (blob) {
        return { blob: blob, filename: filename };
      });
    }).then(function (file) {
      var url = URL.createObjectURL(file.blob);
      var link = document.createElement("a");
      link.href = url;
      link.download = file.filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    }).catch(handleDashboardError);
  });

  refreshBtn.addEventListener("click", function () { loadDashboard(); });
  signOutBtn.addEventListener("click", function () { clearKey(); location.reload(); });
  closeDialogBtn.addEventListener("click", function () { detailDialog.close(); });
  detailDialog.addEventListener("click", function (event) {
    if (event.target === detailDialog) detailDialog.close();
  });

  dialogCollectBtn.addEventListener("click", function () {
    if (!currentRow) return;
    var next = currentRow.status === "collected" ? "pending" : "collected";
    detailDialog.close();
    setStatus(currentRow, next);
  });

  dialogDeleteBtn.addEventListener("click", function () {
    if (!currentRow) return;
    var row = currentRow;
    detailDialog.close();
    deleteRow(row);
  });

  if (getKey()) enterDashboard();
})();