<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hybrid Report Strategy
    |--------------------------------------------------------------------------
    |
    | Menentukan provider yang bertanggung jawab untuk setiap bagian
    | normalized premium report.
    |
    | Ini sengaja diletakkan di config supaya nanti kita bisa mengganti
    | provider tanpa mengubah CompositeReportBuilder.
    |
    */

    'hybrid' => [

        'sources' => [

            /*
             * GENERAL / BASE DATA
             */
            'vehicle' => 'checkcardetails',
            'v5c' => 'checkcardetails',
            'specifications' => 'checkcardetails',
            'dimensions' => 'checkcardetails',
            'valuation' => 'checkcardetails',
            'emissions_compliance' => 'checkcardetails',
            'mot' => 'checkcardetails',

            /*
             * CRITICAL HISTORY
             */
            'status' => 'oneauto',

            'history.finance' => 'oneauto',
            'history.stolen' => 'oneauto',
            'history.write_off' => 'oneauto',
            'history.high_risk' => 'oneauto',

            'history.keepers' => 'oneauto',
            'history.plate_changes' => 'oneauto',

            'history.colour_changes' => 'checkcardetails',

            /*
             * PREMIUM / SPECIALIST DATA
             */
            'salvage' => 'oneauto',
            'recalls' => 'oneauto',

            /*
             * RUNNING COSTS
             */
            'running_costs.road_tax' => 'checkcardetails',
            'running_costs.insurance' => 'oneauto',
            'running_costs.fuel' => 'checkcardetails',
        ],

        /*
        |--------------------------------------------------------------------------
        | Source Profiles
        |--------------------------------------------------------------------------
        |
        | Metadata untuk Comparison Lab.
        |
        | Ini bukan data yang ditampilkan sebagai fakta kendaraan.
        | Ini metadata internal tentang bagaimana kita memperlakukan setiap
        | section.
        |
        */

        'profiles' => [

            'vehicle' => [
                'criticality' => 'medium',
                'purpose' => 'Base vehicle identity and registration details.',
            ],

            'v5c' => [
                'criticality' => 'high',
                'purpose' => 'V5C issue history. Serial verification is currently not available.',
            ],

            'status' => [
                'criticality' => 'high',
                'purpose' => 'Scrapped, imported and exported vehicle status.',
            ],

            'history.finance' => [
                'criticality' => 'critical',
                'purpose' => 'Outstanding finance and finance-related vehicle history.',
            ],

            'history.stolen' => [
                'criticality' => 'critical',
                'purpose' => 'Stolen vehicle records.',
            ],

            'history.write_off' => [
                'criticality' => 'critical',
                'purpose' => 'Insurance write-off and loss-category history.',
            ],

            'history.high_risk' => [
                'criticality' => 'critical',
                'purpose' => 'High-risk vehicle markers.',
            ],

            'history.keepers' => [
                'criticality' => 'medium',
                'purpose' => 'Previous keeper and keeper-change history.',
            ],

            'history.plate_changes' => [
                'criticality' => 'medium',
                'purpose' => 'Registration and cherished plate transfer history.',
            ],

            'history.colour_changes' => [
                'criticality' => 'low',
                'purpose' => 'Recorded vehicle colour changes.',
            ],

            'salvage' => [
                'criticality' => 'critical',
                'purpose' => 'Salvage auction history, damage records and available auction media.',
            ],

            'specifications' => [
                'criticality' => 'low',
                'purpose' => 'Technical vehicle specifications.',
            ],

            'dimensions' => [
                'criticality' => 'low',
                'purpose' => 'Physical dimensions and vehicle weights.',
            ],

            'valuation' => [
                'criticality' => 'medium',
                'purpose' => 'Estimated market valuation.',
            ],

            'recalls' => [
                'criticality' => 'high',
                'purpose' => 'Vehicle or model safety recall information.',
            ],

            'emissions_compliance' => [
                'criticality' => 'medium',
                'purpose' => 'ULEZ / clean-air assessment. May be derived rather than officially verified.',
            ],

            'running_costs.road_tax' => [
                'criticality' => 'low',
                'purpose' => 'Estimated or recorded road-tax cost.',
            ],

            'running_costs.insurance' => [
                'criticality' => 'low',
                'purpose' => 'Estimated insurance cost.',
            ],

            'running_costs.fuel' => [
                'criticality' => 'low',
                'purpose' => 'Fuel economy and derived fuel-running information.',
            ],

            'mot' => [
                'criticality' => 'high',
                'purpose' => 'MOT test, mileage and defect history.',
            ],
        ],
    ],
];