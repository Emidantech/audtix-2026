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
      if (v) rows.push(label + ": " + v);
    });
    return "New audit service request\n\n" + rows.join("\n");
  }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }

  var done = document.getElementById("rq-done");
  function show(html) { form.hidden = true; done.innerHTML = html; done.hidden = false; done.focus(); window.scrollTo({ top: done.getBoundingClientRect().top + window.scrollY - 100, behavior: "smooth" }); }
  function success(ref, n) {
    show('<h2>Thank you, your request is in</h2><p>Reference</p><div class="ref">' + esc(ref) + '</div><p>We received your form' + (n ? ' and ' + n + ' document' + (n === 1 ? '' : 's') : '') + '. Our audit team will contact you using the method you chose. A confirmation email is on its way to you. Quote the reference if you write to us.</p><div class="row"><a class="btn" href="index.html">Back to home</a><a class="btn ghost" href="tools.html">Try the free tools</a></div>');
  }
  function fallback(o, why) {
    var text = summary(), to = cfg.notifyTo || "info@audtix.com", cc = cfg.notifyCc || "audtixsystem@gmail.com";
    var mail = "mailto:" + to + "?cc=" + encodeURIComponent(cc) + "&subject=" + encodeURIComponent("Audit service request: " + o.org_name) + "&body=" + encodeURIComponent(text);
    var wa = "https://wa.me/" + (cfg.whatsapp || "256701287388") + "?text=" + encodeURIComponent(text);
    show('<h2>One last step to send your request</h2><p>' + esc(why) + ' Your details are ready below. Send them using one of these buttons.</p>' + (allFiles().length ? '<p><b>Your documents are not attached here.</b> Please email them to ' + esc(to) + ' and quote your organisation name.</p>' : '') + '<div class="row"><a class="btn" href="' + mail + '">Send by email</a><a class="btn ghost" href="' + wa + '" target="_blank" rel="noopener noreferrer">Send on WhatsApp</a><button class="btn ghost" type="button" id="rq-copy">Copy details</button></div><pre id="rq-pre">' + esc(text) + '</pre>');
    var b = document.getElementById("rq-copy");
    b.addEventListener("click", function () {
      function pick() { var r = document.createRange(); r.selectNodeContents(document.getElementById("rq-pre")); var s = getSelection(); s.removeAllRanges(); s.addRange(r); b.textContent = "Selected, press copy"; }
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(function () { b.textContent = "Copied"; }, pick); else pick();
    });
  }

  form.addEventListener("submit", function (ev) {
    ev.preventDefault();
    var errs = validate(), first = null;
    $$("[data-e]", form).forEach(function (e) { var k = e.getAttribute("data-e"); setErr(k, errs[k]); if (errs[k] && !first) first = k; });
    $("#rq-error").textContent = "";
    if (first) { var f = wrap(first) || $('[data-e="files"]', form); var i = f && $("input,select,textarea", f); if (i) i.focus(); else if (f && f.scrollIntoView) f.scrollIntoView({ block: "center" }); $("#rq-error").textContent = "Please fix the highlighted fields."; return; }
    var o = collect();
    if (o.website) return success("AUDIT-0000-00000", 0);
    if (!cfg.auditRequestEndpoint) return fallback(o, "Online delivery is not switched on for this page yet.");
    var btn = $("#rq-send"); btn.disabled = true; btn.textContent = "Sending...";
    var fd = new FormData(); fd.append("data", JSON.stringify(o));
    var fl = allFiles(), nfiles = fl.length;
    fl.forEach(function (x) { fd.append(x.key, x.file, x.file.name); });
    var ctl = typeof AbortController !== "undefined" ? new AbortController() : null, to = setTimeout(function () { ctl && ctl.abort(); }, nfiles ? 120000 : 30000);
    fetch(cfg.auditRequestEndpoint, { method: "POST", body: fd, signal: ctl ? ctl.signal : undefined })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { s: r.status, j: j }; }); })
      .then(function (x) {
        clearTimeout(to);
        if (x.s === 200 && x.j.ok) return success(x.j.reference, nfiles);
        btn.disabled = false; btn.textContent = "Submit request";
        if (x.s === 422 && x.j.errors) { Object.keys(x.j.errors).forEach(function (k) { setErr(k, x.j.errors[k]); }); $("#rq-error").textContent = "Please fix the highlighted fields."; return; }
        if (x.s === 413) { setErr("files", "The files are too large. Remove some and try again."); $("#rq-error").textContent = "Please fix the highlighted fields."; return; }
        if (x.s === 429) { $("#rq-error").textContent = "Too many requests from this network. Please try again later or WhatsApp us."; return; }
        fallback(o, "We could not reach our server.");
      })
      .catch(function () { clearTimeout(to); btn.disabled = false; btn.textContent = "Submit request"; fallback(o, "We could not reach our server."); });
  });
})();
