import SettingsLayout from "@/layouts/settings/settings-layout";
import { router, usePage } from "@inertiajs/react";
import { AlertTriangle, Info } from 'lucide-react';
import { useState } from 'react';


type ProviderAccount = {
    connected: boolean;
    email: string | null;
    can_disconnect: boolean;
};

type connectedAccountsData = {
    has_password: boolean;
    google: ProviderAccount;
    microsoft: ProviderAccount;
};

type Props = {
    connectedAccounts: connectedAccountsData;
};

type SharedProps = {
    flash?: {
        success?: string | null;
        error?: string | null;
    };
};

type Provider = 'google' | 'microsoft';

export default function ConnectedAccounts({ connectedAccounts }: Props) {
    const { flash } = usePage<SharedProps>().props;
    const [ disconnectingProvider, setDisconnectingProvider] = 
        useState<Provider | null>(null);
    const disconnectProvider = (
            provider: 'google' | 'microsoft',
        ) => {
            const confirmed = window.confirm(
                `Disconnect your ${provider} accounts?`,
            );

            if(!confirmed) {
                return;
            }

            router.delete(
                `/settings/connected-accounts/${provider}`,
            {
                preserveScroll: true,

                onFinish: () => {
                    setDisconnectingProvider(null);
                },
            },
        );
    };
    return (
        <SettingsLayout>
            <div className="min-h-screen bg-surface">
                <div className="mx-auto w-full max-w-3xl px-margin-mobile py-10 md:px-gutter lg:py-section-padding">

                    {/* Page Header */}
                    <header className="mb-10">
                        <h1 className="text-h1 font-bold tracking-tight text-primary">
                            Connected Accounts
                        </h1>

                        <p className="mt-2 max-w-xl text-body-lg text-on-surface-variant">
                            Manage the external accounts you use to sign in.
                        </p>

                        <div className="sovereign-line mt-6" />
                    </header>

                    {/* Flash */}
                    {flash?.success && (
                        <div className="mb-6 border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                            {flash.success}
                        </div>
                    )}

                    {flash?.error && (
                        <div className="mb-6 border border-error-container bg-error-container/40 px-4 py-3 text-sm text-error">
                            {flash.error}
                        </div>
                    )}

                    <div className="space-y-6">

                        {/* Google */}
                        <ProviderCard
                            provider="google"
                            title="GOOGLE"
                            account={connectedAccounts.google}
                            disconnecting={
                                disconnectingProvider === 'google'
                            }
                            onDisconnect={() =>
                                disconnectProvider('google')
                            }
                        />

                        {/* Microsoft */}
                        <ProviderCard
                            provider="microsoft"
                            title="MICROSOFT"
                            account={connectedAccounts.microsoft}
                            disconnecting={
                                disconnectingProvider ===
                                'microsoft'
                            }
                            onDisconnect={() =>
                                disconnectProvider('microsoft')
                            }
                        />

                        {/* Footer note */}
                        <div className="mt-8 flex items-start gap-2 text-primary">
                            <Info className="mt-0.5 h-5 w-5 shrink-0" />

                            <p className="text-label-sm font-semibold leading-relaxed">
                                Keep at least one sign-in method
                                connected to avoid losing access to
                                your account.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    );
}

type ProviderCardProps = {
    provider: Provider;
    title: string;
    account: ProviderAccount;
    disconnecting: boolean;
    onDisconnect: () => void;
};

