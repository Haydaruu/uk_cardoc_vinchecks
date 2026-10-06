<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>
        Invoice {{ $invoice['invoice_id'] }}
    </title>

    <style>
        @page {
            size: A4;
            margin: 14mm 14mm 22mm 14mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #151c27;
            background: #ffffff;
            font-family:
                DejaVu Sans,
                Arial,
                sans-serif;
            font-size: 11px;
            line-height: 1.5;
        }

        .page {
            position: relative;
            min-height: 255mm;
        }

        .header {
            display: table;
            width: 100%;
            margin-bottom: 28px;
        }

        .brand,
        .invoice-meta {
            display: table-cell;
            vertical-align: top;
        }

        .invoice-meta {
            width: 45%;
            text-align: right;
        }

        .brand-name {
            margin: 0;
            color: #000d2f;
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .brand-subtitle {
            margin-top: 5px;
            color: #64748b;
            font-size: 10px;
        }

        .invoice-title {
            margin: 0;
            color: #000d2f;
            font-size: 30px;
            font-weight: 800;
            line-height: 1;
            text-transform: uppercase;
        }

        .invoice-number {
            margin-top: 8px;
            color: #64748b;
            font-size: 10px;
        }

        .status-badge {
            display: inline-block;
            margin-top: 10px;
            padding: 5px 10px;
            border-radius: 4px;
            background: #dcfce7;
            color: #166534;
            font-size: 8px;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }

        .divider {
            height: 1px;
            margin: 22px 0;
            background: #d8dae2;
        }

        .two-column {
            display: table;
            width: 100%;
            margin-bottom: 26px;
        }

        .column {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .column + .column {
            padding-left: 30px;
        }

        .section-label {
            margin-bottom: 8px;
            color: #64748b;
            font-size: 8px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .customer-name {
            color: #000d2f;
            font-size: 14px;
            font-weight: 700;
        }

        .customer-email,
        .meta-value {
            margin-top: 3px;
            color: #475569;
            font-size: 10px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table thead th {
            padding: 11px 12px;
            background: #f1f5f9;
            color: #475569;
            font-size: 8px;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-align: left;
            text-transform: uppercase;
        }

        .table tbody td {
            padding: 15px 12px;
            border-bottom: 1px solid #e2e8f0;
            color: #151c27;
            font-size: 10px;
        }

        .text-right {
            text-align: right;
        }

        .item-title {
            color: #000d2f;
            font-size: 11px;
            font-weight: 700;
        }

        .item-description {
            margin-top: 3px;
            color: #64748b;
            font-size: 9px;
        }

        .totals {
            width: 100%;
            margin-top: 20px;
        }

        .total-row {
            display: table;
            width: 100%;
            margin-bottom: 7px;
        }

        .total-label,
        .total-value {
            display: table-cell;
            color: #475569;
            font-size: 10px;
        }

        .total-value {
            width: 35%;
            color: #151c27;
            font-weight: 600;
            text-align: right;
        }

        .grand-total {
            margin-top: 12px;
            padding-top: 14px;
            border-top: 2px solid #000d2f;
        }

        .grand-total .total-label,
        .grand-total .total-value {
            color: #000d2f;
            font-size: 15px;
            font-weight: 800;
        }

        .payment-box {
            margin-top: 30px;
            padding: 16px;
            border: 1px solid #d8dae2;
            border-radius: 6px;
            background: #f8fafc;
        }

        .payment-title {
            margin-bottom: 10px;
            color: #000d2f;
            font-size: 11px;
            font-weight: 800;
        }

        .payment-grid {
            display: table;
            width: 100%;
        }

        .payment-cell {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .payment-cell + .payment-cell {
            padding-left: 20px;
        }

        .note {
            margin-top: 28px;
            color: #64748b;
            font-size: 8px;
            line-height: 1.6;
        }

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
    <div class="page">

        <div class="header">
            <div class="brand">
                <p class="brand-name">
                    UKCarDoc
                </p>

                <p class="brand-subtitle">
                    Vehicle history & report services
                </p>
            </div>

            <div class="invoice-meta">
                <h1 class="invoice-title">
                    Invoice
                </h1>

                <p class="invoice-number">
                    {{ $invoice['invoice_id'] }}
                </p>

                <span class="status-badge">
                    Paid
                </span>
            </div>
        </div>

        <div class="divider"></div>

        <div class="two-column">

            <div class="column">
                <div class="section-label">
                    Invoice To
                </div>

                <div class="customer-name">
                    {{ $invoice['customer']['name'] }}
                </div>

                <div class="customer-email">
                    {{ $invoice['customer']['email'] }}
                </div>
            </div>

            <div class="column">
                <div class="section-label">
                    Invoice Details
                </div>

                <div class="meta-value">
                    <strong>Invoice date:</strong>
                    {{ $invoice['paid_at']
                        ? $invoice['paid_at']->format('d M Y')
                        : '—'
                    }}
                </div>

                <div class="meta-value">
                    <strong>Payment method:</strong>
                    {{ ucfirst($invoice['payment_method']) }}
                </div>
            </div>

        </div>

        <table class="table">
            <thead>
                <tr>
                    <th>
                        Description
                    </th>

                    <th class="text-right">
                        Amount
                    </th>
                </tr>
            </thead>

            <tbody>
                <tr>
                    <td>
                        <div class="item-title">
                            {{ $invoice['description'] }}
                        </div>

                        <div class="item-description">
                            UKCarDoc account purchase
                        </div>
                    </td>

                    <td class="text-right">
                        {{ $invoice['currency'] }}
                        {{ number_format(
                            (float) $invoice['amount'],
                            2
                        ) }}
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="totals">

            <div class="total-row">
                <div class="total-label">
                    Subtotal
                </div>

                <div class="total-value">
                    {{ $invoice['currency'] }}
                    {{ number_format(
                        (float) $invoice['amount'],
                        2
                    ) }}
                </div>
            </div>

            <div class="total-row grand-total">
                <div class="total-label">
                    Total Paid
                </div>

                <div class="total-value">
                    {{ $invoice['currency'] }}
                    {{ number_format(
                        (float) $invoice['amount'],
                        2
                    ) }}
                </div>
            </div>

        </div>

        <div class="payment-box">

            <div class="payment-title">
                Payment Reference
            </div>

            <div class="payment-grid">

                <div class="payment-cell">
                    <div class="section-label">
                        Invoice Number
                    </div>

                    <div class="meta-value">
                        {{ $invoice['invoice_id'] }}
                    </div>
                </div>

                <div class="payment-cell">
                    <div class="section-label">
                        Gateway Reference
                    </div>

                    <div class="meta-value">
                        {{ $invoice['payment_gateway_ref'] ?? '—' }}
                    </div>
                </div>

            </div>

        </div>

        <p class="note">
            This invoice confirms payment received by UKCarDoc.
            The information shown reflects the transaction recorded
            in the UKCarDoc payment system at the time of issue.
        </p>

    </div>

    <div class="footer">
        UKCarDoc · Payment Invoice ·
        {{ $invoice['invoice_id'] }}
    </div>
</body>
</html>