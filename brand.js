/* Applies the branding set in Admin > Business settings > Branding (colours, logos, favicon).
   Runs in <head>: re-applies the last known branding at once (no flash), then checks api/site.php for changes. */
(function () {
  var KEY = "audtix-brand", root = document.documentElement;
  function setFav(url) {
    var l = document.querySelector('link[rel="icon"]');
    if (!l) { l = document.createElement("link"); l.rel = "icon"; document.head.appendChild(l); }
    l.href = url;
  }
  function vars(b) { if (b && b.vars) for (var k in b.vars) if (/^--[a-z-]+$/.test(k) && /^#[0-9A-Fa-f]{6}$/.test(b.vars[k])) root.style.setProperty(k, b.vars[k]); }
  function logos(b) {
    if (!b) return;
    var q = function (u) { return u + (u.indexOf("?") < 0 ? "?v=" + encodeURIComponent(b.v || "") : ""); };
    var keep = function (i, orig) { if (!i.getAttribute("data-orig")) i.setAttribute("data-orig", orig); i.onerror = function () { i.onerror = null; i.src = i.getAttribute("data-orig"); }; };
    if (b.logo) document.querySelectorAll('img[src$="img/logo-blue.png"], img[data-brand="logo"]').forEach(function (i) { keep(i, "img/logo-blue.png"); i.setAttribute("data-brand", "logo"); i.src = q(b.logo); });
    if (b.logo_white) document.querySelectorAll('img[src$="img/logo-white.png"], img[data-brand="logo-white"]').forEach(function (i) { keep(i, "img/logo-white.png"); i.setAttribute("data-brand", "logo-white"); i.src = q(b.logo_white); });
    if (b.favicon) setFav(q(b.favicon));
  }
  var cached = null;
  try { cached = JSON.parse(localStorage.getItem(KEY) || "null"); } catch (e) {}
  vars(cached);
  if (cached && cached.favicon) setFav(cached.favicon + "?v=" + encodeURIComponent(cached.v || ""));
  function ready(fn) { if (document.readyState !== "loading") fn(); else document.addEventListener("DOMContentLoaded", fn); }
  ready(function () { logos(cached); });
  if (!window.fetch) return;
  fetch("api/site.php", { credentials: "omit" }).then(function (r) { return r.ok ? r.json() : null; }).then(function (b) {
    if (!b || !b.ok) return;
    try { localStorage.setItem(KEY, JSON.stringify(b)); } catch (e) {}
    if (!cached || cached.v !== b.v) { vars(b); ready(function () { logos(b); }); }
  }).catch(function () {});
})();
