import { Head } from '@inertiajs/react';
import FaqAccordion from '@/components/faq/faq-accordion';
import BaseLayout from '@/layouts/base-layout';
import {
    ArrowRight,
    Clock,
    CreditCard,
    FileText,
    Mail,
    Search,
    Settings,
} from 'lucide-react';

const faqItems = [
    {
        question: 'How accurate is the vehicle data?',
        answer: 'Our data is sourced directly from official UK databases including DVLA, HPI, and VOSA. Reports are generated in real-time to ensure the most current information is available at the point of purchase.',
    },
    {
        question: 'Can I get a refund if the report is incorrect?',
        answer: 'If you believe your report contains inaccurate data, contact our support team within 14 days. We will investigate with our data partners and issue a full refund if an error is confirmed on our side.',
    },
    {
        question: 'How do I download my PDF report?',
        answer: 'After completing a vehicle check, navigate to My Reports and select your report. Click the "Download PDF Report" button to save a high-resolution copy to your device.',
    },
];

const topicCards = [
    {
        title: 'Reports & Data',
        description:
            'Understand your vehicle history report, interpret MOT records, and learn how to verify data accuracy.',
        icon: FileText,
        featured: true,
        links: [
            'Interpreting MOT history',
            'Understanding write-off categories',
            'Reading mileage records',
        ],
    },
    {
        title: 'Payments & Credits',
        description: 'Manage your account credits, view invoices, and understand our refund policy.',
        icon: CreditCard,
        featured: false,
    },
    {
        title: 'Account Settings',
        description: 'Update your profile, change your password, and manage notification preferences.',
        icon: Settings,
        featured: false,
    },
    {
        title: 'Technical Support',
        description: 'Experiencing issues? Our technical team is ready to help with platform problems.',
        icon: Mail,
        featured: false,
        accent: true,
    },
];

