import { Link, Form } from '@inertiajs/react';
import { User, Settings, LogOut } from 'lucide-react';
import { 
    DropdownMenu, 
    DropdownMenuContent, 
    DropdownMenuItem, 
    DropdownMenuTrigger 
} from '@/components/ui/dropdown-menu';
import { logout } from '@/routes';

export default function ProfileDropdownMenu({ name }: {name:string}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button className= "flex size-9 items-center justify-center rounded-full border border-slate-200">
                    <User size={18}/>
                </button>
                </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <div className="px-2 py-1.5 text-sm font-semibold">{name}</div>
                <DropdownMenuItem asChild>
                    <Link href="/settings" className="flex items-center gap-2">
                        <Settings size={16} /> Settings
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Form {...logout.form()}>
                        {() => (
                            <button type="submit" className="flex w-full items-center gap-2 text-left">
                                <LogOut size={16} /> Logout
                            </button>
                        )}
                    </Form>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}