import { Head } from '@inertiajs/react';
import { useState, useEffect, FormEvent } from 'react';
import { createIntent } from '@/routes/checkout';
import { Elements, PaymentElement, useStripe, useElements, } from '@stripe/react-stripe-js';
import { loadStripe } from '@stripe/stripe-js';
import { Lock, ShieldCheck, ShieldAlert, CheckCircle2, Check } from 'lucide-react';
import BaseLayout from '@/layouts/base-layout';

const stripePromise = loadStripe(import.meta.env.VITE_STRIPE_KEY);

type CheckoutProps = {
    plan: string;
    label: string;
    amountDisplay: string;
};

export default function Checkout({ plan, label, amountDisplay} : CheckoutProps) {
    const [clientSecret, setClientSecret] = useState<string | null>(null);
    const [initError, setInitError] = useState<string | null>(null);
    
    useEffect(() =>{
        fetch(createIntent.url(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN' :
                document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                    ?.content ?? '',
            },
            body: JSON.stringify({ plan }),
        })

        .then((res) => res.json())
        .then((data)=> {
            if (data.clientSecret) setClientSecret(data.clientSecret);
            else setInitError(data.message ?? 'Gagal memulai pembayaran.');
        })
        .catch(() => setInitError('Tidak bisa terhubung ke server pembayaran'));
    }, [plan]);

    return (
         <>
            <Head title="Secure Checkout" />
            <main className="mx-auto max-w-7xl px-8 py-section-padding">
                <div className="grid grid-cols-1 gap-12 lg:grid-cols-12">
                    {/* Left Column: Payment */}
                    <div className="space-y-12 lg:col-span-7">
                        <section>
                            <h1 className="font-h2 text-h2 mb-2 text-primary">Secure Checkout</h1>
                            <p className="font-body-lg mb-8 text-on-surface-variant">
                                Complete your purchase of <span className="font-bold text-primary">{label}</span>.
                            </p>
 
                            <div className="mb-8 grid grid-cols-2 gap-4">
                                <div className="flex items-center justify-center gap-2 rounded-lg bg-black py-4 text-white">
                                    <ShieldCheck className="size-4" />
                                    <span className="font-bold">Powered by Stripe</span>
                                </div>
                                <button
                                    type="button"
                                    disabled
                                    className="flex cursor-not-allowed items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white py-4 text-slate-400"
                                    title="PayPal akan tersedia setelah MVP"
                                >
                                    <span className="font-bold">PayPal</span>
                                    <span className="text-[10px] font-bold uppercase tracking-widest">
                                        Coming Soon
                                    </span>
                                </button>
                            </div>
 
                            <div className="sovereign-line mb-8" />
 
                            {initError && (
                                <div className="flex items-center gap-2 rounded border border-error-container bg-error-container p-4 text-on-error-container">
                                    <ShieldAlert className="size-5 shrink-0" />
                                    <p className="text-sm">{initError}</p>
                                </div>
                            )}
 
                            {!initError && !clientSecret && (
                                <p className="text-sm text-on-surface-variant">Menyiapkan pembayaran...</p>
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
                                                fontFamily: 'Inter, sans-serif',
                                                borderRadius: '4px',
                                            },
                                        },
                                    }}
                                >
                                    <CheckoutForm amountDisplay={amountDisplay} />
                                </Elements>
                            )}
                        </section>
                    </div>
 
                    {/* Right Column: Order Summary & Trust */}
                    <div className="space-y-8 lg:col-span-5">
                        <div className="rounded-lg border border-gray-200 bg-white p-8 shadow-[0_4px_20px_rgba(0,32,91,0.04)]">
                            <h3 className="font-h3 text-h3 mb-6 text-primary">Order Summary</h3>
                            <div className="mb-6 space-y-4">
                                <div className="flex items-start justify-between">
                                    <p className="font-bold text-primary">{label}</p>
                                    <span className="font-bold text-primary">{amountDisplay}</span>
                                </div>
                                <div className="sovereign-line" />
                                <div className="flex items-center gap-2 text-sm text-slate-600">
                                    <CheckCircle2 className="size-4 text-green-600" />
                                    Credits never expire — use them anytime to unlock a Full Report
                                </div>
                            </div>
                            <div className="mb-6 rounded bg-surface-container-low p-4">
                                <div className="flex items-center justify-between text-primary">
                                    <span className="font-bold">Total Amount Due</span>
                                    <span className="text-2xl font-black">{amountDisplay}</span>
                                </div>
                            </div>
                            <div className="flex items-center gap-3 text-xs text-slate-500">
                                <ShieldCheck className="size-4 shrink-0" />
                                Your credits will be available immediately after payment.
                            </div>
                        </div>
 
                        <div className="grid grid-cols-1 gap-4">
                            <div className="flex items-center gap-4 rounded border border-gray-100 bg-white p-4">
                                <div className="flex size-12 items-center justify-center rounded-full bg-surface-container">
                                    <ShieldCheck className="size-5 text-primary" />
                                </div>
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-widest text-primary">
                                        UK Government Data Partner
                                    </p>
                                    <p className="text-[11px] text-slate-500">
                                        Direct integration with DVLA &amp; DVSA systems.
                                    </p>
                                </div>
                            </div>
 
                            <div className="flex items-center justify-between rounded border border-gray-200 bg-slate-50 p-4">
                                <TrustBadge label="Verified by" value="VISA" />
                                <div className="h-8 w-px bg-gray-300" />
                                <TrustBadge label="Mastercard" value="ID Check" />
                                <div className="h-8 w-px bg-gray-300" />
                                <TrustBadge label="PCI DSS" value="Compliant" />
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </>
    );
}

