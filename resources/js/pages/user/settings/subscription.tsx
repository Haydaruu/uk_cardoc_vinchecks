import SettingsLayout from "@/layouts/settings/settings-layout";
import { router } from '@inertiajs/react';

export default function Subscription() {
    return (
        <SettingsLayout>
            <div className="p-8">
                <h1 className="text-4x1 font-bold text-primary">
                    Subscription
                </h1>
                <button
                    onClick={() => router.post('/settings/subscription/checkout')}>
                    Subscribe
                </button>
            </div>
        </SettingsLayout>
    );
}