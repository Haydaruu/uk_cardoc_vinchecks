<?php

namespace App\Services;

use App\Contracts\VehicleDataProviderInterface;
use App\Exceptions\VehicleNotFoundException;
use App\Models\ApiLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class VehicleDataService implements VehicleDataProviderInterface
{
    /**
     * Create a new class instance.
     */
    private string $apiKey;

    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.checkcardetails.key');
        $this->baseUrl = config('services.checkcardetails.base_url');
    }

    /** @return array<string, mixed> */
    private function fetch(string $dataPoint, string $vrm): array
    {
        $startTime = microtime(true);
        $endpoint = "{$this->baseUrl}/vehicledata/{$dataPoint}";

        try {
            $response = Http::timeout(20)
                ->retry(2, 500)
                ->get($endpoint, [
                    'apikey' => $this->apiKey,
                    'vrm' => $vrm,
                ]);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->logApiCall(
                vin: $vrm,
                endpoint: $endpoint,
                statusCode: $response->status(),
                durationMs: $durationMs,
                requestPayload: ['vrm' => $vrm, 'dataPoint' => $dataPoint],
                responsePayload: $response->json()
            );

            return $this->handleResponse($response, $dataPoint);
        } catch (ConnectionException $e) {
            $this->logApiCall(
                vin: $vrm,
                endpoint: $dataPoint,
                statusCode: 0,
                durationMs: (int) ((microtime(true) - $startTime) * 1000),
                requestPayload: ['vrm' => $vrm],
                responsePayload: null,
                errorMessage: $e->getMessage(),
                status: 'timeout',
            );

            throw new \RuntimeException('Vehicle data data provider tidak bisa dihubungi. Coba lagi nanti.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function handleResponse(
        Response $response,
        string $dataPoint
    ): array {
        $payload = $response->json();

        if ($response->status() === 200 && ! is_array($payload)) {
            throw new \RuntimeException(
                'Vehicle data provider returned an invalid JSON response.'
            );
        }

        $message = is_array($payload) && is_string($payload['message'] ?? null)
            ? $payload['message']
            : null;

        return match ($response->status()) {
            200 => $payload,

            404 => throw new VehicleNotFoundException(
                'Vehicle dengan registrasi ini tidak ditemukan.'
            ),

            400 => throw new \RuntimeException(
                'Bad request: '.($message ?? 'Cek format VRM atau API key kamu.')
            ),

            403 => throw new \RuntimeException(
                'Forbidden: '.($message ?? 'API key invalid, belum punya akses, atau limit harian tercapai.')
            ),

            500 => throw new \RuntimeException(
                'Server checkcardetails.co.uk sedang bermasalah (bukan salah kode kamu). Coba lagi beberapa saat.'
            ),

            default => throw new \RuntimeException(
                "Status tidak dikenal: {$response->status()}"
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>|null  $responsePayload
     */
    private function logApiCall(
        string $vin,
        string $endpoint,
        int $statusCode,
        int $durationMs,
        array $requestPayload,
        ?array $responsePayload,
        ?string $errorMessage = null,
        string $status = 'success',
    ): void {
        ApiLog::create([
            'vin' => $vin,
            'api_provider' => 'checkcardetails',
            'endpoint' => $endpoint,
            'response_time_ms' => $durationMs,
            'http_status_code' => $statusCode,
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'error_message' => $errorMessage,
            'status' => $errorMessage ? 'failed' : ($status === 'timeout' ? 'timeout' : 'success'),
        ]);
    }

    // ==== Method publik per data point ====

    public function getRegistrationDetails(string $vrm): array
    {
        return $this->fetch('vehicleregistration', $vrm);
    }

    /** @return array<string, mixed> */
    public function getFullVehicleData(string $vrm): array
    {
        return $this->fetch('ukvehicledata', $vrm);
    }

    public function getHistoryCheck(string $vrm): array
    {
        return $this->fetch('carhistorycheck', $vrm);
    }

    public function getMotHistory(string $vrm): array
    {
        return $this->fetch('mot', $vrm);
    }

    public function getMileageHistory(string $vrm): array
    {
        return $this->fetch('mileage', $vrm);
    }

    public function getVehicleImage(string $vrm): array
    {
        return $this->fetch('vehicleimage', $vrm);
    }

    public function getVehicleValuation(string $vrm): array
    {
        return $this->fetch('vehiclevaluation', $vrm);
    }

    public function performCheck(string $vrm, string $tier = 'basic'): array
    {
        $data = $this->getRegistrationDetails($vrm);

        if (in_array($tier, ['premium', 'full'])) {
            $data = array_merge(
                $data,
                $this->getHistoryCheck($vrm),
                $this->getMotHistory($vrm),
            );
        }

        return $data;
    }
}
