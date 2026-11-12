<?php
declare(strict_types=1);
/* Public branding for the website (read by brand.js): colours, logos, favicon. Set in Admin > Business settings > Branding. */
require __DIR__ . '/lib/common.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
try { $b = brand(); $p = brand_palette(); } catch (Throwable $e) { echo json_encode(['ok' => false]); exit; }
echo json_encode(['ok' => true, 'v' => $b['v'], 'vars' => $p, 'logo' => $b['logo'] ?: null, 'logo_white' => $b['logo_white'] ?: null, 'favicon' => $b['favicon'] ?: null], JSON_UNESCAPED_SLASHES);
