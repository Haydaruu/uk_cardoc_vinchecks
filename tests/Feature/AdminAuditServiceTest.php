<?php

use App\Models\AdminActivityLog;
use App\Models\User;
use App\Services\AdminAuditService;

test('it records activity log with admin role and basic attributes', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
    ]);

    $service = new AdminAuditService;
    $log = $service->log(
        admin: $admin,
        action: 'user.credits.adjust',
        targetType: 'User',
        targetId: 99,
        reason: 'Goodwill compensation',
        outcome: 'success',
        metadata: [
            'credits_added' => 5,
            'previous_balance' => 0,
        ],
    );

    expect($log)->toBeInstanceOf(AdminActivityLog::class)
        ->and($log->admin_id)->toBe($admin->id)
        ->and($log->action)->toBe('user.credits.adjust')
        ->and($log->target_type)->toBe('User')
        ->and($log->target_id)->toBe(99)
        ->and($log->reason)->toBe('Goodwill compensation')
        ->and($log->outcome)->toBe('success')
        ->and($log->metadata['admin_role'])->toBe('admin')
        ->and($log->metadata['credits_added'])->toBe(5)
        ->and($log->metadata['previous_balance'])->toBe(0);
});

test('it redacts compound and nested sensitive keys in metadata', function () {
    $admin = User::factory()->create([
        'role' => 'super_admin',
    ]);

    $service = new AdminAuditService;
    $log = $service->log(
        admin: $admin,
        action: 'system.config.update',
        metadata: [
            'provider' => 'stripe',
            'client_secret' => 'pi_secret_12345',
            'stripe_token' => 'tok_visa_abc',
            'webhook_signature' => 't=123,v1=signature_hash',
            'password_confirmation' => 'SuperSecretPass123',
            'api_key' => 'live_key_xyz',
            'nested_payload' => [
                'safe_label' => 'Standard Checkout',
                'access_token' => 'sk_live_token_nested',
                'deep' => [
                    'card_number' => '4242424242424242',
                    'cvv' => '999',
                    'amount' => 1999,
                ],
            ],
        ],
    );

    $metadata = $log->metadata;

    // Safe values preserved
    expect($metadata['provider'])->toBe('stripe')
        ->and($metadata['nested_payload']['safe_label'])->toBe('Standard Checkout')
        ->and($metadata['nested_payload']['deep']['amount'])->toBe(1999)
        ->and($metadata['admin_role'])->toBe('super_admin');

    // Sensitive compound and nested keys redacted
    expect($metadata['client_secret'])->toBe('[REDACTED]')
        ->and($metadata['stripe_token'])->toBe('[REDACTED]')
        ->and($metadata['webhook_signature'])->toBe('[REDACTED]')
        ->and($metadata['password_confirmation'])->toBe('[REDACTED]')
        ->and($metadata['api_key'])->toBe('[REDACTED]')
        ->and($metadata['nested_payload']['access_token'])->toBe('[REDACTED]')
        ->and($metadata['nested_payload']['deep']['card_number'])->toBe('[REDACTED]')
        ->and($metadata['nested_payload']['deep']['cvv'])->toBe('[REDACTED]');
});
