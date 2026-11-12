<?php
/* One-time creation of the first super admin. Needs setup_token from the config file.
   Once any super admin exists this page refuses to run. Delete the token from the config afterwards. */
require __DIR__ . '/inc/boot.php';
$pdo = null; $err = ''; $done = false;
try { $pdo = db(); $exists = (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'super_admin'")->fetchColumn(); }
catch (Throwable $e) { $exists = -1; }
$token = (string) cfg('setup_token', '');
page_head('Setup');
echo '<div class="login" style="max-width:480px"><h1><span class="dot"></span>First-time setup</h1>';
if ($exists === -1) echo '<div class="flash bad">Cannot reach the database. Check the db_ values in the config file and that the SQL file was imported.</div>';
elseif ($exists > 0) echo '<p>A super admin already exists, so setup is closed. <a href="login.php">Go to sign in</a>.</p>';
elseif (strlen($token) < 16) echo '<div class="flash bad">Setup is locked. Put a long random <b>setup_token</b> (16+ characters) in your config file first, then reload this page.</div>';
else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $u = strtolower(trim((string) $_POST['username'])); $em = strtolower(trim((string) $_POST['email'])); $fn = trim((string) $_POST['full_name']); $pw = (string) $_POST['password'];
        if (!hash_equals($token, (string) ($_POST['token'] ?? ''))) { $err = 'The setup token is wrong.'; log_login(null, $u, false, 'bad_setup_token'); }
        elseif (!preg_match('/^[a-z0-9._-]{3,40}$/', $u)) $err = 'Username: 3 to 40 letters, numbers, dot, dash or underscore.';
        elseif (!filter_var($em, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email address.';
        elseif (mb_strlen($fn) < 2) $err = 'Full name is required.';
        elseif (($pe = valid_password($pw))) $err = $pe;
        elseif ($pw !== (string) $_POST['password2']) $err = 'The two passwords do not match.';
        else {
            $pdo->prepare('INSERT INTO admins (username, email, full_name, phone, job_title, role, password_hash, is_active, must_change_pw, created_at, updated_at) VALUES (?,?,?,?,?,?,?,1,0,?,?)')
                ->execute([$u, $em, mb_substr($fn, 0, 120), mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, 40) ?: null, 'Super admin', 'super_admin', make_hash($pw), now(), now()]);
            log_activity(null, 'super_admin_created', $u); $done = true;
        }
    }
    if ($done) echo '<div class="flash">Super admin created. <b>Now remove setup_token from your config file</b>, then <a href="login.php">sign in</a>.</div>';
    else {
        if ($err) echo '<div class="flash bad" role="alert">' . h($err) . '</div>';
        echo '<p class="muted">Create the super admin account. This page closes itself afterwards.</p><form method="post" class="stack">' . csrf_field()
          . '<label>Setup token<input name="token" type="password" required autocomplete="off"></label><label>Full name<input name="full_name" required maxlength="120" value="' . h($_POST['full_name'] ?? '') . '"></label>'
          . '<label>Username<input name="username" required maxlength="40" autocapitalize="none" value="' . h($_POST['username'] ?? '') . '"></label><label>Email<input name="email" type="email" required value="' . h($_POST['email'] ?? '') . '"></label>'
          . '<label>Phone (optional)<input name="phone" maxlength="40" value="' . h($_POST['phone'] ?? '') . '"></label>'
          . '<label>Password<input name="password" type="password" required minlength="10" autocomplete="new-password"></label><label>Repeat password<input name="password2" type="password" required minlength="10" autocomplete="new-password"></label>'
          . '<button class="btn">Create super admin</button></form>';
    }
}
echo '</div>'; page_foot();
