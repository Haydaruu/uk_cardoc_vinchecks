import { Head, Link } from "@inertiajs/react";
import BaseLayout from "@/layouts/base-layout";
import {
    ShieldCheck, FileText, History,
    Search, ArrowRight, CheckCircle, AlertTriangle, BadgeCheck
} from "lucide-react";

function Home() {
    return (
        <>
            <Head title="UK Vehicle History Check" />

            {/* ── HERO ── */}
            <section className="relative min-h-[600px] flex items-center overflow-hidden bg-gradient-to-br from-slate-50 via-white to-slate-100">
                {/* subtle grid bg */}
                <div className="absolute inset-0 opacity-[0.03]"
                    style={{ backgroundImage: "linear-gradient(#000 1px,transparent 1px),linear-gradient(90deg,#000 1px,transparent 1px)", backgroundSize: "40px 40px" }} />

                <div className="relative max-w-[1200px] mx-auto px-6 md:px-10 py-24 w-full">
                    {/* eyebrow */}
                    <p className="text-[11px] font-bold uppercase tracking-[0.2em] text-secondary mb-4">
                        Official British Vehicle Data
                    </p>

                    {/* headline */}
                    <h1 className="text-[clamp(2.2rem,5vw,3.5rem)] font-black tracking-tight text-primary-container leading-[1.1] max-w-2xl mb-6">
                        Check any UK vehicle history in seconds
                    </h1>

                    <p className="text-slate-500 text-base max-w-xl mb-10 leading-relaxed">
                        Get instant access to MOT records, salvage data, outstanding finance, and
                        technical specifications sourced directly from DVLA, HPI, and VOSA.
                    </p>

                    {/* Search bar */}
                    <div className="flex flex-col sm:flex-row gap-0 max-w-xl shadow-lg shadow-slate-200/60">
                        <div className="relative flex-1">
                            <Search className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 w-4 h-4" />
                            <input
                                type="text"
                                placeholder="Enter Registration (e.g. AB12 CDE) or VIN"
                                className="w-full pl-11 pr-4 py-4 border border-slate-200 sm:border-r-0 sm:rounded-l-sm rounded-sm text-sm text-slate-700 placeholder:text-slate-300 focus:outline-none focus:border-secondary bg-white"
                            />
                        </div>
                        <button className="bg-secondary text-white font-bold uppercase tracking-wider text-sm px-8 py-4 sm:rounded-r-sm hover:bg-red-700 transition-colors whitespace-nowrap flex items-center gap-2">
                            CHECK NOW <ArrowRight size={15} />
                        </button>
                    </div>

                    {/* trust badges */}
                    <div className="flex flex-wrap gap-5 mt-6">
                        {["DVLA Real-time API", "Encrypted Secure Reports"].map(t => (
                            <span key={t} className="flex items-center gap-1.5 text-[12px] text-slate-400 font-medium">
                                <CheckCircle size={13} className="text-slate-400" /> {t}
                            </span>
                        ))}
                    </div>
                </div>
            </section>

            {/* ── DATA PARTNERS ── */}
            <section className="border-y border-slate-100 bg-white py-8">
                <div className="max-w-[1200px] mx-auto px-6 md:px-10">
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-8">
                        {[
                            { name: "DVLA",  sub: "Verified Source" },
                            { name: "HPI",   sub: "Data Partner" },
                            { name: "VOSA",  sub: "Safety Records" },
                            { name: "PNC",   sub: "Police National Computer" },
                        ].map(p => (
                            <div key={p.name} className="flex flex-col items-center text-center">
                                <span className="text-[18px] font-black tracking-tight text-slate-800">{p.name}</span>
                                <span className="text-[10px] uppercase tracking-widest text-slate-400 mt-0.5">{p.sub}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* ── FEATURES ── */}
            <section className="py-24 bg-white">
                <div className="max-w-[1200px] mx-auto px-6 md:px-10">
                    <div className="mb-14">
                        <div className="sovereign-line w-8 mb-6" />
                        <h2 className="text-[clamp(1.5rem,3vw,2rem)] font-black tracking-tight text-primary-container mb-3">
                            Precision data for informed decisions
                        </h2>
                        <div className="flex items-end justify-between gap-4 flex-wrap">
                            <p className="text-slate-500 text-sm max-w-md leading-relaxed">
                                We provide the most comprehensive vehicle intelligence in the UK,
                                helping you avoid costly mistakes and ensuring your safety on the road.
                            </p>
                            <a href="#" className="text-[11px] font-bold uppercase tracking-widest text-slate-500 hover:text-secondary transition-colors flex items-center gap-1.5 whitespace-nowrap">
                                EXPLORE FULL REPORT SCOPE <ArrowRight size={12} />
                            </a>
                        </div>
                    </div>

                    <div className="grid md:grid-cols-3 gap-6">
                        {[
                            {
                                icon: <History size={20} className="text-slate-600" />,
                                title: "Full History Check",
                                desc: "Deep-dive into the vehicle's past including previous owners, color changes, number plate transfers, and scrapped status.",
                                tags: ["OWNERSHIP", "TIMELINE"],
                            },
                            {
                                icon: <ShieldCheck size={20} className="text-slate-600" />,
                                title: "DVLA Verified Data",
                                desc: "Official real-time data from the DVLA. Confirm engine size, BHP, fuel type, and current tax/MOT status instantly.",
                                tags: ["OFFICIAL", "LIVE STATUS"],
                            },
                            {
                                icon: <FileText size={20} className="text-slate-600" />,
                                title: "Instant PDF Reports",
                                desc: "Generate professional, high-resolution PDF reports that you can share with buyers or keep for your records. Export ready.",
                                tags: ["EXPORT", "CLOUD ACCESS"],
                            },
                        ].map(card => (
                            <div key={card.title} className="border border-slate-100 rounded-sm p-7 hover:border-slate-200 hover:shadow-sm transition-all group">
                                <div className="mb-5">{card.icon}</div>
                                <h3 className="text-[15px] font-bold text-primary-container mb-2">{card.title}</h3>
                                <p className="text-slate-500 text-[13px] leading-relaxed mb-5">{card.desc}</p>
                                <div className="flex gap-2 flex-wrap">
                                    {card.tags.map(t => (
                                        <span key={t} className="text-[10px] font-bold uppercase tracking-widest border border-slate-200 text-slate-400 px-2 py-0.5 rounded-full">
                                            {t}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* ── DASHBOARD PREVIEW ── */}
            <section className="bg-primary-container py-20">
                <div className="max-w-[1200px] mx-auto px-6 md:px-10 grid md:grid-cols-2 gap-16 items-center">
                    {/* Left copy */}
                    <div>
                        <h2 className="text-[clamp(1.5rem,3vw,2rem)] font-black tracking-tight text-white mb-4 leading-tight">
                            Dashboard-grade clarity for every inspection
                        </h2>
                        <p className="text-slate-400 text-sm leading-relaxed mb-8">
                            The report is structured out of data. They are context-mapped and positioned
                            with data sets to summarise the information from the data — everything from insurance
                            write-offs to outstanding finance at a glance.
                        </p>
                        <div className="space-y-3">
                            <div className="flex items-start gap-3 bg-white/5 border border-white/10 rounded-sm px-4 py-3">
                                <CheckCircle size={15} className="text-green-400 mt-0.5 shrink-0" />
                                <div>
                                    <p className="text-[12px] font-bold uppercase tracking-widest text-green-400">Pass State</p>
                                    <p className="text-[12px] text-slate-400 mt-0.5">The vehicle meets all legal safety requirements.</p>
                                </div>
                            </div>
                            <div className="flex items-start gap-3 bg-white/5 border border-white/10 rounded-sm px-4 py-3">
                                <AlertTriangle size={15} className="text-amber-400 mt-0.5 shrink-0" />
                                <div>
                                    <p className="text-[12px] font-bold uppercase tracking-widest text-amber-400">Advisory State</p>
                                    <p className="text-[12px] text-slate-400 mt-0.5">Potential issues detected requiring attention.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Right — sample report card */}
                    <div className="bg-white rounded-sm shadow-xl p-6">
                        <div className="flex items-start justify-between mb-5">
                            <div>
                                <p className="text-xl font-black tracking-tight text-primary-container">LR19 GKN</p>
                                <p className="text-[11px] uppercase tracking-widest text-slate-400 mt-0.5">Volkswagen Golf GTI</p>
                            </div>
                            <span className="text-[10px] font-bold uppercase tracking-widest bg-green-50 text-green-600 border border-green-200 px-2.5 py-1 rounded-full">
                                VERIFIED
                            </span>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            {[
                                { label: "MOT Status",  value: "Valid until Oct 2025", color: "text-green-600" },
                                { label: "Mileage",     value: "34,102 Miles",          color: "text-slate-800" },
                                { label: "Write-Off",   value: "None Recorded",         color: "text-green-600" },
                                { label: "Finance",     value: "Outstanding",           color: "text-red-600" },
                            ].map(item => (
                                <div key={item.label} className="border-t border-slate-100 pt-3">
                                    <p className="text-[10px] uppercase tracking-widest text-slate-400 mb-1">{item.label}</p>
                                    <p className={`text-[13px] font-bold ${item.color}`}>{item.value}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </section>

            {/* ── CTA BOTTOM ── */}
            <section className="py-24 bg-white text-center">
                <div className="max-w-[600px] mx-auto px-6">
                    <p className="text-[11px] font-bold uppercase tracking-[0.2em] text-slate-400 mb-4">Ready to verify your next vehicle?</p>
                    <h2 className="text-[clamp(1.5rem,3vw,2rem)] font-black tracking-tight text-primary-container mb-3">
                        Don't leave it to chance.
                    </h2>
                    <p className="text-slate-500 text-sm mb-10">
                        Get the full picture today with UKcardoc's premium vehicle history platform.
                    </p>
                    <div className="flex flex-col sm:flex-row gap-3 justify-center">
                        <Link href="/register" className="bg-primary-container text-white font-bold uppercase tracking-widest text-[12px] px-8 py-3.5 rounded-sm hover:bg-primary transition-colors">
                            GET STARTED FREE
                        </Link>
                        <Link href="/sample" className="border border-slate-800 text-slate-800 font-bold uppercase tracking-widest text-[12px] px-8 py-3.5 rounded-sm hover:bg-slate-50 transition-colors">
                            VIEW SAMPLE REPORT
                        </Link>
                    </div>
                </div>
            </section>
        </>
    );
}

Home.layout = (page: React.ReactNode) => <BaseLayout>{page}</BaseLayout>;

export default Home;
