<?php
require __DIR__ . '/inc/boot.php';
$a = null; try { $s = db()->prepare('SELECT logo FROM biz_accounts WHERE id = ?'); $s->execute([(int) ($_GET['a'] ?? 0)]); $a = $s->fetchColumn(); } catch (Throwable $e) {}
$p = logo_file_path($a ?: null); header_remove('Cache-Control'); header('Cache-Control: private, max-age=3600'); header('Content-Type: image/png');
if ($p) { readfile($p); exit; }
echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');   // 1x1 transparent
