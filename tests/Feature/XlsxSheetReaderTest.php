<?php

namespace Tests\Feature;

use App\Services\Xlsx\XlsxSheetReaderService;
use RuntimeException;
use Tests\Support\XlsxFixture;
use Tests\TestCase;

class XlsxSheetReaderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'xlsx');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    public function test_it_reads_cells_by_column_reference(): void
    {
        XlsxFixture::create($this->path, [
            ['name', 'amount', 'note'],
            ['Київ', 12.5, null],
            [null, null, 'лише примітка'],
        ]);

        $rows = iterator_to_array((new XlsxSheetReaderService($this->path))->rows());

        $this->assertSame([
            1 => ['name', 'amount', 'note'],
            2 => ['Київ', '12.5'],
            3 => [2 => 'лише примітка'],
        ], $rows);
    }

    public function test_it_returns_formula_text_for_error_cells(): void
    {
        XlsxFixture::create($this->path, [[['formula' => '+38050abc1234']]]);

        $this->assertSame(['+38050abc1234'], (new XlsxSheetReaderService($this->path))->header());
    }

    public function test_it_skips_rows_up_to_the_cursor_and_counts_rows(): void
    {
        XlsxFixture::create($this->path, [['a'], ['b'], ['c'], ['d']]);

        $reader = new XlsxSheetReaderService($this->path);

        $this->assertSame(4, $reader->countRows());
        $this->assertSame([3 => ['c'], 4 => ['d']], iterator_to_array($reader->rows(2)));
    }

    public function test_it_rejects_a_file_that_is_not_a_spreadsheet(): void
    {
        file_put_contents($this->path, 'not a zip');

        $this->expectException(RuntimeException::class);

        new XlsxSheetReaderService($this->path);
    }
}
