import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex size-9 shrink-0 items-center justify-start text-orange-500">
                <AppLogoIcon className="size-9" />
            </div>
            <div className="ml-2 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-semibold">
                    Crucible DB
                </span>
                <span className="truncate text-xs leading-tight text-sidebar-foreground/60">
                    Access control plane
                </span>
            </div>
        </>
    );
}
