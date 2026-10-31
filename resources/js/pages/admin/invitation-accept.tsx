import { Head, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    KeyRound,
    Loader2,
    ShieldCheck,
    UserCheck,
} from 'lucide-react';
import React from 'react';

interface InvitationAcceptProps {
    invitation: {
        email: string;
        name: string;
        role: 'admin' | 'super_admin';
        expires_at: string;
    };
    token: string;
    is_existing_user: boolean;
    is_logged_in_as_target: boolean;
    logged_in_email?: string | null;
}

export default function InvitationAccept({
    invitation,
    token,
    is_existing_user,
    is_logged_in_as_target,
}: InvitationAcceptProps) {
    const { data, setData, post, processing, errors } = useForm({
        name: invitation.name || '',
        password: '',
        password_confirmation: '',
    });

    const isSuperAdmin = invitation.role === 'super_admin';

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(`/admin/invitations/accept/${token}`);
    };

    return (
        <>
            <Head title="Accept Administrator Invitation — VinCheck" />

            <div className="flex min-h-screen w-full flex-col items-center justify-center bg-[#f4f6f9] px-4 py-12">
                <div className="w-full max-w-lg rounded-xl border border-slate-200 bg-white p-8 shadow-md">
                    {/* Brand header */}
                    <div className="mb-6 text-center">
                        <div className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-[#bb001a] font-sans text-base font-extrabold text-white">
                            VC
                        </div>
                        <h1 className="mt-2 text-lg font-black tracking-tight text-[#00205b]">
                            VinCheck UK
                        </h1>
                        <p className="text-xs font-semibold tracking-wider text-[#757681] uppercase">
                            Operational Console Invitation
                        </p>
                    </div>

                    {/* Invitation Details Card */}
                    <div className="mb-6 rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <div className="flex items-start justify-between">
                            <div>
                                <span className="block text-xs font-medium text-slate-500">
                                    Invited Email Address
                                </span>
                                <span className="block text-sm font-semibold text-slate-900">
                                    {invitation.email}
                                </span>
                            </div>
                            <span
                                className={`rounded-full px-2.5 py-0.5 font-mono text-[10px] font-bold uppercase ${
                                    isSuperAdmin
                                        ? 'border border-amber-300 bg-amber-100 text-amber-800'
                                        : 'border border-blue-300 bg-blue-100 text-blue-800'
                                }`}
                            >
                                {isSuperAdmin
                                    ? 'Super Administrator'
                                    : 'Administrator'}
                            </span>
                        </div>
                    </div>

                    {/* Scenario 1: Existing customer account elevation */}
                    {is_existing_user && (
                        <div className="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-xs text-amber-900">
                            <div className="flex items-center gap-2 font-semibold">
                                <AlertTriangle className="h-4 w-4 text-amber-600" />
                                <span>Existing Customer Account Detected</span>
                            </div>
                            <p className="mt-1 leading-relaxed text-amber-800">
                                An account already exists for{' '}
                                <strong>{invitation.email}</strong>. Accepting
                                this invitation will elevate your account
                                privileges to{' '}
                                <strong>
                                    {isSuperAdmin
                                        ? 'Super Administrator'
                                        : 'Administrator'}
                                </strong>
                                .
                            </p>
                            {!is_logged_in_as_target && (
                                <p className="mt-2 font-medium text-amber-950">
                                    Please enter your existing password below to
                                    verify account ownership before claiming
                                    administrative access.
                                </p>
                            )}
                        </div>
                    )}

                    {/* Scenario 2: New Administrator setup */}
                    {!is_existing_user && (
                        <div className="mb-6 rounded-lg border border-sky-200 bg-sky-50 p-4 text-xs text-sky-900">
                            <div className="flex items-center gap-2 font-semibold">
                                <ShieldCheck className="h-4 w-4 text-sky-600" />
                                <span>
                                    Complete Administrator Account Setup
                                </span>
                            </div>
                            <p className="mt-1 leading-relaxed text-sky-800">
                                Set your full name and a secure password to
                                activate your console credentials.
                            </p>
                        </div>
                    )}

                    <form onSubmit={handleSubmit} className="space-y-4">
                        {/* New user name field */}
                        {!is_existing_user && (
                            <div>
                                <label
                                    htmlFor="admin-name"
                                    className="block text-xs font-semibold text-slate-700"
                                >
                                    Full Name{' '}
                                    <span className="text-red-500">*</span>
                                </label>
                                <input
                                    id="admin-name"
                                    type="text"
                                    value={data.name}
                                    onChange={(e) =>
                                        setData('name', e.target.value)
                                    }
                                    disabled={processing}
                                    required
                                    className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition focus:border-blue-600 focus:outline-hidden"
                                />
                                {errors.name && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {errors.name}
                                    </p>
                                )}
                            </div>
                        )}

                        {/* Password field */}
                        {(!is_existing_user || !is_logged_in_as_target) && (
                            <div>
                                <label
                                    htmlFor="admin-password"
                                    className="block text-xs font-semibold text-slate-700"
                                >
                                    {is_existing_user
                                        ? 'Confirm Existing Password'
                                        : 'New Password'}{' '}
                                    <span className="text-red-500">*</span>
                                </label>
                                <div className="relative mt-1">
                                    <input
                                        id="admin-password"
                                        type="password"
                                        value={data.password}
                                        onChange={(e) =>
                                            setData('password', e.target.value)
                                        }
                                        disabled={processing}
                                        required
                                        placeholder={
                                            is_existing_user
                                                ? '••••••••'
                                                : 'Minimum 8 characters'
                                        }
                                        className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition focus:border-blue-600 focus:outline-hidden"
                                    />
                                    <KeyRound className="absolute top-2.5 right-3 h-4 w-4 text-slate-400" />
                                </div>
                                {errors.password && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {errors.password}
                                    </p>
                                )}
                            </div>
                        )}

                        {/* Password confirmation for new users */}
                        {!is_existing_user && (
                            <div>
                                <label
                                    htmlFor="admin-password-confirmation"
                                    className="block text-xs font-semibold text-slate-700"
                                >
                                    Confirm New Password{' '}
                                    <span className="text-red-500">*</span>
                                </label>
                                <input
                                    id="admin-password-confirmation"
                                    type="password"
                                    value={data.password_confirmation}
                                    onChange={(e) =>
                                        setData(
                                            'password_confirmation',
                                            e.target.value,
                                        )
                                    }
                                    disabled={processing}
                                    required
                                    placeholder="Repeat password"
                                    className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 transition focus:border-blue-600 focus:outline-hidden"
                                />
                                {errors.password_confirmation && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {errors.password_confirmation}
                                    </p>
                                )}
                            </div>
                        )}

                        <div className="pt-4">
                            <button
                                type="submit"
                                disabled={processing}
                                className="flex w-full items-center justify-center gap-2 rounded-lg bg-[#00205b] px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-[#001740] disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : is_existing_user ? (
                                    <UserCheck className="h-4 w-4" />
                                ) : (
                                    <CheckCircle2 className="h-4 w-4" />
                                )}
                                <span>
                                    {processing
                                        ? 'Activating...'
                                        : is_existing_user
                                          ? 'Confirm & Elevate to Administrator'
                                          : 'Complete Setup & Enter Console'}
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </>
    );
}
