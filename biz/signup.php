<?php
require __DIR__ . '/inc/boot.php';
if (me()) redirect('index.php');
$pdo = null; $plans = []; try { $pdo = db(); biz_ensure_plans($pdo); $plans = $pdo->query('SELECT * FROM biz_plans WHERE is_active = 1 AND is_public = 1 ORDER BY sort_order')->fetchAll(); } catch (Throwable $e) {}
$err = ''; $v = ['business' => '', 'name' => '', 'email' => '', 'phone' => '', 'address' => '', 'web' => '', 'category' => '', 'facebook' => '', 'instagram' => '', 'linkedin' => '', 'x_url' => '', 'tiktok' => '', 'plan_id' => ''];
if (empty($_SESSION['su_t'])) $_SESSION['su_t'] = time();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf(); foreach ($v as $k => $_) $v[$k] = mb_substr(trim((string) ($_POST[$k] ?? '')), 0, 190); $v['email'] = strtolower($v['email']); $pw = (string) ($_POST['password'] ?? '');
    if (trim((string) ($_POST['company_url'] ?? '')) !== '' || time() - (int) $_SESSION['su_t'] < 3) { redirect('login.php'); }          // bots
    try {
        $pdo = db(); $ip = client_ip();
        $c = $pdo->prepare("SELECT COUNT(*) FROM submission_log WHERE ip = ? AND category = 'biz_signup' AND created_at > ?"); $c->execute([$ip, gmdate('Y-m-d H:i:s', time() - 3600)]);
        $x = $pdo->prepare('SELECT COUNT(*) FROM biz_users WHERE email = ?'); $x->execute([$v['email']]);
        if ((int) $c->fetchColumn() >= 5) $err = 'Too many sign-ups from this connection. Please try later.';
        elseif ($v['business'] === '' || $v['name'] === '') $err = 'Enter the business name and your name.';
        elseif (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email address.';
        elseif ($v['phone'] === '') $err = 'Enter a phone number.';
        elseif ($e = valid_password($pw)) $err = $e;
        elseif ((int) $x->fetchColumn()) $err = 'That email already has an account. Sign in instead.';
        else {
            [$logo, $le] = biz_save_logo($_FILES['logo'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
            if ($le) $err = $le;
            else {
                $plan = null; foreach ($plans as $p) if ((string) $p['id'] === $v['plan_id']) $plan = $p;
                $pdo->prepare('INSERT INTO submission_log (ip, category, created_at) VALUES (?,?,?)')->execute([$ip, 'biz_signup', now()]);
                [$aid, $uid] = biz_create_account($pdo, ['business' => $v['business'], 'name' => $v['name'], 'email' => $v['email'], 'phone' => $v['phone'], 'address' => $v['address'], 'web' => $v['web'], 'logo' => $logo, 'category' => $v['category'],
                    'facebook' => biz_social_url($v['facebook']), 'instagram' => biz_social_url($v['instagram']), 'linkedin' => biz_social_url($v['linkedin']), 'x_url' => biz_social_url($v['x_url']), 'tiktok' => biz_social_url($v['tiktok']),
                    'plan_id' => null, 'approval' => biz_platform('biz_signup_approval', '0') === '1' ? 'pending' : 'approved'], $pw);
                biz_mail_staff('New business account: ' . $v['business'], "A business signed up on the platform.\n\nBusiness: {$v['business']}\nOwner: {$v['name']}\nEmail: {$v['email']}\nPhone: {$v['phone']}\nPlan chosen: " . ($plan['name'] ?? 'Trial'), $v['email']);
                session_regenerate_id(true); $_SESSION['biz_uid'] = $uid; $_SESSION['biz_seen'] = time(); biz_audit('login', 'after sign-up', $aid);
                $paid = $plan && ((float) $plan['price_ugx'] > 0 || (float) $plan['price_usd'] > 0);
                flash('Welcome! Your workspace is ready.' . ($paid ? ' Complete payment to start your plan.' : ' Add details in Settings, then create your first document.')); redirect($paid ? 'billing.php?plan=' . (int) $plan['id'] : 'index.php');
            }
        }
    } catch (Throwable $e) { $err = 'Sign-up is not available yet. Please contact the service provider.'; }
}
biz_head('Create your business account'); ?>
<form method="post" enctype="multipart/form-data" class="card narrow wide"><?= csrf_field() ?>
  <p class="muted">Your own workspace for quotations, invoices, proforma invoices and receipts, with your logo, customers, branches and team. Free for <?= (int) biz_platform('biz_trial_days', (string) BIZ_TRIAL_DAYS) ?> days. No card needed.</p>
  <?php if ($err): ?><div class="flash bad"><?= h($err) ?></div><?php endif; ?>
  <h2>Business</h2>
  <label>Registered or trading name<input name="business" value="<?= h($v['business']) ?>" maxlength="190" required></label>
  <label>Logo (PNG, JPG or WebP)<input type="file" name="logo" accept="image/*"></label>
  <label>Category<select name="category"><option value="">Select</option><?php foreach (BIZ_CATEGORIES as $c): ?><option<?= $v['category'] === $c ? ' selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select></label>
  <label>Physical address<input name="address" value="<?= h($v['address']) ?>" maxlength="190"></label>
  <div class="two"><label>Phone / WhatsApp<input type="tel" name="phone" value="<?= h($v['phone']) ?>" autocomplete="tel" required></label><label>Website<input name="web" value="<?= h($v['web']) ?>" placeholder="https://"></label>
  <label>Facebook<input name="facebook" value="<?= h($v['facebook']) ?>"></label><label>Instagram<input name="instagram" value="<?= h($v['instagram']) ?>"></label><label>LinkedIn<input name="linkedin" value="<?= h($v['linkedin']) ?>"></label><label>X<input name="x_url" value="<?= h($v['x_url']) ?>"></label><label>TikTok<input name="tiktok" value="<?= h($v['tiktok']) ?>"></label></div>
  <h2>Administrator</h2>
  <label>Your name<input name="name" value="<?= h($v['name']) ?>" maxlength="190" autocomplete="name" required></label>
  <label>Email<input type="email" name="email" value="<?= h($v['email']) ?>" autocomplete="username" required></label>
  <label>Password<input type="password" name="password" autocomplete="new-password" minlength="8" required><small class="muted">At least 8 characters with a letter and a number.</small></label>
  <?php if ($plans): ?><h2>Package</h2><div class="plans"><?php foreach ($plans as $i => $p): ?><label class="plan"><input type="radio" name="plan_id" value="<?= (int) $p['id'] ?>"<?= ($v['plan_id'] === (string) $p['id'] || ($v['plan_id'] === '' && $p['code'] === 'trial')) ? ' checked' : '' ?>><b><?= h($p['name']) ?></b><small><?= (float) $p['price_ugx'] > 0 ? h(number_format((float) $p['price_ugx'])) . ' UGX' : ($p['code'] === 'trial' ? 'Free trial' : 'Price on request') ?><?= (float) $p['price_usd'] > 0 ? ' · ' . h(number_format((float) $p['price_usd'], 2)) . ' USD' : '' ?> / <?= (int) $p['duration_days'] ?> days</small><small><?= (int) $p['max_users'] ?: 'Unlimited' ?> users · <?= (int) $p['max_branches'] ?: 'Unlimited' ?> branches</small></label><?php endforeach; ?></div><?php endif; ?>
  <input class="hp" name="company_url" tabindex="-1" autocomplete="off" aria-hidden="true">
  <button class="btn">Create my account</button>
  <p class="muted c">Already registered? <a href="login.php">Sign in</a></p>
</form>
<?php biz_foot();
