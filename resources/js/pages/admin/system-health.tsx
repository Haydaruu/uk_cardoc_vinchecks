import { Head, router } from '@inertiajs/react';
import {
    Activity,
    AlertCircle,
    AlertTriangle,
    CheckCircle2,
    Clock,
    CreditCard,
    Database,
    ExternalLink,
    HardDrive,
    Layers,
    Mail,
    RefreshCw,
    Server,
    Shield,
    XCircle,
} from 'lucide-react';
import React, { useState } from 'react';

import StatusBadge from '@/components/admin/status-badge';
import AdminLayout from '@/layouts/admin-layout';

interface FailedJobItem {
    id: number;
    uuid: string;
    connection: string;
    queue: string;
    failed_at: string;
    summary: string;
}

interface HealthData {
    overall_status: 'healthy' | 'degraded' | 'failed';
    checked_at: string;
    database: {
        status: 'healthy' | 'degraded' | 'failed';
        driver: string;
        database: string;
        latency_ms: number | null;
        error: string | null;
    };
    storage: {
        status: 'healthy' | 'failed';
        disk: string;
        latency_ms: number | null;
        free_space: string;
        total_space: string;
        error: string | null;
    };
    queue: {
        status: 'healthy' | 'degraded' | 'unknown';
        driver: string;
        note: string | null;
        pending_jobs_count: number | null;
        failed_jobs_count: number;
        recent_failed_jobs: FailedJobItem[];
        retry_supported: boolean;
    };
    mail: {
        status: 'healthy' | 'unknown';
        driver: string;
        host: string;
        port: string;
        encryption: string;
        from_address: string;
        note: string;
    };
    gateways: {
        stripe: {
            name: string;
            status: 'healthy' | 'degraded' | 'unknown';
            configured: boolean;
            webhook_configured: boolean;
            mode: string;
            last_event: {
                event_id: string;
                status: string;
                recorded_at: string | null;
            } | null;
            note: string;
        };
        paypal: {
            name: string;
            status: 'healthy' | 'degraded' | 'unknown';
            configured: boolean;
            webhook_configured: boolean;
            mode: string;
            last_event: {
                event_id: string;
                status: string;
                recorded_at: string | null;
            } | null;
            note: string;
        };
    };
    vehicle_providers: {
        checkcardetails: {
            name: string;
            status: 'healthy' | 'degraded' | 'unknown';
            configured: boolean;
            role: string;
            last_call: {
                endpoint: string;
                status_code: number;
                duration_ms: number | null;
                recorded_at: string | null;
            } | null;
            note: string;
        };
        oneauto: {
            name: string;
            status: 'healthy' | 'degraded' | 'unknown';
            configured: boolean;
            role: string;
            last_call: {
                endpoint: string;
                status_code: number;
                duration_ms: number | null;
                recorded_at: string | null;
            } | null;
            note: string;
        };
    };
}

interface Props {
    health: HealthData;
}

