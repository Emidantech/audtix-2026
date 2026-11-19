<?php
require __DIR__ . '/inc/boot.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
check_csrf(); $i = $_SESSION['biz_imp'] ?? null; $u = me();
if ($i && $u) biz_audit('support_session_ended', '', (int) $u['account_id']);
biz_imp_end(); redirect('../admin/biz-accounts.php');
