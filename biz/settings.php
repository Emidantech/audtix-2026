<?php
require __DIR__ . '/inc/boot.php';
$u = biz_require(); $a = account(); $pdo = db(); $aid = (int) $a['id']; $owner = bcan('settings.manage'); $err = []; $forced = !empty($_GET['pw']) || (int) $u['must_change_pw'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if ($act === 'password') {
        biz_no_imp();
        $cur = (string) ($_POST['current'] ?? ''); $new = (string) ($_POST['new'] ?? '');
        if (!password_verify($cur, (string) $u['password_hash'])) $err[] = 'Current password is wrong.';
        elseif ($e = valid_password($new)) $err[] = $e;
        else { $pdo->prepare('UPDATE biz_users SET password_hash = ?, must_change_pw = 0 WHERE id = ?')->execute([make_hash($new), $u['id']]); session_regenerate_id(true); flash('Password changed.'); redirect('settings.php'); }
    } elseif ($act === 'profile' && $owner) {
        $f = []; foreach (['name' => 190, 'owner_name' => 190, 'email' => 190, 'phone' => 60, 'address' => 500, 'web' => 190, 'tin' => 20, 'bank_details' => 1000, 'terms_default' => 2000] as $k => $n) $f[$k] = mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $n);
        $pre = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['doc_prefix'] ?? ''))); $pre = substr($pre, 0, 8);
        $cur = strtoupper((string) ($_POST['currency'] ?? '')); if (!isset(CURRENCIES[$cur])) $cur = $a['currency'];
        if ($f['name'] === '') $err[] = 'Business name is required.';
        if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Email looks wrong.';
        $logo = $a['logo'];
        if (!empty($_FILES['logo']['name'])) {
            [$nm, $le] = biz_save_logo($_FILES['logo']);
            if ($le) $err[] = $le; elseif ($nm) { if ($logo && ($old = logo_file_path($logo))) @unlink($old); $logo = $nm; }
        }
        if (!$err) {
            $pdo->prepare('UPDATE biz_accounts SET name=?, owner_name=?, email=?, phone=?, address=?, web=?, tin=?, bank_details=?, terms_default=?, doc_prefix=?, currency=?, logo=?, updated_at=? WHERE id=?')
                ->execute([$f['name'], $f['owner_name'], $f['email'], $f['phone'], $f['address'], $f['web'], $f['tin'], $f['bank_details'], $f['terms_default'], $pre, $cur, $logo, gmdate('Y-m-d H:i:s'), $aid]);
            $col = fn($k) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_POST[$k] ?? '')) ? strtolower($_POST[$k]) : null;
            $th = in_array($_POST['theme'] ?? '', ['light', 'dark', 'sand', 'forest'], true) ? $_POST['theme'] : null; $cat = in_array($_POST['category'] ?? '', BIZ_CATEGORIES, true) ? $_POST['category'] : null;
            biz_ext_save($aid, ['category' => $cat, 'facebook' => biz_social_url((string) ($_POST['facebook'] ?? '')), 'instagram' => biz_social_url((string) ($_POST['instagram'] ?? '')), 'linkedin' => biz_social_url((string) ($_POST['linkedin'] ?? '')), 'x_url' => biz_social_url((string) ($_POST['x_url'] ?? '')), 'tiktok' => biz_social_url((string) ($_POST['tiktok'] ?? '')),
                'primary_color' => $col('primary_color'), 'secondary_color' => $col('secondary_color'), 'theme' => $th]);
            biz_audit('settings_saved', $f['name']); flash('Business details saved.'); redirect('settings.php');
        }
    }
    $a = array_merge($a, array_intersect_key($_POST, array_flip(['name', 'owner_name', 'email', 'phone', 'address', 'web', 'tin', 'bank_details', 'terms_default', 'doc_prefix', 'currency'])));
}
$sub = $a['_sub'] ?? sub_state($a);
biz_head('Settings', 'set'); ?>
<?php if ($forced): ?><div class="flash bad">Please set your own password before continuing.</div><?php endif; ?>
<?php if ($err): ?><div class="flash bad"><?php foreach ($err as $x): ?><div><?= h($x) ?></div><?php endforeach; ?></div><?php endif; ?>
<div class="card" id="plan"><h2>Subscription</h2><p><span class="pill <?= $sub['ok'] ? 'posted' : 'cancelled' ?>"><?= h($sub['label']) ?></span> <?= h((string) $a['plan']) ?><?= !empty($sub['until']) ? ' &middot; until ' . h(fmt_date($sub['until'])) : '' ?></p>
<?php if ($sub['note']): ?><p class="muted"><?= h($sub['note']) ?></p><?php endif; ?>
<p><a class="btn sm" href="billing.php">Manage subscription</a></p><p class="muted">Questions? Contact Audtix Systems: <a href="mailto:<?= h(doc_setting_global('company_email')) ?>"><?= h(doc_setting_global('company_email')) ?></a>. Your documents are never deleted.</p></div>
<?php if (!$forced): ?>
<?php if ($owner): ?><form method="post" enctype="multipart/form-data" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="profile"><h2>Business details</h2><p class="muted">These appear at the top of your documents.</p>
<label>Business name<input name="name" required maxlength="190" value="<?= h((string) $a['name']) ?>"></label>
<div class="two"><label>Phone<input name="phone" maxlength="60" value="<?= h((string) $a['phone']) ?>"></label><label>Email<input name="email" type="email" maxlength="190" value="<?= h((string) $a['email']) ?>"></label>
<label>Website<input name="web" maxlength="190" value="<?= h((string) $a['web']) ?>"></label><label>TIN<input name="tin" maxlength="20" value="<?= h((string) $a['tin']) ?>"></label>
<label>Owner name<input name="owner_name" maxlength="190" value="<?= h((string) $a['owner_name']) ?>"></label>
<label>Document prefix<input name="doc_prefix" maxlength="8" value="<?= h((string) $a['doc_prefix']) ?>" placeholder="e.g. WCR"></label>
<label>Currency<select name="currency"><?php foreach (CURRENCIES as $k => $c): ?><option<?= $a['currency'] === $k ? ' selected' : '' ?>><?= $k ?></option><?php endforeach; ?></select></label></div>
<label>Address<input name="address" maxlength="500" value="<?= h((string) $a['address']) ?>"></label>
<label>Bank / mobile money details<textarea name="bank_details" rows="3" maxlength="1000"><?= h((string) $a['bank_details']) ?></textarea></label>
<label>Default terms<textarea name="terms_default" rows="3" maxlength="2000"><?= h((string) $a['terms_default']) ?></textarea></label>
<h3>Brand and online</h3><div class="two"><label>Category<select name="category"><option value="">Select</option><?php foreach (BIZ_CATEGORIES as $c): ?><option<?= ($a['category'] ?? '') === $c ? ' selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select></label>
<label>Theme<select name="theme"><?php foreach (['light' => 'Light', 'dark' => 'Dark', 'sand' => 'Sand', 'forest' => 'Forest'] as $k => $l): ?><option value="<?= $k ?>"<?= ($a['theme'] ?? 'light') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
<label>Primary colour<input type="color" name="primary_color" value="<?= h(($a['primary_color'] ?? '') ?: '#0b4a9e') ?>"></label><label>Secondary colour<input type="color" name="secondary_color" value="<?= h(($a['secondary_color'] ?? '') ?: '#e3e8f0') ?>"></label>
<label>Facebook<input name="facebook" value="<?= h((string) ($a['facebook'] ?? '')) ?>"></label><label>Instagram<input name="instagram" value="<?= h((string) ($a['instagram'] ?? '')) ?>"></label><label>LinkedIn<input name="linkedin" value="<?= h((string) ($a['linkedin'] ?? '')) ?>"></label><label>X<input name="x_url" value="<?= h((string) ($a['x_url'] ?? '')) ?>"></label><label>TikTok<input name="tiktok" value="<?= h((string) ($a['tiktok'] ?? '')) ?>"></label></div>
<label>Logo (PNG, JPG, GIF or WebP)<input type="file" name="logo" accept="image/*"></label><?php if (logo_file_path($a['logo'])): ?><p><img class="lg" src="logo.php?a=<?= $aid ?>&v=<?= substr(md5((string) $a['logo']), 0, 6) ?>" alt="Current logo"></p><?php endif; ?>
<div class="actions"><button class="btn">Save details</button></div></form>
<p class="muted">Add team members, roles and branch access under <a href="team.php">Team</a>.</p>
<?php endif; endif; ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="password"><h2>Change password</h2>
<label>Current password<input type="password" name="current" required autocomplete="current-password"></label><label>New password<input type="password" name="new" required minlength="8" autocomplete="new-password"></label><div class="actions"><button class="btn">Change password</button></div></form>
<?php biz_foot();
