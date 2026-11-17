<?php
/* "Action required" page for one request for quotation: the target of the button in the notification email. */
require __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/rfq.php';
require_once dirname(__DIR__) . '/api/lib/customers.php';
$admin = require_login(); $pdo = db();
$kind = ($_REQUEST['kind'] ?? '') === 'request' ? 'request' : 'quote'; $id = (int) ($_REQUEST['id'] ?? 0);
if (!rfq_can_view($kind)) require_perm($kind === 'request' ? 'requests.view' : 'quotes.view');
$r = rfq_load($pdo, $kind, $id);
if (!$r) { http_response_code(404); page_head('Not found', $admin, 'quotes:all'); echo '<div class="alert alert-warning">This request was not found. <a href="index.php">Back to the dashboard</a></div>'; page_foot(); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if ($act === 'make') {
        $type = (string) ($_POST['type'] ?? '');
        if (!isset(RFQ_TARGETS[$type])) redirect('rfq.php?kind=' . $kind . '&id=' . $id);
        doc_need('doc.create', $type);
        $cid = capture_customer($pdo, ['name' => $r['name'], 'contact_person' => $r['contact'], 'tin' => $r['tin'], 'address' => $r['location'], 'phone' => $r['phone'], 'email' => $r['email'], 'note' => 'Registered from ' . $r['ref'] . '.']);
        rfq_event($pdo, $kind, $id, (int) $admin['id'], 'note', 'Started a ' . strtolower(DOC_TYPES[$type]['label']) . ' from this request');
        $open = $kind === 'quote' ? ['pending'] : ['new'];
        if (in_array($r['status'], $open, true)) {
            $tbl = $kind === 'quote' ? 'quotes' : 'requests'; $pdo->prepare("UPDATE $tbl SET status = 'in_review', updated_at = ? WHERE id = ?")->execute([now(), $id]);
        }
        redirect('doc_edit.php?type=' . $type . ($cid ? '&customer_id=' . $cid : '') . '&rfq=' . $kind . ':' . $id);
    }
    redirect('rfq.php?kind=' . $kind . '&id=' . $id);
}
page_head('Action required: ' . $r['ref'], $admin, $kind === 'request' ? 'requests' : 'quotes:all');
?>
<div class="row g-3"><div class="col-lg-8">
  <div class="card card-warning card-outline"><div class="card-header"><h3 class="card-title"><i class="bi bi-lightning-charge text-warning"></i> Action required</h3><div class="card-tools"><span class="badge text-bg-secondary"><?= h(ucfirst(str_replace('_', ' ', $r['status']))) ?></span></div></div>
    <div class="card-body">
      <p class="mb-1"><b class="font-monospace"><?= h($r['ref']) ?></b> &middot; <?= h($r['title']) ?> &middot; received <?= h(eat($r['received'])) ?></p>
      <p class="mb-3 text-secondary"><?= h($r['name']) ?><?= $r['contact'] ? ' (' . h($r['contact']) . ')' : '' ?> &middot; <a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a> &middot; <?= h($r['phone']) ?></p>
      <?php if ($r['summary']): ?><p class="mb-3"><?= h($r['summary']) ?></p><?php endif; ?>
      <h6>Turn this RFQ into</h6>
      <?= rfq_buttons($admin, $kind, $id, 'btn') ?>
      <p class="text-secondary small mt-3 mb-0">A draft opens with the client already registered as a customer and this request's reference filled in. Add the prices and save. The client's details are reused for every later document.</p>
    </div></div>
  <div class="card"><div class="card-header"><h3 class="card-title">What the client asked for</h3></div><div class="card-body"><dl class="rows">
    <?php $last = ''; foreach ($r['rows'] as $row): if (($row[2] ?? '') !== '' && $row[2] !== $last) { $last = $row[2]; echo '</dl><div class="sec">' . h($last) . '</div><dl class="rows">'; } ?>
      <div><dt><?= h($row[0]) ?></dt><dd><?= h($row[1]) ?></dd></div><?php endforeach; ?></dl></div></div>
</div>
<div class="col-lg-4"><div class="card"><div class="card-body">
  <a class="btn btn-outline-secondary w-100 mb-2" href="<?= h(rfq_view_url($kind, $id)) ?>"><i class="bi bi-box-arrow-up-right"></i> Open the full request</a>
  <a class="btn btn-outline-secondary w-100" href="rfq_pdf.php?kind=<?= h($kind) ?>&id=<?= $id ?>"><i class="bi bi-file-earmark-pdf"></i> Save as PDF</a>
</div></div></div></div>
<?php page_foot();
