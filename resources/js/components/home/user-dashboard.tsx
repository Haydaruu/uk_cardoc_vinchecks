type Props = {
    user: { name: string; credits: number; is_premium: boolean };
};

export default function UserDashboard({ user }: Props) {
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
                    <div className="mt-4 flex gap-3">
                        <input
                            type="text"
                            placeholder="ENTER REG (E.G. AB12 CDE)"
                            className="h-12 flex-1 rounded border border-slate-200 px-4 text-sm uppercase tracking-widest placeholder:normal-case"
                        />
                        <button className="rounded bg-secondary px-6 text-sm font-bold uppercase text-white">
                            Check
                        </button>
                    </div>
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