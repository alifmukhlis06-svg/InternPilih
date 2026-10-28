<?php

/**
 * Helpers for dependency-free CSV and PDF report downloads.
 */

function reportCsvValue(mixed $value): string {
    $text = (string)($value ?? '');

    // Prevent spreadsheet applications from evaluating exported values as formulas.
    if (preg_match('/^[\x00-\x20]*[=+\-@]/', $text)) {
        return "'" . $text;
    }

    return $text;
}

function sendReportCsv(string $filename, array $headers, array $rows): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        http_response_code(500);
        exit;
    }

    // UTF-8 BOM keeps names readable when the CSV is opened in Microsoft Excel.
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, array_map('reportCsvValue', $headers));
    foreach ($rows as $row) {
        fputcsv($output, array_map('reportCsvValue', $row));
    }
    fclose($output);
    exit;
}

function reportPdfPlainText(mixed $value): string {
    $text = trim(preg_replace('/\s+/u', ' ', (string)($value ?? '')) ?? '');
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }

    return preg_replace('/[^\x20-\x7E\x80-\xFF]/', '?', $text) ?? '';
}

function reportPdfCell(mixed $value, int $maxCharacters): string {
    $text = reportPdfPlainText($value);
    if (strlen($text) > $maxCharacters) {
        $text = substr($text, 0, max(1, $maxCharacters - 3)) . '...';
    }

    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function reportPdfText(float $x, float $y, string $text, string $font = 'F1', float $size = 8): string {
    return sprintf(
        "BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
        $font,
        $size,
        $x,
        $y,
        reportPdfCell($text, 180)
    );
}

/**
 * Build a landscape A4 PDF containing a summary and a paginated data table.
 * Column definitions require: label, width, and optional max character count.
 */
function buildReportPdf(
    string $title,
    string $subtitle,
    array $columns,
    array $rows,
    string $generatedBy
): string {
    $pageWidth = 842.0;
    $pageHeight = 595.0;
    $left = 30.0;
    $tableTop = 490.0;
    $headerHeight = 20.0;
    $rowHeight = 17.0;
    $rowsPerPage = 24;
    $pageRows = array_chunk($rows, $rowsPerPage);
    if ($pageRows === []) {
        $pageRows = [[]];
    }

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

    $pageObjectIds = [];
    $totalPages = count($pageRows);
    foreach ($pageRows as $pageIndex => $pageData) {
        $pageObjectId = 5 + ($pageIndex * 2);
        $contentObjectId = $pageObjectId + 1;
        $pageObjectIds[] = $pageObjectId;

        $content = "0 0 0 rg\n0.75 G\n";
        $content .= reportPdfText($left, 555, $title, 'F2', 16);
        $content .= reportPdfText($left, 536, $subtitle, 'F1', 9);
        $content .= reportPdfText(
            $left,
            520,
            'Generated ' . date('d M Y, H:i') . ' by ' . $generatedBy,
            'F1',
            8
        );
        $content .= reportPdfText($pageWidth - 112, 555, 'Page ' . ($pageIndex + 1) . ' of ' . $totalPages, 'F1', 8);

        $tableWidth = array_sum(array_column($columns, 'width'));
        $content .= sprintf("0.92 0.95 0.98 rg %.2F %.2F %.2F %.2F re f\n0 0 0 rg\n", $left, $tableTop, $tableWidth, $headerHeight);
        $content .= sprintf("0.65 G %.2F %.2F %.2F %.2F re S\n", $left, $tableTop, $tableWidth, $headerHeight);

        $x = $left;
        foreach ($columns as $column) {
            $content .= reportPdfText($x + 3, $tableTop + 7, (string)$column['label'], 'F2', 7);
            $x += (float)$column['width'];
        }

        foreach ($pageData as $rowIndex => $row) {
            $rowTop = $tableTop - ($rowIndex * $rowHeight);
            $rowBottom = $rowTop - $rowHeight;
            if ($rowIndex % 2 === 1) {
                $content .= sprintf("0.97 0.98 0.99 rg %.2F %.2F %.2F %.2F re f\n0 0 0 rg\n", $left, $rowBottom, $tableWidth, $rowHeight);
            }
            $content .= sprintf("0.85 G %.2F %.2F m %.2F %.2F l S\n", $left, $rowBottom, $left + $tableWidth, $rowBottom);

            $x = $left;
            foreach ($columns as $columnIndex => $column) {
                $maxCharacters = (int)($column['max'] ?? max(4, floor(((float)$column['width']) / 4.4)));
                $cell = reportPdfCell($row[$columnIndex] ?? '', $maxCharacters);
                $content .= sprintf("BT /F1 7 Tf %.2F %.2F Td (%s) Tj ET\n", $x + 3, $rowBottom + 6, $cell);
                $x += (float)$column['width'];
            }
        }

        if ($pageData === []) {
            $content .= reportPdfText($left + 4, $tableTop - 17, 'No records found.', 'F1', 8);
        }

        $content .= reportPdfText($left, 20, 'InternPilih - Coordinator report', 'F1', 7);

        $objects[$pageObjectId] = sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0F %.0F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
            $pageWidth,
            $pageHeight,
            $contentObjectId
        );
        $objects[$contentObjectId] = "<< /Length " . strlen($content) . ">>\nstream\n" . $content . "endstream";
    }

    $kids = implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageObjectIds));
    $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageObjectIds) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0 => 0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $objectCount = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($objectCount + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($id = 1; $id <= $objectCount; $id++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
    }
    $pdf .= "trailer\n<< /Size " . ($objectCount + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

    return $pdf;
}

function sendReportPdf(string $filename, string $pdf): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
}
