<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
require_perm('requests.view');
$pdo = db();
$cat = $_GET['category'] ?? ''; $st = $_GET['status'] ?? ''; $q = trim((string) ($_GET['q'] ?? '')); $mail = $_GET['mail'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 25;
$where = []; $args = [];
if (isset(CATEGORIES[$cat])) { $where[] = 'category = ?'; $args[] = $cat; } else $cat = '';
if (isset(STATUSES[$st])) { $where[] = 'status = ?'; $args[] = $st; } else $st = '';
if ($mail === 'failed') { $where[] = "email_status = 'failed'"; } else $mail = '';
if ($q !== '') { $where[] = '(request_id LIKE ? OR client_name LIKE ? OR email LIKE ? OR phone LIKE ? OR contact_person LIKE ?)'; $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%'; array_push($args, $like, $like, $like, $like, $like); }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$cnt = $pdo->prepare("SELECT COUNT(*) FROM requests $w"); $cnt->execute($args); $total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / $per)); $page = min($page, $pages); $off = ($page - 1) * $per;
$rs = $pdo->prepare("SELECT id, request_id, category, status, client_name, email, phone, service_summary, files_count, email_status, created_at FROM requests $w ORDER BY id DESC LIMIT $per OFFSET $off");
$rs->execute($args); $list = $rs->fetchAll();
$stats = $pdo->query("SELECT COUNT(*) t, SUM(status='new') n, SUM(category='tax') tx, SUM(category='audit') au, SUM(email_status='failed') ef FROM requests")->fetch();
$title = $cat === 'tax' ? 'Tax requests' : ($cat === 'audit' ? 'Audit requests' : 'Tax and audit requests');
page_head($title, $admin, 'requests' . ($cat ? ':' . $cat : ''), [['Requests', $cat ? 'requests.php' : null]] + ($cat ? [1 => [CATEGORIES[$cat], null]] : []));
function qs(array $o): string { return '?' . http_build_query(array_filter($o, fn($v) => $v !== '' && $v !== null)); }
$box = fn($n, $l, $c, $i, $href) => '<div class="col-6 col-lg"><div class="small-box text-bg-' . $c . '"><div class="inner"><h3>' . (int) $n . '</h3><p>' . $l . '</p></div><i class="small-box-icon bi bi-' . $i . '"></i><a href="' . $href . '" class="small-box-footer link-light link-underline-opacity-0">Show <i class="bi bi-arrow-right-short"></i></a></div></div>';
?>
<div class="row g-3 mb-1">
  <?= $box($stats['t'], 'All requests', 'primary', 'inboxes', 'requests.php') ?>
  <?= $box($stats['n'], 'New, not yet opened', 'warning', 'envelope-exclamation', 'requests.php?status=new') ?>
  <?= $box($stats['tx'], 'Tax', 'success', 'receipt', 'requests.php?category=tax') ?>
  <?= $box($stats['au'], 'Audit', 'info', 'clipboard-check', 'requests.php?category=audit') ?>
  <?= $box($stats['ef'], 'Email copy failed', 'danger', 'envelope-x', 'requests.php?mail=failed') ?>
</div>
<div class="card">
  <div class="card-header"><form class="row g-2 align-items-end" method="get">
    <div class="col-md-4"><label class="form-label small mb-1" for="f-q">Search</label><input id="f-q" class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="ID, name, email, phone"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1" for="f-c">Category</label><select id="f-c" class="form-select form-select-sm" name="category"><option value="">All</option><option value="tax"<?= $cat === 'tax' ? ' selected' : '' ?>>Tax (TAX)</option><option value="audit"<?= $cat === 'audit' ? ' selected' : '' ?>>Audit (AUDIT)</option></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1" for="f-s">Status</label><select id="f-s" class="form-select form-select-sm" name="status"><option value="">All</option><?php foreach (STATUSES as $k => $v): ?><option value="<?= $k ?>"<?= $st === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1" for="f-m">Email copy</label><select id="f-m" class="form-select form-select-sm" name="mail"><option value="">Any</option><option value="failed"<?= $mail === 'failed' ? ' selected' : '' ?>>Copy failed</option></select></div>
    <div class="col-6 col-md-2 d-flex gap-2"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel"></i> Filter</button><?php if ($where): ?><a class="btn btn-outline-secondary btn-sm" href="requests.php">Clear</a><?php endif; ?></div>
  </form></div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0">
    <thead><tr><th>Request ID</th><th>Tag</th><th>Client</th><th>Service</th><th>Status</th><th>Files</th><th>Email copy</th><th>Received</th></tr></thead><tbody>
    <?php foreach ($list as $r): ?><tr>
      <td class="mono"><a href="request.php?id=<?= (int) $r['id'] ?>"><?= h($r['request_id']) ?></a></td>
      <td><span class="tag <?= h($r['category']) ?>"><?= h(CATEGORIES[$r['category']]) ?></span></td>
      <td><b><?= h($r['client_name']) ?></b><br><span class="text-secondary small"><?= h($r['email']) ?> &middot; <?= h($r['phone']) ?></span></td>
      <td class="small"><?= h(mb_strimwidth((string) $r['service_summary'], 0, 70, '…')) ?></td>
      <td><span class="badge text-bg-<?= STATUS_COLORS[$r['status']] ?? 'secondary' ?>"><?= h(STATUSES[$r['status']] ?? $r['status']) ?></span></td>
      <td><?= (int) $r['files_count'] ?></td>
      <td><span class="badge text-bg-<?= $r['email_status'] === 'sent' ? 'success' : ($r['email_status'] === 'failed' ? 'danger' : 'secondary') ?>"><?= h($r['email_status']) ?></span></td>
      <td class="small text-nowrap"><?= h(eat($r['created_at'])) ?></td>
    </tr><?php endforeach; if (!$list): ?><tr><td colspan="8" class="text-secondary p-4">No requests match.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  <div class="card-footer"><?= pager($page, $pages, $total, fn($p) => qs(['q' => $q, 'category' => $cat, 'status' => $st, 'mail' => $mail, 'page' => $p]), 'request') ?></div>
</div>
<?php page_foot();
