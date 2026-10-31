import { useForm } from '@inertiajs/react';
import { AlertTriangle, Loader2, UserPlus, X } from 'lucide-react';
import React from 'react';

interface InviteAdminModalProps {
    isOpen: boolean;
    onClose: () => void;
}

export default function InviteAdminModal({
    isOpen,
    onClose,
}: InviteAdminModalProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        name: '',
        role: 'admin' as 'admin' | 'super_admin',
        reason: '',
        confirm_elevation: false,
    });

    if (!isOpen) {
        return null;
    }

    const isReasonValid = data.reason.trim().length >= 5;
    const isEmailValid = data.email.trim().includes('@');
    const isNameValid = data.name.trim().length >= 2;
    const canSubmit =
        isReasonValid && isEmailValid && isNameValid && !processing;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (!canSubmit) {
            return;
        }

        post('/admin/administration/invitations', {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs">
            <div className="relative w-full max-w-lg rounded-xl border border-outline-variant bg-white shadow-2xl">
                {/* Header accent */}
                <div className="h-1.5 rounded-t-xl bg-primary" />

                <div className="p-6">
                    <div className="flex items-center justify-between border-b border-outline-variant/60 pb-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <UserPlus className="h-5 w-5" />
                            </div>
                            <div>
                                <h3 className="font-sans text-base font-bold text-on-surface">
                                    Invite Administrator
                                </h3>
                                <p className="text-xs text-on-surface-variant">
                                    Issue access credentials via secure bound
                                    invitation
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={processing}
                            className="rounded-lg p-1.5 text-on-surface-variant/70 transition hover:bg-surface-container hover:text-on-surface disabled:opacity-50"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    </div>

                    <form onSubmit={handleSubmit} className="mt-5 space-y-4">
                        <div>
                            <label
                                htmlFor="invite-name"
                                className="block text-xs font-semibold text-on-surface"
                            >
                                Full Name <span className="text-error">*</span>
                            </label>
                            <input
                                id="invite-name"
                                type="text"
                                value={data.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                                disabled={processing}
                                placeholder="e.g. Eleanor Vance"
                                className="mt-1.5 w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface transition focus:border-primary focus:outline-hidden disabled:opacity-50"
                            />
                            {errors.name && (
                                <p className="mt-1 text-xs text-error">
                                    {errors.name}
                                </p>
                            )}
                        </div>

                        <div>
                            <label
                                htmlFor="invite-email"
                                className="block text-xs font-semibold text-on-surface"
                            >
                                Official Email Address{' '}
                                <span className="text-error">*</span>
                            </label>
                            <input
                                id="invite-email"
                                type="email"
                                value={data.email}
                                onChange={(e) =>
                                    setData('email', e.target.value)
                                }
                                disabled={processing}
                                placeholder="eleanor.vance@vincheck.test"
                                className="mt-1.5 w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface transition focus:border-primary focus:outline-hidden disabled:opacity-50"
                            />
                            {errors.email && (
                                <p className="mt-1 text-xs text-error">
                                    {errors.email}
                                </p>
                            )}
                        </div>

                        <div>
                            <label
                                htmlFor="invite-role"
                                className="block text-xs font-semibold text-on-surface"
                            >
                                Administrative Role{' '}
                                <span className="text-error">*</span>
                            </label>
                            <select
                                id="invite-role"
                                value={data.role}
                                onChange={(e) =>
                                    setData(
                                        'role',
                                        e.target.value as
                                            'admin' | 'super_admin',
                                    )
                                }
                                disabled={processing}
                                className="mt-1.5 w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface transition focus:border-primary focus:outline-hidden disabled:opacity-50"
                            >
                                <option value="admin">
                                    Operational Admin (Standard Console Access)
                                </option>
                                <option value="super_admin">
                                    Super Admin (Full System & Config Control)
                                </option>
                            </select>
                            <p className="mt-1 text-[11px] text-on-surface-variant">
                                Super Admins have unrestricted access to system
                                configuration, diagnostics, and administrator
                                provisioning.
                            </p>
                        </div>

                        <div>
                            <label
                                htmlFor="invite-reason"
                                className="block text-xs font-semibold text-on-surface"
                            >
                                Audit Reason{' '}
                                <span className="text-error">*</span>
                            </label>
                            <textarea
                                id="invite-reason"
                                rows={2}
                                value={data.reason}
                                onChange={(e) =>
                                    setData('reason', e.target.value)
                                }
                                disabled={processing}
                                placeholder="e.g. Onboarding new Tier-2 operational analyst"
                                className="mt-1.5 w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface transition focus:border-primary focus:outline-hidden disabled:opacity-50"
                            />
                            <div className="mt-1 flex items-center justify-between text-[11px]">
                                <span
                                    className={
                                        data.reason.length < 5 &&
                                        data.reason.length > 0
                                            ? 'text-error'
                                            : 'text-on-surface-variant/70'
                                    }
                                >
                                    {data.reason.length} / 5 minimum characters
                                </span>
                                {errors.reason && (
                                    <span className="text-error">
                                        {errors.reason}
                                    </span>
                                )}
                            </div>
                        </div>

                        {/* Customer Privilege Elevation Warning & Explicit Confirmation (Spec 7.3) */}
                        <div className="rounded-lg border border-amber-300 bg-amber-50/70 p-3.5">
                            <div className="flex items-start gap-2.5">
                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                                <div className="space-y-1">
                                    <p className="text-xs font-bold text-amber-900">
                                        Privilege Escalation Notice (Spec 7.3)
                                    </p>
                                    <p className="text-[11px] leading-relaxed text-amber-800">
                                        If this email address belongs to an
                                        existing registered customer, issuing
                                        this invitation will elevate their
                                        access to administrative permissions
                                        upon acceptance.
                                    </p>
                                </div>
                            </div>
                            <label className="mt-2.5 flex cursor-pointer items-start gap-2 text-xs font-medium text-amber-950">
                                <input
                                    type="checkbox"
                                    checked={data.confirm_elevation}
                                    onChange={(e) =>
                                        setData(
                                            'confirm_elevation',
                                            e.target.checked,
                                        )
                                    }
                                    disabled={processing}
                                    className="mt-0.5 h-3.5 w-3.5 rounded border-amber-400 text-primary focus:ring-primary"
                                />
                                <span>
                                    I explicitly confirm privilege escalation if
                                    this email belongs to an existing customer.
                                </span>
                            </label>
                            {errors.confirm_elevation && (
                                <p className="mt-1 text-xs font-medium text-error">
                                    {errors.confirm_elevation}
                                </p>
                            )}
                        </div>

                        <div className="mt-6 flex items-center justify-end gap-3 border-t border-outline-variant/60 pt-3">
                            <button
                                type="button"
                                onClick={onClose}
                                disabled={processing}
                                className="rounded-lg border border-outline-variant px-4 py-2 text-xs font-semibold text-on-surface transition hover:bg-surface-container disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={!canSubmit}
                                className="hover:bg-primary-hover flex items-center gap-2 rounded-lg bg-primary px-5 py-2 text-xs font-semibold text-white transition disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {processing && (
                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                )}
                                <span>
                                    {processing
                                        ? 'Issuing...'
                                        : 'Issue Invitation'}
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
}
