import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, FileCheck2, CreditCard } from 'lucide-react';
import type { ReactNode } from 'react';
import BaseLayout from '@/layouts/base-layout';

const sections = [
    {
        title: '1. Scope of This Policy',
        paragraphs: [
            'This Refund and Cancellation Policy applies to purchases made through UKCarDoc, including:',
        ],
        bullets: [
            'Individual digital vehicle history reports.',
            'One-time credit purchases and credit bundles.',
            'Monthly membership and subscription plans.',
        ],
        notice: 'Nothing in this policy excludes or limits any statutory consumer rights that cannot legally be excluded.',
    },
    {
        title: '2. Digital Vehicle History Reports',
        paragraphs: [
            'UKCarDoc provides digital vehicle history reports using information obtained from third-party vehicle data providers.',
            'The availability and detail of information may vary between vehicles and data sources.',
            'A report may contain limited information or show that certain records are unavailable. The absence of a particular record does not necessarily mean that the relevant event has never occurred.',
            'Our reports are not a substitute for an independent vehicle inspection or professional mechanical assessment.',
            'If you believe your report is materially incorrect, incomplete compared with the service purchased, inaccessible or affected by a technical failure, please contact our support team.',
        ],
    },
    {
        title: '3. Digital Content and Cancellation Rights',
        paragraphs: [
            'Vehicle history reports are supplied in digital form.',
            'Depending on applicable consumer law, you may have a statutory cancellation right when purchasing digital content online.',
            'Under applicable UK consumer law, the 14-day cancellation right for digital content may be lost once supply begins only where the required conditions have been met.',
        ],
        bullets: [
            'You have given your express consent for supply to begin during the cancellation period.',
            'You have acknowledged that you will lose your right to cancel when supply begins.',
            'We have provided the required confirmation of your consent and acknowledgement.',
        ],
        notice: 'We will only rely on this exception where the required consent, acknowledgement and confirmation have actually been obtained. A general acceptance of these Terms or a payment confirmation alone does not constitute a waiver of statutory cancellation rights.',
    },
    {
        title: '4. When You May Request a Refund or Other Remedy',
        paragraphs: ['You may contact us for an investigation where:'],
        bullets: [
            'Payment was taken but the purchased report was not generated or made available.',
            'You were charged more than once for the same purchase.',
            'Your purchased report cannot be accessed due to a technical problem.',
            'The report was materially different from the service described at the time of purchase.',
            'A payment or account problem prevented you from receiving the purchased service.',
            'You have another entitlement to a refund or remedy under applicable consumer law.',
        ],
        notice: 'Depending on the circumstances and applicable law, the appropriate remedy may include restoring access, correcting or regenerating a report, providing a replacement or credit, or issuing a partial or full refund.',
    },
    {
        title: '5. Vehicle Data Availability and Discrepancies',
        paragraphs: [
            'Vehicle information is obtained from third-party sources. The records available may differ depending on the vehicle and the data provider.',
            'A limited result or the absence of a particular historical record does not automatically establish that the report service has failed.',
            'However, where a report does not conform to the service description, contains a material technical error or is otherwise subject to a statutory remedy, we will assess the matter in accordance with applicable law.',
            'Please contact support and provide your order reference and a description of the discrepancy so that we can investigate.',
        ],
    },
    {
        title: '6. Duplicate Payments and Payment Errors',
        paragraphs: [
            'If you believe that you have been charged more than once for the same order, contact us with the relevant transaction or invoice reference.',
            'We will investigate payment records with the relevant payment provider. Where a duplicate charge is confirmed, we will arrange an appropriate refund.',
            'Please do not submit another payment while an existing transaction is still being investigated.',
        ],
    },
    {
        title: '7. Credit Purchases and Credit Bundles',
        paragraphs: [
            'Credits are added to your account after successful payment confirmation.',
            'The current checkout information states that unused credits do not expire. Credits are associated with the customer account to which they were issued.',
            'Credits are not automatically converted into cash. Any refund request relating to unused credits will be assessed in accordance with the circumstances of the purchase and applicable consumer law.',
            'If you are considering deleting your account, please contact support first. Remaining credits may become unavailable when an account is deleted or anonymised under our account deletion process.',
            'Nothing in this section removes a refund or other statutory right that cannot legally be excluded.',
        ],
    },
    {
        title: '8. Monthly Memberships and Subscriptions',
        paragraphs: [
            'Monthly memberships automatically renew according to the billing frequency and price displayed before purchase.',
            'Membership plans provide the monthly credit allowance described on the relevant pricing page and at checkout.',
            'You may cancel your membership through your account subscription settings.',
            'Under the current cancellation process, cancellation stops future renewals while access to the existing paid membership remains available until the end of the current billing period.',
            'Cancelling a membership does not automatically constitute a refund request for a payment already made. Any statutory cancellation or refund rights remain unaffected.',
        ],
        notice: 'If you change your membership plan, the new plan may take effect at the next billing cycle as described in your account and subscription confirmation.',
    },
    {
        title: '9. How to Request a Refund',
        paragraphs: [
            'To request a refund or report a payment problem, contact us through our support page or support email address.',
            'Please include the following information where available:',
        ],
        bullets: [
            'Your name.',
            'The email address associated with your purchase.',
            'Your invoice or purchase reference.',
            'The vehicle registration number, where relevant.',
            'A clear description of the issue.',
        ],
        notice: 'For your security, do not send your full payment card number, card security code or account password.',
    },
    {
        title: '10. Refund Processing',
        paragraphs: [
            'Where we determine that a refund is due, we will process it without undue delay and within the time required by applicable law.',
            'Where required by law, refunds will be issued using the original payment method unless you expressly agree otherwise.',
            'The time it takes for a refunded amount to appear in your account may depend on your payment provider or financial institution.',
        ],
    },
    {
        title: '11. Contact Information',
        paragraphs: [
            'For questions about refunds, cancellations, reports or payments, please contact UKCarDoc using the details below.',
        ],
    },
];

