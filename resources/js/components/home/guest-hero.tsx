import { Search } from 'lucide-react';

export default function GuestHero() {
    return (
        <section className="relative min-h-[600px] flex items-center overflow-hidden bg-gradient-to-b from-white to-slate-50">
            <div className="relative z-10 mx-auto max-w-[1200px] px-6 py-24 md:px-10 w-full">
                <p className="text-[11px] font-bold uppercase tracking-[0.2em] text-secondary">
                    Official British Vehicle Data
                </p>
                <h1 className="mt-4 max-w-2xl text-5xl font-black leading-tight tracking-tight text-primary-container">
                    Check any UK vehicle history in seconds
                </h1>
                <p className="mt-6 max-w-xl text-base text-slate-500">
                    Get instant access to MOT records, salvage data, outstanding finance, and technical specifications sourced directly from DVLA, HPI, and VOSA.
                </p>

                <div className="mt-8 flex max-w-xl gap-3">
                    <div className="relative flex-1">
                        <Search className="pointer-events-none absolute left-4 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                        <input
                            type="text"
                            placeholder="Enter Registration (e.g. AB12 CDE) or VIN"
                            className="h-14 w-full rounded border border-slate-200 bg-white pl-11 pr-4 text-sm placeholder:text-slate-400 focus:border-primary-container focus:outline-none"
                        />
                    </div>
                    <button className="flex h-14 items-center gap-2 rounded bg-secondary px-6 text-sm font-bold uppercase tracking-widest text-white hover:bg-secondary-container">
                        Check Now →
                    </button>
                </div>

                <div className="mt-4 flex gap-6 text-xs text-slate-400">
                    <span className="flex items-center gap-1.5">✓ DVLA Real-time API</span>
                    <span className="flex items-center gap-1.5">✓ Encrypted Secure Reports</span>
                </div>
            </div>
        </section>
    );
}