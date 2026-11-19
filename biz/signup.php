<?php
require __DIR__ . '/inc/boot.php';
if (me()) redirect('index.php');
$err = ''; $v = ['business' => '', 'name' => '', 'email' => '', 'phone' => ''];
if (empty($_SESSION['su_t'])) $_SESSION['su_t'] = time();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf(); foreach ($v as $k => $_) $v[$k] = mb_substr(trim((string) ($_POST[$k] ?? '')), 0, 190); $v['email'] = strtolower($v['email']); $pw = (string) ($_POST['password'] ?? '');
    if (trim((string) ($_POST['website'] ?? '')) !== '' || time() - (int) $_SESSION['su_t'] < 3) { redirect('login.php'); }          // bots
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
            $pdo->prepare('INSERT INTO submission_log (ip, category, created_at) VALUES (?,?,?)')->execute([$ip, 'biz_signup', now()]);
            $pdo->prepare("INSERT INTO biz_accounts (name, owner_name, email, phone, plan, status, trial_ends, created_at, updated_at) VALUES (?,?,?,?, 'Trial', 'trial', ?, ?, ?)")->execute([$v['business'], $v['name'], $v['email'], $v['phone'], gmdate('Y-m-d', time() + BIZ_TRIAL_DAYS * 86400), now(), now()]); $aid = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO biz_users (account_id, full_name, email, password_hash, role, created_at) VALUES (?,?,?,?, 'owner', ?)")->execute([$aid, $v['name'], $v['email'], make_hash($pw), now()]);
            try { send_mail('New business account: ' . $v['business'], "A business signed up for document services.\n\nBusiness: {$v['business']}\nOwner: {$v['name']}\nEmail: {$v['email']}\nPhone: {$v['phone']}\nTrial ends: " . gmdate('Y-m-d', time() + BIZ_TRIAL_DAYS * 86400), '<p>New business account <b>' . h($v['business']) . '</b> (' . h($v['name']) . ', ' . h($v['phone']) . ', ' . h($v['email']) . ').</p>'); } catch (Throwable $e) {}
            $u = $pdo->prepare('SELECT id FROM biz_users WHERE email = ?'); $u->execute([$v['email']]);
            session_regenerate_id(true); $_SESSION['biz_uid'] = (int) $u->fetchColumn(); $_SESSION['biz_seen'] = time(); flash('Welcome! Add your logo and details in Settings, then create your first document.'); redirect('index.php');
        }
    } catch (Throwable $e) { $err = 'Sign-up is not available yet. Please contact the service provider.'; }
}
biz_head('Start your free trial'); ?>
<form method="post" class="card narrow"><?= csrf_field() ?>
  <p class="muted">Make quotations, invoices, proforma invoices and receipts with your own logo and numbering. Free for <?= BIZ_TRIAL_DAYS ?> days. No card needed.</p>
  <?php if ($err): ?><div class="flash bad"><?= h($err) ?></div><?php endif; ?>
  <label>Business name<input name="business" value="<?= h($v['business']) ?>" maxlength="190" required></label>
  <label>Your name<input name="name" value="<?= h($v['name']) ?>" maxlength="190" autocomplete="name" required></label>
  <label>Email<input type="email" name="email" value="<?= h($v['email']) ?>" autocomplete="username" required></label>
  <label>Phone / WhatsApp<input type="tel" name="phone" value="<?= h($v['phone']) ?>" autocomplete="tel" required></label>
  <label>Password<input type="password" name="password" autocomplete="new-password" minlength="8" required><small class="muted">At least 8 characters with a letter and a number.</small></label>
  <input class="hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
  <button class="btn">Create my account</button>
  <p class="muted c">Already registered? <a href="login.php">Sign in</a></p>
</form>
<?php biz_foot();
