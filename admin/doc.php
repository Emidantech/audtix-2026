<?php
require __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/docs.php';
require_once __DIR__ . '/inc/render.php';
$admin = require_perm('doc.view'); $pdo = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$d = get_document($pdo, $id);
if (!$d) { http_response_code(404); page_head('Not found', $admin); echo '<p>Document not found. <a href="docs.php">Back</a></p>'; page_foot(); exit; }
$GLOBALS['nav_type'] = $d['doc_type']; $T = DOC_TYPES[$d['doc_type']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'post') { $r = post_document($pdo, $admin, $id); flash($r['ok'] ? $T['label'] . ' posted.' : $r['error'], $r['ok'] ? 'ok' : 'bad'); }
    elseif ($act === 'cancel') { $r = cancel_document($pdo, $admin, $id, (string) ($_POST['reason'] ?? '')); flash($r['ok'] ? $T['label'] . ' cancelled. It stays on record.' : $r['error'], $r['ok'] ? 'ok' : 'bad'); }
    elseif ($act === 'convert') { $r = convert_document($pdo, $admin, $id, (string) ($_POST['target'] ?? '')); if ($r['ok']) { flash('Draft created. Review it, then save and post.'); redirect('doc_edit.php?type=' . urlencode((string) $_POST['target']) . '&id=' . $r['id']); } flash($r['error'], 'bad'); }
    redirect('doc.php?id=' . $id);
}
$cx = doc_context($pdo, $d);
$ev = $pdo->prepare('SELECT e.*, a.full_name FROM document_events e LEFT JOIN admins a ON a.id = e.admin_id WHERE e.document_id = ? ORDER BY e.id DESC'); $ev->execute([$id]); $events = $ev->fetchAll();
$kids = $pdo->prepare("SELECT id, doc_no, doc_type, status, total, currency FROM documents WHERE parent_id = ? ORDER BY id"); $kids->execute([$id]); $children = $kids->fetchAll();
$revs = $pdo->prepare('SELECT COUNT(*) FROM document_revisions WHERE document_id = ?'); $revs->execute([$id]); $nrev = (int) $revs->fetchColumn();
$canEdit = !edit_block_reason($admin, $d); $canPrint = can('doc.print', $admin);
page_head($d['doc_no'], $admin); echo '<link rel="stylesheet" href="doc.css">';
?>
<p class="noprint"><a href="docs.php?type=<?= h($d['doc_type']) ?>">&larr; <?= h($T['plural']) ?></a></p>
<div class="hdr noprint"><div><h1 class="mono" style="margin-bottom:4px"><?= h($d['doc_no']) ?></h1><span class="pill <?= h($d['status']) ?>"><?= h(ucfirst($d['status'])) ?></span> <span class="muted"><?= h($T['label']) ?> &middot; <?= h($d['customer_name']) ?></span></div><span class="spacer"></span>
  <div class="row">
  <?php if ($canEdit): ?><a class="btn ghost" href="doc_edit.php?type=<?= h($d['doc_type']) ?>&id=<?= $id ?>">Edit</a><?php endif; ?>
  <?php if ($d['status'] === 'draft' && can('doc.post', $admin)): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="post"><button class="btn green" data-confirm="Post this <?= h(strtolower($T['label'])) ?>? Once posted it is locked.">Post</button></form><?php endif; ?>
  <?php if ($canPrint): ?><button type="button" class="btn ghost" data-print>Print</button><a class="btn" href="doc_pdf.php?id=<?= $id ?>">Download PDF</a><?php endif; ?>
  <?php if ($d['status'] === 'posted' && can('doc.create', $admin)) foreach (CONVERSIONS[$d['doc_type']] as $tg): $dis = $tg === 'receipt' && $cx['balance'] <= 0; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="convert"><input type="hidden" name="target" value="<?= h($tg) ?>"><button class="btn ghost"<?= $dis ? ' disabled title="Fully paid"' : '' ?>><?= $tg === 'receipt' ? 'Receive payment' : 'Convert to ' . h(strtolower(DOC_TYPES[$tg]['label'])) ?></button></form><?php endforeach; ?>
  </div></div>
<?php if ($d['doc_type'] === 'invoice' && $d['status'] === 'posted'): ?><div class="stats noprint"><div class="stat"><b><?= money(db_cents($d['total']), $d['currency']) ?></b><span>Invoice total (<?= h($d['currency']) ?>)</span></div><div class="stat"><b><?= money($cx['paid'], $d['currency']) ?></b><span>Received</span></div><div class="stat"><b><?= money($cx['balance'], $d['currency']) ?></b><span><?= $cx['balance'] <= 0 ? 'Fully paid' : 'Balance due' ?></span></div></div><?php endif; ?>
<?= render_doc_html($d, $cx) ?>
<div class="cols2 noprint" style="margin-top:20px">
  <?php if ($children || $cx['parent']): ?><div class="card"><h2>Linked documents</h2><ul class="timeline">
    <?php if ($cx['parent']): ?><li>From <a href="doc.php?id=<?= (int) $cx['parent']['id'] ?>"><?= h($cx['parent']['doc_no']) ?></a> <small><?= h(DOC_TYPES[$cx['parent']['doc_type']]['label']) ?></small></li><?php endif; ?>
    <?php foreach ($children as $c): ?><li><a href="doc.php?id=<?= (int) $c['id'] ?>"><?= h($c['doc_no']) ?></a> <span class="pill <?= h($c['status']) ?>"><?= h($c['status']) ?></span> <small><?= h(DOC_TYPES[$c['doc_type']]['label']) ?> &middot; <?= money(db_cents($c['total']), $c['currency']) ?> <?= h($c['currency']) ?></small></li><?php endforeach; ?></ul></div><?php endif; ?>
  <div class="card"><h2>History <?= $nrev ? '<span class="muted">(' . $nrev . ' earlier version' . ($nrev === 1 ? '' : 's') . ' kept)</span>' : '' ?></h2><ul class="timeline"><?php foreach ($events as $e): ?><li><?= h($e['detail']) ?><small><?= h(ucfirst($e['event_type'])) ?> &middot; <?= h($e['full_name'] ?: 'System') ?> &middot; <?= h(eat($e['created_at'])) ?></small></li><?php endforeach; ?></ul></div>
  <?php if ($d['status'] !== 'cancelled' && can('doc.cancel', $admin)): ?><div class="card"><h2><?= $d['doc_type'] === 'receipt' ? 'Void this receipt' : 'Cancel this document' ?></h2>
    <p class="muted">Nothing is ever deleted. The document stays on record, marked cancelled, with your name, the time and the reason.<?= $d['doc_type'] === 'invoice' ? ' An invoice with receipts cannot be cancelled until its receipts are.' : '' ?></p>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="cancel"><label>Reason *<input name="reason" required minlength="5" maxlength="500"></label><button class="btn danger" data-confirm="Cancel <?= h($d['doc_no']) ?>? This cannot be undone.">Cancel <?= h(strtolower($T['label'])) ?></button></form></div><?php endif; ?>
</div>
<?php page_foot();
