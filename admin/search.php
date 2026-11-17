<?php
/* Top-bar search: jumps straight to an exact quote or request number, otherwise lists matches from both. */
require __DIR__ . '/inc/boot.php';
$admin = require_login(); $pdo = db();
$q = trim((string) ($_GET['q'] ?? ''));
if ($q === '') redirect('index.php');
$u = strtoupper($q);
$cq = can('quotes.view'); $cr = can('requests.view');
$cd = (bool) doc_types_for('doc.view'); $cc = can('customers.view');
if ($cd) { $s = $pdo->prepare('SELECT id FROM documents WHERE doc_no = ? AND ' . doc_type_in()); $s->execute([$u]); if ($id = $s->fetchColumn()) redirect('doc.php?id=' . $id); }
if ($cq) { $s = $pdo->prepare('SELECT id FROM quotes WHERE quote_no = ?'); $s->execute([$u]); if ($id = $s->fetchColumn()) redirect('quote.php?id=' . $id); }
if ($cr) { $s = $pdo->prepare('SELECT id FROM requests WHERE request_id = ?'); $s->execute([$u]); if ($id = $s->fetchColumn()) redirect('request.php?id=' . $id); }
$like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
$s = $pdo->prepare('SELECT id, quote_no, service, status, client_name, company, email, created_at FROM quotes WHERE quote_no LIKE ? OR client_name LIKE ? OR company LIKE ? OR email LIKE ? OR phone LIKE ? ORDER BY id DESC LIMIT 50');
$s->execute([$like, $like, $like, $like, $like]); $quotes = $cq ? $s->fetchAll() : [];
$s = $pdo->prepare('SELECT id, request_id, category, status, client_name, email, created_at FROM requests WHERE request_id LIKE ? OR client_name LIKE ? OR email LIKE ? OR phone LIKE ? OR contact_person LIKE ? ORDER BY id DESC LIMIT 50');
$s->execute([$like, $like, $like, $like, $like]); $reqs = $cr ? $s->fetchAll() : [];
$docs = []; $custs = [];
if ($cd) { $s = $pdo->prepare('SELECT id, doc_no, doc_type, status, customer_name, total, currency FROM documents WHERE (doc_no LIKE ? OR customer_name LIKE ? OR reference_text LIKE ?) AND ' . doc_type_in() . ' ORDER BY id DESC LIMIT 50'); $s->execute([$like, $like, $like]); $docs = $s->fetchAll(); }
if ($cc) { $s = $pdo->prepare('SELECT id, name, tin, phone, email FROM customers WHERE name LIKE ? OR tin LIKE ? OR phone LIKE ? OR email LIKE ? ORDER BY name LIMIT 50'); $s->execute([$like, $like, $like, $like]); $custs = $s->fetchAll(); }
page_head('Search: ' . $q, $admin, '', [['Search', null]]);
?>
<div class="row g-3">
  <?php if ($cq): ?><div class="col-xl-6"><div class="card"><div class="card-header"><h3 class="card-title">Quotations (<?= count($quotes) ?>)</h3></div><div class="card-body p-0"><table class="table table-hover mb-0"><tbody>
  <?php foreach ($quotes as $r): ?><tr><td class="mono text-nowrap"><a href="quote.php?id=<?= (int) $r['id'] ?>"><?= h($r['quote_no']) ?></a></td><td><?= h($r['company'] ?: $r['client_name']) ?><br><span class="small text-secondary"><?= h($r['email']) ?></span></td><td><?= status_badge($r['status']) ?></td><td class="small text-nowrap"><?= h(eat($r['created_at'])) ?></td></tr><?php endforeach; ?>
  <?php if (!$quotes): ?><tr><td class="text-secondary p-3">No quotations match.</td></tr><?php endif; ?></tbody></table></div></div></div><?php endif; ?>
  <?php if ($cr): ?><div class="col-xl-6"><div class="card"><div class="card-header"><h3 class="card-title">Tax and audit requests (<?= count($reqs) ?>)</h3></div><div class="card-body p-0"><table class="table table-hover mb-0"><tbody>
  <?php foreach ($reqs as $r): ?><tr><td class="mono"><a href="request.php?id=<?= (int) $r['id'] ?>"><?= h($r['request_id']) ?></a></td><td><?= h($r['client_name']) ?><br><span class="small text-secondary"><?= h($r['email']) ?></span></td><td><span class="badge text-bg-<?= STATUS_COLORS[$r['status']] ?? 'secondary' ?>"><?= h(STATUSES[$r['status']] ?? $r['status']) ?></span></td><td class="small text-nowrap"><?= h(eat($r['created_at'])) ?></td></tr><?php endforeach; ?>
  <?php if (!$reqs): ?><tr><td class="text-secondary p-3">No requests match.</td></tr><?php endif; ?></tbody></table></div></div></div><?php endif; ?>
</div>
<?php if ($cd || $cc): ?><div class="row g-3 mt-0">
  <?php if ($cd): ?><div class="col-xl-6"><div class="card"><div class="card-header"><h3 class="card-title">Business documents (<?= count($docs) ?>)</h3></div><div class="card-body p-0"><table class="table table-hover mb-0"><tbody>
  <?php foreach ($docs as $r): ?><tr><td class="mono text-nowrap"><a href="doc.php?id=<?= (int) $r['id'] ?>"><?= h($r['doc_no']) ?></a></td><td><?= h($r['customer_name']) ?><br><span class="small text-secondary"><?= h(DOC_TYPES[$r['doc_type']]['label']) ?></span></td><td class="text-end text-nowrap"><?= h(dmoney(db_cents($r['total']), $r['currency'])) ?> <?= h($r['currency']) ?><br><span class="badge text-bg-secondary"><?= h(ucfirst($r['status'])) ?></span></td></tr><?php endforeach; ?>
  <?php if (!$docs): ?><tr><td class="text-secondary p-3">No documents match.</td></tr><?php endif; ?></tbody></table></div></div></div><?php endif; ?>
  <?php if ($cc): ?><div class="col-xl-6"><div class="card"><div class="card-header"><h3 class="card-title">Customers (<?= count($custs) ?>)</h3></div><div class="card-body p-0"><table class="table table-hover mb-0"><tbody>
  <?php foreach ($custs as $r): ?><tr><td><a href="customers.php?id=<?= (int) $r['id'] ?>"><?= h($r['name']) ?></a><br><span class="small text-secondary"><?= h(trim($r['email'] . ' ' . $r['phone'])) ?></span></td><td class="text-end small text-secondary"><?= $r['tin'] ? 'TIN ' . h($r['tin']) : '' ?></td></tr><?php endforeach; ?>
  <?php if (!$custs): ?><tr><td class="text-secondary p-3">No customers match.</td></tr><?php endif; ?></tbody></table></div></div></div><?php endif; ?>
</div><?php endif; ?>
<?php page_foot();
