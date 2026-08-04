import { Link, useForm, usePage } from '@inertiajs/react';
import { Mail, Pencil, ArrowLeft, Send } from 'lucide-react';
import { useState } from 'react';

type SharedProps = {
    auth: {
        user: { email: string } | null;
    };
};

export default function VerifyEmail() {
    const { props } = usePage<SharedProps>();
    const email = props.auth.user?.email ?? '';
    const { post, processing } = useForm({});
    const [sent, setSent] = useState(false);

    function resend() {
        post(route('verification.send'), {
            onSuccess: () => setSent(true),
        });
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
            <div className="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-8 text-center shadow-sm">
                <div className="mx-auto mb-5 flex h-12 w-12 items-center justify-center rounded-lg bg-primary-container/10">
                    <Mail className="h-6 w-6 text-primary-container" />
                </div>

                <h1 className="text-xl font-bold text-slate-900">
                    Verify your email
                </h1>

                <p className="mt-2 text-sm text-slate-500">
                    We&apos;ve sent a verification link to{' '}
                    <span className="font-semibold text-slate-700">{email}</span>.
                    Please check your inbox and click the link to activate your
                    account.
                </p>

                {sent && (
                    <p className="mt-3 text-sm font-medium text-emerald-600">
                        Link sent!
                    </p>
                )}

                <button
                    type="button"
                    onClick={resend}
                    disabled={processing}
                    className="mt-6 flex w-full items-center justify-center gap-2 rounded-sm bg-secondary py-3 text-[11px] font-bold uppercase tracking-widest text-white transition-colors hover:bg-secondary-container disabled:opacity-60"
                >
                    Resend Link
                    <Send size={14} />
                </button>

                <div className="mt-6 flex items-center justify-center gap-6 border-t border-slate-100 pt-4 text-sm">
                    <Link
                        href="/profile"
                        className="flex items-center gap-1.5 text-slate-500 hover:text-primary-container"
                    >
                        <Pencil size={14} />
                        Change email address
                    </Link>
                    <Link
                        href={route('authPage')}
                        className="flex items-center gap-1.5 text-slate-500 hover:text-primary-container"
                    >
                        <ArrowLeft size={14} />
                        Back to login
                    </Link>
                </div>
            </div>
        </div>
    );
}