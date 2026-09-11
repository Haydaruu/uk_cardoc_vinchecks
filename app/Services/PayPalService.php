<?php

namespace App\Services;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use RunTimeException;

class PayPalService
{
    private function baseUrl(): string
    {
        $mode = config('services.paypal.mode', 'sandbox');
        return $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function accessToken(): string
    {
        $cachedToken = Cache::get('paypal_access_token');

        if ($cachedToken) {
            return $cachedToken;
        }

        $response = Http::asForm()
            ->withBasicAuth(
                config('services.paypal.client_id'),
                config('services.paypal.client_secret'),
            )
            ->post($this->baseUrl() . '/v1/oauth2/token',
            [
                'grant_type' => 'client_credentials',
            
            ],
        );

        if ($response->failed()) {
            throw new RuntimeException('Unable to autheticate with PayPal.');
        }

        $token = $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 3600);

        if (! $token) {
            throw new RuntimeException('Paypal access token was not returned.');
        }

        Cache::put('paypal_access_token', $token, now()->addSeconds(max($expiresIn - 60, 60),),);

        return $token;
    }
    public function createOrder(User $user, string $planSlug): array 
    {
        $plan = config("credit_plans.{$planSlug}");


        if (!$plan || $plan['type'] !== 'one_time') {
            throw new RuntimeException('Invalid Paypal credit plan.');
        }

        $amount = number_format($plan['amount_minor'] / 100, 2, '.', '');

        $currency = $plan['currency'];

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->withHeaders([ 'PayPal-Request-Id' => (string) Str::uuid(),])
            ->post($this->baseUrl(). '/v2/checkout/orders',
                [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [
                        [
                            'reference_id' => $planSlug,
                            'custom_id' => $user->id .'|'. $planSlug,
                            'invoice_id' => 'UKC-PPL'. strtoupper(Str::random(12)),
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
            throw new RuntimeException('Unable to create PayPal order: '. $response->body());
        }

        return $response->json();
    }

    public function getOrder(string $orderId): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->get($this->baseUrl(). "/v2/checkout/orders/{$orderId}");

        if($response->failed()) {
            throw new RuntimeException('Unable to retrieve PayPal order. ');
        }

        return $response->json();
    }

    public function captureOrder(string $orderId,): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->withHeaders(['PayPal-Request-Id' => 'capture-'. $orderId,])
            ->withBody('{}', 'application/json')
            ->send('POST',$this->baseUrl()."/v2/checkout/orders/{$orderId}/capture",[],);

        if($response->failed()) {
            throw new RuntimeException('Unable to capture PayPal order: '. $response->body());
        }

        return $response->json();
    }

    public function verifyWebhook(Request $request): bool
    {
        $webhookId = config('services.paypal.webhook_id');

        if(! $webhookId) {
            return false;
        }

        $transmissionId = $request->header('PAYPAL-TRANSMISSION-ID');

        $transmissionTime = $request->header('PAYPAL-TRANSMISSION-TIME');

        $transmissionSig = $request->header('PAYPAL-TRANSMISSION-SIG');

        $certUrl = $request->header('PAYPAL-CERT-URL');

        $authAlgo = $request->header('PAYPAL-AUTH-ALGO');

        if(! $transmissionId || ! $transmissionTime || ! $transmissionSig || ! $certUrl || ! $authAlgo) {
            return false;
        }

        $rawBody = $request->getContent();

        if(! $rawBody) {
            return false;
        }

        $payload = '{'
        . '"auth_algo":'
        . json_encode($authAlgo)
        . ','
        . '"cert_url":'
        . json_encode($certUrl)
        . ','
        . '"transmission_id":'
        . json_encode($transmissionId)
        . ','
        . '"transmission_sig":'
        . json_encode($transmissionSig)
        . ','
        . '"transmission_time":'
        . json_encode($transmissionTime)
        . ','
        . '"webhook_id":'
        . json_encode($webhookId)
        . ','
        . '"webhook_event":'
        . $rawBody
        . '}';

        $response = Http::withToken($this
            ->accessToken())
            ->acceptJson()
            ->withBody($payload, 'application/json')
            ->post($this->baseUrl(). '/v1/notifications/verify-webhook-signature'  
            );

        if ($response->failed()){
            return false;
        }

        return $response->json('verification_status') === 'SUCCESS';
    }

    public function createCatalogProduct(string $name, string $description): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->withHeaders([
                'PayPal-Request-Id' => (string) Str::uuid(),
                'Prefer' => 'return=representation',
            ])
            ->post($this->baseUrl(). '/v1/catalogs/products',
                [
                    'name' => $name,
                    'description' => $description,
                    'type' => 'SERVICE',
                ],
            );

            if($response->failed()) {
                throw new RunTimeException('Unable to create PayPal product: '.$response->body());
            }

            return $response->json();
    }

