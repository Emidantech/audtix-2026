<?php
require __DIR__ . '/inc/boot.php';
$u = biz_require(); biz_need('billing.manage'); $a = account(); $pdo = db(); $aid = (int) $a['id']; biz_ensure_plans($pdo); $sub = $a['_sub']; $err = '';
$plans = $pdo->query("SELECT * FROM biz_plans WHERE is_active = 1 AND is_public = 1 AND code <> 'trial' ORDER BY sort_order")->fetchAll(); $cur = $a['plan_id'] ? biz_plan_row((int) $a['plan_id']) : null;
$prov = biz_providers(); $choose = (int) ($_GET['plan'] ?? 0);
$usage = function () use ($pdo, $aid) { $q = $pdo->prepare('SELECT COUNT(*) FROM biz_users WHERE account_id = ? AND is_active = 1'); $q->execute([$aid]); $q2 = $pdo->prepare('SELECT COUNT(*) FROM biz_branches WHERE account_id = ? AND is_active = 1'); $q2->execute([$aid]); return [(int) $q->fetchColumn(), (int) $q2->fetchColumn()]; };
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    biz_no_imp(); $pid = (int) ($_POST['plan_id'] ?? 0); $pv = (string) ($_POST['provider'] ?? ''); $ccy = ($_POST['currency'] ?? '') === 'USD' ? 'USD' : 'UGX'; $plan = null; foreach ($plans as $p) if ((int) $p['id'] === $pid) $plan = $p;
    [$nu, $nb] = $usage();
    if (!$plan) $err = 'Choose a package.';
    elseif (!isset($prov[$pv]) || !$prov[$pv][1]) $err = 'That payment method is not available yet.';
    elseif (biz_plan_price($plan, $ccy) <= 0) $err = 'This package has no online price in ' . $ccy . '. Contact us to subscribe.';
    elseif (($plan['max_users'] > 0 && $nu > $plan['max_users']) || ($plan['max_branches'] > 0 && $nb > $plan['max_branches'])) $err = 'You currently have more users or branches than this package allows. Deactivate some first to change to it.';
    else {
        $ref = biz_new_reference(); $amt = number_format(biz_plan_price($plan, $ccy), 2, '.', '');
        $pdo->prepare("INSERT INTO biz_payments (account_id, plan_id, reference, provider, amount, currency, status, created_at) VALUES (?,?,?,?,?,?, 'pending', ?)")->execute([$aid, $plan['id'], $ref, $pv, $amt, $ccy, gmdate('Y-m-d H:i:s')]);
        biz_audit('payment_started', "$ref $pv $amt $ccy");
        if ($pv === 'stripe') {
            $base = (is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            $r = biz_stripe_checkout(['reference' => $ref, 'currency' => $ccy, 'amount' => $amt], $plan, $a, "$base/billing.php?paid=$ref", "$base/billing.php");
            if ($r[0]) { header('Location: ' . $r[1]); exit; } $err = $r[1];
        } else { flash("Payment $ref created. Follow the instructions below, then it is confirmed by Audtix and your plan is activated."); redirect('billing.php?ref=' . $ref); }
    }
}
$hist = $pdo->prepare('SELECT p.*, pl.name plan_name FROM biz_payments p LEFT JOIN biz_plans pl ON pl.id = p.plan_id WHERE p.account_id = ? ORDER BY p.id DESC LIMIT 30'); $hist->execute([$aid]); $hist = $hist->fetchAll();
$show = null; if (!empty($_GET['ref'])) foreach ($hist as $h) if ($h['reference'] === $_GET['ref'] && $h['status'] === 'pending') $show = $h;
biz_head('Subscription', 'bill'); ?>
<?php if ($err): ?><div class="flash bad"><?= h($err) ?></div><?php endif; ?>
<?php if (!empty($_GET['paid'])): ?><div class="flash ok">Thank you. We are confirming your payment with the payment provider. Your plan activates as soon as it is verified, usually within a minute.</div><?php endif; ?>
<div class="card"><h2>Current plan</h2><p><span class="pill <?= $sub['ok'] ? 'posted' : 'cancelled' ?>"><?= h($sub['label']) ?></span> <b><?= h($cur['name'] ?? (string) $a['plan']) ?></b><?= !empty($sub['until']) ? ' · until ' . h(fmt_date($sub['until'])) : '' ?></p>
<?php if ($sub['note']): ?><p class="muted"><?= h($sub['note']) ?></p><?php endif; ?>
<?php if ($cur): ?><p class="muted"><?= (int) $cur['max_users'] ?: 'Unlimited' ?> users · <?= (int) $cur['max_branches'] ?: 'Unlimited' ?> branches · <?= (int) $cur['max_docs_month'] ?: 'Unlimited' ?> documents a month · Includes: <?= h(implode(', ', array_map(fn($m) => BIZ_MODULES[$m] ?? $m, array_filter(explode(',', (string) $cur['modules']))))) ?></p><?php endif; ?></div>
<?php if ($show): ?><div class="card"><h2>Pay <?= h(number_format((float) $show['amount'], 2)) ?> <?= h($show['currency']) ?> (<?= h($show['provider'] === 'mtn' ? 'MTN Mobile Money' : ($show['provider'] === 'airtel' ? 'Airtel Money' : 'Bank transfer')) ?>)</h2>
<p>Use this reference exactly: <b><?= h($show['reference']) ?></b></p><p><?= $show['provider'] === 'mtn' ? 'Send to MTN number <b>' . h(biz_platform('biz_momo_number')) . '</b>.' : ($show['provider'] === 'airtel' ? 'Send to Airtel number <b>' . h(biz_platform('biz_airtel_number')) . '</b>.' : 'Pay to: ' . nl2br(h(biz_platform('biz_bank_details')))) ?></p>
<p class="muted">After we confirm the payment your plan activates automatically and you get a receipt here.</p></div><?php endif; ?>
<?php if ($plans): ?><h2>Packages</h2><div class="plans"><?php foreach ($plans as $p): $isCur = $cur && (int) $cur['id'] === (int) $p['id']; ?>
<form method="post" class="plan card<?= $choose === (int) $p['id'] ? ' sel' : '' ?>"><?= csrf_field() ?><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>"><b><?= h($p['name']) ?><?= $isCur ? ' (current)' : '' ?></b>
<small><?= (float) $p['price_ugx'] > 0 ? h(number_format((float) $p['price_ugx'])) . ' UGX' : 'UGX price not set' ?> · <?= (float) $p['price_usd'] > 0 ? h(number_format((float) $p['price_usd'], 2)) . ' USD' : 'USD price not set' ?> / <?= (int) $p['duration_days'] ?> days</small>
<small><?= (int) $p['max_users'] ?: 'Unlimited' ?> users · <?= (int) $p['max_branches'] ?: 'Unlimited' ?> branches · <?= (int) $p['max_docs_month'] ?: 'Unlimited' ?> docs/month</small>
<?php if ($p['features']): ?><small><?= h($p['features']) ?></small><?php endif; ?>
<select name="currency"><option>UGX</option><option>USD</option></select>
<select name="provider"><?php foreach ($prov as $k => [$l, $on]): if ($on): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endif; endforeach; ?></select>
<button class="btn sm"><?= $isCur ? 'Renew' : 'Choose' ?></button></form><?php endforeach; ?></div>
<?php if (!array_filter($prov, fn($x) => $x[1])): ?><p class="muted">Online payment is not switched on yet. Contact Audtix Systems to subscribe.</p><?php endif; endif; ?>
<div class="card"><h2>Payment history</h2><div class="list"><?php foreach ($hist as $h): ?><div class="row"><div><b><?= h($h['reference']) ?></b><small><?= h((string) $h['plan_name']) ?> · <?= h($h['provider']) ?> · <?= h(substr($h['created_at'], 0, 10)) ?></small></div><div class="r"><b><?= h(number_format((float) $h['amount'], 2)) ?> <?= h($h['currency']) ?></b><span class="pill <?= $h['status'] === 'succeeded' ? 'posted' : ($h['status'] === 'failed' ? 'cancelled' : '') ?>"><?= h(ucfirst($h['status'])) ?></span><?php if ($h['status'] === 'succeeded'): ?><a class="btn sm ghost" href="pay-receipt.php?ref=<?= h($h['reference']) ?>" target="_blank">Receipt</a><?php endif; ?></div></div><?php endforeach; if (!$hist): ?><p class="muted">No payments yet.</p><?php endif; ?></div></div>
<div class="card"><h2>Get help</h2><p><a class="btn sm ghost" href="support.php">Contact support</a></p></div>
<?php biz_foot();
