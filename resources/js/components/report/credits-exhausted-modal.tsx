import { Link } from '@inertiajs/react'
import { CreditCard, X } from 'lucide-react'

export default function CreditExhaustedModal({ onClose }: { onClose: () => void}) {
        return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div className="relative w-full max-w-md rounded-lg bg-white shadow-xl">
                <div className="h-1 rounded-t-lg bg-[#bb001a]" />
                <div className="p-8 text-center">
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close dialog"
                        className="absolute right-4 top-4 rounded-md p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                    >
                        <X className="size-5" />
                    </button>
                    <div className="mx-auto mb-4 flex size-14 items-center justify-center rounded bg-red-50">
                        <CreditCard className="size-6 text-[#bb001a]" />
                    </div>
                    <h2 className="text-xl font-bold text-[#151c27]">Credits Exhausted</h2>
                    <p className="mt-2 text-sm text-slate-500">
                        Your account balance is zero. Top up your credits to continue generating premium vehicle reports.
                    </p>
                    <Link href="/pricing?tab=credits" className="mt-6 block rounded bg-[#bb001a] py-3 text-sm font-bold text-white">
                        BUY CREDITS
                    </Link>
                    <Link href="/pricing?tab=plans" className="mt-3 block rounded border border-slate-800 py-3 text-sm font-bold text-[#151c27]">
                        VIEW PLANS
                    </Link>
                </div>
            </div>
        </div>
    );
}