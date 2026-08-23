import { PropsWithChildren } from "react";
import SettingsSidebar from "@/components/settings/settings-sidebar";
export default function SettingsLayout({children}: PropsWithChildren) {
    return (
        <div className="min-h-screen flex bg-surface" >
            <SettingsSidebar />

            <main className="min-h-screen md:ml-64">
                {children}
            </main>

        </div>
    );
}