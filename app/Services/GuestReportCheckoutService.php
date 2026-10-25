<?php

namespace App\Services;

use App\Models\GuestReportAccessToken;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\StripeClient;
use Throwable;

class GuestReportCheckoutService
{
    public function startCheckout(
        Report $report,
        string $name,
        string $email,
        string $phone,
        string $successUrl,
        string $cancelUrl,
    ): array {
        $this->assertReportCanBePurchased($report);

        $stripeSecret = config('services.stripe.secret');
        $configuredPriceId = config('services.stripe.guest_report_price_id');

        if (
            ! is_string($stripeSecret)
            || $stripeSecret === ''
            || ! is_string($configuredPriceId)
            || $configuredPriceId === ''
        ) {
            throw new RuntimeException(
                'Stripe is not configured for guest report checkout.'
            );
        }

        $stripe = new StripeClient($stripeSecret);

        $configuredPrice = $this->retrieveValidPrice(
            $stripe,
            $configuredPriceId,
            $report->id,
        );

        $normalizedName = trim($name);
        $normalizedEmail = mb_strtolower(trim($email));
        $normalizedPhone = trim($phone);

        $purchase = $this->findOrCreatePendingPurchase(
            $report->id,
            $normalizedName,
            $normalizedEmail,
            $normalizedPhone,
            $configuredPrice,
        );

        $accessToken = null;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($purchase->provider_order_id) {
                try {
                    $existingSession = $stripe->checkout->sessions->retrieve(
                        $purchase->provider_order_id,
                    );
                } catch (ApiErrorException $exception) {
                    Log::warning(
                        'Could not retrieve existing guest checkout session.',
                        [
                            'purchase_id' => $purchase->id,
                            'exception' => $exception::class,
                        ],
                    );

                    throw new RuntimeException(
                        'The existing checkout could not be verified. Please retry later.',
                        0,
                        $exception,
                    );
                }

                if ($existingSession->status === 'complete') {
                    Log::warning(
                        'Guest checkout session is already complete.',
                        [
                            'purchase_id' => $purchase->id,
                        ],
                    );

                    throw new RuntimeException(
                        'The existing checkout has completed. Please wait for payment confirmation before retrying.'
                    );
                }

                if ($existingSession->status === 'open') {
                    $successUrlQuery = parse_url(
                        $existingSession->success_url ?? '',
                        PHP_URL_QUERY,
                    );

                    parse_str(
                        is_string($successUrlQuery)
                            ? $successUrlQuery
                            : '',
                        $successParams,
                    );

                    $sessionSuccessPath = parse_url(
                        $existingSession->success_url ?? '',
                        PHP_URL_PATH,
                    );

                    $requestedSuccessPath = parse_url(
                        $successUrl,
                        PHP_URL_PATH,
                    );

                    $sessionUsesCurrentSuccessPath =
                        is_string($sessionSuccessPath)
                        && is_string($requestedSuccessPath)
                        && $sessionSuccessPath === $requestedSuccessPath;

                    $sessionAccessToken = $successParams['guest_token'] ?? null;

                    $sessionAccessTokenIsValid = is_string($sessionAccessToken)
                        && preg_match(
                            '/\A[a-f0-9]{64}\z/',
                            $sessionAccessToken,
                        ) === 1
                        && GuestReportAccessToken::query()
                            ->where('guest_report_id', $report->id)
                            ->where(
                                'token_hash',
                                hash('sha256', $sessionAccessToken),
                            )
                            ->whereNull('revoked_at')
                            ->exists();

                    if (
                        is_string($existingSession->url)
                        && $existingSession->url !== ''
                        && $sessionAccessTokenIsValid
                        && $sessionUsesCurrentSuccessPath
                    ) {
                        return [
                            'purchase' => $purchase,
                            'checkout_url' => $existingSession->url,
                        ];
                    }

                    try {
                        $stripe->checkout->sessions->expire(
                            $purchase->provider_order_id,
                        );
                    } catch (ApiErrorException $exception) {
                        Log::warning(
                            'Could not expire existing guest checkout session.',
                            [
                                'purchase_id' => $purchase->id,
                                'exception' => $exception::class,
                            ],
                        );

                        throw new RuntimeException(
                            'The existing checkout could not be safely renewed. Please retry later.',
                            0,
                            $exception,
                        );
                    }

                    $purchase->update([
                        'payment_status' => GuestReportPurchase::STATUS_EXPIRED,
                        'expired_at' => now(),
                    ]);

                    $purchase = $this->findOrCreatePendingPurchase(
                        $report->id,
                        $normalizedName,
                        $normalizedEmail,
                        $normalizedPhone,
                        $configuredPrice,
                    );

                    // Token harus disiapkan untuk purchase yang baru.
                    $accessToken = $this->ensureAccessToken($purchase);

                    continue;
                }

                if ($existingSession->status === 'expired') {
                    $purchase->update([
                        'payment_status' => GuestReportPurchase::STATUS_EXPIRED,
                        'expired_at' => now(),
                    ]);

                    $purchase = $this->findOrCreatePendingPurchase(
                        $report->id,
                        $normalizedName,
                        $normalizedEmail,
                        $normalizedPhone,
                        $configuredPrice,
                    );

                    $accessToken = $this->ensureAccessToken($purchase);

                    continue;
                }

                throw new RuntimeException(
                    'The existing checkout has an unexpected status. Please retry later.'
                );
            }

            $accessToken ??= $this->ensureAccessToken($purchase);

            $priceId = $purchase->stripe_price_id
                ?? $configuredPrice->id;

            if ($purchase->stripe_price_id === null) {
                $purchase->update([
                    'stripe_price_id' => $priceId,
                ]);

                $purchase->refresh();
            }

            $price = $priceId === $configuredPrice->id
                ? $configuredPrice
                : $this->retrieveValidPrice(
                    $stripe,
                    $priceId,
                    $report->id,
                );

            if (
                (int) $price->unit_amount !== (int) $purchase->amount_minor
                || strtolower($price->currency)
                    !== strtolower($purchase->currency)
            ) {
                throw new RuntimeException(
                    'The saved checkout price no longer matches its Stripe Price.'
                );
            }

            $metadata = [
                'purpose' => 'guest_report_purchase',
                'guest_report_purchase_id' => (string) $purchase->id,
                'report_id' => (string) $report->id,
            ];

            try {
                $session = $stripe->checkout->sessions->create([
                    'mode' => 'payment',
                    'customer_email' => $purchase->email,
                    'client_reference_id' => (string) $purchase->id,
                    'line_items' => [
                        [
                            'price' => $price->id,
                            'quantity' => 1,
                        ],
                    ],
                    'metadata' => $metadata,
                    'payment_intent_data' => [
                        'metadata' => $metadata,
                    ],
                    'success_url' => $this->appendGuestAccessToken(
                        $successUrl,
                        $accessToken,
                    ),
                    'cancel_url' => $cancelUrl,
                ], [
                    'idempotency_key' => 'guest-report-checkout-'.$purchase->id,
                ]);
            } catch (Throwable $exception) {
                Log::error('Could not create guest Checkout Session.', [
                    'purchase_id' => $purchase->id,
                    'report_id' => $report->id,
                    'exception' => $exception::class,
                ]);

                throw new RuntimeException(
                    'Checkout could not be started. Please retry later.',
                    0,
                    $exception,
                );
            }

            if (! is_string($session->url) || $session->url === '') {
                throw new RuntimeException(
                    'Stripe did not return a usable Checkout URL.'
                );
            }

            try {
                $purchase->update([
                    'provider_order_id' => $session->id,
                ]);
            } catch (Throwable $exception) {
                Log::error(
                    'Could not save guest Checkout Session reference.',
                    [
                        'purchase_id' => $purchase->id,
                        'report_id' => $report->id,
                        'exception' => $exception::class,
                    ],
                );

                throw new RuntimeException(
                    'Checkout was created but could not be linked locally. Please retry safely.',
                    0,
                    $exception,
                );
            }

            return [
                'purchase' => $purchase->fresh(),
                'checkout_url' => $session->url,
            ];
        }

