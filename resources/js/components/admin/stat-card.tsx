import type { ReactNode } from 'react';
import React from 'react';

interface StatCardProps {
    title: string;
    value: string | number;
    subtitle?: string;
    icon?: ReactNode;
    trend?: {
        label: string;
        isPositive?: boolean;
    };
    variant?: 'default' | 'primary' | 'accent';
}

export default function StatCard({
    title,
    value,
    subtitle,
    icon,
    trend,
    variant = 'default',
}: StatCardProps) {
    const isPrimary = variant === 'primary';
    const isAccent = variant === 'accent';

    const cardBg = isPrimary
        ? 'bg-primary text-white border-primary'
        : isAccent
          ? 'bg-secondary text-white border-secondary'
          : 'bg-white border-outline-variant text-on-surface';

    const subtitleColor =
        isPrimary || isAccent ? 'text-white/80' : 'text-on-surface-variant';

    return (
        <div
            className={`rounded-xl border p-6 shadow-sm transition-all ${cardBg}`}
        >
            <div className="flex items-center justify-between">
                <span
                    className={`text-xs font-semibold tracking-wider uppercase ${subtitleColor}`}
                >
                    {title}
                </span>
                {icon && <div className="text-xl opacity-80">{icon}</div>}
            </div>

            <div className="mt-3 flex items-baseline gap-2">
                <span className="font-sans text-2xl font-extrabold tracking-tight lg:text-3xl">
                    {value}
                </span>
            </div>

            {(subtitle || trend) && (
                <div className="mt-2 flex items-center gap-2 text-xs">
                    {trend && (
                        <span
                            className={`font-semibold ${
                                trend.isPositive
                                    ? 'text-emerald-500'
                                    : 'text-amber-500'
                            }`}
                        >
                            {trend.label}
                        </span>
                    )}
                    {subtitle && (
                        <span className={subtitleColor}>{subtitle}</span>
                    )}
                </div>
            )}
        </div>
    );
}
