import type { ReactNode } from 'react';
import React from 'react';

interface EmptyStateProps {
    title: string;
    description?: string;
    icon?: ReactNode;
    action?: ReactNode;
}

export default function EmptyState({
    title,
    description,
    icon,
    action,
}: EmptyStateProps) {
    return (
        <div className="my-4 flex flex-col items-center justify-center rounded-xl border border-dashed border-outline-variant bg-white p-12 text-center">
            {icon ? (
                <div className="mb-4 rounded-full bg-surface-container p-3 text-on-surface-variant">
                    {icon}
                </div>
            ) : (
                <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-surface-container-high text-lg font-semibold text-on-surface-variant">
                    Ø
                </div>
            )}
            <h3 className="font-sans text-base font-bold text-on-surface">
                {title}
            </h3>
            {description && (
                <p className="mt-1 max-w-sm text-xs text-on-surface-variant">
                    {description}
                </p>
            )}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}
