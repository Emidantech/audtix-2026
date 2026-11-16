<?php
require __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/docs.php';
require_login();
$f = (string) ($_GET['f'] ?? '');
$path = logo_dir() . '/' . $f;
if (!preg_match('/^[a-f0-9]{24}\.png$/', $f) || !is_file($path)) { http_response_code(404); exit; }
header('Content-Type: image/png'); header('Content-Length: ' . filesize($path)); header('Cache-Control: private, max-age=86400'); header('X-Content-Type-Options: nosniff');
readfile($path);
