import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store as registerStore } from '@/routes/register';

type Props = {
    passwordRules?: string;
};

export default function RegisterForm({ passwordRules }: Props) {
    return (
        <>
            <div className="mb-6">
                <h2 className="mb-1 text-[28px] font-bold tracking-tight text-[#151c27]">Create Account</h2>
                <p className="text-sm text-[#757681]">Access your premium vehicle reports and fleet dashboard.</p>
            </div>

            <Form {...registerStore.form()} resetOnSuccess={['password', 'password_confirmation']} disableWhileProcessing className="flex flex-col gap-5">
                {({ processing, errors }) => (
                    <div className="grid gap-5">
                        <div className="grid gap-1.5">
                            <Label htmlFor="reg-name" className="text-[11px] font-semibold uppercase tracking-wider text-[#151c27]">Username</Label>
                            <Input id="reg-name" type="text" required autoFocus name="name" placeholder="Johndoe" className="h-11 rounded border-[#c5c6d1] bg-white px-4 text-sm text-[#151c27] placeholder:text-[#c5c6d1] transition-all focus:border-[#00205b] focus:ring-2 focus:ring-[#00205b]/10" />
                            <InputError message={errors.name} className="mt-1" />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="reg-phone" className="text-[11px] font-semibold uppercase tracking-wider text-[#151c27]">Phone Number</Label>
                            <Input id="reg-phone" type="tel" name="phone" placeholder="+44" className="h-11 rounded border-[#c5c6d1] bg-white px-4 text-sm text-[#151c27] placeholder:text-[#c5c6d1] transition-all focus:border-[#00205b] focus:ring-2 focus:ring-[#00205b]/10" />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="reg-email" className="text-[11px] font-semibold uppercase tracking-wider text-[#151c27]">Email Address</Label>
                            <Input id="reg-email" type="email" required name="email" placeholder="name@company.co.uk" className="h-11 rounded border-[#c5c6d1] bg-white px-4 text-sm text-[#151c27] placeholder:text-[#c5c6d1] transition-all focus:border-[#00205b] focus:ring-2 focus:ring-[#00205b]/10" />
                            <InputError message={errors.email} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="reg-password" className="text-[11px] font-semibold uppercase tracking-wider text-[#151c27]">Password</Label>
                            <PasswordInput id="reg-password" required name="password" placeholder="••••••••" passwordrules={passwordRules} className="h-11 rounded border-[#c5c6d1] bg-white px-4 text-sm text-[#151c27] placeholder:text-[#c5c6d1] transition-all focus:border-[#00205b] focus:ring-2 focus:ring-[#00205b]/10" />
                            <InputError message={errors.password} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="reg-password-confirmation" className="text-[11px] font-semibold uppercase tracking-wider text-[#151c27]">
                                Confirm Password
                            </Label>
                            <PasswordInput
                                id="reg-password-confirmation"
                                required
                                name="password_confirmation"
                                placeholder="••••••••"
                                className="h-11 rounded border-[#c5c6d1] bg-white px-4 text-sm text-[#151c27] placeholder:text-[#c5c6d1] transition-all focus:border-[#00205b] focus:ring-2 focus:ring-[#00205b]/10"
                            />
                            <InputError message={errors.password_confirmation} />
                        </div>
                        <Button type="submit" className="mt-1 h-12 w-full rounded bg-[#bb001a] text-sm font-semibold text-white shadow-sm transition-all hover:bg-[#930012] active:scale-[0.98]" disabled={processing}>
                            {processing ? <Spinner className="h-4 w-4 text-white" /> : 'Signup'}
                        </Button>
                    </div>
                )}
            </Form>

            <div className="relative my-5 flex items-center">
                <div className="flex-1 border-t border-[#e5e7eb]" />
                <span className="mx-4 text-[11px] font-semibold uppercase tracking-widest text-[#9ca3af]">OR</span>
                <div className="flex-1 border-t border-[#e5e7eb]" />
            </div>

            <div className="grid grid-cols-2 gap-3">
                <a href="http://localhost:8000/auth/google/redirect" className="flex h-11 items-center justify-center gap-2 rounded border border-[#c5c6d1] bg-white px-4 text-sm font-semibold text-[#151c27] transition-all hover:bg-[#f0f3ff] hover:border-[#00205b]">
                    {/* SVG Google (sama seperti di atas) */}
                    <svg className="h-4 w-4" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
                    GOOGLE
                </a>
                <button type="button" className="flex h-11 items-center justify-center gap-2 rounded border border-[#c5c6d1] bg-white px-4 text-sm font-semibold text-[#151c27] transition-all hover:bg-[#f0f3ff] hover:border-[#00205b]">
                    {/* SVG Apple (sama seperti di atas) */}
                    <svg className="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/></svg>
                    iOS APPLE
                </button>
            </div>
        </>
    );
}