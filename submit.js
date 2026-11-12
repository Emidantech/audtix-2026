/* Shared sender for the Tax and Audit request forms.
   Posts to the website's own PHP endpoint, then shows a success card with the request ID and a WhatsApp option. */
(function () {
  var cfg = window.AUDTIX_CONFIG || {};
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function fileLabels(form) {
    var o = {};
    Array.prototype.forEach.call(form.querySelectorAll("input[type=file]"), function (i) {
      var w = i.closest("[data-f]"), l = w && w.querySelector("label");
      o[i.name] = l ? l.textContent.replace(/\s+/g, " ").trim() : i.name.replace(/^file__/, "").replace(/_/g, " ");
    });
    return o;
  }
  function waLink(text) { return "https://wa.me/" + (cfg.whatsapp || "256701287388") + "?text=" + encodeURIComponent(text); }

  function popup(res, name, onClose) {
    var kind = res.category === "audit" ? "Audit" : "Tax";
    var prev = document.activeElement;
    var ov = document.createElement("div"); ov.className = "sx-ov";
    ov.innerHTML = '<div class="sx-card" role="dialog" aria-modal="true" aria-labelledby="sx-t" tabindex="-1">' +
      '<div class="sx-ic" aria-hidden="true"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>' +
      '<h2 id="sx-t">Request sent</h2>' +
      '<p class="sx-ref"><span class="sx-id">' + esc(res.request_id) + '</span><span class="sx-tag sx-' + esc(res.category) + '">' + kind.toUpperCase() + '</span></p>' +
      '<p>Thank you' + (name ? ', ' + esc(name) : '') + '. Your information is on its way to our admin team and someone will reach out to you soon. Please keep your request ID for reference.</p>' +
      '<div class="sx-row"><a class="sx-wa" target="_blank" rel="noopener noreferrer" href="' + esc(waLink("Hello Audtix Systems, I just submitted a " + kind.toLowerCase() + " service request. My request ID is " + res.request_id + ".")) + '">' +
      '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12a8 8 0 0 1-11.9 7L4 20l1.1-3.9A8 8 0 1 1 20 12z"/><path d="M9 9.5c.3 2 2.5 4.2 5 5l1.2-1.2-1.8-1-.8.7c-.8-.4-1.600-1.200-2-2l.7-.8-1-1.800z"/></svg>Contact us on WhatsApp</a>' +
      '<button type="button" class="sx-close">Close</button></div></div>';
    document.body.appendChild(ov); document.body.classList.add("sx-lock");
    var card = ov.querySelector(".sx-card"); card.focus();
    function close() { document.removeEventListener("keydown", key); ov.remove(); document.body.classList.remove("sx-lock"); if (prev && prev.focus) try { prev.focus(); } catch (e) {} onClose && onClose(); }
    function key(e) {
      if (e.key === "Escape") return close();
      if (e.key === "Tab") { var f = card.querySelectorAll("a,button"); if (!f.length) return; var a = f[0], z = f[f.length - 1]; if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); } }
    }
    document.addEventListener("keydown", key);
    ov.querySelector(".sx-close").addEventListener("click", close);
    ov.addEventListener("mousedown", function (e) { if (e.target === ov) close(); });
  }

  /* opts: form, category, data, rows, files [{key,file}], button, name, onFieldErrors(errs), onMessage(html), onDone() */
  function send(opts) {
    var btn = opts.button, label = btn.textContent;
    btn.disabled = true; btn.textContent = "Sending...";
    var fd = new FormData();
    fd.append("category", opts.category);
    fd.append("data", JSON.stringify(opts.data));
    fd.append("rows", JSON.stringify(opts.rows || []));
    fd.append("file_labels", JSON.stringify(fileLabels(opts.form)));
    opts.files.forEach(function (x) { fd.append(x.key + "[]", x.file, x.file.name); });
    var ctl = typeof AbortController !== "undefined" ? new AbortController() : null;
    var to = setTimeout(function () { ctl && ctl.abort(); }, opts.files.length ? 120000 : 30000);
    var wa = '<a href="' + esc(waLink("Hello Audtix Systems, I tried to send a " + opts.category + " service request on your website but it did not go through.")) + '" target="_blank" rel="noopener noreferrer">WhatsApp us</a>';
    function fail(msg) { clearTimeout(to); btn.disabled = false; btn.textContent = label; opts.onMessage(msg); if (window.AudtixToast) window.AudtixToast("Your request was not sent. See the message above the button.", "error"); }
    fetch(cfg.submitEndpoint || "api/submit.php", { method: "POST", body: fd, signal: ctl ? ctl.signal : undefined })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { s: r.status, j: j }; }); })
      .then(function (x) {
        clearTimeout(to);
        if (x.s === 200 && x.j.ok) { btn.disabled = false; btn.textContent = label; if (window.AudtixToast) window.AudtixToast("Your request was sent. Request ID " + x.j.request_id + ".", "ok", 8000); return popup(x.j, opts.name, opts.onDone); }
        if (x.s === 422 && x.j.errors) { btn.disabled = false; btn.textContent = label; opts.onFieldErrors(x.j.errors); return opts.onMessage("Please fix the highlighted fields."); }
        if (x.s === 413) { btn.disabled = false; btn.textContent = label; opts.onFieldErrors({ files: "The files are too large. Remove some and try again." }); return opts.onMessage("Please fix the highlighted fields."); }
        if (x.s === 429) return fail("Too many requests from this network. Please try again later or " + wa + ".");
        fail("We could not send your request just now. Please try again in a moment, or " + wa + ".");
      })
      .catch(function () { fail("We could not send your request just now. Please check your connection and try again, or " + wa + "."); });
  }
  window.AudtixSubmit = { send: send };
})();
