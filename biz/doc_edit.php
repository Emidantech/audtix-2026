<?php
require __DIR__ . '/inc/boot.php';
$u = biz_require(true); $a = account(); $pdo = db(); $aid = (int) $a['id'];
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0); $d = $id ? biz_doc($pdo, $aid, $id) : null;
if ($id && (!$d || $d['status'] !== 'draft')) { flash('Only drafts can be edited.', 'bad'); redirect($d ? 'doc.php?id=' . $id : 'docs.php'); }
$type = $d ? $d['doc_type'] : (string) ($_GET['type'] ?? $_POST['type'] ?? ''); if (!in_array($type, BIZ_TYPES, true)) redirect('index.php');
$T = DOC_TYPES[$type]; $errors = []; $isR = $type === 'receipt';
$lim = (int) $a['monthly_doc_limit'];
$customers = $pdo->prepare('SELECT * FROM biz_customers WHERE account_id = ? ORDER BY name LIMIT 500'); $customers->execute([$aid]); $customers = $customers->fetchAll();
$items = $pdo->prepare('SELECT * FROM biz_items WHERE account_id = ? AND is_active = 1 ORDER BY name LIMIT 500'); $items->execute([$aid]); $items = $items->fetchAll();
$invs = []; if ($isR) { $s = $pdo->prepare("SELECT * FROM biz_documents WHERE account_id = ? AND doc_type = 'invoice' AND status = 'posted' ORDER BY id DESC LIMIT 200"); $s->execute([$aid]);
    foreach ($s as $i) { $bal = db_cents($i['total']) - biz_invoice_paid($pdo, (int) $i['id'], $d ? (int) $d['id'] : 0); if ($bal > 0) $i['_bal'] = $bal; else continue; $invs[] = $i; } }
$today = gmdate('Y-m-d'); $plus30 = (new DateTime('today'))->modify('+30 days')->format('Y-m-d');
$in = $d ?: ['customer_id' => '', 'customer_name' => '', 'doc_date' => $today, 'due_date' => $T['due'] ? $plus30 : '', 'currency' => $a['currency'], 'terms' => $a['terms_default'], 'notes' => '', 'payment_method' => '', 'payment_ref' => '', 'reference_text' => '', 'salesperson' => '', 'parent_id' => (int) ($_GET['parent'] ?? 0) ?: '', 'lines' => [['description' => '', 'quantity' => '1', 'unit' => '', 'unit_price' => '', 'discount' => '', 'tax_rate' => '0']]];
if (!$d && $isR && $in['parent_id']) { $p = biz_doc($pdo, $aid, (int) $in['parent_id']); if ($p && $p['doc_type'] === 'invoice') { $in['customer_id'] = $p['customer_id']; $in['customer_name'] = $p['customer_name']; $in['currency'] = $p['currency']; foreach ($invs as $i) if ($i['id'] == $p['id']) $in['lines'][0] = ['description' => 'Payment for invoice ' . $p['doc_no'], 'quantity' => '1', 'unit_price' => dec($i['_bal'])]; } }
if ($d && $isR) { $in['lines'] = [['description' => $d['lines'][0]['description'] ?? 'Payment received', 'quantity' => '1', 'unit_price' => $d['total']]]; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$a['_sub']['ok']) { flash($a['_sub']['note'], 'bad'); redirect('index.php'); }
    if (!$id && $lim > 0 && biz_month_count($pdo, $aid) >= $lim * 2) { flash("You have reached this month's document limit. Contact us to upgrade.", 'bad'); redirect('index.php'); }
    $in = $_POST; $in['lines'] = $isR ? [['description' => trim((string) ($_POST['description'] ?? '')) ?: 'Payment received', 'quantity' => '1', 'unit_price' => (string) ($_POST['amount'] ?? '')]] : array_values((array) ($_POST['lines'] ?? []));
    if ($isR && !empty($_POST['parent_id'])) { $p = biz_doc($pdo, $aid, (int) $_POST['parent_id']); if ($p) { $in['customer_id'] = $p['customer_id']; $in['customer_name'] = $p['customer_name']; $in['currency'] = $p['currency']; } }
    $r = biz_save($pdo, $u, $a, $type, $id ?: null, $in);
    if ($r['ok']) {
        if (($_POST['go'] ?? '') === 'post') { $p = biz_post($pdo, $u, $a, $r['id']); flash($p['ok'] ? $T['label'] . ' posted.' : $p['error'], $p['ok'] ? 'ok' : 'bad'); } else flash('Draft saved.');
        redirect('doc.php?id=' . $r['id']);
    }
    $errors = $r['errors'];
    if ($isR) { $in['lines'] = [['description' => $in['lines'][0]['description'], 'quantity' => '1', 'unit_price' => $in['lines'][0]['unit_price']]]; }
}
$v = fn($k) => h((string) ($in[$k] ?? ''));
biz_head(($id ? 'Edit ' : 'New ') . $T['label'], 'docs'); ?>
<?php if ($errors): ?><div class="flash bad"><?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="post" id="docform" class="doc" data-currency="<?= h($in['currency']) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="type" value="<?= h($type) ?>">
<div class="card"><h2>Customer</h2>
<?php if ($isR && $invs): ?>
  <label>Invoice being paid<select name="parent_id" id="inv-pick"><option value="">Not linked to an invoice</option><?php foreach ($invs as $i): ?><option value="<?= (int) $i['id'] ?>" data-balance="<?= h(dec($i['_bal'])) ?>" data-name="<?= h($i['customer_name']) ?>" data-no="<?= h($i['doc_no']) ?>"<?= (string) ($in['parent_id'] ?? '') === (string) $i['id'] ? ' selected' : '' ?>><?= h($i['doc_no'] . ' · ' . $i['customer_name'] . ' · balance ' . dmoney($i['_bal'], $i['currency'])) ?></option><?php endforeach; ?></select></label>
