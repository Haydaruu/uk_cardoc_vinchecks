export default function AuthLayoutLeftPanel() {
    return (
        <div className="relative hidden h-full flex-col justify-between bg-primary p-12 text-white lg:flex">
            {/* Background car image */}
            <img
                src="/images/auth/bg_login_register.webp"
                alt="Vintage British car interior"
                className="absolute inset-0 h-full w-full object-cover opacity-80"
            />

            {/* Dark gradient overlay for readability */}
            <div className="absolute inset-0 bg-linear-to-b from-black/40 via-black/20 to-black/60" />

            {/* Diagonal design element */}
            <div className="pointer-events-none absolute right-0 bottom-0 h-full w-full bg-linear-to-tr from-primary/40 to-transparent" />

            {/* Top branding section */}
            <div className="relative z-20">
                {/* UKcardoc title - Large bold text */}
                <h1
                    className="mb-6 text-[52px] leading-none font-bold tracking-tighter"
                    style={{
                        fontWeight: 700,
                        letterSpacing: '-0.02em',
                    }}
                >
                    UKcardoc
                </h1>

                {/* Tagline - Directly below title */}
                <p className="font-body-lg max-w-md text-body-lg leading-relaxed opacity-90">
                    Precision vehicle history for the modern enthusiast.
                    <br />
                    Institutional trust through automotive clarity.
                </p>
            </div>

            {/* Center - Empty space for visual balance */}
            <div className="relative z-20 my-auto" />

            {/* Bottom — Sovereign Assurance card */}
            <div className="relative z-20">
                <div className="rounded-lg border border-white/20 bg-white/10 p-8 backdrop-blur-md">
                    <div className="mb-4 flex items-center gap-4">
                        {/* Red shield icon */}
                        <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-secondary shadow-lg">
                            <svg
                                className="h-6 w-6 text-white"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="2"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                            >
                                <path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                        </div>
                        <div>
                            <p className="font-h3 text-[18px]">
                                Sovereign Assurance
                            </p>
                            <p className="text-sm opacity-70">
                                Official UK Data Verification
                            </p>
                        </div>
                    </div>

                    {/* Sovereign line divider */}
                    <div className="relative mb-4 h-px bg-white/50">
                        <div className="bg-#bb001a absolute top-0 left-0 h-full w-10" />
                    </div>

                    <p className="text-sm italic opacity-80">
                        &ldquo;The standard of excellence in UK vehicle
                        diagnostics and historical reporting.&rdquo;
                    </p>
                </div>
            </div>
        </div>
    );
}
