import { Globe, Mail } from 'lucide-react';
import { Link } from '@inertiajs/react';

const footerLinks = [
    [
        { label: 'Terms of Service', href: '#' },
        { label: 'Contact Support', href: '/support' },
        { label: 'Refund Policy', href: '#' },
    ],
    [
        { label: 'Privacy Policy', href: '#' },
        { label: 'About Us', href: '#' },
        { label: 'DVLA Status', href: '#' },
    ],
] as const;

export default function SiteFooter() {
    return (
        <footer className="border-t border-slate-200 bg-slate-50">
            <div className="mx-auto max-w-[1200px] px-6 py-12 md:px-10">
                <div className="grid gap-10 md:grid-cols-[1.4fr_1fr_1fr_auto] md:items-start">
                    <div>
                        <p className="font-sans text-lg font-black tracking-tight text-primary-container">
                            UKCARDOC
                        </p>
                        <p className="mt-4 max-w-sm text-[10px] font-medium uppercase leading-relaxed tracking-wider text-slate-400">
                            © 2026 UKCARDOC. Vehicle data sourced from DVLA, HPI, and VOSA.
                            Built with British precision.
                        </p>
                    </div>

                    {footerLinks.map((column, index) => (
                        <ul key={index} className="space-y-3">
                            {column.map((link) => (
                                <li key={link.label}>
                                    <Link
                                        href={link.href}
                                        className="text-[10px] font-medium uppercase tracking-wider text-slate-400 transition-colors hover:text-primary-container"
                                    >
                                        {link.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    ))}

                    <div className="flex gap-2">
                        <button
                            type="button"
                            className="flex size-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 transition-colors hover:border-slate-300 hover:text-primary-container"
                            aria-label="Language"
                        >
                            <Globe size={16} />
                        </button>
                        <a
                            href="mailto:support@ukcardoc.com"
                            className="flex size-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 transition-colors hover:border-slate-300 hover:text-primary-container"
                            aria-label="Email support"
                        >
                            <Mail size={16} />
                        </a>
                    </div>
                </div>
            </div>
        </footer>
    );
}
