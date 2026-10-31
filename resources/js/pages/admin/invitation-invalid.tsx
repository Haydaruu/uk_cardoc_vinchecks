import { Head, Link } from '@inertiajs/react';
import { AlertCircle, ArrowLeft } from 'lucide-react';
import React from 'react';

interface InvitationInvalidProps {
    reason: string;
}

export default function InvitationInvalid({ reason }: InvitationInvalidProps) {
    return (
        <>
            <Head title="Invitation Invalid — VinCheck" />

            <div className="flex min-h-screen w-full flex-col items-center justify-center bg-[#f4f6f9] px-4 py-12">
                <div className="w-full max-w-md rounded-xl border border-slate-200 bg-white p-8 text-center shadow-md">
                    <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-red-100 text-red-600">
                        <AlertCircle className="h-8 w-8" />
                    </div>

                    <h1 className="mt-4 font-sans text-xl font-bold text-slate-900">
                        Invitation Unavailable
                    </h1>

                    <p className="mt-2 text-sm leading-relaxed text-slate-600">
                        {reason}
                    </p>

                    <div className="mt-6 border-t border-slate-200 pt-6">
                        <Link
                            href="/login"
                            className="inline-flex items-center gap-2 rounded-lg bg-[#00205b] px-5 py-2.5 text-xs font-semibold text-white shadow-xs transition hover:bg-[#001740]"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            <span>Return to Sign In</span>
                        </Link>
                    </div>
                </div>
            </div>
        </>
    );
}
