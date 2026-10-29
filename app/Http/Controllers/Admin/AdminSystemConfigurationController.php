<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSystemConfigurationRequest;
use App\Models\User;
use App\Services\Admin\AdminSystemConfigurationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminSystemConfigurationController extends Controller
{
    public function __construct(
        protected AdminSystemConfigurationService $configurationService,
    ) {}

    /**
     * Render the system configuration page with allowlisted settings and safe infrastructure summaries.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('admin/system-configuration', [
            'settings' => $this->configurationService->getSettings(),
            'infrastructure' => $this->configurationService->getInfrastructureStatus(),
        ]);
    }

    /**
     * Update allowlisted configuration parameters with audit logging.
     */
    public function update(UpdateSystemConfigurationRequest $request): RedirectResponse
    {
        $admin = $request->user();
        assert($admin instanceof User);

        /** @var array<string, mixed> $settings */
        $settings = (array) $request->input('settings', []);
        $reason = (string) $request->validated('reason');

        $this->configurationService->updateSettings(
            admin: $admin,
            updates: $settings,
            reason: $reason,
            request: $request,
        );

        return back()->with('success', 'System configuration updated successfully.');
    }
}
