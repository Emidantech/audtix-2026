<?php
require __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/docs.php';
$admin = require_login(); $pdo = db();
$canView = can('doc.view', $admin) || can('customer.manage', $admin); $canManage = can('customer.manage', $admin);
if (!$canView) require_perm('doc.view');
$errors = []; $in = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) require_perm('customer.manage');
    $id = (int) ($_POST['id'] ?? 0); $act = $_POST['action'] ?? 'save';
    if ($act === 'toggle' && $id) {
        $pdo->prepare('UPDATE customers SET is_active = 1 - is_active, updated_at = ? WHERE id = ?')->execute([now(), $id]);
        log_activity((int) $admin['id'], 'customer_toggle', (string) $id); flash('Customer updated.'); redirect('customers.php?id=' . $id);
    }
    $in = ['name' => t($_POST, 'name', 190), 'contact_person' => t($_POST, 'contact_person', 190), 'tin' => preg_replace('/\s+/', '', t($_POST, 'tin', 20)), 'address' => t($_POST, 'address', 500), 'phone' => t($_POST, 'phone', 60), 'email' => strtolower(t($_POST, 'email', 190)), 'notes' => t($_POST, 'notes', 1000)];
    if (mb_strlen($in['name']) < 2) $errors['name'] = 'Name is required.';
    if ($in['tin'] !== '' && !preg_match('/^\d{10}$/', $in['tin'])) $errors['tin'] = 'A TIN has 10 digits.';
    if ($in['email'] !== '' && !filter_var($in['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if ($in['phone'] !== '' && !preg_match('/^[+\d\s().-]{5,60}$/', $in['phone'])) $errors['phone'] = 'Enter a valid phone number.';
    if ($in['tin'] !== '') { $d = $pdo->prepare('SELECT id, name FROM customers WHERE tin = ? AND id <> ?'); $d->execute([$in['tin'], $id]); if ($o = $d->fetch()) $errors['tin'] = 'Customer "' . $o['name'] . '" already uses this TIN.'; }
    [$logo, $le] = save_logo($_FILES['logo'] ?? ['error' => UPLOAD_ERR_NO_FILE]); if ($le) $errors['logo'] = $le;
    if (!$errors) {
        if ($id) {
            $pdo->prepare('UPDATE customers SET name=?, contact_person=?, tin=?, address=?, phone=?, email=?, notes=?, updated_at=? WHERE id=?')->execute([$in['name'], $in['contact_person'] ?: null, $in['tin'] ?: null, $in['address'] ?: null, $in['phone'] ?: null, $in['email'] ?: null, $in['notes'] ?: null, now(), $id]);
            if ($logo) $pdo->prepare('UPDATE customers SET logo_file = ? WHERE id = ?')->execute([$logo, $id]);
            elseif (!empty($_POST['remove_logo'])) $pdo->prepare('UPDATE customers SET logo_file = NULL WHERE id = ?')->execute([$id]);
            log_activity((int) $admin['id'], 'customer_updated', (string) $id, $in['name']); flash('Customer saved.');
        } else {
            $pdo->prepare('INSERT INTO customers (name, contact_person, tin, address, phone, email, logo_file, notes, is_active, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,1,?,?,?)')->execute([$in['name'], $in['contact_person'] ?: null, $in['tin'] ?: null, $in['address'] ?: null, $in['phone'] ?: null, $in['email'] ?: null, $logo, $in['notes'] ?: null, $admin['id'], now(), now()]);
            $id = (int) $pdo->lastInsertId(); log_activity((int) $admin['id'], 'customer_created', (string) $id, $in['name']); flash('Customer registered.');
        }
        redirect('customers.php?id=' . $id . (isset($_GET['for']) ? '' : ''));
    }
    $_GET['edit'] = $id ?: 'new';
}

$edit = $_GET['edit'] ?? null; $viewId = (int) ($_GET['id'] ?? 0);
if ($edit !== null && $canManage) {
    $c = ['id' => 0, 'name' => '', 'contact_person' => '', 'tin' => '', 'address' => '', 'phone' => '', 'email' => '', 'notes' => '', 'logo_file' => null, 'is_active' => 1];
    if ($edit !== 'new') { $s = $pdo->prepare('SELECT * FROM customers WHERE id = ?'); $s->execute([(int) $edit]); $c = $s->fetch() ?: $c; }
    $c = array_merge($c, $in);
    page_head($c['id'] ? 'Edit customer' : 'New customer', $admin);
    ?>
<p><a href="customers.php<?= $c['id'] ? '?id=' . (int) $c['id'] : '' ?>">&larr; Back</a></p>
<h1><?= $c['id'] ? 'Edit customer' : 'Register a customer' ?></h1>
<div class="card"><form method="post" enctype="multipart/form-data" class="stack" style="max-width:720px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
  <label>Customer or company name *<input name="name" required maxlength="190" value="<?= h($c['name']) ?>"><?= isset($errors['name']) ? '<span class="err">' . h($errors['name']) . '</span>' : '' ?></label>
  <div class="cols2">
    <label>Contact person<input name="contact_person" maxlength="190" value="<?= h($c['contact_person']) ?>"></label>
    <label>TIN (10 digits)<input name="tin" inputmode="numeric" maxlength="14" value="<?= h($c['tin']) ?>"><?= isset($errors['tin']) ? '<span class="err">' . h($errors['tin']) . '</span>' : '' ?></label>
    <label>Phone<input name="phone" maxlength="60" value="<?= h($c['phone']) ?>"><?= isset($errors['phone']) ? '<span class="err">' . h($errors['phone']) . '</span>' : '' ?></label>
    <label>Email<input name="email" type="email" maxlength="190" value="<?= h($c['email']) ?>"><?= isset($errors['email']) ? '<span class="err">' . h($errors['email']) . '</span>' : '' ?></label>
  </div>
  <label>Address<textarea name="address" maxlength="500" style="min-height:70px"><?= h($c['address']) ?></textarea></label>
  <div><b>Logo</b> <span class="muted">(PNG, JPG, GIF or WebP, up to 2 MB; shown on this customer's documents)</span>
    <div class="row" style="margin-top:8px"><img class="thumb" data-logo-preview style="width:72px;height:72px" alt="" <?= logo_url($c['logo_file']) ? 'src="' . h(logo_url($c['logo_file'])) . '"' : 'hidden' ?>>
      <input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" data-logo-input>
      <?php if ($c['logo_file']): ?><label class="inline"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label><?php endif; ?></div>
    <?= isset($errors['logo']) ? '<span class="err">' . h($errors['logo']) . '</span>' : '' ?></div>
  <label>Internal notes<textarea name="notes" maxlength="1000" style="min-height:60px"><?= h($c['notes']) ?></textarea></label>
  <div class="row"><button class="btn">Save customer</button><a class="btn ghost" href="customers.php">Cancel</a></div>
</form></div>
<?php page_foot(); exit; }

if ($viewId) {
    $s = $pdo->prepare('SELECT * FROM customers WHERE id = ?'); $s->execute([$viewId]); $c = $s->fetch();
    if (!$c) { http_response_code(404); page_head('Not found', $admin); echo '<p>Customer not found. <a href="customers.php">Back</a></p>'; page_foot(); exit; }
    $docs = list_documents($pdo, ['customer_id' => $viewId], 1, 200)['rows'];
    $inv = []; $rec = []; foreach ($docs as $d) { if ($d['status'] !== 'posted') continue; $cu = $d['currency']; if ($d['doc_type'] === 'invoice') $inv[$cu] = ($inv[$cu] ?? 0) + db_cents($d['total']); if ($d['doc_type'] === 'receipt') $rec[$cu] = ($rec[$cu] ?? 0) + db_cents($d['total']); }
    $cur_sum = function (array $m) { if (!$m) return '0'; $o = []; foreach ($m as $cu => $v) $o[] = money($v, $cu) . ' ' . $cu; return implode(' / ', $o); };
    $outs = []; foreach (array_unique(array_merge(array_keys($inv), array_keys($rec))) as $cu) $outs[$cu] = ($inv[$cu] ?? 0) - ($rec[$cu] ?? 0);
    page_head($c['name'], $admin);
    ?>
<p><a href="customers.php">&larr; All customers</a></p>
<div class="hdr"><?php if (logo_url($c['logo_file'])): ?><img class="thumb" style="width:64px;height:64px" src="<?= h(logo_url($c['logo_file'])) ?>" alt=""><?php endif; ?><div><h1><?= h($c['name']) ?></h1><span class="pill <?= (int) $c['is_active'] ? 'good' : 'bad' ?>"><?= (int) $c['is_active'] ? 'Active' : 'Switched off' ?></span></div><span class="spacer"></span>
  <?php if ($canManage): ?><a class="btn ghost" href="?edit=<?= (int) $c['id'] ?>">Edit</a><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="action" value="toggle"><button class="btn ghost" data-confirm="<?= (int) $c['is_active'] ? 'Switch this customer off? Their old documents stay.' : 'Switch this customer back on?' ?>"><?= (int) $c['is_active'] ? 'Switch off' : 'Switch on' ?></button></form><?php endif; ?>
  <?php if (can('doc.create', $admin) && (int) $c['is_active']): ?><a class="btn" href="doc_edit.php?type=quotation&customer_id=<?= (int) $c['id'] ?>">New quotation</a><a class="btn" href="doc_edit.php?type=invoice&customer_id=<?= (int) $c['id'] ?>">New invoice</a><?php endif; ?></div>
<div class="stats"><div class="stat"><b style="font-size:20px"><?= h($cur_sum($inv)) ?></b><span>Invoiced (posted)</span></div><div class="stat"><b style="font-size:20px"><?= h($cur_sum($rec)) ?></b><span>Receipts</span></div><div class="stat"><b style="font-size:20px"><?= h($cur_sum($outs)) ?></b><span>Outstanding</span></div><div class="stat"><b><?= count($docs) ?></b><span>Documents</span></div></div>
<div class="cols2"><div class="card"><h2>Details</h2><dl class="rows">
  <?php foreach (['contact_person' => 'Contact', 'tin' => 'TIN', 'phone' => 'Phone', 'email' => 'Email', 'address' => 'Address', 'notes' => 'Notes'] as $k => $l) if ($c[$k]) echo '<div><dt>' . h($l) . '</dt><dd>' . h($c[$k]) . '</dd></div>'; ?></dl></div></div>
<div class="card tablewrap" style="padding:0"><table><thead><tr><th>Number</th><th>Type</th><th>Date</th><th>Status</th><th class="num">Total</th></tr></thead><tbody>
<?php foreach ($docs as $d): ?><tr><td class="mono"><a href="doc.php?id=<?= (int) $d['id'] ?>"><?= h($d['doc_no']) ?></a></td><td><?= h(DOC_TYPES[$d['doc_type']]['label']) ?></td><td><?= h(fmt_date($d['doc_date'])) ?></td><td><span class="pill <?= h($d['status']) ?>"><?= h($d['status']) ?></span></td><td class="num"><?= money(db_cents($d['total']), $d['currency']) ?> <?= h($d['currency']) ?></td></tr><?php endforeach; if (!$docs) echo '<tr><td colspan="5" class="muted" style="padding:22px">No documents yet.</td></tr>'; ?></tbody></table></div>
<?php page_foot(); exit; }

$q = trim((string) ($_GET['q'] ?? '')); $show = ($_GET['show'] ?? '') === 'off' ? 0 : 1; $page = max(1, (int) ($_GET['page'] ?? 1)); $per = 30;
$w = 'WHERE c.is_active = ?'; $a = [$show]; if ($q !== '') { $w .= ' AND (c.name LIKE ? OR c.tin LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.contact_person LIKE ?)'; $l = '%' . $q . '%'; array_push($a, $l, $l, $l, $l, $l); }
$cn = $pdo->prepare("SELECT COUNT(*) FROM customers c $w"); $cn->execute($a); $total = (int) $cn->fetchColumn(); $pages = max(1, (int) ceil($total / $per)); $page = min($page, $pages);
$rs = $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM documents d WHERE d.customer_id = c.id) AS docs FROM customers c $w ORDER BY c.name LIMIT $per OFFSET " . (($page - 1) * $per)); $rs->execute($a); $rows = $rs->fetchAll();
page_head('Customers', $admin);
?>
<div class="hdr"><h1>Customers</h1><span class="spacer"></span><?php if ($canManage): ?><a class="btn" href="?edit=new">Register a customer</a><?php endif; ?></div>
<p class="muted">Pick a customer once and their details and logo fill in on every document.</p>
<form class="filters" method="get"><label>Search<input name="q" value="<?= h($q) ?>" placeholder="Name, TIN, email, phone"></label><label>Show<select name="show"><option value="">Active</option><option value="off"<?= !$show ? ' selected' : '' ?>>Switched off</option></select></label><button class="btn">Filter</button></form>
<div class="card tablewrap" style="padding:0"><table><thead><tr><th></th><th>Customer</th><th>TIN</th><th>Contact</th><th class="num">Documents</th></tr></thead><tbody>
<?php foreach ($rows as $c): ?><tr><td><?= logo_url($c['logo_file']) ? '<img class="thumb" src="' . h(logo_url($c['logo_file'])) . '" alt="">' : '' ?></td><td><a href="?id=<?= (int) $c['id'] ?>"><b><?= h($c['name']) ?></b></a><br><span class="muted"><?= h($c['contact_person']) ?></span></td><td class="mono"><?= h($c['tin']) ?></td><td><?= h($c['phone']) ?><br><span class="muted"><?= h($c['email']) ?></span></td><td class="num"><?= (int) $c['docs'] ?></td></tr><?php endforeach; if (!$rows) echo '<tr><td colspan="5" class="muted" style="padding:24px">No customers found.</td></tr>'; ?></tbody></table></div>
<div class="pager"><?= $total ?> customer<?= $total === 1 ? '' : 's' ?> &middot; page <?= $page ?> of <?= $pages ?><?php if ($page > 1): ?> <a class="btn sm ghost" href="?q=<?= urlencode($q) ?>&page=<?= $page - 1 ?>">Previous</a><?php endif; ?><?php if ($page < $pages): ?> <a class="btn sm ghost" href="?q=<?= urlencode($q) ?>&page=<?= $page + 1 ?>">Next</a><?php endif; ?></div>
<?php page_foot();
