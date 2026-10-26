
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify your email address</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f5f9;color:#172033;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f3f5f9;">
        <tr>
            <td align="center" style="padding:36px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background-color:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">

                    <tr>
                        <td style="padding:30px 36px;background-color:#000d2f;">
                            <p style="margin:0;color:#ffffff;font-size:25px;font-weight:800;letter-spacing:-0.5px;">
                                UKCarDoc
                            </p>
                            <p style="margin:7px 0 0;color:#cbd5e1;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;">
                                Vehicle history &amp; report services
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:36px 36px 24px;">
                            <p style="margin:0 0 12px;color:#64748b;font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;">
                                Account security
                            </p>

                            <h1 style="margin:0 0 20px;color:#000d2f;font-size:27px;line-height:1.3;">
                                Verify your email address
                            </h1>

                            <p style="margin:0 0 16px;color:#334155;font-size:15px;line-height:1.8;">
                                Hello {{ $customerName }},
                            </p>

                            <p style="margin:0 0 26px;color:#475569;font-size:14px;line-height:1.8;">
                                Thank you for creating your UKCarDoc account.
                                Please verify your email address to finish setting
                                up your account and help keep it secure.
                            </p>

                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 26px;">
                                <tr>
                                    <td align="center" style="background-color:#000d2f;border-radius:6px;">
                                        <a href="{{ $verificationUrl }}"
                                           style="display:inline-block;padding:15px 26px;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;border-radius:6px;">
                                            Verify Email Address
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 12px;color:#64748b;font-size:12px;line-height:1.7;">
                                If the button does not work, copy and paste this link
                                into your browser:
                            </p>

                            <p style="margin:0 0 24px;overflow-wrap:anywhere;word-break:break-word;font-size:12px;line-height:1.8;">
                                <a href="{{ $verificationUrl }}" style="color:#1d4ed8;text-decoration:underline;">
                                    {{ $verificationUrl }}
                                </a>
                            </p>

                            <div style="height:1px;background-color:#e2e8f0;margin:24px 0;"></div>

                            <p style="margin:0;color:#64748b;font-size:12px;line-height:1.8;">
                                If you did not create this account, you can safely
                                ignore this email. Your account will not be verified
                                through this message unless the verification link is used.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:22px 36px;background-color:#f8fafc;border-top:1px solid #e2e8f0;">
                            <p style="margin:0 0 5px;color:#000d2f;font-size:12px;font-weight:700;">
                                UKCarDoc
                            </p>
                            <p style="margin:0;color:#94a3b8;font-size:11px;line-height:1.7;">
                                Vehicle history &amp; report services
                            </p>
                            <p style="margin:12px 0 0;color:#94a3b8;font-size:10px;line-height:1.6;">
                                This is an automated account security email.
                                Please do not reply directly to this message.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
