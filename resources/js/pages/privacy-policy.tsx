import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ShieldCheck, LockKeyhole } from 'lucide-react';
import type { ReactNode } from 'react';
import BaseLayout from '@/layouts/base-layout';

const sections = [
    {
        title: '1. Who We Are',
        paragraphs: [
            'UKCarDoc is a vehicle information service operated by UKCarDoc Ltd, trading as UKCarDoc.',
            'For the purposes of applicable data protection laws, the data controller responsible for the personal information described in this Privacy Policy is the operator identified below.',
        ],
    },
    {
        title: '2. Personal Information We Collect',
        paragraphs: [
            'Depending on how you use our services, we may collect the following categories of personal information.',
        ],
        subsections: [
            {
                title: 'Account and Profile Information',
                bullets: [
                    'Name and email address.',
                    'Phone number, where provided.',
                    'Account credentials and authentication information.',
                    'Email verification and account security information.',
                    'Information received through Google or Microsoft sign-in, such as your provider account identifier, email address and profile image.',
                ],
            },
            {
                title: 'Vehicle and Report Information',
                bullets: [
                    'Vehicle Registration Mark (VRM).',
                    'Vehicle Identification Number (VIN), where provided.',
                    'Vehicle details submitted for a report.',
                    'Vehicle information returned by our third-party vehicle data providers.',
                    'Generated reports and information relating to report access and delivery.',
                ],
            },
            {
                title: 'Guest Purchases and Contact Details',
                bullets: [
                    'Customer name, email address and phone number.',
                    'Purchase and report references.',
                    'Information required to process an order and provide access to a purchased report.',
                ],
            },
            {
                title: 'Payment and Transaction Information',
                bullets: [
                    'Purchased products, credit packages and membership plans.',
                    'Transaction references, payment status, amounts and currencies.',
                    'Payment provider references and invoice information.',
                    'Subscription and billing information.',
                    'Limited payment details provided by a payment provider, such as card brand or last four digits where available.',
                ],
            },
            {
                title: 'Customer Support Information',
                bullets: [
                    'Name, email address and subject submitted through our support form.',
                    'Messages, enquiries and related correspondence.',
                    'Information provided through our live-chat service.',
                ],
            },
            {
                title: 'Technical and Security Information',
                bullets: [
                    'IP addresses, where collected.',
                    'Browser and device information.',
                    'Session identifiers and security-related activity.',
                    'Timestamps and technical logs.',
                ],
            },
        ],
    },
    {
        title: '3. How We Use Your Information',
        paragraphs: ['We use personal information for the following purposes:'],
        bullets: [
            'Create and manage customer accounts.',
            'Authenticate users and maintain account security.',
            'Process vehicle searches and generate vehicle history reports.',
            'Deliver purchased reports and invoices.',
            'Process payments, credit purchases and membership subscriptions.',
            'Maintain purchase history and subscription records.',
            'Respond to customer support requests.',
            'Detect suspicious activity, fraud and misuse.',
            'Maintain, troubleshoot and secure our website.',
            'Meet applicable accounting, legal and regulatory obligations.',
        ],
    },
    {
        title: '4. Legal Bases for Processing',
        paragraphs: [
            'Where the UK GDPR applies, we rely on the legal basis appropriate to each processing activity.',
        ],
        subsections: [
            {
                title: 'Contract',
                paragraphs: [
                    'We process information where necessary to create accounts, process purchases, generate reports, manage credits and subscriptions, and provide services requested by customers.',
                ],
            },
            {
                title: 'Legitimate Interests',
                paragraphs: [
                    'We may process information to protect our services, prevent fraud, maintain security, troubleshoot technical issues and manage our business, provided these interests do not override your rights and freedoms.',
                ],
            },
            {
                title: 'Legal Obligation',
                paragraphs: [
                    'We may process and retain information to comply with applicable financial, accounting, regulatory and legal requirements.',
                ],
            },
            {
                title: 'Consent',
                paragraphs: [
                    'Where consent is required, including for applicable non-essential cookies or optional processing, we will request it before carrying out that processing. You may withdraw consent where consent is the relevant legal basis.',
                ],
            },
        ],
    },
    {
        title: '5. Third-Party Services and Data Sharing',
        paragraphs: [
            'We may share information with third-party service providers where necessary to operate UKCarDoc and provide the requested services.',
        ],
        subsections: [
            {
                title: 'Vehicle Data Providers',
                paragraphs: [
                    'CheckCarDetails and/or OneAuto may be used to retrieve vehicle information, depending on the provider configuration and service availability.',
                ],
            },
            {
                title: 'Payment Providers',
                paragraphs: [
                    'PayPal and Stripe may process payment information and transaction details when the relevant payment method is made available.',
                ],
            },
            {
                title: 'Authentication Providers',
                paragraphs: [
                    'Google and Microsoft may process authentication information when you choose to connect or sign in using their services.',
                ],
            },
            {
                title: 'Customer Support and Communications',
                paragraphs: [
                    'Tidio may process information when you use our live-chat service. Our configured email provider may process information required to send account, purchase, report-access and support emails.',
                ],
            },
            {
                title: 'Operational Notifications',
                paragraphs: [
                    'Telegram may be used for internal operational notifications. These notifications may contain limited transaction, vehicle or guest activity information and are intended for authorised personnel.',
                ],
            },
            {
                title: 'Hosting and Infrastructure',
                paragraphs: [
                    'Our website, database and related infrastructure may be hosted or supported by third-party infrastructure providers. [Insert the actual hosting and infrastructure providers.]',
                ],
            },
            {
                title: 'Other Disclosures',
                paragraphs: [
                    'We may disclose information to professional advisers, regulators, law enforcement agencies or other authorities where required by law or necessary to protect our legal rights.',
                    'We do not sell personal information to third parties.',
                ],
            },
        ],
    },
    {
        title: '6. International Data Transfers',
        paragraphs: [
            'UKCarDoc is operated from Indonesia, and certain third-party service providers may process information in other countries.',
            'Where applicable data protection laws restrict international transfers, we will use an appropriate legal transfer mechanism and safeguards.',
            'You may contact us using the details below to request further information about relevant transfer safeguards.',
        ],
    },
    {
        title: '7. Data Retention',
        paragraphs: [
            'We retain personal information only for as long as reasonably necessary for the purposes described in this policy, including service delivery, account management, legal obligations, accounting, security and dispute resolution.',
            'Our retention schedule should be reviewed and confirmed for each category of information before publication.',
        ],
        subsections: [
            {
                title: 'Retention Periods',
                bullets: [
                    'Account and report information: [Insert actual retention period or criteria].',
                    'Payment and invoice records: [Insert applicable accounting and legal retention period].',
                    'Guest purchase and access information: [Insert actual retention period or criteria].',
                    'Customer support correspondence: [Insert actual retention period].',
                    'Technical and security logs: [Insert actual retention period].',
                ],
            },
        ],
        notice: 'Some information may be retained after an account is closed or deleted where required by law or necessary to establish, exercise or defend legal claims.',
    },
    {
        title: '8. Data Security',
        paragraphs: [
            'We use reasonable technical and organisational measures designed to protect information against unauthorised access, alteration, disclosure, loss or misuse.',
            'However, no internet transmission or electronic storage system can be guaranteed to be completely secure.',
        ],
    },
    {
        title: '9. Your Privacy Rights',
        paragraphs: [
            'Depending on the law applicable to your information, you may have the right to:',
        ],
        bullets: [
            'Request access to your personal information.',
            'Request correction of inaccurate or incomplete information.',
            'Request deletion of personal information.',
            'Request restriction of processing.',
            'Object to certain processing activities.',
            'Request data portability.',
            'Withdraw consent where processing relies on consent.',
            'Lodge a complaint with a relevant supervisory authority.',
        ],
        notice: 'These rights are subject to applicable legal conditions and exceptions. We may need to verify your identity before responding to a request.',
    },
    {
        title: '10. Cookies and Similar Technologies',
        paragraphs: [
            'UKCarDoc uses essential website technologies to support authentication, sessions, security and website functionality.',
            'Third-party services, including payment and live-chat services, may also use cookies or similar technologies.',
            'Where consent is legally required, we will provide an appropriate mechanism for users to review and manage non-essential cookies before they are used.',
            'Further information about individual cookies, their purposes and retention periods should be provided in our Cookie Policy or cookie preference interface.',
        ],
    },
    {
        title: '11. Changes to This Policy',
        paragraphs: [
            'We may update this Privacy Policy when our services, business practices or legal obligations change.',
            'The latest version will be published on this page with its updated revision date.',
        ],
    },
];

