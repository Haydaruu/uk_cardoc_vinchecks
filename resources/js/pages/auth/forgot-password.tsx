import { Head, Link, usePage } from '@inertiajs/react';
import { Form } from '@inertiajs/react';
import { Mail, ArrowLeft, ArrowRight } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { email as passwordEmail } from '@/routes/password';
import { login } from '@/routes';
import AuthLayout from '@/layouts/auth/auth-layout';

type SharedProps = {
    status?: string;
    errors?: Record<string, string>;
};

export default function ForgotPassword() {
    const { props } = usePage<SharedProps>();
    const { status, errors } = props;

    return (
        <>
            <Head title="Forgot Password" />

            <div className="flex min-h-svh w-full flex-col items-center justify-center bg-[#f9f9ff] px-6 py-12">
                <div className="w-full max-w-md rounded-lg border border-slate-200 bg-white p-10 shadow-sm">
                    {/* Logo */}
                    <div className="mb-8 text-center">
                        <h1 className="text-lg font-black tracking-tight text-[#00205b]">UKCARDOC</h1>
                        <div className="mx-auto mt-2 h-0.5 w-8 bg-[#bb001a]" />
                    </div>

                    <div className="mb-8 text-center">
                        <h2 className="mb-2 text-2xl font-bold tracking-tight text-[#151c27]">
                            Forgot password?
                        </h2>
                        <p className="text-sm leading-relaxed text-[#757681]">
                            Enter the email address associated with your account and we&apos;ll send you a link to reset your password.
                        </p>
                    </div>

                    {status && (
                        <div className="mb-5 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-center text-sm font-medium text-green-700">
                            {status}
                        </div>
                    )}

                    <Form
                        {...passwordEmail.form()}
                        resetOnSuccess={false}
                        disableWhileProcessing
                        className="flex flex-col gap-5"
                    >
                        {({ processing, errors: formErrors }) => (
                            <div className="grid gap-5">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="forgot-email" className="text-[11px] font-semibold uppercase tracking-wider text-[#151c27]">
                                        Email Address
                                    </Label>
                                    <div className="relative">
                                        <Mail className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                                        <Input
                                            id="forgot-email"
                                            type="email"
                                            name="email"
                                            required
                                            autoFocus
                                            autoComplete="email"
                                            placeholder="name@company.com"
                                            className="h-11 rounded border-[#c5c6d1] bg-white pl-10 pr-4 text-sm text-[#151c27] placeholder:text-[#c5c6d1] transition-all focus:border-[#00205b] focus:ring-2 focus:ring-[#00205b]/10"
                                        />
                                    </div>
                                    <InputError message={formErrors.email || errors?.email} />
                                </div>

                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="flex h-12 w-full items-center justify-center gap-2 rounded bg-[#bb001a] text-sm font-bold uppercase tracking-widest text-white shadow-sm transition-all hover:bg-[#930012] active:scale-[0.98]"
                                >
                                    {processing ? (
                                        <Spinner className="h-4 w-4 text-white" />
                                    ) : (
                                        <>
                                            Send Reset Link
                                            <ArrowRight className="size-4" />
                                        </>
                                    )}
                                </Button>
                            </div>
                        )}
                    </Form>

                    <div className="my-6 border-t border-[#e5e7eb]" />

                    <div className="text-center">
                        <Link
                            href={login.url()}
                            className="inline-flex items-center gap-2 text-sm font-medium text-[#00205b] transition-colors hover:text-[#bb001a]"
                        >
                            <ArrowLeft className="size-4" />
                            Return to login
                        </Link>
                    </div>
                </div>

                <p className="mt-6 max-w-md text-center text-sm text-[#757681]">
                    Having trouble? Contact our{' '}
                    <Link href="/support" className="font-medium text-[#00205b] underline hover:text-[#bb001a]">
                        support team
                    </Link>{' '}
                    for official assistance.
                </p>
            </div>
        </>
    );
}

ForgotPassword.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;