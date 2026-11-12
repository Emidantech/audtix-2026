<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
require_perm('quotes.view');
$pdo = db(); $me = (int) $admin['id'];
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$s = $pdo->prepare('SELECT q.*, a.full_name AS assignee, rb.full_name AS responder, cb.full_name AS creator FROM quotes q LEFT JOIN admins a ON a.id = q.assigned_to LEFT JOIN admins rb ON rb.id = q.responded_by LEFT JOIN admins cb ON cb.id = q.created_by WHERE q.id = ?');
$s->execute([$id]); $q = $s->fetch();
if (!$q) { http_response_code(404); page_head('Not found', $admin, 'quotes:all'); echo '<div class="alert alert-warning">Quotation not found. <a href="quotes.php">Back to quotations</a></div>'; page_foot(); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? ''); $back = 'quote.php?id=' . $id;
    deny_unless(['status' => 'quotes.edit', 'assign' => 'quotes.edit', 'note' => 'quotes.edit', 'respond' => 'quotes.respond', 'delete' => 'quotes.delete'][$act] ?? 'quotes.edit', $back);
    if ($act === 'status') {
        $new = (string) ($_POST['status'] ?? '');
        if (isset(QUOTE_STATUSES[$new]) && $new !== $q['status']) {
            $pdo->prepare('UPDATE quotes SET status = ?, updated_at = ? WHERE id = ?')->execute([$new, now(), $id]);
            $why = trim((string) ($_POST['reason'] ?? ''));
            quote_event($pdo, $id, $me, 'status', 'Status changed from ' . QUOTE_STATUSES[$q['status']] . ' to ' . QUOTE_STATUSES[$new] . ($why !== '' ? ': ' . $why : ''));
            log_activity($me, 'quote_status', $q['quote_no'], $new);
            flash('Status set to ' . QUOTE_STATUSES[$new] . '.');
        }
    } elseif ($act === 'assign') {
        $to = (int) ($_POST['assigned_to'] ?? 0); $name = 'nobody';
        if ($to) { $x = $pdo->prepare('SELECT full_name FROM admins WHERE id = ? AND is_active = 1'); $x->execute([$to]); $name = $x->fetchColumn(); if (!$name) redirect('quote.php?id=' . $id); }
        $pdo->prepare('UPDATE quotes SET assigned_to = ?, updated_at = ? WHERE id = ?')->execute([$to ?: null, now(), $id]);
        quote_event($pdo, $id, $me, 'assign', 'Assigned to ' . $name);
        flash('Assignment saved.');
    } elseif ($act === 'note') {
        $n = trim((string) ($_POST['note'] ?? ''));
        if ($n !== '') { quote_event($pdo, $id, $me, 'note', $n); log_activity($me, 'quote_note', $q['quote_no']); flash('Note added.'); }
    } elseif ($act === 'respond') {
        $amt = str_replace([',', ' '], '', (string) ($_POST['amount'] ?? ''));
        $cur = in_array($_POST['currency'] ?? '', ['UGX', 'USD', 'KES', 'EUR', 'GBP'], true) ? $_POST['currency'] : 'UGX';
        $valid = (string) ($_POST['valid_until'] ?? ''); $msg = trim((string) ($_POST['message'] ?? ''));
        $send = !empty($_POST['send_email']);
        $err = '';
        if ($amt === '' || !is_numeric($amt) || (float) $amt < 0 || (float) $amt > 9999999999999) $err = 'Enter the quoted amount as a number.';
        elseif ($valid !== '' && (!($d = DateTime::createFromFormat('!Y-m-d', $valid)) || $d->format('Y-m-d') !== $valid)) $err = 'Enter a valid "valid until" date.';
        elseif (mb_strlen($msg) < 5) $err = 'Write a short message to the client.';
        [$docs, $fe] = quote_take_uploads('quote_pdf', 3, true);
        if (!$err && $fe) $err = $fe;
        if ($err) { flash($err, 'bad'); redirect('quote.php?id=' . $id . '#respond'); }
        $stored = quote_store_files($pdo, $id, $q['quote_no'], $docs, 'response');
        $pdo->prepare('UPDATE quotes SET status = ?, quoted_amount = ?, currency = ?, valid_until = ?, response_message = ?, responded_at = ?, responded_by = ?, updated_at = ? WHERE id = ?')
            ->execute(['responded', round((float) $amt, 2), $cur, $valid ?: null, mb_substr($msg, 0, 5000), now(), $me, now(), $id]);
        $line = 'Quotation of ' . money((string) $amt, $cur) . ($valid ? ', valid until ' . date('j M Y', strtotime($valid)) : '') . (count($stored) ? ', ' . count($stored) . ' document(s) attached' : '');
        $mailNote = '';
        if ($send) {
            $title = quote_service_title($q['service']); $first = trim(explode(' ', (string) $q['client_name'])[0]);
            $subject = "Your quotation $q[quote_no]: $title | Audtix Systems";
            $text = "Dear $first,\n\n$msg\n\nQuotation: $q[quote_no]\nService: $title\nAmount: " . money((string) $amt, $cur) . ($valid ? "\nValid until: " . date('j M Y', strtotime($valid)) : '') . "\n\nReply to this email or WhatsApp +256 701 287 388 to go ahead.\n\n" . $admin['full_name'] . "\nAudtix Systems";
            $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#10202f;max-width:620px"><p>Dear ' . h($first) . ',</p><p style="white-space:pre-wrap">' . h($msg) . '</p>'
                  . '<table style="border-collapse:collapse;margin:18px 0;background:#f4f8fd;border-radius:10px"><tr><td style="padding:8px 16px;color:#5a6b7d">Quotation</td><td style="padding:8px 16px;font-family:monospace;font-weight:bold">' . h($q['quote_no']) . '</td></tr>'
                  . '<tr><td style="padding:8px 16px;color:#5a6b7d">Service</td><td style="padding:8px 16px">' . h($title) . '</td></tr>'
                  . '<tr><td style="padding:8px 16px;color:#5a6b7d">Amount</td><td style="padding:8px 16px;font-size:18px;font-weight:bold">' . h(money((string) $amt, $cur)) . '</td></tr>'
                  . ($valid ? '<tr><td style="padding:8px 16px;color:#5a6b7d">Valid until</td><td style="padding:8px 16px">' . h(date('j M Y', strtotime($valid))) . '</td></tr>' : '') . '</table>'
                  . '<p>Reply to this email or <a href="https://wa.me/256701287388">WhatsApp +256 701 287 388</a> to go ahead.</p><p>' . h($admin['full_name']) . '<br>Audtix Systems</p></div>';
            $cc = array_values(array_filter(array_map('trim', explode(',', (string) cfg('notify_to', '')))));
            [$ok, $merr] = send_mail_to([$q['email']], $cc, $subject, $text, $html, array_map(fn($f) => ['name' => $f['orig'], 'mime' => $f['mime'], 'path' => $f['path']], $stored), (string) cfg('notify_to', ''));
            $mailNote = $ok ? ' Emailed to ' . $q['email'] . '.' : ' The email to the client FAILED: ' . $merr;
            $line .= $ok ? '; emailed to ' . $q['email'] : '; email to client failed (' . mb_substr($merr, 0, 120) . ')';
            flash('Quotation saved.' . $mailNote, $ok ? 'ok' : 'bad');
        } else flash('Quotation saved. It was not emailed; send it to the client yourself.');
        quote_event($pdo, $id, $me, 'response', $line);
        log_activity($me, 'quote_responded', $q['quote_no'], money((string) $amt, $cur));
    } elseif ($act === 'delete') {
        quote_delete($pdo, $q, $me);
        flash('Quotation ' . $q['quote_no'] . ' deleted.'); redirect('quotes.php');
    }
    redirect('quote.php?id=' . $id);
}

