import { useState } from 'react';
import { Head } from '@inertiajs/react';
import AuthLayoutLeftPanel from '@/layouts/auth/auth-layout-left-panel';
import LoginForm from '@/components/auth/login-form';
import RegisterForm from '@/components/auth/register-form';

export default function AuthPage() {
    const [activeTab, setActiveTab] = useState('login');

    return (
        <>
            <Head title={activeTab === 'login' ? 'Login' : 'Create Account'} />
            
            {/* Gunakan min-h-screen di tingkat tertinggi */}
            <div className="flex min-h-screen w-full bg-white">
                {/* PANEL KIRI - Gunakan md (768px) bukan lg (1024px) */}
                <div className="hidden md:block md:w-1/2 relative min-h-screen">
                    <AuthLayoutLeftPanel />
                </div>

                {/* PANEL KANAN - Dengan struktur yang sama seperti desain asli */}
                <div className="w-full md:w-1/2 flex flex-col">
                    {/* Tab Toggle - Sesuaikan padding */}
                    <div className="w-full max-w-md mx-auto flex border-b border-slate-200 px-6 pt-8">
                        <button
                            onClick={() => setActiveTab('login')}
                            className={`flex-1 py-4 text-center font-semibold text-[15px] border-b-2 ${
                                activeTab === 'login'
                                    ? 'border-[#bb001a] text-[#000d2f]'
                                    : 'border-transparent text-slate-400 hover:text-[#000d2f]'
                            }`}
                        >
                            Login
                        </button>
                        <button
                            onClick={() => setActiveTab('register')}
                            className={`flex-1 py-4 text-center font-semibold text-[15px] border-b-2 ${
                                activeTab === 'register'
                                    ? 'border-[#bb001a] text-[#000d2f]'
                                    : 'border-transparent text-slate-400 hover:text-[#000d2f]'
                            }`}
                        >
                            Create Account
                        </button>
                    </div>

                    {/* Area Form - Gunakan max-w-md konsisten */}
                    <div className="flex-1 flex items-center justify-center w-full px-6 py-10">
                        <div className="w-full max-w-md space-y-6">
                            {activeTab === 'login' ? (
                                <LoginForm canResetPassword={true} />
                            ) : (
                                <RegisterForm />
                            )}
                        </div>
                    </div>

                    {/* Footer - Sesuaikan dengan desain asli */}
                    <div className="w-full max-w-md mx-auto p-6 text-center">
                        <p className="text-[11px] text-slate-400 uppercase tracking-widest font-semibold">
                            © 2024 UKcardoc Ltd. Registered in England & Wales.
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}

AuthPage.layout = (page: React.ReactNode) => page;