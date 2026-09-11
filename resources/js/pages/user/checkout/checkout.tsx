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
    PayPalSubscriptionButton,
    type OnApproveDataOneTimePayments,
    type OnApproveDataSubscriptions,
    type OnErrorData,
} from '@paypal/react-paypal-js/sdk-v6';

import {
    Check,
    CreditCard,
    Lock,
    ShieldAlert,
    ShieldCheck,
    Wallet,
} from 'lucide-react';

import {
    type FormEvent,
    type ReactNode,
    useEffect,
    useState,
} from 'react';

const stripePromise = loadStripe(import.meta.env.VITE_STRIPE_KEY);

type PaymentMethod = 'stripe' | 'paypal';
type PlanType = 'one_time' | 'subscription';

type CheckoutProps = {
    plan: string;
    planType: PlanType;
    label: string;
    amountDisplay: string;
    credits: number;
    paypalClientId: string | null;
    paypalEnvironment: 'sandbox' | 'production';
};

export default function Checkout({
    plan,
    planType,
    label,
    amountDisplay,
    credits,
    paypalClientId,
    paypalEnvironment,
}: CheckoutProps) {
    const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>('stripe');
    const [clientSecret, setClientSecret] = useState<string | null>(null);
    const [subscriptionId, setSubscriptionId] = useState<string | null>(null);
    const [initError, setInitError] = useState<string | null>(null);

    const isSubscription = planType === 'subscription';

    const stripeIntentUrl = isSubscription
        ? '/checkout/create-subscription-intent'
        : createIntent.url();

    useEffect(() => {
        setClientSecret(null);
        setSubscriptionId(null);
        setInitError(null);
    }, [plan, planType]);

    useEffect(() => {
        if(paymentMethod !== 'stripe' || clientSecret) return;

        let cancelled = false;

        async function prepareStripePayment() {
            setInitError(null);

            try {
                const data = await postJson<{
                    clientSecret?: string;
                    subscriptionId?: string;
                }>(stripeIntentUrl, { plan });

                if(!data.clientSecret) {
                    throw new Error('Stripe did not return a payment client secret.');
                }

                if(cancelled) return;

                if(isSubscription) {
                    if(!data.subscriptionId) {
                        throw new Error('Stripe did not return a subscription ID.');
                    }

                    setSubscriptionId(data.subscriptionId);
                }

                setClientSecret(data.clientSecret);
            } catch(error) {
                if(cancelled) return;

                setInitError(
                    error instanceof Error
                        ? error.message
                        : 'Unable to connect to payment server.',
                );
            }
        }

        prepareStripePayment();

        return () => {
            cancelled = true;
        };
    }, [
        plan,
        paymentMethod,
        clientSecret,
        stripeIntentUrl,
        isSubscription,
    ]);

    return (
        <>
            <Head title="Secure Checkout" />

            <main className="min-h-[calc(100vh-80px)] bg-surface">
                <div className="mx-auto max-w-[1180px] px-5 py-10 md:px-8 md:py-14">

                    {/* Heading */}
                    <div className="mb-9 max-w-2xl">
                        <div className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.16em] text-on-surface-variant">
                            <ShieldCheck className="size-4 text-secondary" />
                            Secure checkout
                        </div>

                        <h1 className="text-3xl font-bold tracking-tight text-primary md:text-4xl">
                            {isSubscription
                                ? `Start your ${label} membership`
                                : `Complete your ${label} purchase`}
                        </h1>

                        <p className="mt-3 max-w-xl text-sm leading-6 text-on-surface-variant">
                            {isSubscription
                                ? `${credits} credits are added to your account every month. Cancel anytime from your subscription settings.`
                                : `${credits} ${credits === 1 ? 'credit' : 'credits'} will be available after payment.`}
                        </p>
                    </div>

                    <div className="grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_390px]">

                        {/* Payment */}
                        <section className="overflow-hidden rounded-xl border border-outline-variant/60 bg-white shadow-[0_12px_35px_rgba(0,13,47,0.05)]">

                            {/* Payment selector */}
                            <div className="border-b border-outline-variant/50 px-6 py-6 md:px-8">
                                <div className="mb-4 flex items-center justify-between">
                                    <div>
                                        <p className="text-sm font-semibold text-primary">
                                            Payment method
                                        </p>

                                        <p className="mt-1 text-xs text-on-surface-variant">
                                            Choose how you would like to pay.
                                        </p>
                                    </div>

                                    <Lock className="size-4 text-outline" />
                                </div>

                                <div className="grid grid-cols-2 rounded-lg bg-surface-container-low p-1">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setInitError(null);
                                            setPaymentMethod('stripe');
                                        }}
                                        className={`flex items-center justify-center gap-2 rounded-md px-4 py-3 text-sm font-semibold transition-all ${
                                            paymentMethod === 'stripe'
                                                ? 'bg-white text-primary shadow-sm ring-1 ring-outline-variant/40'
                                                : 'text-on-surface-variant hover:text-primary'
                                        }`}
                                    >
                                        <CreditCard className="size-4" />
                                        Card
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => {
                                            setInitError(null);
                                            setPaymentMethod('paypal');
                                        }}
                                        className={`flex items-center justify-center gap-2 rounded-md px-4 py-3 text-sm font-semibold transition-all ${
                                            paymentMethod === 'paypal'
                                                ? 'bg-white text-primary shadow-sm ring-1 ring-outline-variant/40'
                                                : 'text-on-surface-variant hover:text-primary'
                                        }`}
                                    >
                                        <Wallet className="size-4" />
                                        PayPal
                                    </button>
                                </div>
                            </div>

                            {/* Payment body */}
                            <div className="px-6 py-7 md:px-8 md:py-8">

                                {paymentMethod === 'stripe' && (
                                    <>
                                        {initError && (
                                            <PaymentError message={initError} />
                                        )}

                                        {!initError && !clientSecret && (
                                            <PaymentLoading />
                                        )}

                                        {clientSecret && (
                                            <Elements
                                                stripe={stripePromise}
                                                options={{
                                                    clientSecret,

                                                    appearance: {
                                                        variables: {
                                                            colorPrimary: '#000d2f',
                                                            colorDanger: '#ba1a1a',
                                                            colorText: '#151c27',
                                                            fontFamily: 'Inter, sans-serif',
                                                            borderRadius: '6px',
                                                            spacingUnit: '4px',
                                                        },

                                                        rules: {
                                                            '.Input': {
                                                                borderColor: '#d8dae2',
                                                                boxShadow: 'none',
                                                                padding: '13px 14px',
                                                            },

                                                            '.Input:focus': {
                                                                borderColor: '#455c99',
                                                                boxShadow: '0 0 0 1px #455c99',
                                                            },
                                                        },
                                                    },
                                                }}
                                            >
                                                <StripeCheckoutForm
                                                    amountDisplay={amountDisplay}
                                                    planType={planType}
                                                    subscriptionId={subscriptionId}
                                                />
                                            </Elements>
                                        )}
                                    </>
                                )}

                                {paymentMethod === 'paypal' && (
                                    <PayPalSection
                                        plan={plan}
                                        planType={planType}
                                        paypalClientId={paypalClientId}
                                        paypalEnvironment={paypalEnvironment}
                                    />
                                )}
                            </div>

                            {/* Secure footer */}
                            <div className="flex items-center gap-3 border-t border-outline-variant/40 bg-surface-container-low/50 px-6 py-4 text-xs leading-5 text-on-surface-variant md:px-8">
                                <ShieldCheck className="size-4 shrink-0 text-primary" />
                                Payment information is processed securely by the selected payment provider.
                            </div>
                        </section>

                        <OrderSummary
                            label={label}
                            amountDisplay={amountDisplay}
                            credits={credits}
                            planType={planType}
                        />
                    </div>
                </div>
            </main>
        </>
    );
}

