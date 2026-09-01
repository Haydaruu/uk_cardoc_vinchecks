import SettingsLayout from '@/layouts/settings/settings-layout';
import {
    ArrowRight,
    CarFront,
    ChevronDown,
    CircleUserRound,
    CreditCard,
    Mail,
    MessageSquare,
    RefreshCw,
    Search,
    WalletCards,
} from 'lucide-react';
import { useMemo, useState } from 'react';

const topics = [
    {
        title: 'Account & Login',
        description:
            'Manage your profile, password resets, and security settings.',
        icon: CircleUserRound,
    },
    {
        title: 'Credits & Billing',
        description:
            'Top up credits, view purchase history, and manage invoices.',
        icon: WalletCards,
    },
    {
        title: 'Vehicle Reports',
        description:
            'Understanding report data, missing information, and downloads.',
        icon: CarFront,
    },
    {
        title: 'Payments & Refunds',
        description:
            'Information regarding failed payments, refunds, and billing issues.',
        icon: CreditCard,
    },
    {
        title: 'Subscriptions',
        description:
            'Manage your active plans, renewals, and subscription features.',
        icon: RefreshCw,
    },
];

const faqs = [
    {
        question: 'How do UKCarDoc credits work?',
        answer:
            'UKCarDoc credits are purchased in bundles or through a subscription plan. One credit equals one full vehicle check. Credits remain in your account until you use them, provided your account remains active.',
    },
    {
        question: 'What information is included in a vehicle report?',
        answer:
            'Vehicle reports may include vehicle identity, MOT information, tax status, mileage history, and other available vehicle data depending on the report type.',
    },
    {
        question: 'How long does a vehicle check take?',
        answer:
            'Most vehicle checks are processed shortly after submission. Processing time may vary if an external vehicle-data provider is delayed.',
    },
    {
        question: 'Is the data provided accurate?',
        answer:
            'UKCarDoc displays information received from its available vehicle-data sources. Availability and completeness can vary depending on the vehicle and provider.',
    },
    {
        question: 'Can I get a refund if the report is incomplete?',
        answer:
            'Refund eligibility depends on the circumstances of the failed or incomplete report. Contact support so the transaction and report can be reviewed.',
    },
    {
        question: 'How do I update my payment method?',
        answer:
            'Payment method management depends on the payment or subscription currently associated with your account.',
    },
    {
        question: 'Are there discounts for bulk checks?',
        answer:
            'Available credit bundles and subscription plans are shown on the pricing page.',
    },
    {
        question: 'How do I delete my account?',
        answer:
            'Open Settings, go to Security, and use the Delete Account option in the Danger Zone.',
    },
];

