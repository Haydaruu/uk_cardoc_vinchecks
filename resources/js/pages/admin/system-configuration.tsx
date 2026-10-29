import { Head, useForm, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    CheckCircle,
    CheckCircle2,
    Database,
    Globe,
    Layers,
    Loader2,
    Lock,
    Mail,
    Save,
    Settings,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import React, { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

interface SettingItem {
    key: string;
    value: any;
    raw_value: string;
    type: 'string' | 'boolean' | 'integer';
    group: string;
    description: string;
    updated_at?: string | null;
    updated_by_name?: string | null;
}

interface InfrastructureStatus {
    gateways: {
        stripe: { configured: boolean; webhook_configured: boolean; mode: string };
        paypal: { configured: boolean; webhook_configured: boolean; mode: string };
    };
    mail: {
        driver: string;
        host: string;
        port: string;
        encryption: string;
        from_address: string;
        from_name: string;
    };
    queue: { driver: string };
    storage: { disk: string };
    providers: {
        checkcardetails: { name: string; configured: boolean; role: string };
        oneauto: { name: string; configured: boolean; role: string };
    };
    plans: Array<{
        slug: string;
        label: string;
        type: string;
        credits: number;
        amount_display: string;
        currency: string;
        has_stripe_price: boolean;
        has_paypal_plan: boolean;
    }>;
    catalog_sync_notice: string;
}

interface SystemConfigurationProps {
    settings: Record<string, SettingItem>;
    infrastructure: InfrastructureStatus;
}

export default function SystemConfiguration({
    settings,
    infrastructure,
}: SystemConfigurationProps) {
    const { flash } = usePage<{
        flash?: {
            success?: string | null;
            error?: string | null;
            warning?: string | null;
        };
    }>().props;

    const [activeTab, setActiveTab] = useState<'settings' | 'infrastructure'>('settings');

    const { data, setData, post, processing, errors } = useForm({
        settings: {
            company_name: settings.company_name?.value ?? 'VinCheck UK',
            support_email: settings.support_email?.value ?? 'support@vincheck.test',
            support_phone: settings.support_phone?.value ?? '+44 20 7946 0912',
            maintenance_mode: Boolean(settings.maintenance_mode?.value),
            enable_guest_checkout: Boolean(settings.enable_guest_checkout?.value ?? true),
            default_report_credits: Number(settings.default_report_credits?.value ?? 1),
            notify_admin_on_failure: Boolean(settings.notify_admin_on_failure?.value ?? true),
        },
        reason: '',
    });

    const isReasonValid = data.reason.trim().length >= 5;
    const canSubmit = isReasonValid && !processing;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (!canSubmit) {
            return;
        }

        post('/admin/system-configuration', {
            preserveScroll: true,
            onSuccess: () => {
                setData('reason', '');
            },
        });
    };

    return (
        <AdminLayout
            title="System Configuration"
            subtitle="Allowlisted settings & safe infrastructure status"
        >
            <Head title="System Configuration — Super Admin" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="rounded bg-amber-400/20 px-2 py-0.5 font-mono text-[10px] font-bold text-amber-700 uppercase">
                                Super Admin Restricted
                            </span>
                        </div>
                        <h1 className="mt-1 font-sans text-2xl font-extrabold text-on-surface">
                            System Configuration
                        </h1>
                        <p className="text-xs text-on-surface-variant">
                            Manage allowlisted application parameters and monitor non-sensitive infrastructure status
                        </p>
                    </div>

                    {/* Tab Navigation */}
                    <div className="flex rounded-lg border border-outline-variant bg-surface p-1">
                        <button
                            type="button"
                            onClick={() => setActiveTab('settings')}
                            className={`flex items-center gap-2 rounded-md px-3.5 py-1.5 text-xs font-semibold transition ${
                                activeTab === 'settings'
                                    ? 'bg-primary text-white shadow-xs'
                                    : 'text-on-surface-variant hover:text-on-surface'
                            }`}
                        >
                            <Settings className="h-3.5 w-3.5" />
                            <span>App Settings</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('infrastructure')}
                            className={`flex items-center gap-2 rounded-md px-3.5 py-1.5 text-xs font-semibold transition ${
                                activeTab === 'infrastructure'
                                    ? 'bg-primary text-white shadow-xs'
                                    : 'text-on-surface-variant hover:text-on-surface'
                            }`}
                        >
                            <Globe className="h-3.5 w-3.5" />
                            <span>Infrastructure Status</span>
                        </button>
                    </div>
                </div>

                {/* Flash Messages */}
                {flash?.success && (
                    <div className="flex items-center gap-3 rounded-lg border border-emerald-500/30 bg-emerald-50 p-4 text-xs text-emerald-800">
                        <CheckCircle className="h-4 w-4 shrink-0 text-emerald-600" />
                        <span className="font-medium">{flash.success}</span>
                    </div>
                )}
                {flash?.error && (
                    <div className="flex items-center gap-3 rounded-lg border border-error/30 bg-error/10 p-4 text-xs text-error">
                        <AlertCircle className="h-4 w-4 shrink-0" />
                        <span className="font-medium">{flash.error}</span>
                    </div>
                )}
                {flash?.warning && (
                    <div className="flex items-center gap-3 rounded-lg border border-amber-500/30 bg-amber-50 p-4 text-xs text-amber-800">
                        <AlertTriangle className="h-4 w-4 shrink-0 text-amber-600" />
                        <span className="font-medium">{flash.warning}</span>
                    </div>
                )}

                {/* Tab: Settings */}
                {activeTab === 'settings' && (
                    <div className="rounded-xl border border-outline-variant bg-white p-6 shadow-xs">
                        <div className="flex items-center justify-between border-b border-outline-variant/60 pb-4">
                            <div>
                                <h2 className="font-sans text-base font-bold text-on-surface">
                                    Configurable Application Parameters
                                </h2>
                                <p className="text-xs text-on-surface-variant">
                                    Changes are stored in the database, validated by strict schema allowlist, and recorded in the audit trail.
                                </p>
                            </div>
                            <div className="flex items-center gap-1.5 text-xs text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-1 rounded-md font-medium">
                                <ShieldCheck className="h-4 w-4 text-emerald-600" />
                                <span>No Secrets Exposed</span>
                            </div>
                        </div>

                        <form onSubmit={handleSubmit} className="mt-6 space-y-6">
                            {/* General Business Group */}
                            <div>
                                <h3 className="text-xs font-bold tracking-wider text-primary uppercase">
                                    General & Contact Details
                                </h3>
                                <div className="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label
                                            htmlFor="company_name"
                                            className="block text-xs font-semibold text-on-surface"
                                        >
                                            Company / Business Name
                                        </label>
                                        <input
                                            id="company_name"
                                            type="text"
                                            value={data.settings.company_name}
                                            onChange={(e) =>
                                                setData('settings', {
                                                    ...data.settings,
                                                    company_name: e.target.value,
                                                })
                                            }
                                            disabled={processing}
                                            className="mt-1.5 w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface transition focus:border-primary focus:outline-hidden"
                                        />
                                        <p className="mt-1 text-[11px] text-on-surface-variant">
                                            {settings.company_name?.description}
                                        </p>
                                    </div>

                                    <div>
                                        <label
                                            htmlFor="support_email"
                                            className="block text-xs font-semibold text-on-surface"
                                        >
                                            Support Email Address
                                        </label>
                                        <input
                                            id="support_email"
                                            type="email"
                                            value={data.settings.support_email}
                                            onChange={(e) =>
                                                setData('settings', {
                                                    ...data.settings,
                                                    support_email: e.target.value,
                                                })
                                            }
                                            disabled={processing}
                                            className="mt-1.5 w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface transition focus:border-primary focus:outline-hidden"
                                        />
                                        <p className="mt-1 text-[11px] text-on-surface-variant">
                                            {settings.support_email?.description}
                                        </p>
                                    </div>

                                    <div>
                                        <label
                                            htmlFor="support_phone"
                                            className="block text-xs font-semibold text-on-surface"
                                        >
                                            Support Phone Number
                                        </label>
                                        <input
                                            id="support_phone"
                                            type="text"
                                            value={data.settings.support_phone}
                                            onChange={(e) =>
                                                setData('settings', {
                                                    ...data.settings,
                                                    support_phone: e.target.value,
                                                })
                                            }
                                            disabled={processing}
                                            className="mt-1.5 w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface transition focus:border-primary focus:outline-hidden"
                                        />
                                        <p className="mt-1 text-[11px] text-on-surface-variant">
                                            {settings.support_phone?.description}
                                        </p>
                                    </div>

                                    <div>
                                        <label
                                            htmlFor="default_report_credits"
                                            className="block text-xs font-semibold text-on-surface"
                                        >
                                            Default Report Credit Cost
                                        </label>
                                        <input
                                            id="default_report_credits"
                                            type="number"
                                            min={1}
                                            max={10}
                                            value={data.settings.default_report_credits}
                                            onChange={(e) =>
                                                setData('settings', {
                                                    ...data.settings,
                                                    default_report_credits: parseInt(e.target.value, 10) || 1,
                                                })
                                            }
                                            disabled={processing}
                                            className="mt-1.5 w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface transition focus:border-primary focus:outline-hidden"
                                        />
                                        <p className="mt-1 text-[11px] text-on-surface-variant">
                                            {settings.default_report_credits?.description}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            {/* Feature Toggles */}
                            <div className="border-t border-outline-variant/60 pt-5">
                                <h3 className="text-xs font-bold tracking-wider text-primary uppercase">
                                    Operational Flags & Feature Controls
                                </h3>
                                <div className="mt-4 space-y-4">
                                    <div className="flex items-center justify-between rounded-lg border border-outline-variant/60 bg-surface p-4">
                                        <div>
                                            <span className="block text-xs font-semibold text-on-surface">
                                                Enable Guest Checkout
                                            </span>
                                            <p className="mt-0.5 text-xs text-on-surface-variant">
                                                Allow buyers to purchase and receive vehicle check reports without creating an account first.
                                            </p>
                                        </div>
                                        <input
                                            type="checkbox"
                                            checked={data.settings.enable_guest_checkout}
                                            onChange={(e) =>
                                                setData('settings', {
                                                    ...data.settings,
                                                    enable_guest_checkout: e.target.checked,
                                                })
                                            }
                                            disabled={processing}
                                            className="h-4 w-4 rounded border-outline-variant text-primary focus:ring-primary"
                                        />
                                    </div>

                                    <div className="flex items-center justify-between rounded-lg border border-outline-variant/60 bg-surface p-4">
                                        <div>
                                            <span className="block text-xs font-semibold text-on-surface">
                                                Notify Admin on Gateway Failure
                                            </span>
                                            <p className="mt-0.5 text-xs text-on-surface-variant">
                                                Send immediate alert notifications to admin email when automated reconciliation encounters persistent errors.
                                            </p>
                                        </div>
                                        <input
                                            type="checkbox"
                                            checked={data.settings.notify_admin_on_failure}
                                            onChange={(e) =>
                                                setData('settings', {
                                                    ...data.settings,
                                                    notify_admin_on_failure: e.target.checked,
                                                })
                                            }
                                            disabled={processing}
                                            className="h-4 w-4 rounded border-outline-variant text-primary focus:ring-primary"
                                        />
                                    </div>

                                    <div className="flex items-center justify-between rounded-lg border border-amber-400/40 bg-amber-50/50 p-4">
                                        <div>
                                            <span className="block text-xs font-semibold text-amber-900">
                                                Maintenance Mode Flag
                                            </span>
                                            <p className="mt-0.5 text-xs text-amber-800/80">
                                                Display informational maintenance banner across customer vehicle check and purchase pages.
                                            </p>
                                        </div>
                                        <input
                                            type="checkbox"
                                            checked={data.settings.maintenance_mode}
                                            onChange={(e) =>
                                                setData('settings', {
                                                    ...data.settings,
                                                    maintenance_mode: e.target.checked,
                                                })
                                            }
                                            disabled={processing}
                                            className="h-4 w-4 rounded border-outline-variant text-amber-600 focus:ring-amber-500"
                                        />
                                    </div>
                                </div>
                            </div>

                            {/* Mandatory Reason */}
                            <div className="border-t border-outline-variant/60 pt-5">
                                <label
                                    htmlFor="config-reason"
                                    className="block text-xs font-semibold text-on-surface"
                                >
                                    Mandatory Audit Reason <span className="text-error">*</span>
                                </label>
                                <p className="mt-0.5 text-[11px] text-on-surface-variant">
                                    Every configuration modification must document an explicit operational justification.
                                </p>
                                <textarea
                                    id="config-reason"
                                    rows={2}
                                    value={data.reason}
                                    onChange={(e) => setData('reason', e.target.value)}
                                    disabled={processing}
                                    placeholder="e.g. Updating customer support phone line following telecommunications migration"
                                    className="mt-1.5 w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface transition focus:border-primary focus:outline-hidden disabled:opacity-50"
                                />
                                <div className="mt-1 flex items-center justify-between text-[11px]">
                                    <span
                                        className={
                                            data.reason.length < 5 && data.reason.length > 0
                                                ? 'text-error'
                                                : 'text-on-surface-variant/70'
                                        }
                                    >
                                        {data.reason.length} / 5 minimum characters
                                    </span>
                                    {errors.reason && (
                                        <span className="text-error">{errors.reason}</span>
                                    )}
                                </div>
                            </div>

                            {/* Submit Button */}
                            <div className="flex items-center justify-end gap-3 pt-3">
                                <button
                                    type="submit"
                                    disabled={!canSubmit}
                                    className="flex items-center gap-2 rounded-lg bg-primary px-5 py-2.5 text-xs font-semibold text-white transition hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {processing ? (
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                    ) : (
                                        <Save className="h-4 w-4" />
                                    )}
                                    <span>{processing ? 'Saving...' : 'Save Configuration Changes'}</span>
                                </button>
                            </div>
                        </form>
                    </div>
                )}

                {/* Tab: Infrastructure Status */}
                {activeTab === 'infrastructure' && (
                    <div className="space-y-6">
                        {/* Security Disclaimer Banner */}
                        <div className="flex items-start gap-3 rounded-xl border border-primary/20 bg-primary/5 p-4 text-xs text-primary">
                            <Lock className="h-5 w-5 shrink-0 mt-0.5" />
                            <div className="space-y-1">
                                <p className="font-bold text-on-surface">
                                    Zero-Secret Architecture Guardrail
                                </p>
                                <p className="text-on-surface-variant">
                                    Per security specification 7.2, credentials, API secrets, private keys, and signing tokens are strictly isolated in server environment variables. This dashboard reports exclusively verified operational status, modes, and configurations without exposing secret material.
                                </p>
                            </div>
                        </div>

                        {/* Gateways Grid */}
                        <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                            {/* Stripe Card */}
                            <div className="rounded-xl border border-outline-variant bg-white p-5 shadow-xs">
                                <div className="flex items-center justify-between border-b border-outline-variant/60 pb-3">
                                    <div className="flex items-center gap-2">
                                        <span className="font-sans text-sm font-bold text-on-surface">
                                            Stripe Payment Gateway
                                        </span>
                                    </div>
                                    <span
                                        className={`rounded px-2 py-0.5 font-mono text-[10px] font-bold uppercase ${
                                            infrastructure.gateways.stripe.mode === 'live'
                                                ? 'bg-emerald-100 text-emerald-800'
                                                : 'bg-amber-100 text-amber-800'
                                        }`}
                                    >
                                        Mode: {infrastructure.gateways.stripe.mode}
                                    </span>
                                </div>
                                <div className="mt-4 space-y-3 text-xs">
                                    <div className="flex items-center justify-between">
                                        <span className="text-on-surface-variant">API Key Configured</span>
                                        <span className="flex items-center gap-1 font-semibold text-on-surface">
                                            {infrastructure.gateways.stripe.configured ? (
                                                <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" />
                                            ) : (
                                                <XCircle className="h-3.5 w-3.5 text-error" />
                                            )}
                                            {infrastructure.gateways.stripe.configured ? 'Configured' : 'Missing'}
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between">
                                        <span className="text-on-surface-variant">Webhook Signature Secret</span>
                                        <span className="flex items-center gap-1 font-semibold text-on-surface">
                                            {infrastructure.gateways.stripe.webhook_configured ? (
                                                <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" />
                                            ) : (
                                                <XCircle className="h-3.5 w-3.5 text-error" />
                                            )}
                                            {infrastructure.gateways.stripe.webhook_configured ? 'Configured' : 'Missing'}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            {/* PayPal Card */}
                            <div className="rounded-xl border border-outline-variant bg-white p-5 shadow-xs">
                                <div className="flex items-center justify-between border-b border-outline-variant/60 pb-3">
                                    <div className="flex items-center gap-2">
                                        <span className="font-sans text-sm font-bold text-on-surface">
                                            PayPal Gateway
                                        </span>
                                    </div>
                                    <span
                                        className={`rounded px-2 py-0.5 font-mono text-[10px] font-bold uppercase ${
                                            infrastructure.gateways.paypal.mode === 'live'
                                                ? 'bg-emerald-100 text-emerald-800'
                                                : 'bg-blue-100 text-blue-800'
                                        }`}
                                    >
                                        Mode: {infrastructure.gateways.paypal.mode}
                                    </span>
                                </div>
                                <div className="mt-4 space-y-3 text-xs">
                                    <div className="flex items-center justify-between">
                                        <span className="text-on-surface-variant">Client ID & Secret</span>
                                        <span className="flex items-center gap-1 font-semibold text-on-surface">
                                            {infrastructure.gateways.paypal.configured ? (
                                                <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" />
                                            ) : (
                                                <XCircle className="h-3.5 w-3.5 text-error" />
                                            )}
                                            {infrastructure.gateways.paypal.configured ? 'Configured' : 'Missing'}
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between">
                                        <span className="text-on-surface-variant">Webhook ID</span>
                                        <span className="flex items-center gap-1 font-semibold text-on-surface">
                                            {infrastructure.gateways.paypal.webhook_configured ? (
                                                <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" />
                                            ) : (
                                                <XCircle className="h-3.5 w-3.5 text-error" />
                                            )}
                                            {infrastructure.gateways.paypal.webhook_configured ? 'Configured' : 'Missing'}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Core Infrastructure Row */}
                        <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
                            <div className="rounded-xl border border-outline-variant bg-white p-5 shadow-xs">
                                <div className="flex items-center gap-2 border-b border-outline-variant/60 pb-2.5">
                                    <Mail className="h-4 w-4 text-primary" />
                                    <span className="font-bold text-xs text-on-surface">Mail Driver</span>
                                </div>
                                <div className="mt-3 space-y-1 text-xs">
                                    <p className="font-semibold text-on-surface uppercase font-mono">
                                        {infrastructure.mail.driver}
                                    </p>
                                    <p className="text-on-surface-variant">Host: {infrastructure.mail.host}:{infrastructure.mail.port}</p>
                                    <p className="text-on-surface-variant">From: {infrastructure.mail.from_address}</p>
                                </div>
                            </div>

                            <div className="rounded-xl border border-outline-variant bg-white p-5 shadow-xs">
                                <div className="flex items-center gap-2 border-b border-outline-variant/60 pb-2.5">
                                    <Layers className="h-4 w-4 text-primary" />
                                    <span className="font-bold text-xs text-on-surface">Queue Worker</span>
                                </div>
                                <div className="mt-3 space-y-1 text-xs">
                                    <p className="font-semibold text-on-surface uppercase font-mono">
                                        {infrastructure.queue.driver}
                                    </p>
                                    <p className="text-on-surface-variant">Asynchronous task processing connection</p>
                                </div>
                            </div>

                            <div className="rounded-xl border border-outline-variant bg-white p-5 shadow-xs">
                                <div className="flex items-center gap-2 border-b border-outline-variant/60 pb-2.5">
                                    <Database className="h-4 w-4 text-primary" />
                                    <span className="font-bold text-xs text-on-surface">Storage Disk</span>
                                </div>
                                <div className="mt-3 space-y-1 text-xs">
                                    <p className="font-semibold text-on-surface uppercase font-mono">
                                        {infrastructure.storage.disk}
                                    </p>
                                    <p className="text-on-surface-variant">Local PDF and report document storage</p>
                                </div>
                            </div>
                        </div>

                        {/* Vehicle Data Providers Card */}
                        <div className="rounded-xl border border-outline-variant bg-white p-5 shadow-xs">
                            <h3 className="font-sans text-sm font-bold text-on-surface">
                                Vehicle Report Providers
                            </h3>
                            <p className="text-xs text-on-surface-variant">
                                Status of external vehicle check APIs configured in application environment
                            </p>
                            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {Object.entries(infrastructure.providers).map(([key, prov]) => (
                                    <div
                                        key={key}
                                        className="rounded-lg border border-outline-variant/60 bg-surface p-3.5"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="font-semibold text-xs text-on-surface">
                                                {prov.name}
                                            </span>
                                            <span
                                                className={`rounded px-1.5 py-0.5 text-[10px] font-bold ${
                                                    prov.configured
                                                        ? 'bg-emerald-100 text-emerald-800'
                                                        : 'bg-error/10 text-error'
                                                }`}
                                            >
                                                {prov.configured ? 'Configured' : 'Missing Key'}
                                            </span>
                                        </div>
                                        <p className="mt-1 text-[11px] text-on-surface-variant">
                                            {prov.role}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* Credit Plans & Subscription Catalog */}
                        <div className="rounded-xl border border-outline-variant bg-white p-5 shadow-xs">
                            <div className="flex items-center justify-between border-b border-outline-variant/60 pb-3">
                                <div>
                                    <h3 className="font-sans text-sm font-bold text-on-surface">
                                        Credit Plans & Subscription Catalog
                                    </h3>
                                    <p className="text-xs text-on-surface-variant">
                                        Defined pricing plans currently available for customer checkout
                                    </p>
                                </div>
                                <span className="rounded bg-surface-container px-2.5 py-1 text-[10px] font-semibold text-on-surface-variant">
                                    Static Config v1
                                </span>
                            </div>

                            <div className="mt-3 rounded-lg border border-amber-300 bg-amber-50/70 p-3 text-xs text-amber-900">
                                <p className="font-semibold">Notice regarding dynamic catalog changes:</p>
                                <p className="mt-0.5 text-[11px] text-amber-800">
                                    {infrastructure.catalog_sync_notice}
                                </p>
                            </div>

                            <div className="mt-4 overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead>
                                        <tr className="border-b border-outline-variant/60 bg-surface-container/30 text-on-surface-variant">
                                            <th className="px-3 py-2 font-semibold">Plan Identifier</th>
                                            <th className="px-3 py-2 font-semibold">Label</th>
                                            <th className="px-3 py-2 font-semibold">Category</th>
                                            <th className="px-3 py-2 font-semibold">Credits</th>
                                            <th className="px-3 py-2 font-semibold">Amount</th>
                                            <th className="px-3 py-2 font-semibold">Stripe / PayPal Synced</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-outline-variant/40">
                                        {infrastructure.plans.map((plan) => (
                                            <tr key={plan.slug} className="hover:bg-surface-container/20">
                                                <td className="px-3 py-2 font-mono font-medium text-on-surface">
                                                    {plan.slug}
                                                </td>
                                                <td className="px-3 py-2 text-on-surface">{plan.label}</td>
                                                <td className="px-3 py-2 capitalize text-on-surface-variant">
                                                    {plan.type.replace('_', ' ')}
                                                </td>
                                                <td className="px-3 py-2 font-semibold text-on-surface">
                                                    {plan.credits}
                                                </td>
                                                <td className="px-3 py-2 font-semibold text-on-surface">
                                                    {plan.amount_display}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <div className="flex items-center gap-1.5">
                                                        <span
                                                            className={`rounded px-1.5 py-0.2 font-mono text-[9px] ${
                                                                plan.has_stripe_price
                                                                    ? 'bg-emerald-100 text-emerald-800'
                                                                    : 'bg-surface-container text-on-surface-variant'
                                                            }`}
                                                        >
                                                            Stripe
                                                        </span>
                                                        {plan.type === 'subscription' && (
                                                            <span
                                                                className={`rounded px-1.5 py-0.2 font-mono text-[9px] ${
                                                                    plan.has_paypal_plan
                                                                        ? 'bg-blue-100 text-blue-800'
                                                                        : 'bg-surface-container text-on-surface-variant'
                                                                }`}
                                                            >
                                                                PayPal
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
