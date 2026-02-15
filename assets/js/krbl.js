document.addEventListener("click", function (e) {
  var btn = e.target.closest(".krbl-report-btn");
  if (!btn) return;

  e.preventDefault();

  var container = btn.closest(".krbl-report-container");
  var out = container ? container.querySelector(".krbl-output") : null;

  btn.disabled = true;
  btn.classList.add("is-loading");

  var txt = btn.querySelector(".krbl-text");
  if (txt) {
    txt.textContent = "⏳ " + (KRBL?.i18n?.sending || "Sending...");
  }

  var fd = new FormData();
  fd.append("action", "krbl_submit_report");
  fd.append("_wpnonce", KRBL.nonce);
  fd.append("post_id", btn.dataset.post);

  fetch(KRBL.ajaxUrl, { method: "POST", credentials: "same-origin", body: fd })
    .then(function (r) {
      return r.json();
    })
    .then(function (res) {
      if (!out) return;

      if (res && res.success) {
        out.innerHTML =
          "<div class='krbl-msg success'>✅ " +
          (KRBL?.i18n?.thanks || "Thanks, your report has been sent!") +
          "</div>";
        btn.remove();
        return;
      }

      if (res && res.data && res.data.code === "duplicate") {
        out.innerHTML =
          "<div class='krbl-msg notice'>ℹ️ " +
          (KRBL?.i18n?.dup || "Already reported recently. Thank you!") +
          "</div>";
        btn.remove();
        return;
      }

      out.innerHTML =
        "<div class='krbl-msg error'>⚠️ " +
        (KRBL?.i18n?.fail || "Failed to send. Try again later.") +
        "</div>";
      btn.remove();
    })
    .catch(function () {
      if (!out) return;

      out.innerHTML =
        "<div class='krbl-msg error'>⚠️ " +
        (KRBL?.i18n?.error || "Error. Please try again.") +
        "</div>";
      btn.remove();
    });
});
