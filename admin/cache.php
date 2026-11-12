<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
require_perm('settings.edit');
$pdo = db(); $me = (int) $admin['id'];
$items = [
    'site'   => ['Website branding', 'globe', true],
    'admin'  => ['Admin panel styles and scripts', 'window', true],
    'server' => ['Server code cache (OPcache)', 'cpu', true],
    'limits' => ['Form rate limits', 'speedometer', true],
    'notes'  => ['Notifications older than 90 days', 'bell-slash', false],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pick = array_intersect(array_keys($items), (array) ($_POST['clear'] ?? []));
    $done = []; $save = []; $v = (string) time();
    if (in_array('site', $pick, true)) { $save['brand_version'] = $v; $done[] = $items['site'][0]; }
    if (in_array('admin', $pick, true)) { $save['asset_version'] = $v; $done[] = $items['admin'][0]; }
    if ($save) settings_save($save, $me);
    if (in_array('server', $pick, true)) { clearstatcache(true); if (function_exists('opcache_reset') && @opcache_reset()) $done[] = $items['server'][0]; else $done[] = 'File cache'; }
    if (in_array('limits', $pick, true)) { $pdo->exec('DELETE FROM submission_log'); $done[] = $items['limits'][0]; }
    if (in_array('notes', $pick, true)) { $pdo->prepare('DELETE FROM notifications WHERE created_at < ?')->execute([gmdate('Y-m-d H:i:s', time() - 90 * 86400)]); $done[] = $items['notes'][0]; }
    log_activity($me, 'cache_cleared', implode(', ', $pick));
    flash($done ? 'Cleared: ' . implode(', ', $done) . '.' : 'Nothing selected.', $done ? 'ok' : 'bad');
    redirect('cache.php');
}
page_head('Clear cache', $admin, 'settings', [['Business settings', 'settings.php'], ['Clear cache', null]]);
?>
<div class="row"><div class="col-lg-7">
<form method="post" class="card"><?= csrf_field() ?>
  <ul class="list-group list-group-flush">
  <?php foreach ($items as $k => [$label, $icon, $on]): ?>
    <li class="list-group-item"><div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" name="clear[]" value="<?= $k ?>" id="c-<?= $k ?>"<?= $on ? ' checked' : '' ?>><label class="form-check-label" for="c-<?= $k ?>"><i class="bi bi-<?= $icon ?> me-2 text-secondary"></i><?= h($label) ?></label></div></li>
  <?php endforeach; ?>
  </ul>
  <div class="card-footer"><button class="btn btn-danger"><i class="bi bi-arrow-clockwise"></i> Clear cache</button></div>
</form>
</div></div>
<?php page_foot();
