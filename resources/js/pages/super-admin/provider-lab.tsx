import { Head } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import NormalizedReportPreview from '@/components/report/normalized-report-preview';
import type { NormalizedReport } from '@/types/normalized-report';

type ProviderId = 'checkcardetails' | 'oneauto';

type Provider = {
    id: ProviderId;
    name: string;
};

type CoverageStatus =
    | 'available'
    | 'partial'
    | 'derived'
    | 'unavailable'
    | 'not_run';

type CoverageRow = {
    key: string;
    label: string;
    getStatus: (
        report?: NormalizedReport,
    ) => CoverageStatus;
};

type Props = {
    providers: Provider[];
    csrfToken: string;
};

type LabResult = {
    provider: ProviderId;
    vrm: string;
    duration_ms: number;
    meta: Record<string, unknown>;
    normalized: NormalizedReport;
    raw: Record<string, unknown>;
};

type ActiveTab =
    | 'preview'
    | 'normalized'
    | 'raw';

const coverageRows: CoverageRow[] = [
    {
        key: 'salvage',
        label: 'Salvage Auction History & Photos',
        getStatus: (report) => {
            if (!report) {
                return 'not_run';
            }

            return report.salvage?.available
                ? 'available'
                : 'unavailable';
        },
    },

    {
        key: 'v5c',
        label: 'V5C / Logbook History',
        getStatus: (report) => {
            if (!report) {
                return 'not_run';
            }

            return report.v5c
                ? 'available'
                : 'unavailable';
        },
    },

    {
        key: 'v5c_serial',
        label: 'V5C Serial Verification',
        getStatus: (report) => {
            if (!report) {
                return 'not_run';
            }

            return report.v5c
                ?.verification_available
                ? 'available'
                : 'unavailable';
        },
    },

    {
        key: 'scrapped',
        label: 'Scrapped Status',
        getStatus: (report) => {
            if (!report) {
                return 'not_run';
            }

            return report.status
                ? 'available'
                : 'unavailable';
        },
    },

    {
        key: 'high_risk',
        label: 'High-Risk Record',
        getStatus: (report) => {
            if (!report) {
                return 'not_run';
            }

            return report.history
                ?.high_risk?.available
                ? 'available'
                : 'unavailable';
        },
    },

    {
        key: 'valuation',
        label: 'Market Valuation',
        getStatus: (report) => {
            if (!report) {
                return 'not_run';
            }

            return report.valuation?.retail != null
                ? 'available'
                : 'unavailable';
        },
    },

    {
        key: 'emissions',
        label: 'ULEZ / CAZ Compliance',
        getStatus: (report) => {
            if (!report) {
                return 'not_run';
            }

            if (
                report.emissions_compliance
                    ?.assessment_type === 'derived'
            ) {
                return 'derived';
            }

            return report.emissions_compliance
                ? 'available'
                : 'unavailable';
        },
    },

    {
        key: 'specifications',
        label: 'Vehicle Specifications',
        getStatus: (report) => {
            if (!report) {
                return 'not_run';
            }

            return report.specifications
                ? 'available'
                : 'unavailable';
        },
    },

    {
        key: 'recall',
        label: 'Recall Check',
        getStatus: (report) => {
            if (!report) {
                return 'not_run';
            }

            return report.recalls?.available
                ? 'available'
                : 'unavailable';
        },
    },

    {
        key: 'running_costs',
        label: 'Estimated Running Costs',
        getStatus: (report) => {
            if (!report) {
                return 'not_run';
            }

            const costs =
                report.running_costs;

            if (!costs) {
                return 'unavailable';
            }

            const roadTax =
                costs.road_tax?.available;

            const insurance =
                costs.insurance?.available;

            const fuel =
                costs.fuel?.available;

            if (
                roadTax &&
                insurance &&
                fuel
            ) {
                return 'available';
            }

            if (
                roadTax ||
                insurance ||
                fuel
            ) {
                return 'partial';
            }

            return 'unavailable';
        },
    },

    {
        key: 'plate_history',
        label: 'Plate Transfer History',
        getStatus: (report) => {
            if (!report) {
                return 'not_run';
            }

            return report.history
                ?.plate_changes?.available
                ? 'available'
                : 'unavailable';
        },
    },

    {
        key: 'mot',
        label: 'MOT History',
        getStatus: (report) => {
            if (!report) {
                return 'not_run';
            }

            return report.mot
                ?.service_available
                ? 'available'
                : 'unavailable';
        },
    },

    {
        key: 'mileage',
        label: 'Mileage History',
        getStatus: (report) => {
            if (!report) {
                return 'not_run';
            }

            return Array.isArray(
                report.mot?.mileage_history,
            )
                ? 'available'
                : 'unavailable';
        },
    },
];

