
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preparing Your VinCheck Report</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            box-sizing: border-box;
            background: #f5f7fc;
            color: #17233f;
            font-family: Arial, sans-serif;
        }

        main {
            width: 100%;
            max-width: 480px;
            padding: 36px 28px;
            box-sizing: border-box;
            text-align: center;
            background: white;
            border: 1px solid #e1e7f0;
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(20, 40, 80, .08);
        }

        .spinner {
            width: 38px;
            height: 38px;
            margin: 0 auto 24px;
            border: 4px solid #e2e8f0;
            border-top-color: #bd0020;
            border-radius: 50%;
            animation: spin .8s linear infinite;
        }

        h1 { font-size: 24px; }
        p { line-height: 1.65; color: #53627b; }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <main>
        <div class="spinner" aria-hidden="true"></div>
        <h1>Payment received</h1>
        <p id="status-message">
            We’re preparing your full vehicle report and invoice.
        </p>
        <p>
            This page will open your invoice automatically when it is ready.
            We’ll also send the report link and invoice to your email.
        </p>
        <noscript>
            JavaScript is disabled. Please check your email for the report
            and invoice, or refresh this page later.
        </noscript>
    </main>

    <script>
        const statusUrl = @json($statusUrl);
        let attempts = 0;

        // Remove checkout query parameters from the visible browser URL.
        window.history.replaceState(
            {},
            document.title,
            window.location.pathname
        );

        async function checkStatus() {
            attempts += 1;

            try {
                const response = await fetch(statusUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        Accept: 'application/json',
                    },
                });

                if (response.ok) {
                    const result = await response.json();

                    if (result.ready && result.invoiceUrl) {
                        window.location.replace(result.invoiceUrl);
                        return;
                    }
                } else if (
                    response.status === 403
                    || response.status === 404
                    || response.status === 410
                ) {
                    document.getElementById('status-message').textContent =
                        'We could not refresh the status automatically. ' +
                        'Please use the invoice link in your email.';
                    return;
                }
            } catch (error) {
                // A temporary network error is retried automatically.
            }

            const delay = Math.min(10000, 2000 + attempts * 500);
            window.setTimeout(checkStatus, delay);
        }

        checkStatus();
    </script>
</body>
</html>
