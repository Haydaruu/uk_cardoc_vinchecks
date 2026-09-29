<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>Vehicle Report</title>

    <style>
        @page {
            size: A4;
            margin: 12mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: #172033;
            font-size: 12px;
            line-height: 1.5;
        }

        h1,
        h2,
        h3 {
            margin: 0;
        }

        .header {
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #172033;
        }

        .eyebrow {
            margin-bottom: 4px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #667085;
        }

        .vehicle-title {
            font-size: 24px;
            font-weight: 700;
        }

        .vehicle-subtitle {
            margin-top: 4px;
            color: #667085;
        }

        .section {
            margin-top: 20px;
            page-break-inside: avoid;
        }

        .section-title {
            margin-bottom: 10px;
            font-size: 15px;
            font-weight: 700;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .item {
            padding: 10px;
            border: 1px solid #d9dee8;
            border-radius: 6px;
        }

        .label {
            font-size: 10px;
            color: #667085;
            text-transform: uppercase;
        }

        .value {
            margin-top: 3px;
            font-weight: 600;
        }

        .footer-note {
            margin-top: 30px;
            padding-top: 12px;
            border-top: 1px solid #d9dee8;
            color: #667085;
            font-size: 10px;
        }
    </style>
</head>

<body>

    @php
        $vehicle = $data['vehicle'] ?? [];
        $status = $data['status'] ?? [];
        $meta = $data['meta'] ?? [];
    @endphp

    <div class="header">
        <div class="eyebrow">UK Vehicle History Report</div>

        <h1 class="vehicle-title">
            {{ $vehicle['make'] ?? 'Unknown Make' }}
            {{ $vehicle['model'] ?? '' }}
        </h1>

        <div class="vehicle-subtitle">
            Registration:
            {{ $vehicle['vrm'] ?? 'N/A' }}
        </div>
    </div>

    <div class="section">
        <h2 class="section-title">Vehicle Overview</h2>

        <div class="grid">
            <div class="item">
                <div class="label">Registration</div>
                <div class="value">
                    {{ $vehicle['vrm'] ?? 'N/A' }}
                </div>
            </div>

            <div class="item">
                <div class="label">VIN</div>
                <div class="value">
                    {{ $vehicle['vin'] ?? 'N/A' }}
                </div>
            </div>

            <div class="item">
                <div class="label">Year</div>
                <div class="value">
                    {{ $vehicle['year'] ?? 'N/A' }}
                </div>
            </div>

            <div class="item">
                <div class="label">Fuel</div>
                <div class="value">
                    {{ $vehicle['fuel_type'] ?? 'N/A' }}
                </div>
            </div>

            <div class="item">
                <div class="label">Transmission</div>
                <div class="value">
                    {{ $vehicle['transmission'] ?? 'N/A' }}
                </div>
            </div>

            <div class="item">
                <div class="label">Colour</div>
                <div class="value">
                    {{ $vehicle['colour'] ?? 'N/A' }}
                </div>
            </div>
        </div>
    </div>

    <div class="section">
        <h2 class="section-title">Vehicle Status</h2>

        <div class="grid">
            <div class="item">
                <div class="label">Scrapped</div>
                <div class="value">
                    {{ $status['scrapped'] === true ? 'Yes' : ($status['scrapped'] === false ? 'No' : 'Unavailable') }}
                </div>
            </div>

            <div class="item">
                <div class="label">Imported</div>
                <div class="value">
                    {{ $status['imported'] === true ? 'Yes' : ($status['imported'] === false ? 'No' : 'Unavailable') }}
                </div>
            </div>

            <div class="item">
                <div class="label">Exported</div>
                <div class="value">
                    {{ $status['exported'] === true ? 'Yes' : ($status['exported'] === false ? 'No' : 'Unavailable') }}
                </div>
            </div>

            <div class="item">
                <div class="label">Certificate of Destruction</div>
                <div class="value">
                    {{ $status['certificate_of_destruction_issued'] === true ? 'Issued' : ($status['certificate_of_destruction_issued'] === false ? 'Not issued' : 'Unavailable') }}
                </div>
            </div>
        </div>
    </div>

    <div class="section">
        <h2 class="section-title">Report Metadata</h2>

        <div class="grid">
            <div class="item">
                <div class="label">Provider</div>
                <div class="value">
                    {{ $meta['provider'] ?? 'N/A' }}
                </div>
            </div>

            <div class="item">
                <div class="label">Schema Version</div>
                <div class="value">
                    {{ $meta['schema_version'] ?? 'N/A' }}
                </div>
            </div>

            <div class="item">
                <div class="label">Generated At</div>
                <div class="value">
                    {{ optional($report->generated_at)->format('d M Y H:i') ?? 'N/A' }}
                </div>
            </div>

            <div class="item">
                <div class="label">Format</div>
                <div class="value">
                    {{ $meta['format'] ?? 'N/A' }}
                </div>
            </div>
        </div>
    </div>

    <div class="footer-note">
        This report reflects vehicle data available at the time the report was generated.
        Data availability may vary by provider and source.
    </div>

</body>
</html>