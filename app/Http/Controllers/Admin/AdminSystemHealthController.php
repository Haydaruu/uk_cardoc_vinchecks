<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminSystemHealthService;
use App\Services\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminSystemHealthController extends Controller
{
    public function __construct(
        protected AdminSystemHealthService $healthService,
        protected AdminAuditService $auditService,
    ) {}

    /**
     * Display the System Health and Diagnostics dashboard.
     */
    public function index(Request $request): Response
    {
        $health = $this->healthService->checkAll();

        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(403);
        }

        $this->auditService->log(
            admin: $actor,
            action: 'system_health.viewed',
            targetType: 'system',
            targetId: 0,
            reason: 'Viewed system health diagnostics dashboard',
            outcome: 'success',
            metadata: [
                'overall_status' => $health['overall_status'],
            ],
            request: $request,
        );

        return Inertia::render('admin/system-health', [
            'health' => $health,
        ]);
    }

    /**
     * Run fresh lightweight health probes on demand.
     */
    public function refresh(Request $request): JsonResponse
    {
        $health = $this->healthService->checkAll();

        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(403);
        }

        $this->auditService->log(
            admin: $actor,
            action: 'system_health.refreshed',
            targetType: 'system',
            targetId: 0,
            reason: 'Executed fresh system health diagnostic probes',
            outcome: 'success',
            metadata: [
                'overall_status' => $health['overall_status'],
            ],
            request: $request,
        );

        return response()->json([
            'health' => $health,
            'message' => 'System health probes refreshed successfully.',
        ]);
    }
}
