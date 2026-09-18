<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\Reports\NormalizedReportBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ProviderComparisonController extends Controller
{
    public function __construct(
        private NormalizedReportBuilder $reportBuilder,
    ){

    }
    public function index(): Response
    {
        return Inertia::render('super-admin/provider-lab', [
            'providers' => [
                [
                    'id' => 'checkcardetails',
                    'name' => 'CheckCarDetails',
                ],
                [
                    'id' => 'oneauto',
                    'name' => 'OneAuto',
                ],
            ],

            'csrfToken' => csrf_token(),
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => [
                'required',
                'in:checkcardetails,oneauto',
            ],
            'vrm' => [
                'required',
                'string',
                'max:10',
            ],
        ]);

        $vrm = strtoupper(preg_replace('/\s+/', '', $validated['vrm']));

        $startedAt = microtime(true);

        try{
            $result = $this->reportBuilder->build(
                vrm: $vrm,
                provider: $validated['provider'],
            );

            return response()->json([
                'provider' => $validated['provider'],
                'vrm' => $vrm,

                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),

                'meta' => $result['meta'] ?? [],
                'normalized' => $result['normalized'],
                'raw' => $result['raw'],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
