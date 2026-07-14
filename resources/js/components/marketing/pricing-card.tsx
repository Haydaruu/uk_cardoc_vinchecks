import { Link } from '@inertiajs/react';
import { Check, X } from 'lucide-react';

type PricingFeature = {
    label: string;
    included: boolean;
};

type PricingCardProps = {
    tier: string;
    title: string;
    price: string;
    period?: string;
    features: PricingFeature[];
    ctaLabel: string;
    ctaHref: string;
    highlighted?: boolean;
    badge?: string;
};

export default function PricingCard({
    tier,
    title,
    price,
    period = '/per report',
    features,
    ctaLabel,
    ctaHref,
    highlighted = false,
    badge,
}: PricingCardProps) {
    return (
        <div
            className={`relative flex flex-col rounded-sm border bg-white p-8 transition-shadow hover:shadow-md ${
                highlighted
                    ? 'border-secondary shadow-lg shadow-secondary/10'
                    : 'border-slate-200'
            }`}
        >
            {badge && (
                <div className="absolute -top-px left-0 right-0 rounded-t-sm bg-secondary py-2 text-center text-[10px] font-bold uppercase tracking-widest text-white">
                    {badge}
                </div>
            )}

            <div className={badge ? 'mt-6' : ''}>
                <p
                    className={`text-[10px] font-bold uppercase tracking-widest ${
                        highlighted ? 'text-secondary' : 'text-slate-400'
                    }`}
                >
                    {tier}
                </p>
                <h3 className="mt-2 text-2xl font-black tracking-tight text-primary-container">
                    {title}
                </h3>
                <div className="mt-4 flex items-baseline gap-1">
                    <span className="text-4xl font-black tracking-tight text-primary-container">
                        {price}
                    </span>
                    <span className="text-sm text-slate-400">{period}</span>
                </div>
            </div>

            <ul className="mt-8 flex-1 space-y-3">
                {features.map((feature) => (
                    <li key={feature.label} className="flex items-start gap-3">
                        {feature.included ? (
                            <Check size={16} className="mt-0.5 shrink-0 text-secondary" />
                        ) : (
                            <X size={16} className="mt-0.5 shrink-0 text-slate-300" />
                        )}
                        <span
                            className={`text-sm ${
                                feature.included ? 'text-slate-600' : 'text-slate-300'
                            }`}
                        >
                            {feature.label}
                        </span>
                    </li>
                ))}
            </ul>

            <Link
                href={ctaHref}
                className={`mt-8 block rounded-sm py-3.5 text-center text-[11px] font-bold uppercase tracking-widest transition-colors ${
                    highlighted
                        ? 'bg-secondary text-white hover:bg-secondary-container'
                        : 'border border-slate-800 text-slate-800 hover:bg-slate-50'
                }`}
            >
                {ctaLabel}
            </Link>
        </div>
    );
}
