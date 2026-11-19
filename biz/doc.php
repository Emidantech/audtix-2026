<?php
require __DIR__ . '/inc/boot.php';
$u = biz_require(); biz_need('docs.view'); $a = account(); $pdo = db(); $aid = (int) $a['id']; $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0); $d = biz_doc($pdo, $aid, $id);
if (!$d) { http_response_code(404); biz_head('Not found'); echo '<p>Document not found. <a href="docs.php">Back</a></p>'; biz_foot(); exit; }
$T = DOC_TYPES[$d['doc_type']]; $can = $a['_sub']['ok'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can) { flash($a['_sub']['note'], 'bad'); redirect('doc.php?id=' . $id); }
    $act = (string) ($_POST['action'] ?? '');
    if ($act === 'post' && bcan('docs.post')) { $r = biz_post($pdo, $u, $a, $id); flash($r['ok'] ? $T['label'] . ' posted.' : $r['error'], $r['ok'] ? 'ok' : 'bad'); }
    elseif ($act === 'cancel' && bcan('docs.cancel')) { $r = biz_cancel($pdo, $u, $a, $id, (string) ($_POST['reason'] ?? '')); flash($r['ok'] ? $T['label'] . ' cancelled. It stays on record.' : $r['error'], $r['ok'] ? 'ok' : 'bad'); }
    elseif ($act === 'convert' && bcan('docs.create')) { $r = biz_convert($pdo, $u, $a, $id, (string) ($_POST['target'] ?? '')); if ($r['ok']) { try { $pdo->prepare('INSERT INTO biz_doc_meta (document_id, account_id, branch_id) SELECT ?, account_id, branch_id FROM biz_doc_meta WHERE document_id = ?')->execute([$r['id'], $id]); } catch (Throwable $e) {} flash('Draft created. Review it, then save and post.'); redirect('doc_edit.php?id=' . $r['id']); } flash(implode(' ', $r['errors'] ?? [$r['error'] ?? 'Could not convert.']), 'bad'); }
    elseif ($act === 'delete' && $d['status'] === 'draft' && bcan('docs.create')) { $pdo->prepare('DELETE FROM biz_document_lines WHERE document_id = ?')->execute([$id]); $pdo->prepare('DELETE FROM biz_documents WHERE id = ? AND account_id = ?')->execute([$id, $aid]); flash('Draft deleted.'); redirect('docs.php'); }
    biz_audit('doc_' . $act, $d['doc_no']);
    redirect('doc.php?id=' . $id);
}
$cx = biz_context($pdo, $d); $kids = $pdo->prepare('SELECT id, doc_no, doc_type, status FROM biz_documents WHERE parent_id = ? AND account_id = ? ORDER BY id'); $kids->execute([$id, $aid]); $kids = $kids->fetchAll();
$msg = rawurlencode("Hello " . $d['customer_name'] . ", please find your " . strtolower($T['label']) . " " . $d['doc_no'] . " for " . dmoney(db_cents($d['total']), $d['currency']) . " " . $d['currency'] . ". - " . $a['name']);
$ph = preg_replace('/\D/', '', (string) $d['customer_phone']); if ($ph !== '' && $ph[0] === '0') $ph = '256' . substr($ph, 1);
biz_head($d['doc_no'], 'docs'); ?>
<p class="noprint"><a href="docs.php?type=<?= h($d['doc_type']) ?>">&larr; <?= h($T['plural']) ?></a></p>
<div class="card noprint"><div class="acts"><span class="pill <?= h($d['status']) ?>"><?= h(ucfirst($d['status'])) ?></span>
  <a class="btn sm" href="pdf.php?id=<?= $id ?>">Download PDF</a><a class="btn sm ghost" href="pdf.php?id=<?= $id ?>&inline=1" target="_blank">View PDF</a>
  <a class="btn sm ghost" target="_blank" rel="noopener" href="https://wa.me/<?= h($ph) ?>?text=<?= $msg ?>">WhatsApp customer</a>
  <?php if ($can && $d['status'] === 'draft'): ?><a class="btn sm ghost" href="doc_edit.php?id=<?= $id ?>">Edit</a>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn sm" name="action" value="post" data-confirm="Post this <?= h(strtolower($T['label'])) ?>? A posted document cannot be edited, only cancelled.">Post</button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn sm danger" name="action" value="delete" data-confirm="Delete this draft?">Delete draft</button></form><?php endif; ?>
  <?php if ($can && $d['status'] === 'posted'): foreach (['quotation' => ['invoice', 'proforma'], 'proforma' => ['invoice']][$d['doc_type']] ?? [] as $t): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="target" value="<?= $t ?>"><button class="btn sm ghost" name="action" value="convert">Make <?= h(strtolower(DOC_TYPES[$t]['label'])) ?></button></form><?php endforeach; ?>
    <?php if ($d['doc_type'] === 'invoice' && $cx['balance'] > 0): ?><a class="btn sm" href="doc_edit.php?type=receipt&parent=<?= $id ?>">Record payment</a><?php endif; ?>
    <details class="cx"><summary class="btn sm ghost danger">Cancel</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input name="reason" placeholder="Reason for cancelling" required maxlength="500"><button class="btn sm danger" name="action" value="cancel">Cancel document</button></form></details><?php endif; ?>
</div><?php if ($kids): ?><p class="muted">Linked: <?php foreach ($kids as $k): ?><a href="doc.php?id=<?= (int) $k['id'] ?>"><?= h($k['doc_no']) ?></a> <?php endforeach; ?></p><?php endif; ?></div>
<div class="sheetwrap"><?= render_doc_html($d, $cx) ?></div>
<?php biz_foot();
