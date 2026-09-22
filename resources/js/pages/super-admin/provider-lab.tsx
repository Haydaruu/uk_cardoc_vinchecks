import { Head } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';

import NormalizedReportPreview from '@/components/report/normalized-report-preview';
import type {
    NormalizedReport,
    SourceProfile,
} from '@/types/normalized-report';

type Provider = {
    id: string;
    name: string;
};

type HybridMode = 'live' | 'fixture';

type CoverageStatus =
    | 'available'
    | 'partial'
    | 'derived'
    | 'unavailable'
    | 'not_run';

type IntegrationStatus =
    | 'integrated'
    | 'partial'
    | 'planned'
    | 'not_integrated';

type SandboxStatus =
    | 'available'
    | 'limited'
    | 'unavailable'
    | 'unknown';

type EvaluationStatus =
    | 'testing'
    | 'candidate'
    | 'approved'
    | 'rejected';

type ProvenanceStatus =
    | 'verified'
    | 'partial'
    | 'needs_review'
    | 'unknown';

type ProviderEvaluation = {
    name: string;
    role: string;
    integration_status: IntegrationStatus;
    sandbox_status: SandboxStatus;
    evaluation_status: EvaluationStatus;
    estimated_cost_per_check_gbp: number | null;
    provenance_status: ProvenanceStatus;
    notes?: string | null;
};

type CoverageRow = {
    key: string;
    label: string;

    getStatus: (
        report?: NormalizedReport,
    ) => CoverageStatus;
};

type Props = {
    providers: Provider[];

    providerEvaluation: Record<
        string,
        ProviderEvaluation
    >;

    csrfToken: string;
};

type LabResult = {
    provider: string;
    vrm: string;
    duration_ms: number;

    meta: Record<string, unknown>;

    normalized: NormalizedReport;

    raw: Record<string, unknown>;
};

type ActiveTab =
    | 'preview'
    | 'normalized'
    | 'sources'
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

            const valuation =
                report.valuation;

            const hasAnyValue =
                valuation?.retail != null ||
                valuation?.trade_in != null ||
                valuation?.private != null ||
                valuation?.trade != null;

            return hasAnyValue
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

            const emissions =
                report.emissions_compliance;

            if (!emissions) {
                return 'unavailable';
            }

            if (
                emissions.assessment_type ===
                'derived'
            ) {
                return 'derived';
            }

            return 'available';
        },
    },

    {
        key: 'specifications',
        label: 'Vehicle Specifications',

        getStatus: (report) => {
            if (!report) {
                return 'not_run';
            }

            const specs =
                report.specifications;

            if (!specs) {
                return 'unavailable';
            }

            const hasAnySpecs =
                specs.power_bhp != null ||
                specs.power_ps != null ||
                specs.power_kw != null ||
                specs.torque_nm != null ||
                specs.euro_standard != null ||
                specs.combined_mpg != null;

            return hasAnySpecs
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

            if (
                !report.mot
                    ?.service_available
            ) {
                return 'unavailable';
            }

            return Array.isArray(
                report.mot
                    .mileage_history,
            )
                ? 'available'
                : 'unavailable';
        },
    },
];

