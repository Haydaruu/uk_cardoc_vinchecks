import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, FileText, ShieldCheck } from 'lucide-react';
import BaseLayout from '@/layouts/base-layout';

const sections = [
    {
        title: '1. About These Terms',
        paragraphs: [
            'These Terms and Conditions govern your access to and use of the VinCheck website, vehicle history reports, accounts, and related services.',
            'The Services are operated from Indonesia by [Full Legal Name of Operator]. By using the Services or placing an order, you agree to these Terms, subject to any mandatory rights that apply to you under applicable law.',
        ],
    },
    {
        title: '2. Our Vehicle History Reports',
        paragraphs: [
            'VinCheck provides vehicle history reports using information obtained from third-party data providers and other available sources.',
            'The information available may vary between vehicles. We do not guarantee that every report will contain every historical event or record associated with a vehicle.',
        ],
    },
    {
        title: '3. Accuracy and Limitations',
        paragraphs: [
            'Information may be incomplete, delayed, unavailable, or inaccurate because it originates from third-party sources. The absence of a record does not establish that an event has never occurred.',
            'A report is not a substitute for a physical vehicle inspection, professional mechanical assessment, or independent verification of important information before purchasing a vehicle.',
            'Nothing in these Terms excludes rights or remedies that cannot lawfully be excluded under applicable consumer law.',
        ],
    },
    {
        title: '4. User Accounts and Guest Purchases',
        paragraphs: [
            'Certain Services require an account. You agree to provide accurate information and take reasonable steps to protect your login credentials.',
            'Guest purchases may require an email address and other information needed to process your order and deliver your report. You are responsible for providing a valid email address.',
        ],
    },
    {
        title: '5. Orders and Payments',
        paragraphs: [
            'Prices, applicable taxes, product details, and available payment methods will be displayed before you confirm your purchase.',
            'VinCheck may accept payments through Stripe and PayPal. An order is treated as paid when payment has been successfully confirmed through the relevant payment process.',
            'A checkout success page or return to our website does not by itself guarantee that payment has been confirmed or that a report has been delivered.',
        ],
    },
    {
        title: '6. Report Delivery',
        paragraphs: [
            'After successful payment, we will take reasonable steps to generate and deliver the purchased report through the delivery method described at checkout.',
            'Delivery may be delayed by technical issues or third-party data availability. If your report is unavailable or inaccessible, contact our support team with your order reference.',
        ],
    },
    {
        title: '7. Credits and Subscriptions',
        paragraphs: [
            'Where credits or subscription plans are offered, their prices, billing frequency, included features, and applicable restrictions will be disclosed before purchase.',
            'Subscription cancellation, renewal, unused credits, and refunds are subject to the terms presented for the relevant plan and applicable law.',
        ],
    },
    {
        title: '8. Cancellations and Refunds',
        paragraphs: [
            'If you are charged but your report is not generated or made available, contact support so we can investigate and determine an appropriate remedy.',
            'Duplicate charges and reports that do not conform to their description will be assessed in accordance with the circumstances and applicable law.',
            'Where required, we will obtain separate express consent and acknowledgement before beginning immediate supply of eligible digital content when that affects statutory cancellation rights.',
            'Nothing in these Terms removes any consumer cancellation, refund, or other statutory right that cannot lawfully be excluded.',
        ],
    },
    {
        title: '9. Acceptable Use',
        paragraphs: [
            'You must not use the Services for unlawful or fraudulent purposes, attempt to access another customer’s account or report without authorisation, interfere with system security, circumvent payment requirements, or misuse vehicle information to target individuals unlawfully.',
        ],
    },
    {
        title: '10. Third-Party Services',
        paragraphs: [
            'VinCheck may rely on third-party providers for vehicle information, payment processing, hosting, and email delivery. Third-party services may experience interruptions or limitations.',
            'Our use of third-party providers does not remove our own legal obligations to customers.',
        ],
    },
    {
        title: '11. Intellectual Property',
        paragraphs: [
            'Unless otherwise stated, the VinCheck website, branding, software, interface, and original materials are owned by or licensed to VinCheck.',
            'You may use the Services for their intended purposes. Purchasing a report does not transfer ownership of the website, software, or underlying third-party databases.',
        ],
    },
    {
        title: '12. Privacy',
        paragraphs: [
            'Personal information is processed in connection with account management, payment processing, report delivery, customer support, security, and other disclosed purposes.',
            'Please review our Privacy Policy for information about how personal data is collected, used, shared, retained, and protected.',
        ],
    },
    {
        title: '13. Availability and Changes',
        paragraphs: [
            'We aim to keep the Services available but cannot guarantee uninterrupted access at all times. Maintenance, technical issues, or third-party interruptions may affect availability.',
            'We may update these Terms from time to time. Changes will not remove rights that customers have already acquired under applicable law.',
        ],
    },
    {
        title: '14. Liability and Consumer Rights',
        paragraphs: [
            'Nothing in these Terms excludes or limits liability where such exclusion or limitation is prohibited by law.',
            'Nothing in these Terms removes statutory consumer rights or remedies relating to the Services or digital content.',
        ],
    },
    {
        title: '15. Governing Law and Disputes',
        paragraphs: [
            'The governing law and jurisdiction applicable to these Terms must be confirmed based on the legal identity of the operator, its business arrangements, and the markets in which VinCheck operates.',
            'Any governing-law provision remains subject to mandatory consumer protections that cannot lawfully be excluded.',
        ],
    },
    {
        title: '16. Contact Us',
        paragraphs: [
            'For questions about these Terms, an order, or a vehicle history report, contact us using the details below.',
        ],
    },
];

