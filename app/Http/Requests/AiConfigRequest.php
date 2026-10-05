<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return ['base_url' => ['required', 'url', 'max:255'], 'api_key' => ['nullable', 'string', 'max:4096'], 'model' => ['required', 'string', 'max:150']];
    }
}
