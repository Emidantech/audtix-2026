/* Continuous right-to-left scrolling rows (hero partner strip, clients). Additive: without JS the original layout is shown. */
(function () {
  'use strict';
  function build(host) {
    if (host.__mq) return; host.__mq = true;
    var items = Array.prototype.slice.call(host.children); if (!items.length) return;
    var track = document.createElement('div'); track.className = 'mq-track';
    var a = document.createElement('div'); a.className = 'mq-set';
    items.forEach(function (n) { a.appendChild(n); });
    track.appendChild(a); host.appendChild(track); host.classList.add('mq-on');
    function fit() {                                   // repeat the set until the row is always full, then slide exactly one set
      Array.prototype.slice.call(track.children, 1).forEach(function (n) { track.removeChild(n); });
      var w = a.getBoundingClientRect().width; if (!w) return;
      var need = Math.ceil(host.clientWidth / w) + 1;
      for (var i = 0; i < need; i++) { var c = a.cloneNode(true); c.setAttribute('aria-hidden', 'true'); Array.prototype.forEach.call(c.querySelectorAll('a,button,[tabindex]'), function (n) { n.setAttribute('tabindex', '-1'); }); track.appendChild(c); }
      track.style.setProperty('--mq-w', w + 'px');
    }
    fit(); window.addEventListener('resize', fit); window.addEventListener('load', fit);
    var secs = parseFloat(host.getAttribute('data-mq-speed')) || 28;
    track.style.animationDuration = secs + 's';
  }
  function init() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-marquee]'), build);
    var cg = document.querySelector('#clients .cgroups');       // clients: one moving row built from the grouped chips
    if (cg && !document.querySelector('.mq-clients')) {
      var row = document.createElement('div'); row.className = 'mq mq-clients'; row.setAttribute('data-marquee', ''); row.setAttribute('data-mq-speed', '34');
      Array.prototype.forEach.call(cg.querySelectorAll('.cchips > *'), function (n) { row.appendChild(n.cloneNode(true)); });
      cg.parentNode.insertBefore(row, cg); build(row); cg.classList.add('mq-hidden');
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
