<?php
/* SaaS support requests from businesses, and announcements shown to every business. */
require __DIR__ . '/inc/boot.php';
$admin = require_login(); $pdo = db(); require_perm('bizsupport.view'); $self = 'biz-support.php';
ob_start();
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    deny_unless('bizsupport.edit', $self); $do = (string) ($_POST['do'] ?? ''); $now = gmdate('Y-m-d H:i:s');
    if ($do === 'reply') { $id = (int) ($_POST['id'] ?? 0); $st = ($_POST['close'] ?? '') === '1' ? 'closed' : 'answered'; $pdo->prepare('UPDATE biz_support SET reply = ?, status = ?, updated_at = ? WHERE id = ?')->execute([mb_substr(trim((string) ($_POST['reply'] ?? '')), 0, 3000), $st, $now, $id]); log_activity((int) $admin['id'], 'biz_support_reply', (string) $id); flash('Reply saved. The business sees it under Support.'); }
    elseif ($do === 'announce') { $t = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 190); $b = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, 2000); $e = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['expires_on'] ?? '')) ? $_POST['expires_on'] : null;
        if ($t === '' || $b === '') flash('Enter a title and message.', 'bad'); else { $pdo->prepare('INSERT INTO biz_announcements (title, body, expires_on, created_at) VALUES (?,?,?,?)')->execute([$t, $b, $e, $now]); log_activity((int) $admin['id'], 'biz_announce', $t); flash('Announcement is now shown to all businesses.'); } }
    elseif ($do === 'expire') { $pdo->prepare('UPDATE biz_announcements SET expires_on = ? WHERE id = ?')->execute([gmdate('Y-m-d', time() - 86400), (int) ($_POST['id'] ?? 0)]); flash('Announcement ended.'); }
    redirect($self);
  }
  $t = $pdo->query('SELECT s.*, a.name business, u.full_name, u.email FROM biz_support s LEFT JOIN biz_accounts a ON a.id = s.account_id LEFT JOIN biz_users u ON u.id = s.user_id ORDER BY (s.status = \'closed\'), s.id DESC LIMIT 100')->fetchAll();
  $an = $pdo->query('SELECT * FROM biz_announcements ORDER BY id DESC LIMIT 20')->fetchAll(); $can = can('bizsupport.edit'); $today = gmdate('Y-m-d');
  page_head('Support and notices', $admin, 'bizacc:support');
  if ($can) echo '<div class="card"><div class="card-header"><h3 class="card-title">Announcement to all businesses</h3></div><div class="card-body"><form method="post" class="row g-2">' . csrf_field() . '<input type="hidden" name="do" value="announce"><div class="col-md-4"><input class="form-control" name="title" placeholder="Title" required maxlength="190"></div><div class="col-md-5"><input class="form-control" name="body" placeholder="Message" required maxlength="2000"></div><div class="col-md-2"><input class="form-control" type="date" name="expires_on" title="Optional end date"></div><div class="col-md-1"><button class="btn btn-primary w-100">Post</button></div></form></div></div>';
  if ($an) { echo '<div class="card"><div class="card-body small">'; foreach ($an as $x) { $live = !$x['expires_on'] || $x['expires_on'] >= $today; echo '<div class="d-flex justify-content-between border-bottom py-1"><span><b>' . h($x['title']) . '</b> ' . h($x['body']) . ' <span class="badge bg-' . ($live ? 'success' : 'secondary') . '">' . ($live ? 'live' : 'ended') . '</span></span>' . ($can && $live ? '<form method="post">' . csrf_field() . '<input type="hidden" name="do" value="expire"><input type="hidden" name="id" value="' . (int) $x['id'] . '"><button class="btn btn-sm btn-outline-secondary">End</button></form>' : '') . '</div>'; } echo '</div></div>'; }
  echo '<h4 class="mt-3">Support requests</h4>'; if (!$t) echo '<p class="text-muted">None yet.</p>';
  foreach ($t as $x) { echo '<div class="card"><div class="card-body"><div class="d-flex justify-content-between"><b>' . h($x['subject']) . '</b><span class="badge bg-' . ($x['status'] === 'closed' ? 'secondary' : ($x['status'] === 'open' ? 'warning text-dark' : 'info')) . '">' . h(ucfirst($x['status'])) . '</span></div><div class="small text-muted">' . h($x['business'] . ' · ' . $x['full_name'] . ' <' . $x['email'] . '> · ' . $x['created_at']) . '</div><p class="mt-2">' . nl2br(h($x['message'])) . '</p>';
    if ($can && $x['status'] !== 'closed') echo '<form method="post">' . csrf_field() . '<input type="hidden" name="do" value="reply"><input type="hidden" name="id" value="' . (int) $x['id'] . '"><textarea class="form-control mb-2" name="reply" rows="2" maxlength="3000" placeholder="Reply">' . h((string) $x['reply']) . '</textarea><button class="btn btn-sm btn-primary">Send reply</button> <button class="btn btn-sm btn-outline-secondary" name="close" value="1">Reply and close</button></form>'; elseif ($x['reply']) echo '<div class="small"><b>Reply:</b> ' . h($x['reply']) . '</div>';
    echo '</div></div>'; }
} catch (Throwable $e) { ob_clean(); page_head('Support and notices', $admin, 'bizacc:support'); mod_setup_notice($e); }
page_foot(); ob_end_flush();
