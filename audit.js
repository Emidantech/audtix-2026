(function () {
  var form = document.getElementById("rq-form");
  if (!form || form.getAttribute("data-kind") !== "audit") return;
  var cfg = window.AUDTIX_CONFIG || {};
  var started = Date.now();
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var MAX_FILE = 5 * 1024 * 1024, MAX_TOTAL = 15 * 1024 * 1024, MAX_PER = 3;

  function wrap(n) { return $('[data-f="' + n + '"]', form); }
  function isHidden(el) { return !!(el && el.closest("[hidden]")); }
  function val(n) {
    var els = $$('[name="' + n + '"]', form); if (!els.length) return "";
    if (els[0].type === "radio") { var c = els.filter(function (x) { return x.checked; })[0]; return c ? c.value : ""; }
    return (els[0].value || "").trim();
  }
  function checked(n) { return $$('input[name="' + n + '"]:checked', form).map(function (x) { return x.value; }); }

  function sync() {
    $$("[data-if]", form).forEach(function (el) {
      var rule = el.getAttribute("data-if"), show = false, m;
      if ((m = rule.match(/^(\w+)=(.+)$/))) show = val(m[1]) === m[2];
      else if ((m = rule.match(/^(\w+)~(.+)$/))) show = checked(m[1]).indexOf(m[2]) > -1;
      if (!show && el.getAttribute("data-keep")) { var i = $("input", el); if (i && i.value.trim() !== "") show = true; } // a typed TIN stays visible
      el.hidden = !show;
    });
  }
  form.addEventListener("change", sync); form.addEventListener("input", sync); sync();
  // Clear a field's error as soon as the person edits it
  function clearErr(ev) { var w = ev.target.closest && ev.target.closest("[data-f]"); if (w) setErr(w.getAttribute("data-f"), ""); }
  form.addEventListener("input", function (ev) { clearErr(ev); var g = document.getElementById("rq-error"); if (g) g.textContent = ""; }); form.addEventListener("change", clearErr);

  function setErr(n, m) {
    var e = $('[data-e="' + n + '"]', form); if (e) e.textContent = m || "";
    var f = wrap(n); if (f) f.classList.toggle("bad", !!m);
  }
  function fmt(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + " MB" : Math.max(1, Math.round(n / 1024)) + " KB"; }
  function okType(f) { return /\.(pdf|jpe?g|png)$/i.test(f.name) && (!f.type || /^(application\/pdf|image\/jpeg|image\/png)$/.test(f.type)); }
  function fileInputs() { return $$("input[type=file]", form); }
  function allFiles() { var out = []; fileInputs().forEach(function (i) { Array.prototype.forEach.call(i.files || [], function (f) { out.push({ key: i.name, file: f }); }); }); return out; }
  function fileProblem() {
    var total = 0, per = {}, list = allFiles();
    for (var n = 0; n < list.length; n++) {
      var f = list[n].file; total += f.size; per[list[n].key] = (per[list[n].key] || 0) + 1;
      if (!okType(f)) return f.name + " must be a PDF, JPG or PNG.";
      if (f.size > MAX_FILE) return f.name + " is larger than 5 MB.";
      if (per[list[n].key] > MAX_PER) return "Upload at most " + MAX_PER + " files for each document type.";
    }
    if (total > MAX_TOTAL) return "Together the files must be under 15 MB. You have " + fmt(total) + ".";
    return "";
  }
  fileInputs().forEach(function (i) {
    i.addEventListener("change", function () {
      var ul = $('[data-list="' + i.name.slice(6) + '"]', form); ul.innerHTML = "";
      Array.prototype.forEach.call(i.files || [], function (f) { var li = document.createElement("li"); li.textContent = f.name + " (" + fmt(f.size) + ")"; ul.appendChild(li); });
      setErr("files", fileProblem());
    });
  });

  function validate() {
    var errs = {};
    $$("[data-f][data-req]", form).forEach(function (w) {
      var n = w.getAttribute("data-f");
      if (isHidden(w) || n.indexOf("file__") === 0) return;
      var ok;
      if (n === "accuracy_confirmed" || n === "consent") ok = form.elements[n].checked;
      else if (w.getAttribute("data-checks")) ok = checked(n).length > 0;
      else ok = val(n) !== "";
      if (!ok) errs[n] = n === "accuracy_confirmed" ? "Please confirm that the information is true and complete." : n === "consent" ? "Please confirm so we can contact you." : w.getAttribute("data-checks") ? "Choose at least one." : "This is required.";
    });
    if (!errs.email && val("email") && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(val("email"))) errs.email = "Enter a valid email address.";
    var ph = val("phone"), d = ph.replace(/\D/g, "");
    if (!errs.phone && ph && (!/^[+\d\s().-]+$/.test(ph) || d.length < 7 || d.length > 15)) errs.phone = "Enter a valid phone number.";
    var tin = val("tin").replace(/\s+/g, "");
    if (tin && !/^[0-9]{10}$/.test(tin)) errs.tin = "A TIN has 10 digits.";
    var fp = fileProblem(); if (fp) errs.files = fp;
    return errs;
  }

  function collect() {
    var o = { page: "request-audit.html", elapsed_ms: Date.now() - started, website: (form.elements.website.value || "").trim(),
      consent: !!form.elements.consent.checked, accuracy_confirmed: !!form.elements.accuracy_confirmed.checked };
    $$("[data-f]", form).forEach(function (w) {
      var n = w.getAttribute("data-f");
      if (n.indexOf("file__") === 0 || n === "consent" || n === "accuracy_confirmed") return;
      if (isHidden(w) && n !== "tin") return;
      o[n] = w.getAttribute("data-checks") ? checked(n) : val(n); // a TIN the client typed is never dropped
    });
    if (o.tin) o.tin = o.tin.replace(/\s+/g, "");
    return o;
  }

  function sectionOf(w) {
    var d = w.closest("details.qsec"); if (d) { var l = $(".ql", d), t = $(".qt", d); return "Questionnaire " + (l ? l.textContent.trim() + ": " : "") + (t ? t.textContent.trim() : ""); }
    var st = w.closest(".rq-step"), h = st && $("h2,h3,legend", st); return h ? h.textContent.replace(/\s+/g, " ").trim() : "";
  }
  function summary() {
    var rows = [];
    $$("[data-f][data-label]", form).forEach(function (w) {
      var n = w.getAttribute("data-f"), label = w.getAttribute("data-label"), v = "";
      if (isHidden(w) && n !== "tin") return;
      if (w.getAttribute("data-checks")) v = $$("input:checked", w).map(function (x) { return x.parentNode.textContent.trim(); }).join(", ");
      else if ($("select", w)) { var s = $("select", w); v = s.value ? s.options[s.selectedIndex].textContent : ""; }
      else if ($("input[type=radio]", w)) { var r = $("input:checked", w); v = r ? r.parentNode.textContent.trim() : ""; }
      else { var i = $("input,textarea", w); v = i ? i.value.trim() : ""; }
      if (n === "tin" && !v && val("has_tin") === "yes") v = "Not provided";
      if (v) rows.push([label, v, sectionOf(w)]);
    });
    return rows;
  }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }

  function msg(html) { $("#rq-error").innerHTML = html || ""; }

  form.addEventListener("submit", function (ev) {
    ev.preventDefault();
    var errs = validate(), first = null;
    $$("[data-e]", form).forEach(function (e) { var k = e.getAttribute("data-e"); setErr(k, errs[k]); if (errs[k] && !first) first = k; });
    msg("");
    if (first) { var f = wrap(first) || $('[data-e="files"]', form); var i = f && $("input,select,textarea", f); if (i) i.focus(); else if (f && f.scrollIntoView) f.scrollIntoView({ block: "center" }); msg("Please fix the highlighted fields."); return; }
    var o = collect();
    AudtixSubmit.send({
      form: form, category: "audit", data: o, rows: summary(), files: allFiles(), button: $("#rq-send"), name: o.org_name,
      onFieldErrors: function (e) { Object.keys(e).forEach(function (k) { setErr(k, e[k]); }); },
      onMessage: msg,
      onDone: function () { form.reset(); $$("[data-list]", form).forEach(function (u) { u.innerHTML = ""; }); started = Date.now(); sync(); window.scrollTo({ top: 0, behavior: "smooth" }); }
    });
  });
})();
