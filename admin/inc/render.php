<?php
declare(strict_types=1);
/* Draws a document as HTML (view, preview, print) and as a PDF. Both follow the same layout. */

function doc_meta_rows(array $d, array $cx): array {
    $T = DOC_TYPES[$d['doc_type']]; $rows = [];
    $rows[] = [$T['label'] . ' No', $d['doc_no']];
    $rows[] = ['Date', fmt_date($d['doc_date'])];
    if ($T['due'] !== '' && $d['due_date']) $rows[] = [$T['due'], fmt_date($d['due_date'])];
    if ($cx['parent']) $rows[] = [$d['doc_type'] === 'receipt' ? 'Invoice' : 'Based on', $cx['parent']['doc_no']];
    if ($d['payment_method']) $rows[] = ['Payment method', $d['payment_method']];
    if ($d['payment_ref']) $rows[] = ['Payment ref', $d['payment_ref']];
    if ($d['salesperson']) $rows[] = ['Salesperson', $d['salesperson']];
    if ($d['reference_text'] && $d['doc_type'] !== 'receipt') $rows[] = ['Reference', $d['reference_text']];
    if ($d['customer_tin']) $rows[] = ['Customer TIN', $d['customer_tin']];
    if ($d['currency'] !== 'UGX') $rows[] = ['Currency', $d['currency']];
    return $rows;
}

/** Totals block rows as [label, cents, bold]. */
function doc_totals(array $d, array $cx): array {
    $c = fn($k) => db_cents($d[$k]); $r = [];
    $hasDisc = $c('discount_total') > 0; $hasTax = $c('tax_total') > 0;
    if ($d['doc_type'] === 'receipt') {
        if ($cx['parent']) { $r[] = ['Invoice total', $cx['inv_total'], false]; $r[] = ['Previously paid', $cx['inv_prior'], false]; }
        $r[] = ['Amount received', $c('total'), true];
        if ($cx['parent']) $r[] = ['Balance remaining', $cx['balance'], false];
        return $r;
    }
    if ($hasDisc || $hasTax) { $r[] = ['Subtotal', $c('subtotal'), false]; if ($hasDisc) $r[] = ['Discount', -$c('discount_total'), false]; if ($hasTax) $r[] = ['VAT / tax', $c('tax_total'), false]; }
    $r[] = ['Total', $c('total'), true];
    if ($d['doc_type'] === 'invoice') {
        $r[] = ['Paid', $cx['paid'], false]; $r[] = ['Balance due', $cx['balance'], false];
        if ($cx['prev_balance'] > 0) { $r[] = ['Previous balance', $cx['prev_balance'], false]; $r[] = ['Total due', $cx['balance'] + $cx['prev_balance'], true]; }
    }
    return $r;
}
function sig_boxes(array $d): array {
    switch ($d['doc_type']) {
        case 'receipt': return ['Received by (Audtix)', 'Authorized by', ''];
        case 'budget': return ['Prepared by', 'Approved by', ''];
        case 'requisition': return ['Requested by', 'Authorized by', 'Received by'];
        default: return ['Prepared by', 'Authorized by', $d['doc_type'] === 'invoice' ? 'Customer acknowledgement' : 'Customer acceptance'];
    }
}

