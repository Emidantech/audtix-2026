<?php
declare(strict_types=1);
/* Report building and export (HTML table data, PDF, Excel). Money columns carry integer cents. */

function report_types(): array {
    $r = []; foreach (DOC_TYPES as $k => $t) if (dcan('doc.view', null, $k)) $r[$k] = $t['label'] . ' report';
    $r['customers'] = 'Customer transaction report'; $r['products'] = 'Products and services transaction report';
    if (can('quotes.view')) { $r['rfq'] = 'Quotation requests by service (RFQ summary)'; $r['rfq_list'] = 'Quotation requests list (RFQs)'; }
    if (can('requests.view')) $r['requests'] = 'Tax and audit requests report';
    return $r;
}
function cur_totals(array $m, bool $signed = false): string { ksort($m); $o = []; foreach ($m as $cu => $v) $o[] = ($v < 0 ? '-' : '') . dmoney(abs($v), $cu) . ' ' . $cu; return $o ? implode(' / ', $o) : '0'; }

/** @return array{title:string,columns:array,rows:array,summary:array,period:string,filters:string} */
function build_report(PDO $pdo, array $p): array {
    $types = report_types(); $kind = isset($types[$p['report']]) ? $p['report'] : (string) array_key_first($types);
    [$from, $to, $plabel] = period_range((string) $p['period'], (string) ($p['from'] ?? ''), (string) ($p['to'] ?? ''));
    $f = ['from' => $from, 'to' => $to, 'customer_id' => (int) ($p['customer_id'] ?? 0), 'created_by' => (int) ($p['created_by'] ?? 0), 'q' => trim((string) ($p['q'] ?? '')), 'status' => (string) ($p['status'] ?? '')];
    $fl = []; if ($f['customer_id']) { $s = $pdo->prepare('SELECT name FROM customers WHERE id = ?'); $s->execute([$f['customer_id']]); if ($n = $s->fetchColumn()) $fl[] = 'Customer: ' . $n; }
    if ($f['status']) $fl[] = 'Status: ' . ucfirst($f['status']); if ($f['q'] !== '') $fl[] = 'Reference: ' . $f['q'];
    if ($f['created_by']) { $s = $pdo->prepare('SELECT full_name FROM admins WHERE id = ?'); $s->execute([$f['created_by']]); if ($n = $s->fetchColumn()) $fl[] = 'Created by: ' . $n; }
    $out = ['title' => $types[$kind], 'period' => $plabel . ': ' . fmt_date($from) . ' to ' . fmt_date($to), 'filters' => implode('  |  ', $fl), 'columns' => [], 'rows' => [], 'summary' => [], 'from' => $from, 'to' => $to];

    if (in_array($kind, ['rfq', 'rfq_list', 'requests'], true)) { rfq_sync_all($pdo); return build_rfq_report($pdo, $kind, $out, $from, $to, (string) $f['status'], (string) $f['q']); }
    if (isset(DOC_TYPES[$kind])) {
        $res = list_documents($pdo, $f + ['type' => $kind], 1, 5000);
        $cols = [['Number', 'no', 't', 92], ['Date', 'date', 't', 70], ['Customer', 'customer', 't', 150], ['Reference', 'ref', 't', 100], ['Status', 'status', 't', 56], ['Cur', 'cur', 't', 30], ['Subtotal', 'sub', 'm', 76], ['Discount', 'disc', 'm', 64], ['Tax', 'tax', 'm', 64], ['Total', 'total', 'm', 80]];
        if ($kind === 'invoice') { $cols[] = ['Received', 'paid', 'm', 76]; $cols[] = ['Balance', 'bal', 'm', 76]; }
        $cols[] = ['Created by', 'by', 't', 80]; $out['columns'] = $cols;
        $cnt = ['draft' => 0, 'posted' => 0, 'cancelled' => 0]; $val = []; $paid = []; $canc = []; 
        foreach ($res['rows'] as $d) {
            $cnt[$d['status']]++; $cu = $d['currency']; $tot = db_cents($d['total']);
            if ($d['status'] === 'posted') { $val[$cu] = ($val[$cu] ?? 0) + $tot; if ($kind === 'invoice') $paid[$cu] = ($paid[$cu] ?? 0) + db_cents($d['paid']); }
            if ($d['status'] === 'cancelled') $canc[$cu] = ($canc[$cu] ?? 0) + $tot;
            $row = ['no' => $d['doc_no'], 'date' => fmt_date($d['doc_date']), 'customer' => $d['customer_name'], 'ref' => (string) $d['reference_text'], 'status' => ucfirst($d['status']), 'cur' => $cu, 'sub' => db_cents($d['subtotal']), 'disc' => db_cents($d['discount_total']), 'tax' => db_cents($d['tax_total']), 'total' => $tot, 'by' => (string) $d['creator'], 'id' => (int) $d['id']];
            if ($kind === 'invoice') { $row['paid'] = $d['status'] === 'posted' ? db_cents($d['paid']) : 0; $row['bal'] = $d['status'] === 'posted' ? $tot - db_cents($d['paid']) : 0; }
            $out['rows'][] = $row;
        }
        $out['summary'][] = ['Documents', (string) $res['total']]; $out['summary'][] = ['Draft / Posted / Cancelled', $cnt['draft'] . ' / ' . $cnt['posted'] . ' / ' . $cnt['cancelled']];
        if ($kind === 'invoice') { $o = $val; foreach ($paid as $cu => $v) $o[$cu] = ($o[$cu] ?? 0) - $v; $out['summary'][] = ['Total invoiced (posted)', cur_totals($val)]; $out['summary'][] = ['Received on these invoices', cur_totals($paid)]; $out['summary'][] = ['Outstanding on these invoices', cur_totals($o)]; }
        elseif ($kind === 'receipt') $out['summary'][] = ['Total collected (posted)', cur_totals($val)];
        else $out['summary'][] = ['Total value (posted)', cur_totals($val)];
        $out['summary'][] = ['Cancelled value', cur_totals($canc)];
        if ($res['total'] > 5000) $out['summary'][] = ['Note', 'Only the first 5,000 documents are listed. Narrow the dates.'];
    } elseif ($kind === 'customers') {
        $res = list_documents($pdo, $f + ['type' => ''], 1, 5000); $g = [];
        foreach ($res['rows'] as $d) {
            $key = $d['customer_id'] . '|' . $d['currency'];
            $g[$key] ??= ['customer' => $d['customer_name'], 'cur' => $d['currency'], 'docs' => 0, 'inv_n' => 0, 'invoiced' => 0, 'receipts' => 0, 'canc' => 0, 'out' => 0, 'cid' => (int) $d['customer_id']];
            $g[$key]['docs']++;
            if ($d['status'] === 'cancelled') { $g[$key]['canc']++; continue; }
            if ($d['status'] !== 'posted') continue;
            if ($d['doc_type'] === 'invoice') { $g[$key]['inv_n']++; $g[$key]['invoiced'] += db_cents($d['total']); }
            if ($d['doc_type'] === 'receipt') $g[$key]['receipts'] += db_cents($d['total']);
        }
        $o = $pdo->query("SELECT i.customer_id, i.currency, SUM(i.total) AS t, (SELECT COALESCE(SUM(r.total),0) FROM documents r WHERE r.doc_type='receipt' AND r.status='posted' AND r.parent_id IN (SELECT id FROM documents x WHERE x.customer_id = i.customer_id AND x.currency = i.currency AND x.doc_type='invoice' AND x.status='posted')) AS p FROM documents i WHERE i.doc_type='invoice' AND i.status='posted' GROUP BY i.customer_id, i.currency")->fetchAll();
        $outs = []; foreach ($o as $r) $outs[$r['customer_id'] . '|' . $r['currency']] = db_cents($r['t']) - db_cents($r['p']);
        if (!dcan('doc.view', null, 'invoice')) $outs = [];
        foreach ($g as $k => &$r) $r['out'] = $outs[$k] ?? 0; unset($r);
        uasort($g, fn($a, $b) => $b['invoiced'] <=> $a['invoiced']);
        $out['columns'] = [['Customer', 'customer', 't', 200], ['Cur', 'cur', 't', 34], ['Documents', 'docs', 'n', 62], ['Invoices', 'inv_n', 'n', 56], ['Invoiced (posted)', 'invoiced', 'm', 110], ['Receipts (posted)', 'receipts', 'm', 110], ['Cancelled', 'canc', 'n', 56], ['Outstanding now', 'out', 'm', 110]];
        $out['rows'] = array_values($g); $inv = []; $rc = []; $ot = [];
        foreach ($g as $r) { $inv[$r['cur']] = ($inv[$r['cur']] ?? 0) + $r['invoiced']; $rc[$r['cur']] = ($rc[$r['cur']] ?? 0) + $r['receipts']; $ot[$r['cur']] = ($ot[$r['cur']] ?? 0) + $r['out']; }
        $out['summary'] = [['Customers with activity', (string) count(array_unique(array_map(fn($r) => $r['cid'], $g)))], ['Invoiced (posted)', cur_totals($inv)], ['Receipts (posted)', cur_totals($rc)], ['Outstanding now (all time)', cur_totals($ot)]];
    } else {   // products
        $dt = (string) ($p['doc_type'] ?? 'invoice'); $w = "d.status = 'posted' AND d.doc_date >= ? AND d.doc_date <= ? AND " . doc_type_in('d.doc_type'); $a = [$from, $to];
        if (isset(DOC_TYPES[$dt]) && $dt !== 'receipt') { $w .= ' AND d.doc_type = ?'; $a[] = $dt; } else { $dt = 'all'; $w .= " AND d.doc_type <> 'receipt'"; }
        if ($f['customer_id']) { $w .= ' AND d.customer_id = ?'; $a[] = $f['customer_id']; }
        if ($f['created_by']) { $w .= ' AND d.created_by = ?'; $a[] = $f['created_by']; }
        if ($f['q'] !== '') { $w .= ' AND (COALESCE(i.name, l.description) LIKE ? OR d.doc_no LIKE ?)'; $a[] = '%' . $f['q'] . '%'; $a[] = '%' . $f['q'] . '%'; }
        $s = $pdo->prepare("SELECT COALESCE(i.name, l.description) AS name, d.currency AS cur, SUM(l.quantity) AS qty, SUM(l.gross_amount) AS gross, SUM(l.discount) AS disc, SUM(l.tax_amount) AS tax, SUM(l.line_total) AS total, COUNT(DISTINCT d.id) AS docs
                            FROM document_lines l JOIN documents d ON d.id = l.document_id LEFT JOIN items i ON i.id = l.item_id WHERE $w GROUP BY COALESCE(i.name, l.description), d.currency ORDER BY SUM(l.line_total) DESC LIMIT 1000");
        $s->execute($a); $rows = []; $tot = [];
        foreach ($s->fetchAll() as $r) { $rows[] = ['name' => mb_strimwidth((string) $r['name'], 0, 120, '...'), 'cur' => $r['cur'], 'qty' => fmt_qty($r['qty']), 'docs' => (int) $r['docs'], 'gross' => db_cents($r['gross']), 'disc' => db_cents($r['disc']), 'tax' => db_cents($r['tax']), 'total' => db_cents($r['total'])]; $tot[$r['cur']] = ($tot[$r['cur']] ?? 0) + db_cents($r['total']); }
        $out['columns'] = [['Product or service', 'name', 't', 230], ['Cur', 'cur', 't', 34], ['Quantity', 'qty', 't', 70], ['Documents', 'docs', 'n', 62], ['Amount', 'gross', 'm', 100], ['Discount', 'disc', 'm', 90], ['Tax', 'tax', 'm', 90], ['Total', 'total', 'm', 100]];
        $out['rows'] = $rows; $out['summary'] = [['Document type', $dt === 'all' ? 'All except receipts' : DOC_TYPES[$dt]['plural']], ['Distinct items', (string) count($rows)], ['Total (posted documents)', cur_totals($tot)]];
    }
    return $out;
}
function report_xlsx(array $r): ?string {
    $head = array_map(fn($c) => $c[0], $r['columns']); $rows = [$head];
    foreach ($r['rows'] as $row) { $x = []; foreach ($r['columns'] as $c) { $v = $row[$c[1]] ?? ''; $x[] = $c[2] === 'm' ? ['m' => ((int) $v) / 100] : ($c[2] === 'n' ? (int) $v : (string) $v); } $rows[] = $x; }
    $sum = [['Summary', ''], ['Report', $r['title']], ['Period', $r['period']]]; if ($r['filters']) $sum[] = ['Filters', $r['filters']]; foreach ($r['summary'] as $s) $sum[] = [$s[0], $s[1]];
    return xlsx_build([['name' => 'Report', 'rows' => $rows, 'widths' => array_map(fn($c) => max(10, (int) ($c[3] / 5.2)), $r['columns'])], ['name' => 'Summary', 'rows' => $sum, 'widths' => [32, 60]]]);
}
function report_pdf(array $r): string {
    $p = new SimplePdf(true, $r['title']); $M = 32; $W = $p->W; $H = $p->H; $CW = $W - 2 * $M; $ink = [16, 32, 47]; $mut = [90, 107, 125]; $line = [214, 224, 236]; $band = [241, 245, 250];
    $footer = function () use ($p, $M, $W, $H, $mut, $line) { $p->line($M, $H - 36, $W - $M, $H - 36, 0.5, $line); $p->text($M, $H - 22, doc_setting('company_name', 'Audtix Systems Ltd') . '  |  Generated ' . (new DateTime('now', new DateTimeZone('Africa/Kampala')))->format('j M Y H:i') . ' EAT', 8, false, $mut); $p->text($W - $M - 100, $H - 22, 'Page {{PAGE}} of {{PAGES}}', 8, false, $mut, 'r', 100); };
    $footer(); $y = 40; $p->image(company_logo_path(), $M, $y - 4, 90, 34);
    $p->text($W - $M - 400, $y + 10, $r['title'], 16, true, $ink, 'r', 400); $p->text($W - $M - 400, $y + 25, $r['period'], 9, false, $mut, 'r', 400); $y += 48;
    if ($r['filters']) { $p->text($M, $y, $r['filters'], 8.5, false, $mut); $y += 14; }
    foreach ($r['summary'] as $s) foreach ($p->wrap($s[1], $CW - 190, 9, true) as $k => $l) { if ($k === 0) $p->text($M, $y, $s[0], 9, false, $mut); $p->text($M + 170, $y, $l, 9, true, $ink); $y += 12.5; }
    $y += 10;
    $sum = 0; foreach ($r['columns'] as $c) $sum += $c[3]; $scale = $CW / $sum; $cols = []; $x = $M; foreach ($r['columns'] as $c) { $w = $c[3] * $scale; $cols[] = [$c[0], $c[1], $c[2], $x, $w]; $x += $w; }
    $head = function (float $yy) use ($p, $cols, $M, $CW, $band, $ink) { $p->rect($M, $yy, $CW, 18, $band); foreach ($cols as $c) $p->text($c[3] + 3, $yy + 12.5, $c[0], 8, true, $ink, $c[2] === 'm' || $c[2] === 'n' ? 'r' : 'l', $c[4] - 6); return $yy + 18; };
    $y = $head($y);
    foreach ($r['rows'] as $row) {
        $cells = []; $rh = 16;
        foreach ($cols as $c) { $v = $row[$c[1]] ?? ''; if ($c[2] === 'm') $s = dmoney((int) $v, (string) ($row['cur'] ?? 'UGX')); else $s = (string) $v; $wr = $c[2] === 't' ? $p->wrap($s, $c[4] - 6, 8) : [$s]; $cells[] = $wr; $rh = max($rh, count($wr) * 10.5 + 6); }
        if ($y + $rh > $H - 46) { $p->addPage(); $footer(); $y = $head(36); }
        foreach ($cols as $i => $c) foreach ($cells[$i] as $j => $t) $p->text($c[3] + 3, $y + 11 + $j * 10.5, $t, 8, false, $ink, $c[2] === 'm' || $c[2] === 'n' ? 'r' : 'l', $c[4] - 6);
        $y += $rh; $p->line($M, $y, $M + $CW, $y, 0.3, $line);
    }
    if (!$r['rows']) $p->text($M, $y + 18, 'No records for these filters.', 9, false, $mut);
    return $p->output();
}


/** Request (RFQ) reports. Dates are East Africa Time days; received = when the request came in. */
function build_rfq_report(PDO $pdo, string $kind, array $out, string $from, string $to, string $status, string $q): array {
    $tz = new DateTimeZone('Africa/Kampala'); $utc = new DateTimeZone('UTC');
    $a = (new DateTime($from . ' 00:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s'); $b = (new DateTime($to . ' 23:59:59', $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
    $loc = fn($v) => $v ? (new DateTime((string) $v, $utc))->setTimezone($tz)->format('j M Y H:i') : '';
    if ($kind === 'requests') {
        $w = 'created_at >= ? AND created_at <= ?'; $args = [$a, $b];
        if (isset(STATUSES[$status])) { $w .= ' AND status = ?'; $args[] = $status; }
        if ($q !== '') { $w .= ' AND (request_id LIKE ? OR client_name LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
        $s = $pdo->prepare("SELECT * FROM requests WHERE $w ORDER BY id DESC LIMIT 5000"); $s->execute($args); $cnt = []; $cat = ['tax' => 0, 'audit' => 0];
        $out['columns'] = [['Request', 'no', 't', 92], ['Received', 'date', 't', 100], ['Type', 'type', 't', 50], ['Client', 'client', 't', 190], ['Services', 'svc', 't', 230], ['Status', 'status', 't', 90], ['Assigned', 'asg', 't', 80]];
        $adm = []; foreach ($pdo->query('SELECT id, full_name FROM admins') as $r) $adm[(int) $r['id']] = $r['full_name'];
        foreach ($s->fetchAll() as $r) { $cnt[$r['status']] = ($cnt[$r['status']] ?? 0) + 1; $cat[$r['category']] = ($cat[$r['category']] ?? 0) + 1;
            $out['rows'][] = ['no' => $r['request_id'], 'date' => $loc($r['created_at']), 'type' => strtoupper($r['category']), 'client' => $r['client_name'], 'svc' => mb_strimwidth((string) $r['service_summary'], 0, 120, '...'), 'status' => STATUSES[$r['status']] ?? $r['status'], 'asg' => $adm[(int) $r['assigned_to']] ?? ''];
        }
        $out['summary'][] = ['Requests received', (string) count($out['rows'])]; $out['summary'][] = ['Tax / Audit', $cat['tax'] . ' / ' . $cat['audit']];
        foreach (['new', 'in_review', 'quoted', 'in_progress', 'completed'] as $k) $out['summary'][] = [STATUSES[$k], (string) ($cnt[$k] ?? 0)];
        return $out;
    }
    $w = 'created_at >= ? AND created_at <= ?'; $args = [$a, $b];
    if (isset(QUOTE_STATUSES[$status])) { $w .= ' AND status = ?'; $args[] = $status; }
    if ($q !== '') { $w .= ' AND (quote_no LIKE ? OR client_name LIKE ? OR company LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
    $s = $pdo->prepare("SELECT * FROM quotes WHERE $w ORDER BY id DESC LIMIT 5000"); $s->execute($args); $rows = $s->fetchAll();
    if ($kind === 'rfq_list') {
        $out['columns'] = [['Request', 'no', 't', 92], ['Received', 'date', 't', 100], ['Client', 'client', 't', 190], ['Service', 'svc', 't', 130], ['Status', 'status', 't', 70], ['Responded', 'resp', 't', 100], ['Cur', 'cur', 't', 30], ['Quoted amount', 'amt', 'm', 100]];
        foreach ($rows as $r) $out['rows'][] = ['no' => $r['quote_no'], 'date' => $loc($r['created_at']), 'client' => (string) ($r['company'] ?: $r['client_name']), 'svc' => quote_service_title((string) $r['service']), 'status' => QUOTE_STATUSES[$r['status']] ?? $r['status'], 'resp' => $loc($r['responded_at']), 'cur' => (string) ($r['currency'] ?: 'UGX'), 'amt' => $r['quoted_amount'] !== null ? db_cents($r['quoted_amount']) : 0];
    } else {
        $g = []; foreach (quote_services() as $k => $sv) $g[$k] = ['svc' => $sv['title'], 'recv' => 0, 'open' => 0, 'resp' => 0, 'won' => 0, 'lost' => 0, 'canc' => 0, 'rate' => '', 'cur' => 'UGX', 'val' => 0];
        $hrs = []; $wonv = [];
        foreach ($rows as $r) { $k = $r['service']; $g[$k] ??= ['svc' => quote_service_title($k), 'recv' => 0, 'open' => 0, 'resp' => 0, 'won' => 0, 'lost' => 0, 'canc' => 0, 'rate' => '', 'cur' => 'UGX', 'val' => 0];
            $g[$k]['recv']++; $st = $r['status'];
            if (in_array($st, ['pending', 'in_review'], true)) $g[$k]['open']++; elseif ($st === 'responded') $g[$k]['resp']++; elseif (in_array($st, ['approved', 'completed'], true)) { $g[$k]['won']++; if (($r['currency'] ?: 'UGX') === 'UGX') $g[$k]['val'] += db_cents($r['quoted_amount']); else $wonv[$r['currency']] = ($wonv[$r['currency']] ?? 0) + db_cents($r['quoted_amount']); } elseif ($st === 'rejected') $g[$k]['lost']++; else $g[$k]['canc']++;
            if ($r['responded_at']) $hrs[] = (strtotime((string) $r['responded_at'] . ' UTC') - strtotime((string) $r['created_at'] . ' UTC')) / 3600;
        }
        $tot = ['recv' => 0, 'open' => 0, 'resp' => 0, 'won' => 0, 'lost' => 0, 'canc' => 0, 'val' => 0];
        foreach ($g as &$x) { $dec = $x['won'] + $x['lost']; $x['rate'] = $dec ? round($x['won'] / $dec * 100) . '%' : '-'; foreach ($tot as $k => $_) $tot[$k] += $x[$k]; } unset($x);
        $out['columns'] = [['Service', 'svc', 't', 190], ['Received', 'recv', 'n', 62], ['Open', 'open', 'n', 50], ['Responded', 'resp', 'n', 62], ['Won', 'won', 'n', 46], ['Declined', 'lost', 'n', 56], ['Cancelled', 'canc', 'n', 58], ['Win rate', 'rate', 't', 56], ['Cur', 'cur', 't', 30], ['Value won', 'val', 'm', 110]];
        $out['rows'] = array_values($g);
        $dec = $tot['won'] + $tot['lost']; $out['summary'][] = ['Requests received', (string) $tot['recv']]; $out['summary'][] = ['Open / Responded', $tot['open'] . ' / ' . $tot['resp']]; $out['summary'][] = ['Won / Declined', $tot['won'] . ' / ' . $tot['lost']];
        $out['summary'][] = ['Win rate (decided)', $dec ? round($tot['won'] / $dec * 100) . '%' : '-']; $out['summary'][] = ['Average time to respond', $hrs ? (round(array_sum($hrs) / count($hrs), 1)) . ' hours' : '-'];
        $out['summary'][] = ['Value won', cur_totals(['UGX' => $tot['val']] + $wonv)];
        return $out;
    }
    $cn = []; foreach ($rows as $r) $cn[$r['status']] = ($cn[$r['status']] ?? 0) + 1;
    $out['summary'][] = ['Requests received', (string) count($rows)]; foreach (['pending', 'in_review', 'responded', 'approved', 'rejected'] as $k) $out['summary'][] = [QUOTE_STATUSES[$k], (string) ($cn[$k] ?? 0)];
    return $out;
}
