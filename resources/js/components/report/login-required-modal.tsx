import { Link } from '@inertiajs/react';
import { UserCircle } from 'lucide-react';

export default function LoginRequiredModal({ onClose }: { onClose: () => void }) {
        return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div className="w-full max-w-md rounded-lg bg-white p-8 text-center shadow-xl">
                <div className="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-red-50">
                    <UserCircle className="size-7 text-[#bb001a]" />
                </div>
                <h2 className="text-xl font-bold text-[#151c27]">Login Required</h2>
                <p className="mt-2 text-sm text-slate-500">
                    To see detailed history records, including finance, theft alerts, and full accident history, please sign in to your UKcardoc account.
                </p>
                <Link href="/login" className="mt-6 block rounded bg-[#00205b] py-3 text-sm font-bold text-white">
                    Sign In to Account
                </Link>
                <Link href="/register" className="mt-3 block rounded border border-slate-200 py-3 text-sm font-bold text-[#151c27]">
                    Register for Free
                </Link>
                <button onClick={onClose} className="mt-4 text-sm text-slate-400 underline">
                    Continue with Limited View
                </button>
            </div>
        </div>
    );
}