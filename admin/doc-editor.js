/* Document editor: customer picker, price-list search, live totals, receipt invoice picker.
   Totals shown here are only a preview. The server recalculates everything when saving. */
(function () {
  var form = document.getElementById("docform"); if (!form) return;
  var data = JSON.parse(document.getElementById("doc-data").textContent);
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }
  function getJSON(url, cb) { fetch(url, { credentials: "same-origin" }).then(function (r) { return r.json(); }).then(cb).catch(function () { cb({ rows: [] }); }); }

  /* ---- customer ---- */
  var cq = $("#cust-q"), cac = $("#cust-ac"), ccard = $("#cust-card"), cid = $("#customer_id");
  function showCustomer(c) {
    cid.value = c ? c.id : 0;
    if (!c) { ccard.hidden = true; cq.hidden = false; cq.closest('label').hidden = false; cq.value = ""; cq.focus(); return; }
    ccard.hidden = false; cq.hidden = true; cq.closest('label').hidden = true; cac.hidden = true;
    ccard.innerHTML = (c.logo ? '<img src="' + esc(c.logo) + '" alt="">' : "") + '<div><b>' + esc(c.name) + "</b>" +
      [c.contact ? "Attn: " + c.contact : "", c.address, c.phone, c.email, c.tin ? "TIN: " + c.tin : ""].filter(Boolean).map(function (x) { return "<span>" + esc(x) + "</span>"; }).join("") +
      '</div><button type="button" class="btn sm ghost" data-change>Change</button>';
    $("[data-change]", ccard).addEventListener("click", function () { showCustomer(null); loadInvoices(); });
    loadInvoices();
  }
  if (cq) cq.addEventListener("input", debounce(function () {
    var q = cq.value.trim(); if (q.length < 1) { cac.hidden = true; return; }
    getJSON("lookup.php?kind=customer&q=" + encodeURIComponent(q), function (j) {
      cac.innerHTML = j.rows.length ? "" : '<div class="none">No customer found</div>';
      j.rows.forEach(function (c) { var b = document.createElement("button"); b.type = "button"; b.innerHTML = "<b>" + esc(c.name) + "</b><small>" + esc([c.tin, c.phone, c.email].filter(Boolean).join(" · ")) + "</small>"; b.addEventListener("click", function () { showCustomer(c); }); cac.appendChild(b); });
      cac.hidden = false;
    });
  }, 220));
  if (data.customer) showCustomer(data.customer);

  /* ---- receipt: open invoices for the chosen customer ---- */
  var inv = $("#inv-sel"), amount = $("#amount"), hint = $("#bal-hint"), invRows = [];
  function loadInvoices() {
    if (!inv) return;
    var c = parseInt(cid.value, 10) || 0;
    inv.innerHTML = '<option value="0">- none / other transaction -</option>'; hint.textContent = "";
    if (!c) return;
    getJSON("lookup.php?kind=invoice&customer_id=" + c + "&include=" + (data.parent_id || 0) + "&receipt=" + (data.doc_id || 0), function (j) {
      invRows = j.rows;
      j.rows.forEach(function (r) { var o = document.createElement("option"); o.value = r.id; o.textContent = r.no + " · " + r.date + " · balance " + r.balance_fmt + " " + r.currency; if (r.id === data.parent_id) o.selected = true; inv.appendChild(o); });
      if (data.parent_id && !inv.value || data.parent_id && inv.value === "0") { /* the invoice may already be fully paid by this receipt (editing): keep amount as is */ }
      onInv(false);
    });
  }
  function onInv(fill) {
    var r = invRows.filter(function (x) { return String(x.id) === inv.value; })[0];
    hint.textContent = r ? "Balance on this invoice: " + r.balance_fmt + " " + r.currency : "";
    if (r) { var cur = $("#currency"); if (cur) cur.value = r.currency; if (fill && !amount.value) amount.value = r.balance; else if (fill) amount.value = r.balance; }
  }
  if (inv) { inv.addEventListener("change", function () { onInv(true); }); }

  /* ---- lines ---- */
  var box = $("#lines"), idx = 0;
  function num(v) { v = String(v == null ? "" : v).replace(/,/g, "").trim(); return v === "" ? 0 : parseFloat(v); }
  function cents(v) { return Math.round(num(v) * 100); }
  function fmt(c) { var cur = ($("#currency") || {}).value || "UGX"; var neg = c < 0; c = Math.abs(c); var w = Math.floor(c / 100), f = c % 100; var s = String(w).replace(/\B(?=(\d{3})+(?!\d))/g, ","); if (!(cur === "UGX" && f === 0)) s += "." + (f < 10 ? "0" : "") + f; return (neg ? "-" : "") + s; }
  function calcRow(row) {
    var qm = Math.round(num($('[data-k="quantity"]', row).value) * 1000), pc = cents($('[data-k="unit_price"]', row).value), dc = cents($('[data-k="discount"]', row).value), bp = Math.round(num($('[data-k="tax_rate"]', row).value) * 100);
    var gross = Math.floor((qm * pc + 500) / 1000), net = gross - dc, tax = Math.floor((net * bp + 5000) / 10000);
    return { gross: gross, disc: dc, tax: tax, total: net + tax };
  }
  function recalc() {
    if (!box) return; var s = 0, d = 0, t = 0, tot = 0;
    $$(".lrow", box).forEach(function (row) {
      var desc = $('[data-k="description"]', row).value.trim(), priced = $('[data-k="unit_price"]', row).value.trim() !== "";
      var r = calcRow(row); var out = $(".lt", row);
      if (!desc && !priced) { out.textContent = ""; return; }
      out.textContent = fmt(r.total); s += r.gross; d += r.disc; t += r.tax; tot += r.total;
    });
    $("#t-sub").textContent = fmt(s); $("#t-disc").textContent = fmt(d); $("#t-tax").textContent = fmt(t); $("#t-total").textContent = fmt(tot);
  }
  function addRow(l) {
    l = l || {}; var i = idx++, row = document.createElement("div"); row.className = "lrow";
    row.innerHTML =
      '<div class="f d"><input type="hidden" data-k="item_id" name="lines[' + i + '][item_id]" value="' + esc(l.item_id || "") + '"><textarea data-k="description" name="lines[' + i + '][description]" rows="1" placeholder="Search the price list or type a description">' + esc(l.description || "") + '</textarea><div class="ac" hidden></div></div>' +
      '<div class="f" data-l="Qty"><input data-k="quantity" placeholder="Qty" name="lines[' + i + '][quantity]" inputmode="decimal" value="' + esc(l.quantity == null ? "1" : l.quantity) + '" aria-label="Quantity"></div>' +
      '<div class="f" data-l="Unit"><input data-k="unit" placeholder="Unit" name="lines[' + i + '][unit]" value="' + esc(l.unit || "") + '" maxlength="30" aria-label="Unit"></div>' +
      '<div class="f" data-l="Unit price"><input data-k="unit_price" placeholder="Price" name="lines[' + i + '][unit_price]" inputmode="decimal" value="' + esc(l.unit_price == null ? "" : l.unit_price) + '" aria-label="Unit price"></div>' +
      '<div class="f" data-l="Discount"><input data-k="discount" placeholder="Discount" name="lines[' + i + '][discount]" inputmode="decimal" value="' + esc(l.discount == null ? "0" : l.discount) + '" aria-label="Discount"></div>' +
      '<div class="f" data-l="Tax %"><input data-k="tax_rate" placeholder="Tax %" name="lines[' + i + '][tax_rate]" inputmode="decimal" value="' + esc(l.tax_rate == null ? "0" : l.tax_rate) + '" aria-label="Tax percent"></div>' +
      '<div class="f lt" data-l="Total" aria-label="Line total"></div><button type="button" class="x" aria-label="Remove line">&times;</button>';
    box.appendChild(row);
    var ta = $('[data-k="description"]', row), ac = $(".ac", row);
    function fit() { ta.style.height = "auto"; ta.style.height = ta.scrollHeight + "px"; } fit();
    ta.addEventListener("input", function () { fit(); recalc(); });
    ta.addEventListener("input", debounce(function () {
      var q = ta.value.trim(); if (q.length < 2) { ac.hidden = true; return; }
      getJSON("lookup.php?kind=item&q=" + encodeURIComponent(q), function (j) {
        if (!j.rows.length) { ac.hidden = true; return; } ac.innerHTML = "";
        j.rows.forEach(function (it) {
          var b = document.createElement("button"); b.type = "button"; b.innerHTML = "<b>" + esc(it.name) + "</b><small>" + esc((it.category ? it.category + " · " : "") + (it.unit ? it.unit + " · " : "") + it.price) + "</small>";
          b.addEventListener("mousedown", function (e) { e.preventDefault(); });
          b.addEventListener("click", function () {
            ta.value = it.description; $('[data-k="item_id"]', row).value = it.id; $('[data-k="unit"]', row).value = it.unit || ""; $('[data-k="unit_price"]', row).value = it.price; $('[data-k="tax_rate"]', row).value = it.tax || "0";
            ac.hidden = true; fit(); recalc();
          }); ac.appendChild(b);
        }); ac.hidden = false;
      });
    }, 250));
    ta.addEventListener("blur", function () { setTimeout(function () { ac.hidden = true; }, 150); });
    $$("input", row).forEach(function (x) { x.addEventListener("input", recalc); });
    $(".x", row).addEventListener("click", function () { if ($$(".lrow", box).length > 1) { row.remove(); recalc(); } else { $$("input,textarea", row).forEach(function (x) { if (x.dataset.k === "quantity") x.value = "1"; else if (x.dataset.k === "discount" || x.dataset.k === "tax_rate") x.value = "0"; else x.value = ""; }); recalc(); } });
    return row;
  }
  if (box) {
    (data.lines.length ? data.lines : [{}]).forEach(addRow); recalc();
    $("#add-line").addEventListener("click", function () { addRow().querySelector("textarea").focus(); });
    $("#vat-all").addEventListener("click", function () { $$('[data-k="tax_rate"]', box).forEach(function (i) { i.value = data.vat; }); recalc(); });
    var cur = $("#currency"); if (cur) cur.addEventListener("change", recalc);
  }
  if (inv) loadInvoices();

  /* ---- stop double submits ---- */
  form.addEventListener("submit", function (e) {
    if (form.dataset.busy === "1") { e.preventDefault(); return; }
    var b = e.submitter; if (b && b.getAttribute("formtarget") === "_blank") return;
    form.dataset.busy = "1"; setTimeout(function () { form.dataset.busy = ""; }, 8000);
  });
  document.addEventListener("click", function (e) { if (!e.target.closest(".cust-pick")) cac && (cac.hidden = true); });
})();
