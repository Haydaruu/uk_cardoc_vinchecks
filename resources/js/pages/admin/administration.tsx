import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    CheckCircle,
    CheckCircle2,
    Clock,
    History,
    Shield,
    Trash2,
    UserCheck,
    UserPlus,
    Users,
    UserX,
} from 'lucide-react';
import React, { useState } from 'react';

import ChangeAdminRoleModal from '@/components/admin/change-admin-role-modal';
import InviteAdminModal from '@/components/admin/invite-admin-modal';
import Pagination from '@/components/admin/pagination';
import ReactivateAdminModal from '@/components/admin/reactivate-admin-modal';
import SuspendAdminModal from '@/components/admin/suspend-admin-modal';
import AdminLayout from '@/layouts/admin-layout';

interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'super_admin';
    is_suspended: boolean;
    suspended_at?: string | null;
    suspended_reason?: string | null;
    suspended_by?: string | null;
    email_verified: boolean;
    created_at?: string | null;
    last_login_at?: string | null;
}

interface PendingInvitation {
    id: number;
    email: string;
    name: string;
    role: string;
    invited_by_name?: string | null;
    expires_at: string;
    created_at: string;
}

interface AuditLogItem {
    id: number;
    admin_id: number;
    admin?: {
        name: string;
        email: string;
        role: string;
    } | null;
    action: string;
    target_type?: string | null;
    target_id?: number | null;
    reason?: string | null;
    outcome: string;
    metadata?: Record<string, any> | null;
    ip_address?: string | null;
    created_at: string;
}

