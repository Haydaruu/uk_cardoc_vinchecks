<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your VinCheck Report Is Ready</title>
</head>
<body>
    <p>Hello {{ $customerName }},</p>

    <p>
        Your full vehicle report for
        <strong>{{ $registrationNumber }}</strong>
        is ready.
    </p>

    <p>
        <a href="{{ $reportUrl }}">Open your full vehicle report</a>
    </p>

    <p>
        Your PDF invoice is attached to this email.
        You can also
        <a href="{{ $invoiceUrl }}">view your invoice online</a>.
        The online invoice link expires after 30 days.
    </p>

    <p>
        Please keep this email safe. Your report link provides access
        to your purchased report, so avoid sharing it with others.
    </p>

    <p>
        Thank you for using VinCheck.
    </p>
</body>
</html>