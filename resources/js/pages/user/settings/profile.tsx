import SettingsLayout from "@/layouts/settings/settings-layout";
import { Link, useForm, usePage } from '@inertiajs/react';
import {
    BadgeCheck,
    CircleUserRound,
    Crown,
    KeyRound,
    ShieldCheck,
} from 'lucide-react';
import type { Auth } from "@/types";


type ProfileData = {
    name: string;
    phone_number: string | null;
    email: string;
    email_verified_at: string | null;
    avatar: string | null;
    credits: number;
    created_at: string;
};

type ProfileProps = {
    profile: ProfileData;
};

type SharedProps = {
    auth: Auth;
    flash?: {
        success?: string | null;
    };
};


function getInitials(name: string) {
    return name
        .trim()
        .split(/\s+/)
        .map((word) => word[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

function formatMemberSince(date: string): string{
    return new Intl.DateTimeFormat('en-GB', {
        month: 'long',
        year: 'numeric',
    }).format(new Date(date));
}

function formatAccountId(id: number): string {
    return `UKC-${String(id).padStart(5, '0')}`;
}

export default function Profile({ profile } : ProfileProps) {
    const { auth, flash } = usePage<SharedProps>().props;
    const user = auth.user;
    const form = useForm({
        name: profile.name,
        phone_number: profile.phone_number ?? '',
    });
    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        form.patch('/settings/profile', {
            preserveScroll: true,
        });
    }

    return (
        <SettingsLayout>
            <div
                className="min-h-screen bg-surface">
                <div className="mx-auto max-w-container-max px-margin-mobile py-10 md:px-gutter lg:py-section-padding">
                    {/* Page Header */}
                    <header className="mb-10 lg:mb-12">
                        <h1 className="text-h1 font-bold text-primary">
                            Profile
                        </h1>

                        <p className="mt-2 max-w-2xl text-body-lg text-on-surface-variant">
                            Manage your personal information and account details.
                        </p>

                        <div className="sovereign-line mt-6" />
                    </header>

                    {/* Success Message */}
                    {flash?.success && (
                        <div
                            role="status"
                            className="
                                mb-6 flex items-center gap-3
                                border border-green-200
                                bg-green-50 px-4 py-3
                                text-sm text-green-800
                            "
                        >
                            <BadgeCheck className="h-5 w-5 shrink-0" />

                            <span>{flash.success}</span>
                        </div>
                    )}

                    {/* Main Grid */}
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        {/* Profile Information */}
                        <section
                            className="
                                glass-card dashboard-shadow dashboard-shadow-hover
                                lg:col-span-2
                                rounded-xl border border-outline-variant
                                p-6 transition-shadow duration-300
                                lg:p-8
                            "
                        >
                            <h2
                                className="
                                    mb-8 text-label-sm
                                    font-semibold uppercase
                                    tracking-wider
                                    text-on-surface-variant
                                "
                            >
                                Profile Information
                            </h2>

                            <form onSubmit={submit}>
                                <div className="flex flex-col items-start gap-8 sm:flex-row">
                                    {/* Avatar */}
                                    <div className="shrink-0">
                                        {profile.avatar ? (
                                            <div
                                                className="
                                                    h-24 w-24 overflow-hidden
                                                    rounded-full
                                                    border-2 border-outline-variant
                                                    bg-surface-container-high
                                                "
                                            >
                                                <img
                                                    src={profile.avatar}
                                                    alt={`${profile.name} avatar`}
                                                    className="h-full w-full object-cover"
                                                />
                                            </div>
                                        ) : (
                                            <div
                                                className="
                                                    flex h-24 w-24 items-center justify-center
                                                    rounded-full
                                                    border-2 border-outline-variant
                                                    bg-primary
                                                    text-xl font-bold text-on-primary
                                                "
                                                aria-label={`${profile.name} initials`}
                                            >
                                                {getInitials(profile.name)}
                                            </div>
                                        )}
                                    </div>

                                    {/* Fields */}
                                    <div className="w-full flex-1 space-y-6">
                                        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                            {/* Name */}
                                            <div className="space-y-2">
                                                <label
                                                    htmlFor="name"
                                                    className="
                                                        block text-label-sm
                                                        font-semibold
                                                        text-on-surface-variant
                                                    "
                                                >
                                                    Full Name
                                                </label>

                                                <input
                                                    id="name"
                                                    type="text"
                                                    autoComplete="name"
                                                    value={form.data.name}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'name',
                                                            event.target.value,
                                                        )
                                                    }
                                                    className={`
                                                        w-full rounded-md
                                                        border bg-surface px-3 py-3
                                                        font-body text-body-md
                                                        text-primary
                                                        outline-none
                                                        transition-colors
                                                        focus:border-primary
                                                        focus:ring-1 focus:ring-primary
                                                        ${
                                                            form.errors.name
                                                                ? 'border-error'
                                                                : 'border-outline-variant'
                                                        }
                                                    `}
                                                />

                                                {form.errors.name && (
                                                    <p className="text-sm text-error">
                                                        {form.errors.name}
                                                    </p>
                                                )}
                                            </div>

                                            {/* Phone */}
                                            <div className="space-y-2">
                                                <label
                                                    htmlFor="phone_number"
                                                    className="
                                                        block text-label-sm
                                                        font-semibold
                                                        text-on-surface-variant
                                                    "
                                                >
                                                    Phone Number
                                                </label>

                                                <input
                                                    id="phone_number"
                                                    type="tel"
                                                    autoComplete="tel"
                                                    value={form.data.phone_number}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'phone_number',
                                                            event.target.value,
                                                        )
                                                    }
                                                    className={`
                                                        w-full rounded-md
                                                        border bg-surface px-3 py-3
                                                        font-mono text-sm
                                                        text-primary
                                                        outline-none
                                                        transition-colors
                                                        focus:border-primary
                                                        focus:ring-1 focus:ring-primary
                                                        ${
                                                            form.errors.phone_number
                                                                ? 'border-error'
                                                                : 'border-outline-variant'
                                                        }
                                                    `}
                                                />

                                                {form.errors.phone_number && (
                                                    <p className="text-sm text-error">
                                                        {
                                                            form.errors
                                                                .phone_number
                                                        }
                                                    </p>
                                                )}
                                            </div>
                                        </div>

                                        {/* Email */}
                                        <div className="space-y-2">
                                            <label
                                                htmlFor="email"
                                                className="
                                                    flex items-center gap-2
                                                    text-label-sm
                                                    font-semibold
                                                    text-on-surface-variant
                                                "
                                            >
                                                Email Address

                                                {profile.email_verified_at ? (
                                                    <span
                                                        className="
                                                            inline-flex items-center gap-1
                                                            rounded-full
                                                            border border-outline-variant
                                                            bg-surface-container-high
                                                            px-2 py-1
                                                            text-[10px]
                                                            font-bold uppercase
                                                            text-primary
                                                        "
                                                    >
                                                        <BadgeCheck className="h-3.5 w-3.5 text-green-700" />
                                                        Verified
                                                    </span>
                                                ) : (
                                                    <span
                                                        className="
                                                            rounded-full
                                                            bg-error-container
                                                            px-2 py-1
                                                            text-[10px]
                                                            font-bold uppercase
                                                            text-on-error-container
                                                        "
                                                    >
                                                        Not Verified
                                                    </span>
                                                )}
                                            </label>

                                            <input
                                                id="email"
                                                type="email"
                                                value={profile.email}
                                                readOnly
                                                disabled
                                                className="
                                                    w-full cursor-not-allowed
                                                    rounded-md
                                                    border border-outline-variant
                                                    bg-surface-container-low
                                                    px-3 py-3
                                                    font-body text-body-md
                                                    text-on-surface-variant
                                                    opacity-100
                                                "
                                            />
                                        </div>

                                        {/* Actions */}
                                        <div
                                            className="
                                                flex justify-end
                                                border-t border-outline-variant/50
                                                pt-4
                                            "
                                        >
                                            <button
                                                type="submit"
                                                disabled={
                                                    form.processing ||
                                                    !form.isDirty
                                                }
                                                className="
                                                    rounded-md
                                                    bg-secondary
                                                    px-6 py-2.5
                                                    text-label-sm
                                                    font-semibold uppercase
                                                    text-on-secondary
                                                    shadow-sm
                                                    transition
                                                    hover:bg-secondary-container
                                                    focus:outline-none
                                                    focus:ring-2
                                                    focus:ring-secondary
                                                    focus:ring-offset-2
                                                    disabled:cursor-not-allowed
                                                    disabled:opacity-50
                                                "
                                            >
                                                {form.processing
                                                    ? 'Saving...'
                                                    : 'Save Changes'}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </section>

                        {/* Right Column */}
                        <aside className="flex flex-col gap-6">
                            {/* Account Info */}
                            <section
                                className="
                                    glass-card dashboard-shadow dashboard-shadow-hover
                                    flex-1 rounded-xl
                                    border border-outline-variant
                                    p-6 transition-shadow duration-300
                                "
                            >
                                <div
                                    className="
                                        mb-6 flex items-center gap-2
                                        border-b border-outline-variant
                                        pb-3
                                    "
                                >
                                    <CircleUserRound className="h-5 w-5 text-primary" />

                                    <h2
                                        className="
                                            text-label-sm font-semibold
                                            uppercase tracking-wider
                                            text-on-surface-variant
                                        "
                                    >
                                        Account Info
                                    </h2>
                                </div>

                                <div className="text-sm">
                                    {user && (
                                        <div
                                            className="
                                                flex items-center justify-between
                                                border-b border-outline-variant/30
                                                py-3
                                            "
                                        >
                                            <span className="text-on-surface-variant">
                                                Account ID
                                            </span>

                                            <span className="font-mono font-semibold text-primary">
                                                {formatAccountId(user.id)}
                                            </span>
                                        </div>
                                    )}

                                    <div
                                        className="
                                            flex items-center justify-between
                                            border-b border-outline-variant/30
                                            py-3
                                        "
                                    >
                                        <span className="text-on-surface-variant">
                                            Member Since
                                        </span>

                                        <span className="font-medium text-primary">
                                            {formatMemberSince(
                                                profile.created_at,
                                            )}
                                        </span>
                                    </div>

                                    <div
                                        className="
                                            flex items-center justify-between
                                            border-b border-outline-variant/30
                                            py-3
                                        "
                                    >
                                        <span className="text-on-surface-variant">
                                            Email Status
                                        </span>

                                        {profile.email_verified_at ? (
                                            <span className="inline-flex items-center gap-1 font-semibold text-green-700">
                                                <BadgeCheck className="h-4 w-4" />
                                                Verified
                                            </span>
                                        ) : (
                                            <span className="font-semibold text-error">
                                                Not Verified
                                            </span>
                                        )}
                                    </div>

                                    <div className="flex items-center justify-between py-3">
                                        <span className="text-on-surface-variant">
                                            Available Credits
                                        </span>

                                        <span className="font-mono font-bold text-primary">
                                            {profile.credits.toLocaleString(
                                                'en-GB',
                                            )}
                                        </span>
                                    </div>
                                </div>
                            </section>

                            {/* Status */}
                            <section
                                className="
                                    glass-card dashboard-shadow dashboard-shadow-hover
                                    relative flex-1 overflow-hidden
                                    rounded-xl
                                    border border-outline-variant
                                    p-6 transition-shadow duration-300
                                "
                            >
                                <div
                                    className="
                                        pointer-events-none
                                        absolute -right-8 -top-8
                                        h-24 w-24 rounded-full
                                        bg-primary/5 blur-xl
                                    "
                                />

                                <div
                                    className="
                                        relative mb-6 flex items-center gap-2
                                        border-b border-outline-variant
                                        pb-3
                                    "
                                >
                                    <ShieldCheck className="h-5 w-5 text-primary" />

                                    <h2
                                        className="
                                            text-label-sm font-semibold
                                            uppercase tracking-wider
                                            text-on-surface-variant
                                        "
                                    >
                                        Status
                                    </h2>
                                </div>

                                <div className="relative space-y-6">
                                    <div>
                                        <span
                                            className="
                                                mb-2 block text-label-sm
                                                font-semibold
                                                text-on-surface-variant
                                            "
                                        >
                                            Account Status
                                        </span>

                                        <div className="flex items-center gap-2">
                                            <span className="h-2 w-2 rounded-full bg-green-500" />

                                            <span className="font-semibold text-primary">
                                                Active
                                            </span>
                                        </div>
                                    </div>

                                    <div>
                                        <span
                                            className="
                                                mb-2 block text-label-sm
                                                font-semibold
                                                text-on-surface-variant
                                            "
                                        >
                                            Authentication
                                        </span>

                                        <Link
                                            href="/settings/connected-accounts"
                                            className="
                                                inline-flex items-center gap-2
                                                rounded-md
                                                border border-outline-variant
                                                bg-surface-container-high
                                                px-2.5 py-1.5
                                                text-sm font-medium
                                                text-primary
                                                transition-colors
                                                hover:bg-surface-container-highest
                                            "
                                        >
                                            <KeyRound className="h-4 w-4" />
                                            Manage sign-in methods
                                        </Link>
                                    </div>

                                    <div>
                                        <span
                                            className="
                                                mb-2 block text-label-sm
                                                font-semibold
                                                text-on-surface-variant
                                            "
                                        >
                                            Account Tier
                                        </span>

                                        <div className="inline-flex items-center gap-2 font-semibold text-secondary">
                                            <Crown className="h-4 w-4" />

                                            {user?.is_premium
                                                ? 'Premium'
                                                : 'Standard'}
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </aside>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    );
}