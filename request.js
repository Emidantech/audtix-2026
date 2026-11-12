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
      var k = el.getAttribute("data-show"), show = true, m;
      if ((m = k.match(/^(\w+)~(.+)$/))) show = checked(m[1]).indexOf(m[2]) > -1;
      else if ((m = k.match(/^(\w+)=(.+)$/))) show = val(m[1]) === m[2];
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

  var MONTHS = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
  function niceDate(d) { var p = d.split("-"); return p.length === 3 ? (+p[2]) + " " + MONTHS[+p[1] - 1] + " " + p[0] : d; }
  function taxPeriods() { var a = val("tax_period_from"), b = val("tax_period_to"); return a && b ? niceDate(a) + " to " + niceDate(b) : ""; }
  // "To" can never be before "From"
  form.elements.tax_period_from.addEventListener("change", function () { form.elements.tax_period_to.min = this.value || "2000-01-01"; });
  function uraDetails() {
    var out = [];
    $$("[data-ura]", form).forEach(function (p) {
      if (p.closest("[hidden]")) return;
      var parts = [];
      $$("input", p).forEach(function (i) { var v = i.value.trim(); if (!v) return; var l = $('label[for="' + i.id + '"]', p).textContent; parts.push(l + ": " + (i.type === "date" ? niceDate(v) : v)); });
      if (parts.length) out.push([p.getAttribute("data-ura"), parts.join("; ")]);
    });
    return out;
  }

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
    var tf = val("tax_period_from"), tt = val("tax_period_to");
    if (!tf || !tt) errs.tax_periods = "Pick both the start and end dates of the tax period.";
    else if (tt < tf) errs.tax_periods = "The end date must be after the start date.";
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
    ["applicant_type","display_name","contact_person","has_tin","tin","reg_number","email","phone","preferred_contact","location","other_service","timeline","year_end","turnover_band","employees_band","vat_registered","bookkeeping","ura_notice","ura_notice_details","notes","heard_from","business_sector","operating_status","stopped_on","years_operating","accounting_software","filing_status","outstanding_years","payroll_monthly","biggest_challenge"].forEach(function (k) {
      var el = $('[data-f="' + k + '"]', form), hidden = el && el.closest("[hidden]");
      if (!hidden || k === "tin") o[k] = val(k); // a TIN the client typed is never dropped
    });
    if (o.tin) o.tin = o.tin.replace(/\s+/g, "");
    o.tax_periods = taxPeriods(); o.tax_period_from = val("tax_period_from"); o.tax_period_to = val("tax_period_to");
    if (o.ura_communications) $$("[data-ura]", form).forEach(function (p) { if (p.closest("[hidden]")) return; $$("input", p).forEach(function (i) { if (i.value.trim()) o[i.name] = i.value.trim(); }); });
    return o;
  }
  function labelFor(name, v) {
    var e = form.elements[name];
    if (e && e.tagName === "SELECT") { var op = $$("option", e).filter(function (x) { return x.value === v; })[0]; return op ? op.textContent : v; }
    var r = $$('input[name="' + name + '"]', form).filter(function (x) { return x.value === v; })[0];
    return r ? r.parentNode.textContent.trim() : v;
  }
  function summary(o) {
    var rows = [["Applying as", labelFor("applicant_type", o.applicant_type)], ["Name", o.display_name], ["Contact person", o.contact_person], ["Has a TIN", labelFor("has_tin", o.has_tin)], ["TIN", o.tin || (o.has_tin === "yes" ? "Not provided" : "")], ["Registration number", o.reg_number], ["Email", o.email], ["Phone or WhatsApp", o.phone], ["Preferred contact", o.preferred_contact && labelFor("preferred_contact", o.preferred_contact)], ["Location", o.location], ["Services", o.services.map(function (v) { return labelFor("services", v); }).join(", ")], ["Other service", o.other_service], ["Tax periods", o.tax_periods], ["Timeline", o.timeline && labelFor("timeline", o.timeline)], ["Year end", o.year_end && labelFor("year_end", o.year_end)], ["Turnover", o.turnover_band && labelFor("turnover_band", o.turnover_band)], ["Employees", o.employees_band && labelFor("employees_band", o.employees_band)], ["VAT registered", o.vat_registered && labelFor("vat_registered", o.vat_registered)], ["Records", o.bookkeeping && labelFor("bookkeeping", o.bookkeeping)], ["URA notice", o.ura_notice && labelFor("ura_notice", o.ura_notice)], ["Notice details", o.ura_notice_details], ["Notes", o.notes], ["Heard from", o.heard_from && labelFor("heard_from", o.heard_from)], ["Biggest challenge", o.biggest_challenge && labelFor("biggest_challenge", o.biggest_challenge)], ["Business sector", o.business_sector && labelFor("business_sector", o.business_sector)], ["Time in operation", o.years_operating && labelFor("years_operating", o.years_operating)], ["Currently operating", o.operating_status && labelFor("operating_status", o.operating_status)], ["Stopped operating on", o.stopped_on], ["Accounting software", o.accounting_software], ["Records available", (o.records_available || []).map(function (v) { return labelFor("records_available", v); }).join(", ")], ["Filing returns regularly", o.filing_status && labelFor("filing_status", o.filing_status)], ["Years outstanding", o.outstanding_years && labelFor("outstanding_years", o.outstanding_years)], ["URA communication", (o.ura_communications || []).map(function (v) { return labelFor("ura_communications", v); }).join(", ")]].concat(uraDetails()).concat([["Payroll every month", o.payroll_monthly && labelFor("payroll_monthly", o.payroll_monthly)], ["Information confirmed true and complete", "Yes"]]);
    return rows.filter(function (r) { return r[1]; });
  }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }

  function msg(html) { $("#rq-error").innerHTML = html || ""; }
  form.addEventListener("input", function () { msg(""); });

  form.addEventListener("submit", function (ev) {
    ev.preventDefault();
    var errs = validate(), first = null;
    ["applicant_type","display_name","contact_person","has_tin","tin","email","phone","services","other_service","tax_periods","files","accuracy_confirmed","consent"].forEach(function (k) { setErr(k, errs[k]); if (errs[k] && !first) first = k; });
    msg("");
    if (first) { var f = $('[data-f="' + first + '"]', form); var i = f && $("input,select,textarea", f); if (i) i.focus(); msg("Please fix the highlighted fields."); return; }
    var o = collect();
    AudtixSubmit.send({
      form: form, category: "tax", data: o, rows: summary(o), files: allFiles(), button: $("#rq-send"), name: o.display_name,
      onFieldErrors: function (e) { Object.keys(e).forEach(function (k) { setErr(k, e[k]); }); },
      onMessage: msg,
      onDone: function () { form.reset(); $$("[data-list]", form).forEach(function (u) { u.innerHTML = ""; }); started = Date.now(); sync(); window.scrollTo({ top: 0, behavior: "smooth" }); }
    });
  });
})();
