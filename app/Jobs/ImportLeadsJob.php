<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Services\Import\LeadImporterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportLeadsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $backoff = 2;

    public function __construct(public int $importId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->importId))->releaseAfter(3)->expireAfter(60),
        ];
    }

    public function handle(LeadImporterService $importer): void
    {
        $import = Import::find($this->importId);

        if ($import === null || $import->status->isFinished()) {
            return;
        }

        $finished = $importer->run($import, microtime(true) + (int) config('import.time_budget'));

        if (! $finished) {
            self::dispatch($this->importId);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $import = Import::find($this->importId);

        if ($import === null) {
            return;
        }

        $import->update([
            'status' => ImportStatus::Failed,
            'error' => $exception?->getMessage(),
            'finished_at' => now(),
        ]);

        Storage::disk('local')->delete($import->path);
    }
}
