<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VehicleCheckRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'registration_number' => [
                'required',
                'string',
                'regex:/^[A-Z0-9]{2,7}$/i',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'registration_number' => strtoupper(str_replace(' ', '', $this->registration_number)),
        ]);
    }
}
