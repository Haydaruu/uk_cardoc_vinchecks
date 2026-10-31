import { useForm } from '@inertiajs/react';
import { AlertTriangle, Loader2, ShieldAlert, X } from 'lucide-react';
import React from 'react';

interface ChangeAdminRoleModalProps {
    isOpen: boolean;
    onClose: () => void;
    admin: {
        id: number;
        name: string;
        email: string;
        role: string;
    } | null;
}

export default function ChangeAdminRoleModal({
    isOpen,
    onClose,
    admin,
}: ChangeAdminRoleModalProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        role: (admin?.role === 'super_admin' ? 'admin' : 'super_admin') as
            'admin' | 'super_admin',
        reason: '',
    });

    if (!isOpen || !admin) {
        return null;
    }

    const isReasonValid = data.reason.trim().length >= 5;
    const isRoleChanged = data.role !== admin.role;
    const canSubmit = isReasonValid && isRoleChanged && !processing;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (!canSubmit) {
            return;
        }

        post(`/admin/administration/admins/${admin.id}/role`, {
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
                {/* Accent line */}
                <div className="h-1.5 rounded-t-xl bg-amber-500" />

                <div className="p-6">
                    <div className="flex items-center justify-between border-b border-outline-variant/60 pb-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600">
                                <ShieldAlert className="h-5 w-5" />
                            </div>
                            <div>
                                <h3 className="font-sans text-base font-bold text-on-surface">
                                    Change Administrator Role
                                </h3>
                                <p className="text-xs text-on-surface-variant">
                                    {admin.name} ({admin.email})
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
                        {admin.role === 'super_admin' &&
                            data.role === 'admin' && (
                                <div className="flex items-start gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-800">
                                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                                    <div className="space-y-1">
                                        <p className="font-semibold text-amber-900">
                                            Super Admin Demotion Warning
                                        </p>
                                        <p>
                                            Demoting this account to Operational
                                            Admin will immediately revoke their
                                            access to System Configuration,
                                            Administration, and System Health.
                                            The backend strictly protects
                                            against removing the last active
                                            Super Admin.
                                        </p>
                                    </div>
                                </div>
                            )}

                        <div>
                            <label
                                htmlFor="new-role"
                                className="block text-xs font-semibold text-on-surface"
                            >
                                Target Role{' '}
                                <span className="text-error">*</span>
                            </label>
                            <select
                                id="new-role"
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
                                <option value="admin">Operational Admin</option>
                                <option value="super_admin">Super Admin</option>
                            </select>
                            {errors.role && (
                                <p className="mt-1 text-xs text-error">
                                    {errors.role}
                                </p>
                            )}
                        </div>

                        <div>
                            <label
                                htmlFor="role-change-reason"
                                className="block text-xs font-semibold text-on-surface"
                            >
                                Mandatory Audit Reason{' '}
                                <span className="text-error">*</span>
                            </label>
                            <textarea
                                id="role-change-reason"
                                rows={2}
                                value={data.reason}
                                onChange={(e) =>
                                    setData('reason', e.target.value)
                                }
                                disabled={processing}
                                placeholder="e.g. Role adjusted following annual administrative review"
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
                                    {processing ? 'Updating...' : 'Update Role'}
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
}
