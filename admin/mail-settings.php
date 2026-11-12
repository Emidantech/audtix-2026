<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
require_perm('mail.view');
$me = (int) $admin['id'];
$emails = fn(string $v) => implode(', ', array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $v)), fn($e) => $e !== '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    deny_unless('mail.edit', 'mail-settings.php');
    if (($_POST['action'] ?? '') === 'test') {
        $to = trim((string) ($_POST['test_to'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { flash('Enter the email address to send the test to.', 'bad'); redirect('mail-settings.php'); }
        $m = mail_config();
        [$ok, $err] = send_mail_to([$to], [], 'Test email from the Audtix admin panel',
            "This is a test from Admin > Business settings > Email and notifications.\nMethod: {$m['method']}\nSent by: {$admin['full_name']}\nTime (UTC): " . now(),
            '<div style="font-family:Arial,sans-serif;font-size:15px"><p>This is a test from <b>Admin &gt; Business settings &gt; Email and notifications</b>.</p><p>Method: <b>' . h($m['method']) . '</b><br>Sent by: ' . h($admin['full_name']) . '<br>Time (UTC): ' . h(now()) . '</p><p>If you can read this, email is working.</p></div>');
        log_activity($me, 'mail_test', $to, $ok ? 'sent' : $err);
        flash($ok ? 'Test email sent to ' . $to . '. Check the inbox (and the spam folder).' : 'The test email failed: ' . $err, $ok ? 'ok' : 'bad');
        redirect('mail-settings.php');
    }
    $method = in_array($_POST['mail_method'] ?? '', ['smtp', 'mail'], true) ? $_POST['mail_method'] : 'smtp';
    $save = ['mail_method' => $method];
    $from = trim((string) ($_POST['mail_from'] ?? '')); $to = $emails((string) ($_POST['notify_to'] ?? '')); $cc = $emails((string) ($_POST['notify_cc'] ?? ''));
    $err = [];
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) $err[] = 'Enter a valid "send from" address.';
    foreach (array_filter(explode(', ', $to . ($cc ? ', ' . $cc : ''))) as $e) if (!filter_var($e, FILTER_VALIDATE_EMAIL)) $err[] = '"' . $e . '" is not a valid email address.';
    if ($to === '') $err[] = 'Add at least one address to receive system notifications.';
    if ($method === 'smtp') {
        $host = trim((string) ($_POST['smtp_host'] ?? '')); $port = (int) ($_POST['smtp_port'] ?? 0); $sec = in_array($_POST['smtp_secure'] ?? '', ['ssl', 'tls', 'none'], true) ? $_POST['smtp_secure'] : 'ssl';
        if (!preg_match('/^[a-z0-9.-]{3,190}$/i', $host)) $err[] = 'Enter the SMTP server name, for example smtp.hostinger.com.';
        if ($port < 1 || $port > 65535) $err[] = 'Enter the SMTP port (465 for SSL, 587 for TLS).';
        $save += ['smtp_host' => $host, 'smtp_port' => (string) $port, 'smtp_secure' => $sec, 'smtp_user' => mb_substr(trim((string) ($_POST['smtp_user'] ?? '')), 0, 190)];
        $pw = (string) ($_POST['smtp_pass'] ?? '');
        if ($pw !== '') $save['smtp_pass'] = secret_encrypt($pw);
        if (!empty($_POST['clear_pass'])) $save['smtp_pass'] = '';
    }
    if ($err) { flash(implode(' ', $err), 'bad'); redirect('mail-settings.php'); }
    $save += ['mail_from' => $from, 'mail_from_name' => mb_substr(trim((string) ($_POST['mail_from_name'] ?? '')), 0, 120), 'notify_to' => $to, 'notify_cc' => $cc,
              'notify_on_quote' => empty($_POST['notify_on_quote']) ? '0' : '1', 'notify_on_tax' => empty($_POST['notify_on_tax']) ? '0' : '1', 'notify_on_audit' => empty($_POST['notify_on_audit']) ? '0' : '1'];
    settings_save($save, $me);
    log_activity($me, 'mail_settings_saved', $method);
    flash('Email settings saved. Send a test email to check them.');
    redirect('mail-settings.php');
}

$m = mail_config(); $s = settings_all();
$hasDbPass = ($s['smtp_pass'] ?? '') !== ''; $hasCfgPass = !$hasDbPass && (string) cfg('smtp_pass', '') !== '';
$ro = can('mail.edit') ? '' : ' disabled';
$method = $m['method'] === 'file' ? 'smtp' : $m['method'];
page_head('Email and notifications', $admin, 'mail', [['Business settings', 'settings.php'], ['Email and notifications', null]]);
?>
<div class="row g-3">
<div class="col-xl-8">
<form method="post"><?= csrf_field() ?>
  <div class="card"><div class="card-header"><h3 class="card-title">How the system sends email</h3></div><div class="card-body">
    <div class="row g-2 mb-3">
      <div class="col-md-6"><label class="card h-100 p-3 mb-0"><span class="form-check"><input class="form-check-input" type="radio" name="mail_method" value="smtp"<?= $method === 'smtp' ? ' checked' : '' ?><?= $ro ?>><span class="form-check-label fw-semibold">SMTP mail server</span></span><span class="small text-secondary mt-1">Recommended. Sends through a real mailbox (Hostinger, Google Workspace, Microsoft 365, Zoho...). Less likely to land in spam.</span></label></div>
      <div class="col-md-6"><label class="card h-100 p-3 mb-0"><span class="form-check"><input class="form-check-input" type="radio" name="mail_method" value="mail"<?= $method === 'mail' ? ' checked' : '' ?><?= $ro ?>><span class="form-check-label fw-semibold">Hosting server mail (PHP mail)</span></span><span class="small text-secondary mt-1">Uses the web host's built-in mail. No password needed, but some inboxes treat it as spam.</span></label></div>
    </div>
    <div data-smtp>
      <div class="row g-2">
        <div class="col-md-12"><label class="form-label" for="smtp-preset">Quick fill</label><select id="smtp-preset" class="form-select"<?= $ro ?>><option value="">Choose your email provider (optional)</option>
          <option data-host="smtp.hostinger.com" data-port="465" data-secure="ssl">Hostinger email</option><option data-host="smtp.gmail.com" data-port="465" data-secure="ssl">Gmail / Google Workspace</option>
          <option data-host="smtp.office365.com" data-port="587" data-secure="tls">Microsoft 365 / Outlook</option><option data-host="smtp.zoho.com" data-port="465" data-secure="ssl">Zoho Mail</option><option data-host="mail.yourdomain.com" data-port="465" data-secure="ssl">cPanel hosting (mail.yourdomain.com)</option></select></div>
        <div class="col-md-6"><label class="form-label" for="smtp_host">SMTP server</label><input class="form-control" id="smtp_host" name="smtp_host" value="<?= h($m['smtp_host']) ?>" placeholder="smtp.hostinger.com"<?= $ro ?>></div>
        <div class="col-6 col-md-3"><label class="form-label" for="smtp_port">Port</label><input class="form-control" id="smtp_port" name="smtp_port" inputmode="numeric" value="<?= h((string) $m['smtp_port']) ?>"<?= $ro ?>></div>
        <div class="col-6 col-md-3"><label class="form-label" for="smtp_secure">Security</label><select class="form-select" id="smtp_secure" name="smtp_secure"<?= $ro ?>><?php foreach (['ssl' => 'SSL (465)', 'tls' => 'STARTTLS (587)', 'none' => 'None (not advised)'] as $k => $l): ?><option value="<?= $k ?>"<?= $m['smtp_secure'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label" for="smtp_user">Username</label><input class="form-control" id="smtp_user" name="smtp_user" autocomplete="off" value="<?= h($m['smtp_user']) ?>" placeholder="usually the full email address"<?= $ro ?>></div>
        <div class="col-md-6"><label class="form-label" for="smtp_pass">Password</label><input class="form-control" id="smtp_pass" name="smtp_pass" type="password" autocomplete="new-password" placeholder="<?= $hasDbPass ? 'Saved. Leave empty to keep it' : ($hasCfgPass ? 'Using the password in the config file' : 'Mailbox password or app password') ?>"<?= $ro ?>>
          <?php if ($hasDbPass): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="clear_pass" value="1" id="clear_pass"<?= $ro ?>><label class="form-check-label small" for="clear_pass">Remove the saved password</label></div><?php endif; ?>
          <div class="form-text">Stored encrypted. Gmail and Microsoft 365 need an "app password".</div></div>
      </div>
      <p class="small text-secondary mt-2 mb-0"><i class="bi bi-info-circle"></i> IMAP and POP3 are for <i>reading</i> a mailbox. The website only <i>sends</i> email, so it needs SMTP (or server mail), not IMAP or POP3.</p>
    </div>
    <hr>
    <div class="row g-2">
      <div class="col-md-6"><label class="form-label" for="mail_from">Send from (address)</label><input class="form-control" id="mail_from" name="mail_from" type="email" required value="<?= h($m['mail_from']) ?>"<?= $ro ?>><div class="form-text">With SMTP, use the same mailbox as the username.</div></div>
      <div class="col-md-6"><label class="form-label" for="mail_from_name">Send from (name)</label><input class="form-control" id="mail_from_name" name="mail_from_name" maxlength="120" value="<?= h($m['mail_from_name']) ?>"<?= $ro ?>></div>
    </div>
  </div></div>

  <div class="card"><div class="card-header"><h3 class="card-title">System notifications</h3></div><div class="card-body">
    <p class="small text-secondary">When someone submits a form on the website, admins see it under the bell in this panel, and an email goes to the addresses below.</p>
    <div class="row g-2">
      <div class="col-md-6"><label class="form-label" for="notify_to">Send notifications to</label><input class="form-control" id="notify_to" name="notify_to" value="<?= h($m['notify_to']) ?>" placeholder="info@audtix.com"<?= $ro ?>><div class="form-text">One or more addresses, separated by commas.</div></div>
      <div class="col-md-6"><label class="form-label" for="notify_cc">Copy (CC)</label><input class="form-control" id="notify_cc" name="notify_cc" value="<?= h($m['notify_cc']) ?>" placeholder="optional"<?= $ro ?>></div>
    </div>
    <div class="mt-3"><span class="form-label d-block">Email the team when someone sends</span>
      <?php foreach (['quote' => 'A quotation request (all services)', 'tax' => 'A tax service request', 'audit' => 'An audit service request'] as $k => $l): ?>
      <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="notify_on_<?= $k ?>" value="1" id="n-<?= $k ?>"<?= notify_enabled($k) ? ' checked' : '' ?><?= $ro ?>><label class="form-check-label" for="n-<?= $k ?>"><?= h($l) ?></label></div>
      <?php endforeach; ?>
      <div class="form-text">Turning one off stops the email only. It still appears under the bell. If the database is ever down, the email is sent anyway so nothing is lost.</div></div>
  </div></div>
  <?php if (can('mail.edit')): ?><button class="btn btn-primary mb-4"><i class="bi bi-check2"></i> Save email settings</button><?php endif; ?>
</form>
</div>
<div class="col-xl-4">
  <div class="card card-outline card-success"><div class="card-header"><h3 class="card-title">Send a test email</h3></div><div class="card-body">
    <p class="small text-secondary">Save first, then send a test to check the settings work.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test">
      <div class="input-group"><input class="form-control" type="email" name="test_to" required value="<?= h($admin['email']) ?>" aria-label="Send test to"<?= $ro ?>><button class="btn btn-success"<?= $ro ?>><i class="bi bi-send"></i> Send</button></div></form>
  </div></div>
  <div class="card"><div class="card-body small"><b>Currently in use</b><br>Method: <?= h($m['method'] === 'file' ? 'test file' : ($m['method'] === 'smtp' ? 'SMTP' : 'server mail')) ?><?= $m['method'] === 'smtp' ? '<br>Server: ' . h($m['smtp_host']) . ':' . (int) $m['smtp_port'] . ' (' . h($m['smtp_secure']) . ')' : '' ?><br>From: <?= h($m['mail_from_name']) ?> &lt;<?= h($m['mail_from']) ?>&gt;<br>Settings saved here override the config file.</div></div>
</div>
</div>
<?php page_foot();