export default function RefundPolicy() {
    return (
        <>
            <Head title="Refund & Cancellation Policy" />

            <section className="bg-primary py-section-padding text-white">
                <div className="mx-auto max-w-container-max px-gutter">
                    <Link
                        href="/"
                        className="mb-8 inline-flex items-center gap-2 text-sm text-white/70 transition-colors hover:text-white"
                    >
                        <ArrowLeft size={16} />
                        Back to home
                    </Link>

                    <div className="mb-5 flex size-12 items-center justify-center rounded-lg bg-white/10">
                        <FileCheck2 size={26} />
                    </div>

                    <p className="font-label-sm mb-3 text-label-sm tracking-widest text-white/60 uppercase">
                        Customer information
                    </p>

                    <h1 className="font-h1 mb-5 text-h1">
                        Refund &amp; Cancellation Policy
                    </h1>

                    <p className="max-w-2xl text-body-lg text-white/75">
                        Information about report purchases, credits, membership
                        cancellations, refunds and your consumer rights.
                    </p>

                    <p className="mt-6 text-sm text-white/60">
                        Last updated: 14 November 2026
                    </p>
                </div>
            </section>

            <section className="bg-surface-container-lowest py-section-padding">
                <div className="mx-auto grid max-w-container-max grid-cols-1 gap-10 px-gutter lg:grid-cols-[240px_minmax(0,1fr)]">
                    <aside className="h-fit rounded-xl border border-surface-variant bg-surface p-6">
                        <div className="mb-4 flex items-center gap-2 text-primary">
                            <CreditCard size={20} />
                            <h2 className="font-semibold">Payment support</h2>
                        </div>

                        <p className="text-sm leading-6 text-on-surface-variant">
                            If a report is unavailable, you notice a duplicate
                            payment or you need help with a subscription, please
                            contact our support team.
                        </p>

                        <Link
                            href="/support"
                            className="mt-5 inline-flex text-sm font-semibold text-primary underline underline-offset-4 hover:text-secondary"
                        >
                            Contact support
                        </Link>

                        <div className="mt-6 border-t border-surface-variant pt-5">
                            <p className="text-xs leading-5 text-on-surface-variant">
                                Support email:
                            </p>
                            <a
                                href="mailto:support@ukcardoc.com"
                                className="mt-1 text-sm font-semibold break-all text-primary hover:underline"
                            >
                                support@ukcardoc.com
                            </a>
                        </div>

                        <div className="mt-6 rounded-lg bg-surface-container-low p-4">
                            <p className="text-xs leading-5 text-on-surface-variant">
                                Please include your invoice or purchase
                                reference so that we can identify the
                                transaction.
                            </p>
                        </div>
                    </aside>

                    <article className="max-w-3xl min-w-0">
                        {sections.map((section) => (
                            <section
                                key={section.title}
                                className="scroll-mt-8 border-b border-surface-variant py-8 first:pt-0 last:border-0 last:pb-0"
                            >
                                <h2 className="font-h2 mb-4 text-h2 text-primary">
                                    {section.title}
                                </h2>

                                <div className="space-y-4 text-base leading-7 text-on-surface-variant">
                                    {section.paragraphs?.map((paragraph) => (
                                        <p key={paragraph}>{paragraph}</p>
                                    ))}

                                    {section.bullets && (
                                        <ul className="list-disc space-y-2 pl-6">
                                            {section.bullets.map((item) => (
                                                <li key={item}>{item}</li>
                                            ))}
                                        </ul>
                                    )}

                                    {section.notice && (
                                        <div className="rounded-lg border border-surface-variant bg-surface-container-low p-5 text-sm leading-6">
                                            {section.notice}
                                        </div>
                                    )}

                                    {section.title ===
                                        '11. Contact Information' && (
                                        <div className="rounded-lg bg-surface-container-low p-5">
                                            <p>
                                                <strong>Business name:</strong>{' '}
                                                UKCarDoc
                                            </p>
                                            <p className="mt-2">
                                                <strong>Support email:</strong>{' '}
                                                support@ukcardoc.com
                                            </p>
                                            <p className="mt-2">
                                                <strong>Support page:</strong>{' '}
                                                <Link
                                                    href="/support"
                                                    className="font-semibold text-primary underline underline-offset-4"
                                                >
                                                    Contact UKCarDoc
                                                </Link>
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </section>
                        ))}

                        <div className="mt-8 rounded-lg border border-surface-variant bg-surface-container-low p-5 text-sm leading-6 text-on-surface-variant">
                            <strong className="text-primary">Important:</strong>{' '}
                            This policy does not remove any mandatory
                            cancellation, refund or digital-content rights
                            available under applicable consumer law.
                        </div>
                    </article>
                </div>
            </section>
        </>
    );
}

RefundPolicy.layout = (page: ReactNode) => <BaseLayout>{page}</BaseLayout>;
