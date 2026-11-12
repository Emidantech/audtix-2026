(function () {
  // Dropdowns
  var dds = Array.prototype.slice.call(document.querySelectorAll('.dd'));
  function closeAll(except) {
    dds.forEach(function (d) {
      if (d !== except) { d.classList.remove('open'); d.firstElementChild.setAttribute('aria-expanded', 'false'); }
    });
  }
  dds.forEach(function (d) {
    var b = d.firstElementChild;
    b.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = !d.classList.contains('open');
      closeAll(d);
      d.classList.toggle('open', open);
      b.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
  document.addEventListener('click', function () { closeAll(null); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(null); });
  document.querySelectorAll('.dd-panel a').forEach(function (a) {
    a.addEventListener('click', function () { closeAll(null); });
  });

  // Mobile menu (three-bar button)
  var mb = document.getElementById('menu-btn');
  var hdr = document.querySelector('.site-header');
  if (mb && hdr) {
    var mq = window.matchMedia ? window.matchMedia('(max-width: 920px)') : null;
    var setMenu = function (open, restoreFocus) {
      hdr.classList.toggle('menu-open', open);
      document.documentElement.classList.toggle('nav-lock', open);
      mb.setAttribute('aria-expanded', open ? 'true' : 'false');
      mb.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
      if (!open) { closeAll(null); if (restoreFocus) mb.focus(); }
    };
    mb.addEventListener('click', function (e) { e.stopPropagation(); setMenu(!hdr.classList.contains('menu-open')); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && hdr.classList.contains('menu-open')) setMenu(false, true); });
    document.addEventListener('click', function (e) { if (hdr.classList.contains('menu-open') && !hdr.contains(e.target)) setMenu(false); });
    document.querySelectorAll('#site-nav a').forEach(function (a) { a.addEventListener('click', function () { setMenu(false); }); });
    if (mq) { var onChange = function () { if (!mq.matches) setMenu(false); }; if (mq.addEventListener) mq.addEventListener('change', onChange); else if (mq.addListener) mq.addListener(onChange); }
  }


  // Footer link groups: dropdowns on small screens, always open on larger ones
  var fcols = Array.prototype.slice.call(document.querySelectorAll('.fcol'));
  if (fcols.length) {
    var fmq = window.matchMedia ? window.matchMedia('(max-width: 680px)') : null;
    var applyFooter = function () {
      var small = !!(fmq && fmq.matches);
      fcols.forEach(function (c) {
        var b = c.querySelector('.fh');
        c.classList.toggle('collapsible', small);
        if (small) { c.classList.remove('open'); b.setAttribute('aria-expanded', 'false'); }
        else { c.classList.remove('open'); b.setAttribute('aria-expanded', 'true'); }
      });
    };
    fcols.forEach(function (c) {
      c.querySelector('.fh').addEventListener('click', function () {
        if (!c.classList.contains('collapsible')) return;
        var open = !c.classList.contains('open');
        c.classList.toggle('open', open);
        this.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    });
    applyFooter();
    if (fmq) { if (fmq.addEventListener) fmq.addEventListener('change', applyFooter); else if (fmq.addListener) fmq.addListener(applyFooter); }
  }

  // Hero video: pause control, and no autoplay when the viewer prefers reduced motion
  var vid = document.getElementById('heroVideo');
  var pb = document.getElementById('vpause');
  if (vid && pb) {
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var iconPause = '<svg width="16" height="16" viewBox="0 0 16 16" fill="#fff" aria-hidden="true"><rect x="3" y="2" width="4" height="12" rx="1"/><rect x="9" y="2" width="4" height="12" rx="1"/></svg>';
    var iconPlay = '<svg width="16" height="16" viewBox="0 0 16 16" fill="#fff" aria-hidden="true"><path d="M4 2l10 6-10 6z"/></svg>';
    function sync() {
      var paused = vid.paused;
      pb.innerHTML = paused ? iconPlay : iconPause;
      pb.setAttribute('aria-label', paused ? 'Play background video' : 'Pause background video');
    }
    if (reduce) { try { vid.pause(); } catch (e) {} }
    pb.addEventListener('click', function () {
      if (vid.paused) { var p = vid.play(); if (p && p.catch) p.catch(function () {}); } else { vid.pause(); }
      sync();
    });
    vid.addEventListener('play', sync); vid.addEventListener('pause', sync);
    sync();
  }

  // Contact form: builds the message, then lets the visitor choose how to send it.
  var form = document.getElementById('contactForm');
  if (form) {
    var EMAIL = 'info@audtix.com', WA = '256701287388';
    var sent = document.getElementById('sent');
    var out = document.getElementById('msgOut');
    var mailA = document.getElementById('mailLink');
    var waA = document.getElementById('waLink');
    var copyB = document.getElementById('copyMsg');
    var copyNote = document.getElementById('copyNote');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!form.checkValidity()) { form.reportValidity(); return; }
      var v = function (id) { return (document.getElementById(id).value || '').trim(); };
      var lines = [
        'Hello Audtix Systems,', '',
        'Name: ' + v('f-name'),
        v('f-company') ? 'Company: ' + v('f-company') : '',
        'Email: ' + v('f-email'),
        v('f-phone') ? 'Phone: ' + v('f-phone') : '',
        'Service: ' + v('f-service'), '',
        v('f-message')
      ].filter(function (l, i, a) { return !(l === '' && a[i - 1] === ''); });
      var text = lines.join('\n');
      var subject = 'Enquiry: ' + v('f-service') + ' (' + v('f-name') + ')';
      out.value = text;
      mailA.href = 'mailto:' + EMAIL + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(text);
      waA.href = 'https://wa.me/' + WA + '?text=' + encodeURIComponent(text);
      sent.classList.add('show');
      copyNote.textContent = '';
      if (window.AudtixToast) window.AudtixToast('Your message is ready. Choose email or WhatsApp below to send it.', 'info');
      sent.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    copyB.addEventListener('click', function () {
      function fallback() { out.focus(); out.select(); copyNote.textContent = 'Message selected. Press Ctrl+C or Cmd+C to copy.'; }
      try {
        navigator.clipboard.writeText(out.value).then(function () { copyNote.textContent = 'Message copied.'; }, fallback);
      } catch (err) { fallback(); }
    });
  }
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-copy'), note = b.nextElementSibling;
      function done(m) { if (note) note.textContent = m; }
      try { navigator.clipboard.writeText(t).then(function () { done('Copied'); }, function () { done('Select the text and copy it'); }); }
      catch (err) { done('Select the text and copy it'); }
    });
  });
})();