function OrderSummary({
    label,
    amountDisplay,
    credits,
    planType,
}: {
    label: string;
    amountDisplay: string;
    credits: number;
    planType: PlanType;
}) {
    const isSubscription = planType === 'subscription';

    return (
        <aside className="lg:sticky lg:top-8">
            <div className="overflow-hidden rounded-xl border border-outline-variant/60 bg-white shadow-[0_12px_35px_rgba(0,13,47,0.05)]">

                <div className="border-b border-outline-variant/50 px-6 py-5">
                    <p className="text-xs font-semibold uppercase tracking-[0.15em] text-on-surface-variant">
                        Order summary
                    </p>
                </div>

                <div className="p-6">
                    <div className="flex items-start justify-between gap-6">
                        <div>
                            <p className="font-semibold text-primary">
                                {label}
                            </p>

                            <p className="mt-1 text-xs leading-5 text-on-surface-variant">
                                {isSubscription
                                    ? 'Monthly membership'
                                    : credits === 1
                                      ? '1 report credit'
                                      : `${credits} report credits`}
                            </p>
                        </div>

                        <p className="shrink-0 text-sm font-semibold text-primary">
                            {amountDisplay}
                        </p>
                    </div>

                    <div className="my-6 h-px bg-outline-variant/50" />

                    <div className="space-y-3">
                        <SummaryBenefit
                            text={
                                isSubscription
                                    ? `${credits} credits added every month`
                                    : `${credits} ${credits === 1 ? 'credit' : 'credits'} added after payment`
                            }
                        />

                        <SummaryBenefit text="Unused credits never expire" />

                        {isSubscription && (
                            <SummaryBenefit text="Cancel anytime from your account" />
                        )}
                    </div>

                    <div className="my-6 h-px bg-outline-variant/50" />

                    <div className="flex items-end justify-between gap-6">
                        <div>
                            <p className="text-xs text-on-surface-variant">
                                {isSubscription ? 'Due today' : 'Total'}
                            </p>

                            {isSubscription && (
                                <p className="mt-1 text-[11px] text-outline">
                                    Then billed monthly
                                </p>
                            )}
                        </div>

                        <p className="text-2xl font-bold tracking-tight text-primary">
                            {amountDisplay}
                        </p>
                    </div>
                </div>

                <div className="border-t border-outline-variant/40 bg-primary px-6 py-5 text-white">
                    <div className="flex items-start gap-3">
                        <ShieldCheck className="mt-0.5 size-5 shrink-0 text-white/80" />

                        <div>
                            <p className="text-sm font-semibold">
                                Secure payment
                            </p>

                            <p className="mt-1 text-xs leading-5 text-white/65">
                                Your card or PayPal credentials are handled directly by the payment provider.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
    );
}