        throw new RuntimeException(
            'Checkout could not be resumed. Please retry.'
        );
    }

    public function getCheckoutSummary(Report $report): array
    {
        $this->assertReportCanBePurchased($report);

        $stripeSecret = config('services.stripe.secret');
        $priceId = config('services.stripe.guest_report_price_id');

        if (
            ! is_string($stripeSecret) || $stripeSecret === ''
            || ! is_string($priceId) || $priceId === ''
        ) {
            throw new RuntimeException(
                'Stripe is not configured for guest report checkout.'
            );
        }

        $price = $this->retrieveValidPrice(
            new StripeClient($stripeSecret),
            $priceId,
            $report->id,
        );

        $currency = strtoupper($price->currency);
        $amount = (int) $price->unit_amount;

        $amountDisplay = $currency === 'GBP'
            ? '£'.number_format($amount / 100, 2)
            : $currency.' '.number_format($amount / 100, 2);

        return [
            'amountMinor' => $amount,
            'currency' => $currency,
            'amountDisplay' => $amountDisplay,
        ];
    }

    private function findOrCreatePendingPurchase(
        int $reportId,
        string $name,
        string $email,
        string $phone,
        Price $price,
    ): GuestReportPurchase {
        return DB::transaction(function () use (
            $reportId,
            $name,
            $email,
            $phone,
            $price,
        ) {
            $report = Report::query()
                ->lockForUpdate()
                ->findOrFail($reportId);

            $this->assertReportCanBePurchased($report);

            $pendingPurchase = $report->guestReportPurchases()
                ->where(
                    'payment_status',
                    GuestReportPurchase::STATUS_PENDING,
                )
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($pendingPurchase) {
                if ($pendingPurchase->email !== $email) {
                    throw new RuntimeException(
                        'A checkout is already in progress for this report.'
                    );
                }

                $pendingPurchase->update([
                    'name' => $name,
                    'phone' => $phone,
                ]);

                return $pendingPurchase;
            }

            return $report->guestReportPurchases()->create([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'payment_provider' => 'stripe',
                'payment_status' => GuestReportPurchase::STATUS_PENDING,
                'amount_minor' => $price->unit_amount,
                'currency' => strtolower($price->currency),
                'stripe_price_id' => $price->id,
                ]);
            });
    }

    private function retrieveValidPrice(
        StripeClient $stripe,
        string $priceId,
        int $reportId,
    ): Price {
        try {
            $price = $stripe->prices->retrieve($priceId);
        } catch (ApiErrorException $exception) {
            Log::error('Could not retrieve guest report Price.', [
                'report_id' => $reportId,
                'exception' => $exception::class,
            ]);

            throw new RuntimeException(
                'Could not retrieve guest report Price.',
                0,
                $exception,
            );
        }

        if (
            ! $price->active
            || $price->type !== 'one_time'
            || $price->unit_amount === null
            || $price->unit_amount <= 0
        ) {
            throw new RuntimeException(
                'The configured Stripe Price is invalid for a one-time purchase.'
            );
        }

        return $price;
    }

    private function assertReportCanBePurchased(Report $report): void
    {
        if ($report->user_id !== null) {
            throw new RuntimeException(
                'Guest checkout is only available for unowned reports.'
            );
        }

        if (
            $report->report_type !== 'basic'
            || ! $report->vin_check_id
            || $report->vinCheck?->status !== 'success'
        ) {
            throw new RuntimeException(
                'This report is not ready for a guest full-report purchase.'
            );
        }

        $alreadyPaid = $report->guestReportPurchases()
            ->where(
                'payment_status',
                GuestReportPurchase::STATUS_PAID,
            )
            ->exists();

        if ($alreadyPaid) {
            throw new RuntimeException(
                'A successful purchase already exists for this report.'
            );
        }
    }

    private function ensureAccessToken(
        GuestReportPurchase $purchase,
    ): string {
        $appKey = config('app.key');

        if (! is_string($appKey) || $appKey === '') {
            throw new RuntimeException(
                'Application key is required to issue guest report access tokens.'
            );
        }

        // Token yang sama dihasilkan kembali untuk purchase yang sama.
        // APP_KEY harus tetap stabil selama checkout yang tertunda.
        $material = sprintf(
            'vincheck-guest-report-access:report:%d:purchase:%d',
            $purchase->report_id,
            $purchase->id,
        );

        $token = hash_hmac('sha256', $material, $appKey);
        $tokenHash = hash('sha256', $token);
        $reportId = (int) $purchase->report_id;

        DB::transaction(function () use ($reportId, $tokenHash) {
            // Hanya token untuk purchase saat ini yang tetap aktif.
            GuestReportAccessToken::query()
                ->where('guest_report_id', $reportId)
                ->where('token_hash', '!=', $tokenHash)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                ]);

            $accessToken = GuestReportAccessToken::query()
                ->where('guest_report_id', $reportId)
                ->where('token_hash', $tokenHash)
                ->first();

            if ($accessToken) {
                if ($accessToken->revoked_at !== null) {
                    $accessToken->forceFill([
                        'revoked_at' => null,
                    ])->save();
                }

                return;
            }

            GuestReportAccessToken::query()->create([
                'guest_report_id' => $reportId,
                'token_hash' => $tokenHash,
                'created_at' => now(),
            ]);
        });

        return $token;
    }

    private function appendGuestAccessToken(
        string $successUrl,
        string $accessToken,
    ): string {
        $separator = str_contains($successUrl, '?') ? '&' : '?';

        return $successUrl
            .$separator
            .'guest_token='
            .rawurlencode($accessToken);
    }
}
