<?php
declare(strict_types=1);
/* Public, read-only feed for media.html and post.html. Only published posts of visible types.
   GET ?type=<slug>&page=N   -> list      GET ?slug=<slug>   -> one post (body rendered to safe HTML) */
require __DIR__ . '/lib/common.php';
require __DIR__ . '/lib/markdown.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
function out(int $c, array $b, int $cache = 60): void { http_response_code($c); header('Cache-Control: public, max-age=' . $cache); echo json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') out(405, ['ok' => false], 0);
try { $pdo = db(); } catch (Throwable $e) { out(503, ['ok' => false, 'error' => 'unavailable'], 0); }
$now = now();
$types = $pdo->query('SELECT id, name, slug, description FROM post_types WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
$typeOut = array_map(fn($t) => ['name' => $t['name'], 'slug' => $t['slug'], 'description' => (string) $t['description']], $types);
$fmt = function (array $p, bool $full = false): array {
    $o = ['title' => $p['title'], 'slug' => $p['slug'], 'type' => $p['type_slug'], 'type_name' => $p['type_name'], 'excerpt' => (string) $p['excerpt'],
          'cover' => $p['cover_image'] ?: null, 'author' => $p['author_name'] ?: null, 'date' => substr((string) $p['published_at'], 0, 10), 'featured' => (bool) $p['is_featured'],
          'pdf' => $p['attachment'] ? ['url' => $p['attachment'], 'name' => $p['attachment_name'] ?: 'Download PDF'] : null,
          'minutes' => max(1, (int) round(str_word_count(strip_tags((string) $p['body'])) / 200))];
    if ($full) $o['html'] = md_render((string) $p['body']);
    return $o;
};
$base = "FROM posts p JOIN post_types t ON t.id = p.type_id WHERE p.status = 'published' AND t.is_active = 1 AND (p.published_at IS NULL OR p.published_at <= ?)";

if (isset($_GET['slug'])) {
    $s = $pdo->prepare("SELECT p.*, t.slug type_slug, t.name type_name $base AND p.slug = ?"); $s->execute([$now, (string) $_GET['slug']]); $p = $s->fetch();
    if (!$p) out(404, ['ok' => false, 'error' => 'not_found'], 30);
    try { $pdo->prepare('UPDATE posts SET views = views + 1 WHERE id = ?')->execute([$p['id']]); } catch (Throwable $e) {}
    $r = $pdo->prepare("SELECT p.*, t.slug type_slug, t.name type_name $base AND p.type_id = ? AND p.id <> ? ORDER BY p.published_at DESC LIMIT 3"); $r->execute([$now, $p['type_id'], $p['id']]);
    out(200, ['ok' => true, 'post' => $fmt($p, true), 'related' => array_map($fmt, $r->fetchAll())], 60);
}
$type = (string) ($_GET['type'] ?? ''); $page = max(1, (int) ($_GET['page'] ?? 1)); $per = 9;
$where = $base; $args = [$now];
if ($type !== '' && in_array($type, array_column($types, 'slug'), true)) { $where .= ' AND t.slug = ?'; $args[] = $type; } else $type = '';
$c = $pdo->prepare("SELECT COUNT(*) $where"); $c->execute($args); $total = (int) $c->fetchColumn();
$s = $pdo->prepare("SELECT p.*, t.slug type_slug, t.name type_name $where ORDER BY p.is_featured DESC, p.published_at DESC, p.id DESC LIMIT $per OFFSET " . (($page - 1) * $per)); $s->execute($args);
out(200, ['ok' => true, 'types' => $typeOut, 'type' => $type, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per)), 'total' => $total, 'posts' => array_map($fmt, $s->fetchAll())], 60);
