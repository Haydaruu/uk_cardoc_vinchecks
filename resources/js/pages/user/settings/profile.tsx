import SettingsLayout from "@/layouts/settings/settings-layout";
import { useForm } from "@inertiajs/react";

type ProfileData = {
    name: string;
    phone_number: string | null;
    email: string;
    email_verified_at: string | null;
    avatar: string | null;
    credits: number;
    created_at: string;
};

type Props = {
    profile: ProfileData;
};
export default function Profile({ profile } : Props) {
    const form = useForm({
        name: profile.name,
        phone_number: profile.phone_number ?? '',
    });
    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        form.patch('settings/profile', {
            preserveScroll: true,
        });
    }

    return (
        <SettingsLayout>
            <div className="p-8">
                <h1 className="text-4x1 font-bold text-primary">
                    Profile
                </h1>
            </div>
        </SettingsLayout>
    );
}