<?php
require __DIR__ . '/inc/boot.php';
$u = biz_require(); $a = account(); $pdo = db(); $aid = (int) $a['id']; $owner = $u['role'] === 'owner'; $err = []; $forced = !empty($_GET['pw']) || (int) $u['must_change_pw'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if ($act === 'password') {
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
            flash('Business details saved.'); redirect('settings.php');
        }
    } elseif ($act === 'adduser' && $owner) {
        $n = mb_substr(trim((string) ($_POST['full_name'] ?? '')), 0, 190); $em = strtolower(trim((string) ($_POST['email'] ?? ''))); $pw = (string) ($_POST['password'] ?? '');
        $x = $pdo->prepare('SELECT COUNT(*) FROM biz_users WHERE email = ?'); $x->execute([$em]);
        if ($n === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) $err[] = 'Enter the name and a valid email.';
        elseif ($e = valid_password($pw)) $err[] = $e;
        elseif ((int) $x->fetchColumn()) $err[] = 'That email already has an account.';
        else { $pdo->prepare("INSERT INTO biz_users (account_id, full_name, email, password_hash, role, must_change_pw, created_at) VALUES (?,?,?,?, 'staff', 1, ?)")->execute([$aid, $n, $em, make_hash($pw), gmdate('Y-m-d H:i:s')]); flash('Team member added. They must change the password at first sign-in.'); redirect('settings.php'); }
    } elseif ($act === 'toguser' && $owner) {
        $pdo->prepare("UPDATE biz_users SET is_active = 1 - is_active WHERE id = ? AND account_id = ? AND role = 'staff'")->execute([(int) ($_POST['uid'] ?? 0), $aid]); redirect('settings.php');
    }
    $a = array_merge($a, array_intersect_key($_POST, array_flip(['name', 'owner_name', 'email', 'phone', 'address', 'web', 'tin', 'bank_details', 'terms_default', 'doc_prefix', 'currency'])));
}
function doc_setting_global(string $k): string { $c = $GLOBALS['DOC_CO'] ?? null; unset($GLOBALS['DOC_CO']); $v = doc_setting($k, 'info@audtix.com'); if ($c !== null) $GLOBALS['DOC_CO'] = $c; return $v; }
function biz_save_logo(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, ''];
    if ($file['error'] !== UPLOAD_ERR_OK) return [null, 'The logo could not be uploaded.'];
    if ($file['size'] > 2 * 1024 * 1024) return [null, 'The logo must be under 2 MB.'];
    if (!function_exists('imagecreatefromstring')) return [null, 'Image support (GD) is not available on this server.'];
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) return [null, 'The logo must be a PNG, JPG, GIF or WebP image.'];
    if ($info[0] > 5000 || $info[1] > 5000 || $info[0] < 16 || $info[1] < 16) return [null, 'The logo size is not supported (16 to 5000 pixels).'];
    $im = @imagecreatefromstring((string) file_get_contents($file['tmp_name'])); if (!$im) return [null, 'The image could not be read.'];
    $w = imagesx($im); $h = imagesy($im); $max = 600;
    if ($w > $max) { $nh = (int) round($h * $max / $w); $n = imagecreatetruecolor($max, $nh); imagealphablending($n, false); imagesavealpha($n, true); imagefill($n, 0, 0, imagecolorallocatealpha($n, 255, 255, 255, 127)); imagecopyresampled($n, $im, 0, 0, 0, 0, $max, $nh, $w, $h); imagedestroy($im); $im = $n; }
    else { imagealphablending($im, false); imagesavealpha($im, true); }
    $d = storage_dir() . '/biz-logos'; if (!is_dir($d)) @mkdir($d, 0750, true);
    $name = bin2hex(random_bytes(12)) . '.png'; $ok = @imagepng($im, $d . '/' . $name, 6); imagedestroy($im);
    return $ok ? [$name, ''] : [null, 'The logo could not be stored.'];
}
$team = $pdo->prepare('SELECT id, full_name, email, role, is_active FROM biz_users WHERE account_id = ? ORDER BY role, id'); $team->execute([$aid]); $team = $team->fetchAll();
$sub = $a['_sub'] ?? sub_state($a);
biz_head('Settings', 'set'); ?>
<?php if ($forced): ?><div class="flash bad">Please set your own password before continuing.</div><?php endif; ?>
<?php if ($err): ?><div class="flash bad"><?php foreach ($err as $x): ?><div><?= h($x) ?></div><?php endforeach; ?></div><?php endif; ?>
<div class="card" id="plan"><h2>Subscription</h2><p><span class="pill <?= $sub['ok'] ? 'posted' : 'cancelled' ?>"><?= h($sub['label']) ?></span> <?= h((string) $a['plan']) ?><?= !empty($sub['until']) ? ' &middot; until ' . h(fmt_date($sub['until'])) : '' ?></p>
<?php if ($sub['note']): ?><p class="muted"><?= h($sub['note']) ?></p><?php endif; ?>
<p class="muted">To activate or renew, contact Audtix Systems: <a href="mailto:<?= h(doc_setting_global('company_email')) ?>"><?= h(doc_setting_global('company_email')) ?></a>. Your documents are never deleted.</p></div>
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
<label>Logo (PNG, JPG, GIF or WebP)<input type="file" name="logo" accept="image/*"></label><?php if (logo_file_path($a['logo'])): ?><p><img class="lg" src="logo.php?a=<?= $aid ?>&v=<?= substr(md5((string) $a['logo']), 0, 6) ?>" alt="Current logo"></p><?php endif; ?>
<div class="actions"><button class="btn">Save details</button></div></form>
<div class="card"><h2>Team</h2><div class="list"><?php foreach ($team as $t): ?><div class="row"><div><b><?= h($t['full_name']) ?></b><small><?= h($t['email']) ?> &middot; <?= h($t['role']) ?></small></div><div class="r"><?php if ($t['role'] === 'staff'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toguser"><input type="hidden" name="uid" value="<?= (int) $t['id'] ?>"><button class="btn sm ghost"><?= $t['is_active'] ? 'Disable' : 'Enable' ?></button></form><?php endif; ?></div></div><?php endforeach; ?></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="adduser"><h3>Add team member</h3><div class="two"><label>Name<input name="full_name" maxlength="190" required></label><label>Email<input name="email" type="email" maxlength="190" required></label></div><label>Temporary password<input name="password" required minlength="8"></label><div class="actions"><button class="btn">Add</button></div></form></div>
<?php endif; endif; ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="password"><h2>Change password</h2>
<label>Current password<input type="password" name="current" required autocomplete="current-password"></label><label>New password<input type="password" name="new" required minlength="8" autocomplete="new-password"></label><div class="actions"><button class="btn">Change password</button></div></form>
<?php biz_foot();
