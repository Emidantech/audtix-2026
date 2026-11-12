(function (root) {
  'use strict';

  /* ---------- Saved reference rates (units per 1 USD). Used ONLY when the live feeds cannot be reached, and always labelled with their date. ---------- */
  var CUR = {
    USD: { r: 1,        d: '2026-11-04', name: 'US dollar',          dec: 2 },
    UGX: { r: 4225.83,  d: '2026-11-04', name: 'Ugandan shilling',   dec: 0 },
    EUR: { r: 0.8895,   d: '2026-11-04', name: 'Euro',               dec: 2 },
    GBP: { r: 0.7463,   d: '2026-11-02', name: 'British pound',      dec: 2 },
    KES: { r: 129.85,   d: '2026-11-03', name: 'Kenyan shilling',    dec: 2 },
    TZS: { r: 2629,     d: '2026-11-04', name: 'Tanzanian shilling', dec: 0 },
    RWF: { r: 1471.52,  d: '2026-11-04', name: 'Rwandan franc',      dec: 0 },
    ZAR: { r: 16.617,   d: '2026-11-03', name: 'South African rand', dec: 2 },
    AED: { r: 3.6725,   d: null,         name: 'UAE dirham (pegged to USD)', dec: 2 },
    INR: { r: 94.7103,  d: '2026-11-02', name: 'Indian rupee',       dec: 2 },
    CAD: { r: 1.3987,   d: '2026-10-29', name: 'Canadian dollar',    dec: 2 },
    JPY: { r: 156.7334, d: '2026-10-26', name: 'Japanese yen',       dec: 0 }
  };
  var ORDER = ['UGX', 'USD', 'EUR', 'GBP', 'KES', 'TZS', 'RWF', 'ZAR', 'AED', 'INR', 'CAD', 'JPY'];
  var REF_DATE = '2026-11-04';

  // Evergreen education only. Anything about current prices or events is generated live from the feeds below.
  var EVERGREEN = [
    { tag: 'Basics', cur: [], t: 'The mid-market rate is the midpoint between buy and sell prices. Banks and money changers add a margin. Compare the rate you are offered with the mid-market rate, and ask for the total cost in shillings before you agree.' },
    { tag: 'Basics', cur: [], t: 'A pip is the usual unit of price movement: 0.0001 for most pairs and 0.01 for pairs with the yen. If EUR/USD moves from 1.1233 to 1.1253, that is 20 pips.' },
    { tag: 'Risk', cur: [], t: 'Leverage enlarges losses as well as gains, and most retail forex traders lose money. Many experienced traders risk only 1% to 2% of their account on a single trade and set a stop-loss before they enter.' },
    { tag: 'Safety', cur: [], t: 'Before sending money to a forex broker, check that a recognised regulator licenses it and that you can withdraw easily. Be wary of anyone who promises guaranteed returns.' }
  ];
  var TIPS = EVERGREEN.slice();

  /* ---------- Pure calculation functions ---------- */
  function payeMonthly(m, resident) {
    var t;
    if (resident) {
      if (m <= 335000) t = 0;
      else if (m <= 410000) t = 0.2 * (m - 335000);
      else if (m <= 485000) t = 15000 + 0.25 * (m - 410000);
      else t = 33750 + 0.3 * (m - 485000);
    } else {
      if (m <= 335000) t = 0.1 * m;
      else if (m <= 410000) t = 33500 + 0.2 * (m - 335000);
      else t = 48500 + 0.3 * (m - 410000);
    }
    if (m > 10000000) t += 0.1 * (m - 10000000);
    return Math.round(t);
  }

  function incomeTaxAnnual(x, kind) {
    var t;
    if (kind === 'company') return Math.round(0.3 * x);
    if (kind === 'resident') {
      if (x <= 4020000) t = 0;
      else if (x <= 4920000) t = 0.2 * (x - 4020000);
      else if (x <= 5820000) t = 180000 + 0.25 * (x - 4920000);
      else t = 405000 + 0.3 * (x - 5820000);
      if (x > 120000000) t += 0.1 * (x - 120000000);
      return Math.round(t);
    }
    if (x <= 4020000) t = 0.1 * x;
    else if (x <= 4920000) t = 402000 + 0.2 * (x - 4020000);
    else if (x <= 120000000) t = 582000 + 0.3 * (x - 4920000);
    else t = 35106000 + 0.4 * (x - 120000000);
    return Math.round(t);
  }

  function returnCalc(o) {
    var profit = o.rev - o.exp - o.ded;
    var used = 0, chargeable = 0, lossCF = 0;
    if (profit <= 0) { lossCF = o.loss + (-profit); }
    else { used = Math.min(o.loss, profit); chargeable = profit - used; lossCF = o.loss - used; }
    var tax = incomeTaxAnnual(chargeable, o.kind);
    return { profit: profit, used: used, chargeable: chargeable, lossCF: lossCF, tax: tax, balance: tax - o.paid };
  }

  function vatCalc(amount, mode, rate) {
    if (mode === 'add') {
      var v = Math.round(amount * rate);
      return { net: amount, vat: v, gross: amount + v };
    }
    var net = rate ? Math.round(amount / (1 + rate)) : amount;
    return { net: net, vat: amount - net, gross: amount };
  }

  function vatPayable(sales, purchases) {
    var out = Math.round(sales * 0.18), inp = Math.round(purchases * 0.18);
    return { out: out, inp: inp, payable: out - inp };
  }

  function rateFor(from, to) { return CUR[to].r / CUR[from].r; }
  function convert(amount, from, to) { return amount * rateFor(from, to); }

  var api = { CUR: CUR, payeMonthly: payeMonthly, incomeTaxAnnual: incomeTaxAnnual, returnCalc: returnCalc, vatCalc: vatCalc, vatPayable: vatPayable, rateFor: rateFor, convert: convert };
  root.AudtixCalc = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (typeof document === 'undefined') return;

  /* ---------- Page code ---------- */
  var $ = function (id) { return document.getElementById(id); };
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  function fmt(n, dec) {
    return new Intl.NumberFormat('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec }).format(n);
  }
  function ugx(n) { return 'UGX ' + fmt(Math.round(n), 0); }
  function fmtRate(r) {
    if (r >= 100) return fmt(r, 2);
    if (r >= 1) return fmt(r, 4);
    return String(Number(r.toPrecision(4)));
  }
  function niceDate(iso) {
    if (!iso) return 'pegged rate';
    var p = iso.split('-');
    return parseInt(p[2], 10) + ' ' + MONTHS[parseInt(p[1], 10) - 1] + ' ' + p[0];
  }
  function val(id) {
    var s = ($(id).value || '').replace(/,/g, '');
    var n = parseFloat(s);
    return isFinite(n) && n > 0 ? Math.min(n, 1e15) : 0;
  }
  function rows(list) {
    return '<dl class="rows">' + list.map(function (r) {
      return '<div' + (r[2] ? ' class="' + r[2] + '"' : '') + '><dt>' + r[0] + '</dt><dd>' + r[1] + '</dd></div>';
    }).join('') + '</dl>';
  }
  function usdNote(amountUgx) {
    var u = amountUgx / CUR.UGX.r;
    return '<p class="eq">About US$ ' + fmt(u, 2) + ' at ' + fmt(CUR.UGX.r, 2) + ' UGX per dollar (' + (CUR.UGX.live ? 'live rate, ' + niceDate(CUR.UGX.d) : 'saved rate dated ' + niceDate(REF_DATE) + ', not live') + ').</p>';
  }

  function moneyInput(el, allowDec) {
    el.addEventListener('input', function () {
      var pos = el.selectionStart == null ? el.value.length : el.selectionStart;
      var before = el.value.slice(0, pos).replace(/[^0-9.]/g, '').length;
      var raw = el.value.replace(/[^0-9.]/g, '');
      if (!allowDec) raw = raw.replace(/\./g, '');
      else { var p = raw.split('.'); raw = p[0] + (p.length > 1 ? '.' + p.slice(1).join('').slice(0, 4) : ''); }
      var parts = raw.split('.');
      parts[0] = parts[0].replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
      var out = parts.join('.');
      el.value = out;
      var n = 0, i = 0;
      for (; i < out.length && n < before; i++) { if (/[0-9.]/.test(out.charAt(i))) n++; }
      try { el.setSelectionRange(i, i); } catch (e) { /* not focusable here */ }
    });
  }

  /* ----- PAYE ----- */
  function renderPaye() {
    var g = val('p-gross'), res = $('p-res').value === 'resident', nssf = $('p-nssf').checked;
    var out = $('out-paye');
    if (!g) { out.innerHTML = '<p class="empty">Enter a monthly gross pay to see the result.</p>'; return; }
    var paye = payeMonthly(g, res);
    var ns = nssf ? Math.round(g * 0.05) : 0, nsEr = nssf ? Math.round(g * 0.10) : 0;
    var net = g - paye - ns;
    var list = [
      ['Gross pay', ugx(g)],
      ['PAYE', '− ' + ugx(paye)]
    ];
    if (nssf) list.push(['NSSF, employee 5%', '− ' + ugx(ns)]);
    list.push(['Net pay', ugx(net), 'total']);
    var more = [
      ['PAYE for the year', ugx(paye * 12)],
      ['PAYE as a share of gross', fmt(g ? (paye / g) * 100 : 0, 1) + '%']
    ];
    if (nssf) { more.push(['NSSF, employer 10%', ugx(nsEr)]); more.push(['Total cost to employer', ugx(g + nsEr)]); }
    out.innerHTML = '<div class="big-label">Net pay each month</div><div class="big">' + ugx(net) + '</div>' + rows(list) +
      '<details class="more"><summary>More detail</summary>' + rows(more) + '</details>' + usdNote(net);
  }

  /* ----- VAT ----- */
  function renderVat() {
    var a = val('v-amount');
    var mode = document.querySelector('input[name="v-mode"]:checked').value;
    var sel = $('v-rate').value;
    var rate = sel === 'std' ? 0.18 : 0;
    var out = $('out-vat');
    if (!a) { out.innerHTML = '<p class="empty">Enter an amount to see the result.</p>'; }
    else {
      var r = vatCalc(a, mode, rate);
      var label = sel === 'std' ? 'VAT at 18%' : (sel === 'zero' ? 'VAT at 0% (zero-rated)' : 'No VAT (exempt)');
      out.innerHTML = '<div class="big-label">' + (mode === 'add' ? 'Price including VAT' : 'Price excluding VAT') + '</div><div class="big">' +
        ugx(mode === 'add' ? r.gross : r.net) + '</div>' +
        rows([['Amount excluding VAT', ugx(r.net)], [label, ugx(r.vat)], ['Amount including VAT', ugx(r.gross), 'total']]) + usdNote(r.gross);
    }
    var s = val('v-sales'), p = val('v-purch');
    var o2 = $('out-vatpay');
    if (!s && !p) { o2.innerHTML = '<p class="empty">Enter your sales and purchases for the month.</p>'; return; }
    var vp = vatPayable(s, p);
    o2.innerHTML = '<div class="big-label">' + (vp.payable >= 0 ? 'VAT payable to URA' : 'VAT credit (refundable or carried forward)') + '</div><div class="big">' + ugx(Math.abs(vp.payable)) + '</div>' +
      rows([['Output VAT on sales at 18%', ugx(vp.out)], ['Input VAT on purchases at 18%', '− ' + ugx(vp.inp)], [vp.payable >= 0 ? 'VAT payable' : 'VAT credit', ugx(Math.abs(vp.payable)), 'total']]);
  }

  /* ----- Annual return ----- */
  function renderReturn() {
    var kind = $('r-kind').value;
    var o = { kind: kind, rev: val('r-rev'), exp: val('r-exp'), ded: val('r-ded'), loss: val('r-loss'), paid: val('r-paid') };
    var out = $('out-return');
    if (!o.rev) { out.innerHTML = '<p class="empty">Enter your income for the year to see the estimate.</p>'; return; }
    var c = returnCalc(o);
    var list = [
      ['Income for the year', ugx(o.rev)],
      ['Allowable expenses', '− ' + ugx(o.exp)],
      ['Other deductions and allowances', '− ' + ugx(o.ded)],
      [c.profit >= 0 ? 'Profit before losses' : 'Loss for the year', ugx(Math.abs(c.profit))]
    ];
    if (c.used) list.push(['Losses brought forward used', '− ' + ugx(c.used)]);
    list.push(['Chargeable income', ugx(c.chargeable)]);
    list.push(['Income tax assessed', ugx(c.tax)]);
    if (o.paid) list.push(['Tax already paid or withheld', '− ' + ugx(o.paid)]);
    var bal = c.balance;
    list.push([bal >= 0 ? 'Balance to pay' : 'Overpaid (refund or credit)', ugx(Math.abs(bal)), 'total']);
    var head = bal >= 0 ? 'Estimated balance to pay' : 'Estimated overpayment';
    var eff = c.chargeable ? fmt((c.tax / c.chargeable) * 100, 1) + '%' : '0%';
    out.innerHTML = '<div class="big-label">' + head + '</div><div class="big">' + ugx(Math.abs(bal)) + '</div>' + rows(list) +
      '<p class="eq">Average tax rate on chargeable income: ' + eff + '.' + (c.lossCF ? ' Loss to carry forward: ' + ugx(c.lossCF) + '.' : '') + '</p>' + usdNote(Math.abs(bal));
  }

  /* ----- Live data: rates, history, headlines, clock ----- */
  var CFG = (typeof window !== 'undefined' && window.AUDTIX_CONFIG) || {};
  var LIVE = { ratesOk: false, ratesAt: null, ratesSrc: '', cached: false, hist: null, histSrc: '', news: {}, newsErr: {}, newsQuery: '' };
  var DAY = 86400000;
  var TRUSTED = ['reuters', 'bloomberg', 'financial times', 'ft.com', 'bbc', 'cnbc', 'associated press', 'ap news', 'al jazeera', 'wall street journal', 'wsj', 'marketwatch', 'barron', 'the economist',
    'daily monitor', 'monitor', 'new vision', 'the independent', 'nile post', 'the east african', 'eastafrican', 'bank of uganda', 'ministry of finance', 'uganda revenue authority', 'business daily', 'the citizen', 'capital fm',
    'federal reserve', 'european central bank', 'ecb', 'bank of england', 'imf', 'international monetary fund', 'world bank', 'fxstreet', 'investing.com', 'yahoo finance', 'forbes', 'the guardian', 'nasdaq', 'axios', 'cnn business', 'dw.com', 'africanews', 'the africa report'];

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function isoDay(d) { return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()); }
  function longDate(d) { return d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }); }
  function shortDT(d) { return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) + ', ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }); }
  function ago(d) {
    var m = Math.round((Date.now() - d.getTime()) / 60000);
    if (m < 1) return 'just now';
    if (m < 60) return m + ' min ago';
    if (m < 1440) return Math.round(m / 60) + ' h ago';
    var dd = Math.round(m / 1440); return dd + (dd === 1 ? ' day ago' : ' days ago');
  }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function safeUrl(u) { return /^https?:\/\//i.test(u) ? u : '#'; }

  function getJSON(url, ms) {
    return new Promise(function (resolve, reject) {
      var ctl = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var t = setTimeout(function () { if (ctl) ctl.abort(); reject(new Error('timeout')); }, ms || 9000);
      fetch(url, { cache: 'no-store', signal: ctl ? ctl.signal : undefined })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (j) { clearTimeout(t); resolve(j); }, function (e) { clearTimeout(t); reject(e); });
    });
  }
  function store(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* ignore */ } }
  function recall(k) { try { return JSON.parse(localStorage.getItem(k)); } catch (e) { return null; } }

  function applyRates(rates, at, src, cached) {
    var n = 0;
    ORDER.forEach(function (c) {
      var r = c === 'USD' ? 1 : rates[c];
      if (typeof r === 'number' && isFinite(r) && r > 0) { CUR[c].r = r; CUR[c].d = isoDay(at); CUR[c].live = true; n++; }
    });
    if (n >= 2 && rates.UGX) { LIVE.ratesOk = true; LIVE.ratesAt = at; LIVE.ratesSrc = src; LIVE.cached = !!cached; return true; }
    return false;
  }
  function loadRates() {
    // Source 1: ExchangeRate-API open endpoint. Source 2: community currency-api on the jsDelivr CDN.
    return getJSON('https://open.er-api.com/v6/latest/USD').then(function (j) {
      if (!j || j.result !== 'success' || !j.rates) throw new Error('bad shape');
      var at = new Date((j.time_last_update_unix || Date.now() / 1000) * 1000);
      if (!applyRates(j.rates, at, 'ExchangeRate-API', false)) throw new Error('no rates');
      store('audtix.fx.v2', { rates: j.rates, at: at.getTime(), src: 'ExchangeRate-API' });
    }).catch(function () {
      return getJSON('https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json').then(function (j) {
        if (!j || !j.usd) throw new Error('bad shape');
        var rates = {}; Object.keys(j.usd).forEach(function (k) { rates[k.toUpperCase()] = j.usd[k]; });
        var at = j.date ? new Date(j.date + 'T00:00:00Z') : new Date();
        if (!applyRates(rates, at, 'currency-api (jsDelivr)', false)) throw new Error('no rates');
        store('audtix.fx.v2', { rates: rates, at: at.getTime(), src: 'currency-api (jsDelivr)' });
      });
    }).catch(function () {
      var c = recall('audtix.fx.v2');
      if (c && c.rates && Date.now() - c.at < 3 * DAY && applyRates(c.rates, new Date(c.at), c.src, true)) return;
      LIVE.ratesOk = false;
    });
  }

  function loadHistory() {
    var offs = [7, 30, 90], out = {}, srcOk = '';
    return Promise.all(offs.map(function (n) {
      var day = isoDay(new Date(Date.now() - n * DAY));
      var one = 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@' + day + '/v1/currencies/usd.json';
      var two = 'https://' + day + '.currency-api.pages.dev/v1/currencies/usd.json';
      return getJSON(one).catch(function () { return getJSON(two); }).then(function (j) {
        if (!j || !j.usd) throw new Error('bad shape');
        var m = {}; Object.keys(j.usd).forEach(function (k) { m[k.toUpperCase()] = j.usd[k]; });
        out[n] = { rates: m, day: j.date || day }; srcOk = 'currency-api (jsDelivr)';
      }).catch(function () { /* that day is missing */ });
    })).then(function () {
      LIVE.hist = Object.keys(out).length ? out : null; LIVE.histSrc = srcOk;
    });
  }
  function pairThen(a, b, n) {
    var h = LIVE.hist && LIVE.hist[n]; if (!h) return null;
    var ra = a === 'USD' ? 1 : h.rates[a], rb = b === 'USD' ? 1 : h.rates[b];
    return ra && rb ? rb / ra : null;
  }
  function pct(now, then) { return then ? ((now - then) / then) * 100 : null; }
  function sgn(p) { return (p >= 0 ? '+' : '−') + fmt(Math.abs(p), 2) + '%'; }

  function googleNewsRss(q) { return 'https://news.google.com/rss/search?q=' + encodeURIComponent(q + ' when:14d') + '&hl=en-UG&gl=UG&ceid=UG:en'; }
  function newsQueryFor(a, b) {
    var has = function (c) { return a === c || b === c; };
    if (has('UGX')) return has('USD') || (a !== 'UGX' && b !== 'UGX') ? 'Uganda shilling dollar exchange rate' : 'Uganda shilling ' + (a === 'UGX' ? b : a);
    return a + ' ' + b + ' forex';
  }
  function trusted(src) { src = (src || '').toLowerCase(); return TRUSTED.some(function (t) { return src.indexOf(t) > -1; }); }
  function normaliseNews(items) {
    var seen = {}, cutoff = Date.now() - 21 * DAY;
    return items.map(function (x) {
      var title = String(x.title || ''), src = x.source || '', m = title.match(/^(.*) - ([^-]{2,60})$/);
      if (!src && m) { title = m[1]; src = m[2]; } else if (m && src && m[2].trim() === src.trim()) { title = m[1]; }
      var d = x.publishedAt ? new Date(x.publishedAt) : (x.pubDate ? new Date(String(x.pubDate).replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(x.pubDate) ? '' : 'Z')) : null);
      return { title: title.trim(), src: src.trim(), url: x.url || x.link || '', date: d };
    }).filter(function (n) {
      if (!n.title || !n.url || !n.date || isNaN(n.date.getTime()) || n.date.getTime() < cutoff || n.date.getTime() > Date.now() + DAY) return false;
      if (!trusted(n.src)) return false;
      var k = n.title.toLowerCase().slice(0, 60); if (seen[k]) return false; seen[k] = 1; return true;
    }).sort(function (a, b) { return b.date - a.date; }).slice(0, 6);
  }
  function loadNews(q) {
    var c = LIVE.news[q];
    if (c && Date.now() - c.at < 10 * 60000) return Promise.resolve();
    var p;
    if (CFG.newsEndpoint) p = getJSON(CFG.newsEndpoint + (CFG.newsEndpoint.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(q), 12000).then(function (j) { return j.items || []; });
    else p = getJSON('https://api.rss2json.com/v1/api.json?rss_url=' + encodeURIComponent(googleNewsRss(q)), 12000).then(function (j) { if (!j || j.status !== 'ok') throw new Error('bad'); return j.items || []; });
    return p.then(function (items) { LIVE.news[q] = { at: Date.now(), items: normaliseNews(items) }; delete LIVE.newsErr[q]; store('audtix.news.v2.' + q, { at: Date.now(), items: LIVE.news[q].items.map(function (n) { return { title: n.title, source: n.src, url: n.url, publishedAt: n.date.toISOString() }; }) }); })
      .catch(function () {
        var r = recall('audtix.news.v2.' + q);
        if (r && Date.now() - r.at < 6 * 3600000) { LIVE.news[q] = { at: r.at - 9 * 60000, items: normaliseNews(r.items), cached: true }; }
        LIVE.newsErr[q] = true;
      });
  }

  /* Insights computed from the live numbers, never written by hand. */
  function liveInsights(a, b) {
    var out = [];
    if (!LIVE.ratesOk) return out;
    var now = rateFor(a, b), tag = a + '/' + b;
    var src = 'Based on ' + LIVE.ratesSrc + ' rates' + (LIVE.hist ? ' and ' + LIVE.histSrc + ' history' : '') + '.';
    if (LIVE.hist) {
      var parts = [7, 30, 90].map(function (n) { var t = pairThen(a, b, n); return t ? { n: n, p: pct(now, t), t: t } : null; }).filter(Boolean);
      if (parts.length) {
        out.push({ tag: 'Trend', cur: [a, b], t: tag + ' is ' + fmtRate(now) + ' now. ' + parts.map(function (x) { return sgn(x.p) + ' over ' + x.n + ' days (from ' + fmtRate(x.t) + ')'; }).join(', ') + '. ' + src });
        var p30 = (parts.filter(function (x) { return x.n === 30; })[0] || parts[parts.length - 1]);
        if (b === 'UGX' || a === 'UGX') {
          var strong = (b === 'UGX') ? p30.p < 0 : p30.p > 0;
          var other = a === 'UGX' ? b : a;
          out.push({ tag: 'Uganda', cur: ['UGX'], t: 'Over ' + p30.n + ' days the shilling has ' + (strong ? 'strengthened' : 'weakened') + ' against the ' + (other === 'USD' ? 'US dollar' : other) + ' by about ' + fmt(Math.abs(p30.p), 1) + '%. ' + (strong ? 'Imports priced in ' + other + ' cost fewer shillings than a month ago, while exporters receive fewer shillings per unit sold.' : 'Imports priced in ' + other + ' cost more shillings than a month ago. Exporters receive more shillings per unit sold. Agree prices and payment dates with this in mind.') + ' ' + src });
        }
      }
      var peers = ['UGX', 'KES', 'TZS', 'RWF'].map(function (c) { var t = pairThen('USD', c, 30); return t ? { c: c, p: pct(CUR[c].r, t) } : null; }).filter(Boolean);
      if (peers.length >= 3) {
        out.push({ tag: 'Region', cur: ['UGX', 'KES', 'TZS', 'RWF'], t: 'Against the US dollar over 30 days: ' + peers.map(function (x) { return x.c + ' ' + sgn(x.p); }).join(', ') + '. A positive figure means the dollar bought more of that currency. ' + src });
      }
      var movers = ORDER.filter(function (c) { return c !== 'USD' && c !== 'AED' && pairThen('USD', c, 30); }).map(function (c) { return { c: c, p: pct(CUR[c].r, pairThen('USD', c, 30)) }; }).sort(function (x, y) { return x.p - y.p; });
      if (movers.length >= 4) {
        out.push({ tag: 'Movers', cur: [], t: 'Of the currencies tracked here, the ' + movers[0].c + ' gained the most against the dollar over 30 days (' + sgn(-movers[0].p) + ' in dollar terms), and the ' + movers[movers.length - 1].c + ' lost the most (' + sgn(-movers[movers.length - 1].p) + '). ' + src });
      }
    }
    return out;
  }

  /* ----- Converter + forex desk ----- */
  var deskPair = ['USD', 'UGX'];
  var tipIdx = 0, tipTimer = null, tipPaused = false;

  function statusText() {
    if (LIVE.ratesOk) {
      return (LIVE.cached ? 'Last saved live rates' : 'Live rates') + ' from ' + LIVE.ratesSrc + '. Source updated ' + shortDT(LIVE.ratesAt) + (LIVE.cached ? '. Reconnecting…' : '. Checked ' + ago(LIVE.checkedAt || LIVE.ratesAt) + '.');
    }
    return 'Live rates could not be loaded. Showing saved reference rates dated ' + niceDate(REF_DATE) + '. They are not current.';
  }
  function renderClock() {
    var d = new Date();
    if ($('d-today')) $('d-today').textContent = 'Today · ' + longDate(d);
    if ($('t-today')) $('t-today').textContent = longDate(d) + ' · ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    if ($('d-risk-date')) $('d-risk-date').textContent = longDate(d);
    if ($('c-live')) { $('c-live').textContent = statusText(); $('c-live').className = 'live-status ' + (LIVE.ratesOk && !LIVE.cached ? 'on' : 'off'); }
    if ($('d-live')) { $('d-live').textContent = statusText(); $('d-live').className = 'live-status ' + (LIVE.ratesOk && !LIVE.cached ? 'on' : 'off'); }
  }

  function fillSelect(id, def) {
    var s = $(id);
    s.innerHTML = ORDER.map(function (c) { return '<option value="' + c + '">' + c + ' · ' + CUR[c].name + '</option>'; }).join('');
    s.value = def;
  }
  function renderConvert() {
    var amt = val('c-amount'), from = $('c-from').value, to = $('c-to').value;
    var own = val('c-own');
    var rate = own || rateFor(from, to);
    var out = $('out-conv');
    var stamp = own ? 'Using your rate' : (LIVE.ratesOk ? (LIVE.cached ? 'Last saved live rate, ' + shortDT(LIVE.ratesAt) : 'Live rate, source updated ' + shortDT(LIVE.ratesAt)) : 'Saved reference rate dated ' + niceDate(REF_DATE) + ', not live');
    if (!amt) {
      out.innerHTML = '<p class="empty">Enter an amount to convert.</p>';
    } else {
      var res = amt * rate;
      out.innerHTML = '<div class="big-label">' + fmt(amt, amt % 1 ? 2 : 0) + ' ' + from + ' is</div><div class="big">' + fmt(res, CUR[to].dec) + ' ' + to + '</div>' +
        rows([['1 ' + from, fmtRate(rate) + ' ' + to], ['1 ' + to, fmtRate(1 / rate) + ' ' + from], ['Rate used', stamp]]);
    }
    var also = ['UGX', 'USD', 'EUR', 'GBP', 'KES', 'TZS', 'RWF'].filter(function (c) { return c !== from; });
    $('c-also').innerHTML = also.map(function (c) {
      return '<div><span>' + c + '</span><b>' + (amt ? fmt(convert(amt, from, c), CUR[c].dec) : '–') + '</b></div>';
    }).join('');
    $('c-news').href = 'https://news.google.com/search?hl=en&q=' + encodeURIComponent(from + ' ' + to + ' forex');
    $('c-news').textContent = 'Search the latest ' + from + '/' + to + ' news ↗';
    if (deskPair[0] !== from || deskPair[1] !== to) { deskPair = [from, to]; renderDesk(true); loadDeskNews(); }
  }

  function renderDesk(pairChanged) {
    var a = deskPair[0], b = deskPair[1];
    var r = rateFor(a, b);
    $('d-pair').textContent = a + ' / ' + b;
    $('d-rate').textContent = fmtRate(r);
    $('d-inv').textContent = '1 ' + b + ' = ' + fmtRate(1 / r) + ' ' + a;
    var box = $('d-range'), chg = '';
    if (LIVE.ratesOk && LIVE.hist) {
      [7, 30, 90].forEach(function (n) {
        var t = pairThen(a, b, n); if (!t) return;
        var p = pct(r, t);
        chg += '<div class="rg"><div class="rg-h"><span>' + n + ' days ago</span><span>' + fmtRate(t) + '</span></div><div class="rg-l"><span>Change to now</span><span class="' + (p >= 0 ? 'up' : 'down') + '">' + sgn(p) + '</span></div></div>';
      });
    }
    if (chg) { box.innerHTML = chg; box.hidden = false; } else { box.hidden = true; box.innerHTML = ''; }
    var ins = liveInsights(a, b), notes = ins.slice(0, 1).map(function (x) { return x.t; });
    if (!notes.length) notes.push(LIVE.ratesOk ? 'Price history for this pair is not available right now, so no trend is shown.' : 'Live data is not available right now, so no market commentary is shown. Use the official sources below.');
    $('d-notes').innerHTML = notes.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('');
    $('d-search').href = 'https://news.google.com/search?hl=en&q=' + encodeURIComponent(a + ' ' + b + ' forex');
    $('d-search').textContent = 'Search live ' + a + '/' + b + ' news ↗';
    TIPS = ins.slice(1).concat(EVERGREEN);
    if (pairChanged || tipIdx >= TIPS.length) setTip(0); else setTip(tipIdx);
    renderQuick();
  }
  function renderQuick() {
    var q = [['USD', 'UGX'], ['EUR', 'UGX'], ['GBP', 'UGX'], ['KES', 'UGX']];
    $('d-quick').innerHTML = q.map(function (p) {
      return '<div><span>' + p[0] + '/' + p[1] + '</span><b>' + fmt(rateFor(p[0], p[1]), 2) + '</b></div>';
    }).join('');
    $('rate-table').innerHTML = ORDER.map(function (c) {
      var when = CUR[c].live ? shortDT(LIVE.ratesAt) : (c === 'AED' ? 'pegged' : niceDate(REF_DATE) + ' (saved)');
      return '<tr><td>' + c + '</td><td>' + CUR[c].name + '</td><td class="n">' + fmtRate(CUR[c].r) + '</td><td>' + when + '</td></tr>';
    }).join('');
  }

  function setTip(i) {
    tipIdx = (i + TIPS.length) % TIPS.length;
    $('d-tip-tag').textContent = TIPS[tipIdx].tag;
    $('d-tip').textContent = TIPS[tipIdx].t;
    $('d-tip-n').textContent = (tipIdx + 1) + ' of ' + TIPS.length;
  }
  function startTips() {
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) return;
    tipTimer = setInterval(function () { if (!tipPaused && !document.hidden) setTip(tipIdx + 1); }, 11000);
  }

  var OFFICIAL = [['Bank of Uganda: exchange rates', 'https://www.bou.or.ug/bou/bouwebsite/FinancialMarkets/'], ['Federal Reserve: news and events', 'https://www.federalreserve.gov/newsevents.htm'], ['European Central Bank: press', 'https://www.ecb.europa.eu/press/html/index.en.html'], ['Bank of England: news', 'https://www.bankofengland.co.uk/news']];
  function renderNews() {
    var q = LIVE.newsQuery, n = LIVE.news[q], html = '';
    if (!n && !LIVE.newsErr[q]) html = '<p class="empty-n">Loading the latest headlines…</p>';
    else if (!n || !n.items.length) html = '<p class="empty-n">' + (LIVE.newsErr[q] ? 'Live headlines could not be loaded right now.' : 'No recent headlines from trusted publishers for this pair.') + ' Use the search link or the official sources below.</p>';
    else {
      html = '<h4 class="ng">Latest, from trusted publishers' + (n.cached ? ' (saved copy)' : '') + '</h4>' + n.items.map(function (x) {
        return '<a class="nitem" href="' + esc(safeUrl(x.url)) + '" target="_blank" rel="noopener noreferrer"><b>' + esc(x.title) + '</b><em>' + esc(x.src) + ' · ' + ago(x.date) + ' · ' + x.date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) + ' ↗</em></a>';
      }).join('');
    }
    html += '<h4 class="ng">Official sources</h4><div class="offl">' + OFFICIAL.map(function (o) { return '<a href="' + o[1] + '" target="_blank" rel="noopener noreferrer">' + o[0] + ' ↗</a>'; }).join('') + '</div>';
    $('d-news').innerHTML = html;
  }
  function loadDeskNews() {
    var q = newsQueryFor(deskPair[0], deskPair[1]); LIVE.newsQuery = q; renderNews();
    return loadNews(q).then(function () { if (LIVE.newsQuery === q) renderNews(); });
  }

  function refreshAll() { LIVE.checkedAt = new Date(); renderConvert(); renderDesk(false); renderClock(); }
  function refreshLive(force) {
    if (!force && LIVE.checkedAt && Date.now() - LIVE.checkedAt.getTime() < 4 * 60000) return Promise.resolve();
    return loadRates().then(function () { refreshAll(); return loadHistory(); }).then(function () { refreshAll(); });
  }


  /* ----- Financial insights centre (Uganda and East Africa institutions) ----- */
  var FS = (typeof window !== 'undefined' && window.FIN_SOURCES) || { groups: [], sources: [], countries: [] };
  var HUB = { items: [], health: null, at: null, ok: false, cached: false, group: 'all', country: 'all', shown: 12, tried: false };
  var GROUP_NAME = {}; FS.groups.forEach(function (g) { GROUP_NAME[g[0]] = g[1]; });
  var COUNTRY_NAME = {}; FS.countries.forEach(function (c) { COUNTRY_NAME[c[0]] = c[1]; });
  var SRC_BY_ID = {}; FS.sources.forEach(function (s) { SRC_BY_ID[s.id] = s; });

  function matchSource(name) {
    name = (name || '').toLowerCase().trim(); if (!name) return null;
    for (var i = 0; i < FS.sources.length; i++) {
      var s = FS.sources[i];
      if (s.name.toLowerCase() === name) return s;
      for (var j = 0; j < s.aliases.length; j++) if (name === s.aliases[j] || (s.aliases[j].length > 5 && name.indexOf(s.aliases[j]) > -1)) return s;
    }
    return null;
  }
  function toHubItems(raw, viaSearch) {
    var cutoff = Date.now() - 120 * DAY, seen = {};
    return raw.map(function (x) {
      var s = x.sourceId ? SRC_BY_ID[x.sourceId] : null, title = String(x.title || ''), src = x.source || '';
      if (!s && viaSearch) { var m = title.match(/^(.*) - ([^-]{2,80})$/); if (m) { title = m[1]; src = m[2]; } s = matchSource(src); }
      var d = x.publishedAt ? new Date(x.publishedAt) : (x.pubDate ? new Date(String(x.pubDate).replace(' ', 'T') + 'Z') : null);
      return s && d && !isNaN(d.getTime()) ? { s: s, title: title.trim(), url: x.url || x.link || '', summary: (x.summary || '').trim(), date: d } : null;
    }).filter(function (n) {
      if (!n || !n.title || !n.url || n.date.getTime() < cutoff || n.date.getTime() > Date.now() + DAY) return false;
      var k = n.title.toLowerCase().slice(0, 70); if (seen[k]) return false; seen[k] = 1; return true;
    }).sort(function (a, b) { return b.date - a.date; });
  }
  function loadHub() {
    var p;
    if (CFG.updatesEndpoint) {
      p = getJSON(CFG.updatesEndpoint, 25000).then(function (j) { HUB.health = j.sources || null; return toHubItems(j.items || [], false); });
    } else {
      // No backend yet: one public news search limited to the main Ugandan institutions' own websites.
      var hosts = FS.sources.filter(function (s) { return s.country === 'UG'; }).slice(0, 10).map(function (s) { return 'site:' + s.host; });
      var q = '(' + hosts.join(' OR ') + ')';
      p = getJSON('https://api.rss2json.com/v1/api.json?rss_url=' + encodeURIComponent(googleNewsRss(q).replace('when%3A14d', 'when%3A60d')), 15000).then(function (j) {
        if (!j || j.status !== 'ok') throw new Error('bad'); return toHubItems(j.items || [], true);
      });
    }
    return p.then(function (items) {
      if (!items.length) throw new Error('empty');
      HUB.items = items; HUB.at = new Date(); HUB.ok = true; HUB.cached = false;
      store('audtix.hub.v1', { at: HUB.at.getTime(), items: items.map(function (n) { return { sourceId: n.s.id, title: n.title, url: n.url, summary: n.summary, publishedAt: n.date.toISOString() }; }) });
    }).catch(function () {
      var c = recall('audtix.hub.v1');
      if (c && c.items && Date.now() - c.at < 3 * DAY) { HUB.items = toHubItems(c.items, false); HUB.at = new Date(c.at); HUB.ok = HUB.items.length > 0; HUB.cached = true; }
      else { HUB.ok = false; }
    }).then(function () { HUB.tried = true; renderHub(); });
  }
  function hubVisible() {
    return HUB.items.filter(function (n) { return (HUB.group === 'all' || n.s.group === HUB.group) && (HUB.country === 'all' || n.s.country === HUB.country); });
  }
  function renderHub() {
    var st = $('hub-status'); if (!st) return;
    var vis = hubVisible();
    if (HUB.ok) {
      var srcN = {}; HUB.items.forEach(function (n) { srcN[n.s.id] = 1; });
      st.textContent = (HUB.cached ? 'Saved copy from ' + shortDT(HUB.at) + '. Reconnecting…' : 'Updated ' + shortDT(HUB.at) + ', checked ' + ago(HUB.at) + '.') + ' ' + HUB.items.length + ' recent posts from ' + Object.keys(srcN).length + ' of ' + FS.sources.length + ' institutions.';
      st.className = 'live-status ' + (HUB.cached ? 'off' : 'on');
    } else if (HUB.tried) {
      st.textContent = 'Updates could not be loaded right now. Open the institutions directly from the list below.'; st.className = 'live-status off';
      $('hub-src-wrap').open = true;
    }
    var chips = '<button type="button" class="fc' + (HUB.group === 'all' ? ' on' : '') + '" data-g="all">All topics</button>' + FS.groups.map(function (g) {
      var n = HUB.items.filter(function (x) { return x.s.group === g[0] && (HUB.country === 'all' || x.s.country === HUB.country); }).length;
      return '<button type="button" class="fc' + (HUB.group === g[0] ? ' on' : '') + '" data-g="' + g[0] + '">' + esc(g[1]) + (HUB.ok ? ' <i>' + n + '</i>' : '') + '</button>';
    }).join('');
    $('hub-chips').innerHTML = chips;
    var grid = $('hub-grid');
    if (!HUB.ok) grid.innerHTML = HUB.tried ? '' : '<p class="empty-n dark">Loading the latest updates…</p>';
    else if (!vis.length) grid.innerHTML = '<p class="empty-n dark">No recent posts for this filter. Try another topic or country.</p>';
    else grid.innerHTML = vis.slice(0, HUB.shown).map(function (n) {
      return '<a class="hcard" href="' + esc(safeUrl(n.url)) + '" target="_blank" rel="noopener noreferrer"><span class="hs">' + esc(n.s.short) + ' · ' + esc(COUNTRY_NAME[n.s.country] || n.s.country) + '</span><b>' + esc(n.title) + '</b>' +
        (n.summary ? '<p>' + esc(n.summary.length > 150 ? n.summary.slice(0, 147) + '…' : n.summary) + '</p>' : '') +
        '<em>' + esc(GROUP_NAME[n.s.group] || '') + ' · ' + ago(n.date) + ' · ' + n.date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) + ' ↗</em></a>';
    }).join('');
    $('hub-more').hidden = !(HUB.ok && vis.length > HUB.shown);
    // Sidebar: three newest, Uganda first
    var side = $('d-official');
    if (side) {
      var top = HUB.items.filter(function (n) { return n.s.country === 'UG'; }).concat(HUB.items.filter(function (n) { return n.s.country !== 'UG'; })).slice(0, 3);
      side.innerHTML = !HUB.ok ? '<p class="empty-n">' + (HUB.tried ? 'Not available right now.' : 'Loading…') + '</p>' : top.map(function (n) {
        return '<a class="nitem" href="' + esc(safeUrl(n.url)) + '" target="_blank" rel="noopener noreferrer"><b>' + esc(n.title) + '</b><em>' + esc(n.s.short) + ' · ' + ago(n.date) + ' ↗</em></a>';
      }).join('');
    }
  }
  function initHub() {
    if (!$('hub-grid')) return;
    $('hub-sources').innerHTML = FS.sources.map(function (s) {
      return '<li><a href="' + esc(safeUrl(s.site)) + '" target="_blank" rel="noopener noreferrer">' + esc(s.name) + ' ↗</a><span>' + esc(COUNTRY_NAME[s.country] || s.country) + ' · ' + esc(GROUP_NAME[s.group] || '') + '</span></li>';
    }).join('');
    var sel = $('hub-country');
    FS.countries.forEach(function (c) { var o = document.createElement('option'); o.value = c[0]; o.textContent = c[1]; sel.appendChild(o); });
    sel.addEventListener('change', function () { HUB.country = sel.value; HUB.shown = 12; renderHub(); });
    $('hub-chips').addEventListener('click', function (e) { var b = e.target.closest('button[data-g]'); if (!b) return; HUB.group = b.getAttribute('data-g'); HUB.shown = 12; renderHub(); });
    $('hub-more').addEventListener('click', function () { HUB.shown += 12; renderHub(); });
    renderHub(); loadHub();
    setInterval(function () { if (!document.hidden) loadHub(); }, 10 * 60000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden && HUB.at && Date.now() - HUB.at.getTime() > 10 * 60000) loadHub(); });
  }

  /* ----- Tabs ----- */
  var TABS = ['paye', 'vat', 'return', 'convert'];
  function showTab(name, focus) {
    if (TABS.indexOf(name) < 0) name = 'paye';
    TABS.forEach(function (n) {
      var on = n === name, b = $('tab-' + n);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.tabIndex = on ? 0 : -1;
      $('panel-' + n).hidden = !on;
    });
    if (focus) $('tab-' + name).focus();
  }
  function goTab(name, focus) {
    showTab(name, focus);
    try { history.replaceState(null, '', '#' + name); } catch (e) { try { location.hash = name; } catch (e2) { /* ignore */ } }
  }

  function init() {
    fillSelect('c-from', 'USD'); fillSelect('c-to', 'UGX');
    ['p-gross', 'v-amount', 'v-sales', 'v-purch', 'r-rev', 'r-exp', 'r-ded', 'r-loss', 'r-paid'].forEach(function (id) { moneyInput($(id), false); });
    moneyInput($('c-amount'), true); moneyInput($('c-own'), true);

    ['p-gross', 'p-res', 'p-nssf'].forEach(function (id) { $(id).addEventListener('input', renderPaye); $(id).addEventListener('change', renderPaye); });
    ['v-amount', 'v-rate', 'v-sales', 'v-purch'].forEach(function (id) { $(id).addEventListener('input', renderVat); $(id).addEventListener('change', renderVat); });
    Array.prototype.forEach.call(document.querySelectorAll('input[name="v-mode"]'), function (el) { el.addEventListener('change', renderVat); });
    ['r-kind', 'r-rev', 'r-exp', 'r-ded', 'r-loss', 'r-paid'].forEach(function (id) { $(id).addEventListener('input', renderReturn); $(id).addEventListener('change', renderReturn); });
    ['c-amount', 'c-from', 'c-to', 'c-own'].forEach(function (id) { $(id).addEventListener('input', renderConvert); $(id).addEventListener('change', renderConvert); });
    $('c-swap').addEventListener('click', function () {
      var f = $('c-from').value; $('c-from').value = $('c-to').value; $('c-to').value = f; $('c-own').value = ''; renderConvert();
    });

    TABS.forEach(function (n, i) {
      var b = $('tab-' + n);
      b.addEventListener('click', function () { goTab(n, false); });
      b.addEventListener('keydown', function (e) {
        var k = e.key, j = -1;
        if (k === 'ArrowRight') j = (i + 1) % TABS.length;
        else if (k === 'ArrowLeft') j = (i + TABS.length - 1) % TABS.length;
        else if (k === 'Home') j = 0;
        else if (k === 'End') j = TABS.length - 1;
        if (j > -1) { e.preventDefault(); goTab(TABS[j], true); }
      });
    });
    window.addEventListener('hashchange', function () { showTab(location.hash.replace('#', '')); });

    $('d-prev').addEventListener('click', function () { tipPaused = true; setTip(tipIdx - 1); });
    $('d-next').addEventListener('click', function () { tipPaused = true; setTip(tipIdx + 1); });
    var tb = $('d-tipbox');
    tb.addEventListener('mouseenter', function () { tipPaused = true; });
    tb.addEventListener('mouseleave', function () { tipPaused = false; });

    renderPaye(); renderVat(); renderReturn();
    renderConvert();
    deskPair = ['USD', 'UGX']; renderDesk(false);
    renderClock(); setInterval(renderClock, 30000);
    setTip(0);
    startTips();
    loadDeskNews();
    initHub();
    refreshLive(true);
    setInterval(function () { if (!document.hidden) { refreshLive(false); loadDeskNews(); } }, 5 * 60000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { renderClock(); refreshLive(false); loadDeskNews(); } });
    var rb = $('d-retry'); if (rb) rb.addEventListener('click', function () { LIVE.news = {}; LIVE.newsErr = {}; loadDeskNews(); refreshLive(true); });
    showTab(location.hash.replace('#', ''));
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})(typeof window !== 'undefined' ? window : globalThis);
