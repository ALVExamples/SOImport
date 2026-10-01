<?php

namespace App\Services\Import;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Services\Xlsx\XlsxSheetReaderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class LeadImporterService
{
    public function run(Import $import, float $deadline): bool
    {
        $reader = new XlsxSheetReaderService(Storage::disk('local')->path($import->path));

        if ($import->status === ImportStatus::Pending) {
            $import->update([
                'status' => ImportStatus::Processing,
                'started_at' => now(),
                'total_rows' => max(0, $reader->countRows() - 1),
            ]);
        }

        $batchSize = (int) config('import.batch_size');
        $mapper = null;
        $batch = [];
        $lastRow = $import->cursor_row;

        foreach ($reader->rows($import->cursor_row) as $number => $cells) {
            $lastRow = $number;

            if ($mapper === null) {
                $mapper = new LeadRowMapperService($import->cursor_row === 0 ? $cells : $reader->header());

                if ($import->cursor_row === 0) {
                    continue;
                }
            }

            if ($cells === []) {
                continue;
            }

            $row = $mapper->map($cells);
            $row['issues'] = $row['issues'] === [] ? null : json_encode($row['issues']);
            $row['import_id'] = $import->id;
            $row['source_row'] = $number;
            $batch[] = $row;

            if (count($batch) >= $batchSize) {
                $this->flush($import, $batch, $number);
                $batch = [];

                if (microtime(true) >= $deadline) {
                    return false;
                }
            }
        }

        $this->flush($import, $batch, $lastRow);

        $import->update([
            'status' => ImportStatus::Completed,
            'total_rows' => $import->processed_rows,
            'finished_at' => now(),
        ]);

        Storage::disk('local')->delete($import->path);

        return true;
    }

    private function flush(Import $import, array $batch, int $cursorRow): void
    {
        DB::transaction(function () use ($import, $batch, $cursorRow) {
            if ($batch !== []) {
                DB::table('leads')->insert($batch);
            }

            $import->update([
                'cursor_row' => $cursorRow,
                'processed_rows' => $import->processed_rows + count($batch),
                'issues_count' => $import->issues_count + count(array_filter(array_column($batch, 'issues'))),
            ]);
        });
    }
}
