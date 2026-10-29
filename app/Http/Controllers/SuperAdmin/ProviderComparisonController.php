<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminAuditService;
use App\Services\Reports\CompositeReportBuilder;
use App\Services\Reports\NormalizedReportBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ProviderComparisonController extends Controller
{
    public function __construct(
        private NormalizedReportBuilder $reportBuilder,
        private CompositeReportBuilder $compositeReportBuilder,
        private AdminAuditService $auditService,
    ) {}

    public function index(): Response
    {
        $providerConfig = config(
            'provider-lab.providers',
            []
        );

        $providers = collect(
            (array) $providerConfig
        )
            ->map(
                fn (
                    array $provider,
                    string $id
                ) => [
                    'id' => $id,

                    'name' => $provider['name']
                        ?? $id,
                ]
            )
            ->values()
            ->all();

        /*
        * Hybrid bukan provider asli.
        * Jadi tetap kita inject sebagai strategy.
        */
        $providers[] = [
            'id' => 'hybrid',
            'name' => 'Hybrid / Plan C',
        ];

        return Inertia::render('super-admin/provider-lab', [
            'providers' => $providers,

            'providerEvaluation' => $providerConfig,

            'csrfToken' => csrf_token(),

        ]);
    }

    public function run(Request $request): JsonResponse
    {
        /*
        * Ambil provider langsung dari registry.
        *
        * Jadi nanti ketika UK Vehicle Data / MotorCheck
        * ditambahkan ke config, validation tidak perlu
        * di-hardcode ulang.
        */
        $registeredProviders = array_keys(
            config(
                'provider-lab.providers',
                []
            )
        );

        $allowedProviders = [
            ...$registeredProviders,
            'hybrid',
        ];

        $validated = $request->validate([
            'provider' => [
                'required',
                Rule::in(
                    $allowedProviders
                ),
            ],

            'mode' => [
                'nullable',
                Rule::in([
                    'live',
                    'fixture',
                ]),
            ],

            /*
            * Tidak boleh required di sini.
            *
            * Fixture mode memang tidak mengirim satu VRM utama,
            * karena masing-masing sandbox memakai VRM berbeda.
            */
            'vrm' => [
                'nullable',
                'string',
                'max:10',
            ],

            'checkcardetails_vrm' => [
                'nullable',
                'string',
                'max:10',
            ],

            'oneauto_vrm' => [
                'nullable',
                'string',
                'max:10',
            ],
        ]);

        $mode =
            $validated['mode']
            ?? 'live';

        /*
        * HYBRID FIXTURE
        *
        * Tidak membutuhkan `vrm`.
        * Tapi dua sandbox registration wajib tersedia.
        */
        if (
            $validated['provider'] === 'hybrid'
            && $mode === 'fixture'
        ) {
            if (
                blank(
                    $validated[
                        'checkcardetails_vrm'
                    ]
                    ?? null
                )
                || blank(
                    $validated[
                        'oneauto_vrm'
                    ]
                    ?? null
                )
            ) {
                throw ValidationException::withMessages([
                    'fixture' => 'Both CheckCarDetails and OneAuto test registrations are required for fixture mode.',
                ]);
            }
        }

        /*
        * Semua mode selain hybrid fixture wajib punya
        * satu registration utama.
        *
        * Berlaku untuk:
        * - CheckCarDetails
        * - OneAuto
        * - Hybrid Same Vehicle
        */
        elseif (
            blank(
                $validated['vrm']
                ?? null
            )
        ) {
            throw ValidationException::withMessages([
                'vrm' => 'Vehicle registration is required.',
            ]);
        }

        $vrm = isset(
            $validated['vrm']
        )
            ? strtoupper(
                preg_replace(
                    '/\s+/',
                    '',
                    $validated['vrm']
                )
            )
            : null;

        $startedAt =
            microtime(true);

        try {
            $result = match (true) {

                /*
                * PLAN C - Sandbox Fixture
                */
                $validated['provider'] === 'hybrid'
                    && $mode === 'fixture' => $this
                        ->compositeReportBuilder
                        ->buildFixture(
                            checkCarDetailsVrm: $validated[
                                    'checkcardetails_vrm'
                                ],

                            oneAutoVrm: $validated[
                                    'oneauto_vrm'
                                ],
                        ),

                /*
                * PLAN C - Same real vehicle
                */
                $validated['provider'] === 'hybrid' => $this
                    ->compositeReportBuilder
                    ->build(
                        vrm: $validated[
                                'vrm'
                            ],
                    ),

                /*
                * PLAN D - Single provider comparison
                */
                default => $this
                    ->reportBuilder
                    ->build(
                        vrm: $validated[
                                'vrm'
                            ],

                        provider: $validated[
                                'provider'
                            ],
                    ),
            };

            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            if ($request->user() instanceof User) {
                $this->auditService->log(
                    admin: $request->user(),
                    action: 'provider_lab.run',
                    targetType: 'provider',
                    targetId: 0,
                    reason: "Provider Lab test executed for VRM: {$vrm} (mode: {$mode})",
                    outcome: 'success',
                    metadata: [
                        'provider' => $validated['provider'],
                        'mode' => $mode,
                        'vrm' => $vrm,
                        'duration_ms' => $durationMs,
                    ],
                    request: $request,
                );
            }

            return response()->json([
                'provider' => $validated['provider'],
                'vrm' => $result['vrm'] ?? $vrm,
                'duration_ms' => $durationMs,
                'meta' => $this->sanitizeOutput($result['meta'] ?? []),
                'normalized' => $result['normalized'],
                'raw' => $this->sanitizeOutput($result['raw'] ?? []),
            ]);
        } catch (Throwable $e) {
            report($e);

            if ($request->user() instanceof User) {
                $this->auditService->log(
                    admin: $request->user(),
                    action: 'provider_lab.run',
                    targetType: 'provider',
                    targetId: 0,
                    reason: "Provider Lab execution failed for VRM: {$vrm} (mode: {$mode})",
                    outcome: 'failed',
                    metadata: [
                        'provider' => $validated['provider'] ?? 'unknown',
                        'mode' => $mode,
                        'vrm' => $vrm,
                    ],
                    request: $request,
                );
            }

            return response()->json([
                'message' => preg_replace('/(api_key|key|secret|password|token)=[^&\s]+/i', '$1=[REDACTED]', $e->getMessage()),
            ], 422);
        }
    }

    /**
     * Recursively sanitize sensitive keys from raw provider responses.
     */
    private function sanitizeOutput(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $sensitiveKeys = [
            'api_key', 'apikey', 'secret', 'password', 'token', 'access_token',
            'client_secret', 'auth', 'authorization', 'webhook_secret',
        ];

        $sanitized = [];
        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, $sensitiveKeys, true)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeOutput($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
