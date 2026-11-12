<?php
require __DIR__ . '/inc/boot.php';
$admin = require_super(); $pdo = db();
$tab = ($_GET['tab'] ?? 'logins') === 'activity' ? 'activity' : 'logins';
$only = ($_GET['only'] ?? '') === 'failed'; $page = max(1, (int) ($_GET['page'] ?? 1)); $per = 50; $off = ($page - 1) * $per;
page_head('Login log', $admin);
echo '<h1>Login log</h1><p class="muted">Every sign-in attempt and every admin action. Times are East Africa Time.</p><p><a class="btn sm' . ($tab === 'logins' ? '' : ' ghost') . '" href="?tab=logins">Sign-ins</a> <a class="btn sm' . ($tab === 'activity' ? '' : ' ghost') . '" href="?tab=activity">Admin activity</a>' . ($tab === 'logins' ? ' <a class="btn sm ghost" href="?tab=logins' . ($only ? '' : '&only=failed') . '">' . ($only ? 'Show all' : 'Only failed') . '</a>' : '') . '</p>';
if ($tab === 'logins') {
    $w = $only ? 'WHERE l.success = 0' : '';
    $rows = $pdo->query("SELECT l.*, a.full_name FROM admin_login_log l LEFT JOIN admins a ON a.id = l.admin_id $w ORDER BY l.id DESC LIMIT $per OFFSET $off")->fetchAll();
    echo '<div class="card tablewrap" style="padding:0"><table><thead><tr><th>When</th><th>Who</th><th>Result</th><th>IP</th><th>Browser</th></tr></thead><tbody>';
    foreach ($rows as $r) echo '<tr><td>' . h(eat($r['created_at'])) . '</td><td>' . h($r['full_name'] ?: $r['username_tried']) . '</td><td><span class="pill ' . ($r['success'] ? 'good' : 'bad') . '">' . ($r['success'] ? 'Signed in' : h(str_replace('_', ' ', $r['reason']))) . '</span></td><td class="mono">' . h($r['ip']) . '</td><td class="muted">' . h(mb_strimwidth((string) $r['user_agent'], 0, 70, '…')) . '</td></tr>';
} else {
    $rows = $pdo->query("SELECT l.*, a.full_name FROM admin_activity l LEFT JOIN admins a ON a.id = l.admin_id ORDER BY l.id DESC LIMIT $per OFFSET $off")->fetchAll();
    echo '<div class="card tablewrap" style="padding:0"><table><thead><tr><th>When</th><th>Admin</th><th>Action</th><th>Target</th><th>Detail</th><th>IP</th></tr></thead><tbody>';
    foreach ($rows as $r) echo '<tr><td>' . h(eat($r['created_at'])) . '</td><td>' . h($r['full_name'] ?: 'System') . '</td><td>' . h(str_replace('_', ' ', $r['action'])) . '</td><td class="mono">' . h($r['target']) . '</td><td>' . h($r['detail']) . '</td><td class="mono">' . h($r['ip']) . '</td></tr>';
}
if (!$rows) echo '<tr><td colspan="6" class="muted" style="padding:24px">Nothing here yet.</td></tr>';
echo '</tbody></table></div><div class="pager">';
$base = '?tab=' . $tab . ($only ? '&only=failed' : '');
if ($page > 1) echo '<a class="btn sm ghost" href="' . $base . '&page=' . ($page - 1) . '">Newer</a>';
if (count($rows) === $per) echo '<a class="btn sm ghost" href="' . $base . '&page=' . ($page + 1) . '">Older</a>';
echo '</div>'; page_foot();
