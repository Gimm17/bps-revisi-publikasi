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
            'evidences' => ['sometimes', 'array', 'min:1', 'max:50'],
            'evidences.*' => ['array:physical_page,printed_page,quote,bbox'],
            'evidences.*.physical_page' => ['required', 'integer', 'min:1', 'max:'.$this->route('publication')->page_count],
            'evidences.*.printed_page' => ['sometimes', 'nullable', 'string', 'max:50'],
            'evidences.*.quote' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'evidences.*.bbox' => ['sometimes', 'nullable', 'array', 'list', 'size:4'],
            'evidences.*.bbox.*' => ['numeric', 'between:0,1'],
        ];
    }
}
