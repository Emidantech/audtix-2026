<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
require_perm('posts.view');
$pdo = db(); $me = (int) $admin['id'];
const POST_STATUSES = ['draft' => ['Draft', 'secondary'], 'published' => ['Published', 'success'], 'archived' => ['Archived', 'dark']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = (string) ($_POST['back'] ?? 'posts.php'); if (!preg_match('/^posts\.php(\?[\w=&%.-]*)?$/', $back)) $back = 'posts.php';
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $act = (string) ($_POST['bulk'] ?? '');
    if (!$ids) { flash('Select at least one post.', 'bad'); redirect($back); }
    $in = implode(',', array_fill(0, count($ids), '?'));
    if (isset(POST_STATUSES[$act])) {
        deny_unless('posts.publish', $back);
        $pdo->prepare("UPDATE posts SET status = ?, published_at = CASE WHEN ? = 'published' AND published_at IS NULL THEN ? ELSE published_at END, updated_by = ?, updated_at = ? WHERE id IN ($in)")
            ->execute(array_merge([$act, $act, now(), $me, now()], $ids));
        log_activity($me, 'posts_status', implode(',', $ids), $act); flash(count($ids) . ' post(s) set to ' . POST_STATUSES[$act][0] . '.');
    } elseif ($act === 'delete') {
        deny_unless('posts.delete', $back);
        $rows = $pdo->prepare("SELECT id, title, attachment FROM posts WHERE id IN ($in)"); $rows->execute($ids);
        foreach ($rows->fetchAll() as $p) { delete_upload($p['attachment']); $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$p['id']]); log_activity($me, 'post_deleted', $p['title']); }
        flash(count($ids) . ' post(s) deleted.');
    }
    redirect($back);
}

