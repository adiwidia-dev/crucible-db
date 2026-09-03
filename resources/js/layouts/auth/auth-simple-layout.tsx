import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
    wide = false,
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
                <section
                    aria-labelledby="auth-title"
                    className={`w-full overflow-hidden border bg-background sm:rounded-lg ${wide ? 'max-w-2xl' : 'max-w-md'}`}
                >
                    <div className="border-b px-5 py-5 sm:px-8">
                        <h1
                            id="auth-title"
                            className="text-2xl font-semibold tracking-tight"
                        >
                            {title}
                        </h1>
                        <p className="mt-2 max-w-sm text-sm leading-6 text-muted-foreground">
                            {description}
                        </p>
                    </div>
                    <div className="px-5 py-6 sm:px-8 sm:py-7">{children}</div>
                </section>
            </main>
        </div>
    );
}
