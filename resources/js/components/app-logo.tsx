import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex size-7 shrink-0 items-center justify-start text-brand">
                <AppLogoIcon className="size-7" />
            </div>
            <div className="ml-2 grid flex-1 text-left text-sm">
                <span className="truncate leading-tight font-semibold tracking-[-0.01em]">
                    Crucible DB
                </span>
            </div>
        </>
    );
}
