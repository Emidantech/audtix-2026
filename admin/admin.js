/* Admin panel behaviour (kept in a file because the page's security policy blocks inline scripts). */
(function () {
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  $$("[data-menu]").forEach(function (b) { b.addEventListener("click", function () { var o = document.body.classList.toggle("menu-open"); var bb = document.querySelector(".burger"); if (bb) bb.setAttribute("aria-expanded", o ? "true" : "false"); }); });
  $$("[data-grp]").forEach(function (b) { b.addEventListener("click", function () { var g = b.parentNode, o = g.classList.toggle("open"); b.setAttribute("aria-expanded", o ? "true" : "false"); }); });
  $$("[data-confirm]").forEach(function (b) { b.addEventListener("click", function (e) { if (!window.confirm(b.getAttribute("data-confirm"))) e.preventDefault(); }); });
  $$("[data-autosubmit]").forEach(function (s) { s.addEventListener("change", function () { s.form.submit(); }); });
  $$("[data-print]").forEach(function (b) { b.addEventListener("click", function () { window.print(); }); });
  $$("[data-preset]").forEach(function (b) {
    b.addEventListener("click", function () {
      var set = (b.getAttribute("data-preset") || "").split(",");
      $$('input[name="perms[]"]', b.form).forEach(function (c) { c.checked = set.indexOf(c.value) > -1; });
    });
  });
  $$("[data-logo-input]").forEach(function (i) {
    i.addEventListener("change", function () {
      var f = i.files && i.files[0], img = document.querySelector("[data-logo-preview]"); if (!f || !img) return;
      var r = new FileReader(); r.onload = function () { img.src = r.result; img.hidden = false; }; r.readAsDataURL(f);
    });
  });
})();
