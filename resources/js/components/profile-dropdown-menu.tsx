import { Link, Form } from '@inertiajs/react';
import { User, Settings, LogOut } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { logout } from '@/routes';

export default function ProfileDropdownMenu({ name }: { name: string }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button className="flex size-9 items-center justify-center rounded-full border border-slate-200">
                    <User size={18} />
                </button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
                align="end"
                className="w-48 rounded-lg border border-outline-variant bg-surface-container-lowest p-1"
            >
                <div className="px-2 py-1.5 text-sm font-semibold text-on-surface">{name}</div>

                <DropdownMenuSeparator className="my-1 h-px bg-outline-variant" />

                <DropdownMenuItem asChild>
                    <Link
                        href="/settings"
                        className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-on-surface hover:bg-surface-container focus:bg-surface-container"
                    >
                        <Settings size={16} className="text-on-surface-variant" /> Settings
                    </Link>
                </DropdownMenuItem>

                <DropdownMenuItem asChild>
                    <Form {...logout.form()}>
                        {() => (
                            <button
                                type="submit"
                                className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-secondary hover:bg-error-container focus:bg-error-container"
                            >
                                <LogOut size={16} /> Logout
                            </button>
                        )}
                    </Form>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}