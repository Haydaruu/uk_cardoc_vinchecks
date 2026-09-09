<?php

namespace App\Services;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use RUnTimeException;

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

        $response = Http::withToken($this
            ->accessToken())
            ->acceptJson()
            ->post($this->baseUrl(). '/v1/notifications/verify-webhook-signature',
                [
                    'auth_algo' => $authAlgo,
                    'cert_url' => $certUrl,
                    'transmission_id' => $transmissionId,
                    'transmission_sig' => $transmissionSig,
                    'transmission_time' => $transmissionTime,
                    'webhook_id' => $webhookId,
                    'webhook_event' => $request->json()->all(),
                ]
            );

        if ($response->failed()){
            return false;
        }

        return $response->json('verification_status') === 'SUCCESS';
    }
}
