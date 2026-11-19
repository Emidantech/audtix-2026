<?php
declare(strict_types=1);
/* SaaS layer for the business portal: plans and limits, configurable roles, branches, audit trail, brand colours, impersonation state.
   Every function degrades safely when the new tables have not been created yet (before Admin > Modules database setup is run again). */

const BIZ_GRACE_DEFAULT = 7;
const BIZ_PERMS = [
    'docs.view' => 'View documents', 'docs.create' => 'Create and edit drafts', 'docs.post' => 'Post documents', 'docs.cancel' => 'Cancel documents',
    'customers.manage' => 'Manage customers', 'items.manage' => 'Manage products and services', 'reports.view' => 'View reports',
    'branches.manage' => 'Manage branches', 'team.manage' => 'Manage team and roles', 'settings.manage' => 'Edit business profile and branding',
    'billing.manage' => 'Manage subscription and payments', 'audit.view' => 'View activity log',
];
const BIZ_MODULES = ['docs' => 'Documents', 'customers' => 'Customers', 'items' => 'Products and services', 'branches' => 'Branches', 'reports' => 'Reports', 'team' => 'Team and roles'];
const BIZ_CATEGORIES = ['Transport and car hire', 'Retail and wholesale', 'Construction', 'Agriculture', 'Hospitality and food', 'Health', 'Education', 'Professional services', 'ICT and media', 'Manufacturing', 'Non-profit', 'Other'];

