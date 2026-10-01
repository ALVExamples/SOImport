<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreChunkRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'upload_id' => ['required', 'uuid'],
            'index' => ['required', 'integer', 'min:0', 'max:9999'],
            'chunk' => ['required', 'file', 'max:'.config('import.max_chunk_size')],
        ];
    }
}