/* ===================== HTML ===================== */
function render_doc_html(array $d, array $cx): string {
    $T = DOC_TYPES[$d['doc_type']]; $cur = $d['currency']; $o = '';
    $o .= '<div class="sheet' . ($d['status'] !== 'posted' ? ' is-' . h($d['status']) : '') . '">';
    if ($d['status'] === 'draft') $o .= '<div class="wm">DRAFT</div>'; elseif ($d['status'] === 'cancelled') $o .= '<div class="wm bad">CANCELLED</div>';
    $o .= '<div class="dh"><div class="co"><img class="colo" src="' . h(company_logo_url()) . '" alt=""><b>' . h(setting('company_name', 'Audtix Systems Ltd')) . '</b>';
    foreach (company_lines() as $l) $o .= '<span>' . h($l) . '</span>';
    $o .= '</div><div class="meta"><h1>' . h($T['title']) . '</h1><dl>';
    foreach (doc_meta_rows($d, $cx) as $r) $o .= '<dt>' . h($r[0]) . '</dt><dd>' . h($r[1]) . '</dd>';
    $o .= '</dl></div></div>';
    $o .= '<div class="bill">' . (logo_url($d['customer_logo']) ? '<img class="cl" src="' . h(logo_url($d['customer_logo'])) . '" alt="">' : '') . '<div><small>' . h($T['bill']) . '</small><b>' . h($d['customer_name']) . '</b>';
    foreach (array_filter([$d['customer_contact'] ? 'Attn: ' . $d['customer_contact'] : '', $d['customer_address'], $d['customer_phone'], $d['customer_email'], $d['customer_tin'] ? 'TIN: ' . $d['customer_tin'] : '']) as $l) $o .= '<span>' . h($l) . '</span>';
    $o .= '</div></div>';
    $o .= '<div class="tw"><table class="lines"><thead><tr><th class="n">#</th><th>Description</th><th class="r">Qty</th><th>Unit</th><th class="r">Unit price</th><th class="r">Discount</th><th class="r">Tax %</th><th class="r">Total</th></tr></thead><tbody>';
    foreach ($d['lines'] as $i => $l) {
        $o .= '<tr><td class="n">' . ($i + 1) . '</td><td>' . nl2br(h($l['description'])) . '</td><td class="r">' . h(fmt_qty($l['quantity'])) . '</td><td>' . h($l['unit']) . '</td><td class="r">' . money(db_cents($l['unit_price']), $cur) . '</td><td class="r">' . money(db_cents($l['discount']), $cur) . '</td><td class="r">' . h(rtrim(rtrim(number_format((float) $l['tax_rate'], 2), '0'), '.')) . '%</td><td class="r">' . money(db_cents($l['line_total']), $cur) . '</td></tr>';
    }
    $o .= '</tbody></table></div><div class="tot"><table>';
    foreach (doc_totals($d, $cx) as $r) $o .= '<tr' . ($r[2] ? ' class="b"' : '') . '><td>' . h($r[0]) . '</td><td class="r">' . ($r[1] < 0 ? '-' : '') . money(abs($r[1]), $cur) . ' ' . h($cur) . '</td></tr>';
    $o .= '</table></div>';
    $o .= '<div class="words"><small>Amount in words</small>' . h(amount_in_words(db_cents($d['total']), $cur)) . '</div>';
    if ($d['status'] === 'cancelled') $o .= '<div class="cancelled">Cancelled on ' . h(eat($d['cancelled_at'])) . '. Reason: ' . h($d['cancel_reason']) . '</div>';
    if ($d['notes']) $o .= '<div class="blk"><small>Notes</small>' . nl2br(h($d['notes'])) . '</div>';
    if (in_array($d['doc_type'], ['invoice', 'proforma', 'quotation'], true) && setting('bank_details')) $o .= '<div class="blk"><small>Payment details</small>' . nl2br(h(setting('bank_details'))) . '</div>';
    if ($d['terms']) $o .= '<div class="blk"><small>Terms and conditions</small>' . nl2br(h($d['terms'])) . '</div>';
    $o .= '<div class="sigs">';
    $names = [$cx['prepared_by'], $cx['authorized_by'], ''];
    foreach (sig_boxes($d) as $i => $lab) { if ($lab === '') continue; $o .= '<div><span class="sl"></span><b>' . h($lab) . '</b>' . ($names[$i] ? '<em>' . h($names[$i]) . '</em>' : '') . '<i>Signature and date</i></div>'; }
    $o .= '</div><div class="foot">' . h(setting('company_name', 'Audtix Systems Ltd')) . ' &middot; ' . h(setting('company_email')) . ' &middot; ' . h(setting('company_phone')) . '</div></div>';
    return $o;
}

