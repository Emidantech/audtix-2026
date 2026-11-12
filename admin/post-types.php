<?php
require __DIR__ . '/inc/boot.php';
require dirname(__DIR__) . '/api/lib/markdown.php';
$admin = require_login();
require_perm('post_types.view');
$pdo = db(); $me = (int) $admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? ''); $id = (int) ($_POST['id'] ?? 0);
    deny_unless($act === 'delete' ? 'post_types.delete' : ($id ? 'post_types.edit' : 'post_types.add'), 'post-types.php');
    if ($act === 'delete') {
        $n = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE type_id = ?'); $n->execute([$id]);
        if ((int) $n->fetchColumn()) flash('This type still has posts. Move or delete them first, or hide the type instead.', 'bad');
        else { $pdo->prepare('DELETE FROM post_types WHERE id = ?')->execute([$id]); log_activity($me, 'post_type_deleted', (string) $id); flash('Type deleted.'); }
        redirect('post-types.php');
    }
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80);
    $slug = slugify((string) ($_POST['slug'] ?? '') ?: $name);
    $x = $pdo->prepare('SELECT COUNT(*) FROM post_types WHERE slug = ? AND id <> ?'); $x->execute([$slug, $id]);
    if (mb_strlen($name) < 2 || $slug === '') flash('Give the type a name.', 'bad');
    elseif ((int) $x->fetchColumn()) flash('Another type already uses the address "' . $slug . '".', 'bad');
    else {
        $vals = [$name, $slug, mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255) ?: null, (int) ($_POST['sort_order'] ?? 0), empty($_POST['is_active']) ? 0 : 1, now()];
        if ($id) $pdo->prepare('UPDATE post_types SET name = ?, slug = ?, description = ?, sort_order = ?, is_active = ?, updated_at = ? WHERE id = ?')->execute(array_merge($vals, [$id]));
        else $pdo->prepare('INSERT INTO post_types (name, slug, description, sort_order, is_active, updated_at, created_at) VALUES (?,?,?,?,?,?,?)')->execute(array_merge($vals, [now()]));
        log_activity($me, 'post_type_saved', $slug); flash('Type "' . $name . '" saved.');
        redirect('post-types.php');
    }
    redirect('post-types.php' . ($id ? '?edit=' . $id : ''));
}
$types = $pdo->query("SELECT t.*, (SELECT COUNT(*) FROM posts p WHERE p.type_id = t.id) n, (SELECT COUNT(*) FROM posts p WHERE p.type_id = t.id AND p.status = 'published') pub FROM post_types t ORDER BY t.sort_order, t.name")->fetchAll();
$edit = null; if (isset($_GET['edit'])) foreach ($types as $t) if ((int) $t['id'] === (int) $_GET['edit']) $edit = $t;
$canForm = $edit ? can('post_types.edit') : can('post_types.add');
page_head('Post types', $admin, 'post_types', [['Media', 'posts.php'], ['Post types', null]]);
?>
<p class="text-secondary">Types group the posts on the website's Media page, for example Blog, Stories and Publications. Each type gets its own tab.</p>
<div class="row g-3">
  <div class="<?= $canForm ? 'col-xl-8' : 'col-12' ?>"><div class="card"><div class="card-body p-0"><table class="table table-hover mb-0"><thead><tr><th>Order</th><th>Name</th><th>Web address</th><th>Posts</th><th>Shown</th><th></th></tr></thead><tbody>
  <?php foreach ($types as $t): ?><tr>
    <td><?= (int) $t['sort_order'] ?></td><td><b><?= h($t['name']) ?></b><br><span class="small text-secondary"><?= h((string) $t['description']) ?></span></td>
    <td class="mono small">media.html?type=<?= h($t['slug']) ?></td>
    <td><?php if (can('posts.view')): ?><a href="posts.php?type=<?= (int) $t['id'] ?>"><?= (int) $t['n'] ?></a><?php else: ?><?= (int) $t['n'] ?><?php endif; ?> <span class="small text-secondary">(<?= (int) $t['pub'] ?> published)</span></td>
    <td><span class="badge text-bg-<?= (int) $t['is_active'] ? 'success' : 'secondary' ?>"><?= (int) $t['is_active'] ? 'Yes' : 'Hidden' ?></span></td>
    <td class="text-end text-nowrap"><?php if (can('post_types.edit')): ?><a class="btn btn-sm btn-outline-primary" href="post-types.php?edit=<?= (int) $t['id'] ?>" aria-label="Edit <?= h($t['name']) ?>"><i class="bi bi-pencil"></i></a><?php endif; ?>
      <?php if (can('post_types.delete')): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="btn btn-sm btn-outline-danger" aria-label="Delete <?= h($t['name']) ?>" data-confirm="Delete the type <?= h($t['name']) ?>?"<?= (int) $t['n'] ? ' disabled title="Has posts"' : '' ?>><i class="bi bi-trash3"></i></button></form><?php endif; ?></td>
  </tr><?php endforeach; if (!$types): ?><tr><td colspan="6" class="text-secondary p-4">No types yet.</td></tr><?php endif; ?>
  </tbody></table></div></div></div>
  <?php if ($canForm): ?>
  <div class="col-xl-4"><div class="card card-outline card-primary"><div class="card-header"><h3 class="card-title"><?= $edit ? 'Edit ' . h($edit['name']) : 'Add a type' ?></h3><?php if ($edit): ?><div class="card-tools"><a class="btn btn-tool" href="post-types.php" aria-label="Close"><i class="bi bi-x-lg"></i></a></div><?php endif; ?></div><div class="card-body">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
      <div class="mb-2"><label class="form-label" for="t-n">Name</label><input id="t-n" class="form-control" name="name" required maxlength="80" value="<?= h($edit['name'] ?? '') ?>" placeholder="e.g. News"></div>
      <div class="mb-2"><label class="form-label" for="t-s">Web address</label><div class="input-group"><span class="input-group-text small">?type=</span><input id="t-s" class="form-control" name="slug" maxlength="80" value="<?= h($edit['slug'] ?? '') ?>" placeholder="made from the name"></div></div>
      <div class="mb-2"><label class="form-label" for="t-d">Description</label><input id="t-d" class="form-control" name="description" maxlength="255" value="<?= h((string) ($edit['description'] ?? '')) ?>"></div>
      <div class="row g-2 mb-3"><div class="col-6"><label class="form-label" for="t-o">Order</label><input id="t-o" class="form-control" name="sort_order" type="number" value="<?= (int) ($edit['sort_order'] ?? (count($types) + 1)) ?>"></div>
        <div class="col-6 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="t-a"<?= !$edit || (int) $edit['is_active'] ? ' checked' : '' ?>><label class="form-check-label" for="t-a">Show on website</label></div></div></div>
      <button class="btn btn-primary"><?= $edit ? 'Save' : 'Add type' ?></button></form></div></div></div>
  <?php endif; ?>
</div>
<?php page_foot();
