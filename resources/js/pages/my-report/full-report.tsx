import { Head } from '@inertiajs/react';
import {
    ShieldCheck, AlertTriangle, Camera, Car, CreditCard, Gauge,
    FileCheck, ShieldAlert, CarFront, KeyRound, DollarSign, Palette,
    Download, Share2, CheckCircle2, Fuel, Settings, Users, History,
    ArrowRight,
} from 'lucide-react';
import BaseLayout from '@/layouts/base-layout';

type FullReportProps = {
    report: {
        id: number;
        report_type: 'basic' | 'premium';
        generated_at?: string;
        data: {
            registrationNumber: string;
            make: string;
            model: string;
            colour: string;
            fuelType: string;
            engineCapacity: number;
            yearOfManufacture: number;
            tax?: { taxStatus: string };
            mot?: { motStatus: string; motDueDate: string; days?: number };

            VehicleRegistration?: {
                Vin?: string;
                VehicleClass?: string;
                DoorPlanLiteral?: string;
                SeatingCapacity?: number;
                DateFirstRegisteredUk?: string;
                Transmission?: string;
                GearCount?: number;
            };
            Dimensions?: {
                WheelBase?: number;
                CarLength?: number;
                Width?: number;
                Height?: number;
                GrossVehicleWeight?: number;
            };
            VehicleHistory?: {
                stolenRecord?: boolean;
                financeRecord?: boolean;
                writeOffRecord?: boolean;
                stolen?: { forcename: string; creationdate: string; status: string }[];
                finance?: { agreementtype: string; dateofagreement: string; agreementterminmonths: string; financecompany: string }[];
                writeoff?: { status: string; causeofdamage: string; lossdate: string; insurername: string; damagelocations: string[] }[];
                NumberOfPreviousKeepers?: number;
                KeeperChangesList?: { DateOfTransaction: string; NumberOfPreviousKeepers: number }[];
                PlateChangeList?: { PreviousVRM: string; CurrentVRM: string; DateOfTransaction: string }[];
                V5CCertificateList?: { CertificateDate: string }[];
            };

            motHistorySummary?: { totalTests: number; passedTests: number; failedTests: number };
            motHistory?: {
                motTestNumber: string;
                completedDate: string;
                testResult: 'PASSED' | 'FAILED';
                odometerValue?: string;
                defects?: { text: string; type: string; dangerous: boolean }[];
            }[];

            summary?: {
                lastRecordedMileage: string;
                averageMileage: number;
                averageMileageStatus: string;
                mileageIssues: 'Yes' | 'No';
                mileageIssueDescription?: string;
            };

            VehicleImages?: {
                ImageDetailsList: { ImageUrl: string; ViewPoint: string }[];
                ImageDetailsCount: number;
            };

            ValuationList?: {
                DealerForecourt: string;
                TradeRetail: string;
                PrivateClean: string;
                Auction: string;
            };

            ColourChangeDetails?: {
                CurrentColour?: string;
                NumberOfPreviousColours?: number;
                OriginalColour?: string;
            };
            ColourChangeList?: { PreviousColour: string; DateOfChange: string }[] | null;
        };
    };
};

