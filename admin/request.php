<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
$pdo = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$s = $pdo->prepare('SELECT r.*, a.full_name AS assignee FROM requests r LEFT JOIN admins a ON a.id = r.assigned_to WHERE r.id = ?'); $s->execute([$id]); $r = $s->fetch();
if (!$r) { http_response_code(404); page_head('Not found', $admin); echo '<p>Request not found. <a href="index.php">Back to requests</a></p>'; page_foot(); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'status') {
        $new = (string) ($_POST['status'] ?? '');
        if (isset(STATUSES[$new]) && $new !== $r['status']) {
            $pdo->prepare('UPDATE requests SET status = ?, updated_at = ? WHERE id = ?')->execute([$new, now(), $id]);
            $pdo->prepare('INSERT INTO request_events (request_pk, admin_id, event_type, note, created_at) VALUES (?,?,?,?,?)')->execute([$id, $admin['id'], 'status', 'Status changed from ' . STATUSES[$r['status']] . ' to ' . STATUSES[$new], now()]);
            log_activity((int) $admin['id'], 'request_status', $r['request_id'], $new);
            flash('Status updated.');
        }
    } elseif ($act === 'note') {
        $n = trim((string) ($_POST['note'] ?? ''));
        if ($n !== '') { $pdo->prepare('INSERT INTO request_events (request_pk, admin_id, event_type, note, created_at) VALUES (?,?,?,?,?)')->execute([$id, $admin['id'], 'note', mb_substr($n, 0, 2000), now()]); log_activity((int) $admin['id'], 'request_note', $r['request_id']); flash('Note added.'); }
    } elseif ($act === 'assign') {
        $to = (int) ($_POST['assigned_to'] ?? 0);
        $name = 'nobody';
        if ($to) { $q = $pdo->prepare('SELECT full_name FROM admins WHERE id = ? AND is_active = 1'); $q->execute([$to]); $name = $q->fetchColumn(); if (!$name) redirect('request.php?id=' . $id); }
        $pdo->prepare('UPDATE requests SET assigned_to = ?, updated_at = ? WHERE id = ?')->execute([$to ?: null, now(), $id]);
        $pdo->prepare('INSERT INTO request_events (request_pk, admin_id, event_type, note, created_at) VALUES (?,?,?,?,?)')->execute([$id, $admin['id'], 'assign', 'Assigned to ' . $name, now()]);
        flash('Assignment saved.');
    }
    redirect('request.php?id=' . $id);
}
// First time a request is opened while it is still "new", move it to "in review".
if ($r['status'] === 'new') {
    $pdo->prepare('UPDATE requests SET status = ?, updated_at = ? WHERE id = ? AND status = ?')->execute(['in_review', now(), $id, 'new']);
    $pdo->prepare('INSERT INTO request_events (request_pk, admin_id, event_type, note, created_at) VALUES (?,?,?,?,?)')->execute([$id, $admin['id'], 'status', 'Opened by ' . $admin['full_name'] . ', status set to In review', now()]);
    $r['status'] = 'in_review';
}
$rows = json_decode((string) $r['rows_json'], true) ?: [];
$fs = $pdo->prepare('SELECT * FROM request_files WHERE request_pk = ? ORDER BY id'); $fs->execute([$id]); $files = $fs->fetchAll();
$ev = $pdo->prepare('SELECT e.*, a.full_name FROM request_events e LEFT JOIN admins a ON a.id = e.admin_id WHERE e.request_pk = ? ORDER BY e.id DESC'); $ev->execute([$id]); $events = $ev->fetchAll();
$admins = $pdo->query('SELECT id, full_name FROM admins WHERE is_active = 1 ORDER BY full_name')->fetchAll();
page_head($r['request_id'], $admin);
function fsize(int $n): string { return $n >= 1048576 ? round($n / 1048576, 1) . ' MB' : max(1, (int) round($n / 1024)) . ' KB'; }
?>
<p><a href="index.php">&larr; All requests</a></p>
<h1 class="mono"><?= h($r['request_id']) ?> <span class="tag <?= h($r['category']) ?>"><?= h(CATEGORIES[$r['category']]) ?></span></h1>
<p class="muted"><?= h($r['client_name']) ?> &middot; received <?= h(eat($r['created_at'])) ?> (EAT) &middot; email copy: <span class="pill <?= $r['email_status'] === 'sent' ? 'good' : ($r['email_status'] === 'failed' ? 'bad' : '') ?>"><?= h($r['email_status']) ?></span><?= $r['email_error'] ? ' <small>' . h($r['email_error']) . '</small>' : '' ?></p>
<div class="grid2">
  <div>
    <div class="card"><h2>Submitted answers</h2><dl class="rows">
      <?php $lastSec = ''; foreach ($rows as $row): if (($row[1] ?? '') === '') continue; if (($row[2] ?? '') !== '' && $row[2] !== $lastSec) { $lastSec = $row[2]; echo '</dl><div class="sec">' . h($lastSec) . '</div><dl class="rows">'; } ?>
        <div><dt><?= h($row[0]) ?></dt><dd><?= h($row[1]) ?></dd></div>
      <?php endforeach; ?></dl></div>
    <div class="card"><h2>Documents (<?= count($files) ?>)</h2>
      <?php if (!$files): ?><p class="muted">No documents were uploaded.</p><?php else: ?><table><thead><tr><th>Document</th><th>File</th><th>Size</th><th></th></tr></thead><tbody>
      <?php foreach ($files as $f): ?><tr><td><?= h($f['doc_label'] ?: substr($f['field_key'], 6)) ?></td><td><?= h($f['original_name']) ?></td><td><?= h(fsize((int) $f['size_bytes'])) ?></td><td><a class="btn sm ghost" href="download.php?id=<?= (int) $f['id'] ?>">Download</a></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
  </div>
  <div>
    <div class="card"><h2>Client</h2><dl class="rows">
      <div><dt>Name</dt><dd><?= h($r['client_name']) ?></dd></div>
      <?php if ($r['contact_person']): ?><div><dt>Contact</dt><dd><?= h($r['contact_person']) ?></dd></div><?php endif; ?>
      <div><dt>Email</dt><dd><a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a></dd></div>
      <div><dt>Phone</dt><dd><a href="tel:<?= h(preg_replace('/[^\d+]/', '', $r['phone'])) ?>"><?= h($r['phone']) ?></a> &middot; <a href="https://wa.me/<?= h(preg_replace('/\D/', '', $r['phone'])) ?>" rel="noopener noreferrer" target="_blank">WhatsApp</a></dd></div></dl></div>
    <div class="card"><h2>Status</h2>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="status">
        <select name="status"><?php foreach (STATUSES as $k => $v): ?><option value="<?= $k ?>"<?= $r['status'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select><button class="btn sm">Update status</button></form>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="assign">
        <label>Assigned to<select name="assigned_to"><option value="0">Nobody</option><?php foreach ($admins as $a): ?><option value="<?= (int) $a['id'] ?>"<?= (int) $r['assigned_to'] === (int) $a['id'] ? ' selected' : '' ?>><?= h($a['full_name']) ?></option><?php endforeach; ?></select></label><button class="btn sm ghost">Save</button></form></div>
    <div class="card"><h2>Internal notes and history</h2>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="note"><textarea name="note" maxlength="2000" placeholder="Add a note for the team (the client never sees this)"></textarea><button class="btn sm">Add note</button></form>
      <ul class="timeline"><?php foreach ($events as $e): ?><li><?= h($e['note']) ?><small><?= h($e['full_name'] ?: 'System') ?> &middot; <?= h(eat($e['created_at'])) ?></small></li><?php endforeach; ?>
        <li>Request received<small>Website &middot; <?= h(eat($r['created_at'])) ?></small></li></ul></div>
  </div>
</div>
<?php page_foot();
