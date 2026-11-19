<?php
/* CRM pipeline: deals by stage, move a deal along, log activities, and register the company as a customer. */
require __DIR__ . '/inc/boot.php';
require_once dirname(__DIR__) . '/api/lib/customers.php';
$admin = require_login(); $pdo = db(); require_perm('crm.view');
const CRM_STAGES = ['lead' => 'Lead', 'qualified' => 'Qualified', 'proposal' => 'Proposal', 'negotiation' => 'Negotiation', 'won' => 'Won', 'lost' => 'Lost'];
ob_start();
$self = 'crm.php'; $dealId = (int) ($_GET['deal'] ?? $_POST['deal'] ?? 0);
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $d = $pdo->prepare('SELECT * FROM crm_deals WHERE id = ?'); $d->execute([$dealId]); $deal = $d->fetch(); if (!$deal) redirect($self);
    $back = $self . '?deal=' . $dealId;
    if ($do === 'stage' && isset(CRM_STAGES[$_POST['stage'] ?? ''])) { deny_unless('crm.edit', $self); $pdo->prepare('UPDATE crm_deals SET stage=?, updated_at=? WHERE id=?')->execute([$_POST['stage'], now(), $dealId]);
        $pdo->prepare('INSERT INTO crm_activities (deal_id, act_type, body, created_by, created_at) VALUES (?,?,?,?,?)')->execute([$dealId, 'stage', 'Moved to ' . CRM_STAGES[$_POST['stage']], (int) $admin['id'], now()]); flash('Moved to ' . CRM_STAGES[$_POST['stage']] . '.'); redirect(($_POST['from'] ?? '') === 'board' ? $self : $back); }
    if ($do === 'note') { deny_unless('crm.edit', $self); $b = trim((string) ($_POST['body'] ?? '')); $t = in_array($_POST['act_type'] ?? '', ['note', 'call', 'meeting', 'email', 'task'], true) ? $_POST['act_type'] : 'note';
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['due_date'] ?? '')) ? $_POST['due_date'] : null;
        if ($b !== '') { $pdo->prepare('INSERT INTO crm_activities (deal_id, act_type, body, due_date, created_by, created_at) VALUES (?,?,?,?,?,?)')->execute([$dealId, $t, mb_substr($b, 0, 1000), $due, (int) $admin['id'], now()]); $pdo->prepare('UPDATE crm_deals SET updated_at=? WHERE id=?')->execute([now(), $dealId]); flash('Logged.'); } redirect($back); }
    if ($do === 'done') { deny_unless('crm.edit', $self); $pdo->prepare('UPDATE crm_activities SET is_done = 1 WHERE id = ? AND deal_id = ?')->execute([(int) $_POST['act'], $dealId]); redirect($back); }
    if ($do === 'customer') { deny_unless('crm.edit', $self); $cid = capture_customer($pdo, ['name' => $deal['company'] ?: $deal['contact_name'] ?: $deal['title'], 'contact_person' => $deal['contact_name'], 'phone' => $deal['phone'], 'email' => $deal['email'], 'note' => 'Registered from CRM deal #' . $dealId . '.']);
        if ($cid) { $pdo->prepare('UPDATE crm_deals SET customer_id=?, updated_at=? WHERE id=?')->execute([$cid, now(), $dealId]); flash('Registered as a customer. You can now raise documents for them.'); } else flash('Could not register the customer: ' . ($GLOBALS['capture_customer_error'] ?? 'unknown reason'), 'bad'); redirect($back); }
    redirect($back);
  }
  page_head($dealId ? 'Deal' : 'CRM pipeline', $admin, 'crm:' . ($dealId ? 'crm_deals' : 'pipeline'), $dealId ? [['CRM pipeline', $self], ['Deal', null]] : [], !$dealId && can('crm.add') ? '<a class="btn btn-primary btn-sm" href="mod.php?m=crm_deals&new=1"><i class="bi bi-plus-lg"></i> Add lead or deal</a>' : '');
  if ($dealId) {
    $d = $pdo->prepare('SELECT * FROM crm_deals WHERE id = ?'); $d->execute([$dealId]); $deal = $d->fetch(); if (!$deal) { echo '<div class="alert alert-danger">Deal not found.</div>'; page_foot(); exit; }
    echo '<div class="card"><div class="card-body"><div class="d-flex flex-wrap gap-2 align-items-start"><div class="me-auto"><h5 class="mb-1">' . h($deal['title']) . ' ' . mod_badge($deal['stage']) . '</h5><div class="text-secondary small">' . h(implode(' · ', array_filter([$deal['company'], $deal['contact_name'], $deal['phone'], $deal['email']]))) . '</div><div class="mt-1">Value <b>' . mod_money($deal['deal_value']) . '</b> UGX' . ($deal['expected_close'] ? ' &middot; close by ' . h($deal['expected_close']) : '') . ($deal['next_step'] ? ' &middot; next: ' . h($deal['next_step']) : '') . '</div></div>';
    if (can('crm.edit')) echo '<div class="d-flex gap-2 flex-wrap"><a class="btn btn-sm btn-outline-primary" href="mod.php?m=crm_deals&id=' . $dealId . '">Edit</a>' . (!$deal['customer_id'] ? '<form method="post" class="m-0">' . csrf_field() . '<input type="hidden" name="deal" value="' . $dealId . '"><button class="btn btn-sm btn-outline-success" name="do" value="customer"><i class="bi bi-person-plus"></i> Register as customer</button></form>' : '<a class="btn btn-sm btn-outline-success" href="customers.php?q=' . urlencode((string) ($deal['company'] ?: $deal['contact_name'])) . '"><i class="bi bi-check2-circle"></i> Customer</a>') . '</div>';
    echo '</div>';
    if (can('crm.edit')) { echo '<form method="post" class="d-flex flex-wrap gap-1 mt-3">' . csrf_field() . '<input type="hidden" name="deal" value="' . $dealId . '"><input type="hidden" name="do" value="stage">'; foreach (CRM_STAGES as $k => $l) echo '<button class="btn btn-sm ' . ($k === $deal['stage'] ? 'btn-primary' : 'btn-outline-secondary') . '" name="stage" value="' . $k . '">' . h($l) . '</button>'; echo '</form>'; }
    if ($deal['notes']) echo '<p class="mt-3 mb-0" style="white-space:pre-wrap">' . h($deal['notes']) . '</p>';
    echo '</div></div>';
    if (can('crm.edit')) echo '<div class="card"><div class="card-body"><form method="post" class="row g-2">' . csrf_field() . '<input type="hidden" name="deal" value="' . $dealId . '"><input type="hidden" name="do" value="note"><div class="col-6 col-md-2"><select class="form-select" name="act_type"><option value="note">Note</option><option value="call">Call</option><option value="meeting">Meeting</option><option value="email">Email</option><option value="task">Task</option></select></div><div class="col-12 col-md-6"><input class="form-control" name="body" maxlength="1000" placeholder="What happened or what needs doing?" required></div><div class="col-6 col-md-2"><input class="form-control" type="date" name="due_date" title="Due date (optional)"></div><div class="col-auto"><button class="btn btn-primary">Log</button></div></form></div></div>';
    $a = $pdo->prepare('SELECT * FROM crm_activities WHERE deal_id = ? ORDER BY id DESC LIMIT 100'); $a->execute([$dealId]); $acts = $a->fetchAll();
    echo '<div class="card"><div class="card-header"><h3 class="card-title">Activity</h3></div><ul class="list-group list-group-flush">';
    foreach ($acts as $x) echo '<li class="list-group-item d-flex gap-2 align-items-start"><span class="badge text-bg-light border">' . h($x['act_type']) . '</span><div class="me-auto">' . h($x['body']) . '<div class="small text-secondary">' . h(eat($x['created_at'])) . ($x['due_date'] ? ' &middot; due ' . h($x['due_date']) : '') . '</div></div>' . ($x['act_type'] === 'task' && !$x['is_done'] && can('crm.edit') ? '<form method="post" class="m-0">' . csrf_field() . '<input type="hidden" name="deal" value="' . $dealId . '"><input type="hidden" name="act" value="' . (int) $x['id'] . '"><button class="btn btn-sm btn-outline-success" name="do" value="done">Done</button></form>' : ($x['is_done'] ? '<i class="bi bi-check2-circle text-success"></i>' : '')) . '</li>';
    if (!$acts) echo '<li class="list-group-item text-secondary">No activity yet.</li>';
    echo '</ul></div>';
  } else {
    $all = $pdo->query('SELECT id, title, company, contact_name, stage, deal_value, expected_close FROM crm_deals ORDER BY updated_at DESC LIMIT 600')->fetchAll(); $by = array_fill_keys(array_keys(CRM_STAGES), []);
    foreach ($all as $d) $by[$d['stage']][] = $d;
    echo '<div class="row g-2 crm-board">';
    foreach (CRM_STAGES as $k => $lab) { $sum = array_sum(array_map(fn($d) => (float) $d['deal_value'], $by[$k]));
        echo '<div class="col-12 col-md-6 col-xl-2"><div class="card h-100 mb-0"><div class="card-header py-2"><b>' . h($lab) . '</b> <span class="badge text-bg-secondary">' . count($by[$k]) . '</span><div class="small text-secondary">' . mod_money($sum) . ' UGX</div></div><div class="card-body p-2">';
        foreach (array_slice($by[$k], 0, 30) as $d) echo '<div class="border rounded p-2 mb-2"><a class="fw-semibold" href="' . $self . '?deal=' . (int) $d['id'] . '">' . h($d['title']) . '</a><div class="small text-secondary">' . h((string) ($d['company'] ?: $d['contact_name'])) . '</div><div class="small">' . mod_money($d['deal_value']) . '</div></div>';
        if (!$by[$k]) echo '<span class="small text-secondary">Empty</span>';
        echo '</div></div></div>'; }
    echo '</div>';
  }
} catch (Throwable $e) { ob_clean(); page_head('CRM', $admin, 'crm:pipeline'); mod_setup_notice($e); }
page_foot(); ob_end_flush();