function ProviderCard({
    provider,
    title,
    account,
    disconnecting,
    onDisconnect,
}: ProviderCardProps) {
    const connectUrl = `/settings/connected-accounts/${provider}/redirect`;
    return (
        <section className="rounded-lg border border-outline-variant bg-surface-container-lowest p-6 shadow-sm transition-shadow hover:shadow-md">

            <div className="flex flex-col gap-6 md:flex-row md:items-center md:justify-between">

                {/* Provider */}
                <div className="flex items-center gap-4">
                    <div
                        className={`flex h-12 w-12 shrink-0 items-center justify-center rounded border border-outline-variant bg-surface-container-low ${
                            !account.connected
                                ? 'opacity-70'
                                : ''
                        }`}
                    >
                        {provider === 'google' ? (
                            <GoogleLogo />
                        ) : (
                            <MicrosoftLogo />
                        )}
                    </div>

                    <div>
                        <h2 className="text-h3 font-semibold text-primary">
                            {title}
                        </h2>

                        <div className="mt-1 flex items-center gap-2">
                            <span
                                className={`h-2 w-2 rounded-full ${
                                    account.connected
                                        ? 'bg-green-500'
                                        : 'bg-outline'
                                }`}
                            />

                            <span className="text-label-sm text-on-surface-variant">
                                {account.connected
                                    ? 'Connected'
                                    : 'Not Connected'}
                            </span>
                        </div>

                        {account.connected && (
                            <p className="mt-1 break-all text-body-md text-on-surface">
                                {account.email ??
                                    'Email unavailable'}
                            </p>
                        )}
                    </div>
                </div>

                {/* Action */}
                {account.connected ? (
                    <button
                        type="button"
                        disabled={
                            !account.can_disconnect ||
                            disconnecting
                        }
                        onClick={onDisconnect}
                        className={`shrink-0 rounded px-6 py-2 text-label-sm font-semibold transition-colors ${
                            account.can_disconnect
                                ? 'border-2 border-outline-variant text-primary hover:border-primary hover:bg-surface-container-low'
                                : 'cursor-not-allowed border-2 border-outline-variant text-outline opacity-60'
                        }`}
                    >
                        {disconnecting
                            ? 'Disconnecting...'
                            : 'Disconnect'}
                    </button>
                ) : (
                    <a
                        href={connectUrl}
                        className="shrink-0 rounded bg-secondary px-6 py-2 text-center text-label-sm font-semibold text-on-secondary transition-colors hover:bg-secondary-container"
                    >
                        Connect
                    </a>
                )}
            </div>

            {/* Only login method warning */}
            {account.connected &&
                !account.can_disconnect && (
                    <div className="mt-4 flex items-start gap-2 rounded border border-outline-variant bg-surface-container-low p-3">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-secondary" />

                        <p className="text-label-sm leading-relaxed text-on-surface-variant">
                            {title.charAt(0) +
                                title
                                    .slice(1)
                                    .toLowerCase()}{' '}
                            cannot be disconnected because it is
                            currently your only sign-in method.
                            Keep at least one sign-in method
                            connected to avoid losing access to
                            your account.
                        </p>
                    </div>
                )}
        </section>
    );
}

function GoogleLogo() {
    return (
        <svg
            viewBox="0 0 24 24"
            className="h-6 w-6"
            aria-label="Google"
        >
            <path
                fill="#4285F4"
                d="M21.6 12.23c0-.71-.06-1.4-.18-2.06H12v3.9h5.38a4.6 4.6 0 0 1-1.99 3.02v2.53h3.23c1.9-1.75 2.98-4.33 2.98-7.39Z"
            />
            <path
                fill="#34A853"
                d="M12 22c2.7 0 4.97-.9 6.62-2.38l-3.23-2.53c-.9.6-2.05.96-3.39.96-2.61 0-4.82-1.76-5.61-4.13H3.05v2.61A10 10 0 0 0 12 22Z"
            />
            <path
                fill="#FBBC05"
                d="M6.39 13.92A6.02 6.02 0 0 1 6.08 12c0-.67.11-1.32.31-1.92V7.47H3.05A10 10 0 0 0 2 12c0 1.61.38 3.13 1.05 4.53l3.34-2.61Z"
            />
            <path
                fill="#EA4335"
                d="M12 5.95c1.47 0 2.79.5 3.83 1.5l2.87-2.88A9.63 9.63 0 0 0 12 2a10 10 0 0 0-8.95 5.47l3.34 2.61C7.18 7.71 9.39 5.95 12 5.95Z"
            />
        </svg>
    );
}

function MicrosoftLogo() {
    return (
        <svg
            viewBox="0 0 24 24"
            className="h-6 w-6"
            aria-label="Microsoft"
        >
            <path fill="#F25022" d="M2 2h9v9H2z" />
            <path fill="#7FBA00" d="M13 2h9v9h-9z" />
            <path fill="#00A4EF" d="M2 13h9v9H2z" />
            <path fill="#FFB900" d="M13 13h9v9h-9z" />
        </svg>
    );
}