export default function FullReport({ report }: FullReportProps) {
    const { data } = report;

    const hasHistoryData = !!data.VehicleHistory;
    const hasImages = (data.VehicleImages?.ImageDetailsCount ?? 0) > 0;
    const hasValuation = !!data.ValuationList;
    const hasMotHistory = !!data.motHistory?.length;
    const hasDimensions = !!data.Dimensions;

    const hasFinance = !!data.VehicleHistory?.financeRecord;
    const hasStolen = !!data.VehicleHistory?.stolenRecord;
    const hasWriteOff = !!data.VehicleHistory?.writeOffRecord;
    const hasMileageIssue = data.summary?.mileageIssues === 'Yes';

    const sortedMotDesc = [...(data.motHistory ?? [])].sort(
        (a, b) => new Date(b.completedDate).getTime() - new Date(a.completedDate).getTime(),
    );
    const failedMotTests = sortedMotDesc.filter((t) => t.testResult === 'FAILED');
    const latestFailedTest = failedMotTests[0];

    const anyIssue = hasFinance || hasStolen || hasWriteOff || hasMileageIssue || failedMotTests.length > 0;

    const motDaysPercent = data.mot?.days != null ? Math.min(100, Math.max(0, (data.mot.days / 365) * 100)) : 0;
    const motPassPercent = data.motHistorySummary
        ? (data.motHistorySummary.passedTests / Math.max(1, data.motHistorySummary.totalTests)) * 100
        : 0;
    const earliestMotYear = sortedMotDesc.length
        ? new Date(sortedMotDesc[sortedMotDesc.length - 1].completedDate).getFullYear()
        : null;

    // Owner history: gabung keeper changes + plate changes, urutkan terbaru dulu
    type TimelineEntry =
        | { type: 'keeper'; date: string; keepers: number }
        | { type: 'plate'; date: string; from: string; to: string };

    const timeline: TimelineEntry[] = [
        ...(data.VehicleHistory?.KeeperChangesList ?? []).map((k): TimelineEntry => ({
            type: 'keeper',
            date: k.DateOfTransaction,
            keepers: k.NumberOfPreviousKeepers,
        })),
        ...(data.VehicleHistory?.PlateChangeList ?? []).map((p): TimelineEntry => ({
            type: 'plate',
            date: p.DateOfTransaction,
            from: p.PreviousVRM,
            to: p.CurrentVRM,
        })),
    ].sort((a, b) => new Date(b.date).getTime() - new Date(a.date).getTime());

    // Sparkline mileage dari motHistory (urut naik berdasarkan tanggal)
    const mileagePoints = [...(data.motHistory ?? [])]
        .filter((t) => t.odometerValue)
        .sort((a, b) => new Date(a.completedDate).getTime() - new Date(b.completedDate).getTime())
        .map((t) => ({ year: new Date(t.completedDate).getFullYear(), value: Number(t.odometerValue) }));

    const sparklinePath = (() => {
        if (mileagePoints.length < 2) return null;
        const values = mileagePoints.map((p) => p.value);
        const min = Math.min(...values);
        const max = Math.max(...values);
        const range = max - min || 1;
        return mileagePoints
            .map((p, i) => {
                const x = (i / (mileagePoints.length - 1)) * 100;
                const y = 90 - ((p.value - min) / range) * 80;
                return `${x},${y}`;
            })
            .join(' ');
    })();

    const validatedDate = report.generated_at
        ? new Date(report.generated_at)
        : new Date();

    return (
        <>
            <Head title={`${data.make} ${data.model} — Full Report`} />

            <main className="mx-auto max-w-[1200px] px-6 py-10 lg:px-0">
                {/* Hero Header */}
                <section className="mb-8">
                    <div className="flex flex-col items-start gap-8 rounded-lg border border-outline-variant bg-white p-6 shadow-sm md:flex-row">
                        <div className="aspect-video w-full overflow-hidden rounded-lg border border-slate-200 bg-slate-100 md:w-1/3">
                            {hasImages ? (
                                <img
                                    src={data.VehicleImages!.ImageDetailsList[0].ImageUrl}
                                    alt={`${data.make} ${data.model}`}
                                    className="h-full w-full object-cover"
                                />
                            ) : (
                                <div className="flex h-full w-full items-center justify-center">
                                    <Car className="size-10 text-slate-300" />
                                </div>
                            )}
                        </div>

                        <div className="w-full flex-1">
                            <div className="mb-4 flex items-start justify-between">
                                <div>
                                    <div className="mb-3 flex flex-wrap items-center gap-3">
                                        <span className="rounded border border-emerald-200 bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800">
                                            VALIDATED {validatedDate.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }).toUpperCase()}
                                        </span>
                                        <span className="font-label-sm rounded-full bg-surface-container px-3 py-1 uppercase text-on-primary-container">
                                            VRM: {data.registrationNumber}
                                        </span>
                                    </div>
                                    <h1 className="font-h1 mb-2 text-primary">{data.make} {data.model}</h1>
                                    <p className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-500">
                                        <span>Year: {data.yearOfManufacture}</span>
                                        <span className="h-1 w-1 rounded-full bg-slate-300" />
                                        {data.VehicleRegistration?.DateFirstRegisteredUk && (
                                            <>
                                                <span>First Reg: {data.VehicleRegistration.DateFirstRegisteredUk.slice(0, 10)}</span>
                                                <span className="h-1 w-1 rounded-full bg-slate-300" />
                                            </>
                                        )}
                                        <span>Engine: {data.engineCapacity}cc</span>
                                        {data.VehicleRegistration?.Vin && (
                                            <>
                                                <span className="h-1 w-1 rounded-full bg-slate-300" />
                                                <span className="font-mono text-slate-600">VIN: {data.VehicleRegistration.Vin}</span>
                                            </>
                                        )}
                                    </p>
                                </div>

                                <div className="hidden shrink-0 sm:flex sm:flex-col sm:items-end">
                                    {hasHistoryData && (
                                        anyIssue ? (
                                            <div className="flex items-center gap-2 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-xs font-bold uppercase tracking-wide text-red-700 shadow-sm">
                                                <AlertTriangle className="size-4" /> Issues Found
                                            </div>
                                        ) : (
                                            <div className="flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-xs font-bold uppercase tracking-wide text-emerald-700 shadow-sm">
                                                <ShieldCheck className="size-4" /> Clean Report
                                            </div>
                                        )
                                    )}
                                </div>
                            </div>

                            <div className="mt-6 flex flex-wrap gap-3 border-t border-slate-100 pt-6">
                                <Chip icon={<Fuel className="size-[18px] text-slate-500" />} label={data.fuelType} />
                                {data.VehicleRegistration?.Transmission && (
                                    <Chip
                                        icon={<Settings className="size-[18px] text-slate-500" />}
                                        label={
                                            data.VehicleRegistration.GearCount
                                                ? `${data.VehicleRegistration.Transmission}, ${data.VehicleRegistration.GearCount} gears`
                                                : data.VehicleRegistration.Transmission
                                        }
                                    />
                                )}
                            </div>
                        </div>
                    </div>
                </section>

                {/* Bento Grid */}
                <div className="grid grid-cols-1 items-start gap-6 md:grid-cols-12">
                    {/* Vehicle Specifications */}
                    <SectionCard span="md:col-span-8" icon={<Car className="size-5" />} title="Vehicle Specifications">
                        <div className="p-6">
                            <div className="mb-6 flex items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-slate-100 text-primary-container">
                                    <Car className="size-6" />
                                </div>
                                <div>
                                    <p className="text-lg font-bold text-slate-900">{data.make} {data.model}</p>
                                    <p className="text-sm text-slate-500">
                                        {[data.VehicleRegistration?.DoorPlanLiteral, data.colour?.toUpperCase()].filter(Boolean).join(' · ')}
                                    </p>
                                </div>
                            </div>

                            <div className="mb-6 flex flex-wrap gap-x-8 gap-y-3 border-y border-slate-100 py-4">
                                <SpecInline icon={<Fuel className="size-4 text-slate-400" />} label={data.fuelType} />
                                {data.VehicleRegistration?.Transmission && (
                                    <SpecInline
                                        icon={<Settings className="size-4 text-slate-400" />}
                                        label={
                                            data.VehicleRegistration.GearCount
                                                ? `${data.VehicleRegistration.Transmission}, ${data.VehicleRegistration.GearCount} gears`
                                                : data.VehicleRegistration.Transmission
                                        }
                                    />
                                )}
                                {data.VehicleRegistration?.SeatingCapacity && (
                                    <SpecInline icon={<Users className="size-4 text-slate-400" />} label={`${data.VehicleRegistration.SeatingCapacity} seats`} />
                                )}
                            </div>

                            {hasDimensions ? (
                                <>
                                    <p className="mb-4 text-xs font-bold uppercase tracking-wider text-slate-400">Dimensions</p>
                                    <div className="mb-6 flex justify-center rounded-lg border border-slate-100 bg-slate-50 p-6">
                                        <DimensionDiagram
                                            length={data.Dimensions!.CarLength}
                                            width={data.Dimensions!.Width}
                                            height={data.Dimensions!.Height}
                                        />
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <MiniStat label="Wheelbase" value={data.Dimensions!.WheelBase ? `${data.Dimensions!.WheelBase} mm` : '—'} />
                                        <MiniStat label="Gross weight" value={data.Dimensions!.GrossVehicleWeight ? `${data.Dimensions!.GrossVehicleWeight} kg` : '—'} />
                                    </div>
                                </>
                            ) : (
                                <PendingAccessCard label="Dimensions" />
                            )}
                        </div>
                    </SectionCard>

                    {/* Finance + Stolen (sidebar) */}
                    <div className="flex flex-col gap-6 md:col-span-4">
                        <StatusCard
                            icon={<CreditCard className="size-[18px]" />}
                            title="Finance Status"
                            state={!hasHistoryData ? 'pending' : hasFinance ? 'issue' : 'clean'}
                        >
                            {!hasHistoryData ? (
                                <PendingAccessCard label="Finance check" />
                            ) : hasFinance ? (
                                <>
                                    <p className="mb-4 font-bold text-red-700">Finance Recorded</p>
                                    <div className="space-y-3 text-sm">
                                        <DetailRow label="Agreement" value={data.VehicleHistory!.finance?.[0]?.agreementtype} />
                                        <DetailRow label="Date" value={data.VehicleHistory!.finance?.[0]?.dateofagreement?.slice(0, 10)} />
                                        <DetailRow label="Term" value={`${data.VehicleHistory!.finance?.[0]?.agreementterminmonths} months`} />
                                        <DetailRow label="Company" value={data.VehicleHistory!.finance?.[0]?.financecompany} last />
                                    </div>
                                </>
                            ) : (
                                <div className="flex items-center gap-3">
                                    <CheckCircle2 className="size-5 shrink-0 text-emerald-500" />
                                    <div>
                                        <p className="font-bold text-emerald-700">No Finance Recorded</p>
                                        <p className="text-xs text-slate-500">Checked against HPI and Experian.</p>
                                    </div>
                                </div>
                            )}
                        </StatusCard>

                        <StatusCard
                            icon={<ShieldAlert className="size-[18px]" />}
                            title="Stolen Records"
                            state={!hasHistoryData ? 'pending' : hasStolen ? 'issue' : 'clean'}
                        >
                            {!hasHistoryData ? (
                                <PendingAccessCard label="Stolen record check" />
                            ) : hasStolen ? (
                                <>
                                    <p className="mb-4 font-bold text-red-700">Recorded as Stolen</p>
                                    <div className="space-y-3 text-sm">
                                        <DetailRow label="Force" value={data.VehicleHistory!.stolen?.[0]?.forcename} />
                                        <DetailRow label="Date" value={data.VehicleHistory!.stolen?.[0]?.creationdate?.slice(0, 10)} />
                                        <DetailRow label="Status" value={data.VehicleHistory!.stolen?.[0]?.status} last />
                                    </div>
                                </>
                            ) : (
                                <div className="flex items-center gap-3">
                                    <CheckCircle2 className="size-5 shrink-0 text-emerald-500" />
                                    <div>
                                        <p className="font-bold text-emerald-700">Not Recorded as Stolen</p>
                                        <p className="text-xs text-slate-500">POLICEDATA CHECK OK</p>
                                    </div>
                                </div>
                            )}
                        </StatusCard>
                    </div>

                    {/* MOT Status */}
                    <SectionCard span="md:col-span-8" icon={<FileCheck className="size-5" />} title="MOT Status">
                        <div className="p-6">
                            <div className="mb-5 flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <CheckCircle2 className={`size-5 ${data.mot?.motStatus === 'Valid' ? 'text-emerald-600' : 'text-red-600'}`} />
                                    <span className="font-semibold text-slate-800">{data.mot?.motStatus ?? '—'}</span>
                                </div>
                                {data.mot?.motStatus && (
                                    <span
                                        className={`rounded border px-2.5 py-1 text-xs font-bold uppercase tracking-wider ${
                                            data.mot.motStatus === 'Valid'
                                                ? 'border-emerald-200 bg-emerald-100 text-emerald-700'
                                                : 'border-red-200 bg-red-100 text-red-700'
                                        }`}
                                    >
                                        {data.mot.motStatus === 'Valid' ? 'Pass' : 'Fail'}
                                    </span>
                                )}
                            </div>

                            {data.mot?.days != null && (
                                <div className="mb-6">
                                    <div className="mb-2 flex justify-between text-sm">
                                        <span className="font-medium text-slate-700">{data.mot.days} days remaining</span>
                                        <span className="text-slate-500">expires {data.mot.motDueDate}</span>
                                    </div>
                                    <div className="h-2.5 overflow-hidden rounded-full border border-slate-200 bg-slate-100">
                                        <div
                                            className={`h-full rounded-full ${data.mot.motStatus === 'Valid' ? 'bg-emerald-500' : 'bg-red-500'}`}
                                            style={{ width: `${motDaysPercent}%` }}
                                        />
                                    </div>
                                </div>
                            )}

                            {data.motHistorySummary && (
                                <div className="mb-8">
                                    <p className="mb-3 text-xs font-bold uppercase tracking-wider text-slate-400">
                                        {data.motHistorySummary.totalTests} tests{earliestMotYear ? ` since ${earliestMotYear}` : ''}
                                    </p>
                                    <div className="flex h-2.5 gap-0.5 overflow-hidden rounded-full border border-slate-200">
                                        <div className="bg-emerald-500" style={{ width: `${motPassPercent}%` }} />
                                        <div className="bg-red-500" style={{ width: `${100 - motPassPercent}%` }} />
                                    </div>
                                    <div className="mt-3 flex gap-6 text-sm">
                                        <div className="flex items-center gap-2">
                                            <span className="block size-2.5 rounded bg-emerald-500" />
                                            <span className="font-medium text-slate-700">{data.motHistorySummary.passedTests} passed</span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <span className="block size-2.5 rounded bg-red-500" />
                                            <span className="font-medium text-slate-700">{data.motHistorySummary.failedTests} failed</span>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {!hasMotHistory ? (
                                <PendingAccessCard label="MOT test history" />
                            ) : latestFailedTest ? (
                                <div className="border-t border-slate-100 pt-5">
                                    <div className="flex items-start gap-3">
                                        <AlertTriangle className="mt-0.5 size-5 shrink-0 text-red-500" />
                                        <div>
                                            <h4 className="mb-1 text-sm font-bold text-red-700">
                                                Latest test failed
                                                <span className="ml-1 font-normal text-red-400">
                                                    · {new Date(latestFailedTest.completedDate).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}
                                                </span>
                                            </h4>
                                            {!!latestFailedTest.defects?.length && (
                                                <ul className="ml-1 list-inside list-disc space-y-1 text-sm text-red-600">
                                                    {latestFailedTest.defects.map((d, i) => (
                                                        <li key={i}>{d.text}</li>
                                                    ))}
                                                </ul>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <p className="border-t border-slate-100 pt-5 text-sm text-slate-500">No failed MOT tests on record.</p>
                            )}
                        </div>
                    </SectionCard>

                    {/* Salvage & Write-off */}
                    <StatusCard
                        span="md:col-span-4"
                        icon={<CarFront className="size-[18px]" />}
                        title="Salvage & Write-off"
                        state={!hasHistoryData ? 'pending' : hasWriteOff ? 'issue' : 'clean'}
                    >
                        {!hasHistoryData ? (
                            <PendingAccessCard label="Write-off check" />
                        ) : hasWriteOff ? (
                            <>
                                <p className="mb-4 font-bold text-red-700">Write-off Recorded</p>
                                <div className="space-y-3 text-sm">
                                    <DetailRow label="Status" value={data.VehicleHistory!.writeoff?.[0]?.status} />
                                    <DetailRow label="Date" value={data.VehicleHistory!.writeoff?.[0]?.lossdate?.slice(0, 10)} />
                                    <DetailRow label="Insurer" value={data.VehicleHistory!.writeoff?.[0]?.insurername} />
                                    <DetailRow label="Location" value={data.VehicleHistory!.writeoff?.[0]?.damagelocations?.join(', ')} last />
                                </div>
                            </>
                        ) : (
                            <div className="flex items-center gap-3">
                                <CheckCircle2 className="size-5 shrink-0 text-emerald-500" />
                                <div>
                                    <p className="font-bold text-emerald-700">No Category Recorded</p>
                                    <p className="text-xs text-slate-500">MIAFTR REGISTRY CHECK OK</p>
                                </div>
                            </div>
                        )}
                    </StatusCard>

                    {/* Mileage History */}
                    <SectionCard span="md:col-span-8" icon={<Gauge className="size-5" />} title="Mileage History">
                        <div className="p-6">
                            {data.summary ? (
                                <>
                                    <div className="mb-6 grid grid-cols-3 gap-4">
                                        <MiniStat label="Last Recorded" value={`${Number(data.summary.lastRecordedMileage).toLocaleString()} miles`} big />
                                        <MiniStat label="Average / Year" value={`${data.summary.averageMileage.toLocaleString()} miles`} big />
                                        <MiniStat label="Status" value={data.summary.averageMileageStatus} big accent="emerald" />
                                    </div>
                                    {hasMileageIssue && (
                                        <div className="mb-6 flex gap-3 rounded-lg border border-red-200 bg-red-50 p-4">
                                            <AlertTriangle className="mt-0.5 size-5 shrink-0 text-red-500" />
                                            <div>
                                                <h4 className="mb-1 text-sm font-bold text-red-800">Mileage Anomaly Detected</h4>
                                                <p className="text-sm text-red-700">{data.summary.mileageIssueDescription}</p>
                                            </div>
                                        </div>
                                    )}
                                    {sparklinePath && (
                                        <div className="mb-2 flex h-24 w-full items-end border-b border-l border-slate-200 pb-2 pl-2">
                                            <svg className="h-full w-full" preserveAspectRatio="none" viewBox="0 0 100 100">
                                                <polyline fill="none" points={sparklinePath} stroke="#00205b" strokeWidth="2" />
                                            </svg>
                                        </div>
                                    )}
                                    {mileagePoints.length >= 2 && (
                                        <div className="flex justify-between px-2 text-xs text-slate-400">
                                            <span>{mileagePoints[0].year}</span>
                                            <span>{mileagePoints[mileagePoints.length - 1].year}</span>
                                        </div>
                                    )}
                                </>
                            ) : (
                                <PendingAccessCard label="Mileage history" />
                            )}
                        </div>
                    </SectionCard>

                    {/* Owner History */}
                    <SectionCard span="md:col-span-4" icon={<History className="size-5" />} title="Owner History">
                        <div className="p-6">
                            {!hasHistoryData ? (
                                <PendingAccessCard label="Owner history" />
                            ) : (
                                <>
                                    <div className="mb-6 flex items-center gap-3 border-b border-slate-100 pb-6">
                                        <div className="flex size-10 items-center justify-center rounded-full border border-slate-200 bg-slate-100 text-slate-500">
                                            <KeyRound className="size-5" />
                                        </div>
                                        <p className="text-lg font-bold text-slate-900">
                                            {data.VehicleHistory!.NumberOfPreviousKeepers ?? 0} Previous Keepers
                                        </p>
                                    </div>

                                    {timeline.length > 0 ? (
                                        <div className="relative">
                                            <div className="absolute bottom-0 left-[7px] top-1 w-px bg-slate-200" />
                                            {timeline.map((entry, i) => (
                                                <div key={i} className={`relative pl-6 ${i < timeline.length - 1 ? 'mb-6' : ''}`}>
                                                    <div className={`absolute left-0 top-1 size-2 rounded-full border-2 border-white ${i === 0 ? 'bg-primary-container ring-2 ring-primary-container/20' : 'bg-slate-300'}`} />
                                                    <p className="mb-1 text-xs font-bold uppercase tracking-wider text-slate-400">
                                                        {entry.type === 'keeper' ? 'Keeper Change' : 'Plate Change'}
                                                    </p>
                                                    <p className="text-sm font-medium text-slate-800">{entry.date?.slice(0, 10)}</p>
                                                    {entry.type === 'keeper' ? (
                                                        <p className="mt-0.5 text-xs text-slate-500">{entry.keepers} previous keeper(s) recorded</p>
                                                    ) : (
                                                        <div className="mt-1.5 inline-flex items-center gap-2 rounded border border-slate-200 bg-slate-50 px-2 py-1">
                                                            <span className="font-mono text-xs font-bold text-slate-600">{entry.from}</span>
                                                            <ArrowRight className="size-3.5 text-slate-400" />
                                                            <span className="font-mono text-xs font-bold text-slate-900">{entry.to}</span>
                                                        </div>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-slate-500">No keeper or plate changes on record.</p>
                                    )}
                                </>
                            )}
                        </div>
                    </SectionCard>

                    {/* Vehicle Valuation */}
                    <SectionCard span="md:col-span-8" icon={<DollarSign className="size-5" />} title="Vehicle Valuation">
                        <div className="p-6">
                            {hasValuation ? (
                                <>
                                    <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                                        <MiniStat label="Dealer Forecourt" value={`£${Number(data.ValuationList!.DealerForecourt).toLocaleString()}`} />
                                        <MiniStat label="Trade Retail" value={`£${Number(data.ValuationList!.TradeRetail).toLocaleString()}`} />
                                        <MiniStat label="Private Clean" value={`£${Number(data.ValuationList!.PrivateClean).toLocaleString()}`} />
                                        <MiniStat label="Auction" value={`£${Number(data.ValuationList!.Auction).toLocaleString()}`} />
                                    </div>
                                    {data.summary?.lastRecordedMileage && (
                                        <p className="mt-4 text-right text-xs text-slate-400">
                                            Valuation based on {Number(data.summary.lastRecordedMileage).toLocaleString()} miles.
                                        </p>
                                    )}
                                </>
                            ) : (
                                <PendingAccessCard label="Vehicle valuation" />
                            )}
                        </div>
                    </SectionCard>

                    {/* Colour History */}
                    <SectionCard span="md:col-span-4" icon={<Palette className="size-5" />} title="Colour History">
                        <div className="p-6">
                            {!data.ColourChangeDetails ? (
                                <PendingAccessCard label="Colour change history" />
                            ) : (
                                <>
                                    <div className="mb-4 flex items-center gap-4">
                                        <div
                                            className="size-10 shrink-0 rounded-full border-2 border-slate-200 bg-white shadow-sm"
                                            style={{ backgroundColor: data.ColourChangeDetails.CurrentColour?.toLowerCase() }}
                                        />
                                        <div>
                                            <p className="mb-0.5 text-xs font-bold uppercase tracking-wider text-slate-400">Current Colour</p>
                                            <p className="text-sm font-bold text-slate-800">{data.ColourChangeDetails.CurrentColour}</p>
                                        </div>
                                    </div>
                                    <div className="border-t border-slate-100 pt-4 text-sm text-slate-500">
                                        {data.ColourChangeList?.length ? (
                                            <div className="space-y-2">
                                                {data.ColourChangeList.map((c, i) => (
                                                    <p key={i}>{c.PreviousColour} → {data.ColourChangeDetails!.CurrentColour} ({c.DateOfChange?.slice(0, 10)})</p>
                                                ))}
                                            </div>
                                        ) : data.ColourChangeDetails.NumberOfPreviousColours ? (
                                            <p>Vehicle has had {data.ColourChangeDetails.NumberOfPreviousColours} colour change(s) on record.</p>
                                        ) : (
                                            <p>No colour changes recorded for this vehicle.</p>
                                        )}
                                    </div>
                                </>
                            )}
                        </div>
                    </SectionCard>
                </div>

                {/* Actions */}
                <div className="mt-10 flex flex-col justify-center gap-4 sm:flex-row">
                    <button className="flex items-center justify-center gap-2 rounded-md border-2 border-primary-container px-8 py-3 text-sm font-bold uppercase tracking-wider text-primary-container transition-all hover:bg-slate-50">
                        <Download className="size-4" /> Download PDF Report
                    </button>
                    <button className="flex items-center justify-center gap-2 rounded-md bg-secondary px-8 py-3 text-sm font-bold uppercase tracking-wider text-on-secondary shadow-md transition-transform active:scale-[0.98] hover:bg-secondary-container">
                        <Share2 className="size-4" /> Share Report Link
                    </button>
                </div>
            </main>
        </>
    );
}

/* ==== Sub-components ==== */

function SectionCard({ span, icon, title, children }: { span: string; icon: React.ReactNode; title: string; children: React.ReactNode }) {
    return (
        <div className={`${span} overflow-hidden rounded-lg border border-outline-variant bg-white shadow-sm`}>
            <div className="flex items-center gap-2 border-b border-slate-200 bg-slate-50/50 px-6 py-4">
                <span className="text-primary-container">{icon}</span>
                <h3 className="text-base font-semibold text-slate-800">{title}</h3>
            </div>
            {children}
        </div>
    );
}

function StatusCard({
    span, icon, title, state, children,
}: {
    span?: string;
    icon: React.ReactNode;
    title: string;
    state: 'pending' | 'clean' | 'issue';
    children: React.ReactNode;
}) {
    const isIssue = state === 'issue';
    return (
        <div className={`${span ?? ''} overflow-hidden rounded-lg border bg-white shadow-sm ${isIssue ? 'border-red-200' : 'border-outline-variant'}`}>
            <div className={`flex items-center gap-2 border-b px-5 py-3 ${isIssue ? 'border-red-100 bg-red-50/50' : 'border-slate-200 bg-slate-50/50'}`}>
                <span className={isIssue ? 'text-red-700' : 'text-primary-container'}>{icon}</span>
                <h3 className={`text-sm font-bold uppercase tracking-wide ${isIssue ? 'text-red-800' : 'text-slate-800'}`}>{title}</h3>
            </div>
            <div className="p-5">{children}</div>
        </div>
    );
}

function Chip({ icon, label }: { icon: React.ReactNode; label?: string }) {
    if (!label) return null;
    return (
        <span className="flex items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm font-medium text-slate-700">
            {icon} {label.toUpperCase()}
        </span>
    );
}

function SpecInline({ icon, label }: { icon: React.ReactNode; label?: string }) {
    if (!label) return null;
    return (
        <div className="flex items-center gap-2 text-slate-700">
            {icon}
            <span className="text-sm font-medium">{label}</span>
        </div>
    );
}

function MiniStat({ label, value, big, accent }: { label: string; value?: string | number; big?: boolean; accent?: 'emerald' }) {
    return (
        <div className="rounded-lg border border-slate-100 bg-slate-50 p-4">
            <p className="mb-1 text-xs font-bold uppercase tracking-wider text-slate-400">{label}</p>
            <p className={`font-bold ${big ? 'text-xl' : 'text-lg'} ${accent === 'emerald' ? 'text-emerald-600' : 'text-slate-900'}`}>
                {value ?? '—'}
            </p>
        </div>
    );
}

function DetailRow({ label, value, last }: { label: string; value?: string; last?: boolean }) {
    return (
        <div className={`flex justify-between ${last ? '' : 'border-b border-slate-100 pb-2'}`}>
            <span className="text-slate-500">{label}</span>
            <span className="text-right font-medium text-slate-800">{value ?? '—'}</span>
        </div>
    );
}

function PendingAccessCard({ label }: { label: string }) {
    return (
        <div className="text-center">
            <p className="font-semibold text-slate-400">{label} pending</p>
            <p className="mt-1 text-xs text-slate-400">Data provider access in progress.</p>
        </div>
    );
}

function DimensionDiagram({ length, width, height }: { length?: number; width?: number; height?: number }) {
    return (
        <svg
            role="img"
            aria-label={`Diagram dimensi kendaraan: panjang ${length ?? '—'}mm, lebar ${width ?? '—'}mm, tinggi ${height ?? '—'}mm`}
            style={{ width: '100%', maxWidth: 500, height: 'auto' }}
            viewBox="0 0 600 150"
        >
            <rect fill="#f1f5f9" height="46" rx="10" stroke="#cbd5e1" strokeWidth="1.5" width="380" x="90" y="55" />
            <line stroke="#94a3b8" strokeWidth="1" x1="90" x2="470" y1="35" y2="35" />
            <line stroke="#94a3b8" strokeWidth="1" x1="90" x2="90" y1="28" y2="42" />
            <line stroke="#94a3b8" strokeWidth="1" x1="470" x2="470" y1="28" y2="42" />
            <text fill="#64748b" fontFamily="Inter, sans-serif" fontSize="12" fontWeight="500" textAnchor="middle" x="280" y="24">
                {length ? `${length} mm length` : '— mm length'}
            </text>
            <line stroke="#94a3b8" strokeWidth="1" x1="490" x2="490" y1="55" y2="101" />
            <line stroke="#94a3b8" strokeWidth="1" x1="483" x2="497" y1="55" y2="55" />
            <line stroke="#94a3b8" strokeWidth="1" x1="483" x2="497" y1="101" y2="101" />
            <text fill="#64748b" fontFamily="Inter, sans-serif" fontSize="12" fontWeight="500" x="502" y="82">
                {width ? `${width} mm width` : '— mm width'}
            </text>
            <line stroke="#94a3b8" strokeWidth="1" x1="60" x2="60" y1="55" y2="101" />
            <line stroke="#94a3b8" strokeWidth="1" x1="53" x2="67" y1="55" y2="55" />
            <line stroke="#94a3b8" strokeWidth="1" x1="53" x2="67" y1="101" y2="101" />
            <text fill="#64748b" fontFamily="Inter, sans-serif" fontSize="12" fontWeight="500" textAnchor="end" x="50" y="82">
                {height ? `${height} mm height` : '— mm height'}
            </text>
        </svg>
    );
}

FullReport.layout = (page: React.ReactNode) => <BaseLayout>{page}</BaseLayout>;