<?php

namespace App\Http\Requests\Admin;

use App\Services\Admin\AdminSystemConfigurationService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSystemConfigurationRequest extends FormRequest
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
        $rules = [
            'settings' => ['required', 'array'],
            'settings.*' => [
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $parts = explode('.', $attribute);
                    $key = $parts[1] ?? null;

                    if ($key === null || ! array_key_exists($key, AdminSystemConfigurationService::ALLOWLIST)) {
                        $fail("Setting key '{$key}' is not allowed or configurable.");
                    }
                },
            ],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];

        // Dynamically apply validation rules defined in ALLOWLIST
        foreach (AdminSystemConfigurationService::ALLOWLIST as $key => $meta) {
            $subRules = ['sometimes'];
            foreach ($meta['validation'] as $rule) {
                $subRules[] = $rule;
            }
            $rules["settings.{$key}"] = $subRules;
        }

        return $rules;
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
            'settings.required' => 'At least one configuration parameter must be provided.',
        ];
    }
}
