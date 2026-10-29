import { router } from '@inertiajs/react';
import type { FormEvent} from 'react';
import React, { useState } from 'react';

interface FilterOption {
    label: string;
    value: string;
}

interface FilterSelect {
    name: string;
    label: string;
    options: FilterOption[];
    value: string;
}

interface SearchFilterBarProps {
    searchPlaceholder?: string;
    searchValue?: string;
    filters?: FilterSelect[];
    baseUrl: string;
}

export default function SearchFilterBar({
    searchPlaceholder = 'Search records...',
    searchValue = '',
    filters = [],
    baseUrl,
}: SearchFilterBarProps) {
    const [search, setSearch] = useState(searchValue);

    const handleSearch = (e: FormEvent) => {
        e.preventDefault();
        const params = new URLSearchParams(window.location.search);

        if (search.trim()) {
            params.set('search', search.trim());
        } else {
            params.delete('search');
        }

        params.set('page', '1');
        router.get(
            `${baseUrl}?${params.toString()}`,
            {},
            { preserveState: true, preserveScroll: true },
        );
    };

    const handleFilterChange = (name: string, value: string) => {
        const params = new URLSearchParams(window.location.search);

        if (value && value !== 'all') {
            params.set(name, value);
        } else {
            params.delete(name);
        }

        params.set('page', '1');
        router.get(
            `${baseUrl}?${params.toString()}`,
            {},
            { preserveState: true, preserveScroll: true },
        );
    };

    const handleReset = () => {
        setSearch('');
        router.get(baseUrl, {}, { preserveState: true, preserveScroll: true });
    };

    return (
        <div className="mb-6 flex flex-col justify-between gap-3 rounded-xl border border-outline-variant bg-white p-4 shadow-xs md:flex-row md:items-center">
            <form
                onSubmit={handleSearch}
                className="flex flex-1 items-center gap-2"
            >
                <div className="relative flex-1">
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={searchPlaceholder}
                        className="w-full rounded-lg border border-outline-variant bg-surface/50 py-2 pr-4 pl-9 text-xs text-on-surface focus:border-primary focus:ring-2 focus:ring-primary focus:outline-none"
                    />
                    <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-on-surface-variant">
                        🔍
                    </div>
                </div>
                <button
                    type="submit"
                    className="rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-primary/90"
                >
                    Search
                </button>
            </form>

            <div className="flex flex-wrap items-center gap-2">
                {filters.map((filter) => (
                    <div
                        key={filter.name}
                        className="flex items-center gap-1.5"
                    >
                        <label className="text-xs font-medium whitespace-nowrap text-on-surface-variant">
                            {filter.label}:
                        </label>
                        <select
                            value={filter.value}
                            onChange={(e) =>
                                handleFilterChange(filter.name, e.target.value)
                            }
                            className="rounded-lg border border-outline-variant bg-white px-2.5 py-1.5 text-xs text-on-surface focus:ring-2 focus:ring-primary focus:outline-none"
                        >
                            {filter.options.map((opt) => (
                                <option key={opt.value} value={opt.value}>
                                    {opt.label}
                                </option>
                            ))}
                        </select>
                    </div>
                ))}

                {(search ||
                    filters.some((f) => f.value && f.value !== 'all')) && (
                    <button
                        onClick={handleReset}
                        type="button"
                        className="px-2 py-1 text-xs font-semibold text-error hover:underline"
                    >
                        Reset
                    </button>
                )}
            </div>
        </div>
    );
}