// First time a pending quotation is opened, move it to "in review".
if ($q['status'] === 'pending' && can('quotes.edit')) {
    $pdo->prepare('UPDATE quotes SET status = ?, updated_at = ? WHERE id = ? AND status = ?')->execute(['in_review', now(), $id, 'pending']);
    quote_event($pdo, $id, $me, 'status', 'Opened by ' . $admin['full_name'] . ', status set to In review');
    $q['status'] = 'in_review';
}
$rows = json_decode((string) $q['rows_json'], true) ?: [];
$fs = $pdo->prepare('SELECT * FROM quote_files WHERE quote_pk = ? ORDER BY kind, id'); $fs->execute([$id]); $files = $fs->fetchAll();
$ev = $pdo->prepare('SELECT e.*, a.full_name FROM quote_events e LEFT JOIN admins a ON a.id = e.admin_id WHERE e.quote_pk = ? ORDER BY e.id DESC'); $ev->execute([$id]); $events = $ev->fetchAll();
$admins = $pdo->query('SELECT id, full_name FROM admins WHERE is_active = 1 ORDER BY full_name')->fetchAll();
function fsize(int $n): string { return $n >= 1048576 ? round($n / 1048576, 1) . ' MB' : max(1, (int) round($n / 1024)) . ' KB'; }
$wa = preg_replace('/\D/', '', $q['phone']); if (str_starts_with($wa, '0')) $wa = '256' . substr($wa, 1);

