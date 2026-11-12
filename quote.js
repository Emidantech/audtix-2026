/* Reusable quotation form: quote.html?service=erp|custom|ura|cyber|dev|net|adv|ict
   The fields come from quote-forms.js (the same file the server checks against), so one page serves every service. */
(function () {
  var form = document.getElementById("q-form");
  if (!form) return;
  var cfg = window.AUDTIX_CONFIG || {};
  var started = Date.now();
  var SPEC = null, SERVICE = "", FIELDS = [];
  var MAX_FILE = 5 * 1024 * 1024, MAX_TOTAL = 15 * 1024 * 1024, MAX_FILES = 5;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function waLink(t) { return "https://wa.me/" + (cfg.whatsapp || "256701287388") + "?text=" + encodeURIComponent(t); }
  function today() { var d = new Date(); return d.getFullYear() + "-" + ("0" + (d.getMonth() + 1)).slice(-2) + "-" + ("0" + d.getDate()).slice(-2); }

  /* ---------- rendering ---------- */
  function req(f) { return f.required ? ' <span class="req">*</span>' : ""; }
  function attrs(f) {
    var a = ' name="' + esc(f.name) + '"';
    if (f.max && f.type !== "number") a += ' maxlength="' + f.max + '"';
    if (f.type === "number") { a += ' inputmode="numeric"'; if (f.min != null) a += ' min="' + f.min + '"'; if (f.max != null) a += ' max="' + f.max + '"'; }
    if (f.placeholder) a += ' placeholder="' + esc(f.placeholder) + '"';
    if (f.autocomplete) a += ' autocomplete="' + esc(f.autocomplete) + '"';
    if (f.type === "date" && f.future) a += ' min="' + today() + '"';
    return a;
  }
  function fieldHtml(f) {
    var id = "q-" + f.name, show = f.show ? ' data-show="' + esc(f.show) + '"' : "", hint = f.hint ? '<p class="hint">' + esc(f.hint) + "</p>" : "", err = '<p class="err" data-e="' + esc(f.name) + '"></p>';
    if (f.type === "radio" || f.type === "checkboxes") {
      var t = f.type === "radio" ? "radio" : "checkbox";
      return '<fieldset class="field rg" data-f="' + esc(f.name) + '"' + show + "><legend>" + esc(f.label) + req(f) + '</legend><div class="opts">' +
        f.options.map(function (o) { return '<label class="opt"><input type="' + t + '" name="' + esc(f.name) + '" value="' + esc(o[0]) + '"><span>' + esc(o[1]) + "</span></label>"; }).join("") + "</div>" + hint + err + "</fieldset>";
    }
    if (f.type === "items") {
      return '<fieldset class="field" data-f="' + esc(f.name) + '"' + show + ' data-items="' + esc(f.name) + '"><legend>' + esc(f.label) + req(f) + "</legend>" + hint +
        '<div class="q-items" role="table"><div class="q-irow q-ihead" role="row">' + f.columns.map(function (c) { return '<span role="columnheader" class="q-c-' + esc(c.name) + '">' + esc(c.label) + "</span>"; }).join("") + '<span aria-hidden="true"></span></div><div class="q-ibody"></div></div>' +
        '<button type="button" class="q-add">+ Add another item</button>' + err + "</fieldset>";
    }
    var input;
    if (f.type === "select") input = '<select id="' + id + '"' + attrs(f) + '><option value="">Select</option>' + f.options.map(function (o) { return '<option value="' + esc(o[0]) + '">' + esc(o[1]) + "</option>"; }).join("") + "</select>";
    else if (f.type === "textarea") input = '<textarea id="' + id + '"' + attrs(f) + ' rows="' + (f.rows || 4) + '"></textarea>';
    else input = '<input id="' + id + '" type="' + ({ number: "text", email: "email", tel: "tel", date: "date" }[f.type] || "text") + '"' + attrs(f) + ">";
    return '<div class="field" data-f="' + esc(f.name) + '"' + show + '><label for="' + id + '">' + esc(f.label) + req(f) + "</label>" + input + hint + err + "</div>";
  }
  function itemRow(f) {
    var row = document.createElement("div"); row.className = "q-irow"; row.setAttribute("role", "row");
    row.innerHTML = f.columns.map(function (c) {
      return '<input class="q-c-' + esc(c.name) + '" data-col="' + esc(c.name) + '" aria-label="' + esc(c.label) + '"' + (c.max && c.type !== "number" ? ' maxlength="' + c.max + '"' : "") + (c.type === "number" ? ' inputmode="numeric"' : "") + (c.placeholder ? ' placeholder="' + esc(c.placeholder) + '"' : "") + ">";
    }).join("") + '<button type="button" class="q-del" aria-label="Remove this item">&times;</button>';
    return row;
  }
  function sectionHtml(sec, n) {
    var out = '<div class="rq-step"><h2><span>' + n + "</span>" + esc(sec.title) + "</h2>", i = 0, fs = sec.fields;
    while (i < fs.length) {
      if (fs[i].half && fs[i + 1] && fs[i + 1].half) { out += '<div class="two-f">' + fieldHtml(fs[i]) + fieldHtml(fs[i + 1]) + "</div>"; i += 2; }
      else { out += fieldHtml(fs[i]); i += 1; }
    }
    return out + "</div>";
  }
  function render() {
    var svc = SPEC.services[SERVICE];
    var secs = [SPEC.contact].concat(svc.sections, [SPEC.closing]);
    FIELDS = []; secs.forEach(function (s) { FIELDS = FIELDS.concat(s.fields); });
    var html = secs.map(function (s, i) { return sectionHtml(s, i + 1); }).join("");
    html += '<div class="rq-step"><h2><span>' + (secs.length + 1) + "</span>Documents <em>(optional)</em></h2>" +
      '<div class="upl" data-f="attachments"><label class="upl-l" for="q-attachments">Specifications, BOQ, RFQ or photos</label><input id="q-attachments" class="upl-i" type="file" name="attachments" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" multiple><ul class="upl-list" data-list="attachments"></ul>' +
      '<p class="hint">PDF, JPG or PNG. Up to ' + MAX_FILES + ' files, 5 MB each, 15 MB in total.</p><p class="err" data-e="attachments"></p></div></div>' +
      '<div class="hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>' +
      '<div class="rq-submit"><label class="opt consent" data-f="consent"><input type="checkbox" name="consent"><span>I agree that Audtix Systems may contact me about this quotation and keep these details to handle it. We do not sell your information. <span class="req">*</span></span></label>' +
      '<p class="err" data-e="consent"></p><p class="err form-err" id="q-error" role="alert"></p><button class="btn" type="submit" id="q-send">Request quotation</button><p class="hint">Please do not include passwords or bank PINs.</p></div>';
    form.innerHTML = html;
    $$("[data-items]", form).forEach(function (box) {
      var f = FIELDS.filter(function (x) { return x.name === box.getAttribute("data-items"); })[0], body = $(".q-ibody", box);
      for (var k = 0; k < Math.max(1, f.min_rows || 1); k++) body.appendChild(itemRow(f));
      $(".q-add", box).addEventListener("click", function () {
        if ($$(".q-irow", body).length >= (f.max_rows || 40)) return;
        var r = itemRow(f); body.appendChild(r); $("input", r).focus();
      });
      body.addEventListener("click", function (e) {
        var b = e.target.closest(".q-del"); if (!b) return;
        var rows = $$(".q-irow", body);
        if (rows.length > 1) b.closest(".q-irow").remove(); else $$("input", rows[0]).forEach(function (i) { i.value = ""; });
      });
    });
    var up = $("#q-attachments", form);
    up.addEventListener("change", function () {
      var ul = $('[data-list="attachments"]', form); ul.innerHTML = "";
      Array.prototype.forEach.call(up.files || [], function (f) { var li = document.createElement("li"); li.textContent = f.name + " (" + fmt(f.size) + ")"; ul.appendChild(li); });
      setErr("attachments", fileProblem());
    });
    form.hidden = false; $("#q-loading").hidden = true;
    sync();
  }
  function renderHeader() {
    var svc = SPEC.services[SERVICE];
    if (!svc) {
      // No (or unknown) service in the link: let the visitor pick one.
      $("#q-loading").innerHTML = "<p>Choose the service you need a quotation for:</p><div class=\"q-pick\">" + Object.keys(SPEC.services).map(function (k) {
        var s = SPEC.services[k];
        return '<a href="quote.html?service=' + k + '"><b>' + esc(s.title) + "</b><span>" + esc(s.short || "") + "</span></a>";
      }).join("") + "</div>";
      return;
    }
    document.title = "Request a Quotation: " + svc.title + " | Audtix Systems";
    $("#q-crumb").textContent = "Quotation: " + svc.title;
    $("#q-eyebrow").textContent = svc.eyebrow || svc.title;
    $("#q-title").textContent = "Request a quotation: " + svc.title;
    $("#q-intro").textContent = svc.intro;
    var ill = { erp: "erp", custom: "erp", dev: "erp", ura: "tax", cyber: "security", net: "security", adv: "tools", ict: "hardware" }[SERVICE];
    if (ill && $("#q-illus")) { $("#q-illus").src = "img/" + ill + ".svg"; $("#q-illus").alt = svc.title; }
  }

  /* ---------- reading values and show rules ---------- */
  function val(n) {
    var els = $$('[name="' + n + '"]', form); if (!els.length) return "";
    if (els[0].type === "radio") { var c = els.filter(function (x) { return x.checked; })[0]; return c ? c.value : ""; }
    if (els[0].type === "checkbox") return els.filter(function (x) { return x.checked; }).map(function (x) { return x.value; });
    return (els[0].value || "").trim();
  }
  function ruleOk(rule) {
    var m;
    if (!rule) return true;
    if ((m = rule.match(/^(\w+)~(.+)$/))) { var v = val(m[1]); return Array.isArray(v) && v.indexOf(m[2]) > -1; }
    if ((m = rule.match(/^(\w+)!=(.*)$/))) { var w = val(m[1]); return w !== "" && w !== m[2]; }
    if ((m = rule.match(/^(\w+)=(.+)$/))) return val(m[1]) === m[2];
    return true;
  }
  function sync() { $$("[data-show]", form).forEach(function (el) { el.hidden = !ruleOk(el.getAttribute("data-show")); }); }
  function items(f) {
    return $$('[data-items="' + f.name + '"] .q-ibody .q-irow', form).map(function (r) {
      var o = {}; $$("input", r).forEach(function (i) { o[i.getAttribute("data-col")] = i.value.trim(); }); return o;
    }).filter(function (o) { return Object.keys(o).some(function (k) { return o[k] !== ""; }); });
  }
  function isShown(f) { var w = $('[data-f="' + f.name + '"]', form); return w && !w.closest("[hidden]"); }

  /* ---------- validation (the server checks again) ---------- */
  function fmt(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + " MB" : Math.max(1, Math.round(n / 1024)) + " KB"; }
  function fileProblem() {
    var fs = Array.prototype.slice.call(($("#q-attachments", form) || {}).files || []), total = 0;
    if (fs.length > MAX_FILES) return "Upload at most " + MAX_FILES + " files.";
    for (var i = 0; i < fs.length; i++) {
      total += fs[i].size;
      if (!/\.(pdf|jpe?g|png)$/i.test(fs[i].name)) return fs[i].name + " must be a PDF, JPG or PNG.";
      if (fs[i].size > MAX_FILE) return fs[i].name + " is larger than 5 MB.";
    }
    return total > MAX_TOTAL ? "Together the files must be under 15 MB. You have " + fmt(total) + "." : "";
  }
  function setErr(n, m) {
    var e = $('[data-e="' + n + '"]', form); if (e) e.textContent = m || "";
    var w = $('[data-f="' + n + '"]', form); if (w) w.classList.toggle("bad", !!m);
  }
  function validate() {
    var errs = {};
    FIELDS.forEach(function (f) {
      if (!isShown(f)) return;
      var v = f.type === "items" ? items(f) : val(f.name), n = f.name;
      if (f.type === "checkboxes") { if (f.required && !v.length) errs[n] = "Choose at least one."; return; }
      if (f.type === "items") {
        if (f.required && !v.length) { errs[n] = "Add at least one item."; return; }
        v.some(function (row, i) {
          return f.columns.some(function (c) {
            var cv = row[c.name] || "";
            if (c.required && !cv) { errs[n] = "Line " + (i + 1) + ": fill in " + c.label.toLowerCase() + "."; return true; }
            if (c.type === "number" && cv && (!/^\d+$/.test(cv) || (c.min != null && +cv < c.min))) { errs[n] = "Line " + (i + 1) + ": " + c.label.toLowerCase() + " must be a whole number from " + (c.min || 0) + "."; return true; }
          });
        });
        return;
      }
      if (v === "") { if (f.required) errs[n] = (f.type === "select" || f.type === "radio") ? "Please choose an answer." : f.type === "date" ? "Pick a date." : "This is required."; return; }
      if (f.type === "email" && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)) errs[n] = "Enter a valid email address.";
      if (f.type === "tel") { var d = v.replace(/\D/g, ""); if (!/^[+\d\s().-]+$/.test(v) || d.length < 7 || d.length > 15) errs[n] = "Enter a valid phone number."; }
      if (f.type === "number") { var x = v.replace(/[,\s]/g, ""); if (!/^\d+$/.test(x)) errs[n] = "Enter a whole number."; else if (f.min != null && +x < f.min) errs[n] = "The smallest allowed is " + f.min + "."; else if (f.max != null && +x > f.max) errs[n] = "That number is too large."; }
      if (f.type === "date" && f.future && v < today()) errs[n] = "Pick today or a later date.";
      if ((f.type === "text" || f.type === "textarea") && f.required && v.length < 2) errs[n] = "This is required.";
    });
    var fp = fileProblem(); if (fp) errs.attachments = fp;
    if (!form.elements.consent.checked) errs.consent = "Please confirm so we can contact you.";
    return errs;
  }
  function collect() {
    var o = { elapsed_ms: Date.now() - started, website: val("website"), consent: !!form.elements.consent.checked };
    FIELDS.forEach(function (f) { if (isShown(f)) o[f.name] = f.type === "items" ? items(f) : val(f.name); });
    return o;
  }
  function showErrors(errs) {
    var first = null;
    $$("[data-e]", form).forEach(function (e) { var n = e.getAttribute("data-e"); if (n !== "form") setErr(n, errs[n]); });
    Object.keys(errs).forEach(function (k) { if (!first) first = $('[data-f="' + k + '"]', form); });
    if (first) { var i = $("input,select,textarea", first); first.scrollIntoView({ block: "center", behavior: "smooth" }); if (i) i.focus({ preventScroll: true }); }
  }
  function msg(h) { $("#q-error").innerHTML = h || ""; }

  /* ---------- success card (same look as the request forms) ---------- */
  function popup(res, name) {
    var svc = SPEC.services[SERVICE], prev = document.activeElement;
    var ov = document.createElement("div"); ov.className = "sx-ov";
    ov.innerHTML = '<div class="sx-card" role="dialog" aria-modal="true" aria-labelledby="sx-t" tabindex="-1">' +
      '<div class="sx-ic" aria-hidden="true"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>' +
      '<h2 id="sx-t">Quotation request sent</h2><p class="sx-ref"><span class="sx-id">' + esc(res.quote_no) + '</span><span class="sx-tag">' + esc(svc.tag) + "</span></p>" +
      "<p>Thank you" + (name ? ", " + esc(name) : "") + ". Our team will prepare your " + esc(svc.title.toLowerCase()) + " quotation and reply soon. Please keep your quote number for reference.</p>" +
      '<div class="sx-row"><a class="sx-wa" target="_blank" rel="noopener noreferrer" href="' + esc(waLink("Hello Audtix Systems, I just requested a quotation for " + svc.title + ". My quote number is " + res.quote_no + ".")) + '">Contact us on WhatsApp</a>' +
      '<button type="button" class="sx-close">Close</button></div></div>';
    document.body.appendChild(ov); document.body.classList.add("sx-lock");
    var card = $(".sx-card", ov); card.focus();
    function close() { document.removeEventListener("keydown", key); ov.remove(); document.body.classList.remove("sx-lock"); if (prev && prev.focus) try { prev.focus(); } catch (e) {} }
    function key(e) {
      if (e.key === "Escape") return close();
      if (e.key === "Tab") { var f = $$("a,button", card), a = f[0], z = f[f.length - 1]; if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); } }
    }
    document.addEventListener("keydown", key);
    $(".sx-close", ov).addEventListener("click", close);
    ov.addEventListener("mousedown", function (e) { if (e.target === ov) close(); });
  }

  /* ---------- sending ---------- */
  form.addEventListener("change", sync); form.addEventListener("input", sync);
  form.addEventListener("input", function (e) { var w = e.target.closest && e.target.closest("[data-f]"); if (w) setErr(w.getAttribute("data-f"), ""); msg(""); });
  form.addEventListener("change", function (e) { var w = e.target.closest && e.target.closest("[data-f]"); if (w && w.getAttribute("data-f") !== "attachments") setErr(w.getAttribute("data-f"), ""); });
  form.addEventListener("submit", function (ev) {
    ev.preventDefault();
    var errs = validate();
    showErrors(errs);
    if (Object.keys(errs).length) { msg("Please fix the highlighted fields."); if (window.AudtixToast) window.AudtixToast("Please fix the highlighted fields.", "error", 4000); return; }
    var data = collect(), btn = $("#q-send"), label = btn.textContent;
    var fd = new FormData(); fd.append("service", SERVICE); fd.append("data", JSON.stringify(data));
    Array.prototype.forEach.call($("#q-attachments", form).files || [], function (f) { fd.append("attachments[]", f, f.name); });
    btn.disabled = true; btn.textContent = "Sending…"; msg("");
    var wa = '<a href="' + esc(waLink("Hello Audtix Systems, I tried to request a " + SPEC.services[SERVICE].title + " quotation on your website but it did not go through.")) + '" target="_blank" rel="noopener noreferrer">WhatsApp us</a>';
    var ctl = window.AbortController ? new AbortController() : null, to = setTimeout(function () { ctl && ctl.abort(); }, 120000);
    function done() { clearTimeout(to); btn.disabled = false; btn.textContent = label; }
    fetch(cfg.quoteEndpoint || "api/quote.php", { method: "POST", body: fd, signal: ctl ? ctl.signal : undefined })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { s: r.status, j: j }; }); })
      .then(function (x) {
        done();
        if (x.s === 200 && x.j.ok) {
          popup(x.j, data.full_name);
          if (window.AudtixToast) window.AudtixToast("Your quotation request was sent. Quote number " + x.j.quote_no + ".", "ok", 8000);
          form.reset(); started = Date.now(); $('[data-list="attachments"]', form).innerHTML = "";
          $$(".q-ibody", form).forEach(function (b) { $$(".q-irow", b).slice(1).forEach(function (r) { r.remove(); }); });
          sync(); return;
        }
        if (x.s === 422 && x.j.errors) { showErrors(x.j.errors); if (window.AudtixToast) window.AudtixToast("Please fix the highlighted fields.", "error"); return msg(x.j.errors.form || "Please fix the highlighted fields."); }
        if (x.s === 413) { setErr("attachments", "The files are too large. Remove some and try again."); return msg("Please fix the highlighted fields."); }
        if (x.s === 429) return msg("Too many requests from this network in the last hour. Please try later, or " + wa + ".");
        msg("Sorry, something went wrong on our side. Please try again, or " + wa + ".");
      })
      .catch(function () { done(); msg("We could not reach our server. Check your connection and try again, or " + wa + "."); });
  });

  /* ---------- start ---------- */
  /* Which service? ?service=erp (main), also ?s=erp, #erp or a friendly name like ?service=hardware */
  var ALIAS = { hardware: "ict", "ict-hardware": "ict", fintech: "custom", "custom-fintech": "custom", software: "dev", development: "dev",
    networking: "net", network: "net", advisory: "adv", "enterprise-advisory": "adv", security: "cyber", "cyber-security": "cyber", efris: "ura", "ura-integration": "ura" };
  function pickService() {
    var q = new URLSearchParams(location.search), v = (q.get("service") || q.get("s") || "").trim().toLowerCase();
    var h = decodeURIComponent(location.hash.replace(/^#/, "")).trim().toLowerCase();
    v = ALIAS[v] || v;
    // #erp works too, but only for real service names (#q-form is just a jump to the form)
    if (!v && h && window.QUOTE_FORMS && window.QUOTE_FORMS.services[ALIAS[h] || h]) v = ALIAS[h] || h;
    return v;
  }
  function start() {
    SPEC = window.QUOTE_FORMS;
    SERVICE = pickService();
    if (!SPEC || !SPEC.services) return false;
    renderHeader();
    if (SPEC.services[SERVICE]) render();
    return true;
  }
  function failed() { $("#q-loading").innerHTML = '<p>The form could not be loaded. Please reload the page, email <a href="mailto:info@audtix.com">info@audtix.com</a> or WhatsApp +256 701 287 388.</p>'; }
  window.addEventListener("hashchange", function () { if (SPEC && pickService() !== SERVICE) location.reload(); });
  if (!start()) {
    // quote-forms.js did not load (old cached page, slow network): load it now, then try once more
    var sc = document.createElement("script");
    sc.src = "quote-forms.js?v=" + Date.now();
    sc.onload = function () { if (!start()) failed(); };
    sc.onerror = failed;
    document.head.appendChild(sc);
  }
})();
