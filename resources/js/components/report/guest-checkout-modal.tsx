
import { useForm } from '@inertiajs/react';
import { LockKeyhole, Mail, ShieldCheck, X } from 'lucide-react';
import { useEffect } from 'react';

type GuestCheckoutModalProps = {
    reportId: number;
    onClose: () => void;
};

export default function GuestCheckoutModal({
    reportId,
    onClose,
}: GuestCheckoutModalProps) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
    } = useForm({
        name: '',
        email: '',
        phone: '',
    });

    useEffect(() => {
        function handleKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape' && !processing) {
                onClose();
            }
        }

        window.addEventListener('keydown', handleKeyDown);

        return () => {
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [onClose, processing]);

    function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();

        post(`/reports/${reportId}/guest-checkout/prepare`, {
            preserveScroll: true,
        });
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center
                overflow-y-auto bg-primary/50 px-4 py-6 backdrop-blur-sm"
            onMouseDown={(event) => {
                if (
                    event.target === event.currentTarget
                    && !processing
                ) {
                    onClose();
                }
            }}
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="guest-checkout-title"
                className="my-auto w-full max-w-lg overflow-hidden rounded-xl
                    border border-outline-variant/60 bg-white
                    shadow-[0_20px_60px_rgba(0,13,47,0.18)]"
            >
                <div className="h-1 bg-secondary" />

                <div className="p-6 sm:p-8">
                    <div className="mb-6 flex items-start justify-between gap-4">
                        <div className="flex items-start gap-3">
                            <div className="flex size-12 shrink-0 items-center
                                justify-center rounded-lg bg-surface-container">
                                <ShieldCheck className="size-6 text-primary" />
                            </div>

                            <div>
                                <h2
                                    id="guest-checkout-title"
                                    className="text-xl font-bold text-primary"
                                >
                                    Unlock Full Report
                                </h2>

                                <p className="mt-1 text-sm text-on-surface-variant">
                                    Secure guest checkout
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            onClick={onClose}
                            disabled={processing}
                            aria-label="Close checkout dialog"
                            className="rounded-md p-2 text-outline transition
                                hover:bg-surface-container-low hover:text-primary
                                disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <X className="size-5" />
                        </button>
                    </div>

                    <div className="mb-6">
                        <h3 className="text-lg font-semibold text-primary">
                            Where should we send your report?
                        </h3>

                        <p className="mt-2 text-sm leading-6 text-on-surface-variant">
                            Enter your contact details before continuing
                            to secure payment.
                        </p>
                    </div>

                    <form onSubmit={handleSubmit} className="space-y-5">
                        <div>
                            <label
                                htmlFor="guest-name"
                                className="mb-2 block text-sm font-semibold text-primary"
                            >
                                Full name
                            </label>

                            <input
                                id="guest-name"
                                type="text"
                                autoComplete="name"
                                maxLength={150}
                                required
                                value={data.name}
                                onChange={(event) =>
                                    setData('name', event.target.value)
                                }
                                placeholder="Enter your full name"
                                aria-invalid={Boolean(errors.name)}
                                className="w-full rounded-md border border-outline-variant
                                    bg-white px-4 py-3 text-sm text-primary outline-none
                                    transition placeholder:text-outline/70
                                    focus:border-surface-tint focus:ring-1
                                    focus:ring-surface-tint"
                            />

                            {errors.name && (
                                <p className="mt-2 text-sm text-error">
                                    {errors.name}
                                </p>
                            )}
                        </div>

                        <div>
                            <label
                                htmlFor="guest-email"
                                className="mb-2 block text-sm font-semibold text-primary"
                            >
                                Email address
                            </label>

                            <input
                                id="guest-email"
                                type="email"
                                autoComplete="email"
                                maxLength={254}
                                required
                                value={data.email}
                                onChange={(event) =>
                                    setData('email', event.target.value)
                                }
                                placeholder="you@example.com"
                                aria-invalid={Boolean(errors.email)}
                                className="w-full rounded-md border border-outline-variant
                                    bg-white px-4 py-3 text-sm text-primary outline-none
                                    transition placeholder:text-outline/70
                                    focus:border-surface-tint focus:ring-1
                                    focus:ring-surface-tint"
                            />

                            {errors.email && (
                                <p className="mt-2 text-sm text-error">
                                    {errors.email}
                                </p>
                            )}
                        </div>

                        <div>
                            <label
                                htmlFor="guest-phone"
                                className="mb-2 block text-sm font-semibold text-primary"
                            >
                                Phone number
                            </label>

                            <input
                                id="guest-phone"
                                type="tel"
                                inputMode="tel"
                                autoComplete="tel"
                                maxLength={32}
                                required
                                value={data.phone}
                                onChange={(event) =>
                                    setData('phone', event.target.value)
                                }
                                placeholder="+44 7xxx xxx xxx"
                                aria-invalid={Boolean(errors.phone)}
                                className="w-full rounded-md border border-outline-variant
                                    bg-white px-4 py-3 text-sm text-primary outline-none
                                    transition placeholder:text-outline/70
                                    focus:border-surface-tint focus:ring-1
                                    focus:ring-surface-tint"
                            />

                            {errors.phone && (
                                <p className="mt-2 text-sm text-error">
                                    {errors.phone}
                                </p>
                            )}
                        </div>

                        <div className="rounded-lg bg-surface-container-low p-4">
                            <div className="flex items-start gap-3">
                                <Mail className="mt-0.5 size-5 shrink-0 text-primary" />

                                <div>
                                    <p className="text-sm font-semibold text-primary">
                                        Report access by email
                                    </p>

                                    <p className="mt-1 text-xs leading-5 text-on-surface-variant">
                                        Your private report link will be emailed
                                        when the full report is ready.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="flex w-full items-center justify-center
                                gap-2 rounded-lg bg-secondary px-6 py-4
                                text-sm font-semibold text-white shadow-sm
                                transition hover:bg-secondary-container
                                active:scale-[0.99] disabled:cursor-wait
                                disabled:opacity-60"
                        >
                            <LockKeyhole className="size-4" />

                            {processing
                                ? 'Preparing checkout...'
                                : 'Continue to Checkout'}
                        </button>

                        <p className="text-center text-xs leading-5 text-outline">
                            Your contact details are used to prepare
                            your guest report purchase.
                        </p>
                    </form>
                </div>
            </section>
        </div>
    );
}
