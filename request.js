(function () {
  var form = document.getElementById("rq-form");
  if (!form) return;
  var cfg = window.AUDTIX_CONFIG || {};
  var started = Date.now();
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  function val(n) { var e = form.elements[n]; if (!e) return ""; if (e.length && e[0] && e[0].type === "radio") { var c = $$('input[name="' + n + '"]:checked', form)[0]; return c ? c.value : ""; } return (e.value || "").trim(); }
  function checked(n) { return $$('input[name="' + n + '"]:checked', form).map(function (x) { return x.value; }); }

  var MAX_FILE = 5 * 1024 * 1024, MAX_TOTAL = 15 * 1024 * 1024, MAX_PER = 3;
  function sync() {
    var t = val("applicant_type"), svc = checked("services");
    $$('[data-show]', form).forEach(function (el) {
      var k = el.getAttribute("data-show"), show = true;
      if (k === "notindividual") show = t !== "" && t !== "individual";
      if (k === "tin") show = val("has_tin") === "yes" || val("tin") !== "";
      if (k === "other") show = svc.indexOf("other") > -1;
      if (k === "notice") show = val("ura_notice") === "yes";
      if (k === "software") show = val("bookkeeping") === "software";
      if (k === "closed") show = val("operating_status") === "no" || val("operating_status") === "temporarily_closed";
      if (k === "outstanding") show = ["no", "some_missing", "never"].indexOf(val("filing_status")) > -1;
      if (k === "payroll") show = val("employees_band") !== "" && val("employees_band") !== "none";
      el.hidden = !show;
    });
  }
  form.addEventListener("change", function (ev) {
    var t = ev.target;
    if (t && t.name === "records_available" && t.checked) {
      $$('input[name="records_available"]', form).forEach(function (x) { if ((t.value === "none") ? x !== t : x.value === "none") x.checked = false; });
    }
  });
  form.addEventListener("change", sync); form.addEventListener("input", sync); sync();

  function setErr(n, m) {
    var e = $('[data-e="' + n + '"]', form); if (e) e.textContent = m || "";
    var f = $('[data-f="' + n + '"]', form); if (f) f.classList.toggle("bad", !!m);
  }
  function validate() {
    var errs = {}, t = val("applicant_type");
    if (!t) errs.applicant_type = "Please choose who is applying.";
    if (val("display_name").length < 2) errs.display_name = "Name is required.";
    if (t && t !== "individual" && val("contact_person").length < 2) errs.contact_person = "Contact person is required.";
    if (!val("has_tin")) errs.has_tin = "Please choose an answer.";
    var tin = val("tin").replace(/\s+/g, "");
    if (val("has_tin") === "yes" && tin && !/^[0-9]{10}$/.test(tin)) errs.tin = "A TIN has 10 digits.";
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(val("email"))) errs.email = "Enter a valid email address.";
    var ph = val("phone"), d = ph.replace(/\D/g, "");
    if (!ph) errs.phone = "Phone or WhatsApp number is required.";
    else if (!/^[+\d\s().-]+$/.test(ph) || d.length < 7 || d.length > 15) errs.phone = "Enter a valid phone number.";
    var s = checked("services");
    if (!s.length) errs.services = "Choose at least one service.";
    if (s.indexOf("other") > -1 && val("other_service").length < 2) errs.other_service = "Tell us which other service.";
    if (!val("tax_periods")) errs.tax_periods = "Tax period is required.";
    var fp = fileProblem(); if (fp) errs.files = fp;
    if (!form.elements.accuracy_confirmed.checked) errs.accuracy_confirmed = "Please confirm that the information is true and complete.";
    if (!form.elements.consent.checked) errs.consent = "Please confirm so we can contact you.";
    return errs;
  }
  function fmt(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + " MB" : Math.max(1, Math.round(n / 1024)) + " KB"; }
  function okType(f) { return /\.(pdf|jpe?g|png)$/i.test(f.name) && (!f.type || /^(application\/pdf|image\/jpeg|image\/png)$/.test(f.type)); }
  function fileInputs() { return $$('input[type=file]', form); }
  function allFiles() { var out = []; fileInputs().forEach(function (i) { Array.prototype.forEach.call(i.files || [], function (f) { out.push({ key: i.name, file: f }); }); }); return out; }
  function fileProblem() {
    var total = 0, per = {};
    var list = allFiles();
    for (var n = 0; n < list.length; n++) {
      var f = list[n].file; total += f.size; per[list[n].key] = (per[list[n].key] || 0) + 1;
      if (!okType(f)) return f.name + " must be a PDF, JPG or PNG.";
      if (f.size > MAX_FILE) return f.name + " is larger than 5 MB.";
      if (per[list[n].key] > MAX_PER) return "Upload at most " + MAX_PER + " files for each document type.";
    }
    if (total > MAX_TOTAL) return "Together the files must be under 15 MB. You have " + fmt(total) + ".";
    return "";
  }
  function showFiles(input) {
    var ul = $('[data-list="' + input.name.slice(6) + '"]', form); ul.innerHTML = "";
    Array.prototype.forEach.call(input.files || [], function (f) { var li = document.createElement("li"); li.textContent = f.name + " (" + fmt(f.size) + ")"; ul.appendChild(li); });
  }
  fileInputs().forEach(function (i) { i.addEventListener("change", function () { showFiles(i); setErr("files", fileProblem()); }); });
  function collect() {
    var o = { page: "request-tax.html", elapsed_ms: Date.now() - started, website: val("website"), consent: !!form.elements.consent.checked, accuracy_confirmed: !!form.elements.accuracy_confirmed.checked, services: checked("services") };
    ["records_available", "ura_communications"].forEach(function (k) { var el = $('[data-f="' + k + '"]', form); if (el && !el.closest("[hidden]")) o[k] = checked(k); });
    ["applicant_type","display_name","contact_person","has_tin","tin","reg_number","email","phone","preferred_contact","location","other_service","tax_periods","timeline","year_end","turnover_band","employees_band","vat_registered","bookkeeping","ura_notice","ura_notice_details","notes","heard_from","business_sector","operating_status","stopped_on","years_operating","accounting_software","filing_status","outstanding_years","payroll_monthly","biggest_challenge"].forEach(function (k) {
      var el = $('[data-f="' + k + '"]', form), hidden = el && el.closest("[hidden]");
      if (!hidden || k === "tin") o[k] = val(k); // a TIN the client typed is never dropped
    });
    if (o.tin) o.tin = o.tin.replace(/\s+/g, "");
    return o;
  }
  function labelFor(name, v) {
    var e = form.elements[name];
    if (e && e.tagName === "SELECT") { var op = $$("option", e).filter(function (x) { return x.value === v; })[0]; return op ? op.textContent : v; }
    var r = $$('input[name="' + name + '"]', form).filter(function (x) { return x.value === v; })[0];
    return r ? r.parentNode.textContent.trim() : v;
  }
  function summary(o) {
    var rows = [["Applying as", labelFor("applicant_type", o.applicant_type)], ["Name", o.display_name], ["Contact person", o.contact_person], ["Has a TIN", labelFor("has_tin", o.has_tin)], ["TIN", o.tin || (o.has_tin === "yes" ? "Not provided" : "")], ["Registration number", o.reg_number], ["Email", o.email], ["Phone or WhatsApp", o.phone], ["Preferred contact", o.preferred_contact && labelFor("preferred_contact", o.preferred_contact)], ["Location", o.location], ["Services", o.services.map(function (v) { return labelFor("services", v); }).join(", ")], ["Other service", o.other_service], ["Tax periods", o.tax_periods], ["Timeline", o.timeline && labelFor("timeline", o.timeline)], ["Year end", o.year_end && labelFor("year_end", o.year_end)], ["Turnover", o.turnover_band && labelFor("turnover_band", o.turnover_band)], ["Employees", o.employees_band && labelFor("employees_band", o.employees_band)], ["VAT registered", o.vat_registered && labelFor("vat_registered", o.vat_registered)], ["Records", o.bookkeeping && labelFor("bookkeeping", o.bookkeeping)], ["URA notice", o.ura_notice && labelFor("ura_notice", o.ura_notice)], ["Notice details", o.ura_notice_details], ["Notes", o.notes], ["Heard from", o.heard_from && labelFor("heard_from", o.heard_from)], ["Biggest challenge", o.biggest_challenge && labelFor("biggest_challenge", o.biggest_challenge)], ["Business sector", o.business_sector && labelFor("business_sector", o.business_sector)], ["Time in operation", o.years_operating && labelFor("years_operating", o.years_operating)], ["Currently operating", o.operating_status && labelFor("operating_status", o.operating_status)], ["Stopped operating on", o.stopped_on], ["Accounting software", o.accounting_software], ["Records available", (o.records_available || []).map(function (v) { return labelFor("records_available", v); }).join(", ")], ["Filing returns regularly", o.filing_status && labelFor("filing_status", o.filing_status)], ["Years outstanding", o.outstanding_years && labelFor("outstanding_years", o.outstanding_years)], ["URA communication", (o.ura_communications || []).map(function (v) { return labelFor("ura_communications", v); }).join(", ")], ["Payroll every month", o.payroll_monthly && labelFor("payroll_monthly", o.payroll_monthly)], ["Information confirmed true and complete", "Yes"]];
    return "New tax service request\n\n" + rows.filter(function (r) { return r[1]; }).map(function (r) { return r[0] + ": " + r[1]; }).join("\n");
  }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }

  var done = document.getElementById("rq-done");
  function show(html) { form.hidden = true; $(".rq-side").hidden = false; done.innerHTML = html; done.hidden = false; done.focus(); window.scrollTo({ top: done.getBoundingClientRect().top + window.scrollY - 100, behavior: "smooth" }); }
  function success(ref, n) {
    show('<h2>Thank you, your request is in</h2><p>Reference</p><div class="ref">' + esc(ref) + '</div><p>We received your form' + (n ? ' and ' + n + ' document' + (n === 1 ? '' : 's') : '') + '. Our tax team will contact you using the method you chose. A confirmation email is on its way to you. Quote the reference if you write to us.</p><div class="row"><a class="btn" href="index.html">Back to home</a><a class="btn ghost" href="tools.html">Try the free tools</a></div>');
  }
  function fallback(o, why) {
    var text = summary(o), to = cfg.notifyTo || "info@audtix.com", cc = cfg.notifyCc || "audtixsystem@gmail.com";
    var mail = "mailto:" + to + "?cc=" + encodeURIComponent(cc) + "&subject=" + encodeURIComponent("Tax service request: " + o.display_name) + "&body=" + encodeURIComponent(text);
    var wa = "https://wa.me/" + (cfg.whatsapp || "256701287388") + "?text=" + encodeURIComponent(text);
    show('<h2>One last step to send your request</h2><p>' + esc(why) + ' Your details are ready below. Send them using one of these buttons.</p>' + (allFiles().length ? '<p><b>Your documents are not attached here.</b> Please email them to ' + esc(to) + ' and quote your company name.</p>' : '') + '<p style="display:none"></p><div class="row"><a class="btn" href="' + mail + '">Send by email</a><a class="btn ghost" href="' + wa + '" target="_blank" rel="noopener noreferrer">Send on WhatsApp</a><button class="btn ghost" type="button" id="rq-copy">Copy details</button></div><pre id="rq-pre">' + esc(text) + '</pre>');
    var b = document.getElementById("rq-copy");
    b.addEventListener("click", function () {
      var pre = document.getElementById("rq-pre");
      function ok() { b.textContent = "Copied"; }
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(ok, function () { sel(); });
      else sel();
      function sel() { var r = document.createRange(); r.selectNodeContents(pre); var s = getSelection(); s.removeAllRanges(); s.addRange(r); b.textContent = "Selected, press copy"; }
    });
  }

  form.addEventListener("submit", function (ev) {
    ev.preventDefault();
    var errs = validate(), first = null;
    ["applicant_type","display_name","contact_person","has_tin","tin","email","phone","services","other_service","tax_periods","files","accuracy_confirmed","consent"].forEach(function (k) { setErr(k, errs[k]); if (errs[k] && !first) first = k; });
    $("#rq-error").textContent = "";
    if (first) { var f = $('[data-f="' + first + '"]', form); var i = f && $("input,select,textarea", f); if (i) i.focus(); $("#rq-error").textContent = "Please fix the highlighted fields."; return; }
    var o = collect();
    if (o.website) return success("AUD-0000-00000", 0);
    if (!cfg.taxRequestEndpoint) return fallback(o, "Online delivery is not switched on for this page yet.");
    var btn = $("#rq-send"); btn.disabled = true; btn.textContent = "Sending...";
    var fd = new FormData(); fd.append("data", JSON.stringify(o));
    var fl = allFiles(), nfiles = fl.length;
    fl.forEach(function (x) { fd.append(x.key, x.file, x.file.name); });
    var ctl = typeof AbortController !== "undefined" ? new AbortController() : null, to = setTimeout(function () { ctl && ctl.abort(); }, nfiles ? 120000 : 30000);
    fetch(cfg.taxRequestEndpoint, { method: "POST", body: fd, signal: ctl ? ctl.signal : undefined })
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
