<?php
/* Support access started from Audtix Super Admin. One-time token (60 seconds) created by an authorised admin, bound to an audit record. No customer password is used or revealed. */
require __DIR__ . '/inc/boot.php';
$t = (string) ($_GET['t'] ?? ''); $pdo = db();
$s = $pdo->prepare('SELECT * FROM biz_imp_log WHERE token_hash = ? AND started_at IS NULL AND token_expires > ?'); $s->execute([hash('sha256', $t), gmdate('Y-m-d H:i:s')]); $l = $s->fetch();
if (!$t || !$l) { http_response_code(403); biz_head('Support access'); echo '<div class="card narrow"><div class="flash bad">This support link is invalid or has expired.</div></div>'; biz_foot(); exit; }
$pdo->prepare('UPDATE biz_imp_log SET started_at = ?, token_hash = NULL, ip = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), client_ip(), $l['id']]);
unset($_SESSION['biz_imp']); session_regenerate_id(true); $_SESSION['biz_uid'] = (int) $l['user_id']; $_SESSION['biz_seen'] = time();
$_SESSION['biz_imp'] = ['log' => (int) $l['id'], 'admin' => (int) $l['admin_id'], 'admin_name' => (string) $l['admin_name'], 'until' => time() + 1800];
biz_audit('support_session_started', 'Reason: ' . $l['reason'], (int) $l['account_id']); redirect('index.php');
