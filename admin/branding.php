<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
require_perm('settings.view');
$me = (int) $admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    deny_unless('settings.edit', 'branding.php');
    $save = []; $errors = [];
    foreach (['brand_color' => 'Brand colour', 'brand_dark' => 'Dark colour'] as $k => $l) {
        $v = strtoupper(trim((string) ($_POST[$k] ?? '')));
        if (!valid_hex($v)) $errors[] = $l . ' must look like #0479EE.'; else $save[$k] = $v;
    }
    $files = ['brand_logo' => [['png', 'jpg', 'webp'], 'Logo'], 'brand_logo_white' => [['png', 'jpg', 'webp'], 'Logo for dark backgrounds'], 'brand_favicon' => [['png', 'ico'], 'Favicon']];
    foreach ($files as $k => [$kinds, $label]) {
        if (!empty($_POST['remove_' . $k])) { delete_upload(setting($k, '')); $save[$k] = ''; continue; }
        if (empty($_FILES[$k]) || $_FILES[$k]['error'] === UPLOAD_ERR_NO_FILE) continue;
        [$ok, $url, $info] = save_upload($_FILES[$k], 'brand', $kinds, 2 * 1024 * 1024);
        if (!$ok) { $errors[] = $label . ': ' . $url; continue; }
        if ($k === 'brand_favicon' && $info['w'] && ($info['w'] < 32 || $info['w'] !== $info['h'])) { delete_upload($url); $errors[] = 'Favicon: use a square image at least 32 x 32 pixels (512 x 512 is best).'; continue; }
        delete_upload(setting($k, '')); $save[$k] = $url;
    }
    if ($errors) { flash(implode(' ', $errors), 'bad'); if (!$save) redirect('branding.php'); }
    $save['brand_version'] = (string) time();
    settings_save($save, $me);
    log_activity($me, 'branding_saved', '', implode(',', array_keys($save)));
    if (!$errors) flash('Branding saved. The website and this panel use it straight away (visitors may need to refresh).');
    redirect('branding.php');
}

$b = brand(); $p = brand_palette();
$site = fn(?string $u, string $fallback) => $u ? '..' . $u . '?v=' . $b['v'] : $fallback;
page_head('Branding', $admin, 'branding', [['Business settings', 'settings.php'], ['Branding', null]]);
$ro = can('settings.edit') ? '' : ' disabled';
?>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card"><div class="card-header"><h3 class="card-title">Logos and favicon</h3></div><div class="card-body">
      <?php foreach ([['brand_logo', 'Logo', 'Shown in the website header and on the sign-in page. PNG, JPG or WebP with a transparent or white background, about 300 x 200 pixels.', $site($b['logo'], '../img/logo-blue.png'), false],
                      ['brand_logo_white', 'Logo for dark backgrounds', 'Shown in the website footer and the admin sidebar. Use a white or light version.', $site($b['logo_white'], '../img/logo-white.png'), true],
                      ['brand_favicon', 'Favicon', 'The small icon in the browser tab. Square PNG (512 x 512 is best) or ICO.', $site($b['favicon'], 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 16 16%22%3E%3Crect width=%2216%22 height=%2216%22 rx=%223%22 fill=%22%23ccd6e2%22/%3E%3C/svg%3E'), false]] as [$k, $label, $help, $src, $dark]): ?>
      <div class="row g-3 align-items-center mb-3 pb-3 border-bottom">
        <div class="col-sm-4"><div class="brand-preview<?= $dark ? ' dark' : '' ?>"><img src="<?= h($src) ?>" alt="<?= h($label) ?>" style="max-height:<?= $k === 'brand_favicon' ? '40' : '56' ?>px;max-width:100%"></div></div>
        <div class="col-sm-8"><label class="form-label fw-semibold" for="<?= $k ?>"><?= h($label) ?></label><input class="form-control" type="file" id="<?= $k ?>" name="<?= $k ?>" accept="<?= $k === 'brand_favicon' ? '.png,.ico,image/png,image/x-icon' : '.png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp' ?>"<?= $ro ?>><div class="form-text"><?= h($help) ?></div>
          <?php if (setting($k, '')): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="remove_<?= $k ?>" value="1" id="rm-<?= $k ?>"<?= $ro ?>><label class="form-check-label small" for="rm-<?= $k ?>">Remove and use the original</label></div><?php endif; ?></div>
      </div>
      <?php endforeach; ?>
    </div></div>
  </div>
  <div class="col-xl-5">
    <div class="card"><div class="card-header"><h3 class="card-title">Colours</h3></div><div class="card-body">
      <?php foreach ([['brand_color', 'Brand colour', 'Buttons, links, icons and highlights.', $b['color']], ['brand_dark', 'Dark colour', 'Footer, dark sections and the admin sidebar.', $b['dark']]] as [$k, $label, $help, $v]): ?>
      <div class="mb-3"><label class="form-label fw-semibold" for="<?= $k ?>"><?= h($label) ?></label>
        <div class="input-group"><input type="color" class="form-control form-control-color" value="<?= h($v) ?>" data-color-for="<?= $k ?>" aria-label="Pick <?= h(strtolower($label)) ?>"<?= $ro ?>><input class="form-control mono" id="<?= $k ?>" name="<?= $k ?>" value="<?= h($v) ?>" maxlength="7" pattern="#[0-9A-Fa-f]{6}" required<?= $ro ?>></div>
        <div class="form-text"><?= h($help) ?></div></div>
      <?php endforeach; ?>
      <div class="small text-secondary mb-1">Shades made from your brand colour</div>
      <div class="d-flex gap-2 flex-wrap"><?php foreach ($p as $var => $hex): ?><span class="text-center small"><span class="swatch d-block mx-auto mb-1" style="background:<?= h($hex) ?>"></span><span class="mono" style="font-size:10px"><?= h($hex) ?></span></span><?php endforeach; ?></div>
      <div class="form-text">Pick a brand colour dark enough for white text on it (the default #0479EE works well).</div>
    </div></div>
    <?php if (can('settings.edit')): ?><button class="btn btn-primary btn-lg w-100"><i class="bi bi-check2"></i> Save branding</button><?php endif; ?>
  </div>
</div>
</form>
<?php page_foot();