export default function TermsAndConditions() {
    return (
        <>
            <Head title="Terms & Conditions" />

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
                        <FileText size={26} />
                    </div>

                    <p className="font-label-sm mb-3 text-label-sm tracking-widest text-white/60 uppercase">
                        Legal information
                    </p>

                    <h1 className="font-h1 mb-5 text-h1">Terms & Conditions</h1>

                    <p className="max-w-2xl text-body-lg text-white/75">
                        Please read these terms carefully before using VinCheck
                        or purchasing a vehicle history report.
                    </p>

                    <p className="mt-6 text-sm text-white/60">
                        Last updated: [Insert publication date]
                    </p>
                </div>
            </section>

            <section className="bg-surface-container-lowest py-section-padding">
                <div className="mx-auto grid max-w-container-max grid-cols-1 gap-10 px-gutter lg:grid-cols-[240px_minmax(0,1fr)]">
                    <aside className="h-fit rounded-xl border border-surface-variant bg-surface p-6">
                        <div className="mb-4 flex items-center gap-2 text-primary">
                            <ShieldCheck size={20} />
                            <h2 className="font-semibold">
                                Before you proceed
                            </h2>
                        </div>

                        <p className="text-sm leading-6 text-on-surface-variant">
                            Vehicle reports rely on available third-party
                            information. Please review the limitations and
                            applicable consumer rights described below.
                        </p>

                        <Link
                            href="/support"
                            className="mt-5 inline-flex text-sm font-semibold text-primary underline underline-offset-4 hover:text-secondary"
                        >
                            Contact support
                        </Link>
                    </aside>

                    <article className="max-w-3xl min-w-0 space-y-0">
                        {sections.map((section) => (
                            <section
                                key={section.title}
                                className="scroll-mt-8 border-b border-surface-variant py-8 first:pt-0 last:border-0 last:pb-0"
                            >
                                <h2 className="font-h2 mb-4 text-h2 text-primary">
                                    {section.title}
                                </h2>

                                <div className="space-y-4 text-base leading-7 text-on-surface-variant">
                                    {section.paragraphs.map((paragraph) => (
                                        <p key={paragraph}>{paragraph}</p>
                                    ))}

                                    {section.title === '16. Contact Us' && (
                                        <div className="rounded-lg bg-surface-container-low p-5">
                                            <p>
                                                <strong>Operator:</strong> [Full
                                                Legal Name of Operator]
                                            </p>
                                            <p className="mt-2">
                                                <strong>
                                                    Business address:
                                                </strong>{' '}
                                                [Full Business Address in
                                                Indonesia]
                                            </p>
                                            <p className="mt-2">
                                                <strong>Support email:</strong>{' '}
                                                [Confirmed Support Email
                                                Address]
                                            </p>
                                            <p className="mt-2">
                                                <strong>Privacy Policy:</strong>{' '}
                                                [Privacy Policy URL]
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </section>
                        ))}
                    </article>
                </div>
            </section>
        </>
    );
}

TermsAndConditions.layout = (page: React.ReactNode) => (
    <BaseLayout>{page}</BaseLayout>
);
