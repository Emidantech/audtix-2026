<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
if (!can('dashboard.view')) redirect('notifications.php');
$pdo = db(); require_once __DIR__ . '/inc/docs.php';
$cq = can('quotes.view'); $cr = can('requests.view');
if ($cq || $cr) rfq_sync_all($pdo);   // bring requests in line with documents already posted
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
$bySvc = []; foreach ($pdo->query("SELECT service, COUNT(*) n, SUM(status IN ('pending','in_review')) open_n, SUM(status = 'responded') resp_n, SUM(status IN ('approved','completed')) won_n, SUM(status = 'rejected') lost_n FROM quotes GROUP BY service") as $r) $bySvc[$r['service']] = $r;
$trend = []; for ($i = 5; $i >= 0; $i--) $trend[gmdate('Y-m', strtotime(gmdate('Y-m-01') . " -$i months"))] = ['n' => 0, 'won' => 0];
foreach ($pdo->query("SELECT created_at, status FROM quotes WHERE created_at >= '" . gmdate('Y-m-01 00:00:00', strtotime(gmdate('Y-m-01') . ' -5 months')) . "'") as $r) { $k = substr((string) $r['created_at'], 0, 7); if (isset($trend[$k])) { $trend[$k]['n']++; if (in_array($r['status'], ['approved', 'completed'], true)) $trend[$k]['won']++; } }
$reqs = $pdo->query("SELECT id, request_id, category, client_name, created_at FROM requests WHERE status = 'new' ORDER BY id DESC LIMIT 5")->fetchAll();
$box = fn($n, $l, $col, $i, $href) => '<div class="col-6 col-xl-3"><div class="small-box text-bg-' . $col . '"><div class="inner"><h3>' . $n . '</h3><p>' . $l . '</p></div><i class="small-box-icon bi bi-' . $i . '"></i><a href="' . $href . '" class="small-box-footer link-light link-underline-opacity-0">Open <i class="bi bi-arrow-right-short"></i></a></div></div>';
page_head('Dashboard', $admin, 'dashboard', [['Dashboard', null]]);
?>
<div class="dash-hero"><div><div class="dh-hi">Welcome back, <?= h(explode(' ', trim((string) $admin['full_name']))[0]) ?></div><div class="dh-sub"><?= h((new DateTime('now', new DateTimeZone('Africa/Kampala')))->format('l, j F Y')) ?> &middot; <?= h(role_name($admin)) ?></div></div>
  <div class="dh-act"><?php if ($cq || $cr || can('doc_reports.view')): ?><a class="btn btn-light btn-sm" href="reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a><?php endif; ?><?php if ($cq): ?><a class="btn btn-outline-light btn-sm" href="quotes.php"><i class="bi bi-inboxes"></i> All quotations</a><?php endif; ?></div></div>
<?php $qa = array_values(array_filter(array_keys(DOC_TYPES), fn($k) => dcan('doc.create', $admin, $k)));
if ($qa): $dcount = $c['docs']; ?>
<div class="qa-grid" aria-label="Create a business document">
  <?php foreach ($qa as $k): ?><a class="qa-tile" href="doc_edit.php?type=<?= h($k) ?>"><span class="qa-ico"><i class="bi bi-<?= h(DOC_ICONS[$k]) ?>"></i></span><span class="qa-name"><?= h(DOC_TYPES[$k]['label']) ?></span><span class="qa-sub">New &middot; <?= (int) ($dcount[$k] ?? 0) ?> total</span></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if (!$cq && !$cr): ?><div class="callout callout-info"><h5>Welcome, <?= h($admin['full_name']) ?></h5><p class="mb-0">Use the menu on the left. Your role is <b><?= h(role_name($admin)) ?></b>.</p></div><?php endif; ?>
<?php if ($cq || $cr): ?><div class="row g-3 mb-1">
  <?php if ($cq): ?><?= $box($c['q']['pending'], 'Quotations pending', 'warning', 'hourglass-split', 'quotes.php?status=pending') ?>
  <?= $box($c['q']['in_review'], 'In review', 'info', 'search', 'quotes.php?status=in_review') ?>
  <?= $box($c['q']['responded'], 'Responded, awaiting client', 'primary', 'send-check', 'quotes.php?status=responded') ?>
  <?php endif; if ($cr): ?><?= $box($c['r_new'], 'New tax and audit requests', 'danger', 'inbox', 'requests.php?status=new') ?><?php endif; ?>
