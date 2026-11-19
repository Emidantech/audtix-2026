<?php
declare(strict_types=1);
/* Website chatbot engine: sessions, replies (AI or built-in), hand-off to a person, and registering the client as a customer. */
require_once __DIR__ . '/chatkb.php';
require_once __DIR__ . '/customers.php';

function chat_session(PDO $pdo, string $token, bool $create, array $meta = []): ?array {
    if ($token !== '' && preg_match('/^[a-f0-9]{32}$/', $token)) { $s = $pdo->prepare('SELECT * FROM chat_sessions WHERE token = ?'); $s->execute([$token]); if ($r = $s->fetch()) return $r; }
    if (!$create) return null;
    $t = bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO chat_sessions (token, status, page_url, ip, created_at, updated_at) VALUES (?, 'open', ?, ?, ?, ?)")->execute([$t, mb_substr((string) ($meta['page'] ?? ''), 0, 255), client_ip(), now(), now()]);
    $s = $pdo->prepare('SELECT * FROM chat_sessions WHERE token = ?'); $s->execute([$t]); return $s->fetch() ?: null;
}
function chat_add(PDO $pdo, int $sid, string $role, string $body): void {
    $pdo->prepare('INSERT INTO chat_messages (session_id, role, body, created_at) VALUES (?,?,?,?)')->execute([$sid, $role, mb_substr($body, 0, 4000), now()]);
    $pdo->prepare('UPDATE chat_sessions SET updated_at = ? WHERE id = ?')->execute([now(), $sid]);
}
function chat_messages(PDO $pdo, int $sid, int $limit = 60): array {
    $s = $pdo->prepare('SELECT role, body, created_at FROM chat_messages WHERE session_id = ? ORDER BY id DESC LIMIT ' . $limit); $s->execute([$sid]); return array_reverse($s->fetchAll());
}

/** Picks up an email or phone number the client typed into the chat, and saves contact details; registers the customer once there is a name and a way to reach them. */
function chat_save_contact(PDO $pdo, array $sess, array $in, string $message = ''): array {
    $f = ['name' => trim((string) ($in['name'] ?? '')), 'email' => strtolower(trim((string) ($in['email'] ?? ''))), 'phone' => trim((string) ($in['phone'] ?? '')), 'company' => trim((string) ($in['company'] ?? ''))];
    if ($f['email'] === '' && preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $message, $m)) $f['email'] = strtolower($m[0]);
    if ($f['phone'] === '' && preg_match('/(?:\+?256|0)[\s-]?7\d{2}[\s-]?\d{3}[\s-]?\d{3}|\+\d[\d\s-]{8,14}\d/', $message, $m)) $f['phone'] = trim($m[0]);
    if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $f['email'] = '';
    $new = []; foreach (['name' => 190, 'email' => 190, 'phone' => 60, 'company' => 190] as $k => $max) { $v = mb_substr($f[$k], 0, $max); if ($v !== '' && trim((string) $sess[$k]) === '') $new[$k] = $v; }
    if ($new) { $pdo->prepare('UPDATE chat_sessions SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($new))) . ', updated_at = ? WHERE id = ?')->execute([...array_values($new), now(), $sess['id']]); $sess = array_merge($sess, $new); }
    if (empty($sess['customer_id']) && trim((string) $sess['name']) !== '' && (trim((string) $sess['email']) !== '' || trim((string) $sess['phone']) !== '')) {
        $cid = capture_customer($pdo, ['name' => $sess['company'] ?: $sess['name'], 'contact_person' => $sess['company'] ? $sess['name'] : '', 'phone' => $sess['phone'], 'email' => $sess['email'], 'note' => 'Registered from the website chat.']);
        if ($cid) { $pdo->prepare('UPDATE chat_sessions SET customer_id = ? WHERE id = ?')->execute([$cid, $sess['id']]); $sess['customer_id'] = $cid; }
    }
    return $sess;
}

function chat_http(string $url, array $headers, string $body, int $timeout = 25): array {
    $raw = false; $code = 0;
    if (function_exists('curl_init')) { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8]); $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); }
    else { $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true]]); $raw = @file_get_contents($url, false, $ctx); $code = (int) (preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m) ? $m[1] : 0); }
    return [$raw === false ? null : (string) $raw, $code];
}

