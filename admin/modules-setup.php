<?php
/* One-click database setup for HR, payroll, finance, CRM, marketing, procurement, EFRIS and the website chat (super admin).
   Runs database/migrations/2026-11-18-modules-chatbot.sql statement by statement. It only ADDS missing tables. */
require __DIR__ . '/inc/boot.php';
$admin = require_super(); $pdo = db();
const MOD_TABLES = ['hr_employees', 'hr_leave', 'hr_payroll_runs', 'hr_payslips', 'fin_expenses', 'crm_deals', 'crm_activities', 'mkt_campaigns', 'proc_suppliers', 'proc_orders', 'efris_log', 'chat_sessions', 'chat_messages'];
function mod_tables_state(PDO $pdo): array { $o = []; foreach (MOD_TABLES as $t) { try { $pdo->query('SELECT 1 FROM ' . $t . ' LIMIT 1'); $o[$t] = true; } catch (Throwable $e) { $o[$t] = false; } } return $o; }
$log = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $file = dirname(__DIR__) . '/database/migrations/2026-11-18-modules-chatbot.sql';
    $sql = is_file($file) ? (string) file_get_contents($file) : '';
    if ($sql === '') $log[] = ['bad', 'The file database/migrations/2026-11-18-modules-chatbot.sql was not found on the server. Upload it with the rest of the site.'];
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*(?:\r?\n|$)/', (string) $sql) as $st) {
        $st = trim($st); if ($st === '' || stripos($st, 'DELETE ') === 0 || stripos($st, 'DROP ') === 0) continue;       // never remove anything
        $label = preg_match('/^CREATE TABLE IF NOT EXISTS\s+(\w+)/i', $st, $m) ? $m[1] : substr($st, 0, 20);
        try { $pdo->exec($st); if (stripos($st, 'CREATE TABLE') === 0) $log[] = ['ok', $label . ' is ready']; }
        catch (Throwable $e) { $log[] = ['bad', $label . ': ' . $e->getMessage()]; }
    }
    log_activity((int) $admin['id'], 'modules_db_setup', '', count($log) . ' steps');
}
$state = mod_tables_state($pdo); $missing = array_keys(array_filter($state, fn($v) => !$v));
page_head('Modules database setup', $admin, 'doc_settings');
?>
<div class="card"><div class="card-body">
  <h5>HR, Finance, CRM, Marketing, Procurement, EFRIS and Website chat: database check</h5>
  <ul class="list-unstyled mb-3"><?php foreach ($state as $t => $ok): ?><li><i class="bi bi-<?= $ok ? 'check-circle-fill text-success' : 'x-circle-fill text-danger' ?>"></i> <code><?= h($t) ?></code> <?= $ok ? 'ready' : 'missing' ?></li><?php endforeach; ?></ul>
  <?php foreach ($log as [$k, $m]): ?><div class="alert alert-<?= $k === 'ok' ? 'success' : 'danger' ?> py-2 mb-2"><?= h($m) ?></div><?php endforeach; ?>
  <?php if ($missing): ?>
    <p>Some tables are missing. This adds them. <b>Nothing you already have in the database is changed or deleted.</b></p>
    <form method="post"><?= csrf_field() ?><button class="btn btn-primary">Create the missing tables</button></form>
  <?php else: ?><p class="mb-0">Everything is in place.</p><?php endif; ?>
</div></div>
<?php page_foot();
