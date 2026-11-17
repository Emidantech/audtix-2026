<?php
/* One-click database setup for the business documents module (super admin).
   Runs database/migrations/2026-11-16-business-documents.sql statement by statement. It only ADDS: tables that are
   missing, starting settings and permissions with INSERT IGNORE. Nothing existing is changed or removed
   (the migration's clean-up DELETE of the old combined "documents.*" permissions is skipped here). */
require __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/docs.php';
$admin = require_super(); $pdo = db();
const DOC_TABLES = ['app_settings', 'customers', 'items', 'doc_counters', 'documents', 'document_lines', 'document_events', 'document_revisions'];
function doc_tables_state(PDO $pdo): array {
    $o = [];
    foreach (DOC_TABLES as $t) { try { $pdo->query('SELECT 1 FROM ' . $t . ' LIMIT 1'); $o[$t] = true; } catch (Throwable $e) { $o[$t] = false; } }
    return $o;
}
$log = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $file = dirname(__DIR__) . '/database/migrations/2026-11-16-business-documents.sql';
    $sql = is_file($file) ? (string) file_get_contents($file) : '';
    if ($sql === '') $log[] = ['bad', 'The setup file database/migrations/2026-11-16-business-documents.sql was not found on the server. Upload it with the rest of the site.'];
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*(?:\r?\n|$)/', (string) $sql) as $st) {
        $st = trim($st); if ($st === '') continue;
        if (stripos($st, 'DELETE ') === 0) continue;                                   // never remove anything
        $label = preg_match('/^(CREATE TABLE IF NOT EXISTS\s+(\w+)|INSERT IGNORE INTO\s+(\w+))/i', $st, $m) ? trim(($m[2] ?? '') ?: ($m[3] ?? '')) : substr($st, 0, 20);
        try { $pdo->exec($st); }
        catch (Throwable $e) {
            if (stripos($st, 'CREATE TABLE') === 0 && stripos($st, 'FOREIGN KEY') !== false) {          // retry without the optional links between tables
                $keep = array_values(array_filter(explode("\n", $st), fn($l) => stripos($l, 'CONSTRAINT') === false));
                foreach ($keep as $i => $l) if (isset($keep[$i + 1]) && ltrim($keep[$i + 1])[0] === ')') $keep[$i] = rtrim(rtrim($l), ',');
                $plain = implode("\n", $keep);
                try { $pdo->exec($plain); $log[] = ['ok', $label . ' created (without foreign-key links)']; continue; } catch (Throwable $e2) { $e = $e2; }
            }
            $log[] = ['bad', $label . ': ' . $e->getMessage()]; continue;
        }
        if (stripos($st, 'CREATE TABLE') === 0) $log[] = ['ok', $label . ' is ready'];
    }
    log_activity((int) $admin['id'], 'documents_db_setup', '', count($log) . ' steps');
}
$state = doc_tables_state($pdo); $missing = array_keys(array_filter($state, fn($v) => !$v));
page_head('Documents database setup', $admin, 'doc-settings');
?>
<div class="card"><div class="card-body">
  <h5>Business documents: database check</h5>
  <ul class="list-unstyled mb-3"><?php foreach ($state as $t => $ok): ?><li><i class="bi bi-<?= $ok ? 'check-circle-fill text-success' : 'x-circle-fill text-danger' ?>"></i> <code><?= h($t) ?></code> <?= $ok ? 'ready' : 'missing' ?></li><?php endforeach; ?></ul>
  <?php foreach ($log as [$k, $m]): ?><div class="alert alert-<?= $k === 'ok' ? 'success' : 'danger' ?> py-2 mb-2"><?= h($m) ?></div><?php endforeach; ?>
  <?php if ($missing): ?>
    <p>Some tables for the business documents module are missing. This adds them. <b>Nothing you already have in the database is changed or deleted.</b></p>
    <form method="post"><?= csrf_field() ?><button class="btn btn-primary">Create the missing tables</button></form>
  <?php else: ?>
    <p class="mb-2">Everything is in place. <a href="doc-dashboard.php">Open Documents</a> &middot; <a href="doc-settings.php">Document settings</a></p>
  <?php endif; ?>
</div></div>
<?php page_foot();
