<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Один источник для CSV/XLSX-выгрузок (заказы, клиенты, комиссия) — раньше
 * CSV-рендеринг (BOM, fputcsv) был продублирован в OrderController и
 * CustomerController по отдельности. Сервис не знает ничего о домене —
 * только headers + готовые к выводу строки (суммы уже в рублях строкой,
 * см. вызывающий код).
 */
class TableExport
{
    /** @param iterable<array<int,string>> $rows */
    public static function stream(string $format, string $filenameBase, array $headers, iterable $rows): StreamedResponse
    {
        return $format === 'xlsx'
            ? self::xlsx($filenameBase, $headers, $rows)
            : self::csv($filenameBase, $headers, $rows);
    }

    /** @param iterable<array<int,string>> $rows */
    private static function csv(string $filenameBase, array $headers, iterable $rows): StreamedResponse
    {
        $filename = $filenameBase . '_' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM — чтобы Excel открывал без кракозябр
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ';');
            foreach ($rows as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param iterable<array<int,string>> $rows */
    private static function xlsx(string $filenameBase, array $headers, iterable $rows): StreamedResponse
    {
        $filename = $filenameBase . '_' . now()->format('Y-m-d') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);

        $rowNum = 2;
        foreach ($rows as $row) {
            $sheet->fromArray($row, null, 'A' . $rowNum);
            $rowNum++;
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
