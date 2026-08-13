import {Head, usePage} from '@inertiajs/react';
import UserDashboard, { type RecentReport} from '@/components/home/user-dashboard';
import BaseLayout from '@/layouts/base-layout';
import { ReactNode} from 'react';


type DasbhoardPageProps = {
    auth: {
        user: {
            name: string;
            credits: number;
            is_premium: boolean;
        } | null;
    };
    recentReports: RecentReport[];
    recentSearches: string[];
};

function Dashboard() {
    const { auth, recentReports, recentSearches } = usePage<DasbhoardPageProps>().props;

    return (
        <>
            <Head title="UK Vehicle History Check"/>
            <UserDashboard user={auth.user} recentReports={recentReports} recentSearches={recentSearches}/> 
        </>
    );
}

Dashboard.layout = (page: ReactNode) => <BaseLayout>{page}</BaseLayout>;

export default Dashboard;