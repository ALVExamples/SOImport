<?php

namespace App\Services\Import;

use App\Enums\LeadStatus;
use InvalidArgumentException;

final class LeadRowMapperService
{
    public const COLUMNS = [
        'external_id',
        'created_at',
        'first_name',
        'last_name',
        'phone',
        'email',
        'city',
        'source',
        'utm_campaign',
        'product',
        'budget_uah',
        'status',
        'manager',
        'comment',
        'next_contact_at',
    ];

    private const EXCEL_EPOCH_OFFSET = 25569;

    private const EXCEL_MAX_SERIAL = 2958465;

    private array $columnMap = [];

    private array $issues = [];

    public function __construct(array $header)
    {
        $names = array_map(fn ($name) => mb_strtolower(trim((string) $name)), $header);

        foreach (self::COLUMNS as $column) {
            $index = array_search($column, $names, true);

            if ($index !== false) {
                $this->columnMap[$column] = $index;
            }
        }

        $missing = array_diff(self::COLUMNS, array_keys($this->columnMap));

        if ($missing !== []) {
            throw new InvalidArgumentException('У файлі відсутні колонки: '.implode(', ', $missing));
        }
    }

    public function map(array $cells): array
    {
        $this->issues = [];

        return [
            'external_id' => $this->required('external_id', $this->text($cells, 'external_id', 64)),
            'created_at' => $this->required('created_at', $this->dateTime($cells, 'created_at')),
            'first_name' => $this->text($cells, 'first_name', 100),
            'last_name' => $this->text($cells, 'last_name', 100),
            'phone' => $this->phone($cells),
            'email' => $this->email($cells),
            'city' => $this->text($cells, 'city', 100),
            'source' => $this->text($cells, 'source', 100),
            'utm_campaign' => $this->text($cells, 'utm_campaign', 100),
            'product' => $this->text($cells, 'product', 150),
            'budget_uah' => $this->budget($cells),
            'status' => $this->status($cells),
            'manager' => $this->text($cells, 'manager', 100),
            'comment' => $this->text($cells, 'comment', 65535),
            'next_contact_at' => $this->dateTime($cells, 'next_contact_at'),
            'issues' => $this->issues,
        ];
    }

    private function raw(array $cells, string $column): ?string
    {
        $value = trim((string) ($cells[$this->columnMap[$column]] ?? ''));

        return $value === '' ? null : $value;
    }

    private function text(array $cells, string $column, int $maxLength): ?string
    {
        $value = $this->raw($cells, $column);

        if ($value !== null && mb_strlen($value) > $maxLength) {
            $this->issues[$column] = 'truncated';

            return mb_substr($value, 0, $maxLength);
        }

        return $value;
    }

    private function required(string $column, ?string $value): ?string
    {
        if ($value === null && ! isset($this->issues[$column])) {
            $this->issues[$column] = 'empty';
        }

        return $value;
    }

    private function phone(array $cells): ?string
    {
        $value = $this->raw($cells, 'phone');

        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            $value = sprintf('%.0F', (float) $value);
        }

        if (preg_match('/^\+?[\d\s()\-]+$/', $value)) {
            $digits = preg_replace('/\D/', '', $value);

            if (preg_match('/^380\d{9}$/', $digits)) {
                return '+'.$digits;
            }
        }

        $this->issues['phone'] = 'invalid';

        return mb_substr($value, 0, 32);
    }

    private function email(array $cells): ?string
    {
        $value = $this->text($cells, 'email', 255);

        if ($value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->issues['email'] = 'invalid';
        }

        return $value;
    }

    private function budget(array $cells): ?string
    {
        $value = $this->raw($cells, 'budget_uah');

        if ($value === null) {
            return null;
        }

        if (! is_numeric($value) || abs((float) $value) >= 1e12) {
            $this->issues['budget_uah'] = 'invalid';

            return null;
        }

        if ((float) $value < 0) {
            $this->issues['budget_uah'] = 'negative';
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function status(array $cells): ?string
    {
        $value = $this->required('status', $this->text($cells, 'status', 32));

        if ($value !== null && LeadStatus::tryFrom($value) === null) {
            $this->issues['status'] = 'unknown';
        }

        return $value;
    }

    private function dateTime(array $cells, string $column): ?string
    {
        $value = $this->raw($cells, $column);

        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            $serial = (float) $value;

            if ($serial >= 1 && $serial <= self::EXCEL_MAX_SERIAL) {
                return gmdate('Y-m-d H:i:s', (int) round(($serial - self::EXCEL_EPOCH_OFFSET) * 86400));
            }
        } elseif (($timestamp = strtotime($value.' UTC')) !== false) {
            return gmdate('Y-m-d H:i:s', $timestamp);
        }

        $this->issues[$column] = 'invalid';

        return null;
    }
}
