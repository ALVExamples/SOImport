<?php

namespace Tests\Support;

use App\Services\Import\LeadRowMapperService;
use ZipArchive;

final class XlsxFixture
{
    public static function leads(string $path, array $rows): string
    {
        return self::create($path, [LeadRowMapperService::COLUMNS, ...$rows]);
    }

    public static function leadRow(int $number, array $overrides = []): array
    {
        return array_values([
            'external_id' => sprintf('LD-%06d', $number),
            'created_at' => 45848.213125,
            'first_name' => 'Олександр',
            'last_name' => 'Поліщук',
            'phone' => 380672341057,
            'email' => "lead{$number}@ukr.net",
            'city' => 'Київ',
            'source' => 'Instagram',
            'utm_campaign' => 'organic',
            'product' => 'CRM-система',
            'budget_uah' => 23700.0,
            'status' => 'new',
            'manager' => 'Литвиненко Н.',
            'comment' => null,
            'next_contact_at' => 45861.213125,
            ...$overrides,
        ]);
    }

    public static function create(string $path, array $rows): string
    {
        $strings = [];
        $sheet = '';

        foreach (array_values($rows) as $rowIndex => $cells) {
            $number = $rowIndex + 1;
            $sheet .= "<row r=\"{$number}\">";

            foreach (array_values($cells) as $columnIndex => $value) {
                if ($value === null) {
                    continue;
                }

                $reference = self::columnName($columnIndex).$number;

                if (is_array($value)) {
                    $formula = htmlspecialchars($value['formula'], ENT_XML1);
                    $sheet .= "<c r=\"{$reference}\" t=\"str\"><f>{$formula}</f><v>#ERROR!</v></c>";
                } elseif (is_string($value)) {
                    $strings[$value] ??= count($strings);
                    $sheet .= "<c r=\"{$reference}\" t=\"s\"><v>{$strings[$value]}</v></c>";
                } else {
                    $sheet .= "<c r=\"{$reference}\"><v>{$value}</v></c>";
                }
            }

            $sheet .= '</row>';
        }

        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $shared = '';

        foreach (array_keys($strings) as $string) {
            $shared .= '<si><t>'.htmlspecialchars((string) $string, ENT_XML1).'</t></si>';
        }

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', "<workbook xmlns=\"{$namespace}\"><sheets><sheet name=\"Sheet1\" sheetId=\"1\"/></sheets></workbook>");
        $zip->addFromString('xl/sharedStrings.xml', "<sst xmlns=\"{$namespace}\">{$shared}</sst>");
        $zip->addFromString('xl/worksheets/sheet1.xml', "<worksheet xmlns=\"{$namespace}\"><sheetData>{$sheet}</sheetData></worksheet>");
        $zip->close();

        return $path;
    }

    private static function columnName(int $index): string
    {
        $name = '';

        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + ($index - 1) % 26).$name;
        }

        return $name;
    }
}
