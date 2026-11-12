<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
$pdo = db();
$c = nav_counts();
$month = gmdate('Y-m-01 00:00:00');
$m = $pdo->prepare("SELECT COUNT(*) n, SUM(status IN ('approved','completed')) won, SUM(CASE WHEN status IN ('approved','completed') THEN quoted_amount ELSE 0 END) won_ugx FROM quotes WHERE created_at >= ? AND (currency = 'UGX' OR currency IS NULL)");
$m->execute([$month]); $mo = $m->fetch();
$responded = (int) $pdo->query("SELECT COUNT(*) FROM quotes WHERE responded_at IS NOT NULL")->fetchColumn();
$decided = (int) $pdo->query("SELECT COUNT(*) FROM quotes WHERE status IN ('approved','completed','rejected')")->fetchColumn();
$won = (int) $pdo->query("SELECT COUNT(*) FROM quotes WHERE status IN ('approved','completed')")->fetchColumn();
$latest = $pdo->query("SELECT q.id, q.quote_no, q.service, q.status, q.client_name, q.company, q.created_at, a.full_name assignee FROM quotes q LEFT JOIN admins a ON a.id = q.assigned_to ORDER BY q.id DESC LIMIT 8")->fetchAll();
$stale = $pdo->prepare("SELECT id, quote_no, service, COALESCE(company, client_name) who, created_at FROM quotes WHERE status IN ('pending','in_review') AND created_at < ? ORDER BY created_at LIMIT 8");
$stale->execute([gmdate('Y-m-d H:i:s', time() - 2 * 86400)]); $stale = $stale->fetchAll();
$bySvc = []; foreach ($pdo->query("SELECT service, COUNT(*) n, SUM(status IN ('pending','in_review')) open_n FROM quotes GROUP BY service") as $r) $bySvc[$r['service']] = $r;
$reqs = $pdo->query("SELECT id, request_id, category, client_name, created_at FROM requests WHERE status = 'new' ORDER BY id DESC LIMIT 5")->fetchAll();
$box = fn($n, $l, $col, $i, $href) => '<div class="col-6 col-xl-3"><div class="small-box text-bg-' . $col . '"><div class="inner"><h3>' . $n . '</h3><p>' . $l . '</p></div><i class="small-box-icon bi bi-' . $i . '"></i><a href="' . $href . '" class="small-box-footer link-light link-underline-opacity-0">Open <i class="bi bi-arrow-right-short"></i></a></div></div>';
page_head('Dashboard', $admin, 'dashboard', [['Dashboard', null]]);
?>
<div class="row g-3 mb-1">
  <?= $box($c['q']['pending'], 'Quotations pending', 'warning', 'hourglass-split', 'quotes.php?status=pending') ?>
  <?= $box($c['q']['in_review'], 'In review', 'info', 'search', 'quotes.php?status=in_review') ?>
  <?= $box($c['q']['responded'], 'Responded, awaiting client', 'primary', 'send-check', 'quotes.php?status=responded') ?>
  <?= $box($c['r_new'], 'New tax and audit requests', 'danger', 'inbox', 'requests.php?status=new') ?>
</div>
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-xl-3"><div class="info-box"><span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-calendar3"></i></span><div class="info-box-content"><span class="info-box-text">Quotation requests this month</span><span class="info-box-number"><?= (int) $mo['n'] ?></span></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="info-box"><span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-hand-thumbs-up"></i></span><div class="info-box-content"><span class="info-box-text">Won this month</span><span class="info-box-number"><?= (int) $mo['won'] ?> <small class="fw-normal text-secondary"><?= $mo['won_ugx'] ? h(money((string) $mo['won_ugx'], 'UGX')) : '' ?></small></span></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="info-box"><span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-send"></i></span><div class="info-box-content"><span class="info-box-text">Quotations sent (all time)</span><span class="info-box-number"><?= $responded ?></span></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="info-box"><span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-percent"></i></span><div class="info-box-content"><span class="info-box-text">Win rate (decided quotes)</span><span class="info-box-number"><?= $decided ? round($won / $decided * 100) . '%' : '–' ?></span>
    <div class="progress"><div class="progress-bar" style="width:<?= $decided ? round($won / $decided * 100) : 0 ?>%"></div></div></div></div></div>
