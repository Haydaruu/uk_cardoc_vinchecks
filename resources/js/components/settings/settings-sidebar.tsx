import { Link, usePage } from '@inertiajs/react';
import {
    Car,
    User,
    Shield,
    Link2,
    Receipt,
    CreditCard,
    HelpCircle,
    LogOut,
} from 'lucide-react';
import { logout } from '@/routes';


type AuthUser = {
    id: number;
    role: string;
    name: string;
    email: string;
    avatar: string | null;
    credits: number;
    is_premium: boolean;
};

type PageProps = {
    auth: {
        user: AuthUser | null;
    };
};

type NavItem = {
    label: string;
    href: string;
    icon: typeof User;
};


const NAV_ITEMS: NavItem[] = [
    { label: 'Profile', href: '/settings/profile', icon: User },
    { label: 'Security', href: '/settings/security', icon: Shield },
    { label: 'Connected Accounts', href: '/settings/connected-accounts', icon: Link2 },
    { label: 'Purchase History', href: '/settings/purchase-history', icon: Receipt },
    { label: 'Subscription', href: '/settings/subscription', icon: CreditCard },
    { label: 'Help Centre', href: '/settings/help', icon: HelpCircle },
];

export default function SettingsSidebar() {
    const { props, url } = usePage<PageProps>();
    const user = props.auth.user;

    if (!user) return null;

    const initials = user.name
        .split(' ')
        .map((part) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();

    const creditLabel = `${user.credits} ${user.credits === 1 ? 'Credit' : 'Credits'}`;
    const checkLabel = `${user.credits} vehicle check${user.credits === 1 ? '' : 's'} available`;

    return (
        <nav className="fixed left-0 top-0 z-50 hidden h-screen w-64 flex-col border-r border-outline-variant bg-surface-container-low p-base shadow-sm md:flex">
            <div className="mb-8 px-4">
                <div className="mt-4 flex items-center gap-2">
                    <div className="flex size-8 items-center justify-center rounded bg-primary">
                        <Car className="size-5 text-on-primary" />
                    </div>
                    <span className="font-h3 text-h3 block truncate font-bold text-primary">UKCarDoc</span>
                </div>
            </div>

            <div className="mb-8 flex items-center gap-3 px-4">
                <div className="size-10 flex-shrink-0 overflow-hidden rounded-full border border-outline-variant bg-surface-container-high">
                    {user.avatar ? (
                        <img src={user.avatar} alt={user.name} className="size-full object-cover" />
                    ) : (
                        <div className="flex size-full items-center justify-center font-bold text-primary">
                            {initials}
                        </div>
                    )}
                </div>
                <div className="min-w-0">
                    <p className="font-body-md text-body-md truncate font-semibold text-primary">{user.name}</p>
                    <p className="font-label-sm text-label-sm truncate font-normal text-on-surface-variant">
                        {user.email}
                    </p>
                </div>
            </div>

            <div className="mb-6 px-4">
                <div className="rounded-lg border border-outline-variant/50 bg-primary/5 p-3">
                    <div className="mb-1 flex items-center justify-between">
                        <span className="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">
                            Available Credits
                        </span>
                        <span className="font-mono text-sm font-bold text-primary">{user.credits}</span>
                    </div>
                    <p className="mb-2 text-[11px] text-on-surface-variant">{checkLabel}</p>
                    <Link
                        href="/pricing"
                        className="block w-full rounded bg-secondary py-1.5 text-center text-[11px] font-bold uppercase text-on-secondary transition-colors hover:bg-secondary-container"
                    >
                        Top Up Credits
                    </Link>
                </div>
            </div>

            <div className="flex-1 space-y-1 overflow-y-auto">
                {NAV_ITEMS.map((item) => {
                    const isActive = url === item.href;
                    const Icon = item.icon;

                    return (
                        <Link
                            key={item.href}
                            href={item.href}
                            className={
                                isActive
                                    ? 'flex items-center gap-3 rounded-r-lg border-l-4 border-secondary bg-primary/5 px-4 py-2.5 font-semibold text-primary transition-all'
                                    : 'flex items-center gap-3 rounded-lg px-4 py-2.5 text-on-surface-variant transition-all hover:bg-surface-container-highest'
                            }
                        >
                            <Icon className="size-5" />
                            <span>{item.label}</span>
                        </Link>
                    );
                })}
            </div>

            <div className="mt-auto space-y-2 border-t border-outline-variant pt-4">
                <Link
                    href="/pricing"
                    className="font-label-sm text-label-sm block w-full rounded bg-primary px-4 py-2 text-center text-on-primary shadow-sm transition-colors hover:bg-primary-container"
                >
                    Upgrade Plan
                </Link>
                <Link
                    href={logout.url()}
                    method="post"
                    as="button"
                    className="mt-2 flex w-full items-center gap-3 rounded-lg px-4 py-2.5 text-on-surface-variant transition-all hover:bg-surface-container-highest"
                >
                    <LogOut className="size-5" />
                    <span>Logout</span>
                </Link>
            </div>
        </nav>
    );
}