<?php
declare(strict_types=1);
/* Minimal .xlsx writer (one or more sheets, text and numbers, bold header, money format). Needs the PHP zip extension. */

function xlsx_col(int $i): string { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; }
function xlsx_x(string $s): string { return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

/** $sheets: [ ['name'=>'Invoices', 'rows'=>[[cell,...],...], 'widths'=>[..optional..]] ]
 *  A cell is a string, an int/float (number), or ['m'=>float] for a money number, or ['b'=>'text'] for bold text. First row is the header. */
function xlsx_build(array $sheets): ?string {
    if (!class_exists('ZipArchive')) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'xl'); $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) return null;
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rel = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sheets as $i => $sh) {
        $n = $i + 1; $name = xlsx_x(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $sh['name']), 0, 31));
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wb .= '<sheet name="' . $name . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $rel .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        if (!empty($sh['widths'])) { $x .= '<cols>'; foreach ($sh['widths'] as $k => $w) $x .= '<col min="' . ($k + 1) . '" max="' . ($k + 1) . '" width="' . $w . '" customWidth="1"/>'; $x .= '</cols>'; }
        $x .= '<sheetData>';
        foreach ($sh['rows'] as $r => $row) {
            $x .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($row) as $c => $cell) {
                $ref = xlsx_col($c) . ($r + 1);
                if (is_array($cell) && isset($cell['m'])) $x .= '<c r="' . $ref . '" s="2"><v>' . (float) $cell['m'] . '</v></c>';
                elseif (is_array($cell) && isset($cell['b'])) $x .= '<c r="' . $ref . '" s="1" t="inlineStr"><is><t xml:space="preserve">' . xlsx_x((string) $cell['b']) . '</t></is></c>';
                elseif (is_int($cell) || is_float($cell)) $x .= '<c r="' . $ref . '"><v>' . $cell . '</v></c>';
                elseif ($cell === null || $cell === '') continue;
                else $x .= '<c r="' . $ref . '"' . ($r === 0 ? ' s="1"' : '') . ' t="inlineStr"><is><t xml:space="preserve">' . xlsx_x((string) $cell) . '</t></is></c>';
            }
            $x .= '</row>';
        }
        $z->addFromString('xl/worksheets/sheet' . $n . '.xml', $x . '</sheetData></worksheet>');
    }
    $z->addFromString('[Content_Types].xml', $ct . '</Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
    $nsh = count($sheets);
    $z->addFromString('xl/_rels/workbook.xml.rels', $rel . '<Relationship Id="rId' . ($nsh + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>');
    $z->close(); $data = (string) file_get_contents($tmp); @unlink($tmp);
    return $data;
}