function SummaryBenefit({ text }: { text: string }) {
    return (
        <div className="flex items-start gap-3 text-sm text-on-surface-variant">
            <div className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-surface-container">
                <Check className="size-3 text-primary" />
            </div>

            <span>{text}</span>
        </div>
    );
}

function PayPalSection({
    plan,
    planType,
    paypalClientId,
    paypalEnvironment,
}: {
    plan: string;
    planType: PlanType;
    paypalClientId: string | null;
    paypalEnvironment: 'sandbox' | 'production';
}) {
    const isSubscription = planType === 'subscription';

    return (
        <div className="mx-auto max-w-lg py-2">
            <div className="mb-7 text-center">
                <div className="mx-auto mb-4 flex size-11 items-center justify-center rounded-full bg-surface-container">
                    <Wallet className="size-5 text-primary" />
                </div>

                <h2 className="text-lg font-semibold text-primary">
                    {isSubscription ? 'Subscribe with PayPal' : 'Pay with PayPal'}
                </h2>

                <p className="mx-auto mt-2 max-w-sm text-sm leading-6 text-on-surface-variant">
                    {isSubscription
                        ? 'Approve your monthly membership securely using your PayPal account.'
                        : 'Continue with your PayPal account to complete this payment securely.'}
                </p>
            </div>

            {!paypalClientId ? (
                <PaymentError message="PayPal is not configured correctly." />
            ) : (
                <PayPalProvider
                    clientId={paypalClientId}
                    environment={paypalEnvironment}
                    components={
                        isSubscription
                            ? ['paypal-subscriptions']
                            : ['paypal-payments']
                    }
                    pageType="checkout"
                >
                    {isSubscription ? (
                        <PayPalSubscriptionCheckout plan={plan} />
                    ) : (
                        <PayPalOneTimeCheckout plan={plan} />
                    )}
                </PayPalProvider>
            )}
        </div>
    );
}

function PayPalOneTimeCheckout({ plan }: { plan: string }) {
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [isProcessing, setIsProcessing] = useState(false);

    async function createPayPalOrder(): Promise<{ orderId: string }> {
        setErrorMessage(null);

        const data = await postJson<{ id?: string }>(
            '/checkout/paypal/create-order',
            { plan },
        );

        if(!data.id) {
            throw new Error('PayPal order ID was not returned.');
        }

        return {
            orderId: data.id,
        };
    }

    async function handleApprove(data: OnApproveDataOneTimePayments) {
        setIsProcessing(true);
        setErrorMessage(null);

        try {
            const result = await postJson<{
                status?: string;
                redirect?: string;
            }>('/checkout/paypal/capture', {
                order_id: data.orderId,
            });

            if(result.status !== 'success') {
                throw new Error('PayPal payment was not completed.');
            }

            if(!result.redirect) {
                throw new Error('Checkout success URL was not returned.');
            }

            window.location.assign(result.redirect);
        } catch(error) {
            setIsProcessing(false);

            setErrorMessage(
                error instanceof Error
                    ? error.message
                    : 'PayPal payment failed.',
            );

            throw error;
        }
    }

    function handleError(error: OnErrorData) {
        setIsProcessing(false);
        setErrorMessage(error.message ?? 'Something went wrong with PayPal.');
    }

    return (
        <div>
            {errorMessage && (
                <div className="mb-4">
                    <PaymentError message={errorMessage} />
                </div>
            )}

            {isProcessing && (
                <PaymentProcessing text="Confirming payment..." />
            )}

            <div className={isProcessing ? 'pointer-events-none opacity-50' : ''}>
                <PayPalOneTimePaymentButton
                    createOrder={createPayPalOrder}
                    onApprove={handleApprove}
                    onCancel={() => {
                        setIsProcessing(false);
                        setErrorMessage('PayPal checkout was cancelled. No payment was taken.');
                    }}
                    onError={handleError}
                    presentationMode="auto"
                    disabled={isProcessing}
                />
            </div>

            <p className="mt-4 text-center text-xs text-outline">
                You'll return to UKCarDoc after PayPal confirms your payment.
            </p>
        </div>
    );
}

