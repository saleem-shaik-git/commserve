<?php
declare(strict_types=1);

/**
 * Minimal pure-PHP PDF generator - no external dependencies.
 * Supports Helvetica and Helvetica-Bold, text, lines, multi-page.
 */
final class SimplePDF
{
    private array $pages = [];
    private string $current = '';
    private float $y = 800.0;
    private float $marginLeft = 40.0;
    private float $marginRight = 40.0;
    private float $pageWidth = 595.0; // A4
    private float $pageHeight = 842.0;
    private string $font = 'F1';
    private int $fontSize = 10;

    public function __construct()
    {
        $this->current = '';
        $this->y = 800.0;
    }

    private function esc(string $t): string
    {
        $t = str_replace('\\', '\\\\', $t);
        $t = str_replace('(', '\\(', $t);
        $t = str_replace(')', '\\)', $t);
        // Strip non-printable and limit to WinAnsi
        $t = preg_replace('/[^\x20-\x7E]/u', ' ', $t) ?? $t;
        return $t;
    }

    public function addPage(): void
    {
        if ($this->current !== '' || $this->y < 799) {
            $this->pages[] = $this->current;
        }
        $this->current = '';
        $this->y = 800.0;
    }

    public function setFont(string $family = 'Helvetica', string $style = '', int $size = 10): void
    {
        $this->fontSize = $size;
        $s = strtolower($style);
        $f = strtolower($family);
        $isBold = $s === 'b' || $s === 'bold' || str_contains($f, 'bold');
        $this->font = $isBold ? 'F2' : 'F1';
    }

    public function text(float $x, float $y, string $txt): void
    {
        if ($txt === '') return;
        $t = $this->esc($txt);
        // Truncate very long lines to avoid overflow
        if (strlen($t) > 200) $t = substr($t, 0, 200);
        $this->current .= "BT /{$this->font} {$this->fontSize} Tf 1 0 0 1 {$x} {$y} Tm ({$t}) Tj ET\n";
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $w = 0.5): void
    {
        $this->current .= sprintf("q %.2F w %.2F %.2F m %.2F %.2F l S Q\n", $w, $x1, $y1, $x2, $y2);
    }

    public function rect(float $x, float $y, float $w, float $h, bool $fill = false): void
    {
        $op = $fill ? 'f' : 'S';
        $this->current .= sprintf("q %.2F w %.2F %.2F %.2F %.2F re %s Q\n", 0.5, $x, $y, $w, $h, $op);
    }

    public function writeLine(string $txt, float $x = 0, int $size = 10, string $style = '', float $gap = 14.0): void
    {
        if ($x == 0) $x = $this->marginLeft;
        $this->setFont('Helvetica', $style, $size);
        $this->text($x, $this->y, $txt);
        $this->ln($gap);
    }

    public function writeLines(array $lines, float $x = 0, int $size = 10, string $style = ''): void
    {
        foreach ($lines as $l) $this->writeLine((string)$l, $x, $size, $style);
    }

    public function ln(float $h = 14.0): void
    {
        $this->y -= $h;
        if ($this->y < 50) {
            $this->addPage();
        }
    }

    public function getY(): float { return $this->y; }
    public function setY(float $y): void { $this->y = $y; }

    public function getX(): float { return $this->marginLeft; }

    public function ensureSpace(float $needed = 80.0): void
    {
        if ($this->y - $needed < 50) $this->addPage();
    }

    public function output(): string
    {
        if ($this->current !== '' || empty($this->pages)) {
            $this->pages[] = $this->current;
        }
        $pCount = count($this->pages);
        if ($pCount === 0) $pCount = 1;

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        // 1 Catalog
        $offsets[1] = strlen($pdf);
        $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

        // 2 Pages - kids placeholder, will fill after we know page obj numbers
        $kids = [];
        for ($i = 0; $i < $pCount; $i++) {
            $pageObj = 5 + $i * 2;
            $kids[] = "$pageObj 0 R";
        }
        $offsets[2] = strlen($pdf);
        $pdf .= "2 0 obj\n<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count $pCount >>\nendobj\n";

        // 3 Helvetica, 4 Helvetica-Bold
        $offsets[3] = strlen($pdf);
        $pdf .= "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
        $offsets[4] = strlen($pdf);
        $pdf .= "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj\n";

        for ($i = 0; $i < $pCount; $i++) {
            $pageObj = 5 + $i * 2;
            $contentObj = 6 + $i * 2;
            $content = $this->pages[$i] ?? '';
            if ($content === '') $content = "BT /F1 10 Tf 1 0 0 1 50 800 Tm ( ) Tj ET\n";

            $offsets[$pageObj] = strlen($pdf);
            $pdf .= "$pageObj 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $contentObj 0 R >>\nendobj\n";

            $offsets[$contentObj] = strlen($pdf);
            $pdf .= "$contentObj 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream\nendobj\n";
        }

        $objCount = 4 + $pCount * 2;
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . ($objCount + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $objCount; $i++) {
            $off = $offsets[$i] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= "trailer\n<< /Size " . ($objCount + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n$xrefPos\n%%EOF";

        return $pdf;
    }
}
