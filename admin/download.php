<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
require_perm('requests.view');
$s = db()->prepare('SELECT f.*, r.request_id FROM request_files f JOIN requests r ON r.id = f.request_pk WHERE f.id = ?');
$s->execute([(int) ($_GET['id'] ?? 0)]); $f = $s->fetch();
if (!$f || !preg_match('/^[a-f0-9]{24}\.(pdf|jpg|png)$/', $f['stored_name']) || !preg_match('/^[A-Z]+-\d{4}-[A-Z0-9]+$/', $f['request_id'])) { http_response_code(404); exit('File not found.'); }
$path = storage_dir() . '/requests/' . $f['request_id'] . '/' . $f['stored_name'];
if (!is_file($path)) { http_response_code(404); exit('The file is missing from storage.'); }
log_activity((int) $admin['id'], 'file_download', $f['request_id'], $f['original_name']);
$name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $f['original_name']);
header('Content-Type: ' . $f['mime']); header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $name . '"'); header('X-Content-Type-Options: nosniff');
readfile($path);
