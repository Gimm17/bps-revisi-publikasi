<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'], 'region' => ['required', 'string', 'max:150'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'], 'kind' => ['required', 'in:kcda,kabda,provda,other'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:'.config('audit.max_upload_kb')],
            'template' => ['nullable', 'file', 'mimes:xlsx', 'max:10240'],
        ];
    }
}
