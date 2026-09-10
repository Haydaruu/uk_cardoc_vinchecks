import {
    Head,
    usePage,
} from '@inertiajs/react';

import UserDashboard, {
    type RecentReport,
} from '@/components/home/user-dashboard';

import BaseLayout from '@/layouts/base-layout';

import {
    type ReactNode,
} from 'react';

type DashboardPageProps = {
    auth: {
        user: {
            name: string;
            credits: number;
            is_premium: boolean;
        } | null;
    };

    recentReports:
        RecentReport[];

    recentSearches:
        string[];
};

function Dashboard() {
    const {
        auth,
        recentReports,
        recentSearches,
    } =
        usePage<DashboardPageProps>()
            .props;

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
                user={
                    auth.user
                }
                recentReports={
                    recentReports
                }
                recentSearches={
                    recentSearches
                }
            />
        </>
    );
}

Dashboard.layout = (
    page: ReactNode,
) => (
    <BaseLayout>
        {page}
    </BaseLayout>
);

export default Dashboard;