<?php
/* Create a quotation (e.g. a phone or walk-in enquiry) or edit an existing one.
   The fields come from /quote-forms.js, the same definition as the public form. */
require __DIR__ . '/inc/boot.php';
$admin = require_login();
$pdo = db(); $me = (int) $admin['id'];
require_perm(!empty($_GET['id']) || !empty($_POST['id']) ? 'quotes.edit' : 'quotes.add');
$services = quote_services();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$q = null;
if ($id) {
    $s = $pdo->prepare('SELECT * FROM quotes WHERE id = ?'); $s->execute([$id]); $q = $s->fetch();
    if (!$q) { http_response_code(404); page_head('Not found', $admin, 'quotes:all'); echo '<div class="alert alert-warning">Quotation not found.</div>'; page_foot(); exit; }
}
$service = $q ? $q['service'] : (string) ($_GET['service'] ?? $_POST['service'] ?? '');
if (!isset($services[$service])) $service = '';
$data = $q ? (json_decode((string) $q['data_json'], true) ?: []) : [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $service !== '') {
    $in = [];
    foreach (quote_sections($service) as $sec) foreach ($sec['fields'] as $f) {
        $n = $f['name'];
        if ($f['type'] === 'checkboxes') $in[$n] = (array) ($_POST[$n] ?? []);
        elseif ($f['type'] === 'items') $in[$n] = array_values((array) ($_POST[$n] ?? []));
        else $in[$n] = (string) ($_POST[$n] ?? '');
    }
    [$clean, $rows, $errors] = quote_validate($service, $in, false);
    if (($clean['email'] ?? '') === '' && ($clean['phone'] ?? '') === '' && !isset($errors['email'])) $errors['email'] = 'Enter an email or a phone number so the team can reply.';
    if (!$errors) {
        $title = quote_service_title($service);
        array_unshift($rows, ['Service', $title, 'Quotation']);
        $summary = quote_summary($service, $rows);
        $cols = [$clean['client_type'] ?: null, $clean['full_name'], ($clean['company'] ?? '') ?: null, $clean['email'] ?? '', $clean['phone'] ?? '', ($clean['location'] ?? '') ?: null,
                 $summary ?: null, ($clean['budget'] ?? '') ?: null, ($clean['needed_by'] ?? '') ?: null, json_encode($clean, JSON_UNESCAPED_UNICODE), json_encode($rows, JSON_UNESCAPED_UNICODE)];
        if ($q) {
            $old = json_decode((string) $q['rows_json'], true) ?: []; $o = []; $nw = [];
            foreach ($old as $r) $o[$r[0]] = $r[1]; foreach ($rows as $r) $nw[$r[0]] = $r[1];
            $changed = array_keys(array_diff_assoc($nw, $o) + array_diff_assoc($o, $nw));
            $pdo->prepare('UPDATE quotes SET client_type = ?, client_name = ?, company = ?, email = ?, phone = ?, location = ?, summary = ?, budget = ?, needed_by = ?, data_json = ?, rows_json = ?, updated_at = ? WHERE id = ?')
                ->execute(array_merge($cols, [now(), $id]));
            if ($changed) quote_event($pdo, $id, $me, 'edit', 'Edited: ' . mb_strimwidth(implode(', ', $changed), 0, 1900, '…'));
            log_activity($me, 'quote_edited', $q['quote_no']);
            flash($changed ? 'Changes saved.' : 'Nothing changed.');
            redirect('quote.php?id=' . $id);
        }
        $no = next_quote_no($pdo, $service, (int) gmdate('Y'));
        $pdo->prepare('INSERT INTO quotes (quote_no, service, status, source, client_type, client_name, company, email, phone, location, summary, budget, needed_by, data_json, rows_json, files_count, email_status, assigned_to, created_by, created_at, updated_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(array_merge([$no, $service, 'in_review', 'admin'], $cols, [0, 'pending', $me, $me, now(), now()]));
        $newId = (int) $pdo->lastInsertId();
        quote_event($pdo, $newId, $me, 'system', 'Entered by ' . $admin['full_name']);
        log_activity($me, 'quote_created', $no, $service);
        flash('Quotation ' . $no . ' created.');
        redirect('quote.php?id=' . $newId);
    }
    $data = $in;
}

function v(array $d, string $n): string { $x = $d[$n] ?? ''; return is_array($x) ? '' : (string) $x; }
function field(array $f, array $d, array $errors): string {
    $n = $f['name']; $id = 'e-' . $n; $err = $errors[$n] ?? ''; $inv = $err ? ' is-invalid' : '';
    $req = !empty($f['required']) ? ' <span class="text-danger" title="Required on the website form">*</span>' : '';
    $show = !empty($f['show']) ? ' data-show="' . h($f['show']) . '"' : '';
    $fb = $err ? '<div class="invalid-feedback d-block">' . h($err) . '</div>' : '';
    $col = !empty($f['half']) ? 'col-md-6' : 'col-12';
    switch ($f['type']) {
        case 'radio': case 'checkboxes':
            $multi = $f['type'] === 'checkboxes'; $cur = $multi ? (array) ($d[$n] ?? []) : v($d, $n);
            $o = '';
            foreach ($f['options'] as $i => $op) {
                $on = $multi ? in_array($op[0], $cur, true) : $cur === $op[0];
                $o .= '<div class="form-check form-check-inline"><input class="form-check-input" type="' . ($multi ? 'checkbox' : 'radio') . '" name="' . h($n) . ($multi ? '[]' : '') . '" id="' . $id . $i . '" value="' . h($op[0]) . '"' . ($on ? ' checked' : '') . '><label class="form-check-label" for="' . $id . $i . '">' . h($op[1]) . '</label></div>';
            }
            return '<div class="' . $col . '"' . $show . '><fieldset><legend class="form-label fs-6 mb-1">' . h($f['label']) . $req . '</legend>' . $o . $fb . '</fieldset></div>';
        case 'select':
            $o = '<option value="">Select</option>';
            foreach ($f['options'] as $op) $o .= '<option value="' . h($op[0]) . '"' . (v($d, $n) === $op[0] ? ' selected' : '') . '>' . h($op[1]) . '</option>';
            return '<div class="' . $col . '"' . $show . '><label class="form-label" for="' . $id . '">' . h($f['label']) . $req . '</label><select class="form-select' . $inv . '" id="' . $id . '" name="' . h($n) . '">' . $o . '</select>' . $fb . '</div>';
        case 'textarea':
            return '<div class="' . $col . '"' . $show . '><label class="form-label" for="' . $id . '">' . h($f['label']) . $req . '</label><textarea class="form-control' . $inv . '" id="' . $id . '" name="' . h($n) . '" rows="' . (int) ($f['rows'] ?? 3) . '" maxlength="' . (int) ($f['max'] ?? 3000) . '">' . h(v($d, $n)) . '</textarea>' . $fb . '</div>';
        case 'items':
            $rows = array_values((array) ($d[$n] ?? [])); if (!$rows) $rows = [[]];
            $th = ''; foreach ($f['columns'] as $c) $th .= '<th>' . h($c['label']) . '</th>';
            $tb = '';
            foreach ($rows as $i => $r) {
                $tb .= '<tr>';
                foreach ($f['columns'] as $c) $tb .= '<td><input class="form-control form-control-sm" name="' . h($n) . '[' . $i . '][' . h($c['name']) . ']" value="' . h((string) ($r[$c['name']] ?? '')) . '" aria-label="' . h($c['label']) . '"' . ($c['type'] === 'number' ? ' inputmode="numeric"' : '') . '></td>';
                $tb .= '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-del-row aria-label="Remove line"><i class="bi bi-x-lg"></i></button></td></tr>';
            }
            return '<div class="col-12"' . $show . '><label class="form-label">' . h($f['label']) . $req . '</label><div class="table-responsive"><table class="table table-sm mb-2"><thead><tr>' . $th . '<th></th></tr></thead><tbody id="items-' . h($n) . '">' . $tb . '</tbody></table></div><button type="button" class="btn btn-sm btn-outline-primary" data-add-row="items-' . h($n) . '"><i class="bi bi-plus-lg"></i> Add line</button>' . $fb . '</div>';
        default:
            $type = ['email' => 'email', 'tel' => 'tel', 'date' => 'date'][$f['type']] ?? 'text';
            return '<div class="' . $col . '"' . $show . '><label class="form-label" for="' . $id . '">' . h($f['label']) . $req . '</label><input class="form-control' . $inv . '" type="' . $type . '" id="' . $id . '" name="' . h($n) . '" value="' . h(v($d, $n)) . '"' . (!empty($f['max']) && $f['type'] !== 'number' ? ' maxlength="' . (int) $f['max'] . '"' : '') . ($f['type'] === 'number' ? ' inputmode="numeric"' : '') . '>' . $fb . '</div>';
    }
}

$title = $q ? 'Edit ' . $q['quote_no'] : 'New quotation' . ($service ? ': ' . $services[$service]['title'] : '');
page_head($title, $admin, $q ? 'quote' : 'quote:new', [['Quotations', 'quotes.php'], [$q ? $q['quote_no'] : 'New', $q ? 'quote.php?id=' . $id : null]] + ($q ? [2 => ['Edit', null]] : []));

if ($service === ''): ?>
<p class="text-secondary">Use this for enquiries that came by phone, email, WhatsApp or a visit. Choose the service first.</p>
<div class="row g-3">
<?php foreach ($services as $k => $s): ?>
  <div class="col-sm-6 col-xl-3"><a class="card h-100 text-decoration-none" href="quote-edit.php?service=<?= h($k) ?>"><div class="card-body"><span class="tag mb-2"><?= h($s['tag']) ?></span><h5 class="card-title d-block mt-2 mb-1"><?= h($s['title']) ?></h5><p class="card-text small text-secondary mb-0"><?= h($s['short'] ?? '') ?></p></div></a></div>
<?php endforeach; ?>
</div>
<?php else: ?>
<?php if ($errors): ?><div class="alert alert-danger">Please fix the highlighted fields.</div><?php endif; ?>
<form method="post" data-rules novalidate><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="service" value="<?= h($service) ?>">
<?php foreach (quote_sections($service) as $sec): ?>
  <div class="card mb-3"><div class="card-header"><h3 class="card-title"><?= h($sec['title']) ?></h3></div><div class="card-body"><div class="row g-3">
    <?php foreach ($sec['fields'] as $f) echo field($f, $data, $errors); ?>
  </div></div></div>
<?php endforeach; ?>
<div class="d-flex gap-2 mb-4"><button class="btn btn-primary"><i class="bi bi-check2"></i> <?= $q ? 'Save changes' : 'Create quotation' ?></button><a class="btn btn-outline-secondary" href="<?= $q ? 'quote.php?id=' . $id : 'quote-edit.php' ?>">Cancel</a></div>
<p class="small text-secondary"><span class="text-danger">*</span> is required on the website. Here only the client's name is required, plus an email or phone number.</p>
</form>
<?php endif; page_foot();
