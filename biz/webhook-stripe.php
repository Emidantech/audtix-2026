<?php
/* Stripe webhook. Only a correctly signed event can activate a subscription. Set the endpoint to https://YOUR-DOMAIN/biz/webhook-stripe.php and store its signing secret as STRIPE_WEBHOOK_SECRET. */
declare(strict_types=1);
define('BIZ_NO_SESSION', 1);
require __DIR__ . '/inc/boot.php';
header('Content-Type: application/json');
$raw = (string) file_get_contents('php://input'); $secret = biz_pay_secret('STRIPE_WEBHOOK_SECRET');
if (!biz_stripe_sig_ok($raw, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), $secret, time())) { http_response_code(400); echo '{"ok":false}'; exit; }
$ev = json_decode($raw, true); $o = $ev['data']['object'] ?? [];
if (($ev['type'] ?? '') === 'checkout.session.completed' && ($o['payment_status'] ?? '') === 'paid') {
    $pdo = db(); $s = $pdo->prepare('SELECT * FROM biz_payments WHERE reference = ?'); $s->execute([(string) ($o['client_reference_id'] ?? '')]); $p = $s->fetch();
    if ($p && $p['provider'] === 'stripe' && (int) ($o['amount_total'] ?? -1) === (int) round(((float) $p['amount']) * 100) && strtoupper((string) ($o['currency'] ?? '')) === $p['currency']) biz_payment_succeed($pdo, $p, (string) ($o['payment_intent'] ?? $o['id'] ?? ''), 'Stripe webhook');
    elseif ($p) { $pdo->prepare("UPDATE biz_payments SET note = ? WHERE id = ? AND status <> 'succeeded'")->execute(['Webhook amount or currency did not match', $p['id']]); }
}
echo '{"ok":true}';