function CheckoutForm({ amountDisplay }: {amountDisplay: string}) {
    const stripe = useStripe();
    const elements = useElements();
    const [isProcessing, setIsProcessing] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const [billing, setBilling] = useState({ line1: '', city: '', postalCode: ''});

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        if (!stripe || !elements) return;


        setIsProcessing(true);
        setErrorMessage(null);

        const { error } = await stripe.confirmPayment({
            elements,
            confirmParams: {
                return_url: `${window.location.origin}/checkout/success`,
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
            setErrorMessage(error.message ?? 'Pembayaran gagal, coba lagi.');
            setIsProcessing(false);
        }
    }

    return(
         <form onSubmit={handleSubmit} className="space-y-8">
            <div>
                <h3 className="font-h3 text-h3 mb-4 text-primary">Payment Details</h3>
                <PaymentElement />
            </div>
 
            <div className="space-y-4 pt-2">
                <h3 className="font-h3 text-h3 text-primary">Billing Address</h3>
                <div>
                    <label className="font-label-sm mb-2 block text-primary">STREET ADDRESS</label>
                    <input
                        required
                        type="text"
                        placeholder="123 Pall Mall"
                        value={billing.line1}
                        onChange={(e) => setBilling((b) => ({ ...b, line1: e.target.value }))}
                        className="w-full rounded border border-gray-300 px-4 py-3 outline-none transition-all focus:border-primary focus:ring-2 focus:ring-primary"
                    />
                </div>
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label className="font-label-sm mb-2 block text-primary">CITY</label>
                        <input
                            required
                            type="text"
                            placeholder="London"
                            value={billing.city}
                            onChange={(e) => setBilling((b) => ({ ...b, city: e.target.value }))}
                            className="w-full rounded border border-gray-300 px-4 py-3 outline-none transition-all focus:border-primary focus:ring-2 focus:ring-primary"
                        />
                    </div>
                    <div>
                        <label className="font-label-sm mb-2 block text-primary">POSTCODE</label>
                        <input
                            required
                            type="text"
                            placeholder="SW1A 1AA"
                            value={billing.postalCode}
                            onChange={(e) => setBilling((b) => ({ ...b, postalCode: e.target.value }))}
                            className="w-full rounded border border-gray-300 px-4 py-3 outline-none transition-all focus:border-primary focus:ring-2 focus:ring-primary"
                        />
                    </div>
                </div>
            </div>
 
            {errorMessage && (
                <div className="flex items-center gap-2 rounded border border-error-container bg-error-container p-4 text-on-error-container">
                    <ShieldAlert className="size-5 shrink-0" />
                    <p className="text-sm">{errorMessage}</p>
                </div>
            )}
 
            <div className="pt-2">
                <button
                    type="submit"
                    disabled={!stripe || isProcessing}
                    className="flex w-full items-center justify-center gap-3 rounded bg-secondary py-5 font-bold text-white shadow-lg transition-all hover:bg-red-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <Lock className="size-5" />
                    {isProcessing ? 'Processing...' : `Pay ${amountDisplay} Now`}
                </button>
                <p className="mt-4 text-center text-xs text-slate-500">
                    By clicking "Pay Now" you agree to our Terms of Service and Refund Policy.
                </p>
            </div>
        </form>
    );
}

function TrustBadge({ label, value }: {label: string; value: string;}) {
    return(
         <div className="px-2 text-center">
            <p className="text-[10px] font-black uppercase tracking-tighter text-slate-400">{label}</p>
            <p className="text-sm font-bold text-slate-600">{value}</p>
        </div>
    );
}

Checkout.layout = (page: React.ReactNode) => <BaseLayout>{page}</BaseLayout>;