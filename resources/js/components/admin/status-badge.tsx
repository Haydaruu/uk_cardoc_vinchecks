import React from 'react';

type StatusType =
    | 'success'
    | 'paid'
    | 'active'
    | 'completed'
    | 'verified'
    | 'refunded'
    | 'failed'
    | 'expired'
    | 'suspended'
    | 'pending'
    | 'processing'
    | 'queued'
    | 'free'
    | 'basic'
    | 'premium'
    | string;

interface StatusBadgeProps {
    status: StatusType;
    label?: string;
}

export default function StatusBadge({ status, label }: StatusBadgeProps) {
    const normalized = (status || '').toLowerCase();
    const displayLabel = label || status || '-';

    let colorClasses =
        'bg-surface-container-high text-on-surface-variant border-outline-variant';

    if (
        ['success', 'paid', 'active', 'completed', 'verified'].includes(
            normalized,
        )
    ) {
        colorClasses =
            'bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800';
    } else if (
        ['refunded', 'failed', 'expired', 'suspended'].includes(normalized)
    ) {
        colorClasses =
            'bg-error-container/40 text-error border-error/30 dark:bg-error-container/20 dark:text-error';
    } else if (['pending', 'processing', 'queued'].includes(normalized)) {
        colorClasses =
            'bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800';
    } else if (['premium'].includes(normalized)) {
        colorClasses =
            'bg-primary-container text-white border-primary-container';
    } else if (['basic', 'free'].includes(normalized)) {
        colorClasses =
            'bg-surface-container-highest text-primary border-outline-variant';
    }

    return (
        <span
            className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold tracking-wider uppercase ${colorClasses}`}
        >
            {displayLabel}
        </span>
    );
}
