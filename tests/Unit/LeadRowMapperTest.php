<?php

namespace Tests\Unit;

use App\Services\Import\LeadRowMapperService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\XlsxFixture;

class LeadRowMapperTest extends TestCase
{
    public function test_it_maps_a_valid_row(): void
    {
        $row = $this->map(XlsxFixture::leadRow(1));

        $this->assertSame('LD-000001', $row['external_id']);
        $this->assertSame('2025-07-10 05:06:54', $row['created_at']);
        $this->assertSame('2025-07-23 05:06:54', $row['next_contact_at']);
        $this->assertSame('+380672341057', $row['phone']);
        $this->assertSame('23700.00', $row['budget_uah']);
        $this->assertNull($row['comment']);
        $this->assertSame([], $row['issues']);
    }

    public function test_it_keeps_invalid_values_and_reports_issues(): void
    {
        $row = $this->map(XlsxFixture::leadRow(2, [
            'phone' => '+38050abc1234',
            'email' => '@ukr.net',
            'status' => 'archived',
            'budget_uah' => 'багато',
            'next_contact_at' => 'колись',
        ]));

        $this->assertSame('+38050abc1234', $row['phone']);
        $this->assertSame('@ukr.net', $row['email']);
        $this->assertSame('archived', $row['status']);
        $this->assertNull($row['budget_uah']);
        $this->assertNull($row['next_contact_at']);
        $this->assertSame([
            'phone' => 'invalid',
            'email' => 'invalid',
            'budget_uah' => 'invalid',
            'status' => 'unknown',
            'next_contact_at' => 'invalid',
        ], $row['issues']);
    }

    public function test_it_normalizes_numeric_phone_and_flags_short_numbers(): void
    {
        $row = $this->map(XlsxFixture::leadRow(3, ['phone' => '12345.0']));

        $this->assertSame('12345', $row['phone']);
        $this->assertSame(['phone' => 'invalid'], $row['issues']);
    }

    public function test_it_reports_missing_required_values(): void
    {
        $row = $this->map(XlsxFixture::leadRow(4, ['external_id' => null, 'first_name' => null]));

        $this->assertNull($row['external_id']);
        $this->assertNull($row['first_name']);
        $this->assertSame(['external_id' => 'empty'], $row['issues']);
    }

    public function test_it_resolves_columns_by_header_name(): void
    {
        $header = array_reverse(LeadRowMapperService::COLUMNS);
        $mapper = new LeadRowMapperService($header);

        $row = $mapper->map(array_reverse(XlsxFixture::leadRow(5)));

        $this->assertSame('LD-000005', $row['external_id']);
        $this->assertSame('Київ', $row['city']);
    }

    public function test_it_rejects_a_header_without_required_columns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LeadRowMapperService(['external_id', 'phone']);
    }

    private function map(array $cells): array
    {
        $cells = array_map(fn ($value) => $value === null ? null : (string) $value, $cells);

        return (new LeadRowMapperService(LeadRowMapperService::COLUMNS))->map(array_filter($cells, fn ($value) => $value !== null));
    }
}