/* ---------- Toast messages: AudtixToast("Sent!", "ok" | "error" | "info") ---------- */
(function () {
  var box = null;
  window.AudtixToast = function (msg, kind, ms) {
    if (!box) { box = document.createElement('div'); box.className = 'toasts'; box.setAttribute('role', 'status'); box.setAttribute('aria-live', 'polite'); document.body.appendChild(box); }
    var t = document.createElement('div'); t.className = 'toast ' + (kind || 'ok');
    var ic = { ok: '<path d="M5 12.5l4.5 4.5L19 7.5"/>', error: '<path d="M12 8v5M12 16.5v.5"/><circle cx="12" cy="12" r="9"/>', info: '<path d="M12 11v6M12 7.5v.5"/><circle cx="12" cy="12" r="9"/>' }[kind || 'ok'];
    t.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ic + '</svg><span></span><button type="button" aria-label="Dismiss">&times;</button>';
    t.querySelector('span').textContent = msg;
    var gone = function () { t.classList.add('out'); setTimeout(function () { t.remove(); }, 250); };
    t.querySelector('button').addEventListener('click', gone);
    box.appendChild(t);
    setTimeout(gone, ms || 6000);
  };
})();

/* ---------- Floating "Request a quote" button (all pages except the forms themselves) ---------- */
(function () {
  if (document.querySelector('.rq-form, #q-form')) return;
  var services = [['Tax service', 'request-tax.html#rq-form'], ['Audit service', 'request-audit.html#rq-form'], ['ERP deployment', 'quote.html?service=erp#q-form'], ['Custom fintech systems', 'quote.html?service=custom#q-form'],
    ['URA system integration', 'quote.html?service=ura#q-form'], ['Cyber security', 'quote.html?service=cyber#q-form'], ['Software development', 'quote.html?service=dev#q-form'], ['Networking', 'quote.html?service=net#q-form'],
    ['Enterprise advisory', 'quote.html?service=adv#q-form'], ['ICT hardware', 'quote.html?service=ict#q-form']];
  var w = document.createElement('div'); w.className = 'fab';
  w.innerHTML = '<div class="fab-panel" id="fab-panel" role="menu" hidden><b>What do you need?</b>' + services.map(function (s) { return '<a role="menuitem" href="' + s[1] + '">' + s[0] + '<span aria-hidden="true">→</span></a>'; }).join('') +
    '<a role="menuitem" class="fab-wa" href="https://wa.me/256701287388" target="_blank" rel="noopener noreferrer">Not sure? WhatsApp us</a></div>' +
    '<button type="button" class="fab-btn" aria-expanded="false" aria-controls="fab-panel"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/><path d="M9 13h7M9 17h5"/></svg><span>Request a quote</span></button>';
  document.body.appendChild(w);
  var btn = w.querySelector('.fab-btn'), panel = w.querySelector('.fab-panel');
  function set(open) { panel.hidden = !open; btn.setAttribute('aria-expanded', open ? 'true' : 'false'); w.classList.toggle('open', open); }
  btn.addEventListener('click', function (e) { e.stopPropagation(); set(panel.hidden); if (!panel.hidden) panel.querySelector('a').focus(); });
  document.addEventListener('click', function (e) { if (!w.contains(e.target)) set(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { set(false); btn.focus(); } });
  // stay clear of the footer's last line on small screens
  var foot = document.querySelector('footer.site');
  if (foot && 'IntersectionObserver' in window) new IntersectionObserver(function (es) { w.classList.toggle('at-foot', es[0].isIntersecting); }, { rootMargin: '0px 0px -40px 0px' }).observe(foot.querySelector('.fbase') || foot);
})();
