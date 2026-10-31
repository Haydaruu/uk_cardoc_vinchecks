import {
    PayPalProvider,
    useEligibleMethods,
} from '@paypal/react-paypal-js/sdk-v6';

type PayPalEligibilityDiagnosticProps = {
    clientId: string;
    amount: string;
    currencyCode: string;
};

type EligibilityRowProps = {
    label: string;
    eligible: boolean | null;
    detail: string;
};

export default function PayPalEligibilityDiagnostic({
    clientId,
    amount,
    currencyCode,
}: PayPalEligibilityDiagnosticProps) {
    return (
        <details className="mt-8 rounded-xl border border-outline-variant/60 bg-white">
            <summary className="cursor-pointer px-5 py-4 text-sm font-semibold text-primary">
                PayPal guest-card eligibility check (Sandbox)
            </summary>

            <div className="border-t border-outline-variant/50 px-5 py-5">
                <p className="mb-4 text-xs leading-5 text-on-surface-variant">
                    Diagnostic only. The buyer country is simulated as GB in
                    Sandbox. This does not enable or change any payment flow.
                </p>

                <PayPalProvider
                    clientId={clientId}
                    environment="sandbox"
                    components={[
                        'paypal-payments',
                        'paypal-guest-payments',
                        'card-fields',
                    ]}
                    pageType="checkout"
                    testBuyerCountry="GB"
                >
                    <PayPalEligibilityResults
                        amount={amount}
                        currencyCode={currencyCode}
                    />
                </PayPalProvider>
            </div>
        </details>
    );
}

function PayPalEligibilityResults({
    amount,
    currencyCode,
}: {
    amount: string;
    currencyCode: string;
}) {
    const {
        eligiblePaymentMethods,
        isLoading,
        error,
    } = useEligibleMethods({
        payload: {
            amount,
            currencyCode,
            paymentFlow: 'ONE_TIME_PAYMENT',
        },
    });

    if (isLoading) {
        return (
            <div className="flex items-center gap-3 text-sm text-on-surface-variant">
                <span className="size-4 animate-spin rounded-full border-2 border-primary border-t-transparent" />
                Checking PayPal eligibility for GB / {currencyCode}…
            </div>
        );
    }

    if (error) {
        return (
            <div className="rounded-lg border border-error/20 bg-error-container/40 px-4 py-3 text-sm text-on-error-container">
                Eligibility check failed: {error.message}
            </div>
        );
    }

    if (!eligiblePaymentMethods) {
        return (
            <p className="text-sm text-on-surface-variant">
                PayPal did not return eligibility information.
            </p>
        );
    }

    const rows: EligibilityRowProps[] = [
        {
            label: 'PayPal Checkout',
            eligible: eligiblePaymentMethods.isEligible('paypal'),
            detail: 'Buyer can use a PayPal account.',
        },
        {
            label: 'Guest debit / credit card',
            eligible: eligiblePaymentMethods.isEligible('card'),
            detail: 'Checks the card method used by PayPal guest checkout.',
        },
        {
            label: 'Advanced Card Fields',
            eligible: eligiblePaymentMethods.isEligible('advanced_cards'),
            detail: 'Checks whether embedded card fields are eligible.',
        },
    ];

    return (
        <div className="space-y-3">
            <div className="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-on-surface-variant">
                <span>
                    Buyer simulation: <strong className="text-primary">GB</strong>
                </span>
                <span>
                    Amount:{' '}
                    <strong className="text-primary">
                        {currencyCode} {amount}
                    </strong>
                </span>
                <span>Flow: one-time payment</span>
            </div>

            {rows.map((row) => (
                <EligibilityRow key={row.label} {...row} />
            ))}

            <p className="pt-2 text-xs leading-5 text-on-surface-variant">
                Sandbox eligibility is not a guarantee that this merchant
                account will be approved for the same payment methods in Live.
            </p>
        </div>
    );
}

function EligibilityRow({
    label,
    eligible,
    detail,
}: EligibilityRowProps) {
    const status = eligible === null
        ? 'Not returned'
        : eligible
          ? 'Eligible'
          : 'Not eligible';

    const statusClass = eligible
        ? 'bg-success-container text-on-success-container'
        : 'bg-surface-container text-on-surface-variant';

    return (
        <div className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-outline-variant/50 px-4 py-3">
            <div>
                <p className="text-sm font-semibold text-primary">{label}</p>
                <p className="mt-1 text-xs text-on-surface-variant">{detail}</p>
            </div>

            <span className={`rounded-full px-3 py-1 text-xs font-semibold ${statusClass}`}>
                {status}
            </span>
        </div>
    );
}
