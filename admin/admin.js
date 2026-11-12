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
/* Roles page: "All" checkbox per row, and keep it in step */
(function () {
  function rowBoxes(mod) { return document.querySelectorAll('input[name="perms[]"][data-mod="' + mod + '"]'); }
  function syncAll(mod) { var all = document.querySelector('[data-row-all="' + mod + '"]'); if (!all) return; var b = rowBoxes(mod), n = 0; b.forEach(function (x) { if (x.checked) n++; }); all.checked = n === b.length; all.indeterminate = n > 0 && n < b.length; }
  document.querySelectorAll("[data-row-all]").forEach(function (a) { syncAll(a.getAttribute("data-row-all")); });
  document.addEventListener("change", function (e) {
    var t = e.target;
    if (t.matches("[data-row-all]")) rowBoxes(t.getAttribute("data-row-all")).forEach(function (x) { x.checked = t.checked; });
    if (t.matches('input[name="perms[]"]')) {
      var mod = t.getAttribute("data-mod");
      if (t.checked && !/\.view$/.test(t.value)) { var v = document.querySelector('input[name="perms[]"][value="' + mod + '.view"]'); if (v) v.checked = true; }
      if (!t.checked && /\.view$/.test(t.value)) rowBoxes(mod).forEach(function (x) { x.checked = false; });
      syncAll(mod);
    }
  });
  /* Copy-to-clipboard buttons (image library) */
  document.addEventListener("click", function (e) {
    var b = e.target.closest("[data-copy]"); if (!b) return;
    var txt = b.getAttribute("data-copy");
    (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).then(function () { var o = b.innerHTML; b.innerHTML = '<i class="bi bi-check2"></i> Copied'; setTimeout(function () { b.innerHTML = o; }, 1400); }, function () { window.prompt("Copy this:", txt); });
  });
  /* Colour pickers mirror their text box */
  document.querySelectorAll("[data-color-for]").forEach(function (p) {
    var t = document.getElementById(p.getAttribute("data-color-for"));
    p.addEventListener("input", function () { t.value = p.value.toUpperCase(); t.dispatchEvent(new Event("input")); });
    t.addEventListener("input", function () { if (/^#[0-9a-f]{6}$/i.test(t.value)) { p.value = t.value; var sw = document.querySelectorAll('[data-swatch="' + t.id + '"]'); sw.forEach(function (s) { s.style.background = t.value; }); } });
  });
  /* Email settings: show SMTP fields only for SMTP, and fill provider presets */
  var method = document.querySelectorAll('input[name="mail_method"]');
  function m() { var v = (document.querySelector('input[name="mail_method"]:checked') || {}).value; document.querySelectorAll("[data-smtp]").forEach(function (el) { el.classList.toggle("d-none", v !== "smtp"); }); }
  method.forEach(function (r) { r.addEventListener("change", m); }); if (method.length) m();
  var preset = document.getElementById("smtp-preset");
  if (preset) preset.addEventListener("change", function () {
    var o = preset.selectedOptions[0]; if (!o || !o.value) return;
    document.getElementById("smtp_host").value = o.getAttribute("data-host");
    document.getElementById("smtp_port").value = o.getAttribute("data-port");
    document.getElementById("smtp_secure").value = o.getAttribute("data-secure");
  });
  /* Post editor: slug from title, Markdown toolbar */
  var title = document.getElementById("p-title"), slug = document.getElementById("p-slug");
  if (title && slug) {
    var touched = slug.value !== "";
    slug.addEventListener("input", function () { touched = true; });
    title.addEventListener("input", function () { if (!touched) slug.value = title.value.toLowerCase().normalize("NFKD").replace(/[̀-ͯ]/g, "").replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 120); });
  }
  var body = document.getElementById("p-body");
  document.addEventListener("click", function (e) {
    var b = e.target.closest("[data-md]"); if (!b || !body) return;
    e.preventDefault();
    var k = b.getAttribute("data-md"), s = body.selectionStart, en = body.selectionEnd, sel = body.value.slice(s, en) || "text", out = sel;
    var wrap = { bold: ["**", "**"], italic: ["*", "*"], link: ["[", "](https://)"], code: ["`", "`"] }[k];
    if (wrap) out = wrap[0] + sel + wrap[1];
    if (k === "h2") out = "\n## " + sel + "\n"; if (k === "h3") out = "\n### " + sel + "\n";
    if (k === "ul") out = "\n- " + sel.split("\n").join("\n- ") + "\n"; if (k === "ol") out = "\n1. " + sel.split("\n").join("\n1. ") + "\n";
    if (k === "quote") out = "\n> " + sel + "\n";
    if (k === "img") { var u = window.prompt("Image address (copy one from the Image library):", "/uploads/media/"); if (!u) return; out = "![" + (body.value.slice(s, en) || "Image") + "](" + u + ")"; }
    body.setRangeText(out, s, en, "end"); body.focus();
  });
})();
/* Stop browsers auto-filling the admin login into other forms (e.g. SMTP username/password) */
document.querySelectorAll("input[data-unlock]").forEach(function (i) {
  var un = function () { i.removeAttribute("readonly"); };
  i.addEventListener("focus", un); i.addEventListener("pointerdown", un);
});
