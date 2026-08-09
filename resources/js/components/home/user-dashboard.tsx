import { useForm } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/VehicleCheckController';

type Props = {
    user: { name: string; credits: number; is_premium: boolean };
};

export default function UserDashboard({ user }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        registration_number: '',
    });

    function handleCheck(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post(store().url);
    }

    return (
        <div className="mx-auto max-w-[1200px] px-6 py-12 md:px-10">
            <h1 className="text-4xl font-black tracking-tight text-primary-container">
                Welcome back, {user.name}
            </h1>
            <p className="mt-2 max-w-xl text-slate-500">
                You have {user.credits} credits remaining. Perform a full vehicle history check to uncover hidden pasts, finance markers, and salvage data.
            </p>

            <div className="mt-8 grid gap-6 md:grid-cols-[1fr_320px]">
                <div className="rounded-lg border border-slate-200 p-8">
                <h2 className="text-xl font-bold text-primary-container">Start New Check</h2>
                <p className="mt-1 text-sm text-slate-500">
                    Enter the VRM (Registration) or VIN to instantly pull UK government and private database records.
                </p>
                <form onSubmit={handleCheck} className="mt-4 flex gap-3">
                    <div className="flex-1">
                        <input
                            type="text"
                            value={data.registration_number}
                            onChange={(e) => setData('registration_number', e.target.value)}
                            placeholder="ENTER REG (E.G. AB12 CDE)"
                            className="h-12 w-full rounded border border-slate-200 px-4 text-sm uppercase tracking-widest placeholder:normal-case"
                        />
                        {errors.registration_number && (
                            <p className="mt-1 text-xs text-red-600">{errors.registration_number}</p>
                        )}
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded bg-secondary px-6 text-sm font-bold uppercase text-white disabled:opacity-60"
                    >
                        {processing ? 'Checking...' : 'Check'}
                    </button>
                </form>
            </div>

                <div className="rounded-lg bg-primary-container p-6 text-white">
                    <p className="text-sm font-semibold uppercase tracking-widest text-white/70">
                        Your Balance
                    </p>
                    <p className="mt-2 text-4xl font-black">{user.credits}</p>
                    <p className="text-sm text-white/70">Full History Credits</p>
                    <button className="mt-4 w-full rounded bg-white py-2.5 text-sm font-bold text-primary-container">
                        Add More Credits
                    </button>
                </div>
            </div>

            {/* Recent Reports — butuh data asli dari backend, lihat catatan di bawah */}
        </div>
    );
}