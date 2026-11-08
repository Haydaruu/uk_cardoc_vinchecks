<?php

namespace App\Services;

use App\Exceptions\PayPalGuestOrderMismatchException;
use App\Models\GuestReportPurchase;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PayPalService
{
    private function baseUrl(): string
    {
        $mode = config('services.paypal.mode', 'sandbox');

        return $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function accessToken(): string
    {
        $mode = config('services.paypal.mode', 'sandbox');
        $cacheKey = "paypal_access_token_{$mode}";

        $cachedToken = Cache::get($cacheKey);

        if ($cachedToken) {
            return $cachedToken;
        }

        $response = Http::asForm()
            ->timeout(10)
            ->withBasicAuth(
                config('services.paypal.client_id'),
                config('services.paypal.client_secret'),
            )
            ->post($this->baseUrl().'/v1/oauth2/token',
                [
                    'grant_type' => 'client_credentials',

                ],
            );

        if ($response->failed()) {
            throw new RuntimeException('Unable to authenticate with PayPal.');
        }

        $token = $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 3600);

        if (! $token) {
            throw new RuntimeException('Paypal access token was not returned.');
        }

        Cache::put($cacheKey, $token, now()->addSeconds(max($expiresIn - 60, 60)));

        return $token;
    }

    /** @return array<string, mixed> */
    public function createOrder(User $user, string $planSlug, ?\App\Models\CreditCheckoutAttempt $attempt = null): array
    {
        $plan = config("credit_plans.{$planSlug}");

        if (! $plan || ($plan['type'] ?? null) !== 'one_time') {
            throw new RuntimeException('Invalid Paypal credit plan.');
        }

        if ($attempt !== null) {
            $amountMinor = $attempt->amount_minor;
            $currency = $attempt->currency;
            $requestId = 'credit-order-'.$attempt->attempt_uuid;
            $invoiceId = $attempt->invoice_id;
            $customId = $user->id.'|'.$planSlug;
        } else {
            $amountMinor = (int) $plan['amount_minor'];
            $currency = $plan['currency'];
            $requestId = (string) Str::uuid();
            $invoiceId = 'UKC-PPL'.strtoupper(Str::random(12));
            $customId = $user->id.'|'.$planSlug;
        }

        // Pure integer arithmetic representation without floating-point rounding artifacts
        $pounds = intdiv($amountMinor, 100);
        $pence = abs($amountMinor % 100);
        $amount = sprintf('%d.%02d', $pounds, $pence);

        $response = Http::withToken($this->accessToken())
            ->timeout(15)
            ->acceptJson()
            ->withHeaders([
                'PayPal-Request-Id' => $requestId,
                'Prefer' => 'return=representation',
            ])
            ->post($this->baseUrl().'/v2/checkout/orders',
                [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [
                        [
                            'reference_id' => $planSlug,
                            'custom_id' => $customId,
                            'invoice_id' => $invoiceId,
                            'description' => $plan['label'],
                            'amount' => [
                                'currency_code' => $currency,
                                'value' => $amount,
                            ],
                        ],
                    ],
                ],
            );

        if ($response->failed()) {
            throw new RuntimeException('Unable to create PayPal order: '.$response->body());
        }

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function createGuestReportOrder(
        GuestReportPurchase $purchase,
    ): array {
        if (
            $purchase->payment_provider !== 'paypal'
            || $purchase->payment_status !== GuestReportPurchase::STATUS_PENDING
        ) {
            throw new RuntimeException(
                'Invalid PayPal guest report purchase.',
            );
        }

        $amount = number_format(
            $purchase->amount_minor / 100,
            2,
            '.',
            '',
        );

        $currency = strtoupper($purchase->currency);

        $checkoutReference = (string) $purchase->checkout_reference;

        if (! Str::isUuid($checkoutReference)) {
            throw new RuntimeException(
                'Invalid guest report checkout reference.',
            );
        }

        $customId = sprintf(
            'guest-report|%s|%d',
            $checkoutReference,
            $purchase->report_id,
        );

        $invoiceId = 'UKC-GR-'.str_replace(
            '-',
            '',
            strtoupper($checkoutReference),
        );

        // Stable idempotency key: retries for this purchase reuse the same key.
        $requestId = 'guest-report-order-'.$checkoutReference;

        $response = Http::withToken($this->accessToken())
            ->timeout(15)
            ->acceptJson()
            ->withHeaders([
                'PayPal-Request-Id' => $requestId,
                'Prefer' => 'return=representation',
            ])
            ->post($this->baseUrl().'/v2/checkout/orders', [
                'intent' => 'CAPTURE',

                'purchase_units' => [
                    [
                        // Retained as the purchase-unit reference, not the payment identity.
                        'reference_id' => (string) $purchase->id,
                        'custom_id' => $customId,
                        'invoice_id' => $invoiceId,
                        'description' => 'UKCarDoc Full Vehicle Report',

                        'amount' => [
                            'currency_code' => $currency,
                            'value' => $amount,
                        ],
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Unable to create PayPal guest report order.',
            );
        }

        $order = $response->json();

        if (! is_string($order['id'] ?? null)) {
            throw new RuntimeException(
                'PayPal did not return a guest report order ID.',
            );
        }

        return $order;
    }

    /**
     * Update legacy guest report identifiers before capturing an order.
     *
     * Only call this after the controller has verified that the order belongs
     * to the expected local purchase and is still CREATED or APPROVED.
     */
    public function updateGuestReportOrderIdentifiers(
        string $orderId,
        string $referenceId,
        string $customId,
        string $invoiceId,
    ): void {
        if (! ctype_digit($referenceId)) {
            throw new RuntimeException(
                'Invalid PayPal purchase unit reference.',
            );
        }

        $purchaseUnitPath = "/purchase_units/@reference_id=='{$referenceId}'";

        $response = Http::withToken($this->accessToken())
            ->timeout(15)
            ->acceptJson()
            ->patch(
                $this->baseUrl()."/v2/checkout/orders/{$orderId}",
                [
                    [
                        'op' => 'replace',
                        'path' => $purchaseUnitPath.'/custom_id',
                        'value' => $customId,
                    ],
                    [
                        'op' => 'replace',
                        'path' => $purchaseUnitPath.'/invoice_id',
                        'value' => $invoiceId,
                    ],
                ],
            );

        if ($response->failed()) {
            throw new RuntimeException(
                'Unable to update PayPal guest report order identifiers.',
            );
        }
    }

    /**
     * Validate a guest report order and migrate legacy identifiers when possible.
     *
     * A completed legacy order is not modified. It remains eligible for the
     * backward-compatible payment processing path.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public function prepareGuestReportOrderForCapture(
        GuestReportPurchase $purchase,
        array $order,
    ): array {
        $checkoutReference = (string) $purchase->checkout_reference;

        if (
            $purchase->payment_provider !== 'paypal'
            || ! Str::isUuid($checkoutReference)
            || ! is_string($purchase->provider_order_id)
            || $purchase->provider_order_id === ''
        ) {
            throw new RuntimeException('Invalid local PayPal guest purchase.');
        }

        $expectedCustomId = sprintf(
            'guest-report|%s|%d',
            $checkoutReference,
            $purchase->report_id,
        );

        $legacyCustomId = sprintf(
            'guest-report|%d|%d',
            $purchase->id,
            $purchase->report_id,
        );

        $expectedInvoiceId = 'UKC-GR-'.str_replace(
            '-',
            '',
            strtoupper($checkoutReference),
        );

        $legacyInvoiceId = 'UKC-GR-'.$purchase->id;

        $expectedAmount = number_format(
            $purchase->amount_minor / 100,
            2,
            '.',
            '',
        );

        $expectedCurrency = strtoupper($purchase->currency);

        $validateOrder = function (array $candidate) use (
            $purchase,
            $expectedCustomId,
            $legacyCustomId,
            $expectedInvoiceId,
            $legacyInvoiceId,
            $expectedAmount,
            $expectedCurrency,
        ): void {
            $orderId = $candidate['id'] ?? null;
            $status = $candidate['status'] ?? null;
            $unit = $candidate['purchase_units'][0] ?? null;

            if (
                ! is_string($orderId)
                || $orderId !== $purchase->provider_order_id
                || ! is_array($unit)
                || (string) ($unit['reference_id'] ?? '') !== (string) $purchase->id
            ) {
                throw new PayPalGuestOrderMismatchException(
                    'PayPal order does not belong to this guest purchase.',
                );
            }

            if (! in_array($status, ['CREATED', 'APPROVED', 'COMPLETED'], true)) {
                throw new RuntimeException(
                    'PayPal guest order has an unsupported status.',
                );
            }

            $customId = $unit['custom_id'] ?? null;
            $invoiceId = $unit['invoice_id'] ?? null;

            if (
                ! in_array($customId, [$expectedCustomId, $legacyCustomId], true)
                || ! in_array($invoiceId, [$expectedInvoiceId, $legacyInvoiceId], true)
            ) {
                throw new PayPalGuestOrderMismatchException(
                    'PayPal guest order identifiers do not match the purchase.',
                );
            }

            $amount = $unit['amount']['value'] ?? null;
            $currency = strtoupper(
                is_string($unit['amount']['currency_code'] ?? null)
                    ? $unit['amount']['currency_code']
                    : '',
            );

            if (
                ! is_string($amount)
                || $amount !== $expectedAmount
                || $currency !== $expectedCurrency
            ) {
                throw new RuntimeException(
                    'PayPal order amount or currency does not match the purchase.',
                );
            }
        };

        // Validate ownership, identifiers, amount and currency before any PATCH.
        $validateOrder($order);

        $unit = $order['purchase_units'][0];
        $isAlreadyMigrated = (
            ($unit['custom_id'] ?? null) === $expectedCustomId
            && ($unit['invoice_id'] ?? null) === $expectedInvoiceId
        );

        if ($isAlreadyMigrated) {
            return $order;
        }

        // Completed orders cannot be modified. Preserve them for legacy processing.
        if (($order['status'] ?? null) === 'COMPLETED') {
            return $order;
        }

        // Only CREATED and APPROVED orders may reach the migration PATCH.
        $this->updateGuestReportOrderIdentifiers(
            orderId: $purchase->provider_order_id,
            referenceId: (string) $purchase->id,
            customId: $expectedCustomId,
            invoiceId: $expectedInvoiceId,
        );

        // Never assume PATCH succeeded. Retrieve and validate the canonical order.
        $updatedOrder = $this->getOrder($purchase->provider_order_id);

        $validateOrder($updatedOrder);

        $updatedUnit = $updatedOrder['purchase_units'][0] ?? [];

        if (
            ($updatedUnit['custom_id'] ?? null) !== $expectedCustomId
            || ($updatedUnit['invoice_id'] ?? null) !== $expectedInvoiceId
        ) {
            throw new PayPalGuestOrderMismatchException(
                'PayPal guest order identifier migration could not be verified.',
            );
        }

        return $updatedOrder;
    }

    /** @return array<string, mixed> */
    public function getOrder(string $orderId): array
    {
        $response = Http::withToken($this->accessToken())
            ->timeout(10)
            ->acceptJson()
            ->get($this->baseUrl()."/v2/checkout/orders/{$orderId}");

        if ($response->failed()) {
            $errorName = $response->json('name') ?? 'UNKNOWN';
            $errorMessage = $response->json('message') ?? 'No error message returned';

            $debugId = $response->header('PayPal-Debug-Id');

            throw new RuntimeException(sprintf(
                'Unable to retrieve PayPal order. HTTP %d | %s | %s | Debug ID: %s',
                $response->status(),
                $errorName,
                $errorMessage,
                $debugId !== '' ? $debugId : 'Unavailable',
            ));
        }

        return $response->json();
    }

    /** @return array<string, mixed> */
    public function captureOrder(string $orderId): array
    {
        $response = Http::withToken($this->accessToken())
            ->timeout(20)
            ->acceptJson()
            ->withHeaders(['PayPal-Request-Id' => 'capture-'.$orderId])
            ->withBody('{}', 'application/json')
            ->send('POST', $this->baseUrl()."/v2/checkout/orders/{$orderId}/capture", []);

        if ($response->failed()) {
            throw new RuntimeException('Unable to capture PayPal order: '.$response->body());
        }

        return $response->json();
    }

    public function verifyWebhook(Request $request): bool
    {
        $webhookId = config('services.paypal.webhook_id');

        if (! $webhookId) {
            return false;
        }

        $transmissionId = $request->header('PAYPAL-TRANSMISSION-ID');

        $transmissionTime = $request->header('PAYPAL-TRANSMISSION-TIME');

        $transmissionSig = $request->header('PAYPAL-TRANSMISSION-SIG');

        $certUrl = $request->header('PAYPAL-CERT-URL');

        $authAlgo = $request->header('PAYPAL-AUTH-ALGO');

        if (! $transmissionId || ! $transmissionTime || ! $transmissionSig || ! $certUrl || ! $authAlgo) {
            return false;
        }

        $rawBody = $request->getContent();

        if (! $rawBody) {
            return false;
        }

        $payload = '{'
        .'"auth_algo":'
        .json_encode($authAlgo)
        .','
        .'"cert_url":'
        .json_encode($certUrl)
        .','
        .'"transmission_id":'
        .json_encode($transmissionId)
        .','
        .'"transmission_sig":'
        .json_encode($transmissionSig)
        .','
        .'"transmission_time":'
        .json_encode($transmissionTime)
        .','
        .'"webhook_id":'
        .json_encode($webhookId)
        .','
        .'"webhook_event":'
        .$rawBody
        .'}';

        $response = Http::withToken($this
            ->accessToken())
            ->timeout(10)
            ->acceptJson()
            ->withBody($payload, 'application/json')
            ->post($this->baseUrl().'/v1/notifications/verify-webhook-signature'
            );

        if ($response->failed()) {
            return false;
        }

        return $response->json('verification_status') === 'SUCCESS';
    }

    /** @return array<string, mixed> */
    public function createCatalogProduct(string $name, string $description): array
    {
        $response = Http::withToken($this->accessToken())
            ->timeout(15)
            ->acceptJson()
            ->withHeaders([
                'PayPal-Request-Id' => (string) Str::uuid(),
                'Prefer' => 'return=representation',
            ])
            ->post($this->baseUrl().'/v1/catalogs/products',
                [
                    'name' => $name,
                    'description' => $description,
                    'type' => 'SERVICE',
                ],
            );

        if ($response->failed()) {
            throw new RuntimeException('Unable to create PayPal product: '.$response->body());
        }

        return $response->json();
    }

    /** @return array<string, mixed> */
    public function createBillingPlan(string $productId, string $name, int $amountMinor, string $currency = 'GBP'): array
    {
        $amount = number_format($amountMinor / 100, 2, '.', '');

        $response = Http::withToken($this->accessToken())
            ->timeout(15)
            ->acceptJson()
            ->withHeaders([
                'PayPal-Request-Id' => (string) Str::uuid(),
                'Prefer' => 'return=representation',
            ])
            ->post($this->baseUrl().'/v1/billing/plans',
                [
                    'product_id' => $productId,
                    'name' => $name,
                    'description' => "{$name} monthly UKCardoc membership",
                    'status' => 'ACTIVE',
                    'billing_cycles' => [
                        [
                            'frequency' => [
                                'interval_unit' => 'MONTH',
                                'interval_count' => 1,
                            ],

                            'tenure_type' => 'REGULAR',
                            'sequence' => 1,
                            'total_cycles' => 0,
                            'pricing_scheme' => [
                                'fixed_price' => [
                                    'value' => $amount,
                                    'currency_code' => strtoupper($currency),
                                ],
                            ],
                        ],
                    ],

                    'payment_preferences' => [
                        'auto_bill_outstanding' => true,
                        'payment_failure_threshold' => 3,
                    ],
                ],
            );

        if ($response->failed()) {
            throw new RuntimeException('Unable to create PayPal billing plan: '.$response->body());
        }

        return $response->json();
    }

    /** @return array<string, mixed> */
    public function createSubscription(User $user, string $planSlug): array
    {
        $plan = config("credit_plans.{$planSlug}");

        if (! $plan || $plan['type'] !== 'subscription') {
            throw new RuntimeException('Invalid PayPal subscription plan.');
        }

        $payPalPlanId = $plan['paypal_plan_id'] ?? null;

        if (! $payPalPlanId) {
            throw new RuntimeException('PayPal billing plan is not configured.');
        }

        $response = Http::withToken($this->accessToken())
            ->timeout(15)
            ->acceptJson()
            ->withHeaders([
                'PayPal-Request-Id' => (string) Str::uuid(),
                'Prefer' => 'return=representation',
            ])
            ->post($this->baseUrl().'/v1/billing/subscriptions', [
                'plan_id' => $payPalPlanId,
                'custom_id' => $user->id.'|'.$planSlug,

                'application_context' => [
                    'brand_name' => 'UKCarDoc',
                    'locale' => 'en-GB',
                    'user_action' => 'SUBSCRIBE_NOW',
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Unable to create PayPal subscription: '.$response->body()
            );
        }

        return $response->json();
    }

    /** @return array<string, mixed> */
    public function getSubscription(string $subscriptionId): array
    {
        $response = Http::withToken($this->accessToken())
            ->timeout(10)
            ->acceptJson()
            ->get($this->baseUrl()."/v1/billing/subscriptions/{$subscriptionId}");

        if ($response->failed()) {
            throw new RuntimeException(
                'Unable to retrieve PayPal subscription: '.$response->body()
            );
        }

        return $response->json();
    }

    /** @return array<string, mixed> */
    public function reviseSubscription(string $subscriptionId, string $payPalPlanId, string $returnUrl, string $cancelUrl): array
    {
        $response = Http::withToken($this->accessToken())
            ->timeout(15)
            ->acceptJson()
            ->withHeaders([
                'PayPal-Request-Id' => (string) Str::uuid(),
                'Prefer' => 'return=representation',
            ])
            ->post(
                $this->baseUrl()."/v1/billing/subscriptions/{$subscriptionId}/revise",
                [
                    'plan_id' => $payPalPlanId,

                    'application_context' => [
                        'brand_name' => 'UKCarDoc',
                        'locale' => 'en-GB',
                        'return_url' => $returnUrl,
                        'cancel_url' => $cancelUrl,
                    ],
                ]
            );

        if ($response->failed()) {
            throw new RuntimeException(
                'Unable to revise PayPal subscription: '.$response->body()
            );
        }

        return $response->json();
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        $response = Http::withToken($this->accessToken())
            ->timeout(10)
            ->acceptJson()
            ->post($this->baseUrl()."/v1/billing/subscriptions/{$subscriptionId}/cancel", [
                'reason' => 'Cancelled by customer.',
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Unable to cancel PayPal subscription: '.$response->body()
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function refundCapture(
        string $captureId,
        string $amount,
        string $currency = 'GBP',
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): array {
        $headers = [];
        if ($idempotencyKey) {
            $headers['PayPal-Request-Id'] = $idempotencyKey;
        }

        $payload = [
            'amount' => [
                'value' => $amount,
                'currency_code' => strtoupper($currency),
            ],
        ];

        if ($reason) {
            $payload['note_to_payer'] = substr($reason, 0, 255);
        }

        $response = Http::withToken($this->accessToken())
            ->timeout(15)
            ->acceptJson()
            ->withHeaders($headers)
            ->post($this->baseUrl()."/v2/payments/captures/{$captureId}/refund", $payload);

        if ($response->failed()) {
            throw new RuntimeException('Unable to refund PayPal capture: '.$response->body());
        }

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getCapture(string $captureId): array
    {
        $response = Http::withToken($this->accessToken())
            ->timeout(10)
            ->acceptJson()
            ->get($this->baseUrl()."/v2/payments/captures/{$captureId}");

        if ($response->failed()) {
            throw new RuntimeException('Unable to get PayPal capture: '.$response->body());
        }

        return $response->json();
    }
}
