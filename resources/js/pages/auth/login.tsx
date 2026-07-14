import { useState } from 'react';
import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AppLogoIcon from '@/components/app-logo-icon';
import AuthLayoutLeftPanel from '@/components/auth/AuthLayoutLeftPanel';
import { home } from '@/routes';
import { store } from '@/routes/login';
import { store as registerStore } from '@/routes/register';
import { request } from '@/routes/password';
import { cn } from '@/lib/utils';
import { ArrowLeft } from 'lucide-react';

type Props = {
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
    passwordRules?: string;
};

type AuthTab = 'login' | 'register';

export default function Login({
    status,
    canResetPassword,
    canRegister,
    passwordRules,
}: Props) {
    const [activeTab, setActiveTab] = useState<AuthTab>('login');

    const handleTabChange = (tab: AuthTab) => {
        setActiveTab(tab);
    };

    return (
        <>
            <Head title={activeTab === 'login' ? 'Log in' : 'Create account'} />

            <div className="flex min-h-svh flex-col bg-white lg:flex-row">
                {/* Left Panel — Branding */}
                <div className="w-full lg:w-[52%] lg:min-h-svh">
                    <AuthLayoutLeftPanel />
                </div>

                {/* Right Panel — Form */}
                <div className="flex w-full flex-col items-center justify-between px-6 py-10 lg:w-[48%] lg:px-14 lg:py-0">
                    {/* Mobile logo */}
                    <div className="w-full max-w-[440px]">
                        <Link
                            href={home()}
                            className="mb-6 flex items-center gap-2 lg:hidden"
                        >
                            <div className="flex h-9 w-9 items-center justify-center">
                                <AppLogoIcon className="size-9 fill-current text-[#00205b]" />
                            </div>
                            <span className="text-lg font-semibold text-[#00205b]">
                                UkCardoc
                            </span>
                        </Link>
                    </div>

                    <div className="w-full max-w-[440px] flex flex-col justify-center flex-1 py-10">
                        {/* Tab Switcher — underline style matching design */}
                        <div className="mb-8 flex w-full border-b border-[#e5e7eb]">
                            <button
                                onClick={() => handleTabChange('login')}
                                className={cn(
                                    'flex-1 pb-3 text-sm font-semibold transition-all relative',
                                    activeTab === 'login'
                                        ? 'text-[#151c27]'
                                        : 'text-[#9ca3af] hover:text-[#444650]'
                                )}
                            >
                                Login
                                {activeTab === 'login' && (
                                    <span className="absolute bottom-0 left-0 right-0 h-0.5 bg-[#bb001a]" />
                                )}
                            </button>
                            {canRegister && (
                                <button
                                    onClick={() => handleTabChange('register')}
                                    className={cn(
                                        'flex-1 pb-3 text-sm font-semibold transition-all relative',
                                        activeTab === 'register'
                                            ? 'text-[#151c27]'
                                            : 'text-[#9ca3af] hover:text-[#444650]'
                                    )}
                                >
                                    Create Account
                                    {activeTab === 'register' && (
                                        <span className="absolute bottom-0 left-0 right-0 h-0.5 bg-[#bb001a]" />
                                    )}
                                </button>
                            )}
                        </div>

                        {/* ── LOGIN FORM ── */}
                        {activeTab === 'login' && (
                            <>
                                {/* Form header */}
                                <h2 className="mb-1 text-[28px] font-bold tracking-tight text-[#151c27]">
                                    Welcome Back
                                </h2>
                                <p className="mb-7 text-sm text-[#757681]">
                                    Access your premium vehicle reports and fleet dashboard.
                                </p>

                                {/* Social buttons */}
                                <div className="mb-5 grid grid-cols-2 gap-3">
                                    <a
                                        type="button"
                                        className="flex h-11 items-center justify-center gap-2 rounded border border-[#c5c6d1] bg-white px-4 text-sm font-semibold text-[#151c27] transition-all hover:bg-[#f0f3ff] hover:border-[#00205b]"
                                        href="http://localhost:8000/auth/google/redirect"
                                    >
                                        <svg className="h-4 w-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                                            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                                            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                                        </svg>
                                        GOOGLE
                                    </a>
                                    <button
                                        type="button"
                                        className="flex h-11 items-center justify-center gap-2 rounded border border-[#c5c6d1] bg-white px-4 text-sm font-semibold text-[#151c27] transition-all hover:bg-[#f0f3ff] hover:border-[#00205b]"
                                    >
                                        <svg className="h-4 w-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor">
                                            <path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/>
                                        </svg>
                                        iOS APPLE
                                    </button>
                                </div>

                                {/* Divider */}
                                <div className="relative mb-5 flex items-center">
                                    <div className="flex-1 border-t border-[#e5e7eb]" />
                                    <span className="mx-4 text-[11px] font-semibold uppercase tracking-widest text-[#9ca3af]">
                                        OR CONTINUE WITH EMAIL
                                    </span>
                                    <div className="flex-1 border-t border-[#e5e7eb]" />
                                </div>

                                <Form
                                    {...store.form()}
                                    resetOnSuccess={['password']}
                                    className="flex flex-col gap-5"
                                >
                                    {({ processing, errors }) => (
                                        <div className="grid gap-5">
                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor="email"
                                                    className="text-[11px] font-semibold uppercase tracking-wider text-[#151c27]"
                                                >
                                                    Email Address
                                                </Label>
                                                <Input
                                                    id="email"
                                                    type="email"
                                                    name="email"
                                                    required
                                                    autoFocus
                                                    tabIndex={1}
                                                    autoComplete="email"
                                                    placeholder="name@company.co.uk"
                                                    className="h-11 rounded border-[#c5c6d1] bg-white px-4 text-sm text-[#151c27] placeholder:text-[#c5c6d1] transition-all focus:border-[#00205b] focus:ring-2 focus:ring-[#00205b]/10"
                                                />
                                                <InputError message={errors.email} />
                                            </div>

                                            <div className="grid gap-1.5">
                                                <div className="flex items-center">
                                                    <Label
                                                        htmlFor="password"
                                                        className="text-[11px] font-semibold uppercase tracking-wider text-[#151c27]"
                                                    >
                                                        Password
                                                    </Label>
                                                    {canResetPassword && (
                                                        <Link
                                                            href={request()}
                                                            className="ml-auto text-xs font-semibold text-[#bb001a] transition-colors hover:text-[#930012]"
                                                            tabIndex={5}
                                                        >
                                                            FORGOT?
                                                        </Link>
                                                    )}
                                                </div>
                                                <PasswordInput
                                                    id="password"
                                                    name="password"
                                                    required
                                                    tabIndex={2}
                                                    autoComplete="current-password"
                                                    placeholder="••••••••"
                                                    className="h-11 rounded border-[#c5c6d1] bg-white px-4 text-sm text-[#151c27] placeholder:text-[#c5c6d1] transition-all focus:border-[#00205b] focus:ring-2 focus:ring-[#00205b]/10"
                                                />
                                                <InputError message={errors.password} />
                                            </div>

                                            <div className="flex items-center space-x-2.5">
                                                <Checkbox
                                                    id="remember"
                                                    name="remember"
                                                    tabIndex={3}
                                                    className="border-[#c5c6d1] text-[#bb001a] data-[state=checked]:bg-[#bb001a] data-[state=checked]:border-[#bb001a]"
                                                />
                                                <Label
                                                    htmlFor="remember"
                                                    className="text-sm text-[#444650]"
                                                >
                                                    Remember this device for 30 days
                                                </Label>
                                            </div>

                                            <Button
                                                type="submit"
                                                className="h-12 w-full rounded bg-[#bb001a] text-sm font-semibold text-white shadow-sm transition-all hover:bg-[#930012] active:scale-[0.98]"
                                                tabIndex={4}
                                                disabled={processing}
                                                data-test="login-button"
                                            >
                                                {processing ? (
                                                    <Spinner className="h-4 w-4 text-white" />
                                                ) : (
                                                    'Sign In to Dashboard'
                                                )}
                                            </Button>
                                        </div>
                                    )}
                                </Form>

                                {/* Status message */}
                                {status && (
                                    <div className="mt-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-center text-sm font-medium text-green-700">
                                        {status}
                                    </div>
                                )}
                            </>
                        )}

                        {/* ── REGISTER FORM ── */}
                        {activeTab === 'register' && (
                            <>
                                {/* Form header */}
                                <h2 className="mb-1 text-[28px] font-bold tracking-tight text-[#151c27]">
                                    Create Account
                                </h2>
                                <p className="mb-7 text-sm text-[#757681]">
                                    Access your premium vehicle reports and fleet dashboard.
                                </p>

                                <Form
                                    {...registerStore.form()}
                                    resetOnSuccess={[
                                        'password',
                                        'password_confirmation',
                                    ]}
                                    disableWhileProcessing
                                    className="flex flex-col gap-5"
                                >
                                    {({ processing, errors }) => (
                                        <div className="grid gap-5">
                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor="reg-name"
                                                    className="text-[11px] font-semibold uppercase tracking-wider text-[#151c27]"
                                                >
                                                    Username
                                                </Label>
                                                <Input
                                                    id="reg-name"
                                                    type="text"
                                                    required
                                                    autoFocus
                                                    tabIndex={1}
                                                    autoComplete="name"
                                                    name="name"
                                                    placeholder="Johndoe"
                                                    className="h-11 rounded border-[#c5c6d1] bg-white px-4 text-sm text-[#151c27] placeholder:text-[#c5c6d1] transition-all focus:border-[#00205b] focus:ring-2 focus:ring-[#00205b]/10"
                                                />
                                                <InputError
                                                    message={errors.name}
                                                    className="mt-1"
                                                />
                                            </div>

                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor="reg-phone"
                                                    className="text-[11px] font-semibold uppercase tracking-wider text-[#151c27]"
                                                >
                                                    Phone Number
                                                </Label>
                                                <Input
                                                    id="reg-phone"
                                                    type="tel"
                                                    tabIndex={2}
                                                    autoComplete="tel"
                                                    name="phone"
                                                    placeholder="+44"
                                                    className="h-11 rounded border-[#c5c6d1] bg-white px-4 text-sm text-[#151c27] placeholder:text-[#c5c6d1] transition-all focus:border-[#00205b] focus:ring-2 focus:ring-[#00205b]/10"
                                                />
                                            </div>

                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor="reg-email"
                                                    className="text-[11px] font-semibold uppercase tracking-wider text-[#151c27]"
                                                >
                                                    Email Address
                                                </Label>
                                                <Input
                                                    id="reg-email"
                                                    type="email"
                                                    required
                                                    tabIndex={3}
                                                    autoComplete="email"
                                                    name="email"
                                                    placeholder="name@company.co.uk"
                                                    className="h-11 rounded border-[#c5c6d1] bg-white px-4 text-sm text-[#151c27] placeholder:text-[#c5c6d1] transition-all focus:border-[#00205b] focus:ring-2 focus:ring-[#00205b]/10"
                                                />
                                                <InputError message={errors.email} />
                                            </div>

                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor="reg-password"
                                                    className="text-[11px] font-semibold uppercase tracking-wider text-[#151c27]"
                                                >
                                                    Password
                                                </Label>
                                                <PasswordInput
                                                    id="reg-password"
                                                    required
                                                    tabIndex={4}
                                                    autoComplete="new-password"
                                                    name="password"
                                                    placeholder="••••••••"
                                                    passwordrules={passwordRules}
                                                    className="h-11 rounded border-[#c5c6d1] bg-white px-4 text-sm text-[#151c27] placeholder:text-[#c5c6d1] transition-all focus:border-[#00205b] focus:ring-2 focus:ring-[#00205b]/10"
                                                />
                                                <InputError message={errors.password} />
                                            </div>

                                            <Button
                                                type="submit"
                                                className="mt-1 h-12 w-full rounded bg-[#bb001a] text-sm font-semibold text-white shadow-sm transition-all hover:bg-[#930012] active:scale-[0.98]"
                                                tabIndex={5}
                                                disabled={processing}
                                                data-test="register-user-button"
                                            >
                                                {processing ? (
                                                    <Spinner className="h-4 w-4 text-white" />
                                                ) : (
                                                    'Signup'
                                                )}
                                            </Button>
                                        </div>
                                    )}
                                </Form>

                                {/* Divider */}
                                <div className="relative my-5 flex items-center">
                                    <div className="flex-1 border-t border-[#e5e7eb]" />
                                    <span className="mx-4 text-[11px] font-semibold uppercase tracking-widest text-[#9ca3af]">
                                        OR
                                    </span>
                                    <div className="flex-1 border-t border-[#e5e7eb]" />
                                </div>

                                {/* Social buttons */}
                                <div className="grid grid-cols-2 gap-3">
                                    <a
                                        type="button"
                                        className="flex h-11 items-center justify-center gap-2 rounded border border-[#c5c6d1] bg-white px-4 text-sm font-semibold text-[#151c27] transition-all hover:bg-[#f0f3ff] hover:border-[#00205b]"
                                        href="http://localhost:8000/auth/google/redirect"
                                    >
                                        <svg className="h-4 w-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                                            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                                            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                                        </svg>
                                        GOOGLE 
                                    </a>
                                    <button
                                        type="button"
                                        className="flex h-11 items-center justify-center gap-2 rounded border border-[#c5c6d1] bg-white px-4 text-sm font-semibold text-[#151c27] transition-all hover:bg-[#f0f3ff] hover:border-[#00205b]"
                                    >
                                        <svg className="h-4 w-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor">
                                            <path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/>
                                        </svg>
                                        iOS APPLE
                                    </button>
                                </div>
                            </>
                        )}
                    </div>

                    {/* Bottom section — Terms + Copyright + Back to Home */}
                    <div className="w-full max-w-[440px] pb-8 space-y-5">
                        {/* Sovereign line */}
                        <div className="flex items-center gap-1">
                            <div className="h-px w-5 bg-[#bb001a]" />
                            <div className="h-px flex-1 bg-transparent" />
                        </div>

                        {/* Terms */}
                        <p className="text-xs text-[#9ca3af] leading-relaxed">
                            By continuing, you agree to UKcardoc's{' '}
                            <Link
                                href="#"
                                className="font-medium text-[#444650] underline underline-offset-2 hover:text-[#bb001a]"
                            >
                                Terms of Service
                            </Link>{' '}
                            and{' '}
                            <Link
                                href="#"
                                className="font-medium text-[#444650] underline underline-offset-2 hover:text-[#bb001a]"
                            >
                                Privacy Policy
                            </Link>
                            .<br />
                            Data provided is sourced directly from official UK automotive registries.
                        </p>

                        {/* Back to Home */}
                        <Link
                            href={home()}
                            className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#444650] transition-colors hover:text-[#bb001a] group"
                        >
                            <ArrowLeft className="h-3.5 w-3.5 transition-transform group-hover:-translate-x-0.5" />
                            Back to Home
                        </Link>

                        {/* Copyright */}
                        <p className="text-[10px] font-medium uppercase tracking-wider text-[#c5c6d1] text-center">
                            © 2026 UKCARDOC LTD. REGISTERED IN ENGLAND &amp; WALES.
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}

Login.layout = (children: React.ReactNode) => <>{children}</>;
