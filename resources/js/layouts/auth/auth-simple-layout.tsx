import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col bg-muted/30">
            <header className="border-b bg-background">
                <div className="mx-auto flex h-16 w-full max-w-7xl items-center px-6 md:px-10">
                    <Link
                        href={home()}
                        className="flex items-center gap-3 rounded-md outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                        aria-label="Crucible DB home"
                    >
                        <span className="flex size-8 items-center justify-center rounded-md border bg-card">
                            <AppLogoIcon className="size-6 text-orange-500" />
                        </span>
                        <span className="grid text-left leading-tight">
                            <span className="text-sm font-semibold tracking-[-0.01em]">
                                Crucible DB
                            </span>
                            <span className="text-xs text-muted-foreground">
                                Database access control
                            </span>
                        </span>
                    </Link>
                </div>
            </header>

            <main className="flex flex-1 items-center justify-center px-6 py-12 md:px-10">
                <div className="w-full max-w-md">
                    <div className="grid gap-8">
                        <div className="space-y-2">
                            <p className="text-xs font-medium tracking-[0.08em] text-muted-foreground uppercase">
                                Secure sign in
                            </p>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {title}
                            </h1>
                            <p className="max-w-sm text-sm leading-6 text-muted-foreground">
                                {description}
                            </p>
                        </div>
                        <div className="border-t pt-6">{children}</div>
                    </div>
                </div>
            </main>
        </div>
    );
}
