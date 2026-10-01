<?php

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class ChunkedUploadService
{
    public function storeChunk(string $uploadId, int $index, UploadedFile $chunk): void
    {
        $chunk->storeAs($this->directory($uploadId), (string) $index, 'local');
    }

    public function assemble(string $uploadId, int $chunks): string
    {
        $disk = Storage::disk('local');
        $directory = $this->directory($uploadId);
        $path = "imports/{$uploadId}.xlsx";

        $disk->makeDirectory('imports');
        $target = fopen($disk->path($path), 'wb');
        $size = 0;

        try {
            for ($index = 0; $index < $chunks; $index++) {
                $part = "{$directory}/{$index}";

                if (! $disk->exists($part)) {
                    $this->fail($path, 'Файл завантажено не повністю, спробуйте ще раз.');
                }

                $size += $disk->size($part);

                if ($size > (int) config('import.max_file_size')) {
                    $this->fail($path, 'Файл завеликий.');
                }

                $source = fopen($disk->path($part), 'rb');
                stream_copy_to_stream($source, $target);
                fclose($source);
            }
        } finally {
            fclose($target);
            $disk->deleteDirectory($directory);
        }

        if (! $this->isSpreadsheet($disk->path($path))) {
            $this->fail($path, 'Файл не є коректним xlsx-документом.');
        }

        return $path;
    }

    private function isSpreadsheet(string $path): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        $valid = $zip->locateName('xl/workbook.xml') !== false;
        $zip->close();

        return $valid;
    }

    private function fail(string $path, string $message): never
    {
        Storage::disk('local')->delete($path);

        throw ValidationException::withMessages(['file' => $message]);
    }

    private function directory(string $uploadId): string
    {
        return "chunks/{$uploadId}";
    }
}