/** Asks Claude for the next reply. Returns null when no key is set or the call fails (the built-in answers are used instead). */
function chat_ai_reply(array $history): ?string {
    $key = (string) cfg('ai_api_key', ''); if ($key === '') return null;
    $msgs = []; foreach ($history as $m) { $r = $m['role'] === 'user' ? 'user' : 'assistant'; if ($msgs && $msgs[count($msgs) - 1]['role'] === $r) $msgs[count($msgs) - 1]['content'] .= "\n" . $m['body']; else $msgs[] = ['role' => $r, 'content' => (string) $m['body']]; }
    while ($msgs && $msgs[0]['role'] !== 'user') array_shift($msgs); if (!$msgs) return null;
    [$raw, $code] = chat_http('https://api.anthropic.com/v1/messages', ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
        json_encode(['model' => (string) cfg('ai_model', 'claude-haiku-5-5'), 'max_tokens' => 500, 'system' => chat_system_prompt(), 'messages' => $msgs]), 25);
    if ($raw === null || $code < 200 || $code >= 300) { error_log('audtix chat ai: HTTP ' . $code . ' ' . substr((string) $raw, 0, 200)); return null; }
    $j = json_decode($raw, true); $t = ''; foreach (($j['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $t .= $b['text'];
    return trim($t) !== '' ? trim($t) : null;
}

/** Built-in answer. Returns [text, needsHuman]. */
function chat_rule_reply(string $message, int $botTurns): array {
    $l = ' ' . mb_strtolower($message) . ' ';
    foreach (CHAT_HANDOFF_WORDS as $w) if (str_contains($l, $w)) return ["Understood. I will pass this to a colleague together with our conversation so you do not have to repeat yourself. They will pick it up on WhatsApp shortly.", true];
    foreach (chat_rules() as [$keys, $ans]) foreach ($keys as $k) if (str_contains($l, $k)) return [$ans, false];
    return $botTurns >= 2 ? ["I want to get this right, so I will bring in a colleague who can help properly. They will see our conversation on WhatsApp.", true]
                          : ["Thanks for your message. Could you tell me a little more, for example whether it is about tax, audit, an ERP or software system, cyber security, networking or ICT hardware?", false];
}

function chat_transcript(PDO $pdo, array $sess): string {
    $o = "Audtix website chat #" . $sess['id'] . "\nClient: " . ($sess['name'] ?: 'not given') . ($sess['company'] ? ' (' . $sess['company'] . ')' : '') . "\nPhone: " . ($sess['phone'] ?: '-') . "   Email: " . ($sess['email'] ?: '-') . "\nPage: " . ($sess['page_url'] ?: '-') . "\n\n";
    foreach (chat_messages($pdo, (int) $sess['id'], 80) as $m) $o .= ($m['role'] === 'user' ? 'Client' : 'Bot') . ': ' . $m['body'] . "\n";
    return trim($o);
}

/** Sends the conversation to the Audtix WhatsApp (Cloud API when configured), emails it, and marks the chat for a person. Returns the wa.me link the client can use too. */
function chat_handoff(PDO $pdo, array $sess, string $reason): array {
    $pdo->prepare("UPDATE chat_sessions SET status = 'handoff', handoff_reason = ?, handoff_at = ?, updated_at = ? WHERE id = ?")->execute([mb_substr($reason, 0, 255), now(), now(), $sess['id']]);
    $sess['status'] = 'handoff'; $text = chat_transcript($pdo, $sess); $sent = ['whatsapp' => false, 'email' => false];
    $tok = (string) cfg('whatsapp_token', ''); $pid = (string) cfg('whatsapp_phone_id', ''); $to = preg_replace('/\D/', '', (string) cfg('whatsapp_to', CHAT_WA));
    if ($tok !== '' && $pid !== '' && $to !== '') {
        [$raw, $code] = chat_http('https://graph.facebook.com/v20.0/' . rawurlencode($pid) . '/messages', ['Content-Type: application/json', 'Authorization: Bearer ' . $tok],
            json_encode(['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => false, 'body' => mb_substr("Needs a person: " . $reason . "\n\n" . $text, 0, 3900)]]), 20);
        $sent['whatsapp'] = $code >= 200 && $code < 300; if (!$sent['whatsapp']) error_log('audtix chat whatsapp: HTTP ' . $code . ' ' . substr((string) $raw, 0, 200));
    }
    try { $r = send_mail('Chat needs a person: ' . ($sess['name'] ?: 'website visitor'), "Reason: $reason\n\n$text\n\nOpen in admin: " . chat_site() . '/admin/chats.php?id=' . $sess['id'], '<pre style="font:14px/1.5 system-ui">' . h("Reason: $reason\n\n$text") . '</pre>'); $sent['email'] = !empty($r[0]); } catch (Throwable $e) {}
    $lines = []; foreach (array_slice(chat_messages($pdo, (int) $sess['id'], 12), -8) as $m) $lines[] = ($m['role'] === 'user' ? 'Me' : 'Audtix bot') . ': ' . mb_substr($m['body'], 0, 160);
    $wa = 'https://wa.me/' . CHAT_WA . '?text=' . rawurlencode(mb_substr("Hello Audtix, I was chatting on your website (chat #{$sess['id']}).\n" . implode("\n", $lines), 0, 900));
    return ['sent' => $sent, 'wa_url' => $wa];
}