export default function HelpCentre() {
    const [search, setSearch] = useState('');
    const [openFaq, setOpenFaq] = useState<number | null>(0);
    const [showContactForm, setShowContactForm] = useState(false);

    const filteredTopics = useMemo(() => {
        const query = search.trim().toLowerCase();

        if (!query) {
            return topics;
        }

        return topics.filter((topic) =>
            `${topic.title} ${topic.description}`
                .toLowerCase()
                .includes(query),
        );
    }, [search]);

    const filteredFaqs = useMemo(() => {
        const query = search.trim().toLowerCase();

        if (!query) {
            return faqs;
        }

        return faqs.filter((faq) =>
            `${faq.question} ${faq.answer}`
                .toLowerCase()
                .includes(query),
        );
    }, [search]);

    return (
        <SettingsLayout>
            {/* Polos. Pattern dari desain sengaja tidak dipakai. */}
            <div className="min-h-screen bg-surface">
                <div className="mx-auto w-full max-w-container-max px-margin-mobile py-10 md:px-gutter md:py-12">

                    {/* Header */}
                    <header className="mb-10">
                        <h1 className="font-h1 text-h1 text-primary">
                            Help Centre
                        </h1>

                        <p className="mt-2 max-w-2xl font-body-lg text-body-lg text-on-surface-variant">
                            Find answers, manage common issues, or
                            contact our support team.
                        </p>

                        <div className="sovereign-line mt-6" />
                    </header>

                    {/* Search */}
                    <div className="relative mb-12 max-w-3xl">
                        <Search className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-outline" />

                        <input
                            type="search"
                            value={search}
                            onChange={(event) =>
                                setSearch(event.target.value)
                            }
                            placeholder="How can we help?"
                            className="block w-full rounded-xl border border-outline-variant bg-surface-container-lowest py-4 pl-12 pr-4 font-body-md text-body-md text-primary shadow-[0_4px_20px_rgba(0,13,47,0.04)] outline-none transition focus:border-primary focus:ring-1 focus:ring-primary"
                        />
                    </div>

                    {/* Browse by Topic */}
                    <section className="mb-16">
                        <h2 className="mb-6 font-h3 text-h3 text-primary">
                            Browse by Topic
                        </h2>

                        <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                            {filteredTopics.map((topic) => {
                                const Icon = topic.icon;

                                return (
                                    <article
                                        key={topic.title}
                                        className="group flex h-full flex-col rounded-xl border border-outline-variant bg-surface-container-lowest p-6 shadow-[0_4px_20px_rgba(0,13,47,0.04)] transition-all hover:border-primary-container hover:shadow-[0_8px_30px_rgba(0,13,47,0.08)]"
                                    >
                                        <div className="mb-4 flex h-10 w-10 items-center justify-center rounded-full border border-outline-variant bg-surface-container-low transition-colors group-hover:border-primary-container group-hover:bg-primary-container">
                                            <Icon className="h-5 w-5 text-primary transition-colors group-hover:text-on-primary" />
                                        </div>

                                        <h3 className="mb-2 font-h3 text-lg text-primary">
                                            {topic.title}
                                        </h3>

                                        <p className="flex-grow text-sm leading-6 text-on-surface-variant">
                                            {topic.description}
                                        </p>

                                        <button
                                            type="button"
                                            className="mt-4 flex items-center gap-1 self-start font-label-sm text-label-sm text-secondary hover:underline"
                                        >
                                            View articles
                                            <ArrowRight className="h-4 w-4" />
                                        </button>
                                    </article>
                                );
                            })}
                        </div>
                    </section>

                    {/* Popular Questions */}
                    <section className="mb-16">
                        <h2 className="mb-6 font-h3 text-h3 text-primary">
                            Popular Questions
                        </h2>

                        <div className="overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest shadow-[0_4px_20px_rgba(0,13,47,0.04)]">
                            {filteredFaqs.length > 0 ? (
                                filteredFaqs.map((faq) => {
                                    const faqIndex =
                                        faqs.indexOf(faq);

                                    const isOpen =
                                        openFaq === faqIndex;

                                    return (
                                        <div
                                            key={faq.question}
                                            className="border-b border-outline-variant last:border-b-0"
                                        >
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setOpenFaq(
                                                        isOpen
                                                            ? null
                                                            : faqIndex,
                                                    )
                                                }
                                                className="flex w-full items-center justify-between gap-4 p-4 text-left"
                                            >
                                                <span className="font-body-md font-semibold text-primary">
                                                    {
                                                        faq.question
                                                    }
                                                </span>

                                                <ChevronDown
                                                    className={`h-5 w-5 shrink-0 text-on-surface-variant transition-transform ${
                                                        isOpen
                                                            ? 'rotate-180'
                                                            : ''
                                                    }`}
                                                />
                                            </button>

                                            {isOpen && (
                                                <div className="px-4 pb-4 text-sm leading-6 text-on-surface-variant">
                                                    {faq.answer}
                                                </div>
                                            )}
                                        </div>
                                    );
                                })
                            ) : (
                                <div className="p-8 text-center text-on-surface-variant">
                                    No matching questions found.
                                </div>
                            )}
                        </div>
                    </section>

                    {/* Still Need Help */}
                    <section>
                        <h2 className="mb-6 font-h3 text-h3 text-primary">
                            Still Need Help?
                        </h2>

                        <div className="mb-8 grid grid-cols-1 gap-6 md:grid-cols-2">

                            {/* Live Chat */}
                            <div className="flex flex-col items-center rounded-xl border border-outline-variant bg-surface-container-lowest p-6 text-center shadow-[0_4px_20px_rgba(0,13,47,0.04)]">
                                <div className="relative mb-4">
                                    <div className="flex h-12 w-12 items-center justify-center rounded-full border border-outline-variant bg-surface-container-high text-primary">
                                        <MessageSquare className="h-5 w-5" />
                                    </div>

                                    <span className="absolute bottom-0 right-0 h-3 w-3 rounded-full border-2 border-white bg-green-500" />
                                </div>

                                <h3 className="mb-2 font-h3 text-lg text-primary">
                                    Live Chat
                                </h3>

                                <p className="mb-6 text-sm leading-6 text-on-surface-variant">
                                    Chat with our support team in
                                    real-time.
                                </p>

                                <button
                                    type="button"
                                    className="w-full rounded bg-secondary px-6 py-2.5 font-label-sm text-label-sm uppercase text-on-secondary transition hover:bg-secondary-container"
                                >
                                    Start Chat
                                </button>
                            </div>

                            {/* Contact Support */}
                            <div className="flex flex-col items-center rounded-xl border border-outline-variant bg-surface-container-lowest p-6 text-center shadow-[0_4px_20px_rgba(0,13,47,0.04)]">
                                <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full border border-outline-variant bg-surface-container-high text-primary">
                                    <Mail className="h-5 w-5" />
                                </div>

                                <h3 className="mb-2 font-h3 text-lg text-primary">
                                    Contact Support
                                </h3>

                                <p className="mb-6 text-sm leading-6 text-on-surface-variant">
                                    Send us a message and we'll get
                                    back to you within 24 hours.
                                </p>

                                <button
                                    type="button"
                                    onClick={() =>
                                        setShowContactForm(
                                            (current) =>
                                                !current,
                                        )
                                    }
                                    className="w-full rounded border border-outline-variant bg-surface px-6 py-2.5 font-label-sm text-label-sm uppercase text-primary transition hover:bg-surface-container-highest"
                                >
                                    {showContactForm
                                        ? 'Close Form'
                                        : 'Open Form'}
                                </button>
                            </div>
                        </div>

                        {/* Contact Form */}
                        {showContactForm && (
                            <div className="rounded-xl border border-outline-variant bg-surface-container-lowest p-6 shadow-[0_4px_20px_rgba(0,13,47,0.04)] md:p-8">
                                <h3 className="mb-6 font-h3 text-lg text-primary">
                                    Submit a Request
                                </h3>

                                <form
                                    onSubmit={(event) =>
                                        event.preventDefault()
                                    }
                                    className="space-y-6"
                                >
                                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                        <Field label="Category">
                                            <select className="w-full rounded border border-outline-variant bg-surface p-3 text-primary outline-none focus:border-primary focus:ring-1 focus:ring-primary">
                                                <option>
                                                    General Inquiry
                                                </option>
                                                <option>
                                                    Billing Issue
                                                </option>
                                                <option>
                                                    Technical Support
                                                </option>
                                            </select>
                                        </Field>

                                        <Field label="Related Report (Optional)">
                                            <select className="w-full rounded border border-outline-variant bg-surface p-3 text-primary outline-none focus:border-primary focus:ring-1 focus:ring-primary">
                                                <option>
                                                    Select a recent
                                                    report...
                                                </option>
                                            </select>
                                        </Field>
                                    </div>

                                    <Field label="Subject">
                                        <input
                                            type="text"
                                            placeholder="Brief description of your issue"
                                            className="w-full rounded border border-outline-variant bg-surface p-3 text-primary outline-none focus:border-primary focus:ring-1 focus:ring-primary"
                                        />
                                    </Field>

                                    <Field label="Message">
                                        <textarea
                                            placeholder="Please provide details..."
                                            className="h-32 w-full resize-none rounded border border-outline-variant bg-surface p-3 text-primary outline-none focus:border-primary focus:ring-1 focus:ring-primary"
                                        />
                                    </Field>

                                    <div className="flex justify-end pt-2">
                                        <button
                                            type="submit"
                                            className="rounded bg-secondary px-6 py-2.5 font-label-sm text-label-sm uppercase text-on-secondary transition hover:bg-secondary-container"
                                        >
                                            Send Message
                                        </button>
                                    </div>
                                </form>
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </SettingsLayout>
    );
}

function Field({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-2">
            <label className="block font-label-sm text-label-sm text-on-surface-variant">
                {label}
            </label>

            {children}
        </div>
    );
}