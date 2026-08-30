<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DeleteAccountRequest extends FormRequest
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
        $rules = [
            'confirmation' => ['required', 'stirng', 'in:DELETE'],
        ];

        if(! is_null($this->user()->password)) {
            $rules['cureent_password'] = [
                'required',
                'current_password:web',
            ];
        }

        return $rules;
    }
}
