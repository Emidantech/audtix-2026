<?php
/* Roles and permissions. A super admin can do everything. Any other admin gets the permissions
   of their role (roles + role_permissions). Each page and button checks can('module.action'). */

const PERMISSIONS = [
    'dashboard'  => ['Dashboard', ['view' => 'View']],
    'quotes'     => ['Quotations', ['view' => 'View', 'add' => 'Add', 'edit' => 'Edit and change status', 'respond' => 'Send quotation to client', 'delete' => 'Delete']],
    'requests'   => ['Tax and audit requests', ['view' => 'View', 'edit' => 'Change status, assign, notes', 'delete' => 'Delete']],
    'posts'      => ['Media: posts', ['view' => 'View', 'add' => 'Add', 'edit' => 'Edit', 'publish' => 'Publish and unpublish', 'delete' => 'Delete']],
    'post_types' => ['Media: post types', ['view' => 'View', 'add' => 'Add', 'edit' => 'Edit', 'delete' => 'Delete']],
    'media'      => ['Media: image library', ['view' => 'View', 'add' => 'Upload', 'delete' => 'Delete']],
    'admins'     => ['Admin users', ['view' => 'View', 'add' => 'Add', 'edit' => 'Edit, reset password, activate', 'delete' => 'Delete']],
    'roles'      => ['Roles and permissions', ['view' => 'View', 'add' => 'Add', 'edit' => 'Edit', 'delete' => 'Delete']],
    'settings'   => ['Business settings: branding', ['view' => 'View', 'edit' => 'Edit']],
    'mail'       => ['Business settings: email and notifications', ['view' => 'View', 'edit' => 'Edit and send test email']],
    'log'        => ['Login log and activity', ['view' => 'View']],
];

function all_permissions(): array {
    $out = [];
    foreach (PERMISSIONS as $m => [, $acts]) foreach ($acts as $a => $_) $out[] = "$m.$a";
    return $out;
}

function is_super(?array $a = null): bool { $a = $a ?? me(); return $a && $a['role'] === 'super_admin'; }

/** Permission keys of the signed-in admin. */
function my_permissions(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $a = me();
    if (!$a) return $cache = [];
    if (is_super($a)) return $cache = all_permissions();
    if (empty($a['role_id'])) return $cache = [];
    try { $s = db()->prepare('SELECT permission FROM role_permissions WHERE role_id = ?'); $s->execute([(int) $a['role_id']]); $cache = $s->fetchAll(PDO::FETCH_COLUMN); }
    catch (Throwable $e) { $cache = []; }
    return $cache;
}
function can(string $perm): bool { return in_array($perm, my_permissions(), true); }
function can_any(string $module): bool { foreach (my_permissions() as $p) if (str_starts_with($p, $module . '.')) return true; return false; }

/** Stops the page with a friendly 403 if the admin lacks the permission. */
function require_perm(string $perm): void {
    if (can($perm)) return;
    http_response_code(403);
    $label = PERMISSIONS[explode('.', $perm)[0]][0] ?? $perm;
    page_head('No access', me(), '');
    echo '<div class="callout callout-danger"><h5><i class="bi bi-shield-lock"></i> You do not have access to this</h5><p class="mb-2">Your role does not include <b>' . h($label) . ': ' . h(PERMISSIONS[explode('.', $perm)[0]][1][explode('.', $perm)[1] ?? ''] ?? $perm) . '</b>.</p><p class="mb-0">Ask a super admin to add it to your role.</p></div>';
    page_foot(); exit;
}
/** For POST actions: flash an error and go back instead of a full page. */
function deny_unless(string $perm, string $back): void {
    if (can($perm)) return;
    flash('Your role does not allow this action.', 'bad'); redirect($back);
}
function role_name(?array $a): string {
    if (!$a) return '';
    if (is_super($a)) return 'Super admin';
    if (empty($a['role_id'])) return 'No role';
    static $names = null;
    if ($names === null) { $names = []; try { foreach (db()->query('SELECT id, name FROM roles') as $r) $names[(int) $r['id']] = $r['name']; } catch (Throwable $e) {} }
    return $names[(int) $a['role_id']] ?? 'No role';
}