function PayPalSubscriptionCheckout({ plan }: { plan: string }) {
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [isProcessing, setIsProcessing] = useState(false);

    async function createSubscription(): Promise<{ subscriptionId: string }> {
        setErrorMessage(null);

        const data = await postJson<{ subscriptionId?: string }>(
            '/checkout/paypal/subscription/create',
            { plan },
        );

        if(!data.subscriptionId) {
            throw new Error('PayPal subscription ID was not returned.');
        }

        return {
            subscriptionId: data.subscriptionId,
        };
    }

    async function handleApprove(data: OnApproveDataSubscriptions) {
        setIsProcessing(true);
        setErrorMessage(null);

        try {
            const result = await postJson<{
                status?: string;
                redirect?: string;
            }>('/checkout/paypal/subscription/confirm', {
                subscription_id: data.subscriptionId,
            });

            if(result.status !== 'success') {
                throw new Error('PayPal subscription was not activated.');
            }

            if(!result.redirect) {
                throw new Error('Subscription success URL was not returned.');
            }

            window.location.assign(result.redirect);
        } catch(error) {
            setIsProcessing(false);

            setErrorMessage(
                error instanceof Error
                    ? error.message
                    : 'PayPal subscription failed.',
            );

            throw error;
        }
    }

    function handleError(error: OnErrorData) {
        setIsProcessing(false);
        setErrorMessage(
            error.message ?? 'Something went wrong with PayPal.',
        );
    }

    return (
        <div>
            {errorMessage && (
                <div className="mb-4">
                    <PaymentError message={errorMessage} />
                </div>
            )}

            {isProcessing && (
                <PaymentProcessing text="Activating membership..." />
            )}

            <div className={isProcessing ? 'pointer-events-none opacity-50' : ''}>
                <PayPalSubscriptionButton
                    createSubscription={createSubscription}
                    onApprove={handleApprove}
                    onCancel={() => {
                        setIsProcessing(false);
                        setErrorMessage(
                            'PayPal subscription was cancelled. No membership was created.',
                        );
                    }}
                    onError={handleError}
                    presentationMode="auto"
                    disabled={isProcessing}
                />
            </div>

            <p className="mt-4 text-center text-xs text-outline">
                You'll return to UKCarDoc after PayPal confirms your membership.
            </p>
        </div>
    );
}