</div>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card"><div class="card-header"><h3 class="card-title">Latest quotations</h3><div class="card-tools"><a class="btn btn-tool" href="quotes.php">View all</a></div></div>
      <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Quote no.</th><th>Service</th><th>Client</th><th>Status</th><th>Assigned</th><th>Received</th></tr></thead><tbody>
      <?php foreach ($latest as $r): ?><tr><td class="mono text-nowrap"><a href="quote.php?id=<?= (int) $r['id'] ?>"><?= h($r['quote_no']) ?></a></td><td class="small"><?= h(quote_service_title($r['service'])) ?></td><td><?= h($r['company'] ?: $r['client_name']) ?></td><td><?= status_badge($r['status']) ?></td><td class="small"><?= h($r['assignee'] ?: '–') ?></td><td class="small text-nowrap"><?= h(eat($r['created_at'])) ?></td></tr><?php endforeach; ?>
      <?php if (!$latest): ?><tr><td colspan="6" class="text-secondary p-4">No quotations yet. They appear here as soon as someone uses a quotation form on the website.</td></tr><?php endif; ?>
      </tbody></table></div></div></div>
    <div class="card"><div class="card-header"><h3 class="card-title">Quotations by service</h3></div><div class="card-body p-0"><table class="table mb-0"><thead><tr><th>Service</th><th class="text-end">Open</th><th class="text-end">All time</th><th></th></tr></thead><tbody>
      <?php foreach (quote_services() as $k => $s): $r = $bySvc[$k] ?? ['n' => 0, 'open_n' => 0]; ?><tr><td><span class="tag me-2"><?= h($s['tag']) ?></span><?= h($s['title']) ?></td><td class="text-end"><?= (int) $r['open_n'] ? '<span class="badge text-bg-warning">' . (int) $r['open_n'] . '</span>' : '0' ?></td><td class="text-end"><?= (int) $r['n'] ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="quotes.php?service=<?= h($k) ?>">Open</a> <a class="btn btn-sm btn-outline-secondary" href="../quote.html?service=<?= h($k) ?>" target="_blank" rel="noopener" title="Public form"><i class="bi bi-box-arrow-up-right"></i></a></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  </div>
  <div class="col-xl-4">
    <div class="card card-outline card-warning"><div class="card-header"><h3 class="card-title"><i class="bi bi-alarm"></i> Waiting more than 2 days</h3></div><div class="card-body p-0"><ul class="list-group list-group-flush">
      <?php foreach ($stale as $r): ?><li class="list-group-item d-flex justify-content-between align-items-start"><div><a class="mono" href="quote.php?id=<?= (int) $r['id'] ?>"><?= h($r['quote_no']) ?></a><div class="small text-secondary"><?= h($r['who']) ?></div></div><span class="small text-secondary text-nowrap"><?= h(eat($r['created_at'])) ?></span></li><?php endforeach; ?>
      <?php if (!$stale): ?><li class="list-group-item text-secondary">Nothing overdue. Well done.</li><?php endif; ?></ul></div></div>
    <div class="card"><div class="card-header"><h3 class="card-title">New tax and audit requests</h3><div class="card-tools"><a class="btn btn-tool" href="requests.php">View all</a></div></div><div class="card-body p-0"><ul class="list-group list-group-flush">
      <?php foreach ($reqs as $r): ?><li class="list-group-item d-flex justify-content-between"><div><a class="mono" href="request.php?id=<?= (int) $r['id'] ?>"><?= h($r['request_id']) ?></a> <span class="tag <?= h($r['category']) ?>"><?= h(CATEGORIES[$r['category']]) ?></span><div class="small text-secondary"><?= h($r['client_name']) ?></div></div><span class="small text-secondary text-nowrap"><?= h(eat($r['created_at'])) ?></span></li><?php endforeach; ?>
      <?php if (!$reqs): ?><li class="list-group-item text-secondary">No new requests.</li><?php endif; ?></ul></div></div>
    <div class="card"><div class="card-header"><h3 class="card-title">Quick actions</h3></div><div class="card-body d-grid gap-2">
      <a class="btn btn-primary" href="quote-edit.php"><i class="bi bi-plus-lg"></i> Enter a quotation request</a>
      <a class="btn btn-outline-primary" href="quotes.php?mine=1">Quotations assigned to me</a>
      <a class="btn btn-outline-secondary" href="../quote.html" target="_blank" rel="noopener">Open the public quotation page</a></div></div>
  </div>
</div>
<?php page_foot();
