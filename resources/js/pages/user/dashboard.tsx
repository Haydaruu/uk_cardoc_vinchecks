import {Head, usePage} from '@inertiajs/react';
import GuestHero from '@/components/home/guest-hero';
import UserDashboard from '@/components/home/user-dashboard';
import BaseLayout from '@/layouts/base-layout';
import { ReactNode } from 'react';

type SharedProps = {
    auth: {
        user: {
            name: string;
            credits: number;
            is_premium: boolean;
        } | null;
    };
};

function Dashboard() {
    const { auth } = usePage<SharedProps>().props;

    return (
        <>
            <Head title="UK Vehicle History Check"/>
            {auth.user ? <UserDashboard user={auth.user} /> : <GuestHero />}
        </>
    );
}

Dashboard.layout = (page: ReactNode) => <BaseLayout>{page}</BaseLayout>;

export default Dashboard;