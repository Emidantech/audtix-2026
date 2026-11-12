<?php
declare(strict_types=1);
/* Business settings stored in the `settings` table (edited in Admin > Business settings).
   Database values win over the config file, so email can be set up without editing files. */

const SETTING_DEFAULTS = [
    'brand_color'      => '#0479EE',   // main brand colour (buttons, links, accents)
    'brand_dark'       => '#0A2A55',   // dark colour (footer, dark sections, admin sidebar)
    'brand_logo'       => '',          // /uploads/brand/... ; empty = img/logo-blue.png
    'brand_logo_white' => '',          // for dark backgrounds; empty = img/logo-white.png
    'brand_favicon'    => '',
    'brand_version'    => '1',
    'mail_method'      => '',          // smtp | mail | '' (use the config file)
    'notify_on_quote'  => '1',
    'notify_on_tax'    => '1',
    'notify_on_audit'  => '1',
];

function settings_all(bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $cache = SETTING_DEFAULTS;
    try { foreach (db()->query('SELECT k, v FROM settings') as $r) $cache[$r['k']] = (string) $r['v']; } catch (Throwable $e) {}
    return $cache;
}
function setting(string $k, $default = null) { $a = settings_all(); return array_key_exists($k, $a) && $a[$k] !== '' ? $a[$k] : ($default ?? (SETTING_DEFAULTS[$k] ?? null)); }

/** Saves several settings at once. Works on MySQL and SQLite. */
function settings_save(array $kv, ?int $by): void {
    $pdo = db(); $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM settings WHERE k = ?');
        $ins = $pdo->prepare('INSERT INTO settings (k, v, updated_by, updated_at) VALUES (?,?,?,?)');
        foreach ($kv as $k => $v) { $del->execute([$k]); $ins->execute([$k, (string) $v, $by, now()]); }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    settings_all(true);
}

/* Secrets (SMTP password) are encrypted with a key derived from the config file, so a database dump alone does not reveal them. */
function secret_key(): string { return hash('sha256', (string) (cfg('app_key') ?: (cfg('db_pass', '') . '|' . cfg('db_name', '') . '|' . cfg('db_dsn', '') . '|audtix-settings')), true); }
function secret_encrypt(string $plain): string {
    if ($plain === '') return '';
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc1:' . base64_encode($iv . $tag . $ct);
}
function secret_decrypt(string $stored): string {
    if (!str_starts_with($stored, 'enc1:')) return $stored;
    $raw = base64_decode(substr($stored, 5), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}

/** Email settings: database first, then the config file. */
function mail_config(): array {
    $s = settings_all(); $c = cfg();
    $method = $s['mail_method'] ?? '';
    // Once email is set up in the admin panel, those settings are used as they are (no mixing with the config file,
    // which could hold an old or sample SMTP password). Before that, the config file is used.
    $fromDb = $method !== '';
    $pick = fn(string $k, $d = '') => $fromDb ? (($s[$k] ?? '') !== '' ? $s[$k] : (in_array($k, ['smtp_host', 'smtp_user'], true) ? '' : ($c[$k] ?? $d))) : (($c[$k] ?? '') !== '' ? $c[$k] : $d);
    if ($method === '') $method = !empty($c['smtp_host']) ? 'smtp' : 'mail';
    if (($c['mail_transport'] ?? '') === 'file') $method = 'file';   // tests
    return [
        'method' => $method,
        'mail_from' => $pick('mail_from', 'no-reply@localhost'), 'mail_from_name' => $pick('mail_from_name', 'Audtix Website'),
        'smtp_host' => $pick('smtp_host'), 'smtp_port' => (int) $pick('smtp_port', 465), 'smtp_secure' => $pick('smtp_secure', 'ssl'),
        'smtp_user' => $pick('smtp_user'), 'smtp_pass' => $fromDb ? (($s['smtp_pass'] ?? '') !== '' ? secret_decrypt($s['smtp_pass']) : '') : (string) ($c['smtp_pass'] ?? ''),
        'pass_source' => $fromDb ? ((($s['smtp_pass'] ?? '') !== '') ? 'settings' : 'none') : ((string) ($c['smtp_pass'] ?? '') !== '' ? 'config' : 'none'),
        'from_db' => $fromDb,
        'notify_to' => $pick('notify_to'), 'notify_cc' => $pick('notify_cc'),
        'mail_file' => $c['mail_file'] ?? '',
    ];
}
function notify_enabled(string $event): bool { return setting('notify_on_' . $event, '1') === '1'; }

/** Branding for the website and admin. */
function brand(): array {
    return [
        'color' => setting('brand_color'), 'dark' => setting('brand_dark'),
        'logo' => setting('brand_logo', ''), 'logo_white' => setting('brand_logo_white', ''), 'favicon' => setting('brand_favicon', ''),
        'v' => setting('brand_version', '1'),
    ];
}

/* Colour helpers: build the light and dark shades the site uses from one brand colour. */
function hex_rgb(string $hex): array { $h = ltrim($hex, '#'); if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]; return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))]; }
function rgb_hex(array $c): string { return sprintf('#%02X%02X%02X', ...array_map(fn($v) => max(0, min(255, (int) round($v))), $c)); }
function mix(string $a, string $b, float $t): string { $x = hex_rgb($a); $y = hex_rgb($b); return rgb_hex([$x[0] + ($y[0] - $x[0]) * $t, $x[1] + ($y[1] - $x[1]) * $t, $x[2] + ($y[2] - $x[2]) * $t]); }
function valid_hex(string $c): bool { return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $c); }
function brand_palette(): array {
    $b = brand(); $p = valid_hex($b['color']) ? strtoupper($b['color']) : '#0479EE'; $d = valid_hex($b['dark']) ? strtoupper($b['dark']) : '#0A2A55';
    // Unchanged defaults keep the site's original hand-tuned shades.
    $out = $p === '#0479EE' ? ['--blue' => '#0479EE', '--blue-deep' => '#0366D0', '--blue-ink' => '#0360C2', '--blue-soft' => '#E6F1FE', '--blue-mid' => '#9CC9F8'] : null;
    $dk = $d === '#0A2A55' ? ['--navy' => '#0A2A55', '--navy-line' => '#1F4378', '--navy-muted' => '#A9C0E0'] : null;
    $gen = [
        '--blue' => $p, '--blue-deep' => mix($p, '#000000', .14), '--blue-ink' => mix($p, '#000000', .19), '--blue-soft' => mix($p, '#FFFFFF', .9), '--blue-mid' => mix($p, '#FFFFFF', .6),
        '--navy' => $d, '--navy-line' => mix($d, '#FFFFFF', .14), '--navy-muted' => mix($d, '#FFFFFF', .65),
    ];
    if ($out) foreach ($out as $k => $v) $gen[$k] = $v;
    if ($dk) foreach ($dk as $k => $v) $gen[$k] = $v;
    return $gen;
}
