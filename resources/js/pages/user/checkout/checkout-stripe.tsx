import BaseLayout from '@/layouts/base-layout';
import { createIntent } from '@/routes/checkout';
import {
    Elements,
    PaymentElement,
    useElements,
    useStripe,
} from '@stripe/react-stripe-js';
import { loadStripe } from '@stripe/stripe-js';
import { Head } from '@inertiajs/react';
import {
    PayPalOneTimePaymentButton,
    PayPalProvider,
    type OnApproveDataOneTimePayments,
    type OnErrorData,
} from '@paypal/react-paypal-js/sdk-v6';
import {
    CheckCircle2,
    CreditCard,
    Lock,
    ShieldAlert,
    ShieldCheck,
    Wallet,
} from 'lucide-react';
import {
    FormEvent,
    useEffect,
    useState,
} from 'react';

const stripePromise = loadStripe(
    import.meta.env.VITE_STRIPE_KEY,
);

type PaymentMethod =
    | 'stripe'
    | 'paypal';

type CheckoutProps = {
    plan: string;
    label: string;
    amountDisplay: string;

    paypalClientId: string | null;

    paypalEnvironment:
        | 'sandbox'
        | 'production';
};

export default function Checkout({
    plan,
    label,
    amountDisplay,
    paypalClientId,
    paypalEnvironment,
}: CheckoutProps) {
    const [
        paymentMethod,
        setPaymentMethod,
    ] = useState<PaymentMethod>(
        'stripe',
    );

    const [
        clientSecret,
        setClientSecret,
    ] = useState<string | null>(
        null,
    );

    const [
        initError,
        setInitError,
    ] = useState<string | null>(
        null,
    );

    /*
     * Stripe PaymentIntent.
     *
     * We only need to create it when
     * Stripe is selected.
     */
    useEffect(() => {
        if (
            paymentMethod !==
            'stripe'
        ) {
            return;
        }

        if (clientSecret) {
            return;
        }

        setInitError(null);

        fetch(
            createIntent.url(),
            {
                method: 'POST',

                headers: {
                    'Content-Type':
                        'application/json',

                    'X-CSRF-TOKEN':
                        getCsrfToken(),
                },

                body: JSON.stringify({
                    plan,
                }),
            },
        )
            .then(async (res) => {
                const data =
                    await res.json();

                if (!res.ok) {
                    throw new Error(
                        data.message ??
                            'Unable to prepare Stripe payment.',
                    );
                }

                return data;
            })
            .then((data) => {
                if (
                    data.clientSecret
                ) {
                    setClientSecret(
                        data.clientSecret,
                    );

                    return;
                }

                setInitError(
                    data.message ??
                        'Unable to prepare payment.',
                );
            })
            .catch((error) => {
                setInitError(
                    error instanceof
                        Error
                        ? error.message
                        : 'Unable to connect to payment server.',
                );
            });
    }, [
        plan,
        paymentMethod,
        clientSecret,
    ]);

    return (
        <>
            <Head title="Secure Checkout" />

            <main className="mx-auto max-w-7xl px-8 py-section-padding">
                <div className="grid grid-cols-1 gap-12 lg:grid-cols-12">

                    {/* Payment */}
                    <div className="space-y-12 lg:col-span-7">

                        <section>
                            <h1 className="font-h2 text-h2 mb-2 text-primary">
                                Secure Checkout
                            </h1>

                            <p className="font-body-lg mb-8 text-on-surface-variant">
                                Complete your
                                purchase of{' '}

                                <span className="font-bold text-primary">
                                    {label}
                                </span>
                                .
                            </p>

                            {/* Payment Method */}
                            <div className="mb-8">
                                <p className="font-label-sm mb-3 text-xs font-bold uppercase tracking-widest text-on-surface-variant">
                                    Payment Method
                                </p>

                                <div className="grid grid-cols-2 gap-4">

                                    {/* Stripe */}
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setInitError(
                                                null,
                                            );

                                            setPaymentMethod(
                                                'stripe',
                                            );
                                        }}
                                        className={`flex items-center justify-center gap-3 rounded-lg border px-4 py-4 font-bold transition-all ${
                                            paymentMethod ===
                                            'stripe'
                                                ? 'border-primary bg-primary text-white shadow-sm'
                                                : 'border-gray-300 bg-white text-primary hover:border-primary'
                                        }`}
                                    >
                                        <CreditCard className="size-5" />

                                        <span>
                                            Card
                                        </span>
                                    </button>

                                    {/* PayPal */}
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setInitError(
                                                null,
                                            );

                                            setPaymentMethod(
                                                'paypal',
                                            );
                                        }}
                                        className={`flex items-center justify-center gap-3 rounded-lg border px-4 py-4 font-bold transition-all ${
                                            paymentMethod ===
                                            'paypal'
                                                ? 'border-primary bg-surface-container text-primary shadow-sm'
                                                : 'border-gray-300 bg-white text-primary hover:border-primary'
                                        }`}
                                    >
                                        <Wallet className="size-5" />

                                        <span>
                                            PayPal
                                        </span>
                                    </button>

                                </div>
                            </div>

                            <div className="sovereign-line mb-8" />

                            {/* Stripe */}
                            {paymentMethod ===
                                'stripe' && (
                                <>
                                    {initError && (
                                        <PaymentError
                                            message={
                                                initError
                                            }
                                        />
                                    )}

                                    {!initError &&
                                        !clientSecret && (
                                            <div className="flex items-center gap-3 rounded-lg border border-outline-variant bg-surface-container-low p-4 text-sm text-on-surface-variant">
                                                <div className="size-4 animate-spin rounded-full border-2 border-primary border-t-transparent" />

                                                Preparing
                                                secure card
                                                payment...
                                            </div>
                                        )}

                                    {clientSecret && (
                                        <Elements
                                            stripe={
                                                stripePromise
                                            }
                                            options={{
                                                clientSecret,

                                                appearance:
                                                    {
                                                        variables:
                                                            {
                                                                colorPrimary:
                                                                    '#000d2f',

                                                                colorDanger:
                                                                    '#ba1a1a',

                                                                fontFamily:
                                                                    'Inter, sans-serif',

                                                                borderRadius:
                                                                    '4px',
                                                            },
                                                    },
                                            }}
                                        >
                                            <StripeCheckoutForm
                                                amountDisplay={
                                                    amountDisplay
                                                }
                                            />
                                        </Elements>
                                    )}
                                </>
                            )}

                            {/* PayPal */}
                            {paymentMethod ===
                                'paypal' && (
                                <div className="space-y-6">

                                    <div className="rounded-lg border border-outline-variant bg-surface-container-low p-5">
                                        <div className="flex items-start gap-3">

                                            <ShieldCheck className="mt-0.5 size-5 shrink-0 text-primary" />

                                            <div>
                                                <p className="font-semibold text-primary">
                                                    Pay
                                                    securely
                                                    with
                                                    PayPal
                                                </p>

                                                <p className="mt-1 text-sm leading-relaxed text-on-surface-variant">
                                                    You
                                                    will
                                                    complete
                                                    payment
                                                    through
                                                    PayPal.
                                                    UKCarDoc
                                                    never
                                                    receives
                                                    your
                                                    PayPal
                                                    password.
                                                </p>
                                            </div>

                                        </div>
                                    </div>

                                    {!paypalClientId ? (
                                        <PaymentError message="PayPal is not configured correctly." />
                                    ) : (
                                        <PayPalProvider
                                            clientId={
                                                paypalClientId
                                            }
                                            environment={
                                                paypalEnvironment
                                            }
                                            components={[
                                                'paypal-payments',
                                            ]}
                                            pageType="checkout"
                                        >
                                            <PayPalCheckout
                                                plan={
                                                    plan
                                                }
                                            />
                                        </PayPalProvider>
                                    )}

                                </div>
                            )}

                        </section>

                    </div>

                    {/* Order Summary */}
                    <div className="space-y-8 lg:col-span-5">

                        <div className="rounded-lg border border-gray-200 bg-white p-8 shadow-[0_4px_20px_rgba(0,32,91,0.04)]">

                            <h3 className="font-h3 text-h3 mb-6 text-primary">
                                Order Summary
                            </h3>

                            <div className="mb-6 space-y-4">

                                <div className="flex items-start justify-between gap-6">

                                    <p className="font-bold text-primary">
                                        {label}
                                    </p>

                                    <span className="shrink-0 font-bold text-primary">
                                        {
                                            amountDisplay
                                        }
                                    </span>

                                </div>

                                <div className="sovereign-line" />

                                <div className="flex items-center gap-2 text-sm text-slate-600">

                                    <CheckCircle2 className="size-4 shrink-0 text-green-600" />

                                    Credits never
                                    expire — use them
                                    anytime to unlock a
                                    Full Report

                                </div>

                            </div>

                            <div className="mb-6 rounded bg-surface-container-low p-4">

                                <div className="flex items-center justify-between text-primary">

                                    <span className="font-bold">
                                        Total Amount
                                        Due
                                    </span>

                                    <span className="text-2xl font-black">
                                        {
                                            amountDisplay
                                        }
                                    </span>

                                </div>

                            </div>

                            <div className="flex items-center gap-3 text-xs text-slate-500">

                                <ShieldCheck className="size-4 shrink-0" />

                                Your credits will
                                be available
                                immediately after
                                payment.

                            </div>

                        </div>

                        {/* Trust */}
                        <div className="grid grid-cols-1 gap-4">

                            <div className="flex items-center gap-4 rounded border border-gray-100 bg-white p-4">

                                <div className="flex size-12 items-center justify-center rounded-full bg-surface-container">

                                    <ShieldCheck className="size-5 text-primary" />

                                </div>

                                <div>

                                    <p className="text-xs font-bold uppercase tracking-widest text-primary">
                                        UK Government
                                        Data Partner
                                    </p>

                                    <p className="text-[11px] text-slate-500">
                                        Direct
                                        integration
                                        with DVLA
                                        &amp; DVSA
                                        systems.
                                    </p>

                                </div>

                            </div>

                            <div className="flex items-center justify-between rounded border border-gray-200 bg-slate-50 p-4">

                                <TrustBadge
                                    label="Verified by"
                                    value="VISA"
                                />

                                <div className="h-8 w-px bg-gray-300" />

                                <TrustBadge
                                    label="Mastercard"
                                    value="ID Check"
                                />

                                <div className="h-8 w-px bg-gray-300" />

                                <TrustBadge
                                    label="Secure"
                                    value="Payments"
                                />

                            </div>

                        </div>

                    </div>

                </div>
            </main>
        </>
    );
}