$actions = '<a class="btn btn-sm btn-outline-secondary" href="quotes.php?service=' . h($q['service']) . '"><i class="bi bi-arrow-left"></i> Back</a>'
         . (can('quotes.edit') ? '<a class="btn btn-sm btn-outline-primary" href="quote-edit.php?id=' . $id . '"><i class="bi bi-pencil"></i> Edit</a>' : '')
         . (can('quotes.respond') ? '<a class="btn btn-sm btn-primary" href="#respond"><i class="bi bi-send"></i> ' . ($q['responded_at'] ? 'Send revised quote' : 'Send quotation') . '</a>' : '');
page_head($q['quote_no'], $admin, 'quote', [], $actions);
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <span class="tag"><?= h(quote_service_tag($q['service'])) ?></span><b><?= h(quote_service_title($q['service'])) ?></b><?= status_badge($q['status']) ?>
  <span class="text-secondary small">received <?= h(eat($q['created_at'])) ?> &middot; <?= $q['source'] === 'admin' ? 'entered by ' . h($q['creator'] ?: 'an admin') : 'from the website' ?> &middot; team email:</span>
  <span class="badge text-bg-<?= $q['email_status'] === 'sent' ? 'success' : ($q['email_status'] === 'failed' ? 'danger' : 'secondary') ?>"><?= h($q['email_status']) ?></span><?= $q['email_error'] ? '<small class="text-danger">' . h($q['email_error']) . '</small>' : '' ?>
