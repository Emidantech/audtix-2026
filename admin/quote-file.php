<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
require_perm('quotes.view');
$s = db()->prepare('SELECT f.*, q.quote_no FROM quote_files f JOIN quotes q ON q.id = f.quote_pk WHERE f.id = ?');
$s->execute([(int) ($_GET['id'] ?? 0)]); $f = $s->fetch();
if (!$f || !preg_match('/^[a-f0-9]{24}\.(pdf|jpg|png)$/', $f['stored_name']) || !preg_match('/^QT-[A-Z]+-\d{4}-[A-Z0-9]+$/', $f['quote_no'])) { http_response_code(404); exit('File not found.'); }
$path = storage_dir() . '/quotes/' . $f['quote_no'] . '/' . $f['stored_name'];
if (!is_file($path)) { http_response_code(404); exit('The file is missing from storage.'); }
log_activity((int) $admin['id'], 'file_download', $f['quote_no'], $f['original_name']);
$name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $f['original_name']);
header('Content-Type: ' . $f['mime']); header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $name . '"'); header('X-Content-Type-Options: nosniff');
readfile($path);
