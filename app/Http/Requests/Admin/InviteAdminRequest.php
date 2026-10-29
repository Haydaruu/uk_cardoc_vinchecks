<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InviteAdminRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->role === 'super_admin';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:150'],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'role' => ['required', 'in:admin,super_admin'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'confirm_elevation' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.min' => 'An audit reason of at least 5 characters is required.',
            'role.in' => 'Administrator role must be either admin or super_admin.',
        ];
    }
}
