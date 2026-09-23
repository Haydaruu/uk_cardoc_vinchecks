import type { ReactNode } from 'react';

import {
    AlertTriangle,
    ArrowRight,
    BadgePoundSterling,
    Car,
    CarFront,
    CheckCircle2,
    CircleHelp,
    CreditCard,
    FileCheck,
    FileText,
    Fuel,
    Gauge,
    History,
    KeyRound,
    Palette,
    Receipt,
    RotateCcw,
    Settings,
    ShieldAlert,
    ShieldCheck,
    Users,
    Wrench,
} from 'lucide-react';

import type {
    NormalizedReport,
    SalvageRecord,
} from '@/types/normalized-report';

type Props = {
    report: NormalizedReport;
};

type CheckState =
    | 'clean'
    | 'issue'
    | 'unavailable';

type VehicleStoryEvent = {
    id: string;

    date: string | null;

    category:
        | 'mot'
        | 'v5c'
        | 'keeper'
        | 'plate'
        | 'status';

    title: string;

    detail?: string;

    tone:
        | 'neutral'
        | 'success'
        | 'warning'
        | 'danger';
};

type MileagePoint = {
    date: string | null;
    mileage: number;
    result: string | null;
};

type MileageAnomaly = {
    previousMileage: number;
    currentMileage: number;

    previousDate: string | null;
    currentDate: string | null;

    decrease: number;
};

