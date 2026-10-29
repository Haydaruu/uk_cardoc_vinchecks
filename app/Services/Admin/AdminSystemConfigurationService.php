<?php

namespace App\Services\Admin;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AdminAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminSystemConfigurationService
{
    /**
     * Explicit allowlist of configurable settings with schema, defaults, and validation rules.
     * Secrets are STRICTLY FORBIDDEN from this allowlist.
     */
    public const ALLOWLIST = [
        'company_name' => [
            'type' => 'string',
            'group' => 'general',
            'default' => 'VinCheck UK',
            'description' => 'Business display name for invoices and public notices.',
            'validation' => ['required', 'string', 'min:2', 'max:100'],
        ],
        'support_email' => [
            'type' => 'string',
            'group' => 'general',
            'default' => 'support@vincheck.test',
            'description' => 'Primary customer support and contact email address.',
            'validation' => ['required', 'email', 'max:150'],
        ],
        'support_phone' => [
            'type' => 'string',
            'group' => 'general',
            'default' => '+44 20 7946 0912',
            'description' => 'Customer assistance operational phone line.',
            'validation' => ['required', 'string', 'min:5', 'max:50'],
        ],
        'maintenance_mode' => [
            'type' => 'boolean',
            'group' => 'system',
            'default' => 'false',
            'description' => 'When enabled, displays an informational maintenance notice to customers.',
            'validation' => ['required', 'boolean'],
        ],
        'enable_guest_checkout' => [
            'type' => 'boolean',
            'group' => 'features',
            'default' => 'true',
            'description' => 'Permit unregistered guest buyers to purchase vehicle reports directly.',
            'validation' => ['required', 'boolean'],
        ],
        'default_report_credits' => [
            'type' => 'integer',
            'group' => 'business',
            'default' => '1',
            'description' => 'Standard credit cost required to generate a complete vehicle check.',
            'validation' => ['required', 'integer', 'min:1', 'max:10'],
        ],
        'notify_admin_on_failure' => [
            'type' => 'boolean',
            'group' => 'notifications',
            'default' => 'true',
            'description' => 'Trigger system alert emails to administrator on recurring gateway errors.',
            'validation' => ['required', 'boolean'],
        ],
    ];

    public function __construct(
        protected AdminAuditService $auditService,
    ) {}

    /**
     * Get all allowlisted settings with current values or default values.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getSettings(): array
    {
        $existing = SystemSetting::with('updatedBy:id,name,email')
            ->whereIn('key', array_keys(self::ALLOWLIST))
            ->get()
            ->keyBy('key');

        $result = [];

        foreach (self::ALLOWLIST as $key => $meta) {
            $record = $existing->get($key);

            $rawVal = $record !== null ? $record->value : $meta['default'];
            $typedVal = match ($meta['type']) {
                'boolean' => filter_var($rawVal, FILTER_VALIDATE_BOOLEAN),
                'integer' => (int) $rawVal,
                default => (string) $rawVal,
            };

            $result[$key] = [
                'key' => $key,
                'value' => $typedVal,
                'raw_value' => $rawVal,
                'type' => $meta['type'],
                'group' => $meta['group'],
                'description' => $meta['description'],
                'updated_at' => $record?->updated_at?->toIso8601String(),
                'updated_by_name' => $record?->updatedBy?->name,
            ];
        }

        return $result;
    }

    /**
     * Update an allowlisted configuration value with strict backend validation and audit logging.
     *
     * @param  array<string, mixed>  $updates  Key-value pairs of settings to update
     */
    public function updateSettings(
        User $admin,
        array $updates,
        string $reason,
        Request $request,
    ): void {
        if (trim($reason) === '' || strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages([
                'reason' => ['An explicit audit reason of at least 5 characters is required.'],
            ]);
        }

        // Validate that all keys are allowlisted
        foreach (array_keys($updates) as $key) {
            if (! array_key_exists($key, self::ALLOWLIST)) {
                throw ValidationException::withMessages([
                    'settings' => ["Setting key '{$key}' is not allowed or configurable."],
                ]);
            }
        }

        DB::transaction(function () use ($admin, $updates, $reason, $request) {
            foreach ($updates as $key => $newValue) {
                $meta = self::ALLOWLIST[$key];

                $setting = SystemSetting::firstOrNew(['key' => $key]);
                $oldValue = $setting->exists ? $setting->value : $meta['default'];

                $strValue = is_bool($newValue) ? ($newValue ? 'true' : 'false') : (string) $newValue;

                $setting->value = $strValue;
                $setting->type = $meta['type'];
                $setting->group = $meta['group'];
                $setting->description = $meta['description'];
                $setting->updated_by = $admin->id;
                $setting->save();

                // Record audit log for each configuration mutation
                $this->auditService->log(
                    admin: $admin,
                    action: 'system_configuration.updated',
                    targetType: 'setting',
                    targetId: $setting->id,
                    reason: $reason,
                    outcome: 'success',
                    metadata: [
                        'setting_key' => $key,
                        'old_value' => $oldValue,
                        'new_value' => $strValue,
                        'group' => $meta['group'],
                    ],
                    request: $request,
                );
            }
        });
    }

