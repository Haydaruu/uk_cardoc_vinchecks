import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

import UserDashboard from '@/components/home/user-dashboard';
import type { RecentReport } from '@/components/home/user-dashboard';
import BaseLayout from '@/layouts/base-layout';

type DashboardPageProps = {
    auth: {
        user: {
            name: string;
            credits: number;
            is_premium: boolean;
            subscription_plan: string | null;
        } | null;
    };

    recentReports: RecentReport[];

    recentSearches: string[];
};

function Dashboard() {
    const { auth, recentReports, recentSearches } =
        usePage<DashboardPageProps>().props;

    /*
     * Route dashboard memang
     * protected oleh auth,
     * tapi type shared Inertia
     * tetap mengizinkan null.
     */
    if (!auth.user) {
        return null;
    }

    return (
        <>
            <Head title="UK Vehicle History Check" />

            <UserDashboard
                user={auth.user}
                recentReports={recentReports}
                recentSearches={recentSearches}
            />
        </>
    );
}

Dashboard.layout = (page: ReactNode) => <BaseLayout>{page}</BaseLayout>;

export default Dashboard;
