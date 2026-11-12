<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
require_perm('requests.view');
$pdo = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$s = $pdo->prepare('SELECT r.*, a.full_name AS assignee FROM requests r LEFT JOIN admins a ON a.id = r.assigned_to WHERE r.id = ?'); $s->execute([$id]); $r = $s->fetch();
if (!$r) { http_response_code(404); page_head('Not found', $admin, 'requests'); echo '<div class="alert alert-warning">Request not found. <a href="requests.php">Back to requests</a></div>'; page_foot(); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    deny_unless($act === 'delete' ? 'requests.delete' : 'requests.edit', 'request.php?id=' . $id);
    if ($act === 'delete') {
        $dir = storage_dir() . '/requests/' . $r['request_id'];
        if (preg_match('/^(TAX|AUDIT)-\d{4}-[A-Z0-9]+$/', $r['request_id']) && is_dir($dir)) { foreach (glob($dir . '/*') ?: [] as $f) @unlink($f); @rmdir($dir); }
        $pdo->prepare('DELETE FROM request_files WHERE request_pk = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM request_events WHERE request_pk = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM requests WHERE id = ?')->execute([$id]);
        log_activity((int) $admin['id'], 'request_deleted', $r['request_id']);
        flash('Request ' . $r['request_id'] . ' deleted.'); redirect('requests.php');
    }
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
if ($r['status'] === 'new' && can('requests.edit')) {
    $pdo->prepare('UPDATE requests SET status = ?, updated_at = ? WHERE id = ? AND status = ?')->execute(['in_review', now(), $id, 'new']);
    $pdo->prepare('INSERT INTO request_events (request_pk, admin_id, event_type, note, created_at) VALUES (?,?,?,?,?)')->execute([$id, $admin['id'], 'status', 'Opened by ' . $admin['full_name'] . ', status set to In review', now()]);
    $r['status'] = 'in_review';
}
$rows = json_decode((string) $r['rows_json'], true) ?: [];
$fs = $pdo->prepare('SELECT * FROM request_files WHERE request_pk = ? ORDER BY id'); $fs->execute([$id]); $files = $fs->fetchAll();
$ev = $pdo->prepare('SELECT e.*, a.full_name FROM request_events e LEFT JOIN admins a ON a.id = e.admin_id WHERE e.request_pk = ? ORDER BY e.id DESC'); $ev->execute([$id]); $events = $ev->fetchAll();
$admins = $pdo->query('SELECT id, full_name FROM admins WHERE is_active = 1 ORDER BY full_name')->fetchAll();
page_head($r['request_id'], $admin, 'requests:' . $r['category'], [['Requests', 'requests.php'], [$r['request_id'], null]]);
function fsize(int $n): string { return $n >= 1048576 ? round($n / 1048576, 1) . ' MB' : max(1, (int) round($n / 1024)) . ' KB'; }
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <span class="tag <?= h($r['category']) ?>"><?= h(CATEGORIES[$r['category']]) ?></span>
  <span class="badge text-bg-<?= STATUS_COLORS[$r['status']] ?? 'secondary' ?>"><?= h(STATUSES[$r['status']] ?? $r['status']) ?></span>
  <span class="text-secondary"><?= h($r['client_name']) ?> &middot; received <?= h(eat($r['created_at'])) ?> &middot; email copy:</span>
  <span class="badge text-bg-<?= $r['email_status'] === 'sent' ? 'success' : ($r['email_status'] === 'failed' ? 'danger' : 'secondary') ?>"><?= h($r['email_status']) ?></span><?= $r['email_error'] ? '<small class="text-danger">' . h($r['email_error']) . '</small>' : '' ?>
</div>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card"><div class="card-header"><h3 class="card-title">Submitted answers</h3></div><div class="card-body"><dl class="rows">
      <?php $lastSec = ''; foreach ($rows as $row): if (($row[1] ?? '') === '') continue; if (($row[2] ?? '') !== '' && $row[2] !== $lastSec) { $lastSec = $row[2]; echo '</dl><div class="sec">' . h($lastSec) . '</div><dl class="rows">'; } ?>
        <div><dt><?= h($row[0]) ?></dt><dd><?= h($row[1]) ?></dd></div>
      <?php endforeach; ?></dl></div></div>
    <div class="card"><div class="card-header"><h3 class="card-title">Documents (<?= count($files) ?>)</h3></div>
      <?php if (!$files): ?><div class="card-body text-secondary">No documents were uploaded.</div><?php else: ?><div class="card-body p-0"><table class="table mb-0"><thead><tr><th>Document</th><th>File</th><th>Size</th><th></th></tr></thead><tbody>
      <?php foreach ($files as $f): ?><tr><td><?= h($f['doc_label'] ?: substr($f['field_key'], 6)) ?></td><td><?= h($f['original_name']) ?></td><td><?= h(fsize((int) $f['size_bytes'])) ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="download.php?id=<?= (int) $f['id'] ?>"><i class="bi bi-download"></i> Download</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
  </div>
  <div class="col-lg-4">
    <div class="card card-outline card-primary"><div class="card-header"><h3 class="card-title">Client</h3></div><div class="card-body"><dl class="rows">
      <div><dt>Name</dt><dd><?= h($r['client_name']) ?></dd></div>
      <?php if ($r['contact_person']): ?><div><dt>Contact</dt><dd><?= h($r['contact_person']) ?></dd></div><?php endif; ?>
      <div><dt>Email</dt><dd><a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a></dd></div>
      <div><dt>Phone</dt><dd><a href="tel:<?= h(preg_replace('/[^\d+]/', '', $r['phone'])) ?>"><?= h($r['phone']) ?></a> &middot; <a href="https://wa.me/<?= h(preg_replace('/\D/', '', $r['phone'])) ?>" rel="noopener noreferrer" target="_blank">WhatsApp</a></dd></div></dl></div></div>
    <?php if (can('requests.edit')): ?>
    <div class="card"><div class="card-header"><h3 class="card-title">Status and assignment</h3></div><div class="card-body">
      <form method="post" class="d-flex gap-2 mb-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="status">
        <select name="status" class="form-select form-select-sm" aria-label="Status"><?php foreach (STATUSES as $k => $v): ?><option value="<?= $k ?>"<?= $r['status'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-primary text-nowrap">Update</button></form>
      <form method="post" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="assign">
        <select name="assigned_to" class="form-select form-select-sm" aria-label="Assigned to"><option value="0">Not assigned</option><?php foreach ($admins as $a): ?><option value="<?= (int) $a['id'] ?>"<?= (int) $r['assigned_to'] === (int) $a['id'] ? ' selected' : '' ?>><?= h($a['full_name']) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline-primary text-nowrap">Assign</button></form></div></div>
    <?php endif; ?>
    <div class="card"><div class="card-header"><h3 class="card-title">Internal notes and history</h3></div><div class="card-body">
      <?php if (can('requests.edit')): ?><form method="post" class="mb-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="note"><textarea name="note" class="form-control mb-2" rows="3" maxlength="2000" placeholder="Add a note for the team (the client never sees this)"></textarea><button class="btn btn-sm btn-primary">Add note</button></form><?php endif; ?>
      <ul class="timeline-list"><?php foreach ($events as $e): ?><li class="ev-<?= h($e['event_type']) ?>"><?= h($e['note']) ?><small><?= h($e['full_name'] ?: 'System') ?> &middot; <?= h(eat($e['created_at'])) ?></small></li><?php endforeach; ?>
        <li>Request received<small>Website &middot; <?= h(eat($r['created_at'])) ?></small></li></ul></div></div>
    <?php if (can('requests.delete')): ?>
    <div class="card card-outline card-danger"><div class="card-body"><form method="post" class="d-flex align-items-center justify-content-between gap-2" data-confirm="Delete <?= h($r['request_id']) ?> permanently, with its documents and history? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="delete"><span class="small text-secondary">Delete this request and its documents permanently.</span><button class="btn btn-sm btn-danger text-nowrap"><i class="bi bi-trash3"></i> Delete</button></form></div></div>
    <?php endif; ?>
  </div>
</div>
<?php page_foot();
