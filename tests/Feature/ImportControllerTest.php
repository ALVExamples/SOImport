<?php

namespace Tests\Feature;

use App\Jobs\ImportLeadsJob;
use App\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\XlsxFixture;
use Tests\TestCase;

class ImportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
    }

    public function test_the_import_page_is_rendered(): void
    {
        $this->withoutVite()->get('/')->assertOk()->assertSee('id="app"', false);
    }

    public function test_a_chunked_upload_creates_an_import_and_queues_the_job(): void
    {
        $source = XlsxFixture::leads(tempnam(sys_get_temp_dir(), 'xlsx'), [XlsxFixture::leadRow(1)]);
        $content = file_get_contents($source);
        $parts = str_split($content, (int) ceil(strlen($content) / 3));
        $uploadId = (string) Str::uuid();

        foreach ($parts as $index => $part) {
            $this->postJson('/imports/chunks', [
                'upload_id' => $uploadId,
                'index' => $index,
                'chunk' => UploadedFile::fake()->createWithContent('chunk', $part),
            ])->assertNoContent();
        }

        $response = $this->postJson('/imports', [
            'upload_id' => $uploadId,
            'name' => 'База даних.xlsx',
            'chunks' => count($parts),
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'pending');

        $import = Import::sole();
        $this->assertSame('База даних.xlsx', $import->original_name);
        $this->assertSame($content, Storage::disk('local')->get($import->path));
        Storage::disk('local')->assertMissing("chunks/{$uploadId}/0");
        Queue::assertPushed(ImportLeadsJob::class, fn (ImportLeadsJob $job) => $job->importId === $import->id);

        $this->getJson('/imports')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/imports/{$import->id}")->assertOk()->assertJsonPath('data.id', $import->id);
    }

    public function test_it_rejects_a_file_that_is_not_a_spreadsheet(): void
    {
        $uploadId = (string) Str::uuid();

        $this->postJson('/imports/chunks', [
            'upload_id' => $uploadId,
            'index' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'plain text'),
        ])->assertNoContent();

        $this->postJson('/imports', ['upload_id' => $uploadId, 'name' => 'leads.xlsx', 'chunks' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, Import::count());
        Queue::assertNothingPushed();
    }

    public function test_it_rejects_an_incomplete_upload(): void
    {
        $this->postJson('/imports', ['upload_id' => (string) Str::uuid(), 'name' => 'leads.xlsx', 'chunks' => 2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_it_rejects_other_file_extensions(): void
    {
        $this->postJson('/imports', ['upload_id' => (string) Str::uuid(), 'name' => 'leads.csv', 'chunks' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }
}