export default function NormalizedReportPreview({
    report,
}: Props) {
    const {
        meta,
        vehicle,
        status,
        v5c,
        history,
        salvage,
        specifications,
        dimensions,
        valuation,
        recalls,
        emissions_compliance,
        running_costs,
        mot,
    } = report;

    /*
     * MOT
     */
    const sortedMot = [
        ...(mot.tests ?? []),
    ].sort(
        (a, b) =>
            new Date(
                b.date ?? 0,
            ).getTime() -
            new Date(
                a.date ?? 0,
            ).getTime(),
    );

    const latestMot =
        sortedMot[0] ?? null;

    const passedMotTests =
        sortedMot.filter((test) =>
            isPass(test.result),
        );

    const failedMotTests =
        sortedMot.filter((test) =>
            isFail(test.result),
        );

    const completedMotTests =
        passedMotTests.length +
        failedMotTests.length;

    const motPassPercent =
        completedMotTests > 0
            ? (
                  passedMotTests.length /
                  completedMotTests
              ) *
              100
            : 0;

    /*
     * Mileage
     */
    const mileagePoints: MileagePoint[] =
        [
            ...(mot.mileage_history ??
                []),
        ]
            .filter(
                (
                    item,
                ): item is typeof item & {
                    mileage: number;
                } =>
                    item.mileage != null,
            )
            .map((item) => ({
                date:
                    item.date ??
                    null,

                mileage:
                    item.mileage,

                result:
                    item.result ??
                    null,
            }))
            .sort(
                (a, b) =>
                    safeDateTimestamp(
                        a.date,
                    ) -
                    safeDateTimestamp(
                        b.date,
                    ),
            );

    const latestMileage =
        mileagePoints.length > 0
            ? mileagePoints[
                  mileagePoints.length -
                      1
              ]
            : null;

    const sparklinePath =
        createSparklinePath(
            mileagePoints,
        );

    const mileageAnomalies =
        findMileageAnomalies(
            mileagePoints,
        );

    const mileageIntegrityState:
        | 'consistent'
        | 'issue'
        | 'insufficient' =
        mileagePoints.length < 2
            ? 'insufficient'
            : mileageAnomalies.length >
                0
              ? 'issue'
              : 'consistent';

    /*
     * History counts
     */
    const financeCount =
        getSectionCount(
            history.finance,
        );

    const stolenCount =
        getSectionCount(
            history.stolen,
        );

    const writeOffCount =
        getSectionCount(
            history.write_off,
        );

    const highRiskCount =
        getSectionCount(
            history.high_risk,
        );

    const criticalChecks = [
        {
            label: 'Finance',

            available:
                history.finance
                    .available,

            count: financeCount,
        },

        {
            label: 'Stolen',

            available:
                history.stolen
                    .available,

            count: stolenCount,
        },

        {
            label: 'Write-off',

            available:
                history.write_off
                    .available,

            count: writeOffCount,
        },

        {
            label: 'High Risk',

            available:
                history.high_risk
                    .available,

            count: highRiskCount,
        },
    ];

    const issueCheckCount =
        criticalChecks.filter(
            (check) =>
                check.available &&
                check.count > 0,
        ).length +
        (salvage.available &&
        salvage.record_found
            ? 1
            : 0);

    const unavailableCheckCount =
        criticalChecks.filter(
            (check) =>
                !check.available,
        ).length +
        (!salvage.available
            ? 1
            : 0);

    const reportState:
        | 'issue'
        | 'clear'
        | 'partial' =
        issueCheckCount > 0
            ? 'issue'
            : unavailableCheckCount >
                0
              ? 'partial'
              : 'clear';

    /*
     * Owner / registration
     */
    const keeperRecords =
        history.keepers.records ??
        [];

    const plateRecords =
        history.plate_changes
            .records ?? [];

    /*
     * Vehicle story
     */
    const vehicleStoryEvents =
        buildVehicleStoryEvents(
            report,
        );

    /*
     * Current MOT
     */
    const currentMotExpiry =
        mot.current
            ?.mot_expiry_date ??
        mot.expiry_date ??
        latestMot?.expiry_date ??
        null;

    const currentMotStatus =
        mot.current?.mot_status ??
        mot.status ??
        deriveMotStatusForDisplay(
            latestMot?.result,
            currentMotExpiry,
        );

    const currentMotSource =
        mot.current
            ?.mot_status_source ??
        (mot.status
            ? 'provider'
            : currentMotStatus
              ? 'derived'
              : 'unavailable');

    /*
     * Current tax
     */
    const taxServiceAvailable =
        mot.current
            ?.tax_service_available ??
        mot.tax_status != null;

    const currentTaxStatus =
        mot.current?.tax_status ??
        mot.tax_status ??
        null;

    const currentTaxExpiry =
        mot.current
            ?.tax_expiry_date ??
        mot.tax_expiry_date ??
        null;

    return (
        <>
            {/* ============================================================
                HERO
            ============================================================ */}

            <section className="mb-8">
                <div className="flex flex-col items-start gap-8 rounded-lg border border-outline-variant bg-white p-6 shadow-sm md:flex-row">
                    <div className="aspect-video w-full overflow-hidden rounded-lg border border-slate-200 bg-slate-100 md:w-1/3">
                        {vehicle.image_url ? (
                            <img
                                src={
                                    vehicle.image_url
                                }
                                alt={`${vehicle.make ?? 'Vehicle'} ${vehicle.model ?? ''}`}
                                className="h-full w-full object-cover"
                            />
                        ) : (
                            <div className="flex h-full w-full flex-col items-center justify-center gap-2">
                                <Car className="size-12 text-slate-300" />

                                <span className="text-xs font-medium uppercase tracking-wider text-slate-400">
                                    Vehicle
                                    image
                                    unavailable
                                </span>
                            </div>
                        )}
                    </div>

                    <div className="w-full flex-1">
                        <div className="flex flex-col justify-between gap-5 sm:flex-row sm:items-start">
                            <div>
                                <div className="mb-3 flex flex-wrap items-center gap-3">
                                    <span className="rounded border border-emerald-200 bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800">
                                        VALIDATED{' '}
                                        {meta.generated_at
                                            ? formatDate(
                                                  meta.generated_at,
                                              ).toUpperCase()
                                            : 'REPORT'}
                                    </span>

                                    <span className="font-label-sm rounded-full bg-surface-container px-3 py-1 uppercase text-on-primary-container">
                                        VRM:{' '}
                                        {vehicle.vrm ??
                                            'UNKNOWN'}
                                    </span>

                                    <span className="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-bold uppercase tracking-wide text-slate-500">
                                        {
                                            meta.provider
                                        }
                                    </span>
                                </div>

                                <h1 className="font-h1 mb-2 text-primary">
                                    {vehicle.make ??
                                        'Unknown Vehicle'}{' '}
                                    {vehicle.model ??
                                        ''}
                                </h1>

                                <p className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-500">
                                    {vehicle.year && (
                                        <>
                                            <span>
                                                Year:{' '}
                                                {
                                                    vehicle.year
                                                }
                                            </span>

                                            <Dot />
                                        </>
                                    )}

                                    {vehicle.engine_capacity_cc && (
                                        <>
                                            <span>
                                                Engine:{' '}
                                                {vehicle.engine_capacity_cc.toLocaleString()}
                                                cc
                                            </span>

                                            <Dot />
                                        </>
                                    )}

                                    <span className="font-mono text-slate-600">
                                        VIN:{' '}
                                        {vehicle.vin ??
                                            'Not available'}
                                    </span>
                                </p>
                            </div>

                            <ReportStateBadge
                                state={
                                    reportState
                                }
                                issueCount={
                                    issueCheckCount
                                }
                            />
                        </div>

                        <div className="mt-6 flex flex-wrap gap-3 border-t border-slate-100 pt-6">
                            <Chip
                                icon={
                                    <Fuel className="size-[18px] text-slate-500" />
                                }
                                label={
                                    vehicle.fuel_type
                                }
                            />

                            <Chip
                                icon={
                                    <Settings className="size-[18px] text-slate-500" />
                                }
                                label={
                                    vehicle.transmission
                                }
                            />

                            <Chip
                                icon={
                                    <Car className="size-[18px] text-slate-500" />
                                }
                                label={
                                    vehicle.body_type
                                }
                            />

                            <Chip
                                icon={
                                    <Palette className="size-[18px] text-slate-500" />
                                }
                                label={
                                    vehicle.colour
                                }
                            />

                            {dimensions.seats !=
                                null && (
                                <Chip
                                    icon={
                                        <Users className="size-[18px] text-slate-500" />
                                    }
                                    label={`${dimensions.seats} seats`}
                                />
                            )}
                        </div>

                        <div className="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <HeroStat
                                label="Latest Mileage"
                                value={
                                    latestMileage?.mileage !=
                                    null
                                        ? `${latestMileage.mileage.toLocaleString()} mi`
                                        : '—'
                                }
                            />

                            <HeroStat
                                label="Retail Value"
                                value={
                                    valuation.retail !=
                                    null
                                        ? formatMoney(
                                              valuation.retail,
                                          )
                                        : '—'
                                }
                            />

                            <HeroStat
                                label="Latest MOT"
                                value={
                                    currentMotStatus ??
                                    latestMot?.result ??
                                    'Not available'
                                }
                            />

                            <HeroStat
                                label="Keeper Changes"
                                value={String(
                                    history
                                        .keepers
                                        .count ??
                                        0,
                                )}
                            />
                        </div>
                    </div>
                </div>
            </section>

            {/* ============================================================
                REPORT GRID
            ============================================================ */}

            <div className="grid grid-cols-1 items-start gap-6 md:grid-cols-12">
                {/* ========================================================
                    VEHICLE STORY
                ======================================================== */}

                <SectionCard
                    span="md:col-span-12"
                    icon={
                        <History className="size-5" />
                    }
                    title="Vehicle Story"
                    description="A chronological view of the recorded lifecycle of this vehicle."
                >
                    <div className="p-6">
                        {vehicleStoryEvents.length ===
                        0 ? (
                            <EmptyState text="No dated vehicle history events are available." />
                        ) : (
                            <>
                                <div className="mb-7 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <MiniStat
                                        label="Events Recorded"
                                        value={
                                            vehicleStoryEvents.length
                                        }
                                    />

                                    <MiniStat
                                        label="MOT Events"
                                        value={
                                            vehicleStoryEvents.filter(
                                                (
                                                    event,
                                                ) =>
                                                    event.category ===
                                                    'mot',
                                            )
                                                .length
                                        }
                                    />

                                    <MiniStat
                                        label="Keeper Changes"
                                        value={
                                            history
                                                .keepers
                                                .count ??
                                            keeperRecords.length
                                        }
                                    />

                                    <MiniStat
                                        label="Plate Changes"
                                        value={
                                            history
                                                .plate_changes
                                                .count ??
                                            plateRecords.length
                                        }
                                    />
                                </div>

                                <VehicleStoryTimeline
                                    events={
                                        vehicleStoryEvents
                                    }
                                />
                            </>
                        )}
                    </div>
                </SectionCard>

                {/* ========================================================
                    VEHICLE SPECIFICATIONS
                ======================================================== */}

                <SectionCard
                    span="md:col-span-8"
                    icon={
                        <Car className="size-5" />
                    }
                    title="Vehicle Specifications"
                    description="Vehicle dimensions, performance and factory specification data."
                >
                    <div className="p-6">
                        <div className="mb-6 flex flex-wrap gap-x-8 gap-y-3 border-b border-slate-100 pb-5">
                            <SpecInline
                                icon={
                                    <Gauge className="size-4 text-slate-400" />
                                }
                                label={
                                    specifications.power_bhp !=
                                    null
                                        ? `${specifications.power_bhp} bhp`
                                        : undefined
                                }
                            />

                            <SpecInline
                                icon={
                                    <Gauge className="size-4 text-slate-400" />
                                }
                                label={
                                    specifications.torque_nm !=
                                    null
                                        ? `${specifications.torque_nm} Nm torque`
                                        : undefined
                                }
                            />

                            <SpecInline
                                icon={
                                    <Fuel className="size-4 text-slate-400" />
                                }
                                label={
                                    specifications.combined_mpg !=
                                    null
                                        ? `${specifications.combined_mpg} mpg combined`
                                        : undefined
                                }
                            />

                            {dimensions.doors !=
                                null && (
                                <SpecInline
                                    icon={
                                        <Car className="size-4 text-slate-400" />
                                    }
                                    label={`${dimensions.doors} doors`}
                                />
                            )}
                        </div>

                        {hasDimensions(
                            dimensions,
                        ) ? (
                            <div className="mb-6 flex justify-center rounded-lg border border-slate-100 bg-slate-50 p-6">
                                <DimensionDiagram
                                    length={
                                        dimensions.length_mm ??
                                        undefined
                                    }
                                    width={
                                        dimensions.width_mm ??
                                        undefined
                                    }
                                    height={
                                        dimensions.height_mm ??
                                        undefined
                                    }
                                />
                            </div>
                        ) : (
                            <UnavailableCard label="Vehicle dimensions" />
                        )}

                        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                            <MiniStat
                                label="Power"
                                value={
                                    specifications.power_bhp !=
                                    null
                                        ? `${specifications.power_bhp} bhp`
                                        : '—'
                                }
                            />

                            <MiniStat
                                label="Power PS"
                                value={
                                    specifications.power_ps !=
                                    null
                                        ? `${specifications.power_ps} PS`
                                        : '—'
                                }
                            />

                            <MiniStat
                                label="Power kW"
                                value={
                                    specifications.power_kw !=
                                    null
                                        ? `${specifications.power_kw} kW`
                                        : '—'
                                }
                            />

                            <MiniStat
                                label="Torque"
                                value={
                                    specifications.torque_nm !=
                                    null
                                        ? `${specifications.torque_nm} Nm`
                                        : '—'
                                }
                            />

                            <MiniStat
                                label="Top Speed"
                                value={
                                    specifications.top_speed_mph !=
                                    null
                                        ? `${specifications.top_speed_mph} mph`
                                        : '—'
                                }
                            />

                            <MiniStat
                                label="0–60 mph"
                                value={
                                    specifications.zero_to_sixty_mph !=
                                    null
                                        ? `${specifications.zero_to_sixty_mph}s`
                                        : '—'
                                }
                            />

                            <MiniStat
                                label="Insurance Group"
                                value={
                                    specifications.insurance_group ??
                                    '—'
                                }
                            />

                            <MiniStat
                                label="Euro Standard"
                                value={
                                    specifications.euro_standard ??
                                    '—'
                                }
                            />

                            <MiniStat
                                label="Wheelbase"
                                value={
                                    dimensions.wheelbase_mm !=
                                    null
                                        ? `${dimensions.wheelbase_mm} mm`
                                        : '—'
                                }
                            />

                            <MiniStat
                                label="Kerb Weight"
                                value={
                                    dimensions.kerb_weight_kg !=
                                    null
                                        ? `${dimensions.kerb_weight_kg} kg`
                                        : '—'
                                }
                            />

                            <MiniStat
                                label="Gross Weight"
                                value={
                                    dimensions.gross_weight_kg !=
                                    null
                                        ? `${dimensions.gross_weight_kg} kg`
                                        : '—'
                                }
                            />

                            <MiniStat
                                label="CO₂"
                                value={
                                    specifications.co2_gkm !=
                                    null
                                        ? `${specifications.co2_gkm} g/km`
                                        : '—'
                                }
                            />
                        </div>
                    </div>
                </SectionCard>

                {/* ========================================================
                    HISTORY CHECKS
                ======================================================== */}

                <SectionCard
                    span="md:col-span-4"
                    icon={
                        <ShieldCheck className="size-5" />
                    }
                    title="History Checks"
                    description="Critical recorded vehicle history."
                >
                    <div className="space-y-3 p-5">
                        <HistoryCheckRow
                            icon={
                                <CreditCard />
                            }
                            label="Finance"
                            available={
                                history.finance
                                    .available
                            }
                            count={
                                financeCount
                            }
                        />

                        <HistoryCheckRow
                            icon={
                                <ShieldAlert />
                            }
                            label="Stolen"
                            available={
                                history.stolen
                                    .available
                            }
                            count={
                                stolenCount
                            }
                        />

                        <HistoryCheckRow
                            icon={
                                <CarFront />
                            }
                            label="Write-off"
                            available={
                                history.write_off
                                    .available
                            }
                            count={
                                writeOffCount
                            }
                        />

                        <HistoryCheckRow
                            icon={
                                <AlertTriangle />
                            }
                            label="High Risk"
                            available={
                                history.high_risk
                                    .available
                            }
                            count={
                                highRiskCount
                            }
                        />

                        <HistoryCheckRow
                            icon={<Car />}
                            label="Salvage"
                            available={
                                salvage.available
                            }
                            count={
                                salvage.record_found
                                    ? Math.max(
                                          1,
                                          salvage
                                              .records
                                              .length,
                                      )
                                    : 0
                            }
                        />
                    </div>
                </SectionCard>

                {/* ========================================================
                    MOT & MILEAGE
                ======================================================== */}

                <SectionCard
                    span="md:col-span-8"
                    icon={
                        <FileCheck className="size-5" />
                    }
                    title="MOT & Mileage"
                    description="MOT test history, mileage progression and recorded defects."
                >
                    <div className="p-6">
                        {!mot.service_available ? (
                            <UnavailableCard label="MOT service" />
                        ) : sortedMot.length ===
                          0 ? (
                            <EmptyState text="No MOT tests are recorded for this vehicle." />
                        ) : (
                            <>
                                <CurrentRoadStatus
                                    motStatus={
                                        currentMotStatus
                                    }
                                    motExpiry={
                                        currentMotExpiry
                                    }
                                    motSource={
                                        currentMotSource
                                    }
                                    taxAvailable={
                                        taxServiceAvailable
                                    }
                                    taxStatus={
                                        currentTaxStatus
                                    }
                                    taxExpiry={
                                        currentTaxExpiry
                                    }
                                />

                                <MileageIntegrityPanel
                                    state={
                                        mileageIntegrityState
                                    }
                                    anomalies={
                                        mileageAnomalies
                                    }
                                    points={
                                        mileagePoints
                                    }
                                />

                                <div className="mb-6 flex flex-col justify-between gap-4 border-b border-slate-100 pb-6 sm:flex-row sm:items-start">
                                    <div className="flex items-start gap-3">
                                        {isPass(
                                            latestMot?.result,
                                        ) ? (
                                            <CheckCircle2 className="mt-0.5 size-6 text-emerald-600" />
                                        ) : (
                                            <AlertTriangle className="mt-0.5 size-6 text-amber-600" />
                                        )}

                                        <div>
                                            <p className="text-xs font-bold uppercase tracking-wider text-slate-400">
                                                Latest
                                                MOT
                                            </p>

                                            <p className="mt-1 text-xl font-bold text-slate-900">
                                                {latestMot?.result ??
                                                    'Unknown'}
                                            </p>

                                            <p className="mt-1 text-sm text-slate-500">
                                                {latestMot?.date
                                                    ? formatDate(
                                                          latestMot.date,
                                                      )
                                                    : 'Date unavailable'}
                                            </p>
                                        </div>
                                    </div>

                                    <div className="sm:text-right">
                                        <p className="text-xs font-bold uppercase tracking-wider text-slate-400">
                                            Recorded
                                            Mileage
                                        </p>

                                        <p className="mt-1 text-xl font-bold text-slate-900">
                                            {latestMot?.mileage !=
                                            null
                                                ? `${latestMot.mileage.toLocaleString()} ${latestMot.mileage_unit ?? 'mi'}`
                                                : '—'}
                                        </p>

                                        {latestMot?.expiry_date && (
                                            <p className="mt-1 text-sm text-slate-500">
                                                Expires{' '}
                                                {formatDate(
                                                    latestMot.expiry_date,
                                                )}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                                    <MiniStat
                                        label="Tests Recorded"
                                        value={
                                            sortedMot.length
                                        }
                                    />

                                    <MiniStat
                                        label="Passed"
                                        value={
                                            passedMotTests.length
                                        }
                                    />

                                    <MiniStat
                                        label="Failed"
                                        value={
                                            failedMotTests.length
                                        }
                                    />

                                    <MiniStat
                                        label="Pass Rate"
                                        value={
                                            completedMotTests >
                                            0
                                                ? `${Math.round(
                                                      motPassPercent,
                                                  )}%`
                                                : '—'
                                        }
                                    />
                                </div>

                                {completedMotTests >
                                    0 && (
                                    <div className="mb-8">
                                        <div className="flex h-2.5 overflow-hidden rounded-full border border-slate-200 bg-slate-100">
                                            <div
                                                className="bg-emerald-500"
                                                style={{
                                                    width: `${motPassPercent}%`,
                                                }}
                                            />

                                            <div
                                                className="bg-red-500"
                                                style={{
                                                    width: `${100 - motPassPercent}%`,
                                                }}
                                            />
                                        </div>

                                        <div className="mt-3 flex gap-6 text-xs font-medium text-slate-500">
                                            <span>
                                                {
                                                    passedMotTests.length
                                                }{' '}
                                                passed
                                            </span>

                                            <span>
                                                {
                                                    failedMotTests.length
                                                }{' '}
                                                failed
                                            </span>
                                        </div>
                                    </div>
                                )}

                                {sparklinePath && (
                                    <div className="mb-8">
                                        <p className="mb-3 text-xs font-bold uppercase tracking-wider text-slate-400">
                                            Mileage
                                            Progression
                                        </p>

                                        <div className="h-28 rounded-lg border border-slate-100 bg-slate-50 p-4">
                                            <svg
                                                className="h-full w-full"
                                                preserveAspectRatio="none"
                                                viewBox="0 0 100 100"
                                            >
                                                <polyline
                                                    fill="none"
                                                    points={
                                                        sparklinePath
                                                    }
                                                    stroke="currentColor"
                                                    strokeWidth="2"
                                                    className="text-primary"
                                                />
                                            </svg>
                                        </div>

                                        <div className="mt-2 flex justify-between text-xs text-slate-400">
                                            <span>
                                                {mileagePoints[0]
                                                    ?.date
                                                    ? formatYear(
                                                          mileagePoints[0]
                                                              .date,
                                                      )
                                                    : ''}
                                            </span>

                                            <span>
                                                {latestMileage?.date
                                                    ? formatYear(
                                                          latestMileage.date,
                                                      )
                                                    : ''}
                                            </span>
                                        </div>
                                    </div>
                                )}

                                <details className="group overflow-hidden rounded-lg border border-slate-200">
                                    <summary className="flex cursor-pointer list-none items-center justify-between bg-slate-50 px-5 py-4 font-semibold text-slate-800">
                                        Full MOT
                                        History

                                        <span className="text-xs font-bold uppercase tracking-wide text-primary-container">
                                            {
                                                sortedMot.length
                                            }{' '}
                                            tests
                                        </span>
                                    </summary>

                                    <div className="space-y-4 border-t border-slate-200 p-5">
                                        {sortedMot.map(
                                            (
                                                test,
                                                index,
                                            ) => (
                                                <MotTestCard
                                                    key={
                                                        test.test_number ??
                                                        index
                                                    }
                                                    test={
                                                        test
                                                    }
                                                />
                                            ),
                                        )}
                                    </div>
                                </details>
                            </>
                        )}
                    </div>
                </SectionCard>

                {/* ========================================================
                    RUNNING COSTS
                ======================================================== */}

                <SectionCard
                    span="md:col-span-4"
                    icon={
                        <Receipt className="size-5" />
                    }
                    title="Running Costs"
                    description="Available tax, insurance and economy estimates."
                >
                    <div className="grid grid-cols-2 gap-3 p-5">
                        <MiniStat
                            label="Annual Tax"
                            value={
                                running_costs
                                    .road_tax
                                    .available &&
                                running_costs
                                    .road_tax
                                    .annual !=
                                    null
                                    ? formatMoney(
                                          running_costs
                                              .road_tax
                                              .annual,
                                      )
                                    : '—'
                            }
                        />

                        <MiniStat
                            label="First Year"
                            value={
                                running_costs
                                    .road_tax
                                    .available &&
                                running_costs
                                    .road_tax
                                    .first_year !=
                                    null
                                    ? formatMoney(
                                          running_costs
                                              .road_tax
                                              .first_year,
                                      )
                                    : '—'
                            }
                        />

                        <MiniStat
                            label="6 Month Tax"
                            value={
                                running_costs
                                    .road_tax
                                    .available &&
                                running_costs
                                    .road_tax
                                    .six_month !=
                                    null
                                    ? formatMoney(
                                          running_costs
                                              .road_tax
                                              .six_month,
                                      )
                                    : '—'
                            }
                        />

                        <MiniStat
                            label="Premium Tax"
                            value={
                                running_costs
                                    .road_tax
                                    .available &&
                                running_costs
                                    .road_tax
                                    .premium_annual !=
                                    null
                                    ? formatMoney(
                                          running_costs
                                              .road_tax
                                              .premium_annual,
                                      )
                                    : '—'
                            }
                        />

                        <MiniStat
                            label="Insurance Age 20"
                            value={
                                running_costs
                                    .insurance
                                    .available &&
                                running_costs
                                    .insurance
                                    .age_20 !=
                                    null
                                    ? formatMoney(
                                          running_costs
                                              .insurance
                                              .age_20,
                                      )
                                    : '—'
                            }
                        />

                        <MiniStat
                            label="Insurance Age 30"
                            value={
                                running_costs
                                    .insurance
                                    .available &&
                                running_costs
                                    .insurance
                                    .age_30 !=
                                    null
                                    ? formatMoney(
                                          running_costs
                                              .insurance
                                              .age_30,
                                      )
                                    : '—'
                            }
                        />

                        <MiniStat
                            label="Insurance Age 40"
                            value={
                                running_costs
                                    .insurance
                                    .available &&
                                running_costs
                                    .insurance
                                    .age_40 !=
                                    null
                                    ? formatMoney(
                                          running_costs
                                              .insurance
                                              .age_40,
                                      )
                                    : '—'
                            }
                        />

                        <MiniStat
                            label="Combined MPG"
                            value={
                                running_costs
                                    .fuel
                                    .available &&
                                running_costs
                                    .fuel
                                    .combined_mpg !=
                                    null
                                    ? `${running_costs.fuel.combined_mpg} mpg`
                                    : '—'
                            }
                        />
                    </div>

                    {running_costs
                        .insurance
                        .available && (
                        <div className="border-t border-slate-100 px-5 py-4">
                            <p className="text-xs leading-5 text-slate-500">
                                Insurance
                                figures are
                                estimates and
                                may vary based
                                on driver,
                                location,
                                insurer and
                                policy details.
                            </p>
                        </div>
                    )}
                </SectionCard>

                {/* ========================================================
                    VALUATION
                ======================================================== */}

                <SectionCard
                    span="md:col-span-8"
                    icon={
                        <BadgePoundSterling className="size-5" />
                    }
                    title="Vehicle Valuation"
                    description="Available market valuation estimates for this vehicle."
                >
                    <div className="grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-4">
                        <MiniStat
                            label="Retail"
                            big
                            value={
                                valuation.retail !=
                                null
                                    ? formatMoney(
                                          valuation.retail,
                                      )
                                    : '—'
                            }
                        />

                        <MiniStat
                            label="Private"
                            big
                            value={
                                valuation.private !=
                                null
                                    ? formatMoney(
                                          valuation.private,
                                      )
                                    : '—'
                            }
                        />

                        <MiniStat
                            label="Trade"
                            big
                            value={
                                valuation.trade !=
                                null
                                    ? formatMoney(
                                          valuation.trade,
                                      )
                                    : '—'
                            }
                        />

                        <MiniStat
                            label="Part Exchange"
                            big
                            value={
                                valuation.trade_in !=
                                null
                                    ? formatMoney(
                                          valuation.trade_in,
                                      )
                                    : '—'
                            }
                        />
                    </div>

                    {latestMileage?.mileage !=
                        null && (
                        <p className="border-t border-slate-100 px-6 py-4 text-xs text-slate-500">
                            Latest recorded
                            mileage:{' '}
                            {latestMileage.mileage.toLocaleString()}{' '}
                            miles.
                        </p>
                    )}
                </SectionCard>

                {/* ========================================================
                    V5C
                ======================================================== */}

                <SectionCard
                    span="md:col-span-4"
                    icon={
                        <FileText className="size-5" />
                    }
                    title="V5C / Logbook"
                    description="Recorded registration document history."
                >
                    <div className="p-5">
                        <div className="grid grid-cols-2 gap-3">
                            <MiniStat
                                label="Documents"
                                value={
                                    v5c.count ??
                                    '—'
                                }
                            />

                            <MiniStat
                                label="Latest Issue"
                                value={
                                    v5c.latest_issue_date
                                        ? formatDate(
                                              v5c.latest_issue_date,
                                          )
                                        : '—'
                                }
                            />
                        </div>

                        <V5CVerificationState
                            available={
                                v5c.verification_available
                            }
                            verified={
                                v5c.serial_verified
                            }
                        />

                        <div className="mt-5 border-t border-slate-100 pt-5">
                            <p className="mb-3 text-xs font-bold uppercase tracking-wider text-slate-400">
                                Issue History
                            </p>

                            {v5c.history
                                .length > 0 ? (
                                <div className="flex flex-wrap gap-2">
                                    {v5c.history.map(
                                        (
                                            item,
                                            index,
                                        ) => (
                                            <span
                                                key={
                                                    index
                                                }
                                                className="rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-600"
                                            >
                                                {item.issue_date
                                                    ? formatDate(
                                                          item.issue_date,
                                                      )
                                                    : 'Unknown date'}
                                            </span>
                                        ),
                                    )}
                                </div>
                            ) : (
                                <p className="text-sm text-slate-500">
                                    No V5C
                                    issue
                                    history
                                    available.
                                </p>
                            )}
                        </div>
                    </div>
                </SectionCard>

                {/* ========================================================
                    DETAILED HISTORY
                ======================================================== */}

                <SectionCard
                    span="md:col-span-12"
                    icon={
                        <ShieldAlert className="size-5" />
                    }
                    title="Detailed History Records"
                    description="Full provider records behind the headline vehicle-history checks."
                >
                    <div className="grid gap-6 p-6 lg:grid-cols-2">
                        <FinanceHistoryGroup
                            state={getCheckState(
                                history.finance
                                    .available,
                                financeCount,
                            )}
                            records={
                                history.finance
                                    .records
                            }
                        />

                        <HistoryRecordGroup
                            title="Stolen Records"
                            state={getCheckState(
                                history.stolen
                                    .available,
                                stolenCount,
                            )}
                            records={
                                history.stolen
                                    .records
                            }
                            clearText="No stolen vehicle records found."
                        />

                        <WriteOffHistoryGroup
                            state={getCheckState(
                                history
                                    .write_off
                                    .available,
                                writeOffCount,
                            )}
                            records={
                                history
                                    .write_off
                                    .records
                            }
                        />

                        <HistoryRecordGroup
                            title="High Risk Records"
                            state={getCheckState(
                                history
                                    .high_risk
                                    .available,
                                highRiskCount,
                            )}
                            records={
                                history
                                    .high_risk
                                    .records
                            }
                            clearText="No high-risk records found."
                        />
                    </div>
                </SectionCard>

                {/* ========================================================
                    SALVAGE
                ======================================================== */}

                <SectionCard
                    span="md:col-span-12"
                    icon={
                        <CarFront className="size-5" />
                    }
                    title="Salvage Auction History"
                    description="Recorded salvage auction entries, damage information and supporting photographs."
                >
                    <div className="p-6">
                        {!salvage.available ? (
                            <UnavailableCard label="Salvage auction history" />
                        ) : !salvage.record_found ? (
                            <EmptyState text="No salvage auction records found." />
                        ) : (
                            <>
                                <AttentionState
                                    title="Salvage record found"
                                    description={`${salvage.records.length} auction ${
                                        salvage
                                            .records
                                            .length ===
                                        1
                                            ? 'record'
                                            : 'records'
                                    } available.`}
                                />

                                <div className="mt-5">
                                    <SalvageAuctionList
                                        records={
                                            salvage.records
                                        }
                                    />
                                </div>
                            </>
                        )}
                    </div>
                </SectionCard>

                {/* ========================================================
                    RECALL
                ======================================================== */}

                <SectionCard
                    span="md:col-span-6"
                    icon={
                        <RotateCcw className="size-5" />
                    }
                    title="Recall Check"
                    description="Manufacturer and vehicle safety recall information."
                >
                    <div className="p-5">
                        {!recalls.available ? (
                            <UnavailableCard label="Recall information" />
                        ) : (
                            <>
                                <div className="grid grid-cols-2 gap-3">
                                    <MiniStat
                                        label="Status"
                                        value={
                                            recalls.status ??
                                            'No active status'
                                        }
                                    />

                                    <MiniStat
                                        label="Recall Records"
                                        value={
                                            recalls.count ??
                                            0
                                        }
                                    />

                                    <MiniStat
                                        label="Source"
                                        value={
                                            recalls.source ??
                                            '—'
                                        }
                                    />
                                </div>

                                {recalls.records
                                    .length >
                                    0 && (
                                    <div className="mt-5">
                                        <RecordList
                                            records={
                                                recalls.records
                                            }
                                            recordLabel="Recall record"
                                        />
                                    </div>
                                )}

                                {recalls.manufacturer_url && (
                                    <a
                                        href={
                                            recalls.manufacturer_url
                                        }
                                        target="_blank"
                                        rel="noreferrer"
                                        className="mt-5 flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-primary-container transition-colors hover:bg-slate-100"
                                    >
                                        Manufacturer
                                        recall check

                                        <ArrowRight className="size-4" />
                                    </a>
                                )}
                            </>
                        )}
                    </div>
                </SectionCard>

                {/* ========================================================
                    OWNER / REGISTRATION
                ======================================================== */}

                <SectionCard
                    span="md:col-span-6"
                    icon={
                        <History className="size-5" />
                    }
                    title="Keeper & Registration History"
                    description="Recorded keeper and registration plate changes."
                >
                    <div className="grid gap-8 p-6 lg:grid-cols-2">
                        <TimelineColumn
                            title="Keeper Changes"
                            icon={
                                <KeyRound className="size-4" />
                            }
                            available={
                                history.keepers
                                    .available
                            }
                        >
                            {keeperRecords.length >
                            0 ? (
                                keeperRecords.map(
                                    (
                                        item,
                                        index,
                                    ) => (
                                        <TimelineItem
                                            key={
                                                index
                                            }
                                            label="Keeper Change"
                                            title={`${item.previous_keepers ?? '—'} previous keeper(s)`}
                                            date={
                                                item.change_date
                                            }
                                        />
                                    ),
                                )
                            ) : (
                                <p className="text-sm text-slate-500">
                                    No keeper
                                    changes
                                    recorded.
                                </p>
                            )}
                        </TimelineColumn>

                        <TimelineColumn
                            title="Plate Changes"
                            icon={
                                <Car className="size-4" />
                            }
                            available={
                                history
                                    .plate_changes
                                    .available
                            }
                        >
                            {plateRecords.length >
                            0 ? (
                                plateRecords.map(
                                    (
                                        item,
                                        index,
                                    ) => (
                                        <TimelineItem
                                            key={
                                                index
                                            }
                                            label={
                                                item.transfer_type ??
                                                'Plate Change'
                                            }
                                            title={`${item.previous_vrm ?? 'Unknown'} → ${item.current_vrm ?? 'Unknown'}`}
                                            date={
                                                item.transfer_date
                                            }
                                            secondaryDate={
                                                item.receipt_date
                                                    ? `Receipt: ${formatDate(
                                                          item.receipt_date,
                                                      )}`
                                                    : undefined
                                            }
                                        />
                                    ),
                                )
                            ) : (
                                <p className="text-sm text-slate-500">
                                    No plate
                                    changes
                                    recorded.
                                </p>
                            )}
                        </TimelineColumn>
                    </div>
                </SectionCard>

                {/* ========================================================
                    VEHICLE STATUS
                ======================================================== */}

                <SectionCard
                    span="md:col-span-4"
                    icon={
                        <Car className="size-5" />
                    }
                    title="Vehicle Status"
                    description="Recorded lifecycle and registration status."
                >
                    <div className="space-y-3 p-5">
                        <BooleanRow
                            label="Scrapped"
                            value={
                                status.scrapped
                            }
                        />

                        <BooleanRow
                            label="Certificate of Destruction"
                            value={
                                status.certificate_of_destruction_issued
                            }
                        />

                        <BooleanRow
                            label="Imported"
                            value={
                                status.imported
                            }
                        />

                        <BooleanRow
                            label="Exported"
                            value={
                                status.exported
                            }
                        />

                        {status.scrapped_date && (
                            <DetailRow
                                label="Scrapped Date"
                                value={formatDate(
                                    status.scrapped_date,
                                )}
                            />
                        )}

                        <div className="border-t border-slate-100 pt-4">
                            <p className="mb-3 flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-slate-400">
                                <Palette className="size-4" />

                                Colour
                                History
                            </p>

                            {!history
                                .colour_changes
                                .available ? (
                                <p className="text-sm text-slate-400">
                                    Not
                                    available
                                    from this
                                    provider.
                                </p>
                            ) : getSectionCount(
                                  history.colour_changes,
                              ) === 0 ? (
                                <p className="text-sm text-slate-500">
                                    No colour
                                    changes
                                    recorded.
                                </p>
                            ) : (
                                <RecordList
                                    records={
                                        history
                                            .colour_changes
                                            .records
                                    }
                                    recordLabel="Colour change"
                                />
                            )}
                        </div>
                    </div>
                </SectionCard>

                {/* ========================================================
                    ULEZ
                ======================================================== */}

                <SectionCard
                    span="md:col-span-8"
                    icon={
                        <Fuel className="size-5" />
                    }
                    title="ULEZ / Clean Air Assessment"
                    description="Emissions-based assessment from the vehicle data available to this report."
                >
                    <div className="p-6">
                        <div
                            className={`rounded-lg border p-5 ${
                                emissions_compliance.meets_minimum_standard ===
                                true
                                    ? 'border-emerald-200 bg-emerald-50'
                                    : emissions_compliance.meets_minimum_standard ===
                                        false
                                      ? 'border-amber-200 bg-amber-50'
                                      : 'border-slate-200 bg-slate-50'
                            }`}
                        >
                            <div className="flex flex-col justify-between gap-5 md:flex-row md:items-start">
                                <div>
                                    <div className="flex items-center gap-3">
                                        {emissions_compliance.meets_minimum_standard ===
                                        true ? (
                                            <CheckCircle2 className="size-5 text-emerald-600" />
                                        ) : emissions_compliance.meets_minimum_standard ===
                                          false ? (
                                            <AlertTriangle className="size-5 text-amber-600" />
                                        ) : (
                                            <CircleHelp className="size-5 text-slate-500" />
                                        )}

                                        <p className="font-bold text-slate-900">
                                            {emissions_compliance.meets_minimum_standard ===
                                            true
                                                ? 'Meets minimum emissions standard'
                                                : emissions_compliance.meets_minimum_standard ===
                                                    false
                                                  ? 'May not meet minimum emissions standard'
                                                  : 'Unable to determine emissions compliance'}
                                        </p>
                                    </div>

                                    <p className="mt-2 max-w-3xl text-sm leading-relaxed text-slate-600">
                                        {emissions_compliance.reason ??
                                            'No additional assessment information is available.'}
                                    </p>
                                </div>

                                <div className="flex shrink-0 flex-wrap gap-2">
                                    {emissions_compliance.euro_standard && (
                                        <span className="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-700">
                                            {
                                                emissions_compliance.euro_standard
                                            }
                                        </span>
                                    )}

                                    <span className="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold uppercase text-slate-500">
                                        {
                                            emissions_compliance.assessment_type
                                        }
                                    </span>
                                </div>
                            </div>

                            {!emissions_compliance.officially_verified && (
                                <p className="mt-5 border-t border-black/5 pt-4 text-xs leading-relaxed text-slate-500">
                                    This
                                    assessment
                                    is not an
                                    authoritative
                                    vehicle-specific
                                    ULEZ or Clean
                                    Air Zone
                                    approval.
                                    Local
                                    classifications,
                                    exemptions
                                    and rules
                                    may apply.
                                </p>
                            )}
                        </div>
                    </div>
                </SectionCard>
            </div>
        </>
    );
}

/* ================================================================
   BASE COMPONENTS
================================================================ */

function SectionCard({
    span,
    icon,
    title,
    description,
    children,
}: {
    span: string;

    icon: ReactNode;

    title: string;

    description?: string;

    children: ReactNode;
}) {
    return (
        <section
            className={`${span} overflow-hidden rounded-lg border border-outline-variant bg-white shadow-sm`}
        >
            <div className="flex items-start gap-3 border-b border-slate-200 bg-slate-50/50 px-6 py-4">
                <span className="mt-0.5 text-primary-container">
                    {icon}
                </span>

                <div>
                    <h3 className="text-base font-semibold text-slate-800">
                        {title}
                    </h3>

                    {description && (
                        <p className="mt-0.5 text-xs leading-relaxed text-slate-500">
                            {
                                description
                            }
                        </p>
                    )}
                </div>
            </div>

            {children}
        </section>
    );
}

function ReportStateBadge({
    state,
    issueCount,
}: {
    state:
        | 'issue'
        | 'clear'
        | 'partial';

    issueCount: number;
}) {
    if (state === 'issue') {
        return (
            <div className="shrink-0 rounded-md border border-red-200 bg-red-50 px-4 py-3">
                <div className="flex items-center gap-2 text-red-700">
                    <AlertTriangle className="size-4" />

                    <span className="text-xs font-bold uppercase tracking-wide">
                        Attention
                        Required
                    </span>
                </div>

                <p className="mt-1 text-xs text-red-500">
                    {issueCount}{' '}
                    history{' '}
                    {issueCount === 1
                        ? 'area'
                        : 'areas'}{' '}
                    with records.
                </p>
            </div>
        );
    }

    if (state === 'partial') {
        return (
            <div className="shrink-0 rounded-md border border-blue-200 bg-blue-50 px-4 py-3">
                <div className="flex items-center gap-2 text-blue-700">
                    <ShieldCheck className="size-4" />

                    <span className="text-xs font-bold uppercase tracking-wide">
                        Available
                        Checks Clear
                    </span>
                </div>

                <p className="mt-1 text-xs text-blue-500">
                    Some checks
                    are unavailable
                    from this
                    provider.
                </p>
            </div>
        );
    }

    return (
        <div className="shrink-0 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3">
            <div className="flex items-center gap-2 text-emerald-700">
                <ShieldCheck className="size-4" />

                <span className="text-xs font-bold uppercase tracking-wide">
                    No Critical
                    Records
                </span>
            </div>

            <p className="mt-1 text-xs text-emerald-500">
                Available
                critical
                history checks
                returned clear.
            </p>
        </div>
    );
}

function HistoryCheckRow({
    icon,
    label,
    available,
    count,
}: {
    icon: ReactNode;

    label: string;

    available: boolean;

    count: number;
}) {
    const state =
        getCheckState(
            available,
            count,
        );

    return (
        <div
            className={`flex items-center justify-between rounded-lg border p-3.5 ${
                state === 'issue'
                    ? 'border-red-200 bg-red-50'
                    : state ===
                        'clean'
                      ? 'border-emerald-100 bg-emerald-50/60'
                      : 'border-slate-200 bg-slate-50'
            }`}
        >
            <div className="flex items-center gap-3">
                <span
                    className={`[&>svg]:size-[18px] ${
                        state ===
                        'issue'
                            ? 'text-red-600'
                            : state ===
                                'clean'
                              ? 'text-emerald-600'
                              : 'text-slate-400'
                    }`}
                >
                    {icon}
                </span>

                <span className="text-sm font-semibold text-slate-800">
                    {label}
                </span>
            </div>

            <span
                className={`text-xs font-bold ${
                    state ===
                    'issue'
                        ? 'text-red-700'
                        : state ===
                            'clean'
                          ? 'text-emerald-700'
                          : 'text-slate-400'
                }`}
            >
                {state === 'issue'
                    ? `${count} found`
                    : state ===
                        'clean'
                      ? 'Clear'
                      : 'N/A'}
            </span>
        </div>
    );
}

function HistoryRecordGroup({
    title,
    state,
    records,
    clearText,
}: {
    title: string;

    state: CheckState;

    records?: unknown[];

    clearText: string;
}) {
    return (
        <div className="rounded-lg border border-slate-200">
            <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/70 px-4 py-3">
                <p className="font-semibold text-slate-800">
                    {title}
                </p>

                <CheckBadge
                    state={state}
                />
            </div>

            <div className="p-4">
                {state ===
                'unavailable' ? (
                    <UnavailableCard
                        label={title}
                        compact
                    />
                ) : state ===
                  'clean' ? (
                    <EmptyState
                        text={
                            clearText
                        }
                        compact
                    />
                ) : (
                    <RecordList
                        records={
                            records
                        }
                        recordLabel={title.replace(
                            /\s+Records$/i,
                            '',
                        )}
                    />
                )}
            </div>
        </div>
    );
}

function CheckBadge({
    state,
}: {
    state: CheckState;
}) {
    if (state === 'issue') {
        return (
            <span className="rounded-full bg-red-100 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-red-700">
                Records Found
            </span>
        );
    }

    if (state === 'clean') {
        return (
            <span className="rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-emerald-700">
                Clear
            </span>
        );
    }

    return (
        <span className="rounded-full bg-slate-200 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-slate-500">
            Unavailable
        </span>
    );
}

/* ================================================================
   VEHICLE STORY
================================================================ */

function VehicleStoryTimeline({
    events,
}: {
    events: VehicleStoryEvent[];
}) {
    return (
        <div className="relative">
            <div className="absolute bottom-0 left-[7px] top-0 w-px bg-slate-200" />

            <div className="space-y-0">
                {events.map(
                    (event) => (
                        <div
                            key={
                                event.id
                            }
                            className="relative flex gap-5 pb-7 last:pb-0"
                        >
                            <StoryDot
                                tone={
                                    event.tone
                                }
                            />

                            <div className="min-w-0 flex-1 border-b border-slate-100 pb-6 last:border-0">
                                <div className="flex flex-col justify-between gap-2 sm:flex-row sm:items-start">
                                    <div>
                                        <p className="text-sm font-semibold text-slate-900">
                                            {
                                                event.title
                                            }
                                        </p>

                                        {event.detail && (
                                            <p className="mt-1 text-sm leading-6 text-slate-500">
                                                {
                                                    event.detail
                                                }
                                            </p>
                                        )}
                                    </div>

                                    <span className="shrink-0 text-xs font-medium text-slate-400">
                                        {event.date
                                            ? formatDate(
                                                  event.date,
                                              )
                                            : 'Date unavailable'}
                                    </span>
                                </div>

                                <span className="mt-2 inline-block text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                    {
                                        event.category
                                    }
                                </span>
                            </div>
                        </div>
                    ),
                )}
            </div>
        </div>
    );
}

function StoryDot({
    tone,
}: {
    tone: VehicleStoryEvent['tone'];
}) {
    const styles = {
        neutral:
            'border-slate-400 bg-slate-100',

        success:
            'border-emerald-500 bg-emerald-100',

        warning:
            'border-amber-500 bg-amber-100',

        danger:
            'border-red-500 bg-red-100',
    };

    return (
        <span
            className={`relative z-10 mt-1.5 size-[15px] shrink-0 rounded-full border-2 ${styles[tone]}`}
        />
    );
}

/* ================================================================
   MILEAGE
================================================================ */

function MileageIntegrityPanel({
    state,
    anomalies,
    points,
}: {
    state:
        | 'consistent'
        | 'issue'
        | 'insufficient';

    anomalies: MileageAnomaly[];

    points: MileagePoint[];
}) {
    if (
        state ===
        'insufficient'
    ) {
        return (
            <div className="mb-6 rounded-lg border border-slate-200 bg-slate-50 p-4">
                <div className="flex items-start gap-3">
                    <CircleHelp className="mt-0.5 size-5 shrink-0 text-slate-500" />

                    <div>
                        <p className="font-semibold text-slate-800">
                            Mileage
                            Integrity
                        </p>

                        <p className="mt-1 text-sm leading-6 text-slate-500">
                            Insufficient
                            recorded
                            mileage
                            history is
                            available to
                            assess mileage
                            progression.
                        </p>
                    </div>
                </div>
            </div>
        );
    }

    if (state === 'issue') {
        return (
            <div className="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
                <div className="flex items-start gap-3">
                    <AlertTriangle className="mt-0.5 size-5 shrink-0 text-red-600" />

                    <div className="flex-1">
                        <p className="font-semibold text-red-800">
                            Potential
                            Mileage
                            Anomaly
                            Detected
                        </p>

                        <p className="mt-1 text-sm leading-6 text-red-700">
                            A later
                            recorded
                            mileage is
                            lower than an
                            earlier
                            mileage
                            entry. This
                            does not by
                            itself confirm
                            odometer
                            tampering,
                            but the
                            vehicle
                            history
                            should be
                            investigated.
                        </p>

                        <div className="mt-4 space-y-2">
                            {anomalies.map(
                                (
                                    anomaly,
                                    index,
                                ) => (
                                    <div
                                        key={
                                            index
                                        }
                                        className="rounded-md border border-red-200 bg-white/70 px-3 py-2"
                                    >
                                        <p className="text-sm font-semibold text-red-800">
                                            {anomaly.previousMileage.toLocaleString()}
                                            {
                                                ' → '
                                            }
                                            {anomaly.currentMileage.toLocaleString()}{' '}
                                            mi
                                        </p>

                                        <p className="mt-1 text-xs text-red-600">
                                            Decrease
                                            of{' '}
                                            {anomaly.decrease.toLocaleString()}{' '}
                                            miles
                                        </p>

                                        <p className="mt-1 text-xs text-red-500">
                                            {anomaly.previousDate
                                                ? formatDate(
                                                      anomaly.previousDate,
                                                  )
                                                : 'Unknown date'}
                                            {
                                                ' → '
                                            }
                                            {anomaly.currentDate
                                                ? formatDate(
                                                      anomaly.currentDate,
                                                  )
                                                : 'Unknown date'}
                                        </p>
                                    </div>
                                ),
                            )}
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4">
            <div className="flex items-start gap-3">
                <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-emerald-600" />

                <div>
                    <p className="font-semibold text-emerald-800">
                        Mileage
                        Progression
                        Appears
                        Consistent
                    </p>

                    <p className="mt-1 text-sm leading-6 text-emerald-700">
                        No mileage
                        decreases were
                        detected across{' '}
                        {points.length}{' '}
                        recorded
                        mileage
                        entries.
                    </p>

                    <p className="mt-2 text-xs text-emerald-600">
                        This
                        assessment is
                        based only on
                        mileage
                        records
                        available to
                        this report.
                    </p>
                </div>
            </div>
        </div>
    );
}

/* ================================================================
   CURRENT ROAD STATUS
================================================================ */

function CurrentRoadStatus({
    motStatus,
    motExpiry,
    motSource,
    taxAvailable,
    taxStatus,
    taxExpiry,
}: {
    motStatus: string | null;

    motExpiry: string | null;

    motSource: string;

    taxAvailable: boolean;

    taxStatus: string | null;

    taxExpiry: string | null;
}) {
    return (
        <div className="mb-6">
            <p className="mb-3 text-xs font-bold uppercase tracking-wider text-slate-400">
                Current Road
                Status
            </p>

            <div className="grid gap-4 md:grid-cols-2">
                <RoadStatusCard
                    title="MOT Status"
                    status={
                        motStatus
                    }
                    expiry={
                        motExpiry
                    }
                    source={
                        motSource
                    }
                    unavailable={
                        !motStatus
                    }
                />

                <RoadStatusCard
                    title="Vehicle Tax"
                    status={currentTaxLabel(
                        taxStatus,
                    )}
                    expiry={
                        taxExpiry
                    }
                    source={
                        taxAvailable
                            ? 'provider'
                            : 'unavailable'
                    }
                    unavailable={
                        !taxAvailable
                    }
                />
            </div>
        </div>
    );
}

function RoadStatusCard({
    title,
    status,
    expiry,
    source,
    unavailable,
}: {
    title: string;

    status: string | null;

    expiry: string | null;

    source: string;

    unavailable: boolean;
}) {
    if (unavailable) {
        return (
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                <div className="flex items-start gap-3">
                    <CircleHelp className="mt-0.5 size-5 shrink-0 text-slate-400" />

                    <div>
                        <p className="text-xs font-bold uppercase tracking-wider text-slate-400">
                            {title}
                        </p>

                        <p className="mt-2 font-semibold text-slate-700">
                            Data
                            unavailable
                        </p>

                        <p className="mt-1 text-xs text-slate-500">
                            Current
                            status was
                            not supplied
                            by this
                            provider.
                        </p>
                    </div>
                </div>
            </div>
        );
    }

    const tone =
        getRoadStatusTone(
            status,
        );

    const styles = {
        success:
            'border-emerald-200 bg-emerald-50 text-emerald-800',

        warning:
            'border-amber-200 bg-amber-50 text-amber-800',

        danger:
            'border-red-200 bg-red-50 text-red-800',

        neutral:
            'border-slate-200 bg-slate-50 text-slate-800',
    } as const;

    return (
        <div
            className={`rounded-lg border p-4 ${styles[tone]}`}
        >
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-xs font-bold uppercase tracking-wider opacity-60">
                        {title}
                    </p>

                    <div className="mt-2 flex items-center gap-2">
                        {tone ===
                        'success' ? (
                            <CheckCircle2 className="size-5" />
                        ) : tone ===
                              'danger' ||
                          tone ===
                              'warning' ? (
                            <AlertTriangle className="size-5" />
                        ) : (
                            <CircleHelp className="size-5" />
                        )}

                        <p className="text-lg font-bold">
                            {status ??
                                'Unknown'}
                        </p>
                    </div>
                </div>

                <span className="rounded bg-white/60 px-2 py-1 text-[10px] font-bold uppercase tracking-wide opacity-70">
                    {source}
                </span>
            </div>

            <div className="mt-4 border-t border-black/5 pt-3">
                <p className="text-xs opacity-70">
                    {expiry
                        ? `Expiry: ${formatDate(
                              expiry,
                          )}`
                        : 'Expiry date unavailable'}
                </p>
            </div>
        </div>
    );
}

/* ================================================================
   V5C
================================================================ */

function V5CVerificationState({
    available,
    verified,
}: {
    available: boolean;

    verified:
        | boolean
        | null;
}) {
    if (!available) {
        return (
            <div className="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4">
                <div className="flex items-start gap-3">
                    <CircleHelp className="mt-0.5 size-5 shrink-0 text-slate-500" />

                    <div>
                        <p className="text-sm font-semibold text-slate-800">
                            V5C Serial
                            Verification
                        </p>

                        <p className="mt-1 text-xs leading-5 text-slate-500">
                            Serial or
                            document
                            reference
                            verification
                            is not
                            available
                            from this
                            data
                            provider.
                        </p>
                    </div>
                </div>
            </div>
        );
    }

    if (verified === true) {
        return (
            <div className="mt-5 rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                <div className="flex items-start gap-3">
                    <FileCheck className="mt-0.5 size-5 shrink-0 text-emerald-600" />

                    <div>
                        <p className="text-sm font-semibold text-emerald-800">
                            V5C
                            Verification
                            Confirmed
                        </p>

                        <p className="mt-1 text-xs leading-5 text-emerald-700">
                            The submitted
                            V5C reference
                            was confirmed
                            by the
                            available
                            verification
                            service.
                        </p>
                    </div>
                </div>
            </div>
        );
    }

    if (verified === false) {
        return (
            <div className="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4">
                <div className="flex items-start gap-3">
                    <AlertTriangle className="mt-0.5 size-5 shrink-0 text-amber-600" />

                    <div>
                        <p className="text-sm font-semibold text-amber-800">
                            V5C
                            Verification
                            Not Confirmed
                        </p>

                        <p className="mt-1 text-xs leading-5 text-amber-700">
                            The available
                            verification
                            result did
                            not confirm
                            the submitted
                            V5C reference.
                        </p>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4">
            <div className="flex items-start gap-3">
                <CircleHelp className="mt-0.5 size-5 shrink-0 text-slate-500" />

                <div>
                    <p className="text-sm font-semibold text-slate-800">
                        V5C
                        Verification
                        Result
                        Unavailable
                    </p>

                    <p className="mt-1 text-xs leading-5 text-slate-500">
                        V5C
                        verification
                        is supported,
                        but no
                        verification
                        result was
                        returned.
                    </p>
                </div>
            </div>
        </div>
    );
}

/* ================================================================
   FINANCE
================================================================ */

function FinanceHistoryGroup({
    state,
    records,
}: {
    state: CheckState;
    records?: unknown[];
}) {
    const parsedRecords =
        toRecords(records);

    return (
        <div className="rounded-lg border border-slate-200">
            <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/70 px-4 py-3">
                <div className="flex items-center gap-2">
                    <CreditCard className="size-4 text-slate-500" />

                    <p className="font-semibold text-slate-800">
                        Finance
                        Records
                    </p>
                </div>

                <CheckBadge
                    state={state}
                />
            </div>

            <div className="p-4">
                {state ===
                'unavailable' ? (
                    <UnavailableCard
                        label="Finance records"
                        compact
                    />
                ) : state ===
                  'clean' ? (
                    <EmptyState
                        text="No finance records found."
                        compact
                    />
                ) : (
                    <div className="space-y-4">
                        {parsedRecords.map(
                            (
                                record,
                                index,
                            ) => (
                                <FinanceRecordCard
                                    key={
                                        index
                                    }
                                    record={
                                        record
                                    }
                                    index={
                                        index
                                    }
                                />
                            ),
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}

function FinanceRecordCard({
    record,
    index,
}: {
    record: Record<
        string,
        unknown
    >;

    index: number;
}) {
    const company =
        recordString(
            record,
            [
                'finance_company',
                'financeCompany',
                'FinanceCompany',
                'company',
            ],
        );

    const type =
        recordString(
            record,
            [
                'finance_type',
                'financeType',
                'FinanceType',
                'agreement_type',
            ],
        );

    const agreement =
        recordString(
            record,
            [
                'finance_agreement_number',
                'agreement_number',
                'financeAgreementNumber',
                'AgreementNumber',
            ],
        );

    const startDate =
        recordString(
            record,
            [
                'finance_start_date',
                'start_date',
                'financeStartDate',
                'StartDate',
            ],
        );

    const term =
        recordNumber(
            record,
            [
                'finance_term_months',
                'term_months',
                'financeTermMonths',
                'Term',
            ],
        );

    const vehicleDescription =
        recordString(
            record,
            [
                'financed_vehicle_desc',
                'vehicle_description',
                'financedVehicleDesc',
                'VehicleDescription',
            ],
        );

    const contact =
        recordString(
            record,
            [
                'finance_company_contact_number',
                'contact_number',
                'financeCompanyContactNumber',
            ],
        );

    const updated =
        recordString(
            record,
            [
                'date_last_updated',
                'last_updated',
                'dateLastUpdated',
            ],
        );

    return (
        <article className="overflow-hidden rounded-lg border border-red-200 bg-white">
            <div className="border-b border-red-100 bg-red-50/70 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <span className="rounded-full bg-red-100 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-red-700">
                            Finance
                            Record
                        </span>

                        <h4 className="mt-3 font-bold text-slate-900">
                            {company ??
                                `Finance agreement #${index + 1}`}
                        </h4>

                        {type && (
                            <p className="mt-1 text-sm font-medium text-red-700">
                                {
                                    type
                                }
                            </p>
                        )}
                    </div>

                    {agreement && (
                        <div className="rounded-md border border-red-100 bg-white px-3 py-2 text-right">
                            <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                Agreement
                            </p>

                            <p className="mt-1 font-mono text-xs font-semibold text-slate-700">
                                {
                                    agreement
                                }
                            </p>
                        </div>
                    )}
                </div>
            </div>

            <div className="grid gap-4 p-4 sm:grid-cols-2">
                <HistoryDetail
                    label="Finance Company"
                    value={
                        company
                    }
                />

                <HistoryDetail
                    label="Finance Type"
                    value={type}
                />

                <HistoryDetail
                    label="Start Date"
                    value={
                        startDate
                            ? formatDate(
                                  startDate,
                              )
                            : null
                    }
                />

                <HistoryDetail
                    label="Term"
                    value={
                        term != null
                            ? `${term} months`
                            : null
                    }
                />

                <HistoryDetail
                    label="Vehicle"
                    value={
                        vehicleDescription
                    }
                />

                <HistoryDetail
                    label="Contact"
                    value={
                        contact
                    }
                />

                <HistoryDetail
                    label="Last Updated"
                    value={
                        updated
                            ? formatDate(
                                  updated,
                              )
                            : null
                    }
                />
            </div>

            <div className="border-t border-red-100 bg-red-50/40 px-4 py-3">
                <p className="text-xs leading-5 text-red-700">
                    A finance
                    record was
                    returned by
                    the vehicle
                    history
                    provider.
                    Settlement
                    status should
                    be confirmed
                    before
                    completing a
                    vehicle
                    purchase.
                </p>
            </div>

            <ProviderRecordDetails
                record={record}
            />
        </article>
    );
}

/* ================================================================
   WRITE-OFF
================================================================ */

function WriteOffHistoryGroup({
    state,
    records,
}: {
    state: CheckState;
    records?: unknown[];
}) {
    const parsedRecords =
        toRecords(records);

    return (
        <div className="rounded-lg border border-slate-200">
            <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/70 px-4 py-3">
                <div className="flex items-center gap-2">
                    <ShieldAlert className="size-4 text-slate-500" />

                    <p className="font-semibold text-slate-800">
                        Write-off
                        Records
                    </p>
                </div>

                <CheckBadge
                    state={state}
                />
            </div>

            <div className="p-4">
                {state ===
                'unavailable' ? (
                    <UnavailableCard
                        label="Write-off records"
                        compact
                    />
                ) : state ===
                  'clean' ? (
                    <EmptyState
                        text="No write-off records found."
                        compact
                    />
                ) : (
                    <div className="space-y-4">
                        {parsedRecords.map(
                            (
                                record,
                                index,
                            ) => (
                                <WriteOffRecordCard
                                    key={
                                        index
                                    }
                                    record={
                                        record
                                    }
                                    index={
                                        index
                                    }
                                />
                            ),
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}

function WriteOffRecordCard({
    record,
    index,
}: {
    record: Record<
        string,
        unknown
    >;

    index: number;
}) {
    const category =
        recordString(
            record,
            [
                'recovered_category',
                'write_off_category',
                'writeoff_category',
                'category',
                'Category',
            ],
        );

    const categoryDescription =
        recordString(
            record,
            [
                'recovered_category_desc',
                'category_description',
                'write_off_category_desc',
                'CategoryDescription',
            ],
        );

    const dateOfLoss =
        recordString(
            record,
            [
                'date_of_loss',
                'loss_date',
                'dateOfLoss',
                'DateOfLoss',
            ],
        );

    const recordStatus =
        recordString(
            record,
            [
                'vehicle_status',
                'status',
                'VehicleStatus',
            ],
        );

    const insurer =
        recordString(
            record,
            [
                'insurer_name',
                'insurer',
                'InsurerName',
            ],
        );

    const claim =
        recordString(
            record,
            [
                'insurer_claim_number',
                'claim_number',
                'claimNumber',
            ],
        );

    const cause =
        recordString(
            record,
            [
                'cause_of_damage',
                'damage_cause',
                'causeOfDamage',
            ],
        );

    const miaftrDate =
        recordString(
            record,
            [
                'date_of_miaftr_entry',
                'miaftr_date',
                'dateOfMiaftrEntry',
            ],
        );

    const removedDate =
        recordString(
            record,
            [
                'date_removed',
                'removed_date',
                'dateRemoved',
            ],
        );

    const damageLocations =
        extractDamageLocations(
            record,
        );

    return (
        <article className="overflow-hidden rounded-lg border border-red-200 bg-white">
            <div className="border-b border-red-100 bg-red-50/70 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <span className="rounded-full bg-red-100 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-red-700">
                            Insurance
                            Write-off
                        </span>

                        <h4 className="mt-3 text-lg font-bold text-slate-900">
                            {recordStatus ??
                                `Write-off record #${index + 1}`}
                        </h4>

                        {dateOfLoss && (
                            <p className="mt-1 text-sm text-slate-500">
                                Loss
                                recorded{' '}
                                {formatDate(
                                    dateOfLoss,
                                )}
                            </p>
                        )}
                    </div>

                    {category && (
                        <div className="flex min-w-14 shrink-0 items-center justify-center rounded-lg border-2 border-red-300 bg-white px-3 py-3 text-xl font-black uppercase text-red-700">
                            {
                                category
                            }
                        </div>
                    )}
                </div>
            </div>

            {(category ||
                categoryDescription) && (
                <div className="border-b border-red-100 bg-red-50/30 p-4">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-red-500">
                        Write-off
                        Category
                    </p>

                    <p className="mt-1 font-semibold text-red-900">
                        {category
                            ? `Category ${category}`
                            : 'Category not supplied'}
                    </p>

                    {categoryDescription && (
                        <p className="mt-1 text-sm text-red-700">
                            {
                                categoryDescription
                            }
                        </p>
                    )}
                </div>
            )}

            <div className="grid gap-4 p-4 sm:grid-cols-2">
                <HistoryDetail
                    label="Date of Loss"
                    value={
                        dateOfLoss
                            ? formatDate(
                                  dateOfLoss,
                              )
                            : null
                    }
                />

                <HistoryDetail
                    label="Vehicle Status"
                    value={
                        recordStatus
                    }
                />

                <HistoryDetail
                    label="Cause of Damage"
                    value={
                        cause
                    }
                />

                <HistoryDetail
                    label="Insurer"
                    value={
                        insurer
                    }
                />

                <HistoryDetail
                    label="Claim Number"
                    value={
                        claim
                    }
                />

                <HistoryDetail
                    label="MIAFTR Entry"
                    value={
                        miaftrDate
                            ? formatDate(
                                  miaftrDate,
                              )
                            : null
                    }
                />

                <HistoryDetail
                    label="Record Removed"
                    value={
                        removedDate
                            ? formatDate(
                                  removedDate,
                              )
                            : null
                    }
                />
            </div>

            {damageLocations.length >
                0 && (
                <div className="border-t border-slate-100 p-4">
                    <p className="mb-3 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                        Recorded
                        Damage
                        Locations
                    </p>

                    <div className="flex flex-wrap gap-2">
                        {damageLocations.map(
                            (
                                location,
                            ) => (
                                <span
                                    key={
                                        location
                                    }
                                    className="rounded-full border border-red-200 bg-red-50 px-3 py-1 text-xs font-semibold text-red-700"
                                >
                                    {
                                        location
                                    }
                                </span>
                            ),
                        )}
                    </div>
                </div>
            )}

            <ProviderRecordDetails
                record={record}
            />
        </article>
    );
}

function HistoryDetail({
    label,
    value,
}: {
    label: string;

    value:
        | string
        | null
        | undefined;
}) {
    return (
        <div>
            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                {label}
            </p>

            <p className="mt-1 break-words text-sm font-semibold text-slate-800">
                {value ??
                    'Not available'}
            </p>
        </div>
    );
}

function ProviderRecordDetails({
    record,
}: {
    record: Record<
        string,
        unknown
    >;
}) {
    return (
        <details className="border-t border-slate-100">
            <summary className="cursor-pointer list-none px-4 py-3 text-xs font-bold uppercase tracking-wider text-primary-container hover:bg-slate-50">
                View all
                provider
                fields
            </summary>

            <div className="grid gap-x-8 border-t border-slate-100 bg-slate-50/50 p-4 md:grid-cols-2">
                {Object.entries(
                    record,
                ).map(
                    ([
                        key,
                        value,
                    ]) => (
                        <div
                            key={
                                key
                            }
                            className="flex justify-between gap-4 border-b border-slate-100 py-2.5 text-sm"
                        >
                            <span className="text-slate-500">
                                {humanizeKey(
                                    key,
                                )}
                            </span>

                            <span className="max-w-[60%] break-words text-right font-medium text-slate-800">
                                {formatUnknownValue(
                                    value,
                                    key,
                                )}
                            </span>
                        </div>
                    ),
                )}
            </div>
        </details>
    );
}

/* ================================================================
   SALVAGE
================================================================ */

function SalvageAuctionList({
    records,
}: {
    records: SalvageRecord[];
}) {
    if (records.length === 0) {
        return (
            <EmptyState text="No detailed salvage auction records are available." />
        );
    }

    return (
        <div className="space-y-6">
            {records.map(
                (
                    record,
                    index,
                ) => (
                    <SalvageAuctionCard
                        key={
                            record.salvage_auction_record_id ??
                            index
                        }
                        record={
                            record
                        }
                        index={
                            index
                        }
                    />
                ),
            )}
        </div>
    );
}

function SalvageAuctionCard({
    record,
    index,
}: {
    record: SalvageRecord;

    index: number;
}) {
    const images =
        Array.from(
            new Set(
                (
                    record.external_image_urls ??
                    []
                ).filter(
                    (
                        url,
                    ): url is string =>
                        typeof url ===
                            'string' &&
                        url.length >
                            0,
                ),
            ),
        );

    const reference =
        record.salvage_auction_reference ??
        record.salvage_auction_record_id;

    return (
        <article className="overflow-hidden rounded-xl border border-red-200 bg-white">
            <div className="flex flex-col justify-between gap-4 border-b border-red-100 bg-red-50/70 p-5 sm:flex-row sm:items-start">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="rounded-full bg-red-100 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-red-700">
                            Salvage
                            Record
                        </span>

                        <span className="text-xs font-medium text-slate-400">
                            #
                            {index +
                                1}
                        </span>
                    </div>

                    <h4 className="mt-3 text-lg font-bold text-slate-900">
                        {record.salvage_auction_lot_desc ??
                            'Salvage auction listing'}
                    </h4>

                    {record.salvage_auction_lot_date && (
                        <p className="mt-1 text-sm text-slate-500">
                            Auctioned{' '}
                            {formatDate(
                                record.salvage_auction_lot_date,
                            )}
                        </p>
                    )}
                </div>

                {reference !=
                    null && (
                    <div className="shrink-0 rounded-lg border border-red-100 bg-white px-3 py-2 text-right">
                        <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                            Auction
                            Reference
                        </p>

                        <p className="mt-1 font-mono text-sm font-semibold text-slate-700">
                            {String(
                                reference,
                            )}
                        </p>
                    </div>
                )}
            </div>

            {images.length > 0 ? (
                <div className="border-b border-slate-100 p-5">
                    <div className="mb-3 flex items-center justify-between">
                        <p className="text-xs font-bold uppercase tracking-wider text-slate-400">
                            Auction
                            Photos
                        </p>

                        <span className="text-xs text-slate-400">
                            {
                                images.length
                            }{' '}
                            photo
                            {images.length ===
                            1
                                ? ''
                                : 's'}
                        </span>
                    </div>

                    <div
                        className={`grid gap-3 ${
                            images.length ===
                            1
                                ? 'grid-cols-1'
                                : 'grid-cols-2 lg:grid-cols-3'
                        }`}
                    >
                        {images.map(
                            (
                                image,
                                imageIndex,
                            ) => (
                                <a
                                    key={
                                        image
                                    }
                                    href={
                                        image
                                    }
                                    target="_blank"
                                    rel="noreferrer"
                                    className="group relative aspect-video overflow-hidden rounded-lg border border-slate-200 bg-slate-100"
                                >
                                    <img
                                        src={
                                            image
                                        }
                                        alt={`Salvage auction vehicle photo ${imageIndex + 1}`}
                                        loading="lazy"
                                        className="h-full w-full object-cover transition-transform duration-200 group-hover:scale-[1.03]"
                                    />

                                    <div className="absolute bottom-2 right-2 rounded bg-black/60 px-2 py-1 text-[10px] font-semibold text-white">
                                        View
                                        full
                                    </div>
                                </a>
                            ),
                        )}
                    </div>
                </div>
            ) : (
                <div className="border-b border-slate-100 bg-slate-50 px-5 py-4">
                    <p className="flex items-center gap-2 text-sm text-slate-500">
                        <CircleHelp className="size-4" />

                        No salvage
                        auction
                        photos were
                        supplied
                        with this
                        record.
                    </p>
                </div>
            )}

            <div className="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                <SalvageDetail
                    label="Auction Date"
                    value={
                        record.salvage_auction_lot_date
                            ? formatDate(
                                  record.salvage_auction_lot_date,
                              )
                            : 'Not available'
                    }
                />

                <SalvageDetail
                    label="Mileage"
                    value={
                        record.mileage !=
                        null
                            ? `${record.mileage.toLocaleString()} mi`
                            : 'Not available'
                    }
                />

                <SalvageDetail
                    label="Location"
                    value={
                        record.salvage_auction_location ??
                        'Not available'
                    }
                />

                <SalvageDetail
                    label="Reference"
                    value={
                        reference !=
                        null
                            ? String(
                                  reference,
                              )
                            : 'Not available'
                    }
                />
            </div>

            <div className="border-t border-slate-100 p-5">
                <p className="mb-4 text-xs font-bold uppercase tracking-wider text-slate-400">
                    Recorded Damage
                </p>

                <div className="grid gap-4 md:grid-cols-2">
                    <DamagePanel
                        label="Primary Damage"
                        value={
                            record.primary_damage_desc
                        }
                        important
                    />

                    <DamagePanel
                        label="Secondary Damage"
                        value={
                            record.secondary_damage_desc
                        }
                    />
                </div>
            </div>

            <div className="border-t border-slate-100 bg-slate-50 px-5 py-4">
                <p className="text-xs leading-5 text-slate-500">
                    Salvage
                    auction
                    information
                    reflects
                    records
                    returned by
                    the available
                    data source.
                    Photographs
                    and
                    descriptions
                    may represent
                    the vehicle
                    condition at
                    the time of
                    that listing.
                </p>
            </div>
        </article>
    );
}

function SalvageDetail({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    return (
        <div>
            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                {label}
            </p>

            <p className="mt-1 text-sm font-semibold text-slate-800">
                {value}
            </p>
        </div>
    );
}

function DamagePanel({
    label,
    value,
    important = false,
}: {
    label: string;

    value:
        | string
        | null;

    important?: boolean;
}) {
    return (
        <div
            className={`rounded-lg border p-4 ${
                important &&
                value
                    ? 'border-red-200 bg-red-50'
                    : 'border-slate-200 bg-slate-50'
            }`}
        >
            <p
                className={`text-[11px] font-bold uppercase tracking-wider ${
                    important &&
                    value
                        ? 'text-red-500'
                        : 'text-slate-400'
                }`}
            >
                {label}
            </p>

            <p
                className={`mt-2 text-sm font-semibold leading-6 ${
                    important &&
                    value
                        ? 'text-red-800'
                        : 'text-slate-700'
                }`}
            >
                {value ??
                    'Not available'}
            </p>
        </div>
    );
}

/* ================================================================
   GENERIC RECORDS
================================================================ */

function RecordList({
    records,
    recordLabel,
}: {
    records?: unknown[];

    recordLabel: string;
}) {
    const parsedRecords =
        toRecords(records);

    if (
        parsedRecords.length ===
        0
    ) {
        return (
            <p className="text-sm text-slate-500">
                No detailed
                records
                available.
            </p>
        );
    }

    return (
        <div className="space-y-3">
            {parsedRecords.map(
                (
                    record,
                    index,
                ) => (
                    <details
                        key={
                            index
                        }
                        className="group overflow-hidden rounded-lg border border-slate-200"
                    >
                        <summary className="flex cursor-pointer list-none items-center justify-between bg-white px-4 py-3 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                            <span>
                                {
                                    recordLabel
                                }{' '}
                                #
                                {index +
                                    1}
                            </span>

                            <span className="text-xs font-bold uppercase tracking-wide text-primary-container">
                                View
                                details
                            </span>
                        </summary>

                        <div className="grid gap-x-8 border-t border-slate-100 bg-slate-50/40 p-4 md:grid-cols-2">
                            {Object.entries(
                                record,
                            ).map(
                                ([
                                    key,
                                    value,
                                ]) => (
                                    <div
                                        key={
                                            key
                                        }
                                        className="flex justify-between gap-4 border-b border-slate-100 py-2.5 text-sm"
                                    >
                                        <span className="text-slate-500">
                                            {humanizeKey(
                                                key,
                                            )}
                                        </span>

                                        <span className="max-w-[60%] break-words text-right font-medium text-slate-800">
                                            {formatUnknownValue(
                                                value,
                                                key,
                                            )}
                                        </span>
                                    </div>
                                ),
                            )}
                        </div>
                    </details>
                ),
            )}
        </div>
    );
}

/* ================================================================
   MOT
================================================================ */

function MotTestCard({
    test,
}: {
    test: NormalizedReport['mot']['tests'][number];
}) {
    const pass =
        isPass(
            test.result,
        );

    const fail =
        isFail(
            test.result,
        );

    return (
        <div className="rounded-lg border border-slate-200 bg-white p-4">
            <div className="flex flex-col justify-between gap-3 sm:flex-row">
                <div className="flex items-start gap-3">
                    {pass ? (
                        <CheckCircle2 className="mt-0.5 size-5 text-emerald-600" />
                    ) : fail ? (
                        <AlertTriangle className="mt-0.5 size-5 text-red-600" />
                    ) : (
                        <CircleHelp className="mt-0.5 size-5 text-slate-400" />
                    )}

                    <div>
                        <p
                            className={`font-bold ${
                                pass
                                    ? 'text-emerald-700'
                                    : fail
                                      ? 'text-red-700'
                                      : 'text-slate-700'
                            }`}
                        >
                            {test.result ??
                                'Unknown result'}
                        </p>

                        <p className="mt-0.5 text-xs text-slate-500">
                            {test.date
                                ? formatDate(
                                      test.date,
                                  )
                                : 'Date unavailable'}
                        </p>

                        {test.test_number && (
                            <p className="mt-1 font-mono text-[11px] text-slate-400">
                                MOT #
                                {
                                    test.test_number
                                }
                            </p>
                        )}
                    </div>
                </div>

                <div className="sm:text-right">
                    <p className="font-bold text-slate-800">
                        {test.mileage !=
                        null
                            ? `${test.mileage.toLocaleString()} ${test.mileage_unit ?? 'mi'}`
                            : 'Mileage unavailable'}
                    </p>

                    {test.expiry_date && (
                        <p className="mt-0.5 text-xs text-slate-500">
                            Expiry{' '}
                            {formatDate(
                                test.expiry_date,
                            )}
                        </p>
                    )}
                </div>
            </div>

            {test.defects?.length >
                0 && (
                <div className="mt-4 border-t border-slate-100 pt-4">
                    <p className="mb-3 text-xs font-bold uppercase tracking-wider text-slate-400">
                        Defects /
                        Advisories
                    </p>

                    <div className="space-y-2">
                        {test.defects.map(
                            (
                                defect,
                                defectIndex,
                            ) => (
                                <div
                                    key={
                                        defectIndex
                                    }
                                    className={`rounded-md border px-3 py-2.5 text-sm ${
                                        defect.dangerous
                                            ? 'border-red-200 bg-red-50 text-red-800'
                                            : 'border-slate-200 bg-slate-50 text-slate-700'
                                    }`}
                                >
                                    <span className="font-bold">
                                        {defect.type ??
                                            'Note'}
                                    </span>

                                    {defect.description && (
                                        <>
                                            {
                                                ' — '
                                            }
                                            {
                                                defect.description
                                            }
                                        </>
                                    )}
                                </div>
                            ),
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

/* ================================================================
   TIMELINES
================================================================ */

function TimelineColumn({
    title,
    icon,
    available,
    children,
}: {
    title: string;

    icon: ReactNode;

    available: boolean;

    children: ReactNode;
}) {
    return (
        <div>
            <p className="mb-5 flex items-center gap-2 font-semibold text-slate-800">
                <span className="text-slate-400">
                    {icon}
                </span>

                {title}
            </p>

            {!available ? (
                <UnavailableCard
                    label={title}
                    compact
                />
            ) : (
                <div className="relative space-y-5 before:absolute before:bottom-2 before:left-[5px] before:top-2 before:w-px before:bg-slate-200">
                    {
                        children
                    }
                </div>
            )}
        </div>
    );
}

function TimelineItem({
    label,
    title,
    date,
    secondaryDate,
}: {
    label: string;

    title: string;

    date?:
        | string
        | null;

    secondaryDate?: string;
}) {
    return (
        <div className="relative pl-6">
            <span className="absolute left-0 top-1.5 size-2.5 rounded-full border-2 border-white bg-primary-container ring-2 ring-primary-container/15" />

            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                {label}
            </p>

            <p className="mt-1 font-medium text-slate-800">
                {title}
            </p>

            {date && (
                <p className="mt-1 text-xs text-slate-500">
                    {formatDate(
                        date,
                    )}
                </p>
            )}

            {secondaryDate && (
                <p className="mt-0.5 text-xs text-slate-400">
                    {
                        secondaryDate
                    }
                </p>
            )}
        </div>
    );
}

/* ================================================================
   SMALL UI
================================================================ */

function BooleanRow({
    label,
    value,
}: {
    label: string;
    value: boolean | null;
}) {
    return (
        <div className="flex items-center justify-between rounded-lg border border-slate-100 bg-slate-50 p-3.5">
            <span className="text-sm text-slate-600">
                {label}
            </span>

            <span
                className={`text-sm font-bold ${
                    value === true
                        ? 'text-amber-700'
                        : value ===
                            false
                          ? 'text-emerald-700'
                          : 'text-slate-400'
                }`}
            >
                {value == null
                    ? 'Unknown'
                    : value
                      ? 'Yes'
                      : 'No'}
            </span>
        </div>
    );
}

function AttentionState({
    title,
    description,
}: {
    title: string;
    description: string;
}) {
    return (
        <div className="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
            <AlertTriangle className="mt-0.5 size-5 shrink-0 text-amber-600" />

            <div>
                <p className="font-bold text-amber-900">
                    {title}
                </p>

                <p className="mt-1 text-sm text-amber-700">
                    {
                        description
                    }
                </p>
            </div>
        </div>
    );
}

function EmptyState({
    text,
    compact = false,
}: {
    text: string;
    compact?: boolean;
}) {
    return (
        <div
            className={`flex items-center gap-3 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 ${
                compact
                    ? 'p-3'
                    : 'p-4'
            }`}
        >
            <CheckCircle2 className="size-5 shrink-0" />

            <p className="text-sm font-semibold">
                {text}
            </p>
        </div>
    );
}

function UnavailableCard({
    label,
    compact = false,
}: {
    label: string;
    compact?: boolean;
}) {
    return (
        <div
            className={`flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 text-slate-500 ${
                compact
                    ? 'p-3'
                    : 'p-4'
            }`}
        >
            <CircleHelp className="size-5 shrink-0" />

            <div>
                <p className="text-sm font-semibold text-slate-600">
                    {label}{' '}
                    unavailable
                </p>

                {!compact && (
                    <p className="mt-0.5 text-xs">
                        This data is
                        not available
                        from the
                        selected
                        provider.
                    </p>
                )}
            </div>
        </div>
    );
}

function Chip({
    icon,
    label,
}: {
    icon: ReactNode;

    label?:
        | string
        | null;
}) {
    if (!label) {
        return null;
    }

    return (
        <span className="flex items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm font-medium text-slate-700">
            {icon}

            {label.toUpperCase()}
        </span>
    );
}

function SpecInline({
    icon,
    label,
}: {
    icon: ReactNode;
    label?: string;
}) {
    if (!label) {
        return null;
    }

    return (
        <div className="flex items-center gap-2 text-slate-700">
            {icon}

            <span className="text-sm font-medium">
                {label}
            </span>
        </div>
    );
}

function HeroStat({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    return (
        <div className="rounded-lg border border-slate-100 bg-slate-50 px-3 py-3">
            <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                {label}
            </p>

            <p className="mt-1 truncate text-sm font-bold text-slate-800">
                {value}
            </p>
        </div>
    );
}

function MiniStat({
    label,
    value,
    big = false,
}: {
    label: string;

    value?:
        | string
        | number
        | null;

    big?: boolean;
}) {
    return (
        <div className="rounded-lg border border-slate-100 bg-slate-50 p-4">
            <p className="mb-1 text-xs font-bold uppercase tracking-wider text-slate-400">
                {label}
            </p>

            <p
                className={`font-bold text-slate-900 ${
                    big
                        ? 'text-xl'
                        : 'text-lg'
                }`}
            >
                {value ??
                    '—'}
            </p>
        </div>
    );
}

function DetailRow({
    label,
    value,
}: {
    label: string;
    value?: string;
}) {
    return (
        <div className="flex items-center justify-between gap-4 border-b border-slate-100 py-2.5 last:border-0">
            <span className="text-sm text-slate-500">
                {label}
            </span>

            <span className="text-right text-sm font-medium text-slate-800">
                {value ??
                    '—'}
            </span>
        </div>
    );
}

function Dot() {
    return (
        <span className="h-1 w-1 rounded-full bg-slate-300" />
    );
}

/* ================================================================
   DIMENSION DIAGRAM
================================================================ */

function DimensionDiagram({
    length,
    width,
    height,
}: {
    length?: number;
    width?: number;
    height?: number;
}) {
    return (
        <svg
            role="img"
            aria-label={`Vehicle dimensions: length ${
                length ?? '—'
            } mm, width ${
                width ?? '—'
            } mm, height ${
                height ?? '—'
            } mm`}
            style={{
                width: '100%',
                maxWidth: 500,
                height: 'auto',
            }}
            viewBox="0 0 600 150"
        >
            <rect
                fill="#f1f5f9"
                height="46"
                rx="10"
                stroke="#cbd5e1"
                strokeWidth="1.5"
                width="380"
                x="90"
                y="55"
            />

            <line
                stroke="#94a3b8"
                strokeWidth="1"
                x1="90"
                x2="470"
                y1="35"
                y2="35"
            />

            <line
                stroke="#94a3b8"
                strokeWidth="1"
                x1="90"
                x2="90"
                y1="28"
                y2="42"
            />

            <line
                stroke="#94a3b8"
                strokeWidth="1"
                x1="470"
                x2="470"
                y1="28"
                y2="42"
            />

            <text
                fill="#64748b"
                fontFamily="Inter, sans-serif"
                fontSize="12"
                fontWeight="500"
                textAnchor="middle"
                x="280"
                y="24"
            >
                {length
                    ? `${length} mm length`
                    : '— mm length'}
            </text>

            <line
                stroke="#94a3b8"
                strokeWidth="1"
                x1="490"
                x2="490"
                y1="55"
                y2="101"
            />

            <line
                stroke="#94a3b8"
                strokeWidth="1"
                x1="483"
                x2="497"
                y1="55"
                y2="55"
            />

            <line
                stroke="#94a3b8"
                strokeWidth="1"
                x1="483"
                x2="497"
                y1="101"
                y2="101"
            />

            <text
                fill="#64748b"
                fontFamily="Inter, sans-serif"
                fontSize="12"
                fontWeight="500"
                x="502"
                y="82"
            >
                {width
                    ? `${width} mm width`
                    : '— mm width'}
            </text>

            <line
                stroke="#94a3b8"
                strokeWidth="1"
                x1="60"
                x2="60"
                y1="55"
                y2="101"
            />

            <line
                stroke="#94a3b8"
                strokeWidth="1"
                x1="53"
                x2="67"
                y1="55"
                y2="55"
            />

            <line
                stroke="#94a3b8"
                strokeWidth="1"
                x1="53"
                x2="67"
                y1="101"
                y2="101"
            />

            <text
                fill="#64748b"
                fontFamily="Inter, sans-serif"
                fontSize="12"
                fontWeight="500"
                textAnchor="end"
                x="50"
                y="82"
            >
                {height
                    ? `${height} mm height`
                    : '— mm height'}
            </text>
        </svg>
    );
}

/* ================================================================
   HELPERS
================================================================ */

function getCheckState(
    available: boolean,
    count: number,
): CheckState {
    if (!available) {
        return 'unavailable';
    }

    return count > 0
        ? 'issue'
        : 'clean';
}

function getSectionCount(
    section: {
        count?:
            | number
            | null;

        records?: unknown[];
    },
) {
    return (
        section.count ??
        section.records?.length ??
        0
    );
}

function isPass(
    value?:
        | string
        | null,
) {
    if (!value) {
        return false;
    }

    return /pass|passed|valid/i.test(
        value,
    );
}

function isFail(
    value?:
        | string
        | null,
) {
    if (!value) {
        return false;
    }

    return /fail|failed/i.test(
        value,
    );
}

function hasDimensions(
    dimensions: NormalizedReport['dimensions'],
) {
    return [
        dimensions.length_mm,
        dimensions.width_mm,
        dimensions.height_mm,
    ].some(
        (value) =>
            value != null,
    );
}

function createSparklinePath(
    entries: MileagePoint[],
) {
    if (
        entries.length < 2
    ) {
        return null;
    }

    const values =
        entries.map(
            (point) =>
                point.mileage,
        );

    const min =
        Math.min(...values);

    const max =
        Math.max(...values);

    const range =
        max - min || 1;

    return entries
        .map(
            (
                point,
                index,
            ) => {
                const x =
                    (index /
                        (entries.length -
                            1)) *
                    100;

                const y =
                    90 -
                    ((point.mileage -
                        min) /
                        range) *
                        80;

                return `${x},${y}`;
            },
        )
        .join(' ');
}

/* ================================================================
   VEHICLE STORY HELPERS
================================================================ */

function buildVehicleStoryEvents(
    report: NormalizedReport,
): VehicleStoryEvent[] {
    const events: VehicleStoryEvent[] =
        [];

    /*
     * V5C
     */
    report.v5c.history.forEach(
        (item, index) => {
            if (
                !item.issue_date
            ) {
                return;
            }

            events.push({
                id: `v5c-${index}`,

                date:
                    item.issue_date,

                category:
                    'v5c',

                title:
                    'V5C / logbook issued',

                detail:
                    'A V5C document issue event was recorded.',

                tone:
                    'neutral',
            });
        },
    );

    /*
     * Keepers
     */
    (
        report.history.keepers
            .records ?? []
    ).forEach(
        (item, index) => {
            if (
                !item.change_date
            ) {
                return;
            }

            events.push({
                id: `keeper-${index}`,

                date:
                    item.change_date,

                category:
                    'keeper',

                title:
                    'Keeper change recorded',

                detail:
                    item.previous_keepers !=
                    null
                        ? `${item.previous_keepers} previous keeper${
                              item.previous_keepers ===
                              1
                                  ? ''
                                  : 's'
                          } recorded.`
                        : undefined,

                tone:
                    'neutral',
            });
        },
    );

    /*
     * Plates
     */
    (
        report.history
            .plate_changes
            .records ?? []
    ).forEach(
        (item, index) => {
            const date =
                item.transfer_date ??
                item.receipt_date;

            if (!date) {
                return;
            }

            const change =
                `${item.previous_vrm ?? 'Unknown'} → ${item.current_vrm ?? 'Unknown'}`;

            events.push({
                id: `plate-${index}`,

                date,

                category:
                    'plate',

                title:
                    'Registration plate changed',

                detail:
                    item.transfer_type
                        ? `${change} • ${item.transfer_type}`
                        : change,

                tone:
                    'neutral',
            });
        },
    );

    /*
     * MOT
     */
    report.mot.tests.forEach(
        (test, index) => {
            if (!test.date) {
                return;
            }

            const details: string[] =
                [];

            if (
                test.mileage !=
                null
            ) {
                details.push(
                    `${test.mileage.toLocaleString()} ${test.mileage_unit ?? 'mi'}`,
                );
            }

            if (
                test.defects.length >
                0
            ) {
                details.push(
                    `${test.defects.length} defect${
                        test.defects
                            .length ===
                        1
                            ? ''
                            : 's'
                    } recorded`,
                );
            }

            events.push({
                id: `mot-${index}`,

                date:
                    test.date,

                category:
                    'mot',

                title:
                    `MOT ${test.result ?? 'recorded'}`,

                detail:
                    details.length >
                    0
                        ? details.join(
                              ' • ',
                          )
                        : undefined,

                tone: isFail(
                    test.result,
                )
                    ? 'danger'
                    : isPass(
                            test.result,
                        )
                      ? 'success'
                      : 'neutral',
            });
        },
    );

    /*
     * Scrapped
     */
    if (
        report.status
            .scrapped_date
    ) {
        events.push({
            id: 'scrapped',

            date:
                report.status
                    .scrapped_date,

            category:
                'status',

            title:
                'Vehicle recorded as scrapped',

            detail:
                report.status
                    .certificate_of_destruction_issued
                    ? 'A Certificate of Destruction is recorded.'
                    : undefined,

            tone:
                'danger',
        });
    }

    return events.sort(
        (a, b) =>
            safeDateTimestamp(
                b.date,
            ) -
            safeDateTimestamp(
                a.date,
            ),
    );
}

function safeDateTimestamp(
    value:
        | string
        | null,
): number {
    if (!value) {
        return 0;
    }

    const timestamp =
        new Date(
            value,
        ).getTime();

    return Number.isNaN(
        timestamp,
    )
        ? 0
        : timestamp;
}

/* ================================================================
   MILEAGE HELPERS
================================================================ */

function findMileageAnomalies(
    points: MileagePoint[],
): MileageAnomaly[] {
    const anomalies: MileageAnomaly[] =
        [];

    for (
        let index = 1;
        index < points.length;
        index++
    ) {
        const previous =
            points[index - 1];

        const current =
            points[index];

        if (
            current.mileage <
            previous.mileage
        ) {
            anomalies.push({
                previousMileage:
                    previous.mileage,

                currentMileage:
                    current.mileage,

                previousDate:
                    previous.date,

                currentDate:
                    current.date,

                decrease:
                    previous.mileage -
                    current.mileage,
            });
        }
    }

    return anomalies;
}

/* ================================================================
   MOT / TAX HELPERS
================================================================ */

function deriveMotStatusForDisplay(
    result:
        | string
        | null
        | undefined,

    expiry:
        | string
        | null,
): string | null {
    const normalized =
        result
            ?.trim()
            .toLowerCase() ??
        '';

    if (
        normalized.includes(
            'fail',
        )
    ) {
        return 'Failed';
    }

    if (expiry) {
        const timestamp =
            new Date(
                expiry,
            ).getTime();

        if (
            !Number.isNaN(
                timestamp,
            )
        ) {
            return timestamp <
                Date.now()
                ? 'Expired'
                : 'Valid';
        }
    }

    if (result) {
        return result;
    }

    return null;
}

function getRoadStatusTone(
    status:
        | string
        | null,
):
    | 'success'
    | 'warning'
    | 'danger'
    | 'neutral' {
    if (!status) {
        return 'neutral';
    }

    const value =
        status.toLowerCase();

    if (
        value.includes(
            'valid',
        ) ||
        value.includes(
            'taxed',
        ) ||
        value.includes(
            'pass',
        )
    ) {
        return 'success';
    }

    if (
        value.includes(
            'fail',
        ) ||
        value.includes(
            'expired',
        )
    ) {
        return 'danger';
    }

    if (
        value.includes(
            'untaxed',
        ) ||
        value.includes(
            'sorn',
        )
    ) {
        return 'warning';
    }

    return 'neutral';
}

function currentTaxLabel(
    status:
        | string
        | null,
): string | null {
    if (!status) {
        return null;
    }

    return status;
}

/* ================================================================
   RECORD HELPERS
================================================================ */

function toRecords(
    records?: unknown[],
): Record<string, unknown>[] {
    return (
        records ?? []
    ).filter(
        (
            value,
        ): value is Record<
            string,
            unknown
        > =>
            typeof value ===
                'object' &&
            value !== null &&
            !Array.isArray(
                value,
            ),
    );
}

function normalizeRecordKey(
    value: string,
): string {
    return value
        .replace(
            /[^a-z0-9]/gi,
            '',
        )
        .toLowerCase();
}

function recordValue(
    record: Record<
        string,
        unknown
    >,

    aliases: string[],
): unknown {
    const entries =
        Object.entries(
            record,
        );

    for (
        const alias of aliases
    ) {
        const target =
            normalizeRecordKey(
                alias,
            );

        const entry =
            entries.find(
                ([key]) =>
                    normalizeRecordKey(
                        key,
                    ) === target,
            );

        if (entry) {
            return entry[1];
        }
    }

    return null;
}

function recordString(
    record: Record<
        string,
        unknown
    >,

    aliases: string[],
): string | null {
    const value =
        recordValue(
            record,
            aliases,
        );

    if (
        value === null ||
        value ===
            undefined ||
        value === ''
    ) {
        return null;
    }

    if (
        typeof value ===
            'string' ||
        typeof value ===
            'number'
    ) {
        return String(
            value,
        );
    }

    return null;
}

function recordNumber(
    record: Record<
        string,
        unknown
    >,

    aliases: string[],
): number | null {
    const value =
        recordValue(
            record,
            aliases,
        );

    if (
        typeof value ===
            'number' &&
        Number.isFinite(
            value,
        )
    ) {
        return value;
    }

    if (
        typeof value ===
        'string'
    ) {
        const parsed =
            Number(value);

        return Number.isFinite(
            parsed,
        )
            ? parsed
            : null;
    }

    return null;
}

function extractDamageLocations(
    record: Record<
        string,
        unknown
    >,
): string[] {
    const value =
        recordValue(
            record,
            [
                'damage_location_items',
                'damage_locations',
                'damageLocationItems',
            ],
        );

    if (
        !Array.isArray(
            value,
        )
    ) {
        const direct =
            recordString(
                record,
                [
                    'damage_location_desc',
                    'damage_location',
                ],
            );

        return direct
            ? [direct]
            : [];
    }

    return value
        .map((item) => {
            if (
                typeof item ===
                'string'
            ) {
                return item;
            }

            if (
                typeof item ===
                    'object' &&
                item !== null
            ) {
                return recordString(
                    item as Record<
                        string,
                        unknown
                    >,
                    [
                        'damage_location_desc',
                        'description',
                        'location',
                    ],
                );
            }

            return null;
        })
        .filter(
            (
                value,
            ): value is string =>
                Boolean(value),
        );
}

/* ================================================================
   FORMATTERS
================================================================ */

function humanizeKey(
    key: string,
) {
    const cleaned =
        key
            .replace(
                /_/g,
                ' ',
            )
            .replace(
                /([a-z0-9])([A-Z])/g,
                '$1 $2',
            )
            .replace(
                /\s+/g,
                ' ',
            )
            .trim();

    return cleaned
        .split(' ')
        .map(
            (part) =>
                part
                    .charAt(
                        0,
                    )
                    .toUpperCase() +
                part.slice(1),
        )
        .join(' ');
}

function formatUnknownValue(
    value: unknown,
    key?: string,
): string {
    if (
        value === null ||
        value ===
            undefined ||
        value === ''
    ) {
        return '—';
    }

    if (
        typeof value ===
        'boolean'
    ) {
        return value
            ? 'Yes'
            : 'No';
    }

    if (
        typeof value ===
            'string' &&
        key &&
        /date/i.test(key)
    ) {
        return formatDate(
            value,
        );
    }

    if (
        Array.isArray(
            value,
        )
    ) {
        if (
            value.length ===
            0
        ) {
            return '—';
        }

        return value
            .map((item) =>
                formatUnknownValue(
                    item,
                ),
            )
            .join(', ');
    }

    if (
        typeof value ===
            'object' &&
        value !== null
    ) {
        return Object.entries(
            value as Record<
                string,
                unknown
            >,
        )
            .map(
                ([
                    childKey,
                    childValue,
                ]) =>
                    `${humanizeKey(childKey)}: ${formatUnknownValue(
                        childValue,
                        childKey,
                    )}`,
            )
            .join(' · ');
    }

    return String(
        value,
    );
}

function formatMoney(
    value: number,
): string {
    return new Intl.NumberFormat(
        'en-GB',
        {
            style:
                'currency',

            currency:
                'GBP',

            maximumFractionDigits:
                0,
        },
    ).format(value);
}

function formatDate(
    value: string,
): string {
    const date =
        new Date(
            value,
        );

    if (
        Number.isNaN(
            date.getTime(),
        )
    ) {
        return value;
    }

    return new Intl.DateTimeFormat(
        'en-GB',
        {
            day:
                '2-digit',

            month:
                'short',

            year:
                'numeric',
        },
    ).format(date);
}

function formatYear(
    value: string,
): string {
    const date =
        new Date(
            value,
        );

    if (
        Number.isNaN(
            date.getTime(),
        )
    ) {
        return '';
    }

    return String(
        date.getFullYear(),
    );
}