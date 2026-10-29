<?php

namespace App\Services;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminAuditService
{
    /**
     * List of sensitive patterns to match in metadata keys.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_PATTERNS = [
        'password',
        'token',
        'secret',
        'api_key',
        'apikey',
        'access_token',
        'refresh_token',
        'card_number',
        'cardnumber',
        'cvv',
        'cvc',
        'webhook_secret',
        'signature',
        'signing_key',
        'credential',
        'private_key',
        'auth_header',
        'authorization',
    ];

    /**
     * Record an admin activity log entry.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function log(
        User $admin,
        string $action,
        ?string $targetType = null,
        int|string|null $targetId = null,
        ?string $reason = null,
        string $outcome = 'success',
        ?array $metadata = null,
        ?Request $request = null,
    ): AdminActivityLog {
        $finalMetadata = $metadata ?? [];

        // Record the admin's role at the time of the action (safe snapshot)
        if (! isset($finalMetadata['admin_role'])) {
            $finalMetadata['admin_role'] = $admin->role;
        }

        // Deeply sanitize metadata to remove any credentials or sensitive keys
        $sanitizedMetadata = $this->sanitizeMetadata($finalMetadata);

        return AdminActivityLog::create([
            'admin_id' => $admin->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId !== null ? (int) $targetId : null,
            'reason' => $reason,
            'outcome' => $outcome,
            'metadata' => $sanitizedMetadata,
            'ip_address' => $request?->ip(),
        ]);
    }

    /**
     * Deeply redact sensitive keys from metadata.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function sanitizeMetadata(array $metadata): array
    {
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            $stringKey = (string) $key;

            if ($this->isSensitiveKey($stringKey)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $sanitized[$key] = $this->sanitizeMetadata($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Check whether a metadata key matches any sensitive pattern.
     */
    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (Str::contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