export default function SystemHealth({ health: initialHealth }: Props) {
    const [health, setHealth] = useState<HealthData>(initialHealth);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [refreshFeedback, setRefreshFeedback] = useState<string | null>(null);

    const handleRefresh = () => {
        setIsRefreshing(true);
        setRefreshFeedback(null);

        router.post(
            '/admin/system-health/refresh',
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    if ((page.props as any).health) {
                        setHealth((page.props as any).health as HealthData);
                    }

                    setRefreshFeedback('Probes executed successfully.');
                    setTimeout(() => setRefreshFeedback(null), 4000);
                },
                onError: () => {
                    setRefreshFeedback('Failed to execute refresh probes.');
                },
                onFinish: () => {
                    setIsRefreshing(false);
                },
            },
        );
    };

    const getStatusIndicator = (status: string) => {
        switch (status) {
            case 'healthy':
                return {
                    icon: <CheckCircle2 className="h-5 w-5 text-emerald-600" />,
                    bg: 'bg-emerald-50 border-emerald-200 text-emerald-800',
                    dot: 'bg-emerald-500',
                    label: 'Healthy',
                };
            case 'degraded':
                return {
                    icon: <AlertTriangle className="h-5 w-5 text-amber-600" />,
                    bg: 'bg-amber-50 border-amber-200 text-amber-800',
                    dot: 'bg-amber-500',
                    label: 'Degraded',
                };
            case 'failed':
                return {
                    icon: <XCircle className="h-5 w-5 text-red-600" />,
                    bg: 'bg-red-50 border-red-200 text-red-800',
                    dot: 'bg-red-500',
                    label: 'Failed',
                };
            default:
                return {
                    icon: <AlertCircle className="h-5 w-5 text-slate-500" />,
                    bg: 'bg-slate-50 border-slate-200 text-slate-700',
                    dot: 'bg-slate-400',
                    label: 'Unknown',
                };
        }
    };

    const overall = getStatusIndicator(health.overall_status);

    return (
        <AdminLayout
            title="System Health & Diagnostics"
            subtitle="Operational Infrastructure Health, Component Connectivity, and Failed Job Diagnostics"
        >
            <Head title="System Health & Diagnostics — Super Admin" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <h1 className="text-2xl font-bold tracking-tight text-slate-900">
                                System Health & Diagnostics
                            </h1>
                            <span className="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">
                                Super Admin Only
                            </span>
                        </div>
                        <p className="mt-1 text-sm text-slate-500">
                            Operational Infrastructure Health, Component
                            Connectivity, and Failed Job Diagnostics
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <button
                            type="button"
                            onClick={handleRefresh}
                            disabled={isRefreshing}
                            className="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-50"
                        >
                            <RefreshCw
                                className={`h-4 w-4 ${isRefreshing ? 'animate-spin text-blue-600' : 'text-slate-500'}`}
                            />
                            {isRefreshing
                                ? 'Executing Probes...'
                                : 'Run Fresh Probes'}
                        </button>
                    </div>
                </div>

                {refreshFeedback && (
                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-xs font-medium text-emerald-800">
                        {refreshFeedback}
                    </div>
                )}

                {/* Overall Health Status Hero */}
                <div
                    className={`rounded-xl border p-5 transition-all ${overall.bg}`}
                >
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-center gap-3.5">
                            <div className="rounded-lg bg-white/80 p-2.5 shadow-sm">
                                {overall.icon}
                            </div>
                            <div>
                                <div className="flex items-center gap-2">
                                    <h2 className="text-lg font-bold tracking-tight">
                                        Overall System Status:{' '}
                                        <span className="capitalize">
                                            {overall.label}
                                        </span>
                                    </h2>
                                    <span
                                        className={`inline-block h-2.5 w-2.5 rounded-full ${overall.dot} animate-pulse`}
                                    />
                                </div>
                                <p className="text-xs opacity-90">
                                    {health.overall_status === 'healthy' &&
                                        'All internal services, database connections, and storage probes are operating normally.'}
                                    {health.overall_status === 'degraded' &&
                                        'One or more non-critical components report warnings, high latency, or failed jobs.'}
                                    {health.overall_status === 'failed' &&
                                        'Critical service outage detected in core database or storage connectivity.'}
                                </p>
                            </div>
                        </div>

                        <div className="text-right text-xs opacity-80 sm:text-right">
                            <div className="flex items-center gap-1.5 font-mono">
                                <Clock className="h-3.5 w-3.5" />
                                <span>
                                    Last Probe:{' '}
                                    {new Date(
                                        health.checked_at,
                                    ).toLocaleTimeString()}{' '}
                                    (
                                    {new Date(
                                        health.checked_at,
                                    ).toLocaleDateString()}
                                    )
                                </span>
                            </div>
                            <span className="text-[11px]">
                                Lightweight read-only probes
                            </span>
                        </div>
                    </div>
                </div>

                {/* Grid 1: Core System Infrastructure */}
                <div>
                    <h3 className="mb-3 text-sm font-semibold tracking-wide text-slate-800 uppercase">
                        Core Infrastructure
                    </h3>
                    <div className="grid grid-cols-1 gap-5 md:grid-cols-3">
                        {/* Database Card */}
                        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                                <div className="flex items-center gap-2.5">
                                    <div className="rounded-lg bg-blue-50 p-2 text-blue-600">
                                        <Database className="h-4 w-4" />
                                    </div>
                                    <h4 className="font-semibold text-slate-900">
                                        Database Engine
                                    </h4>
                                </div>
                                <StatusBadge status={health.database.status} />
                            </div>

                            <div className="mt-4 space-y-2.5 text-xs">
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        Driver:
                                    </span>
                                    <span className="font-mono font-medium text-slate-900">
                                        {health.database.driver}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        Database:
                                    </span>
                                    <span className="font-mono font-medium text-slate-900">
                                        {health.database.database}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        Probe Latency:
                                    </span>
                                    <span
                                        className={`font-mono font-bold ${
                                            (health.database.latency_ms ?? 0) <
                                            100
                                                ? 'text-emerald-600'
                                                : (health.database
                                                        .latency_ms ?? 0) < 500
                                                  ? 'text-blue-600'
                                                  : 'text-amber-600'
                                        }`}
                                    >
                                        {health.database.latency_ms !== null
                                            ? `${health.database.latency_ms} ms`
                                            : 'N/A'}
                                    </span>
                                </div>
                                {health.database.error && (
                                    <div className="mt-2 rounded bg-red-50 p-2 text-[11px] text-red-700">
                                        {health.database.error}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Storage Card */}
                        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                                <div className="flex items-center gap-2.5">
                                    <div className="rounded-lg bg-indigo-50 p-2 text-indigo-600">
                                        <HardDrive className="h-4 w-4" />
                                    </div>
                                    <h4 className="font-semibold text-slate-900">
                                        Storage Subsystem
                                    </h4>
                                </div>
                                <StatusBadge status={health.storage.status} />
                            </div>

                            <div className="mt-4 space-y-2.5 text-xs">
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        Default Disk:
                                    </span>
                                    <span className="font-mono font-medium text-slate-900">
                                        {health.storage.disk}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        Write/Delete Probe:
                                    </span>
                                    <span className="font-mono font-bold text-emerald-600">
                                        {health.storage.latency_ms !== null
                                            ? `${health.storage.latency_ms} ms`
                                            : 'Failed'}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        Disk Capacity:
                                    </span>
                                    <span className="font-mono text-slate-700">
                                        {health.storage.free_space} free /{' '}
                                        {health.storage.total_space}
                                    </span>
                                </div>
                                {health.storage.error && (
                                    <div className="mt-2 rounded bg-red-50 p-2 text-[11px] text-red-700">
                                        {health.storage.error}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Queue Card */}
                        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                                <div className="flex items-center gap-2.5">
                                    <div className="rounded-lg bg-purple-50 p-2 text-purple-600">
                                        <Layers className="h-4 w-4" />
                                    </div>
                                    <h4 className="font-semibold text-slate-900">
                                        Queue & Workers
                                    </h4>
                                </div>
                                <StatusBadge status={health.queue.status} />
                            </div>

                            <div className="mt-4 space-y-2.5 text-xs">
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        Driver:
                                    </span>
                                    <span className="font-mono font-medium text-slate-900">
                                        {health.queue.driver}
                                    </span>
                                </div>
                                {health.queue.pending_jobs_count !== null && (
                                    <div className="flex justify-between">
                                        <span className="text-slate-500">
                                            Pending Jobs:
                                        </span>
                                        <span className="font-mono font-medium text-slate-900">
                                            {health.queue.pending_jobs_count}
                                        </span>
                                    </div>
                                )}
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        Failed Jobs:
                                    </span>
                                    <span
                                        className={`font-mono font-bold ${
                                            health.queue.failed_jobs_count > 0
                                                ? 'text-red-600'
                                                : 'text-emerald-600'
                                        }`}
                                    >
                                        {health.queue.failed_jobs_count}
                                    </span>
                                </div>
                                <p className="mt-1 text-[11px] text-slate-500 italic">
                                    {health.queue.note}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Grid 2: External Payment Gateways (Last-Known) & Email */}
                <div>
                    <h3 className="mb-3 text-sm font-semibold tracking-wide text-slate-800 uppercase">
                        Integrations & Services (Last-Known State)
                    </h3>
                    <div className="grid grid-cols-1 gap-5 md:grid-cols-3">
                        {/* Stripe Gateway */}
                        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                                <div className="flex items-center gap-2.5">
                                    <div className="rounded-lg bg-blue-50 p-2 text-blue-600">
                                        <CreditCard className="h-4 w-4" />
                                    </div>
                                    <h4 className="font-semibold text-slate-900">
                                        Stripe Gateway
                                    </h4>
                                </div>
                                <StatusBadge
                                    status={health.gateways.stripe.status}
                                />
                            </div>

                            <div className="mt-4 space-y-2.5 text-xs">
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        Credentials:
                                    </span>
                                    <span className="font-semibold text-slate-900">
                                        {health.gateways.stripe.configured
                                            ? 'Configured'
                                            : 'Missing'}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        Mode / Environment:
                                    </span>
                                    <span className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] uppercase">
                                        {health.gateways.stripe.mode}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        Webhook Listener:
                                    </span>
                                    <span className="text-slate-700">
                                        {health.gateways.stripe
                                            .webhook_configured
                                            ? 'Active'
                                            : 'Not Set'}
                                    </span>
                                </div>
                                <div className="border-t border-slate-100 pt-2 text-[11px] text-slate-600">
                                    <span className="block font-medium text-slate-500">
                                        Last Webhook Event:
                                    </span>
                                    {health.gateways.stripe.last_event ? (
                                        <span className="font-mono">
                                            {
                                                health.gateways.stripe
                                                    .last_event.event_id
                                            }{' '}
                                            (
                                            {
                                                health.gateways.stripe
                                                    .last_event.status
                                            }
                                            )
                                        </span>
                                    ) : (
                                        <span className="text-slate-400 italic">
                                            No events recorded yet
                                        </span>
                                    )}
                                </div>
                                <p className="text-[10px] text-slate-400">
                                    Live outbound probe disabled per Spec 7.1
                                </p>
                            </div>
                        </div>

                        {/* PayPal Gateway */}
                        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                                <div className="flex items-center gap-2.5">
                                    <div className="rounded-lg bg-sky-50 p-2 text-sky-600">
                                        <CreditCard className="h-4 w-4" />
                                    </div>
                                    <h4 className="font-semibold text-slate-900">
                                        PayPal Gateway
                                    </h4>
                                </div>
                                <StatusBadge
                                    status={health.gateways.paypal.status}
                                />
                            </div>

                            <div className="mt-4 space-y-2.5 text-xs">
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        Credentials:
                                    </span>
                                    <span className="font-semibold text-slate-900">
                                        {health.gateways.paypal.configured
                                            ? 'Configured'
                                            : 'Missing'}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        Mode / Environment:
                                    </span>
                                    <span className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] uppercase">
                                        {health.gateways.paypal.mode}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        Webhook Listener:
                                    </span>
                                    <span className="text-slate-700">
                                        {health.gateways.paypal
                                            .webhook_configured
                                            ? 'Active'
                                            : 'Not Set'}
                                    </span>
                                </div>
                                <div className="border-t border-slate-100 pt-2 text-[11px] text-slate-600">
                                    <span className="block font-medium text-slate-500">
                                        Last Webhook Event:
                                    </span>
                                    {health.gateways.paypal.last_event ? (
                                        <span className="font-mono">
                                            {
                                                health.gateways.paypal
                                                    .last_event.event_id
                                            }{' '}
                                            (
                                            {
                                                health.gateways.paypal
                                                    .last_event.status
                                            }
                                            )
                                        </span>
                                    ) : (
                                        <span className="text-slate-400 italic">
                                            No events recorded yet
                                        </span>
                                    )}
                                </div>
                                <p className="text-[10px] text-slate-400">
                                    Live outbound probe disabled per Spec 7.1
                                </p>
                            </div>
                        </div>

                        {/* Mailer Subsystem */}
                        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                                <div className="flex items-center gap-2.5">
                                    <div className="rounded-lg bg-teal-50 p-2 text-teal-600">
                                        <Mail className="h-4 w-4" />
                                    </div>
                                    <h4 className="font-semibold text-slate-900">
                                        Mail Service
                                    </h4>
                                </div>
                                <StatusBadge status={health.mail.status} />
                            </div>

                            <div className="mt-4 space-y-2.5 text-xs">
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        Driver:
                                    </span>
                                    <span className="font-mono font-medium text-slate-900">
                                        {health.mail.driver}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        Host:
                                    </span>
                                    <span className="font-mono text-slate-700">
                                        {health.mail.host}:{health.mail.port}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        Encryption:
                                    </span>
                                    <span className="font-mono uppercase text-slate-700">
                                        {health.mail.encryption}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        From Address:
                                    </span>
                                    <span className="font-mono text-slate-700">
                                        {health.mail.from_address}
                                    </span>
                                </div>
                                <p className="mt-1 text-[11px] text-slate-500 italic">
                                    {health.mail.note}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Grid 3: Vehicle Data Providers & Developer Diagnostics */}
                <div>
                    <h3 className="mb-3 text-sm font-semibold tracking-wide text-slate-800 uppercase">
                        Vehicle Providers & Developer Diagnostics
                    </h3>
                    <div className="grid grid-cols-1 gap-5 md:grid-cols-3">
                        {/* CheckCarDetails */}
                        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                                <div className="flex items-center gap-2.5">
                                    <div className="rounded-lg bg-slate-100 p-2 text-slate-700">
                                        <Server className="h-4 w-4" />
                                    </div>
                                    <h4 className="font-semibold text-slate-900">
                                        CheckCarDetails
                                    </h4>
                                </div>
                                <StatusBadge
                                    status={
                                        health.vehicle_providers.checkcardetails
                                            .status
                                    }
                                />
                            </div>

                            <div className="mt-4 space-y-2.5 text-xs">
                                <p className="text-[11px] text-slate-500">
                                    {
                                        health.vehicle_providers.checkcardetails
                                            .role
                                    }
                                </p>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        API Credentials:
                                    </span>
                                    <span className="font-semibold text-slate-900">
                                        {health.vehicle_providers
                                            .checkcardetails.configured
                                            ? 'Present'
                                            : 'Not Configured'}
                                    </span>
                                </div>
                                <div className="border-t border-slate-100 pt-2 text-[11px]">
                                    <span className="block font-medium text-slate-500">
                                        Last Recorded Call:
                                    </span>
                                    {health.vehicle_providers.checkcardetails
                                        .last_call ? (
                                        <div className="mt-1 font-mono text-[11px] text-slate-700">
                                            <span>
                                                HTTP{' '}
                                                {
                                                    health.vehicle_providers
                                                        .checkcardetails
                                                        .last_call.status_code
                                                }{' '}
                                                (
                                                {
                                                    health.vehicle_providers
                                                        .checkcardetails
                                                        .last_call.duration_ms
                                                }{' '}
                                                ms)
                                            </span>
                                        </div>
                                    ) : (
                                        <span className="text-slate-400 italic">
                                            No logs in api_logs table
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* OneAuto */}
                        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                                <div className="flex items-center gap-2.5">
                                    <div className="rounded-lg bg-slate-100 p-2 text-slate-700">
                                        <Server className="h-4 w-4" />
                                    </div>
                                    <h4 className="font-semibold text-slate-900">
                                        OneAuto API
                                    </h4>
                                </div>
                                <StatusBadge
                                    status={
                                        health.vehicle_providers.oneauto.status
                                    }
                                />
                            </div>

                            <div className="mt-4 space-y-2.5 text-xs">
                                <p className="text-[11px] text-slate-500">
                                    {health.vehicle_providers.oneauto.role}
                                </p>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">
                                        API Credentials:
                                    </span>
                                    <span className="font-semibold text-slate-900">
                                        {health.vehicle_providers.oneauto
                                            .configured
                                            ? 'Present'
                                            : 'Not Configured'}
                                    </span>
                                </div>
                                <div className="border-t border-slate-100 pt-2 text-[11px]">
                                    <span className="block font-medium text-slate-500">
                                        Last Recorded Call:
                                    </span>
                                    {health.vehicle_providers.oneauto
                                        .last_call ? (
                                        <div className="mt-1 font-mono text-[11px] text-slate-700">
                                            <span>
                                                HTTP{' '}
                                                {
                                                    health.vehicle_providers
                                                        .oneauto.last_call
                                                        .status_code
                                                }{' '}
                                                (
                                                {
                                                    health.vehicle_providers
                                                        .oneauto.last_call
                                                        .duration_ms
                                                }{' '}
                                                ms)
                                            </span>
                                        </div>
                                    ) : (
                                        <span className="text-slate-400 italic">
                                            No logs in api_logs table
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* Provider Lab Shortcut */}
                        <div className="flex flex-col justify-between rounded-xl border border-amber-200 bg-gradient-to-br from-amber-50/50 to-white p-5 shadow-sm">
                            <div>
                                <div className="flex items-center gap-2.5 border-b border-amber-100 pb-3">
                                    <div className="rounded-lg bg-amber-100 p-2 text-amber-800">
                                        <Activity className="h-4 w-4" />
                                    </div>
                                    <h4 className="font-semibold text-slate-900">
                                        Provider Lab & Fixtures
                                    </h4>
                                </div>
                                <p className="mt-3 text-xs text-slate-600">
                                    Compare normalization algorithms, test
                                    sandbox fixtures, and evaluate multi-source
                                    hybrid data consistency.
                                </p>
                            </div>

                            <div className="mt-4 pt-2">
                                <a
                                    href="/admin/provider-lab"
                                    className="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow transition hover:bg-slate-800"
                                >
                                    <span>Open Provider Lab</span>
                                    <ExternalLink className="h-3.5 w-3.5" />
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Failed Jobs Table (v1 Read-Only) */}
                <div className="rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-200 px-6 py-4">
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 className="font-semibold text-slate-900">
                                    Failed Background Jobs
                                </h3>
                                <p className="text-xs text-slate-500">
                                    Queue execution failure records and
                                    sanitized exception traces
                                </p>
                            </div>
                            <span className="rounded-full bg-slate-100 px-3 py-1 font-mono text-xs font-semibold text-slate-700">
                                Total Failed: {health.queue.failed_jobs_count}
                            </span>
                        </div>
                        <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50/70 p-3 text-xs text-amber-800">
                            <strong>Notice (Spec 7.1):</strong> Failed jobs are
                            displayed read-only in v1. Manual retry from console
                            is disabled to prevent uncoordinated double
                            mutations or unintended side effects.
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        {health.queue.recent_failed_jobs.length > 0 ? (
                            <table className="w-full text-left text-xs">
                                <thead className="border-b border-slate-200 bg-slate-50 text-slate-600">
                                    <tr>
                                        <th className="px-6 py-3 font-semibold">
                                            UUID / Connection
                                        </th>
                                        <th className="px-6 py-3 font-semibold">
                                            Queue
                                        </th>
                                        <th className="px-6 py-3 font-semibold">
                                            Failed At
                                        </th>
                                        <th className="px-6 py-3 font-semibold">
                                            Sanitized Exception
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 text-slate-700">
                                    {health.queue.recent_failed_jobs.map(
                                        (job) => (
                                            <tr
                                                key={job.id}
                                                className="hover:bg-slate-50/60"
                                            >
                                                <td className="px-6 py-3 font-mono text-[11px]">
                                                    <span className="block font-medium text-slate-900">
                                                        {job.uuid.slice(0, 18)}
                                                        ...
                                                    </span>
                                                    <span className="text-slate-400">
                                                        {job.connection}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-3 font-mono">
                                                    {job.queue}
                                                </td>
                                                <td className="px-6 py-3 text-slate-500">
                                                    {job.failed_at}
                                                </td>
                                                <td className="px-6 py-3 font-mono text-[11px] text-red-700">
                                                    {job.summary}
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                </tbody>
                            </table>
                        ) : (
                            <div className="p-8 text-center text-xs text-slate-500">
                                <CheckCircle2 className="mx-auto mb-2 h-8 w-8 text-emerald-500" />
                                <p className="font-semibold text-slate-800">
                                    No Failed Jobs Recorded
                                </p>
                                <p className="text-slate-400">
                                    All queue tasks have completed successfully
                                    or no queue failures exist in the system.
                                </p>
                            </div>
                        )}
                    </div>
                </div>

                {/* Security and Sanitation Guarantee Footer */}
                <div className="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs text-slate-600">
                    <Shield className="h-5 w-5 shrink-0 text-slate-500" />
                    <div>
                        <span className="font-semibold text-slate-800">
                            Security Guarantee (Spec 7.1 & 7.4):
                        </span>{' '}
                        All credentials, API keys, database connection strings,
                        and customer PII are strictly redacted from diagnostic
                        views and responses. Diagnostic probes never mutate
                        business data.
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