interface AdministrationProps {
    admins: AdminUser[];
    pending_invitations: PendingInvitation[];
    audit_logs: {
        data: AuditLogItem[];
        links: any[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: {
        action: string;
        admin_id: string;
        outcome: string;
        search: string;
    };
}

export default function Administration({
    admins,
    pending_invitations,
    audit_logs,
    filters,
}: AdministrationProps) {
    const { flash } = usePage<{
        flash?: {
            success?: string | null;
            error?: string | null;
            warning?: string | null;
        };
    }>().props;

    const [activeTab, setActiveTab] = useState<'roster' | 'audit'>('roster');
    const [isInviteModalOpen, setIsInviteModalOpen] = useState(false);
    const [selectedAdminForRole, setSelectedAdminForRole] = useState<AdminUser | null>(null);
    const [selectedAdminForSuspend, setSelectedAdminForSuspend] = useState<AdminUser | null>(null);
    const [selectedAdminForReactivate, setSelectedAdminForReactivate] = useState<AdminUser | null>(null);

    const [filterSearch, setFilterSearch] = useState(filters.search || '');
    const [filterAction, setFilterAction] = useState(filters.action || '');
    const [filterOutcome, setFilterOutcome] = useState(filters.outcome || '');

    const activeSuperAdmins = admins.filter(
        (a) => a.role === 'super_admin' && !a.is_suspended
    );
    const isSoleActiveSuperAdmin = (admin: AdminUser) => {
        return admin.role === 'super_admin' && !admin.is_suspended && activeSuperAdmins.length <= 1;
    };

    const handleFilterSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        router.get(
            '/admin/administration',
            {
                search: filterSearch,
                action: filterAction,
                outcome: filterOutcome,
            },
            {
                preserveState: true,
                preserveScroll: true,
            }
        );
    };

    const handleRevokeInvitation = (invitationId: number, email: string) => {
        const reason = window.prompt(
            `Please enter an audit reason to revoke the invitation for ${email} (min 5 characters):`
        );

        if (!reason || reason.trim().length < 5) {
            alert('A mandatory audit reason of at least 5 characters is required to revoke an invitation.');

            return;
        }

        router.delete(`/admin/administration/invitations/${invitationId}`, {
            data: { reason },
            preserveScroll: true,
        });
    };

    return (
        <AdminLayout
            title="Administration"
            subtitle="Administrator team management, role protection & global audit trail"
        >
            <Head title="Administration & Audit Trail — Super Admin" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="rounded bg-amber-400/20 px-2 py-0.5 font-mono text-[10px] font-bold text-amber-700 uppercase">
                                Super Admin Control
                            </span>
                        </div>
                        <h1 className="mt-1 font-sans text-2xl font-extrabold text-on-surface">
                            Administration & Audit Trail
                        </h1>
                        <p className="text-xs text-on-surface-variant">
                            Provision administrators, manage role assignments, enforce security policies, and inspect global audit logs
                        </p>
                    </div>

                    {/* Header Actions */}
                    <div className="flex items-center gap-3">
                        <div className="flex rounded-lg border border-outline-variant bg-surface p-1">
                            <button
                                type="button"
                                onClick={() => setActiveTab('roster')}
                                className={`flex items-center gap-2 rounded-md px-3.5 py-1.5 text-xs font-semibold transition ${
                                    activeTab === 'roster'
                                        ? 'bg-primary text-white shadow-xs'
                                        : 'text-on-surface-variant hover:text-on-surface'
                                }`}
                            >
                                <Users className="h-3.5 w-3.5" />
                                <span>Administrators ({admins.length})</span>
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveTab('audit')}
                                className={`flex items-center gap-2 rounded-md px-3.5 py-1.5 text-xs font-semibold transition ${
                                    activeTab === 'audit'
                                        ? 'bg-primary text-white shadow-xs'
                                        : 'text-on-surface-variant hover:text-on-surface'
                                }`}
                            >
                                <History className="h-3.5 w-3.5" />
                                <span>Audit Trail</span>
                            </button>
                        </div>

                        {activeTab === 'roster' && (
                            <button
                                type="button"
                                onClick={() => setIsInviteModalOpen(true)}
                                className="flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-primary-hover"
                            >
                                <UserPlus className="h-4 w-4" />
                                <span>Invite Administrator</span>
                            </button>
                        )}
                    </div>
                </div>

                {/* Flash Messages */}
                {flash?.success && (
                    <div className="rounded-lg border border-emerald-500/30 bg-emerald-50 p-4 text-xs text-emerald-800 space-y-2">
                        <div className="flex items-center gap-3">
                            <CheckCircle className="h-4 w-4 shrink-0 text-emerald-600" />
                            <span className="font-medium">{flash.success}</span>
                        </div>
                        {(flash as Record<string, any>)?.invitation_url && (
                            <div className="mt-2 flex items-center justify-between rounded-md border border-emerald-300 bg-white p-2.5 text-xs text-slate-800">
                                <div className="truncate mr-3">
                                    <span className="font-bold text-emerald-700 block">Invitation Link Generated:</span>
                                    <span className="font-mono text-[11px] text-slate-600 truncate block">{(flash as Record<string, any>).invitation_url}</span>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => {
                                        navigator.clipboard.writeText((flash as Record<string, any>).invitation_url);
                                        alert('Invitation URL copied to clipboard!');
                                    }}
                                    className="shrink-0 rounded bg-emerald-600 px-3 py-1 text-[11px] font-semibold text-white hover:bg-emerald-700 cursor-pointer"
                                >
                                    Copy Link
                                </button>
                            </div>
                        )}
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

                {/* Tab: Administrators Roster */}
                {activeTab === 'roster' && (
                    <div className="space-y-6">
                        {/* Protection notice callout */}
                        <div className="flex items-start gap-3 rounded-xl border border-outline-variant bg-surface-container/30 p-4 text-xs">
                            <Shield className="h-5 w-5 shrink-0 text-primary mt-0.5" />
                            <div className="space-y-1">
                                <p className="font-bold text-on-surface">
                                    Sole Super Admin Defense Rule
                                </p>
                                <p className="text-on-surface-variant">
                                    The backend enforces that the system must always retain at least one active Super Admin. Any attempt to demote or deactivate the last remaining Super Admin will be strictly blocked with an atomic validation exception.
                                </p>
                            </div>
                        </div>

                        {/* Admins Table */}
                        <div className="rounded-xl border border-outline-variant bg-white shadow-xs overflow-hidden">
                            <div className="border-b border-outline-variant/60 bg-surface px-6 py-4">
                                <h2 className="font-sans text-sm font-bold text-on-surface">
                                    Active Administrative Team
                                </h2>
                                <p className="text-xs text-on-surface-variant">
                                    All accounts currently provisioned with console operational or administrative authority
                                </p>
                            </div>

                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead>
                                        <tr className="border-b border-outline-variant/60 bg-surface-container/20 text-on-surface-variant">
                                            <th className="px-6 py-3 font-semibold">Administrator</th>
                                            <th className="px-6 py-3 font-semibold">Assigned Role</th>
                                            <th className="px-6 py-3 font-semibold">Account State</th>
                                            <th className="px-6 py-3 font-semibold">Email Verified</th>
                                            <th className="px-6 py-3 font-semibold">Last Login</th>
                                            <th className="px-6 py-3 text-right font-semibold">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-outline-variant/40">
                                        {admins.map((admin) => {
                                            const isSole = isSoleActiveSuperAdmin(admin);

                                            return (
                                                <tr
                                                    key={admin.id}
                                                    className={`hover:bg-surface-container/20 ${
                                                        admin.is_suspended ? 'bg-error-container/10' : ''
                                                    }`}
                                                >
                                                    <td className="px-6 py-4">
                                                        <div className="flex items-center gap-3">
                                                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 font-bold text-primary">
                                                                {admin.name.charAt(0).toUpperCase()}
                                                            </div>
                                                            <div>
                                                                <span className="block font-semibold text-on-surface">
                                                                    {admin.name}
                                                                </span>
                                                                <span className="block font-mono text-[11px] text-on-surface-variant">
                                                                    {admin.email}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <div className="flex items-center gap-1.5">
                                                            <span
                                                                className={`rounded-full px-2 py-0.5 font-mono text-[10px] font-bold uppercase ${
                                                                    admin.role === 'super_admin'
                                                                        ? 'border border-amber-400/40 bg-amber-100 text-amber-900'
                                                                        : 'border border-blue-400/40 bg-blue-100 text-blue-900'
                                                                }`}
                                                            >
                                                                {admin.role === 'super_admin'
                                                                    ? 'Super Admin'
                                                                    : 'Operational Admin'}
                                                            </span>
                                                            {isSole && (
                                                                <span className="rounded bg-amber-500/10 px-1.5 py-0.2 font-mono text-[9px] font-bold text-amber-700">
                                                                    Primary
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        {admin.is_suspended ? (
                                                            <div>
                                                                <span className="inline-flex items-center gap-1 rounded-full bg-error/10 px-2 py-0.5 font-mono text-[10px] font-semibold text-error">
                                                                    <UserX className="h-3 w-3" />
                                                                    Deactivated
                                                                </span>
                                                                {admin.suspended_reason && (
                                                                    <p className="mt-1 max-w-xs truncate text-[10px] text-on-surface-variant italic" title={admin.suspended_reason}>
                                                                        "{admin.suspended_reason}"
                                                                    </p>
                                                                )}
                                                            </div>
                                                        ) : (
                                                            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 font-mono text-[10px] font-semibold text-emerald-700">
                                                                <CheckCircle2 className="h-3 w-3 text-emerald-600" />
                                                                Active
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        {admin.email_verified ? (
                                                            <span className="text-emerald-700 font-medium">Verified</span>
                                                        ) : (
                                                            <span className="text-amber-700 font-medium">Pending</span>
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-4 text-on-surface-variant">
                                                        {admin.last_login_at
                                                            ? new Date(admin.last_login_at).toLocaleDateString()
                                                            : 'Never'}
                                                    </td>
                                                    <td className="px-6 py-4 text-right">
                                                        <div className="flex items-center justify-end gap-2">
                                                            <button
                                                                type="button"
                                                                onClick={() => setSelectedAdminForRole(admin)}
                                                                className="rounded-md border border-outline-variant bg-surface px-2.5 py-1 text-[11px] font-semibold text-on-surface transition hover:bg-surface-container"
                                                            >
                                                                Change Role
                                                            </button>

                                                            {admin.is_suspended ? (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setSelectedAdminForReactivate(admin)}
                                                                    className="flex items-center gap-1 rounded-md border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-800 transition hover:bg-emerald-100"
                                                                >
                                                                    <UserCheck className="h-3 w-3" />
                                                                    <span>Reactivate</span>
                                                                </button>
                                                            ) : (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setSelectedAdminForSuspend(admin)}
                                                                    disabled={isSole}
                                                                    title={
                                                                        isSole
                                                                            ? 'Cannot deactivate the sole active Super Admin'
                                                                            : 'Deactivate administrator'
                                                                    }
                                                                    className="flex items-center gap-1 rounded-md border border-error/30 bg-error/5 px-2.5 py-1 text-[11px] font-semibold text-error transition hover:bg-error/15 disabled:cursor-not-allowed disabled:opacity-40"
                                                                >
                                                                    <UserX className="h-3 w-3" />
                                                                    <span>Deactivate</span>
                                                                </button>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {/* Pending Invitations Section */}
                        {pending_invitations.length > 0 && (
                            <div className="rounded-xl border border-outline-variant bg-white shadow-xs overflow-hidden">
                                <div className="border-b border-outline-variant/60 bg-surface px-6 py-4">
                                    <h2 className="font-sans text-sm font-bold text-on-surface">
                                        Pending Administrator Invitations ({pending_invitations.length})
                                    </h2>
                                    <p className="text-xs text-on-surface-variant">
                                        Unaccepted invitation tokens awaiting verification and access activation
                                    </p>
                                </div>

                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-xs">
                                        <thead>
                                            <tr className="border-b border-outline-variant/60 bg-surface-container/20 text-on-surface-variant">
                                                <th className="px-6 py-3 font-semibold">Recipient</th>
                                                <th className="px-6 py-3 font-semibold">Invited Role</th>
                                                <th className="px-6 py-3 font-semibold">Issued By</th>
                                                <th className="px-6 py-3 font-semibold">Expiration</th>
                                                <th className="px-6 py-3 text-right font-semibold">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-outline-variant/40">
                                            {pending_invitations.map((inv) => (
                                                <tr key={inv.id} className="hover:bg-surface-container/20">
                                                    <td className="px-6 py-3.5">
                                                        <span className="block font-semibold text-on-surface">
                                                            {inv.name}
                                                        </span>
                                                        <span className="block font-mono text-[11px] text-on-surface-variant">
                                                            {inv.email}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-3.5 capitalize font-mono text-[11px] text-primary font-bold">
                                                        {inv.role.replace('_', ' ')}
                                                    </td>
                                                    <td className="px-6 py-3.5 text-on-surface-variant">
                                                        {inv.invited_by_name || 'System Admin'}
                                                    </td>
                                                    <td className="px-6 py-3.5 text-on-surface-variant">
                                                        <div className="flex items-center gap-1.5">
                                                            <Clock className="h-3 w-3 text-amber-600" />
                                                            <span>{new Date(inv.expires_at).toLocaleDateString()}</span>
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-3.5 text-right">
                                                        <button
                                                            type="button"
                                                            onClick={() => handleRevokeInvitation(inv.id, inv.email)}
                                                            className="inline-flex items-center gap-1 rounded-md border border-error/30 bg-error/5 px-2.5 py-1 text-[11px] font-semibold text-error transition hover:bg-error/15"
                                                        >
                                                            <Trash2 className="h-3 w-3" />
                                                            <span>Revoke</span>
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {/* Tab: Audit Trail */}
                {activeTab === 'audit' && (
                    <div className="space-y-6">
                        {/* Filters Card */}
                        <div className="rounded-xl border border-outline-variant bg-white p-5 shadow-xs">
                            <form onSubmit={handleFilterSubmit} className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                                <div>
                                    <label
                                        htmlFor="filter-search"
                                        className="block text-xs font-semibold text-on-surface"
                                    >
                                        Search Query
                                    </label>
                                    <input
                                        id="filter-search"
                                        type="text"
                                        value={filterSearch}
                                        onChange={(e) => setFilterSearch(e.target.value)}
                                        placeholder="Reason, target, admin..."
                                        className="mt-1 w-full rounded-lg border border-outline-variant bg-surface px-3 py-1.5 text-xs text-on-surface transition focus:border-primary focus:outline-hidden"
                                    />
                                </div>

                                <div>
                                    <label
                                        htmlFor="filter-action"
                                        className="block text-xs font-semibold text-on-surface"
                                    >
                                        Action
                                    </label>
                                    <input
                                        id="filter-action"
                                        type="text"
                                        value={filterAction}
                                        onChange={(e) => setFilterAction(e.target.value)}
                                        placeholder="e.g. admin.role_changed"
                                        className="mt-1 w-full rounded-lg border border-outline-variant bg-surface px-3 py-1.5 text-xs text-on-surface transition focus:border-primary focus:outline-hidden"
                                    />
                                </div>

                                <div>
                                    <label
                                        htmlFor="filter-outcome"
                                        className="block text-xs font-semibold text-on-surface"
                                    >
                                        Outcome
                                    </label>
                                    <select
                                        id="filter-outcome"
                                        value={filterOutcome}
                                        onChange={(e) => setFilterOutcome(e.target.value)}
                                        className="mt-1 w-full rounded-lg border border-outline-variant bg-surface px-3 py-1.5 text-xs text-on-surface transition focus:border-primary focus:outline-hidden"
                                    >
                                        <option value="">All Outcomes</option>
                                        <option value="success">Success</option>
                                        <option value="failed">Failed</option>
                                        <option value="pending">Pending</option>
                                    </select>
                                </div>

                                <div className="flex items-end gap-2">
                                    <button
                                        type="submit"
                                        className="w-full rounded-lg bg-primary py-2 text-xs font-semibold text-white transition hover:bg-primary-hover"
                                    >
                                        Filter Logs
                                    </button>
                                </div>
                            </form>
                        </div>

                        {/* Audit Table */}
                        <div className="rounded-xl border border-outline-variant bg-white shadow-xs overflow-hidden">
                            <div className="border-b border-outline-variant/60 bg-surface px-6 py-4">
                                <h2 className="font-sans text-sm font-bold text-on-surface">
                                    System Administrative Audit Trail
                                </h2>
                                <p className="text-xs text-on-surface-variant">
                                    Append-only tamper-resistant record of administrative operations and configuration mutations
                                </p>
                            </div>

                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead>
                                        <tr className="border-b border-outline-variant/60 bg-surface-container/20 text-on-surface-variant">
                                            <th className="px-5 py-3 font-semibold">Timestamp</th>
                                            <th className="px-5 py-3 font-semibold">Administrator</th>
                                            <th className="px-5 py-3 font-semibold">Action</th>
                                            <th className="px-5 py-3 font-semibold">Target</th>
                                            <th className="px-5 py-3 font-semibold">Operational Reason</th>
                                            <th className="px-5 py-3 font-semibold">Outcome</th>
                                            <th className="px-5 py-3 text-right font-semibold">IP Address</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-outline-variant/40">
                                        {audit_logs.data.length === 0 ? (
                                            <tr>
                                                <td colSpan={7} className="px-6 py-8 text-center text-on-surface-variant">
                                                    No audit trail entries matched the specified criteria.
                                                </td>
                                            </tr>
                                        ) : (
                                            audit_logs.data.map((log) => (
                                                <tr key={log.id} className="hover:bg-surface-container/20">
                                                    <td className="px-5 py-3.5 font-mono text-[11px] text-on-surface-variant whitespace-nowrap">
                                                        {new Date(log.created_at).toLocaleString()}
                                                    </td>
                                                    <td className="px-5 py-3.5">
                                                        <span className="block font-semibold text-on-surface">
                                                            {log.admin?.name || `Admin #${log.admin_id}`}
                                                        </span>
                                                        <span className="block font-mono text-[10px] text-on-surface-variant">
                                                            {log.admin?.email}
                                                        </span>
                                                    </td>
                                                    <td className="px-5 py-3.5">
                                                        <span className="rounded bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold text-primary">
                                                            {log.action}
                                                        </span>
                                                    </td>
                                                    <td className="px-5 py-3.5 text-on-surface-variant font-mono text-[11px]">
                                                        {log.target_type ? `${log.target_type} #${log.target_id || '-'}` : '-'}
                                                    </td>
                                                    <td className="px-5 py-3.5 text-on-surface max-w-xs truncate" title={log.reason || ''}>
                                                        {log.reason || '-'}
                                                    </td>
                                                    <td className="px-5 py-3.5">
                                                        <span
                                                            className={`rounded-full px-2 py-0.5 font-mono text-[10px] font-semibold ${
                                                                log.outcome === 'success'
                                                                    ? 'bg-emerald-100 text-emerald-800'
                                                                    : log.outcome === 'failed'
                                                                      ? 'bg-error/10 text-error'
                                                                      : 'bg-amber-100 text-amber-800'
                                                            }`}
                                                        >
                                                            {log.outcome}
                                                        </span>
                                                    </td>
                                                    <td className="px-5 py-3.5 text-right font-mono text-[10px] text-on-surface-variant">
                                                        {log.ip_address || '-'}
                                                    </td>
                                                </tr>
                                            ))
                                        )}
                                    </tbody>
                                </table>
                            </div>

                            <Pagination
                                links={audit_logs.links}
                                from={audit_logs.from}
                                to={audit_logs.to}
                                total={audit_logs.total}
                            />
                        </div>
                    </div>
                )}
            </div>

            {/* Modals */}
            <InviteAdminModal
                isOpen={isInviteModalOpen}
                onClose={() => setIsInviteModalOpen(false)}
            />

            <ChangeAdminRoleModal
                isOpen={selectedAdminForRole !== null}
                onClose={() => setSelectedAdminForRole(null)}
                admin={selectedAdminForRole}
            />

            <SuspendAdminModal
                isOpen={selectedAdminForSuspend !== null}
                onClose={() => setSelectedAdminForSuspend(null)}
                admin={selectedAdminForSuspend}
            />

            <ReactivateAdminModal
                isOpen={selectedAdminForReactivate !== null}
                onClose={() => setSelectedAdminForReactivate(null)}
                admin={selectedAdminForReactivate}
            />
        </AdminLayout>
    );
}
