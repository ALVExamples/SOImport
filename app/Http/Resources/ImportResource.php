<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'status' => $this->status->value,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'issues_count' => $this->issues_count,
            'progress' => $this->progress(),
            'error' => $this->error,
            'duration' => $this->duration(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function progress(): int
    {
        if ($this->status->value === 'completed') {
            return 100;
        }

        if (! $this->total_rows) {
            return 0;
        }

        return (int) min(100, floor($this->processed_rows / $this->total_rows * 100));
    }

    private function duration(): ?float
    {
        if ($this->started_at === null) {
            return null;
        }

        return round($this->started_at->diffInMilliseconds($this->finished_at ?? now()) / 1000, 1);
    }
}
