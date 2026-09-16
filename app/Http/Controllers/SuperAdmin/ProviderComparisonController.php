<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class ProviderComparisonController extends Controller
{
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
        ]);
    }
}
