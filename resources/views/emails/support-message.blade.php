
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Support Request</title>
</head>
<body style="margin:0;padding:24px;background:#f5f7fb;font-family:Arial,sans-serif;color:#17233b;">
    <div style="max-width:640px;margin:0 auto;padding:32px;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;">
        <p style="margin:0 0 8px;color:#bd0024;font-size:12px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;">
            UKCARDOC SUPPORT
        </p>

        <h1 style="margin:0 0 24px;font-size:24px;">New Support Request</h1>

        <p><strong>Name:</strong> {{ $senderName }}</p>
        <p><strong>Email:</strong> {{ $senderEmail }}</p>
        <p><strong>Subject:</strong> {{ $supportSubject }}</p>

        <div style="margin-top:24px;padding:20px;background:#f8fafc;border-radius:8px;">
            <p style="margin-top:0;font-weight:bold;">Message</p>
            <p style="margin-bottom:0;line-height:1.7;white-space:pre-line;">{{ $messageBody }}</p>
        </div>

        <p style="margin-top:28px;color:#64748b;font-size:12px;">
            This message was submitted through the UKCARDOC support form.
            Reply to this email to respond to the customer.
        </p>
    </div>
</body>
</html>
