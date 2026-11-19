<?php
/* EFRIS e-invoicing: settings, queue posted invoices, send through your configured gateway, keep the fiscal document number (FDN). See inc/efris.php. */
require __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/efris.php';
$admin = require_login(); $pdo = db(); require_perm('efris.view'); $self = 'efris.php';
ob_start();
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'settings') {
        deny_unless('efris.edit', $self);
        foreach (['efris_mode' => ['manual', 'gateway'], 'efris_env' => ['test', 'production']] as $k => $ok) { $v = (string) ($_POST[$k] ?? ''); if (in_array($v, $ok, true)) save_doc_setting($pdo, $k, $v); }
        foreach (['efris_tin' => 20, 'efris_legal_name' => 190, 'efris_device_no' => 40, 'efris_branch' => 80, 'efris_gateway_url' => 300] as $k => $max) save_doc_setting($pdo, $k, mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max));
        if (trim((string) ($_POST['efris_gateway_token'] ?? '')) !== '') save_doc_setting($pdo, 'efris_gateway_token', trim((string) $_POST['efris_gateway_token']));
        if (!empty($_POST['clear_token'])) save_doc_setting($pdo, 'efris_gateway_token', '');
        log_activity((int) $admin['id'], 'efris_settings', ''); flash('EFRIS settings saved.'); redirect($self);
    }
    if ($do === 'queue' || $do === 'retry') {
        deny_unless('efris.submit', $self); $docId = (int) ($_POST['doc'] ?? 0); $payload = efris_payload($pdo, $docId);
        if (!$payload) { flash('Only posted invoices can be sent.', 'bad'); redirect($self); }
        $st = $pdo->prepare("SELECT status FROM documents WHERE id = ?"); $st->execute([$docId]); if ($st->fetchColumn() !== 'posted') { flash('Post the invoice first, then send it to EFRIS.', 'bad'); redirect($self); }
        $ex = $pdo->prepare("SELECT COUNT(*) FROM efris_log WHERE document_id = ? AND status = 'submitted'"); $ex->execute([$docId]); if ((int) $ex->fetchColumn()) { flash('This invoice is already submitted to EFRIS.', 'bad'); redirect($self); }
        $c = efris_cfg(); $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $pdo->prepare("INSERT INTO efris_log (document_id, doc_no, status, request_json, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?)")->execute([$docId, $payload['basicInformation']['invoiceNo'], 'queued', $json, (int) $admin['id'], now(), now()]); $lid = (int) $pdo->lastInsertId();
        if (efris_ready($c)) {
            [$ok, $resp, $err] = efris_send($c, $payload);
            $pdo->prepare('UPDATE efris_log SET status=?, fdn=?, verification_code=?, qr_code=?, response_json=?, error_text=?, updated_at=? WHERE id=?')->execute([$ok ? 'submitted' : 'failed', $ok ? mb_substr((string) ($resp['fdn'] ?? $resp['invoiceNo'] ?? ''), 0, 60) : null, mb_substr((string) ($resp['verificationCode'] ?? $resp['verification_code'] ?? ''), 0, 60) ?: null, mb_substr((string) ($resp['qrCode'] ?? $resp['qr_code'] ?? ''), 0, 1000) ?: null, json_encode($resp, JSON_UNESCAPED_UNICODE), $err ?: null, now(), $lid]);
            flash($ok ? 'Submitted to EFRIS.' : 'EFRIS did not accept it: ' . $err, $ok ? 'ok' : 'bad');
        } else flash('Queued. EFRIS is in manual mode (or the gateway is not set up): download the payload and capture it in the EFRIS portal, then mark it captured.');
        log_activity((int) $admin['id'], 'efris_queue', $payload['basicInformation']['invoiceNo']); redirect($self);
    }
    if ($do === 'captured') {   // manual mode: record the FDN issued by the EFRIS portal
        deny_unless('efris.submit', $self); $lid = (int) ($_POST['log'] ?? 0); $fdn = mb_substr(trim((string) ($_POST['fdn'] ?? '')), 0, 60);
        if ($fdn === '') { flash('Enter the fiscal document number (FDN) shown in EFRIS.', 'bad'); redirect($self); }
        $pdo->prepare("UPDATE efris_log SET status='submitted', fdn=?, error_text=NULL, updated_at=? WHERE id=?")->execute([$fdn, now(), $lid]); flash('Recorded. Invoice marked as submitted.'); redirect($self);
    }
    redirect($self);
  }
  if (isset($_GET['payload'])) {   // download the payload JSON
    require_perm('efris.view'); $p = efris_payload($pdo, (int) $_GET['payload']); if (!$p) redirect($self);
    ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="efris-' . preg_replace('/[^A-Za-z0-9_-]/', '', $p['basicInformation']['invoiceNo']) . '.json"'); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); exit;
  }
  $c = efris_cfg(); $ready = efris_ready($c); $canEdit = can('efris.edit'); $canSub = can('efris.submit');
  page_head('EFRIS e-invoicing', $admin, 'efris');
  echo '<div class="alert alert-' . ($ready ? 'success' : 'info') . '"><b>' . ($ready ? 'Gateway mode is on.' : 'Manual mode.') . '</b> ' . ($ready ? 'Invoices are sent to your gateway and the fiscal document number (FDN) is saved here.' : 'Audtix prepares the EFRIS invoice data for you. Until a gateway is connected, download the payload and capture it in the EFRIS portal, then record the FDN here.') . '<div class="small mt-1">Direct filing with URA needs your registered TIN, device number and either URA-accredited gateway access or URA\'s signing keys. Add those below once you have them.</div></div>';
  if ($canEdit) {
    echo '<div class="card"><div class="card-header"><h3 class="card-title">Settings</h3></div><div class="card-body"><form method="post" class="row g-3">' . csrf_field() . '<input type="hidden" name="do" value="settings">';
    $in = fn($n, $l, $v, $w = 'col-md-6', $t = 'text') => '<div class="' . $w . '"><label class="form-label">' . h($l) . '</label><input class="form-control" type="' . $t . '" name="' . $n . '" value="' . h($v) . '" autocomplete="off"></div>';
    echo '<div class="col-md-6"><label class="form-label">Mode</label><select class="form-select" name="efris_mode"><option value="manual"' . ($c['efris_mode'] === 'manual' ? ' selected' : '') . '>Manual: prepare data, capture in EFRIS portal</option><option value="gateway"' . ($c['efris_mode'] === 'gateway' ? ' selected' : '') . '>Gateway: send automatically</option></select></div>';
    echo '<div class="col-md-6"><label class="form-label">Environment</label><select class="form-select" name="efris_env"><option value="test"' . ($c['efris_env'] === 'test' ? ' selected' : '') . '>Test</option><option value="production"' . ($c['efris_env'] === 'production' ? ' selected' : '') . '>Production</option></select></div>';
    echo $in('efris_tin', 'Taxpayer TIN', $c['efris_tin']), $in('efris_legal_name', 'Legal name', $c['efris_legal_name']), $in('efris_device_no', 'EFRIS device number', $c['efris_device_no']), $in('efris_branch', 'Branch', $c['efris_branch']), $in('efris_gateway_url', 'Gateway address (https://...)', $c['efris_gateway_url'], 'col-md-8');
    echo '<div class="col-md-4"><label class="form-label">Gateway token' . ($c['efris_gateway_token'] !== '' ? ' (saved)' : '') . '</label><input class="form-control" type="password" name="efris_gateway_token" autocomplete="new-password" placeholder="' . ($c['efris_gateway_token'] !== '' ? 'Leave blank to keep' : 'Paste token') . '">' . ($c['efris_gateway_token'] !== '' ? '<div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="clear_token" value="1" id="ct"><label class="form-check-label small" for="ct">Remove saved token</label></div>' : '') . '</div>';
    echo '<div class="col-12"><button class="btn btn-primary">Save settings</button></div></form></div></div>';
  }
  $rows = $pdo->query("SELECT d.id, d.doc_no, d.doc_date, d.customer_name, d.total, d.currency, (SELECT l.id FROM efris_log l WHERE l.document_id = d.id ORDER BY l.id DESC LIMIT 1) lid FROM documents d WHERE d.doc_type = 'invoice' AND d.status = 'posted' ORDER BY d.doc_date DESC, d.id DESC LIMIT 60")->fetchAll();
  $logs = []; foreach ($pdo->query('SELECT id, status, fdn, error_text FROM efris_log') as $l) $logs[(int) $l['id']] = $l;
  echo '<div class="card"><div class="card-header"><h3 class="card-title">Posted invoices</h3></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Invoice</th><th>Customer</th><th class="text-end">Total</th><th>EFRIS</th><th></th></tr></thead><tbody>';
  foreach ($rows as $r) { $l = $logs[(int) $r['lid']] ?? null; $stt = $l['status'] ?? 'not sent';
    echo '<tr><td><span class="mono">' . h($r['doc_no']) . '</span><div class="small text-secondary">' . h($r['doc_date']) . '</div></td><td>' . h($r['customer_name']) . '</td><td class="text-end">' . mod_money($r['total']) . ' ' . h($r['currency']) . '</td><td>' . ($l ? '<span class="badge text-bg-' . ['submitted' => 'success', 'queued' => 'warning', 'failed' => 'danger'][$stt] . '">' . h(ucfirst($stt)) . '</span>' : '<span class="badge text-bg-light border">Not sent</span>') . ($l && $l['fdn'] ? '<div class="small">FDN ' . h($l['fdn']) . '</div>' : '') . ($l && $l['error_text'] ? '<div class="small text-danger">' . h($l['error_text']) . '</div>' : '') . '</td><td class="text-end text-nowrap">'
       . '<a class="btn btn-sm btn-outline-secondary" href="' . $self . '?payload=' . (int) $r['id'] . '" title="Download EFRIS data"><i class="bi bi-filetype-json"></i></a> ';
    if ($canSub && $stt !== 'submitted') {
        if ($stt === 'queued' && !$ready) echo '<form method="post" class="d-inline-flex gap-1 m-0">' . csrf_field() . '<input type="hidden" name="do" value="captured"><input type="hidden" name="log" value="' . (int) $l['id'] . '"><input class="form-control form-control-sm" style="width:130px" name="fdn" placeholder="FDN from EFRIS"><button class="btn btn-sm btn-success">Record</button></form>';
        else echo '<form method="post" class="d-inline m-0">' . csrf_field() . '<input type="hidden" name="do" value="' . ($stt === 'failed' ? 'retry' : 'queue') . '"><input type="hidden" name="doc" value="' . (int) $r['id'] . '"><button class="btn btn-sm btn-primary">' . ($stt === 'failed' ? 'Retry' : ($ready ? 'Send to EFRIS' : 'Prepare')) . '</button></form>';
    }
    echo '</td></tr>'; }
  if (!$rows) echo '<tr><td colspan="5" class="text-secondary">No posted invoices yet.</td></tr>';
  echo '</tbody></table></div></div>';
} catch (Throwable $e) { ob_clean(); page_head('EFRIS e-invoicing', $admin, 'efris'); mod_setup_notice($e); }
page_foot(); ob_end_flush();
