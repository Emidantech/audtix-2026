/* Small behaviours for the admin panel. Inline scripts are blocked by the panel's security policy, so they live here. */
(function () {
  // <button data-confirm="Are you sure?"> and <form data-confirm="...">
  document.addEventListener("click", function (e) {
    var b = e.target.closest("[data-confirm]");
    if (b && b.tagName !== "FORM" && !window.confirm(b.getAttribute("data-confirm"))) { e.preventDefault(); e.stopPropagation(); }
  }, true);
  document.addEventListener("submit", function (e) {
    var f = e.target;
    if (f.hasAttribute("data-confirm") && !window.confirm(f.getAttribute("data-confirm"))) e.preventDefault();
  }, true);
  // <select data-autosubmit> submits its form on change
  document.addEventListener("change", function (e) {
    if (e.target.matches("[data-autosubmit]")) e.target.form && e.target.form.submit();
    // select-all checkbox for bulk actions
    if (e.target.matches("[data-check-all]")) {
      document.querySelectorAll('input[name="' + e.target.getAttribute("data-check-all") + '"]').forEach(function (c) { c.checked = e.target.checked; });
    }
    if (e.target.matches("[data-check-all], input[name='ids[]']")) {
      var n = document.querySelectorAll("input[name='ids[]']:checked").length, bar = document.getElementById("bulk-bar");
      if (bar) { bar.classList.toggle("d-none", n === 0); var c = bar.querySelector("[data-count]"); if (c) c.textContent = n; }
    }
  });
  // Items editor on the quotation form (admin)
  document.addEventListener("click", function (e) {
    var add = e.target.closest("[data-add-row]");
    if (add) {
      var body = document.getElementById(add.getAttribute("data-add-row")), tpl = body && body.querySelector("tr");
      if (!tpl) return;
      var r = tpl.cloneNode(true), idx = body.querySelectorAll("tr").length;
      r.querySelectorAll("input").forEach(function (i) { i.value = ""; i.name = i.name.replace(/\[\d+\]/, "[" + idx + "]"); });
      body.appendChild(r); r.querySelector("input").focus();
    }
    var del = e.target.closest("[data-del-row]");
    if (del) {
      var tr = del.closest("tr"), tb = tr.parentNode;
      if (tb.querySelectorAll("tr").length > 1) tr.remove(); else tr.querySelectorAll("input").forEach(function (i) { i.value = ""; });
    }
  });
  // Show/hide rules on the admin quotation form (same language as the public form)
  function val(form, n) {
    var els = form.querySelectorAll('[name="' + n + '"],[name="' + n + '[]"]'); if (!els.length) return "";
    if (els[0].type === "radio") { var c = Array.prototype.filter.call(els, function (x) { return x.checked; })[0]; return c ? c.value : ""; }
    if (els[0].type === "checkbox") return Array.prototype.filter.call(els, function (x) { return x.checked; }).map(function (x) { return x.value; });
    return els[0].value || "";
  }
  function sync(form) {
    form.querySelectorAll("[data-show]").forEach(function (el) {
      var r = el.getAttribute("data-show"), m, ok = true, v;
      if ((m = r.match(/^(\w+)~(.+)$/))) { v = val(form, m[1]); ok = Array.isArray(v) && v.indexOf(m[2]) > -1; }
      else if ((m = r.match(/^(\w+)!=(.*)$/))) { v = val(form, m[1]); ok = v !== "" && v !== m[2]; }
      else if ((m = r.match(/^(\w+)=(.+)$/))) ok = val(form, m[1]) === m[2];
      el.classList.toggle("d-none", !ok);
    });
  }
  document.querySelectorAll("form[data-rules]").forEach(function (f) { sync(f); f.addEventListener("change", function () { sync(f); }); });
})();
