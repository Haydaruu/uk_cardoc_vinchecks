export default function AuthLayoutLeftPanel() {
    return (
        <div className="relative hidden h-full min-h-svh flex-col justify-between bg-primary p-12 text-white lg:flex">
            {/* Background car image */}
            <img
                src="/images/auth/bg_login_register.webp"
                alt="Vintage British car interior"
                className="absolute inset-0 w-full h-full object-cover opacity-80"
            />

            {/* Dark gradient overlay for readability */}
            <div className="absolute inset-0 bg-linear-to-b from-black/40 via-black/20 to-black/60" />

            {/* Diagonal design element */}
            <div className="absolute bottom-0 right-0 w-full h-full bg-linear-to-tr from-primary/40 to-transparent pointer-events-none" />

            {/* Top branding section */}
            <div className="relative z-20">
                {/* UKcardoc title - Large bold text */}
                <h1 
                    className="text-[52px] font-bold tracking-tighter leading-none mb-6"
                    style={{ 
                        fontWeight: 700,
                        letterSpacing: '-0.02em'
                    }}
                >
                    UKcardoc
                </h1>
                
                {/* Tagline - Directly below title */}
                <p className="font-body-lg text-body-lg max-w-md opacity-90 leading-relaxed">
                    Precision vehicle history for the modern enthusiast.
                    <br />
                    Institutional trust through automotive clarity.
                </p>
            </div>

            {/* Center - Empty space for visual balance */}
            <div className="relative z-20 my-auto" />

            {/* Bottom — Sovereign Assurance card */}
            <div className="relative z-20">
                <div className="bg-white/10 backdrop-blur-md p-8 border border-white/20 rounded-lg">
                    <div className="flex items-center gap-4 mb-4">
                        {/* Red shield icon */}
                        <div className="w-12 h-12 bg-secondary flex items-center justify-center rounded-lg shadow-lg">
                            <svg 
                                className="h-6 w-6 text-white" 
                                viewBox="0 0 24 24" 
                                fill="none" 
                                stroke="currentColor" 
                                strokeWidth="2" 
                                strokeLinecap="round" 
                                strokeLinejoin="round"
                            >
                                <path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
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
                    <div className="relative h-px bg-white/50 mb-4">
                        <div className="absolute left-0 top-0 h-full w-10 bg-#bb001a" />
                    </div>

                    <p className="text-sm italic opacity-80">
                        &ldquo;The standard of excellence in UK vehicle diagnostics and historical reporting.&rdquo;
                    </p>
                </div>
            </div>
        </div>
    );
}