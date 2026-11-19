<?php
declare(strict_types=1);
/* Public endpoint for the website chat widget (chat.js). JSON in, JSON out.
   actions: send (a client message), contact (details form), history (restore after a page change).
   Replies come from Claude when "ai_api_key" is set in config, otherwise from the built-in answers (api/lib/chatkb.php).
   When a person is needed, the conversation goes to the Audtix WhatsApp and email and the chat is flagged in Admin > Website chat.
   Details the client shares are saved on the chat and registered as a customer. */
require __DIR__ . '/lib/common.php';
require __DIR__ . '/lib/chat.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
function out(int $code, array $b): void { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_UNICODE); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); out(405, ['ok' => false]); }
$raw = file_get_contents('php://input'); if ($raw === false || strlen($raw) > 20000) out(413, ['ok' => false, 'error' => 'too_large']);
$in = json_decode($raw, true); if (!is_array($in)) out(422, ['ok' => false, 'error' => 'bad_request']);
$action = (string) ($in['action'] ?? 'send'); $token = (string) ($in['token'] ?? '');
if (trim((string) ($in['website'] ?? '')) !== '') out(200, ['ok' => true, 'reply' => 'Thank you.']);          // honeypot

$message = '';
try {
    $pdo = db();
    if ($action === 'history') {
        $s = chat_session($pdo, $token, false); if (!$s) out(200, ['ok' => true, 'messages' => []]);
        out(200, ['ok' => true, 'status' => $s['status'], 'has_contact' => $s['name'] !== null && $s['name'] !== '', 'messages' => array_map(fn($m) => ['role' => $m['role'], 'body' => $m['body']], chat_messages($pdo, (int) $s['id']))]);
    }
    $ip = client_ip();
    $c = $pdo->prepare("SELECT COUNT(*) FROM submission_log WHERE ip = ? AND category = 'chat' AND created_at > ?"); $c->execute([$ip, gmdate('Y-m-d H:i:s', time() - 3600)]);
    if ((int) $c->fetchColumn() >= 60) out(429, ['ok' => false, 'error' => 'rate_limited', 'reply' => 'You are sending messages quickly. Please wait a little, or message us on WhatsApp +256 701 287 388.']);
    $pdo->prepare('INSERT INTO submission_log (ip, category, created_at) VALUES (?,?,?)')->execute([$ip, 'chat', now()]);

    $message = trim((string) ($in['message'] ?? '')); $message = mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $message), 0, 1000);
    if ($action === 'send' && $message === '') out(422, ['ok' => false, 'error' => 'empty']);
    $sess = chat_session($pdo, $token, true, ['page' => (string) ($in['page'] ?? '')]); if (!$sess) out(500, ['ok' => false, 'error' => 'server']);

    if ($action === 'contact') {
        $sess = chat_save_contact($pdo, $sess, $in);
        out(200, ['ok' => true, 'token' => $sess['token'], 'registered' => !empty($sess['customer_id'])]);
    }

    chat_add($pdo, (int) $sess['id'], 'user', $message);
    $sess = chat_save_contact($pdo, $sess, $in, $message);                                         // picks up a typed email or phone number as well
    $hist = chat_messages($pdo, (int) $sess['id'], 14);
    $botTurns = count(array_filter($hist, fn($m) => $m['role'] === 'bot'));
    $needs = false; $reply = null;
    if ($sess['status'] === 'handoff') {                                                           // a person already has this chat: keep the thread, do not spam WhatsApp
        $reply = 'Thank you. A colleague has this conversation and will reply on WhatsApp. If it is urgent, message us on +256 701 287 388.';
    } else {
        $l = ' ' . mb_strtolower($message) . ' '; $asked = false; foreach (CHAT_HANDOFF_WORDS as $w) if (str_contains($l, $w)) { $asked = true; break; }
        $ai = $asked ? null : chat_ai_reply($hist);
        if ($ai !== null) { $needs = str_contains($ai, '[[HANDOFF]]'); $reply = trim(str_replace('[[HANDOFF]]', '', $ai)); }
        else { [$reply, $needs] = chat_rule_reply($message, $botTurns); }
        if ($asked) $needs = true;
    }
    chat_add($pdo, (int) $sess['id'], 'bot', $reply);
    $res = ['ok' => true, 'token' => $sess['token'], 'reply' => $reply, 'handoff' => false, 'need_contact' => (trim((string) $sess['name']) === '' || (trim((string) $sess['phone']) === '' && trim((string) $sess['email']) === ''))];
    if ($needs && $sess['status'] !== 'handoff') {
        $h = chat_handoff($pdo, $sess, mb_substr($message, 0, 200)); $res['handoff'] = true; $res['wa_url'] = $h['wa_url'];
    }
    out(200, $res);
} catch (Throwable $e) {
    error_log('audtix chat: ' . $e->getMessage());
    [$r, $n] = $message !== '' ? chat_rule_reply($message, 0) : ['Sorry, I could not process that just now. Please message us on WhatsApp +256 701 287 388 or at info@audtix.com.', false];   // still answer when the database is unavailable
    out(200, ['ok' => true, 'reply' => $r, 'fallback' => true, 'handoff' => $n, 'wa_url' => 'https://wa.me/' . CHAT_WA . '?text=' . rawurlencode('Hello Audtix, I was chatting on your website. ' . mb_substr($message, 0, 300))]);
}