</div><?php endif; ?>
<?php if ($cq): ?>
<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3"><div class="info-box"><span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-calendar3"></i></span><div class="info-box-content"><span class="info-box-text">Quotation requests this month</span><span class="info-box-number"><?= (int) $mo['n'] ?></span></div></div></div>
  <div class="col-6 col-xl-3"><div class="info-box"><span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-hand-thumbs-up"></i></span><div class="info-box-content"><span class="info-box-text">Won this month</span><span class="info-box-number"><?= (int) $mo['won'] ?> <small class="fw-normal text-secondary"><?= $mo['won_ugx'] ? h(money((string) $mo['won_ugx'], 'UGX')) : '' ?></small></span></div></div></div>
  <div class="col-6 col-xl-3"><div class="info-box"><span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-send"></i></span><div class="info-box-content"><span class="info-box-text">Quotations sent (all time)</span><span class="info-box-number"><?= $responded ?></span></div></div></div>
  <div class="col-6 col-xl-3"><div class="info-box"><span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-percent"></i></span><div class="info-box-content"><span class="info-box-text">Win rate (decided quotes)</span><span class="info-box-number"><?= $decided ? round($won / $decided * 100) . '%' : '–' ?></span>
    <div class="progress"><div class="progress-bar" style="width:<?= $decided ? round($won / $decided * 100) : 0 ?>%"></div></div></div></div></div>
