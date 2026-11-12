<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
require_perm('quotes.view');
$pdo = db();
$services = quote_services();

/* ---------- bulk actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $act = (string) ($_POST['bulk'] ?? ''); $back = (string) ($_POST['back'] ?? 'quotes.php');
    if (!preg_match('/^quotes\.php(\?[\w=&%.-]*)?$/', $back)) $back = 'quotes.php';
    if (!$ids) { flash('Select at least one quotation.', 'bad'); redirect($back); }
    $in = implode(',', array_fill(0, count($ids), '?'));
    if (isset(QUOTE_STATUSES[$act])) {
        deny_unless('quotes.edit', $back);
        $rows = $pdo->prepare("SELECT id, quote_no, status FROM quotes WHERE id IN ($in)"); $rows->execute($ids);
        $n = 0;
        foreach ($rows->fetchAll() as $q) {
            if ($q['status'] === $act) continue;
            $pdo->prepare('UPDATE quotes SET status = ?, updated_at = ? WHERE id = ?')->execute([$act, now(), $q['id']]);
            quote_event($pdo, (int) $q['id'], (int) $admin['id'], 'status', 'Status changed from ' . QUOTE_STATUSES[$q['status']] . ' to ' . QUOTE_STATUSES[$act] . ' (bulk)');
            log_activity((int) $admin['id'], 'quote_status', $q['quote_no'], $act); $n++;
        }
        flash($n . ' quotation' . ($n === 1 ? '' : 's') . ' set to ' . QUOTE_STATUSES[$act] . '.');
    } elseif ($act === 'delete') {
        deny_unless('quotes.delete', $back);
        $rows = $pdo->prepare("SELECT id, quote_no FROM quotes WHERE id IN ($in)"); $rows->execute($ids);
        foreach ($rows->fetchAll() as $q) quote_delete($pdo, $q, (int) $admin['id']);
        flash(count($ids) . ' quotation' . (count($ids) === 1 ? '' : 's') . ' deleted.');
    }
    redirect($back);
}

/* ---------- filters ---------- */
$st = (string) ($_GET['status'] ?? ''); $svc = (string) ($_GET['service'] ?? ''); $q = trim((string) ($_GET['q'] ?? ''));
$mine = ($_GET['mine'] ?? '') === '1'; $page = max(1, (int) ($_GET['page'] ?? 1)); $per = 25;
$where = []; $args = [];
if (isset(QUOTE_STATUSES[$st])) { $where[] = 'q.status = ?'; $args[] = $st; } else $st = '';
if (isset($services[$svc])) { $where[] = 'q.service = ?'; $args[] = $svc; } else $svc = '';
if ($mine) { $where[] = 'q.assigned_to = ?'; $args[] = (int) $admin['id']; }
if ($q !== '') { $where[] = '(q.quote_no LIKE ? OR q.client_name LIKE ? OR q.company LIKE ? OR q.email LIKE ? OR q.phone LIKE ?)'; $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%'; array_push($args, $like, $like, $like, $like, $like); }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$cnt = $pdo->prepare("SELECT COUNT(*) FROM quotes q $w"); $cnt->execute($args); $total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / $per)); $page = min($page, $pages); $off = ($page - 1) * $per;
$rs = $pdo->prepare("SELECT q.*, a.full_name AS assignee FROM quotes q LEFT JOIN admins a ON a.id = q.assigned_to $w ORDER BY q.id DESC LIMIT $per OFFSET $off");
$rs->execute($args); $list = $rs->fetchAll();

// counts per status for the tabs, within the current service filter
$tc = $pdo->prepare('SELECT status, COUNT(*) n FROM quotes' . ($svc ? ' WHERE service = ?' : '') . ' GROUP BY status'); $tc->execute($svc ? [$svc] : []);
$tabs = array_fill_keys(array_keys(QUOTE_STATUSES), 0); $all = 0; foreach ($tc as $r) { $tabs[$r['status']] = (int) $r['n']; $all += (int) $r['n']; }

function qs(array $o): string { return 'quotes.php?' . http_build_query(array_filter($o, fn($v) => $v !== '' && $v !== null && $v !== false)); }
$base = ['service' => $svc, 'q' => $q, 'mine' => $mine ? '1' : ''];
$self = qs($base + ['status' => $st, 'page' => $page > 1 ? $page : '']);
$title = ($svc ? $services[$svc]['title'] . ' quotations' : 'Quotations') . ($st ? ': ' . QUOTE_STATUSES[$st] : '');
page_head($title, $admin, $svc ? 'service:' . $svc : 'quotes:' . ($st ?: 'all'), [], can('quotes.add') ? '<a class="btn btn-primary btn-sm" href="quote-edit.php' . ($svc ? '?service=' . h($svc) : '') . '"><i class="bi bi-plus-lg"></i> New quotation</a>' : '');
?>
<div class="card">
  <div class="card-header pb-0 border-bottom-0">
    <ul class="nav nav-tabs status-tabs" role="tablist">
      <li class="nav-item"><a class="nav-link<?= $st === '' ? ' active' : '' ?>" href="<?= h(qs($base)) ?>">All <span class="badge text-bg-secondary"><?= $all ?></span></a></li>
      <?php foreach (QUOTE_STATUSES as $k => $v): ?><li class="nav-item"><a class="nav-link<?= $st === $k ? ' active' : '' ?>" href="<?= h(qs($base + ['status' => $k])) ?>"><?= h($v) ?> <span class="badge text-bg-<?= $tabs[$k] ? QUOTE_STATUS_COLORS[$k] : 'light' ?>"><?= $tabs[$k] ?></span></a></li><?php endforeach; ?>
    </ul>
  </div>
  <div class="card-body border-top">
    <form class="row g-2 align-items-end" method="get">
      <?php if ($st): ?><input type="hidden" name="status" value="<?= h($st) ?>"><?php endif; ?>
      <div class="col-md-5"><label class="form-label small mb-1" for="f-q">Search</label><input id="f-q" class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Quote number, name, company, email, phone"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1" for="f-s">Service</label><select id="f-s" class="form-select form-select-sm" name="service"><option value="">All services</option><?php foreach ($services as $k => $s): ?><option value="<?= h($k) ?>"<?= $svc === $k ? ' selected' : '' ?>><?= h($s['title']) ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-2"><div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="mine" value="1" id="f-m"<?= $mine ? ' checked' : '' ?>><label class="form-check-label small" for="f-m">Assigned to me</label></div></div>
      <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Filter</button><?php if ($q || $svc || $mine): ?><a class="btn btn-outline-secondary btn-sm" href="<?= h(qs(['status' => $st])) ?>">Clear</a><?php endif; ?></div>
    </form>
  </div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="back" value="<?= h($self) ?>">
  <div id="bulk-bar" class="d-none px-3 py-2 bg-body-tertiary border-top"><div class="d-flex flex-wrap gap-2 align-items-center">
    <span class="small"><b data-count>0</b> selected</span>
    <select name="bulk" class="form-select form-select-sm w-auto" aria-label="Bulk action"><option value="">Choose an action</option><?php if (can('quotes.edit')): ?><optgroup label="Set status"><?php foreach (QUOTE_STATUSES as $k => $v): ?><option value="<?= $k ?>"><?= h($v) ?></option><?php endforeach; ?></optgroup><?php endif; ?><?php if (can('quotes.delete')): ?><option value="delete">Delete permanently</option><?php endif; ?></select>
    <button class="btn btn-sm btn-outline-primary" data-confirm="Apply this action to the selected quotations?">Apply</button>
  </div></div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0">
    <thead><tr><?php $bulk = can('quotes.edit') || can('quotes.delete'); ?><th class="chk-col"><?php if ($bulk): ?><input class="form-check-input" type="checkbox" data-check-all="ids[]" aria-label="Select all"><?php endif; ?></th><th>Quote no.</th><th>Service</th><th>Client</th><th>Request</th><th>Status</th><th>Quoted</th><th>Assigned</th><th>Received</th></tr></thead><tbody>
    <?php foreach ($list as $r): ?><tr>
      <td><?php if ($bulk): ?><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" aria-label="Select <?= h($r['quote_no']) ?>"><?php endif; ?></td>
      <td class="mono text-nowrap"><a href="quote.php?id=<?= (int) $r['id'] ?>"><?= h($r['quote_no']) ?></a><?= $r['source'] === 'admin' ? ' <i class="bi bi-person-badge text-secondary" title="Entered by an admin"></i>' : '' ?><?= $r['email_status'] === 'failed' ? ' <i class="bi bi-envelope-x text-danger" title="Email copy failed"></i>' : '' ?></td>
      <td class="small"><span class="tag"><?= h(quote_service_tag($r['service'])) ?></span><br><?= h(quote_service_title($r['service'])) ?></td>
      <td><b><?= h($r['company'] ?: $r['client_name']) ?></b><br><span class="text-secondary small"><?= $r['company'] ? h($r['client_name']) . ' &middot; ' : '' ?><?= h($r['email']) ?></span></td>
      <td class="small"><?= h(mb_strimwidth((string) $r['summary'], 0, 60, '…')) ?></td>
      <td><?= status_badge($r['status']) ?></td>
      <td class="text-nowrap small"><?= h(money($r['quoted_amount'], $r['currency'])) ?: '<span class="text-secondary">–</span>' ?></td>
      <td class="small"><?= h($r['assignee'] ?: '') ?: '<span class="text-secondary">–</span>' ?></td>
      <td class="small text-nowrap"><?= h(eat($r['created_at'])) ?></td>
    </tr><?php endforeach; if (!$list): ?><tr><td colspan="9" class="text-secondary p-4">No quotations match.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  </form>
  <div class="card-footer"><?= pager($page, $pages, $total, fn($p) => qs($base + ['status' => $st, 'page' => $p]), 'quotation') ?></div>
</div>
<?php page_foot();