$types = $pdo->query('SELECT id, name, slug FROM post_types ORDER BY sort_order, name')->fetchAll();
$typeNames = array_column($types, 'name', 'id');
$type = (int) ($_GET['type'] ?? 0); if (!isset($typeNames[$type])) $type = 0;
$st = (string) ($_GET['status'] ?? ''); if (!isset(POST_STATUSES[$st])) $st = '';
$q = trim((string) ($_GET['q'] ?? '')); $page = max(1, (int) ($_GET['page'] ?? 1)); $per = 20;
$where = []; $args = [];
if ($type) { $where[] = 'p.type_id = ?'; $args[] = $type; }
if ($st) { $where[] = 'p.status = ?'; $args[] = $st; }
if ($q !== '') { $where[] = '(p.title LIKE ? OR p.excerpt LIKE ? OR p.author_name LIKE ?)'; $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%'; array_push($args, $like, $like, $like); }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$c = $pdo->prepare("SELECT COUNT(*) FROM posts p $w"); $c->execute($args); $total = (int) $c->fetchColumn();
$pages = max(1, (int) ceil($total / $per)); $page = min($page, $pages); $off = ($page - 1) * $per;
$s = $pdo->prepare("SELECT p.*, t.name type_name, t.slug type_slug, a.full_name editor FROM posts p JOIN post_types t ON t.id = p.type_id LEFT JOIN admins a ON a.id = p.updated_by $w ORDER BY COALESCE(p.published_at, p.created_at) DESC, p.id DESC LIMIT $per OFFSET $off");
$s->execute($args); $list = $s->fetchAll();
$cnt = []; $cs = $pdo->prepare('SELECT status, COUNT(*) n FROM posts' . ($type ? ' WHERE type_id = ?' : '') . ' GROUP BY status'); $cs->execute($type ? [$type] : []); foreach ($cs as $r) $cnt[$r['status']] = (int) $r['n'];
function qs(array $o): string { return 'posts.php?' . http_build_query(array_filter($o, fn($v) => $v !== '' && $v !== 0 && $v !== null)); }
$base = ['type' => $type, 'q' => $q]; $self = qs($base + ['status' => $st, 'page' => $page > 1 ? $page : '']);
$bulk = can('posts.publish') || can('posts.delete');
page_head($type ? $typeNames[$type] : 'All posts', $admin, 'posts:' . ($type ?: 'all'), [], can('posts.add') ? '<a class="btn btn-primary btn-sm" href="post-edit.php' . ($type ? '?type=' . $type : '') . '"><i class="bi bi-plus-lg"></i> New post</a> <a class="btn btn-outline-secondary btn-sm" href="../media.html' . ($type ? '?type=' . h(array_column($types, 'slug', 'id')[$type]) : '') . '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> View on website</a>' : '');
?>
<div class="card">
  <div class="card-header pb-0 border-bottom-0"><ul class="nav nav-tabs status-tabs">
    <li class="nav-item"><a class="nav-link<?= $st === '' ? ' active' : '' ?>" href="<?= h(qs($base)) ?>">All <span class="badge text-bg-secondary"><?= array_sum($cnt) ?></span></a></li>
    <?php foreach (POST_STATUSES as $k => [$l, $col]): ?><li class="nav-item"><a class="nav-link<?= $st === $k ? ' active' : '' ?>" href="<?= h(qs($base + ['status' => $k])) ?>"><?= $l ?> <span class="badge text-bg-<?= ($cnt[$k] ?? 0) ? $col : 'light' ?>"><?= $cnt[$k] ?? 0 ?></span></a></li><?php endforeach; ?>
  </ul></div>
  <div class="card-body border-top"><form class="row g-2 align-items-end" method="get"><?php if ($st): ?><input type="hidden" name="status" value="<?= h($st) ?>"><?php endif; ?>
    <div class="col-md-6"><label class="form-label small mb-1" for="f-q">Search</label><input id="f-q" class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Title, summary or author"></div>
    <div class="col-md-4"><label class="form-label small mb-1" for="f-t">Type</label><select id="f-t" class="form-select form-select-sm" name="type"><option value="">All types</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"<?= $type === (int) $t['id'] ? ' selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Filter</button></div></form></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="back" value="<?= h($self) ?>">
  <div id="bulk-bar" class="d-none px-3 py-2 bg-body-tertiary border-top"><div class="d-flex flex-wrap gap-2 align-items-center"><span class="small"><b data-count>0</b> selected</span>
    <select name="bulk" class="form-select form-select-sm w-auto" aria-label="Bulk action"><option value="">Choose an action</option><?php if (can('posts.publish')): ?><option value="published">Publish</option><option value="draft">Move to draft</option><option value="archived">Archive</option><?php endif; ?><?php if (can('posts.delete')): ?><option value="delete">Delete permanently</option><?php endif; ?></select>
    <button class="btn btn-sm btn-outline-primary" data-confirm="Apply this action to the selected posts?">Apply</button></div></div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th class="chk-col"><?php if ($bulk): ?><input class="form-check-input" type="checkbox" data-check-all="ids[]" aria-label="Select all"><?php endif; ?></th><th></th><th>Title</th><th>Type</th><th>Status</th><th>Published</th><th>Views</th><th>Last edited</th></tr></thead><tbody>
  <?php foreach ($list as $p): ?><tr>
    <td><?php if ($bulk): ?><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>" aria-label="Select <?= h($p['title']) ?>"><?php endif; ?></td>
    <td><?php if ($p['cover_image']): ?><img class="post-cover" src="..<?= h($p['cover_image']) ?>" alt=""><?php else: ?><span class="post-cover d-inline-grid align-items-center justify-content-center text-secondary"><i class="bi bi-image"></i></span><?php endif; ?></td>
    <td><a class="fw-semibold" href="post-edit.php?id=<?= (int) $p['id'] ?>"><?= h($p['title']) ?></a><?= (int) $p['is_featured'] ? ' <i class="bi bi-star-fill text-warning" title="Featured"></i>' : '' ?><?= $p['attachment'] ? ' <i class="bi bi-file-earmark-pdf text-danger" title="Has a PDF"></i>' : '' ?><br><span class="small text-secondary"><?= h(mb_strimwidth((string) $p['excerpt'], 0, 90, '…')) ?></span></td>
    <td><span class="badge text-bg-light"><?= h($p['type_name']) ?></span></td>
    <td><span class="badge text-bg-<?= POST_STATUSES[$p['status']][1] ?>"><?= POST_STATUSES[$p['status']][0] ?></span></td>
    <td class="small text-nowrap"><?= $p['published_at'] ? h(eat($p['published_at'])) : '–' ?></td>
    <td><?= (int) $p['views'] ?></td>
    <td class="small"><?= h($p['editor'] ?: '–') ?><br><span class="text-secondary"><?= h(eat($p['updated_at'])) ?></span></td>
  </tr><?php endforeach; if (!$list): ?><tr><td colspan="8" class="text-secondary p-4">No posts yet.<?= can('posts.add') ? ' <a href="post-edit.php">Write the first one</a>.' : '' ?></td></tr><?php endif; ?>
  </tbody></table></div></div></form>
  <div class="card-footer"><?= pager($page, $pages, $total, fn($p) => qs($base + ['status' => $st, 'page' => $p]), 'post') ?></div>
</div>
<?php page_foot();
