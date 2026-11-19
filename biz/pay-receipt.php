<?php
require __DIR__ . '/inc/boot.php';
$u = biz_require(); biz_need('billing.manage'); $a = account(); $pdo = db();
$s = $pdo->prepare("SELECT p.*, pl.name plan_name, pl.duration_days FROM biz_payments p LEFT JOIN biz_plans pl ON pl.id = p.plan_id WHERE p.reference = ? AND p.account_id = ? AND p.status = 'succeeded'"); $s->execute([(string) ($_GET['ref'] ?? ''), $a['id']]); $p = $s->fetch();
if (!$p) { http_response_code(404); exit('Receipt not found.'); }
$co = []; foreach (['company_name' => 'Audtix Systems Ltd', 'company_address' => '', 'company_email' => '', 'company_phone' => ''] as $k => $d) $co[$k] = biz_platform($k, $d);
biz_head('Payment receipt ' . $p['reference'], 'bill'); ?>
<div class="card receipt"><h2><?= h($co['company_name']) ?></h2><p class="muted"><?= h($co['company_address']) ?> <?= h($co['company_email']) ?> <?= h($co['company_phone']) ?></p>
<h3>PAYMENT RECEIPT</h3><table class="kv"><tr><td>Receipt no.</td><td><b><?= h($p['reference']) ?></b></td></tr><tr><td>Received from</td><td><?= h($a['name']) ?></td></tr><tr><td>For</td><td><?= h((string) $p['plan_name']) ?> subscription (<?= (int) $p['duration_days'] ?> days)</td></tr>
<tr><td>Amount</td><td><b><?= h(number_format((float) $p['amount'], 2)) ?> <?= h($p['currency']) ?></b></td></tr><tr><td>Method</td><td><?= h($p['provider']) ?></td></tr><tr><td>Verified</td><td><?= h((string) $p['verified_at']) ?> UTC</td></tr></table>
<p class="muted">Thank you for your payment.</p><p class="noprint"><button class="btn sm" data-print>Print</button></p></div>
<?php biz_foot();