    /**
     * Retrieve safe non-sensitive infrastructure and provider status summaries.
     * Absolutely NO secrets, API keys, or credentials are returned.
     *
     * @return array<string, mixed>
     */
    public function getInfrastructureStatus(): array
    {
        $stripeKey = (string) config('services.stripe.key', '');
        $stripeSecret = (string) config('services.stripe.secret', '');
        $stripeWebhook = (string) config('services.stripe.webhook_secret', '');

        $paypalClientId = (string) config('services.paypal.client_id', '');
        $paypalSecret = (string) config('services.paypal.secret', '');
        $paypalWebhook = (string) config('services.paypal.webhook_id', '');
        $paypalMode = (string) config('services.paypal.mode', 'sandbox');

        $checkCarDetailsKey = (string) config('services.checkcardetails.key', '');
        $oneAutoKey = (string) config('services.oneauto.api_key', '');

        // Plans & pricing overview from credit_plans.php
        $rawPlans = (array) config('credit_plans', []);
        $plansSummary = [];
        foreach ($rawPlans as $slug => $plan) {
            $plansSummary[] = [
                'slug' => $slug,
                'label' => $plan['label'] ?? $slug,
                'type' => $plan['type'] ?? 'one_time',
                'credits' => $plan['credits'] ?? 0,
                'amount_display' => $plan['amount_display'] ?? 'N/A',
                'currency' => $plan['currency'] ?? 'GBP',
                'has_stripe_price' => ! empty($plan['price_id']),
                'has_paypal_plan' => ! empty($plan['paypal_plan_id']),
            ];
        }

        return [
            'gateways' => [
                'stripe' => [
                    'configured' => ! empty($stripeKey) && ! empty($stripeSecret),
                    'webhook_configured' => ! empty($stripeWebhook),
                    'mode' => str_starts_with($stripeKey, 'pk_live_') ? 'live' : 'test',
                ],
                'paypal' => [
                    'configured' => ! empty($paypalClientId) && ! empty($paypalSecret),
                    'webhook_configured' => ! empty($paypalWebhook),
                    'mode' => $paypalMode === 'live' ? 'live' : 'sandbox',
                ],
            ],
            'mail' => [
                'driver' => config('mail.default', 'log'),
                'host' => config('mail.mailers.smtp.host', 'N/A'),
                'port' => config('mail.mailers.smtp.port', 'N/A'),
                'encryption' => config('mail.mailers.smtp.encryption', 'tls'),
                'from_address' => config('mail.from.address', 'N/A'),
                'from_name' => config('mail.from.name', 'N/A'),
            ],
            'queue' => [
                'driver' => config('queue.default', 'sync'),
            ],
            'storage' => [
                'disk' => config('filesystems.default', 'local'),
            ],
            'providers' => [
                'checkcardetails' => [
                    'name' => 'CheckCarDetails',
                    'configured' => ! empty($checkCarDetailsKey),
                    'role' => 'General / Base vehicle data & MOT history',
                ],
                'oneauto' => [
                    'name' => 'OneAuto',
                    'configured' => ! empty($oneAutoKey),
                    'role' => 'Critical vehicle history, finance & insurance write-off',
                ],
            ],
            'plans' => $plansSummary,
            'catalog_sync_notice' => 'Dynamic runtime catalog editing is disabled in v1 per Spec 7.2 until automated multi-gateway catalog synchronization for Stripe & PayPal is implemented.',
        ];
    }
}
