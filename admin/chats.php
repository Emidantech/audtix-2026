<?php
/* Website chat conversations: see what the chatbot said, which chats are waiting for a person, and close them. */
require __DIR__ . '/inc/boot.php';
$admin = require_login(); $pdo = db(); require_perm('chat.view'); $self = 'chats.php'; $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
ob_start();
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    deny_unless('chat.edit', $self . '?id=' . $id); $st = (string) ($_POST['status'] ?? '');
    if (in_array($st, ['open', 'handoff', 'closed'], true)) { $pdo->prepare('UPDATE chat_sessions SET status = ?, updated_at = ? WHERE id = ?')->execute([$st, now(), $id]); log_activity((int) $admin['id'], 'chat_status', (string) $id, $st); flash('Updated.'); }
    redirect($self . '?id=' . $id);
  }
  page_head($id ? 'Chat' : 'Website chat', $admin, 'chat', $id ? [['Website chat', $self], ['Conversation', null]] : []);
  if ($id) {
    $s = $pdo->prepare('SELECT * FROM chat_sessions WHERE id = ?'); $s->execute([$id]); $c = $s->fetch(); if (!$c) { echo '<div class="alert alert-danger">Not found.</div>'; page_foot(); exit; }
    $m = $pdo->prepare('SELECT * FROM chat_messages WHERE session_id = ? ORDER BY id'); $m->execute([$id]); $msgs = $m->fetchAll();
    echo '<div class="card"><div class="card-body"><div class="d-flex flex-wrap gap-2"><div class="me-auto"><h5 class="mb-1">' . h($c['name'] ?: 'Website visitor') . ' ' . mod_badge($c['status'] === 'handoff' ? 'pending' : ($c['status'] === 'closed' ? 'completed' : 'active')) . ' <span class="small text-secondary">' . h($c['status'] === 'handoff' ? 'needs a person' : $c['status']) . '</span></h5><div class="text-secondary small">' . h(implode(' · ', array_filter([$c['company'], $c['phone'], $c['email']]))) . '</div>'
       . ($c['handoff_reason'] ? '<div class="small mt-1">Handed over because: <i>' . h($c['handoff_reason']) . '</i></div>' : '') . '<div class="small text-secondary">Started ' . h(eat($c['created_at'])) . ' on ' . h((string) $c['page_url']) . '</div></div><div class="d-flex gap-2 align-items-start flex-wrap">'
       . ($c['phone'] ? '<a class="btn btn-sm btn-success" target="_blank" rel="noopener" href="https://wa.me/' . h(preg_replace('/\D/', '', preg_replace('/^0/', '256', (string) $c['phone']))) . '"><i class="bi bi-whatsapp"></i> WhatsApp client</a>' : '')
       . ($c['customer_id'] ? '<a class="btn btn-sm btn-outline-primary" href="customers.php?q=' . urlencode((string) ($c['company'] ?: $c['name'])) . '"><i class="bi bi-person-check"></i> Customer</a>' : '');
    if (can('chat.edit')) echo '<form method="post" class="m-0 d-flex gap-1">' . csrf_field() . '<input type="hidden" name="id" value="' . $id . '">' . ($c['status'] !== 'closed' ? '<button class="btn btn-sm btn-outline-secondary" name="status" value="closed">Close</button>' : '<button class="btn btn-sm btn-outline-secondary" name="status" value="open">Reopen</button>') . '</form>';
    echo '</div></div></div></div><div class="card"><div class="card-body d-flex flex-column gap-2">';
    foreach ($msgs as $x) echo '<div class="' . ($x['role'] === 'user' ? 'align-self-end text-bg-primary' : 'align-self-start bg-body-secondary') . ' rounded p-2 px-3" style="max-width:85%;white-space:pre-wrap">' . h($x['body']) . '<div class="small opacity-75">' . ($x['role'] === 'user' ? 'Client' : 'Bot') . ' &middot; ' . h(eat($x['created_at'])) . '</div></div>';
    echo '</div></div>';
  } else {
    $f = (string) ($_GET['status'] ?? ''); $w = in_array($f, ['open', 'handoff', 'closed'], true) ? ' WHERE status = ' . $pdo->quote($f) : '';
    echo '<div class="mb-3 d-flex gap-2 flex-wrap">'; foreach (['' => 'All', 'handoff' => 'Needs a person', 'open' => 'Open', 'closed' => 'Closed'] as $k => $l) echo '<a class="btn btn-sm ' . ($f === $k ? 'btn-primary' : 'btn-outline-secondary') . '" href="' . $self . ($k ? '?status=' . $k : '') . '">' . h($l) . '</a>'; echo '</div>';
    $rows = $pdo->query("SELECT s.*, (SELECT COUNT(*) FROM chat_messages m WHERE m.session_id = s.id) n FROM chat_sessions s$w ORDER BY (s.status = 'handoff') DESC, s.updated_at DESC LIMIT 100")->fetchAll();
    echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Client</th><th>Status</th><th>Msgs</th><th>Last activity</th></tr></thead><tbody>';
    foreach ($rows as $r) echo '<tr><td><a class="fw-semibold" href="' . $self . '?id=' . (int) $r['id'] . '">' . h($r['name'] ?: 'Website visitor #' . $r['id']) . '</a><div class="small text-secondary">' . h(implode(' · ', array_filter([$r['company'], $r['phone'], $r['email']]))) . '</div></td><td>' . ($r['status'] === 'handoff' ? '<span class="badge text-bg-danger">Needs a person</span>' : '<span class="badge text-bg-secondary">' . h(ucfirst($r['status'])) . '</span>') . '</td><td>' . (int) $r['n'] . '</td><td>' . h(eat($r['updated_at'])) . '</td></tr>';
    if (!$rows) echo '<tr><td colspan="4" class="text-secondary">No conversations yet.</td></tr>';
    echo '</tbody></table></div></div>';
  }
} catch (Throwable $e) { ob_clean(); page_head('Website chat', $admin, 'chat'); mod_setup_notice($e); }
page_foot(); ob_end_flush();
