
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Your VinCheck Full Vehicle Report Is Ready</title>
</head>

<body style="margin:0;padding:0;background-color:#f3f5f9;color:#172033;font-family:Arial,Helvetica,sans-serif;">

    <!-- Preview text shown in supported email clients -->
    <div style="display:none;font-size:1px;color:#f3f5f9;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">
        Your full vehicle report is ready. Open your report and keep your invoice for your records.
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
           style="width:100%;background-color:#f3f5f9;">
        <tr>
            <td align="center" style="padding:32px 14px;">

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
                       style="width:100%;max-width:600px;background-color:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">

                    <!-- Brand header -->
                    <tr>
                        <td style="padding:28px 32px;background-color:#000d2f;border-bottom:4px solid #c8102e;">
                            <p style="margin:0;color:#ffffff;font-size:25px;font-weight:800;letter-spacing:-0.5px;">
                                UKCarDoc
                            </p>
                            <p style="margin:7px 0 0;color:#cbd5e1;font-size:11px;letter-spacing:1.4px;text-transform:uppercase;">
                                Vehicle history &amp; report services
                            </p>
                        </td>
                    </tr>

                    <!-- Main content -->
                    <tr>
                        <td style="padding:34px 32px 30px;">

                            <p style="margin:0 0 12px;color:#c8102e;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;">
                                Payment confirmed
                            </p>

                            <h1 style="margin:0 0 20px;color:#000d2f;font-size:27px;line-height:1.3;font-weight:800;">
                                Your full report is ready
                            </h1>

                            <p style="margin:0 0 14px;color:#334155;font-size:15px;line-height:1.8;">
                                Hello {{ $customerName }},
                            </p>

                            <p style="margin:0 0 24px;color:#475569;font-size:14px;line-height:1.8;">
                                Your full vehicle report is ready to view.
                                Use the secure link below to access your report
                                for the vehicle shown here.
                            </p>

                            <!-- Vehicle summary -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
                                   style="width:100%;margin:0 0 28px;background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                                <tr>
                                    <td style="padding:17px 18px;">
                                        <p style="margin:0 0 7px;color:#64748b;font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;">
                                            Vehicle registration
                                        </p>
                                        <p style="margin:0;color:#000d2f;font-size:19px;font-weight:800;letter-spacing:0.5px;">
                                            {{ $registrationNumber }}
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Primary report button -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0"
                                   style="margin:0 0 22px;">
                                <tr>
                                    <td align="center" bgcolor="#c8102e" style="border-radius:6px;">
                                        <a href="{{ $reportUrl }}"
                                           style="display:inline-block;padding:15px 25px;border:1px solid #c8102e;border-radius:6px;color:#ffffff;font-size:14px;font-weight:700;line-height:1.3;text-decoration:none;">
                                            View Full Vehicle Report
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 10px;color:#64748b;font-size:12px;line-height:1.7;">
                                Having trouble opening the button? Copy and paste
                                this link into your browser:
                            </p>

                            <p style="margin:0 0 28px;font-size:12px;line-height:1.8;overflow-wrap:anywhere;word-break:break-word;">
                                <a href="{{ $reportUrl }}" style="color:#1d4ed8;text-decoration:underline;">
                                    Open your VinCheck report
                                </a>
                            </p>

                            <!-- Invoice section -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
                                   style="width:100%;border-top:1px solid #e2e8f0;">
                                <tr>
                                    <td style="padding:25px 0 8px;">
                                        <p style="margin:0 0 8px;color:#000d2f;font-size:16px;font-weight:700;">
                                            Your payment invoice
                                        </p>

                                        <p style="margin:0 0 15px;color:#64748b;font-size:13px;line-height:1.8;">
                                            A PDF invoice is attached to this email
                                            for your records. You can also open your
                                            invoice online using the link below.
                                        </p>

                                        <p style="margin:0 0 24px;">
                                            <a href="{{ $invoiceUrl }}"
                                               style="color:#000d2f;font-size:13px;font-weight:700;text-decoration:underline;">
                                                View invoice online
                                            </a>
                                            <span style="color:#94a3b8;font-size:12px;">
                                                &nbsp;·&nbsp; Link expires after 30 days
                                            </span>
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Security note -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
                                   style="width:100%;background-color:#fff7ed;border-left:3px solid #c8102e;">
                                <tr>
                                    <td style="padding:14px 16px;">
                                        <p style="margin:0 0 5px;color:#9a3412;font-size:12px;font-weight:700;">
                                            Keep your report link safe
                                        </p>
                                        <p style="margin:0;color:#7c4a36;font-size:12px;line-height:1.7;">
                                            Your report link grants access to your
                                            purchased report. Do not share it with
                                            others. Anyone with the link may be able
                                            to access the report.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:26px 0 0;color:#475569;font-size:13px;line-height:1.8;">
                                Thank you for choosing UKCarDoc.
                            </p>

                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding:22px 32px;background-color:#f8fafc;border-top:1px solid #e2e8f0;">
                            <p style="margin:0 0 5px;color:#000d2f;font-size:12px;font-weight:700;">
                                UKCarDoc
                            </p>
                            <p style="margin:0;color:#64748b;font-size:11px;line-height:1.7;">
                                Vehicle history &amp; report services
                            </p>
                            <p style="margin:12px 0 0;color:#94a3b8;font-size:10px;line-height:1.7;">
                                This is an automated email about your vehicle
                                report purchase. Please do not reply directly
                                to this message.
                            </p>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>
