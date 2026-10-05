<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('publication')) ?? false;
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'rule_code' => ['required', 'string', 'max:100'],
            'severity' => ['required', 'in:wajib,saran,verifikasi'], 'observed' => ['required', 'string', 'max:5000'],
            'expected' => ['required', 'string', 'max:5000'], 'recommendation' => ['required', 'string', 'max:5000'],
            'review_status' => ['required', 'in:unreviewed,accepted,rejected,needs_verification'],
            'review_note' => ['nullable', 'string', 'max:3000'], 'deleted' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.$this->route('publication')->page_count],
            'quote' => ['sometimes', 'nullable', 'string', 'max:3000'],
        ];
    }
}
