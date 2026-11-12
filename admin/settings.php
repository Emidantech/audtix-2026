<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
if (!(can('settings.view') || can('mail.view') || can('roles.view') || can('admins.view'))) require_perm('settings.view');
$m = mail_config(); $b = brand();
$cards = [
    ['settings.view', 'branding.php', 'palette', 'primary', 'Branding', 'Logo, favicon and brand colours used on the website and in this panel.'],
    ['mail.view', 'mail-settings.php', 'envelope-paper', 'success', 'Email settings', 'Mail server, notification emails and test email.'],
    ['roles.view', 'roles.php', 'shield-check', 'warning', 'Roles and permissions', 'Create roles and choose what each one can view, add, edit and delete.'],
    ['admins.view', 'admins.php', 'people', 'info', 'Admin users', 'Add people to the panel, give them a role, reset passwords and deactivate accounts.'],
    ['settings.edit', 'cache.php', 'arrow-clockwise', 'danger', 'Clear cache', 'Refresh branding, panel files and server cache after changes.'],
];
page_head('Business settings', $admin, 'settings', [['Business settings', null]]);
?>
<p class="text-secondary">Settings saved here are stored in the database and take effect straight away. No need to edit configuration files.</p>
<div class="row g-3 settings-cards">
<?php foreach ($cards as [$perm, $href, $icon, $color, $title, $desc]): if (!can($perm)) continue; ?>
  <div class="col-md-6">
    <a class="card h-100 text-decoration-none action-card" href="<?= h($href) ?>">
      <div class="card-body d-flex gap-3 align-items-start">
        <span class="action-icon text-bg-<?= $color ?>"><i class="bi bi-<?= $icon ?>"></i></span>
        <span class="d-block"><span class="d-block action-title"><?= h($title) ?></span><span class="d-block action-desc"><?= h($desc) ?></span></span>
        <i class="bi bi-chevron-right ms-auto text-secondary align-self-center"></i>
      </div>
    </a>
  </div>
<?php endforeach; ?>
</div>
<div class="row g-3 mt-1">
  <?php if (can('settings.view')): ?><div class="col-md-6"><div class="card"><div class="card-body d-flex align-items-center gap-3"><img src="<?= h(brand_logo_url()) ?>" alt="Current logo" height="36"><span class="swatch" style="background:<?= h($b['color']) ?>"></span><span class="swatch" style="background:<?= h($b['dark']) ?>"></span><span class="small text-secondary">Current branding</span></div></div></div><?php endif; ?>
  <?php if (can('mail.view')): ?><div class="col-md-6"><div class="card"><div class="card-body small"><i class="bi bi-envelope me-1"></i> Sending with <b><?= $m['method'] === 'smtp' ? 'SMTP (' . h($m['smtp_host']) . ')' : ($m['method'] === 'file' ? 'test file' : 'server mail') ?></b> from <b><?= h($m['mail_from']) ?></b>. Notifications go to <b><?= h($m['notify_to'] ?: 'nobody yet') ?></b>.</div></div></div><?php endif; ?>
</div>
<?php page_foot();
