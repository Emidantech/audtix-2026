<?php
require __DIR__ . '/inc/boot.php';
$u = biz_require(); $a = account(); $pdo = db(); $aid = (int) $a['id']; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sj = mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, 190); $m = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 3000);
    if ($sj === '' || $m === '') $err = 'Enter a subject and a message.';
    else { $pdo->prepare("INSERT INTO biz_support (account_id, user_id, subject, message, status, created_at, updated_at) VALUES (?,?,?,?, 'open', ?, ?)")->execute([$aid, $u['id'], $sj, $m, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);
        biz_audit('support_request', $sj); biz_mail_staff('Support request: ' . $a['name'], "$sj\n\n$m\n\nFrom: {$u['full_name']} <{$u['email']}>", $u['email']); flash('Sent. We will reply here.'); redirect('support.php'); }
}
$t = $pdo->prepare('SELECT * FROM biz_support WHERE account_id = ? ORDER BY id DESC LIMIT 30'); $t->execute([$aid]); $t = $t->fetchAll();
biz_head('Support', 'set'); ?>
<?php if ($err): ?><div class="flash bad"><?= h($err) ?></div><?php endif; ?>
<form method="post" class="card"><?= csrf_field() ?><h2>New request</h2><label>Subject<input name="subject" maxlength="190" required></label><label>Message<textarea name="message" rows="4" maxlength="3000" required></textarea></label><div class="actions"><button class="btn">Send</button></div></form>
<div class="card"><h2>Your requests</h2><div class="list"><?php foreach ($t as $x): ?><div class="row"><div><b><?= h($x['subject']) ?></b><small><?= h($x['message']) ?></small><?php if ($x['reply']): ?><small><b>Reply:</b> <?= h($x['reply']) ?></small><?php endif; ?></div><div class="r"><span class="pill <?= $x['status'] === 'closed' ? 'posted' : '' ?>"><?= h(ucfirst($x['status'])) ?></span></div></div><?php endforeach; if (!$t): ?><p class="muted">None yet.</p><?php endif; ?></div></div>
<?php biz_foot();
