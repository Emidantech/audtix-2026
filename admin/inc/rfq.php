<?php
declare(strict_types=1);
/* RFQ actions in the admin panel: permissions, "turn into a document" buttons and the RFQ PDF. */
require_once __DIR__ . '/docs.php';
require_once __DIR__ . '/pdf.php';

const RFQ_TARGETS = ['quotation' => 'Create quotation', 'proforma' => 'Create proforma invoice', 'invoice' => 'Create invoice'];

function rfq_can_view(string $kind): bool { return can($kind === 'request' ? 'requests.view' : 'quotes.view'); }
function rfq_view_url(string $kind, int $id): string { return ($kind === 'request' ? 'request.php?id=' : 'quote.php?id=') . $id; }
function rfq_event(PDO $pdo, string $kind, int $id, ?int $adminId, string $type, string $note): void {
    if ($kind === 'quote') quote_event($pdo, $id, $adminId, $type, $note);
    else $pdo->prepare('INSERT INTO request_events (request_pk, admin_id, event_type, note, created_at) VALUES (?,?,?,?,?)')->execute([$id, $adminId, $type, mb_substr($note, 0, 2000), now()]);
}
/** Buttons that turn the RFQ into a document, plus the PDF. $cls: button size classes. */
function rfq_buttons(array $admin, string $kind, int $id, string $cls = 'btn btn-sm'): string {
    $o = '<div class="d-flex flex-wrap gap-2">';
    foreach (RFQ_TARGETS as $t => $label) if (dcan('doc.create', $admin, $t))
        $o .= '<form method="post" action="rfq.php" class="m-0">' . csrf_field() . '<input type="hidden" name="kind" value="' . h($kind) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="make"><input type="hidden" name="type" value="' . $t . '"><button class="' . $cls . ' btn-' . ($t === 'quotation' ? 'primary' : 'outline-primary') . '"><i class="bi bi-file-earmark-plus"></i> ' . h($label) . '</button></form>';
    $o .= '<a class="' . $cls . ' btn-outline-secondary" href="rfq_pdf.php?kind=' . h($kind) . '&id=' . $id . '"><i class="bi bi-file-earmark-pdf"></i> Save as PDF</a></div>';
    return $o;
}

function render_rfq_pdf(array $r): string {
    $p = new SimplePdf(false, 'RFQ ' . $r['ref']);
    $M = 40; $W = $p->W; $H = $p->H; $CW = $W - 2 * $M; $ink = [16, 32, 47]; $mut = [90, 107, 125]; $line = [214, 224, 236]; $band = [241, 245, 250];
    $co = doc_setting('company_name', 'Audtix Systems Ltd');
    $footer = function () use ($p, $M, $W, $H, $mut, $line, $co) {
        $p->line($M, $H - 44, $W - $M, $H - 44, 0.5, $line);
        $p->text($M, $H - 30, $co . '  |  ' . doc_setting('company_email') . '  |  ' . doc_setting('company_phone'), 8, false, $mut);
        $p->text($W - $M - 100, $H - 30, 'Page {{PAGE}} of {{PAGES}}', 8, false, $mut, 'r', 100);
    };
    $footer();
    $y = 38; $img = $p->image(company_logo_path(), $M, $y, 130, 50); $ly = $y + ($img ? $img[1] : 0) + 14;
    $p->text($M, $ly, $co, 11, true, $ink); $ly += 13;
    foreach (company_lines() as $l) foreach ($p->wrap($l, 230, 8.5) as $wl) { $p->text($M, $ly, $wl, 8.5, false, $mut); $ly += 11; }
    $rx = $W - $M - 215; $ry = 62;
    $p->text($rx, $ry, 'REQUEST FOR QUOTATION', 14, true, $ink); $ry += 18;
    foreach ([['Reference', $r['ref']], ['Received', eat($r['received'])], ['Service', $r['title']], ['Status', ucfirst(str_replace('_', ' ', $r['status']))]] as $m) {
        $vl = $p->wrap((string) $m[1], 120, 9); $p->text($rx, $ry, $m[0] . ':', 9, true, $ink);
        foreach ($vl as $k => $v) { $p->text($rx + 70, $ry, $v, 9, false, $ink); if ($k < count($vl) - 1) $ry += 11.5; }
        $ry += 12.5;
    }
    $y = max($ly, $ry) + 16;
    $p->text($M, $y + 8, 'From', 8.5, false, $mut); $y += 22;
    foreach ($p->wrap($r['name'], $CW, 12.5, true) as $l) { $p->text($M, $y, $l, 12.5, true, $ink); $y += 15; }
    foreach (array_filter([$r['contact'] ? 'Contact: ' . $r['contact'] : '', $r['email'], $r['phone'], $r['location'], $r['tin'] ? 'TIN: ' . $r['tin'] : '']) as $l) { $p->text($M, $y, (string) $l, 9, false, $ink); $y += 11.5; }
    $y += 12;
    $last = null; $lw = 150;
    foreach ($r['rows'] as $row) {
        $sec = (string) ($row[2] ?? ''); $vals = $p->wrap((string) $row[1], $CW - $lw - 8, 9); $need = max(1, count($vals)) * 11.5 + 8 + ($sec !== $last && $sec !== '' ? 26 : 0);
        if ($y + $need > $H - 60) { $p->addPage(); $footer(); $y = 50; $last = null; }
        if ($sec !== '' && $sec !== $last) { $p->rect($M, $y, $CW, 18, $band); $p->text($M + 6, $y + 12.5, $sec, 9, true, $ink); $y += 18 + 8; $last = $sec; }
        $p->text($M, $y + 8, (string) $row[0], 9, false, $mut);
        foreach ($vals as $i => $v) $p->text($M + $lw, $y + 8 + $i * 11.5, $v, 9, false, $ink);
        $y += max(1, count($vals)) * 11.5 + 6; $p->line($M, $y, $M + $CW, $y, 0.3, $line); $y += 4;
    }
    return $p->output();
}
