<?php
/* Renewal reminders. Run daily: from cron (php biz/cron-reminders.php) or by URL with ?key=<biz_cron_key platform setting>. Emails owners whose plan ends in 7, 3 or 1 days, or has just ended. */
require __DIR__ . '/inc/boot.php';
$cli = PHP_SAPI === 'cli'; $key = biz_platform('biz_cron_key');
if (!$cli && ($key === '' || !hash_equals($key, (string) ($_GET['key'] ?? '')))) { http_response_code(403); exit('Forbidden'); }
$pdo = db(); $sent = 0; $today = gmdate('Y-m-d');
foreach ($pdo->query("SELECT * FROM biz_accounts WHERE status <> 'suspended'")->fetchAll() as $a) {
    $end = max((string) $a['paid_until'], (string) $a['trial_ends']); if ($end === '' || ($a['status'] === 'active' && !$a['paid_until'])) continue;
    $days = (int) floor((strtotime($end) - strtotime($today)) / 86400); if (!in_array($days, [7, 3, 1, 0, -1], true) || !$a['email']) continue;
    $msg = $days >= 0 ? "Your {$a['plan']} subscription ends " . ($days === 0 ? 'today' : "in $days day(s) on $end") . '.' : "Your subscription ended on $end. Renew to keep creating documents. Nothing has been deleted.";
    biz_mail_to($a['email'], 'Subscription reminder: ' . $a['name'], $msg . "\n\nRenew any time from the Subscription page in your workspace."); $sent++;
}
echo "Reminders sent: $sent\n";
