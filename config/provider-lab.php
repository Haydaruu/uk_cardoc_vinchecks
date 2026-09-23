<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider Registry
    |--------------------------------------------------------------------------
    |
    | Semua provider yang tersedia di Comparison Lab.
    |
    | Kalau nanti UK Vehicle Data / MotorCheck masuk,
    | kita cukup tambah entry baru di sini.
    |
    */

    'providers' => [

        'checkcardetails' => [
            'name' => 'CheckCarDetails',

            /*
             * Role sementara dalam arsitektur kita.
             */
            'role' => 'base',

            /*
             * Status integrasi ke codebase.
             */
            'integration_status' => 'integrated',

            /*
             * Kondisi sandbox / trial yang kita punya.
             */
            'sandbox_status' => 'available',

            /*
             * Status evaluasi vendor.
             */
            'evaluation_status' => 'testing',

            /*
             * Cost sengaja nullable.
             * Isi setelah vendor pricing benar-benar confirmed.
             */
            'estimated_cost_per_check_gbp' => null,

            /*
             * Jangan menaruh asumsi marketing vendor di sini.
             * Isi setelah kita verify provenance-nya.
             */
            'provenance_status' => 'needs_review',

            'notes' =>
                'Currently used as the base provider for general vehicle data, MOT, specifications and valuation.',
        ],

        'oneauto' => [
            'name' => 'OneAuto',

            'role' => 'critical_history',

            'integration_status' => 'integrated',

            'sandbox_status' => 'available',

            'evaluation_status' => 'testing',

            'estimated_cost_per_check_gbp' => null,

            'provenance_status' => 'needs_review',

            'notes' =>
                'Currently evaluated for finance, stolen, write-off, high-risk, salvage and recall enrichment.',
        ],

        /*
         * Jangan aktifkan sebelum adapter-nya ada.
         *
         * Nanti bentuknya tinggal seperti:
         *
         * 'ukvehicledata' => [
         *     'name' => 'UK Vehicle Data',
         *     ...
         * ],
         */
    ],

];