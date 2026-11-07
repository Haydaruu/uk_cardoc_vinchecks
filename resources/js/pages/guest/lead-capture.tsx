import { Head, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    CarFront,
    CheckCircle2,
    LockKeyhole,
    Mail,
    Phone,
    ShieldCheck,
} from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';

import BaseLayout from '@/layouts/base-layout';

type GuestLeadPageProps = {
    report: {
        id: number;
        registration_number: string | null;
        make: string | null;
        model: string | null;
    };
};

export default function GuestLeadCapture({ report }: GuestLeadPageProps) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        phone: '',
    });

    function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        post(`/reports/${report.id}/guest-lead`, {
            preserveScroll: true,
        });
    }

    const vehicleName = [report.make, report.model].filter(Boolean).join(' ');

    return (
        <>
            <Head title="Vehicle Identified | UKCardoc" />

            <main className="mx-auto flex min-h-[80vh] max-w-3xl items-center justify-center px-5 py-12">
                <section className="w-full overflow-hidden rounded-xl border border-outline-variant/60 bg-white shadow-sm">
                    <div className="h-1 bg-secondary" />

                    <div className="p-6 sm:p-10">
                        {/* Vehicle identification */}
                        <div className="mb-8 text-center">
                            <div className="mx-auto mb-5 flex size-16 items-center justify-center rounded-xl bg-surface-container">
                                <ShieldCheck className="size-8 text-primary" />
                            </div>

                            <div className="mb-3 inline-flex items-center gap-1.5 rounded-full bg-green-100 px-3 py-1 text-xs font-bold text-green-800">
                                <CheckCircle2 className="size-3.5" />
                                VEHICLE IDENTIFIED
                            </div>

                            <h1 className="text-2xl font-bold text-primary sm:text-3xl">
                                Your vehicle is ready
                            </h1>

                            <p className="mt-2 text-sm leading-6 text-on-surface-variant">
                                We have successfully identified your vehicle.
                                Enter your contact details to view its basic
                                report preview.
                            </p>
                        </div>

                        {/* Vehicle card */}
                        <div className="mb-8 rounded-lg border border-outline-variant/60 bg-surface-container-low p-5">
                            <div className="flex items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-lg bg-white">
                                    <CarFront className="size-6 text-primary" />
                                </div>

                                <div className="min-w-0 flex-1">
                                    <p className="text-xs font-semibold tracking-wider text-on-surface-variant uppercase">
                                        Identified vehicle
                                    </p>

                                    <h2 className="mt-1 truncate text-lg font-bold text-primary">
                                        {vehicleName || 'Vehicle details'}
                                    </h2>
                                </div>
                            </div>

                            <div className="mt-5 border-t border-outline-variant/50 pt-4">
                                <p className="mb-2 text-xs font-semibold text-on-surface-variant">
                                    UK Registration Number
                                </p>

                                <div className="inline-flex rounded-md border-2 border-primary bg-white px-4 py-2 font-mono text-xl font-bold tracking-wider text-primary">
                                    {report.registration_number || 'N/A'}
                                </div>
                            </div>
                        </div>

                        {/* Contact form */}
                        <div className="mb-6">
                            <h3 className="text-lg font-bold text-primary">
                                Where can we reach you?
                            </h3>

                            <p className="mt-1 text-sm leading-6 text-on-surface-variant">
                                Add your email to continue. A phone number is
                                optional.
                            </p>
                        </div>

                        <form onSubmit={handleSubmit} className="space-y-5">
                            <div>
                                <label
                                    htmlFor="lead-email"
                                    className="mb-2 block text-sm font-semibold text-primary"
                                >
                                    Email address
                                    <span className="ml-1 text-error">*</span>
                                </label>

                                <div className="relative">
                                    <Mail className="absolute top-1/2 left-4 size-4 -translate-y-1/2 text-outline" />

                                    <input
                                        id="lead-email"
                                        type="email"
                                        autoComplete="email"
                                        maxLength={254}
                                        required
                                        autoFocus
                                        value={data.email}
                                        onChange={(event) =>
                                            setData('email', event.target.value)
                                        }
                                        placeholder="you@example.com"
                                        aria-invalid={Boolean(errors.email)}
                                        aria-describedby={
                                            errors.email
                                                ? 'lead-email-error'
                                                : undefined
                                        }
                                        className="w-full rounded-md border border-outline-variant bg-white py-3 pr-4 pl-11 text-sm text-primary transition outline-none placeholder:text-outline/70 focus:border-surface-tint focus:ring-1 focus:ring-surface-tint"
                                    />
                                </div>

                                {errors.email && (
                                    <p
                                        id="lead-email-error"
                                        className="mt-2 text-sm text-error"
                                    >
                                        {errors.email}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label
                                    htmlFor="lead-phone"
                                    className="mb-2 block text-sm font-semibold text-primary"
                                >
                                    Phone number
                                    <span className="ml-2 text-xs font-normal text-outline">
                                        (Optional)
                                    </span>
                                </label>

                                <div className="relative">
                                    <Phone className="absolute top-1/2 left-4 size-4 -translate-y-1/2 text-outline" />

                                    <input
                                        id="lead-phone"
                                        type="tel"
                                        inputMode="tel"
                                        autoComplete="tel"
                                        maxLength={32}
                                        value={data.phone}
                                        onChange={(event) =>
                                            setData('phone', event.target.value)
                                        }
                                        placeholder="+44 7xxx xxx xxx"
                                        aria-invalid={Boolean(errors.phone)}
                                        aria-describedby={
                                            errors.phone
                                                ? 'lead-phone-error'
                                                : undefined
                                        }
                                        className="w-full rounded-md border border-outline-variant bg-white py-3 pr-4 pl-11 text-sm text-primary transition outline-none placeholder:text-outline/70 focus:border-surface-tint focus:ring-1 focus:ring-surface-tint"
                                    />
                                </div>

                                {errors.phone && (
                                    <p
                                        id="lead-phone-error"
                                        className="mt-2 text-sm text-error"
                                    >
                                        {errors.phone}
                                    </p>
                                )}
                            </div>

                            <div className="rounded-lg bg-surface-container-low p-4">
                                <div className="flex items-start gap-3">
                                    <LockKeyhole className="mt-0.5 size-5 shrink-0 text-primary" />

                                    <div>
                                        <p className="text-sm font-semibold text-primary">
                                            Your details, your report
                                        </p>

                                        <p className="mt-1 text-xs leading-5 text-on-surface-variant">
                                            Your contact details will be linked
                                            to this vehicle report. No payment
                                            is required to continue to the basic
                                            preview.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                className="flex w-full items-center justify-center gap-2 rounded-lg bg-secondary px-6 py-4 text-sm font-bold text-white shadow-sm transition hover:bg-secondary-container active:scale-[0.99] disabled:cursor-wait disabled:opacity-60"
                            >
                                {processing
                                    ? 'Preparing your preview...'
                                    : 'Continue to Vehicle Preview'}

                                {!processing && (
                                    <ArrowRight className="size-4" />
                                )}
                            </button>

                            <p className="text-center text-xs leading-5 text-outline">
                                <LockKeyhole className="mr-1 inline size-3" />
                                Secure contact submission
                            </p>
                        </form>
                    </div>
                </section>
            </main>
        </>
    );
}

GuestLeadCapture.layout = (page: ReactNode) => <BaseLayout>{page}</BaseLayout>;
