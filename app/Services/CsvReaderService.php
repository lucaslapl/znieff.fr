<?php

namespace App\Services;

use League\Csv\Reader;

/**
 * Lecture CSV tolérante.
 *
 * Repris de l'Observatoire des ABC (même équipe, AGPL-3.0) : league/csv est
 * strict (fichiers mal quotés), on retombe sur un parseur permissif ;
 * `iterate()` lit en flux les référentiels INPN de plusieurs millions de
 * lignes (BOM UTF-8 géré, lignes de largeur différente ignorées).
 */
class CsvReaderService
{
    /**
     * @return array<int, array<string, string>> lignes sous forme de tableaux associatifs
     */
    public function read(string $file, string $delimiter = ';', bool $forceTolerant = false): array
    {
        $text = file_get_contents($file);
        if ($text === false) {
            throw new \RuntimeException("Impossible de lire le fichier CSV : {$file}");
        }

        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }

        if (! $forceTolerant) {
            $rows = $this->tryLeagueCsv($text, $delimiter);
            if ($rows !== null) {
                return $rows;
            }
        }

        return $this->parseTolerant($text, $delimiter);
    }

    /**
     * Lecture en flux : référentiels INPN multimillions de lignes.
     *
     * @return \Generator<int, array<string, string>>
     */
    public function iterate(string $file, string $delimiter = ';'): \Generator
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Impossible de lire le fichier CSV : {$file}");
        }

        $header = fgetcsv($handle, 0, $delimiter);
        if ($header === false) {
            fclose($handle);

            return; // fichier vide — aucune ligne
        }

        $header[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(fn ($h) => trim((string) $h), $header);

        try {
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($row === [null]) { // ligne vide
                    continue;
                }
                if (count($row) !== count($header)) {
                    continue; // skip_records_with_error
                }
                yield array_change_key_case(array_combine($header, $row), CASE_LOWER);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Tente league/csv ; retourne null si le fichier n'est pas strictement valide.
     *
     * @return array<int, array<string, string>>|null
     */
    private function tryLeagueCsv(string $text, string $delimiter): ?array
    {
        try {
            $reader = Reader::createFromString($text);
            $reader->setDelimiter($delimiter);
            $reader->setHeaderOffset(0);
            $records = [];
            foreach ($reader->getRecords() as $record) {
                $records[] = array_change_key_case($record, CASE_LOWER);
            }

            return $records;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parseur de repli : guillemets permissifs, largeurs inégales ignorées.
     *
     * @return array<int, array<string, string>>
     */
    private function parseTolerant(string $text, string $delimiter): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        if ($lines === []) {
            return [];
        }

        $header = array_map(fn ($h) => trim($h), $this->splitLine($lines[0], $delimiter));

        $out = [];
        $n = count($header);
        for ($i = 1, $c = count($lines); $i < $c; $i++) {
            $line = $lines[$i];
            if (trim($line) === '') {
                continue;
            }
            $fields = $this->splitLine($line, $delimiter);
            if (count($fields) !== $n) {
                continue;
            }
            $row = [];
            foreach ($header as $j => $col) {
                $row[$col] = $fields[$j] ?? '';
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function splitLine(string $line, string $delimiter): array
    {
        $fields = [];
        $field = '';
        $inQuotes = false;
        $quoteChar = '';
        $len = strlen($line);

        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];

            if ($ch === '"' || $ch === "'") {
                if (! $inQuotes) {
                    $inQuotes = true;
                    $quoteChar = $ch;
                } elseif ($ch === $quoteChar) {
                    if ($i + 1 < $len && $line[$i + 1] === $quoteChar) {
                        $field .= $quoteChar;
                        $i++;
                    } else {
                        $inQuotes = false;
                    }
                } else {
                    $field .= $ch;
                }

                continue;
            }

            if ($ch === $delimiter && ! $inQuotes) {
                $fields[] = $field;
                $field = '';

                continue;
            }

            $field .= $ch;
        }
        $fields[] = $field;

        return $fields;
    }
}