function biz_default_roles(): array {
    $all = array_keys(BIZ_PERMS);
    return [
        'Business Administrator' => [$all, 1],
        'Finance Manager' => [['docs.view', 'docs.create', 'docs.post', 'docs.cancel', 'customers.manage', 'reports.view', 'billing.manage', 'audit.view'], 1],
        'Accountant' => [['docs.view', 'docs.create', 'docs.post', 'customers.manage', 'reports.view'], 1],
        'Sales Manager' => [['docs.view', 'docs.create', 'docs.post', 'customers.manage', 'items.manage', 'reports.view'], 1],
        'Sales Representative' => [['docs.view', 'docs.create', 'customers.manage'], 0],
        'Inventory Manager' => [['docs.view', 'items.manage'], 1],
        'Branch Manager' => [['docs.view', 'docs.create', 'docs.post', 'docs.cancel', 'customers.manage', 'items.manage', 'reports.view'], 0],
        'Human Resource Manager' => [['team.manage', 'audit.view'], 1],
        'General Employee' => [['docs.view'], 0],
    ];
}
function biz_seed_roles(PDO $pdo, int $accId): void {
    $c = $pdo->prepare('SELECT COUNT(*) FROM biz_roles WHERE account_id = ?'); $c->execute([$accId]); if ((int) $c->fetchColumn()) return;
    $i = $pdo->prepare('INSERT INTO biz_roles (account_id, name, perms, all_branches) VALUES (?,?,?,?)');
    foreach (biz_default_roles() as $n => [$p, $ab]) $i->execute([$accId, $n, implode(',', $p), $ab]);
}
function biz_ensure_plans(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try { if ((int) $pdo->query('SELECT COUNT(*) FROM biz_plans')->fetchColumn()) return; } catch (Throwable $e) { return; }
    // Limits are a starting point to edit in Super Admin. Prices are left at 0 on purpose: set them in Super Admin > Plans.
    $rows = [['trial', 'Free / Trial', 14, 2, 1, 30, 100, 'docs,customers,items'], ['basic', 'Basic', 30, 3, 1, 150, 500, 'docs,customers,items,reports'], ['professional', 'Professional', 30, 10, 5, 1000, 2000, 'docs,customers,items,reports,branches,team'],
             ['premium', 'Premium', 30, 30, 20, 5000, 10000, 'docs,customers,items,reports,branches,team'], ['enterprise', 'Enterprise', 30, 0, 0, 0, 0, 'docs,customers,items,reports,branches,team']];
    $i = $pdo->prepare('INSERT INTO biz_plans (code, name, duration_days, max_users, max_branches, max_docs_month, storage_mb, modules, is_public, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?,1,1,?)');
    foreach ($rows as $n => $r) $i->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7], $n + 1]);
}
function biz_ext(int $accId, bool $fresh = false): array {
    static $c = []; if (!$fresh && isset($c[$accId])) return $c[$accId];
    try { $s = db()->prepare('SELECT * FROM biz_account_ext WHERE account_id = ?'); $s->execute([$accId]); $e = $s->fetch(); }
    catch (Throwable $x) { return $c[$accId] = ['approval' => 'approved']; }
    return $c[$accId] = $e ?: ['account_id' => $accId, 'approval' => 'approved'];
}
function biz_ext_save(int $accId, array $f): void {
    $pdo = db(); $x = $pdo->prepare('SELECT COUNT(*) FROM biz_account_ext WHERE account_id = ?'); $x->execute([$accId]);
    if (!(int) $x->fetchColumn()) $pdo->prepare('INSERT INTO biz_account_ext (account_id, approval) VALUES (?, ?)')->execute([$accId, 'approved']);
    foreach ($f as $k => $v) { if (!preg_match('/^[a-z_]+$/', $k)) continue; $pdo->prepare("UPDATE biz_account_ext SET $k = ? WHERE account_id = ?")->execute([$v, $accId]); }
    biz_ext($accId, true);
}
function biz_plan_row(?int $id): ?array {
    if (!$id) return null; try { $s = db()->prepare('SELECT * FROM biz_plans WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; } catch (Throwable $e) { return null; }
}
function biz_account_plan(array $a): ?array { return biz_plan_row((int) ($a['plan_id'] ?? 0)); }

/** Is this module included in the business's plan? No plan means everything is on. */
function biz_module_on(string $m): bool {
    $a = account(); $p = biz_account_plan($a); if (!$p || $p['modules'] === '') return true;
    return in_array($m, explode(',', (string) $p['modules']), true);
}
function biz_role(?array $u = null): ?array {
    $u ??= me(); if (!$u) return null; static $c = []; if (isset($c[$u['id']])) return $c[$u['id']];
    try { $s = db()->prepare('SELECT r.* FROM biz_user_ext x JOIN biz_roles r ON r.id = x.role_id WHERE x.user_id = ? AND r.account_id = ?'); $s->execute([$u['id'], $u['account_id']]); $r = $s->fetch() ?: null; } catch (Throwable $e) { $r = null; }
    return $c[$u['id']] = $r;
}
function bcan(string $perm): bool {
    $u = me(); if (!$u) return false; if ($u['role'] === 'owner') return true;
    $r = biz_role($u); if (!$r) return in_array($perm, ['docs.view', 'docs.create', 'docs.post', 'customers.manage', 'items.manage'], true);   // staff created before roles existed
    return in_array($perm, explode(',', (string) $r['perms']), true);
}
function biz_need(string $perm): void { if (!bcan($perm)) { flash('You do not have permission for that.', 'bad'); redirect('index.php'); } }
function biz_need_module(string $m): void { if (!biz_module_on($m)) { flash('That feature is not included in your plan. You can upgrade under Subscription.', 'bad'); redirect('billing.php'); } }
/** Branch the signed-in user is limited to, or null when they can work across all branches. */
function biz_branch(?array $u = null): ?int {
    $u ??= me(); if (!$u || $u['role'] === 'owner') return null; $r = biz_role($u); if ($r && (int) $r['all_branches']) return null;
    try { $s = db()->prepare('SELECT branch_id FROM biz_user_ext WHERE user_id = ?'); $s->execute([$u['id']]); $b = (int) $s->fetchColumn(); } catch (Throwable $e) { return null; }
    return $b ?: null;
}
/** SQL fragment limiting biz_documents rows (unaliased column id) to the user's branch. */
function biz_branch_sql(string $col = 'id'): array {
    $b = biz_branch(); if ($b === null) return ['', []];
    return [" AND $col IN (SELECT document_id FROM biz_doc_meta WHERE branch_id = ?)", [$b]];
}
function biz_branches(int $accId, bool $activeOnly = true): array {
    try { $s = db()->prepare('SELECT * FROM biz_branches WHERE account_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY name'); $s->execute([$accId]); return $s->fetchAll(); } catch (Throwable $e) { return []; }
}
function biz_audit(string $action, string $detail = '', ?int $accId = null): void {
    try {
        $u = defined('BIZ_PORTAL') ? me() : null; $accId ??= (int) ($u['account_id'] ?? 0); $imp = defined('BIZ_PORTAL') ? ($_SESSION['biz_imp'] ?? null) : null;
        db()->prepare('INSERT INTO biz_audit (account_id, user_id, admin_id, action, detail, ip, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$accId, $u['id'] ?? null, $imp['admin'] ?? null, $action, mb_substr($detail, 0, 500), client_ip(), gmdate('Y-m-d H:i:s')]);
    } catch (Throwable $e) {}
}
function biz_impersonating(): ?array { $i = $_SESSION['biz_imp'] ?? null; if (!$i) return null; if (time() > (int) $i['until']) { biz_imp_end(); return null; } return $i; }
function biz_imp_end(): void {
    $i = $_SESSION['biz_imp'] ?? null; if (!$i) return;
    try { db()->prepare('UPDATE biz_imp_log SET ended_at = ? WHERE id = ? AND ended_at IS NULL')->execute([gmdate('Y-m-d H:i:s'), (int) $i['log']]); } catch (Throwable $e) {}
    unset($_SESSION['biz_imp'], $_SESSION['biz_uid']);
}
/** Support sessions may look and work but cannot touch credentials, team or billing. */
function biz_no_imp(): void { if (biz_impersonating()) { flash('Not available during a support session.', 'bad'); redirect('index.php'); } }

/** Active limit for the account: plan limit, with the account-level monthly document limit taking priority when set. */
function biz_limit(array $a, string $k): int {
    if ($k === 'max_docs_month' && (int) $a['monthly_doc_limit'] > 0) return (int) $a['monthly_doc_limit'];
    $p = biz_account_plan($a); return $p ? (int) $p[$k] : 0;
}
function biz_theme_css(array $a): string {
    $ok = fn($c) => is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c); $css = '';
    if ($ok($a['primary_color'] ?? '')) $css .= '--pri:' . $a['primary_color'] . ';--pri-fg:' . (biz_lum($a['primary_color']) > 0.55 ? '#111' : '#fff') . ';';
    if ($ok($a['secondary_color'] ?? '')) $css .= '--sec:' . $a['secondary_color'] . ';';
    $t = (string) ($a['theme'] ?? '');
    if ($t === 'dark') $css .= '--bg:#0e131b;--card:#171e29;--fg:#e8edf5;--mut:#9aa6b8;--line:#2a3443;';
    elseif ($t === 'sand') $css .= '--bg:#f7f2ea;--card:#fffdf9;--line:#e8dfd0;';
    elseif ($t === 'forest') $css .= '--bg:#eef5f0;--card:#fbfefc;--line:#d6e6db;';
    return $css === '' ? '' : '<style>:root{' . $css . '}</style>';
}
function biz_lum(string $hex): float { $r = hexdec(substr($hex, 1, 2)) / 255; $g = hexdec(substr($hex, 3, 2)) / 255; $b = hexdec(substr($hex, 5, 2)) / 255; return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b; }
function biz_new_reference(): string { return 'SUB-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(4))); }
/** Extends the subscription from the later of today and the current paid_until. Called only after a payment is verified on the server. */
function biz_activate_plan(PDO $pdo, int $accId, array $plan): string {
    $s = $pdo->prepare('SELECT paid_until FROM biz_accounts WHERE id = ?'); $s->execute([$accId]); $cur = (string) $s->fetchColumn();
    $from = ($cur && $cur >= gmdate('Y-m-d')) ? $cur : gmdate('Y-m-d'); $to = gmdate('Y-m-d', strtotime($from . ' +' . max(1, (int) $plan['duration_days']) . ' days'));
    $pdo->prepare("UPDATE biz_accounts SET status = 'active', plan = ?, paid_until = ?, updated_at = ? WHERE id = ?")->execute([$plan['name'], $to, gmdate('Y-m-d H:i:s'), $accId]);
    biz_ext_save($accId, ['plan_id' => (int) $plan['id']]); return $to;
}

function doc_setting_global(string $k): string { $c = $GLOBALS['DOC_CO'] ?? null; unset($GLOBALS['DOC_CO']); $v = doc_setting($k, 'info@audtix.com'); if ($c !== null) $GLOBALS['DOC_CO'] = $c; return $v; }
function biz_save_logo(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, ''];
    if ($file['error'] !== UPLOAD_ERR_OK) return [null, 'The logo could not be uploaded.'];
    if ($file['size'] > 2 * 1024 * 1024) return [null, 'The logo must be under 2 MB.'];
    if (!function_exists('imagecreatefromstring')) return [null, 'Image support (GD) is not available on this server.'];
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) return [null, 'The logo must be a PNG, JPG, GIF or WebP image.'];
    if ($info[0] > 5000 || $info[1] > 5000 || $info[0] < 16 || $info[1] < 16) return [null, 'The logo size is not supported (16 to 5000 pixels).'];
    $im = @imagecreatefromstring((string) file_get_contents($file['tmp_name'])); if (!$im) return [null, 'The image could not be read.'];
    $w = imagesx($im); $h = imagesy($im); $max = 600;
    if ($w > $max) { $nh = (int) round($h * $max / $w); $n = imagecreatetruecolor($max, $nh); imagealphablending($n, false); imagesavealpha($n, true); imagefill($n, 0, 0, imagecolorallocatealpha($n, 255, 255, 255, 127)); imagecopyresampled($n, $im, 0, 0, 0, 0, $max, $nh, $w, $h); imagedestroy($im); $im = $n; }
    else { imagealphablending($im, false); imagesavealpha($im, true); }
    $d = storage_dir() . '/biz-logos'; if (!is_dir($d)) @mkdir($d, 0750, true);
    $name = bin2hex(random_bytes(12)) . '.png'; $ok = @imagepng($im, $d . '/' . $name, 6); imagedestroy($im);
    return $ok ? [$name, ''] : [null, 'The logo could not be stored.'];
}

function biz_platform(string $k, string $d = ''): string { $c = $GLOBALS['DOC_CO'] ?? null; unset($GLOBALS['DOC_CO']); $v = doc_setting($k, $d); if ($c !== null) $GLOBALS['DOC_CO'] = $c; return $v; }

/** Creates a business workspace: account, owner login, branding/profile extras, default roles. $f keys: business, name, email, phone, address, web, tin, category, facebook..tiktok, logo, currency, plan_id, trial_days, approval, must_change_pw. */
function biz_create_account(PDO $pdo, array $f, string $password): array {
    $now = gmdate('Y-m-d H:i:s'); biz_ensure_plans($pdo);
    $days = isset($f['trial_days']) ? (int) $f['trial_days'] : (int) (biz_platform('biz_trial_days', (string) BIZ_TRIAL_DAYS)); $days = max(0, min(365, $days));
    $pdo->prepare("INSERT INTO biz_accounts (name, owner_name, email, phone, address, web, tin, logo, currency, plan, status, trial_ends, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?, 'Trial', 'trial', ?, ?, ?)")
        ->execute([$f['business'], $f['name'], $f['email'], $f['phone'], $f['address'] ?? null, $f['web'] ?? null, $f['tin'] ?? null, $f['logo'] ?? null, $f['currency'] ?? 'UGX', gmdate('Y-m-d', time() + $days * 86400), $now, $now]);
    $aid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO biz_users (account_id, full_name, email, password_hash, role, must_change_pw, created_at) VALUES (?,?,?,?, 'owner', ?, ?)")->execute([$aid, $f['name'], $f['email'], make_hash($password), (int) ($f['must_change_pw'] ?? 0), $now]);
    $uid = (int) $pdo->lastInsertId();
    $plan = null; if (!empty($f['plan_id'])) $plan = biz_plan_row((int) $f['plan_id']); if (!$plan) { $s = $pdo->query("SELECT * FROM biz_plans WHERE code = 'trial'"); $plan = $s ? ($s->fetch() ?: null) : null; }
    biz_ext_save($aid, ['category' => $f['category'] ?? null, 'facebook' => $f['facebook'] ?? null, 'instagram' => $f['instagram'] ?? null, 'linkedin' => $f['linkedin'] ?? null, 'x_url' => $f['x_url'] ?? null, 'tiktok' => $f['tiktok'] ?? null,
        'plan_id' => $plan ? (int) $plan['id'] : null, 'approval' => $f['approval'] ?? 'approved']);
    biz_seed_roles($pdo, $aid); biz_audit('account_created', $f['business'], $aid);
    return [$aid, $uid];
}
function biz_social_url(string $v): string { $v = trim($v); if ($v === '') return ''; if (!preg_match('#^https?://#i', $v)) $v = 'https://' . $v; return filter_var($v, FILTER_VALIDATE_URL) ? mb_substr($v, 0, 255) : ''; }

function biz_login_log(?int $accId, ?int $uid, string $email, bool $ok): void {
    try { db()->prepare('INSERT INTO biz_login_log (account_id, user_id, email, ok, ip, created_at) VALUES (?,?,?,?,?,?)')->execute([$accId, $uid, mb_substr($email, 0, 190), $ok ? 1 : 0, client_ip(), gmdate('Y-m-d H:i:s')]); } catch (Throwable $e) {}
}

/** First load after the SaaS tables exist: default plans, default roles, and the trial plan for trial accounts. Cheap and idempotent. */
function biz_bootstrap(array $a): void {
    static $done = []; $id = (int) $a['id']; if (isset($done[$id])) return; $done[$id] = true;
    try {
        $pdo = db(); biz_ensure_plans($pdo); biz_seed_roles($pdo, $id); $e = biz_ext($id);
        if (empty($e['plan_id']) && $a['status'] === 'trial') { $p = $pdo->query("SELECT id FROM biz_plans WHERE code = 'trial'")->fetchColumn(); if ($p) { biz_ext_save($id, ['plan_id' => (int) $p]); biz_ext($id, true); } }
    } catch (Throwable $x) {}
}

/** Email to a business user. send_mail() always goes to Audtix's own notification address, so customer emails must use send_mail_to(). */
function biz_mail_to(string $to, string $subject, string $text): void { try { send_mail_to([$to], [], $subject, $text, '<p>' . nl2br(h($text)) . '</p>'); } catch (Throwable $e) {} }
/** Email to Audtix staff about a business (sign-up, support request). */
function biz_mail_staff(string $subject, string $text, string $replyTo = ''): void { try { send_mail($subject, $text, '<p>' . nl2br(h($text)) . '</p>', [], $replyTo); } catch (Throwable $e) {} }
