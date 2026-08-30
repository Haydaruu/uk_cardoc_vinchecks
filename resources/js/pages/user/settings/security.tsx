import SettingsLayout from "@/layouts/settings/settings-layout";
import { useForm, usePage, router } from "@inertiajs/react";
import { useState } from "react";
import {
    KeyRound,
    MonitorSmartphone,
    Laptop,
    Smartphone,
    TriangleAlert,
} from 'lucide-react';


type SessionData = {
    key: string;
    device: string;
    browser: string;
    last_activity: number;
    is_current: boolean;
};

type SecurityData = {
    has_password: boolean;
    sessions: SessionData[];
};

type Props = {
    security: SecurityData;
}

type SharedProps = {
    flash?: {
        success?: string | null;
        error?: string | null;
    };

    errors?: {
        account?: string;
    };
};

export default function Security({ security }: Props) {
    const { flash, errors } = usePage<SharedProps>().props;

    const form = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const deleteForm = useForm({
        current_password: '',
        confirmation: '',
    });

    const [deleteModalOpen, setDeleteModalOpen] = useState(false);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        form.put('/settings/security/password', {
            preserveScroll: true,
            errorBag: 'updatePassword',

            onSuccess: () => {
                form.reset();
            },
        });
    };

    const submitDeleteAccount = (e: React.FormEvent) => {
        e.preventDefault();

        deleteForm.delete('/settings/security/account', {
            preserveScroll: true,

            onSuccess: () => {
                setDeleteModalOpen(false);
                deleteForm.reset();
            },
        });
    };

    const closeDeleteModal = () => {
        if (deleteForm.processing) {
            return;
        }

        setDeleteModalOpen(false);
        deleteForm.reset();
        deleteForm.clearErrors();
    };

    const revokeSession = (key:string) => {
        const confirmed = window.confirm(
            'Are you sure you want to sign out this session?',
        );

        if (!confirmed) {
            return;
        }
        router.delete(
            `/settings/security/sessions/${key}`,
            {
                preserveScroll: true,
            }
        );
    };

    const submitLabel = security.has_password
        ? 'Update Password'
        : 'Set Password';

    return (
        <SettingsLayout>
            <div className="min-h-screen bg-surface">
                <div className="mx-auto max-w-container-max px-margin-mobile py-10 md:px-gutter lg:py-section-padding">

                    {/* Page Header */}
                    <header className="mb-10 lg:mb-12">
                        <h1 className="text-h1 font-bold text-primary">
                            Security
                        </h1>

                        <p className="mt-2 text-body-lg text-on-surface-variant">
                            Manage your password and account security.
                        </p>

                        <div className="sovereign-line mt-6" />
                    </header>

                    {/* Feedback */}
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

                    {/* Security Grid */}
                    <div className="grid grid-cols-1 gap-8 lg:grid-cols-3">

                        {/* Left Column */}
                        <div className="flex flex-col gap-8 lg:col-span-1">

                            {/* Password */}
                            <section className="rounded-lg border border-outline-variant bg-surface-container-lowest p-6 shadow-sm">
                                <div className="mb-6 flex items-center gap-2">
                                    <KeyRound className="h-6 w-6 text-primary" />

                                    <h2 className="text-h3 font-semibold text-primary">
                                        Password
                                    </h2>
                                </div>

                                {!security.has_password && (
                                    <div className="mb-5 border border-outline-variant bg-surface-container-low px-4 py-3 text-sm leading-relaxed text-on-surface-variant">
                                        You currently sign in using a connected
                                        account. Set a password to also enable
                                        email and password sign-in.
                                    </div>
                                )}

                                <form
                                    onSubmit={submit}
                                    className="flex flex-col gap-5"
                                >
                                    {security.has_password && (
                                        <div>
                                            <label
                                                htmlFor="current_password"
                                                className="mb-2 block text-label-sm font-semibold text-on-surface-variant"
                                            >
                                                Current Password
                                            </label>

                                            <input
                                                id="current_password"
                                                type="password"
                                                autoComplete="current-password"
                                                value={
                                                    form.data.current_password
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'current_password',
                                                        e.target.value,
                                                    )
                                                }
                                                className="w-full rounded border border-outline-variant bg-surface p-3 text-body-md text-on-surface outline-none transition-shadow focus:border-primary focus:ring-1 focus:ring-primary"
                                            />

                                            {form.errors.current_password && (
                                                <p className="mt-2 text-sm text-error">
                                                    {
                                                        form.errors
                                                            .current_password
                                                    }
                                                </p>
                                            )}
                                        </div>
                                    )}

                                    <div>
                                        <label
                                            htmlFor="password"
                                            className="mb-2 block text-label-sm font-semibold text-on-surface-variant"
                                        >
                                            New Password
                                        </label>

                                        <input
                                            id="password"
                                            type="password"
                                            autoComplete="new-password"
                                            value={form.data.password}
                                            onChange={(e) =>
                                                form.setData(
                                                    'password',
                                                    e.target.value,
                                                )
                                            }
                                            className="w-full rounded border border-outline-variant bg-surface p-3 text-body-md text-on-surface outline-none transition-shadow focus:border-primary focus:ring-1 focus:ring-primary"
                                        />

                                        {form.errors.password && (
                                            <p className="mt-2 text-sm text-error">
                                                {form.errors.password}
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <label
                                            htmlFor="password_confirmation"
                                            className="mb-2 block text-label-sm font-semibold text-on-surface-variant"
                                        >
                                            Confirm New Password
                                        </label>

                                        <input
                                            id="password_confirmation"
                                            type="password"
                                            autoComplete="new-password"
                                            value={
                                                form.data.password_confirmation
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'password_confirmation',
                                                    e.target.value,
                                                )
                                            }
                                            className="w-full rounded border border-outline-variant bg-surface p-3 text-body-md text-on-surface outline-none transition-shadow focus:border-primary focus:ring-1 focus:ring-primary"
                                        />

                                        {form.errors
                                            .password_confirmation && (
                                            <p className="mt-2 text-sm text-error">
                                                {
                                                    form.errors
                                                        .password_confirmation
                                                }
                                            </p>
                                        )}
                                    </div>

                                    <button
                                        type="submit"
                                        disabled={
                                            form.processing || !form.isDirty
                                        }
                                        className="mt-2 w-full rounded bg-secondary py-3 text-label-sm font-semibold text-on-secondary shadow-sm transition-colors hover:bg-secondary-container focus:ring-2 focus:ring-secondary focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {form.processing
                                            ? 'Saving...'
                                            : submitLabel}
                                    </button>
                                </form>
                            </section>

                            {/* Danger Zone */}
                            <section className="rounded-lg border border-error-container bg-error-container/25 p-6 shadow-sm">
                                <div className="mb-4 flex items-center gap-2">
                                    <TriangleAlert className="h-6 w-6 text-error" />

                                    <h2 className="text-h3 font-semibold text-error">
                                        Danger Zone
                                    </h2>
                                </div>

                                <p className="mb-6 text-body-md text-on-surface-variant">
                                    Once you delete your account, there is no
                                    going back. Please be certain.
                                </p>

                                <button
                                    type="button"
                                    onClick={() => setDeleteModalOpen(true)}
                                    className="w-full rounded border border-error py-3 text-label-sm font-semibold text-error transition-colors hover:bg-error hover:text-on-error"
                                >
                                    Delete Account
                                </button>
                                 
                                   {deleteModalOpen && (
                                        <div
                                            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
                                            onClick={closeDeleteModal}
                                        >
                                            <div
                                                className="w-full max-w-md rounded-lg border border-outline-variant bg-surface-container-lowest p-6 shadow-xl"
                                                onClick={(e) => e.stopPropagation()}
                                            >
                                                <div className="mb-5 flex items-start gap-3">
                                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-error-container">
                                                        <TriangleAlert className="h-5 w-5 text-error" />
                                                    </div>

                                                    <div>
                                                        <h2 className="text-xl font-semibold text-primary">
                                                            Delete Account
                                                        </h2>

                                                        <p className="mt-1 text-sm leading-relaxed text-on-surface-variant">
                                                            This action will permanently deactivate your account
                                                            and sign you out from all devices.
                                                        </p>
                                                    </div>
                                                </div>

                                                <form
                                                    onSubmit={submitDeleteAccount}
                                                    className="space-y-5"
                                                >
                                                    {security.has_password && (
                                                        <div>
                                                            <label
                                                                htmlFor="delete_current_password"
                                                                className="mb-2 block text-label-sm font-semibold text-on-surface-variant"
                                                            >
                                                                Current Password
                                                            </label>

                                                            <input
                                                                id="delete_current_password"
                                                                type="password"
                                                                autoComplete="current-password"
                                                                value={deleteForm.data.current_password}
                                                                onChange={(e) =>
                                                                    deleteForm.setData(
                                                                        'current_password',
                                                                        e.target.value,
                                                                    )
                                                                }
                                                                className="w-full rounded border border-outline-variant bg-surface p-3 text-body-md outline-none focus:border-error focus:ring-1 focus:ring-error"
                                                            />

                                                            {deleteForm.errors.current_password && (
                                                                <p className="mt-2 text-sm text-error">
                                                                    {deleteForm.errors.current_password}
                                                                </p>
                                                            )}
                                                        </div>
                                                    )}

                                                    <div>
                                                        <label
                                                            htmlFor="delete_confirmation"
                                                            className="mb-2 block text-label-sm font-semibold text-on-surface-variant"
                                                        >
                                                            Type DELETE to confirm
                                                        </label>

                                                        <input
                                                            id="delete_confirmation"
                                                            type="text"
                                                            value={deleteForm.data.confirmation}
                                                            onChange={(e) =>
                                                                deleteForm.setData(
                                                                    'confirmation',
                                                                    e.target.value,
                                                                )
                                                            }
                                                            placeholder="DELETE"
                                                            autoComplete="off"
                                                            className="w-full rounded border border-outline-variant bg-surface p-3 text-body-md outline-none focus:border-error focus:ring-1 focus:ring-error"
                                                        />

                                                        {deleteForm.errors.confirmation && (
                                                            <p className="mt-2 text-sm text-error">
                                                                {deleteForm.errors.confirmation}
                                                            </p>
                                                        )}
                                                    </div>

                                                    {errors?.account && (
                                                        <div className="border border-error-container bg-error-container/30 px-4 py-3 text-sm text-error">
                                                            {errors.account}
                                                        </div>
                                                    )}

                                                    <div className="flex gap-3 pt-2">
                                                        <button
                                                            type="button"
                                                            onClick={closeDeleteModal}
                                                            disabled={deleteForm.processing}
                                                            className="flex-1 rounded border border-outline-variant px-4 py-3 text-sm font-semibold text-primary transition-colors hover:bg-surface-container-low disabled:opacity-50"
                                                        >
                                                            Cancel
                                                        </button>

                                                        <button
                                                            type="submit"
                                                            disabled={
                                                                deleteForm.processing ||
                                                                deleteForm.data.confirmation !== 'DELETE' ||
                                                                (
                                                                    security.has_password &&
                                                                    !deleteForm.data.current_password
                                                                )
                                                            }
                                                            className="flex-1 rounded bg-error px-4 py-3 text-sm font-semibold text-on-error transition-opacity disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            {deleteForm.processing
                                                                ? 'Deleting...'
                                                                : 'Delete Account'}
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    )}
                            </section>
                        </div>

                        {/* Right Column — Active Sessions */}
                        <div className="lg:col-span-2">
                            <section className="overflow-hidden rounded-lg border border-outline-variant bg-surface-container-lowest shadow-sm">

                                {/* Sessions Header */}
                                <div className="flex items-center justify-between border-b border-outline-variant bg-surface-container-low p-6">
                                    <div className="flex items-center gap-2">
                                        <MonitorSmartphone className="h-6 w-6 text-primary" />

                                        <h2 className="text-h3 font-semibold text-primary">
                                            Active Sessions
                                        </h2>
                                    </div>

                                    <span className="rounded-full border border-outline-variant bg-surface px-3 py-1 text-label-sm text-on-surface-variant">
                                        {security.sessions.length}{' '}
                                        {security.sessions.length === 1
                                            ? 'Active'
                                            : 'Active'}
                                    </span>
                                </div>

                                {/* Sessions Table */}
                                <div className="overflow-x-auto">
                                    <table className="w-full border-collapse text-left">
                                        <thead className="bg-surface text-label-sm uppercase tracking-wider text-on-surface-variant">
                                            <tr>
                                                <th className="border-b border-outline-variant p-4">
                                                    Device
                                                </th>

                                                <th className="border-b border-outline-variant p-4">
                                                    Browser
                                                </th>

                                                <th className="border-b border-outline-variant p-4">
                                                    Last Active
                                                </th>

                                                <th className="border-b border-outline-variant p-4 text-right">
                                                    Action
                                                </th>
                                            </tr>
                                        </thead>

                                        <tbody className="text-body-md text-on-surface">
                                            {security.sessions.map(
                                                (session) => (
                                                    <tr
                                                        key={session.key}
                                                        className={
                                                            session.is_current
                                                                ? 'bg-surface-container-low'
                                                                : 'transition-colors hover:bg-surface-container-low'
                                                        }
                                                    >
                                                        <td
                                                            className={`border-b border-outline-variant p-4 ${
                                                                session.is_current
                                                                    ? 'border-l-4 border-l-secondary'
                                                                    : 'border-l-4 border-l-transparent'
                                                            }`}
                                                        >
                                                            <div className="flex items-center gap-3">
                                                                <span className="text-on-surface-variant">
                                                                    <DeviceIcon
                                                                        device={
                                                                            session.device
                                                                        }
                                                                    />
                                                                </span>

                                                                <span
                                                                    className={
                                                                        session.is_current
                                                                            ? 'font-semibold text-primary'
                                                                            : ''
                                                                    }
                                                                >
                                                                    {
                                                                        session.device
                                                                    }
                                                                </span>
                                                            </div>
                                                        </td>

                                                        <td className="border-b border-outline-variant p-4">
                                                            {session.browser}
                                                        </td>

                                                        <td
                                                            className={`border-b border-outline-variant p-4 ${
                                                                session.is_current
                                                                    ? 'font-medium text-secondary'
                                                                    : 'text-on-surface-variant'
                                                            }`}
                                                        >
                                                            {formatLastActive(
                                                                session.last_activity,
                                                            )}

                                                            {session.is_current &&
                                                                ' (Current)'}
                                                        </td>

                                                        <td className="border-b border-outline-variant p-4 text-right">
                                                            {session.is_current ? (
                                                                <span className="text-label-sm text-on-surface-variant opacity-60">
                                                                    Current
                                                                </span>
                                                            ) : (
                                                                <button
                                                                    type="button"
                                                                    onClick={() =>
                                                                        revokeSession(
                                                                            session.key,
                                                                        )
                                                                    }
                                                                    className="text-label-sm font-semibold text-primary underline transition-colors hover:text-secondary"
                                                                >
                                                                    Sign Out
                                                                </button>
                                                            )}
                                                        </td>
                                                    </tr>
                                                ),
                                            )}

                                            {security.sessions.length ===
                                                0 && (
                                                <tr>
                                                    <td
                                                        colSpan={4}
                                                        className="p-8 text-center text-on-surface-variant"
                                                    >
                                                        No active sessions
                                                        found.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    );
}

function formatLastActive(timestamp: number): string {
    const now = Math.floor(Date.now() / 1000);
    const diff = now - timestamp;

    if( diff < 60 ) {
        return 'Just Now';
    }

    if (diff < 3600){
        const minutes = Math.floor(diff / 60);

        return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;
    }

    if (diff < 86400) {
        const hours = Math.floor(diff / 3600);

        return `${hours} hour${hours === 1 ? '' : 's'} ago`;
    }

    const days = Math.floor(diff / 86400);

    return `${days} day${days === 1 ? '' : 's'} ago`;

}

    function DeviceIcon({ device }: { device: string }) {
        const value = device.toLowerCase();

        if(
            value.includes('iphone') ||
            value.includes('android') ||
            value.includes('mobile') 
        ) {
            return <Smartphone className="h-5 w-5"/>;
        }

        if(
            value.includes('windows') ||
            value.includes('mac') ||
            value.includes('linux') 
        ) {
            return <MonitorSmartphone className="h-5 w-5"/>;
        }
    }