/* ===================== PDF ===================== */
function render_doc_pdf(array $d, array $cx): string {
    $T = DOC_TYPES[$d['doc_type']]; $cur = $d['currency'];
    $p = new SimplePdf(false, $T['label'] . ' ' . $d['doc_no']);
    $M = 40; $W = $p->W; $H = $p->H; $CW = $W - 2 * $M;
    $ink = [16, 32, 47]; $mut = [90, 107, 125]; $line = [214, 224, 236]; $band = [241, 245, 250]; $blue = [22, 100, 192];
    $wm = function () use ($p, $d) { if ($d['status'] === 'draft') $p->watermark('DRAFT', 110, [236, 240, 245]); elseif ($d['status'] === 'cancelled') $p->watermark('CANCELLED', 84, [250, 226, 224]); };
    $footer = function () use ($p, $M, $W, $H, $mut, $line) {
        $p->line($M, $H - 44, $W - $M, $H - 44, 0.5, $line);
        $p->text($M, $H - 30, setting('company_name', 'Audtix Systems Ltd') . '  |  ' . setting('company_email') . '  |  ' . setting('company_phone'), 8, false, $mut);
        $p->text($W - $M - 100, $H - 30, 'Page {{PAGE}} of {{PAGES}}', 8, false, $mut, 'r', 100);
    };
    $wm(); $footer();
    // header: logo + company (left), title + meta (right)
    $y = 38; $img = $p->image(company_logo_path(), $M, $y, 130, 50); $ly = $y + ($img ? $img[1] : 0) + 14;
    $p->text($M, $ly, setting('company_name', 'Audtix Systems Ltd'), 11, true, $ink); $ly += 13;
    foreach (company_lines() as $l) foreach ($p->wrap($l, 230, 8.5) as $wl) { $p->text($M, $ly, $wl, 8.5, false, $mut); $ly += 11; }
    $rx = $W - $M - 215; $ry = 62;
    $p->text($rx, $ry, $T['title'], strlen($T['title']) > 12 ? 17 : 21, true, $ink); $ry += 18;
    foreach (doc_meta_rows($d, $cx) as $r) {
        $vl = $p->wrap((string) $r[1], 120, 9); $p->text($rx, $ry, $r[0] . ':', 9, true, $ink);
        foreach ($vl as $k => $v) { $p->text($rx + 92, $ry, $v, 9, false, $ink); if ($k < count($vl) - 1) $ry += 11.5; }
        $ry += 12.5;
    }
    $y = max($ly, $ry) + 14;
    // bill to
    $bx = $M; $bh = 0;
    if (logo_url($d['customer_logo']) && is_file(logo_dir() . '/' . $d['customer_logo'])) { $ci = $p->image(logo_dir() . '/' . $d['customer_logo'], $M, $y, 78, 42); if ($ci) { $bx = $M + 90; $bh = $ci[1]; } }
    $by = $y + 8; $p->text($bx, $by, $T['bill'], 8.5, false, $mut); $by += 14;
    foreach ($p->wrap($d['customer_name'], 300, 12.5, true) as $l) { $p->text($bx, $by, $l, 12.5, true, $ink); $by += 15; }
    foreach (array_filter([$d['customer_contact'] ? 'Attn: ' . $d['customer_contact'] : '', $d['customer_address'], $d['customer_phone'], $d['customer_email'], $d['customer_tin'] ? 'TIN: ' . $d['customer_tin'] : '']) as $l)
        foreach ($p->wrap($l, 300, 9) as $wl) { $p->text($bx, $by, $wl, 9, false, $ink); $by += 11.5; }
    $y = max($by, $y + $bh) + 14;
    // table
    $cols = [['#', 20, 'l'], ['Description', 0, 'l'], ['Qty', 40, 'r'], ['Unit', 40, 'l'], ['Unit price', 64, 'r'], ['Discount', 54, 'r'], ['Tax %', 36, 'r'], ['Total', 70, 'r']];
    $fixed = 0; foreach ($cols as $c) $fixed += $c[1]; $cols[1][1] = $CW - $fixed; $pad = 4;
    $xs = []; $x = $M; foreach ($cols as $c) { $xs[] = $x; $x += $c[1]; }
    $head = function (float $yy) use ($p, $cols, $xs, $M, $CW, $band, $ink, $line, $pad) {
        $p->rect($M, $yy, $CW, 20, $band); foreach ($cols as $i => $c) { $p->text($xs[$i] + $pad, $yy + 13.5, $c[0], 8.5, true, $ink, $c[2] === 'r' ? 'r' : 'l', $c[1] - 2 * $pad); }
        $p->line($M, $yy + 20, $M + $CW, $yy + 20, 0.6, [180, 195, 212]); return $yy + 20;
    };
    $y = $head($y);
    foreach ($d['lines'] as $i => $l) {
        $dl = $p->wrap($l['description'], $cols[1][1] - 2 * $pad, 9); $rh = max(20, count($dl) * 11.5 + 9);
        if ($y + $rh > $H - 70) { $p->addPage(); $wm(); $footer(); $y = $head(46); }
        $vals = [(string) ($i + 1), '', fmt_qty($l['quantity']), (string) $l['unit'], money(db_cents($l['unit_price']), $cur), money(db_cents($l['discount']), $cur), rtrim(rtrim(number_format((float) $l['tax_rate'], 2), '0'), '.') . '%', money(db_cents($l['line_total']), $cur)];
        foreach ($cols as $k => $c) { if ($k === 1) continue; $p->text($xs[$k] + $pad, $y + 14, mb_strimwidth($vals[$k], 0, 40, '...'), 9, false, $ink, $c[2] === 'r' ? 'r' : 'l', $c[1] - 2 * $pad); }
        foreach ($dl as $j => $t) $p->text($xs[1] + $pad, $y + 14 + $j * 11.5, $t, 9, false, $ink);
        $y += $rh; $p->line($M, $y, $M + $CW, $y, 0.4, $line);
    }
    // totals + words need room; start a new page when short
    $tot = doc_totals($d, $cx); $need = 24 + count($tot) * 17 + 60;
    if ($y + $need > $H - 70) { $p->addPage(); $wm(); $footer(); $y = 46; }
    $y += 14; $tx = $W - $M - 250; $ty = $y;
    foreach ($tot as $r) {
        $b = $r[2]; $p->text($tx, $ty + 11, $r[0], $b ? 10.5 : 9.5, $b, $ink, 'r', 120);
        $p->text($tx + 124, $ty + 11, ($r[1] < 0 ? '-' : '') . money(abs($r[1]), $cur) . ' ' . $cur, $b ? 10.5 : 9.5, $b, $ink, 'r', 126);
        if ($b) $p->line($tx + 20, $ty + 15, $W - $M, $ty + 15, 0.8, $ink);
        $ty += 17;
    }
    // amount in words, left of totals
    $wy = $y; $p->text($M, $wy + 9, 'Amount in words', 8.5, false, $mut); $wy += 21;
    foreach ($p->wrap(amount_in_words(db_cents($d['total']), $cur), $tx - $M - 20, 10, true) as $wl) { $p->text($M, $wy, $wl, 10, true, $ink); $wy += 13; }
    $y = max($ty, $wy) + 12;
    $block = function (string $label, string $text) use (&$y, $p, $M, $CW, $H, $mut, $ink, $wm, $footer) {
        $lines = $p->wrap($text, $CW, 8.5); $need = 16 + count($lines) * 11;
        if ($y + $need > $H - 70) { $p->addPage(); $wm(); $footer(); $y = 46; }
        $p->text($M, $y + 8, $label, 8.5, true, $mut); $y += 20;
        foreach ($lines as $l) { $p->text($M, $y, $l, 8.5, false, $ink); $y += 11; }
        $y += 8;
    };
    if ($d['status'] === 'cancelled') $block('Cancelled', 'Cancelled on ' . eat($d['cancelled_at']) . '. Reason: ' . $d['cancel_reason']);
    if ($d['notes']) $block('Notes', $d['notes']);
    if (in_array($d['doc_type'], ['invoice', 'proforma', 'quotation'], true) && setting('bank_details')) $block('Payment details', setting('bank_details'));
    if ($d['terms']) $block('Terms and conditions', $d['terms']);
    // signatures
    if ($y + 80 > $H - 56) { $p->addPage(); $wm(); $footer(); $y = 60; }
    $sy = max($y + 30, min($H - 110, $y + 40)); $boxes = array_values(array_filter(sig_boxes($d), fn($b) => $b !== '')); $names = [$cx['prepared_by'], $cx['authorized_by'], ''];
    $n = count($boxes); $gap = 24; $bw = ($CW - $gap * ($n - 1)) / max(1, $n);
    foreach ($boxes as $i => $lab) {
        $bx = $M + $i * ($bw + $gap); $p->line($bx, $sy, $bx + $bw, $sy, 0.6, $ink);
        $p->text($bx, $sy + 12, $lab, 9, true, $ink); if (!empty($names[$i])) $p->text($bx, $sy + 24, $names[$i], 9, false, $ink);
        $p->text($bx, $sy + ($names[$i] ?? '' ? 36 : 24), 'Signature and date', 7.5, false, $mut);
    }
    return $p->output();
}
