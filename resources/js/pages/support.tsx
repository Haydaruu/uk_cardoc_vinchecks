import { Head } from '@inertiajs/react';
import FaqAccordion from '@/components/faq/faq-accordion';
import BaseLayout from '@/layouts/base-layout';

const faqItems = [
    {
        question: 'How accurate is the vehicle data?',
        answer: "Our data is pulled in real-time from the DVLA, HPI, and VOSA databases. While we strive for 100% accuracy, we recommend cross-referencing with the vehicle's logbook (V5C) for final verification.",
    },
    {
        question: 'Can I get a refund?',
        answer: 'Yes, if a report fails to generate or contains significant errors from our data providers, we offer a full refund within 14 days of purchase. Contact our support team with your report ID.',
    },
    {
        question: 'How do I download my report as a PDF?',
        answer: 'Once your report is generated, click the "Download PDF" button in the top right corner of the report dashboard. You can also access previous reports from the "My Reports" tab in your account.',
    },
];

function Support() {
    return (
        <>
            <Head title="Support" />

            {/* Hero */}
            <section className="relative overflow-hidden bg-primary-container px-6 py-section-padding text-white md:px-12">
                <div className="pointer-events-none absolute inset-0 opacity-10">
                    <div className="absolute -right-20 -top-20 size-96 rotate-45 border-[40px] border-white" />
                    <div className="absolute bottom-10 left-10 size-48 -rotate-12 border-[20px] border-secondary" />
                </div>

                <div className="relative z-10 mx-auto max-w-4xl text-center">
                    <h1 className="text-h1 mb-6 text-white">How can we help you today?</h1>
                    <div className="group relative mx-auto max-w-2xl">
                        <span className="material-symbols-outlined absolute left-5 top-1/2 -translate-y-1/2 text-slate-400 transition-colors group-focus-within:text-secondary">
                            search
                        </span>
                        <input
                            type="text"
                            placeholder="Search for reports, billing, or technical issues..."
                            className="dashboard-shadow text-body-lg w-full rounded-xl border-none bg-white py-5 pl-14 pr-6 text-on-surface shadow-xl transition-transform duration-300 placeholder:text-outline focus:ring-2 focus:ring-secondary"
                        />
                    </div>
                    <p className="text-body-md mt-6 text-on-primary-container opacity-80">
                        Popular: &quot;Refund Policy&quot;, &quot;Download PDF&quot;, &quot;Ulez Compliance&quot;
                    </p>
                </div>
            </section>

            {/* Browse by Topic — Bento Grid */}
            <section className="mx-auto max-w-container-max px-6 py-section-padding md:px-12">
                <div className="mb-12">
                    <div className="sovereign-line mb-4" />
                    <h2 className="text-h2 text-primary-container">Browse by Topic</h2>
                </div>

                <div className="grid grid-cols-1 gap-6 md:grid-cols-4">
                    {/* Large card — Reports & Data */}
                    <div className="dashboard-shadow dashboard-shadow-hover group relative flex cursor-pointer flex-col justify-between overflow-hidden rounded-xl border border-slate-200 bg-white p-8 transition-all md:col-span-2 md:row-span-2">
                        <div className="relative z-10">
                            <span className="material-symbols-outlined mb-6 block text-4xl text-secondary">
                                description
                            </span>
                            <h3 className="text-h3 mb-4 text-primary-container">Reports &amp; Data</h3>
                            <p className="text-body-md text-on-surface-variant">
                                Understand your vehicle history reports, mileage anomalies, and technical specifications provided by DVLA and HPI.
                            </p>
                            <ul className="mt-6 space-y-3">
                                {['Interpreting MOT history', 'VIN mismatch guide', 'Finance check details'].map((link) => (
                                    <li key={link} className="flex items-center text-sm font-medium text-primary-container hover:underline">
                                        <span className="material-symbols-outlined mr-2 text-xs">arrow_forward</span>
                                        {link}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>

                    {/* Medium — Payments & Credits */}
                    <div className="dashboard-shadow dashboard-shadow-hover group cursor-pointer rounded-xl border border-slate-200 bg-white p-6 transition-all">
                        <span className="material-symbols-outlined mb-4 block text-3xl text-primary-container">
                            payments
                        </span>
                        <h3 className="mb-2 text-xl font-semibold text-primary-container">Payments &amp; Credits</h3>
                        <p className="text-body-md text-sm text-on-surface-variant">
                            Managing invoices, refund requests, and credit bundles.
                        </p>
                    </div>

                    {/* Medium — Account Settings */}
                    <div className="dashboard-shadow dashboard-shadow-hover group cursor-pointer rounded-xl border border-slate-200 bg-white p-6 transition-all">
                        <span className="material-symbols-outlined mb-4 block text-3xl text-primary-container">
                            manage_accounts
                        </span>
                        <h3 className="mb-2 text-xl font-semibold text-primary-container">Account Settings</h3>
                        <p className="text-body-md text-sm text-on-surface-variant">
                            Update your email, password, and communication preferences.
                        </p>
                    </div>

                    {/* Wide accent — Technical Support */}
                    <div className="dashboard-shadow dashboard-shadow-hover group relative cursor-pointer overflow-hidden rounded-xl bg-secondary p-8 text-white transition-all md:col-span-2">
                        <div className="relative z-10">
                            <span className="material-symbols-outlined mb-4 block text-3xl">terminal</span>
                            <h3 className="text-h3 mb-2">Technical Support</h3>
                            <p className="text-body-md opacity-90">
                                Troubleshooting PDF generation, browser compatibility, and API integrations.
                            </p>
                        </div>
                        <div className="absolute right-0 top-0 h-full w-32 translate-x-16 skew-x-12 bg-white/10" />
                    </div>
                </div>
            </section>

            {/* FAQ */}
            <section className="bg-surface-container-low px-6 py-section-padding md:px-12">
                <div className="mx-auto max-w-3xl">
                    <div className="mb-16 text-center">
                        <h2 className="text-h2 mb-4">Frequently Asked Questions</h2>
                        <p className="text-body-md text-on-surface-variant">
                            Quick answers to the most common queries from our community.
                        </p>
                    </div>
                    <FaqAccordion items={faqItems} />
                </div>
            </section>

            {/* Contact */}
            <section className="mx-auto max-w-container-max px-6 py-section-padding md:px-12">
                <div className="grid grid-cols-1 gap-16 lg:grid-cols-2">
                    <div>
                        <h2 className="text-h2 mb-6">Still need help?</h2>
                        <p className="text-body-lg mb-12 text-on-surface-variant">
                            Our dedicated support team in London is ready to assist you with any complex inquiries or commercial partnership requests.
                        </p>

                        <div className="space-y-8">
                            <div className="flex gap-6">
                                <div className="flex size-12 items-center justify-center rounded-lg bg-surface-container-high text-primary-container">
                                    <span className="material-symbols-outlined">mail</span>
                                </div>
                                <div>
                                    <h4 className="font-bold text-primary-container">Email Support</h4>
                                    <p className="text-on-surface-variant">support@ukcardoc.co.uk</p>
                                    <p className="mt-1 text-xs font-bold uppercase tracking-tighter text-secondary">
                                        Response within 4 hours
                                    </p>
                                </div>
                            </div>

                            <div className="flex gap-6">
                                <div className="flex size-12 items-center justify-center rounded-lg bg-surface-container-high text-primary-container">
                                    <span className="material-symbols-outlined">schedule</span>
                                </div>
                                <div>
                                    <h4 className="font-bold text-primary-container">Business Hours</h4>
                                    <p className="text-on-surface-variant">Monday – Friday: 08:00 – 18:00</p>
                                    <p className="text-on-surface-variant">Saturday: 09:00 – 13:00</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="dashboard-shadow rounded-xl border border-slate-200 bg-white p-8">
                        <form className="space-y-6" onSubmit={(e) => e.preventDefault()}>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <label className="text-sm font-bold text-primary-container">Full Name</label>
                                    <input
                                        type="text"
                                        placeholder="John Smith"
                                        className="w-full rounded border-slate-200 text-sm focus:border-secondary focus:ring-0"
                                    />
                                </div>
                                <div className="space-y-2">
                                    <label className="text-sm font-bold text-primary-container">Email Address</label>
                                    <input
                                        type="email"
                                        placeholder="john@example.com"
                                        className="w-full rounded border-slate-200 text-sm focus:border-secondary focus:ring-0"
                                    />
                                </div>
                            </div>
                            <div className="space-y-2">
                                <label className="text-sm font-bold text-primary-container">Subject</label>
                                <select className="w-full rounded border-slate-200 text-sm focus:border-secondary focus:ring-0">
                                    <option>Data Discrepancy</option>
                                    <option>Billing Issue</option>
                                    <option>Business API Inquiry</option>
                                    <option>Other</option>
                                </select>
                            </div>
                            <div className="space-y-2">
                                <label className="text-sm font-bold text-primary-container">Message</label>
                                <textarea
                                    rows={4}
                                    placeholder="Tell us more about your issue..."
                                    className="w-full resize-none rounded border-slate-200 text-sm focus:border-secondary focus:ring-0"
                                />
                            </div>
                            <button
                                type="submit"
                                className="w-full rounded bg-secondary py-4 font-bold text-white transition-all hover:bg-[#930012] active:scale-[0.98]"
                            >
                                Send Message
                            </button>
                        </form>
                    </div>
                </div>
            </section>
        </>
    );
}

Support.layout = (page: React.ReactNode) => <BaseLayout>{page}</BaseLayout>;

export default Support;