export default function ProviderLab({
    providers,
    providerEvaluation,
    csrfToken,
}: Props) {
    /*
     * Selected runner.
     *
     * String sengaja dipakai agar nanti provider
     * baru dari registry backend tidak memerlukan
     * perubahan union type di frontend.
     */
    const [provider, setProvider] =
        useState('oneauto');

    const [hybridMode, setHybridMode] =
        useState<HybridMode>('fixture');

    /*
     * Single provider atau Hybrid Live.
     */
    const [vrm, setVrm] =
        useState('AB21ABC');

    /*
     * Sandbox fixture inputs.
     */
    const [
        checkCarDetailsVrm,
        setCheckCarDetailsVrm,
    ] = useState('EA65AMX');

    const [
        oneAutoVrm,
        setOneAutoVrm,
    ] = useState('AB21ABC');

    /*
     * Dynamic result storage.
     *
     * Contoh:
     * results.checkcardetails
     * results.oneauto
     * results.ukvehicledata
     */
    const [results, setResults] =
        useState<
            Record<
                string,
                LabResult
            >
        >({});

    const [
        activeTab,
        setActiveTab,
    ] =
        useState<ActiveTab>(
            'preview',
        );

    const [loading, setLoading] =
        useState(false);

    const [error, setError] =
        useState<
            string | null
        >(null);

    /*
     * Hybrid bukan real API provider,
     * jadi jangan dimasukkan ke comparison columns.
     */
    const comparisonProviders =
        providers.filter(
            (item) =>
                item.id !==
                'hybrid',
        );

    const result =
        results[provider] ??
        null;

    const isHybrid =
        provider === 'hybrid';

    const isHybridFixture =
        isHybrid &&
        hybridMode ===
            'fixture';

    const submitDisabled =
        loading ||
        (
            isHybridFixture
                ? checkCarDetailsVrm
                      .trim() ===
                      '' ||
                  oneAutoVrm
                      .trim() ===
                      ''
                : vrm.trim() ===
                  ''
        );

    async function runProvider(
        event: FormEvent<HTMLFormElement>,
    ) {
        event.preventDefault();

        setLoading(true);
        setError(null);
        setResults((current) => {
            const next = {
                ...current,
            };

            delete next[provider];

            return next;
        });

        try {
            const payload =
                isHybridFixture
                    ? {
                          provider,
                          mode: 'fixture',

                          checkcardetails_vrm:
                              checkCarDetailsVrm,

                          oneauto_vrm:
                              oneAutoVrm,
                      }
                    : {
                          provider,
                          mode: 'live',
                          vrm,
                      };

            const response =
                await fetch(
                    '/admin/provider-lab/run',
                    {
                        method:
                            'POST',

                        headers: {
                            'Content-Type':
                                'application/json',

                            Accept:
                                'application/json',

                            'X-CSRF-TOKEN':
                                csrfToken,
                        },

                        body:
                            JSON.stringify(
                                payload,
                            ),
                    },
                );

            const data =
                await response.json();

            if (
                !response.ok
            ) {
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

            setActiveTab(
                'preview',
            );
        } catch (err) {
            setError(
                err instanceof
                    Error
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
                <div className="mx-auto max-w-7xl">
                    {/* Header */}
                    <div className="mb-8">
                        <p className="text-sm font-semibold uppercase tracking-wider text-slate-500">
                            Super Admin Tools
                        </p>

                        <h1 className="mt-2 text-3xl font-bold text-slate-900">
                            Provider Comparison Lab
                        </h1>

                        <p className="mt-2 max-w-3xl text-slate-600">
                            Compare vehicle data
                            providers, inspect
                            normalized responses
                            and experiment with
                            composite report
                            strategies without
                            affecting production.
                        </p>
                    </div>

                    {/* Runner */}
                    <form
                        onSubmit={
                            runProvider
                        }
                        className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"
                    >
                        <div>
                            <p className="mb-3 text-sm font-semibold text-slate-700">
                                Provider / Strategy
                            </p>

                            <div className="flex flex-wrap gap-3">
                                {providers.map(
                                    (
                                        item,
                                    ) => {
                                        const evaluation =
                                            providerEvaluation[
                                                item.id
                                            ];

                                        const isIntegrated =
                                            item.id ===
                                                'hybrid' ||
                                            evaluation
                                                ?.integration_status ===
                                                'integrated';

                                        const isSelected =
                                            provider ===
                                            item.id;

                                        return (
                                            <button
                                                key={
                                                    item.id
                                                }
                                                type="button"
                                                disabled={
                                                    !isIntegrated
                                                }
                                                onClick={() => {
                                                    if (
                                                        !isIntegrated
                                                    ) {
                                                        return;
                                                    }

                                                    setProvider(
                                                        item.id,
                                                    );

                                                    setError(
                                                        null,
                                                    );

                                                    setActiveTab(
                                                        'preview',
                                                    );
                                                }}
                                                className={`rounded-lg border px-4 py-2 text-sm font-semibold transition ${
                                                    isSelected
                                                        ? 'border-slate-900 bg-slate-900 text-white'
                                                        : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                                } ${
                                                    !isIntegrated
                                                        ? 'cursor-not-allowed opacity-40'
                                                        : ''
                                                }`}
                                            >
                                                <span className="flex items-center gap-2">
                                                    {
                                                        item.name
                                                    }

                                                    {item.id ===
                                                        'hybrid' && (
                                                        <span
                                                            className={`rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ${
                                                                isSelected
                                                                    ? 'bg-white/15 text-white'
                                                                    : 'bg-amber-100 text-amber-700'
                                                            }`}
                                                        >
                                                            Experimental
                                                        </span>
                                                    )}

                                                    {!isIntegrated &&
                                                        item.id !==
                                                            'hybrid' && (
                                                            <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500">
                                                                Planned
                                                            </span>
                                                        )}
                                                </span>
                                            </button>
                                        );
                                    },
                                )}
                            </div>
                        </div>

                        {/* Hybrid Mode */}
                        {isHybrid && (
                            <div className="mt-6 border-t border-slate-200 pt-6">
                                <p className="mb-3 text-sm font-semibold text-slate-700">
                                    Hybrid Test Mode
                                </p>

                                <div className="flex flex-wrap gap-3">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setHybridMode(
                                                'live',
                                            );

                                            setError(
                                                null,
                                            );
                                        }}
                                        className={`rounded-lg border px-4 py-2 text-sm font-semibold transition ${
                                            hybridMode ===
                                            'live'
                                                ? 'border-slate-900 bg-slate-900 text-white'
                                                : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                        }`}
                                    >
                                        Same Vehicle
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => {
                                            setHybridMode(
                                                'fixture',
                                            );

                                            setError(
                                                null,
                                            );
                                        }}
                                        className={`rounded-lg border px-4 py-2 text-sm font-semibold transition ${
                                            hybridMode ===
                                            'fixture'
                                                ? 'border-amber-600 bg-amber-600 text-white'
                                                : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                        }`}
                                    >
                                        Sandbox Fixture
                                    </button>
                                </div>

                                <p className="mt-3 max-w-3xl text-xs leading-5 text-slate-500">
                                    Same Vehicle sends
                                    one registration to
                                    every configured
                                    provider and enforces
                                    vehicle identity
                                    checks. Sandbox
                                    Fixture allows each
                                    provider to use its
                                    own test registration
                                    only for development
                                    and UI testing.
                                </p>
                            </div>
                        )}

                        {/* Fixture Inputs */}
                        {isHybridFixture ? (
                            <div className="mt-6 grid gap-4 md:grid-cols-2">
                                <div>
                                    <label
                                        htmlFor="checkcardetails-vrm"
                                        className="mb-2 block text-sm font-semibold text-slate-700"
                                    >
                                        CheckCarDetails
                                        Test VRM
                                    </label>

                                    <input
                                        id="checkcardetails-vrm"
                                        value={
                                            checkCarDetailsVrm
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setCheckCarDetailsVrm(
                                                event.target.value.toUpperCase(),
                                            )
                                        }
                                        className="w-full rounded-lg border border-slate-300 px-4 py-2 uppercase outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
                                        placeholder="EA65AMX"
                                        autoComplete="off"
                                    />

                                    <p className="mt-2 text-xs text-slate-500">
                                        Used only for
                                        CheckCarDetails
                                        sandbox testing.
                                    </p>
                                </div>

                                <div>
                                    <label
                                        htmlFor="oneauto-vrm"
                                        className="mb-2 block text-sm font-semibold text-slate-700"
                                    >
                                        OneAuto Test VRM
                                    </label>

                                    <input
                                        id="oneauto-vrm"
                                        value={
                                            oneAutoVrm
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setOneAutoVrm(
                                                event.target.value.toUpperCase(),
                                            )
                                        }
                                        className="w-full rounded-lg border border-slate-300 px-4 py-2 uppercase outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
                                        placeholder="AB21ABC"
                                        autoComplete="off"
                                    />

                                    <p className="mt-2 text-xs text-slate-500">
                                        Used only for
                                        OneAuto sandbox
                                        testing.
                                    </p>
                                </div>
                            </div>
                        ) : (
                            <div className="mt-6">
                                <label
                                    htmlFor="vrm"
                                    className="mb-2 block text-sm font-semibold text-slate-700"
                                >
                                    Vehicle Registration
                                </label>

                                <input
                                    id="vrm"
                                    value={
                                        vrm
                                    }
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

                                <p className="mt-2 text-xs text-slate-500">
                                    {isHybrid
                                        ? 'The same registration will be sent to every configured provider and identity checks will be enforced.'
                                        : 'Only the selected provider will be called.'}
                                </p>
                            </div>
                        )}

                        <div className="mt-6">
                            <button
                                type="submit"
                                disabled={
                                    submitDisabled
                                }
                                className="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {loading
                                    ? 'Running...'
                                    : isHybridFixture
                                      ? 'Build Hybrid Fixture'
                                      : isHybrid
                                        ? 'Run Hybrid Report'
                                        : 'Run Provider'}
                            </button>
                        </div>
                    </form>

                    {/* Error */}
                    {error && (
                        <div className="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-700">
                            {error}
                        </div>
                    )}

                    {/* Dynamic Coverage Matrix */}
                    <section className="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                        <div className="border-b border-slate-200 p-6">
                            <h2 className="text-lg font-bold text-slate-900">
                                Provider Coverage Matrix
                            </h2>

                            <p className="mt-1 max-w-3xl text-sm text-slate-500">
                                Coverage is calculated
                                from the latest result
                                manually executed for
                                each real provider.
                                Hybrid is excluded
                                because it is a report
                                composition strategy,
                                not a source API.
                            </p>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-50 text-slate-600">
                                    <tr>
                                        <th className="whitespace-nowrap px-6 py-4 font-semibold">
                                            Requirement
                                        </th>

                                        {comparisonProviders.map(
                                            (
                                                item,
                                            ) => (
                                                <th
                                                    key={
                                                        item.id
                                                    }
                                                    className="whitespace-nowrap px-6 py-4 font-semibold"
                                                >
                                                    {
                                                        item.name
                                                    }
                                                </th>
                                            ),
                                        )}
                                    </tr>
                                </thead>

                                <tbody className="divide-y divide-slate-200">
                                    {coverageRows.map(
                                        (
                                            row,
                                        ) => (
                                            <tr
                                                key={
                                                    row.key
                                                }
                                                className="hover:bg-slate-50/60"
                                            >
                                                <td className="whitespace-nowrap px-6 py-4 font-medium text-slate-900">
                                                    {
                                                        row.label
                                                    }
                                                </td>

                                                {comparisonProviders.map(
                                                    (
                                                        item,
                                                    ) => (
                                                        <td
                                                            key={
                                                                item.id
                                                            }
                                                            className="px-6 py-4"
                                                        >
                                                            <CoverageBadge
                                                                status={row.getStatus(
                                                                    results[
                                                                        item
                                                                            .id
                                                                    ]
                                                                        ?.normalized,
                                                                )}
                                                            />
                                                        </td>
                                                    ),
                                                )}
                                            </tr>
                                        ),
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    {/* Provider Evaluation */}
                    <ProviderEvaluationMatrix
                        providers={
                            providerEvaluation
                        }
                    />

                    {/* Result */}
                    {result && (
                        <section className="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                            {/* Result Header */}
                            <div className="border-b border-slate-200 p-6">
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <p className="text-sm text-slate-500">
                                            Result
                                        </p>

                                        <h2 className="mt-1 text-xl font-bold text-slate-900">
                                            {
                                                result.vrm
                                            }
                                        </h2>

                                        {result
                                            .normalized
                                            .meta
                                            .lab_fixture && (
                                            <span className="mt-2 inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-bold uppercase tracking-wide text-amber-700">
                                                Lab Fixture
                                            </span>
                                        )}
                                    </div>

                                    <div className="text-right text-sm text-slate-500">
                                        <p>
                                            Strategy:{' '}
                                            <strong className="text-slate-900">
                                                {result
                                                    .normalized
                                                    .meta
                                                    .strategy ===
                                                'hybrid'
                                                    ? 'Hybrid Composition'
                                                    : 'Single Provider'}
                                            </strong>
                                        </p>

                                        <p className="mt-1">
                                            Provider:{' '}
                                            <strong className="text-slate-900">
                                                {formatProviderName(
                                                    result.provider,
                                                )}
                                            </strong>
                                        </p>

                                        <p className="mt-1">
                                            Duration:{' '}
                                            <strong className="text-slate-900">
                                                {result.duration_ms !=
                                                null
                                                    ? `${result.duration_ms} ms`
                                                    : '—'}
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

                                {result
                                    .normalized
                                    .meta
                                    .sources && (
                                    <TabButton
                                        active={
                                            activeTab ===
                                            'sources'
                                        }
                                        onClick={() =>
                                            setActiveTab(
                                                'sources',
                                            )
                                        }
                                    >
                                        Sources
                                    </TabButton>
                                )}

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
                                    {result
                                        .normalized
                                        .meta
                                        .lab_fixture && (
                                        <FixtureWarning
                                            report={
                                                result.normalized
                                            }
                                        />
                                    )}

                                    <NormalizedReportPreview
                                        report={
                                            result.normalized
                                        }
                                    />
                                </div>
                            )}

                            {/* Normalized */}
                            {activeTab ===
                                'normalized' && (
                                <JsonViewer
                                    data={
                                        result.normalized
                                    }
                                />
                            )}

                            {/* Source Policy */}
                            {activeTab ===
                                'sources' &&
                                result
                                    .normalized
                                    .meta
                                    .sources && (
                                    <SourceMap
                                        sources={
                                            result
                                                .normalized
                                                .meta
                                                .sources
                                        }
                                        profiles={
                                            result
                                                .normalized
                                                .meta
                                                .source_profiles
                                        }
                                    />
                                )}

                            {/* Raw */}
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

function ProviderEvaluationMatrix({
    providers,
}: {
    providers: Record<
        string,
        ProviderEvaluation
    >;
}) {
    return (
        <section className="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div className="border-b border-slate-200 p-6">
                <h2 className="text-lg font-bold text-slate-900">
                    Provider Evaluation
                </h2>

                <p className="mt-1 max-w-3xl text-sm text-slate-500">
                    Internal engineering
                    evaluation for integration
                    status, sandbox access,
                    provenance review and the
                    intended role of each
                    provider.
                </p>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50 text-slate-600">
                        <tr>
                            <th className="px-5 py-4 font-semibold">
                                Provider
                            </th>

                            <th className="px-5 py-4 font-semibold">
                                Intended Role
                            </th>

                            <th className="px-5 py-4 font-semibold">
                                Integration
                            </th>

                            <th className="px-5 py-4 font-semibold">
                                Sandbox
                            </th>

                            <th className="px-5 py-4 font-semibold">
                                Provenance
                            </th>

                            <th className="px-5 py-4 font-semibold">
                                Cost / Check
                            </th>

                            <th className="px-5 py-4 font-semibold">
                                Evaluation
                            </th>
                        </tr>
                    </thead>

                    <tbody className="divide-y divide-slate-200">
                        {Object.entries(
                            providers,
                        ).map(
                            ([
                                id,
                                item,
                            ]) => (
                                <tr
                                    key={
                                        id
                                    }
                                    className="align-top hover:bg-slate-50/60"
                                >
                                    <td className="px-5 py-4">
                                        <p className="font-semibold text-slate-900">
                                            {
                                                item.name
                                            }
                                        </p>

                                        {item.notes && (
                                            <p className="mt-1 max-w-xs text-xs leading-5 text-slate-500">
                                                {
                                                    item.notes
                                                }
                                            </p>
                                        )}
                                    </td>

                                    <td className="px-5 py-4">
                                        <RoleBadge
                                            role={
                                                item.role
                                            }
                                        />
                                    </td>

                                    <td className="px-5 py-4">
                                        <EvaluationBadge
                                            value={
                                                item.integration_status
                                            }
                                        />
                                    </td>

                                    <td className="px-5 py-4">
                                        <EvaluationBadge
                                            value={
                                                item.sandbox_status
                                            }
                                        />
                                    </td>

                                    <td className="px-5 py-4">
                                        <EvaluationBadge
                                            value={
                                                item.provenance_status
                                            }
                                        />
                                    </td>

                                    <td className="whitespace-nowrap px-5 py-4 font-medium text-slate-700">
                                        {item
                                            .estimated_cost_per_check_gbp !=
                                        null
                                            ? formatGbp(
                                                  item
                                                      .estimated_cost_per_check_gbp,
                                              )
                                            : 'Not confirmed'}
                                    </td>

                                    <td className="px-5 py-4">
                                        <EvaluationBadge
                                            value={
                                                item.evaluation_status
                                            }
                                        />
                                    </td>
                                </tr>
                            ),
                        )}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function FixtureWarning({
    report,
}: {
    report: NormalizedReport;
}) {
    const fixtureInputs =
        report.meta
            .fixture_inputs;

    return (
        <div className="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-5 text-amber-950">
            <p className="font-bold">
                Development Fixture — Not a Real Vehicle Report
            </p>

            <p className="mt-2 max-w-3xl text-sm leading-6">
                This preview intentionally
                combines different sandbox
                vehicles from multiple
                providers only to test
                normalized composition,
                provider routing and report UI.
                Vehicle identity has not been
                verified.
            </p>

            {fixtureInputs && (
                <div className="mt-4 flex flex-wrap gap-3 text-xs">
                    <span className="rounded-lg border border-amber-200 bg-white/70 px-3 py-2">
                        CheckCarDetails:{' '}
                        <strong>
                            {
                                fixtureInputs
                                    .checkcardetails
                            }
                        </strong>
                    </span>

                    <span className="rounded-lg border border-amber-200 bg-white/70 px-3 py-2">
                        OneAuto:{' '}
                        <strong>
                            {
                                fixtureInputs
                                    .oneauto
                            }
                        </strong>
                    </span>
                </div>
            )}
        </div>
    );
}

function SourceMap({
    sources,
    profiles,
}: {
    sources: Record<
        string,
        string
    >;

    profiles?: Record<
        string,
        SourceProfile
    >;
}) {
    return (
        <div className="bg-slate-50 p-6">
            <div className="mb-5">
                <h3 className="text-lg font-bold text-slate-900">
                    Hybrid Source Policy
                </h3>

                <p className="mt-1 max-w-3xl text-sm text-slate-500">
                    Shows which provider
                    supplies each normalized
                    section and how critical the
                    information is to the
                    vehicle-history report.
                </p>
            </div>

            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50 text-slate-600">
                        <tr>
                            <th className="px-5 py-3 font-semibold">
                                Report Section
                            </th>

                            <th className="px-5 py-3 font-semibold">
                                Provider
                            </th>

                            <th className="px-5 py-3 font-semibold">
                                Criticality
                            </th>

                            <th className="px-5 py-3 font-semibold">
                                Purpose
                            </th>
                        </tr>
                    </thead>

                    <tbody className="divide-y divide-slate-200">
                        {Object.entries(
                            sources,
                        ).map(
                            ([
                                section,
                                sourceProvider,
                            ]) => {
                                const profile =
                                    profiles?.[
                                        section
                                    ];

                                return (
                                    <tr
                                        key={
                                            section
                                        }
                                        className="align-top hover:bg-slate-50/70"
                                    >
                                        <td className="whitespace-nowrap px-5 py-4 font-medium text-slate-800">
                                            {formatSourceSection(
                                                section,
                                            )}
                                        </td>

                                        <td className="px-5 py-4">
                                            <ProviderSourceBadge
                                                provider={
                                                    sourceProvider
                                                }
                                            />
                                        </td>

                                        <td className="px-5 py-4">
                                            {profile ? (
                                                <CriticalityBadge
                                                    criticality={
                                                        profile.criticality
                                                    }
                                                />
                                            ) : (
                                                <span className="text-slate-400">
                                                    —
                                                </span>
                                            )}
                                        </td>

                                        <td className="max-w-md px-5 py-4 text-sm leading-6 text-slate-600">
                                            {profile?.purpose ??
                                                'No internal source-policy note.'}
                                        </td>
                                    </tr>
                                );
                            },
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function ProviderSourceBadge({
    provider,
}: {
    provider: string;
}) {
    const style =
        provider ===
        'checkcardetails'
            ? 'bg-blue-100 text-blue-700'
            : provider ===
                'oneauto'
              ? 'bg-violet-100 text-violet-700'
              : 'bg-slate-100 text-slate-700';

    return (
        <span
            className={`inline-flex whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold ${style}`}
        >
            {formatProviderName(
                provider,
            )}
        </span>
    );
}

function CriticalityBadge({
    criticality,
}: {
    criticality:
        SourceProfile['criticality'];
}) {
    const styles: Record<
        SourceProfile['criticality'],
        string
    > = {
        low:
            'bg-slate-100 text-slate-600',

        medium:
            'bg-blue-100 text-blue-700',

        high:
            'bg-amber-100 text-amber-700',

        critical:
            'bg-red-100 text-red-700',
    };

    return (
        <span
            className={`inline-flex whitespace-nowrap rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wide ${styles[criticality]}`}
        >
            {criticality}
        </span>
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
        available:
            'Available',

        partial:
            'Partial',

        derived:
            'Derived',

        unavailable:
            'Unavailable',

        not_run:
            'Not run',
    };

    return (
        <span
            className={`inline-flex whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold ${styles[status]}`}
        >
            {labels[status]}
        </span>
    );
}

function EvaluationBadge({
    value,
}: {
    value: string;
}) {
    const positive = [
        'integrated',
        'available',
        'verified',
        'approved',
    ];

    const warning = [
        'partial',
        'limited',
        'testing',
        'candidate',
        'needs_review',
    ];

    const negative = [
        'unavailable',
        'not_integrated',
        'rejected',
    ];

    let style =
        'bg-slate-100 text-slate-600';

    if (
        positive.includes(
            value,
        )
    ) {
        style =
            'bg-emerald-100 text-emerald-700';
    } else if (
        warning.includes(
            value,
        )
    ) {
        style =
            'bg-amber-100 text-amber-700';
    } else if (
        negative.includes(
            value,
        )
    ) {
        style =
            'bg-red-100 text-red-700';
    }

    return (
        <span
            className={`inline-flex whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold ${style}`}
        >
            {formatStatusLabel(
                value,
            )}
        </span>
    );
}

function RoleBadge({
    role,
}: {
    role: string;
}) {
    const label =
        role === 'base'
            ? 'Base Data'
            : role ===
                'critical_history'
              ? 'Critical History'
              : formatStatusLabel(
                    role,
                );

    return (
        <span className="inline-flex whitespace-nowrap rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700">
            {label}
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
    children: ReactNode;
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

function formatProviderName(
    provider: string,
): string {
    if (
        provider ===
        'checkcardetails'
    ) {
        return 'CheckCarDetails';
    }

    if (
        provider ===
        'oneauto'
    ) {
        return 'OneAuto';
    }

    if (
        provider ===
        'hybrid'
    ) {
        return 'Hybrid';
    }

    /*
     * Fallback untuk provider baru.
     *
     * uk_vehicle_data
     * -> Uk Vehicle Data
     */
    return formatStatusLabel(
        provider,
    );
}

function formatSourceSection(
    value: string,
): string {
    return value
        .split('.')
        .map((part) =>
            formatStatusLabel(
                part,
            ),
        )
        .join(' / ');
}

function formatStatusLabel(
    value: string,
): string {
    return value
        .replace(
            /_/g,
            ' ',
        )
        .replace(
            /\b\w/g,
            (
                character,
            ) =>
                character.toUpperCase(),
        );
}

function formatGbp(
    value: number,
): string {
    return new Intl.NumberFormat(
        'en-GB',
        {
            style: 'currency',
            currency: 'GBP',

            maximumFractionDigits:
                2,
        },
    ).format(value);
}