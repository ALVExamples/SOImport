<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Lead;
use App\Services\Import\LeadImporterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\XlsxFixture;
use Tests\TestCase;

class LeadImporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['import.batch_size' => 3]);
    }

    public function test_it_imports_every_row_including_duplicates(): void
    {
        $import = $this->createImport([
            XlsxFixture::leadRow(1),
            XlsxFixture::leadRow(1),
            XlsxFixture::leadRow(2, ['email' => 'broken@@ukr.net']),
            XlsxFixture::leadRow(3, ['phone' => ['formula' => '+38050abc1234']]),
            XlsxFixture::leadRow(4, ['utm_campaign' => null, 'budget_uah' => null]),
        ]);

        $finished = app(LeadImporterService::class)->run($import, microtime(true) + 60);

        $this->assertTrue($finished);
        $this->assertSame(5, Lead::count());
        $this->assertSame(2, Lead::where('external_id', 'LD-000001')->count());

        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(5, $import->total_rows);
        $this->assertSame(5, $import->processed_rows);
        $this->assertSame(2, $import->issues_count);
        $this->assertNotNull($import->finished_at);
        Storage::disk('local')->assertMissing($import->path);

        $lead = Lead::where('external_id', 'LD-000003')->first();
        $this->assertSame('+38050abc1234', $lead->phone);
        $this->assertSame(['phone' => 'invalid'], $lead->issues);
        $this->assertSame('2025-07-10 05:06:54', $lead->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(5, $lead->source_row);
    }

    public function test_it_stops_at_the_deadline_and_resumes_from_the_cursor(): void
    {
        $import = $this->createImport(array_map(fn ($number) => XlsxFixture::leadRow($number), range(1, 8)));
        $importer = app(LeadImporterService::class);

        $this->assertFalse($importer->run($import, 0));
        $this->assertSame(3, Lead::count());
        $this->assertSame(ImportStatus::Processing, $import->refresh()->status);
        $this->assertSame(4, $import->cursor_row);
        $this->assertSame(8, $import->total_rows);

        $this->assertFalse($importer->run($import, 0));
        $this->assertSame(6, Lead::count());

        $this->assertTrue($importer->run($import, 0));
        $this->assertSame(8, Lead::count());
        $this->assertSame(8, Lead::distinct()->count('source_row'));
        $this->assertSame(ImportStatus::Completed, $import->refresh()->status);
        $this->assertSame(8, $import->processed_rows);
    }

    private function createImport(array $rows): Import
    {
        Storage::disk('local')->makeDirectory('imports');
        XlsxFixture::leads(Storage::disk('local')->path('imports/leads.xlsx'), $rows);

        return Import::create(['original_name' => 'leads.xlsx', 'path' => 'imports/leads.xlsx']);
    }
}
