<?php
require __DIR__ . '/inc/boot.php';
require dirname(__DIR__) . '/api/lib/markdown.php';
$admin = require_login();
$pdo = db(); $me = (int) $admin['id'];
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
require_perm($id ? 'posts.view' : 'posts.add');
$p = null;
if ($id) { $s = $pdo->prepare('SELECT * FROM posts WHERE id = ?'); $s->execute([$id]); $p = $s->fetch(); if (!$p) { http_response_code(404); page_head('Not found', $admin, 'posts:all'); echo '<div class="alert alert-warning">Post not found.</div>'; page_foot(); exit; } }
$types = $pdo->query('SELECT id, name, slug FROM post_types ORDER BY sort_order, name')->fetchAll();
$canSave = $id ? can('posts.edit') : can('posts.add');
$tz = new DateTimeZone('Africa/Kampala');
$toLocal = fn(?string $utc) => $utc ? (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d\TH:i') : '';
$toUtc = function (string $local) use ($tz): ?string { $d = DateTime::createFromFormat('Y-m-d\TH:i', $local, $tz); return $d ? $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'delete') {
        deny_unless('posts.delete', 'post-edit.php?id=' . $id);
        delete_upload($p['attachment'] ?? null);
        $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]); log_activity($me, 'post_deleted', $p['title']);
        flash('Post deleted.'); redirect('posts.php');
    }
    deny_unless($id ? 'posts.edit' : 'posts.add', $id ? 'post-edit.php?id=' . $id : 'post-edit.php');
    $in = fn($k, $n) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $n);
    $title = $in('title', 200); $body = (string) ($_POST['body'] ?? ''); $typeId = (int) ($_POST['type_id'] ?? 0);
    $slug = slugify($in('slug', 200) ?: $title);
    $err = [];
    if (mb_strlen($title) < 3) $err[] = 'Give the post a title.';
    if (!in_array($typeId, array_map(fn($t) => (int) $t['id'], $types), true)) $err[] = 'Choose a type.';
    if (mb_strlen(trim($body)) < 10) $err[] = 'Write the post (at least a sentence).';
    if (mb_strlen($body) > 200000) $err[] = 'The post is too long.';
    if ($slug === '') $slug = 'post-' . time();
    $x = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE slug = ? AND id <> ?'); $x->execute([$slug, $id]); if ((int) $x->fetchColumn()) $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
    // status: only people with "publish" may publish or archive
    $status = in_array($_POST['status'] ?? '', ['draft', 'published', 'archived'], true) ? $_POST['status'] : 'draft';
    if (!can('posts.publish')) $status = $p ? $p['status'] : 'draft';
    $pubAt = $toUtc((string) ($_POST['published_at'] ?? '')) ?? ($p['published_at'] ?? null);
    if ($status === 'published' && !$pubAt) $pubAt = now();
    // cover image: new upload (also added to the image library), pick from library, or remove
    $cover = $p['cover_image'] ?? null;
    if (!empty($_POST['remove_cover'])) $cover = null;
    $pick = (string) ($_POST['cover_pick'] ?? '');
    if ($pick !== '' && preg_match('#^/uploads/media/[0-9]{8}-[a-f0-9]{12}\.(png|jpg|webp|gif)$#', $pick)) $cover = $pick;
    if (!empty($_FILES['cover']) && $_FILES['cover']['error'] !== UPLOAD_ERR_NO_FILE) {
        [$ok, $url, $info] = save_upload($_FILES['cover'], 'media', ['png', 'jpg', 'webp'], IMG_MAX);
        if ($ok) { $cover = $url; $pdo->prepare('INSERT INTO media (original_name, path, mime, size_bytes, width, height, alt_text, uploaded_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$info['orig'], $url, $info['mime'], $info['size'], $info['w'], $info['h'], $title, $me, now()]); }
        else $err[] = 'Cover image: ' . $url;
    }
    // PDF (publications)
    $att = $p['attachment'] ?? null; $attName = $p['attachment_name'] ?? null;
    if (!empty($_POST['remove_attachment'])) { delete_upload($att); $att = $attName = null; }
    if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
        [$ok, $url, $info] = save_upload($_FILES['attachment'], 'docs', ['pdf'], PDF_MAX);
        if ($ok) { delete_upload($att); $att = $url; $attName = $info['orig']; } else $err[] = 'PDF: ' . $url;
    }
    if ($err) { flash(implode(' ', $err), 'bad'); $_SESSION['post_draft'] = $_POST; redirect($id ? 'post-edit.php?id=' . $id : 'post-edit.php?type=' . $typeId); }
    $vals = [$typeId, $title, $slug, $in('excerpt', 500) ?: md_plain($body, 220), $body, $cover, $att, $attName, $in('author_name', 120) ?: null, $status, empty($_POST['is_featured']) ? 0 : 1, $pubAt, $me, now()];
    if ($id) {
        $pdo->prepare('UPDATE posts SET type_id = ?, title = ?, slug = ?, excerpt = ?, body = ?, cover_image = ?, attachment = ?, attachment_name = ?, author_name = ?, status = ?, is_featured = ?, published_at = ?, updated_by = ?, updated_at = ? WHERE id = ?')->execute(array_merge($vals, [$id]));
        log_activity($me, 'post_updated', $title, $status);
    } else {
        $pdo->prepare('INSERT INTO posts (type_id, title, slug, excerpt, body, cover_image, attachment, attachment_name, author_name, status, is_featured, published_at, updated_by, updated_at, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($vals, [$me, now()]));
        $id = (int) $pdo->lastInsertId(); log_activity($me, 'post_created', $title, $status);
    }
    flash($status === 'published' ? 'Saved and published. It is live on the Media page.' : 'Saved as ' . ($status === 'draft' ? 'a draft' : 'archived') . '.');
    redirect('post-edit.php?id=' . $id);
}

$d = $_SESSION['post_draft'] ?? null; unset($_SESSION['post_draft']);
$v = fn($k, $def = '') => (string) ($d[$k] ?? ($p[$k] ?? $def));
$library = can('media.view') ? $pdo->query('SELECT path, original_name FROM media ORDER BY id DESC LIMIT 60')->fetchAll() : [];
$typeSlug = ''; foreach ($types as $t) if ($p && (int) $t['id'] === (int) $p['type_id']) $typeSlug = $t['slug'];
$ro = $canSave ? '' : ' disabled';
$actions = $p && $p['status'] === 'published' ? '<a class="btn btn-sm btn-outline-secondary" href="../post.html?slug=' . h($p['slug']) . '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> View on website</a>' : '';
page_head($p ? 'Edit post' : 'New post', $admin, $p ? 'post' : 'post:new', [], $actions . ' <a class="btn btn-sm btn-outline-secondary" href="posts.php"><i class="bi bi-arrow-left"></i> All posts</a>');
?>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card"><div class="card-body">
      <div class="mb-3"><label class="form-label" for="p-title">Title</label><input id="p-title" class="form-control form-control-lg" name="title" required maxlength="200" value="<?= h($v('title')) ?>"<?= $ro ?>></div>
      <div class="mb-3"><label class="form-label" for="p-slug">Web address</label><div class="input-group"><span class="input-group-text small">post.html?slug=</span><input id="p-slug" class="form-control" name="slug" maxlength="200" value="<?= h($v('slug')) ?>" placeholder="made from the title"<?= $ro ?>></div></div>
      <div class="mb-3"><label class="form-label" for="p-ex">Summary</label><textarea id="p-ex" class="form-control" name="excerpt" rows="2" maxlength="500" placeholder="One or two sentences shown on the Media page. Left empty, the start of the post is used."<?= $ro ?>><?= h($v('excerpt')) ?></textarea></div>
      <label class="form-label" for="p-body">Post</label>
      <div class="md-toolbar btn-group btn-group-sm mb-1 flex-wrap" role="toolbar" aria-label="Formatting">
        <?php foreach (['h2' => ['type-h2', 'Heading'], 'h3' => ['type-h3', 'Subheading'], 'bold' => ['type-bold', 'Bold'], 'italic' => ['type-italic', 'Italic'], 'ul' => ['list-ul', 'Bullet list'], 'ol' => ['list-ol', 'Numbered list'], 'quote' => ['quote', 'Quote'], 'link' => ['link-45deg', 'Link'], 'img' => ['image', 'Image']] as $k => [$ic, $l]): ?><button type="button" class="btn btn-outline-secondary" data-md="<?= $k ?>" title="<?= $l ?>" aria-label="<?= $l ?>"<?= $ro ?>><i class="bi bi-<?= $ic ?>"></i></button><?php endforeach; ?>
      </div>
      <textarea id="p-body" class="form-control mono" name="body" rows="18" required<?= $ro ?>><?= h($v('body')) ?></textarea>
      <div class="form-text">Formatting: <code>## Heading</code>, <code>**bold**</code>, <code>*italic*</code>, <code>- list</code>, <code>[link](https://...)</code>, <code>![picture](/uploads/media/...)</code>. Copy picture addresses from the <a href="media.php" target="_blank">Image library</a>.</div>
    </div></div>
    <?php if ($p): ?><div class="card"><div class="card-header"><h3 class="card-title">Preview (last saved version)</h3></div><div class="card-body post-body"><?= md_render((string) $p['body']) ?></div></div><?php endif; ?>
  </div>
  <div class="col-xl-4">
    <div class="card card-outline card-primary"><div class="card-header"><h3 class="card-title">Publish</h3></div><div class="card-body">
      <div class="mb-2"><label class="form-label" for="p-type">Type</label><select id="p-type" class="form-select" name="type_id" required<?= $ro ?>><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"<?= (int) $v('type_id', (string) ($_GET['type'] ?? '')) === (int) $t['id'] ? ' selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?></select></div>
      <div class="mb-2"><label class="form-label" for="p-st">Status</label><select id="p-st" class="form-select" name="status"<?= can('posts.publish') ? $ro : ' disabled' ?>><?php foreach (['draft' => 'Draft (not on the website)', 'published' => 'Published', 'archived' => 'Archived (hidden)'] as $k => $l): ?><option value="<?= $k ?>"<?= $v('status', 'draft') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><?= can('posts.publish') ? '' : '<div class="form-text">Your role can save drafts. Someone with publish permission makes it live.</div>' ?></div>
      <div class="mb-2"><label class="form-label" for="p-at">Publish date (Kampala time)</label><input id="p-at" class="form-control" type="datetime-local" name="published_at" value="<?= h($d['published_at'] ?? $toLocal($p['published_at'] ?? null)) ?>"<?= $ro ?>><div class="form-text">Leave empty to use the moment you publish.</div></div>
      <div class="mb-2"><label class="form-label" for="p-au">Author</label><input id="p-au" class="form-control" name="author_name" maxlength="120" value="<?= h($v('author_name', $admin['full_name'])) ?>"<?= $ro ?>></div>
      <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="is_featured" value="1" id="p-f"<?= $v('is_featured') === '1' ? ' checked' : '' ?><?= $ro ?>><label class="form-check-label" for="p-f">Feature at the top of the Media page</label></div>
      <?php if ($canSave): ?><button class="btn btn-primary w-100"><i class="bi bi-check2"></i> Save</button><?php endif; ?>
      <?php if ($p): ?><p class="small text-secondary mt-2 mb-0">Views: <?= (int) $p['views'] ?> &middot; created <?= h(eat($p['created_at'])) ?></p><?php endif; ?>
    </div></div>
    <div class="card"><div class="card-header"><h3 class="card-title">Cover image</h3></div><div class="card-body">
      <?php if ($p && $p['cover_image']): ?><img src="..<?= h($p['cover_image']) ?>" alt="" class="img-fluid rounded mb-2"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="remove_cover" value="1" id="rc"<?= $ro ?>><label class="form-check-label small" for="rc">Remove cover</label></div><?php endif; ?>
      <label class="form-label small" for="p-cover">Upload a new image</label><input id="p-cover" class="form-control form-control-sm mb-2" type="file" name="cover" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"<?= $ro ?>>
      <?php if ($library): ?><label class="form-label small" for="p-pick">or pick from the library</label><select id="p-pick" class="form-select form-select-sm" name="cover_pick"<?= $ro ?>><option value="">Keep current</option><?php foreach ($library as $m): ?><option value="<?= h($m['path']) ?>"><?= h($m['original_name']) ?></option><?php endforeach; ?></select><?php endif; ?>
      <div class="form-text">Landscape works best, about 1200 x 675 pixels. Up to 4 MB.</div>
    </div></div>
    <div class="card"><div class="card-header"><h3 class="card-title">PDF download</h3></div><div class="card-body">
      <?php if ($p && $p['attachment']): ?><p class="small mb-1"><i class="bi bi-file-earmark-pdf text-danger"></i> <a href="..<?= h($p['attachment']) ?>" target="_blank" rel="noopener"><?= h((string) $p['attachment_name']) ?></a></p><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="remove_attachment" value="1" id="ra"<?= $ro ?>><label class="form-check-label small" for="ra">Remove PDF</label></div><?php endif; ?>
      <input class="form-control form-control-sm" type="file" name="attachment" accept=".pdf,application/pdf" aria-label="PDF"<?= $ro ?>><div class="form-text">For publications: a report or guide readers can download. Up to 15 MB.</div>
    </div></div>
  </div>
</div>
</form>
<?php if ($p && can('posts.delete')): ?>
<form method="post" class="mb-4" data-confirm="Delete this post permanently?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="delete"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Delete post</button></form>
<?php endif; page_foot();
