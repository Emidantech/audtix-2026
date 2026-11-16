<?php
declare(strict_types=1);
/* A small PDF writer: A4 pages, Helvetica / Helvetica-Bold, text, lines, rectangles, images, "Page x of y".
   No libraries needed. Coordinates are in points from the TOP-LEFT of the page. */

final class SimplePdf {
    public float $W = 595.28, $H = 841.89;
    private array $pages = [];       // content streams
    private int $cur = -1;
    private array $images = [];      // name => [w,h,jpegdata,comps]
    private array $w;                // font widths
    private array $pageImages = [];  // page index => [names]
    private string $title = '';

    public function __construct(bool $landscape = false, string $title = '') {
        $this->w = require __DIR__ . '/helv.php';
        if ($landscape) { [$this->W, $this->H] = [$this->H, $this->W]; }
        $this->title = $title;
        $this->addPage();
    }
    public function addPage(): void { $this->pages[] = ''; $this->cur = count($this->pages) - 1; $this->pageImages[$this->cur] = []; }
    public function pageCount(): int { return count($this->pages); }

    private static function enc(string $s): string {
        $s = str_replace(["\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{2022}", "\u{00A0}"], ["'", "'", '"', '"', '-', '-', '*', ' '], $s);
        $o = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        if ($o === false) $o = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        return (string) $o;
    }
    private static function esc(string $s): string { return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $s); }
    private static function n(float $v): string { return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0'; }
    private static function rgb(array $c): string { return self::n($c[0] / 255) . ' ' . self::n($c[1] / 255) . ' ' . self::n($c[2] / 255); }

    public function width(string $s, float $size, bool $bold = false): float {
        $t = $this->w[$bold ? 'b' : 'r']; $sum = 0; $e = self::enc($s);
        for ($i = 0, $n = strlen($e); $i < $n; $i++) $sum += $t[ord($e[$i])] ?? 600;
        return $sum * $size / 1000;
    }
    /** Greedy word wrap to a width. Long words are broken. */
    public function wrap(string $s, float $maxw, float $size, bool $bold = false): array {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $s) as $para) {
            $line = '';
            foreach (preg_split('/\s+/', trim($para)) as $word) {
                if ($word === '') continue;
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($this->width($try, $size, $bold) <= $maxw) { $line = $try; continue; }
                if ($line !== '') { $out[] = $line; $line = ''; }
                while ($this->width($word, $size, $bold) > $maxw && mb_strlen($word) > 1) {
                    $k = mb_strlen($word);
                    while ($k > 1 && $this->width(mb_substr($word, 0, $k), $size, $bold) > $maxw) $k--;
                    $out[] = mb_substr($word, 0, $k); $word = mb_substr($word, $k);
                }
                $line = $word;
            }
            $out[] = $line;
        }
        return $out ?: [''];
    }
    private function add(string $c): void { $this->pages[$this->cur] .= $c . "\n"; }

    /** $y is the text BASELINE measured from the top. align: l, r, c (within $w starting at $x). */
    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, array $color = [16, 32, 47], string $align = 'l', float $w = 0): void {
        if ($s === '') return;
        $tw = $this->width($s, $size, $bold);
        if ($align === 'r') $x = $x + $w - $tw; elseif ($align === 'c') $x = $x + ($w - $tw) / 2;
        $this->add('BT /' . ($bold ? 'F2' : 'F1') . ' ' . self::n($size) . ' Tf ' . self::rgb($color) . ' rg ' . self::n($x) . ' ' . self::n($this->H - $y) . ' Td (' . self::esc(self::enc($s)) . ') Tj ET');
    }
    /** Rotated text (watermark), centred on the page. */
    public function watermark(string $s, float $size, array $color): void {
        $a = deg2rad(40); $c = cos($a); $sn = sin($a); $tw = $this->width($s, $size, true);
        $cx = $this->W / 2; $cy = $this->H / 2;
        $x = $cx - ($tw / 2) * $c; $y = $cy - ($tw / 2) * $sn;
        $this->add('BT /F2 ' . self::n($size) . ' Tf ' . self::rgb($color) . ' rg ' . self::n($c) . ' ' . self::n($sn) . ' ' . self::n(-$sn) . ' ' . self::n($c) . ' ' . self::n($x) . ' ' . self::n($y) . ' Tm (' . self::esc(self::enc($s)) . ') Tj ET');
    }
    public function line(float $x1, float $y1, float $x2, float $y2, float $lw = 0.5, array $color = [200, 210, 222]): void {
        $this->add(self::n($lw) . ' w ' . self::rgb($color) . ' RG ' . self::n($x1) . ' ' . self::n($this->H - $y1) . ' m ' . self::n($x2) . ' ' . self::n($this->H - $y2) . ' l S');
    }
    public function rect(float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $lw = 0.5): void {
        $op = ($fill && $stroke) ? 'B' : ($fill ? 'f' : 'S');
        $s = ($fill ? self::rgb($fill) . ' rg ' : '') . ($stroke ? self::rgb($stroke) . ' RG ' . self::n($lw) . ' w ' : '');
        $this->add($s . self::n($x) . ' ' . self::n($this->H - $y - $h) . ' ' . self::n($w) . ' ' . self::n($h) . ' re ' . $op);
    }
    /** Draws an image file (PNG/JPEG/GIF/WebP) scaled to fit inside $w x $h, aligned left/top. Returns drawn [w,h] or null. */
    public function image(string $path, float $x, float $y, float $w, float $h): ?array {
        if (!is_file($path) || !function_exists('imagecreatefromstring')) return null;
        $key = md5($path . filemtime($path));
        if (!isset($this->images[$key])) {
            $im = @imagecreatefromstring((string) file_get_contents($path)); if (!$im) return null;
            $iw = imagesx($im); $ih = imagesy($im);
            $bg = imagecreatetruecolor($iw, $ih); imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255)); imagecopy($bg, $im, 0, 0, 0, 0, $iw, $ih);
            ob_start(); imagejpeg($bg, null, 92); $jpg = (string) ob_get_clean(); imagedestroy($im); imagedestroy($bg);
            $this->images[$key] = [$iw, $ih, $jpg];
        }
        [$iw, $ih] = $this->images[$key];
        $r = min($w / $iw, $h / $ih); $dw = $iw * $r; $dh = $ih * $r;
        $this->pageImages[$this->cur][$key] = true;
        $this->add('q ' . self::n($dw) . ' 0 0 ' . self::n($dh) . ' ' . self::n($x) . ' ' . self::n($this->H - $y - $dh) . ' cm /I' . array_search($key, array_keys($this->images), true) . ' Do Q');
        return [$dw, $dh];
    }

    public function output(): string {
        $total = count($this->pages); $objs = []; $o = 0;
        $new = function () use (&$o) { return ++$o; };
        $catalog = $new(); $pagesObj = $new(); $f1 = $new(); $f2 = $new();
        $imgIds = []; $idx = 0;
        foreach ($this->images as $key => $im) { $imgIds[$key] = [$new(), $idx++]; }
        $pageIds = []; $contentIds = [];
        for ($i = 0; $i < $total; $i++) { $pageIds[$i] = $new(); $contentIds[$i] = $new(); }
        $info = $new();
        $objs[$catalog] = "<< /Type /Catalog /Pages $pagesObj 0 R >>";
        $objs[$pagesObj] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn($i) => $i . ' 0 R', $pageIds)) . '] /Count ' . $total . ' >>';
        foreach ([$f1 => 'Helvetica', $f2 => 'Helvetica-Bold'] as $id => $nm) $objs[$id] = "<< /Type /Font /Subtype /Type1 /BaseFont /$nm /Encoding /WinAnsiEncoding >>";
        foreach ($this->images as $key => $im) {
            $objs[$imgIds[$key][0]] = ['dict' => '<< /Type /XObject /Subtype /Image /Width ' . $im[0] . ' /Height ' . $im[1] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode', 'data' => $im[2]];
        }
        for ($i = 0; $i < $total; $i++) {
            $xo = ''; foreach (array_keys($this->pageImages[$i]) as $k) $xo .= '/I' . $imgIds[$k][1] . ' ' . $imgIds[$k][0] . ' 0 R ';
            $objs[$pageIds[$i]] = "<< /Type /Page /Parent $pagesObj 0 R /MediaBox [0 0 " . self::n($this->W) . ' ' . self::n($this->H) . "] /Resources << /Font << /F1 $f1 0 R /F2 $f2 0 R >>" . ($xo ? " /XObject << $xo>>" : '') . " >> /Contents {$contentIds[$i]} 0 R >>";
            $c = str_replace(['{{PAGE}}', '{{PAGES}}'], [(string) ($i + 1), (string) $total], $this->pages[$i]);
            $objs[$contentIds[$i]] = ['dict' => '<< ', 'data' => $c, 'z' => true];
        }
        $objs[$info] = '<< /Title (' . self::esc(self::enc($this->title)) . ') /Producer (Audtix Admin) /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>';
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offs = [];
        ksort($objs);
        foreach ($objs as $id => $ob) {
            $offs[$id] = strlen($out);
            if (is_array($ob)) {
                $data = $ob['data'];
                if (!empty($ob['z']) && function_exists('gzcompress')) { $data = gzcompress($data, 6); $dict = '<< /Filter /FlateDecode'; } else $dict = $ob['dict'];
                $out .= "$id 0 obj\n$dict /Length " . strlen($data) . " >>\nstream\n" . $data . "\nendstream\nendobj\n";
            } else $out .= "$id 0 obj\n$ob\nendobj\n";
        }
        $xref = strlen($out); $n = $o + 1;
        $out .= "xref\n0 $n\n0000000000 65535 f \n";
        for ($i = 1; $i <= $o; $i++) $out .= sprintf("%010d 00000 n \n", $offs[$i]);
        $out .= "trailer\n<< /Size $n /Root $catalog 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF";
        return $out;
    }
}
