<?php

namespace App\Services\Xlsx;

use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

final class XlsxSheetReaderService
{
    private const SHARED_STRINGS = 'xl/sharedStrings.xml';

    private const DEFAULT_SHEET = 'xl/worksheets/sheet1.xml';

    private string $sheet;

    private bool $hasSharedStrings;

    private ?array $sharedStrings = null;

    public function __construct(private readonly string $path)
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Файл не є коректним xlsx-документом.');
        }

        $sheet = $this->locateSheet($zip);
        $this->hasSharedStrings = $zip->locateName(self::SHARED_STRINGS) !== false;
        $zip->close();

        if ($sheet === null) {
            throw new RuntimeException('У файлі не знайдено жодного аркуша.');
        }

        $this->sheet = $sheet;
    }

    public function countRows(): int
    {
        $stream = fopen($this->uri($this->sheet), 'rb');

        if ($stream === false) {
            throw new RuntimeException('Не вдалося прочитати аркуш.');
        }

        $count = 0;
        $carry = '';

        while (! feof($stream)) {
            $chunk = fread($stream, 1 << 20);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $count += preg_match_all('/<row[ >]/', $carry.$chunk);
            $carry = substr($chunk, -4);
        }

        fclose($stream);

        return $count;
    }

    public function header(): array
    {
        foreach ($this->rows() as $cells) {
            return $cells;
        }

        return [];
    }

    public function rows(int $afterRow = 0): Generator
    {
        $this->sharedStrings ??= $this->loadSharedStrings();

        $reader = $this->open($this->sheet);

        try {
            $found = false;

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                    $found = true;
                    break;
                }
            }

            $number = 0;

            while ($found) {
                $reference = $reader->getAttribute('r');
                $number = $reference !== null ? (int) $reference : $number + 1;

                if ($number > $afterRow) {
                    yield $number => $this->readRow($reader);
                }

                $found = $reader->next('row');
            }
        } finally {
            $reader->close();
        }
    }

    private function readRow(XMLReader $reader): array
    {
        $cells = [];

        if ($reader->isEmptyElement) {
            return $cells;
        }

        $depth = $reader->depth;
        $column = -1;
        $type = null;
        $formula = null;

        while ($reader->read() && $reader->depth > $depth) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            switch ($reader->localName) {
                case 'c':
                    $reference = $reader->getAttribute('r');
                    $column = $reference !== null ? $this->columnIndex($reference) : $column + 1;
                    $type = $reader->getAttribute('t');
                    $formula = null;
                    break;
                case 'f':
                    $formula = $reader->readString();
                    break;
                case 'v':
                    $cells[$column] = $this->cellValue($type, $reader->readString(), $formula);
                    break;
                case 'is':
                    $cells[$column] = $reader->readString();
                    break;
            }
        }

        return $cells;
    }

    private function cellValue(?string $type, string $value, ?string $formula): string
    {
        if ($type === 's') {
            return $this->sharedStrings[(int) $value] ?? '';
        }

        $isError = $type === 'e' || ($type === 'str' && str_starts_with($value, '#'));

        if ($isError && $formula !== null && $formula !== '') {
            return $formula;
        }

        return $value;
    }

    private function columnIndex(string $reference): int
    {
        $index = 0;
        $length = strlen($reference);

        for ($i = 0; $i < $length && ctype_alpha($reference[$i]); $i++) {
            $index = $index * 26 + ord($reference[$i]) - 64;
        }

        return $index - 1;
    }

    private function loadSharedStrings(): array
    {
        if (! $this->hasSharedStrings) {
            return [];
        }

        $strings = [];
        $reader = $this->open(self::SHARED_STRINGS);

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $strings[] = $reader->readString();
            }
        }

        $reader->close();

        return $strings;
    }

    private function locateSheet(ZipArchive $zip): ?string
    {
        if ($zip->locateName(self::DEFAULT_SHEET) !== false) {
            return self::DEFAULT_SHEET;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (is_string($name) && preg_match('#^xl/worksheets/[^/]+\.xml$#', $name)) {
                return $name;
            }
        }

        return null;
    }

    private function open(string $entry): XMLReader
    {
        $reader = new XMLReader;

        if (! $reader->open($this->uri($entry), null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException("Не вдалося прочитати {$entry}.");
        }

        return $reader;
    }

    private function uri(string $entry): string
    {
        return 'zip://'.$this->path.'#'.$entry;
    }
}
