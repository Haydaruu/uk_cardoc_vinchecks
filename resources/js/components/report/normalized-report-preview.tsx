import {
    AlertTriangle,
    BadgePoundSterling,
    Car,
    CheckCircle2,
    CircleHelp,
    FileText,
    Fuel,
    Gauge,
    History,
    Receipt,
    RotateCcw,
    ShieldAlert,
    ShieldCheck,
    Users,
    Wrench,
} from 'lucide-react';
import type { NormalizedReport } from "@/types/normalized-report";



type Props = {
    report: NormalizedReport;
};

export default function NormalizedReportPreview({ report ,}: Props) {
    const {
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

    const latestMileage =
        mot.mileage_history?.length > 0
            ? [...mot.mileage_history]
                  .filter(
                      (item) =>
                          item.mileage != null,
                  )
                  .sort(
                      (a, b) =>
                          new Date(
                              b.date ?? 0,
                          ).getTime() -
                          new Date(
                              a.date ?? 0,
                          ).getTime(),
                  )[0]
            : null;

    return ( 
        <div className="space-y-6">
            {/* HERO */}
            <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div className="p-6 md:p-8">
                    <div className="flex flex-col justify-between gap-6 md:flex-row md:items-start">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">
                                Vehicle Report
                            </p>

                            <h2 className="mt-2 text-3xl font-bold text-slate-900">
                                {vehicle.make ??
                                    'Unknown'}{' '}
                                {vehicle.model ?? ''}
                            </h2>

                            <p className="mt-1 text-lg font-semibold text-slate-600">
                                {vehicle.vrm ??
                                    'Unknown VRM'}
                            </p>
                        </div>

                        <div className="rounded-lg bg-slate-900 px-4 py-3 text-white">
                            <p className="text-xs uppercase text-slate-300">
                                VIN
                            </p>

                            <p className="mt-1 font-mono text-sm font-semibold">
                                {vehicle.vin ??
                                    'Not available'}
                            </p>
                        </div>
                    </div>

                    <div className="mt-8 grid gap-5 border-t border-slate-200 pt-6 sm:grid-cols-2 lg:grid-cols-4">
                        <Info
                            label="Year"
                            value={vehicle.year}
                        />

                        <Info
                            label="Fuel"
                            value={
                                vehicle.fuel_type
                            }
                        />

                        <Info
                            label="Transmission"
                            value={
                                vehicle.transmission
                            }
                        />

                        <Info
                            label="Colour"
                            value={vehicle.colour}
                        />

                        <Info
                            label="Body"
                            value={
                                vehicle.body_type
                            }
                        />

                        <Info
                            label="Engine"
                            value={
                                vehicle.engine_capacity_cc
                                    ? `${vehicle.engine_capacity_cc.toLocaleString()} cc`
                                    : null
                            }
                        />

                        <Info
                            label="Latest Mileage"
                            value={
                                latestMileage?.mileage !=
                                null
                                    ? `${latestMileage.mileage.toLocaleString()} mi`
                                    : null
                            }
                        />

                        <Info
                            label="Euro Standard"
                            value={
                                specifications.euro_standard
                            }
                        />
                    </div>
                </div>
            </section>

            {/* HISTORY STATUS */}
            <Section
                title="Vehicle History"
                description="Recorded history checks for this vehicle."
                icon={<ShieldCheck size={20} />}
            >
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <HistoryStatus
                        label="Finance"
                        available={
                            history.finance
                                .available
                        }
                        count={
                            history.finance.count
                        }
                    />

                    <HistoryStatus
                        label="Stolen"
                        available={
                            history.stolen
                                .available
                        }
                        count={
                            history.stolen.count
                        }
                    />

                    <HistoryStatus
                        label="Write-off"
                        available={
                            history.write_off
                                .available
                        }
                        count={
                            history.write_off.count
                        }
                    />

                    <HistoryStatus
                        label="High Risk"
                        available={
                            history.high_risk
                                .available
                        }
                        count={
                            history.high_risk.count
                        }
                    />
                </div>
            </Section>

            {/* VEHICLE STATUS */}
            <Section
                title="Vehicle Status"
                description="Registration and vehicle lifecycle status."
                icon={<Car size={20} />}
            >
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <BooleanCard
                        label="Scrapped"
                        value={status.scrapped}
                    />

                    <BooleanCard
                        label="Certificate of Destruction"
                        value={
                            status.certificate_of_destruction_issued
                        }
                    />

                    <BooleanCard
                        label="Imported"
                        value={status.imported}
                    />

                    <BooleanCard
                        label="Exported"
                        value={status.exported}
                    />
                </div>

                {status.scrapped_date && (
                    <p className="mt-4 text-sm text-slate-500">
                        Scrapped date:{' '}
                        <strong className="text-slate-900">
                            {formatDate(
                                status.scrapped_date,
                            )}
                        </strong>
                    </p>
                )}
            </Section>

            {/* SALVAGE */}
            <Section
                title="Salvage Auction History"
                description="Recorded salvage auction information."
                icon={<ShieldAlert size={20} />}
            >
                {!salvage.available ? (
                    <Unavailable />
                ) : salvage.record_found ? (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 p-5">
                        <div className="flex items-center gap-3">
                            <AlertTriangle className="text-amber-700" />

                            <div>
                                <p className="font-semibold text-amber-900">
                                    Salvage record
                                    found
                                </p>

                                <p className="text-sm text-amber-700">
                                    {
                                        salvage
                                            .records
                                            .length
                                    }{' '}
                                    recorded auction
                                    entr
                                    {salvage.records
                                        .length === 1
                                        ? 'y'
                                        : 'ies'}
                                    .
                                </p>
                            </div>
                        </div>
                    </div>
                ) : (
                    <ClearMessage text="No salvage auction record found." />
                )}
            </Section>

            {/* V5C */}
            <Section
                title="V5C / Logbook"
                description="Recorded V5C document history."
                icon={<FileText size={20} />}
            >
                <div className="grid gap-4 md:grid-cols-3">
                    <MetricCard
                        label="Documents Recorded"
                        value={
                            v5c.count != null
                                ? String(
                                      v5c.count,
                                  )
                                : '—'
                        }
                    />

                    <MetricCard
                        label="Latest Issue"
                        value={
                            v5c.latest_issue_date
                                ? formatDate(
                                      v5c.latest_issue_date,
                                  )
                                : 'Not available'
                        }
                    />

                    <MetricCard
                        label="Serial Verification"
                        value={
                            v5c.verification_available
                                ? v5c.serial_verified
                                    ? 'Verified'
                                    : 'Not verified'
                                : 'Not available'
                        }
                    />
                </div>

                {v5c.history.length > 0 && (
                    <div className="mt-5 border-t border-slate-200 pt-5">
                        <p className="mb-3 text-sm font-semibold text-slate-700">
                            Issue History
                        </p>

                        <div className="flex flex-wrap gap-2">
                            {v5c.history.map(
                                (item, index) => (
                                    <span
                                        key={index}
                                        className="rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700"
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
                    </div>
                )}
            </Section>

            {/* VALUATION */}
            <Section
                title="Market Valuation"
                description="Estimated current vehicle market values."
                icon={
                    <BadgePoundSterling
                        size={20}
                    />
                }
            >
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <MoneyCard
                        label="Retail"
                        value={
                            valuation.retail
                        }
                    />

                    <MoneyCard
                        label="Private"
                        value={
                            valuation.private
                        }
                    />

                    <MoneyCard
                        label="Trade"
                        value={
                            valuation.trade
                        }
                    />

                    <MoneyCard
                        label="Part Exchange"
                        value={
                            valuation.trade_in
                        }
                    />
                </div>
            </Section>

            {/* SPECS */}
            <Section
                title="Vehicle Specifications"
                description="Performance, efficiency and physical specifications."
                icon={<Gauge size={20} />}
            >
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard
                        label="Power"
                        value={
                            specifications.power_bhp !=
                            null
                                ? `${specifications.power_bhp} bhp`
                                : 'Not available'
                        }
                    />

                    <MetricCard
                        label="Torque"
                        value={
                            specifications.torque_nm !=
                            null
                                ? `${specifications.torque_nm} Nm`
                                : 'Not available'
                        }
                    />

                    <MetricCard
                        label="Combined MPG"
                        value={
                            specifications.combined_mpg !=
                            null
                                ? `${specifications.combined_mpg} mpg`
                                : 'Not available'
                        }
                    />

                    <MetricCard
                        label="CO₂"
                        value={
                            specifications.co2_gkm !=
                            null
                                ? `${specifications.co2_gkm} g/km`
                                : 'Not available'
                        }
                    />

                    <MetricCard
                        label="Euro Standard"
                        value={
                            specifications.euro_standard ??
                            'Not available'
                        }
                    />

                    <MetricCard
                        label="Top Speed"
                        value={
                            specifications.top_speed_mph !=
                            null
                                ? `${specifications.top_speed_mph} mph`
                                : 'Not available'
                        }
                    />

                    <MetricCard
                        label="0–60 mph"
                        value={
                            specifications.zero_to_sixty_mph !=
                            null
                                ? `${specifications.zero_to_sixty_mph}s`
                                : 'Not available'
                        }
                    />

                    <MetricCard
                        label="Insurance Group"
                        value={
                            specifications.insurance_group ??
                            'Not available'
                        }
                    />
                </div>

                <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard
                        label="Length"
                        value={
                            dimensions.length_mm !=
                            null
                                ? `${dimensions.length_mm} mm`
                                : 'Not available'
                        }
                    />

                    <MetricCard
                        label="Width"
                        value={
                            dimensions.width_mm !=
                            null
                                ? `${dimensions.width_mm} mm`
                                : 'Not available'
                        }
                    />

                    <MetricCard
                        label="Height"
                        value={
                            dimensions.height_mm !=
                            null
                                ? `${dimensions.height_mm} mm`
                                : 'Not available'
                        }
                    />

                    <MetricCard
                        label="Kerb Weight"
                        value={
                            dimensions.kerb_weight_kg !=
                            null
                                ? `${dimensions.kerb_weight_kg} kg`
                                : 'Not available'
                        }
                    />
                </div>
            </Section>

            {/* EMISSIONS */}
            <Section
                title="ULEZ / Clean Air Assessment"
                description="Emissions-based assessment using available vehicle data."
                icon={<Fuel size={20} />}
            >
                <div className="rounded-lg border border-slate-200 p-5">
                    <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                        <div>
                            <p className="font-semibold text-slate-900">
                                {emissions_compliance.meets_minimum_standard ===
                                true
                                    ? 'Meets minimum emissions standard'
                                    : emissions_compliance.meets_minimum_standard ===
                                        false
                                      ? 'May not meet minimum emissions standard'
                                      : 'Unable to determine'}
                            </p>

                            <p className="mt-1 text-sm text-slate-500">
                                {emissions_compliance.reason ??
                                    'No assessment reason available.'}
                            </p>
                        </div>

                        <span className="w-fit rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700">
                            {emissions_compliance.assessment_type ===
                            'derived'
                                ? 'Derived assessment'
                                : 'Provider assessment'}
                        </span>
                    </div>

                    {!emissions_compliance.officially_verified && (
                        <p className="mt-4 border-t border-slate-200 pt-4 text-xs leading-relaxed text-slate-500">
                            This is not an
                            authoritative
                            vehicle-specific ULEZ
                            or Clean Air Zone
                            approval. Local
                            exemptions and
                            classifications may
                            apply.
                        </p>
                    )}
                </div>
            </Section>

            {/* RECALL */}
            <Section
                title="Recall Check"
                description="Manufacturer and safety recall information."
                icon={<RotateCcw size={20} />}
            >
                {!recalls.available ? (
                    <Unavailable />
                ) : (
                    <div className="grid gap-4 md:grid-cols-3">
                        <MetricCard
                            label="Status"
                            value={
                                recalls.status ??
                                'Unknown'
                            }
                        />

                        <MetricCard
                            label="Recall Records"
                            value={String(
                                recalls.count ??
                                    0,
                            )}
                        />

                        <MetricCard
                            label="Source"
                            value={
                                recalls.source ??
                                'Not available'
                            }
                        />
                    </div>
                )}
            </Section>

            {/* RUNNING COST */}
            <Section
                title="Estimated Running Costs"
                description="Available tax, insurance and fuel information."
                icon={<Receipt size={20} />}
            >
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <MoneyCard
                        label="Annual Road Tax"
                        value={
                            running_costs
                                .road_tax
                                .available
                                ? running_costs
                                      .road_tax
                                      .annual ??
                                  null
                                : null
                        }
                    />

                    <MoneyCard
                        label="First Year Tax"
                        value={
                            running_costs
                                .road_tax
                                .available
                                ? running_costs
                                      .road_tax
                                      .first_year ??
                                  null
                                : null
                        }
                    />

                    <MoneyCard
                        label="Insurance — Age 30"
                        value={
                            running_costs
                                .insurance
                                .available
                                ? running_costs
                                      .insurance
                                      .age_30 ??
                                  null
                                : null
                        }
                    />

                    <MetricCard
                        label="Combined Economy"
                        value={
                            running_costs.fuel
                                .available &&
                            running_costs.fuel
                                .combined_mpg !=
                                null
                                ? `${running_costs.fuel.combined_mpg} mpg`
                                : 'Not available'
                        }
                    />
                </div>

                {running_costs.insurance
                    .available && (
                    <p className="mt-4 text-xs text-slate-500">
                        Insurance figures are
                        estimates and are not
                        insurance quotations.
                    </p>
                )}
            </Section>

            {/* KEEPERS + PLATES */}
            <Section
                title="Keeper & Registration History"
                description="Recorded keeper and registration plate changes."
                icon={<Users size={20} />}
            >
                <div className="grid gap-6 lg:grid-cols-2">
                    <div>
                        <h4 className="font-semibold text-slate-900">
                            Keeper Changes
                        </h4>

                        <div className="mt-3 space-y-3">
                            {!history.keepers
                                .available ? (
                                <Unavailable />
                            ) : history.keepers
                                  .records
                                  ?.length ? (
                                history.keepers.records.map(
                                    (
                                        item,
                                        index,
                                    ) => (
                                        <TimelineCard
                                            key={
                                                index
                                            }
                                            title={`${item.previous_keepers ?? '—'} previous keeper(s)`}
                                            date={
                                                item.change_date
                                            }
                                        />
                                    ),
                                )
                            ) : (
                                <ClearMessage text="No keeper changes recorded." />
                            )}
                        </div>
                    </div>

                    <div>
                        <h4 className="font-semibold text-slate-900">
                            Plate Changes
                        </h4>

                        <div className="mt-3 space-y-3">
                            {!history
                                .plate_changes
                                .available ? (
                                <Unavailable />
                            ) : history
                                  .plate_changes
                                  .records
                                  ?.length ? (
                                history.plate_changes.records.map(
                                    (
                                        item,
                                        index,
                                    ) => (
                                        <TimelineCard
                                            key={
                                                index
                                            }
                                            title={`${item.previous_vrm ?? 'Unknown'} → ${item.current_vrm ?? 'Unknown'}`}
                                            date={
                                                item.transfer_date
                                            }
                                            description={
                                                item.transfer_type ??
                                                undefined
                                            }
                                        />
                                    ),
                                )
                            ) : (
                                <ClearMessage text="No plate changes recorded." />
                            )}
                        </div>
                    </div>
                </div>
            </Section>

            {/* MOT */}
            <Section
                title="MOT & Mileage History"
                description="Recorded MOT tests, mileage and defects."
                icon={<Wrench size={20} />}
            >
                {!mot.service_available ? (
                    <Unavailable />
                ) : mot.tests.length > 0 ? (
                    <div className="space-y-4">
                        {mot.tests.map(
                            (test, index) => (
                                <div
                                    key={index}
                                    className="rounded-lg border border-slate-200 p-5"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-4">
                                        <div>
                                            <p className="font-semibold text-slate-900">
                                                {test.result ??
                                                    'Unknown result'}
                                            </p>

                                            <p className="mt-1 text-sm text-slate-500">
                                                {test.date
                                                    ? formatDate(
                                                          test.date,
                                                      )
                                                    : 'Date unavailable'}
                                            </p>
                                        </div>

                                        <div className="text-right">
                                            <p className="font-semibold text-slate-900">
                                                {test.mileage !=
                                                null
                                                    ? `${test.mileage.toLocaleString()} ${test.mileage_unit ?? 'mi'}`
                                                    : 'Mileage unavailable'}
                                            </p>

                                            {test.expiry_date && (
                                                <p className="mt-1 text-xs text-slate-500">
                                                    Expires{' '}
                                                    {formatDate(
                                                        test.expiry_date,
                                                    )}
                                                </p>
                                            )}
                                        </div>
                                    </div>

                                    {test.defects
                                        ?.length >
                                        0 && (
                                        <div className="mt-4 border-t border-slate-200 pt-4">
                                            <p className="mb-2 text-sm font-semibold text-slate-700">
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
                                                            className="rounded bg-slate-50 p-3 text-sm"
                                                        >
                                                            <span className="font-semibold">
                                                                {defect.type ??
                                                                    'Note'}
                                                                :
                                                            </span>{' '}
                                                            {defect.description ??
                                                                'No description'}
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ),
                        )}
                    </div>
                ) : (
                    <ClearMessage text="No MOT tests recorded." />
                )}
            </Section>
        </div>
    );
}

function Info({ 
    label, 
    value
}: {
    label: string;
    value: string | number | null;
}) {
    return (
        <div>
            <p className="text-xs uppercase text-slate-500">
                {label}
            </p>

            <p className="mt-1 font-semibold">
                {value ?? 'Not available'}
            </p>
        </div>
    );
}

function Section({
    title,
    description,
    icon,
    children,
}: {
    title: string;
    description: string;
    icon: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <section className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <div className="mb-6 flex items-start gap-3">
                <div className="rounded-lg bg-slate-100 p-2 text-slate-700">
                    {icon}
                </div>

                <div>
                    <h3 className="text-lg font-bold text-slate-900">
                        {title}
                    </h3>

                    <p className="mt-1 text-sm text-slate-500">
                        {description}
                    </p>
                </div>
            </div>

            {children}
        </section>
    );
}

function HistoryStatus({
    label,
    available,
    count,
}: {
    label: string;
    available: boolean;
    count?: number | null;
}) {
    if (!available) {
        return (
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                <p className="font-semibold text-slate-900">
                    {label}
                </p>

                <p className="mt-2 text-sm text-slate-500">
                    Data unavailable
                </p>
            </div>
        );
    }

    const hasRecord =
        (count ?? 0) > 0;

    return (
        <div className="rounded-lg border border-slate-200 bg-white p-4">
            <p className="font-semibold text-slate-900">
                {label}
            </p>

            <div className="mt-2 flex items-center gap-2">
                {hasRecord ? (
                    <AlertTriangle
                        size={16}
                        className="text-red-600"
                    />
                ) : (
                    <CheckCircle2
                        size={16}
                        className="text-emerald-600"
                    />
                )}

                <p
                    className={`text-sm font-semibold ${
                        hasRecord
                            ? 'text-red-600'
                            : 'text-emerald-600'
                    }`}
                >
                    {hasRecord
                        ? `${count} record${count === 1 ? '' : 's'} found`
                        : 'No record found'}
                </p>
            </div>
        </div>
    );
}

function BooleanCard({
    label,
    value,
}: {
    label: string;
    value: boolean | null;
}) {
    return (
        <div className="rounded-lg border border-slate-200 p-4">
            <p className="text-sm text-slate-500">
                {label}
            </p>

            <p className="mt-2 font-semibold text-slate-900">
                {value === null
                    ? 'Not available'
                    : value
                      ? 'Yes'
                      : 'No'}
            </p>
        </div>
    );
}

function MetricCard({
    label,
    value,
}: {
    label: string;
    value:
        | string
        | number
        | null
        | undefined;
}) {
    return (
        <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                {label}
            </p>

            <p className="mt-2 text-lg font-bold text-slate-900">
                {value ?? 'Not available'}
            </p>
        </div>
    );
}

function MoneyCard({
    label,
    value,
}: {
    label: string;
    value: number | null | undefined;
}) {
    return (
        <MetricCard
            label={label}
            value={
                value != null
                    ? formatMoney(value)
                    : 'Not available'
            }
        />
    );
}

function TimelineCard({
    title,
    date,
    description,
}: {
    title: string;
    date?: string | null;
    description?: string;
}) {
    return (
        <div className="rounded-lg border border-slate-200 p-4">
            <div className="flex items-start gap-3">
                <History
                    size={17}
                    className="mt-0.5 text-slate-400"
                />

                <div>
                    <p className="font-semibold text-slate-900">
                        {title}
                    </p>

                    {date && (
                        <p className="mt-1 text-sm text-slate-500">
                            {formatDate(date)}
                        </p>
                    )}

                    {description && (
                        <p className="mt-1 text-xs text-slate-500">
                            {description}
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}

function ClearMessage({
    text,
}: {
    text: string;
}) {
    return (
        <div className="flex items-center gap-3 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">
            <CheckCircle2 size={20} />

            <p className="text-sm font-semibold">
                {text}
            </p>
        </div>
    );
}

function Unavailable() {
    return (
        <div className="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-slate-500">
            <CircleHelp size={20} />

            <p className="text-sm">
                This data is not available
                from the selected provider.
            </p>
        </div>
    );
}

function formatMoney(
    value: number,
): string {
    return new Intl.NumberFormat(
        'en-GB',
        {
            style: 'currency',
            currency: 'GBP',
            maximumFractionDigits: 0,
        },
    ).format(value);
}

function formatDate(
    value: string,
): string {
    const date = new Date(value);

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
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        },
    ).format(date);
}