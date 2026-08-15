// pricing-card.tsx
import { Link } from '@inertiajs/react';
import { Check } from 'lucide-react';

type PricingCardProps = {
    tier: string;
    title: string;
    pricePerReport: string;
    originalPricePerReport?: string;
    totalPrice: string;
    originalTotalPrice?: string;
    discountLabel?: string;
    bullets: string[];
    ctaLabel: string;
    ctaHref: string;
    highlighted?: boolean;
    badge?: string;
};

export default function PricingCard({
    tier,
    title,
    pricePerReport,
    originalPricePerReport,
    totalPrice,
    originalTotalPrice,
    discountLabel,
    bullets,
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

                <div className="mt-4 flex items-baseline gap-2">
                    <span className="text-4xl font-black tracking-tight text-primary-container">
                        {pricePerReport}
                    </span>
                    <span className="text-sm text-slate-400">/ per report</span>
                </div>

                <div className="mt-2 flex items-center gap-2">
                    <span className="text-sm font-semibold text-slate-600">
                        Total price {totalPrice}
                    </span>
                    {originalTotalPrice && (
                        <span className="text-sm text-slate-300 line-through">
                            {originalTotalPrice}
                        </span>
                    )}
                </div>

                {discountLabel && (
                    <span className="mt-3 inline-block rounded-sm bg-secondary/10 px-2.5 py-1 text-[11px] font-bold text-secondary">
                        {discountLabel}
                    </span>
                )}
            </div>

            <ul className="mt-8 flex-1 space-y-3 border-t border-slate-100 pt-6">
                {bullets.map((bullet) => (
                    <li key={bullet} className="flex items-start gap-3">
                        <Check size={16} className="mt-0.5 shrink-0 text-secondary" />
                        <span className="text-sm text-slate-600">{bullet}</span>
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