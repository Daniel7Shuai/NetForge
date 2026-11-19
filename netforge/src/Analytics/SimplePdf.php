<?php
declare(strict_types=1);

namespace NetForge\Analytics;

/**
 * A tiny PDF writer for report exports (FR-7.5): headings, paragraphs and tables
 * on A4 pages, using the built-in Helvetica fonts. No external library needed.
 * Text is converted to Windows-1252, which covers English plus ’ – · and similar.
 */
final class SimplePdf
{
    private const W = 595.28;   // A4 in points
    private const H = 841.89;
    private const M = 42;       // margin

    private array $pages = [];
    private string $ops = '';
    private float $y = 0;
    private int $pageNo = 0;

    public function __construct(private string $title, private string $subtitle = '')
    {
        $this->newPage();
    }

    public function heading(string $text): self
    {
        $this->need(34);
        $this->y -= 14;
        $this->write($text, self::M, $this->y, 13, true);
        $this->y -= 10;
        return $this;
    }

    public function paragraph(string $text, int $size = 9): self
    {
        $maxChars = (int) floor((self::W - 2 * self::M) / ($size * 0.5));
        foreach (explode("\n", wordwrap($text, $maxChars, "\n", true)) as $line) {
            $this->need($size + 4);
            $this->y -= $size + 4;
            $this->write($line, self::M, $this->y, $size, false, [0.25, 0.25, 0.3]);
        }
        $this->y -= 4;
        return $this;
    }

    /**
     * @param string[] $columns
     * @param array<array<scalar|null>> $rows
     * @param float[] $widths relative column widths
     * @param bool[] $right which columns are right-aligned (numbers)
     */
    public function table(array $columns, array $rows, array $widths, array $right = []): self
    {
        $total = array_sum($widths);
        $avail = self::W - 2 * self::M;
        $cols = array_map(fn ($w) => $w / $total * $avail, $widths);
        $size = 8.5;
        $rowH = 15;
        $drawHeader = function () use ($columns, $cols, $size, $rowH, $right) {
            $this->need($rowH * 2);
            $this->y -= $rowH;
            $this->ops .= sprintf("0.93 0.95 0.97 rg %.2f %.2f %.2f %.2f re f\n", self::M, $this->y - 4, self::W - 2 * self::M, $rowH);
            $x = self::M;
            foreach ($columns as $i => $c) {
                $this->cell($c, $x, $cols[$i], $size, true, $right[$i] ?? false);
                $x += $cols[$i];
            }
        };
        $drawHeader();
        if ($rows === []) {
            $this->y -= $rowH;
            $this->write('No data for this period.', self::M + 4, $this->y, $size, false, [0.4, 0.4, 0.45]);
        }
        foreach ($rows as $row) {
            if ($this->y - $rowH < self::M + 20) {
                $this->newPage();
                $drawHeader();
            }
            $this->y -= $rowH;
            $this->ops .= sprintf("0.85 0.87 0.9 RG 0.5 w %.2f %.2f m %.2f %.2f l S\n", self::M, $this->y - 4, self::W - self::M, $this->y - 4);
            $x = self::M;
            foreach (array_values($row) as $i => $v) {
                $this->cell((string) ($v ?? '–'), $x, $cols[$i], $size, false, $right[$i] ?? false);
                $x += $cols[$i];
            }
        }
        $this->y -= 8;
        return $this;
    }

    public function output(): string
    {
        $this->finishPage();
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $n = 5;
        $pageObjs = [];
        foreach ($this->pages as $content) {
            $pageObjs[] = [$n, $n + 1, $content];
            $kids[] = "$n 0 R";
            $n += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($pageObjs as [$p, $c, $content]) {
            $objs[$p] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $c);
            $objs[$c] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        }
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $i => $body) {
            $offsets[$i] = strlen($pdf);
            $pdf .= "$i 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }
        $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
        return $pdf;
    }

    /* ---------- internals ---------- */

    private function newPage(): void
    {
        if ($this->pageNo > 0) {
            $this->finishPage();
        }
        $this->pageNo++;
        $this->ops = '';
        $this->y = self::H - self::M;
        // header band
        $this->write('NetForge', self::M, $this->y - 4, 9, true, [0.05, 0.55, 0.65]);
        $this->write($this->title, self::M, $this->y - 24, 16, true);
        if ($this->subtitle !== '') {
            $this->write($this->subtitle, self::M, $this->y - 40, 9, false, [0.35, 0.35, 0.4]);
        }
        $this->ops .= sprintf("0.8 0.83 0.87 RG 0.8 w %.2f %.2f m %.2f %.2f l S\n", self::M, $this->y - 50, self::W - self::M, $this->y - 50);
        $this->y -= 56;
    }

    private function finishPage(): void
    {
        $this->write(sprintf('Page %d', $this->pageNo), self::W - self::M - 40, 24, 8, false, [0.45, 0.45, 0.5]);
        $this->write('Generated ' . date('j M Y, H:i'), self::M, 24, 8, false, [0.45, 0.45, 0.5]);
        $this->pages[$this->pageNo - 1] = $this->ops;
    }

    private function need(float $h): void
    {
        if ($this->y - $h < self::M + 20) {
            $this->newPage();
        }
    }

    private function cell(string $text, float $x, float $w, float $size, bool $bold, bool $right): void
    {
        $max = (int) floor(($w - 8) / ($size * 0.52));
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, max(1, $max - 1)) . '…';
        }
        $tx = $right ? $x + $w - 4 - mb_strlen($text) * $size * 0.5 : $x + 4;
        $this->write($text, $tx, $this->y, $size, $bold);
    }

    private function write(string $text, float $x, float $y, float $size, bool $bold = false, array $rgb = [0.1, 0.1, 0.12]): void
    {
        $enc = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        $esc = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], (string) $enc);
        $this->ops .= sprintf("BT %.3f %.3f %.3f rg /%s %.1f Tf %.2f %.2f Td (%s) Tj ET\n", $rgb[0], $rgb[1], $rgb[2], $bold ? 'F2' : 'F1', $size, $x, $y, $esc);
    }
}