export default function ProviderLab({
    providers,
    csrfToken,
}: Props) {
    const [provider, setProvider] =
        useState<ProviderId>('oneauto');

    const [vrm, setVrm] =
        useState('AB21ABC');

    const [results, setResults] =
        useState<
            Partial<
                Record<
                    ProviderId,
                    LabResult
                >
            >
        >({});

    const [activeTab, setActiveTab] =
        useState<ActiveTab>('preview');

    const [loading, setLoading] =
        useState(false);

    const [error, setError] =
        useState<string | null>(null);

    const result =
        results[provider] ?? null;

    async function runProvider(
        event: FormEvent<HTMLFormElement>,
    ) {
        event.preventDefault();

        setLoading(true);
        setError(null);

        try {
            const response = await fetch(
                '/admin/provider-lab/run',
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json',

                        Accept:
                            'application/json',

                        'X-CSRF-TOKEN':
                            csrfToken,
                    },

                    body: JSON.stringify({
                        provider,
                        vrm,
                    }),
                },
            );

            const data =
                await response.json();

            if (!response.ok) {
                throw new Error(
                    data.message ??
                        'Provider request failed.',
                );
            }

            setResults(
                (current) => ({
                    ...current,

                    [provider]:
                        data as LabResult,
                }),
            );

            setActiveTab('preview');
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Unknown error occurred.',
            );
        } finally {
            setLoading(false);
        }
    }

    return (
        <>
            <Head title="Provider Comparison Lab" />

            <main className="min-h-screen bg-slate-50 px-4 py-8 md:px-8">
                <div className="mx-auto max-w-6xl">
                    {/* Header */}
                    <div className="mb-8">
                        <p className="text-sm font-semibold uppercase tracking-wider text-slate-500">
                            Super Admin Tools
                        </p>

                        <h1 className="mt-2 text-3xl font-bold text-slate-900">
                            Provider Comparison Lab
                        </h1>

                        <p className="mt-2 max-w-2xl text-slate-600">
                            Compare normalized
                            vehicle data between
                            available providers
                            without affecting the
                            production report flow.
                        </p>
                    </div>

                    {/* Provider Runner */}
                    <form
                        onSubmit={runProvider}
                        className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"
                    >
                        <div>
                            <p className="mb-3 text-sm font-semibold text-slate-700">
                                Provider
                            </p>

                            <div className="flex flex-wrap gap-3">
                                {providers.map(
                                    (item) => (
                                        <button
                                            key={
                                                item.id
                                            }
                                            type="button"
                                            onClick={() => {
                                                setProvider(
                                                    item.id,
                                                );

                                                setError(
                                                    null,
                                                );
                                            }}
                                            className={`rounded-lg border px-4 py-2 text-sm font-semibold transition ${
                                                provider ===
                                                item.id
                                                    ? 'border-slate-900 bg-slate-900 text-white'
                                                    : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                            }`}
                                        >
                                            {
                                                item.name
                                            }
                                        </button>
                                    ),
                                )}
                            </div>
                        </div>

                        <div className="mt-6">
                            <label
                                htmlFor="vrm"
                                className="mb-2 block text-sm font-semibold text-slate-700"
                            >
                                Vehicle Registration
                            </label>

                            <div className="flex flex-col gap-3 sm:flex-row">
                                <input
                                    id="vrm"
                                    value={vrm}
                                    onChange={(
                                        event,
                                    ) =>
                                        setVrm(
                                            event.target.value.toUpperCase(),
                                        )
                                    }
                                    className="w-full max-w-xs rounded-lg border border-slate-300 px-4 py-2 uppercase outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
                                    placeholder="AB21ABC"
                                    autoComplete="off"
                                />

                                <button
                                    type="submit"
                                    disabled={
                                        loading ||
                                        vrm.trim() ===
                                            ''
                                    }
                                    className="rounded-lg bg-slate-900 px-5 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {loading
                                        ? 'Running...'
                                        : 'Run Provider'}
                                </button>
                            </div>

                            <p className="mt-3 text-xs text-slate-500">
                                Only the selected
                                provider will be
                                called.
                            </p>
                        </div>
                    </form>

                    {/* Error */}
                    {error && (
                        <div className="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-700">
                            {error}
                        </div>
                    )}

                    {/* Coverage Matrix */}
                    <section className="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                        <div className="border-b border-slate-200 p-6">
                            <h2 className="text-lg font-bold text-slate-900">
                                Coverage Matrix
                            </h2>

                            <p className="mt-1 text-sm text-slate-500">
                                Provider capability
                                based on the latest
                                manually-run result.
                            </p>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-50 text-slate-600">
                                    <tr>
                                        <th className="px-6 py-4 font-semibold">
                                            Requirement
                                        </th>

                                        <th className="px-6 py-4 font-semibold">
                                            CheckCarDetails
                                        </th>

                                        <th className="px-6 py-4 font-semibold">
                                            OneAuto
                                        </th>
                                    </tr>
                                </thead>

                                <tbody className="divide-y divide-slate-200">
                                    {coverageRows.map(
                                        (row) => (
                                            <tr
                                                key={
                                                    row.key
                                                }
                                                className="hover:bg-slate-50/60"
                                            >
                                                <td className="px-6 py-4 font-medium text-slate-900">
                                                    {
                                                        row.label
                                                    }
                                                </td>

                                                <td className="px-6 py-4">
                                                    <CoverageBadge
                                                        status={row.getStatus(
                                                            results
                                                                .checkcardetails
                                                                ?.normalized,
                                                        )}
                                                    />
                                                </td>

                                                <td className="px-6 py-4">
                                                    <CoverageBadge
                                                        status={row.getStatus(
                                                            results
                                                                .oneauto
                                                                ?.normalized,
                                                        )}
                                                    />
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    {/* Result */}
                    {result && (
                        <section className="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                            {/* Result Header */}
                            <div className="border-b border-slate-200 p-6">
                                <div className="flex flex-wrap items-center justify-between gap-4">
                                    <div>
                                        <p className="text-sm text-slate-500">
                                            Result
                                        </p>

                                        <h2 className="mt-1 text-xl font-bold text-slate-900">
                                            {
                                                result.vrm
                                            }
                                        </h2>
                                    </div>

                                    <div className="text-right text-sm text-slate-500">
                                        <p>
                                            Provider:{' '}
                                            <strong className="text-slate-900">
                                                {
                                                    result.provider
                                                }
                                            </strong>
                                        </p>

                                        <p className="mt-1">
                                            Duration:{result.duration_ms != null
                                                ? `${result.duration_ms} ms`
                                                : '—'}
                                            <strong className="text-slate-900">
                                                {
                                                    result.duration_ms
                                                }{' '}
                                                ms
                                            </strong>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            {/* Tabs */}
                            <div className="flex flex-wrap gap-2 border-b border-slate-200 p-4">
                                <TabButton
                                    active={
                                        activeTab ===
                                        'preview'
                                    }
                                    onClick={() =>
                                        setActiveTab(
                                            'preview',
                                        )
                                    }
                                >
                                    Report Preview
                                </TabButton>

                                <TabButton
                                    active={
                                        activeTab ===
                                        'normalized'
                                    }
                                    onClick={() =>
                                        setActiveTab(
                                            'normalized',
                                        )
                                    }
                                >
                                    Normalized
                                </TabButton>

                                <TabButton
                                    active={
                                        activeTab ===
                                        'raw'
                                    }
                                    onClick={() =>
                                        setActiveTab(
                                            'raw',
                                        )
                                    }
                                >
                                    Raw Response
                                </TabButton>
                            </div>

                            {/* Preview */}
                            {activeTab ===
                                'preview' && (
                                <div className="bg-slate-50 p-4 md:p-6">
                                    <NormalizedReportPreview
                                        report={
                                            result.normalized
                                        }
                                    />
                                </div>
                            )}

                            {/* Normalized JSON */}
                            {activeTab ===
                                'normalized' && (
                                <JsonViewer
                                    data={
                                        result.normalized
                                    }
                                />
                            )}

                            {/* Raw JSON */}
                            {activeTab ===
                                'raw' && (
                                <JsonViewer
                                    data={
                                        result.raw
                                    }
                                />
                            )}
                        </section>
                    )}
                </div>
            </main>
        </>
    );
}

function CoverageBadge({
    status,
}: {
    status: CoverageStatus;
}) {
    const styles: Record<
        CoverageStatus,
        string
    > = {
        available:
            'bg-emerald-100 text-emerald-700',

        partial:
            'bg-amber-100 text-amber-700',

        derived:
            'bg-blue-100 text-blue-700',

        unavailable:
            'bg-slate-200 text-slate-600',

        not_run:
            'bg-slate-100 text-slate-400',
    };

    const labels: Record<
        CoverageStatus,
        string
    > = {
        available: 'Available',
        partial: 'Partial',
        derived: 'Derived',
        unavailable: 'Unavailable',
        not_run: 'Not run',
    };

    return (
        <span
            className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${styles[status]}`}
        >
            {labels[status]}
        </span>
    );
}

function TabButton({
    active,
    onClick,
    children,
}: {
    active: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-lg px-4 py-2 text-sm font-semibold transition ${
                active
                    ? 'bg-slate-900 text-white'
                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
            }`}
        >
            {children}
        </button>
    );
}

function JsonViewer({
    data,
}: {
    data: unknown;
}) {
    return (
        <div className="overflow-auto bg-slate-950 p-6">
            <pre className="min-w-max text-xs leading-6 text-slate-200">
                {JSON.stringify(
                    data,
                    null,
                    2,
                )}
            </pre>
        </div>
    );
}