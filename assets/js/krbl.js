document.addEventListener("click", function (e) {
  var btn = e.target.closest(".krbl-report-btn");
  if (!btn || btn.disabled || typeof KRBL === "undefined") return;

  e.preventDefault();

  var container = btn.closest(".krbl-report-container");
  var out = container ? container.querySelector(".krbl-output") : null;
  var targetInput = container ? container.querySelector(".krbl-target-url") : null;
  var targetUrl = targetInput ? targetInput.value.trim() : "";
  var i18n = KRBL.i18n || {};

  function showMessage(type, message) {
    if (!out) return;
    var node = document.createElement("div");
    node.className = "krbl-msg " + type;
    node.textContent = message;
    out.replaceChildren(node);
  }

  if (targetUrl) {
    try {
      var parsed = new URL(targetUrl);
      if (
        !targetInput.checkValidity() || targetUrl.length > 2048 ||
        ["http:", "https:"].indexOf(parsed.protocol) === -1 ||
        !parsed.hostname || parsed.username || parsed.password
      ) throw new Error("Invalid URL");
    } catch (error) {
      showMessage("error", i18n.badUrl || "Enter a complete HTTP or HTTPS URL, or leave the field empty.");
      targetInput.focus();
      return;
    }
  }

  btn.disabled = true;
  btn.classList.add("is-loading");
  if (targetInput) targetInput.disabled = true;

  var txt = btn.querySelector(".krbl-text");
  var originalText = txt ? txt.textContent : "";
  if (txt) txt.textContent = i18n.sending || "Sending...";

  var fd = new FormData();
  fd.append("action", "krbl_submit_report");
  fd.append("_wpnonce", KRBL.nonce);
  fd.append("post_id", btn.dataset.post);
  fd.append("target_url", targetUrl);

  fetch(KRBL.ajaxUrl, { method: "POST", credentials: "same-origin", body: fd })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      if (res && res.success) {
        showMessage("success", i18n.thanks || "Thanks, your report has been sent!");
        if (targetInput) targetInput.value = "";
      } else if (res && res.data && res.data.code === "duplicate") {
        showMessage("notice", i18n.dup || "Already reported recently. Thank you!");
      } else {
        var message = res && res.data;
        if (message && typeof message === "object") message = message.message;
        showMessage("error", typeof message === "string" ? message : (i18n.fail || "Failed to send. Try again later."));
      }
    })
    .catch(function () {
      showMessage("error", i18n.error || "Error. Please try again.");
    })
    .finally(function () {
      btn.disabled = false;
      btn.classList.remove("is-loading");
      if (txt) txt.textContent = originalText;
      if (targetInput) targetInput.disabled = false;
    });
});
