// resources/js/pages/my-report/full-report.tsx
import { Head } from '@inertiajs/react';
import { ShieldCheck, Camera, TrendingUp, Clock, Car, CreditCard, Gauge, FileCheck, ShieldAlert, CarFront, Wrench, Download, Share2 } from 'lucide-react';
import BaseLayout from '@/layouts/base-layout';

type FullReportProps = {
    report: {
        id: number;
        report_type: 'basic' | 'premium';
        data: {
            registrationNumber: string;
            make: string;
            model: string;
            colour: string;
            fuelType: string;
            engineCapacity: number;
            yearOfManufacture: number;
            tax?: { taxStatus: string };
            mot?: { motStatus: string; motDueDate: string };

            // dari VehicleSpecs (kalau ada)
            VehicleIdentification?: any;
            DvlaTechnicalDetails?: any;
            BodyDetails?: any;
            Dimensions?: any;
            ModelData?: any;

            // dari VehicleHistory (carhistorycheck) — bisa null kalau belum approved
            VehicleHistory?: {
                stolenRecord?: boolean;
                financeRecord?: boolean;
                writeOffRecord?: boolean;
                stolen?: any[];
                finance?: any[];
                writeoff?: any[];
                V5CCertificateList?: { CertificateDate: string }[];
                KeeperChangesList?: any[];
                NumberOfPreviousKeepers?: number;
            };

            // dari MOT History
            motHistory?: {
                motTestNumber: string;
                completedDate: string;
                testResult: string;
                odometerValue: string;
            }[];

            // dari Mileage
            mileage?: {
                dateOfInformation: string;
                mileage: string;
                unit: string;
            }[];

            // dari VehicleImage
            VehicleImages?: {
                ImageDetailsList: { ImageUrl: string; ViewPoint: string }[];
                ImageDetailsCount: number;
            };

            // dari VehicleValuation
            ValuationList?: {
                Auction: string;
                TradeAverage: string;
                DealerForecourt: string;
            };
        };
    };
};

