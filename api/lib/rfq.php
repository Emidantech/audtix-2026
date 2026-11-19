<?php
declare(strict_types=1);
/* Request for quotation (RFQ) helpers shared by the public API (email button) and the admin panel.
   kind 'quote' = a website quotation request; kind 'request' = a tax or audit service request. */

function site_base_url(): string {
    $c = (string) cfg('site_url', '');
    if ($c !== '') return rtrim($c, '/');
    return 'https://audtix.com';   // never trust the Host header for links that go into emails
}
function rfq_action_url(string $kind, int $pk): string { return site_base_url() . '/admin/rfq.php?kind=' . ($kind === 'request' ? 'request' : 'quote') . '&id=' . $pk; }
/** The button put in the team email: opens the RFQ in the admin panel (after sign-in) ready to be turned into a document. */
function rfq_email_button(string $kind, int $pk): string {
    if ($pk <= 0) return '';
    return '<p style="margin:18px 0 6px"><a href="' . htmlspecialchars(rfq_action_url($kind, $pk), ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#1664c0;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:8px">Turn this RFQ into action &rarr;</a></p>'
         . '<p style="margin:0;color:#5a6b7d;font-size:12px">Opens in the Audtix admin panel (sign-in required). From there: create a quotation, proforma invoice or invoice, or save the RFQ as a PDF.</p>';
}
function rfq_email_button_text(string $kind, int $pk): string { return $pk > 0 ? "\nAction required: turn this RFQ into a quotation or invoice: " . rfq_action_url($kind, $pk) . "\n" : ''; }

/** One normalised view of an RFQ, or null. */
function rfq_load(PDO $pdo, string $kind, int $id): ?array {
    if ($kind === 'quote') {
        $s = $pdo->prepare('SELECT * FROM quotes WHERE id = ?'); $s->execute([$id]); $q = $s->fetch(); if (!$q) return null;
        $rows = json_decode((string) $q['rows_json'], true); $rows = is_array($rows) ? $rows : [];
        $svc = ''; foreach ($rows as $r) if (($r[0] ?? '') === 'Service') { $svc = (string) ($r[1] ?? ''); break; }
        return ['kind' => 'quote', 'id' => (int) $q['id'], 'ref' => $q['quote_no'], 'title' => $svc !== '' ? $svc : quote_service_title((string) $q['service']), 'summary' => (string) $q['summary'], 'status' => (string) $q['status'],
                'name' => (string) ($q['company'] ?: $q['client_name']), 'contact' => $q['company'] ? (string) $q['client_name'] : '', 'email' => (string) $q['email'], 'phone' => (string) $q['phone'], 'location' => (string) $q['location'], 'tin' => '',
                'received' => (string) $q['created_at'], 'rows' => array_values(array_filter($rows, fn($r) => is_array($r) && ($r[1] ?? '') !== '' && ($r[2] ?? '') !== 'Quotation')), 'row' => $q];
    }
    $s = $pdo->prepare('SELECT * FROM requests WHERE id = ?'); $s->execute([$id]); $r = $s->fetch(); if (!$r) return null;
    $rows = json_decode((string) $r['rows_json'], true); $rows = is_array($rows) ? $rows : [];
    $data = json_decode((string) $r['data_json'], true); $data = is_array($data) ? $data : [];
    return ['kind' => 'request', 'id' => (int) $r['id'], 'ref' => $r['request_id'], 'title' => ($r['category'] === 'tax' ? 'Tax services' : 'Audit services'), 'summary' => (string) $r['service_summary'], 'status' => (string) $r['status'],
            'name' => (string) $r['client_name'], 'contact' => (string) $r['contact_person'], 'email' => (string) $r['email'], 'phone' => (string) $r['phone'], 'location' => (string) ($data['location'] ?? ''), 'tin' => preg_replace('/\s+/', '', (string) ($data['tin'] ?? '')),
            'received' => (string) $r['created_at'], 'rows' => array_values(array_filter($rows, fn($x) => is_array($x) && ($x[1] ?? '') !== '')), 'row' => $r];
}

/** One document line per subcategory / equipment item the client picked, so each can be priced on its own.
 *  Falls back to a single descriptive line when nothing can be split. */
function rfq_lines(array $rfq): array {
    $out = []; $title = (string) $rfq['title'];
    $add = function (string $d, string $q = '1') use (&$out, $title) { $d = trim($d); if ($d !== '') $out[] = ['description' => $title . ' - ' . $d, 'quantity' => ($q !== '' && is_numeric($q) && (float) $q > 0) ? $q : '1']; };
    try {
        if ($rfq['kind'] === 'quote') {
            $svc = quote_services()[(string) ($rfq['row']['service'] ?? '')] ?? null;
            foreach (($svc['sections'] ?? []) as $sec) foreach ($sec['fields'] as $f) {
                if (!in_array($f['type'], ['checkboxes', 'items'], true)) continue;
                foreach ($rfq['rows'] as $r) {
                    if (($r[0] ?? '') !== $f['label'] || ($r[2] ?? '') !== $sec['title']) continue;
                    $txt = (string) $r[1];
                    if ($f['type'] === 'checkboxes') {
                        foreach ($f['options'] as $o) { $lab = (string) $o[1]; if ($lab !== '' && preg_match('/(^|, )' . preg_quote($lab, '/') . '(, |$)/u', $txt)) $add($lab); }
                    } else {
                        foreach (preg_split('/\R/', $txt) as $ln) { if (preg_match('/^(\d+(?:\.\d+)?) x (.+)$/u', trim($ln), $m)) $add($m[2], $m[1]); else $add($ln); }
                    }
                }
            }
        } else {
            foreach ($rfq['rows'] as $r) if (in_array($r[0] ?? '', ['Services', 'Audit services needed'], true)) foreach (preg_split('/\s*,\s*/u', (string) $r[1]) as $p) $add($p);
        }
    } catch (Throwable $e) { $out = []; }
    return $out ?: [['description' => $title . ($rfq['summary'] !== '' ? ': ' . $rfq['summary'] : ''), 'quantity' => '1']];
}
