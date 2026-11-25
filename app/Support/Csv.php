<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

class Csv
{
    // A spreadsheet may run text that starts like a formula.
    public static function cell(?string $text): string
    {
        return preg_match('/^( *[=+\-@]|[\t\r\n])/', (string) $text) ? "'$text" : (string) $text;
    }

    // Cells must already be safe. The mark makes Excel read the file as UTF-8.
    public static function download(string $name, array $headings, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, $headings);
            foreach ($rows as $row) {
                fputcsv($file, $row);
            }
            fclose($file);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
