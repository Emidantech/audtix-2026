<?php
/* List, add, edit and delete for the HR, Finance, CRM, Marketing and Procurement modules (definitions in inc/mod.php). */
require __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/mod.php';
$admin = require_login(); $pdo = db();
$defs = mod_defs(); $key = (string) ($_GET['m'] ?? $_POST['m'] ?? ''); $D = $defs[$key] ?? null;
if (!$D) { http_response_code(404); exit('Unknown list.'); }
$perm = $D['perm']; $T = $D['table']; $self = 'mod.php?m=' . $key;
require_perm($perm . '.view');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0); $new = isset($_GET['new']); $err = null; $in = [];

function mod_fetch(PDO $pdo, string $T, int $id): ?array { $s = $pdo->prepare("SELECT * FROM $T WHERE id = ?"); $s->execute([$id]); return $s->fetch() ?: null; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['do'] ?? '') === 'delete' && $id) {
            deny_unless($perm . '.delete', $self); $pdo->prepare("DELETE FROM $T WHERE id = ?")->execute([$id]);
            log_activity((int) $admin['id'], $key . '_delete', (string) $id); flash('Deleted.'); redirect($self);
        }
        deny_unless($perm . ($id ? '.edit' : '.add'), $self);
        $old = $id ? mod_fetch($pdo, $T, $id) : null; $d = [];
        foreach ($D['fields'] as $f) {
            $v = trim((string) ($_POST[$f[0]] ?? ''));
            if (in_array($f[2], ['money', 'number'], true)) $v = $v === '' ? '0' : str_replace([',', ' '], '', $v);
            $d[$f[0]] = $v;
            if (!empty($f[4]) && $v === '') { $err = $f[1] . ' is required.'; }
            if (in_array($f[2], ['money', 'number'], true) && !is_numeric($d[$f[0]])) $err = $f[1] . ' must be a number.';
            if ($f[2] === 'date' && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $err = $f[1] . ' is not a valid date.';
            if ($f[2] === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $err = $f[1] . ' is not a valid email.';
            if ($f[2] === 'select' && $v !== '' && is_array($f[3]) && !isset($f[3][$v])) $err = $f[1] . ': choose one of the options.';
        }
        if (!$err && !empty($D['before'])) $err = ($D['before'])($d, $old);
        if ($err) { $in = $d; }
        else {
            $cols = array_keys($d); foreach (['po_no', 'supplier_name', 'total', 'days', 'emp_no'] as $x) if (isset($d[$x]) && !in_array($x, $cols, true)) $cols[] = $x;
            foreach ($d as $k => $v) if ($v === '' && !in_array($k, ['full_name', 'title', 'name'], true)) $d[$k] = null;
            if ($old) { $set = implode(', ', array_map(fn($c) => "$c = ?", $cols)); $vals = array_map(fn($c) => $d[$c], $cols); $vals[] = now(); $vals[] = $id;
                $pdo->prepare("UPDATE $T SET $set, updated_at = ? WHERE id = ?")->execute($vals); flash('Saved.'); }
            else { $cols[] = 'created_at'; $cols[] = 'updated_at'; $d['created_at'] = $d['updated_at'] = now();
                if ($T === 'fin_expenses' || $T === 'proc_orders') { $cols[] = 'created_by'; $d['created_by'] = (int) $admin['id']; }
                $pdo->prepare("INSERT INTO $T (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_map(fn($c) => $d[$c], $cols)); flash('Added.'); }
            log_activity((int) $admin['id'], $key . ($old ? '_edit' : '_add'), (string) ($id ?: $pdo->lastInsertId()));
            redirect($self);
        }
    } catch (Throwable $e) { $err = 'Could not save: ' . $e->getMessage(); $in = $_POST; }
}

$active = $D['nav'] . ':' . $key;
$canAdd = can($perm . '.add'); $canEdit = can($perm . '.edit'); $canDel = can($perm . '.delete');
$editing = $id || $new || ($err !== null);
page_head($D['title'], $admin, $active, [], $canAdd && !$editing ? '<a class="btn btn-primary btn-sm" href="' . h($self) . '&new=1"><i class="bi bi-plus-lg"></i> Add ' . h($D['one']) . '</a>' : '');

try {
  if ($editing) {
    $row = $id ? mod_fetch($pdo, $T, $id) : null; if ($id && !$row) { echo '<div class="alert alert-danger">Not found.</div>'; page_foot(); exit; }
    $val = $err ? $in : ($row ?: ['status' => '', 'currency' => 'UGX', 'exp_date' => gmdate('Y-m-d'), 'order_date' => gmdate('Y-m-d')]);
    if (!$id && !$err && isset($_GET['stage'])) $val['stage'] = (string) $_GET['stage'];
    echo '<div class="card"><div class="card-body"><h5 class="mb-3">' . ($id ? 'Edit ' : 'New ') . h($D['one']) . '</h5>';
    if ($err) echo '<div class="alert alert-danger py-2">' . h($err) . '</div>';
    echo '<form method="post" class="row g-3">' . csrf_field() . '<input type="hidden" name="m" value="' . h($key) . '"><input type="hidden" name="id" value="' . $id . '">';
    foreach ($D['fields'] as $f) {
        [$n, $label, $type] = $f; $opts = $f[3] ?? null; $v = (string) ($val[$n] ?? ''); $req = !empty($f[4]) ? ' required' : '';
        $col = in_array($type, ['textarea'], true) ? 'col-12' : 'col-md-6';
        echo '<div class="' . $col . '"><label class="form-label">' . h($label) . (!empty($f[4]) ? ' *' : '') . '</label>';
        if ($type === 'textarea') echo '<textarea class="form-control" name="' . h($n) . '" rows="3">' . h($v) . '</textarea>';
        elseif ($type === 'select' || in_array($type, ['employee', 'supplier', 'customer'], true)) {
            $o = $type === 'select' ? $opts : [' ' => ''] + mod_lookup($type);
            echo '<select class="form-select" name="' . h($n) . '"' . $req . '>'; if ($type !== 'select') echo '<option value="">Choose...</option>';
            foreach ($o as $k => $lab) { if ($lab === '' ) continue; echo '<option value="' . h($k) . '"' . ((string) $k === $v || ($v === '' && $type === 'select' && $k === array_key_first($o)) ? ' selected' : '') . '>' . h($lab) . '</option>'; }
            echo '</select>';
        } else {
            $it = ['date' => 'date', 'email' => 'email', 'tel' => 'tel', 'number' => 'number'][$type] ?? 'text';
            echo '<input class="form-control" type="' . $it . '" name="' . h($n) . '" value="' . h(in_array($type, ['money', 'number'], true) && $v !== '' ? rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') : $v) . '"' . ($type === 'money' ? ' inputmode="decimal"' : '') . $req . '>';
        }
        echo '</div>';
    }
    echo '<div class="col-12 d-flex gap-2"><button class="btn btn-primary">Save</button><a class="btn btn-outline-secondary" href="' . h($self) . '">Cancel</a>';
    if ($id && $canDel) echo '<button class="btn btn-outline-danger ms-auto" name="do" value="delete" data-confirm="Delete this ' . h($D['one']) . '? This cannot be undone." formnovalidate>Delete</button>';
    echo '</div></form>';
    if ($key === 'crm_deals' && $id && $row) {   // register the deal's company as a customer so documents can be raised for it
        echo '<hr><a class="btn btn-sm btn-outline-primary" href="crm.php?deal=' . $id . '"><i class="bi bi-person-plus"></i> Open in pipeline</a>';
    }
    echo '</div></div>'; page_foot(); exit;
  }

  /* ---- list ---- */
  $q = trim((string) ($_GET['q'] ?? '')); $fil = (string) ($_GET['f'] ?? ''); $page = max(1, (int) ($_GET['page'] ?? 1)); $per = 25;
  $where = []; $args = [];
  if ($q !== '') { $w = []; foreach ($D['search'] as $c) { $w[] = "$c LIKE ?"; $args[] = '%' . $q . '%'; } $where[] = '(' . implode(' OR ', $w) . ')'; }
  if ($fil !== '' && !empty($D['filter'])) { $where[] = 't.' . $D['filter'][0] . ' = ?'; $args[] = $fil; }
  $W = $where ? ' WHERE ' . implode(' AND ', $where) : ''; $J = ' ' . ($D['join'] ?? '');
  $cnt = $pdo->prepare("SELECT COUNT(*) FROM $T t$J$W"); $cnt->execute($args); $total = (int) $cnt->fetchColumn(); $pages = max(1, (int) ceil($total / $per)); $page = min($page, $pages);
  $s = $pdo->prepare('SELECT t.*' . (!empty($D['extra']) ? ', ' . $D['extra'] : '') . " FROM $T t$J$W ORDER BY {$D['order']} LIMIT $per OFFSET " . (($page - 1) * $per)); $s->execute($args); $rows = $s->fetchAll();
  $sums = []; foreach (($D['sum'] ?? []) as $c) { $x = $pdo->prepare("SELECT COALESCE(SUM(t.$c),0) FROM $T t$J$W"); $x->execute($args); $sums[$c] = (float) $x->fetchColumn(); }
  $fopts = []; if (!empty($D['filter'])) { $fopts = $D['filter'][1] ?? []; if (!$fopts) foreach ($pdo->query("SELECT DISTINCT {$D['filter'][0]} v FROM $T ORDER BY 1") as $r) if ($r['v'] !== null && $r['v'] !== '') $fopts[$r['v']] = $r['v']; }
  echo '<div class="card"><div class="card-body pb-2"><form class="row g-2 align-items-end" method="get"><input type="hidden" name="m" value="' . h($key) . '"><div class="col-12 col-md-5"><input class="form-control" type="search" name="q" value="' . h($q) . '" placeholder="Search ' . h(strtolower($D['title'])) . '"></div>';
  if ($fopts) { echo '<div class="col-6 col-md-3"><select class="form-select" name="f"><option value="">All</option>'; foreach ($fopts as $k => $lab) echo '<option value="' . h($k) . '"' . ((string) $k === $fil ? ' selected' : '') . '>' . h($lab) . '</option>'; echo '</select></div>'; }
  echo '<div class="col-auto"><button class="btn btn-primary">Filter</button> <a class="btn btn-outline-secondary" href="' . h($self) . '">Clear</a></div></form>';
  if ($sums) { echo '<div class="mt-2 small text-secondary">'; foreach ($sums as $c => $v) { $lab = ''; foreach ($D['cols'] as $cc) if ($cc[0] === $c) $lab = $cc[1]; echo '<span class="me-3">' . h($lab ?: ucfirst($c)) . ': <b>' . mod_money($v) . '</b></span>'; } echo '</div>'; }
  echo '</div>';
  if (!$rows) echo '<div class="card-body text-secondary">Nothing here yet.' . ($canAdd ? ' <a href="' . h($self) . '&new=1">Add the first ' . h($D['one']) . '</a>.' : '') . '</div>';
  else {
    echo '<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr>';
    foreach ($D['cols'] as $c) echo '<th>' . h($c[1]) . '</th>'; echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
      $href = $canEdit || true ? h($self) . '&id=' . (int) $r['id'] : '#'; echo '<tr>';
      foreach ($D['cols'] as $i => $c) {
        $v = (string) ($r[$c[0]] ?? ''); $fmt = $c[2] ?? '';
        $cell = $fmt === 'money' ? mod_money($v) : ($fmt === 'badge' ? mod_badge($v) : ($fmt === 'date' ? h($v) : h($v)));
        if ($fmt === 'mono') $cell = '<span class="mono">' . h($v) . '</span>';
        if ($i === 0 || $fmt === 'strong') { if ($fmt === 'strong' || $i === 0) $cell = '<a href="' . $href . '" class="fw-semibold">' . ($cell !== '' ? $cell : 'Open') . '</a>'; }
        echo '<td' . ($fmt === 'money' ? ' class="text-end"' : '') . '>' . $cell . '</td>';
      }
      echo '</tr>';
    }
    echo '</tbody></table></div><div class="card-footer">' . pager($page, $pages, $total, fn($p) => $self . '&q=' . urlencode($q) . '&f=' . urlencode($fil) . '&page=' . $p, $D['one']) . '</div>';
  }
  echo '</div>';
} catch (Throwable $e) { mod_setup_notice($e); }
page_foot();