function PayPalCheckout({
    plan,
}: {
    plan: string;
}) {
    const [
        errorMessage,
        setErrorMessage,
    ] = useState<string | null>(
        null,
    );

    const [
        isProcessing,
        setIsProcessing,
    ] = useState(false);

    /**
     * PayPal SDK calls this when
     * buyer clicks PayPal.
     *
     * Server decides the actual
     * amount and credits.
     */
    const createPayPalOrder =
        async (): Promise<{
            orderId: string;
        }> => {
            setErrorMessage(
                null,
            );

            const response =
                await fetch(
                    '/checkout/paypal/create-order',
                    {
                        method:
                            'POST',

                        headers: {
                            'Content-Type':
                                'application/json',

                            Accept:
                                'application/json',

                            'X-CSRF-TOKEN':
                                getCsrfToken(),
                        },

                        body: JSON.stringify(
                            {
                                plan,
                            },
                        ),
                    },
                );

            const data =
                await response.json();

            if (!response.ok) {
                throw new Error(
                    data.message ??
                        'Unable to create PayPal order.',
                );
            }

            if (!data.id) {
                throw new Error(
                    'PayPal order ID was not returned.',
                );
            }

            return {
                orderId:
                    data.id,
            };
        };

    /**
     * Buyer approved PayPal.
     *
     * Capture happens on Laravel,
     * then CreditService grants
     * credits.
     */
    const handleApprove =
        async (
            data: OnApproveDataOneTimePayments,
        ) => {
            setIsProcessing(
                true,
            );

            setErrorMessage(
                null,
            );

            try {
                const response =
                    await fetch(
                        '/checkout/paypal/capture',
                        {
                            method:
                                'POST',

                            headers: {
                                'Content-Type':
                                    'application/json',

                                Accept:
                                    'application/json',

                                'X-CSRF-TOKEN':
                                    getCsrfToken(),
                            },

                            body: JSON.stringify(
                                {
                                    order_id:
                                        data.orderId,
                                },
                            ),
                        },
                    );

                const result =
                    await response.json();

                if (!response.ok) {
                    throw new Error(
                        result.message ??
                            'Unable to capture PayPal payment.',
                    );
                }

                if (
                    result.status !==
                    'success'
                ) {
                    throw new Error(
                        'PayPal payment was not completed.',
                    );
                }

                if (
                    !result.redirect
                ) {
                    throw new Error(
                        'Checkout success URL was not returned.',
                    );
                }

                /*
                 * Normal browser redirect,
                 * because backend success()
                 * returns Inertia page.
                 */
                window.location.assign(
                    result.redirect,
                );
            } catch (error) {
                setErrorMessage(
                    error instanceof
                        Error
                        ? error.message
                        : 'PayPal payment failed.',
                );

                setIsProcessing(
                    false,
                );

                throw error;
            }
        };

    const handleError = (
        error: OnErrorData,
    ) => {
        setIsProcessing(false);

        setErrorMessage(
            error.message ??
                'Something went wrong with PayPal.',
        );
    };

    return (
        <div className="space-y-4">

            {errorMessage && (
                <PaymentError
                    message={
                        errorMessage
                    }
                />
            )}

            {isProcessing && (
                <div className="flex items-center justify-center gap-3 rounded-lg border border-outline-variant bg-surface-container-low p-4 text-sm font-semibold text-primary">

                    <div className="size-4 animate-spin rounded-full border-2 border-primary border-t-transparent" />

                    Confirming your
                    PayPal payment...

                </div>
            )}

            <div
                className={
                    isProcessing
                        ? 'pointer-events-none opacity-50'
                        : ''
                }
            >
                <PayPalOneTimePaymentButton
                    createOrder={
                        createPayPalOrder
                    }
                    onApprove={
                        handleApprove
                    }
                    onCancel={() => {
                        setIsProcessing(
                            false,
                        );

                        setErrorMessage(
                            'PayPal checkout was cancelled. No payment was taken.',
                        );
                    }}
                    onError={
                        handleError
                    }
                    presentationMode="auto"
                    disabled={
                        isProcessing
                    }
                />
            </div>

            <p className="text-center text-xs text-slate-500">
                You will be redirected
                back to UKCarDoc after
                payment is confirmed.
            </p>

        </div>
    );
}

