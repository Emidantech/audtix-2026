<?php
declare(strict_types=1);
/* Subscription payments. A payment is only marked paid after the server has verified it: a signed Stripe webhook, or a Super Admin reconciling a mobile-money / bank payment.
   Secrets come from environment variables or from storage/payment-secrets.php (outside the web root); nothing is hard-coded. */

/** Names: STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET, MTN_MOMO_SUBSCRIPTION_KEY, MTN_MOMO_API_USER, MTN_MOMO_API_KEY, AIRTEL_CLIENT_ID, AIRTEL_CLIENT_SECRET. */
function biz_pay_secret(string $name): string {
    $v = getenv($name); if (is_string($v) && $v !== '') return $v;
    $f = storage_dir() . '/payment-secrets.php'; if (is_file($f)) { $a = include $f; if (is_array($a) && !empty($a[$name])) return (string) $a[$name]; }
    return '';
}
function biz_providers(): array {
    return ['stripe' => ['Card (Visa, Mastercard) via Stripe', biz_pay_secret('STRIPE_SECRET_KEY') !== '' && biz_pay_secret('STRIPE_WEBHOOK_SECRET') !== ''],
            'mtn' => ['MTN Mobile Money', biz_platform('biz_momo_number') !== ''], 'airtel' => ['Airtel Money', biz_platform('biz_airtel_number') !== ''], 'bank' => ['Bank transfer', biz_platform('biz_bank_details') !== '']];
}
function biz_plan_price(array $plan, string $cur): float { return (float) ($cur === 'USD' ? $plan['price_usd'] : $plan['price_ugx']); }
function biz_stripe_checkout(array $pay, array $plan, array $acct, string $success, string $cancel): array {
    $key = biz_pay_secret('STRIPE_SECRET_KEY'); if ($key === '') return [false, 'Card payments are not set up yet.'];
    $post = http_build_query(['mode' => 'payment', 'client_reference_id' => $pay['reference'], 'success_url' => $success, 'cancel_url' => $cancel, 'customer_email' => $acct['email'],
        'line_items[0][quantity]' => 1, 'line_items[0][price_data][currency]' => strtolower($pay['currency']), 'line_items[0][price_data][unit_amount]' => (int) round(((float) $pay['amount']) * 100), 'line_items[0][price_data][product_data][name]' => $plan['name'] . ' subscription'], '', '&');
    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions'); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERPWD => $key . ':']);
    $r = curl_exec($ch); curl_close($ch); $j = is_string($r) ? json_decode($r, true) : null;
    return !empty($j['url']) ? [true, (string) $j['url'], (string) ($j['id'] ?? '')] : [false, 'The payment page could not be opened. Try again or use another method.'];
}
/** Verifies the Stripe-Signature header (t=timestamp,v1=hmac) against the raw body. */
function biz_stripe_sig_ok(string $payload, string $header, string $secret, int $now, int $tolerance = 300): bool {
    $t = 0; $sigs = []; foreach (explode(',', $header) as $p) { [$k, $v] = array_pad(explode('=', trim($p), 2), 2, ''); if ($k === 't') $t = (int) $v; elseif ($k === 'v1') $sigs[] = $v; }
    if ($t <= 0 || abs($now - $t) > $tolerance || $secret === '') return false; $calc = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($sigs as $s) if (hash_equals($calc, $s)) return true; return false;
}
/** Marks a payment succeeded exactly once and extends the subscription. Returns true when this call did the activation. */
function biz_payment_succeed(PDO $pdo, array $pay, string $providerRef, string $note = ''): bool {
    $u = $pdo->prepare("UPDATE biz_payments SET status = 'succeeded', provider_ref = ?, note = ?, verified_at = ? WHERE id = ? AND status <> 'succeeded'");
    $u->execute([mb_substr($providerRef, 0, 120), mb_substr($note, 0, 300), gmdate('Y-m-d H:i:s'), $pay['id']]); if ($u->rowCount() < 1) return false;
    $plan = biz_plan_row((int) $pay['plan_id']); if (!$plan) return true; $to = biz_activate_plan($pdo, (int) $pay['account_id'], $plan);
    biz_audit('payment_verified', $pay['reference'] . ' ' . $pay['provider'] . ' until ' . $to, (int) $pay['account_id']);
    try { $a = $pdo->prepare("SELECT email FROM biz_users WHERE account_id = ? AND role = 'owner' AND is_active = 1 ORDER BY id LIMIT 1"); $a->execute([$pay['account_id']]); $a = $a->fetch(); if ($a && $a['email']) biz_mail_to($a['email'], 'Payment received: ' . $plan['name'], "Thank you. We received your payment {$pay['reference']} for the {$plan['name']} plan. Your subscription is active until $to."); } catch (Throwable $e) {}
    return true;
}
