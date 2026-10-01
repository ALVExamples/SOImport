<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Import extends Model
{
    protected $fillable = [
        'original_name',
        'path',
        'status',
        'total_rows',
        'processed_rows',
        'cursor_row',
        'issues_count',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $attributes = [
        'status' => ImportStatus::Pending,
        'processed_rows' => 0,
        'cursor_row' => 0,
        'issues_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'cursor_row' => 'integer',
            'issues_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
