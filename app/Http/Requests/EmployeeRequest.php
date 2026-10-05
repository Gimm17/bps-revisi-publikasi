<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user')?->id)],
            'role' => ['required', 'in:admin,examiner,reviewer'],
            'password' => [$this->isMethod('post') ? 'required' : 'nullable', Password::min(12)->mixedCase()->numbers()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
