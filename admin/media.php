<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
require_perm('media.view');
$pdo = db(); $me = (int) $admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? 'upload');
    deny_unless($act === 'delete' ? 'media.delete' : 'media.add', 'media.php');
    if ($act === 'delete') {
        $s = $pdo->prepare('SELECT * FROM media WHERE id = ?'); $s->execute([(int) ($_POST['id'] ?? 0)]); $m = $s->fetch();
        if ($m) {
            $u = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE cover_image = ? OR body LIKE ?'); $u->execute([$m['path'], '%' . $m['path'] . '%']);
            if ((int) $u->fetchColumn() && empty($_POST['force'])) flash('That image is used in a post. Remove it from the post first.', 'bad');
            else { delete_upload($m['path']); $pdo->prepare('DELETE FROM media WHERE id = ?')->execute([$m['id']]); log_activity($me, 'media_deleted', $m['original_name']); flash('Image deleted.'); }
        }
        redirect('media.php');
    }
    $f = $_FILES['images'] ?? null; $ok = 0; $errs = [];
    if ($f) foreach ((array) $f['name'] as $i => $name) {
        $one = ['name' => $name, 'tmp_name' => ((array) $f['tmp_name'])[$i], 'error' => ((array) $f['error'])[$i], 'size' => ((array) $f['size'])[$i]];
        [$good, $url, $info] = save_upload($one, 'media', ['png', 'jpg', 'webp', 'gif'], IMG_MAX);
        if (!$good) { if ($url !== '') $errs[] = $name . ': ' . $url; continue; }
        $pdo->prepare('INSERT INTO media (original_name, path, mime, size_bytes, width, height, alt_text, uploaded_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$info['orig'], $url, $info['mime'], $info['size'], $info['w'], $info['h'], mb_substr(trim((string) ($_POST['alt'] ?? '')), 0, 255) ?: null, $me, now()]);
        $ok++;
    }
    if ($ok) log_activity($me, 'media_uploaded', (string) $ok);
    flash(($ok ? $ok . ' image' . ($ok === 1 ? '' : 's') . ' uploaded. ' : '') . implode(' ', $errs) ?: 'Choose at least one image.', $errs && !$ok ? 'bad' : 'ok');
    redirect('media.php');
}
$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 24;
$total = (int) $pdo->query('SELECT COUNT(*) FROM media')->fetchColumn(); $pages = max(1, (int) ceil($total / $per)); $page = min($page, $pages); $off = ($page - 1) * $per;
$list = $pdo->query("SELECT m.*, a.full_name FROM media m LEFT JOIN admins a ON a.id = m.uploaded_by ORDER BY m.id DESC LIMIT $per OFFSET $off")->fetchAll();
function kb(int $n): string { return $n >= 1048576 ? round($n / 1048576, 1) . ' MB' : max(1, (int) round($n / 1024)) . ' KB'; }
page_head('Image library', $admin, 'media', [['Media', 'posts.php'], ['Image library', null]]);
?>
<?php if (can('media.add')): ?>
<div class="card card-outline card-primary"><div class="card-body"><form method="post" enctype="multipart/form-data" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
  <div class="col-md-6"><label class="form-label" for="m-f">Upload images</label><input id="m-f" class="form-control" type="file" name="images[]" multiple required accept=".png,.jpg,.jpeg,.webp,.gif,image/*"></div>
  <div class="col-md-4"><label class="form-label" for="m-a">Description (for screen readers, optional)</label><input id="m-a" class="form-control" name="alt" maxlength="255"></div>
  <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-upload"></i> Upload</button></div>
  <div class="col-12 form-text">PNG, JPG, WebP or GIF, up to 4 MB each. Use "Copy address" to put an image inside a post.</div>
</form></div></div>
<?php endif; ?>
<div class="row g-3 media-grid">
<?php foreach ($list as $m): ?>
  <div class="col-sm-6 col-md-4 col-xl-3"><div class="card h-100">
    <a href="..<?= h($m['path']) ?>" target="_blank" rel="noopener"><img class="card-img-top" src="..<?= h($m['path']) ?>" alt="<?= h((string) $m['alt_text']) ?>" loading="lazy"></a>
    <div class="card-body p-2 small"><div class="text-truncate fw-semibold" title="<?= h($m['original_name']) ?>"><?= h($m['original_name']) ?></div><div class="text-secondary"><?= $m['width'] ? (int) $m['width'] . ' x ' . (int) $m['height'] . ' &middot; ' : '' ?><?= kb((int) $m['size_bytes']) ?> &middot; <?= h(eat($m['created_at'])) ?></div></div>
    <div class="card-footer p-2 d-flex gap-1"><button type="button" class="btn btn-sm btn-outline-primary flex-grow-1" data-copy="<?= h($m['path']) ?>"><i class="bi bi-clipboard"></i> Copy address</button>
      <?php if (can('media.delete')): ?><form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="btn btn-sm btn-outline-danger" aria-label="Delete image" data-confirm="Delete this image?"><i class="bi bi-trash3"></i></button></form><?php endif; ?></div>
  </div></div>
<?php endforeach; if (!$list): ?><div class="col-12"><div class="card"><div class="card-body text-secondary">No images yet.</div></div></div><?php endif; ?>
</div>
<div class="mt-3"><?= pager($page, $pages, $total, fn($p) => 'media.php?page=' . $p, 'image') ?></div>
<?php page_foot();
