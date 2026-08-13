// resources/js/pages/vehicle-check/loading.tsx
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ShieldCheck } from 'lucide-react';

type VinCheckProps = {
    id: number;
    stage: string;
    status: 'pending' | 'success' | 'failed';
    check_type: 'free' | 'premium';
    registration_number: string;
};

// Definisikan urutan step per tier — ini yang bikin desain guest (2 step) vs premium (3 step) otomatis kepakai
// Catatan: label sengaja tanpa "..." statis karena sekarang dot dianimasikan terpisah (lihat DOT_FRAMES)
const STEPS = {
    free: [
        { key: 'connecting', label: 'Connecting to DVLA' },
        { key: 'finalizing', label: 'Finalizing Sovereign Report' },
    ],
    premium: [
        { key: 'connecting', label: 'Connecting to DVLA' },
        { key: 'verifying_history', label: 'Verifying vehicle history' },
        { key: 'finalizing', label: 'Finalizing Sovereign Report' },
    ],
} as const;

const DOT_FRAMES = ['.', '..', '...'];
const DOT_INTERVAL_MS = 400;

// Progress bar step aktif mendekati 92% mengikuti waktu berjalan sejak step ini jadi aktif.
// Sisa 8% baru terisi penuh saat poll berikutnya konfirmasi backend benar-benar pindah stage —
// jadi bar tidak pernah "berbohong" bilang selesai sebelum job.handle() beneran maju.
const PROGRESS_CAP = 92;
const PROGRESS_TIME_CONSTANT_MS = 4000;

export default function LoadingReport() {
    const { props } = usePage<{ vinCheck: VinCheckProps }>();
    const { vinCheck } = props;
    const steps = STEPS[vinCheck.check_type];
    const currentIndex = Math.max(0, steps.findIndex((s) => s.key === vinCheck.stage));

    const [attempts, setAttempts] = useState(0);
    const MAX_ATTEMPTS = 30;

    // Animasi titik "." -> ".." -> "..." -> kembali dari awal, jalan terus untuk step yang aktif
    const [dotFrame, setDotFrame] = useState(0);
    useEffect(() => {
        const dotInterval = setInterval(() => {
            setDotFrame((prev) => (prev + 1) % DOT_FRAMES.length);
        }, DOT_INTERVAL_MS);
        return () => clearInterval(dotInterval);
    }, []);

    // Progress bar realtime: reset ke 0 tiap kali step aktif berpindah (mulai dari kiri lagi),
    // lalu terus bertambah ke kanan mengikuti waktu berjalan sambil menunggu poll berikutnya
    const [stepStartedAt, setStepStartedAt] = useState(() => Date.now());
    const [now, setNow] = useState(() => Date.now());
    const previousIndexRef = useRef(currentIndex);

    useEffect(() => {
        if (previousIndexRef.current !== currentIndex) {
            previousIndexRef.current = currentIndex;
            setStepStartedAt(Date.now());
        }
    }, [currentIndex]);

    useEffect(() => {
        const tick = setInterval(() => setNow(Date.now()), 100);
        return () => clearInterval(tick);
    }, []);

    const elapsedInStep = now - stepStartedAt;
    const activeProgress = PROGRESS_CAP * (1 - Math.exp(-elapsedInStep / PROGRESS_TIME_CONSTANT_MS));

    useEffect(() => {
        if (vinCheck.status !== 'pending' || attempts >= MAX_ATTEMPTS) return;

        const interval = setInterval(() => {
            setAttempts((prev) => prev + 1);
            router.reload({ only: ['vinCheck'] });
        }, 1500);

        return () => clearInterval(interval);
    }, [vinCheck.status, attempts]);

    useEffect(() => {
        if (vinCheck.status === 'success') {
            router.visit(`/vehicle-check/${vinCheck.id}/loading`); // controller otomatis redirect ke report
        }
    }, [vinCheck.status]);

    if (vinCheck.status === 'failed') {
        return (
            <div className="flex min-h-svh items-center justify-center">
                <div className="text-center">
                    <p className="text-lg font-bold text-[#bb001a]">Vehicle check failed</p>
                    <p className="mt-2 text-sm text-slate-500">
                        We encountered an issue while retrieving the vehicle details. Please try again.
                    </p>
                </div>
            </div>
        );
    }

    return (
        <>
            <Head title="Processing your report..." />
            <div className="flex min-h-svh flex-col items-center justify-center bg-[#f9f9ff] px-6">
                <div className="mb-6 text-center">
                    <h1 className="text-2xl font-bold text-[#00205b]">UKcardoc</h1>
                    <p className="mt-1 text-[10px] font-bold uppercase tracking-[0.2em] text-[#bb001a]">
                        Sovereign Assurance
                    </p>
                </div>

                <div className="w-full max-w-md rounded-lg border border-slate-200 bg-white p-8 shadow-sm">
                    <div className="mb-6 flex justify-center">
                        <div className="flex size-16 items-center justify-center rounded-full border-2 border-[#bb001a]">
                            <ShieldCheck className="size-6 text-[#00205b]" />
                        </div>
                    </div>

                    <div className="space-y-3">
                        {steps.map((step, index) => {
                            const isActive = index === currentIndex;
                            const isDone = index < currentIndex;
                            const isPending = index > currentIndex;

                            return (
                                <div key={step.key}>
                                    <div className="flex items-center justify-between text-sm">
                                        <span className={isPending ? 'text-slate-400' : 'font-semibold text-[#151c27]'}>
                                            {step.label}
                                            {isActive && (
                                                <span className="inline-block w-4 text-left">{DOT_FRAMES[dotFrame]}</span>
                                            )}
                                        </span>
                                        <span
                                            className={
                                                isActive
                                                    ? 'text-[10px] font-bold uppercase text-[#bb001a]'
                                                    : isDone
                                                    ? 'text-[10px] font-bold uppercase text-green-600'
                                                    : 'text-[10px] font-bold uppercase text-slate-300'
                                            }
                                        >
                                            {isActive ? 'ACTIVE' : isDone ? 'DONE' : 'QUEUED'}
                                        </span>
                                    </div>
                                    {isActive && (
                                        <div className="mt-1.5 h-1 w-full overflow-hidden rounded-full bg-slate-100">
                                            <div
                                                className="h-full rounded-full bg-[#bb001a] transition-[width] duration-150 ease-linear"
                                                style={{ width: `${activeProgress}%` }}
                                            />
                                        </div>
                                    )}
                                    {isDone && (
                                        <div className="mt-1.5 h-1 w-full overflow-hidden rounded-full bg-slate-100">
                                            <div className="h-full w-full rounded-full bg-green-500" />
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>

                    <div className="mt-6 flex items-center justify-between rounded bg-[#f0f3ff] px-4 py-3 text-xs">
                        <span className="text-slate-500">
                            REG: <span className="font-mono font-semibold text-[#151c27]">{vinCheck.registration_number}</span>
                        </span>
                        <ShieldCheck className="size-4 text-slate-400" />
                    </div>
                </div>

                <p className="mt-6 max-w-md text-center text-sm text-slate-500">
                    Accessing the UK's most comprehensive vehicle data network. Our real-time connection ensures 100% accuracy for your peace of mind.
                </p>
            </div>
        </>
    );
}