function StripeCheckoutForm({
    amountDisplay,
    planType,
    subscriptionId,
}: {
    amountDisplay: string;
    planType: PlanType;
    subscriptionId: string | null;
}) {
    const stripe = useStripe();
    const elements = useElements();

    const [isProcessing, setIsProcessing] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const [billing, setBilling] = useState({
        line1: '',
        city: '',
        postalCode: '',
    });

    async function handleSubmit(event: FormEvent) {
        event.preventDefault();

        if(!stripe || !elements) return;

        if(planType === 'subscription' && !subscriptionId) {
            setErrorMessage(
                'Subscription could not be identified. Please refresh the page and try again.',
            );

            return;
        }

        setIsProcessing(true);
        setErrorMessage(null);

        const returnUrl = planType === 'subscription'
            ? `${window.location.origin}/checkout/subscription/success?subscription_id=${encodeURIComponent(subscriptionId!)}`
            : `${window.location.origin}/checkout/success`;

        const { error } = await stripe.confirmPayment({
            elements,

            confirmParams: {
                return_url: returnUrl,

                payment_method_data: {
                    billing_details: {
                        address: {
                            line1: billing.line1,
                            city: billing.city,
                            postal_code: billing.postalCode,
                            country: 'GB',
                        },
                    },
                },
            },
        });

        if(error) {
            setErrorMessage(error.message ?? 'Payment failed. Please try again.');
            setIsProcessing(false);
        }
    }

    return (
        <form onSubmit={handleSubmit}>
            <div>
                <div className="mb-5">
                    <h2 className="text-lg font-semibold text-primary">
                        Card details
                    </h2>

                    <p className="mt-1 text-sm text-on-surface-variant">
                        Enter your payment information below.
                    </p>
                </div>

                <PaymentElement />
            </div>

            <div className="my-7 h-px bg-outline-variant/40" />

            <div>
                <h2 className="text-lg font-semibold text-primary">
                    Billing address
                </h2>

                <p className="mt-1 text-sm text-on-surface-variant">
                    Used for payment verification.
                </p>

                <div className="mt-5 space-y-4">
                    <Field
                        label="Street address"
                        placeholder="123 Pall Mall"
                        value={billing.line1}
                        onChange={(value) =>
                            setBilling((current) => ({
                                ...current,
                                line1: value,
                            }))
                        }
                    />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="City"
                            placeholder="London"
                            value={billing.city}
                            onChange={(value) =>
                                setBilling((current) => ({
                                    ...current,
                                    city: value,
                                }))
                            }
                        />

                        <Field
                            label="Postcode"
                            placeholder="SW1A 1AA"
                            value={billing.postalCode}
                            onChange={(value) =>
                                setBilling((current) => ({
                                    ...current,
                                    postalCode: value,
                                }))
                            }
                        />
                    </div>
                </div>
            </div>

            {errorMessage && (
                <div className="mt-5">
                    <PaymentError message={errorMessage} />
                </div>
            )}

            <button
                type="submit"
                disabled={!stripe || isProcessing}
                className="mt-7 flex w-full items-center justify-center gap-2 rounded-lg bg-secondary px-6 py-4 text-sm font-semibold text-white shadow-[0_8px_20px_rgba(187,0,26,0.16)] transition-all hover:bg-secondary-container active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-50"
            >
                <Lock className="size-4" />

                {isProcessing
                    ? 'Processing payment...'
                    : planType === 'subscription'
                      ? `Subscribe for ${amountDisplay}`
                      : `Pay ${amountDisplay}`}
            </button>

            <p className="mt-4 text-center text-[11px] leading-5 text-outline">
                By continuing, you agree to UKCarDoc's Terms of Service and Refund Policy.
            </p>
        </form>
    );
}

function Field({
    label,
    placeholder,
    value,
    onChange,
}: {
    label: string;
    placeholder: string;
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <label className="block">
            <span className="mb-2 block text-xs font-semibold text-primary">
                {label}
            </span>

            <input
                required
                type="text"
                value={value}
                placeholder={placeholder}
                onChange={(event) => onChange(event.target.value)}
                className="w-full rounded-md border border-outline-variant bg-white px-4 py-3 text-sm text-primary outline-none transition focus:border-surface-tint focus:ring-1 focus:ring-surface-tint"
            />
        </label>
    );
}

function PaymentLoading() {
    return (
        <div className="flex min-h-40 items-center justify-center">
            <div className="text-center">
                <div className="mx-auto size-5 animate-spin rounded-full border-2 border-primary border-t-transparent" />

                <p className="mt-4 text-sm text-on-surface-variant">
                    Preparing secure payment…
                </p>
            </div>
        </div>
    );
}

function PaymentProcessing({ text }: { text: string }) {
    return (
        <div className="mb-4 flex items-center justify-center gap-3 rounded-lg bg-surface-container-low px-4 py-3 text-sm text-primary">
            <div className="size-4 animate-spin rounded-full border-2 border-primary border-t-transparent" />
            {text}
        </div>
    );
}

function PaymentError({ message }: { message: string }) {
    return (
        <div className="flex items-start gap-3 rounded-lg border border-error/15 bg-error-container/50 px-4 py-3.5 text-on-error-container">
            <ShieldAlert className="mt-0.5 size-4 shrink-0" />

            <p className="text-sm leading-5">
                {message}
            </p>
        </div>
    );
}

async function postJson<T extends object>(
    url: string,
    body: Record<string, unknown>,
): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',

        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': getCsrfToken(),
        },

        body: JSON.stringify(body),
    });

    const data = await response.json().catch(() => ({})) as T & {
        message?: string;
    };

    if(!response.ok) {
        throw new Error(data.message ?? 'Request failed. Please try again.');
    }

    return data;
}

function getCsrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>(
            'meta[name="csrf-token"]',
        )?.content ?? ''
    );
}

Checkout.layout = (page: ReactNode) => (
    <BaseLayout>
        {page}
    </BaseLayout>
);