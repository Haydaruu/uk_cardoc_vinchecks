<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>
        {{ $data['vehicle']['make'] ?? 'Vehicle' }}
        {{ $data['vehicle']['model'] ?? '' }}
        - Vehicle History Report
    </title>

    <style>
        @page {
            size: A4;
            margin: 12mm 12mm 24mm 12mm;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
        }

        body {
            background: #ffffff;
            color: #172033;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10px;
            line-height: 1.45;
        }

        h1,
        h2,
        h3,
        p {
            margin: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        img {
            max-width: 100%;
        }

        /* =========================================================
           PAGE / FLOW HELPERS
        ========================================================= */

        .report {
            width: 100%;
        }

        .avoid-break,
        .keep-together {
            page-break-inside: avoid;
            break-inside: avoid-page;
        }

        .page-break {
            page-break-before: always;
            break-before: page;
        }

        .section-title {
            page-break-after: avoid;
            break-after: avoid-page;
        }

        /*
         * Recall currently contains a small block that was close
         * to the bottom of the previous page. Keeping the entire
         * section together is safer than splitting the source note.
         */
        .recall-section {
            page-break-before: always;
            break-before: page;
        }

        /* =========================================================
           HEADER
        ========================================================= */

        .cover {
            margin-bottom: 22px;
            padding-bottom: 18px;
            border-bottom: 2px solid #172033;
        }

        .eyebrow {
            margin-bottom: 6px;
            color: #64748b;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        .title {
            color: #172033;
            font-size: 24px;
            font-weight: 800;
            line-height: 1.15;
        }

        .subtitle {
            margin-top: 6px;
            color: #64748b;
            font-size: 11px;
        }

        .meta-row {
            margin-top: 12px;
        }

        .meta-chip {
            display: inline-block;
            margin-right: 6px;
            margin-bottom: 5px;
            padding: 4px 8px;
            border: 1px solid #d9dee8;
            border-radius: 999px;
            color: #475467;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
        }

        /* =========================================================
           SECTIONS
        ========================================================= */

        .section {
            margin-top: 18px;
        }

        .section-title {
            margin-bottom: 9px;
            color: #172033;
            font-size: 14px;
            font-weight: 800;
        }

        .section-description {
            margin-top: -4px;
            margin-bottom: 9px;
            color: #667085;
            font-size: 9px;
        }

        /* =========================================================
           GRID
        ========================================================= */

        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 7px;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 7px;
        }

        .grid-4 {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 7px;
        }

        /* =========================================================
           CARDS
        ========================================================= */

        .card {
            padding: 9px;
            border: 1px solid #d9dee8;
            border-radius: 6px;
            background: #ffffff;
        }

        .card-label {
            color: #667085;
            font-size: 8px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .card-value {
            margin-top: 3px;
            color: #172033;
            font-size: 11px;
            font-weight: 700;
        }

        .muted {
            color: #667085;
        }

        .small {
            font-size: 8px;
        }

        /* =========================================================
           STATUS
        ========================================================= */

        .status {
            display: inline-block;
            padding: 3px 7px;
            border-radius: 999px;
            font-size: 8px;
            font-weight: 800;
        }

        .status-success {
            background: #dcfce7;
            color: #166534;
        }

        .status-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .status-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-neutral {
            background: #eef2f7;
            color: #475467;
        }

        /* =========================================================
           SUMMARY
        ========================================================= */

        .summary-box {
            margin-bottom: 12px;
            padding: 12px;
            border: 1px solid #d9dee8;
            border-radius: 7px;
            background: #f8fafc;
        }

        .summary-main {
            font-size: 15px;
            font-weight: 800;
        }

        .summary-sub {
            margin-top: 4px;
            color: #667085;
            font-size: 9px;
        }

        /* =========================================================
           TABLES
        ========================================================= */

        .table-wrap {
            overflow: hidden;
            border: 1px solid #d9dee8;
            border-radius: 6px;
        }

        th,
        td {
            padding: 7px 8px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f8fafc;
            color: #667085;
            font-size: 8px;
            font-weight: 800;
            text-transform: uppercase;
        }

        td {
            color: #172033;
            font-size: 9px;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        /* =========================================================
           EMPTY / NOTES
        ========================================================= */

        .empty {
            padding: 12px;
            border: 1px dashed #cbd5e1;
            border-radius: 6px;
            color: #667085;
            background: #f8fafc;
        }

        .note {
            margin-top: 8px;
            padding: 8px 10px;
            border-left: 3px solid #94a3b8;
            background: #f8fafc;
            color: #667085;
            font-size: 8px;
        }

        .danger-note {
            border-left-color: #dc2626;
            background: #fef2f2;
            color: #991b1b;
        }

        .warning-note {
            border-left-color: #d97706;
            background: #fffbeb;
            color: #92400e;
        }

        /* =========================================================
           VEHICLE STORY
        ========================================================= */

        .timeline-item {
            position: relative;
            margin-bottom: 9px;
            padding: 9px 10px;
            border-left: 2px solid #cbd5e1;
            background: #f8fafc;
        }

        .timeline-date {
            margin-bottom: 2px;
            color: #667085;
            font-size: 8px;
            font-weight: 700;
        }

        .timeline-title {
            color: #172033;
            font-size: 9px;
            font-weight: 800;
        }

        .timeline-detail {
            margin-top: 2px;
            color: #667085;
            font-size: 8px;
        }

        /* =========================================================
            SALVAGE PHOTO GALLERY
        ========================================================= */

        .photo-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin-top: 10px;
        }

        .photo-card {
            height: 48mm;
            overflow: hidden;
            border: 1px solid #d9dee8;
            border-radius: 6px;
            background: #f8fafc;
            page-break-inside: avoid;
            break-inside: avoid-page;
        }

        .photo-card img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .photo-caption {
            margin-top: 4px;
            color: #94a3b8;
            font-size: 7px;
            text-align: center;
        }

        /* =========================================================
           FOOTER
        ========================================================= */

        .footer {
            position: fixed;
            right: 0;
            bottom: 0;
            left: 0;
            height: 8mm;
            padding-top: 2mm;
            border-top: 1px solid #d9dee8;
            background: #ffffff;
            color: #94a3b8;
            font-size: 7px;
            line-height: 1.2;
            text-align: center;
        }
    </style>
</head>

<body>

@php
    $vehicle = $data['vehicle'] ?? [];
    $status = $data['status'] ?? [];
    $v5c = $data['v5c'] ?? [];
    $history = $data['history'] ?? [];
    $salvage = $data['salvage'] ?? [];
    $specs = $data['specifications'] ?? [];
    $dimensions = $data['dimensions'] ?? [];
    $valuation = $data['valuation'] ?? [];
    $recalls = $data['recalls'] ?? [];
    $compliance = $data['emissions_compliance'] ?? [];
    $runningCosts = $data['running_costs'] ?? [];
    $mot = $data['mot'] ?? [];
    $meta = $data['meta'] ?? [];

    $formatMoney = function ($value) {
        return $value !== null && $value !== ''
            ? '£' . number_format((float) $value, 0)
            : 'Unavailable';
    };

    $formatDate = function ($value) {
        if (!$value) {
            return 'Unavailable';
        }

        try {
            return \Carbon\Carbon::parse($value)->format('d M Y');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    };

    $sectionCount = function ($section) {
        return $section['count']
            ?? count($section['records'] ?? []);
    };

    $availabilityLabel = function ($section) use ($sectionCount) {
        if (!($section['available'] ?? false)) {
            return 'Data unavailable';
        }

        $count = $sectionCount($section);

        return $count > 0
            ? $count . ' record(s) found'
            : 'No record found';
    };

    $boolLabel = function ($value) {
        if ($value === true) {
            return 'Yes';
        }

        if ($value === false) {
            return 'No';
        }

        return 'Unavailable';
    };

    $keeperRecords = $history['keepers']['records'] ?? [];
    $plateRecords = $history['plate_changes']['records'] ?? [];

    $financeCount = $sectionCount($history['finance'] ?? []);
    $stolenCount = $sectionCount($history['stolen'] ?? []);
    $writeOffCount = $sectionCount($history['write_off'] ?? []);
    $highRiskCount = $sectionCount($history['high_risk'] ?? []);

    $issueCount =
        ($financeCount > 0 ? 1 : 0) +
        ($stolenCount > 0 ? 1 : 0) +
        ($writeOffCount > 0 ? 1 : 0) +
        ($highRiskCount > 0 ? 1 : 0) +
        (
            ($salvage['available'] ?? false)
            && ($salvage['record_found'] ?? false)
            ? 1
            : 0
        );

    $unavailableCount =
        (!($history['finance']['available'] ?? false) ? 1 : 0) +
        (!($history['stolen']['available'] ?? false) ? 1 : 0) +
        (!($history['write_off']['available'] ?? false) ? 1 : 0) +
        (!($history['high_risk']['available'] ?? false) ? 1 : 0) +
        (!($salvage['available'] ?? false) ? 1 : 0);

    if ($issueCount > 0) {
        $overallState = 'issue';
    } elseif ($unavailableCount > 0) {
        $overallState = 'partial';
    } else {
        $overallState = 'clear';
    }

    $latestMileage = collect($mot['mileage_history'] ?? [])
        ->filter(fn ($item) => ($item['mileage'] ?? null) !== null)
        ->sortBy(fn ($item) => $item['date'] ?? '')
        ->last();

    $latestMot = collect($mot['tests'] ?? [])
        ->sortByDesc(fn ($item) => $item['date'] ?? '')
        ->first();

    $motStatus =
        data_get($mot, 'current.mot_status')
        ?? data_get($mot, 'status')
        ?? null;

    $motExpiry =
        data_get($mot, 'current.mot_expiry_date')
        ?? data_get($mot, 'expiry_date')
        ?? null;

    $taxStatus =
        data_get($mot, 'current.tax_status')
        ?? data_get($mot, 'tax_status')
        ?? null;

    $taxExpiry =
        data_get($mot, 'current.tax_expiry_date')
        ?? data_get($mot, 'tax_expiry_date')
        ?? null;
@endphp

<div class="report">

    {{-- =========================================================
         HEADER
    ========================================================== --}}

    <section class="cover keep-together">
        <div class="eyebrow">
            UK Vehicle History Report
        </div>

        <h1 class="title">
            {{ $vehicle['make'] ?? 'Unknown Make' }}
            {{ $vehicle['model'] ?? '' }}
        </h1>

        <p class="subtitle">
            Registration:
            <strong>{{ $vehicle['vrm'] ?? 'Unavailable' }}</strong>

            @if (!empty($vehicle['vin']))
                &nbsp; | &nbsp;

                VIN:
                <strong>{{ $vehicle['vin'] }}</strong>
            @endif
        </p>

        <div class="meta-row">
            <span class="meta-chip">
                Provider:
                {{ $meta['provider'] ?? 'Unknown' }}
            </span>

            <span class="meta-chip">
                Schema:
                {{ $meta['schema_version'] ?? 'Unknown' }}
            </span>

            <span class="meta-chip">
                Generated:
                {{ $formatDate($report->generated_at) }}
            </span>

            @if ($overallState === 'issue')
                <span class="status status-danger">
                    Records requiring attention
                </span>
            @elseif ($overallState === 'partial')
                <span class="status status-warning">
                    Some data unavailable
                </span>
            @else
                <span class="status status-success">
                    No recorded critical issues in checked data
                </span>
            @endif
        </div>
    </section>

    {{-- =========================================================
         VEHICLE OVERVIEW
    ========================================================== --}}

    <section class="section keep-together">
        <h2 class="section-title">
            Vehicle Overview
        </h2>

        <div class="grid-4">

            <div class="card">
                <div class="card-label">
                    Registration
                </div>

                <div class="card-value">
                    {{ $vehicle['vrm'] ?? 'Unavailable' }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    VIN
                </div>

                <div class="card-value small">
                    {{ $vehicle['vin'] ?? 'Unavailable' }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Year
                </div>

                <div class="card-value">
                    {{ $vehicle['year'] ?? 'Unavailable' }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Colour
                </div>

                <div class="card-value">
                    {{ $vehicle['colour'] ?? 'Unavailable' }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Fuel
                </div>

                <div class="card-value">
                    {{ $vehicle['fuel_type'] ?? 'Unavailable' }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Transmission
                </div>

                <div class="card-value">
                    {{ $vehicle['transmission'] ?? 'Unavailable' }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Body Type
                </div>

                <div class="card-value">
                    {{ $vehicle['body_type'] ?? 'Unavailable' }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Engine
                </div>

                <div class="card-value">
                    {{ $vehicle['engine_capacity_cc'] !== null
                        ? number_format($vehicle['engine_capacity_cc']) . ' cc'
                        : 'Unavailable' }}
                </div>
            </div>

        </div>
    </section>

    {{-- =========================================================
         VEHICLE STATUS
    ========================================================== --}}

    <section class="section keep-together">
        <h2 class="section-title">
            Vehicle Status
        </h2>

        <div class="grid-4">

            <div class="card">
                <div class="card-label">
                    Scrapped
                </div>

                <div class="card-value">
                    {{ $boolLabel($status['scrapped'] ?? null) }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Imported
                </div>

                <div class="card-value">
                    {{ $boolLabel($status['imported'] ?? null) }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Exported
                </div>

                <div class="card-value">
                    {{ $boolLabel($status['exported'] ?? null) }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Certificate of Destruction
                </div>

                <div class="card-value">
                    {{
                        $boolLabel(
                            $status['certificate_of_destruction_issued']
                                ?? null
                        )
                    }}
                </div>
            </div>

        </div>

        @if (!empty($status['scrapped_date']))
            <div class="note danger-note">
                Scrapped date:
                <strong>
                    {{ $formatDate($status['scrapped_date']) }}
                </strong>
            </div>
        @endif
    </section>

    {{-- =========================================================
         VEHICLE STORY
    ========================================================== --}}

    <section class="section">
        <h2 class="section-title">
            Vehicle Story
        </h2>

        <p class="section-description">
            A chronological view of recorded vehicle lifecycle events.
        </p>

        @php
            $storyEvents = [];

            foreach ($v5c['history'] ?? [] as $item) {
                if (!empty($item['issue_date'])) {
                    $storyEvents[] = [
                        'date' => $item['issue_date'],
                        'title' => 'V5C / logbook issued',
                        'detail' => 'A V5C document issue event was recorded.',
                    ];
                }
            }

            foreach ($keeperRecords as $item) {
                if (!empty($item['change_date'])) {
                    $storyEvents[] = [
                        'date' => $item['change_date'],
                        'title' => 'Keeper change recorded',
                        'detail' => isset($item['previous_keepers'])
                            ? $item['previous_keepers'] . ' previous keeper(s) recorded.'
                            : null,
                    ];
                }
            }

            foreach ($plateRecords as $item) {
                $date =
                    $item['transfer_date']
                    ?? $item['receipt_date']
                    ?? null;

                if ($date) {
                    $storyEvents[] = [
                        'date' => $date,
                        'title' => 'Registration plate change',
                        'detail' =>
                            ($item['previous_vrm'] ?? 'Unknown')
                            . ' -> '
                            . ($item['current_vrm'] ?? 'Unknown'),
                    ];
                }
            }

            foreach ($mot['tests'] ?? [] as $test) {
                if (!empty($test['date'])) {
                    $storyEvents[] = [
                        'date' => $test['date'],
                        'title' => 'MOT ' . ($test['result'] ?? 'recorded'),
                        'detail' =>
                            $test['mileage'] !== null
                            ? number_format($test['mileage'])
                                . ' '
                                . ($test['mileage_unit'] ?? 'mi')
                            : null,
                    ];
                }
            }

            if (!empty($status['scrapped_date'])) {
                $storyEvents[] = [
                    'date' => $status['scrapped_date'],
                    'title' => 'Vehicle recorded as scrapped',
                    'detail' => null,
                ];
            }

            usort(
                $storyEvents,
                fn ($a, $b) =>
                    strcmp(
                        $b['date'],
                        $a['date']
                    )
            );
        @endphp

        @if (count($storyEvents))
            @foreach ($storyEvents as $event)
                <div class="timeline-item keep-together">

                    <div class="timeline-date">
                        {{ $formatDate($event['date']) }}
                    </div>

                    <div class="timeline-title">
                        {{ $event['title'] }}
                    </div>

                    @if ($event['detail'])
                        <div class="timeline-detail">
                            {{ $event['detail'] }}
                        </div>
                    @endif

                </div>
            @endforeach
        @else
            <div class="empty">
                No dated vehicle history events are available.
            </div>
        @endif
    </section>

    {{-- =========================================================
         SPECIFICATIONS
    ========================================================== --}}

    <section class="section keep-together">

        <h2 class="section-title">
            Vehicle Specifications
        </h2>

        <div class="grid-4">

            <div class="card">
                <div class="card-label">
                    Power
                </div>

                <div class="card-value">
                    {{
                        $specs['power_bhp'] !== null
                        ? $specs['power_bhp'] . ' bhp'
                        : 'Unavailable'
                    }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Torque
                </div>

                <div class="card-value">
                    {{
                        $specs['torque_nm'] !== null
                        ? $specs['torque_nm'] . ' Nm'
                        : 'Unavailable'
                    }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Combined MPG
                </div>

                <div class="card-value">
                    {{
                        $specs['combined_mpg'] !== null
                        ? $specs['combined_mpg']
                        : 'Unavailable'
                    }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Euro Standard
                </div>

                <div class="card-value">
                    {{ $specs['euro_standard'] ?? 'Unavailable' }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    CO2
                </div>

                <div class="card-value">
                    {{
                        $specs['co2_gkm'] !== null
                        ? $specs['co2_gkm'] . ' g/km'
                        : 'Unavailable'
                    }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Top Speed
                </div>

                <div class="card-value">
                    {{
                        $specs['top_speed_mph'] !== null
                        ? $specs['top_speed_mph'] . ' mph'
                        : 'Unavailable'
                    }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    0-60 mph
                </div>

                <div class="card-value">
                    {{
                        $specs['zero_to_sixty_mph'] !== null
                        ? $specs['zero_to_sixty_mph'] . ' sec'
                        : 'Unavailable'
                    }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Insurance Group
                </div>

                <div class="card-value">
                    {{ $specs['insurance_group'] ?? 'Unavailable' }}
                </div>
            </div>

        </div>

    </section>

    {{-- =========================================================
         HISTORY CHECKS
    ========================================================== --}}

    <section class="section">

        <h2 class="section-title">
            History Checks
        </h2>

        <div class="grid-2">

            <div class="card">
                <div class="card-label">
                    Finance
                </div>

                <div class="card-value">
                    {{ $availabilityLabel($history['finance'] ?? []) }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Stolen
                </div>

                <div class="card-value">
                    {{ $availabilityLabel($history['stolen'] ?? []) }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Write-off
                </div>

                <div class="card-value">
                    {{ $availabilityLabel($history['write_off'] ?? []) }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    High Risk
                </div>

                <div class="card-value">
                    {{ $availabilityLabel($history['high_risk'] ?? []) }}
                </div>
            </div>

        </div>

        @if ($issueCount > 0)

            <div class="note danger-note">
                One or more checked datasets contain recorded events
                requiring attention.
            </div>

        @elseif ($unavailableCount > 0)

            <div class="note warning-note">
                Some datasets were unavailable and therefore were not
                treated as clear.
            </div>

        @else

            <div class="note">
                No records were returned in the checked critical
                history datasets.
            </div>

        @endif

    </section>

    {{-- =========================================================
         MOT + MILEAGE
    ========================================================== --}}

    <section class="section">

        <h2 class="section-title">
            MOT & Mileage
        </h2>

        <div class="grid-4">

            <div class="card">
                <div class="card-label">
                    Current MOT
                </div>

                <div class="card-value">
                    {{ $motStatus ?? 'Unavailable' }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    MOT Expiry
                </div>

                <div class="card-value">
                    {{ $formatDate($motExpiry) }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Latest Mileage
                </div>

                <div class="card-value">
                    {{
                        $latestMileage
                        ? number_format($latestMileage['mileage'])
                            . ' '
                            . ($latestMileage['unit'] ?? 'mi')
                        : 'Unavailable'
                    }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    MOT Tests
                </div>

                <div class="card-value">
                    {{ count($mot['tests'] ?? []) }}
                </div>
            </div>

        </div>

        @if (!empty($mot['tests']))

            <div
                class="table-wrap"
                style="margin-top: 9px;"
            >
                <table>

                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Result</th>
                            <th>Mileage</th>
                            <th>Expiry</th>
                            <th>Defects</th>
                        </tr>
                    </thead>

                    <tbody>

                        @foreach ($mot['tests'] as $test)

                            <tr>

                                <td>
                                    {{ $formatDate($test['date'] ?? null) }}
                                </td>

                                <td>
                                    {{ $test['result'] ?? 'Unavailable' }}
                                </td>

                                <td>
                                    {{
                                        $test['mileage'] !== null
                                        ? number_format($test['mileage'])
                                            . ' '
                                            . ($test['mileage_unit'] ?? 'mi')
                                        : 'Unavailable'
                                    }}
                                </td>

                                <td>
                                    {{ $formatDate($test['expiry_date'] ?? null) }}
                                </td>

                                <td>
                                    {{ count($test['defects'] ?? []) }}
                                </td>

                            </tr>

                        @endforeach

                    </tbody>

                </table>
            </div>

        @else

            <div class="empty">
                No MOT test history is available.
            </div>

        @endif

    </section>

    {{-- =========================================================
         RUNNING COSTS
    ========================================================== --}}

    <section class="section keep-together">

        <h2 class="section-title">
            Estimated Running Costs
        </h2>

        <div class="grid-3">

            <div class="card">
                <div class="card-label">
                    Annual Road Tax
                </div>

                <div class="card-value">
                    {{
                        data_get(
                            $runningCosts,
                            'road_tax.annual'
                        ) !== null
                        ? $formatMoney(
                            data_get(
                                $runningCosts,
                                'road_tax.annual'
                            )
                        )
                        : 'Unavailable'
                    }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Insurance Estimate
                </div>

                <div class="card-value">
                    {{
                        data_get(
                            $runningCosts,
                            'insurance.age_40'
                        ) !== null
                        ? $formatMoney(
                            data_get(
                                $runningCosts,
                                'insurance.age_40'
                            )
                        )
                        : 'Unavailable'
                    }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Combined MPG
                </div>

                <div class="card-value">
                    {{
                        data_get(
                            $runningCosts,
                            'fuel.combined_mpg'
                        ) !== null
                        ? data_get(
                            $runningCosts,
                            'fuel.combined_mpg'
                        ) . ' mpg'
                        : 'Unavailable'
                    }}
                </div>
            </div>

        </div>

        <div class="note">
            Running-cost figures are estimates/available source values.
            MPG is an efficiency measure, not an annual fuel-cost calculation.
        </div>

    </section>

    {{-- =========================================================
         VALUATION
    ========================================================== --}}

    <section class="section keep-together">

        <h2 class="section-title">
            Vehicle Valuation
        </h2>

        <div class="grid-4">

            <div class="card">
                <div class="card-label">
                    Retail
                </div>

                <div class="card-value">
                    {{ $formatMoney($valuation['retail'] ?? null) }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Trade-in
                </div>

                <div class="card-value">
                    {{ $formatMoney($valuation['trade_in'] ?? null) }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Private
                </div>

                <div class="card-value">
                    {{ $formatMoney($valuation['private'] ?? null) }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Trade
                </div>

                <div class="card-value">
                    {{ $formatMoney($valuation['trade'] ?? null) }}
                </div>
            </div>

        </div>

    </section>

    {{-- =========================================================
         V5C
    ========================================================== --}}

    <section class="section keep-together">

        <h2 class="section-title">
            V5C / Logbook
        </h2>

        <div class="grid-4">

            <div class="card">
                <div class="card-label">
                    V5C Count
                </div>

                <div class="card-value">
                    {{ $v5c['count'] ?? 'Unavailable' }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Latest Issue
                </div>

                <div class="card-value">
                    {{ $formatDate($v5c['latest_issue_date'] ?? null) }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Serial Verified
                </div>

                <div class="card-value">
                    {{ $boolLabel($v5c['serial_verified'] ?? null) }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Verification Available
                </div>

                <div class="card-value">
                    {{ $boolLabel($v5c['verification_available'] ?? null) }}
                </div>
            </div>

        </div>

        <div class="note warning-note">
            A V5C issue history is not the same thing as physical V5C
            serial verification. "Verified" must only be shown when actual
            serial validation exists.
        </div>

    </section>

    {{-- =========================================================
         SALVAGE
    ========================================================== --}}

    <section class="section">

        <h2 class="section-title">
            Salvage Auction History
        </h2>

        @if (!($salvage['available'] ?? false))

            <div class="empty">
                Data unavailable.
            </div>

        @elseif (!($salvage['record_found'] ?? false))

            <div class="card keep-together">
                <span class="status status-success">
                    No record found
                </span>
            </div>

        @else

            @foreach ($salvage['records'] ?? [] as $record)

                <div
                    class="card keep-together"
                    style="margin-bottom: 10px;"
                >

                    <div class="grid-3">

                        <div>
                            <div class="card-label">
                                Auction Reference
                            </div>

                            <div class="card-value">
                                {{
                                    $record['salvage_auction_reference']
                                    ?? $record['salvage_auction_record_id']
                                    ?? 'Unavailable'
                                }}
                            </div>
                        </div>

                        <div>
                            <div class="card-label">
                                Date
                            </div>

                            <div class="card-value">
                                {{
                                    $formatDate(
                                        $record['salvage_auction_lot_date']
                                        ?? null
                                    )
                                }}
                            </div>
                        </div>

                        <div>
                            <div class="card-label">
                                Mileage
                            </div>

                            <div class="card-value">
                                {{
                                    $record['mileage'] !== null
                                    ? number_format($record['mileage'])
                                    : 'Unavailable'
                                }}
                            </div>
                        </div>

                        <div>
                            <div class="card-label">
                                Primary Damage
                            </div>

                            <div class="card-value">
                                {{
                                    $record['primary_damage_desc']
                                    ?? 'Unavailable'
                                }}
                            </div>
                        </div>

                        <div>
                            <div class="card-label">
                                Secondary Damage
                            </div>

                            <div class="card-value">
                                {{
                                    $record['secondary_damage_desc']
                                    ?? 'Unavailable'
                                }}
                            </div>
                        </div>

                        <div>
                            <div class="card-label">
                                Location
                            </div>

                            <div class="card-value">
                                {{
                                    $record['salvage_auction_location']
                                    ?? 'Unavailable'
                                }}
                            </div>
                        </div>

                    </div>

                    @php
                        $photoUrls = collect(
                            $record['external_image_urls'] ?? []
                        )
                            ->filter(
                                fn ($url) =>
                                    is_string($url)
                                    && preg_match(
                                        '/^https?:\/\//i',
                                        trim($url)
                                    )
                            )
                            ->take(6)
                            ->values();
                    @endphp

                    @if ($photoUrls->isNotEmpty())

                        <div class="photo-grid">

                            @foreach ($photoUrls as $index => $photoUrl)

                                <div class="keep-together">

                                    <div class="photo-card">
                                        <img
                                            src="{{ $photoUrl }}"
                                            alt="Salvage auction photo {{ $index + 1 }}"
                                        >
                                    </div>

                                    <div class="photo-caption">
                                        Auction photo {{ $index + 1 }}
                                    </div>

                                </div>

                            @endforeach

                        </div>

                    @endif

                </div>

            @endforeach

        @endif

    </section>

    {{-- =========================================================
         RECALL
    ========================================================== --}}

    <section class="section recall-section">

        <h2 class="section-title">
            Recall Information
        </h2>

        @if (!($recalls['available'] ?? false))

            <div class="empty">
                Data unavailable.
            </div>

        @elseif (
            ($recalls['count']
                ?? count($recalls['records'] ?? []))
            === 0
        )

            <div class="card keep-together">

                <span class="status status-success">
                    No record found
                </span>

            </div>

        @else

            <div class="card keep-together">

                <div class="card-label">
                    Recall Records
                </div>

                <div class="card-value">
                    {{
                        $recalls['count']
                        ?? count($recalls['records'] ?? [])
                    }}
                </div>

            </div>

            @if (!empty($recalls['source']))

                <div class="note keep-together">
                    Source:
                    <strong>
                        {{ $recalls['source'] }}
                    </strong>
                </div>

            @endif

        @endif

    </section>

    {{-- =========================================================
         KEEPER + PLATE
    ========================================================== --}}

    <section class="section">

        <h2 class="section-title">
            Keeper & Registration History
        </h2>

        <div class="grid-2">

            <div class="card">
                <div class="card-label">
                    Keeper Changes
                </div>

                <div class="card-value">
                    {{
                        $history['keepers']['count']
                        ?? count($keeperRecords)
                    }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Plate Changes
                </div>

                <div class="card-value">
                    {{
                        $history['plate_changes']['count']
                        ?? count($plateRecords)
                    }}
                </div>
            </div>

        </div>

        @if (count($plateRecords))

            <div
                class="table-wrap"
                style="margin-top: 9px;"
            >
                <table>

                    <thead>
                        <tr>
                            <th>
                                Previous VRM
                            </th>

                            <th>
                                Current VRM
                            </th>

                            <th>
                                Transfer Date
                            </th>

                            <th>
                                Type
                            </th>
                        </tr>
                    </thead>

                    <tbody>

                        @foreach ($plateRecords as $record)

                            <tr>

                                <td>
                                    {{
                                        $record['previous_vrm']
                                        ?? 'Unavailable'
                                    }}
                                </td>

                                <td>
                                    {{
                                        $record['current_vrm']
                                        ?? 'Unavailable'
                                    }}
                                </td>

                                <td>
                                    {{
                                        $formatDate(
                                            $record['transfer_date']
                                            ?? $record['receipt_date']
                                            ?? null
                                        )
                                    }}
                                </td>

                                <td>
                                    {{
                                        $record['transfer_type']
                                        ?? 'Unavailable'
                                    }}
                                </td>

                            </tr>

                        @endforeach

                    </tbody>

                </table>
            </div>

        @endif

    </section>

    {{-- =========================================================
         ULEZ
    ========================================================== --}}

    <section class="section keep-together">

        <h2 class="section-title">
            ULEZ / Clean Air Assessment
        </h2>

        <div class="grid-4">

            <div class="card">
                <div class="card-label">
                    Fuel Type
                </div>

                <div class="card-value">
                    {{ $compliance['fuel_type'] ?? 'Unavailable' }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Euro Standard
                </div>

                <div class="card-value">
                    {{ $compliance['euro_standard'] ?? 'Unavailable' }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Assessment
                </div>

                <div class="card-value">
                    {{ $compliance['assessment_type'] ?? 'Unavailable' }}
                </div>
            </div>

            <div class="card">
                <div class="card-label">
                    Officially Verified
                </div>

                <div class="card-value">
                    {{
                        $boolLabel(
                            $compliance['officially_verified']
                                ?? null
                        )
                    }}
                </div>
            </div>

        </div>

        <div class="note warning-note">
            This section represents the available compliance assessment
            and must not be interpreted as an official determination
            for every clean-air zone.
        </div>

    </section>

    {{-- =========================================================
         FINAL REPORT NOTE
    ========================================================== --}}

    <section class="section keep-together">

        <div class="note">
            This report reflects vehicle data available at the time it was
            generated. Data availability may vary by provider and source.
            "Data unavailable" means the relevant dataset could not be
            verified from the available source and should not be interpreted
            as a clear result.
        </div>

    </section>

</div>

{{-- =============================================================
     FIXED FOOTER
============================================================= --}}

<div class="footer">
    UK Vehicle History Report
    -
    {{ $vehicle['vrm'] ?? 'Vehicle' }}
    -
    Generated
    {{ $formatDate($report->generated_at) }}
</div>

</body>
</html>