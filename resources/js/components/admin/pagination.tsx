import { Link } from '@inertiajs/react';
import React from 'react';

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginationProps {
    links: PaginationLink[];
    from?: number | null;
    to?: number | null;
    total?: number;
}

export default function Pagination({
    links,
    from,
    to,
    total,
}: PaginationProps) {
    if (!links || links.length <= 3) {
        return null;
    }

    return (
        <div className="flex flex-col items-center justify-between gap-4 px-2 py-4 sm:flex-row">
            <div className="text-xs text-on-surface-variant">
                {from && to && total ? (
                    <span>
                        Showing{' '}
                        <strong className="font-semibold text-on-surface">
                            {from}
                        </strong>{' '}
                        to{' '}
                        <strong className="font-semibold text-on-surface">
                            {to}
                        </strong>{' '}
                        of{' '}
                        <strong className="font-semibold text-on-surface">
                            {total}
                        </strong>{' '}
                        results
                    </span>
                ) : (
                    <span>Showing results</span>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-1">
                {links.map((link, index) => {
                    const isPrev = link.label.includes('Previous');
                    const isNext = link.label.includes('Next');
                    const cleanLabel = isPrev
                        ? '‹ Prev'
                        : isNext
                          ? 'Next ›'
                          : link.label;

                    if (!link.url) {
                        return (
                            <span
                                key={index}
                                className="cursor-not-allowed rounded-md border border-outline-variant/40 px-3 py-1.5 text-xs text-on-surface-variant/50 select-none"
                                dangerouslySetInnerHTML={{ __html: cleanLabel }}
                            />
                        );
                    }

                    return (
                        <Link
                            key={index}
                            href={link.url}
                            preserveScroll
                            preserveState
                            className={`rounded-md border px-3 py-1.5 text-xs font-medium transition-colors ${
                                link.active
                                    ? 'border-primary bg-primary text-white shadow-xs'
                                    : 'border-outline-variant bg-white text-on-surface hover:bg-surface-container'
                            }`}
                            dangerouslySetInnerHTML={{ __html: cleanLabel }}
                        />
                    );
                })}
            </div>
        </div>
    );
}
