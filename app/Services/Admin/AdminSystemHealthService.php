<?php

namespace App\Services\Admin;

use App\Models\ApiLog;
use App\Models\ProcessedWebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminSystemHealthService
{
    /**
     * Perform all lightweight health probes and aggregate component states.
     *
     * @return array<string, mixed>
     */
    public function checkAll(): array
    {
        $db = $this->checkDatabase();
        $storage = $this->checkStorage();
        $queue = $this->checkQueue();
        $mail = $this->checkMail();
        $gateways = $this->checkGateways();
        $providers = $this->checkVehicleProviders();

        // Determine overall system health
        $overallStatus = 'healthy';
        if ($db['status'] === 'failed' || $storage['status'] === 'failed') {
            $overallStatus = 'failed';
        } elseif (
            $db['status'] === 'degraded' ||
            $queue['status'] === 'degraded' ||
            ($queue['failed_jobs_count'] ?? 0) > 0 ||
            $gateways['stripe']['status'] === 'degraded' ||
            $gateways['paypal']['status'] === 'degraded' ||
            $providers['checkcardetails']['status'] === 'degraded' ||
            $providers['oneauto']['status'] === 'degraded'
        ) {
            $overallStatus = 'degraded';
        }

        return [
            'overall_status' => $overallStatus,
            'checked_at' => Carbon::now()->toIso8601String(),
            'database' => $db,
            'storage' => $storage,
            'queue' => $queue,
            'mail' => $mail,
            'gateways' => $gateways,
            'vehicle_providers' => $providers,
        ];
    }

    /**
     * Check database connectivity and query latency.
     *
     * @return array<string, mixed>
     */
    public function checkDatabase(): array
    {
        $startedAt = microtime(true);

        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');
            $latencyMs = round((microtime(true) - $startedAt) * 1000, 2);

            $status = $latencyMs > 1000 ? 'degraded' : 'healthy';

            return [
                'status' => $status,
                'driver' => DB::connection()->getDriverName(),
                'database' => DB::connection()->getDatabaseName(),
                'latency_ms' => $latencyMs,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'driver' => (string) config('database.default', 'unknown'),
                'database' => 'unavailable',
                'latency_ms' => null,
                'error' => $this->sanitizeErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Check storage writability and transient file lifecycle.
     *
     * @return array<string, mixed>
     */
    public function checkStorage(): array
    {
        $defaultDisk = (string) config('filesystems.default', 'local');
        $startedAt = microtime(true);
        $probeFilename = 'health-probes/.probe_'.Str::random(16).'.tmp';

        try {
            $disk = Storage::disk($defaultDisk);
            $probePayload = 'probe_'.time().'_'.Str::random(8);

            $disk->put($probeFilename, $probePayload);
            $readBack = $disk->get($probeFilename);
            $disk->delete($probeFilename);

            $latencyMs = round((microtime(true) - $startedAt) * 1000, 2);

            if ($readBack !== $probePayload) {
                throw new \RuntimeException('Storage probe payload mismatch upon readback');
            }

            $freeSpace = 'N/A';
            $totalSpace = 'N/A';
            $rootPath = config("filesystems.disks.{$defaultDisk}.root", storage_path('app'));
            if (is_string($rootPath) && is_dir($rootPath)) {
                $bytesFree = @disk_free_space($rootPath);
                $bytesTotal = @disk_total_space($rootPath);
                if ($bytesFree !== false && $bytesTotal !== false && $bytesTotal > 0) {
                    $freeSpace = sprintf('%.2f GB', $bytesFree / 1073741824);
                    $totalSpace = sprintf('%.2f GB', $bytesTotal / 1073741824);
                }
            }

            return [
                'status' => 'healthy',
                'disk' => $defaultDisk,
                'latency_ms' => $latencyMs,
                'free_space' => $freeSpace,
                'total_space' => $totalSpace,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            try {
                Storage::disk($defaultDisk)->delete($probeFilename);
            } catch (\Throwable) {
            }

            return [
                'status' => 'failed',
                'disk' => $defaultDisk,
                'latency_ms' => null,
                'free_space' => 'N/A',
                'total_space' => 'N/A',
                'error' => $this->sanitizeErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Check queue worker state and read-only failed jobs.
     *
     * @return array<string, mixed>
     */
    public function checkQueue(): array
    {
        $driver = (string) config('queue.default', 'sync');
        $failedJobsCount = 0;
        $recentFailedJobs = [];

        try {
            $failedJobsCount = DB::table('failed_jobs')->count();
            $records = DB::table('failed_jobs')
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'uuid', 'connection', 'queue', 'exception', 'failed_at']);

            foreach ($records as $record) {
                $recentFailedJobs[] = [
                    'id' => $record->id,
                    'uuid' => (string) $record->uuid,
                    'connection' => (string) $record->connection,
                    'queue' => (string) $record->queue,
                    'failed_at' => (string) $record->failed_at,
                    'summary' => $this->sanitizeException((string) $record->exception),
                ];
            }
        } catch (\Throwable) {
            // failed_jobs table might not exist
        }

        $pendingJobsCount = null;
        if ($driver === 'database') {
            try {
                $pendingJobsCount = DB::table('jobs')->count();
            } catch (\Throwable) {
            }
        }

        $status = 'healthy';
        $note = 'Sync driver active (jobs execute synchronously; daemon worker not required).';

        if ($driver !== 'sync') {
            // Spec 7.1: If there is no mechanism confirming worker is alive, do not claim healthy
            // Display unknown/last activity clearly.
            $status = $failedJobsCount > 0 ? 'degraded' : 'unknown';
            $note = "Queue driver '{$driver}' configured. Worker daemon heartbeat unconfirmed in v1.";
        }

        return [
            'status' => $status,
            'driver' => $driver,
            'note' => $note,
            'pending_jobs_count' => $pendingJobsCount,
            'failed_jobs_count' => $failedJobsCount,
            'recent_failed_jobs' => $recentFailedJobs,
            'retry_supported' => false,
        ];
    }

    /**
     * Check mail service configuration state.
     *
     * @return array<string, mixed>
     */
    public function checkMail(): array
    {
        $driver = (string) config('mail.default', 'log');
        $host = (string) config('mail.mailers.smtp.host', 'N/A');
        $port = (string) config('mail.mailers.smtp.port', 'N/A');
        $encryption = (string) config('mail.mailers.smtp.encryption', 'tls');
        $fromAddress = (string) config('mail.from.address', 'N/A');

        $isConfigured = $driver !== 'log' && $driver !== 'array' && ! empty($host) && $host !== 'N/A';

        return [
            'status' => $isConfigured ? 'healthy' : 'unknown',
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'from_address' => $fromAddress,
            'note' => $isConfigured
                ? "Configured for outbound mail via {$driver}."
                : 'Using local log/array mail driver. Outbound SMTP not active.',
        ];
    }

    /**
     * Check payment gateways last-known activity without live network calls.
     *
     * @return array<string, mixed>
     */
    public function checkGateways(): array
    {
        // Stripe
        $stripeKey = (string) config('services.stripe.key', '');
        $stripeSecret = (string) config('services.stripe.secret', '');
        $stripeWebhook = (string) config('services.stripe.webhook_secret', '');
        $stripeConfigured = ! empty($stripeKey) && ! empty($stripeSecret);
        $stripeMode = str_starts_with($stripeKey, 'pk_live_') ? 'live' : 'test';

        $stripeLastEvent = null;
        try {
            $lastStripe = ProcessedWebhookEvent::where('provider', 'stripe')
                ->orderByDesc('id')
                ->first();
            if ($lastStripe !== null) {
                $stripeLastEvent = [
                    'event_id' => $lastStripe->event_id,
                    'status' => $lastStripe->status,
                    'recorded_at' => $lastStripe->created_at?->toIso8601String(),
                ];
            }
        } catch (\Throwable) {
        }

        $stripeStatus = 'unknown';
        if ($stripeConfigured) {
            $stripeStatus = ($stripeLastEvent !== null && $stripeLastEvent['status'] === ProcessedWebhookEvent::STATUS_FAILED)
                ? 'degraded'
                : ($stripeLastEvent !== null ? 'healthy' : 'unknown');
        }

        // PayPal
        $paypalClientId = (string) config('services.paypal.client_id', '');
        $paypalSecret = (string) config('services.paypal.secret', '');
        $paypalWebhook = (string) config('services.paypal.webhook_id', '');
        $paypalMode = (string) config('services.paypal.mode', 'sandbox');
        $paypalConfigured = ! empty($paypalClientId) && ! empty($paypalSecret);

        $paypalLastEvent = null;
        try {
            $lastPayPal = ProcessedWebhookEvent::where('provider', 'paypal')
                ->orderByDesc('id')
                ->first();
            if ($lastPayPal !== null) {
                $paypalLastEvent = [
                    'event_id' => $lastPayPal->event_id,
                    'status' => $lastPayPal->status,
                    'recorded_at' => $lastPayPal->created_at?->toIso8601String(),
                ];
            }
        } catch (\Throwable) {
        }

        $paypalStatus = 'unknown';
        if ($paypalConfigured) {
            $paypalStatus = ($paypalLastEvent !== null && $paypalLastEvent['status'] === ProcessedWebhookEvent::STATUS_FAILED)
                ? 'degraded'
                : ($paypalLastEvent !== null ? 'healthy' : 'unknown');
        }

        return [
            'stripe' => [
                'name' => 'Stripe',
                'status' => $stripeStatus,
                'configured' => $stripeConfigured,
                'webhook_configured' => ! empty($stripeWebhook),
                'mode' => $stripeMode,
                'last_event' => $stripeLastEvent,
                'note' => $stripeConfigured
                    ? 'Stripe credentials configured. Live probes disabled per Spec 7.1.'
                    : 'Stripe credentials not configured.',
            ],
            'paypal' => [
                'name' => 'PayPal',
                'status' => $paypalStatus,
                'configured' => $paypalConfigured,
                'webhook_configured' => ! empty($paypalWebhook),
                'mode' => $paypalMode,
                'last_event' => $paypalLastEvent,
                'note' => $paypalConfigured
                    ? 'PayPal credentials configured. Live probes disabled per Spec 7.1.'
                    : 'PayPal credentials not configured.',
            ],
        ];
    }

    /**
     * Check vehicle data providers last-known activity from api_logs.
     *
     * @return array<string, mixed>
     */
    public function checkVehicleProviders(): array
    {
        $ccdKey = (string) config('services.checkcardetails.key', '');
        $oneAutoKey = (string) config('services.oneauto.api_key', '');

        // CheckCarDetails
        $ccdLastLog = null;
        $ccdStatus = 'unknown';
        try {
            $log = ApiLog::where('api_provider', 'checkcardetails')
                ->orderByDesc('id')
                ->first();
            if ($log !== null) {
                $ccdLastLog = [
                    'endpoint' => (string) $log->endpoint,
                    'status_code' => $log->http_status_code,
                    'duration_ms' => $log->response_time_ms,
                    'recorded_at' => $log->created_at?->toIso8601String(),
                ];
                $ccdStatus = ($log->http_status_code >= 200 && $log->http_status_code < 300) ? 'healthy' : 'degraded';
            }
        } catch (\Throwable) {
        }

        // OneAuto
        $oaLastLog = null;
        $oaStatus = 'unknown';
        try {
            $log = ApiLog::where('api_provider', 'oneauto')
                ->orderByDesc('id')
                ->first();
            if ($log !== null) {
                $oaLastLog = [
                    'endpoint' => (string) $log->endpoint,
                    'status_code' => $log->http_status_code,
                    'duration_ms' => $log->response_time_ms,
                    'recorded_at' => $log->created_at?->toIso8601String(),
                ];
                $oaStatus = ($log->http_status_code >= 200 && $log->http_status_code < 300) ? 'healthy' : 'degraded';
            }
        } catch (\Throwable) {
        }

        return [
            'checkcardetails' => [
                'name' => 'CheckCarDetails',
                'status' => $ccdStatus,
                'configured' => ! empty($ccdKey),
                'role' => 'General / Base vehicle data & MOT history',
                'last_call' => $ccdLastLog,
                'note' => ! empty($ccdKey)
                    ? 'Configured. Status derived from recent api_logs.'
                    : 'API key not configured.',
            ],
            'oneauto' => [
                'name' => 'OneAuto',
                'status' => $oaStatus,
                'configured' => ! empty($oneAutoKey),
                'role' => 'Critical vehicle history, finance & insurance write-off',
                'last_call' => $oaLastLog,
                'note' => ! empty($oneAutoKey)
                    ? 'Configured. Status derived from recent api_logs.'
                    : 'API key not configured.',
            ],
        ];
    }

    /**
     * Sanitize error message to ensure no connection strings, passwords, or tokens leak.
     */
    public function sanitizeErrorMessage(string $message): string
    {
        // Strip file paths
        $sanitized = preg_replace('/in\s+[A-Za-z0-9_:\/\\\.\-]+\.php/i', '', $message) ?? $message;

        // Strip credentials, passwords, tokens, API keys
        $sanitized = preg_replace('/(password|secret|key|token|auth)=[^&\s]+/i', '$1=[REDACTED]', $sanitized) ?? $sanitized;

        // Strip connection strings (e.g. postgresql://user:pass@host:port/db)
        $sanitized = preg_replace('/[a-z]+:\/\/[^:]+:[^@]+@[^\/]+\/[^\s]+/i', '[REDACTED_CONNECTION_STRING]', $sanitized) ?? $sanitized;

        return mb_substr(trim($sanitized), 0, 250);
    }

    /**
     * Sanitize exception for failed jobs list (take first line, redact sensitive info).
     */
    public function sanitizeException(string $rawException): string
    {
        $firstLine = strtok($rawException, "\r\n") ?: $rawException;

        return $this->sanitizeErrorMessage($firstLine);
    }
}