</div>
<div class="row g-3">
  <div class="col-lg-8">
    <?php if ($q['responded_at']): ?>
    <div class="card card-outline card-success"><div class="card-header"><h3 class="card-title"><i class="bi bi-send-check text-success"></i> Quotation sent</h3><div class="card-tools small text-secondary"><?= h(eat($q['responded_at'])) ?> by <?= h($q['responder'] ?: 'an admin') ?></div></div>
      <div class="card-body"><div class="quote-amount"><?= h(money($q['quoted_amount'], $q['currency'])) ?></div><?= $q['valid_until'] ? '<div class="text-secondary mb-2">Valid until ' . h(date('j M Y', strtotime($q['valid_until']))) . '</div>' : '' ?><p class="mb-0" style="white-space:pre-wrap"><?= h($q['response_message']) ?></p></div></div>
    <?php endif; ?>
    <div class="card"><div class="card-header"><h3 class="card-title">Request details</h3></div><div class="card-body"><dl class="rows">
      <?php $last = ''; foreach ($rows as $row): if (($row[1] ?? '') === '' || ($row[2] ?? '') === 'Quotation') continue; if (($row[2] ?? '') !== $last) { $last = $row[2]; echo '</dl><div class="sec">' . h($last) . '</div><dl class="rows">'; } ?>
        <div><dt><?= h($row[0]) ?></dt><dd><?= h($row[1]) ?></dd></div>
      <?php endforeach; ?></dl></div></div>
    <div class="card"><div class="card-header"><h3 class="card-title">Documents (<?= count($files) ?>)</h3></div>
      <?php if (!$files): ?><div class="card-body text-secondary">No documents yet.</div><?php else: ?><div class="card-body p-0"><table class="table mb-0"><thead><tr><th>From</th><th>File</th><th>Size</th><th>Added</th><th></th></tr></thead><tbody>
      <?php foreach ($files as $f): ?><tr><td><?= $f['kind'] === 'client' ? '<span class="badge text-bg-secondary">Client</span>' : '<span class="badge text-bg-success">Our quotation</span>' ?></td><td><?= h($f['original_name']) ?></td><td><?= h(fsize((int) $f['size_bytes'])) ?></td><td class="small"><?= h(eat($f['created_at'])) ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="quote-file.php?id=<?= (int) $f['id'] ?>"><i class="bi bi-download"></i> Download</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>

    <?php if (can('quotes.respond')): ?>
    <div class="card card-primary card-outline" id="respond"><div class="card-header"><h3 class="card-title"><i class="bi bi-send"></i> <?= $q['responded_at'] ? 'Send a revised quotation' : 'Send the quotation' ?></h3></div><div class="card-body">
      <form method="post" enctype="multipart/form-data" data-confirm="Save this quotation and set the status to Responded?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="respond">
        <div class="row g-2">
          <div class="col-sm-5"><label class="form-label" for="r-amt">Amount</label><input id="r-amt" class="form-control" name="amount" inputmode="decimal" required value="<?= h($q['quoted_amount'] !== null ? (fmod((float) $q['quoted_amount'], 1.0) == 0.0 ? number_format((float) $q['quoted_amount'], 0, '.', '') : number_format((float) $q['quoted_amount'], 2, '.', '')) : '') ?>" placeholder="e.g. 12500000"></div>
          <div class="col-sm-3"><label class="form-label" for="r-cur">Currency</label><select id="r-cur" class="form-select" name="currency"><?php foreach (['UGX', 'USD', 'KES', 'EUR', 'GBP'] as $c): ?><option<?= ($q['currency'] ?: 'UGX') === $c ? ' selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-4"><label class="form-label" for="r-val">Valid until</label><input id="r-val" class="form-control" type="date" name="valid_until" value="<?= h($q['valid_until'] ?: gmdate('Y-m-d', time() + 30 * 86400)) ?>"></div>
          <div class="col-12"><label class="form-label" for="r-msg">Message to the client</label><textarea id="r-msg" class="form-control" name="message" rows="5" maxlength="5000" required><?= h($q['response_message'] ?: "Thank you for contacting Audtix Systems. Please find our quotation for " . strtolower(quote_service_title($q['service'])) . " below. It covers ...") ?></textarea></div>
          <div class="col-md-7"><label class="form-label" for="r-pdf">Quotation PDF (optional)</label><input id="r-pdf" class="form-control" type="file" name="quote_pdf[]" accept=".pdf,application/pdf" multiple><div class="form-text">Up to 3 PDFs, 5 MB each. They are attached to the email.</div></div>
          <div class="col-md-5 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="send_email" value="1" id="r-send" checked><label class="form-check-label" for="r-send">Email it to <?= h($q['email']) ?></label></div></div>
        </div>
        <button class="btn btn-primary mt-3"><i class="bi bi-send"></i> Save and send</button>
      </form></div></div>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
    <div class="card card-outline card-primary"><div class="card-header"><h3 class="card-title">Client</h3></div><div class="card-body"><dl class="rows">
      <div><dt>Name</dt><dd><?= h($q['client_name']) ?></dd></div>
      <?php if ($q['company']): ?><div><dt>Company</dt><dd><?= h($q['company']) ?></dd></div><?php endif; ?>
      <div><dt>Email</dt><dd><a href="mailto:<?= h($q['email']) ?>?subject=<?= rawurlencode('Your quotation ' . $q['quote_no']) ?>"><?= h($q['email']) ?></a></dd></div>
      <div><dt>Phone</dt><dd><a href="tel:<?= h(preg_replace('/[^\d+]/', '', $q['phone'])) ?>"><?= h($q['phone']) ?></a> &middot; <a href="https://wa.me/<?= h($wa) ?>" rel="noopener noreferrer" target="_blank">WhatsApp</a></dd></div>
      <?php if ($q['location']): ?><div><dt>Location</dt><dd><?= h($q['location']) ?></dd></div><?php endif; ?></dl></div></div>

    <?php if (can('quotes.edit')): ?>
    <div class="card"><div class="card-header"><h3 class="card-title">Status</h3></div><div class="card-body">
      <div class="d-flex flex-wrap gap-2 mb-3">
        <?php foreach (['approved' => 'Client approved', 'rejected' => 'Client declined', 'completed' => 'Completed', 'cancelled' => 'Cancel'] as $k => $l): if ($q['status'] === $k) continue; ?>
        <form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="status" value="<?= $k ?>"><button class="btn btn-sm btn-outline-<?= QUOTE_STATUS_COLORS[$k] ?>" data-confirm="Set the status to <?= h(QUOTE_STATUSES[$k]) ?>?"><i class="bi bi-<?= QUOTE_STATUS_ICONS[$k] ?>"></i> <?= h($l) ?></button></form>
        <?php endforeach; ?>
      </div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="status">
        <div class="input-group input-group-sm mb-2"><select name="status" class="form-select" aria-label="Status"><?php foreach (QUOTE_STATUSES as $k => $v): ?><option value="<?= $k ?>"<?= $q['status'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select><button class="btn btn-primary">Update</button></div>
        <input class="form-control form-control-sm" name="reason" maxlength="300" placeholder="Reason (optional, saved in the history)"></form>
      <hr>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="assign"><label class="form-label small" for="as">Assigned to</label>
        <div class="input-group input-group-sm"><select id="as" name="assigned_to" class="form-select"><option value="0">Not assigned</option><?php foreach ($admins as $a): ?><option value="<?= (int) $a['id'] ?>"<?= (int) $q['assigned_to'] === (int) $a['id'] ? ' selected' : '' ?>><?= h($a['full_name']) ?></option><?php endforeach; ?></select><button class="btn btn-outline-primary">Save</button></div></form>
    </div></div>
    <?php endif; ?>

    <div class="card"><div class="card-header"><h3 class="card-title">Internal notes and history</h3></div><div class="card-body">
      <?php if (can('quotes.edit')): ?><form method="post" class="mb-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="note"><textarea name="note" class="form-control mb-2" rows="3" maxlength="2000" placeholder="Add a note for the team (the client never sees this)"></textarea><button class="btn btn-sm btn-primary">Add note</button></form><?php endif; ?>
      <ul class="timeline-list"><?php foreach ($events as $e): ?><li class="ev-<?= h($e['event_type']) ?>"><?= h($e['note']) ?><small><?= h($e['full_name'] ?: 'System') ?> &middot; <?= h(eat($e['created_at'])) ?></small></li><?php endforeach; ?></ul></div></div>

    <?php if (can('quotes.delete')): ?>
    <div class="card card-outline card-danger"><div class="card-body"><form method="post" class="d-flex align-items-center justify-content-between gap-2" data-confirm="Delete <?= h($q['quote_no']) ?> permanently, with its documents and history? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="delete"><span class="small text-secondary">Delete this quotation permanently. To keep a record, use Cancel or Spam instead.</span><button class="btn btn-sm btn-danger text-nowrap"><i class="bi bi-trash3"></i> Delete</button></form></div></div>
    <?php endif; ?>
  </div>
</div>
<?php page_foot();
