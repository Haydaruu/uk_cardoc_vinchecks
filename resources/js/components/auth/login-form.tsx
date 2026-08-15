import { Form, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function LoginForm({ status, canResetPassword }: Props) {
    return (
        <>
            <div className="mb-6">
                <h2 className="mb-1 text-[28px] font-bold tracking-tight text-[#151c27]">Welcome Back</h2>
                <p className="text-sm text-[#757681]">Access your premium vehicle reports and fleet dashboard.</p>
            </div>

                <a href="http://localhost:8000/auth/google/redirect" className="flex h-11 items-center justify-center gap-2 rounded border border-[#c5c6d1] bg-white px-4 text-sm font-semibold text-[#151c27] transition-all hover:bg-[#f0f3ff] hover:border-[#00205b]">
                    {/* SVG Google */}
                    <svg className="h-4 w-4" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
                    GOOGLE
                </a>

            <div className="relative mb-5 flex items-center">
                <div className="flex-1 border-t border-[#e5e7eb]" />
                <span className="mx-4 text-[11px] font-semibold uppercase tracking-widest text-[#9ca3af]">OR CONTINUE WITH EMAIL</span>
                <div className="flex-1 border-t border-[#e5e7eb]" />
            </div>

            <Form {...store.form()} resetOnSuccess={['password']} className="flex flex-col gap-5">
                {({ processing, errors }) => (
                    <div className="grid gap-5">
                        <div className="grid gap-1.5">
                            <Label htmlFor="email" className="text-[11px] font-semibold uppercase tracking-wider text-[#151c27]">Email Address</Label>
                            <Input id="email" type="email" name="email" required autoFocus autoComplete="email" placeholder="name@company.co.uk" className="h-11 rounded border-[#c5c6d1] bg-white px-4 text-sm text-[#151c27] placeholder:text-[#c5c6d1] transition-all focus:border-[#00205b] focus:ring-2 focus:ring-[#00205b]/10" />
                            <InputError message={errors.email} />
                        </div>
                        <div className="grid gap-1.5">
                            <div className="flex items-center">
                                <Label htmlFor="password" className="text-[11px] font-semibold uppercase tracking-wider text-[#151c27]">Password</Label>
                                {canResetPassword && <Link href={request()} className="ml-auto text-xs font-semibold text-[#bb001a] transition-colors hover:text-[#930012]">FORGOT?</Link>}
                            </div>
                            <PasswordInput id="password" name="password" required autoComplete="current-password" placeholder="••••••••" className="h-11 rounded border-[#c5c6d1] bg-white px-4 text-sm text-[#151c27] placeholder:text-[#c5c6d1] transition-all focus:border-[#00205b] focus:ring-2 focus:ring-[#00205b]/10" />
                            <InputError message={errors.password} />
                        </div>
                        <div className="flex items-center space-x-2.5">
                            <Checkbox id="remember" name="remember" className="border-[#c5c6d1] text-[#bb001a] data-[state=checked]:bg-[#bb001a] data-[state=checked]:border-[#bb001a]" />
                            <Label htmlFor="remember" className="text-sm text-[#444650]">Remember this device for 30 days</Label>
                        </div>
                        <Button type="submit" className="h-12 w-full rounded bg-[#bb001a] text-sm font-semibold text-white shadow-sm transition-all hover:bg-[#930012] active:scale-[0.98]" disabled={processing}>
                            {processing ? <Spinner className="h-4 w-4 text-white" /> : 'Sign In to Dashboard'}
                        </Button>
                    </div>
                )}
            </Form>
            {status && <div className="mt-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-center text-sm font-medium text-green-700">{status}</div>}
        </>
    );
}