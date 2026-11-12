<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
$pdo = db(); $me = (int) $admin['id'];
$mods = array_values(array_filter(array_keys(PERMISSIONS), fn($m) => can($m . '.view')));
$in = $mods ? implode(',', array_fill(0, count($mods), '?')) : "''";

$markRead = function (array $ids) use ($pdo, $me) {
    $ins = $pdo->prepare('INSERT INTO notification_reads (notification_id, admin_id, read_at) VALUES (?,?,?)');
    foreach ($ids as $id) { try { $ins->execute([$id, $me, now()]); } catch (PDOException $e) { /* already read */ } }
};

// Open one: mark it read and go to its page.
if (isset($_GET['open'])) {
    $s = $pdo->prepare("SELECT id, link FROM notifications WHERE id = ? AND module IN ($in)"); $s->execute(array_merge([(int) $_GET['open']], $mods)); $n = $s->fetch();
    if ($n) { $markRead([(int) $n['id']]); if ($n['link'] && preg_match('/^[a-z-]+\.php(\?[\w=&%.-]*)?$/', $n['link'])) redirect($n['link']); }
    redirect('notifications.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mods) {
    $s = $pdo->prepare("SELECT n.id FROM notifications n LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.admin_id = ? WHERE r.read_at IS NULL AND n.module IN ($in)");
    $s->execute(array_merge([$me], $mods)); $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    $markRead($ids); flash(count($ids) . ' notification' . (count($ids) === 1 ? '' : 's') . ' marked as read.');
    redirect('notifications.php');
}

$only = ($_GET['show'] ?? '') === 'unread'; $page = max(1, (int) ($_GET['page'] ?? 1)); $per = 30;
$where = "n.module IN ($in)" . ($only ? ' AND r.read_at IS NULL' : '');
$args = array_merge([$me], $mods);
$c = $pdo->prepare("SELECT COUNT(*) FROM notifications n LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.admin_id = ? WHERE $where"); $c->execute($args); $total = (int) $c->fetchColumn();
$pages = max(1, (int) ceil($total / $per)); $page = min($page, $pages); $off = ($page - 1) * $per;
$s = $pdo->prepare("SELECT n.*, r.read_at FROM notifications n LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.admin_id = ? WHERE $where ORDER BY n.id DESC LIMIT $per OFFSET $off"); $s->execute($args); $list = $mods ? $s->fetchAll() : [];
$unread = nav_counts()['unread'];
page_head('Notifications', $admin, 'notifications', [['Notifications', null]]);
?>
<div class="card">
  <div class="card-header d-flex flex-wrap align-items-center gap-2">
    <ul class="nav nav-pills"><li class="nav-item"><a class="nav-link<?= $only ? '' : ' active' ?>" href="notifications.php">All</a></li><li class="nav-item"><a class="nav-link<?= $only ? ' active' : '' ?>" href="notifications.php?show=unread">Unread <span class="badge text-bg-danger"><?= $unread ?></span></a></li></ul>
    <?php if ($unread): ?><form method="post" class="ms-auto m-0"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary"><i class="bi bi-check2-all"></i> Mark all as read</button></form><?php endif; ?>
  </div>
  <div class="list-group list-group-flush notif-list">
    <?php foreach ($list as $n): ?>
    <a class="list-group-item list-group-item-action d-flex gap-3<?= $n['read_at'] ? '' : ' unread' ?>" href="notifications.php?open=<?= (int) $n['id'] ?>">
      <span class="action-icon text-bg-<?= ['quote' => 'primary', 'tax_request' => 'success', 'audit_request' => 'info'][$n['type']] ?? 'secondary' ?>" style="width:40px;height:40px;font-size:18px;border-radius:10px"><i class="bi bi-<?= notif_icon($n['type']) ?>"></i></span>
      <span class="flex-grow-1"><span class="d-block fw-semibold"><?= h($n['title']) ?><?= $n['read_at'] ? '' : ' <span class="badge text-bg-danger">new</span>' ?></span><span class="d-block small text-secondary"><?= h((string) $n['body']) ?></span></span>
      <span class="small text-secondary text-nowrap"><?= h(eat($n['created_at'])) ?></span>
    </a>
    <?php endforeach; if (!$list): ?><div class="list-group-item text-secondary p-4"><?= $only ? 'You are all caught up.' : 'No notifications yet. They appear when someone sends a form on the website.' ?></div><?php endif; ?>
  </div>
  <div class="card-footer"><?= pager($page, $pages, $total, fn($p) => 'notifications.php?' . http_build_query(array_filter(['show' => $only ? 'unread' : '', 'page' => $p])), 'notification') ?></div>
</div>
<?php page_foot();
