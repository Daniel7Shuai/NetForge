<?php
declare(strict_types=1);

namespace NetForge\Analytics;

/**
 * Sends a report as CSV or PDF (FR-7.5). A report is a title, a subtitle and a list of
 * tables: ['title' => ..., 'columns' => [...], 'rows' => [[...]], 'widths' => [...], 'right' => [bool...]].
 */
final class Export
{
    public static function send(string $format, string $filename, string $title, string $subtitle, array $tables, array $notes = []): never
    {
        $filename = preg_replace('/[^a-z0-9-]+/i', '-', $filename) . '-' . date('Ymd');
        if ($format === 'pdf') {
            $pdf = new SimplePdf($title, $subtitle);
            foreach ($notes as $n) {
                $pdf->paragraph($n);
            }
            foreach ($tables as $t) {
                $pdf->heading($t['title'])->table($t['columns'], $t['rows'], $t['widths'], $t['right'] ?? []);
            }
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
            echo $pdf->output();
            exit;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8
        fputcsv($out, [self::cell($title)]);
        fputcsv($out, [self::cell($subtitle)]);
        foreach ($tables as $t) {
            fputcsv($out, []);
            fputcsv($out, [self::cell($t['title'])]);
            fputcsv($out, array_map([self::class, 'cell'], $t['columns']));
            foreach ($t['rows'] as $r) {
                fputcsv($out, array_map([self::class, 'cell'], array_values($r)));
            }
        }
        exit;
    }

    /**
     * A CSV cell that Excel won't run as a formula. Text starting with = + - @ (or a tab or
     * return) gets a leading apostrophe; real numbers like -5 or +12 are left alone.
     */
    public static function cell(mixed $v): mixed
    {
        if ($v === null) {
            return '';
        }
        if (is_string($v) && $v !== '' && !is_numeric($v) && str_contains("=+-@\t\r", $v[0])) {
            return "'" . $v;
        }
        return $v;
    }

    public static function pct(?int $v): string
    {
        return $v === null ? '–' : $v . '%';
    }
}
