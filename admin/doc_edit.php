<?php
require __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/docs.php';
require_once __DIR__ . '/inc/rfq.php';
require_once dirname(__DIR__) . '/api/lib/customers.php';
$admin = require_login(); $pdo = db();
$type = (string) ($_GET['type'] ?? $_POST['type'] ?? ''); $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if (!isset(DOC_TYPES[$type])) { http_response_code(404); exit('Unknown document type.'); }
$GLOBALS['nav_type'] = $type; $T = DOC_TYPES[$type];
$existing = null;
if ($id) {
    if (!dcan('doc.view', $admin, $type)) doc_need('doc.view', $type);
    $existing = get_document($pdo, $id);
    if (!$existing || $existing['doc_type'] !== $type) { http_response_code(404); exit('Document not found.'); }
    if ($why = edit_block_reason($admin, $existing)) { flash($why, 'bad'); redirect('doc.php?id=' . $id); }
} elseif (!dcan('doc.create', $admin, $type)) doc_need('doc.create', $type);

$errors = []; $in = null; $rfqCustErr = null;
$rfqRef = (string) ($_GET['rfq'] ?? $_POST['rfq'] ?? ''); $rfq = null;       // "quote:12" or "request:5": the request this document answers
if (!$existing && preg_match('/^(quote|request):(\d+)$/', $rfqRef, $m) && rfq_can_view($m[1])) $rfq = rfq_load($pdo, $m[1], (int) $m[2]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = $_POST; $act = $_POST['action'] ?? 'save';
    $r = save_document($pdo, $admin, $type, $id ?: null, $in);
    if ($r['ok']) {
        $nid = $r['id'];
        if ($rfq && !$id) { $dn = $pdo->prepare('SELECT doc_no FROM documents WHERE id = ?'); $dn->execute([$nid]); rfq_event($pdo, $rfq['kind'], $rfq['id'], (int) $admin['id'], 'note', DOC_TYPES[$type]['label'] . ' ' . (string) $dn->fetchColumn() . ' created from this request'); }
        if ($act === 'post') {
            $p = post_document($pdo, $admin, $nid);
            if ($p['ok']) { flash(DOC_TYPES[$type]['label'] . ' saved and posted.'); redirect('doc.php?id=' . $nid); }
            flash('Saved as a draft, but not posted: ' . $p['error'], 'bad'); redirect('doc.php?id=' . $nid);
        }
        flash('Saved.'); redirect('doc.php?id=' . $nid);
    }
    $errors = $r['errors'];
}
if ($in === null) {
    $today = (new DateTime('now', new DateTimeZone('Africa/Kampala')))->format('Y-m-d');
    if ($existing) {
        $in = ['customer_id' => $existing['customer_id'], 'doc_date' => $existing['doc_date'], 'due_date' => $existing['due_date'], 'currency' => $existing['currency'], 'salesperson' => $existing['salesperson'], 'reference_text' => $existing['reference_text'], 'payment_method' => $existing['payment_method'], 'payment_ref' => $existing['payment_ref'], 'parent_id' => $existing['parent_id'], 'notes' => $existing['notes'], 'terms' => $existing['terms'], 'version' => $existing['version'], 'amount' => $type === 'receipt' ? rtrim(rtrim((string) $existing['total'], '0'), '.') : '',
               'lines' => array_map(fn($l) => ['item_id' => $l['item_id'], 'description' => $l['description'], 'quantity' => fmt_qty_plain($l['quantity']), 'unit' => $l['unit'], 'unit_price' => plain_num($l['unit_price']), 'discount' => plain_num($l['discount']), 'tax_rate' => plain_num($l['tax_rate'])], $existing['lines'])];
    } else {
        $days = $type === 'invoice' ? INVOICE_DUE_DAYS : (int) doc_setting('quote_valid_days', '30');
        $in = ['customer_id' => (int) ($_GET['customer_id'] ?? 0), 'doc_date' => $today, 'due_date' => $T['due'] !== '' ? (new DateTime($today))->modify("+$days days")->format('Y-m-d') : '', 'currency' => doc_setting('default_currency', 'UGX'), 'salesperson' => $admin['full_name'], 'reference_text' => '', 'payment_method' => '', 'payment_ref' => '', 'parent_id' => (int) ($_GET['parent_id'] ?? 0), 'notes' => '', 'terms' => doc_setting('terms_default'), 'version' => 0, 'amount' => '', 'lines' => [['description' => '', 'quantity' => '1']]];
        if ($rfq) {   // start from the request: customer, reference and one line describing what was asked for
            $in['reference_text'] = $rfq['ref']; if (!$in['customer_id']) {      // normally rfq.php has registered the client already; try again here if not
                $in['customer_id'] = (int) capture_customer($pdo, ['name' => $rfq['name'], 'contact_person' => $rfq['contact'], 'tin' => $rfq['tin'], 'address' => $rfq['location'], 'phone' => $rfq['phone'], 'email' => $rfq['email'], 'note' => 'Registered from ' . $rfq['ref'] . '.']);
                if (!$in['customer_id']) $rfqCustErr = (string) ($GLOBALS['capture_customer_error'] ?? '');
            }
            $in['lines'] = rfq_lines($rfq);   // one line per selected subcategory / equipment item
        }
    }
}
function plain_num($v): string { $s = rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'); return $s === '' ? '0' : $s; }
function fmt_qty_plain($v): string { $s = rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.'); return $s === '' ? '0' : $s; }
$cust = null; if (!empty($in['customer_id'])) { $s = $pdo->prepare('SELECT * FROM customers WHERE id = ?'); $s->execute([(int) $in['customer_id']]); $cust = $s->fetch() ?: null; }
$boot = ['type' => $type, 'doc_id' => $id, 'vat' => (float) doc_setting('default_vat', '18'), 'lines' => array_values((array) ($in['lines'] ?? [])), 'customer' => $cust ? ['id' => (int) $cust['id'], 'name' => $cust['name'], 'contact' => $cust['contact_person'], 'tin' => $cust['tin'], 'address' => $cust['address'], 'phone' => $cust['phone'], 'email' => $cust['email'], 'logo' => logo_url($cust['logo_file'])] : null, 'parent_id' => (int) ($in['parent_id'] ?? 0), 'amount' => (string) ($in['amount'] ?? ''), 'currencies' => array_keys(CURRENCIES), 'services' => doc_service_catalogue(), 'items' => doc_price_list($pdo)];
$v = fn($k) => h((string) ($in[$k] ?? '')); $e = fn($k) => isset($errors[$k]) ? '<span class="err">' . h($errors[$k]) . '</span>' : '';
$isPosted = $existing && $existing['status'] === 'posted';
dm_head(($existing ? 'Edit ' : 'New ') . strtolower($T['label']), $admin, 'docs:' . $type);
?>
<?php $backUrl = $existing ? 'doc.php?id=' . $id : 'docs.php?type=' . h($type); ?>
<div class="hdr doc-bar"><a class="noprint" href="<?= $backUrl ?>">&larr; Back</a>
  <span class="spacer"></span>
  <div class="doc-actions noprint"><button class="btn sm" form="docform" name="action" value="save"><?= $isPosted ? 'Save changes' : 'Save as draft' ?></button>
    <button class="btn sm ghost" form="docform" name="action" value="preview" formaction="doc_preview.php" formtarget="_blank">Preview</button>
    <?php if (!$isPosted && dcan('doc.post', $admin, $type)): ?><button class="btn sm green" form="docform" name="action" value="post" data-confirm="Save and post this <?= h(strtolower($T['label'])) ?>? Once posted it is locked.">Save and post</button><?php endif; ?>
    <a class="btn sm ghost" href="<?= $backUrl ?>">Cancel</a></div></div>
<?php if (!empty($errors['form'])): ?><div class="flash bad" role="alert"><?= h($errors['form']) ?></div><?php endif; ?>
<?php if ($isPosted): ?><div class="flash bad">This document is <b>posted</b>. Changes are recorded in its history with your reason, and the earlier version is kept.</div><?php endif; ?>
<script type="application/json" id="doc-data"><?= str_replace('<', '<', json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_AMP)) ?></script>
<form method="post" id="docform" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="type" value="<?= h($type) ?>"><?php if ($rfq): ?><input type="hidden" name="rfq" value="<?= h($rfq['kind'] . ':' . $rfq['id']) ?>"><?php endif; ?><input type="hidden" name="id" value="<?= (int) $id ?>"><input type="hidden" name="version" value="<?= (int) ($in['version'] ?? 0) ?>"><input type="hidden" name="parent_id" value="<?= (int) ($in['parent_id'] ?? 0) ?>" id="parent_id_f">
<div class="card"><h2>Customer</h2>
  <input type="hidden" name="customer_id" id="customer_id" value="<?= (int) ($in['customer_id'] ?? 0) ?>">
  <div class="cust-pick"><label <?= $cust ? 'hidden' : '' ?>>Search customers by name, TIN, phone or email<input type="search" id="cust-q" placeholder="Start typing..."></label><div class="ac" id="cust-ac" hidden></div>
    <div class="cust-card" id="cust-card" <?= $cust ? '' : 'hidden' ?>></div></div>
  <?= $e('customer_id') ?>
  <?php if ($rfq && $rfqCustErr !== null && !$cust): $q = http_build_query(['edit' => 'new', 'name' => $rfq['name'], 'contact_person' => $rfq['contact'], 'tin' => $rfq['tin'], 'phone' => $rfq['phone'], 'email' => $rfq['email'], 'address' => $rfq['location']]); ?>
  <div class="flash bad" style="margin-top:10px">The client from <?= h($rfq['ref']) ?> could not be registered automatically<?= $rfqCustErr !== '' ? ' (' . h($rfqCustErr) . ')' : '' ?>.<br><b><?= h($rfq['name']) ?></b> &middot; <?= h($rfq['email']) ?> &middot; <?= h($rfq['phone']) ?><br><a href="customers.php?<?= h($q) ?>" target="_blank" rel="noopener">Register this client now</a> (details are filled in), then search for them above.</div>
  <?php endif; ?>
  <?php if (dcan('customer.add', $admin)): ?><p class="muted" style="margin:8px 0 0">Not listed? <a href="customers.php?edit=new" target="_blank" rel="noopener">Register the customer</a> in a new tab, then search again.</p><?php endif; ?>
</div>
<div class="card"><h2>Details</h2><div class="cols3">
  <label>Date<input type="date" name="doc_date" id="doc_date" value="<?= $v('doc_date') ?>" required><?= $e('doc_date') ?></label>
  <?php if ($T['due'] !== ''): ?><label><?= h($T['due']) ?><input type="date" name="due_date" id="due_date" value="<?= $v('due_date') ?>"<?= $type === 'invoice' ? ' readonly title="Always 30 days after the invoice date"' : '' ?>><?= $type === 'invoice' ? '<span class="muted">Always 30 days after the invoice date</span>' : '' ?><?= $e('due_date') ?></label><?php endif; ?>
  <label>Currency<select name="currency" id="currency"><?php foreach (CURRENCIES as $k => $c) echo '<option value="' . $k . '"' . (($in['currency'] ?? '') === $k ? ' selected' : '') . '>' . $k . '</option>'; ?></select><?= $e('currency') ?></label>
  <?php if ($type !== 'receipt'): ?><label>Payment method (optional)<input name="payment_method" value="<?= $v('payment_method') ?>" maxlength="60" placeholder="Mobile money, bank..."></label>
  <label>Salesperson<input name="salesperson" value="<?= $v('salesperson') ?>" maxlength="120"></label>
  <label>Reference (customer PO, project...)<input name="reference_text" value="<?= $v('reference_text') ?>" maxlength="190"></label><?php endif; ?>
</div></div>
<?php if ($type === 'receipt'): ?>
<div class="card"><h2>Payment received</h2><div class="cols3">
  <label>Invoice being paid<select name="parent_id" id="inv-sel"><option value="0">- none / other transaction -</option></select><?= $e('parent_id') ?></label>
  <label>Amount received<input name="amount" id="amount" inputmode="decimal" value="<?= $v('amount') ?>"><?= $e('amount') ?><span class="muted" id="bal-hint"></span></label>
  <label>Payment method<select name="payment_method"><option value="">Choose...</option><?php foreach (['Cash', 'Mobile money', 'Bank transfer', 'Cheque', 'Card', 'Other'] as $m) echo '<option' . (($in['payment_method'] ?? '') === $m ? ' selected' : '') . '>' . $m . '</option>'; ?></select><?= $e('payment_method') ?></label>
  <label>Payment reference (transaction id, cheque no.)<input name="payment_ref" value="<?= $v('payment_ref') ?>" maxlength="120"></label>
  <label>Transaction description <span class="muted">(needed if no invoice)</span><input name="reference_text" value="<?= $v('reference_text') ?>" maxlength="190"><?= $e('reference_text') ?></label>
  <label>Received by<input name="salesperson" value="<?= $v('salesperson') ?>" maxlength="120"></label></div></div>
<?php else: ?>
<div class="card"><div class="hdr"><h2 style="margin:0">Products and services</h2><span class="spacer"></span><button type="button" class="btn sm ghost" id="vat-all">Apply standard VAT to all lines</button></div><?= $e('lines') ?>
  <div class="lines-ed" id="lines"><div class="lh"><span>Description</span><span>Qty</span><span>Unit</span><span>Unit price</span><span>Discount</span><span>Tax %</span><span>Total</span><span></span></div></div>
  <button type="button" class="btn sm ghost" id="add-line" style="margin-top:10px">+ Add a line</button>
  <div class="tot-ed"><div><span>Subtotal</span><b id="t-sub">0</b></div><div><span>Discount</span><b id="t-disc">0</b></div><div><span>VAT / tax</span><b id="t-tax">0</b></div><div class="g"><span>Total</span><b id="t-total">0</b></div></div>
  <p class="muted" style="margin:6px 0 0">Search the price list by typing in a description. Totals are calculated again on the server when you save.</p></div>
<?php endif; ?>
<div class="card"><h2>Notes and terms</h2><div class="cols2"><label>Notes (shown on the document)<textarea name="notes" maxlength="2000"><?= $v('notes') ?></textarea></label><label>Terms and conditions<textarea name="terms" maxlength="4000" style="min-height:130px"><?= $v('terms') ?></textarea></label></div></div>
<?php if ($isPosted): ?><div class="card"><label>Reason for changing this posted document *<input name="edit_reason" maxlength="400" value="<?= $v('edit_reason') ?>" required><?= $e('edit_reason') ?></label></div><?php endif; ?>
</form>
<script src="doc-editor.js"></script>
<?php dm_foot();