function StripeCheckoutForm({
    amountDisplay,
}: {
    amountDisplay: string;
}) {
    const stripe =
        useStripe();

    const elements =
        useElements();

    const [
        isProcessing,
        setIsProcessing,
    ] = useState(false);

    const [
        errorMessage,
        setErrorMessage,
    ] = useState<string | null>(
        null,
    );

    const [
        billing,
        setBilling,
    ] = useState({
        line1: '',
        city: '',
        postalCode: '',
    });

    async function handleSubmit(
        e: FormEvent,
    ) {
        e.preventDefault();

        if (
            !stripe ||
            !elements
        ) {
            return;
        }

        setIsProcessing(true);
        setErrorMessage(null);

        const { error } =
            await stripe.confirmPayment(
                {
                    elements,

                    confirmParams: {
                        return_url:
                            `${window.location.origin}/checkout/success`,

                        payment_method_data:
                            {
                                billing_details:
                                    {
                                        address:
                                            {
                                                line1:
                                                    billing.line1,

                                                city:
                                                    billing.city,

                                                postal_code:
                                                    billing.postalCode,

                                                country:
                                                    'GB',
                                            },
                                    },
                            },
                    },
                },
            );

        if (error) {
            setErrorMessage(
                error.message ??
                    'Payment failed. Please try again.',
            );

            setIsProcessing(
                false,
            );
        }
    }

    return (
        <form
            onSubmit={
                handleSubmit
            }
            className="space-y-8"
        >

            <div>

                <h3 className="font-h3 text-h3 mb-4 text-primary">
                    Payment Details
                </h3>

                <PaymentElement />

            </div>

            <div className="space-y-4 pt-2">

                <h3 className="font-h3 text-h3 text-primary">
                    Billing Address
                </h3>

                <div>

                    <label className="font-label-sm mb-2 block text-primary">
                        STREET ADDRESS
                    </label>

                    <input
                        required
                        type="text"
                        placeholder="123 Pall Mall"
                        value={
                            billing.line1
                        }
                        onChange={(
                            e,
                        ) =>
                            setBilling(
                                (
                                    current,
                                ) => ({
                                    ...current,

                                    line1:
                                        e
                                            .target
                                            .value,
                                }),
                            )
                        }
                        className="w-full rounded border border-gray-300 px-4 py-3 outline-none transition-all focus:border-primary focus:ring-2 focus:ring-primary"
                    />

                </div>

                <div className="grid grid-cols-2 gap-4">

                    <div>

                        <label className="font-label-sm mb-2 block text-primary">
                            CITY
                        </label>

                        <input
                            required
                            type="text"
                            placeholder="London"
                            value={
                                billing.city
                            }
                            onChange={(
                                e,
                            ) =>
                                setBilling(
                                    (
                                        current,
                                    ) => ({
                                        ...current,

                                        city:
                                            e
                                                .target
                                                .value,
                                    }),
                                )
                            }
                            className="w-full rounded border border-gray-300 px-4 py-3 outline-none transition-all focus:border-primary focus:ring-2 focus:ring-primary"
                        />

                    </div>

                    <div>

                        <label className="font-label-sm mb-2 block text-primary">
                            POSTCODE
                        </label>

                        <input
                            required
                            type="text"
                            placeholder="SW1A 1AA"
                            value={
                                billing.postalCode
                            }
                            onChange={(
                                e,
                            ) =>
                                setBilling(
                                    (
                                        current,
                                    ) => ({
                                        ...current,

                                        postalCode:
                                            e
                                                .target
                                                .value,
                                    }),
                                )
                            }
                            className="w-full rounded border border-gray-300 px-4 py-3 outline-none transition-all focus:border-primary focus:ring-2 focus:ring-primary"
                        />

                    </div>

                </div>

            </div>

            {errorMessage && (
                <PaymentError
                    message={
                        errorMessage
                    }
                />
            )}

            <div className="pt-2">

                <button
                    type="submit"
                    disabled={
                        !stripe ||
                        isProcessing
                    }
                    className="flex w-full items-center justify-center gap-3 rounded bg-secondary py-5 font-bold text-white shadow-lg transition-all hover:bg-red-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <Lock className="size-5" />

                    {isProcessing
                        ? 'Processing...'
                        : `Pay ${amountDisplay} Now`}
                </button>

                <p className="mt-4 text-center text-xs text-slate-500">
                    By clicking
                    &quot;Pay
                    Now&quot; you
                    agree to our Terms
                    of Service and
                    Refund Policy.
                </p>

            </div>

        </form>
    );
}

function PaymentError({
    message,
}: {
    message: string;
}) {
    return (
        <div className="flex items-start gap-3 rounded-lg border border-error-container bg-error-container p-4 text-on-error-container">

            <ShieldAlert className="mt-0.5 size-5 shrink-0" />

            <p className="text-sm">
                {message}
            </p>

        </div>
    );
}

function TrustBadge({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    return (
        <div className="px-2 text-center">

            <p className="text-[10px] font-black uppercase tracking-tighter text-slate-400">
                {label}
            </p>

            <p className="text-sm font-bold text-slate-600">
                {value}
            </p>

        </div>
    );
}

function getCsrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>(
            'meta[name="csrf-token"]',
        )?.content ?? ''
    );
}

Checkout.layout = (
    page: React.ReactNode,
) => (
    <BaseLayout>
        {page}
    </BaseLayout>
);