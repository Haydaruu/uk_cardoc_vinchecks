import { Head } from '@inertiajs/react';
import { useState } from 'react';

import LoginForm from '@/components/auth/login-form';
import RegisterForm from '@/components/auth/register-form';
import AuthLayoutLeftPanel from '@/layouts/auth/auth-layout-left-panel';

type Props = { initialTab?: 'login' | 'register' };
export default function AuthPage({ initialTab = 'login' }: Props) {
    const [activeTab, setActiveTab] = useState(initialTab);

    return (
        <>
            <Head title={activeTab === 'login' ? 'Login' : 'Create Account'} />

            {/* Gunakan min-h-screen di tingkat tertinggi */}
            <div className="flex min-h-screen w-full bg-white">
                {/* PANEL KIRI - Gunakan md (768px) bukan lg (1024px) */}
                <div className="sticky top-0 hidden h-screen overflow-hidden md:block md:w-1/2">
                    <AuthLayoutLeftPanel />
                </div>

                {/* PANEL KANAN - Dengan struktur yang sama seperti desain asli */}
                <div className="flex w-full flex-col md:w-1/2">
                    {/* Tab Toggle - Sesuaikan padding */}
                    <div className="mx-auto flex w-full max-w-md border-b border-slate-200 px-6 pt-8">
                        <button
                            onClick={() => setActiveTab('login')}
                            className={`flex-1 border-b-2 py-4 text-center text-[15px] font-semibold ${
                                activeTab === 'login'
                                    ? 'border-[#bb001a] text-[#000d2f]'
                                    : 'border-transparent text-slate-400 hover:text-[#000d2f]'
                            }`}
                        >
                            Login
                        </button>
                        <button
                            onClick={() => setActiveTab('register')}
                            className={`flex-1 border-b-2 py-4 text-center text-[15px] font-semibold ${
                                activeTab === 'register'
                                    ? 'border-[#bb001a] text-[#000d2f]'
                                    : 'border-transparent text-slate-400 hover:text-[#000d2f]'
                            }`}
                        >
                            Create Account
                        </button>
                    </div>

                    {/* Area Form - Gunakan max-w-md konsisten */}
                    <div className="flex w-full flex-1 items-center justify-center px-6 py-10">
                        <div className="w-full max-w-md space-y-6">
                            {activeTab === 'login' ? (
                                <LoginForm canResetPassword={true} />
                            ) : (
                                <RegisterForm />
                            )}
                        </div>
                    </div>

                    {/* Footer - Sesuaikan dengan desain asli */}
                    <div className="mx-auto w-full max-w-md p-6 text-center">
                        <p className="text-[11px] font-semibold tracking-widest text-slate-400 uppercase">
                            © 2026 UKcardoc Ltd. Registered in England & Wales.
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}

AuthPage.layout = (page: React.ReactNode) => page;
