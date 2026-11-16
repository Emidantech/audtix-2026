<?php
require __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/docs.php';
$admin = require_super(); $pdo = db(); $err = [];
$keys = ['company_name' => 190, 'company_address' => 300, 'company_phone' => 60, 'company_email' => 190, 'company_web' => 120, 'company_tin' => 20, 'bank_details' => 600, 'default_currency' => 3, 'default_vat' => 6, 'quote_valid_days' => 4, 'invoice_due_days' => 4, 'terms_default' => 2000];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = []; foreach ($keys as $k => $max) $v[$k] = t($_POST, $k, $max);
    if (mb_strlen($v['company_name']) < 2) $err['company_name'] = 'Company name is required.';
    if ($v['company_email'] !== '' && !filter_var($v['company_email'], FILTER_VALIDATE_EMAIL)) $err['company_email'] = 'Enter a valid email address.';
    $v['default_currency'] = strtoupper($v['default_currency']); if (!isset(CURRENCIES[$v['default_currency']])) $err['default_currency'] = 'Choose a supported currency.';
    if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $v['default_vat']) || (float) $v['default_vat'] > 100) $err['default_vat'] = 'Between 0 and 100.';
    foreach (['quote_valid_days', 'invoice_due_days'] as $k) if (!preg_match('/^\d{1,3}$/', $v[$k])) $err[$k] = 'Whole days.';
    [$logo, $le] = save_logo($_FILES['logo'] ?? ['error' => UPLOAD_ERR_NO_FILE]); if ($le) $err['logo'] = $le;
    if (!$err) {
        foreach ($v as $k => $x) save_doc_setting($pdo, $k, $x);
        if ($logo) save_doc_setting($pdo, 'company_logo', $logo); elseif (!empty($_POST['reset_logo'])) save_doc_setting($pdo, 'company_logo', '');
        log_activity((int) $admin['id'], 'settings_updated'); flash('Settings saved.'); redirect('settings.php');
    }
}
$S = doc_settings(); if ($_SERVER['REQUEST_METHOD'] === 'POST') $S = array_merge($S, $v ?? []);
$f = fn($k) => h($S[$k] ?? ''); $e = fn($k) => isset($err[$k]) ? '<span class="err">' . h($err[$k]) . '</span>' : '';
dm_head('Document settings', $admin, 'doc_settings'); ?>
<h1>System settings</h1><p class="muted">These details print on every document. Only a super admin can change them.</p>
<div class="card"><form method="post" enctype="multipart/form-data" class="stack" style="max-width:760px"><?= csrf_field() ?>
  <h2>Company</h2>
  <label>Company name<input name="company_name" required maxlength="190" value="<?= $f('company_name') ?>"><?= $e('company_name') ?></label>
  <label>Address<input name="company_address" maxlength="300" value="<?= $f('company_address') ?>"></label>
  <div class="cols3"><label>Phone<input name="company_phone" maxlength="60" value="<?= $f('company_phone') ?>"></label><label>Email<input name="company_email" maxlength="190" value="<?= $f('company_email') ?>"><?= $e('company_email') ?></label><label>Website<input name="company_web" maxlength="120" value="<?= $f('company_web') ?>"></label><label>Company TIN<input name="company_tin" maxlength="20" value="<?= $f('company_tin') ?>"></label></div>
  <div><b>Company logo</b> <span class="muted">(leave empty to keep the Audtix logo)</span><div class="row" style="margin-top:8px"><img class="thumb" style="width:auto;max-width:160px;height:56px" src="<?= h(company_logo_url()) ?>" alt=""><input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp"><?php if (doc_setting('company_logo')): ?><label class="inline"><input type="checkbox" name="reset_logo" value="1"> Go back to the Audtix logo</label><?php endif; ?></div><?= $e('logo') ?></div>
  <label>Bank / mobile money payment details (printed on invoices, proformas and quotations)<textarea name="bank_details" maxlength="600" style="min-height:80px"><?= $f('bank_details') ?></textarea></label>
  <h2>Defaults for new documents</h2>
  <div class="cols3"><label>Currency<select name="default_currency"><?php foreach (CURRENCIES as $k => $c) echo '<option value="' . $k . '"' . (($S['default_currency'] ?? 'UGX') === $k ? ' selected' : '') . '>' . $k . ' - ' . h($c[0]) . '</option>'; ?></select><?= $e('default_currency') ?></label>
  <label>Standard VAT %<input name="default_vat" inputmode="decimal" value="<?= $f('default_vat') ?>"><?= $e('default_vat') ?></label>
  <label>Quotation valid for (days)<input name="quote_valid_days" inputmode="numeric" value="<?= $f('quote_valid_days') ?>"><?= $e('quote_valid_days') ?></label>
  <label>Invoice due after (days)<input name="invoice_due_days" inputmode="numeric" value="<?= $f('invoice_due_days') ?>"><?= $e('invoice_due_days') ?></label></div>
  <label>Default terms and conditions<textarea name="terms_default" maxlength="2000" style="min-height:110px"><?= $f('terms_default') ?></textarea></label>
  <button class="btn">Save settings</button></form></div>
<?php dm_foot();