export default function FullReport({ report }: FullReportProps) {
    const { data } = report;
    const hasHistoryData = !!data.VehicleHistory;
    const hasImages = (data.VehicleImages?.ImageDetailsCount ?? 0) > 0;
    const hasValuation = !!data.ValuationList;

    return (
        <>
            <Head title={`${data.make} ${data.model} — Full Report`} />

            <div className="mx-auto max-w-[1200px] px-6 py-10">
                {/* Header */}
                <div className="mb-6 flex flex-col gap-4 rounded-lg border border-slate-200 bg-white p-6 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-4">
                        <div className="flex size-16 items-center justify-center rounded bg-slate-100">
                            <Car className="size-8 text-slate-400" />
                        </div>
                        <div>
                            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase text-slate-500">
                                Report ID: UKC-{report.id}
                            </span>
                            <h1 className="mt-1 text-xl font-bold text-[#151c27]">
                                {data.make} {data.model}
                            </h1>
                            <p className="text-sm text-slate-500">
                                Year: {data.yearOfManufacture} • Reg: {data.registrationNumber} • Engine: {data.engineCapacity}cc
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2 text-sm font-bold text-green-600">
                        <ShieldCheck className="size-4" /> CLEAN REPORT
                    </div>
                </div>

                {/* Exterior Gallery — cuma render kalau beneran ada foto */}
                {hasImages && (
                    <Section icon={<Camera className="size-4" />} title="Exterior History Gallery" badge="HISTORICAL RECORDS">
                        <div className="relative overflow-hidden rounded-lg">
                            <img
                                src={data.VehicleImages!.ImageDetailsList[0].ImageUrl}
                                alt="Vehicle"
                                className="h-80 w-full object-cover"
                            />
                            {data.VehicleImages!.ImageDetailsCount > 1 && (
                                <span className="absolute bottom-4 right-4 rounded-full bg-black/60 px-3 py-1 text-sm font-bold text-white">
                                    {data.VehicleImages!.ImageDetailsCount}
                                </span>
                            )}
                        </div>
                    </Section>
                )}

                {/* Market Price Analysis — cuma render kalau ada valuation */}
                {hasValuation && (
                    <Section icon={<TrendingUp className="size-4" />} title="Market Price Analysis" badge="UPDATED DAILY">
                        <div className="grid grid-cols-3 gap-4 text-center">
                            <PriceStat label="Low Valuation" value={`£${Number(data.ValuationList!.Auction).toLocaleString()}`} />
                            <PriceStat label="Market Average" value={`£${Number(data.ValuationList!.TradeAverage).toLocaleString()}`} highlight />
                            <PriceStat label="High Valuation" value={`£${Number(data.ValuationList!.DealerForecourt).toLocaleString()}`} />
                        </div>
                        <div className="mt-4 h-2 overflow-hidden rounded-full bg-gradient-to-r from-yellow-400 via-green-400 to-red-500" />
                    </Section>
                )}

                {/* Chronological Timeline — dirakit dari data yang sudah ada */}
                {hasHistoryData && (
                    <Section icon={<Clock className="size-4" />} title="Chronological Event Timeline">
                        <div className="space-y-4">
                            {data.VehicleHistory!.V5CCertificateList?.slice(0, 3).map((cert, i) => (
                                <TimelineItem
                                    key={i}
                                    date={cert.CertificateDate}
                                    title="V5C Certificate Issued"
                                    desc="Registration document reissued."
                                />
                            ))}
                        </div>
                    </Section>
                )}

                {/* Vehicle Specifications + Finance Status */}
                <div className="mt-6 grid gap-4 md:grid-cols-[1fr_320px]">
                    <div className="rounded-lg border border-slate-200 bg-white p-6">
                        <h3 className="mb-4 text-sm font-bold text-[#151c27]">Vehicle Specifications</h3>
                        <SpecGroup title="Overview">
                            <Spec label="Make" value={data.make} />
                            <Spec label="Model" value={data.model} />
                            <Spec label="Fuel Type" value={data.fuelType} />
                        </SpecGroup>
                    </div>

                    <div className="rounded-lg border border-slate-200 bg-white p-6 text-center">
                        <h3 className="mb-4 text-sm font-bold text-[#151c27]">Finance Status</h3>
                        {hasHistoryData ? (
                            <FinanceStatusCard hasFinance={!!data.VehicleHistory!.financeRecord} />
                        ) : (
                            <PendingAccessCard label="Finance check" />
                        )}
                    </div>
                </div>

                {/* Mileage History + MOT Status */}
                <div className="mt-4 grid gap-4 md:grid-cols-2">
                    <div className="rounded-lg border border-slate-200 bg-white p-6">
                        <h3 className="mb-4 text-sm font-bold text-[#151c27]">Mileage History</h3>
                        {data.mileage?.slice(0, 3).map((m, i) => (
                            <div key={i} className="flex items-center justify-between border-t border-slate-100 py-2 text-sm first:border-t-0">
                                <span className="text-slate-400">{m.dateOfInformation}</span>
                                <span className="font-bold">{Number(m.mileage).toLocaleString()} miles</span>
                                <span className="text-xs font-bold text-green-600">VALID</span>
                            </div>
                        ))}
                    </div>

                    <div className="rounded-lg border border-slate-200 bg-white p-6">
                        <h3 className="mb-4 flex items-center justify-between text-sm font-bold text-[#151c27]">
                            MOT Status
                            <span className="rounded-full bg-green-50 px-2 py-0.5 text-xs text-green-600">
                                {data.mot?.motStatus?.toUpperCase()}
                            </span>
                        </h3>
                        <div className="flex justify-between text-sm">
                            <span className="text-slate-400">Expiry Date</span>
                            <span className="font-bold">{data.mot?.motDueDate}</span>
                        </div>
                        {data.motHistory?.slice(0, 2).map((t, i) => (
                            <div key={i} className="mt-2 flex items-center justify-between rounded bg-slate-50 px-3 py-2 text-sm">
                                <span>Test Date: {new Date(t.completedDate).toLocaleDateString('en-GB')}</span>
                                <span className="font-bold text-green-600">{t.testResult}</span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Stolen & Salvage */}
                <div className="mt-4 grid gap-4 md:grid-cols-2">
                    <div className="rounded-lg border border-slate-200 bg-white p-6 text-center">
                        <ShieldAlert className="mx-auto mb-2 size-6 text-green-500" />
                        {hasHistoryData ? (
                            <>
                                <p className="font-bold">{data.VehicleHistory!.stolenRecord ? 'Recorded as Stolen' : 'Not Recorded as Stolen'}</p>
                                <p className="text-xs text-slate-400">POLICEDATA CHECK OK</p>
                            </>
                        ) : (
                            <PendingAccessCard label="Stolen record check" />
                        )}
                    </div>

                    <div className="rounded-lg border border-slate-200 bg-white p-6 text-center">
                        <CarFront className="mx-auto mb-2 size-6 text-green-500" />
                        {hasHistoryData ? (
                            <>
                                <p className="font-bold">
                                    {data.VehicleHistory!.writeOffRecord
                                        ? data.VehicleHistory!.writeoff?.[0]?.status
                                        : 'No Category Recorded'}
                                </p>
                                <p className="text-xs text-slate-400">MIAFTR REGISTRY CHECK OK</p>
                            </>
                        ) : (
                            <PendingAccessCard label="Write-off check" />
                        )}
                    </div>
                </div>

                {/* Damage location — kalau ada data write-off */}
                {hasHistoryData && data.VehicleHistory!.writeoff?.[0]?.damagelocations && (
                    <Section icon={<Wrench className="size-4" />} title="Damage & Repair Estimation">
                        <p className="font-semibold">{data.VehicleHistory!.writeoff![0].damagelocations.join(', ')}</p>
                        <p className="text-sm text-slate-400">Cause: {data.VehicleHistory!.writeoff?.[0]?.causeofdamage ?? 'Not specified'}</p>
                    </Section>
                )}

                <div className="mt-6 flex justify-center gap-3">
                    <button className="flex items-center gap-2 rounded border border-slate-300 px-6 py-3 text-sm font-bold">
                        <Download className="size-4" /> Download PDF Report
                    </button>
                    <button className="flex items-center gap-2 rounded bg-[#bb001a] px-6 py-3 text-sm font-bold text-white">
                        <Share2 className="size-4" /> Share Report Link
                    </button>
                </div>
            </div>
        </>
    );
}

function Section({ icon, title, badge, children }: any) {
    return (
        <div className="mt-6 rounded-lg border border-slate-200 bg-white p-6">
            <div className="mb-4 flex items-center justify-between">
                <h3 className="flex items-center gap-2 text-sm font-bold text-[#151c27]">{icon} {title}</h3>
                {badge && <span className="text-[10px] font-bold uppercase text-slate-400">{badge}</span>}
            </div>
            {children}
        </div>
    );
}

function PriceStat({ label, value, highlight }: { label: string; value: string; highlight?: boolean }) {
    return (
        <div>
            <p className="text-[10px] uppercase text-slate-400">{label}</p>
            <p className={`text-lg font-bold ${highlight ? 'text-[#bb001a]' : 'text-[#151c27]'}`}>{value}</p>
        </div>
    );
}

function TimelineItem({ date, title, desc }: { date: string; title: string; desc: string }) {
    return (
        <div className="border-l-2 border-slate-200 pl-4">
            <p className="text-xs text-slate-400">{new Date(date).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}</p>
            <p className="font-semibold">{title}</p>
            <p className="text-sm text-slate-500">{desc}</p>
        </div>
    );
}

function SpecGroup({ title, children }: any) {
    return (
        <div className="mb-4">
            <p className="mb-2 text-xs font-bold uppercase text-slate-400">{title}</p>
            <div className="grid grid-cols-2 gap-3">{children}</div>
        </div>
    );
}

function Spec({ label, value }: { label: string; value?: string | number }) {
    return (
        <div>
            <p className="text-[10px] uppercase text-slate-400">{label}</p>
            <p className="font-semibold">{value ?? '—'}</p>
        </div>
    );
}

function FinanceStatusCard({ hasFinance }: { hasFinance: boolean }) {
    return (
        <>
            <div className={`mx-auto mb-2 flex size-12 items-center justify-center rounded ${hasFinance ? 'bg-red-50' : 'bg-green-50'}`}>
                <CreditCard className={`size-6 ${hasFinance ? 'text-red-600' : 'text-green-600'}`} />
            </div>
            <p className="font-bold">{hasFinance ? 'Finance Agreement Found' : 'No Finance Recorded'}</p>
            <p className="mt-1 text-xs text-slate-400">Checked against HPI and Experian records.</p>
        </>
    );
}

function PendingAccessCard({ label }: { label: string }) {
    return (
        <>
            <p className="font-semibold text-slate-400">{label} pending</p>
            <p className="mt-1 text-xs text-slate-400">Data provider access in progress.</p>
        </>
    );
}

FullReport.layout = (page: React.ReactNode) => <BaseLayout>{page}</BaseLayout>;