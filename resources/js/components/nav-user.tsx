import { Link, router, usePage } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import { SidebarMenu, SidebarMenuItem } from '@/components/ui/sidebar';
import { UserInfo } from '@/components/user-info';
import { logout } from '@/routes';

export function NavUser() {
    const { auth } = usePage().props;

    if (!auth.user) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <div className="flex min-w-0 items-center gap-2 px-0 group-data-[collapsible=icon]:justify-center">
                    <div className="flex min-w-0 flex-1 items-center gap-2 group-data-[collapsible=icon]:hidden">
                        <UserInfo user={auth.user} showEmail={true} />
                    </div>
                    <Link
                        href={logout()}
                        as="button"
                        onClick={() => router.flushAll()}
                        aria-label="Log out"
                        title="Log out"
                        data-test="logout-button"
                        className="flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors duration-150 ease-out hover:bg-sidebar-accent hover:text-sidebar-foreground focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none motion-reduce:transition-none"
                    >
                        <LogOut className="size-4" />
                    </Link>
                </div>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