export default function PrivacyPolicy() {
    return (
        <>
            <Head title="Privacy Policy" />

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
                        <ShieldCheck size={26} />
                    </div>

                    <p className="font-label-sm mb-3 text-label-sm tracking-widest text-white/60 uppercase">
                        Legal information
                    </p>

                    <h1 className="font-h1 mb-5 text-h1">Privacy Policy</h1>

                    <p className="max-w-2xl text-body-lg text-white/75">
                        Learn how UKCarDoc collects, uses, stores and protects
                        your personal information when you use our services.
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
                            <LockKeyhole size={20} />
                            <h2 className="font-semibold">
                                Your privacy matters
                            </h2>
                        </div>

                        <p className="text-sm leading-6 text-on-surface-variant">
                            We process information to provide vehicle reports,
                            manage accounts and payments, and maintain the
                            security of our services.
                        </p>

                        <Link
                            href="/support"
                            className="mt-5 inline-flex text-sm font-semibold text-primary underline underline-offset-4 hover:text-secondary"
                        >
                            Contact support
                        </Link>

                        <div className="mt-6 border-t border-surface-variant pt-5">
                            <p className="text-xs leading-5 text-on-surface-variant">
                                Privacy enquiries:
                            </p>
                            <a
                                href="mailto:support@ukcardoc.com"
                                className="mt-1 text-sm font-semibold break-all text-primary hover:underline"
                            >
                                support@ukcardoc.com
                            </a>
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

                                    {section.subsections?.map((subsection) => (
                                        <div
                                            key={subsection.title}
                                            className="space-y-3 pt-3"
                                        >
                                            <h3 className="font-semibold text-primary">
                                                {subsection.title}
                                            </h3>

                                            {'paragraphs' in subsection &&
                                                subsection.paragraphs.map(
                                                    (paragraph) => (
                                                        <p key={paragraph}>
                                                            {paragraph}
                                                        </p>
                                                    ),
                                                )}

                                            {'bullets' in subsection && (
                                                <ul className="list-disc space-y-2 pl-6">
                                                    {subsection.bullets.map(
                                                        (item) => (
                                                            <li key={item}>
                                                                {item}
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            )}
                                        </div>
                                    ))}

                                    {section.notice && (
                                        <div className="rounded-lg border border-surface-variant bg-surface-container-low p-5 text-sm leading-6">
                                            {section.notice}
                                        </div>
                                    )}

                                    {section.title === '1. Who We Are' && (
                                        <div className="rounded-lg bg-surface-container-low p-5">
                                            <p>
                                                <strong>Trading name:</strong>{' '}
                                                UKCarDoc
                                            </p>
                                            <p className="mt-2">
                                                <strong>
                                                    Privacy contact:
                                                </strong>{' '}
                                                support@ukcardoc.com
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </section>
                        ))}

                        <div className="mt-8 rounded-lg border border-surface-variant bg-surface-container-low p-5 text-sm leading-6 text-on-surface-variant">
                            <strong className="text-primary">Important:</strong>{' '}
                            Please complete all operator, representative and
                            retention details and verify the actual services
                            used by UKCarDoc before publishing this policy.
                        </div>
                    </article>
                </div>
            </section>
        </>
    );
}

PrivacyPolicy.layout = (page: ReactNode) => <BaseLayout>{page}</BaseLayout>;