function Support() {
    return (
        <>
            <Head title="Support" />

            {/* Hero */}
            <section className="relative overflow-hidden bg-primary-container py-20">
                <div className="pointer-events-none absolute inset-0 opacity-10">
                    <div className="absolute right-20 top-10 size-40 rotate-12 border-2 border-secondary" />
                    <div className="absolute bottom-10 left-10 size-56 rotate-45 border-2 border-on-primary-container" />
                    <div className="absolute right-1/3 top-1/2 size-24 border-2 border-secondary" />
                </div>

                <div className="relative mx-auto max-w-[700px] px-6 text-center md:px-10">
                    <h1 className="text-[clamp(1.8rem,4vw,2.5rem)] font-black tracking-tight text-white">
                        How can we help you today?
                    </h1>
                    <div className="relative mx-auto mt-8 max-w-xl">
                        <Search
                            size={18}
                            className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"
                        />
                        <input
                            type="text"
                            placeholder="Search for reports, billing, or technical issues..."
                            className="w-full rounded-sm border-0 py-4 pl-11 pr-4 text-sm text-slate-700 placeholder:text-slate-300 focus:outline-none focus:ring-2 focus:ring-secondary"
                        />
                    </div>
                    <p className="mt-4 text-sm text-on-primary-container">
                        Popular:{' '}
                        <span className="text-white/80">
                            &apos;Refund Policy&apos;, &apos;Download PDF&apos;, &apos;Ulez Compliance&apos;
                        </span>
                    </p>
                </div>
            </section>

            {/* Browse by Topic */}
            <section className="bg-white py-20">
                <div className="mx-auto max-w-[1200px] px-6 md:px-10">
                    <h2 className="text-2xl font-black tracking-tight text-primary-container">
                        Browse by Topic
                    </h2>

                    <div className="mt-10 grid gap-6 md:grid-cols-2">
                        {topicCards.map((card) => {
                            const Icon = card.icon;

                            if (card.featured) {
                                return (
                                    <div
                                        key={card.title}
                                        className="row-span-2 rounded-sm border border-slate-200 bg-white p-8 md:col-span-1"
                                    >
                                        <div className="mb-5 flex size-10 items-center justify-center rounded-sm bg-secondary/10">
                                            <Icon size={20} className="text-secondary" />
                                        </div>
                                        <h3 className="text-lg font-bold text-primary-container">
                                            {card.title}
                                        </h3>
                                        <p className="mt-3 text-sm leading-relaxed text-slate-500">
                                            {card.description}
                                        </p>
                                        <ul className="mt-6 space-y-3">
                                            {card.links?.map((link) => (
                                                <li key={link}>
                                                    <a
                                                        href="#"
                                                        className="flex items-center gap-2 text-sm font-medium text-primary-container transition-colors hover:text-secondary"
                                                    >
                                                        <ArrowRight size={14} className="text-secondary" />
                                                        {link}
                                                    </a>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                );
                            }

                            if (card.accent) {
                                return (
                                    <div
                                        key={card.title}
                                        className="rounded-sm bg-secondary p-8 text-white transition-shadow hover:shadow-lg"
                                    >
                                        <div className="mb-5 flex size-10 items-center justify-center rounded-sm bg-white/20">
                                            <Icon size={20} />
                                        </div>
                                        <h3 className="text-lg font-bold">{card.title}</h3>
                                        <p className="mt-3 text-sm leading-relaxed text-white/80">
                                            {card.description}
                                        </p>
                                    </div>
                                );
                            }

                            return (
                                <div
                                    key={card.title}
                                    className="rounded-sm border border-slate-200 bg-white p-8 transition-shadow hover:shadow-sm"
                                >
                                    <div className="mb-5 flex size-10 items-center justify-center rounded-sm bg-surface-container">
                                        <Icon size={20} className="text-primary-container" />
                                    </div>
                                    <h3 className="text-lg font-bold text-primary-container">
                                        {card.title}
                                    </h3>
                                    <p className="mt-3 text-sm leading-relaxed text-slate-500">
                                        {card.description}
                                    </p>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </section>

            {/* FAQ */}
            <section className="bg-surface-container py-20">
                <div className="mx-auto max-w-[800px] px-6 md:px-10">
                    <div className="text-center">
                        <h2 className="text-2xl font-black tracking-tight text-primary-container">
                            Frequently Asked Questions
                        </h2>
                        <p className="mt-3 text-sm text-slate-500">
                            Quick answers to the most common queries from our community.
                        </p>
                    </div>
                    <div className="mt-10">
                        <FaqAccordion items={faqItems} />
                    </div>
                </div>
            </section>

            {/* Contact */}
            <section className="bg-white py-20">
                <div className="mx-auto grid max-w-[1200px] gap-12 px-6 md:grid-cols-2 md:px-10">
                    <div>
                        <h2 className="text-2xl font-black tracking-tight text-primary-container">
                            Still need help?
                        </h2>
                        <p className="mt-4 text-sm leading-relaxed text-slate-500">
                            Our London-based support team is available to assist you with any
                            questions about your reports, billing, or account.
                        </p>

                        <div className="mt-8 space-y-6">
                            <div className="flex gap-4">
                                <div className="flex size-10 shrink-0 items-center justify-center rounded-sm bg-surface-container">
                                    <Mail size={18} className="text-primary-container" />
                                </div>
                                <div>
                                    <p className="text-sm font-semibold text-primary-container">
                                        Email Support
                                    </p>
                                    <a
                                        href="mailto:support@ukcardoc.com"
                                        className="text-sm text-slate-500 hover:text-secondary"
                                    >
                                        support@ukcardoc.com
                                    </a>
                                    <p className="mt-1 text-[10px] font-bold uppercase tracking-widest text-secondary">
                                        Response within 4 hours
                                    </p>
                                </div>
                            </div>

                            <div className="flex gap-4">
                                <div className="flex size-10 shrink-0 items-center justify-center rounded-sm bg-surface-container">
                                    <Clock size={18} className="text-primary-container" />
                                </div>
                                <div>
                                    <p className="text-sm font-semibold text-primary-container">
                                        Business Hours
                                    </p>
                                    <p className="text-sm text-slate-500">
                                        Monday – Friday: 9:00 AM – 6:00 PM GMT
                                    </p>
                                    <p className="text-sm text-slate-500">
                                        Saturday: 10:00 AM – 2:00 PM GMT
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <form
                        className="rounded-sm border border-slate-200 bg-white p-8 shadow-sm"
                        onSubmit={(e) => e.preventDefault()}
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className="mb-1.5 block text-[10px] font-bold uppercase tracking-widest text-slate-400">
                                    Full Name
                                </label>
                                <input
                                    type="text"
                                    className="w-full rounded-sm border border-slate-200 px-4 py-3 text-sm focus:border-secondary focus:outline-none"
                                    placeholder="John Smith"
                                />
                            </div>
                            <div>
                                <label className="mb-1.5 block text-[10px] font-bold uppercase tracking-widest text-slate-400">
                                    Email Address
                                </label>
                                <input
                                    type="email"
                                    className="w-full rounded-sm border border-slate-200 px-4 py-3 text-sm focus:border-secondary focus:outline-none"
                                    placeholder="john@example.com"
                                />
                            </div>
                        </div>
                        <div className="mt-4">
                            <label className="mb-1.5 block text-[10px] font-bold uppercase tracking-widest text-slate-400">
                                Subject
                            </label>
                            <select className="w-full rounded-sm border border-slate-200 px-4 py-3 text-sm focus:border-secondary focus:outline-none">
                                <option>Report Issue</option>
                                <option>Billing Question</option>
                                <option>Technical Problem</option>
                                <option>General Enquiry</option>
                            </select>
                        </div>
                        <div className="mt-4">
                            <label className="mb-1.5 block text-[10px] font-bold uppercase tracking-widest text-slate-400">
                                Message
                            </label>
                            <textarea
                                rows={5}
                                className="w-full resize-none rounded-sm border border-slate-200 px-4 py-3 text-sm focus:border-secondary focus:outline-none"
                                placeholder="Describe your issue..."
                            />
                        </div>
                        <button
                            type="submit"
                            className="mt-6 w-full rounded-sm bg-secondary py-3.5 text-[11px] font-bold uppercase tracking-widest text-white transition-colors hover:bg-secondary-container"
                        >
                            Send Message
                        </button>
                    </form>
                </div>
            </section>
        </>
    );
}

Support.layout = (page: React.ReactNode) => <BaseLayout>{page}</BaseLayout>;

export default Support;
