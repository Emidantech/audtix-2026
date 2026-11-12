<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
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
page_head('Requests', $admin);
function qs(array $o): string { return '?' . http_build_query(array_filter($o, fn($v) => $v !== '' && $v !== null)); }
?>
<h1>Requests</h1><p class="muted">Every tax and audit submission, newest first. Times are East Africa Time.</p>
<div class="stats">
  <div class="stat"><b><?= (int) $stats['t'] ?></b><span>All requests</span></div>
  <div class="stat"><b><?= (int) $stats['n'] ?></b><span>New, not yet opened</span></div>
  <div class="stat"><b><?= (int) $stats['tx'] ?></b><span>Tax</span></div>
  <div class="stat"><b><?= (int) $stats['au'] ?></b><span>Audit</span></div>
  <div class="stat"><b><?= (int) $stats['ef'] ?></b><span>Email copy failed</span></div>
</div>
<form class="filters" method="get">
  <label>Search<input name="q" value="<?= h($q) ?>" placeholder="ID, name, email, phone"></label>
  <label>Category<select name="category"><option value="">All</option><option value="tax"<?= $cat === 'tax' ? ' selected' : '' ?>>Tax (TAX)</option><option value="audit"<?= $cat === 'audit' ? ' selected' : '' ?>>Audit (AUDIT)</option></select></label>
  <label>Status<select name="status"><option value="">All</option><?php foreach (STATUSES as $k => $v): ?><option value="<?= $k ?>"<?= $st === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></label>
  <label>Email<select name="mail"><option value="">Any</option><option value="failed"<?= $mail === 'failed' ? ' selected' : '' ?>>Copy failed</option></select></label>
  <button class="btn" type="submit">Filter</button><?php if ($where): ?><a class="btn ghost" href="index.php">Clear</a><?php endif; ?>
</form>
<div class="card tablewrap" style="padding:0">
<table><thead><tr><th>Request ID</th><th>Tag</th><th>Client</th><th>Service</th><th>Status</th><th>Files</th><th>Email copy</th><th>Received</th></tr></thead><tbody>
<?php foreach ($list as $r): ?>
<tr>
  <td class="mono"><a href="request.php?id=<?= (int) $r['id'] ?>"><?= h($r['request_id']) ?></a></td>
  <td><span class="tag <?= h($r['category']) ?>"><?= h(CATEGORIES[$r['category']]) ?></span></td>
  <td><b><?= h($r['client_name']) ?></b><br><span class="muted"><?= h($r['email']) ?> &middot; <?= h($r['phone']) ?></span></td>
  <td><?= h(mb_strimwidth((string) $r['service_summary'], 0, 70, '…')) ?></td>
  <td><span class="pill <?= h($r['status']) ?>"><?= h(STATUSES[$r['status']] ?? $r['status']) ?></span></td>
  <td><?= (int) $r['files_count'] ?></td>
  <td><span class="pill <?= $r['email_status'] === 'sent' ? 'good' : ($r['email_status'] === 'failed' ? 'bad' : '') ?>"><?= h($r['email_status']) ?></span></td>
  <td><?= h(eat($r['created_at'])) ?></td>
</tr>
<?php endforeach; if (!$list): ?><tr><td colspan="8" class="muted" style="padding:26px">No requests match.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="pager"><?= $total ?> request<?= $total === 1 ? '' : 's' ?> &middot; page <?= $page ?> of <?= $pages ?>
  <?php if ($page > 1): ?><a class="btn sm ghost" href="<?= h(qs(['q' => $q, 'category' => $cat, 'status' => $st, 'mail' => $mail, 'page' => $page - 1])) ?>">Previous</a><?php endif; ?>
  <?php if ($page < $pages): ?><a class="btn sm ghost" href="<?= h(qs(['q' => $q, 'category' => $cat, 'status' => $st, 'mail' => $mail, 'page' => $page + 1])) ?>">Next</a><?php endif; ?></div>
<?php page_foot();