    public function createBillingPlan(string $productId, string $name, int $amountMinor, string $currency = 'GPB',): array
    {
        $amount = number_format($amountMinor/100,2,'.','');

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->withHeaders([
                'PayPal-Request-Id' => (string) Str::uuid(),
                'Prefer' => 'return=representation',
            ])
            ->post($this->baseUrl(). '/v1/billing/plans',
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

        if($response->failed()) {
            throw new RuntimeException('Unable to create PayPal billing plan: '.$response->body());
        }

        return $response->json();
    }

    public function createSubscription(User $user, string $planSlug): array
    {
        $plan = config("credit_plans.{$planSlug}");

        if(! $plan || $plan['type'] !== 'subscription') {
            throw new RuntimeException('Invalid PayPal subscription plan.');
        }

        $payPalPlanId = $plan['paypal_plan_id'] ?? null;

        if(! $payPalPlanId) {
            throw new RuntimeException('PayPal billing plan is not configured.');
        }

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->withHeaders([
                'PayPal-Request-Id' => (string) Str::uuid(),
                'Prefer' => 'return=representation',
            ])
            ->post($this->baseUrl(). '/v1/billing/subscriptions', [
                'plan_id' => $payPalPlanId,
                'custom_id' => $user->id. '|'. $planSlug,

                'application_context' => [
                    'brand_name' => 'UKCarDoc',
                    'locale' => 'en-GB',
                    'user_action' => 'SUBSCRIBE_NOW',
                ],
            ]);

        if($response->failed()) {
            throw new RuntimeException(
                'Unable to create PayPal subscription: '. $response->body()
            );
        }

        return $response->json();
    }

    public function getSubscription(string $subscriptionId): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->get($this->baseUrl(). "/v1/billing/subscriptions/{$subscriptionId}");

        if($response->failed()) {
            throw new RuntimeException(
                'Unable to retrieve PayPal subscription: '. $response->body()
            );
        }

        return $response->json();
    }

    public function reviseSubscripiton(string $subscriptionId, string $payPalPlanId, string $returnUrl, string $cancelUrl): array 
    {
        $response = Http::withToken($this->accessToken())
        ->acceptJson()
        ->withHeaders([
            'PayPal-Request-Id' => (string) Str::uuid(),
            'Prefer' => 'return=representation',
        ])
        ->post($this->baseUrl(). "/v1/billing/subscriptions/{$subscriptionId}/revise", [
            'plan_id' => $payPalPlanId,

            'application_context' => [
                'brand_name' => 'UKCarDoc',
                'locale' => 'en-GB',
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ],
        ]);

        if($response->failed()) {
            throw new RuntimeException(
                'Unable to revise PayPal subscription: '. $response->body()
            );
        }

        return $response->json();
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->post($this->baseUrl(). "/v1/billing/subscriptions/{$subscriptionId}/cancel", [
                'reason' => 'Cancelled by customer.',
            ]);

        if($response->failed()) {
            throw new RuntimeException(
                'Unable to cancel PayPal subscription: '. $response->body()
            );
        }
    }
}