</div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-xl-8"><?php if ($cq): ?>
    <div class="card"><div class="card-header"><h3 class="card-title">Latest quotations</h3><div class="card-tools"><a class="btn btn-tool" href="quotes.php">View all</a></div></div>
      <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Quote no.</th><th>Service</th><th>Client</th><th>Status</th><th>Assigned</th><th>Received</th></tr></thead><tbody>
      <?php foreach ($latest as $r): ?><tr><td class="mono text-nowrap"><a href="quote.php?id=<?= (int) $r['id'] ?>"><?= h($r['quote_no']) ?></a></td><td class="small"><?= h(quote_service_title($r['service'])) ?></td><td><?= h($r['company'] ?: $r['client_name']) ?></td><td><?= status_badge($r['status']) ?></td><td class="small"><?= h($r['assignee'] ?: '–') ?></td><td class="small text-nowrap"><?= h(eat($r['created_at'])) ?></td></tr><?php endforeach; ?>
      <?php if (!$latest): ?><tr><td colspan="6" class="text-secondary p-4">No quotations yet. They appear here as soon as someone uses a quotation form on the website.</td></tr><?php endif; ?>
      </tbody></table></div></div></div>
    <div class="card"><div class="card-header"><h3 class="card-title">Quotations by service</h3><div class="card-tools"><a class="btn btn-tool" href="reports.php?report=rfq&period=quarter"><i class="bi bi-bar-chart-line"></i> Seasonal report</a></div></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 svc-table"><thead><tr><th>Service</th><th class="text-end">Open</th><th class="text-end">Responded</th><th class="text-end">Won</th><th class="text-end">All time</th><th></th></tr></thead><tbody>
      <?php $mx = 1; foreach ($bySvc as $x) $mx = max($mx, (int) $x['n']); foreach (quote_services() as $k => $s): $r = $bySvc[$k] ?? ['n' => 0, 'open_n' => 0, 'resp_n' => 0, 'won_n' => 0, 'lost_n' => 0]; ?><tr><td><span class="tag me-2"><?= h($s['tag']) ?></span><?= h($s['title']) ?><div class="svc-bar" title="<?= (int) $r['n'] ?> of <?= $mx ?>"><i style="width:<?= round((int) $r['n'] / $mx * 100) ?>%"></i></div></td><td class="text-end"><?= (int) $r['open_n'] ? '<span class="badge text-bg-warning">' . (int) $r['open_n'] . '</span>' : '0' ?></td><td class="text-end"><?= (int) $r['resp_n'] ? '<span class="badge text-bg-primary">' . (int) $r['resp_n'] . '</span>' : '0' ?></td><td class="text-end"><?= (int) $r['won_n'] ? '<span class="badge text-bg-success">' . (int) $r['won_n'] . '</span>' : '0' ?></td><td class="text-end"><?= (int) $r['n'] ?></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="quotes.php?service=<?= h($k) ?>">Open</a> <a class="btn btn-sm btn-outline-secondary" href="../quote.html?service=<?= h($k) ?>" target="_blank" rel="noopener" title="Public form"><i class="bi bi-box-arrow-up-right"></i></a></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
  <?php endif; ?></div>
  <div class="col-xl-4"><?php if ($cq): $tmx = 1; foreach ($trend as $t) $tmx = max($tmx, $t['n']); ?>
    <div class="card"><div class="card-header"><h3 class="card-title">Requests, last 6 months</h3></div><div class="card-body"><div class="trend"><?php foreach ($trend as $k => $t): ?><div class="tcol" title="<?= h(date('M Y', strtotime($k . '-01'))) ?>: <?= (int) $t['n'] ?> received, <?= (int) $t['won'] ?> won"><span class="tn"><?= (int) $t['n'] ?></span><span class="tbar"><i style="height:<?= round($t['n'] / $tmx * 100) ?>%"></i></span><span class="tl"><?= h(date('M', strtotime($k . '-01'))) ?></span></div><?php endforeach; ?></div></div></div>
    <div class="card card-outline card-warning"><div class="card-header"><h3 class="card-title"><i class="bi bi-alarm"></i> Waiting more than 2 days</h3></div><div class="card-body p-0"><ul class="list-group list-group-flush">
      <?php foreach ($stale as $r): ?><li class="list-group-item d-flex justify-content-between align-items-start"><div><a class="mono" href="quote.php?id=<?= (int) $r['id'] ?>"><?= h($r['quote_no']) ?></a><div class="small text-secondary"><?= h($r['who']) ?></div></div><span class="small text-secondary text-nowrap"><?= h(eat($r['created_at'])) ?></span></li><?php endforeach; ?>
      <?php if (!$stale): ?><li class="list-group-item text-secondary">Nothing overdue. Well done.</li><?php endif; ?></ul></div></div><?php endif; if ($cr): ?>
    <div class="card"><div class="card-header"><h3 class="card-title">New tax and audit requests</h3><div class="card-tools"><a class="btn btn-tool" href="requests.php">View all</a></div></div><div class="card-body p-0"><ul class="list-group list-group-flush">
      <?php foreach ($reqs as $r): ?><li class="list-group-item d-flex justify-content-between"><div><a class="mono" href="request.php?id=<?= (int) $r['id'] ?>"><?= h($r['request_id']) ?></a> <span class="tag <?= h($r['category']) ?>"><?= h(CATEGORIES[$r['category']]) ?></span><div class="small text-secondary"><?= h($r['client_name']) ?></div></div><span class="small text-secondary text-nowrap"><?= h(eat($r['created_at'])) ?></span></li><?php endforeach; ?>
      <?php if (!$reqs): ?><li class="list-group-item text-secondary">No new requests.</li><?php endif; ?></ul></div></div><?php endif; ?>
    <div class="card"><div class="card-header"><h3 class="card-title">Quick actions</h3></div><div class="card-body d-grid gap-2">
      <?php if (can('quotes.add')): ?><a class="btn btn-primary" href="quote-edit.php"><i class="bi bi-plus-lg"></i> Enter a quotation request</a><?php endif; ?>
      <?php if ($cq): ?><a class="btn btn-outline-primary" href="quotes.php?mine=1">Quotations assigned to me</a><?php endif; ?>
      <?php if (can('posts.add')): ?><a class="btn btn-outline-primary" href="post-edit.php"><i class="bi bi-pencil-square"></i> Write a post</a><?php endif; ?>
      <a class="btn btn-outline-secondary" href="../quote.html" target="_blank" rel="noopener">Open the public quotation page</a></div></div>
  </div>
</div>
<?php page_foot();