<?php endif; ?>
  <label>Customer<select name="customer_id" id="cust-pick"><option value="">New customer (type below)</option><?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (string) ($in['customer_id'] ?? '') === (string) $c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></label>
  <div id="cust-new"><label>Customer name<input name="customer_name" id="cust-name" value="<?= $v('customer_name') ?>" maxlength="190"></label>
  <div class="two"><label>Phone<input name="customer_phone" value="<?= $v('customer_phone') ?>" maxlength="60"></label><label>Email<input name="customer_email" type="email" value="<?= $v('customer_email') ?>" maxlength="190"></label></div>
  <label>Address<input name="customer_address" value="<?= $v('customer_address') ?>" maxlength="500"></label></div>
</div>
<div class="card"><h2><?= h($T['label']) ?> details</h2><div class="two">
  <label>Date<input type="date" name="doc_date" value="<?= $v('doc_date') ?>" required></label>
  <?php if ($T['due'] !== ''): ?><label><?= h($T['due']) ?><input type="date" name="due_date" value="<?= $v('due_date') ?>"></label><?php endif; ?>
  <label>Currency<select name="currency"><?php foreach (CURRENCIES as $k => $c): ?><option value="<?= $k ?>"<?= ($in['currency'] ?? '') === $k ? ' selected' : '' ?>><?= $k ?></option><?php endforeach; ?></select></label>
  <?php if ($isR): ?><label>Paid by<select name="payment_method"><?php foreach (['Cash', 'Mobile money', 'Bank transfer', 'Card', 'Cheque'] as $m): ?><option<?= ($in['payment_method'] ?? '') === $m ? ' selected' : '' ?>><?= $m ?></option><?php endforeach; ?></select></label><label>Payment reference<input name="payment_ref" value="<?= $v('payment_ref') ?>" maxlength="120"></label><?php endif; ?>
  <?php if (!$isR): ?><label>Reference<input name="reference_text" value="<?= $v('reference_text') ?>" maxlength="190" placeholder="PO, project, vehicle plate..."></label><?php endif; ?>
</div></div>
<?php if ($isR): $l0 = $in['lines'][0] ?? []; ?>
<div class="card"><h2>Payment</h2><label>Amount received<input name="amount" id="amount" inputmode="decimal" value="<?= h((string) ($l0['unit_price'] ?? '')) ?>" required></label><label>For<input name="description" id="desc" value="<?= h((string) ($l0['description'] ?? '')) ?>" maxlength="500" placeholder="What the payment is for"></label></div>
<?php else: ?>
<div class="card"><h2>Items</h2>
<?php if ($items): ?><datalist id="itemlist"><?php foreach ($items as $it): ?><option value="<?= h($it['name']) ?>" data-price="<?= h(trim_num($it['unit_price'])) ?>" data-unit="<?= h((string) $it['unit']) ?>" data-tax="<?= h((string) $it['tax_rate']) ?>"></option><?php endforeach; ?></datalist><?php endif; ?>
<div id="lines"><?php foreach ($in['lines'] as $i => $l): ?>
  <div class="ln"><label class="d">Description<input name="lines[<?= $i ?>][description]" list="itemlist" value="<?= h((string) ($l['description'] ?? '')) ?>" maxlength="1000" class="f-desc" placeholder="Item or service"></label>
  <div class="nums"><label>Qty<input name="lines[<?= $i ?>][quantity]" value="<?= h(trim_num((string) ($l['quantity'] ?? '1')) ?: '1') ?>" inputmode="decimal" class="f-qty"></label><label>Unit<input name="lines[<?= $i ?>][unit]" value="<?= h((string) ($l['unit'] ?? '')) ?>" maxlength="30" class="f-unit" placeholder="day"></label><label>Price<input name="lines[<?= $i ?>][unit_price]" value="<?= h((string) ($l['unit_price'] ?? '')) ?>" inputmode="decimal" class="f-price"></label><label>Disc.<input name="lines[<?= $i ?>][discount]" value="<?= h(($l['discount'] ?? '') === '0.00' ? '' : (string) ($l['discount'] ?? '')) ?>" inputmode="decimal" class="f-disc"></label><label>Tax %<input name="lines[<?= $i ?>][tax_rate]" value="<?= h(trim_num((string) ($l['tax_rate'] ?? '0')) ?: '0') ?>" inputmode="decimal" class="f-tax"></label><b class="lt">0</b><button type="button" class="x rm-line" aria-label="Remove line">&times;</button></div></div>
<?php endforeach; ?></div>
<button type="button" class="btn ghost sm" id="add-line">+ Add line</button>
<div class="tot">Total <b id="grand">0</b></div></div>
<?php endif; ?>
<div class="card"><h2>Notes and terms</h2><label>Notes<textarea name="notes" rows="2" maxlength="2000"><?= $v('notes') ?></textarea></label><?php if (!$isR): ?><label>Terms and conditions<textarea name="terms" rows="3" maxlength="4000"><?= $v('terms') ?></textarea></label><?php endif; ?></div>
<div class="actions"><a class="btn ghost" href="<?= $id ? 'doc.php?id=' . $id : 'docs.php' ?>">Cancel</a><button class="btn ghost" name="go" value="draft">Save draft</button><button class="btn" name="go" value="post" data-confirm="Post this <?= h(strtolower($T['label'])) ?>? A posted document cannot be edited, only cancelled.">Save and post</button></div>
</form>
<?php biz_foot();
