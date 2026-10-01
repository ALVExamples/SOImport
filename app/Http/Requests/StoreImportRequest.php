<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreImportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'upload_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255', 'regex:/\.xlsx$/i'],
            'chunks' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Підтримуються лише файли .xlsx.',
        ];
    }
}
